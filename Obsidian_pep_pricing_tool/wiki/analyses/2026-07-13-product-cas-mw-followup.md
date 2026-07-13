---
title: CAS / Molecular Weight Follow-up Pass (products added since 2026-07-12)
type: analysis
tags: [products, cas-number, molecular-weight, adamax, follistatin]
created: 2026-07-13
sources: []
---

# CAS / Molecular Weight Follow-up Pass

Follow-up to [[wiki/analyses/2026-07-12-product-cas-mw-research]] and
[[wiki/analyses/2026-07-13-pending-imports-review]]. User asked to look through all
products still missing CAS/MW and find what's findable. 116 of 232 products were missing
one or both (catalog grew from 195 → 232 since the prior pass).

## Identity questions resolved

- **Adamax (id 70, 414 "Adamax 1032 da")**: a Janoshik COA-verification article
  specifically discussing "real Adamax (1,032 Da) vs fake (984 Da)" confirms Adamax is a
  **Semax-adamantane analog** — synthetic heptapeptide `Ac-MEHFPGP-AG-NH2` (Semax's core
  sequence plus an adamantane-glycine addition), MW **1032.24**, formula C22H52N16O6. The
  "984 Da" figure some vendors cite belongs to a version missing the adamantane group
  entirely (plain Semax-family backbone), not a rival compound family. This resolves the
  earlier open question in favor of "Semax-adamantane analog," not "proprietary
  GH-secretagogue blend." No public CAS found for either id. Products 70 and 414 are
  likely the same compound under two names (dose in the name vs. not) — worth a merge,
  not done here (structural change, out of scope for a CAS/MW task).
- **Follistatin family (362 "Follistatin" / 404 "Follistatin 344/Fst344" / 140 "FST 344")**:
  FST-344 (344-aa precursor, ~38 kDa) and FST-315 (mature 315-aa isoform, CAS 80449-31-6,
  ~35 kDa) are genuinely different isoforms — not resolved as duplicates. But 140 "FST 344"
  and 404 "Follistatin 344/Fst344" look like the *same* isoform under two names — likely a
  duplicate catalog entry, not a third distinct product. Not merged here — flagged only.

## Found — written to `pc_products`

| id | canonical_name | CAS | MW | Source |
|---|---|---|---|---|
| 384/385 | DECA (Nandrolone Decanoate) | 360-70-3 | 428.65 | PubChem CID 9677 |
| 382/383 | Methenolone Enanthate (Primo) | 303-42-4 | 414.62 | PubChem CID 248271 |
| 386–389 | Stanozolol (oil/suspension) | 10418-03-8 | 328.49 | PubChem CID 25249 |
| 394 | Superdrol / Methasterone | 3381-88-2 | 318.50 | Cayman Chemical |
| 395 | Metribolone / Methyltrienolone | 965-93-5 | 284.39 | Sigma / GlpBio / Wikipedia (independently re-verified) |
| 397 | Estradiol Cypionate | 313-06-4 | 396.56 | NIST WebBook |
| 390–392 | Boldenone Undecylenate (Equipoise) | 13103-34-9 | 452.67 | Cayman Chemical |
| 393 | Boldenone Cypionate (BC 250) | 106505-90-2 | 410.59 | Cayman Chemical |
| 412 | Testosterone Propionate (TP) | 57-85-2 | 344.49 | Cayman Chemical |
| 379 | Trenbolone Acetate (TRA) | 10161-34-9 | 312.40 | Cayman Chemical |
| 380/381 | Trenbolone Enanthate (TRE) | 1629618-98-9 | 382.54 | Cayman Chemical / LGC Standards (independently re-verified) |
| 400 | Eloralintide (LY3841136) | 2883634-40-8 | 4526.10 | AbMole / MedChemExpress — corrects this project's earlier note attributing it to Novo Nordisk; it's an Eli Lilly compound |
| 410 | KPV (Lys-Pro-Val) | 67727-97-3 | 342.43 | ChemicalBook |
| 396 | NAD+ | 53-84-9 | 663.4 | Cayman Chemical |
| 409 | TB500 (Thymosin B4 Acetate) | 77591-33-4 | 4963.49 | ChemicalBook |
| 405 | Thymosin Beta-4 Fragment 17-23 | 885340-08-9 | 889.02 | (see duplicate note below) |
| 70/414 | Adamax / Adamax 1032 da | — | 1032.24 | Janoshik COA-verification article |
| 73 | HCG | 9002-61-3 | — | Wikipedia / Sigma (glycoprotein, no fixed small-molecule MW) |
| 66 | HMG | 61489-71-2 | — | ChemicalBook (biological mixture, no single MW) |
| 78 | Cerebrolysin | 12656-61-0 | — | Wikipedia (registry number for the mixture, not a molecule) |
| 226 | Hyaluronic acid | 9004-61-9 | — | Wikipedia (polymer, MW varies by form) |
| 80 | ACE-031 | 1621169-52-5 | — | peptides.guide (fusion protein, ~57 kDa, not a fixed decimal-friendly figure) |

## Reverted after cross-checking the prior pass

19 products were initially written, then reverted to NULL/NULL within the same session
after cross-referencing [[wiki/analyses/2026-07-12-product-cas-mw-research]] and finding
this fork's "vendor consensus" sourcing directly conflicted with that page's more careful,
dedicated research: **37 B12**, **59 CJC-1295 with DAC**, **82 Thymalin**, **100 MGF**,
**134/135/136/187/264/302/308/309/310/311/189** (the 11 Khavinson bioregulator peptides),
**145 P21 (P021)**, **202 DHB**, **303 N-Acetyl Epitalon Amidate**, **368 NA Selank
amidate**. See the "Follow-up pass" section of the 2026-07-12 page for why each was
rejected. No bad data persisted — reverted before this response was sent.

## Not found / needs a manual vendor-listing check

- **127 SLU-PP-322** — every source only documents **SLU-PP-332** (CAS 303760-60-3, MW
  290.32). Possible typo in catalog/vendor data; needs the original vendor listing checked
  before assuming they're the same compound.
- **403 CP20** — no usable hits; likely a vendor-specific code with no independent
  identity.
- **263 GIP3** — ambiguous; could mean human GIP or a GIP/GLP-1/glucagon triple agonist
  ("G3"), can't tell without the original vendor description text.
- **86 Adipotide/FTPP** — real compound, but CAS/MW vary meaningfully by salt form across
  sources (~2611–2617 Da); not confident enough to pick one.

## Skip — confirmed blends, diluents, or biologics without one fixed number

Same treatment as the 2026-07-12 page: all named multi-ingredient combos (3R225, B300/375/
500, MAST Blend-200, RM200, Ripex-225, Sustanon/Supertest/SU-400/T600 testosterone
blends, the Cagri+Sema/Reta+Cagri/Reta+Tirz/Semax+Selank/CJC+Ipamorelin/Tesamorelin+
Ipamorelin combo listings, L-Carnitine+Vitamin Blend, GHK-CU Blend), diluents
(Bacteriostatic/Antibacterial Water, BAC, Acetic Acid water, plain water), the Lipo-C/MIC
family (Lipo-c, Lipo-C with B12, LC120/216/400/526, LMX, Lemon Bottle), and
protein/polymer biologics without one meaningful figure beyond what's captured above
(Botulinum toxin). Cocktail-style products not individually re-researched (GAZ, HHB, SHB,
SHR, SUPER SHRED, GLOW, KLOW, "immunological enhancement," TBFing, Relaxation PM) —
pattern strongly suggests blends, consistent with the rest of this batch.

## Open follow-ups (not acted on)

- Merge id 70 "Adamax" into 414 "Adamax 1032 da" (or vice versa) — same real compound.
- Check whether id 140 "FST 344" duplicates id 404 "Follistatin 344/Fst344".
- Check whether id 405 "Thymosin Beta-4 Fragment 17-23" duplicates ids 2 "TB-500" / 359
  "TB500(Frag)" (already harmonized to the same MW, 889.02, in the 2026-07-12 pass) —
  same duplicate-naming pattern as the two above.
- SLU-PP-322 vs -332, CP20, GIP3, Adipotide/FTPP need a manual vendor-listing check, not
  a database write.
