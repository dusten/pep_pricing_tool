---
type: folder-index
tags: [folder-index, nav]
---

# memory/ — Folder Index

[[index]] / **memory/**

## Summary
**19** pages

<!-- curated:start -->
memory/ — real persistent memory files for this project (user/feedback/project/reference). ~/.claude's own memory dir just redirects here.
<!-- curated:end -->

## Files (19)
| File | Description |
|---|---|
| [[MEMORY]] | Memory Index |
| [[feedback_archive_diagnostic_scripts]] | Every one-off read-only verification script run on the server also gets saved to diagnosticscripts/ and logged in the wiki, not just migrati… |
| [[feedback_archive_migration_scripts]] | Any one-off script run directly on the server for a bulk data operation must be saved to migrationscripts/ and committed to git, not deleted… |
| [[feedback_ask_open_decisions_directly]] | When a spec surfaces open decisions for the user, ask via AskUserQuestion directly rather than just listing them in a doc and waiting |
| [[feedback_bash_permissions]] | User granted standing permission for routine Bash commands under /home/dusten/projects/peptidesprojects/peppricingtool — don't hold back or … |
| [[feedback_commit_style]] | Commit message style preference — short, no co-author, no per-file details |
| [[feedback_delegate_builds_to_sonnet]] | Build/implementation work on this project gets delegated to background Sonnet 5 subagents; the primary session does investigation, planning,… |
| [[feedback_deploy_workflow]] | Run deploy.sh directly instead of a standalone build step — it already builds and syncs in one command |
| [[feedback_ledger_rebuild_blind_spot]] | When rebuilding a display to read from a new ledger/audit table instead of a live column, check whether the ledger has coverage back to befo… |
| [[feedback_no_local_php]] | No PHP interpreter is installed on this local dev machine — don't run php -l or try to execute PHP locally for this project |
| [[feedback_restore_browser_session_after_test]] | Live-verification subagents that log the shared browser into a throwaway test account must log it back out (or restore the prior session) be… |
| [[feedback_shared_admin_table_css]] | Use the global .admin-table / .actions CSS classes for any new admin list table instead of writing new scoped table CSS per page |
| [[feedback_stage_consequential_fixes]] | For core pricing/comparison-logic bugs, keep review, investigation, and fixing as separate user-gated steps rather than collapsing them |
| [[feedback_wiki_location]] | All persistent notes, session logs, and memory for this project live in the Obsidian wiki, not ~/.claude/ |
| [[project_no_real_billing]] | No Stripe/payment integration exists — tier, tierstatus, and tierrenewsat on pcusers are 100% manually set by admins; confirm before assumin… |
| [[project_precompact_wiki_hook]] | A PreCompact hook is configured to auto-inject a wiki/log/session-note checkpoint reminder before every compaction (manual or automatic) — d… |
| [[project_vendor_suggestions_gated]] | Backlog 69 (user-suggested vendors) Phases 1-2 are built and live but deliberately testaccount-gated pending user testing — don't suggest la… |
| [[reference_ssh_key_path]] | The SSH private key for price.themightygroupbuy.com's server always lives at this fixed path |
| [[user_operational_domain_expertise]] | User has real hands-on vendor/procurement operational knowledge — weight their concrete corrections over generic engineering safety-margin p… |
