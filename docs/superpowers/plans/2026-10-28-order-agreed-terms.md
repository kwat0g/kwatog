# Order Agreed Terms Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. All workers and reviewers must use GPT-6-Luna, as explicitly requested by the user; the controller owns design decisions and quality oversight.

**Goal:** Preserve customer-visible terms and acknowledgment for a sales order and expose them during problem review.

**Architecture:** Add a CRM-owned agreement revision model and a narrow service using the existing sales-order lock. Keep agreement HTTP actions separate from order negotiation, and reuse private-file and portal ownership patterns. Render one shared agreement panel on existing order pages and a read-only version on customer problem pages.

**Tech Stack:** Existing Laravel 11, PostgreSQL, React 18, TypeScript, TanStack Query, React Hook Form/Zod, PHPUnit, and Vitest; no new dependencies.

**Spec:** `docs/superpowers/specs/2026-10-28-order-agreed-terms-design.md`

## Global Constraints

- No invented default terms, warranty durations, or automatic remedy decisions.
- Publication and acknowledgment occur only before order confirmation; confirmed orders are read-only.
- Orders without published agreements retain current behavior.
- One optional private PDF, JPEG, PNG, or WebP attachment per revision, up to 10 MiB (`max:10240` in Laravel).
- Published content, snapshot, file, and existing acknowledgment are immutable.
- Existing internal notes are excluded from the commercial snapshot and are not repurposed.
- Only actual customer portal actors may record portal acknowledgment. External acknowledgment is explicitly staff-recorded evidence.
- All workers/reviewers use `gpt-6-luna`; workers do not spawn further agents.
- Read `CLAUDE.md`, matching `docs/PATTERNS.md` sections, and the design before editing. UI workers also read `docs/DESIGN-SYSTEM.md`.
- Use feature branch `feat/order-agreed-terms` in the existing shared checkout. No deployment, push, live database migration, or live mail action is authorized by this plan.
- Backend tests run against a dedicated disposable test database, never the live/default application database. Do not run two refresh suites against the same database.

## File map and interfaces

Backend owns all `api/` changes. Frontend owns all `spa/` changes. The controller owns these planning documents and the progress ledger. Backend and frontend changes may proceed against the exact API contract below without editing each other's files; integration and review follow both tasks.

### Agreement JSON

```ts
interface OrderAgreement {
  id: string;
  revision: number;
  terms: string;
  snapshot: {
    customer_id: string;
    payment_terms_days: number;
    delivery_terms: string | null;
    incoterm: string | null;
    subtotal: string;
    vat_amount: string;
    total_amount: string;
    items: Array<{
      product_id: string;
      product_label: string;
      quantity: string;
      unit_price: string;
      delivery_date: string | null;
    }>;
  };
  published_at: string;
  published_by: string;
  file_name: string | null;
  acknowledgment: null | {
    method: 'portal' | 'external';
    representative: string;
    acknowledged_at: string;
    recorded_at: string;
    recorded_by: string;
    reference: string | null;
  };
  is_current: boolean;
  is_stale: boolean;
}
interface OrderAgreements {
  sales_order_id: string;
  so_number: string;
  can_publish: boolean;
  can_record_external: boolean;
  can_acknowledge: boolean;
  revisions: OrderAgreement[]; // newest first
}
```

HTTP success body is `{ data: OrderAgreements }`, except downloads, which stream the private attachment. No storage path or raw integer ID is returned. Dates use ISO 8601; decimal values remain strings.

| Audience | Action | Route beneath `/api/v1` | Input |
|---|---|---|---|
| Internal | List | `GET /crm/sales-orders/{salesOrder}/agreements` | none |
| Internal | Publish | `POST /crm/sales-orders/{salesOrder}/agreements` | multipart `terms`, optional `file` |
| Internal | Record external acknowledgment | `POST /crm/sales-orders/{salesOrder}/agreements/{agreement}/acknowledge` | `representative`, `acknowledged_at`, `reference` |
| Internal | Download | `GET /crm/sales-orders/{salesOrder}/agreements/{agreement}/file` | none |
| Customer | List | `GET /b2b/customer/orders/{salesOrder}/agreements` | none |
| Customer | Acknowledge | `POST /b2b/customer/orders/{salesOrder}/agreements/{agreement}/acknowledge` | `{ acknowledged: true }` |
| Customer | Download | `GET /b2b/customer/orders/{salesOrder}/agreements/{agreement}/file` | none |

Publication validates `terms` as trimmed nonblank string, maximum 10000 characters. External acknowledgment validates nonblank `representative` (maximum 200 characters), nonfuture `acknowledged_at`, and nonblank `reference` (maximum 2000 characters) identifying evidence that these exact terms were accepted. An external acknowledgment may predate publication because staff can record a personal agreement afterward; server `recorded_at` preserves when it was entered. Portal actor, representative, and timestamps are server-derived. Acknowledgment rejects a stale or superseded revision; an unchanged retry returns existing acknowledgment without overwriting it.

ReturnCaseResource adds optional `order_agreements: OrderAgreements | null` for customer cases using the original `delivery.salesOrder`, never the replacement order. All three mutation capabilities are false in this read-only projection. Supplier cases return null. Internal return reviewers may download using the internal file route if their authorized case links that order.

### Task 1: Agreement backend and regression proof

**Files:**
- Create: `api/database/migrations/2026_10_28_180000_create_sales_order_agreements_table.php` (check actual dependencies before retaining this timestamp).
- Create: `api/app/Modules/CRM/Models/SalesOrderAgreement.php`.
- Create: `api/app/Modules/CRM/Services/SalesOrderAgreementService.php`.
- Create: `api/app/Modules/CRM/Controllers/SalesOrderAgreementController.php`.
- Create: `api/app/Modules/CRM/Requests/PublishSalesOrderAgreementRequest.php`.
- Modify: `api/app/Modules/CRM/Models/SalesOrder.php`, `api/app/Modules/CRM/Services/SalesOrderService.php`, `api/app/Modules/CRM/routes.php`, `api/app/Modules/B2B/routes.php`.
- Modify: `api/app/Modules/ReturnManagement/Services/ReturnCaseService.php`, `api/app/Modules/ReturnManagement/Resources/ReturnCaseResource.php`.
- Test: `api/tests/Feature/CRM/SalesOrderAgreementTest.php`.

**Interfaces:**
- Produces all HTTP shapes above and confirmation guard.
- Service methods: `publish(SalesOrder $order, User $actor, string $terms, ?UploadedFile $file): SalesOrderAgreement`; `acknowledgePortal(SalesOrder $order, SalesOrderAgreement $agreement, CustomerPortalUser $actor): SalesOrderAgreement`; `acknowledgeExternal(SalesOrder $order, SalesOrderAgreement $agreement, User $actor, array $data): SalesOrderAgreement`; `assertConfirmable(SalesOrder $order): void`; `summary(SalesOrder $order, Request $request, bool $readOnly = false): array`.
- Resource uses `summary` only on detail/explicit agreement reads; avoid adding per-order agreement queries to order lists.

- [x] **Step 1: Write focused failing feature tests.** Reuse role/feature and portal setup from `CustomerPortalOrderTest`, `SalesOrderResponsePortalTest`, and `ReturnCaseWorkflowTest`. Cover publication, JSON shape, visibility, cross-customer and wrong-pair rejection, invalid/oversized uploads, external provenance, confirmation guards, immutable history, unchanged acknowledgment retries, no-terms compatibility, and return projection. Core assertions include:

```php
$this->assertSame('portal', $agreement->fresh()->acknowledgment_method);
$this->assertSame($firstAcknowledgedAt, $agreement->fresh()->acknowledged_at->toIso8601String());
$this->assertSame($firstTerms, $firstRevision->fresh()->terms);
$this->assertArrayNotHasKey('file_path', $response->json('data.revisions.0'));
$otherCustomerResponse->assertForbidden();
$this->assertSame($beforeCaseStatus, $case->fresh()->status);
```

- [x] **Step 2: Run failing tests in isolation.** Detect running Docker services. Host PHP is absent; use `docker compose exec -T -e DB_DATABASE=ogami_test_agreements api php artisan test --filter=SalesOrderAgreementTest`. Create this dedicated test database through the database container if absent. First failure must demonstrate absent feature, not broken test setup. Record actual output.
- [x] **Step 3: Add additive persistence and service.** Migration creates bigint primary key, order foreign key, integer revision, text terms, JSONB snapshot, nullable file metadata, publisher foreign key and publication time, nullable acknowledgment method/representative/times/reference and portal/internal actor foreign keys, and timestamps. Add unique order/revision. Match repository foreign-key/deletion conventions while preserving historical actors. Add `agreements(): HasMany` to SalesOrder. `HasHashId` and `HasAuditLog` apply to agreement records. Reject updates to publication fields and changes to an already recorded acknowledgment in the model; do not expose update/delete routes.

```php
return DB::transaction(function () use ($order, $actor, $terms, $file) {
    $locked = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
    // Enforce pre-confirmation state, allocate next revision, derive snapshot,
    // persist private file and immutable revision, and clean file on failure.
});
```

Implement one canonical server-side snapshot builder used by publication, stale checks, acknowledgment, and confirmation. Query authoritative items. Normalize decimal strings to database scales; sort by stable commercial values, not item IDs. Product labels are captured for display but must not make terms stale merely because a catalogue label changes. Exclude notes and fulfillment/status changes. Store public HashIDs. Compare canonical values rather than client fingerprints.

In `SalesOrderService::update`, reject customer reassignment under the existing order lock once any revision exists, and add a regression test. Otherwise a new customer would inherit another customer's agreement history and files. Orders without agreements retain existing customer-edit behavior.

- [x] **Step 4: Wire HTTP and confirmation.** All list/download routes enforce the appropriate internal permission or customer ownership. Return-only access additionally requires a linked accessible customer return case. Mutation routes use existing sensitive throttles and proper feature gates. Customer actions resolve `customer_portal`, never the default guard. Mirror `SystemUserResolver::impersonate` for portal writes using HasAuditLog, preserving actual portal actor separately. Nest-check agreement/order before file access. Validate uploads as `file|mimes:pdf,jpg,jpeg,png,webp|max:10240`. Do not trust submitted revision/status/snapshot. `confirm()` calls `assertConfirmable($lockedSo)` under its existing lock before changing state. An order with no agreement returns immediately; otherwise require current acknowledgment and matching commercial snapshot. Keep ordinary order responses unchanged.
- [x] **Step 5: Add return projection.** Eager-load original delivery/order and agreement history on case detail, then include summary read-only. Public resource keeps storage paths and internal IDs private. Do not write or change resolution state on reads.
- [x] **Step 6: Verify and report.** Run new tests plus focused existing `SalesOrderResponsePortalTest`, `CustomerPortalOrderTest`, `SalesOrderStatusTransitionsTest`, and `ReturnCaseWorkflowTest` sequentially against the dedicated test database. Run PHP syntax on changed PHP files through Docker. Save commands, pass/fail output, changed files, and limitations in `/tmp/order-agreements-backend-report.md`. Do not migrate the application database or send real mail. Do not commit shared files while another worker is editing.

### Task 2: Existing page integration and frontend proof

**Files:**
- Create: `spa/src/types/orderAgreements.ts`.
- Create: `spa/src/components/crm/OrderAgreementsPanel.tsx` and `OrderAgreementsPanel.test.tsx`.
- Modify: `spa/src/api/crm/salesOrders.ts`, `spa/src/api/b2b/customer.ts`.
- Modify: `spa/src/pages/crm/sales-orders/detail.tsx`, `spa/src/pages/portal/customer/orders/detail.tsx`.
- Modify: `spa/src/types/returnCases.ts`, `spa/src/pages/return-management/cases/detail.tsx`.

**Interfaces:**
- Consumes `OrderAgreement`, `OrderAgreements`, HTTP routes, and return `order_agreements` from the file map above.
- Produces `OrderAgreementsPanel({ orderId, realm, readOnlyData? })`, where `realm: 'internal' | 'customer'` and `readOnlyData?: OrderAgreements`.
- API methods: `agreements(orderId)`, internal `publishAgreement(orderId, formData)`, internal `recordAgreementAcknowledgment(orderId, agreementId, data)`, customer `acknowledgeAgreement(orderId, agreementId)`, and each client's `agreementFile(orderId, agreementId)` returning a Blob. Use existing download utility if available.

- [x] **Step 1: Add focused failing component tests.** Mock API modules with repository Vitest patterns. Cover honest empty state, current text and snapshot display, preserved history, explicit visibility warning, disabled submit while pending, inline server errors, portal acknowledgment payload for displayed revision, external acknowledgment wording, stale terms disabling acknowledgment, and return read-only mode never fetching or exposing mutation controls. Example interaction/assertion:

```tsx
render(<OrderAgreementsPanel orderId="order-hash" realm="customer" />);
await user.click(await screen.findByRole('checkbox', { name: /reviewed.*terms/i }));
await user.click(screen.getByRole('button', { name: /acknowledge terms/i }));
expect(customerPortalApi.acknowledgeAgreement).toHaveBeenCalledWith('order-hash', 'revision-hash');
```

- [x] **Step 2: Run to prove failure.** From `spa`, run `npm run test:run -- src/components/crm/OrderAgreementsPanel.test.tsx`. Record missing-feature failure.
- [x] **Step 3: Implement types, API functions, and panel.** Use house components and style tokens, no dependencies. Each realm's API uses its existing HTTP-only cookie client; never set multipart Content-Type manually. React Hook Form/Zod matches limits and server field errors. On mutation success invalidate agreement query and relevant order detail queries; acknowledge only explicitly checked terms. Show “No terms recorded” rather than implied company defaults. Display latest revision first, historical revisions in accessible disclosures, and captured payment/delivery/item values alongside each revision. Do not render terms as HTML.

```tsx
<Textarea label="Terms shared with customer" maxLength={10000} />
<p>These terms and the attachment will be visible to this order’s customer.</p>
```

Publication and external acknowledgment controls follow server capabilities and the user's existing permissions. External form requires representative, nonfuture acknowledgment time, and evidence/reference; label its result distinctly from customer portal acknowledgment. Attachment control accepts only allowed types, displays limit, and uses Blob download with object URL cleanup. Internal or portal panel fetch failures show retry; read-only mode uses supplied data without any request or action.
- [x] **Step 4: Mount in existing pages.** Insert panel into internal and portal order detail. Extend ReturnCaseRecord with nullable `order_agreements` and render read-only panel when present in the shared problem detail page (confirm this component serves both realms). Preserve existing totals, notes, order response, delivery proposal, and return actions. No new navigation item, page, or global contract module.
- [x] **Step 5: Run focused checks.** Run new component tests plus existing internal/portal order detail and return case detail tests. Run `npm run typecheck`, targeted ESLint on changed TypeScript files, `npm run audit:tokens`, and `npm run build`. Use host node_modules when available; otherwise existing SPA container. Record commands/output and limitations in `/tmp/order-agreements-frontend-report.md`. Do not deploy or change backend files.

### Task 3: Integration review, repairs, and completion

**Files:** All files changed by Tasks 1–2; this plan and progress ledger.

**Interfaces:** Full API contract and acceptance list in the design.

- [x] **Step 1: Controller reviews worker reports and diff.** Confirm schema/API/frontend naming consistency, trust boundaries, original-order return linkage, stale snapshot behavior, actor attribution, file cleanup, and backward compatibility. Resolve discrepancies in worker instructions; implementation repairs remain GPT-6-Luna work.
- [x] **Step 2: GPT-6-Luna reviewer independently checks spec and quality.** Read design, plan, relevant changed files, connected callers, and worker test output. Report actionable correctness/security/regression findings with file and line. No speculative feature expansion. Save `/tmp/order-agreements-review.md`.
- [x] **Step 3: Delegate any repairs and re-review changed paths.** Keep same model. Run covering tests after each repair. Do not declare complete with unaddressed material findings.
- [x] **Step 4: Verify actual integration.** Run focused backend/frontend gates against final shared checkout; reuse already passing checks if no relevant code changed. Exercise publication, acknowledgment, stale revision rejection, private download, and read-only return projection through feature tests. If real browser check is available, use Chromium for render checks; keep test mail fake. Never claim browser testing without running it.
- [x] **Step 5: Record results and finish.** Update checkboxes/ledger only from evidence, run `git diff --check`, and commit feature files and documentation on `feat/order-agreed-terms` after workers finish. Report actual test results and remaining limits. Leave deployment and application migration as separate actions; no push.

## Execution ledger

- Planning: completed from existing order, customer portal, return, and private-file seams. No company contract or policy values were assumed.
- Preflight interfaces: Task 1 produces the JSON/HTTP contract consumed by Task 2; task ownership separates `api/` and `spa/`; Task 3 covers both. No overlapping implementation files.
- Preflight Task 1: migration, service, permissions, confirmation guard, return projection, and tests agree with the design.
- Preflight Task 2: panel/API types and existing-page integration consume the same contract; read-only projection disables all actions.
- Preflight Task 3: review and final gates cover both deliverables; no deployment is included.
- Execution decisions: optional terms preserve no-terms orders; first version permits revisions only before confirmation; acknowledged terms are records, not automatic warranty or financial rules.


## Completion evidence

Implemented on `feat/order-agreed-terms` by GPT-6-Luna backend and frontend workers, with controller oversight and a separate GPT-6-Luna quality review.

- Backend agreement suite: **7 passed, 54 assertions**.
- Backend existing regression suites: **85 passed, 538 assertions** across `SalesOrderResponsePortalTest`, `CustomerPortalOrderTest`, `SalesOrderStatusTransitionsTest`, and `ReturnCaseWorkflowTest`.
- Frontend focused suites: **57 passed** across agreement panel, internal order detail, customer order detail, and return case detail.
- PHP syntax: all **12 changed API files** passed. Targeted frontend ESLint, token audit, and whitespace checks passed.
- Standalone Vite production bundle passed: **8,212 modules**, output `/tmp/ogami-agreements-build`.
- Full TypeScript/build gates remain blocked by **32 existing TypeScript diagnostics**. A disposable archive of baseline `HEAD:spa` and the feature checkout produced identical normalized diagnostics (empty diff; SHA-256 `b5335c6fa034c1abcaac5a3adb51f116110816fbd78314fdda7f21443d8e1443`). No unrelated fixes were added.
- Independent final source review found no material issues. Additional proof covers acknowledgment becoming stale, superseded revisions, cross-customer access, private file downloads, return-only reader scope, actual order confirmation, and original-order return projections.
- External acknowledgment may predate system publication; actual server recording time is stored separately. This permits personal agreements recorded later without representing staff entry as portal acknowledgment.
- Orders with published terms cannot be reassigned or deleted through the sales-order service, preserving customer confidentiality and history; cancellation remains governed by the existing workflow.
- No live database migration, deployment, push, real mail action, browser journey, or concurrency stress test was performed. Apply the additive migration before enabling this code in an application environment. Published terms are read-only after order confirmation; no automatic coverage or remedy decisions were added.

Detailed worker evidence for this session: `/tmp/order-agreements-backend-report.md`, `/tmp/order-agreements-frontend-report.md`, and `/tmp/order-agreements-review.md`.
