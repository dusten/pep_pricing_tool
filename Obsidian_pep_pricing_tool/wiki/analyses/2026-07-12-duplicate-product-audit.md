---
title: Full-Catalog Duplicate-Product Audit
type: analysis
tags: [duplicates, products, data-quality, backlog-28]
created: 2026-07-12
sources: []
---

# Full-Catalog Duplicate-Product Audit

User asked for a deep dive across the whole catalog (205 products) to evaluate how many are
duplicates — not just re-checking recent imports like past cleanup passes did. Read-only
investigation via [[diagnostic_scripts/2026-07-12-duplicate-product-audit.php]] (normalized-name
exact-match grouping + substring-containment self-join, cross-referenced against
`pc_product_aliases` so already-solved cases don't get re-flagged). **No merges performed.**

## Headline numbers

- **205 total products.**
- **1 near-certain (HIGH) duplicate pair** found fresh this pass.
- **~5 additional HIGH/MEDIUM candidates** beyond what backlog #28 already tracks.
- **51 raw substring-containment pairs** surfaced by the heuristic — the large majority are
  false positives (blend/combo products legitimately containing a base-ingredient's name, e.g.
  "Cagrilintide + Semaglutide" containing "Semaglutide"), filtered out below by hand.

## Backlog #28 — current state of each already-known item

- **Adipotide / Adipotide-FTPP** — still 3 separate products: `[97] Adipotide`,
  `[86] Adipotide/FTPP` (has aliases for the FTTP typo), `[87] FTPP Adipotide` (no alias).
  Still open, per #28. **New wrinkle**: `[87] FTPP Adipotide` is the same two words reordered
  as `[86] Adipotide/FTPP` — see new finding below.
- **Gonadorelin vs. Gonadorelin Acetate** — still 2 separate products (`[119]` 6v/12l vs.
  `[96]` 8v/12l), non-overlapping specs. Still open, unchanged.
- **Sermorelin vs. Sermorelin Acetate** — **this one is already resolved** since #28 was
  written: `[42] Sermorelin Acetate` now carries `Sermorelin` as a real alias. Recommend
  striking this part of #28.
- **L-Carnitine cluster** — **also already resolved**: `[27] L-Carnitine` now carries
  `L-Carnitine 1200mg`/`500mg`/`600mg` as real aliases, not separate products. Recommend
  striking this part of #28. (A genuinely distinct `[314] L-Carnitine + Vitamin Blend` exists
  separately — correctly not merged, it's a real multi-ingredient blend.)
- **SU-400 / Sustanon / Supertest / Testosterone Cypionate cluster** — still 4 separate
  products, but each now self-aliases its own short name (e.g. `[257]` aliases `SU-400`) —
  that's import-matching hygiene, not a step toward merging. **Assessment differs from the
  original #28 framing**: these are likely genuinely different testosterone ester
  formulations/blends (Sustanon specifically is a 4-ester blend, not a dose variant of a single
  compound), not simple "same compound, different dose" cases like L-Carnitine was. Recommend
  downgrading urgency on this part of #28 rather than treating it as equivalent to the
  L-Carnitine case.
- **Lipo-C cluster** — the originally-asked question (`Lipo-C[FOCUS]`/`Lipo-C[FAT BLASTER]` vs.
  `Lipo-c`) is **already resolved** — `[33] Lipo-c` carries both as aliases. **New, related
  finding not in the original #28 wording**: `[55] Lipo-C with B12` and `[36] MIC(lipo C with
  B12)` are likely duplicates of *each other* — see below.
- **SUPER SHRED vs. SHR - Shred Blend** — still separate (`[319]` 553mg/ml vs. `[193]`
  350.25mg/ml), still genuinely ambiguous (different mg/ml could mean different formulation).
  Unchanged, correctly still open.

## New findings (not on backlog #28)

**HIGH confidence:**

- **`[377] "HGH 191AA (Somatropin）"` vs. `[123] "HGH 191AA(Somatropin)"`** — identical name
  except product 377 uses a full-width CJK right-parenthesis (U+FF09, confirmed via hex dump —
  likely from a CJK-locale vendor file) where 123 uses a plain ASCII `)`. Pure typographic
  duplicate, no ambiguity. 7 vendors / 41 listings combined.
- **`[354] "Vasoactive Intestinal Peptide (VIP)"` vs. `[34] "VIP"`** — the first name is
  literally the spelled-out expansion of the second's acronym. 20 vendors / 36 listings
  combined.
- **`[230] "BC 250 (Boldenone Cypionate)"` vs. `[216] "Boldenone Cypionate"`** — same
  compound, "BC 250" is a brand/dose shorthand; specs overlap exactly (`10ml x 250mg/ml`).
  3 vendors / 3 listings combined (small, but clean).

**MEDIUM confidence:**

- **`[86] "Adipotide/FTPP"` vs. `[87] "FTPP Adipotide"`** — same two words reordered; likely
  the same vendor-listing pattern extracted two different ways. 10 vendors / 22 listings
  combined.
- **`[55] "Lipo-C with B12"` vs. `[36] "MIC(lipo C with B12)"`** — "MIC" (Methionine/Inositol/
  Choline) is standard industry shorthand for exactly this kind of B12-plus-lipotropic blend;
  product 36's own name self-identifies as "lipo C with B12." Overlapping specs (both have
  `10mg`, `10ml`, similar mg/vial breakdowns). 16 vendors / 23 listings combined.
- **`[81] "Hexarelin Acetate"` vs. `[353] "Hexarelin"`** — same salt-form pattern already
  confirmed correct for Sermorelin/Sermorelin Acetate (now aliased together per above) — worth
  the same treatment. 13v/27l vs. 1v/3l.
- **`[139] "Triptorelin Acetate"` vs. `[373] "Triptorelin Acetate/GnRH Triptorelin"`** — same
  core name, 373 just appends a synonym. 4v/4l vs. 1v/1l.
- **`[31] "GLOW"` vs. `[376] "Glow(TB10mg+BPC-15710mg+GHK50mg)"`** — GLOW's own recipe
  (BPC-157 10mg + TB-500 10mg + GHK-Cu 50mg) sums to exactly 70mg, matching both products'
  identical `70mg` spec label. Strong circumstantial match — one vendor's file likely spelled
  out the recipe instead of matching the existing "GLOW" catalog entry. 20v/25l combined.

**LOW / flag-only (genuinely ambiguous, same spirit as the existing open #28 items):**

- `[368] "NA Selank amidate"` vs. `[45] "Selank"`, and `[367] "NA Semax amidate"` vs.
  `[46] "Semax"` — "amidate" can denote a real, distinct chemical modification (C-terminal
  amidation changes peptide stability/potency), so this could be a genuine variant rather than
  a naming duplicate. Each only 1 vendor/1 listing — low stakes either way.
- `[29] "GHK-Cu"` vs. `[317] "GHK-CU Blend"` — "Blend" in the name could mean a genuinely
  different multi-ingredient formulation. 1 vendor/1 listing on the "Blend" side.
- `[2] "TB-500"` vs. `[359] "TB500(Frag)"` — "Frag" (fragment) is a recognized distinct
  partial-sequence variant in this market for some peptides, so likely a real distinction, not
  a duplicate. Noted for completeness, not recommended for merging.

**Confirmed false positives (substring noise, correctly NOT duplicates):**

Every "`X` vs. `X + Y combo`" or "`X` vs. blend-containing-X" pair the heuristic surfaced —
Cagrilintide/Cagrilintide+Semaglutide, Semaglutide, CJC-1295/CJC-1295+Ipamorelin, Ipamorelin,
Tesamorelin/+combo products, Semax·Selank/+combo, Retatrutide/+combo products, BPC-157/GHK-Cu/
TB-500 vs. the various multi-peptide blend products, L-Carnitine vs. its Vitamin Blend — these
are all legitimately distinct stack/blend products correctly modeled as separate catalog
entries, not duplicates of their base ingredients. Also FOXO4 vs. FOXO4-DRI and MGF vs. PEG-MGF
are recognized-distinct research compounds in this market (different real molecules), not a
naming inconsistency. `Anadrol-50`/`Gonadorelin(-Acetate)` matching `NAD+` are pure substring
coincidences (the letters "n-a-d" appearing inside "**a****nad**rol" / "go**nad**orelin") with
zero real relationship.

## Recommendation

No merges executed — this was evaluation only, per the request. Suggested next step if the
user wants to act: strike the Sermorelin and L-Carnitine portions of backlog #28 (already
resolved), downgrade the SU-400/Sustanon cluster's framing, and decide on the ~6 new HIGH/MEDIUM
candidates above (the HGH-191AA punctuation pair is the safest possible first merge — same
name, differs only in one Unicode character).
