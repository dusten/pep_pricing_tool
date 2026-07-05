---
title: Vendor Upload Dedup Spec (File-Hash + Data-Match)
type: analysis
tags: [spec, dedup, vendor-upload, backlog, claude-extraction]
created: 2026-07-03
sources: []
---

# Vendor Upload Dedup — Two Layers

Design for backlog #14, expanded from its original framing after a real gap surfaced in discussion: a byte-hash check alone only catches a literal re-upload of the same file bytes — it misses the far more common real case, a vendor re-exporting or re-screenshotting the *same data* into a different file.

**Status: both layers built and deployed 2026-07-03** (same day as this spec).

## Why two layers, not one

| | Catches | Saves the Claude API cost? |
|---|---|---|
| **Layer 1 — file hash** | Byte-identical re-upload (same file, uploaded twice) | Yes — skips extraction entirely |
| **Layer 2 — data match** | Different file, same underlying prices (re-export, re-screenshot, reformatted CSV) | No — extraction already ran by the time the data can be compared |

They solve different problems and both are worth having: Layer 1 is the cheap win for the exact scenario #14 originally named (wasted API cost on a literal re-upload). Layer 2 can't avoid that cost — you don't know the data until Claude's read it — but it gives the admin an honest signal ("47 of 50 rows were already at this exact price") instead of a silent, equally-successful-looking commit, and stops a redundant reimport from looking indistinguishable from a real price update.

## Layer 1 — file-hash pre-check — BUILT

Shipped exactly as designed below (migration 020). Verified live: uploaded a real test file, manually flipped it to `complete` (simulating a prior successful process), then re-uploaded the byte-identical content under a *different filename* — correctly detected and marked `skipped_duplicate` with an accurate note naming the original file. A genuinely different file was correctly left alone. All test data (DB rows + orphaned files on disk) cleaned up afterward.

### Schema

```sql
ALTER TABLE pc_vendor_files
  ADD COLUMN content_hash CHAR(64) NULL AFTER file_size_bytes,
  MODIFY COLUMN processing_status ENUM('pending','processing','complete','failed','skipped_duplicate') NOT NULL DEFAULT 'pending',
  ADD INDEX (vendor_id, content_hash);
```

SHA-256 hex digest of the raw file bytes. Scoped **per vendor** — two different vendors happening to upload byte-identical files isn't a real duplicate scenario worth detecting, and scoping globally would risk a false-positive dedup across unrelated vendors.

### Flow (`vendors/files.php`, upload handler)

1. After the malware scan passes (order matters — never skip that gate), compute `hash('sha256', file_get_contents($storedPath))`.
2. Look up: does a `price_list` row for this `vendor_id` with this `content_hash` already exist, where `processing_status IN ('complete', 'skipped_duplicate')` — i.e. a prior upload with identical bytes that was *actually already handled* (not one still pending, processing, or failed — those aren't "already handled," and the vendor may be intentionally retrying)?
3. **If found**: still store and catalog the new file (matches the existing pattern of keeping every upload as an audit trail — never silently drop it), but set `processing_status = 'skipped_duplicate'` immediately instead of `'pending'`, with `processing_notes` naming which prior file it matches (`"Duplicate of file #42, uploaded 2026-06-30 — already processed, extraction skipped."`). The upload response carries this back immediately, so the admin sees it without clicking "Process" first. Since it's never marked `pending`, neither the manual "Process" button nor the async cron worker will ever call Claude on it.
4. **If not found**: proceed exactly as today.

### Non-goals

Cross-vendor dedup; hashing categories other than `price_list` (COA/other files aren't reprocessed, so a hash check buys nothing there); deleting/rejecting the duplicate upload outright (kept for audit, just not processed).

## Layer 2 — post-extraction data-match (changed/unchanged tally) — BUILT

Shipped exactly as designed below. Verified via a rolled-back transaction on the server (same pattern used for the price-history spec): a new price line, a real price change, and an identical resubmit each returned the correct `bool` from `commitPriceRow()`.

### Design

No new schema. `commitPriceRow()` (`backend/lib/price_import.php`) already computes whether an incoming row is a real change — it's the exact same comparison that decides whether to write a `pc_price_history` row (backlog #3). Today that comparison result is discarded (the function returns `void`); this just returns it:

```php
function commitPriceRow(...): bool {
    // ...existing logic unchanged...
    return $priceChanged; // true = new or real change, false = identical resubmit
}
```

`vendor_file_processor.php`'s `processVendorFile()` loop tallies the result instead of assuming every commit is meaningful:

```php
$imported  = 0; // real changes (new rows + actual price/kit-count changes)
$unchanged = 0; // identical resubmits — the data-match signal
// ...
$changed = commitPriceRow($pdo, ...);
if ($changed) $imported++; else $unchanged++;
```

`processing_notes` (already used for warnings and the pending-review count) gets an additional line when `$unchanged > 0`: `"37 row(s) unchanged from the current price list."` — visible both in the immediate process response and later when reviewing file history, no new column needed. `files/process.php`'s response message becomes e.g. `"Imported 3 price rows (37 unchanged)."`.

### What this does *not* do (flagged, not built)

A more ambitious version would compare newly-extracted **pending** rows (new-product/new-spec/name-mismatch candidates awaiting admin review) against previously-rejected `pc_pending_imports` rows, and auto-skip re-queuing something an admin already rejected once. That's real, separate scope — it needs a decision on what "the same rejected item" means (exact raw-JSON match? name+price match? a fuzzy match like the existing candidate-suggestion logic?) and isn't something a vendor re-upload inherently has today. Worth its own spec if repeatedly-rejected junk becomes an actual nuisance; not assumed into this pass.

## Non-goals (both layers)

- Deleting or refusing duplicate uploads outright — always cataloged, never silently dropped.
- Retroactively hashing/backfilling existing uploaded files (starts clean from whenever this ships).
- The pending-imports auto-skip idea above.

## Related

- [[wiki/entities/phase-roadmap|Phase Roadmap]] — backlog #14
- [[wiki/analyses/2026-07-03-price-history-spec|Price History Spec]] — the same old/new comparison in `commitPriceRow()` that Layer 2 reuses
