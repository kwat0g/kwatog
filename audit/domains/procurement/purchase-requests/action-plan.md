# M036 Action Plan — Purchase Requests

The module is intentionally handed off as `📋 Plan Ready`; no production fixes were made during the audit session.

1. **F-001 — Establish one server-side PR access/action policy.** Scope: **large**. Session recommendation: **separate-recommended**. Scope list/show/PDF, submit/update/delete/cancel, approve/reject/bulk approve, acknowledge, and convert. Preserve explicit all-department authority for system administrators and purchasing officers if confirmed; enforce department/ownership rules for department heads and ordinary requesters. Apply the same policy to `pending-count`. Add cross-department direct-URL/PDF/action tests.

2. **F-002 — Restore exact money arithmetic at the PR boundary.** Scope: **medium**. Session recommendation: **separate-recommended**. Replace float casts in request and line totals and every submit threshold comparison with the repository's decimal/centavo convention. Add tests just below, at, and just above the workflow threshold, auto-approval threshold, and budget boundary; verify resource display remains a string.

3. **F-003 — Resolve ownership for generated PRs before budget assessment.** Scope: **medium**. Session recommendation: **separate-recommended**. Define the owning department for MRP and reorder PRs, persist it at creation or resolve it inside the locked submit transaction, and reject/manual-route unowned records. Add source-specific budget warning/acknowledgement tests.

4. **F-005 — Make submit lock-then-guarded and idempotent.** Scope: **medium**. Session recommendation: **separate-recommended**. Re-read the PR with `lockForUpdate()` inside the transaction, re-check draft state, and ensure retries cannot rewrite active approval records. Add a concurrent-submit regression test and verify rejected/cancelled/approved transitions remain terminal.

5. **F-004 — Define and enforce the missing-estimate policy.** Scope: **medium**. Session recommendation: **separate-recommended**. Either require a positive unit estimate on every manual line, or introduce an explicit unknown-price/manual-review control that cannot be treated as amount zero. Test budget, approval, auto-conversion, and manual conversion behavior.

6. **F-006 — Unify priority and urgency semantics.** Scope: **medium**. Session recommendation: **separate-recommended**. Choose the authoritative field, align public validation and internal producers, make the workflow transition match the policy, update the confirmation copy, and add manual/MRP/reorder coverage for normal, urgent, and critical requests.

7. **F-011 — Enforce catalog-item source-of-truth rules server-side.** Scope: **medium**. Session recommendation: **separate-recommended**. Confirm whether standard cost/description/unit may be overridden. If not, ignore/reject conflicting catalog values on create and update and test threshold manipulation attempts.

8. **F-008 — Make approval queue/actions reflect actual authority.** Scope: **medium**. Session recommendation: **separate-recommended**. Reuse the central policy/current-step/delegation/self-approval logic for pending counts and action availability. Keep the API as the final guard and add tests for wrong step, self-submission, delegation, and department scope.

9. **F-009 — Add referential integrity for automation fields.** Scope: **medium**. Session recommendation: **separate-recommended**. Audit existing rows, add indexes and deliberate foreign keys for `template_id` and `suggested_vendor_id`, and document null-on-delete/restrict behavior.

10. **F-010 — Resolve the template product contract.** Scope: **medium**. Session recommendation: **separate-recommended**. If templates remain supported, apply the authorized template server-side and test department/items/hash IDs. Otherwise remove the validator field, service write path, client types/API, and stale test/docs in a coordinated migration.

11. **F-007 — Return preferred suppliers to list/show resources.** Scope: **small**. Session recommendation: **same-session-ok**. Eager-load `items.suggestedVendor` in both list and show, then add an API/resource test proving the SPA conversion map receives the persisted default.

12. **F-012 — Verify and improve narrow-screen table behavior.** Scope: **small**. Session recommendation: **same-session-ok**. Browser-check create/detail at narrow widths; add the standard overflow wrapper if the table causes clipping or page-level scroll. Preserve keyboard access and table header semantics.

## Verification gate

After fixes, run the focused PHP purchasing/approval tests against a reachable PostgreSQL test database, the detail-page Vitest test, relevant SPA type/lint checks, and a browser check for department roles, direct URLs, PDF, approval actions, and mobile tables. Regenerate the module registry after the follow-up audit.
