# M036 Fix Log

Session: 2026-08-25

## Implemented

1. **F-001 — Department/ownership row authorization (fixed in code; runtime verification pending).**
   - Before: `PurchaseRequestService::list()` treated the approve permission as company-wide visibility, and direct row routes had no department/ownership guard.
   - After: added `PurchaseRequestAccessPolicy::visibleTo()` and action checks for department heads, request owners, purchasing officers, and system administrators (`api/app/Modules/Purchasing/Policies/PurchaseRequestAccessPolicy.php:26-123`); wired list, show, PDF, update/delete/restore, submit/cancel, approval, budget acknowledgement, and conversion boundaries (`api/app/Modules/Purchasing/Controllers/PurchaseRequestController.php:41-205`; `api/app/Modules/Purchasing/Services/PurchaseRequestService.php:41-70,154-240,390-515`).
   - Added scoped pending-count filtering for department, self-submission, current-step, and active delegation (`api/app/Modules/Purchasing/Controllers/PurchaseRequestController.php:176-194`).

2. **F-002 — Exact PR money arithmetic (fixed in code; runtime verification pending).**
   - Before: request totals, line totals, and PR approval/urgent thresholds crossed through float casts.
   - After: totals use `Money::mul()`/`Money::add()` and thresholds use canonical decimal strings with `Money` comparisons (`api/app/Modules/Purchasing/Models/PurchaseRequest.php:102-120`; `api/app/Modules/Purchasing/Models/PurchaseRequestItem.php:44-50`; `api/app/Modules/Purchasing/Services/PurchaseRequestService.php:259-297,333-345,533-545`).

3. **F-003 — Generated-PR budget ownership (fixed in code; runtime verification pending).**
   - Before: generated PRs with `department_id = null` skipped budget assessment.
   - After: submit resolves the requester’s employee department, persists it, and refuses an unowned generated PR before approval records are created (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:236-263`). The update request now accepts a hash-resolved department for an authorized draft operator (`api/app/Modules/Purchasing/Requests/UpdatePurchaseRequestRequest.php:23-45`).

4. **F-005 — Concurrent/idempotent submit (fixed in code; runtime verification pending).**
   - Before: the draft guard ran before the transaction and no authoritative row lock/re-check existed.
   - After: submit locks and re-checks the PR inside the transaction before budget, supplier prefill, or approval workflow creation (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:225-241`).

5. **F-006 — Priority/urgency alignment (fixed in code; runtime verification pending).**
   - Before: the public `priority` field and automation labels did not drive the `is_urgent` workflow branch.
   - After: priority `urgent`/`critical` is the public and automation-facing urgency signal; create/update normalize the legacy flag, submit normalizes legacy rows, and the resource reports the effective urgency (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:95-115,175-187,267-285`; `api/app/Modules/Purchasing/Resources/PurchaseRequestResource.php:14-38`).

6. **F-007 — Preferred-supplier conversion data (fixed in code; runtime verification pending).**
   - Before: list/show/PDF did not eager-load `items.suggestedVendor`, so the conversion UI could not receive the persisted default.
   - After: eager-loaded the relation in list/show/PDF (`api/app/Modules/Purchasing/Services/PurchaseRequestService.php:43-49,75-84`; `api/app/Modules/Purchasing/Services/PurchaseRequestPdfService.php:26-32`).

7. **F-008 — Approval queue/action presentation (fixed in code; runtime verification pending).**
   - Before: pending counts and detail buttons were based on coarse permission checks rather than the current step, department, delegation, and self-approval rules.
   - After: the server exposes a detail-level action model (`api/app/Modules/Purchasing/Policies/PurchaseRequestAccessPolicy.php:125-173`; `api/app/Modules/Purchasing/Resources/PurchaseRequestResource.php:112-117`), and the detail page gates submit, approve/reject, convert, PDF, cancel, and budget acknowledgement from that model (`spa/src/pages/purchasing/purchase-requests/detail.tsx:111-127`).

8. **F-009 — Automation reference integrity (fixed in code; migration verification pending).**
   - Before: `template_id` and `suggested_vendor_id` were nullable bare integers without indexes or foreign keys.
   - After: added a migration that fails fast on orphaned legacy references, then adds indexes and `nullOnDelete()` foreign keys (`api/database/migrations/2026_08_25_152000_harden_purchase_request_automation_fields.php:12-67`).

9. **F-012 — Narrow-screen line-item tables (fixed in code).**
   - Before: create/detail tables could force page-level horizontal scrolling.
   - After: wrapped both tables in the design-system overflow container (`spa/src/pages/purchasing/purchase-requests/create.tsx:221-343`; `spa/src/pages/purchasing/purchase-requests/detail.tsx:243-270`).

## Deferred pending decisions

- **F-004 — Missing estimates:** not changed. The existing plan requires a product decision between requiring positive estimates and introducing an explicit manual-review state; treating null/zero as either valid or invalid would guess at that business rule.
- **F-010 — Templates:** not changed. The existing plan requires a product decision to remove the live `template_id` contract or implement template application end-to-end.
- **F-011 — Catalog source of truth:** not changed. The existing plan requires confirmation whether catalog description/unit/standard cost may be overridden by requesters.

## Verification

- Passed PHP syntax checks for all changed PHP files.
- Passed targeted PHPStan for the changed Purchasing classes: `[OK] No errors`.
- Passed targeted SPA ESLint for the changed PR pages/types.
- Added focused regression coverage in `api/tests/Feature/Purchasing/PurchaseRequestHardeningTest.php:34-224` for row scope, pending-count scope, centavo totals, generated ownership, submit idempotency, urgency, and preferred vendors.
- Focused PHP tests could not run because PostgreSQL host `db` does not resolve (`SQLSTATE[08006]`).
- Focused Vitest could not start because the existing root-owned `spa/node_modules/.vite-temp` directory denies writes (`EACCES`). The full SPA typecheck was stopped after several minutes without output; no affected-file parser errors remained, and targeted ESLint passed.
