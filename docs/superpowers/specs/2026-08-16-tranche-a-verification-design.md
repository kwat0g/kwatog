# Tranche A — Restore Trustworthy Verification

**Date:** 2026-08-16
**Status:** approved design, not yet implemented
**Source:** discovery audit of 2026-08-16 (16 risks, 6 convention classes)
**Tranche:** A of A–E. Tranches B–E are out of scope here and get their own specs.

## Goal

`api-tests.yml` and `audit-governance.yml` both pass, the `static_audit` CI gate
becomes meaningful instead of decorative, and the three new findings enter the
existing F-NNN governance system.

Tranche A comes first because nothing in Tranches B–E can be *proven* fixed
while the API test suite is red and the static gate checks almost nothing.

## Why this tranche exists

The 2026-08-16 audit found 16 risks. Grouped:

| Tranche | Content | Status |
|---|---|---|
| **A** | Red CI, decorative static gate | this spec |
| B | Money correctness — `Budget` float in the enforcement gate | deferred |
| C | Performance — 265 unindexed FKs, DTR import N+1, unbounded lists | deferred |
| D | Compliance and observability — payslip DOLE fields, error tracking, dead-scheduler alerting | deferred |
| E | Hygiene and docs — dead code, config pin, `CLAUDE.md` reconciliation | deferred |

A is a hard prerequisite. B–E are independent of each other.

## Findings in scope

### F-039 — larastan is installed but never activated, so the static gate is decorative and fails on a false positive

`composer.json` requires `larastan/larastan ^3.10`, and
`.github/workflows/api-tests.yml:86` runs `vendor/bin/phpstan analyse app
--memory-limit=1G`. There is no `phpstan.neon` anywhere in the repository, so
PHPStan runs at its default level 0 **without** larastan's extension. It
therefore has no knowledge of Eloquent, facades, or container bindings.

Two consequences:

1. The gate reports exactly one error — `Call to static method table() on an
   unknown class DB` at `api/app/Console/Commands/CheckRecruitmentBottlenecks.php:188`
   — which is a **false positive**. Laravel still registers the `DB` alias via
   `Facade::defaultAliases()` even with no `aliases` key in `config/app.php`.
   Verified empirically: `php artisan recruitment:check-bottlenecks` completes
   successfully. The gate exits 1, so `api-tests` fails on every push.
2. Real defects go unreported. Activating larastan at the same level 0 makes the
   false positive disappear and surfaces two genuine errors (F-040 below, plus
   one documented false positive).

Measured error counts, `paths: [app]`, 1,376 files:

| Level | No larastan | With larastan |
|---|---|---|
| 0 | 1 | 2 |
| 1 | 3,327 | 2,107 |
| 3 | 8,410 | 3,892 |
| 5 | 9,681 | 5,151 |

**Priority:** P1 — blocks CI and masks real defects.

### F-040 — `EmailBrandingService` reads `env()` after `config:cache`, so its env fallback tier is dead in production

`api/app/Common/Services/EmailBrandingService.php:78-82`:

```php
$envKey = strtoupper(str_replace(['.', '-'], '_', $key));
$environment = env($envKey);
if (is_string($environment) && trim($environment) !== '') {
    return trim($environment);
}
```

`value()` resolves in three tiers: settings row, then `env()`, then a hardcoded
default. `docker/php/prod-entrypoint.sh:15` and `Makefile:219` both run
`php artisan config:cache`. After that, `env()` returns null for anything not
read through `config()`, so tier 2 never fires in production.

The repository already documents this exact trap at `api/routes/api.php:57-58`:
"Read via `config()`, not `env()` — prod-entrypoint runs `config:cache` and
`env()` returns null outside config files once config is cached."

The tier is independently broken for one key regardless of caching:
`email.brand_name` derives the env name `EMAIL_BRAND_NAME`, while
`api/.env.example` defines `COMPANY_EMAIL_BRAND_NAME`. That lookup has never
matched anything.

**Impact:** degrades quietly rather than failing. All nine affected keys have a
settings row seeded from `env()` at migrate/seed time, when `env()` still works,
so tier 1 normally carries the right value and the dead tier is invisible.

**Priority:** P2 — silent, environment-only, with a working fallback.

### F-041 — `BadgeControllerTest` MRP fixture violates the plan-uniqueness invariants added on 2026-08-15

`api/database/migrations/2026_08_15_121000_guard_mrp_plan_versions.php` adds two
constraints to `mrp_plans`:

- `UNIQUE (sales_order_id, version)` named `mrp_plans_sales_order_version_unique`
- partial unique index `mrp_plans_one_active_per_sales_order ON mrp_plans (sales_order_id) WHERE status = 'active'`

`api/tests/Feature/Dashboard/BadgeControllerTest.php:202-221` inserts two
`mrp_plans` rows for the same `sales_order_id`, both `status = 'active'`, both
defaulting to `version = 1`. Both new constraints are violated. The test was not
updated with the migration.

```
SQLSTATE[23505]: Unique violation: duplicate key value violates unique
constraint "mrp_plans_sales_order_version_unique"
DETAIL: Key (sales_order_id, version)=(1, 1) already exists.
```

Deterministic: `php artisan test --filter=BadgeControllerTest` exits 2 on every
run (verified three times serially). Because
`.github/workflows/api-tests.yml:104` runs the full `vendor/bin/phpunit`, the
`api-tests` job fails on every push.

The constraint is correct and stays. The fixture changes.

**Priority:** P1 — blocks CI.

## Explicitly not findings

### Retracted: suspected test-suite non-determinism

The audit initially reported the suite as order-dependent, having observed 5, 2,
and 1 failures across three runs of `--filter='Import|Badge'`, with
`RawPunchImportTest` passing alone but failing in a batch.

**This was an artifact of the audit method, not a defect.** A killed
`docker compose exec` left a `phpunit` process running inside the `api`
container while new runs were started, so two suites were writing to the shared
`ogami_test` database concurrently. Three subsequent serial runs with nothing
else executing produced identical results every time: 1 failed, 30 passed, 253
assertions — the F-041 failure alone.

Recorded here so it is not re-investigated. No F-number is assigned; a retracted
observation is not a finding.

### Not a defect: `SeparationService.php:291`

With larastan active, level 0 reports:

```
app/Modules/HR/Services/SeparationService.php:291:
Called 'count' on Laravel collection, but could have been retrieved as a query.
```

The code is:

```php
$outstandingLoans = EmployeeLoan::query()
    ->where('employee_id', $lockedClearance->employee_id)
    ->whereIn('status', ['active', 'pending'])
    ->where('balance', '>', 0)
    ->lockForUpdate()
    ->get(['id'])
    ->count();
```

PostgreSQL rejects `FOR UPDATE` in combination with aggregate functions, so
`->count()` cannot carry the row lock. The preceding comment states the lock's
purpose: "Lock the rows before checking balances. A loan settlement racing with
finalization must resolve before the decision is made." Rewriting this to
`->count()` would either error on PostgreSQL or silently drop the lock and
reintroduce the race.

Suppressed via `ignoreErrors` with that reason recorded inline. Not fixed.

## Design

### 1. Governance plumbing

The repository enforces a three-way 1:1 invariant in `audit-governance.yml`:

- `docs/SYSTEM-AUDIT-FINDINGS-2026-08-13.md` — `### F-NNN` headings
- `docs/SYSTEM-AUDIT-FINDING-LIFECYCLE.json` — one row per finding
- `docs/AUDIT-ACCEPTANCE-MANIFEST-2026-08-13.json` — one machine gate per finding

Both validators hardcode the 08-13 findings path and the count 38.

**`scripts/verify-audit-finding-lifecycle.mjs`**

Replace the single hardcoded `readFileSync` of
`docs/SYSTEM-AUDIT-FINDINGS-2026-08-13.md` with a scan of `docs/` for filenames
matching `/^SYSTEM-AUDIT-FINDINGS-\d{4}-\d{2}-\d{2}\.md$/`, sorted for
deterministic output, reading and concatenating every match before extracting
`### F-NNN`.

Use `readdirSync` plus a regex filter — no glob dependency. The script currently
imports only `node:fs`, `node:path`, and `node:url`, and this change must not add
a package to a repository whose CI runs `composer audit` and `npm audit` as
gates.

Globbing introduces a failure mode the single-file read could not have: the same
F-NNN documented in two files. Add that as an explicit error — otherwise the
generalization silently weakens the invariant the script exists to enforce.
Track which file each ID came from so the message can name both.

Existing error cases (`missing lifecycle row`, `lifecycle row has no finding`,
field and status validation) are unchanged.

**`scripts/verify-audit-acceptance-manifest.mjs`**

- Bump the expected gate count from 38 to 41. Kept as an explicit constant by
  decision: growing the registry stays a deliberate, reviewable edit rather than
  a silently-absorbed one.
- `finding_source` is currently a single string naming only the 08-13 document,
  and nothing validates it. With two findings documents that field becomes
  false. Rename to `finding_sources` (array), and validate that it lists exactly
  the set of `SYSTEM-AUDIT-FINDINGS-YYYY-MM-DD.md` files the lifecycle validator
  discovers. This converts an unvalidated, now-inaccurate field into a checked
  one.
- `schema_version` stays `1` and the validator's existing `schema_version !== 1`
  check is unchanged. The rename is the only manifest-shape change; both the
  manifest and its validator are edited in the same commit, so no consumer ever
  sees `finding_source` and `finding_sources` disagree.

No workflow YAML changes. `audit-governance.yml:26-29` already invokes both
scripts by path.

### 2. New findings document

`docs/SYSTEM-AUDIT-FINDINGS-2026-08-16.md`, matching the 08-13 document's
structure: a header stating audit date, scope, and method, then one `### F-NNN —
<title>` section per finding using the established bullet labels (Module /
feature, Related modules, Category, Affected roles, Current Behavior, Problem,
Real-world scenario, Root Cause, Recommended Improvement, Ideal Process, New
Feature/Module Required, Cross-Module Impact, Evidence, Priority, Impact,
Complexity).

The header also records the retracted non-determinism observation and the
`SeparationService` non-defect, so both are discoverable without re-running the
investigation.

### 3. Registry rows

Three rows appended to `docs/SYSTEM-AUDIT-FINDING-LIFECYCLE.json`, matching the
existing shape. Concrete target values, so the plan does not have to invent them:

```json
{
  "id": "F-039",
  "status": "verified",
  "owner": "Platform Engineering",
  "evidence_date": "2026-08-16",
  "verification_scope": "larastan-backed static analysis of app/ at level 0, with one documented suppression",
  "policy_decision": null,
  "regression_proof": "phpstan analyse app: 0 errors (was 1 false positive without larastan, 2 real errors with it)"
}
```

F-040 — `verification_scope`: "EmailBrandingService key resolution under cached
config". F-041 — `verification_scope`: "MRP plan badge count against the
2026-08-15 plan-uniqueness constraints". Both `policy_decision: null`; both
`owner: "Platform Engineering"`; both `evidence_date: "2026-08-16"`.
`regression_proof` for each is the actual test/assertion count from the run that
proves it, filled in when the fix lands.

Each row is created at `status: "open"` and flipped to `"verified"` when its fix
lands with evidence. The validator rejects `verified` or `mitigated` without a
non-empty `regression_proof`, so the status flip and the evidence necessarily
land in the same commit.

### 4. Acceptance gates

Three gates appended to `docs/AUDIT-ACCEPTANCE-MANIFEST-2026-08-13.json`. Every
gate needs a non-empty machine-runnable `command` (only F-030 is permitted a
`null` command).

| ID | `type` | `command` |
|---|---|---|
| F-039 | `static_audit` | `cd api && vendor/bin/phpstan analyse app --memory-limit=1G` |
| F-040 | `focused_test` | `cd api && php artisan test --filter=EmailBranding` |
| F-041 | `focused_test` | `cd api && php artisan test --filter=BadgeControllerTest` |

`static_audit` and `focused_test` are both already in the validator's allowed
type set. Command style matches the existing `cd api && …` convention.

### 5. F-039 implementation

New `api/phpstan.neon`:

```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    paths:
        - app
    level: 0
    ignoreErrors:
        # SeparationService locks the loan rows it counts. PostgreSQL rejects
        # FOR UPDATE with aggregates, so ->count() cannot carry the lock and
        # would reintroduce the settlement race the lock exists to prevent.
        -
            message: "#Called 'count' on Laravel collection, but could have been retrieved as a query#"
            path: app/Modules/HR/Services/SeparationService.php
```

`level: 0` matches what CI effectively runs today, so this commit changes the
gate's *knowledge*, not its strictness — the diff stays reviewable. CI already
passes `app` as an argument and picks up `phpstan.neon` from the working
directory automatically; `api-tests.yml` needs no change.

Level ≥1 is deliberately out of scope: 2,107 errors at level 1 with larastan
active. Raising it requires a `phpstan-baseline.neon` and a burn-down plan, which
is its own tranche.

Bundled style-only change with no behavior or gate effect: add
`use Illuminate\Support\Facades\DB;` to `CheckRecruitmentBottlenecks.php` and
drop the leading backslash at line 188, matching every other `DB` consumer in
the codebase. This is cleanup, not the fix — larastan resolves `\DB` correctly
either way.

### 6. F-040 implementation

Delete the `env()` tier from `EmailBrandingService::value()`, leaving settings
then hardcoded default.

Safe because every key that tier could serve already has a settings source
written while `env()` still works:

| Key | Seeded by |
|---|---|
| `company.legal_name`, `company.address`, `company.phone`, `company.email`, `company.tin`, `company.vat_status`, `company.certification`, `company.public_url` | `0123_seed_company_branding_settings.php:17-26` |
| `company.sales_inbox_email` | `0358_seed_landing_contact_setting.php`, `SettingsSeeder.php` |
| `email.brand_name` | `SettingsSeeder.php` |

**Accepted behavior change:** editing a `COMPANY_*` variable in `.env` after
migrating will no longer take effect in local development without also updating
the settings row (via `PATCH /admin/settings/{key}` or a re-seed). That is
already the behavior in production, where the tier is dead. This makes
development match production instead of diverging from it, which is the point.

### 7. F-041 implementation

`BadgeControllerTest.php:212-221` — give the second `mrp_plans` row its own
sales order:

```php
$so2 = SalesOrder::factory()->create();
DB::table('mrp_plans')->insert([
    'mrp_plan_no'     => 'MRP-TEST-0002',
    'sales_order_id'  => $so2->id,
    'status'          => 'active',
    'shortages_found' => 0,
    // ...
]);
```

The row must keep `status = 'active'`. `BadgeService.php:448-450` counts
`status = active AND shortages_found > 0`, and this row exists to prove the
discriminator is `shortages_found`, not `status`. Demoting its status would let
`assertSame(1, $resp['mrp_plans']['count'])` at line 270 pass even if the
counter dropped its `shortages_found` filter — a weaker test that still goes
green.

A distinct `sales_order_id` satisfies both new constraints while preserving the
assertion at full strength. It also matches the pattern every other fixture
block in this test already follows: one row that counts, one that does not.

## Testing

TDD per `docs/PATTERNS.md`, following the repository's `test:` → `fix:` →
`docs:` commit rhythm visible in recent history.

- **F-040** — new feature test: cache config, then assert `EmailBrandingService::data()`
  resolves each key from its settings row and does not fall through to a
  hardcoded default when a setting is present. Must be red before the fix, since
  today the env tier masks the failure in a non-cached local environment. Name it
  so `--filter=EmailBranding` selects it, matching the gate command.
- **F-041** — `assertSame(1, $resp['mrp_plans']['count'])` at
  `BadgeControllerTest.php:270` is already the regression test. Red → green with
  no new test added.
- **F-039** — the gate is the test: `vendor/bin/phpstan analyse app` exits 0.

## Order of work

1. Governance plumbing — validator changes, new findings document, three
   lifecycle rows at `status: open`, three gates. Both governance validators pass
   at 41 findings.
2. F-041 — fixture fix. `--filter=BadgeControllerTest` green.
3. F-040 — failing test, then remove the env tier. `--filter=EmailBranding` green.
4. F-039 — add `phpstan.neon`, apply the `DB` import cleanup. `phpstan analyse app` exits 0.
5. Flip all three lifecycle rows to `verified` with populated `regression_proof`.
6. Full-suite verification.

## Exit criteria

All measured, not assumed:

1. `cd api && vendor/bin/phpstan analyse app --memory-limit=1G` → exit 0
2. `node scripts/verify-audit-finding-lifecycle.mjs` → exit 0, reports 41 findings
3. `node scripts/verify-audit-acceptance-manifest.mjs` → exit 0, 41 gates mapped
4. `cd api && php artisan test` → green, as **one serial run with no other
   phpunit process executing**

Criterion 4 is required, not optional. The audit never completed a full run —
one attempt was killed at ~293 tests, and the concurrency that killed it is what
produced the retracted non-determinism finding. Expect roughly 9–15 minutes.
Confirm no stray `php` process is running in the `api` container before starting.

## Out of scope

- phpstan level ≥1 and `phpstan-baseline.neon`
- Every Tranche B–E risk, including the `Budget` float money defect (RISK-001),
  the 265 unindexed FK columns (RISK-003), and the DTR import N+1 (RISK-004)
- SPA gates, which already pass: `tsc --noEmit`, `eslint --max-warnings 0`,
  `audit:tokens`, `audit:rbac`, and 219 vitest tests
- The stray `Ad9BNN` unset-variable warning emitted by `docker compose`, which is
  local environment noise with no effect on the application
