---
name: feedback-shared-admin-table-css
description: Use the global .admin-table / .actions CSS classes for any new admin list table instead of writing new scoped table CSS per page
metadata:
  type: feedback
---

Every admin-tab list table used to redefine its own `th, td { padding; border-bottom; }` in a page-local scoped `<style>` block — 17+ near-identical copies (UsersTab, VendorsTab, ProductsTab, WaitlistTab, FeedbackTab, etc.), each able to drift slightly from the others. The COA-submissions table (added 2026-07-11) reinvented this again and introduced a new bug in the process: it set `display: flex` directly on the actions `<td>`, which drops that cell out of `display: table-cell` and breaks its participation in the row — the border-bottom line stopped extending under the actions column. Every *other* table in the app already used the correct pattern (`white-space: nowrap` on the actions cell, established in ProductsTab/UsersTab), just never centralized anywhere.

**Why:** User asked directly, "we have fixed this many a times on different pages why do we keep revisiting this" — table misalignment is a recurring category of bug across this app specifically because there's no shared definition, so each new table (or each fresh session building one) can reinvent it slightly wrong.

**How to apply:** `frontend/src/assets/main.css` now has a canonical `.admin-table` block (width/border-collapse/padding/border-bottom/header styling) plus `.admin-table .actions` (the `white-space: nowrap` + button-margin pattern for an actions cell). Any new admin list table should use `class="admin-table"` on the `<table>` and `class="actions"` on the buttons `<td>` instead of writing new scoped CSS. **Never put `display: flex`/`display: grid` directly on a `<td>` or `<th>`** — it breaks border/alignment behavior in that row; if a cell's content needs flex layout, wrap it in an inner `<div>`. The existing 16 other tables (UsersTab, VendorsTab, etc.) still have their own duplicate CSS and weren't retrofitted in this pass — only ReviewQueueTab.vue's COA table was migrated as the proof case. Retrofitting the rest is straightforward cleanup if it comes up, not urgent.
