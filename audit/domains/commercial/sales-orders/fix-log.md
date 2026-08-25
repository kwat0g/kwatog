# M033 — Sales Orders Fix Log

## 2026-08-25 session

The module was claimed after a fresh registry refresh. The previous report/plan predated substantial uncommitted changes, so the current source was re-audited before fixing. All changes below are limited to the sales-order implementation, its role-facing portal surfaces, and M033 regression tests.

### Fixed

- F-001 — [create.tsx](/home/kwat0g/Desktop/kwatog/spa/src/pages/crm/sales-orders/create.tsx:169) — Removed literal backslashes from the draft-confirmation template literals and removed trailing whitespace; the interrupted create-page syntax is now valid.
- F-002 — [SalesOrderService.php](/home/kwat0g/Desktop/kwatog/api/app/Modules/CRM/Services/SalesOrderService.php:706) and [SalesOrderController.php](/home/kwat0g/Desktop/kwatog/api/app/Modules/CRM/Controllers/SalesOrderController.php:73) — Added a transactionally locked, soft-delete-aware restore service path and routed the controller through it.
- F-003 — [edit.tsx](/home/kwat0g/Desktop/kwatog/spa/src/pages/crm/sales-orders/edit.tsx:230) — Added the incoterm selector so the loaded/persisted field can be edited.
- F-004 — [SalesOrderService.php](/home/kwat0g/Desktop/kwatog/api/app/Modules/CRM/Services/SalesOrderService.php:462) — Confirmation now locks referenced products/customer and rechecks active customer/product invariants before the state change.
- F-006 — [customer.ts](/home/kwat0g/Desktop/kwatog/spa/src/api/b2b/customer.ts:83) and [portal orders page](/home/kwat0g/Desktop/kwatog/spa/src/pages/portal/customer/orders/index.tsx:28) — Preserved the full paginator response, sent page/status parameters, and added status filtering and shared pagination controls.
- F-007 — [edit.tsx](/home/kwat0g/Desktop/kwatog/spa/src/pages/crm/sales-orders/edit.tsx:157) — Added lookup loading/empty helper text, disabled states, and retryable query errors matching create.
- F-009 — [SalesOrderRouteCoverageTest.php](/home/kwat0g/Desktop/kwatog/api/tests/Feature/CRM/SalesOrderRouteCoverageTest.php:27) — Added direct coverage for CRUD/restore/incoterm round-trip, date ordering, confirmation reference rechecks, cancellation downstream guard, removed generic transition route, named-route permissions, and customer-portal page two metadata.

### Verification

- php -l passes for the modified CRM controller/service and the new feature test.
- Scoped ESLint passes for the modified sales-order and portal TypeScript files.
- Focused existing backend suites: 52 tests, 183 assertions passed.
- New M033 route-coverage suite: 9 tests, 32 assertions passed.
- Final full SPA typecheck reports only unrelated project diagnostics: CreateAccountModal.tsx unused errors, accounting/periods.tsx possibly undefined data, and the existing qrcode module/type errors in assets/detail.tsx. No M033 or portal diagnostics remain.

### Deferred

- F-005 — The queued-MRP cancellation race needs a lock/re-read change in api/app/Modules/MRP/Services/MrpEngineService.php/job flow, outside M033 scope.
- F-008 — Cancelled-chain semantics and historical timestamp backfill need shared ChainDefinitions/broadcaster and migration coordination, outside M033 scope.
- F-010 — Cancellation-reason requiredness is a product/compliance decision; current optional behavior was not changed.
- F-011 — Whether system administrators need archive/restore UI is a product/operations decision; the API recovery path was hardened but no new UI action was invented.
