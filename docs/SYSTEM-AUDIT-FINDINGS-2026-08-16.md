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
