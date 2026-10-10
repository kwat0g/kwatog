# MRP plan list response repair

The PPC request to `/mrp/plans` at `2026-10-09T18:44:11.251Z` failed with `Attempt to read property "value" on null` in `MrpPlanResource.php:25`.

`MrpEngineService::list()` loaded a partial Sales Order containing `id`, `so_number` and `customer_id`. The shared plan resource also needs the Sales Order status, which was omitted from that projection. Stored Sales Order statuses were valid; the live database contained no null statuses. Detail queries loaded the full Sales Order, so the list failed while the linked detail could work.

The fix adds `status` to the existing list relationship projection. It preserves the actual status and avoids changing the serializer to return an invented or missing value.

Verification:

- The new PPC list regression returned HTTP 500 before the fix with the exact null-status error.
- All three `MrpPlanDetailTest` tests passed afterward, with 52 assertions, in a dedicated PostgreSQL database. The regression checks both confirmed and closed orders, their hash IDs and customer names.
- Live authenticated HTTP-kernel checks returned 200 for all three list pages, covering all 59 plans, the latest plan detail, and its Sales Order plan endpoint.
- Fingerprints of 250 business tables remained identical before and after the deployed one-line patch. Probes ran in read-only transactions without changing the login session.
- PHP syntax checks passed for the changed service and test.

The earlier `/mrp/scheduler/snapshot` incident was a separate schema rollout mismatch, repaired by applying its missing additive migrations. The common generic 500 response made these different failures appear identical.

A separate `alerts:run` warning was observed at local `2026-10-10 02:45:09`: production alert checking attempted to lazy-load `WorkOrder.salesOrder` while lazy loading was disabled. That background alert error is outside this request repair and remains unresolved. No claim is made that all pages or scheduled checks pass.

Scoped source backup, failing and passing test logs, live probes and data fingerprints are under `/tmp/mrp-plan-list-fix-20261009`.
