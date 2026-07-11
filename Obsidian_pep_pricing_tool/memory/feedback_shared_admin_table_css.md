---
name: feedback-shared-admin-table-css
description: Use the global .admin-table / .actions CSS classes for any new admin list table instead of writing new scoped table CSS per page
metadata:
  type: feedback
---

Every admin-tab list table used to redefine its own `th, td { padding; border-bottom; }` in a page-local scoped `<style>` block — 17+ near-identical copies (UsersTab, VendorsTab, ProductsTab, WaitlistTab, FeedbackTab, etc.), each able to drift slightly from the others. The COA-submissions table (added 2026-07-11) reinvented this again and introduced a new bug in the process: it set `display: flex` directly on the actions `<td>`, which drops that cell out of `display: table-cell` and breaks its participation in the row — the border-bottom line stopped extending under the actions column. Every *other* table in the app already used the correct pattern (`white-space: nowrap` on the actions cell, established in ProductsTab/UsersTab), just never centralized anywhere.

**Why:** User asked directly, "we have fixed this many a times on different pages why do we keep revisiting this" — table misalignment is a recurring category of bug across this app specifically because there's no shared definition, so each new table (or each fresh session building one) can reinvent it slightly wrong.

**How to apply:** `frontend/src/assets/main.css` now has canonical shared blocks for all four patterns found duplicated in a follow-up reuse sweep (2026-07-11), retrofitted across all 17 admin tabs:
- `.admin-table` (+ `.admin-table .actions` for the buttons cell, + `.admin-table .detail-row` for an inline-edit expansion row's background/label styling — see ProductsTab/StacksTab's edit-in-place pattern)
- `.stat-grid`/`.stat-tile`/`.stat-value`/`.stat-label`/`.stat-sublabel` (Overview/Performance/System dashboards)
- `.toolbar` (the filter-controls row above a table)
- `.view-backdrop`/`.view-card`/`.view-header`/`.view-body` (the centered modal used by Files/Claude API tabs' "view full text/file" popups)

Use these classes instead of writing new scoped CSS for any of these four shapes. **Never put `display: flex`/`display: grid` directly on a `<td>` or `<th>`** — it breaks border/alignment behavior in that row; if a cell's content needs flex layout, wrap it in an inner `<div>`.

**Important nuance on vertical-align:** the shared `.admin-table td` rule deliberately does **not** set `vertical-align`. An earlier version forced `top` on every cell app-wide, but that's exactly what ProductsTab's `:nth-child(-n+3)` override had to walk back — Vendors/Merge/Edit-style tables with short single-line rows look wrong pinned to the top, only genuinely tall-wrapping columns (wrapped alias chips, a two-line "name + date" cell) should get it. If a table needs top-alignment for one or two specific columns, add a small scoped override in that page's own `<style scoped>` targeting just those columns (`:nth-child(-n+N)` or `:first-child`), the same way ProductsTab and ReviewQueueTab (COA table) do — don't change the shared default.
