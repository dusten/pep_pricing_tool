# Memory Index

- [Commit style](feedback_commit_style.md) — Short one-liner only, no co-author, no Claude-Session, no per-file details
- [Wiki location](feedback_wiki_location.md) — All session notes, analyses, and persistent memory go in Obsidian_pep_pricing_tool/
- [Deploy workflow](feedback_deploy_workflow.md) — Run `deploy.sh` directly (builds+syncs in one command), never stop at a standalone build step
- [Ask open decisions directly](feedback_ask_open_decisions_directly.md) — Use AskUserQuestion for spec decisions, don't just list them in a doc and wait
- [User operational domain expertise](user_operational_domain_expertise.md) — Trust their real-world numbers/behavior over generic padding, and their compound-identity/data-correctness/formula calls as high-signal
- [Stage consequential fixes](feedback_stage_consequential_fixes.md) — Gate judgment-call bugs (review→investigate→fix); mechanical error-driven bugs (exact stack trace/SQL error) go straight to fix, no gating
- [No local PHP](feedback_no_local_php.md) — No PHP interpreter locally, don't even probe for the binary (repeated correction 2026-07-04); verify via live deploy + downloaded-output inspection instead
- [Bash permissions](feedback_bash_permissions.md) — Standing permission for routine Bash commands in this project tree, don't over-ask
- [Ledger rebuild blind spot](feedback_ledger_rebuild_blind_spot.md) — A "read from the new ledger only" rebuild silently loses anything from before the ledger existed, even same-day; check coverage or keep a second signal
- [Archive migration scripts](feedback_archive_migration_scripts.md) — Any one-off server script for a bulk data operation goes in migration_scripts/ and gets committed, never deleted after running
- [SSH key path](reference_ssh_key_path.md) — Prod server key is always at ~/projects/peptides_projects/pepcal_key.pem
- [Archive diagnostic scripts](feedback_archive_diagnostic_scripts.md) — Read-only verification scripts go in diagnostic_scripts/ too, not just data-mutating ones, and get logged in the wiki
- [Shared admin-table CSS](feedback_shared_admin_table_css.md) — Use global .admin-table/.actions classes for new tables; never put display:flex/grid directly on a td/th
