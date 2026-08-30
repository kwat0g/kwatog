# M020 — Loans & Cash Advances fix log

Audit date: 2026-08-24  
Session outcome: **Plan Ready; no production-code fixes applied**

## Audit actions

- Regenerated the module registry before selection and again before release.
- Claimed `people/loans-cash-advances` atomically after excluding the finance modules locked by other sessions.
- Read the module source, migrations, routes, approval/RBAC configuration, payroll/final-pay integrations, frontend consumers, tests, and relevant product documentation.
- Kept the audit scope to M020 artifacts; existing user changes in application and infrastructure files were not modified.

## Fixes

None. The decision gate was not met: the majority of findings require separate financial-integrity, RBAC, state-machine, or cross-module implementation sessions. The contained frontend/documentation candidates remain ordered in `action-plan.md` but were intentionally deferred so the module can be handed off with a coherent plan.

## Verification log

- Registry regeneration: passed.
- Loan route listing: passed; 13 routes registered.
- PHP syntax checks for audited backend files: passed.
- SPA typecheck: passed.
- Targeted SPA ESLint: passed.
- SPA token discipline: passed; 769 files checked.
- Deterministic amortization tests: 7 passed.
- Database-backed loan tests: 7 could not start because the configured PostgreSQL host `db` was unavailable; no database service was running (`docker compose ps` was empty).

## Session 2026-08-25 — resumed Plan Ready

The module was claimed after the higher-priority finance Plan Ready modules were
locked by other sessions. The existing report and plan were read in full; this
session continued the existing plan without re-auditing it.

### Fixes applied

- F-001/F-006: api/app/Modules/Loans/Support/LoanRate.php:20 now normalizes
  fractional annual rates with six-decimal precision and exposes a separate
  percent conversion at line 35. EmployeeLoan persists the matching cast at
  api/app/Modules/Loans/Models/EmployeeLoan.php:35, migration
  api/database/migrations/0477_harden_loan_precision_and_payment_integrity.php:120
  widens PostgreSQL storage to numeric(8,6), and LoanService lines 106 and 130
  keep salary caps and principals in BCMath-backed decimal strings.
- F-002: api/app/Modules/Loans/Policies/LoanAccessPolicy.php:24 centralizes
  global, self, and department-head row scope. The service applies it at
  api/app/Modules/Loans/Services/LoanService.php:90, :211, and :267;
  controller show/limits enforce it at
  api/app/Modules/Loans/Controllers/LoanController.php:73 and :118.
- F-003: api/app/Modules/Loans/Support/LoanStateMachine.php:21 defines
  explicit transitions and excludes active cancellation. Service cancellation
  now requires write-off permission and only permits pending loans at
  api/app/Modules/Loans/Services/LoanService.php:281; the detail page exposes
  the action only for pending loans with loans.write_off at
  spa/src/pages/loans/detail.tsx:87.
- F-005: the preview endpoint validates loan_type and returns the canonical
  schedule at api/app/Modules/Loans/Controllers/LoanController.php:134.
  Self-service sends the type and consumes the array contract at
  spa/src/api/self-service.ts:106 and spa/src/pages/self-service/loans.tsx:225.
- F-007/F-008 (partial): payment reconciliation filters asOf, derives
  remaining periods from schedule coverage, and serializes current ledger
  balance at api/app/Modules/Loans/Services/LoanService.php:301 and :348.
  Payment audit coverage is enabled at
  api/app/Modules/Loans/Models/LoanPayment.php:14; migration 0477 adds
  positive-amount/type checks and the payroll FK at lines 78, 139, and 26.
- F-009: final approval/rejection events are no longer emitted for every
  intermediate approval; final-only behavior is at
  api/app/Modules/Loans/Services/LoanService.php:216 and regression-covered
  by api/tests/Feature/Notifications/LoanNotificationTest.php:81.
- F-011/F-012: detail progress/rate semantics use total due and the fractional
  formatter at spa/src/pages/loans/detail.tsx:62 and :122; self-service
  status chips use the design-system mapping at
  spa/src/pages/self-service/loans.tsx:75; admin employee selection is
  searchable at spa/src/pages/loans/create.tsx:41.
- F-013 (partial): amortization preview is throttled at
  api/app/Modules/Loans/routes.php:15.

### Deferred

- F-003: approved write-off/settlement workflow, reason/reference capture,
  segregation of duties, and journal handling still require a business
  decision; active cancellation is blocked until that path exists.
- F-004: final-pay settlement/recovery and the unrecovered-residual rule remain
  in the HR/final-pay dependency module.
- F-007/F-008: payroll recompute still directly deletes payment rows and adjusts
  aggregates; a permissioned manual-payment API/UI and reversal-entry design
  remain pending.
- F-010: SSS/Pag-IBIG remain defined but inactive; activation or de-scoping is
  intentionally not guessed.
- F-012/F-013: self-service history pagination and the HR-versus-Loans feature
  gate alignment require dependency-module changes.
- F-014: documentation remains pending the government-loan and write-off
  decisions.

### Verification

- Docker-backed focused suite: 18 passed, 93 assertions.
- Migration verification in ogami_test: employee_loans.interest_rate is
  numeric(8,6) and all three payment constraints/FK are present.
- Deterministic precision/state tests: included in the 18 passing tests.
- Changed SPA files: targeted ESLint passed.
- Route listing passed; preview throttle and write-off middleware are present.
- Full SPA typecheck remains blocked by the pre-existing invalid character and
  unterminated template in spa/src/pages/crm/sales-orders/create.tsx:169,
  outside M020.

Release status for this session: `🔁 Needs Re-audit`. The next session should resolve the deferred write-off, final-pay, payroll-reversal/manual-payment, government-loan, feature-gate, pagination, and documentation decisions.

## Session 2026-08-30 — re-audit + contained fixes

Prior state established before planning: the 2026-08-25 session's `fix-log.md` was fully
written and **all** of its claimed fixes were already committed (swept into `167de85e`).
The working tree was clean for every loans path. This was therefore a verification session,
not a recovery one — the third such case in this pipeline.

Re-measured all 14 prior findings empirically. **Eight are fixed; six still reproduce**
(F-004, F-007, F-008, F-010, F-013, F-014) plus F-012's residue. Five findings are new to
this re-audit. Full evidence in `audit-report.md`.

### Decision on the fix gate

The plan is dominated by `separate-recommended`, so per Step 6 this hands off as
`🔁 Needs Re-audit`. **Four items were nonetheless executed**, under the Step 6 allowance for
genuinely contained work, and the split is stated explicitly here:

- Everything deferred either **changes an amount someone is paid or deducted** (items 2, 3, 4,
  5, 6 in the plan), **changes who may authorise borrowing** (item 1), or **lives in another
  module** (items 4, 7, 17 — Payroll, HR, Separation).
- The four executed items change **no** persisted amount: one removes an identifier from a
  response body, one replaces float display arithmetic with integer arithmetic, one corrects
  a UX-only route guard, one debounces a read-only preview request.

### Fix 1 — R-009: `bulk-approve` no longer echoes raw integer primary keys

`api/app/Modules/Loans/Services/LoanService.php:234-265`

Before (`:249`, `:254`) — `$id` is the **decoded integer PK**, and the controller returns the
array verbatim (`LoanController.php:174-179`):

```php
$failed[] = ['id' => $id, 'reason' => 'Not found.'];
...
$failed[] = ['id' => $id, 'reason' => $e->getMessage()];
```

Measured response body before the fix:

```json
{"data":{"approved":[],"failed":[{"id":24,"reason":"Only pending loans can be approved."},
                                 {"id":999999,"reason":"Not found."}]}}
```

After — the row carries the obfuscated identifier, and the docblock's `@return` now says
`id:string`:

```php
$reference = app('hashids')->encode($id);
...
$failed[] = ['id' => $reference, 'reason' => 'Not found.'];
```

Contained because `POST /loans/bulk-approve` has **no SPA client at all** (verified: no
`loansApi.bulkApprove` in `spa/src/api/loans/index.ts:17-38`, no row selection in
`spa/src/pages/loans/index.tsx`), so no consumer can break, and no money field is touched.

Note the residual enumeration surface is recorded as **R-018** (status is checked before row
scope in `approve()`), deliberately left alone because it changes an approval-path message.

### Fix 2 — R-008: loan progress no longer adds two decimal strings as JS floats

`spa/src/pages/loans/detail.tsx:66-72` and new `spa/src/lib/money.ts`

Before (`detail.tsx:62-64`) — `total_paid` and `balance` are typed `string`
(`spa/src/types/loans.ts:24-26`) precisely to preserve `decimal(15,2)`:

```ts
const totalDue = Number(loan.total_paid) + Number(loan.balance);
const remainingPercent = totalDue > 0 ? Math.min(100, (Number(loan.total_paid) / totalDue) * 100) : 0;
```

After — sum in integer centavos, take the ratio only at the display boundary:

```ts
const paidCentavos = toCentavos(loan.total_paid);
const totalDueCentavos = paidCentavos + toCentavos(loan.balance);
const remainingPercent = totalDueCentavos > 0 ? Math.min(100, (paidCentavos / totalDueCentavos) * 100) : 0;
```

`toCentavos`/`fromCentavos` were put in a shared `spa/src/lib/money.ts` rather than inlined,
because this is a repo-wide pattern worth having one answer for, and because a module-private
helper cannot be unit-tested.

### Fix 3 — R-013: SPA loan route guards now name the permissions the pages actually need

`spa/src/routes/hrRoutes.tsx:158-176`

Before, all three routes: `<PermissionGuard anyOf={['loans.approve', 'loans.write_off']}>`.
After: `permission="loans.view"` for `/hr/loans` and `/hr/loans/:id`,
`permission="loans.create"` for `/hr/loans/create` — matching `loans.view` on
`GET /loans`, `GET /loans/options`, `GET /loans/{loan}` and `loans.create` on
`GET /loans/types`, `POST /loans` (`api/app/Modules/Loans/routes.php:9-19`).

Contained, and verified to be a no-op for every role that exists today: no **seeded** role is
view-only (`department_head` has view+approve at `RolePermissionSeeder.php:733`; `hr_officer`,
`finance_officer`, `system_admin` have all four). It fixes the seeded `loans.outstanding`
dashboard widget — gated on `loans.view` (`DashboardWidgetSeeder.php:272`) with `link_path`
`/hr/loans` (`:100`) — pointing at a route that would refuse `loans.view`. The backend
enforces the same permissions independently, so this is UX correctness only.

The file uses **one-space indentation** throughout; the edit matched it rather than
reformatting. No formatter was run in write mode on any file.

### Fix 4 — R-015: amortization preview is debounced and its failures are surfaced

`spa/src/pages/loans/create.tsx:93-107` and `:172-177`

Before (`:88-95`) — a `useEffect` keyed on a raw `watch()` of `principal`, i.e. one POST per
keystroke into a route carrying `throttle:sensitive` (10/min), with a bare `.then()`:

```ts
useEffect(() => {
  if (loanType && Number(principal) > 0 && periods && periods > 0) {
    loansApi.previewAmortization(loanType, principal, Number(periods)).then(setSchedule);
  } else { setSchedule([]); }
}, [loanType, principal, periods]);
```

After — `useDebounce` (already imported in this file for the employee search) plus
`useQuery`, which owns cancellation and ordering, `retry: false` so a 429 is not amplified,
and a visible notice on failure instead of an unhandled rejection. This copies the shape
`spa/src/pages/self-service/loans.tsx:222-233` already uses rather than inventing one.

### Verification — measured, with the before-baseline

**Red-before / green-after, proven by reverting the source:**

```
# with app/Modules/Loans/Services/LoanService.php restored from `git show HEAD:`
Tests:    2 failed (9 assertions)
  FAILED … bulk approve returns success and failure buckets
  FAILED … bulk approve http body never carries a decoded primary key
          "failure id must be a HashID string, not an integer
           Failed asserting that 2 is of type string."

# restore proven byte-for-byte, then re-run
$ sha256sum -c /tmp/loanservice.sha256
app/Modules/Loans/Services/LoanService.php: OK
Tests:    2 passed (20 assertions)
```

**Regression suites (own database `ogami_test_loans`, never the shared `ogami_test`):**

```
$ docker compose run --rm -e DB_DATABASE=ogami_test_loans api php artisan test \
    tests/Feature/Loans tests/Feature/HR/SelfServiceLoanLifecycleTest.php \
    tests/Feature/HR/ClearanceLoanBlockTest.php tests/Feature/Notifications/LoanNotificationTest.php
Tests:    28 passed (143 assertions)   Duration: 19.25s
```

**SPA:**

```
$ npm run typecheck        → clean (tsc --noEmit, no output)
$ npx eslint src/pages/loans/create.tsx src/pages/loans/detail.tsx \
      src/routes/hrRoutes.tsx src/lib/money.ts src/lib/__tests__/money.test.ts --max-warnings 0
                           → clean
$ npm run test:run         → Test Files 50 passed (50) · Tests 309 passed (309)
$ npm run audit:tokens     → ✓ token discipline clean — 797 files checked
```

Note the prior session recorded the full SPA typecheck as blocked by
`spa/src/pages/crm/sales-orders/create.tsx:169`. That blocker is gone; typecheck is clean.

**Static analysis:**

```
$ php -l app/Modules/Loans/Services/LoanService.php          → No syntax errors detected
$ php -l tests/Feature/Loans/LoanBulkApproveTest.php          → No syntax errors detected
$ ./vendor/bin/phpstan analyse <both> --memory-limit=1G       → [OK] No errors
```

**Pint — inherited, proven, not excused.** Both changed PHP files fail `pint --test`. The
same two files extracted from `git show HEAD:` fail with **byte-identical fixer lists**:

```
changed:  LoanService.php      → fully_qualified_strict_types, control_structure_braces,
                                 method_chaining_indentation, unary_operator_spaces,
                                 braces_position, statement_indentation,
                                 not_operator_with_successor_space, single_line_empty_body,
                                 blank_line_before_statement, ordered_imports,
                                 binary_operator_spaces, phpdoc_align
HEAD:     LoanService.php      → (identical 12, same order)

changed:  LoanBulkApproveTest.php → binary_operator_spaces
HEAD:     LoanBulkApproveTest.php → binary_operator_spaces
```

Zero new violations introduced. Pint was never run in write mode.

### New tests

| File | Kind | Goes red against unmodified source? |
| --- | --- | --- |
| `api/tests/Feature/Loans/LoanBulkApproveTest.php` — existing test, assertions rewritten | regression lock on R-009 | **YES** — it previously *asserted the leak* (`assertContains(99999, $failedIds)`) |
| `api/tests/Feature/Loans/LoanBulkApproveTest.php::test_bulk_approve_http_body_never_carries_a_decoded_primary_key` — new | regression lock on R-009 at the HTTP surface | **YES**, verified above |
| `spa/src/lib/__tests__/money.test.ts` — 7 cases | **documentation + regression lock, labelled honestly: it does NOT go red against unmodified source**, because it tests a helper this session introduced. It pins the arithmetic (including `Number('0.1')+Number('0.2') !== 0.3` and a 333.33/333.33/333.34 split summing to exactly 1000.00) so the float pattern cannot come back through this helper. | no |

The HTTP assertion is made on the **decoded** payload, not a substring of the serialized
body, because `json_encode` escapes `/` and string matching against raw JSON is unsound.

### Scratch artefacts — removed

Four probe files were written to measure the invariants and **deleted before release**:
`ZzLoanInvariantProbeTest.php`, `ZzLoanConcurrencyProbeTest.php`,
`ZzLoanApprovalWalkProbeTest.php`, `ZzLoanDoubleDeductProbeTest.php`. The database
`ogami_test_loans` was dropped. No `php artisan serve` was used and no live HTTP probe ran,
so nothing touched the dev `ogami` database.

### Harness notes for the next session

- A probe that provokes a **real** PostgreSQL `lock_timeout` aborts the surrounding
  `RefreshDatabase` transaction and takes the PHPUnit process down with exit 255. Run any
  such test **alone**; it cannot share a file with tests that must run after it.
- Two connections cannot demonstrate row-lock contention if the contended row was created
  inside `RefreshDatabase`'s transaction — the second connection cannot see it, so it locks
  nothing and the probe silently reports "no contention". Commit the fixture on the second
  connection (including its `departments`/`positions` parents) and clean it up by hand.
- `employees` requires `birth_date` (not `date_of_birth`) and `nationality`;
  `payroll_periods` has no `period_no`; `payrolls` requires `pay_type`. Useful when hand-rolling
  fixtures outside the factories.

### Deferred — unchanged from the plan

R-001 (company loan chain stalled at `production_manager`), R-002 (final-pay recovery
unreachable / separation deadlock), R-003+R-005b (as-of recompute drops later payments and
payroll then over-collects), R-005 (reversal corrupts `pay_periods_remaining`), R-004/Q-1
(interest policy), R-006 (no manual payment path), R-007 (feature-gate bypass), R-011 (missing
partial UNIQUE index — contained in principle, deferred for the production preflight), R-012
(per-cutoff key), R-010 (dead surfaces), R-014 (docs), R-016/R-017, R-018.

Release status: **🔁 Needs Re-audit** — five questions (Q-1 … Q-5) need a human decision
before the money-path items can start.
