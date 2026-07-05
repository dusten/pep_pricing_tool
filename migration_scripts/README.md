# Migration / one-off scripts

Archived one-off PHP scripts run directly on the server (`sudo -u apache php <script>` from
the `price_themightygroupbuy/` app root) for bulk data operations too large or too flaky to
do reliably through the WebUI one click at a time. Not part of the app itself — kept here as
a historical record and reference for similar future bulk operations.

Each file replicates the exact logic of the real endpoint it stands in for (same commit
functions, same audit-log calls) rather than shortcutting around it — see each file's header
comment for what it did and why.
