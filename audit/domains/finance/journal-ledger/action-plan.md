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

---

# M026 — action plan, 2026-08-30 (supersedes the 2026-08-25 plan)

Disposition: **🔁 Needs Re-audit** — F-011/F-012/F-013/F-014/F-016/F-018/F-024/F-025
fixed and verified this session; the items below remain.

The 2026-08-25 plan's items 1-3 (F-001…F-004) are **done** — implemented by the
crashed session, committed in `167de85e`, and confirmed by executed probe rather
than by reading its log. Items 4-9 are re-stated below with what this session
measured, and four new items are added.

Every item is tagged with the evidence for its session recommendation. `separate-recommended`
dominates, and the reason is specific in each case rather than a default: an item
is gated when it changes what a reported peso figure *means*, when it needs a
human accounting decision, or when it has consumers in another module's files.
A *missing guard* is judged on containment and several of those were fixed here.

## Ordered work

1. **Decide the reversal-date policy, then enforce it — F-017**
   - Scope: [medium] · Session recommendation: **[separate-recommended]**
   - Why gated: needs an accounting decision, and the answer changes which period a
     reported figure lands in. Measured today: a `2029-11-15` posted entry accepted
     `reverse_date = 2024-01-01`, because `assertPostingAllowed()` allows any month
     with no `accounting_periods` row.
   - **A contained first step is available and prejudges nothing:** every candidate
     policy (same period / current open period / not-before-source / approved
     exception) forbids a reversal dated before its source, so
     `reverse_date >= source.date` is their intersection. That guard alone is
     `[small] [same-session-ok]` if a human confirms the intersection reading.
     It was NOT applied here because the instruction is not to decide accounting
     policy unilaterally — including by picking a "safe subset".
   - Then: encode the chosen rule in `ReverseJournalEntryRequest`, the service, the
     reverse-dialog copy, and the audit reason; cover default / earlier / later /
     closed / reopened / no-period-row for the manual and automated cancellation
     paths (`BillService:533,710`, `InvoiceService:342`, `PayrollPeriodService:1367`).

2. **Resolve shared Accounting decision #12 — F-015**
   - Scope: [large] · Session recommendation: **[separate-recommended]**
   - Why gated: four modules post through this line, and Option B *starts refusing
     postings that succeed today*. The full characterisation, all four options and
     their measured costs are in `audit-report.md` under "Shared Accounting
     decision #12". The question a human must answer first: **should an unattended
     automated posting be subject to maker-checker at all?**
   - Option D (thread the actor into `GrnGlPostingService::post()` and read
     `StockMovement::created_by` in `MovementGlPostingService`) is separable,
     strictly additive, and closes the only two cases where the actor is
     unrecoverable from anywhere. It is `[small]` per writer but lives in
     Inventory's files, so it is **[separate-recommended]** on ownership grounds,
     not on risk.

3. **Add `whereNull('je.deleted_at')` to the statement and dashboard aggregates — F-012, second half**
   - Scope: [small] each, [medium] as a set · Session recommendation: **[separate-recommended]**
   - Why gated: the files belong to M029 (`financial-statements`, `📋 Plan Ready`)
     and M025 (`chart-of-accounts-periods`, `📋 Plan Ready`), plus Dashboard.
     Ten call sites listed in the report. With this session's trigger guard the
     hidden-but-counted state is unreachable through any path found, so these are
     defence-in-depth — but they are a live divergence in a money aggregate
     (`BudgetConsumptionService` filters, the other ten do not) and the next module
     that reintroduces a way to trash a posted row gets an ₱888-class discrepancy
     for free.

4. **Build the acceptance matrix the invariant suite does not cover — F-010**
   - Scope: [large] · Session recommendation: **[separate-recommended]**
   - `JournalLedgerInvariantTest` now covers 8 invariant families (15 tests / 101
     assertions). Still uncovered: the system_admin × finance_officer route/action
     matrix; automated-writer idempotency for all 11 reference types; the SQLite
     branches of both immutability migrations (reviewed, never executed); and the
     SPA — `spa/src/pages/accounting/journal-entries/` has **zero** test files.

5. **Give the reverse dialog a Zod schema and server-error mapping — F-019**
   - Scope: [small] · Session recommendation: **[same-session-ok]**
   - `detail.tsx:34,228` uses bare `useState` + `disabled={!reason.trim()}` against
     a backend `required|string|min:1|max:500`. No `applyServerValidationErrors`,
     so a rejection lands in a generic toast. Add the schema, map errors, and
     decide whether to surface `reverse_date` at all — which depends on item 1, so
     this is best done *with* item 1 rather than before it.

6. **Resolve source references to document numbers — F-007b**
   - Scope: [medium] · Session recommendation: **[separate-recommended]**
   - F-014 removed the raw-id leak by hashing, which is correct but not *useful*: a
     finance officer now sees `Bill 8njw6dNk3K` where they want `BILL-202604-0015`.
     Doing it properly means resolving each of the 11 allow-listed families to its
     own document number, which needs a per-family eager-load in `list()` to avoid
     an N+1 across a 100-row page. Gated on the query design, not on risk.

7. **Decide the chained-reversal question — see report**
   - Scope: [small] once decided · Session recommendation: **[separate-recommended]**
   - Reversing a reversal is accepted. The arithmetic is right; the *label* is not —
     the original still reads `reversed` after being effectively reinstated. Is
     chained reversal the intended reinstatement mechanism, and should the original
     show it?

8. **Add the stale indicator to the list — F-023**
   - Scope: [small] · Session recommendation: **[same-session-ok]**
   - `index.tsx:43` retains placeholder data but never destructures
     `isPlaceholderData`/`isFetching`, so state 5 of the mandated five is silent.
     No other page under `spa/src/pages/accounting/` does this either, so pick the
     house treatment rather than inventing one here.

9. **Harden `toCents` and cap the line memo — F-026, F-027**
   - Scope: [small] · Session recommendation: **[same-session-ok]**
   - `money.ts:8` applies the sign to the whole part only and throws on
     non-numeric input inside a render body. Both are masked today by the Zod regex
     and `negative: false`, so this is a latent-defect cleanup, not a live fix.
     Add `maxLength={200}` to the line memo inputs while there.

10. **Decide `restore()` and the period gate — F-020; and settle `journal_entry_lines.deleted_at` — F-021**
    - Scope: [small] · Session recommendation: **[separate-recommended]**
    - F-020 is a policy trade-off, not a bug: gating restore makes a draft in a
      closed period permanently unrestorable, where today it can be restored and
      re-dated. Deliberately left alone.
    - F-021: the column exists, nothing writes it, and one aggregate filters on it.
      Now that F-011 is fixed without soft-deleting lines, either drop the column
      and the filter or adopt line soft-deletes properly — the latter would require
      a `jel.deleted_at` filter in ten aggregates that lack one, so it is not the
      cheap option it looks like.

11. **Expose the reversal reason on the reversed source — F-022**
    - Scope: [small] · Session recommendation: **[same-session-ok]**
    - Serialization only. The source row's own `reversal_reason` **cannot** be
      written: the trigger's posted branch lists it as immutable, so the
      posted→reversed transition is forbidden from setting it. Surface
      `reversedBy.reversal_reason` in `JournalEntryResource` instead of trying to
      write the column.

12. **`SourceReferenceRegistry` message interpolates a raw id — F-028**
    - Scope: [small] · Session recommendation: **[same-session-ok]**
    - `:90`. Unreachable from the manual API (both fields are `prohibited`), so it
      only ever reaches an internal writer that already knows the id. Cosmetic.

## Reported elsewhere, not this module's work

- **S-001 — `DocumentSequenceService::generate()`** races 4-of-8 concurrent callers
  into a `23505` 500 when no sequence row exists yet. Proven with a barrier-aligned
  8-connection probe. **Cannot bite journal entries**, because
  `assertPostingAllowed()`'s month advisory lock serialises JE creates before the
  sequence is reached. Owner: shared `Common`. Do not fix it from a module session.

## Re-audit acceptance gates

Carried forward, with the ones this session closed marked:

- ✅ Posted/Reversed entries cannot be updated, re-dated, hard-deleted, soft-deleted,
  hidden, or transitioned outside the state machine — executed across 14 verbs.
- ✅ Archiving a draft is reversible: restore returns the same lines, and the
  restored draft is postable.
- ✅ No POSTED row can be hidden from Eloquent while visible to a raw aggregate.
- ✅ Manual/API amounts share a bounded two-decimal contract; over-precision and
  unrepresentable values are 422s, never silent rounding and never 500s.
- ✅ No journal payload carries a raw database id, in any field, including labels.
- ✅ Every write path refuses a closed period, and says which period to reopen.
- ✅ Header totals equal `SUM(lines)` across create → edit → post → reverse and an
  archive/restore round trip.
- ⬜ Reversal dates follow an explicit accounting policy (item 1).
- ⬜ Manual and automated writes retain authoritative actor evidence, and it is
  settled whether maker-checker applies to unattended postings (item 2).
- ⬜ Soft-delete exclusion is consistent across *every* aggregate, not ten-of-eleven
  (item 3).
- ⬜ Both named roles have tested route permissions, and the SPA has any tests at
  all (item 4).
