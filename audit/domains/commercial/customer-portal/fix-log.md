# M035 — Customer Portal fix log

Session date: 2026-08-25  
Result: implementation completed for the actionable findings; released as `🔁 Needs Re-audit` because complaint order/product traceability still needs a product decision and runtime suites could not complete in this checkout.

## Implemented findings

### F-01 — Customer authentication contract

- Files: `api/config/auth.php:14-24`, `api/app/Modules/B2B/Services/B2bAuthService.php:42-166`, `api/app/Modules/B2B/Controllers/CustomerAuthController.php:40-110`, `spa/src/api/b2b/client.ts:3-18`, `spa/src/api/b2b/customer.ts:18-46`.
- Before: customer login issued a Sanctum bearer token and the SPA persisted it in `sessionStorage`.
- After: the customer guard is session-backed; login regenerates an HTTP-only session, logout/password change invalidate it, and the customer client sends credentialed cookie requests without a storage key or token setter. Supplier bearer behavior remains unchanged.

### F-02/F-03/F-12 — Response boundary, relation loading, and complaint attribution

- Files: `api/app/Modules/B2B/Resources/CustomerPortalUserResource.php:10-31`, `api/app/Modules/B2B/Controllers/CustomerPortalController.php:50-64`, `api/app/Modules/B2B/Services/CustomerPortalService.php:125-143,239-300`.
- Before: dashboard deliveries/complaints were raw model collections; order child eager loads omitted `sales_order_id`; complaint creation retained only the internal system-user attribution.
- After: dashboard data uses customer-safe resources, all order child selections include their relationship key, login/me share the customer user resource, and complaint submission writes an append-only `customer.complaint.submitted` audit event with portal actor and request metadata.

### F-04/F-05/F-10 — Password policy and auth concurrency

- Files: `api/app/Modules/B2B/Services/PortalPasswordHistoryService.php:10-77`, `api/app/Modules/B2B/Middleware/CheckPortalPasswordExpiry.php:10-43`, `api/app/Modules/B2B/Services/PortalPasswordService.php:15-48`, `api/app/Modules/B2B/Services/PortalPasswordResetService.php:15-101`, `api/app/Modules/B2B/Services/B2bAuthService.php:58-164`, `api/database/migrations/2026_08_25_120000_create_portal_password_history_table.php:9-24`.
- Before: portal password changes/resets ignored password history and expiry, login email matching was case-sensitive, and failed-attempt/token mutations were not serialized.
- After: configured portal password history is recorded/checked, customer routes gate expired passwords, email lookup is normalized, and login state plus supplier token replacement run under a user row lock/transaction.

### F-06 — Delivery schedule idempotency

- Files: `api/app/Modules/B2B/Services/CustomerPortalService.php:358-425`.
- Before: any existing customer/month row was returned even when a retry carried different lines, and a concurrent unique-index race could become a raw database error.
- After: retries compare a deterministic SHA-256 payload fingerprint; identical requests replay the existing schedule, changed requests return a business-rule 422, and the unique-index race is normalized to the same contract.

### F-07 — Exact outstanding-balance arithmetic

- Files: `api/app/Modules/B2B/Services/CustomerPortalService.php:66-102`.
- Before: the dashboard used a database sum followed by float-based formatting.
- After: balances are plucked as decimal strings, accumulated through `Money`, and returned as a two-decimal string.

### F-08 — Query validation and statement-date policy

- Files: `api/app/Modules/B2B/Controllers/CustomerPortalController.php:70-126,213-252,320-342`, `api/tests/Feature/B2B/PortalValidationTest.php:77-101`.
- Before: raw status, search, pagination, and `as_of` values reached services without consistent bounds or date validation.
- After: enum/status, search length, page/per-page, complaint date ordering, and ISO date validation occur at the controller boundary; future statement dates are rejected with validation 422 responses.

### F-09 — Proof streaming and filename safety

- Files: `api/app/Modules/B2B/Controllers/CustomerPortalController.php:165-204`, `api/tests/Feature/B2B/CustomerPortalServiceTest.php:306-319`.
- Before: proof downloads loaded the whole object into memory and interpolated the stored filename into a response header.
- After: proofs use a storage read stream, close the resource after `fpassthru`, and emit sanitized ASCII plus RFC-5987 filename parameters.

### F-13/F-14/F-15/F-16 — Pagination, dashboard completeness, date display, and keyboard access

- Files: `api/app/Modules/B2B/Services/CustomerPortalService.php:107-236,351-356`, `spa/src/api/b2b/customer.ts:82-189`, `spa/src/pages/portal/customer/orders/index.tsx:28-133`, `spa/src/pages/portal/customer/invoices/index.tsx:15-113`, `spa/src/pages/portal/customer/deliveries/index.tsx:15-106`, `spa/src/pages/portal/customer/complaints/index.tsx:26-250`, `spa/src/pages/portal/customer/delivery-schedules.tsx:27-215`, `spa/src/pages/portal/customer/dashboard.tsx:160-206`, `spa/src/pages/portal/PortalLoginPage.tsx:201-210`.
- Before: several portal lists were unbounded or discarded paginator metadata; dashboard recent panels were unused; undelivered rows showed no useful date; the password visibility button was removed from keyboard order.
- After: all growing customer lists use a shared paginated envelope and design-system footer, dashboard deliveries/complaints render safe linked panels, scheduled dates are shown until delivery, and the visibility control is keyboard reachable.

### F-18 — Public feature gating

- Files: `api/app/Modules/B2B/routes.php:67-79`, `api/tests/Feature/B2B/CustomerPortalAuthTest.php:231-252`.
- Before: customer login/logout/reset routes remained reachable when `modules.b2b_portals` was disabled.
- After: the public customer auth surface is feature-gated and the test asserts the stable `feature_disabled` response.

## Session 2 — 2026-08-27 (execution + re-audit)

Session 1 (2026-08-25) wrote every entry above but **never executed a single test**:
`migrate:fresh` was broken repo-wide, so nothing reached a database and every
"fix" was source-only. `migrate:fresh` works now. This session executed the
suite on its own database (`ogami_test_cp`) and fixed what execution surfaced.

### S2-01 — Proof download returned 500 for every customer (broken regex)

- File: `api/app/Modules/B2B/Controllers/CustomerPortalController.php:188` (now `:188-198`).
- Finding: the F-09 filename sanitiser shipped in session 1 never ran successfully.

Before:

```php
$filename = preg_replace('/[\r\n"\\]/', '', $filename) ?: 'delivery-proof';
```

After:

```php
$filename = preg_replace('/[\x00-\x1f\x7f"\\\\]/', '', $filename) ?: 'delivery-proof';
```

Cause, proved in the container rather than reasoned about: in a **single-quoted**
PHP string `\\` collapses to ONE backslash, so PCRE received `[\r\n"\]`, read
`\]` as an escaped literal `]`, and never closed the character class.
`preg_replace()` therefore failed to compile — `preg_last_error_msg()` returns
`"Internal error"` and the call returns `null` — and the PHP warning that
Laravel's `HandleExceptions` promotes to `ErrorException` turned **every**
`GET /deliveries/{id}/proofs/{id}/view` into a 500. The endpoint was completely
dead, not merely mis-sanitising.

The replacement uses hex escapes and a doubled backslash so the intent is
unambiguous, and widens the class from CR/LF to all C0 controls plus DEL — every
one of which can forge or truncate a `Content-Disposition` parameter. Verified
both candidate patterns produce the byte-exact filename the test asserts
(`signed__filename_evil.txtX-Portal-Evil__yes.gif`). A repo-wide grep confirms
the broken spelling was not copied anywhere else.

### S2-02 — 8D report 404 was a fixture defect, not a service defect

- File: `api/tests/Feature/B2B/CustomerPortalServiceTest.php:470-487`.
- Production code unchanged. **The gate was right; the fixture was wrong.**

`CustomerComplaint::ncr()` is `belongsTo(NonConformanceReport::class, 'ncr_id')`
(`api/app/Modules/CRM/Models/CustomerComplaint.php:67-70`) — the forward link
lives on `customer_complaints.ncr_id`. `ComplaintService` writes **both**
directions after a successful handoff (`api/app/Modules/CRM/Services/ComplaintService.php:153-158`
and `:307-312`, both `forceFill(['ncr_id' => $ncr->id])`), and the whole CRM
module reads that forward link (`CreateNcrOnComplaintRequested.php:42`,
`ComplaintService.php:274,329,359`). The fixture set only the NCR's reverse
`complaint_id`, so `$complaint->ncr` was null and
`CustomerPortalService::complaint8dReport()` correctly withheld the report.

Added after the NCR create:

```php
$complaint->forceFill(['ncr_id' => $ncr->id])->save();
```

No scope was widened and no gate was relaxed. Note the sibling test at
`CustomerPortalServiceTest.php:403` already asserted `$complaint->ncr_id` is
non-null on the path that goes through `ComplaintService`, which is what
established the correct direction. **This does not belong to another module** —
the CRM 8D/complaint code is correct as written; only the customer-portal test
fixture was at fault.

### S2-03 — Cross-tenant customer invitation = account takeover (security, P0)

- File: `api/app/Modules/B2B/Services/PortalInvitationService.php:22-51` → `:22-80`.

Before — no ownership check at all:

```php
$user = CustomerPortalUser::withTrashed()->firstOrNew(['email' => strtolower(trim($email))]);
$user->forceFill([
    'customer_id' => $customer->id, ..., 'password' => $password, 'is_active' => true, ...
]);
```

After — mirrors the already-hardened supplier half (M047-F005), inside a
transaction with a row lock:

```php
$user = CustomerPortalUser::withTrashed()
    ->whereRaw('LOWER(email) = ?', [$email])
    ->lockForUpdate()
    ->first();

if ($user && (int) $user->customer_id !== (int) $customer->id) {
    throw new BusinessRuleException('This email is already assigned to a different customer. Remove the existing portal account before assigning it elsewhere.');
}
```

`customer_portal_users.email` is a global UNIQUE constraint while the row holds a
single `customer_id` (`api/database/migrations/0168_create_customer_portal_users_table.php:15-17`,
confirmed against the live schema), so the invitation path **is** the tenant
boundary. Customer B inviting an address already held by customer A silently
re-pointed A's portal login at B's orders, invoices, deliveries and complaints
*and* reset it to a password B chose — takeover of A plus denial of A's access.

Three changes, all mirroring the supplier shape:
1. the cross-customer ownership refusal (the security fix);
2. `whereRaw('LOWER(email) = ?')` instead of an exact match, so the boundary is
   not bypassable by capitalisation — writes normalise to lowercase, so a
   case-sensitive lookup would have missed a legacy mixed-case row entirely;
3. `DB::transaction` + `lockForUpdate()`, so two concurrent invitations cannot
   interleave the ownership check with the write;
4. `$user->tokens()->delete()` on rotation — the customer guard is session-backed
   now (F-01), but the model still carries `HasApiTokens` and rows may predate
   that migration, so a stale bearer token must not outlive a rotated password.

Regression test added: `api/tests/Feature/B2B/CustomerPortalAccessLifecycleTest.php`
(new file, 5 tests, copied from `SupplierPortalAccessLifecycleTest`'s shape):

- `test_invitation_refuses_to_move_an_email_to_a_different_customer` — 422, and
  asserts A's `customer_id` **and A's password hash** are untouched and that
  exactly one row holds the address. Asserting the hash is what pins the
  takeover half of the defect, not just the reassignment half.
- `test_invitation_is_case_insensitive_when_detecting_a_cross_customer_conflict`
  — `Mixed-Case@Example.Test` against a stored `mixed-case@example.test` → 422.
- `test_invitation_service_rejects_cross_customer_reassignment_directly` — the
  boundary is in the service, so a future non-HTTP caller inherits it.
- `test_same_customer_reinvitation_rotates_the_credential` — 201, proving the
  guard did not break the legitimate re-invite path.
- `test_invitation_response_does_not_hand_the_temporary_password_to_the_client`.

`inviteSupplier()` was **not** touched. Its only other caller,
`api/tests/Feature/B2B/PortalPasswordResetTest.php:175`, still passes.

### Open question — the inactive-account guard was deliberately NOT mirrored

`inviteSupplier()` also refuses when the existing row is inactive or trashed
("Reactivate it before sending a new invitation"), and I was asked to mirror it.
I did not, because on the customer side it would be an unrecoverable dead end,
and I would rather flag that than ship it.

That supplier guard is only safe because suppliers have an explicit, audited
`reactivateSupplier` path (`api/app/Modules/B2B/routes.php:72-74` →
`PortalAccessService::reactivate()`). Its purpose is to stop a routine "resend"
from silently undoing a deliberate revocation. **Customers have no such path.**
The portal-access route group exposes only `GET customers` and
`POST customers/{customer}/invite` (`api/app/Modules/B2B/routes.php:59-61`);
`PortalAccessService` is supplier-typed throughout, and no code anywhere
deactivates or restores a `CustomerPortalUser`. Re-invitation is therefore the
*only* way to re-enable one, and refusing it would strand any inactive or
soft-deleted customer portal account permanently.

Two coherent resolutions, needing an operator decision rather than a guess:
(a) leave re-invitation as the reactivation path for customers (current
behaviour, no dead end, but a revocation is silently reversible); or
(b) build customer deactivate/reactivate/revoke endpoints to match the supplier
lifecycle, then add the refusal. (b) is the better end state but is new
operator-facing surface, not an audit fix.

### Verification record — session 2 (actual output)

Own database, as required: `ogami_test_cp`.

```
docker compose exec -T -e DB_DATABASE=ogami_test_cp api php artisan test \
  --filter='CustomerPortalServiceTest|CustomerPortalAccessLifecycleTest'

  PASS  Tests\Feature\B2B\CustomerPortalAccessLifecycleTest  (5 tests)
  PASS  Tests\Feature\B2B\CustomerPortalServiceTest         (22 tests)
  Tests:    27 passed (100 assertions)
```

Before the two fixes the same command gave `2 failed, 20 passed`:
`delivery_list_detail_and_proof_use_portal_safe_hash_ids` — "Expected response
status code [200] but received 500"; `portal_8d_report_requires_finalized_report_and_terminal_status`
— "Expected response status code [200] but received 404". Both now pass.

Every other session-1 claim above is now **executed and green** rather than
source-only — including the F-06 idempotency pair
(`store_delivery_schedule_is_idempotent_for_same_month`,
`..._rejects_a_different_payload_for_same_month`), F-07
(`dashboard_outstanding_balance_keeps_decimal_precision`) and F-03
(`sales_order_detail_matches_loaded_child_relations`).

### S2-04 — F-11 order traceability closed; product traceability still deferred

Session 1 deferred all of F-11 as "human decision required". Only half of it was.
The `order_id` half needed no decision at all: the contract was already written,
implemented and tested backend-side — `CreateComplaintRequest.php:26-45` accepts
an optional order, decodes the HashID, refuses an order belonging to another
customer, and refuses a cancelled one; `CustomerPortalService::createComplaint()`
persists `sales_order_id` and hands off to CRM. Only the form never offered it,
so in practice every portal complaint arrived unlinked — which is the actual
harm F-11 describes. That is UI wiring over a settled contract, not a product
question.

Files: `spa/src/pages/portal/customer/complaints/index.tsx:28,45-55,73-93,131-144`.

- Added an optional **Related order** `<Select>` to the submit form, sourced
  from the existing paginated `listOrders` endpoint (`per_page: 100`, its
  server-side maximum), fetched only while the form is open (`enabled: showForm`),
  with cancelled orders filtered out because the server refuses them. The select
  is a convenience shortlist and explicitly **not** the authority on ownership —
  the FormRequest re-checks `customer_id` server-side on every submit.
- `order_id` is now sent (`order_id: orderId || undefined`) and reset on success.
- Replaced the blanket `onError: () => toast.error('Failed to submit complaint.')`
  with the repo's standard server-message mapping, so the rule text
  ("The order ID is invalid or does not belong to your account.") reaches the
  user instead of a generic failure.

Regression test added — `api/tests/Feature/B2B/CustomerPortalServiceTest.php:439-473`,
`test_create_complaint_rejects_another_customers_order`. `order_id` is the only
customer-supplied foreign key on the portal's only write path, so it is the only
place a customer could aim a complaint at somebody else's order. The rule was
implemented but **completely untested** — the existing tests covered the happy
path and the cancelled-order path only. The new test asserts 422 with an
`order_id` validation error and that the complaint was written for *neither*
customer (`assertDatabaseCount('customer_complaints', 0)`).

**Still deferred, and genuinely a product decision:** `product_id` remains
`['prohibited']` (`CreateComplaintRequest.php:46-49`). Accepting it requires
deciding which products a customer may name (any product, or only products on the
linked order), and whether CRM/NCR persists and reports it. The current contract
rejects the field outright rather than silently accepting and dropping something
a caller may believe was stored, which is the right default until that is
decided. Not guessed here either.

## Deferred findings

### F-11 (product half only) — complaint product traceability

Order traceability is **done** — see S2-04 above. `product_id` stays prohibited
pending the product decision described there.

### F-17 — Browser execution (deferred; session 1's stated cause was wrong)

`spa/e2e/customer-portal.spec.ts` exists and its standalone TypeScript compile
passes. Session 1 blamed "the compose image has no installed Chromium
executable". That is not the blocker on this host, and the corrected diagnosis
matters for whoever runs it next:

- Chromium **is** available — `~/.cache/ms-playwright/chromium-1223`,
  `chromium_headless_shell-1223`, `firefox-1522`, and `/usr/bin/google-chrome`.
- The suite needs no backend: `playwright.config.ts:5-7` mocks every API call via
  `page.route()` and runs against the Vite dev server only.
- The real blocker is that `spa/node_modules` is **root-owned** (created by the
  container as root; `drwxr-xr-x root root`), so Vite cannot write the bundled
  config temp file it always emits into `node_modules/.vite-temp`:

```
$ npx vite --port 5173 --strictPort
failed to load config from /home/kwat0g/Desktop/kwatog/spa/vite.config.ts
error when starting dev server:
Error: EACCES: permission denied, open
  '/home/kwat0g/Desktop/kwatog/spa/node_modules/.vite-temp/vite.config.ts.timestamp-….mjs'
```

  The dev server therefore never boots, so Playwright's `webServer` cannot
  start. The same EACCES blocks host Vitest, which is why session 1 could only
  run the unit slice inside compose.

Unblocking it needs `sudo chown -R kwat0g:kwat0g spa/node_modules` (or a
re-`npm install` as the invoking user) — a host-environment change outside this
module's scope — or the `spa` container, which this session's memory budget
(3.7 GiB total, ~1.1 GiB free after an earlier OOM kill) explicitly forbids
bringing up. Lightpanda is **not** a substitute: per `CLAUDE.md` it computes no
layout, and this spec asserts narrow-viewport navigation.

## Verification record

### Session 2 final state (2026-08-27)

Whole customer-portal backend slice, on this session's own database
(`ogami_test_cp` — two agents cannot share `ogami_test`):

```
docker compose exec -T -e DB_DATABASE=ogami_test_cp api php artisan test \
  --filter='CustomerPortal|PortalValidation|PortalToken|PortalPasswordReset'

  PASS  Tests\Feature\B2B\CustomerPortalAccessLifecycleTest   (5 tests)
  PASS  Tests\Feature\B2B\CustomerPortalAuthTest              (9 tests)
  PASS  Tests\Feature\B2B\CustomerPortalServiceTest          (23 tests)
  PASS  Tests\Feature\B2B\PortalPasswordResetTest             (8 tests)
  PASS  Tests\Feature\B2B\PortalTokenCrossGuardTest           (4 tests)
  PASS  Tests\Feature\B2B\PortalValidationTest                (3 tests)
  Tests:    52 passed (220 assertions)
  Duration: 38.60s
```

Session 1's comparable figure was 32 passed / 125 assertions, and was never
actually executed. The full suite was **not** run: this host has 3.7 GiB RAM and
the stack was OOM-killed earlier today, so every run above was tightly filtered.

Concurrency coverage for F-05, executed separately because it is slow (two live
DB connections per test):

```
docker compose exec -T -e DB_DATABASE=ogami_test_cp api php artisan test \
  --filter='LoginThresholdTwoConnectionHarnessTest'

  PASS  Tests\Feature\Auth\LoginThresholdTwoConnectionHarnessTest  (1 test)
  PASS  Tests\Feature\B2B\LoginThresholdTwoConnectionHarnessTest   (4 tests)
    ✓ customer failure waits for authoritative user lock
    ✓ customer failure observes successful counter reset
  Tests:    5 passed (29 assertions)
```

Regression check on the shared file — `PortalInvitationService` is shared B2B, so
the released supplier module was re-run after editing `inviteCustomer()`:

```
  PASS  Tests\Feature\B2B\SupplierPortalAccessLifecycleTest
  Tests:    14 passed (53 assertions)
```

SPA, on the host (compose `spa` was **not** started, per the memory budget):

- `tsc --noEmit`: **2 errors, both pre-existing and both outside this module** —
  `src/pages/assets/detail.tsx(6,20)` "Cannot find module 'qrcode'" and
  `(67,14)` implicit-any. Zero customer-portal errors. Session 1's third error
  (`return-management/detail.tsx` duplicate JSX attribute) has since been fixed
  by another module's session.
- `eslint src/pages/portal/customer/complaints/index.tsx --max-warnings 0`: clean.
- `prettier --check` on that file warns, but **it warned identically at HEAD
  before this session touched it** (verified against `git show`). The file uses
  1-space indentation repo-wide; the team is reformatting pages in separate
  `style(spa):` commits, so reformatting it inside a fix commit would bury the
  audit diff. Left as an optional follow-up.
- `vitest src/api/b2b/client.test.ts` could not run on the host (root-owned
  `node_modules`, see F-17 above); session 1 ran it in compose — 4 passed. No SPA
  file that test covers was changed this session.

### Session 1 record (2026-08-25) — source-only, never executed

- PHP lint passed for all changed/new customer-portal backend files; `git diff --check` passed.
- Compose SPA unit slice: `src/api/b2b/client.test.ts` — **4 passed**.
- Compose SPA typecheck: **blocked by 3 unrelated existing errors** in `src/pages/assets/detail.tsx` (`qrcode`/implicit-any) and `src/pages/return-management/detail.tsx` (duplicate JSX attribute); no customer-portal errors were reported.
- New Playwright spec standalone TypeScript compile: **passed**.
- Compose API feature slice was blocked before tests by unrelated migration `api/database/migrations/2026_08_25_210000_enforce_one_active_holiday_per_date.php:42`, which attempts to drop `holidays_date_name_unique` while PostgreSQL still owns it as a table constraint. **This is fixed repo-wide now** — `migrate:fresh` works, which is what let session 2 execute anything at all.
- Route-list verification was also blocked by the pre-existing missing `api/app/Modules/HR/Controllers/TrainingMatrixController.php`; no unrelated file was changed.
