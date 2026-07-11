# Diagnostic / verification scripts

Archived read-only PHP scripts run directly on the server (`sudo -u apache php <script>` from
the `price_themightygroupbuy/` app root) to verify a change against real production data or
inspect live server state (cache contents, query output, etc.) — no PHP interpreter exists in
local dev, so this is the standard way to check backend code actually works (see the project's
"no local PHP" note in `Obsidian_pep_pricing_tool/memory/`).

Unlike `migration_scripts/` (one sibling directory over), nothing here mutates production data —
these only read. Kept so a verification doesn't have to be reinvented from scratch next time a
similar question comes up.

Naming: `YYYY-MM-DD-descriptive-name.php`. Each file keeps a header comment explaining what it
checks and why.
