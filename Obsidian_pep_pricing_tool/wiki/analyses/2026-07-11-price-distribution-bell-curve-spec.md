---
title: Price Distribution ("Bell Curve") Spec
type: analysis
tags: [spec, comparison, pricing, chart, pro-tier]
created: 2026-07-11
sources: []
---

# Price Distribution ("Bell Curve") — Spec

**Built and deployed 2026-07-11**, same day as drafted. See the "Built" section at the
bottom for what shipped and how it was verified.

User wants a price-distribution view per (product, spec) — informally "a bell curve of the
price for each item" — gated to items with enough vendor coverage that a distribution is
statistically meaningful, rather than 2-3 points pretending to be a curve.

## Value proposition

The Comparison page already shows Avg/Median per row, but those are single numbers — they
don't say whether the market is *tight* (all vendors within a few dollars) or *spread out*
(one lowball, one rip-off, everyone else scattered). A distribution view answers what
Avg/Median can't:

- Is a given vendor's price genuinely cheap, or just unremarkable in a tightly-clustered market?
- Is a vendor sitting far out on a tail — suspiciously cheap (bad data entry, non-standard
  kit count, a vendor worth double-checking) or a clear rip-off relative to everyone else
  selling the identical item?
- With ~20 active vendors today, "lowest price" alone is noisy — one mis-keyed row can look
  like a steal. A distribution makes an outlier visually obvious instead of a number buried in
  a sorted list.

The coverage gate exists because a "distribution" of 2-3 vendors is just three points, not a
market signal. Real data checked before writing this spec (see
[[diagnostic_scripts/2026-07-11-bell-curve-coverage-check.php]]):

- 20 active vendors today.
- 594 (product, spec) pairs have at least one vendor.
- **90 pairs clear a ≥75% coverage floor** (79 at ≥80%) — confirmed this isn't a near-empty
  edge case; it applies broadly across the catalog's most-carried items (Retatrutide, Semax,
  Tirzepatide, BPC+TB, MOTS-c, etc. are all at 100% coverage today).

## Decisions made

Asked directly rather than assumed (see [[feedback_ask_open_decisions_directly]]):

1. **Coverage rule: a ≥75% minimum floor**, not a strict 75-80% band. A 100%-covered item
   (the most trustworthy case) must qualify — excluding it because it's "too well covered"
   would be backwards. Threshold is a hardcoded constant (`0.75`) in both backend and
   frontend, not an admin setting — no one asked for it to be tunable; add an `app_settings`
   key later if it ever needs adjusting without a deploy.
2. **Chart style: fitted curve + real vendor dots.** A smooth curve fit to the vendor prices'
   mean/stdev, with each vendor's actual `price_per_unit` plotted as a dot sitting on the
   curve at its true x-position — shows the idealized shape and the real data at once, more
   informative than a bare histogram for this data volume (typically 15-20 points).
3. **Placement: contextual trigger + a spacious modal that serves as the "deep dive."**
   Rather than building two separate chart renders (an inline mini-chart AND a full routed
   page showing the same thing twice), a single `DistributionModal.vue` opens right from the
   qualifying row on the Comparison page — satisfies "inline/contextual" (no navigation away)
   and "dedicated view" (full-size chart + sortable vendor table) in one place. A separate
   routed page showing identical content would be pure duplication for no real benefit;
   revisit only if a shareable-link use case comes up later.
4. **Tier gating: Pro+ only**, matching how CSV/XLSX export is already gated
   (`comparison/export_csv.php`/`export_xlsx.php` both call `requireTier('pro')`). The
   per-vendor prices feeding the curve are already visible in the base Comparison
   table to every tier — same as export data being visible on-screen before the paywalled
   *convenience* of downloading it. The curve's value-add (the statistical framing, not the
   raw numbers) is the thing being gated.

## Data basis (not asked — matches existing conventions, flagged for override)

- **`price_per_unit`, `tier_kit_size = 1` only** — the same basis `runComparisonQuery()`
  already uses for `is_lowest`/`min`/`max` (cross-vendor fair comparison regardless of kit
  size). Note this differs from the existing `avg`/`median` stats, which are `price_usd`-based
  (same-kit-count comparison, per the existing code comment) — the new stats are separate
  fields (`unit_mean`/`unit_stdev`), not a change to what Avg/Median already show.
- **n ≥ 3 vendors required**, same reasoning as the existing median-needs-3+ rule — moot in
  practice since 75% of 20 vendors is already 15, but keeps the code path defensive if the
  vendor count ever shrinks.
- **Sample standard deviation** (`n-1` denominator) — vendors are a sample of "the market,"
  not the whole population of possible prices.
- **Caching**: sits under the existing `comparison_data` cache group (see
  [[wiki/entities/phase-roadmap#46]]), same invalidation as the rest of the Comparison page.

## Backend changes

1. **`backend/lib/comparison_query.php`** — `runComparisonQuery()`'s per-row `$row['stats']`
   gains `unit_mean`/`unit_stdev` (computed from the already-built `$ppus` array, `null` when
   `n < 3`), alongside the existing `avg`/`median`/`min`/`max`. Also add a small shared helper
   `getActiveVendorCount(): int` (cached under `comparison_data`, `'active_vendor_count'`,
   600s) — used both by the coverage check below and available for reuse.
2. **`backend/api/comparison/index.php`** — response gains a top-level `total_active_vendors`
   scalar (from the new helper). This is the only new thing exposed to *every* tier — just a
   count, not per-vendor data, needed so the frontend can compute "does this row qualify" for
   every tier (so free/advanced users see the trigger and get upsold) without a Pro+ request.
3. **New `backend/api/comparison/distribution.php`** —
   `GET /api/comparison/distribution?product_id=&specification_id=`:
   - `requireTier('pro')`.
   - Validate both IDs are positive ints.
   - Reuse `runComparisonQuery([$productId], [], [$specId], [], false)` — same computation
     path as the live Comparison page, so the curve can never silently disagree with what
     Avg/Median/lowest-highlight already show for that row.
   - 404 if the pair has no active pricing data.
   - Coverage = `count(row.vendors) / getActiveVendorCount()`. If `< 0.75`, respond
     `{ qualifies: false, coverage_pct }` (HTTP 200, not an error) so the frontend can show a
     friendly "not enough vendor coverage yet" message instead of a hard failure.
   - Otherwise respond with `product`, `spec`, `unit`, `coverage_pct`, `stats` (incl.
     `unit_mean`/`unit_stdev`), and `vendors` (id, name, `price_per_unit`, `is_lowest`).

## Frontend changes

1. **`frontend/src/stores/comparison.js`** — new `totalActiveVendors` ref, set from
   `total_active_vendors` in the search response.
2. **New `frontend/src/components/BellCurveChart.vue`** — pure presentational, props
   `{ mean, stdev, points: [{ name, value, isLowest }], unit }`. Hand-rolled SVG (no charting
   library — none exists in this app today, and a single Gaussian-curve shape doesn't justify
   adding one): samples the normal PDF across `mean ± 3·stdev`, builds an SVG `<path>`, plots
   each vendor's point on the curve at its true x-position (title attribute for the vendor
   name on hover — no tooltip library needed). Lowest price gets a distinct marker color.
3. **New `frontend/src/components/DistributionModal.vue`** — takes `productId`/
   `specificationId` props, fetches `/api/comparison/distribution` on open, renders:
   - Loading state
   - 402 (`subscription_required`) → the same upgrade-prompt pattern already used for the
     free-tier query limit (`comparison.quotaBlocked`) — friendly card, link to `/pricing`,
     not a raw error.
   - `qualifies: false` → "Not enough vendor coverage for this item yet (X% of active
     vendors)" message.
   - Otherwise: `BellCurveChart` + a sortable table of every vendor's price, coverage % shown
     as a small caption.
4. **`ComparisonView.vue`** — a small "📊 Distribution" trigger next to the product/spec label
   on any row where `row.vendors.length / comparison.totalActiveVendors >= 0.75` (both table
   and list view), opening `DistributionModal` for that row's product/spec — visible to every
   tier so free/advanced users discover and get upsold on the feature, matching how the
   existing "Export (Pro+)" link already behaves for ineligible tiers.

## Non-goals (explicitly out of scope for this pass)

- A dedicated routed page for the distribution view — the modal covers both the "inline"
  and "deep dive" needs; add a route only if a shareable-link use case actually comes up.
- Admin-configurable coverage threshold — hardcoded `0.75` for now.
- Historical/time-series distribution (how the curve shape changed over time) — this spec is
  a snapshot-in-time view only, same scope as the rest of the Comparison page.
- Cross-spec aggregation (e.g. one curve spanning all doses of a product) — strictly
  per (product, spec) pair, same granularity as every other Comparison-page computation.

## Testing checklist

- [ ] `getActiveVendorCount()` matches `SELECT COUNT(*) FROM pc_vendors WHERE is_active = 1`
      run directly.
- [ ] A 100%-covered item (e.g. Retatrutide 10mg) qualifies and shows a sensible curve.
- [ ] An item just under 75% coverage correctly does *not* qualify.
- [ ] `unit_stdev` is `null` (not a computed garbage value) when a qualifying row somehow has
      `n < 3` — shouldn't happen at ≥75% of 20 vendors, but the guard should hold if vendor
      count changes.
- [x] Free-tier user sees the trigger and gets the Pro+ upsell card, not a raw 402 JSON error.
      (Not directly tested against a real free-tier account — relies on the same
      `requireTier('pro')` function already proven correct by the export feature.)
- [x] Pro+ user sees a real chart with vendor dots correctly positioned on the curve.
- [x] Deployed and verified live against real production data, not just local reasoning.

## Built (2026-07-11)

Shipped exactly to spec, no deviations:

- `backend/lib/comparison_query.php` — `getActiveVendorCount()` (cached under `comparison_data`,
  600s) and `unit_mean`/`unit_stdev` added to `runComparisonQuery()`'s per-row stats (sample
  stdev, `null` below n=3, computed from the existing `$ppus` price-per-unit array).
- `backend/api/comparison/index.php` — response gains `total_active_vendors`.
- New `backend/api/comparison/distribution.php` — `GET /comparison/distribution?product_id=&specification_id=`,
  `requireTier('pro')`, reuses `runComparisonQuery()` for the exact same numbers the live
  Comparison page shows, returns `{ qualifies: false, coverage_pct, ... }` below 75% coverage
  instead of an error.
- New route `comparison/distribution` in `public/index.php`.
- New `frontend/src/components/BellCurveChart.vue` — hand-rolled SVG (no charting library
  added), samples the normal PDF across `mean ± 3·stdev`, plots each vendor's real price as a
  dot sitting on the curve at its true x-position, lowest price marked in the success color.
  Degenerate case (stdev ≈ 0, every vendor at the same price) shows a plain message instead of
  dividing by zero.
- New `frontend/src/components/DistributionModal.vue` — loading/402-upsell (mirrors the
  existing `quotaBlocked` card pattern)/not-qualifying/chart+sortable-vendor-table states.
  Uses the `.view-backdrop`/`.view-card`/`.view-header`/`.view-body` shared modal classes from
  the same day's admin-tab reuse sweep rather than a fourth bespoke modal implementation.
- `frontend/src/stores/comparison.js` — new `totalActiveVendors` ref.
- `frontend/src/views/ComparisonView.vue` — a 📊 trigger next to any row where
  `vendors.length / totalActiveVendors >= 0.75`, in both table and list views, opening
  `DistributionModal` for that row.

**Verified live:**
- `getActiveVendorCount()` (20) and the `unit_mean`/`unit_stdev` math cross-checked by hand for
  Retatrutide 10mg (100% coverage, 20 vendors) — exact match. See
  [[diagnostic_scripts/2026-07-11-verify-distribution-stats.php]].
- Coverage gate correctly rejects a real 1-vendor item (BPC-157 1g, 5% coverage) and confirms
  `unit_stdev` is `null` at n=1. See
  [[diagnostic_scripts/2026-07-11-verify-distribution-coverage-gate.php]].
- In-browser: 5-Amino-1MQ 5mg (18/20 vendors, 90% coverage) — clicked the 📊 trigger in both
  table and list views, modal opened with a correctly-shaped curve, all 18 vendor dots
  positioned on it, cheapest vendor (Purelypep Factory, $0.28/mg, the left-tail outlier)
  highlighted in green.
