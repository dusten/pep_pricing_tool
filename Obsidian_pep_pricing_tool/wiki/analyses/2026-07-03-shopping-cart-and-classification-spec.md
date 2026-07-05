---
title: Shopping Cart, Buy This Stack, and Classification Rework Spec
type: analysis
tags: [spec, cart, stacks, classification, backlog, home-page]
created: 2026-07-03
sources: []
---

# Shopping Cart, "Buy This Stack", and Classification Rework

Design for three linked features, agreed with the user before implementation, built in phases.

**Status: all three phases built and deployed 2026-07-03** (same day as this spec) — Classification, Shopping cart, and Buy This Stack. See each section below for what shipped and how it was verified.

## What's being built

1. **Shopping cart** — pick individual products (at a specific dose/spec) into a cart; the app finds which vendor(s) carry the *entire* cart and ranks them by total cost.
2. **"Buy This Stack"** — admin-curated bundles (e.g. a pre-built "GLOW: BPC-157 5mg + TB-500 5mg + GHK-Cu 50mg" recipe) that add all their components to the cart in one click, then run the same cheapest-vendor calc.
3. **Classification rework** — `pc_products.category` (single-select, six fixed values: glp1/peptide/hormone/blend/consumable/other) is retired entirely and replaced with a proper multi-select tag system, seeded from a real product→classification mapping the user supplied.

Both cart features surface as cards on the Dashboard (home page).

## Decisions

1. **Cart cost logic**: rank vendors who carry the entire cart (every product+spec in it) by total price, cheapest first — a real group-buy is one order from one vendor, not N separate orders. If zero vendors cover 100%, fall back to showing the best *partial*-coverage vendor(s) (e.g. "Vendor X has 4 of 5 items — missing: TB-500 10mg") rather than an empty state.
2. **Classification**: full replace, not a parallel system. `category` goes away; `pc_classifications` (a plain manageable table, not a hardcoded enum) + a many-to-many join table take over completely. Because it's a real table now, the tag set can grow organically (e.g. adding "Lab Supplies" for Bac Water, or "GLP / Metabolic" as vendors phrase it differently) without a schema change — admin-manageable, not fixed.
3. **Access**: cart and stacks are free for any logged-in user, same access model as Comparison today — not gated to a paid tier.
4. **Entry point**: "Add to cart" is a button on the existing Comparison table (per product+spec row) — no new browse/catalog page. The cart doesn't care which vendor you'll ultimately buy from; you're adding "I want BPC-157 5mg," and the vendor is decided by the cheapest-total calc afterward.
5. **Stack scope**: a stack's total cost sums its listed components across vendors, exactly like a manually-built cart. It does **not** try to detect that a vendor sells the same combo pre-mixed as one SKU (see [[wiki/entities/phase-roadmap|Non-goals]] below) — that's a real, separate recipe-matching problem, not solved here.
6. **Kit tier**: cheapest-total math uses the 1-kit tier only, matching Comparison's current behavior and backlog #11's existing limitation. Multi-tier cart totals (half-kit/full-kit, bulk pricing) are a natural follow-up once #11 is solved, not part of this build.
7. **Persistence**: cart is server-side, tied to `user_id` (new `pc_cart_items` table) — survives across devices/sessions, consistent with how quota and settings already work per-account.

## A naming collision to flag

The classification taxonomy includes a tag literally called **"Stack"** (for products that are themselves a pre-mixed blend sold as one SKU — GLOW, KLOW, WOLVERINE-style vendor products). That is a **different concept** from the new `pc_stacks` table (admin-curated cart bundles). A product classified `Stack` and an admin-built "Buy This Stack" bundle are unrelated in this build — decision #5 above means v1 never cross-references them. Worth remembering when either feature gets extended later.

## Schema

### Classification (replaces `pc_products.category`) — BUILT

Shipped as migrations `016_classifications.sql` (tables + 27-tag seed + backfill) and `017_drop_product_category.sql` (column drop, applied only after the full code cutover was verified live). The user supplied a second, informal product list mid-build (grouped by half-kit/full-kit pricing) that didn't map 1:1 onto the first — overlapping products got tags from **both** sources rather than one replacing the other (e.g. BPC-157 carries both `Healing & Recovery` and `Repair / Healing`). Final backfill: 112 product-tag assignments across 72 of 74 live products (2 — HCG, Lemon Bottle — had no data in either source and stayed unclassified, expected and fine). One real error caught and corrected before shipping: Melanotan 1 was initially guessed at by analogy to Melanotan 2, when the source list actually classified it explicitly (`Clinical`) — always re-verify a guess against the literal source text before writing it, don't trust a plausible-sounding analogy.

```sql
CREATE TABLE IF NOT EXISTS pc_classifications (
  id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pc_product_classifications (
  product_id        INT UNSIGNED NOT NULL,
  classification_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (product_id, classification_id),
  FOREIGN KEY (product_id)        REFERENCES pc_products(id)        ON DELETE CASCADE,
  FOREIGN KEY (classification_id) REFERENCES pc_classifications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Seed `pc_classifications` from the user-supplied mapping: Mitochondrial, Weight Management, Fat Loss, Healing & Recovery, Bioregulator, Growth Hormone, Anti-Aging, Skin & Hair, Sleep & Recovery, Sexual Health, Cognitive, Cosmetic, Neuroprotective, Clinical, Hormone Support, Antimicrobial, Immune, Growth Factors, Stack, plus **Lab Supplies** (added — covers things like Bac Water, which aren't compounds at all). Products with `NULL` in the source mapping simply get no `pc_product_classifications` rows (unclassified, filterable as "uncategorized" if useful later — not solved now).

Filtering semantics: selecting multiple classifications is inclusive (**OR** — "show anything tagged Weight Management or GLP"), not a narrowing AND. Standard multi-select tag-filter convention; not treated as an open question.

### Shopping cart — BUILT

Shipped as migration `018_cart.sql`, `GET/POST /api/cart` (`backend/api/cart/index.php`) and `DELETE /api/cart/{id}` (`backend/api/cart/item.php`, ownership-scoped — a user can't remove someone else's row, confirmed returns 404 not someone else's data). The vendor-breakdown query went one step past the original spec draft below: instead of `GROUP BY` (counts only), it fetches raw covering rows and aggregates in PHP so partial-coverage vendors can **name** which items they're missing (e.g. `"1 of 2 items — missing BPC-157 2mg"`), matching what decision #1 actually promised rather than just a count. Frontend: "+ Cart" button per row on the Comparison table (disabled/relabeled "Added" once in cart, backed by a `cartKeys` computed off the live cart store — not a fire-and-forget local flag), a new `/cart` page, a Dashboard summary card, and a bottom-nav entry. `comparison_query.php`'s grouped row was missing `specification_id` in its output (only used internally for the group-by key) — added, since the cart button needs it. Verified live: real API round-trip (add, duplicate-add is a no-op via the `uq_cart_item` unique key, remove, delete-a-missing-id correctly 404s) and real UI interaction (button state flip, Dashboard card reflecting the live cheapest total, Remove button actually clearing the page).

```sql
CREATE TABLE IF NOT EXISTS pc_cart_items (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           INT UNSIGNED NOT NULL,
  product_id        INT UNSIGNED NOT NULL,
  specification_id  INT UNSIGNED NOT NULL,
  added_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cart_item (user_id, product_id, specification_id),
  FOREIGN KEY (user_id)           REFERENCES pc_users(id)           ON DELETE CASCADE,
  FOREIGN KEY (product_id)        REFERENCES pc_products(id)        ON DELETE CASCADE,
  FOREIGN KEY (specification_id)  REFERENCES pc_specifications(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

No quantity column — v1 is "1 kit of this spec," matching decision #6 (1-kit tier only). A cart "item" is presence, not a count.

### Buy This Stack (admin-curated bundles) — BUILT

Shipped as migration `019_stacks.sql`, admin CRUD (`backend/api/admin/stacks/index.php` for list+create, `show.php` for get/update/delete, `items.php` for add/remove component mirroring the existing `products/aliases.php` pattern exactly), a new "Stacks" admin tab (`StacksTab.vue`, same structural pattern as `ProductsTab.vue` — inline edit row expanding to a component picker), public `GET /api/stacks` (Dashboard card), and `POST /api/cart/add-stack/{id}`. That last endpoint bulk-inserts every stack component into the caller's cart then returns the exact same `{items, vendors}` shape as `GET /api/cart` — extracted into a shared `getCartSnapshot()` helper (`backend/lib/cart.php`) so the two endpoints can't drift apart. Verified live: created a real 2-component stack, confirmed it listed in both admin and public views, bulk-added it to an empty cart and got byte-identical vendor-ranking output to manually adding the same two items, removed one component via a real UI click (confirmed via the DB, not just the DOM — an early check gave a false positive by matching the product-picker dropdown's leftover option text instead of the actual chip list), then cleaned up all test data.

```sql
CREATE TABLE IF NOT EXISTS pc_stacks (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(150) NOT NULL,
  description TEXT NULL,
  is_active   BOOLEAN NOT NULL DEFAULT TRUE,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pc_stack_items (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stack_id          INT UNSIGNED NOT NULL,
  product_id        INT UNSIGNED NOT NULL,
  specification_id  INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_stack_item (stack_id, product_id, specification_id),
  FOREIGN KEY (stack_id)          REFERENCES pc_stacks(id)          ON DELETE CASCADE,
  FOREIGN KEY (product_id)        REFERENCES pc_products(id)        ON DELETE CASCADE,
  FOREIGN KEY (specification_id)  REFERENCES pc_specifications(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`is_active` lets an admin retire a stack (e.g. a component product got renamed/discontinued) without losing the row. A product/spec deletion cascades and just silently shrinks the stack's item list — acceptable for v1, not specially handled.

## Cheapest-vendor-total query

One query answers "who covers this cart/stack, and for how much," used identically by both features (a cart is just an ad-hoc set of product/spec pairs; a stack's components are the same shape):

```sql
SELECT pr.vendor_id, v.display_name,
       COUNT(DISTINCT pr.specification_id) AS items_covered,
       SUM(pr.price_usd) AS total
FROM pc_prices pr
JOIN pc_vendors v ON v.id = pr.vendor_id AND v.is_active = 1
WHERE pr.is_active = 1 AND pr.tier_kit_size = 1
  AND (pr.product_id, pr.specification_id) IN (<cart/stack pairs>)
GROUP BY pr.vendor_id
ORDER BY items_covered DESC, total ASC
```

A vendor is "full coverage" when `items_covered` equals the cart/stack's item count. Show the cheapest full-coverage vendor(s) first; if none exist, show the best partial-coverage vendor(s) with what's missing named explicitly.

## API surface

- `GET /api/classifications` — list all tags (filter UI + admin product form)
- Admin can add new classification tags ad hoc (no fixed list) — small CRUD, mirrors how vendor/product aliases already work
- `PUT /api/products/{id}` — `category` field replaced by `classification_ids: [int]`; replace-all semantics (delete + reinsert join rows), same pattern as how aliases are managed today
- `GET /api/comparison/filters` — returns classifications instead of the old category list
- `GET /api/comparison` — `category` param replaced by `classification_ids[]`; `comparison_query.php`'s `WHERE p.category = ?` becomes an `EXISTS` against `pc_product_classifications` for any of the given IDs
- `GET /api/cart` — cart items + the cheapest-vendor-total breakdown in one response (no separate quote call)
- `POST /api/cart` — add `{product_id, specification_id}`
- `DELETE /api/cart/{id}` — remove one item
- `GET /api/stacks` — public list of active stacks (name, description, item count) for the Dashboard card
- `POST /api/cart/add-stack/{stack_id}` — bulk-adds every stack item into the caller's cart (`INSERT IGNORE`, dedups against items already there)
- Admin: `GET/POST /api/admin/stacks`, `PUT/DELETE /api/admin/stacks/{id}`, `POST/DELETE /api/admin/stacks/{id}/items` — same shape as the existing Products-tab alias/spec management pattern

## Frontend

- **Dashboard**: two new cards — "Shopping Cart" (item count, cheapest vendor + total if non-empty, link to `/cart`) and "Buy This Stack" (short list of active stacks with an "Add to Cart" button each)
- **New `/cart` page**: cart item list with remove buttons, the vendor coverage/total ranking (full-coverage vendors first, partial fallback), clear-cart action
- **Comparison table**: replace the single-select `category-tabs` with a multi-select classification chip filter (OR semantics); add an "Add to cart" button per product+spec row
- **New admin "Stacks" tab**: same registration pattern as the other 13 tabs in `AdminView.vue` — create/edit stack name+description, add/remove component rows (product + spec picker), active/inactive toggle
- **Products tab**: category dropdown replaced with a multi-select classification chip picker (add/remove chips), same interaction pattern already used for aliases

## Migration / cutover sequencing

This is a real replace, not an additive change, so it should land in order:

1. New tables (`pc_classifications`, `pc_product_classifications`, `pc_cart_items`, `pc_stacks`, `pc_stack_items`) — additive, non-breaking.
2. Seed `pc_classifications` and backfill `pc_product_classifications` from the user's product→classification mapping, matched against `pc_products.canonical_name`/aliases.
3. Cutover the 10 real touch points from `category` to classifications in one pass (see below) — frontend and backend together, since a half-migrated state (some code reading `category`, some reading classifications) would silently show wrong filters.
4. Drop `pc_products.category` only after the cutover is confirmed working live — a separate, final migration, so there's a clean rollback point if step 3 needs a fix.

### Real touch points for the cutover (confirmed by grep, not guessed)

`backend/api/products/index.php`, `backend/api/products/show.php`, `backend/lib/comparison_query.php`, `backend/api/comparison/index.php`, `backend/api/comparison/filters.php`, `backend/api/admin/query_log_rerun.php`, `frontend/src/stores/comparison.js`, `frontend/src/views/ComparisonView.vue`, `frontend/src/views/admin/tabs/ProductsTab.vue`, `database/schema.sql`.

**Not in scope** — two files matched the same grep but reference an unrelated concept: `backend/api/vendors/files.php` and `frontend/src/views/admin/tabs/VendorsTab.vue`'s `category` is the vendor-*file* category (`price_list`/`coa`/`other`), never touched by this rework.

**Loose end, not solved here**: `admin/query_log_rerun.php` replays a saved comparison query's filter params, including old logged `category` values. Once `category` is gone, historical query-log rows referencing it can't be replayed faithfully — acceptable, not fixed in this pass.

## Non-goals for this pass

- **Recipe-matching a stack against a vendor's pre-mixed blend SKU** (decision #5) — components-only totals for now.
- **Multi-tier cart totals** (half-kit/full-kit/bulk) — 1-kit only, tied to backlog #11.
- **Mix-and-match per-item cheapest vendor** — only single-vendor-covers-everything is built; a "theoretical floor if you don't mind splitting the order" view isn't part of this pass.
- **Uncategorized-product filter UI** — products with no classification tags aren't specially surfaced.

## Related

- [[wiki/entities/phase-roadmap|Phase Roadmap]] — backlog #11 (kit tier selection) is a real dependency for a future multi-tier cart
- [[wiki/analyses/2026-07-03-price-history-spec|Price History Spec]] — same spec-first-then-build pattern
