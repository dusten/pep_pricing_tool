---
title: Original Blueprint vs. Actual App — Gap Analysis
type: analysis
tags: [gap-analysis, backlog, sort-order, price-per-unit, export, saas-pivot]
created: 2026-07-03
sources: [original-project-blueprint]
---

# Original Blueprint vs. Actual App — Gap Analysis

User provided [[wiki/sources/original-project-blueprint|the original founding prompt]] and asked for an honest comparison against the current app. Comparison was done by reading the actual current schema/prompt/routes directly, not from memory. Full detail in the session log ([[sessions/2026-07-03]]); this page is the durable reference for what was found and decided.

## The single biggest divergence

Blueprint = internal admin tool (no auth, no users). Actual = subscription SaaS (waitlist, tiers, quota, referrals, Stripe billing backlogged). **Confirmed intentional by the user, 2026-07-03**: "there's value in the data that I have collected and we will keep down that path and the pricing reflects that." Not a drift — a deliberate pivot that had never been explicitly logged anywhere until now.

## Gaps found, and what happened to each

| Gap | Disposition (2026-07-03) |
|---|---|
| Excel/CSV export doesn't exist (`/api/comparison/export/*`, `php_xlsxwriter` — neither present despite CLAUDE.md previously claiming the library was placed) | Backlogged — Pro tier gets Excel/CSV (blueprint's exact formatting spec, preserved in [[wiki/sources/original-project-blueprint]]), Expert tier gets full data as JSON |
| `price_per_unit` computed in PHP, not a `GENERATED ALWAYS AS ... STORED` column as specified | **Also missing the kit-vial-count factor** — user caught this independently: correct formula is `price_usd / (kit_vial_count * numeric_value)`, current code everywhere computes `price_usd / numeric_value` alone, overstating $/mg by a factor of `kit_vial_count` wherever it isn't 1. Backlogged as a job, likely admin-triggered per-vendor from the Inventory tab, not a blanket live change |
| No real price history — new uploads overwrite the matching row instead of preserving it | Backlogged — user says this work was already planned to start "after this first import" |
| Missing `vendorStore`/`fileStore`/`productStore`/`exportStore`; admin tabs call the API directly instead | **Not a gap worth closing** — user confirmed the simpler direct-call pattern is fine to keep |
| Vendor file storage only covers `price_list` and `coa` categories | Backlogged, no specifics yet on what other categories are needed |
| Referral credits stored as a dollar amount (`pc_referral_credits.amount_usd`) | User: should be corrected before Stripe billing is built — a referral should credit N months of service at the referrer's current tier, not a dollar payout. Settings page. |
| **Spec sorting** — user's original question and, per the user, "the biggest issue" | **Found and fixed same day** — see below |

## The sorting bug — found and fixed 2026-07-03

The blueprint's "Spec sort key" rule (numeric, not lexicographic) was correctly implemented in two of three places that sort specs:

- `comparison_query.php` (public Comparison table) — `ORDER BY p.canonical_name ASC, s.numeric_value ASC` — correct
- `products/show.php` (Products tab) — `ORDER BY numeric_value` — correct
- `vendors/show.php` (Inventory tab **and** the Vendors-tab price list) — `ORDER BY p.canonical_name, s.spec_label, pr.tier_kit_size` — **wrong column**, sorting the display string instead of the numeric value

User supplied a concrete screenshot (Tirzepatide on the Inventory tab: 10mg, 15mg, 20mg, 30mg, 5mg, 60mg) that made the bug unambiguous — sorted as text, `"10mg" < "15mg" < "20mg" < "30mg" < "5mg" < "60mg"` is exactly correct alphabetically (first-character comparison: 1,1,2,3,5,6), which is exactly the wrong order shown. Root cause isolated to one `ORDER BY` clause, written earlier the same session when the vendor price-list feature was first built — not a pre-existing, long-standing bug, and not present in either of the two correctly-sorted queries.

Fixed: `s.spec_label` → `s.numeric_value` in `vendors/show.php`'s price query. Verified against live data post-deploy: Tirzepatide's specs for a real vendor now return `5, 10, 15, 20, 30, 40, 50, 60, 80, 100mg` in that exact order.

**Standing lesson:** the founding document explained *why* sorting was designed to be numeric — it did not, on its own, explain why the user was *seeing* it broken, since the query that mattered most (the comparison table) was already correct. Those are different questions; treating "the spec says X" as proof "the live behavior is X" would have been wrong here. The actual fix came from checking the live queries directly and from the user's own concrete screenshot, not from re-reading the blueprint harder.
