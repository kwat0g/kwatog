# M006 — Notifications action plan

Initial audit: 2026-08-24  
Re-audit session: 2026-08-24  
Disposition: `📋 Plan Ready`

The five small fixes from the initial audit remain completed and verified. The remaining items are intentionally separate-recommended because they cross module boundaries, affect scheduled-job query design, or require a product/contract decision. This re-audit made no code changes because the remaining plan is not majority same-session-safe.

## Ordered plan

1. `[medium][separate-recommended]` Migrate the remaining `NotificationService::notify()` caller in `Quality/NcrService` to the standard `send()` contract. Preserve the NCR link/entity context, in-app preference behavior, email decision, after-commit side effects, and realtime event. Add a rollback and rendered-envelope regression test. Scope includes the Quality caller, so it must be handled in a Quality/notifications session.

2. `[medium][separate-recommended]` Bound digest database work independently from digest display count. Keep the exact unread total, but fetch only the newest configured item window per subscriber and use a count query/window function rather than loading every unread row into PHP. Add a large-backlog memory/query regression.

3. `[medium][separate-recommended]` Define notification idempotency ownership. Decide whether each producer supplies a stable event/entity key or whether `NotificationService` accepts one and the database enforces it. Cover retries and queue/domain-event redelivery before adding a unique index.

4. `[small/medium][separate-recommended]` Replace the preference `updateOrCreate` loop with a validated, deduplicated bulk upsert or otherwise document the concurrency contract. Add tests for duplicate rows, concurrent updates, and the 200-row ceiling.

5. `[small][separate-recommended]` Run a browser accessibility pass on the bell/list interaction model: choose menu semantics or ordinary dialog/list semantics, add focus entry/return and keyboard behavior, avoid nested interactive controls in list rows, and render a query-error state distinct from “no notifications.”

6. `[small][same-session-ok][completed]` Keep in-app opt-out and email opt-in independent in `NotificationService`; cover the email-only recipient case.

7. `[small][same-session-ok][completed]` Keep list requests at the API ceiling while loading later pages with `useInfiniteQuery`; cover a later bounded page.

8. `[small][same-session-ok][completed]` Normalize malformed nested notification-catalog settings before merging defaults; cover malformed groups/types.

9. `[small][same-session-ok][completed]` Catch lazy realtime initialization failure so polling remains the delivery fallback.

10. `[small][same-session-ok][completed]` Reject non-positive prune windows before the delete query; cover `--days=0` and no deletion.

## Re-audit entry criteria

The next session can close M006 when the deferred contract/query/accessibility items have either been implemented with focused tests or explicitly accepted as documented operational decisions, and the SPA Vitest runner can collect tests successfully.
