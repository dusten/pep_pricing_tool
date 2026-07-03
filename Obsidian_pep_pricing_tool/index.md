---
title: Wiki Index
---

# Wiki Index

Catalog of all [[llm-wiki-pattern]] pages. Updated on every ingest. LLM reads this first when answering queries.

## Sources

| Page | Summary |
|------|---------|
| [[wiki/sources/karpathy-llm-wiki-pattern\|Karpathy LLM Wiki Pattern]] | Pattern for building persistent personal wikis using LLMs; contrasts with RAG |
| [[wiki/sources/phase1-framework\|Phase 1 Framework Reference]] | Complete Phase 1 codebase reference — PHP/Vue SaaS, auth, quota, schema, deployment |
| [[wiki/sources/epithalon-vs-n-acetyl-epitalon-amidate\|Epithalon Variant Comparison]] | Base AEDG vs Ac-AEDG-NH₂ — stability, dosing, CAS notes |
| [[wiki/sources/tb-500-variants\|TB-500 Variants Comparison]] | TB4 (43aa) vs B5 fragment (7aa) vs TB5 (MAO-B small molecule) — vendor nomenclature map |
| [[wiki/sources/epithalon-peptide-overview\|Epithalon Overview — PickPeptides]] | General Epithalon overview; salt forms, chemical identifiers |
| [[wiki/sources/tb-500-thymosin-beta4-overview\|TB-500 Overview — PickPeptides]] | TB-500 (fragment form) preclinical data and research status |
| [[wiki/sources/tb-500-fragment-17-23-overview\|TB-500 Fragment (17-23) Overview — PickPeptides]] | Fragment-specific data; MW distinction, CAS, PMID research |
| [[wiki/sources/original-project-blueprint\|Original Project Blueprint — Founding Prompt]] | The actual founding spec (schema, API, Claude prompt, frontend architecture, Excel/CSV export formatting) — never ingested until 2026-07-03; explains the spec-sort-order design intent |

## Concepts

| Page | Summary |
|------|---------|
| [[wiki/concepts/llm-wiki-pattern\|LLM Wiki Pattern]] | Workflow where LLM incrementally builds and maintains a wiki from raw sources |
| [[wiki/concepts/rag-vs-wiki\|RAG vs Persistent Wiki]] | Contrast between retrieval-at-query-time (RAG) and pre-compiled persistent wiki |
| [[wiki/concepts/subscription-tiers\|Subscription Tiers]] | Free/$5/$14/$34 tier structure, capabilities, Stripe IDs, DB enforcement |
| [[wiki/concepts/query-quota\|Query Quota System]] | Free-tier 3-query/72h rolling window — filter_hash dedup, pc_query_log, /api/me/quota |
| [[wiki/concepts/deployment\|Deployment Pattern]] | deploy.sh flow, add-price-site.sh rules, server gotchas, credential convention |
| [[wiki/concepts/variant-compounds\|Variant Compounds Watchlist]] | Compounds with same-name variants (Epithalon, TB-500 family) — weekly scan reference |

## Entities

| Page | Summary |
|------|---------|
| [[wiki/entities/phase-roadmap\|Phase Roadmap]] | Phase 1/2/3 status **+ the canonical project BACKLOG (source of truth; CLAUDE.md points here)** |
| [[wiki/entities/epithalon\|Epithalon]] | Parent page — base AEDG vs N-Acetyl Amidate variants |
| [[wiki/entities/epithalon-base\|Epithalon Base]] | Standard Epitalon (H-AEDG-OH), CAS 307297-39-8 |
| [[wiki/entities/epithalon-n-acetyl-amidate\|N-Acetyl Epitalon Amidate]] | Modified form (Ac-AEDG-NH₂) — higher stability, ~10× lower dose |
| [[wiki/entities/tb-500\|TB-500 / Thymosin Beta Family]] | Parent page — TB4 full / B5 fragment / TB5 small molecule |
| [[wiki/entities/thymosin-b4-acetate\|Thymosin Beta-4 Acetate]] | Full 43aa peptide, CAS 77591-33-4, broad regenerative mechanism |
| [[wiki/entities/tb-500-fragment-17-23\|TB-500 Fragment (17-23)]] | 7aa fragment (Ac-LKKTETQ), CAS 885340-08-9 — what most vendors mean by "TB-500" |
| [[wiki/entities/tb5-mao-b-inhibitor\|TB5 MAO-B Inhibitor]] | Small molecule (CAS 948841-07-4) — NOT a peptide; vendor mislabeling risk |

## Sessions

| File | Summary |
|------|---------|
| [[sessions/2026-06-29\|2026-06-29]] | First production deploy — 6 bugs fixed, site live |
| [[sessions/2026-06-30\|2026-06-30]] | Waitlist email flow; ponytail audit; variant compound wiki; weekly scan routine |
| [[sessions/2026-06-30\|2026-06-30]] | Waitlist email flow — Brevo 400 name bug, SELinux env fix, email templates, deploy hardening |
| [[sessions/2026-07-01\|2026-07-01]] | Full build: nav shell, Comparison table, Calendar, complete admin panel, Claude pipeline, security fixes, deploy.sh decoupled, prod schema sync. Session 2 same day: nav unified to top+bottom everywhere, full user settings page, remaining admin-panel gaps (Overview/System tabs, Users/Waitlist/Performance), comparison query logging+replay, security/caching hardening, deploy.sh 4-mode split + smoke check, deployed to prod — then post-deploy verification caught a schema.sql migration-seeding bug that silently skipped migration 003 on prod, root-caused and fixed. Session 3 same day: Account merged into Settings + card redesigns, perf beacon finally wired up, caching audit (3→10 endpoint groups incl. session/token validation), System tab (Live refresh, ANALYZE cron, per-db slow-query capture + acknowledge/resolve loop, dropdown→pills) — surfaced and fixed 4 real pre-existing bugs (grp's slow-log event silently broken since 06-26, session- vs global-scoped SHOW STATUS, shared-vs-per-app query counter, performance_schema globally off). Session 4: vendor onboarding spec built out fully — Claude key went live, xlsx hidden-sheet bug, Sonnet 5 thinking/max_tokens bugs, prompt caching, batch processing, image/ZIP upload, Apache timeout saga, duplicate-product review-queue crash, tier_kit_size corruption bug — pushed 23 commits to origin/main. Continues in [[sessions/2026-07-02\|2026-07-02]] (same uninterrupted session, date rolled over mid-conversation) |
| [[sessions/2026-07-02\|2026-07-02]] | Direct continuation of 2026-07-01 session 4. Editable review queue + vendor SKU, Products-tab abbreviation retired, dashboard stats wired up, Remitly payment method, phone-number vendor dedup, ZIP upload spec+build — pushed 23 commits. Transaction-rollback bug found by directly answering "what happens when a file fails". File-preview feature that took **six** root causes to actually work end to end: native Chrome PDF viewer's blob-URL resize bugs (abandoned for pdf.js), two CSS replaced-element sizing gotchas, Apache missing a `.mjs` MIME mapping, a content-hashed asset not busting caches when only server config changed, and a real pdf.js 5.x/6.x bug calling native `Uint8Array.prototype.toHex()` with no feature detection (unsupported before Chromium 140 — user was on Chrome 128) — fixed by pinning to pdfjs-dist 4.10.38, confirmed working live. Status check found ClamAV built but disabled+crash-looping in prod (real gap, CLAUDE.md backlog wording now stale). Comparison Cat No. search, admin product edit, Product feedback pill. Review Queue: remaining count, empty-field flags, cross-vendor auto-approve on exact product+spec match (validated against 918 real pending rows incl. a live GHK-Cu 100mg/4-vendor case). BPC-157/TB-500 blend found miscategorized live on the comparison table → spec editing + spec-move-to-different-product + a new Inventory tab (vendor-first, full price-line editing: price/vial-count/SKU/tier), with `price_per_unit` recalculation handled in both new write paths. Products-tab CSS: alias-wrap vertical-alignment fixed, then refined after a real screenshot showed the first fix stranding the Edit button with dead space beneath it. Review Queue: auto-approve message moved below the card so its appear/clear cycle stops shifting the card. Continues in [[sessions/2026-07-03\|2026-07-03]] (same uninterrupted session, date rolled over mid-conversation) |
| [[sessions/2026-07-03\|2026-07-03]] | Direct continuation of 2026-07-02. User surfaced the original founding blueprint (never ingested before — explains the earlier sort-order question), ingested as [[wiki/sources/original-project-blueprint]]; full gap analysis against the actual app filed as [[wiki/analyses/2026-07-03-blueprint-vs-actual]]. SaaS pivot confirmed intentional. Backlog updated: Export tied to Pro (Excel/CSV)/Expert (JSON) tiers, `price_per_unit` missing a kit-vial-count factor (real formula bug, user-caught), price history, referral credits should grant subscription months not dollars, missing Pinia stores confirmed not worth building. Spec-sorting bug (flagged as the user's biggest issue) found and fixed same day — `vendors/show.php`'s price query was sorting by the `spec_label` string instead of the `numeric_value` column; isolated to that one query (Inventory tab + Vendors-tab price list), confirmed the public Comparison table and Products tab were already correct, fixed and verified against live data |

## Analyses

| Page | Summary |
|------|---------|
| [[wiki/analyses/2026-07-01-vendor-upload-spec\|Vendor Onboarding + File Upload Spec]] | Full spec: vendor contact/payment schema, abbreviation field, tiered pricing, hard review queue, async threshold, TB-500/Epitalon warning-only checks, vendor file repository, COA verification queue, verified-vendor badge, paste-to-parse vendor intake template, mandatory ClamAV scan on every upload |
| [[wiki/analyses/2026-07-02-zip-upload-spec\|ZIP Upload Spec]] | Built and deployed 2026-07-02. Zip = one logical price list (WhatsApp auto-zip use case), single multi-block Claude call, 3-entry/12MB caps, reject on stray non-image/PDF, no async path, `callClaudeExtraction()` refactored to one content-blocks param |
| [[wiki/analyses/2026-07-03-blueprint-vs-actual\|Original Blueprint vs. Actual App — Gap Analysis]] | SaaS pivot confirmed intentional; export/price-history/referral-credit gaps backlogged; `price_per_unit` missing a kit-vial-count factor (real bug, backlogged); spec-sorting bug found and fixed same day (wrong column in one query) |
