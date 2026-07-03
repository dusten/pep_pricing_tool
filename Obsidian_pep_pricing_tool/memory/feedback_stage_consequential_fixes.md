---
name: feedback-stage-consequential-fixes
description: For core pricing/comparison-logic bugs, keep review, investigation, and fixing as separate user-gated steps rather than collapsing them
metadata:
  type: feedback
---

For a bug in core pricing/comparison logic (the kind that affects the actual product, not just admin UX), the user runs it as explicitly separate, gated steps rather than one continuous "go fix it" — even when they've already called the issue the biggest priority.

**Why:** The sorting-bug arc ran as three distinct turns: "I don't want you to change anything yet, just wanted to see what the gaps were" (review only) → "Yes go find it" (investigate only, no fix) → "Yes, fix it" (a separate, later authorization for the fix itself). At no point did calling something "the biggest issue" mean permission to skip straight to fixing it. This was followed correctly without being told to slow down — worth confirming as the right default, not just something to fall back to after a correction.

**How to apply:** On a report of a real bug in core logic (pricing, comparison results, data integrity), don't assume urgency implies authorization to jump straight to a fix. Confirm before investigating if it's not already clear investigation alone is welcome, and confirm again before applying the fix even if the investigation makes the fix obvious and small. This is distinct from [[feedback_ask_open_decisions_directly|asking about open spec decisions]] — that's about surfacing ambiguity in what to build; this is about pacing *when* to act on something already understood.
