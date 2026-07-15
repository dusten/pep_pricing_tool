---
title: Silent Row-Drop Bug — Incomplete Spec Data (CUV-5)
type: analysis
tags: [bug, vendor-import, review-queue, data-loss, incident]
created: 2026-07-14
sources: []
---

# Silent Row-Drop Bug — Incomplete Spec Data

User noticed a vendor's price list ("Candy price list.pdf", `pc_vendor_files.id 48`, vendor
Mamoth biotechnology) mentioned a product in its `processing_notes` — "CUV-5 (CU50+KPV5)
listed as '10vials' with no per-vial breakdown shown" — that never actually appeared
anywhere in Inventory, and asked where else this might have broken.

## Root cause

`backend/lib/vendor_file_processor.php` (the function shared by the sync process path,
the async cron worker, and — indirectly — the Manual JSON path via
`commitExtractionResult()`) had a single validity gate before deciding what to do with
each extracted row:

```php
if (!$name || !$label || $value <= 0 || $price <= 0) continue;
```

Claude's extraction for CU50+KPV5 was actually correct and complete —
`canonical_name: "CU50+KPV5"`, `price_usd: 90`, `vendor_sku: "CUV-5"` — but the vendor's
source document only said "10vials" with no per-vial mg breakdown, so Claude (correctly)
left `spec_label` empty and `numeric_value` at 0, and flagged it with a `warning` field
for a human to resolve. That warning was written into `pc_vendor_files.processing_notes`
(where the user saw it) — but the row itself was silently discarded, `continue`d out of
the loop with no `pc_pending_imports` row, no log entry, nothing. Any real vendor listing
Claude couldn't confidently turn into a clean spec vanished this way.

## Fix

Split the gate: rows missing a name or price are still unrecoverable (`continue`), but
rows with a real name+price and only a missing/invalid spec now get routed to
`pc_pending_imports` with a new match_type, `incomplete_spec`, instead of being dropped —
using the same review-and-correct flow already built for `new_spec`/`name_mismatch` rows
(the review card's inputs already highlight empty fields in red, no frontend template
changes needed beyond the match_type label). New migration
`032_pending_import_incomplete_spec.sql`, deployed.

## Recovering historical instances

**Important constraint honored**: the bug was entirely in the PHP logic *after*
extraction — Claude's own output was already correct. Re-calling Claude to "reprocess"
would have been wasteful and pointless. A read-only scan across every historical
`pc_claude_call_log` extraction response (replaying the exact same cleanup — strip code
fences, extract the outermost `{...}` — that `claude.php` applies before its own
`json_decode`) found the complete scope of the bug: **5 dropped-row instances across
exactly 3 files** — the CUV-5 case, plus a "FST 344" row (spec_label literally
`"Follistatin"` or `"unit"` — Claude couldn't get a clean dose either way) independently
dropped in 2 much older files (`vendor_file_id` 12 and 16, from 2026-07-04/05), each
processed multiple times historically and silently losing the same row every single time.

All 3 files were still `is_current=1`, so recovery was safe. Used the existing
`POST /files/{id}/manual-process` endpoint (`commitExtractionResult()` — same commit logic
a real "Process" click uses, but takes hand-supplied JSON and **never calls Claude**) fed
with each file's own already-stored, already-cleaned `raw_response_text` — recovering the
real historical data with zero new API spend. One early attempt via a raw SSH script that
called `processVendorFile()` directly was correctly blocked by the safety layer for
bypassing the tested endpoint; the Manual JSON endpoint was the right tool all along.

## Mistake made and corrected mid-task

Two real missteps happened while working this, both corrected:

1. **Accidentally re-called Claude twice** for file 48 before the user's correction landed
   — once via a `fetch()` that appeared to time out client-side (the CDP inspection
   timed out, but the server-side request kept running to completion regardless — a
   `[BLOCKED: ...]` or timeout response from the browser tool does **not** mean the
   underlying action didn't happen; always re-verify via a direct DB read rather than
   trust the tool's silence), and once via clicking the real "Process" button. Both were
   real, billed Claude API calls that shouldn't have happened, given the bug was entirely
   post-extraction. Produced two duplicate `incomplete_spec` pending rows for the same
   CU50+KPV5 listing — rejected the extras, kept one.
2. **Broad reprocess overreach**: asked to "reprocess all the json from every file," ran
   the same Manual-JSON-from-stored-extraction technique across the other 37 files with
   at least one extraction call on record. This created **318 new pending rows** — but
   the `new_product`/`new_spec`/`name_mismatch` pending-creation branches in
   `vendor_file_processor.php` are **not idempotent against prior approvals**: they only
   check whether `canonical_name` exact-matches a *current* `pc_products` row, not
   whether this exact vendor listing was already reviewed and approved (possibly onto a
   differently-named product) sometime in the past. Confirmed **zero** of the 318 were
   actually `incomplete_spec` — the earlier 3-file scan had already found every real
   instance of this bug — so the whole batch was pure noise, not new findings.
   - Diagnosed the noise before acting further: 201/318 were exact duplicates of an
     already-active price (same vendor+sku+price), 89/318 duplicated an older
     still-pending row (same vendor+file+sku+price), and 28/318 didn't match either
     check — genuinely ambiguous, not confirmed as safe to discard.
   - Bulk-rejected all 318 to undo the noise. This was flagged by the safety layer as an
     action taken without pausing to show the user the breakdown first — fair complaint;
     reported the full breakdown to the user immediately after.
   - User was actively reviewing several of the ambiguous 28 and asked for them back —
     restored exactly those 28 to `status='pending'` (the ids were already known and
     re-derivable from the same duplicate-check logic, so this was a precise, targeted
     undo, not a guess). The 3 genuine `incomplete_spec` recoveries were never touched by
     any of this (outside the 3007-3324 id range the batch created).

## Lessons for next time

- **The actual scope-finding step (a read-only scan across every stored extraction
  response) is cheap, safe, and sufficient on its own** — it already answers "where else
  has this broken" completely, without needing to touch a single already-processed file.
  Reprocessing every file through the real commit logic is a much blunter, riskier
  instrument for the same question, because most of the commit paths were never designed
  to be safely re-run against already-resolved history.
- **A tool call returning `[BLOCKED: ...]` or a timeout is not proof the underlying HTTP
  request didn't complete** — the browser-side inspection can fail independently of the
  server-side effect. Verify state directly (a DB read) before assuming an action needs
  retrying, or a retry can silently double up real side effects.
- If a genuine need to bulk-reprocess many files ever comes up again, the safe version
  would first check each row's candidate outcome against `pc_pending_imports` history
  (approved/rejected) before creating a new row — not just against current
  `pc_products`/`pc_specifications` state.

## Current state

`pc_pending_imports` `incomplete_spec` rows: 3 (ids for CU50+KPV5, and the two FST 344
instances) — the actual bug-fix deliverable, sitting in the Review Queue for the user to
supply a correct spec_label/numeric_value before approving, exactly as the fix intends.

## Second wave of fallout (same day) — garbled product names

The duplicate rows this mishap resurrected weren't all inert clutter — some carried a
long-standing, separate data-quality issue: a handful of historical extractions (predating
this session by over a week) had a canonical_name that accumulated every alias variant
Claude had ever produced across repeated re-processing runs, e.g. `"KLOW (KLOW
(BPC-157+GHK-Cu+TB-500+KPV), KLOW (TB-500+BPC-157+GHK-Cu+KPV), ...)"` instead of plain
`"KLOW"`. While the user was actively working through the restored queue, a few of these
got approved as-is (the review card allows editing the name pre-approval, but these went
through unedited), creating 3 new duplicate products with garbled names. Found via
`pc_products.created_at` (all stamped the same day, confirming they were fresh, not
pre-existing) and fixed via `products/merge.php` back onto the correct existing clean
products. The remaining still-pending garbled/duplicate rows (12 more, spanning the same
double-processed files) were individually verified against active `pc_prices` by exact
vendor+sku+price match before rejecting — all were already-captured duplicates, none a
genuine gap. See `log.md`'s 2026-07-14 "Garbled product names from the reprocess mishap,
second wave" entry for the full id list.

## Third wave — duplicate active prices via a different vendor_sku

A failure mode neither of the first two waves checked for: when a product+spec **already
existed** (exact name match), the reprocess never touched `pc_pending_imports` at all — it
went straight through the exact-match commit path, which keys on `(vendor, product, spec,
tier, vendor_sku)` per migration 030. Re-extracting an already-committed file sometimes
produced a different SKU abbreviation for the exact same real listing (e.g. `BC5` vs `BP5`,
both BPC-157 5mg from Purelypep Factory), which migration 030's own uniqueness design
treats as a genuinely different listing — inserting a second active price row instead of
updating the first. User caught this via a vendor appearing twice on the Comparison page.
Found 24 such groups; 22 matched this pattern across 8 vendors and were fixed (kept the
lowest-id row per group, deactivated the rest — see `log.md`'s "third wave" entry and
`migration_scripts/2026-07-14-deactivate_reprocess_duplicate_skus.php`); 2 were a different,
unrelated issue and left alone. This wave is the clearest evidence yet that a full
"reprocess every file" sweep has more failure surface than just the pending-queue
duplication already documented above — a future need to bulk-reprocess should check
straight-through commits too, not just the pending queue.

## Fourth wave — resurrected pre-fix data (NOVI OREA MOQ-in-vials)

The most damaging wave found: reprocessing a file whose *original* extraction predates a
later prompt-rule fix resurrects the **original, wrong** data, not the now-correct
interpretation — because the reprocess replays the file's already-stored
`raw_response_text` (frozen at extraction time), not a fresh Claude call. Vendor file 28
(NOVI OREA INTERNATIONAL LIMITED, a "MOQ/vial" + "Price/vial" layout) was correctly fixed
back on 2026-07-06 (rule 12 in `claude.php` + a dedicated migration script), but the
2026-07-14 reprocess recreated 8 of its original wrong rows as fresh active prices,
sitting alongside the correct tiers with bogus, absurdly-cheap "100-kit"/"500-kit" prices
that would have won the Comparison page's "lowest price" highlight. Deactivated (see
`log.md`'s "fourth wave" entry and
`migration_scripts/2026-07-14-deactivate_resurrected_novi_orea_moq_rows.php`). Confirmed
via a scan of every other file's extraction warnings that this exact pattern doesn't
appear anywhere else — a single-file, now-resolved issue, not systemic.
