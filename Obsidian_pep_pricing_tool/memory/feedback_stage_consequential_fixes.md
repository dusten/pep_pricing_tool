---
name: feedback-stage-consequential-fixes
description: For core pricing/comparison-logic bugs, keep review, investigation, and fixing as separate user-gated steps rather than collapsing them
metadata:
  type: feedback
---

For a bug in core pricing/comparison logic that requires *judgment about product behavior* (the kind that affects the actual product, not just admin UX), the user runs it as explicitly separate, gated steps rather than one continuous "go fix it" — even when they've already called the issue the biggest priority.

**Exception, confirmed 2026-07-03:** when the user hands over a concrete error (a stack trace, an exact SQL error/constraint name, an exception message) rather than a symptom description, that error *is* the go-ahead — investigate and apply the root-cause fix in one pass, no staged confirmation before or after. Example: given `SQLSTATE[23000] ... pc_specifications_ibfk_1 ... FOREIGN KEY (product_id) REFERENCES pc_products` with zero other framing, went straight from grep → root cause (`pending_imports.php` trusting a stale `candidate_product_id` after `products/merge.php` deletes the loser) → fix, worktree-isolated, no gate in between. User's response: "and don't ask in the future." Mechanical, error-driven bugs with an obvious single-place root cause don't need the sorting-bug treatment.

**Why:** The sorting-bug arc ran as three distinct turns: "I don't want you to change anything yet, just wanted to see what the gaps were" (review only) → "Yes go find it" (investigate only, no fix) → "Yes, fix it" (a separate, later authorization for the fix itself). That case involved a judgment call about *what the correct sort behavior should be* — ambiguity worth surfacing before acting. A DB integrity-constraint error with an exact table/column/FK name has no such ambiguity; the fix is dictated by the error, not chosen.

**How to apply:** On a report of a real bug in core logic, ask: is there a product-behavior judgment call here, or is this mechanical (an exact error message pointing at one FK/query/condition)? Judgment call → stage it (confirm before investigating, confirm again before fixing), per the sorting-bug precedent. Mechanical/error-driven → investigate and fix in one pass, worktree-isolated, no gating; just report what was found and changed. This is distinct from [[feedback_ask_open_decisions_directly|asking about open spec decisions]] — that's about surfacing ambiguity in what to build; this is about pacing *when* to act on something already understood.
