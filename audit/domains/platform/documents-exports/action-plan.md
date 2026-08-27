# M011 — Documents & Exports action plan

Plan date: 2026-08-27
Module: platform / documents-exports
Gate result: **📋 Plan Ready — no fixes in this session**

The plan is intentionally ordered by authorization/lifecycle risk. Each action
is tagged with scope and whether it is suitable for the same audit session.

1. **[large · separate-recommended] Resolve document ownership, permissions,
   and retention.** Decide the owner/retention model for financial statements,
   audit reports, and other report-like PDFs; complete the `DocumentType` →
   permission matrix, including `packing_list` and `commercial_invoice`; then
   route the remaining official PDFs through `PdfRenderService` and
   `DocumentVaultService`. (M011-F05)

2. **[medium · separate-recommended] Decide and enforce scheduled-export
   recipients.** Obtain the business policy for owner-only, organization,
   approved-domain, or admin-approved recipients. Enforce it on create/update
   and immediately before execution; audit rejected or changed schedules.
   (M011-F11)

3. **[medium · separate-recommended] Add typed filter contracts.** Register
   value kind, allowed enum values, hash-ID validation, scalar limits, and
   empty-value behavior for each module filter. Apply the same validation to
   HTTP, persistence, and background execution; test malformed stored JSON.
   (M011-F13)

4. **[medium · separate-recommended] Correlate mail delivery outcomes.** Define
   the meaning of `last_run_at`, pass schedule/attempt identity into the
   mailable, and persist provider failure/retry state or a separate delivery
   ledger. Add a queue-failure test that verifies the UI-visible result.
   (M011-F12)

5. **[medium · separate-recommended] Make export generation resource-safe.**
   Use chunk/cursor or disk-backed generation, avoid duplicate full-byte copies,
   define request/worker timeouts, and expose actionable over-limit metrics.
   Keep the existing row and artifact caps as defense-in-depth. (M011-F07)

6. **[medium · separate-recommended] Complete vault lifecycle integrity.**
   Approve per-type retention, prune superseded rows/blobs safely, make
   scheduled-artifact retention observable, and verify stored SHA-256 digests
   during reconciliation and delivery. Quarantine or alert on mismatch.
   (M011-F08, M011-F16)

7. **[medium · separate-recommended] Expand entity-scoped document discovery.**
   Publish a guarded resolver and UI contract for the supported business
   entities, or explicitly narrow the module. Mount it on intended detail pages
   and add wrong-entity/wrong-department/unsupported-entity tests. (M011-F06)

8. **[medium · separate-recommended] Close verification gaps.** Add statement,
   ImpEx, checksum-mutation, recipient/filter, delivery-failure, and
   capability-matrix tests. Install/pin Chromium in the E2E environment and run
   `spa/e2e/scheduled-exports.spec.ts` against the supported stack. (M011-F09)

9. **[small · separate-recommended] Make the scheduled-export page
   capability-aware.** Hide or disable “New schedule” for a schedule-list-only
   role, or load a permitted module catalog and let the user choose a module.
   Keep the backend module-permission check as the authority. (M011-F14)

10. **[small · same-session-ok] Align SPA document types.** Add
    `packing_list` and `commercial_invoice` to the frontend `DocumentType` union
    and add a fixture/type contract check. (M011-F15)

## Session gate

Only 1 of 10 actions is `same-session-ok`; 9 are `separate-recommended`, and
the total scope includes a large cross-module decision. The majority/small-scope
gate is not met, so no action is being implemented now.
