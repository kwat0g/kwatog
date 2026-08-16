# Tranche B — Money Correctness in the Budget and Approval Gates

**Date:** 2026-08-16
**Status:** approved design, not yet implemented
**Source:** discovery audit of 2026-08-16 — RISK-001 and convention violation C1
**Tranche:** B of A–E. Tranche A (verification restoration, F-039–F-043) is complete and CI is green on `main`.

## Goal

Remove float arithmetic and premature rounding from the two paths that decide
whether a purchase is permitted and how many approval levels it needs, so those
decisions are exact at the cent.

D1 below is the only finding in the 2026-08-16 audit that produces a **wrong
business outcome** today rather than a maintainability, performance, or
observability cost. D2 is latent — disabled by current configuration — and is
fixed here because the call sites are already open, not because it is currently
misbehaving.

## The two defects

### D1 — round-then-threshold classification

`api/app/Modules/Accounting/Services/BudgetEnforcementService.php:41-44` computes

```php
$pct = $available > 0
    ? round(($amount + ($budgets->sum('total_spent') + $budgets->sum('total_committed'))) / $budgets->sum('total_allocated') * 100, 1)
    : 0;
```

and `:59-71` then classify by comparing that rounded percentage against ratio
settings:

```php
if ($pct / 100 >= $overdrawn) { ... }
if ($pct / 100 >= $exhausted) { ... }
```

`budget.exhausted_ratio` seeds to `1.00`
(`api/database/migrations/0297_seed_budget_inventory_and_action_policy_settings.php`).
Because `$pct` is rounded to one decimal first, **every consumption level from
99.95% upward becomes `100.0`**, so `1.0 >= 1.00` is true and the request is
classified `exhausted`.

Worked example. A department with `total_allocated = 1,000,000.00` and
`total_spent + total_committed = 999,000.00` submits a request for `500.00`:

- true consumption after the request: `999,500.00 / 1,000,000.00` = **99.95%**
- `round(99.95, 1)` = `100.0` → `1.0 >= 1.00` → `[false, 'exhausted', …]`

The department genuinely had `1,000.00` available and asked for `500.00`.

**Severity depends on enforcement mode, and the default is not `block`.**
`BUDGETING_ENFORCEMENT_MODE` defaults to `warn` (`.env.example`), and
`BudgetEnforcementService::assess()` only throws when the mode is `block`. So in
the current configuration this defect does **not** reject the request — it stamps
a false `exhausted` level and message onto the document
(`budget_warning_level` / `budget_warning_message`) and demands a Finance
acknowledgment that is not warranted. Under `block` it rejects outright. Both
outcomes are wrong; only the second is loud.

Verified live threshold values: `budget.warning_ratio 0.8`,
`critical_ratio 0.95`, `exhausted_ratio 1`, `overdrawn_ratio 1.2` — so the
99.95%–99.99% band is genuinely reachable in this database.

This requires no floating-point imprecision at all — rounding to one decimal
deliberately discards 0.05% of resolution, and the threshold sits exactly on the
boundary that loss lands on.

**The same defect exists in a second decision path.**
`api/app/Modules/Accounting/Services/BudgetService.php:82-90` (`checkConsumption`)
reads `$budget->utilization_percent`, which is itself
`round(…, 1)` (`api/app/Modules/Accounting/Models/Budget.php:76`), and compares
`$pct / 100 >= $exhausted`. Fixing only the enforcement service would leave an
identical misclassification driving alert and label state.

### D2 — float money comparison

`api/app/Modules/Accounting/Models/Budget.php:71-74`:

```php
public function getAvailableAttribute(): float
{
    return (float) ($this->total_allocated - $this->total_spent - $this->total_committed);
}
```

The columns arrive from PostgreSQL as **exact decimal strings** — verified at
runtime: `total_allocated` is `string '18500000.00'`, while `available` is
`double 8325000.0`. `Budget` declares no decimal casts (its `$casts` covers only
`submitted_at` and `approved_at`), so nothing upstream has lost precision; this
accessor is the first and only place it goes.

The float then decides:

- `BudgetEnforcementService.php:46` — `if ($available <= 0)`
- `BudgetEnforcementService.php:50` — `if ($amount > $available)` → `overdrawn`

The same class appears in the approval chain:

- `api/app/Common/Services/ApprovalService.php:23` — `submit(Model $approvable, string $workflowType, ?float $amount = null)`, whose threshold comparison decides whether an approval step is **skipped**
- `api/app/Common/Traits/HasApprovalWorkflow.php:21` — `submitForApproval(string $workflowType, ?float $amount = null)`
- `api/app/Modules/Purchasing/Services/PurchaseRequestService.php:294` — `$maySkip = $limit > 0 && $total <= $limit;`

`workflow_definitions.amount_threshold` is `decimal(15, 2)`
(`api/database/migrations/0009_create_workflow_definitions_table.php:18`), but
`ApprovalService` does not read that column — it reads
`(float) $step['threshold']` out of the `steps` JSON column
(`ApprovalService.php:39`).

**D2 is currently latent, and the spec says so rather than overstating it.**
Verified against the live database:

- every `workflow_definitions.amount_threshold` is `NULL`, and no `steps` JSON
  entry contains a `threshold` key — they hold only `order`, `role`, `label`. So
  `isset($step['threshold'])` is always false, `$threshold` is always `null`, and
  **no approval step is ever skipped by amount today**.
- `purchasing.urgent_skip_limit` is `0`, and `submitUrgent()` gates on
  `$limit > 0`, so `$maySkip` is always false and that comparison decides nothing
  either.

Both of D2's mechanisms are therefore disabled by configuration. It is worth
fixing regardless — each is a loaded gun that becomes lossy the moment an
operator sets a workflow threshold or a non-zero urgent limit, and the fix is
nearly free while the call sites are already open — but it is **not** producing
wrong outcomes now. D1 is the active defect and the reason this tranche is
prioritised.

Both violate `CLAUDE.md`'s explicit rule: *"Money: `decimal(15, 2)` — **NEVER
float**."* The repository already owns the correct tool,
`App\Common\Support\Money` (bcmath, string in and out, sign-aware half-up
rounding), used at 246 call sites elsewhere.

## Approach: compare amounts, never divide

The alternative considered was to keep the ratio shape and compute it with bcmath
at high scale without rounding before comparison. Rejected: division is the one
bcmath operation that forces an explicit scale choice and can still lose
precision, and it buys nothing, because the comparison can be restated without
it.

Classify by comparing money to money:

```
consumedAfter = total_spent + total_committed + amount     // Money::add
threshold     = total_allocated × ratio                    // Money::mul
if Money::gte(consumedAfter, threshold) → that level
```

No division and no rounding anywhere in the decision. `Money::mul` internally
rounds its result to 2 decimals, which is correct here — the threshold is a peso
amount being compared against a peso amount.

Percentages are still computed, but **only** to build the human-readable message
(`"Budget {$pct}% consumed."`), and are marked display-only at their definition
so a future reader does not reintroduce them as an input.

## Components

| Unit | Change |
|---|---|
| `Budget::getAvailableAttribute()` (`Budget.php:71`) | Return `string`, computed as `Money::sub(Money::sub($this->total_allocated, $this->total_spent), $this->total_committed)`. |
| `Budget::getUtilizationPercentAttribute()` (`Budget.php:76`) | Keeps returning `float`. It becomes display-only and is no longer any gate's input; documented as such at the definition. |
| `BudgetEnforcementService::checkAvailability()` (`:24`) | Signature `float $amount` → `string $amount`. Sum `$available` with `Money::add` over the collection rather than `Collection::sum`. Replace `$available <= 0` with `Money::lte($available, '0')` and `$amount > $available` with `Money::gt`. Replace the four `$pct / 100 >= $ratio` comparisons with amount comparisons. |
| `BudgetEnforcementService::assess()` (`:78`), `::enforce()` (`:143`) | Signature `float $amount` → `string $amount`. The `document->forceFill([... 'amount' => $amount])` audit payload carries the exact string. |
| `BudgetService::checkConsumption()` (`:82`) | Same amount-based classification, so the two paths cannot drift apart again. Takes its figures from the `Budget`'s exact string columns rather than `utilization_percent`. |
| `ApprovalService::submit()` (`:23`) | `?float $amount` → `?string $amount`. Stop float-casting the threshold at `:39`: read `(string) $step['threshold']` and compare with `Money::lt($amount, $threshold)`. Note the threshold lives in the `steps` JSON column, so if an operator stores it as a JSON *number* its precision is already bounded by `json_decode` before this code sees it — the design therefore also documents, in the method's docblock, that a step threshold should be written as a JSON **string** (`"50000.00"`). No data migration: no threshold exists yet. |
| `HasApprovalWorkflow::submitForApproval()` (`:21`) | `?float $amount` → `?string $amount`. |
| `PurchaseRequestService` | `:215` drop `(float)` from `$total = (float) $pr->totalEstimatedAmount()`. `:287` `submitUrgent(float $total)` → `string $total`. `:294` `$total <= $limit` → `Money::lte($total, $limit)`. |
| `PurchaseOrderService:329`, `LoanService:200`, `SalaryAdjustmentService:55` | Drop the `(float)` cast; pass the decimal string through. |
| `BillService:145`, `BudgetController:284` | Drop the `(float)` cast on the `checkAvailability` argument. |
| `BudgetResource` | **Unchanged.** It already casts `(float)` for `total_allocated`, `total_spent` and `total_committed`, so it remains the API boundary. |

**No migration.** The columns are already `decimal(15, 2)` and already arrive as
exact strings.

**No cast changes.** Adding `decimal:2` casts to `Budget` would be a no-op for
correctness here (PostgreSQL already returns exact strings) and is out of scope.

**No SPA changes.** Because `BudgetResource` keeps its existing `(float)` casts,
`spa/src/types/budgeting.ts` — which declares these fields as `number` — stays
accurate and untouched. Making the API contract string-typed to match
`CLAUDE.md`'s TypeScript convention was considered and deliberately cut: it drags
in SPA types, chart code, and formatting for no correctness gain. Noted as a
possible later tranche.

## Accepted behaviour changes

These are the point of the tranche, but they change what the gate permits and
should be communicated to Finance rather than discovered.

1. **A department between 99.95% and 99.99% consumed is no longer classified
   `exhausted`.** Only `>= 100.00%` is. Under `BUDGETING_ENFORCEMENT_MODE=block`,
   requests that were previously rejected in that band will now be accepted.
2. **Exact-boundary requests resolve deterministically.** `amount == available`
   to the cent is no longer `overdrawn`; `amount == available + 0.01` is. Under
   the float comparison the boundary case depended on representation.
3. **Approval-level skipping is exact — a latent change, not an observable one.**
   No workflow currently defines a threshold and `purchasing.urgent_skip_limit`
   is `0`, so nothing observable changes today. `ApprovalService` skips a step when
   `$amount < $threshold` (strict), so a total exactly equal to a step's
   `amount_threshold` is **retained**, not skipped. That semantic is unchanged —
   what changes is that the boundary now resolves deterministically via
   `Money::lt` instead of depending on float representation.

Levels other than the boundary bands are unaffected: a department at 80%, 95% or
120% classifies exactly as before.

## Error handling

No new failure modes. `Money` returns `'0.00'` for division by zero, but this
design performs no division. Where `total_allocated` is zero,
`checkConsumption`'s existing early return (`total_allocated <= 0 → 0`) is
preserved as an explicit `Money::isZero` guard, so a zero-allocation budget
classifies `ok` rather than dividing.

`ApprovalService::submit()` keeps `?string` nullable: callers that pass no amount
(`LeaveRequestService:185`, `ReturnRequestService:187`) are unchanged, and a null
amount continues to mean "no threshold gating applies".

## Testing

`BudgetEnforcementWiringTest` covers wiring only — no existing test asserts level
classification or percentages, so **the defect is entirely uncovered and every
test below is an addition, with nothing to rewrite.** TDD per
`docs/PATTERNS.md`, red before green.

| Test | Asserts |
|---|---|
| 99.95% band | allocated 1,000,000.00, spent+committed 999,000.00, request 500.00 → NOT `exhausted`. **Red today.** |
| exactly 100.00% | request exactly exhausts the budget → `exhausted`. Guards against over-correcting D1. |
| exact-boundary amount | `amount == available` to the cent → not `overdrawn`. |
| one cent over | `amount == available + 0.01` → `overdrawn`. |
| unchanged bands | 80.00% → `warning`, 95.00% → `critical`, 120.00% → `overdrawn`, proving only the boundary behaviour moved. |
| zero allocation | `total_allocated = 0.00` → `ok`, no division. |
| approval threshold boundary | The test must **seed a workflow whose `steps` JSON carries a `threshold`**, because none exists in the shipped data. Total exactly the threshold → step **retained** (strict `<`); one cent below → skipped; one cent above → retained. Deterministic at all three. |
| `checkConsumption` parity | the same figures through both decision paths yield the same level, locking the duplication closed. |
| multi-budget sum | two active budgets for one department sum with `Money::add`; cent values that would drift under `Collection::sum` on floats. |

Every monetary fixture uses adversarial cent values (`…​.05`, `…​.95`, `…​.01`)
rather than round thousands, since round numbers are exactly where float and
decimal agree and therefore prove nothing.

## Exit criteria

1. All new tests green; `--filter=Budget` and `--filter=Approval` exit 0
2. `vendor/bin/phpstan analyse app --memory-limit=1G` → exit 0 (larastan active, level 0, from Tranche A)
3. Full suite green, one serial run, no concurrent PHPUnit process — the current
   baseline is `Tests: 1815, Assertions: 9106`
4. `grep` shows no remaining `float $amount` / `?float $amount` in
   `BudgetEnforcementService`, `ApprovalService`, `HasApprovalWorkflow`, or
   `PurchaseRequestService`
5. Registered as findings in the governance contract (Tranche A's mechanism:
   dated register + lifecycle row + acceptance gate, with the manifest's explicit
   gate count incremented)
6. CI observed green on the real Actions run — **not inferred from a local
   proxy.** Tranche A established this rule the hard way: `api/.env.testing`
   exists on a developer machine and not in CI, which is why local verification
   passed while CI had been broken the entire time.

## Out of scope

- Making the budgeting API and `spa/src/types/budgeting.ts` string-typed
- Adding `decimal:2` casts to `Budget` (no correctness effect — columns already
  return exact strings)
- `CustomerResource`'s `credit_available`, which already follows the string
  convention (`spa/src/types/accounting.ts:222` declares `string | null`)
- Every Tranche C–E risk: the 75 hot unindexed FK join keys, the DTR import N+1,
  payslip DOLE fields, error tracking, dead-scheduler alerting, and the hygiene
  sweep
- F-042 (`phpunit.xml` `APP_ENV` without `force="true"`), still deliberately open
- The three pre-existing vacuous manifest gates F-009, F-015 and F-037, whose
  `--filter` strings match no tests
