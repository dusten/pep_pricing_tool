---
title: TB-500 Product Re-identification as Thymosin Beta-4 (B4)
type: analysis
tags: [tb-500, thymosin-beta-4, cas-number, nomenclature-risk, variant-compound]
created: 2026-07-12
sources: []
---

# TB-500 Product Re-identification as Thymosin Beta-4 (B4)

Earlier this session (see [[wiki/analyses/2026-07-12-duplicate-product-audit]] and
[[wiki/analyses/2026-07-12-product-cas-mw-research]]), product 2 "TB-500" was assigned
the 7aa fragment's CAS (`885340-08-9`), based on this project's own [[wiki/entities/tb-500]]
page's claim that "TB-500 almost always means the fragment" — a general market-pattern
assumption, not a close read of this catalog's own vendor data.

User pushed back: "most of the TB-500 / TB500 are the Long version of B4," and asked for a
full reprocessing of every vendor's raw Claude extraction JSON, with an explicit default
rule: **assume TB-500 = B4 (long form) unless the listing itself says B5/Frag/17-23, or a
second TB-500 line exists in the same vendor's results.**

## Method

Queried `pc_claude_call_log` for every row mentioning TB-500/TB500/Thymosin/17-23 — 35
call-log rows across 18 vendors, going back to the original per-vendor extraction JSON
rather than the current (possibly already-wrong) product assignment in the DB.

## Findings, by vendor

**Explicit B4 confirmation** (Claude read "Thymosin B4 Acetate" directly off the vendor's
own document, alongside a separately-priced, separately-SKU'd Frag line):
- Jenny Peptide — `TB500(Thymosin B4 Acetate)` BT2/BT5/BT10/BT20, separate `TB500(Frag)` B10F
- Nina — `TB500(Thymosin B4 Acetate)` BT2/BT5/BT10, separate `Frag 17-23` line
- Premipeptides — `TB500(Thymosin B4 Acetate）` BT2/BT5/BT10 (one run), separate `Frag 17-23`
  line at a distinctly lower price (~$39), consistent across both reprocessing runs of the
  file

**Separate Frag line exists, main line unqualified but distinctly priced/SKU'd from Frag**
(default to B4 per the rule):
- Laicuinuo LCN — `TB-500` BT2/BT5/BT10 vs. separate `TB-500(Frag/B5/889/17-23)` B10F
- LCN — `TB-500` BT2/BT5/BT10 vs. separate Frag-labeled B10F **and** FRAG10 lines
- Peptide Research Solutions — `TB500` BT2/BT5/BT10 vs. separate `Frag 17-23` (FG sku)

**Single unqualified line, no second entry** (default to B4 per the rule) — and notably,
**nearly every one of these was flagged ambiguous by Claude's own extraction**, never
resolving to the fragment on its own:
- Guangzhou Guangjin Trading Co., Ltd. — "tb-500 naming ambiguity - no CAS given" (both
  extraction runs); also carries a genuinely separate raw-material-tier Frag line
  (`TB-500(Frag/B5/889/17-23)`, 1g, $185.38) alongside its own unqualified 1g line ($741.52)
- HKpep — "ambiguous compound: TB-500 name may refer to multiple molecules"
- NOVI OREA INTERNATIONAL LIMITED — unqualified, no warning
- Norco Peptides — "TB-500/TB500 is on variant-compound watchlist; no CAS number given"
- protidexbio peptide LTD Factory — unqualified, no warning
- Purelypep Factory — "ambiguity: TB-500 may refer to 7aa fragment or Thymosin Beta-4"
  (one run), unqualified (other runs)
- Tiancheng Biotechnology Co., Ltd. — unqualified, no warning
- Tidetron Peptide — "naming ambiguity - no CAS provided to confirm 7aa fragment vs full
  Thymosin Beta-4"
- Tingpeptide — unqualified, no warning
- Zhongke Meiye Pharmaceutical Co., Ltd. — "TB-500 name is ambiguous - could refer to 7aa
  fragment or full Thymosin Beta-4, no CAS given"

**Genuinely inconsistent across reprocessing runs** (Claude guessing, not reading a real
signal — no second entry ever appeared, so defaulted to B4):
- CALLA — plain `TB-500` (first run) vs. `TB-500(Frag/B5/889/17-23)` (later run) for the
  *identical* BT5/BT10 sku and price
- Golden Age — same flip-flop pattern for the identical BT2/BT5/BT10 sku/price

## Applied fix

`migration_scripts/2026-07-12-reidentify_tb500_as_b4.php`:
- **Product 2 "TB-500"** re-identified as the long form: `cas_number = 77591-33-4`,
  `molecular_weight = 4963.4`. Canonical name left unchanged ("TB-500" is the market-standard
  ambiguous term every vendor and buyer actually uses — the CAS/PubChem-link feature built
  earlier this session exists precisely to resolve this kind of ambiguity without needing a
  name change).
- Moved product 2's 3 fragment-describing aliases (`TB-500(Frag/B5/889/17-23)` etc.) onto
  **product 359 "TB500(Frag)"** instead — they describe the fragment, not B4, and were on
  the wrong product.
- This reverses `migration_scripts/2026-07-12-fix_tb500_mislabeled_alias.php` from earlier
  this session, which deleted the one alias (`TB500(Thymosin B4 Acetate)`) that — with this
  deeper per-vendor evidence in hand — was actually the *correct* one for product 2, not a
  mislabeling as originally concluded.
- Fixed 2 price rows discovered misfiled under product 2 during this reprocessing, both
  confirmed as the fragment by matching SKU/price against Jenny Peptide's already-correctly-filed
  Frag row: **Lucy** ($85, sku `B10F`) moved to product 359's existing 2mg spec; **Premipeptides**
  ($39.71, no sku, "10mg") moved to product 359 on a newly-created 10mg spec (none existed
  there before).

**Not auto-fixed, flagged for a future decision**: 4 vendors (Laicuinuo LCN $198/`B10F`, LCN
$220/`B10F` + $89/`FRAG10`, Guangzhou $185.38 raw-material 1g, Peptide Research Solutions
$190/`FG`) had a genuinely distinct Frag-tier line in their raw extraction JSON that never
survived as a separate active price row in the DB — it looks like it was silently overwritten
by the plain TB-500 line landing on the same `specification_id` during import (both extracted
to the same spec label, e.g. "10mg", so only one survived the unique constraint). Reconstructing
these from months-old JSON risks stale pricing, so left for a deliberate decision rather than
auto-restored here.

## Verification

- Live DB check: `pc_prices` vs. `pc_specifications` product_id consistency — 0 mismatches.
- Confirmed on the Comparison page (`TB-500` search): every non-fragment vendor row now shows
  `CAS 77591-33-4 · 4963.4 g/mol`; Lucy's product-2 rows (5mg/10mg/20mg, $85/$169/$310) are
  unaffected and correctly still B4; her fragment row is gone from product 2's 2mg column
  (moved to 359).

## Root cause: preventing recurrence for future vendor imports

User asked how to keep these two products correctly labeled going forward. Two real,
grounded mechanisms explain how the original misassignment happened and now propagate to
future imports:

1. **`backend/lib/claude.php`'s extraction system prompt had the wrong assumption baked in**
   (rule 8): *"TB-500" usually means the 7aa fragment, not full Thymosin Beta-4* — the exact
   claim this analysis disproves. Worse, this likely nudged Claude toward the actual bug
   observed in the CALLA/Golden Age flip-flop: instead of transcribing the vendor's literal
   text (rule 7 requires this), Claude sometimes appended a guessed qualifier —
   `"TB-500(Frag/B5/889/17-23)"` — that wasn't present in the source document.
2. **That hallucinated annotation only "stuck" because of how import matching works.**
   `findExactProductMatch()` (`backend/lib/price_import.php`) matches a new listing's
   `canonical_name` against `pc_products.canonical_name` **or any existing alias**, and
   auto-commits on a hit with no human review. Product 2 held those exact fragment-describing
   aliases at the time, so even Claude's fabricated annotation auto-committed straight back to
   product 2 — silently, because it counted as an "exact" match.

**Fixed**: rewrote rule 8 in `backend/lib/claude.php` to (a) drop the wrong default-assumption
claim, (b) explicitly reinforce that `canonical_name` must stay exactly as the vendor wrote it
for watchlisted names too — no inline qualifier, ever, since an annotated name gets matched as
if it were a distinct product instead of being flagged for review — and (c) instruct Claude to
extract both lines untouched when a vendor genuinely lists two watchlisted-name rows. Also
added `"warning"` to the response shape's per-price example object — it was already being set
inconsistently (sometimes `"warning"`, sometimes `"warnings"` as an array) because the schema
example never defined it, so the app had no stable key to read even if it wanted to. Deployed
live, `php -l` clean, smoke check passed.

**The alias-hygiene fix already made in this session (moving product 2's 3 fragment aliases
onto 359) is the more durable fix long-term**: as long as each product's alias list only
contains names that truly describe that molecule, future imports route correctly on exact-match
even if Claude's naming drifts again — the routing doesn't depend on the prompt being perfect.

**User decided**: show warnings in the Review Queue, skip the auto-commit routing change (b).
`ReviewQueueTab.vue` now renders a `⚠` badge above the editable fields whenever
`raw_json.warning` (or the older `raw_json.warnings` array, for rows extracted before the
schema was standardized) is present — reusing the existing `.badge-coa-pending` style, so an
admin reviewing a pending row sees Claude's own ambiguity flag before approving it. This still
only covers rows that reach the pending queue (`new_product`/`new_spec`/`name_mismatch`) — a
listing that exact-matches an existing product/alias (like every plain "TB-500" line, which
matches product 2's own `canonical_name`) still auto-commits with no review, same as before;
that's the deliberately-skipped option (b). Verified: `php -l`/`npm run build` clean, deployed,
confirmed live on a real pending row (no warning present, badge correctly absent — the
`v-if` guard works as intended).
