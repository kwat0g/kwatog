# M026 — Journal ledger action plan

Audit date: 2026-08-25  
Disposition: Plan Ready

The previous plan was re-evaluated because current uncommitted journal source
changes invalidated the 2026-08-24 report. No production source fixes were
applied in this audit session.

## Ordered work

1. **Close terminal-row deletion and invalid-transition gaps — F-001, F-002**
   - Scope: [large]
   - Session recommendation: [separate-recommended]
   - Add a JournalEntry state machine with explicit `TRANSITIONS`, make
     Posted/Reversed rows immutable including `deleted_at`, require a valid
     reversal link for `Posted → Reversed`, and keep database guards aligned on
     PostgreSQL and SQLite. Add negative transition and terminal soft-delete
     tests before changing any cross-module writer.

2. **Lock and validate the complete line aggregate on terminal transitions — F-003**
   - Scope: [large]
   - Session recommendation: [separate-recommended]
   - Document and implement one header→lines→accounts lock order for post,
     postSystem, reverse, update, and delete. Recheck at least two non-zero,
     XOR-valid lines and totals under the lock. Add PostgreSQL interleaving tests
     for draft-line mutation versus each terminal path.

3. **Make audit actor and reversal reason evidence authoritative — F-004**
   - Scope: [large]
   - Session recommendation: [separate-recommended]
   - Propagate explicit system/on-behalf-of actor context into header and line
     audit events, include reversal reason in the audit reason metadata, and
     test authenticated, console, and listener-driven postings/reversals.

4. **Decide and enforce the reversal-date policy — F-005**
   - Scope: [medium]
   - Session recommendation: [separate-recommended]
   - Obtain the accounting decision for reversing prior-period entries. Encode
     the date relation and open-period rule in the request/service/UI copy, then
     cover default, earlier, later, closed, and reopened periods for manual and
     automated cancellation paths.

5. **Enforce the two-decimal Money contract at the API boundary — F-006**
   - Scope: [medium]
   - Session recommendation: [separate-recommended]
   - Reject over-precision and out-of-range values instead of silently rounding
     user input. Keep all accepted amounts as decimal strings/centavos through
     service totals and add manual plus automated negative tests.

6. **Normalize source-reference serialization — F-007**
   - Scope: [medium]
   - Session recommendation: [same-session-ok]
   - Map allow-listed source families to friendly labels/document numbers,
     remove implementation class names from the public display contract, and
     preserve hashed identifiers where a client genuinely needs them.

7. **Make archive detail actions internally consistent — F-008**
   - Scope: [small]
   - Session recommendation: [same-session-ok]
   - Choose whether archived drafts can be printed; then either add trashed PDF
     binding with explicit authorization or hide Print for archived entries and
     add a route regression.

8. **Repair the edit-page lint defect and polish action copy — F-009**
   - Scope: [small]
   - Session recommendation: [same-session-ok]
   - Stabilize the `lines` fallback used by the edit totals memo, correct the
     create-page “Failed to post” fallback to describe saving a draft, and use a
     destructive/warning action treatment for Reverse if that matches the
     accounting decision. Re-run focused lint and the edit/detail UI test.

9. **Build the M026 acceptance matrix — F-010**
   - Scope: [large]
   - Session recommendation: [separate-recommended]
   - Cover system_admin and finance_officer route/action permissions, create →
     edit → archive/restore → post → reverse, all terminal guards, line races,
     period policy, exact money values, account deactivation, source
     serialization, actor/reason audit evidence, archived PDF behavior, and
     automated writer idempotency.

## Re-audit acceptance gates

- Posted/Reversed entries cannot be updated, hard-deleted, soft-deleted, hidden,
  or transitioned without the canonical state machine and a valid reversal
  relationship.
- Every terminal transition locks and validates the same journal header, line
  aggregate, accounts, and period in a documented order; empty or unbalanced
  entries cannot post.
- Manual and automated writes retain distinguishable, authoritative actor and
  reason evidence in both header and line audit history.
- Reversal dates follow an explicit accounting policy for prior-period entries,
  default dates, closed periods, and reopened periods.
- Manual/API and automated journal amounts share the same bounded two-decimal
  Money contract without silent user-input rounding.
- Source references are allow-listed and rendered with friendly identifiers,
  archived detail actions do not produce binding 404s, and the edit page passes
  the repository lint gate.
- Both named roles have tested route permissions and complete create/edit/post/
  reverse/archive workflows; direct and cross-module writer regressions cover
  every acceptance gate.

## Session decision

Defer all fixes. F-001 through F-006 and F-010 are financial-control or
cross-path changes, and the focused backend suite cannot currently run because
the configured PostgreSQL host `db` is unavailable. F-007 through F-009 are
contained follow-ups, but fixing only those during this first cautious pass
would leave the terminal-state plan incomplete. Keep M026 at Plan Ready and
schedule a dedicated implementation session followed by a fresh audit.
