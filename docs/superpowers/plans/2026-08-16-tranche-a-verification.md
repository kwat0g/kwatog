# Tranche A — Verification Restoration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `api-tests.yml` and `audit-governance.yml` pass, turn the decorative `static_audit` CI gate into a real one by activating larastan, and register findings F-039–F-042 in the existing audit-governance contract.

**Architecture:** Three independent fixes plus governance plumbing. Two fixes are code (`EmailBrandingService` drops a dead `env()` tier; `BadgeControllerTest` gets a second sales order). One is configuration (`api/phpstan.neon` activates larastan at level 0 with one documented suppression). Governance lands last, once every gate command actually passes, so no gate is ever committed in a vacuously-passing state.

**Tech Stack:** Laravel 12 / PHP 8.3, PHPUnit 11, larastan 3.10 / PHPStan, PostgreSQL 16, Node 20 (ESM validator scripts), Docker Compose.

**Spec:** `docs/superpowers/specs/2026-08-16-tranche-a-verification-design.md`

## Global Constraints

- Repository root is the working directory for all `docker compose` and `node scripts/…` commands. PHP commands run inside the `api` container.
- Local execution uses `docker compose exec -T api …`. CI runs the same commands on the host with `defaults.run.working-directory: api` (`.github/workflows/api-tests.yml`). Acceptance-manifest gate commands use the repository's existing `cd api && …` convention.
- **Never run two PHPUnit processes at once.** Both share the `ogami_test` database. Concurrent runs produce phantom failures — this is what caused the retracted non-determinism finding recorded in the spec. Before any full-suite run, confirm no stray process: `docker compose exec -T api ps aux | grep -c '[p]hpunit'` must print `0`.
- PHP memory: the full suite needs more than the 128M default. Apply once per container life: `docker compose exec -T -u root api bash -c "echo 'memory_limit = 512M' > /usr/local/etc/php/conf.d/zz-mem.ini"`.
- `declare(strict_types=1);` on every PHP file, per `CLAUDE.md`.
- Commit rhythm from recent history: `test:` → `fix:` / `feat:` → `docs:`.
- Do not stage unrelated files. The working tree has ~533 modified and ~162 untracked files from other work. Every commit below stages an explicit file list — never `git add -A` or `git add .`.
- PHPStan stays at `level: 0`. Level 1 reports 2,107 errors with larastan active and is explicitly out of scope.

## Task ordering rationale

The spec lists governance plumbing first. This plan does it last, for two reasons the spec's ordering did not account for:

1. F-040's gate command is `php artisan test --filter=EmailBranding`. Until Task 2 creates that test, the filter matches nothing and PHPUnit 11 exits `0` with "No tests executed!" (`failOnEmptyTestSuite` is not set in `api/phpunit.xml`). Registering that gate first would commit a gate that passes without testing anything.
2. F-040 must precede F-039. Activating larastan surfaces the `EmailBrandingService` `env()` error; adding `phpstan.neon` before the fix would leave the new gate red.

CI is already red today, so deferring governance does not regress anything. Exit criteria are unchanged.

## File Structure

| File | Responsibility | Task |
|---|---|---|
| `api/tests/Feature/Dashboard/BadgeControllerTest.php` | Modify `:212-221` — second MRP plan gets its own sales order | 1 |
| `api/tests/Feature/Email/EmailBrandingResolutionTest.php` | Create — proves settings is authoritative and `env()` is not a branding source | 2 |
| `api/app/Common/Services/EmailBrandingService.php` | Modify `:71-86` — delete the `env()` tier from `value()` | 2 |
| `api/phpstan.neon` | Create — activate larastan, level 0, one documented suppression | 3 |
| `api/app/Console/Commands/CheckRecruitmentBottlenecks.php` | Modify `:188` — import the `DB` facade (style only) | 3 |
| `scripts/verify-audit-finding-lifecycle.mjs` | Modify — discover every dated findings document, reject cross-file duplicate IDs | 4 |
| `scripts/verify-audit-acceptance-manifest.mjs` | Modify — expect 42 gates, validate `finding_sources` | 4 |
| `docs/SYSTEM-AUDIT-FINDINGS-2026-08-16.md` | Create — F-039–F-042 sections plus the two non-findings | 4 |
| `docs/SYSTEM-AUDIT-FINDING-LIFECYCLE.json` | Modify — four rows (F-042 open) | 4 |
| `docs/AUDIT-ACCEPTANCE-MANIFEST-2026-08-13.json` | Modify — `finding_source` → `finding_sources`, four gates | 4 |

---

### Task 1: F-041 — Repair the BadgeControllerTest MRP fixture

**Files:**
- Modify: `api/tests/Feature/Dashboard/BadgeControllerTest.php:212-221`
- Test: same file (the existing assertion at `:270` is the regression test)

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: nothing later tasks rely on. `App\Modules\CRM\Models\SalesOrder` is already imported at `:18` — do not add an import.

**Background.** Migration `api/database/migrations/2026_08_15_121000_guard_mrp_plan_versions.php` added `UNIQUE (sales_order_id, version)` and a partial unique index `mrp_plans_one_active_per_sales_order ON mrp_plans (sales_order_id) WHERE status = 'active'`. The fixture inserts two `active` rows for one sales order, both defaulting to `version = 1`, violating both.

The second row **must keep `status = 'active'`**. `api/app/Modules/Dashboard/Services/BadgeService.php:448-450` counts `status = active AND shortages_found > 0`; this row exists to prove the discriminator is `shortages_found`, not `status`. Demoting it would let `assertSame(1, …)` pass even if the counter dropped its `shortages_found` filter.

- [ ] **Step 1: Run the test to confirm the documented failure**

```bash
docker compose exec -T api php artisan test --filter=BadgeControllerTest
```

Expected: FAIL. One test fails — `widened scope badge counts track recent rows` — with:

```
SQLSTATE[23505]: Unique violation: duplicate key value violates unique
constraint "mrp_plans_sales_order_version_unique"
DETAIL: Key (sales_order_id, version)=(1, 1) already exists.
```

- [ ] **Step 2: Give the second MRP plan its own sales order**

In `api/tests/Feature/Dashboard/BadgeControllerTest.php`, the current block reads:

```php
        // ── MRP plans (SO + generator required) ────────────────────────
        $so = SalesOrder::factory()->create();
        DB::table('mrp_plans')->insert([
            'mrp_plan_no'    => 'MRP-TEST-0001',
            'sales_order_id' => $so->id,
            'status'         => 'active',
            'shortages_found'=> 3,
            'generated_by'   => $user->id,
            'generated_at'   => now(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
        DB::table('mrp_plans')->insert([
            'mrp_plan_no'    => 'MRP-TEST-0002',
            'sales_order_id' => $so->id,
            'status'         => 'active',
            'shortages_found'=> 0,
            'generated_by'   => $user->id,
            'generated_at'   => now(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
```

Replace it with:

```php
        // ── MRP plans (SO + generator required) ────────────────────────
        // Migration 2026_08_15_121000 allows only one active plan per sales
        // order, so the negative case needs its own SO. It must stay 'active':
        // the badge counts active plans WITH shortages, and this row is what
        // proves the discriminator is shortages_found rather than status.
        $so = SalesOrder::factory()->create();
        DB::table('mrp_plans')->insert([
            'mrp_plan_no'    => 'MRP-TEST-0001',
            'sales_order_id' => $so->id,
            'status'         => 'active',
            'shortages_found'=> 3,
            'generated_by'   => $user->id,
            'generated_at'   => now(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
        $soWithoutShortages = SalesOrder::factory()->create();
        DB::table('mrp_plans')->insert([
            'mrp_plan_no'    => 'MRP-TEST-0002',
            'sales_order_id' => $soWithoutShortages->id,
            'status'         => 'active',
            'shortages_found'=> 0,
            'generated_by'   => $user->id,
            'generated_at'   => now(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
```

- [ ] **Step 3: Run the test to verify it passes**

```bash
docker compose exec -T api php artisan test --filter=BadgeControllerTest
```

Expected: PASS, 9 tests. Note the assertion count for the lifecycle `regression_proof` in Task 4.

- [ ] **Step 4: Confirm the assertion still discriminates on `shortages_found`**

Temporarily weaken the counter to prove the test would catch a regression. In `api/app/Modules/Dashboard/Services/BadgeService.php:450`, comment out the `shortages_found` filter:

```php
                'counter'     => fn (): int => MrpPlan::query()
                    ->where('status', MrpPlanStatus::Active->value)
                    // ->where('shortages_found', '>', 0)
```

Run `docker compose exec -T api php artisan test --filter=BadgeControllerTest`.

Expected: FAIL with `Failed asserting that 2 is identical to 1`. This proves the fixture still does its job.

**Then revert `BadgeService.php` exactly** — restore the `->where('shortages_found', '>', 0)` line and delete the comment. Verify with `git diff --stat api/app/Modules/Dashboard/Services/BadgeService.php`, which must print nothing.

- [ ] **Step 5: Commit**

```bash
git add api/tests/Feature/Dashboard/BadgeControllerTest.php
git commit -m "fix: separate MRP badge fixture plans onto distinct sales orders

Migration 2026_08_15_121000 added UNIQUE (sales_order_id, version) and a
partial unique index permitting one active plan per sales order. The badge
fixture inserted two active version-1 plans for a single sales order and
was not updated with the migration, failing the api-tests job on every push.

The negative-case row keeps status 'active' deliberately: BadgeService counts
active plans with shortages, so demoting the row would let the assertion pass
even if the counter dropped its shortages_found filter.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: F-040 — Remove the dead `env()` tier from EmailBrandingService

**Files:**
- Create: `api/tests/Feature/Email/EmailBrandingResolutionTest.php`
- Modify: `api/app/Common/Services/EmailBrandingService.php:71-86`

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces: test class `Tests\Feature\Email\EmailBrandingResolutionTest`, which Task 4's F-040 gate command `php artisan test --filter=EmailBranding` selects. The class name must keep the `EmailBranding` prefix or that gate silently matches nothing.
- `EmailBrandingService::data(): array<string, string|null>` and `logoPath()` / `logoDataUri()` signatures are unchanged. Only the private `value()` helper changes.

**Background.** `value()` resolves in three tiers: settings row, `env()`, hardcoded default. `docker/php/prod-entrypoint.sh:15` and `Makefile:219` run `php artisan config:cache`, after which `env()` returns null — so tier 2 is dead in production. The repository documents this exact trap at `api/routes/api.php:57-58`.

Removal is safe because every key tier 2 could serve already has a settings row seeded from `env()` at migrate/seed time: eight in `0123_seed_company_branding_settings.php:17-26`, plus `company.sales_inbox_email` (`0358_seed_landing_contact_setting.php`, `SettingsSeeder.php`) and `email.brand_name` (`SettingsSeeder.php`).

**Why the test uses `company.certification`.** `api/phpunit.xml` sets `COMPANY_LEGAL_NAME`, `COMPANY_ADDRESS`, `COMPANY_PHONE`, `COMPANY_EMAIL`, `COMPANY_TIN`, `COMPANY_VAT_STATUS`, and `COMPANY_PUBLIC_URL` to values identical to their hardcoded fallbacks — so for those keys, tier 2 and tier 3 are indistinguishable and no assertion can go red. `COMPANY_CERTIFICATION` is not set in `phpunit.xml`, so the test can set it to a sentinel via `putenv()` (verified to reach Laravel's `env()` through Dotenv's `EnvConstAdapter`) and distinguish the tiers cleanly.

- [ ] **Step 1: Write the failing test**

Create `api/tests/Feature/Email/EmailBrandingResolutionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Common\Services\EmailBrandingService;
use App\Common\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F-040 — transactional email branding must resolve from settings only.
 *
 * EmailBrandingService::value() used to fall back to env() between the
 * settings row and the hardcoded default. Production runs `config:cache`
 * (docker/php/prod-entrypoint.sh:15), after which env() returns null, so that
 * tier was dead in the only environment that mattered while still firing
 * locally — a silent dev/prod divergence.
 *
 * COMPANY_CERTIFICATION is the probe key because phpunit.xml does not set it,
 * and its hardcoded fallback differs from any env value we inject. The other
 * company keys are configured in phpunit.xml to exactly their fallback
 * strings, so they cannot distinguish tier 2 from tier 3.
 */
class EmailBrandingResolutionTest extends TestCase
{
    use RefreshDatabase;

    private const PROBE_KEY = 'company.certification';
    private const PROBE_ENV = 'COMPANY_CERTIFICATION';
    private const FALLBACK = 'IATF 16949:2016 Certified';

    protected function tearDown(): void
    {
        // Calling putenv() with a bare name unsets the variable, so the
        // sentinel cannot leak into any test that runs after this one.
        putenv(self::PROBE_ENV);

        parent::tearDown();
    }

    public function test_environment_is_not_a_branding_source_when_the_setting_is_empty(): void
    {
        app(SettingsService::class)->set(self::PROBE_KEY, '');
        putenv(self::PROBE_ENV.'=ENV-LEAK-SENTINEL');

        // Guard the premise: if a future seeder populates this key, the test
        // would pass for the wrong reason.
        $this->assertSame(
            '',
            (string) app(SettingsService::class)->get(self::PROBE_KEY),
            'Probe setting must be empty for this test to exercise the fallback path.',
        );

        $brand = app(EmailBrandingService::class)->data();

        $this->assertSame(
            self::FALLBACK,
            $brand['certification'],
            'Branding fell through to env(); settings must be the only configured source.',
        );
        $this->assertStringNotContainsString('SENTINEL', (string) $brand['certification']);
    }

    public function test_settings_row_is_authoritative_over_the_hardcoded_default(): void
    {
        app(SettingsService::class)->set(self::PROBE_KEY, 'IATF 16949:2016 Certified (Ogami Cavite)');
        putenv(self::PROBE_ENV.'=ENV-LEAK-SENTINEL');

        $brand = app(EmailBrandingService::class)->data();

        $this->assertSame('IATF 16949:2016 Certified (Ogami Cavite)', $brand['certification']);
    }
}
```

- [ ] **Step 2: Run the test to verify the first case fails**

```bash
docker compose exec -T api php artisan test --filter=EmailBranding
```

Expected: 1 failed, 1 passed. The failure is `test_environment_is_not_a_branding_source_when_the_setting_is_empty` with:

```
Branding fell through to env(); settings must be the only configured source.
Failed asserting that two strings are identical.
- 'IATF 16949:2016 Certified'
+ 'ENV-LEAK-SENTINEL'
```

If both tests pass at this step, stop — the premise is wrong and the fix is not needed as described. Re-read `EmailBrandingService::value()` before continuing.

- [ ] **Step 3: Delete the `env()` tier**

In `api/app/Common/Services/EmailBrandingService.php`, replace the whole `value()` method:

```php
    private function value(string $key, string $fallback): string
    {
        $configured = $this->settings->get($key);
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $envKey = strtoupper(str_replace(['.', '-'], '_', $key));
        $environment = env($envKey);
        if (is_string($environment) && trim($environment) !== '') {
            return trim($environment);
        }

        return $fallback;
    }
```

with:

```php
    /**
     * Settings row, then hardcoded default. There is deliberately no env()
     * tier: production runs `php artisan config:cache`
     * (docker/php/prod-entrypoint.sh:15), after which env() returns null, so
     * an env tier fires only in development and silently diverges from
     * production. Every branding key is seeded into settings from env at
     * migrate/seed time — migration 0123 for the company.* keys,
     * SettingsSeeder for email.brand_name and company.sales_inbox_email —
     * which is where environment values legitimately enter the system.
     */
    private function value(string $key, string $fallback): string
    {
        $configured = $this->settings->get($key);
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        return $fallback;
    }
```

- [ ] **Step 4: Run the test to verify both cases pass**

```bash
docker compose exec -T api php artisan test --filter=EmailBranding
```

Expected: PASS, 2 tests. Record the assertion count for Task 4.

- [ ] **Step 5: Verify no other caller depended on the env tier**

```bash
docker compose exec -T api php artisan test --filter='Email|Mail|Landing|Recruitment'
```

Expected: PASS. These are the suites that render branded mail (`ApplicationReceivedMail`, `ApplicationStatusUpdatedMail`, `InterviewScheduledMail`, `InterviewDetailsUpdatedMail`, `EmailIntegrationTestMail`). Any failure here means a key resolved through env in the test environment and now needs its settings row seeded — fix the seeder, not the service.

- [ ] **Step 6: Commit**

```bash
git add api/tests/Feature/Email/EmailBrandingResolutionTest.php api/app/Common/Services/EmailBrandingService.php
git commit -m "fix: resolve email branding from settings only

EmailBrandingService::value() fell back to env() between the settings row
and the hardcoded default. Production runs config:cache
(docker/php/prod-entrypoint.sh:15), after which env() returns null, so that
tier was dead in production while still firing locally. It was also broken
outright for email.brand_name, which derived EMAIL_BRAND_NAME while
.env.example defines COMPANY_EMAIL_BRAND_NAME.

Removing it is safe: every affected key is seeded into settings from env at
migrate/seed time, which is where environment values legitimately enter.

Behavior change: editing a COMPANY_* variable in .env after migrating no
longer takes effect locally without updating the setting. That already was
production behavior; development now matches it.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: F-039 — Activate larastan so the static gate is real

**Files:**
- Create: `api/phpstan.neon`
- Modify: `api/app/Console/Commands/CheckRecruitmentBottlenecks.php` (imports block and `:188`)

**Interfaces:**
- Consumes: Task 2's fix. Without it, larastan reports the `EmailBrandingService` `env()` error and this task's gate cannot go green.
- Produces: `api/phpstan.neon`, auto-discovered by `vendor/bin/phpstan analyse app` because `.github/workflows/api-tests.yml` sets `defaults.run.working-directory: api`. No workflow change.

**Background.** `composer.json` requires `larastan/larastan ^3.10` and `api-tests.yml:86` runs `vendor/bin/phpstan analyse app --memory-limit=1G`, but no `phpstan.neon` exists — so PHPStan runs at level 0 with no Laravel knowledge. It reports one false positive that fails CI while missing real defects.

Measured on `app/`, 1,376 files:

| Level | No larastan | With larastan |
|---|---|---|
| 0 | 1 | 2 |
| 1 | 3,327 | 2,107 |
| 5 | 9,681 | 5,151 |

Level 0 keeps this commit's diff about the analyser's *knowledge*, not its strictness.

- [ ] **Step 1: Confirm the current false positive**

```bash
docker compose exec -T api vendor/bin/phpstan analyse app --memory-limit=1G --no-progress
```

Expected: `[ERROR] Found 1 error` —
`Call to static method table() on an unknown class DB` at
`Console/Commands/CheckRecruitmentBottlenecks.php:188`.

This is a false positive. Laravel registers the `DB` alias through `Facade::defaultAliases()` even with no `aliases` key in `config/app.php`. Confirm the command actually runs:

```bash
docker compose exec -T api php artisan recruitment:check-bottlenecks
```

Expected: exits 0, printing a bottleneck scan summary. (It may create notification rows — harmless.)

- [ ] **Step 2: Create the PHPStan configuration**

Create `api/phpstan.neon`:

```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    paths:
        - app

    # Level 0 matches what CI already effectively ran. This commit changes the
    # analyser's knowledge of Laravel, not its strictness, so the diff stays
    # reviewable. Level 1 reports 2,107 errors with larastan active and needs a
    # baseline plus a burn-down plan — its own tranche, deliberately not here.
    level: 0

    ignoreErrors:
        # SeparationService locks the loan rows it counts, then decides whether
        # clearance may finalize. PostgreSQL rejects FOR UPDATE combined with
        # aggregate functions, so ->count() cannot carry the lock; rewriting
        # this would drop the lock and reintroduce the settlement race the
        # preceding comment exists to prevent. Suppressed, not fixed.
        -
            message: "#^Called 'count' on Laravel collection, but could have been retrieved as a query\\.$#"
            path: app/Modules/HR/Services/SeparationService.php
            count: 1
```

- [ ] **Step 3: Run PHPStan to see what larastan surfaces**

```bash
docker compose exec -T api vendor/bin/phpstan analyse app --memory-limit=1G --no-progress
```

Expected: `[OK] No errors`.

The `CheckRecruitmentBottlenecks` false positive is gone because larastan resolves the facade. The `SeparationService` error is suppressed by the `ignoreErrors` entry. The `EmailBrandingService` `env()` error is gone because Task 2 removed the call.

If instead you see `Ignored error pattern … was not matched in reported errors`, the `SeparationService` message text differs from the pattern. Re-run with `--error-format=raw` to read the exact message and correct the regex — do **not** delete the `count: 1` guard, which is what makes an unmatched pattern fail loudly.

- [ ] **Step 4: Apply the DB facade import (style only)**

`api/app/Console/Commands/CheckRecruitmentBottlenecks.php:188` uses `\DB::table(...)` while every other consumer in the codebase imports the facade. This has no behavioral or gate effect now that larastan resolves it — it is consistency cleanup.

Add to the imports block, in alphabetical position after `use Illuminate\Console\Command;`:

```php
use Illuminate\Support\Facades\DB;
```

Then at `:188` change:

```php
        return \DB::table('notifications')
```

to:

```php
        return DB::table('notifications')
```

- [ ] **Step 5: Re-run PHPStan and the command**

```bash
docker compose exec -T api vendor/bin/phpstan analyse app --memory-limit=1G --no-progress
docker compose exec -T api php artisan recruitment:check-bottlenecks
```

Expected: `[OK] No errors`, then the command exits 0 as before.

- [ ] **Step 6: Commit**

```bash
git add api/phpstan.neon api/app/Console/Commands/CheckRecruitmentBottlenecks.php
git commit -m "build: activate larastan so the static analysis gate is meaningful

composer.json required larastan and api-tests.yml ran phpstan, but no
phpstan.neon existed, so PHPStan analysed at level 0 with no knowledge of
Eloquent, facades, or container bindings. It reported one false positive
that failed CI on every push while missing real defects.

With larastan active at the same level 0, the false positive resolves and
the analyser reports the genuine EmailBrandingService env() defect fixed in
the previous commit. SeparationService's locked collection count is
suppressed with the reason inline: PostgreSQL rejects FOR UPDATE with
aggregates, so ->count() would silently drop the row lock.

Level 1 reports 2,107 errors and needs a baseline; that is a separate tranche.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Register F-039–F-042 in the audit governance contract

**Files:**
- Modify: `scripts/verify-audit-finding-lifecycle.mjs:1-8`
- Modify: `scripts/verify-audit-acceptance-manifest.mjs:8,21,23`
- Create: `docs/SYSTEM-AUDIT-FINDINGS-2026-08-16.md`
- Modify: `docs/SYSTEM-AUDIT-FINDING-LIFECYCLE.json` (append four rows — F-042 at status open)
- Modify: `docs/AUDIT-ACCEPTANCE-MANIFEST-2026-08-13.json` (rename one key, append four gates)

**Interfaces:**
- Consumes: Tasks 1–3 all green. Every gate command registered here must already pass.
- Produces: nothing later tasks consume.

**Background.** `audit-governance.yml:26-29` enforces a three-way 1:1 invariant across the findings markdown, `SYSTEM-AUDIT-FINDING-LIFECYCLE.json`, and `AUDIT-ACCEPTANCE-MANIFEST-2026-08-13.json`. The lifecycle validator hardcodes the single 08-13 findings path; the manifest validator hardcodes `38`.

- [ ] **Step 1: Generalise findings-document discovery**

In `scripts/verify-audit-finding-lifecycle.mjs`, replace lines 1-8:

```js
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const findings = readFileSync(resolve(root, 'docs/SYSTEM-AUDIT-FINDINGS-2026-08-13.md'), 'utf8');
const registry = JSON.parse(readFileSync(resolve(root, 'docs/SYSTEM-AUDIT-FINDING-LIFECYCLE.json'), 'utf8'));
const documented = [...findings.matchAll(/^### (F-\d{3})\b/gm)].map((match) => match[1]);
```

with:

```js
import { readdirSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');

// Every audit gets its own dated findings register. Discovering them all keeps
// the 1:1 lifecycle invariant intact as tranches land, instead of pinning the
// contract to whichever audit happened to be first. readdirSync + a regex
// avoids adding a glob dependency to a repository that gates on npm audit.
const findingsFiles = readdirSync(resolve(root, 'docs'))
  .filter((name) => /^SYSTEM-AUDIT-FINDINGS-\d{4}-\d{2}-\d{2}\.md$/.test(name))
  .sort();

const registry = JSON.parse(readFileSync(resolve(root, 'docs/SYSTEM-AUDIT-FINDING-LIFECYCLE.json'), 'utf8'));

// Track the source file per id: reading several documents introduces a failure
// mode a single-file read could not have — the same finding documented twice.
const documentedSources = new Map();
const duplicateDocumentation = [];
for (const name of findingsFiles) {
  const contents = readFileSync(resolve(root, 'docs', name), 'utf8');
  for (const match of contents.matchAll(/^### (F-\d{3})\b/gm)) {
    const id = match[1];
    if (documentedSources.has(id)) {
      duplicateDocumentation.push(`${id}: documented in both ${documentedSources.get(id)} and ${name}`);
      continue;
    }
    documentedSources.set(id, name);
  }
}
const documented = [...documentedSources.keys()];
```

Then, immediately after the `const ids = new Set();` line, add:

```js
errors.push(...duplicateDocumentation);
```

Note: `errors` is declared before `ids` in the existing file, so this ordering is valid.

Finally, change the summary on the last line so operators can see the discovery worked:

```js
console.log(`Audit lifecycle clean: ${registry.length} findings across ${findingsFiles.length} register(s) (${Object.entries(counts).map(([key, value]) => `${key}=${value}`).join(', ')}).`);
```

- [ ] **Step 2: Verify the generalised validator still passes on today's data**

```bash
node scripts/verify-audit-finding-lifecycle.mjs
```

Expected: `Audit lifecycle clean: 38 findings across 1 register(s) (open=1, mitigated=1, verified=36, decision_required=0).`

The count must still be 38 — the new findings document does not exist yet. If this errors, the refactor broke something; fix it before writing any new content.

- [ ] **Step 3: Prove the new duplicate-detection actually fires**

```bash
cp docs/SYSTEM-AUDIT-FINDINGS-2026-08-13.md docs/SYSTEM-AUDIT-FINDINGS-2026-01-01.md
node scripts/verify-audit-finding-lifecycle.mjs; echo "exit=$?"
rm docs/SYSTEM-AUDIT-FINDINGS-2026-01-01.md
```

Expected: exit 1, with 38 lines reading
`F-001: documented in both SYSTEM-AUDIT-FINDINGS-2026-01-01.md and SYSTEM-AUDIT-FINDINGS-2026-08-13.md` and so on. (The copy sorts first, so it is recorded as the original.)

Confirm the temporary file is gone: `git status --porcelain docs/` must not list it.

- [ ] **Step 4: Update the acceptance-manifest validator**

**Note:** Steps 4-7 are one atomic edit set. The manifest validator is expected to pass only at Step 8 — between Steps 4 and 7 it will report a gate-count mismatch and a `finding_sources` mismatch, because the gates and the renamed key do not exist yet. Those intermediate failures are the plan working as designed. Do not "fix" them, and do not commit until Step 8 is green.

In `scripts/verify-audit-acceptance-manifest.mjs`, change line 8:

```js
if (manifest.schema_version !== 1 || !Array.isArray(manifest.gates)) errors.push('invalid manifest schema');
```

to:

```js
if (manifest.schema_version !== 1 || !Array.isArray(manifest.gates)) errors.push('invalid manifest schema');

// finding_sources must name exactly the dated registers the lifecycle
// validator discovers. It was previously a single unvalidated string and went
// stale the moment a second audit landed.
const registers = readdirSync(resolve(root, 'docs'))
  .filter((name) => /^SYSTEM-AUDIT-FINDINGS-\d{4}-\d{2}-\d{2}\.md$/.test(name))
  .sort()
  .map((name) => `docs/${name}`);
const declared = Array.isArray(manifest.finding_sources) ? [...manifest.finding_sources].sort() : null;
if (declared === null) {
  errors.push('finding_sources must be an array of register paths');
} else if (declared.join('|') !== registers.join('|')) {
  errors.push(`finding_sources mismatch: declared ${declared.join(', ') || '(none)'}; found ${registers.join(', ')}`);
}
```

Change the import on line 1 from:

```js
import { readFileSync } from 'node:fs';
```

to:

```js
import { readdirSync, readFileSync } from 'node:fs';
```

Change line 21:

```js
if (manifest.gates?.length !== 38) errors.push(`expected 38 gates, got ${manifest.gates?.length ?? 0}`);
```

to:

```js
// Kept explicit by decision: growing the registry stays a deliberate,
// reviewable edit rather than one absorbed silently.
if (manifest.gates?.length !== 42) errors.push(`expected 42 gates, got ${manifest.gates?.length ?? 0}`);
```

Change line 23:

```js
console.log('Audit acceptance manifest clean: 38 findings mapped; F-030 remains external-evidence-only.');
```

to:

```js
console.log('Audit acceptance manifest clean: 42 findings mapped; F-030 remains external-evidence-only.');
```

- [ ] **Step 5: Write the new findings register**

Create `docs/SYSTEM-AUDIT-FINDINGS-2026-08-16.md`:

````markdown
# Ogami ERP — System Audit Findings Register (2026-08-16)

**Audit date:** 2026-08-16
**Scope:** discovery audit of the full worktree — module inventory, data
integrity, authorization, API and frontend layers, cross-cutting integrations,
and CI gate health.
**Method:** source inventory, live `route:list` and PostgreSQL catalogue
queries, and measured tool runs. File/line references are to the current
worktree.
**Relationship to the 2026-08-13 register:** additive. F-001–F-038 are
unaffected; this register begins at F-039.

**Retracted observation — suspected test-suite non-determinism.** The audit
initially reported the suite as order-dependent, having observed 5, 2, and 1
failures across three runs of `--filter='Import|Badge'`. This was an artifact of
the audit method: a killed `docker compose exec` left a `phpunit` process
running inside the `api` container while new runs started, so two suites wrote
to the shared `ogami_test` database concurrently. Three subsequent serial runs
produced identical results every time — 1 failed, 30 passed, 253 assertions,
the F-041 failure alone. No F-number is assigned; a retracted observation is not
a finding. Recorded so it is not re-investigated.

**Non-defect — `SeparationService.php:291`.** larastan reports "Called 'count'
on Laravel collection, but could have been retrieved as a query." The code is
`->lockForUpdate()->get(['id'])->count()`. PostgreSQL rejects `FOR UPDATE`
combined with aggregate functions, so `->count()` cannot carry the row lock and
rewriting it would reintroduce the loan-settlement race the preceding comment
exists to prevent. Suppressed in `api/phpstan.neon` with that reason inline.

### F-039 — larastan is installed but never activated, leaving the static analysis gate decorative and failing on a false positive

- **Module / feature:** CI quality gates / static analysis.
- **Related modules:** every module under `api/app`.
- **Category:** Engineering productivity / defect detection.
- **Affected roles:** all contributors; no runtime user impact.
- **Current Behavior:** `api/composer.json` requires `larastan/larastan ^3.10` and `.github/workflows/api-tests.yml:86` runs `vendor/bin/phpstan analyse app --memory-limit=1G`, but no `phpstan.neon` exists anywhere in the repository. PHPStan therefore runs at its default level 0 without larastan's extension and has no knowledge of Eloquent, facades, or container bindings.
- **Problem:** The gate reports exactly one error — `Call to static method table() on an unknown class DB` at `api/app/Console/Commands/CheckRecruitmentBottlenecks.php:188` — which is a false positive, since Laravel registers the `DB` alias via `Facade::defaultAliases()` even with no `aliases` key in `config/app.php`. The gate exits 1, failing `api-tests` on every push, while real defects go unreported.
- **Real-world scenario:** A contributor pushes correct code, sees `api-tests` fail on an error that is not a defect, and learns to ignore the gate. Genuine issues such as F-040 ship unnoticed.
- **Root Cause:** A dev dependency was added without the configuration that activates it.
- **Recommended Improvement:** Add `api/phpstan.neon` including `vendor/larastan/larastan/extension.neon` at `level: 0`, with documented `ignoreErrors` for the `SeparationService` locked-count non-defect. Defer level ≥1 to a dedicated tranche.
- **Ideal Process:** Static analysis understands the framework it analyses, so every reported error is actionable and the gate is trusted.
- **New Feature/Module Required:** No. Configuration only.
- **Cross-Module Impact:** Analysis coverage across all of `api/app`.
- **Evidence:** `api/composer.json` (`larastan/larastan ^3.10` under `require-dev`); `.github/workflows/api-tests.yml:86`; absence of any `phpstan.neon`; measured error counts on `app/` (1,376 files) — without larastan: 1 / 3,327 / 8,410 / 9,681 at levels 0 / 1 / 3 / 5; with larastan: 2 / 2,107 / 3,892 / 5,151. `php artisan recruitment:check-bottlenecks` exits 0, proving the reported error is not a runtime fault.
- **Priority:** P1.
- **Impact:** CI blocked; real defects undetected.
- **Complexity:** S.

### F-040 — EmailBrandingService reads env() after config:cache, so its environment fallback tier is dead in production

- **Module / feature:** Common / transactional email branding.
- **Related modules:** HR recruitment mail (`ApplicationReceivedMail`, `ApplicationStatusUpdatedMail`, `InterviewScheduledMail`, `InterviewDetailsUpdatedMail`), `EmailIntegrationTestMail`.
- **Category:** Configuration correctness / dev-prod divergence.
- **Affected roles:** every recipient of transactional email.
- **Current Behavior:** `api/app/Common/Services/EmailBrandingService.php:78-82` resolves each branding key through three tiers — settings row, then `env(STRTOUPPER_KEY)`, then a hardcoded default.
- **Problem:** `docker/php/prod-entrypoint.sh:15` and `Makefile:219` run `php artisan config:cache`. After that, `env()` returns null for anything not read through `config()`, so tier 2 never fires in production while still firing in development. The tier is independently broken for `email.brand_name`, which derives `EMAIL_BRAND_NAME` while `api/.env.example` defines `COMPANY_EMAIL_BRAND_NAME` — that lookup has never matched anything.
- **Real-world scenario:** An operator sets `COMPANY_CERTIFICATION` in the production `.env`, verifies the branding locally where the env tier still works, deploys, and the certification line silently reverts to the hardcoded default in customer-facing mail.
- **Root Cause:** A configuration tier that only functions when the configuration cache is cold, in a deployment that always warms it. The repository already documents this exact trap at `api/routes/api.php:57-58`.
- **Recommended Improvement:** Delete the `env()` tier. Settings is the source of truth and is seeded from env at migrate/seed time, which is where environment values legitimately enter.
- **Ideal Process:** One configuration source per value, behaving identically in development and production.
- **New Feature/Module Required:** No.
- **Cross-Module Impact:** All branded transactional mail.
- **Evidence:** `api/app/Common/Services/EmailBrandingService.php:71-86`; `docker/php/prod-entrypoint.sh:15`; `Makefile:219`; `api/routes/api.php:57-58`; seeding coverage in `api/database/migrations/0123_seed_company_branding_settings.php:17-26`, `0358_seed_landing_contact_setting.php`, and `api/database/seeders/SettingsSeeder.php`.
- **Priority:** P2.
- **Impact:** Silent, environment-only branding regression with a working fallback.
- **Complexity:** S.

### F-041 — Badge dashboard test fixture violates the MRP plan-uniqueness constraints added on 2026-08-15

- **Module / feature:** Dashboard / sidebar badge counts.
- **Related modules:** MRP plan lifecycle.
- **Category:** Test debt / CI correctness.
- **Affected roles:** all contributors; no runtime user impact.
- **Current Behavior:** `api/database/migrations/2026_08_15_121000_guard_mrp_plan_versions.php` adds `UNIQUE (sales_order_id, version)` and a partial unique index `mrp_plans_one_active_per_sales_order ON mrp_plans (sales_order_id) WHERE status = 'active'`. `api/tests/Feature/Dashboard/BadgeControllerTest.php:202-221` inserts two `mrp_plans` rows for one `sales_order_id`, both `status = 'active'` and both defaulting to `version = 1`.
- **Problem:** Both constraints are violated. `php artisan test --filter=BadgeControllerTest` exits 2 deterministically, and `.github/workflows/api-tests.yml:104` runs the full suite, so `api-tests` fails on every push.
- **Real-world scenario:** A correct domain constraint lands, an unrelated test starts failing, and the red build is either ignored or the constraint is reverted to make it green.
- **Root Cause:** A fixture was not updated alongside a constraint-adding migration.
- **Recommended Improvement:** Give the negative-case row its own sales order. It must keep `status = 'active'`: `api/app/Modules/Dashboard/Services/BadgeService.php:448-450` counts active plans with shortages, so demoting the row would let the assertion pass even if the counter dropped its `shortages_found` filter.
- **Ideal Process:** Constraint-adding migrations land with the fixture updates they require.
- **New Feature/Module Required:** No.
- **Cross-Module Impact:** None beyond the test.
- **Evidence:** `api/database/migrations/2026_08_15_121000_guard_mrp_plan_versions.php:19,23`; `api/tests/Feature/Dashboard/BadgeControllerTest.php:202-221,270`; `api/app/Modules/Dashboard/Services/BadgeService.php:448-450`; observed `SQLSTATE[23505] … mrp_plans_sales_order_version_unique, Key (sales_order_id, version)=(1, 1) already exists`.
- **Priority:** P1.
- **Impact:** CI blocked.
- **Complexity:** S.

### F-042 — The PHPUnit suite declares APP_ENV=testing but runs against the development .env

- **Module / feature:** Test harness / environment isolation.
- **Related modules:** every module with a feature test — 1,767 test methods across 355 files.
- **Category:** Test integrity / configuration correctness.
- **Affected roles:** all contributors; no direct runtime user impact.
- **Current Behavior:** `api/phpunit.xml:20` declares `<env name="APP_ENV" value="testing"/>` with no `force="true"` attribute. `docker-compose.yml:11` injects `APP_ENV=${APP_ENV:-local}` into the container's real environment. PHPUnit's `<env>` without `force="true"` does not override a variable that already exists in the environment, so `APP_ENV` stays `local` and Laravel loads `api/.env` rather than a testing configuration.
- **Problem:** The suite does not run in the environment it declares. Every `<env>` value in `phpunit.xml` that is also present in `api/.env` is silently inert — including the carefully commented `COMPANY_*` and `TAX_PH_VAT_RATE` values whose docblock explains they exist to keep document-generating endpoints from failing. Tests read development configuration, so they neither prove behavior under the declared test configuration nor isolate themselves from a developer's local `.env` edits.
- **Real-world scenario:** A developer changes a value in `api/.env` to try something locally. A test that depends on that value changes behavior with no code change, and the failure appears unrelated. Conversely, a test passes locally against a developer's `.env` and fails in CI, where the workflow supplies its own environment block.
- **Root Cause:** A missing `force="true"` attribute, masked by the fact that most affected values in `phpunit.xml` and `api/.env` happen to be identical strings — which is also what made finding F-040's probe key indistinguishable and caused this tranche's plan defect.
- **Recommended Improvement:** Add `force="true"` to the `APP_ENV` entry, then audit every other `<env>` entry in `phpunit.xml` for the same omission. Expect fallout: changing which `.env` loads alters configuration for the whole suite, so this needs its own tranche with a full-suite run, not a drive-by fix.
- **Ideal Process:** The test suite's declared environment is the environment it runs in, independent of any developer's local `.env`.
- **New Feature/Module Required:** No.
- **Cross-Module Impact:** Potentially every feature test.
- **Evidence:** `api/phpunit.xml:20`; `docker-compose.yml:11`; `api/.env:17` (`COMPANY_CERTIFICATION` identical to its hardcoded fallback, which is how this was discovered); observed `env('APP_ENV') === 'local'` inside the running api container.
- **Priority:** P2.
- **Impact:** Tests do not prove what they claim to; local configuration leaks into results.
- **Complexity:** M — the fix is one attribute, the fallout is suite-wide.
- **Status note:** Discovered while implementing F-040 in this tranche. Registered `open` rather than fixed: altering which `.env` the suite loads immediately before this tranche's full-suite verification would make the tranche unverifiable.
````

- [ ] **Step 6: Append the three lifecycle rows**

In `docs/SYSTEM-AUDIT-FINDING-LIFECYCLE.json`, add a comma after the `F-038` row and append these three before the closing `]`. Match the existing one-object-per-line style.

The spec describes creating these rows at `status: "open"` and flipping them to `"verified"` once evidence exists. Because this plan lands governance *after* Tasks 1–3, the evidence already exists here, so the rows are written directly as `verified` and no transition step is needed. The validator's rule still holds: `verified` requires a non-empty `regression_proof`, which is why the counts below must be real.

Substitute the two assertion counts with the values recorded in **Task 1 Step 3** (`--filter=BadgeControllerTest`) and **Task 2 Step 4** (`--filter=EmailBranding`). Both appear in PHPUnit's `Tests:` summary line as `(N assertions)`. Do not invent them — a fabricated count is a false evidence claim in a CI-validated register.

```json
  { "id": "F-039", "status": "verified", "owner": "Engineering Productivity", "evidence_date": "2026-08-16", "verification_scope": "larastan-backed static analysis of app/ at level 0 with one documented suppression", "policy_decision": null, "regression_proof": "phpstan analyse app: 0 errors with larastan active (previously 1 false positive that failed CI)" },
  { "id": "F-040", "status": "verified", "owner": "Platform Engineering", "evidence_date": "2026-08-16", "verification_scope": "EmailBrandingService key resolution with an env sentinel present and the settings row empty", "policy_decision": null, "regression_proof": "Email branding resolution: 2 focused tests / <Task 2 Step 4 assertion count> assertions" },
  { "id": "F-041", "status": "verified", "owner": "Platform Engineering", "evidence_date": "2026-08-16", "verification_scope": "MRP plan badge count against the 2026-08-15 plan-uniqueness constraints", "policy_decision": null, "regression_proof": "Badge counts: 9 focused tests / <Task 1 Step 3 assertion count> assertions, discriminator confirmed by mutating BadgeService" },
  { "id": "F-042", "status": "open", "owner": "Engineering Productivity", "evidence_date": "2026-08-16", "verification_scope": "phpunit.xml APP_ENV declaration only; suite-wide fallout of forcing the testing environment is unmeasured", "policy_decision": null, "regression_proof": null }
```

`F-042` is registered `open` with `regression_proof: null`. The validator permits that — it requires a non-empty `regression_proof` only for `verified` and `mitigated`, and `F-030` is the existing precedent for an `open` row. Do not mark it `verified`; it is deliberately unfixed.

- [ ] **Step 7: Update the acceptance manifest**

In `docs/AUDIT-ACCEPTANCE-MANIFEST-2026-08-13.json`, change line 3 from:

```json
  "finding_source": "docs/SYSTEM-AUDIT-FINDINGS-2026-08-13.md",
```

to:

```json
  "finding_sources": [
    "docs/SYSTEM-AUDIT-FINDINGS-2026-08-13.md",
    "docs/SYSTEM-AUDIT-FINDINGS-2026-08-16.md"
  ],
```

Then add a comma after the `F-038` gate and append these three before the closing `]`, matching the existing compact style and 4-space indent:

```json
    {"id":"F-039","type":"static_audit","command":"cd api && vendor/bin/phpstan analyse app --memory-limit=1G"},
    {"id":"F-040","type":"focused_test","command":"cd api && php artisan test --filter=EmailBranding"},
    {"id":"F-041","type":"focused_test","command":"cd api && php artisan test --filter=BadgeControllerTest"},
    {"id":"F-042","type":"static_audit","command":"cd api && grep -qE 'APP_ENV.+force' phpunit.xml"}
```

F-042's gate command currently exits 1 — correctly, because the finding is `open`. The manifest validator never executes gate commands; it only requires each to be a non-empty string. Recording the command now documents the acceptance criterion so whoever fixes F-042 knows exactly what proves it. Do not soften it to something that passes today.

- [ ] **Step 8: Run both governance validators**

```bash
node scripts/verify-audit-finding-lifecycle.mjs; echo "lifecycle exit=$?"
node scripts/verify-audit-acceptance-manifest.mjs; echo "manifest exit=$?"
```

Expected:

```
Audit lifecycle clean: 42 findings across 2 register(s) (open=2, mitigated=1, verified=39, decision_required=0).
lifecycle exit=0
Audit acceptance manifest clean: 42 findings mapped; F-030 remains external-evidence-only.
manifest exit=0
```

`open=2` is F-030 (pre-existing) plus F-042 (registered open by design).

- [ ] **Step 9: Run every newly registered gate command exactly as written**

```bash
docker compose exec -T api vendor/bin/phpstan analyse app --memory-limit=1G; echo "F-039 exit=$?"
docker compose exec -T api php artisan test --filter=EmailBranding;          echo "F-040 exit=$?"
docker compose exec -T api php artisan test --filter=BadgeControllerTest;    echo "F-041 exit=$?"
(cd api && grep -qE 'APP_ENV.+force' phpunit.xml);                           echo "F-042 exit=$?"
```

Expected: F-039, F-040, and F-041 exit 0. **F-042 exits 1** — that is correct and required, because the finding is registered `open` and unfixed. If F-042 exits 0, something added the `force` attribute and the finding should not be `open`.

A gate that passes because it selected no tests is a failure — confirm F-040 reports 2 tests and F-041 reports 9.

- [ ] **Step 10: Commit**

```bash
git add scripts/verify-audit-finding-lifecycle.mjs \
        scripts/verify-audit-acceptance-manifest.mjs \
        docs/SYSTEM-AUDIT-FINDINGS-2026-08-16.md \
        docs/SYSTEM-AUDIT-FINDING-LIFECYCLE.json \
        docs/AUDIT-ACCEPTANCE-MANIFEST-2026-08-13.json
git commit -m "docs: register F-039..F-042 in the audit governance contract

The lifecycle validator read a single hardcoded findings register, so a
second audit could not enter the contract without editing the script for
every tranche. It now discovers every dated SYSTEM-AUDIT-FINDINGS document
and rejects a finding documented in two of them — a failure mode the
single-file read could not have had.

finding_source named only the 08-13 register and nothing validated it; it is
now finding_sources and is checked against the registers actually present.
The expected gate count stays explicit at 42 so registry growth remains a
reviewable edit. F-042 is registered open: the suite declares APP_ENV=testing
without force="true", so it runs against the development .env. Found while
implementing F-040 and deliberately not fixed here — changing which .env loads
would alter configuration for all 1,767 tests.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Full-suite verification

**Files:** none modified. This task produces evidence, not code.

**Interfaces:**
- Consumes: Tasks 1–4 complete.
- Produces: the measured result satisfying spec exit criterion 4.

This is required, not optional. The audit never completed a full run — one attempt was killed at ~293 tests, and the concurrency that killed it produced the retracted non-determinism finding.

- [ ] **Step 1: Confirm no other PHPUnit process is running**

```bash
docker compose exec -T api ps aux | grep -c '[p]hpunit'
```

Expected: `0`. If not, wait for it to finish. Do not start a second run.

- [ ] **Step 2: Raise the PHP memory limit**

```bash
docker compose exec -T -u root api bash -c "echo 'memory_limit = 512M' > /usr/local/etc/php/conf.d/zz-mem.ini"
```

The 128M default causes an out-of-memory abort partway through the suite.

- [ ] **Step 3: Run the full suite, serially, to completion**

```bash
docker compose exec -T api php artisan test --without-tty 2>&1 | tail -40
```

Expected: `Tests: N passed`, no failures. Runtime roughly 9–15 minutes. Do not run anything else against the `api` container while this executes.

If failures appear, they belong to Tranches B–E or are pre-existing. Record each with its file, test name, and error, then stop and report rather than expanding this tranche's scope.

- [ ] **Step 4: Confirm every exit criterion in one pass**

```bash
docker compose exec -T api vendor/bin/phpstan analyse app --memory-limit=1G; echo "1. phpstan=$?"
node scripts/verify-audit-finding-lifecycle.mjs;                            echo "2. lifecycle=$?"
node scripts/verify-audit-acceptance-manifest.mjs;                          echo "3. manifest=$?"
```

Expected: all three exit 0, with 42 findings reported by both validators.

- [ ] **Step 5: Verify no unrelated files were staged across the tranche**

```bash
git log --stat -5 --format='%h %s'
```

Expected: exactly the files listed in the File Structure table. If any unrelated file appears, correct it before considering the tranche done.

- [ ] **Step 6: Record the evidence**

If Task 4's lifecycle rows used estimated assertion counts, replace them with the measured values now.

```bash
git add docs/SYSTEM-AUDIT-FINDING-LIFECYCLE.json
git commit -m "docs: record measured Tranche A regression evidence

Full suite run serially to completion with no concurrent phpunit process:
<paste the 'Tests:' summary line here>.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

If the counts were already exact, skip this commit.

---

## Out of scope

- PHPStan level ≥1 and `phpstan-baseline.neon` (2,107 errors at level 1 with larastan active).
- Every Tranche B–E risk: the `Budget::getAvailableAttribute(): float` money defect, the 265 unindexed foreign-key columns, the DTR import N+1, payslip DOLE fields, error tracking, and dead-scheduler alerting.
- SPA gates, which already pass: `tsc --noEmit`, `eslint --max-warnings 0`, `audit:tokens`, `audit:rbac`, 219 vitest tests.
- The 52 deleted files under `docs/superpowers/` present in the working tree. They are unrelated to this tranche and recoverable with `git restore docs/superpowers/`.
