---
title: Vendor Onboarding + File Upload Spec
type: analysis
tags: [vendor, upload, spec, review-queue, tiered-pricing, variant-compounds]
created: 2026-07-01
sources: [phase1-framework]
---

# Vendor Onboarding + File Upload Spec

Full spec for the vendor-onboarding-to-price-list workflow, decided before any build work. Supersedes the Phase 3 "auto-commit" extraction behavior for new/mismatched data.

## Workflow (8 steps, as given)

1. Contact vendor
2. Collect vendor data: name, contact channels (WhatsApp/Discord/Telegram), website, payment methods, phone numbers, shipping price
3. Ask for price list
4. Upload price list
5. Process sync or async (file-type/size decides which)
6. Review Abbreviation / Product Name / Specification against DB — **hard review queue**, nothing new commits without admin approval
7. TB-500 variant check — warning only
8. Epitalon variant check — warning only

## Schema changes

### `pc_vendors` — add contact/payment/shipping fields

Current: `contact_name, email, whatsapp, website, notes`. Add:

- `discord VARCHAR(100) NULL`
- `telegram VARCHAR(100) NULL`
- `shipping_price DECIMAL(8,2) NULL`

New tables (one vendor can have many phones / many payment methods):

```sql
CREATE TABLE pc_vendor_phones (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendor_id INT UNSIGNED NOT NULL,
  phone VARCHAR(30) NOT NULL,
  FOREIGN KEY (vendor_id) REFERENCES pc_vendors(id) ON DELETE CASCADE
);

CREATE TABLE pc_vendor_payment_methods (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendor_id INT UNSIGNED NOT NULL,
  method ENUM('usdt_sol','usdc_sol','usdt_trc20','usdc_trc20','usdt_erc20','usdc_erc20',
              'btc','eth','sol','paypal','wise','alipay','alibaba','wire','western_union',
              'zelle','cashapp','credit_card') NOT NULL,
  FOREIGN KEY (vendor_id) REFERENCES pc_vendors(id) ON DELETE CASCADE,
  UNIQUE KEY (vendor_id, method)
);
```

ponytail: plain child tables, no JSON column — these need per-row queries later (e.g. "vendors that accept Zelle") that a JSON blob makes annoying.

### `pc_products` — add `abbreviation`

`abbreviation VARCHAR(50) NULL` alongside existing `canonical_name`. Distinguishes e.g. abbreviation "BPC-157" from a fuller display name if the two ever diverge. Aliases (`pc_product_aliases`) stay for spelling variants; abbreviation is a first-class column because the review queue diffs against it directly.

### Tiered pricing — extend `pc_prices`

Add `tier_kit_size TINYINT UNSIGNED NOT NULL DEFAULT 1` and fold it into the unique key: `(vendor_id, product_id, specification_id, tier_kit_size)`. Claude extraction now emits one row per tier column present (1/10/100-kit) instead of collapsing to 1-kit only. Comparison table continues to default-filter `tier_kit_size = 1` unless the viewer is Pro+ (existing display rule, now backed by real data instead of discarded columns).

### Review queue — new `pc_pending_imports`

```sql
CREATE TABLE pc_pending_imports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendor_file_id INT UNSIGNED NOT NULL,
  vendor_id INT UNSIGNED NOT NULL,
  raw_json JSON NOT NULL,           -- the single extracted price row as Claude returned it
  match_type ENUM('new_product','new_spec','name_mismatch') NOT NULL,
  candidate_product_id INT UNSIGNED NULL,  -- best-guess existing product, if any (fuzzy match)
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (vendor_file_id) REFERENCES pc_vendor_files(id) ON DELETE CASCADE,
  FOREIGN KEY (vendor_id) REFERENCES pc_vendors(id) ON DELETE CASCADE
);
```

**Commit logic in `process.php`:** exact match on `canonical_name` or existing alias (case-insensitive) → commit straight to `pc_prices` as today. Anything else (brand-new product, brand-new spec on an existing product, or a name that's close-but-not-exact to an existing alias) → insert into `pc_pending_imports` instead, skip the price write. Admin approval screen: approve (choose "this is a new product" / "this maps to existing product X" / edit-then-approve), or reject. Approving replays the same insert logic `process.php` already has for products/specs/prices.

Fuzzy-match candidate detection: Levenshtein or `SOUNDEX` against `canonical_name`/`alias` list, threshold TBD at build time — not a new dependency, PHP has `levenshtein()` built in.

### Async processing

Trigger: `file_type = 'pdf' AND file_size_bytes > threshold` (image-scanned PDFs are the ones that risk timeout; text/csv/xlsx stay sync). Threshold candidate: 2MB — refine once we see real vendor files.

Async path: `process.php` becomes an enqueue-only endpoint for qualifying files (`processing_status = 'pending'`, no Claude call). A cron picks up `pending` rows and does today's `callClaudeExtraction` work out-of-band. Sync path (existing behavior) is unchanged for everything else — admin still hits "Process" and gets an inline result immediately.

ponytail: cron polling `pc_vendor_files`, not a message queue — one more table row is enough for the volume here (manual admin uploads, not high-throughput).

### TB-500 / Epitalon variant check

Wire `wiki/concepts/variant-compounds.md` watch-names directly into `buildExtractionSystemPrompt()`: if a canonical_name match hits a watch-name and no CAS number is present in the source text, Claude adds a warning string (same `warnings[]` mechanism already in use for non-standard kit counts) — e.g. `"TB-500 listed without CAS — vendor likely means fragment (885340-08-9) but unconfirmed"`. No blocking, no schema change; surfaces in the existing `processing_notes` field admin already sees.

## Vendor file repository (catalog every file, not just price lists)

Broaden `pc_vendor_files` from "price-list processing input" to "everything this vendor has ever sent us." Add:

```sql
ALTER TABLE pc_vendor_files
  ADD COLUMN category ENUM('price_list','coa','other') NOT NULL DEFAULT 'price_list' AFTER file_type;
```

- Only `category = 'price_list'` rows feed the Claude extraction pipeline (`processing_status`, `processed_at`, `is_current` supersede-tracking — unchanged, scoped to this category).
- `category = 'coa'` / `'other'` uploads (vendor shares their own lab COA, or any other doc) skip processing entirely: stored on upload, `processing_status` set straight to `complete`, no Claude call. They exist purely as an admin-browsable, timestamped history per vendor — this is what lets you look back and see "here's the price list from March, here's the one from June" or pull up a COA the vendor sent directly.
- `is_current` semantics stay price-list-only: uploading a new price list doesn't touch old COA/other rows, they just accumulate.

ponytail: one enum column on the existing table, not a new "documents" table — same FK, same storage path convention, same admin list view, just filterable by category now.

## COA verification (user-submitted, crowd-sourced)

Users can vouch a product/vendor pairing by submitting a third-party lab COA URL. Two entry paths, same review queue:

1. **Standard**: pick vendor from a list, pick product from a dropdown scoped to that vendor's current offerings (`SELECT product_id ... FROM pc_prices WHERE vendor_id = ? AND is_active`), paste COA URL.
2. **Custom blend**: vendor doesn't list this product in their price list (e.g. a custom-mixed order) — user types a free-text product name instead of picking from the dropdown, COA URL still required.

Both paths land in the same pending queue — no auto-post, per your call that everything gets reviewed.

```sql
CREATE TABLE pc_coa_submissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  vendor_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NULL,          -- set for standard path
  custom_product_name VARCHAR(200) NULL, -- set for custom-blend path (mutually exclusive w/ product_id, enforced in PHP)
  coa_url VARCHAR(500) NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (vendor_id) REFERENCES pc_vendors(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES pc_products(id) ON DELETE CASCADE
);
```

**Admin review UI**: single-card queue, not a table — fetch one pending submission, show vendor/product/COA link, two buttons (Approve / Reject), submitting either one auto-loads the next pending row. No batch view needed at this scope.

**Verified vendor badge**: separate from the submission queue. `pc_vendors.is_verified BOOLEAN NOT NULL DEFAULT FALSE` — a manual admin toggle on the vendor edit screen (not auto-computed from submission count). Drives two things in the comparison UI: a badge next to the vendor name, and a "Verified vendors only" filter option. Approved COA submissions stay internal for now (feed nothing public) — they're admin-visible trust signal only, per your call; the public badge is the separate manual flag.

## Paste-to-parse vendor intake

Instead of an admin filling every contact/payment field by hand, send vendors a fixed-format text template; paste their reply into a textarea and parse it into the vendor form fields for review before save.

**Template to send vendors:**

```
Vendor Name:
Contact Name:
Email:
WhatsApp:
Discord:
Telegram:
Website:
Phone Number(s):
Payment Methods (list all that apply — USDT/USDC Solana, USDT/USDC Tron, USDT/USDC ERC20,
  BTC, ETH, SOL, PayPal, Wise, Alipay, Alibaba, Wire Transfer, Western Union, Zelle, CashApp, Credit Card):
Shipping Price:
```

**Parse strategy (cheap path first, AI fallback only on failure):**

1. Line-by-line regex `^([\w \/\(\)]+):\s*(.*)$`, map each label (normalized/lowercased) to a known field via a lookup table — tolerates label reordering and minor wording drift ("Tele" vs "Telegram") but not free-form prose.
2. Phone numbers split on comma/slash into `pc_vendor_phones` rows; payment methods matched by keyword against the enum list into `pc_vendor_payment_methods` rows.
3. If pattern-match can't find the vendor name, or fewer than N fields resolve (vendor replied in a paragraph instead of the template) — fall back to a small Claude prompt (same shape as the extraction system, new lightweight one) that returns the same field JSON from freeform text. No new dependency, reuses the existing Anthropic call already in `lib/claude.php`.
4. Either path lands in an **editable preview**, not a direct save — admin reviews/corrects parsed fields, then confirms. Consistent with the hard-review stance already set for price data: nothing from free text commits unseen.

ponytail: regex-first, LLM-fallback — most vendors will follow the template closely enough that the free pattern match handles it; only pay for a Claude call when the input doesn't fit the shape.

**Admin screen layout** (single combined intake form):

```
Select Vendor: <dropdown of existing vendors — editing>
New Vendor:    <text box — name only, creates new vendor row>
Paste vendor reply: <textarea> [Parse] -> editable field preview -> [Save]
Upload Price File:  <file input, category=price_list, attached to selected/new vendor>
```

Selecting an existing vendor pre-fills the form with current values (so paste-parse is also how you *update* a vendor's info, not just onboard a new one). New Vendor + blank paste box is just a plain create with a name.

## Malware scanning — required for every upload, not just price lists

This was backlog-listed as future work; folding it into this build now since every path here (price list, COA, "other", and any file attached via this intake form) accepts vendor-supplied files.

- Single scan point: right after `move_uploaded_file()`, before a `pc_vendor_files` row is marked available for processing/cataloging (applies to all three `category` values, not just `price_list`).
- Use `clamdscan` against the running `clamd` daemon (not `clamscan` CLI — avoids reloading virus definitions per call).
- Positive match → file quarantined (moved out of the serving path, not deleted — keeps evidence), `processing_status` gets a `failed` result with a clear "malware scan rejected" note, never reaches Claude or the review queue.
- Applies uniformly regardless of sync/async path — scan happens before the sync/async branch decision, not after.

## Explicitly out of scope this round

- Async job UI/progress bar — cron + status poll only, no websocket/live updates
- Payment-method-based vendor filtering in the comparison UI — schema supports it, no UI requested yet
- Retroactive re-extraction of already-processed vendor files to backfill tier data
- Public display of approved COA links on vendor/product pages — internal admin trust signal only for now
- Auto-computed verified status (submission-count threshold) — `is_verified` is a manual admin toggle

## Related

- [[wiki/concepts/variant-compounds|Variant Compounds Watchlist]]
- [[wiki/entities/phase-roadmap|Phase Roadmap]]
- [[wiki/sources/phase1-framework|Phase 1 Framework Reference]]
