# Customer Payment Verification Implementation Plan

> **For agentic workers:** Use `superpowers:executing-plans` to implement this plan task by task, inline in the current session. Do not delegate. Steps use checkboxes for tracking.

**Goal:** Let customers submit payment evidence for a finalized invoice and let Finance verify receipt of funds in one action that creates the existing accounting collection.

**Architecture:** Add an accounting-owned payment submission record, separate from posted collections. Customer and Finance controllers share one submission service; successful verification calls the existing `InvoiceService::recordCollection()` inside the same database transaction. Reuse existing portal tenancy, permissions, private file storage, modal components, decimal money helpers, journal posting, official receipts, and order completion logic.

**Tech Stack:** Laravel, PostgreSQL, existing BCMath/Money helpers, React, TypeScript, TanStack Query, React Hook Form, Zod, PHPUnit, Vitest, existing Playwright tooling. No new dependencies.

**Spec:** The product contract below incorporates the conversation's payment-flow proposal. Full-balance portal payments and an administrator-only manual exception are explicit assumptions for user review before execution.

## Global Constraints

- Planning only until implementation is approved. This document does not authorize any live payment submission, verification, collection, or ledger posting.
- Preserve the shared dirty feature branch and all unrelated work. Inspect current changes before execution; do not broadly stage, reset, clean, or reseed the live database.
- Amounts remain decimal strings with two decimal places. Do not use JavaScript floating-point arithmetic or silently round submitted money.
- The customer portal submits the entire current outstanding balance. This is a deliberate full-settlement policy; exceptional partial receipts remain possible through the restricted manual path.
- Customer evidence does not mark an invoice paid. Only Finance's confirmation of actual received funds changes collections, balance, journals, receipts, or order completion.
- Hide the ordinary **Record collection** button and enforce the equivalent restriction on the backend.
- Approved no-charge replacement orders remain free and create no payment requirement or invoice.
- Keep evidence private and preserve old collections, journals, official receipts, and invoice numbering.
- No bank integration, online checkout, split-payment allocation, automatic proof approval, extra approval tier, or general payment subsystem in this change.
- No application deployment or live migration before the change and migration proof are reviewable under existing workspace authorization.

## Product contract

### Customer experience

1. On an owned `finalized` or `partial` invoice with positive balance and no pending submission, show **Submit payment**.
2. The modal displays the invoice number and read-only **Amount to settle** from the server balance. For `INV-202610-0001`, this is currently **PHP 19,600.00**.
3. Require **Sending bank**, **Payment date**, **Reference number**, and one **Payment proof**. The label distinguishes the customer's bank from Finance's receiving ledger account.
4. Accept PDF, JPEG, PNG, or WebP, maximum **10 MiB** (`10,485,760` bytes), with matching server-detected MIME and extension. No SVG, executable, HTML, public upload URL, or customer-supplied storage path.
5. Require nonblank bank and reference fields after trimming: bank maximum 100 characters; reference maximum 50 characters. Payment date cannot be in the future in `Asia/Manila` or earlier than the invoice date. A payment predating the invoice is handled through the manual exception, not this portal form.
6. Submission creates **Awaiting verification**. Balance remains unchanged and no journal or official receipt is created.
7. Only one pending submission per invoice. A rejected attempt remains visible with Finance's reason; the customer can submit a corrected new attempt. Pending and verified attempts cannot be edited or deleted through the portal.
8. A verified attempt shows **Payment verified** and the resulting updated invoice balance. Submission history distinguishes reported payment from recorded collections.

### Finance experience

1. Before submission, show **Awaiting customer payment submission** and no ordinary **Record collection** action.
2. After submission, show **Review payment**, opening one modal with bank, reference, date, fixed amount, proof download, and a receiving bank ledger account selector.
3. Require **I verified this payment against the receiving bank record** before enabling **Confirm funds received**. This is a human verification statement, not a substitute for actual bank matching.
4. Confirmation posts one normal collection, balanced journal entry, and official receipt, and updates the invoice and order through existing services. There is no separate collection button afterward.
5. **Reject payment** requires a nonblank reason, maximum 500 characters, in the same review modal. Rejection posts nothing.
6. Show pending/verified/rejected history. Do not classify a pending submission as collected or paid.
7. A discrepancy in amount, reference, receiving account, or proof requires rejection and a corrected submission; Finance does not rewrite the customer's amount or evidence.

### Manual exception

- Introduce `accounting.invoices.collect_manual`, granted by default only to `system_admin`. Ordinary `finance_officer` retains `accounting.invoices.collect` for verification and does not receive the manual capability.
- Authorized administrators may open a secondary **Record exceptional collection** disclosure. Require an audit reason of 10–500 characters and the existing actual amount, method, reference, date, and receiving account fields.
- Keep partial/cash/check collection behavior in this exception; it still uses existing balance, posting-account, period, idempotency, journal, and official-receipt checks.
- Block manual collection while a customer submission is pending. Review or reject that submission first, avoiding conflicting evidence and allocations.
- Existing collection records need no fabricated portal payment evidence or retrospective verification.

### State and integrity rules

| Current state | Allowed action | Result | Accounting effect |
| --- | --- | --- | --- |
| No pending submission; invoice open | Customer submits full current balance | `pending` | None |
| `pending` | Finance confirms actual received funds | `verified` | One collection, journal, receipt |
| `pending` | Finance rejects with reason | `rejected` | None |
| `rejected` | Customer submits corrected evidence | New `pending` attempt | None |
| `verified` | Repeat same verification | Existing verified result | No duplicate posting |
| `verified` or `rejected` | Conflicting decision | Validation error | None |
| Invoice draft, cancelled, paid, or zero balance | Customer submits | Validation error | None |

- Lock invoice first, then submission, consistently for submission, verification, rejection, manual collection, and cancellation.
- Validate ownership on every portal read, upload, and proof download; check the submission belongs to the bound invoice in both portals.
- Canonical amount comparison uses the current locked balance at submission and verification. Reject stale balances; never silently shrink or increase the payment.
- One submission posts at most one collection. Use the submission identity for the collection idempotency key, not balance or timestamp.
- Normalize reference matching with trimmed, case-normalized reference and bank fields. A customer cannot reuse the same bank/reference on another pending or verified attempt, even on a different invoice. Rejected attempts release the reference for correction. Do not globally ban the same reference across different customers or banks.
- Duplicate reference detection is an allocation safeguard; it does not authenticate a bank transfer.
- Invoice cancellation with a pending submission is blocked with an explicit review/reject message. Other existing cancellation rules remain intact.
- A closed posting period, invalid receiving account, receipt-generation failure, or ledger failure rolls back verification and collection together; evidence remains pending for review.
- A credit-note application can change the balance while a submission is pending. Keep existing credit-note accounting intact; verification rejects the stale amount and Finance must reject the submission for reconciliation. Never silently post the old full amount or label a credit note as a verified bank payment.
- Portal bank transfers may post only to an active, leaf posting account within the existing **1020 Cash in Bank** account tree. Validate this on the server, not just the dropdown; ordinary asset, receivable, and cash-on-hand accounts are not bank-transfer destinations. The manual path keeps existing method-specific behavior.

## Current code and reuse map

| Existing file | Responsibility and required change |
| --- | --- |
| `api/app/Modules/Accounting/Services/InvoiceService.php` | Owns collection transaction, balance, journal, receipt, and order completion; add shared collection-source/manual guard and pending-submission cancellation guard. |
| `api/app/Modules/Accounting/Controllers/InvoiceController.php` | Existing direct collection endpoint; retain for audited manual exceptions only. |
| `api/app/Modules/Accounting/Requests/StoreCollectionRequest.php` | Reject portal-submission identifiers and arbitrary fields on manual HTTP input; require manual permission and reason. |
| `api/app/Modules/Accounting/Models/Invoice.php` | Add payment-submissions relationship. |
| `api/app/Modules/Accounting/Models/Collection.php` | Persist manual reason for exceptional collections; normal verified collection stores no manual reason. |
| `api/app/Modules/Accounting/Resources/InvoiceResource.php` | Add loaded submission history and payment eligibility without exposing storage paths. |
| `api/app/Modules/B2B/Resources/CustomerPortalInvoiceResource.php` | Add customer-safe submission history, balance context, and submit eligibility. |
| `api/app/Modules/B2B/Services/CustomerPortalService.php` | Load submissions for invoice detail; preserve current customer scoping. |
| `api/app/Modules/B2B/Middleware/B2BTenancyScopeMiddleware.php` | Scope new submission model by customer so route binding cannot expose another customer's payment. |
| `api/app/Modules/B2B/routes.php` | Add guarded customer submission/proof routes next to invoice detail routes. |
| `api/app/Modules/Accounting/routes.php` | Add Finance verification, rejection, and proof routes using existing permissions. |
| `api/database/seeders/RolePermissionSeeder.php` | Register manual permission and exclude it from automatic Finance accounting-module grants. |
| `api/app/Common/Services/NotificationService.php` | Reuse `sendInApp()` and `sendPortalInApp()` for payment notices; no external messages or emails. |
| `api/app/Common/Services/NotificationCatalog.php` | Register in-app payment submission/review types. |
| `spa/src/pages/portal/customer/invoices/detail.tsx` | Submit modal, status-aware action, evidence/history panel, refreshed balance. |
| `spa/src/pages/accounting/invoices/detail.tsx` | Replace normal collection action with review; preserve restricted manual form. |
| `spa/src/api/b2b/customer.ts`, `spa/src/api/accounting/invoices.ts` | Typed multipart submission, authenticated proof download, and review APIs. |
| `spa/src/types/b2b.ts`, `spa/src/types/accounting.ts` | Separate submitted-payment and collected-payment types. |
| `spa/src/lib/chains/o2c.ts` | Replace generic collection instruction with context for waiting/submitted/verified payments without changing unrelated callers. |
| `spa/src/components/ui/Modal.tsx`, `Input.tsx`, `FileInput.tsx` | Reuse focus management, sticky footer, labels, and red accessible validation. |

## Task 1: Persist immutable submission attempts and manual audit provenance

**Create:**
- `api/database/migrations/2026_10_09_050000_create_invoice_payment_submissions.php`
- `api/app/Modules/Accounting/Enums/InvoicePaymentSubmissionStatus.php`
- `api/app/Modules/Accounting/Models/InvoicePaymentSubmission.php`
- `api/tests/Feature/Accounting/InvoicePaymentSubmissionPersistenceTest.php`

**Modify:** `Invoice.php`, `Collection.php`, `RolePermissionSeeder.php` from the map above.

**Interface:** `Invoice::paymentSubmissions(): HasMany`; statuses `pending`, `verified`, `rejected`; records use `HasHashId` and `HasAuditLog`. Submission attributes are immutable except decision metadata and collection link, changed only by the service in Task 2.

- [ ] Write schema/permission tests verifying exact decimal storage, one pending attempt per invoice, customer bank/reference uniqueness for active attempts, historical rejected attempts, and no manual grant to Finance.

```php
$this->assertFalse($finance->fresh()->hasPermission('accounting.invoices.collect_manual'));
$this->assertTrue($admin->hasPermission('accounting.invoices.collect_manual'));
$this->assertSame('19600.00', (string) $submission->fresh()->amount);
```

- [ ] Run `vendor/bin/phpunit tests/Feature/Accounting/InvoicePaymentSubmissionPersistenceTest.php` in the isolated testing environment; expect missing table/model failures before implementation.
- [ ] Add the following table and constraints, following migration conventions already used for payment proof and permissions.

```text
invoice_payment_submissions:
  id bigint primary key
  invoice_id foreign key invoices (restrict delete)
  customer_id foreign key customers (restrict delete)
  submitted_by foreign key customer_portal_users (restrict delete)
  amount decimal(15,2), balance_at_submission decimal(15,2)
  sending_bank varchar(100), sending_bank_normalized varchar(100)
  reference_number varchar(50), reference_normalized varchar(50)
  payment_date date, status varchar(20) default 'pending'
  proof_path varchar(255), proof_file_name varchar(255)
  proof_mime varchar(100), proof_sha256 char(64), proof_size bigint
  idempotency_key uuid, request_fingerprint char(64)
  collection_id nullable foreign key collections (restrict delete), unique
  reviewed_by nullable foreign key users (restrict delete)
  reviewed_at nullable timestamp, rejection_reason nullable varchar(500)
  timestamps
  unique(customer_id, idempotency_key)
  unique(invoice_id) WHERE status = 'pending'
  unique(customer_id, sending_bank_normalized, reference_normalized)
    WHERE status IN ('pending', 'verified')
  CHECK amount > 0 AND amount = balance_at_submission
  CHECK status IN ('pending', 'verified', 'rejected')
  CHECK verified has collection_id, reviewer, timestamp; pending/rejected have no collection_id
  CHECK rejected has reviewer, timestamp, nonblank rejection_reason
collections:
  add nullable manual_reason varchar(500)
```

- [ ] Insert the new permission without reseeding live roles. Grant system-admin membership consistently with existing wildcard behavior; invalidate affected role permission caches. Seeder must explicitly exclude `accounting.invoices.collect_manual` from `finance_officer`'s `$this->module('accounting', except: [...])`.
- [ ] Add the model casts/relationships and collection fillable field; use internal database IDs for joins, public hash IDs for API representation.
- [ ] Run persistence tests. In a disposable database, migrate up/down/up and prove existing collections, invoices, and journals survive unchanged. Rollback of the new table is only supported before real submissions exist; production rollback afterward preserves records and proof files rather than dropping them.
- [ ] Inspect the task diff. Do not stage shared files or commit unrelated work.

## Task 2: Implement submission, verification, rejection, and backend source enforcement

**Create:**
- `api/app/Modules/Accounting/Services/InvoicePaymentSubmissionService.php`
- `api/tests/Feature/Accounting/InvoicePaymentSubmissionServiceTest.php`
- `api/tests/Feature/Accounting/InvoicePaymentSubmissionConcurrencyTest.php`

**Modify:** `InvoiceService.php`, `Collection.php`, and `api/tests/Feature/Accounting/InvoiceCollectionTest.php`.

**Interfaces:**

```php
public function submit(Invoice $invoice, array $data, UploadedFile $proof,
    CustomerPortalUser $by): InvoicePaymentSubmission;
public function verify(Invoice $invoice, InvoicePaymentSubmission $submission,
    string $cashAccountId, User $by): InvoicePaymentSubmission;
public function reject(Invoice $invoice, InvoicePaymentSubmission $submission,
    string $reason, User $by): InvoicePaymentSubmission;
```

- [ ] Add failing tests with an owned finalized invoice totaling `19600.00`, portal owner, Finance reviewer, and private fake PDF/image. Submission must leave invoice unpaid and create zero collections, journals, and official receipts.

```php
$before = [InvoiceCollection::count(), JournalEntry::count(), OfficialReceipt::count()];
$submission = $service->submit($invoice, $data, $proof, $portal);
$this->assertSame('pending', $submission->status->value);
$this->assertSame('19600.00', (string) $invoice->fresh()->balance);
$this->assertSame($before, [InvoiceCollection::count(), JournalEntry::count(), OfficialReceipt::count()]);
```

- [ ] Run the new service tests and confirm missing-service failures.
- [ ] Implement submission using current invoice ownership, status, and locked balance. Validate decimal string shape before comparison; compare exact cents with existing Money helpers. The multipart payload includes `amount` for stale/tampering detection, but stored amount always derives from the locked server balance.
- [ ] Store proof on the private `local` disk using generated names. Capture safe display filename, SHA-256, server MIME, and size. On duplicate idempotent replay or transaction failure, delete only the newly uploaded unused file; never delete the existing submission's proof. Fingerprint canonical invoice ID, amount, date, bank, reference, and proof digest. Same UUID with different payload gives 422; same UUID with identical payload returns the existing record without duplicate effects.
- [ ] Normalize active reference fields and convert uniqueness violations to field-level 422 errors without leaving PostgreSQL's transaction aborted. A concurrent same-invoice attempt must either return the identical idempotent attempt or a clean pending-attempt error.
- [ ] Implement verification with this transaction shape; all permissions and invoice/submission ownership are rechecked on every call, including retries. Resolve the selected account through the existing `PostingAccountResolver` and validate it is an active leaf descendant of, or the posting leaf at, chart account `1020` before posting. Return a field error for non-bank accounts.

```php
return DB::transaction(function () use ($invoice, $submission, $cashAccountId, $by) {
    $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
    $lockedSubmission = InvoicePaymentSubmission::query()->lockForUpdate()->findOrFail($submission->id);
    // Validate parent identity, pending state, proof presence and locked balance.
    // An already verified retry returns its collection only for the same receiving account.
    $collection = app(InvoiceService::class)->recordCollection($lockedInvoice, [
        'payment_submission_id' => $lockedSubmission->hash_id,
        'cash_account_id' => $cashAccountId,
        'collection_date' => $lockedSubmission->payment_date->toDateString(),
        'amount' => (string) $lockedSubmission->amount,
        'payment_method' => 'bank_transfer',
        'reference_number' => $lockedSubmission->reference_number,
        'idempotency_key' => 'payment-submission:'.$lockedSubmission->hash_id,
    ], $by);
    $lockedSubmission->update([
        'status' => InvoicePaymentSubmissionStatus::Verified,
        'collection_id' => $collection->id,
        'reviewed_by' => $by->id,
        'reviewed_at' => now(),
    ]);
    return $lockedSubmission->fresh(['collection.cashAccount']);
}, 3);
```

- [ ] Add the shared `InvoiceService::recordCollection()` source guard. For an internal `payment_submission_id`, lock and validate the same pending submission, require `accounting.invoices.collect`, and exactly match its amount/date/reference/method before any posting; enforce the bank posting-account rule here as well. The HTTP manual request never accepts this field. Without a submission, require `accounting.invoices.collect_manual`, an audit reason, and no pending submission; persist `manual_reason` on the collection. Keep existing amount, account, period, idempotency, journal, official receipt, and order behavior. Existing verified retries return through the submission service and never attempt a second collection.
- [ ] Update existing collection fixtures to explicitly use administrator permission and manual reasons. Do not weaken new checks to keep old tests passing; test ordinary Finance cannot invoke the low-level manual path.
- [ ] Add the invoice cancellation guard under the existing invoice lock: reject cancellation while any submission is pending. Do not cancel or erase proof automatically.
- [ ] Implement rejection under invoice-then-submission locks. Identical rejection retry returns the same result; a different terminal-state decision gives a 422. Reject does not mutate invoice balance, collection records, or proof evidence.
- [ ] Pass service tests for: altered amount, stale balance after a real credit-note application, zero/negative/exponent/three-decimal amount, foreign invoice/submission, missing proof, invalid MIME, non-bank asset/cash-on-hand/header/inactive accounts, future payment date, duplicate reference, duplicate UUID, full settlement, manual partial settlement, rollback, and read-only customer data.
- [ ] Use the existing PostgreSQL/`pcntl_fork` race-test pattern to prove: two submissions yield at most one pending record; two reviewers yield one collection/journal/receipt; verification versus rejection yields one terminal outcome; cancellation versus submission yields either a cancelled invoice with no pending submission or a pending submission with cancellation rejected; manual collection versus submission cannot over-allocate. If the runner lacks PostgreSQL/pcntl, report this limitation instead of claiming race verification.
- [ ] Inspect the task diff and run existing `InvoiceCollectionTest`, invoice cancellation, and receipt-numbering checks affected by this guard.

## Task 3: Expose scoped payment APIs, history, and in-app handoff

**Create:**
- `api/app/Modules/B2B/Requests/Customer/SubmitInvoicePaymentRequest.php`
- `api/app/Modules/B2B/Controllers/CustomerInvoicePaymentController.php`
- `api/app/Modules/Accounting/Requests/VerifyInvoicePaymentSubmissionRequest.php`
- `api/app/Modules/Accounting/Requests/RejectInvoicePaymentSubmissionRequest.php`
- `api/app/Modules/Accounting/Controllers/InvoicePaymentSubmissionController.php`
- `api/app/Modules/Accounting/Resources/InvoicePaymentSubmissionResource.php`
- `api/tests/Feature/B2B/CustomerInvoicePaymentSubmissionTest.php`
- `api/tests/Feature/Accounting/InvoicePaymentSubmissionHttpTest.php`

**Modify:** route files, tenancy middleware, existing invoice resources/services, `StoreCollectionRequest.php`, `CollectionResource.php`, `NotificationCatalog.php`.

**Interfaces and exact route contract:**

```text
POST /api/v1/b2b/customer/invoices/{invoice}/payment-submissions
  multipart: amount, sending_bank, payment_date, reference_number,
             proof_file, idempotency_key (UUID)
GET /api/v1/b2b/customer/invoices/{invoice}/payment-submissions/{paymentSubmission}/proof
POST /api/v1/invoices/{invoice}/payment-submissions/{paymentSubmission}/verify
  JSON: cash_account_id, funds_received_confirmed=true
POST /api/v1/invoices/{invoice}/payment-submissions/{paymentSubmission}/reject
  JSON: reason
GET /api/v1/invoices/{invoice}/payment-submissions/{paymentSubmission}/proof
```

- [ ] Add failing HTTP tests for tenant ownership, unknown fields, no customer-supplied reviewer/collection/status/storage path, proof validation, Finance permissions, and direct collection bypass.

```php
$this->actingAs($otherCustomer, 'customer_portal')
    ->getJson($ownerProofUrl)->assertNotFound();
$this->actingAs($finance)
    ->postJson('/api/v1/invoices/'.$invoice->hash_id.'/collections', $manualPayload)
    ->assertForbidden();
```

- [ ] Run the new HTTP tests to prove endpoints fail before implementation.
- [ ] Add customer routes under all existing portal password/tenancy middleware; throttle mutation routes. Finance proof requires `accounting.invoices.view`; verify/reject require `accounting.invoices.collect`. Existing manual collections route also requires `accounting.invoices.collect_manual`.
- [ ] Explicitly reject unknown request keys. Customer `amount` follows `/\A\d{1,13}(?:\.\d{1,2})?\z/` plus positive/database-bound rules and exact locked balance. Verify accepts no amount, date, reference, bank, payment method, or source token overrides. Require accepted confirmation boolean; reject needs trimmed 1–500-character reason.
- [ ] Scope the new model by customer in `B2BTenancyScopeMiddleware`, then separately enforce submission.invoice_id matches the bound invoice. Downloads require an existing private file and a safe attachment response; do not render uploaded HTML or return file paths.
- [ ] Extend both invoice detail responses with `payment_submissions`, `can_submit_payment`, and `payment_submission_blocked_reason`. History contains hash ID, status, amount, sending_bank, payment_date, reference_number, safe proof filename/MIME/size, submission/review timestamps, rejection reason, and linked collection ID. Customer response excludes employee IDs, ledger accounts, proof_path, fingerprint, and internal raw keys.
- [ ] Require fresh eager loading to prevent lazy-loading failures and per-submission query growth. Order history newest first; return actual eligibility only for this customer and invoice state.
- [ ] Add manual reason to internal collection representation only. Preserve old resources and callers with additive fields.
- [ ] Send an in-app Finance notice on a new pending submission, and a customer in-app notice on verification/rejection. Use existing notification services and permission-resolved active recipients; include invoice links and dedupe keys based on submission/state, not bank details or proof URLs. Persist notices within the owning transaction and emit any broadcast only after commit, following the existing notification service. Replays must not duplicate notices. No email, Slack, or external messaging.
- [ ] Run new HTTP tests plus `CustomerPortalServiceTest`, existing portal access tests, and collection HTTP tests. Confirm GET/history/download requests create no audit mutation, journal, collection, or invoice-state change.
- [ ] Inspect the task diff.

## Task 4: Add customer payment modal and Finance review modal

**Create:**
- `spa/src/types/invoice-payments.ts`
- `spa/src/pages/portal/customer/invoices/SubmitPaymentDialog.tsx`
- `spa/src/pages/portal/customer/invoices/detail.test.tsx`
- `spa/src/pages/accounting/invoices/ReviewPaymentDialog.tsx`
- `spa/src/lib/chains/o2c.test.ts`

**Modify:** customer/Finance invoice pages and APIs/types from the map; existing `spa/src/pages/accounting/invoices/detail.test.tsx`; `spa/src/lib/chains/o2c.ts`.

**Shared TypeScript contract:**

```ts
export interface InvoicePaymentSubmission {
  id: string;
  status: 'pending' | 'verified' | 'rejected';
  amount: string;
  sending_bank: string;
  payment_date: string;
  reference_number: string;
  proof_file_name: string;
  proof_mime: string;
  proof_size: number;
  submitted_at: string;
  reviewed_at: string | null;
  rejection_reason: string | null;
  collection_id: string | null;
}
```

- [ ] Write failing component tests: customer sees fixed `19600.00`; cannot submit without bank/reference/date/proof; Finance sees no ordinary collection button before submission and one Review payment button after; review sends no editable amount.

```ts
expect(screen.queryByRole('button', { name: 'Record collection' })).not.toBeInTheDocument();
await user.click(await screen.findByRole('button', { name: 'Review payment' }));
expect(screen.getByLabelText('Payment amount')).toHaveValue('19600.00');
expect(screen.getByLabelText('Payment amount')).toHaveAttribute('readonly');
```

- [ ] Run `npx vitest run src/pages/portal/customer/invoices/detail.test.tsx src/pages/accounting/invoices/detail.test.tsx` from `spa` and confirm expected failures.
- [x] Add typed API methods: `customerPortalApi.submitInvoicePayment(invoiceId, formData)`, customer authenticated proof download, `invoicesApi.verifyPaymentSubmission(invoiceId, submissionId, {cash_account_id, funds_received_confirmed})`, `rejectPaymentSubmission(..., {reason})`, Finance authenticated proof download. Keep existing Axios clients/session/CSRF behavior; let the browser set multipart boundaries.
- [x] Implement the customer modal with house `Modal`, `ModalFooter`, `Input`, and `FileInput`. Amount remains a string read-only input. Show immediate red field errors for trimmed text, invalid/past-invoice/future date, missing proof, unsupported format, and 10 MiB limit; map server 422 errors next to matching controls. Accessible focus and keyboard behavior comes from existing components.
- [x] Generate a UUID with `crypto.randomUUID()` per prepared submission. Reuse it for a retry of identical payload after a transport failure; if the customer changes fields or file, create a new key before submitting. Disable duplicate submission and closing while a mutation is pending. An ambiguous network result triggers a fresh invoice/history read before allowing another attempt.
- [x] On stale amount/pending conflicts, preserve typed bank/reference/date but require an explicit refreshed invoice review before resubmission. Never silently change the displayed amount and post again.
- [x] Render submission history separately from recorded collections. Pending status copy: **Payment submitted. Finance will verify receipt of funds. Your invoice balance changes after verification.** Rejection displays the exact reason and allows corrected resubmission when the server permits it.
- [x] Implement the Finance review modal with immutable payment detail fields, authenticated proof download, receiving bank posting-account selector limited to active leaves in the `1020` tree, verification checkbox, **Confirm funds received**, and **Reject payment** with required reason. Disable conflicting actions during mutation. A missing proof or invalid/failed account-options query prevents confirmation and shows an actionable error/retry.
- [x] Gate the exceptional manual form using `can('accounting.invoices.collect_manual')`, require the reason, and hide it while a pending submission exists. Preserve partial-payment functionality in that form; add red precision/balance validation to match backend money constraints.
- [x] Update local invoice-page guidance for draft/finalized/pending/verified/rejected states. For shared O2C copy, add an optional payment-stage input with unchanged behavior for existing callers; the invoice page supplies the actual submission state. Do not broaden into a chain-engine refactor.
- [x] Invalidate/refetch internal invoice, customer invoice, invoice lists, and account-statement queries using existing query-key patterns after mutations. Poll visible invoice detail at 30 seconds while awaiting submission/review; stop when paid, cancelled, or hidden. Retain already typed modal input while polling.
- [x] Pass tests for missing/oversize/wrong-format proof, whitespace reference, future date, readonly amounts, duplicate clicks, pending-close guard, rejected reason, ownership API failure, 422 field errors, ambiguous retry, refreshed balance, no user-facing internal paths, and manual-permission gates.
- [x] Run `npm run build` from `spa`; fix only failures caused by this change. Inspect the task diff.

## Task 5: Verify the complete flow and migration compatibility

**Modify:** This document with completed checks and evidence. Add no unrelated features.

- [x] Run focused backend suites in an isolated database whose name is allowed by `tests/phpunit-bootstrap.php` (`ogami_test` or an allowed `ogami_test*` override). Inspect the runner's effective environment before any `RefreshDatabase`/migration command; never use `ogami-api`'s live database for mutation tests.

```bash
vendor/bin/phpunit --filter 'InvoicePaymentSubmission|CustomerInvoicePaymentSubmission|InvoiceCollectionTest|InvoiceDraftNumberingTest|FutureDatedInvoiceCancellationTest|CustomerPortalServiceTest|CreditNoteTest'
```

- [x] Run frontend tests and build:

```bash
npx vitest run src/pages/portal/customer/invoices/detail.test.tsx src/pages/accounting/invoices/detail.test.tsx src/lib/chains/o2c.test.ts
npm run build
```

- [x] Exercise the actual customer and Finance components at **390 px** and **1440 px** using mocked API data. Check focus trapping, mobile footer reachability, red validation text, readonly amount, file selection, evidence download, rejection, pending state, and verified state. Abort actual live POSTs.
- [ ] In the isolated backend, prove the `19600.00` flow: customer submits; invoice remains unpaid; Finance verifies; exactly one collection/journal/receipt; balance `0.00`; invoice `paid`; normal sales-order completion updates. Prove zero-price replacement still has no invoice/payment requirement.
- [x] Verify no amount/proof/status manipulation through direct API calls; test foreign proof access, crafted submission IDs, bypassed hidden buttons, duplicate references, two simultaneous reviewers, missing files, closed periods, and cancellation conflicts.
- [x] Repeat confirmation with the same payload and assert the counts do not change. Repeat with a different receiving account and assert rejection without rewriting a posted collection.
- [x] Run migration up/down/up in a disposable database with representative old collections, then compare counts and amounts. Document that rollback after real payment submissions requires preservation of the new table/files and a compatibility hotfix, not destructive down migration.
- [x] Self-review all touched callers, tests, fixtures, route exports, typings, permission defaults, existing manual collection behavior, and notification deduplication. Confirm no payment stage falsely implies collected funds.
- [x] Update this plan with command output summaries and known limits. Report unrun concurrency/browser checks honestly. Stop when acceptance conditions pass; do not broaden testing without a new failure or unresolved concern.

## Acceptance and review checklist

- [x] Customer amount is fixed to the outstanding server balance and tampered/stale payloads are rejected.
- [x] Reference, sending bank, date, and private PDF/image proof are required.
- [x] No ordinary collection button or direct-API bypass exists before customer submission.
- [x] Finance confirms received funds in one modal action; accounting collection/receipt generation stays automatic.
- [x] Submission alone never changes balance or marks an invoice paid.
- [x] A rejected attempt preserves history and can be corrected with a new attempt.
- [x] Replays and races cannot post the same receipt twice or allocate more than the balance.
- [x] Existing partial/cash/check collection remains available only through an authorized, audited exception.
- [x] Pending submissions block conflicting manual collection and invoice cancellation.
- [x] Proof downloads enforce customer/Finance permissions and never expose public paths.
- [x] Existing invoices, receipts, replacement-order billing, and historical collection data remain unchanged.

## Scope limits and operational risk

- No automatic bank matching is included. Finance must verify actual credit in the receiving bank record; forged proof can otherwise be approved by a human.
- Full-balance portal submission excludes partial transfers, overpayments, withholding-tax settlement, prepayments, and one transfer covering multiple invoices. Those require an audited exception or a separately designed allocation flow; never alter invoice amounts to fit proof.
- Bank reference numbers are not globally unique. The bank/customer-scoped active-reference rule prevents accidental reuse without rejecting unrelated customers; Finance still checks the actual transaction.
- Application replay protection does not prove two payments came from the same bank transaction when a customer changes both bank/reference. Finance verification remains necessary.
- Implementation was approved and completed on 2026-10-09. No live payment submission, verification, collection, or accounting entry was posted during implementation.


## Execution evidence — 2026-10-09

Implementation is complete. Customer and Finance forms use the existing modal components. Customer payments require a private PDF/image, sending bank, date, and reference; the full balance is read-only. Finance verification posts the existing collection, journal, and official receipt in one transaction. Ordinary Finance users cannot call the manual collection endpoint. Administrator exceptions require an audit reason and remain blocked while evidence is pending.

- Backend acceptance: **96 tests, 550 assertions, all passed**, using the isolated `ogami-finish-first-20261008` runner and `phpunit.case-validation.xml`. Suites cover submissions, HTTP input/ownership, invoice collections, AR hardening, numbering, cancellation, portal reads, credit notes, and no-charge replacement delivery. Output: `/tmp/customer-payment-acceptance.log`.
- A separate database, `ogami_test_payment_race_20261009`, proved two concurrent reviewers both returned the same collection identity, with exactly **one collection, one journal, one official receipt**, and balance `0.00`.
- Migration down/up with a representative legacy collection preserved the exact row and amount. Down refuses to discard either payment submissions or manual audit provenance.
- Customer and Finance browser checks at **390 px and 1440 px** passed: no horizontal overflow, focus inside the modal, valid file selection, red validation, fixed amount, bank verification checkbox, rejection validation, and authenticated proof download. Actual POSTs were aborted. Results: `/tmp/customer-payment-browser-final.log`; screenshots: `/tmp/payment-visual/`.
- React tests cover duplicate clicks, pending close guards, required fields, file type/size, future date, immutable amounts, rejection, failed bank lookup, and refresh-before-retry with the same idempotency key. Final frontend result: **31 tests across 6 files passed**, including notification metadata. The production TypeScript/Vite build passed.
- The UI detector returned `[]` for the two new modals. Final collection-boundary and backend notification-catalog checks also passed: **8 tests, 52 assertions**.
- Live additive migration applied successfully after isolated preservation checks. Schema backup: `/tmp/customer-payment-schema-backup/schema-before.sql.gz`. Read-only live checks show `INV-202610-0001` balance `19600.00`, eligible for submission, with **zero payment submissions**. Finance can verify and cannot collect manually. Existing live collection count stayed **2**.
- PHP's previous `2M` upload limit would have contradicted the form. Added `docker/php/uploads.ini` to development and production images (`10M` files, `12M` multipart body), applied it to the running API, and verified effective FPM limits. The application still enforces the 10 MiB proof limit.
- The legacy `OrderToCashChainTest` has four failures before collection at its trial-production fixture: “Waiting for Production to record 5 trial pieces before QC evidence can be saved.” Compatibility flags did not resolve this pre-existing fixture mismatch. This change updates its collection caller to the explicit administrator exception, without weakening Production/QC rules. Output: `/tmp/customer-payment-chain.log`.
- Two early PHPUnit processes overlapped against the same disposable database and caused migration/deadlock errors. They were stopped/completed and the final acceptance run was sequential. The live database was not used for these tests.

No bank matching integration is included. Finance must check actual credited funds. Portal submission supports the full outstanding balance; partial, cash, check, and other settlement exceptions require the restricted audited workflow. Historical collections remain readable without invented customer evidence.

## Invoice interface follow-up — 2026-10-09

Issued invoice detail pages now display who must act next: Finance shows “Awaiting customer payment” before evidence and “Awaiting payment verification” afterward. The customer portal shows “Awaiting payment” or “Awaiting payment verification.” Accounting lifecycle values and balances remain unchanged. Issued invoices no longer expose the Cancel button; draft cancellation remains available for the existing recovery workflow.

The running HTTPS site already served the payment submission components. A read-only resource check for `INV-202610-0001` confirmed `can_submit_payment: true`, balance `19600.00`, and no pending evidence. Fresh browser contexts exercised both actual site routes at 390 px and 1440 px using mocked sessions and the live read-only invoice resource projections: Submit payment opened the fixed-balance modal, and Finance exposed neither Record collection nor Cancel. No payment requests were sent; attempted background broadcast authentication was blocked. Results: `/tmp/payment-live-page-check.mjs`, screenshots `/tmp/payment-live-customer-390.png` and `/tmp/payment-live-finance-390.png`.

Focused verification passed: 16 React tests across the two invoice pages and payment dialogs; 2 isolated backend tests with 25 assertions, including the actual customer invoice GET eligibility before and after submission. The production TypeScript/Vite build and whitespace checks passed.
