---
name: project-vendor-suggestions-gated
description: Backlog #69 (user-suggested vendors) Phases 1-2 are built and live but deliberately test_account-gated pending user testing — don't suggest launching without asking
metadata:
  type: project
---

Backlog #69 (user-suggested vendors — contact details + pricing file, virus scan, template-CSV
instant score or Claude-pipeline fallback, admin accept into the catalog) has Phases 1 and 2 fully
built and deployed as of 2026-07-15/2026-07-17/2026-07-18. Per-suggestion Claude cost tracking closed
the last known gap on 2026-07-18. See [[wiki/analyses/2026-07-15-vendor-suggestions-spec]] and
[[wiki/entities/phase-roadmap]] backlog #69 for full technical detail.

**Why this matters right now:** the feature is deliberately kept behind `pc_users.test_account = 1`
(server-side 404, not just nav hiding) and won't be un-gated until the user has had test accounts
exercise the full loop — template score, non-template admin-approval path, and an admin accept into
the live catalog. This was an explicit, deliberate decision (2026-07-15), not an oversight — Phase 3
(remove the gate, decide the launch tier gate) is the only remaining work on this backlog item.

**How to apply:** don't propose removing the `test_account` gate or treating #69 as "done and ready
to launch" without the user explicitly saying testing is complete. If asked to build something new
on top of vendor suggestions, it's safe to build — just don't change the gating decision unilaterally.
This memory should be updated or removed once Phase 3 actually ships.
