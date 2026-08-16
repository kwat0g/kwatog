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

**Non-defect — `api/app/Modules/HR/Services/SeparationService.php:291-297`.** larastan
anchors the report at the `EmployeeLoan::query()` chain start on :291; the `->count()` it
objects to is on :297. It reports "Called 'count'
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
- **Evidence:** `api/composer.json` (`larastan/larastan ^3.10` under `require-dev`); `.github/workflows/api-tests.yml:86`; absence of any `phpstan.neon`; measured error counts on `app/` (1,376 files) — without larastan: 1 / 3,327 / 8,410 / 9,681 at levels 0 / 1 / 3 / 5; with larastan: 2 / 2,107 / 3,892 / 5,151. That sweep was measured **before** this tranche's fixes; Task 2 removed one of the two level-0 items, so today's tree reports **1** at level 0 with larastan active (the `SeparationService` locked-count item alone), which the single documented suppression takes to 0. `php artisan recruitment:check-bottlenecks` exits 0, proving the reported error is not a runtime fault.
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

### F-042 — PHPUnit's non-forced APP_ENV=testing loses to docker-compose's APP_ENV=local, so the suite never runs in the environment it declares

- **Module / feature:** Test harness / environment isolation.
- **Related modules:** `App\Common\Traits\HasHashId` (applied to every model) and `App\Common\Middleware\LogSlowQueries`; the suite it misconfigures is 1,772 test methods across 356 files.
- **Category:** Test integrity / configuration correctness.
- **Affected roles:** all contributors; no direct runtime user impact.
- **Current Behavior:** `docker-compose.yml:11` injects `APP_ENV=${APP_ENV:-local}` into the api container's real process environment. `api/phpunit.xml:35` declares `<env name="APP_ENV" value="testing"/>` with no `force="true"`, and PHPUnit skips a non-forced `<env>` whose name is already present in the process environment. The declaration therefore loses: `app()->environment()` returns `local` for the whole local suite.
- **Problem:** Any code branching on the testing environment takes the wrong branch locally. `api/app/Common/Traits/HasHashId.php:33` and `:58` accept a raw integer route ID when `app()->environment('testing')`; that binding path is exercised in CI — `.github/workflows/api-tests.yml:90` sets `APP_ENV: testing` as a real process variable — but never locally, where a numeric segment instead falls through to `app('hashids')->decode()` and `abort(404)` on :39/:64. `api/app/Common/Middleware/LogSlowQueries.php:52` defaults slow-query logging to **on** locally for the same reason, when the intent stated on :49 is "off in testing". Local and CI runs therefore execute different branches of the same tree.
  **Scope correction.** An earlier revision of this entry claimed that every `<env>` value in `phpunit.xml` also present in `api/.env` is silently inert and that tests read development configuration. That is false and was withdrawn. PHPUnit applies each non-forced `<env>` *before* Laravel boots, and Laravel's Dotenv is **immutable** — it will not overwrite a variable that is already set. Precedence is **process env > `phpunit.xml` > `api/.env`**. `DB_DATABASE=ogami_test`, the seven `COMPANY_*` keys, `TAX_PH_VAT_RATE`, `CACHE_STORE` and the rest all take effect and all override `api/.env`. `APP_ENV` is the sole inert declaration: 23 of the 24 apply.
- **Real-world scenario:** A contributor adds a route-bound endpoint test that passes a raw integer ID. It is green in CI and 404s on their machine, with nothing in the diff to explain it. Equally dangerous in the other direction: a guard written as `if (app()->environment('testing')) { return; }` to suppress an outbound call is assumed inert during the suite, and locally the call fires.
- **Root Cause:** A non-forced `<env>` cannot outrank a variable the Compose service already exports. `docker-compose.yml` supplies `APP_ENV` for runtime reasons and nothing exempts the test process from it.
- **Recommended Improvement:** Add `force="true"` to the `APP_ENV` entry **and to that entry only.** Do not generalise the attribute across the block: `api/phpunit.xml:64,66,68` declare `DB_HOST=db`, `DB_DATABASE=ogami_test` and `DB_PASSWORD=ogami_dev_pw`, while `.github/workflows/api-tests.yml:93,97` supply `DB_HOST=127.0.0.1` and `DB_PASSWORD=ogami_ci_pw` as process variables for the runner's own service containers. Forcing those entries would override CI's coordinates and point the entire runner suite at the Compose hostname `db`, which does not resolve on a GitHub runner. Those declarations are non-forced *on purpose* — CI must be able to outrank them.
  Note also that the attribute alone does **not** achieve the Ideal Process below. Laravel switches env files only when `.env.{APP_ENV}` exists, and `api/.env.testing` is gitignored (`.gitignore:12`) and untracked — present in some working copies, absent in CI and in a fresh clone. Forcing `APP_ENV=testing` therefore makes *which env file loads* machine-dependent, trading one divergence for a less visible one; on a machine that has the file, the eight keys listed under Evidence lose their `api/.env` values because that file does not carry them. Genuine env-file isolation needs a committed testing env file carrying the `COMPANY_*` and `ADMIN_*` keys, which is a larger change than this finding.
- **Ideal Process:** The test suite's declared environment is the environment it runs in, identically on a developer machine and in CI.
- **New Feature/Module Required:** No.
- **Cross-Module Impact:** Every model, via `HasHashId` route binding; any future `environment('testing')` guard.
- **Evidence:** `api/phpunit.xml:35`; `docker-compose.yml:11`; `.github/workflows/api-tests.yml:90,93,97`; `api/app/Common/Traits/HasHashId.php:33,58`; `api/app/Common/Middleware/LogSlowQueries.php:49-52`. Measured by replaying `phpunit.xml`'s non-forced `<env>` semantics and then booting the framework through `Illuminate\Contracts\Console\Kernel::bootstrap()`: `APP_ENV` is the only skipped declaration (23 applied), `app()->environment()` → `local`, `config('database.connections.pgsql.database')` → `ogami_test` (not `api/.env`'s `ogami`), `config('cache.default')` → `array`, `env('TAX_PH_VAT_RATE')` → `0.12`. Pre-setting `DB_DATABASE=phpunit_sentinel` and `COMPANY_LEGAL_NAME=phpunit_sentinel_co` the way PHPUnit does returns both sentinels after a full boot, confirming `phpunit.xml` outranks `api/.env`.
  Separately — and this is a **distinct** observation, not evidence of the missing `force="true"` — the keys `phpunit.xml` never declares do fall through to `api/.env`: `COMPANY_CERTIFICATION`, `COMPANY_SALES_INBOX_EMAIL`, `COMPANY_EMAIL_BRAND_NAME`, `COMPANY_LOGO_PATH`, `ADMIN_NAME`, `ADMIN_EMAIL`, `ADMIN_PASSWORD`, `ACCOUNTING_FUNCTIONAL_CURRENCY_CODE`. `api/.env` currently supplies four of them (`:14,17,23,91`) and leaves the other four unset, so those resolve to their in-code defaults. `api/.env:17` pins `COMPANY_CERTIFICATION` to a string byte-identical to its hardcoded fallback, which is what made F-040's env tier indistinguishable from its default tier — but `COMPANY_CERTIFICATION` appears **0** times in `api/phpunit.xml`, so `api/.env` legitimately supplies it and no `force` attribute would change that. The remedy for this half is to declare the keys the suite depends on, not to force the ones it already declares.
- **Priority:** P2.
- **Impact:** Local and CI runs exercise different `HasHashId` branches, and any `environment('testing')` guard is silently inactive locally. The suite's other declared `<env>` values are unaffected.
- **Complexity:** M — one attribute, but on a working copy holding an untracked `api/.env.testing` it also changes which env file loads, so it needs its own full-suite run.
- **Status note:** Discovered while implementing F-040 in this tranche. Registered `open` rather than fixed: altering which `.env` the suite loads immediately before this tranche's full-suite verification would make the tranche unverifiable. The mechanism, remediation constraints, and acceptance gate were corrected on 2026-08-16 after the original entry's claims were measured and found wrong; the finding itself remains open and unfixed.

### F-043 — The API test workflow never reached PHPStan or PHPUnit: every framework-booting step ran as production

- **Module / feature:** CI pipeline / `api-tests.yml`.
- **Related modules:** all of `api/` — the workflow is the only automated gate for the PHP suite.
- **Category:** CI correctness / environment configuration.
- **Affected roles:** all contributors; no runtime user impact.
- **Current Behavior:** `.github/workflows/api-tests.yml` set `APP_ENV: testing` only inside the `Run PHPUnit` step. `api/.env` is untracked, so in CI `config('app.env')` fell back to its `env('APP_ENV', 'production')` default (`api/config/app.php:5`). Every step that boots the framework therefore booted as **production**, and `App\Providers\AppServiceProvider:185` calls `ProductionAssertions::assertSafeOrFail()`, which fires only in production (`api/app/Common/Support/ProductionAssertions.php:18`) and throws at `:44`.
- **Problem:** Two steps boot the framework before PHPUnit ever runs. `composer install --prefer-dist --no-progress --no-interaction --optimize-autoloader` (`api-tests.yml:88`) triggers Composer's `post-autoload-dump` hook `artisan package:discover`; and larastan's bootstrap boots the kernel for `phpstan analyse`. Both died on `Production boot blocked: HASHIDS_SALT must be set to a non-default value (config/hashids.php). APP_KEY must be a real generated key (php artisan key:generate). SERVER_NAME must identify the real production host.` The job failed at step 6 of 11, so **PHPStan and PHPUnit have never executed in CI** — the suite has had no automated gate at all.
- **Real-world scenario:** A contributor reads a 41-second red `API tests` badge, assumes a flaky or slow pipeline, and merges on the strength of a local run. Every regression the 1,772-test suite exists to catch ships unguarded.
- **Root Cause:** Step-scoped rather than job-scoped `APP_ENV`, combined with an untracked `api/.env` so nothing supplied the variable outside that one step. The production guard is behaving exactly as designed — it caught a production-shaped boot. The environment was wrong, not the assertion.
- **Recommended Improvement:** Declare `env: APP_ENV: testing` at **job** scope so every step inherits it. The `Run PHPUnit` step's own `env:` block may keep its redundant `APP_ENV` declaration; it re-declares the same value harmlessly. Do not weaken or condition `ProductionAssertions` — it is the control that surfaced this.
- **Ideal Process:** Every step in a test workflow runs in the environment that workflow claims, and the suite it gates actually executes.
- **New Feature/Module Required:** No.
- **Cross-Module Impact:** The whole PHP suite regains automated enforcement. F-039's and F-041's gates become CI-observable rather than locally-asserted.
- **Evidence:** `.github/workflows/api-tests.yml:88,106`; `api/config/app.php:5`; `api/app/Providers/AppServiceProvider.php:185`; `api/app/Common/Support/ProductionAssertions.php:18,30,44`; GitHub Actions run 31931733825 on `main`, job `PHP quality + PHPUnit`, which failed at `Install dependencies` in 37s with the message quoted above while `Run PHPStan` and `Run PHPUnit` were never reached. Locally reproduced both directions: with `APP_ENV=production`, `artisan package:discover` throws `RuntimeException` and `phpstan analyse app` reports `PHPStan was unable to bootstrap your application due to an error in your code`; with `APP_ENV=testing`, both are clean and PHPStan reports `[OK] No errors`.
- **Priority:** P1.
- **Impact:** The PHP test suite had no CI enforcement whatsoever. This also invalidated Tranche A's original claim to "make `api-tests.yml` pass" — F-039 and F-041 removed two real blockers, but an earlier one made the workflow unpassable regardless.
- **Complexity:** S — one job-scoped `env:` block.
- **Status note:** Found by pushing `main` and reading the CI log, after Tranche A's local verification had wrongly inferred that the job died at the PHPStan step. Fixed in the same change that registered it.
