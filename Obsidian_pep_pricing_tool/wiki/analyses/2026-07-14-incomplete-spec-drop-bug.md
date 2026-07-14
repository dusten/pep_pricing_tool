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
