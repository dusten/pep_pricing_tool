---
title: Flagged Duplicate/Conflicting Listings Needing Vendor Research
type: analysis
tags: [duplicate-listings, review-queue, vendor-verification, open]
created: 2026-07-14
sources: []
---

# Flagged Duplicate/Conflicting Listings Needing Vendor Research

Surfaced during the [[wiki/analyses/2026-07-14-incomplete-spec-drop-bug]] "fifth wave"
full-catalog duplicate audit (backlog: user spotted TB-500 10mg/Nina listed twice, asked
for a full review). These 11 are **not** in the Review Queue (`pc_pending_imports`) —
every row here is an already-committed, currently **active** price (`is_active=1`), still
live on the Comparison page right now. They don't fit the established reprocess-mishap
pattern (traced each to its actual Claude extraction call — nearly all came from a single
extraction pass reading two genuinely distinct rows out of the vendor's own source
document), so no mechanical fix was applied. Each needs either a look at the vendor's
current real price sheet, or a judgment call on which listing (if either) is right.

## How to research each one

Pull up the vendor's Inventory tab row or the product on the Comparison page, compare
against the vendor's actual current price list/screenshot on file (Files tab), and decide:
keep one (deactivate the other via the Inventory tab's hide toggle), keep both if
genuinely two real distinct listings, or correct a miscategorized product/spec if that's
the real issue.

## The list

| Vendor | Product / Spec | Tier | Listing A | Listing B | Note |
|---|---|---|---|---|---|
| Golden Age | Cagrilintide + Semaglutide 10mg | 1-kit | `CS10` $188 | `CD5` $238 | "CD5" reads like a plain Cagrilintide 5mg code — possibly filed onto the wrong product |
| Mamoth biotechnology | Semaglutide 5mg | 1-kit | `SM05` $33 | `GP5` $105 | The "GP5" row's `canonical_name` was literally extracted as **"GLP-1"** (the drug class, not Semaglutide specifically) — genuine product-identity question |
| Mamoth biotechnology | Semaglutide 10mg | 1-kit | `SM10` $52 | `GP10` $180 | Same "GLP-1" issue as above |
| keruihk (https://keruihk.net/) | Adipotide/FTPP 2mg | 1-kit | `ADP2` $75 | `AP2` $90 | AP-coded consistently priced higher than ADP-coded across all 3 doses below — looks like a real pattern, not noise |
| keruihk | Adipotide/FTPP 5mg | 1-kit | `ADP5` $130 | `AP5` $200 | same AP/ADP pattern |
| keruihk | Adipotide/FTPP 10mg | 1-kit | `ADP10` $225 | `AP10` $360 | same AP/ADP pattern |
| keruihk | Mazdutide 10mg | 1-kit | `MZ10` $190 | `MDT10` $330 | Spans **two different source files** (two separate WhatsApp screenshots from the same vendor, same day) — looks like an inconsistency between two photos of their own list |
| LCN | TB500(Frag) 10mg | 1-kit | `FRAG10` $89 | `B10F` $220 | 2.5x price gap, same extraction call |
| Lucy | GHK-Cu 50mg | 10-kit | `CU50` $28 | `GH50` $37 | Same extraction call |
| Lucy | Selank 30mg | 10-kit | `SK30` $145 | `NSK30` $155 | Same extraction call — "N" prefix might mean "New" formulation |

(Mazdutide and the Cagrilintide+Semaglutide row are single entries; the Semaglutide and
Adipotide/FTPP rows above cover 2-3 dose variants each of the same underlying conflict —
10 table rows, matching the "11 flagged" count referenced in log.md once Golden Age's
single row is counted separately from Mamoth's two dose rows.)

## Already resolved from the same batch (context, not part of this list)

- Changsha Xjun's NAD+ 1000mg and Melanotan 1 10mg blank-SKU rows — confirmed via the
  source spreadsheet's shared-strings table that only one real SKU exists for each;
  deactivated.
- CALLA's L-Carnitine 10mg — 2 of its "duplicate" SKUs (`LC120`, `LC216`) turned out to be
  Lipo-C tier codes, not L-Carnitine codes; moved to the correct products (Lipo-c / Lipo-C
  with B12).

See `log.md`'s 2026-07-14 "fifth wave" entry for the full 75-group audit and the 62
reprocess-mishap duplicates that were fixed alongside these.
