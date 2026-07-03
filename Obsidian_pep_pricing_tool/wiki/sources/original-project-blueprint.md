---
title: Original Project Blueprint — Founding Prompt
type: source
tags: [blueprint, spec, schema, claude-prompt, export-formatting, sort-order]
created: 2026-07-03
sources: []
---

# Original Project Blueprint — Founding Prompt

**Origin:** Pasted directly into conversation on 2026-07-03 by the user ("here's the Original Prompt that started this project as you might not have it") — not a `raw/clippings/` file. This is the actual prompt that specified the app before any of it was built; it had never been ingested into the wiki before, which is why earlier questions about design intent (e.g. spec sort order) had no answer on file. See [[wiki/analyses/2026-07-03-blueprint-vs-actual]] for the full comparison against the current app.

## Key takeaways — original design intent

- **Stack as originally specified**: Vue 3 (Composition API + Pinia + Vite), PHP 8.2 REST API, MariaDB 10.6+, Claude model `claude-sonnet-4-20250514`. Actual: `claude-sonnet-5` default, `claude-opus-4-8` as an admin-selectable hard-mode override — a deliberate upgrade, not a drift.
- **Original scope was an internal admin tool** — no auth, no users, no subscriptions. Top-level pages: Dashboard | Vendors | Products | Comparison | Export, via a sidebar. The app instead became a subscription SaaS (confirmed intentional by the user 2026-07-03 — "there's value in the data I've collected and we will keep down that path").
- **Spec sort key (the rule that explains the sort-order question asked earlier)**: "Extract numeric value from spec label (5mg→5, 10mg→10, 0.1mg→0.1, 100mg→100). Sort numerically NOT lexicographically. 5 < 10 < 100, not '100' < '5'." This is why `pc_specifications.numeric_value` is a `DECIMAL(10,4)` column separate from the display `spec_label` string in the first place — the schema was built to support this from day one.
- **`price_per_unit` was specified as a generated column**: `DECIMAL(12,6) GENERATED ALWAYS AS (price_usd / (SELECT numeric_value FROM specifications WHERE id = specification_id)) STORED`. The actual schema computes it in PHP at write time instead — see [[wiki/analyses/2026-07-03-blueprint-vs-actual]] for why this became a real bug source.
- **File-replacement model**: "Keep old prices but mark is_active=false; Insert new prices with is_active=true; Old file record remains downloadable." Actual behavior updates the matching row in place (`ON DUPLICATE KEY UPDATE`), so this was never actually built as specified — no real price history exists yet (backlogged 2026-07-03).
- **Claude system prompt rules** (original, 6 core rules — actual has 9, see comparison doc): tiered pricing → use 1-kit price only; USD only, RMB→USD at a fixed 7.2 rate; skip X/—/blank entries; non-standard kit sizes (1/5/6 vials) — the original wording was internally ambiguous ("skip... set flag=true and include a warning"); mcg→mg normalization; combo products sum to total mg.
- **Frontend architecture as specified**: 5 Pinia stores (`vendorStore`, `fileStore`, `productStore`, `comparisonStore`, `exportStore`), dedicated components (`VendorModal`, `FileUploadModal`, `ComparisonTable`), composables (`usePolling`, `useFileUpload`, `useHighlight`). Actual: only `auth.js`, `comparison.js`, `quota.js`, `settings.js` — admin tabs call the API directly rather than through per-domain stores.
- **Business logic specified exactly as built**: lowest-$/unit highlight (ties all highlighted), avg/median computed only from vendors with a price for that row, non-standard-kit tooltip wording ("Listed as {n}-vial kit — $/unit may not be comparable") — all three match the current implementation closely, including near-identical tooltip text.

## Excel/CSV export formatting spec (verbatim structure — not yet built)

A second, reusable prompt was provided alongside the blueprint specifically for generating `vendor_price_per_mg.xlsx` / `vendor_price_comparison.csv` from a batch of vendor files. Preserved here in full since no code implements this yet (see backlog item 5, 2026-07-03) and the exact formatting would otherwise have to be re-derived from scratch when it's eventually built:

**CSV**: `Product | Specification | Vendor_A | Vendor_B | ...` — one column per vendor, sorted alphabetically by product then numerically by specification.

**Excel**:
- Row 1: merged vendor-name headers (2 cols each), dark navy `#2F5496`, white bold Arial 9
- Row 2: sub-headers "Price ($)" / "$/unit" per vendor, medium blue `#4472C4`, white bold Arial 8
- Data rows: alternating white / light blue `#EEF2F9`; $/unit column slightly darker (`#E2E8F4` / `#F5F8FF`), italic Arial 8
- Green highlight `#C6EFCE` (bold dark green text `#276221`) on the lowest $/unit cell *and* its paired price cell, per row
- Two dark-green (`#375623`) stat columns after the last vendor: Avg Price, Median Price — from whichever vendors have a price for that row
- Column widths: A=32, B=13, vendor price cols=10, $/unit cols=9, stat cols=13/14
- Freeze panes at C3; row 1 height 22, row 2 height 16; all cells thin-bordered `#D0D0D0`

Canonical product name list given as an example in this second prompt (useful as a cross-check against the live alias table, not necessarily exhaustive): BPC-157, TB500, GHK-Cu, NAD+, MOTS-C, CJC-1295 w/o DAC, CJC-1295 w/ DAC, CJC-1295 w/o DAC + Ipa, GLOW (BPC+GHK-Cu+TB500), KLOW (BPC+TB500+GHK-Cu+KPV), Cagrilintide + Semaglutide, Retatrutide + Tirzepatide, HGH 191AA, HGH Fragment 176-191, Acetic Acid Water, Lipo-C with B12, ARA-290 (Cibinetide).

## Entities / concepts referenced

- [[wiki/entities/phase-roadmap|Phase Roadmap]]
- [[wiki/concepts/variant-compounds|Variant Compound Watchlist]] — the original blueprint's combo-spec rule is the ancestor of this later, more detailed watchlist
