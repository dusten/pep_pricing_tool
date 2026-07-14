---
title: Variant Compounds Watchlist
type: concept
tags: [variants, watchlist, nomenclature, vendor-risk]
created: 2026-06-30
sources: [epithalon-vs-n-acetyl-epitalon-amidate, tb-500-variants]
---

# Variant Compounds Watchlist

Compounds where the same common name covers meaningfully different molecules. Weekly scan checks vendor clippings for these names and flags which specific variant they're offering.

## How to use this page

The weekly scan reads this table. When a clipping mentions any name in the **Watch Names** column, it flags that entry and notes which vendor and which specific form they list.

---

## Epithalon / Epitalon

Parent page: [[wiki/entities/epithalon|Epithalon]]

| Variant | Formal Name | Sequence | CAS | MW |
|---------|------------|---------|-----|-----|
| Base | Epitalon (Epithalon, AEDG) | H-AEDG-OH | 307297-39-8 | 390.35 g/mol |
| Modified | N-Acetyl Epitalon Amidate | Ac-AEDG-NH₂ | N/A | ~431.4 g/mol |

**Watch names:** `epitalon`, `epithalon`, `epithalone`, `aedg`, `n-acetyl epitalon`, `acetyl epitalon amidate`, `ac-aedg`

**Why it matters:** The modified form (Ac-AEDG-NH₂) has significantly higher metabolic stability and extended half-life but ~10× lower dosing. Vendors sometimes list both or mislabel them interchangeably.

---

## TB-500 / Thymosin Beta Family

Parent page: [[wiki/entities/tb-500|TB-500]]

| Variant | Common Label | Sequence | CAS | MW | Type |
|---------|-------------|---------|-----|-----|------|
| Full peptide | Thymosin Beta-4 Acetate (TB4) | Ac-SDKPDMAEIEKFDKSKLKKTETQEKNPLPSKETIEQEKQAGES | 77591-33-4 | ~4,963 Da | Peptide (natural) |
| Fragment | TB-500 / Thymosin B5 Acetate | Ac-LKKTETQ | 885340-08-9 | ~889 Da | Peptide (synthetic) |
| Impostor | TB5 (MAO-B inhibitor) | (E)-1-(5-Bromothiophen-2-yl)-3-[4-(dimethylamino)phenyl]prop-2-en-1-one | 948841-07-4 | 336.25 Da | **Small molecule — NOT a peptide** |

**Watch names:** `tb-500`, `tb500`, `tb 500`, `thymosin beta-4`, `thymosin b4`, `thymosin b5`, `tb-500 fragment`, `tb500 fragment`, `tb frag`, `17-23`, `lkktetq`, `fequesetide`, `tb5`, `tb-5`

**Why it matters:** naive assumption would be "TB-500" usually means the 7aa fragment, but a close read of this project's own vendor extraction data found the opposite for this catalog — the large majority of plain "TB-500"/"TB500" listings are the full-length 43aa TB4 (B5/885340-08-9 is only the fragment when a vendor carries it as a separate, distinctly-priced line explicitly marked Frag/17-23) — see [[wiki/entities/tb-500]] and [[wiki/analyses/2026-07-12-tb500-b4-reidentification]]. TB5 (CAS 948841-07-4) is a small-molecule MAO-B inhibitor with zero relation to thymosin — at least one vendor (Biosynth) has mislabeled it as a thymosin peptide. CAS number is the only reliable differentiator.
