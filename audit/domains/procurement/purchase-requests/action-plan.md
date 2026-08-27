# M036 Action Plan — Purchase Requests

Decision: **📋 Plan Ready**. No production fixes are authorized this session because the plan is dominated by separate-recommended lifecycle, policy, and cross-module work.

## Small actions

1. **F-015 — Restore soft-deleted PRs.** Add `withTrashed()` to the PR restore route and a focused soft-delete/restore feature test. **same-session-ok**.
2. **F-014 — Correct critical-priority confirmation copy.** After the owner confirms the urgent/critical cap and notification policy, describe the actual behavior rather than promising direct VP notification. **same-session-ok**.
3. **F-018 — Remove the conversion listener's float cast.** Use the repository's exact `Money` comparison for the null/positive price gate and retain manual-conversion fallback. **same-session-ok**.

## Medium actions

4. **F-019 — Isolate narrow-screen overflow.** Use the installed browser environment to identify the element causing document-level overflow at 375/390/768px, then keep tables and steppers locally scrollable without clipping. **separate-recommended** because the likely boundary includes shared layout components.
5. **F-004 — Decide the missing-estimate contract.** Either require positive estimates or add an explicit unknown-price/manual-review state that cannot pass budget/approval as zero; cover API, UI, budget, workflow, and conversion. **separate-recommended**.
6. **F-010 — Resolve templates.** Either remove the surviving `template_id` validator/service/client contract or implement authorized template application and eager-load the relation in list/show responses. **separate-recommended**.
7. **F-011 — Enforce catalog source of truth.** Decide whether catalog description/unit/standard cost are editable estimates; if not, reject or ignore conflicting create/update values and add tampering tests. **separate-recommended**.
8. **F-016 — Serialize delete with submit.** Lock and re-check the authoritative draft row in a transaction before soft deletion; add a stale-instance race test. **separate-recommended**.
9. **F-017 — Serialize update with submit.** Lock and re-check draft status before replacing fields/lines; add concurrent update/submit coverage so approval amounts cannot diverge from line items. **separate-recommended**.

## Large action

10. **F-013 — Establish ownership for every submitted PR.** Require or deterministically resolve a department for manual, MRP, and reorder requests before budget assessment and workflow creation. Coordinate Purchasing, approval-workflow, budgeting, MRP, and inventory fixtures so every pending request has an eligible department-head path. **separate-recommended**.

## Follow-up verification gate

After the separate work, rerun the focused API suite against `DB_DATABASE=ogami_test_m036_roll_d`, the detail Vitest test, affected-page ESLint, SPA typecheck, PHP lint/PHPStan, and the repository Playwright visual checks at phone/tablet/desktop widths. Install/provision the missing Playwright executable before claiming browser verification. Commit only explicit module-owned files before release; do not regenerate or edit the shared registry.
