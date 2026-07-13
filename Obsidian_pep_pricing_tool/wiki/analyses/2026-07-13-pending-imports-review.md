---
title: Pending Imports Queue Review
type: analysis
tags: [review-queue, lipo-c, adamax, tb-500, data-quality]
created: 2026-07-13
sources: []
---

# Pending Imports Queue Review

User asked for a read-through of the Review Queue's pending-imports backlog. 108 pending
rows at review time: 65 from an older Jenny Peptide steroid price list ("Jenny's Oils
List .xlsx") that had never been worked through, and 43 from a brand-new vendor added the
same day, **Changsha Xjun Techonology** (`id 27`, one file: `USD最新4.0.xlsx`).

## Findings

- **Real risk of repeating the Lipo-C misfiling bug** ([[wiki/analyses/2026-07-12-price-history-tier-and-sku-collision]]
  and the earlier Lipo-C/MIC untangling — see [[wiki/entities/phase-roadmap]] #59/original
  #28): pending row for `"Lipo-C"` at 121mg, sku `LC1201`, suggests **product 33 "Lipo-c"**
  as the candidate (`new_spec` match type) — but product 33 is specifically the *no-B12*
  formulation, and `LC1201`'s own ingredient list includes B12 (Methylcobalamin 1mg).
  Approving as suggested would misfile a with-B12 product under the no-B12 product, the
  exact class of error already fixed once this session. Should go to **product 55
  "Lipo-C with B12"** instead (or a fresh look, if genuinely a third distinct tier).
- **TB-500 prompt fix confirmed working in the wild**: Changsha Xjun's three plain "TB500"
  rows (2mg/5mg/10mg) each carry a clean, non-guessing warning ("Ambiguous compound name...
  no CAS number given to disambiguate which molecule this vendor means") and a `candidate=
  "TB-500"` suggestion with no qualifier folded into the name — exactly the intended
  behavior from the `claude.php` rule 8 rewrite ([[wiki/entities/phase-roadmap]] #58 area).
- **Likely duplicate, not a new product**: `"N-Acetyl Semax Amidate（na semax）"` — this
  project already has `id 367 "NA Semax amidate"` (CAS `2920938-90-3`, from the bulk CAS/MW
  research), but the wording differs enough ("N-Acetyl" vs "NA", full-width parens) that the
  fuzzy matcher filed it as `new_product` instead of `name_mismatch`. Approving as-is would
  create a duplicate product.
- **Generic "water" entries** (`new_product`, 3ml/10ml, sku `WA3`/`WA10`) don't clearly map
  to either existing water product (`id 65 "Bacteriostatic Water"`, `id 105 "Antibacterial
  Water"`) — needs a manual call, not obviously a bug either way.
- **Botched extraction**: one row's `canonical_name` is literally `"1mg/ml"` (a concentration
  string, not a product name), but its own `vendor_sku` is `"B12"` — almost certainly meant
  to map onto the existing B12 product, just extracted wrong.
- **New research lead for an old open question**: `wiki/analyses/2026-07-12-product-cas-mw-research.md`
  flagged "Adamax" as genuinely unidentifiable (conflicting vendor descriptions — sometimes
  a Semax-adamantane analog, sometimes a proprietary GH-secretagogue blend). This pending
  row's own text includes `"1032 da"` (1032 Daltons) — a real, previously-unavailable data
  point that could help resolve that identity question with a future targeted search.
- **Genuinely new compound**, not a data-quality issue: `Eloralintide` (10mg/15mg) — a real
  amylin-analog peptide (CagriSema-adjacent, Novo Nordisk), not yet in the catalog at all.
- Everything else in Changsha Xjun's `name_mismatch` batch is OCR-style typos already
  correctly caught by the fuzzy matcher (`SS·31`→SS-31, `MelanotanI`/`Melanotan Il`→Melanotan
  1/2, `Kisspetin-10`→Kisspeptin-10, `Triptorelln Acetate`→Triptorelin Acetate, `Slupp-322`→
  SLU-PP-322, `RelaxatalonPM`→Relaxation PM) — just needs someone to click through and
  approve each.

## Follow-up: Skip action added (#66)

This review surfaced the actual need directly: several rows above (LC1201, the Semax
duplicate, the water/B12 ones) need real research or a deliberate decision, not a quick
approve/reject — but the Review Queue only had those two options, so reaching them meant
scrolling past every prior row again on each visit. See [[wiki/entities/phase-roadmap]] #66
for the fix (a proper "Skip" action, `last_skipped_at` column, FIFO-with-defer ordering).

## Not yet acted on

None of the findings above were fixed as part of this pass — this was a read-only review.
Flagged to the user for a decision on each.
