---
title: Bulk Product CAS Number / Molecular Weight Research
type: analysis
tags: [products, cas-number, molecular-weight, backlog-58, pubchem]
created: 2026-07-12
sources: []
---

# Bulk Product CAS Number / Molecular Weight Research

Backlog #58 follow-up. After shipping per-product CAS/MW fields + the PubChem-link
feature (see [[wiki/entities/phase-roadmap]] #58), user asked to bulk-populate CAS/MW
for all 195 catalog products "using known research data."

## Method

Four parallel research passes (forked agents, WebSearch + WebFetch against PubChem,
DrugBank, ChemicalBook, and manufacturer datasheets — never relying on memory alone):

1. Three parallel batches split the full 195-product catalog roughly into thirds by id.
2. Two of the three hit a transient WebSearch/WebFetch tool outage partway through and
   had to mark some real, resolvable compounds SKIP-UNCERTAIN purely because the tools
   were down (not because the compound is unresearchable) — flagged explicitly rather
   than guessed.
3. A fourth retry pass re-ran only the outage-affected items once tools recovered,
   resolving most of them for real.
4. Final manual reconciliation pass (this session, not forked) caught 2 cross-batch
   data-quality issues before anything touched the database (see Corrections below).

**Result: 117 of 195 products (60%) resolved** with a web-verified CAS number; 116 of
those also have a molecular weight (one, EPO, has CAS only — see below).

## Deliberately left NULL — not guessed

- **Multi-ingredient blends/combos** (no single CAS applies to a mixture): GLOW, KLOW,
  Lipo-C/MIC variants (LC120/216/400/526, Lipo-C with B12), Sustanon/SU-400/Supertest,
  RM200/3R225/B300/B375/B500, MAST Blend-200, HHB/LMX/GAZ/SHR blends, every "X + Y
  combo" product, GHK-CU Blend, Relaxation PM (Melatonin+GABA+Arginine+Glutamine,
  confirmed via vendor listings), Cerebrolysin, Thymalin, HMG, Bacteriostatic/
  Antibacterial Water, T600, Ripex-225, NANDROMIX-300.
- **Real compounds with genuinely conflicting or unregistered CAS across sources**
  (checked, not skipped out of laziness): MGF, SLU-PP-322 (every source only documents
  the -332 compound; one vendor's "-322" listing has no independent chemical identity),
  FST 344 (3 conflicting CAS candidates), DHB (product name conflates two different
  compound concepts — plain 1-Testosterone/dihydroboldenone vs. its cypionate ester —
  and the ester's own CAS couldn't be confirmed even once the ambiguity was resolved),
  NA Selank amidate (one source pairs a plausible-looking CAS with an obviously wrong
  small-molecule formula for a 9-residue peptide), TBFing (genuinely unidentifiable —
  no vendor listing, forum reference, or product page found anywhere), Adipotide/FTPP
  (3 different CAS cited for different salt forms, MW varies 2557–2617), CJC-1295 with
  DAC (2 conflicting CAS across reputable-looking sources), P21/P021 (no CAS ever
  registered per MedKoo), most Khavinson bioregulator tetrapeptides — Cardiogen,
  Cortagen, Crystagen, Bronchogen, Testagen, Pancragen, Prostamax, Chonluten, Ovagen,
  Vesugen, Cartalax (sequences are known, but almost none have a real registered CAS,
  and the few candidate CAS strings found either don't validate as real CAS format or
  don't independently corroborate — one source appears to have contaminated data,
  citing NAD+'s own CAS for Follistatin), N-Acetyl Epitalon Amidate (distinct from
  plain Epithalon — confirmed its own CAS/MW fields are blank on its dedicated
  ChemicalBook page, did not reuse Epithalon's CAS).
- **Heterogeneous biologics with no single fixed MW**: B12 (vendor form ambiguous —
  cyanocobalamin vs. methylcobalamin), Insulin-form ambiguity resolved to standard
  recombinant human insulin, HCG (large glycoprotein heterodimer, MW isn't a fixed
  small-molecule figure), Botulinum toxin (150kDa monomer to 900kDa native complex),
  ACE-031 (glycosylated fusion protein, 57–120kDa depending on state), PEG-MGF
  (PEGylation creates polymer-length heterogeneity, no fixed MW for the conjugate as
  sold), EPO (see below).

## Corrections made during final reconciliation (before writing to the DB)

- **id 29 "GHK-Cu"**: one research pass paired the WRONG CAS (`49557-75-7`, which is
  plain GHK with no copper) with the copper-complex's own MW. Real GHK-Cu (copper
  tripeptide-1) is `89030-95-5` / 403.93 g/mol. Plain GHK itself is the separate catalog
  entry `id 356 "GHK basic"` (`49557-75-7` / 340.38) — correctly distinguished after the
  fix; verified live, both render distinctly on the Comparison page under a "GHK" search.
- **ids 2 "TB-500" and 359 "TB500(Frag)"**: same real 7aa fragment molecule (already
  confirmed via this project's own prior variant-compound research this session, see
  [[wiki/entities/tb-500]]), represented as two separate catalog entries. Two research
  passes returned slightly different MW figures (877.99 vs. 889.02) for what's the same
  molecule — harmonized both to 889.02, matching this project's own already-published
  wiki figure ("~889 Da") rather than carrying two inconsistent numbers for one compound.
- **ids 217/218/219**: steroid esters named without a vendor SKU prefix
  ("Drostanolone Propionate (Masteron)", "Drostanolone Enanthate (Masteron)", "Dianabol
  (Methandrostenolone)") are literally the same compounds as ids 231/232/233 (same
  esters, just different vendor-shorthand catalog names elsewhere in the same session's
  earlier duplicate-audit work) — filled via direct cross-reference, no separate search
  needed.
- **ids 106/123/352**: all three are the same real compound (recombinant human growth
  hormone, 191aa/Somatropin) under three different catalog names — same CAS/MW applied
  to all three (`12629-01-5` / 22125) for consistency.
- **ids 12/350 ("IGF-1 LR3" / "IGF-LR3/1")**: same real compound under two different
  catalog names. A CAS conflict (`946870-92-4` vs. `143045-27-6`) was resolved in favor
  of `946870-92-4` — 5 independent sources vs. only 1-2 weak mentions of the alternate —
  applied to both.
- **id 131 "EPO"**: CAS confirmed (`113427-24-0`, epoetin alfa) but no trustworthy MW
  source was found for the ~30kDa glycoprotein (the one source with a specific number
  was clearly wrong for a 166-residue protein) — CAS-only row, molecular weight left
  NULL rather than using an implausible figure.

Note for a future session, not acted on here (out of scope for a CAS/MW task): ids
106/123/352 being the same real compound under 3 different catalog names is the same
kind of duplicate-naming pattern backlog #28's audit already resolved for other
products — worth a look next time a duplicate-cleanup pass runs.

## Applied

`migration_scripts/2026-07-12-bulk_populate_cas_mw.php` — single transaction, 117 rows.
Verified live: `SELECT COUNT(*) ... SUM(cas_number IS NOT NULL), SUM(molecular_weight IS
NOT NULL)` → 117 / 116 across 195 total products; spot-checked the GHK-Cu/GHK basic
distinction and several other rows directly on the Comparison page.
