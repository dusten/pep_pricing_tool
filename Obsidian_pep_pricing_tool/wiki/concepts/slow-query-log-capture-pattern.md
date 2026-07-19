---
title: Slow-Query Log Capture Pattern
type: concept
tags: [ops, mysql, slow-query-log, portable-prompt, grp]
created: 2026-07-19
sources: [phase-roadmap]
---

# Slow-Query Log Capture Pattern

Reusable methodology for building (or auditing) a per-app slow-query capture system on a
shared MySQL/MariaDB server — developed and hardened over several passes on this project's
own `pc_slow_query_cache`. Portable to any sibling app on the same box, notably `grp`
(`themightygroupbuy` DB), which shares the same physical `mysql.slow_log`.

## Lineage on this project

1. **Built** (2026-07-01) — `pc_slow_query_cache` + hourly import event, scoped to `WHERE
   db = 'tmgb_price'` since `mysql.slow_log` is server-wide/shared with `grp`.
2. **First real bug, same session** — the import event tried `DELETE FROM mysql.slow_log`;
   MariaDB log tables reject `DELETE` outright. Fixed to `SELECT`-only; a separate,
   root-owned, shared cron does the actual `TRUNCATE` (the only thing that can clear a log
   table), timed after every app sharing the log has had a chance to import.
3. **Triage pass** (2026-07-04) — worked through 408 accumulated rows. The "slowest"
   entries were noise (the collector auditing its own queries, permanently pinned since
   `query_time_secs` uses `GREATEST(old,new)` and never decays). Underneath the noise, 90%
   of real occurrences traced to one root cause: `LOWER()` wrapped around an
   already-case-insensitive-collation column, defeating its unique index. Confirmed via
   `EXPLAIN` before/after.
4. **Noise-filter improvement** (2026-07-10) — excluded the collector's own queries from
   self-ingestion, and only captured rows above a real threshold (`query_time >= 0.5s OR
   rows_examined >= 5000`).
5. **Regression, caught by re-auditing** (2026-07-14) — a later "improvement" migration
   recreated the event from scratch and silently reintroduced the exact `DELETE`-against-
   log-table bug from step 2. Not obvious because the `INSERT` half still succeeded before
   hitting the broken `DELETE` — the table kept looking populated while actually re-
   importing the same never-cleared rows every hour.

## The portable prompt

```
Set up (or audit) slow-query capture for this app, following a proven pattern from a
sibling project on the same DB server.

CONTEXT: MySQL/MariaDB's slow_log is server-wide/shared across every app on the box, not
per-database. If another app on this server already has its own slow-query import, treat
the shared mysql.slow_log as contested ground — don't let this app's capture silently
break or race the other app's.

BUILD (if capture doesn't exist yet):
1. A per-app table (e.g. app_slow_query_cache) with: query_hash (unique), query_time_secs,
   rows_examined, query_sql, first_seen_at, last_seen_at, occurrence_count, and a feedback
   loop: status enum('new','acknowledged','resolved') + status_note + status_updated_at.
2. An hourly event/cron that imports from mysql.slow_log, scoped to WHERE db = '<this
   app's db>' only.
3. That import must be SELECT-only against mysql.slow_log. Log tables reject DELETE
   outright ("You can't use locks with log tables") — only TRUNCATE can clear one, and
   TRUNCATE can't be scoped to one db. If the log needs periodic clearing, that's a
   separate, shared, root-owned cron (TRUNCATE), timed after every app sharing the log has
   had a chance to import — never inside this app's own per-db import event.
4. Exclude the collector's own queries from self-ingestion (its INSERT/SELECT against its
   own cache table shows up in the log it's reading from — left in, it creates a
   permanently-pinned phantom "slow" entry, since query_time typically uses
   GREATEST(old,new) and never decays once a spike lands).
5. Filter at import time to genuinely actionable rows only (e.g. query_time >= 0.5s OR
   rows_examined >= 5000) — don't let every no-index full-scan of a tiny table (correct
   and fast at small scale) flood the table as "slow."
6. Admin UI: a table with acknowledge/resolve actions + an export endpoint (CSV, all
   columns, no arbitrary row limit).

TRIAGE (working through accumulated rows):
1. Before trusting a "slowest first" ranking, check for noise: entries pinned by a single
   historical spike (GREATEST() never decays), the collector auditing itself, harmless
   heartbeats (SLEEP()-style keepalives).
2. Group by query shape, not by row — look for one query pattern responsible for a large
   share of total *occurrences* (not just distinct rows). That's usually the one real bug
   worth root-causing.
3. Common root cause worth checking specifically: a function wrapped around an indexed
   column (LOWER(), DATE(), etc.) defeats that column's index even when it's functionally
   unnecessary (e.g. the column's collation is already case-insensitive, so a plain `=`
   already matches case-insensitively). Confirm with EXPLAIN before/after — look for
   type=index or a full scan collapsing to type=const/ref.
4. Mark every row's disposition explicitly (resolved with a note on what was fixed and
   why; acknowledged with a note on why it's fine as-is) rather than leaving them
   unreviewed — the point is a clean, current signal, not an ever-growing backlog.

RE-AUDIT (don't treat this as done-forever):
A later, unrelated migration/change can silently reintroduce an already-fixed bug (e.g.
recreating the event "from scratch" for an improvement and accidentally restoring the old
DELETE statement). This is hard to notice because a partially-broken event can still look
alive — e.g. if INSERT runs before a broken DELETE, the table keeps looking populated even
though the underlying log is never actually clearing and counts are being inflated by
re-importing the same rows. When asked to evaluate this later: check the actual DB error
log for the collector's own failures, and check any maintenance-heartbeat/last-run table if
one exists — don't just eyeball "is data flowing in."
```

## See also

- [[../entities/phase-roadmap|Phase Roadmap]] — backlog #7 and #20, full dated history of
  every pass on this mechanism (build, triage, noise-filter, regression fix).
