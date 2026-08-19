# Close the remaining audit and hygiene backlog

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task.

**Goal:** Close every remaining item that is specifiable from the repository, and record explicitly which items are not — so the backlog ends at a known floor rather than a vague one.

**Architecture:** Six independent tasks. Four touch the audit governance contract (`docs/SYSTEM-AUDIT-FINDING-LIFECYCLE.json`, `docs/AUDIT-ACCEPTANCE-MANIFEST-2026-08-13.json`, `docs/SYSTEM-AUDIT-FINDINGS-2026-08-16.md`) and two are code-only. No task depends on another's output.

**Tech Stack:** Laravel 12.64 / PHP 8.3, PHPUnit 11, PostgreSQL 16, Node 20 for the validator scripts, Docker Compose.

**Spec:** None. There is no design document for this backlog. The binding authority is the audit register itself — `docs/SYSTEM-AUDIT-FINDINGS-2026-08-16.md` for F-042 and F-046, and the measured evidence quoted in each task below. Rulings made here are provisional in the sense the SDD skill means.

## Global Constraints

- Repository root `/home/kwat0g/Desktop/kwatog`. PHP runs in Docker. The long-running `ogami-api` container binds the MAIN checkout, NOT this worktree. To run tests against this worktree use a one-off container:
  ```
  docker run --rm --network kwatog_ogami-net -u www \
    -v <WORKTREE>/api:/var/www \
    -v /home/kwat0g/Desktop/kwatog/api/vendor:/var/www/vendor:ro \
    -e DB_DATABASE=<YOUR_OWN_DB> -w /var/www kwatog-api \
    bash -c 'php -d memory_limit=512M vendor/bin/phpunit --filter=Foo --colors=never'
  ```
  `php -d memory_limit=512M` is required — the image defaults to 128M and OOMs.
  For PHPStan the container also needs `-e APP_ENV=testing -e APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=` plus DB/cache/session/queue/mail/broadcast vars, or `ProductionAssertions::assertSafeOrFail()` aborts the bootstrap.
- **Never run two PHPUnit processes against the same database.** Create your own: `docker compose exec -T db psql -U ogami -d postgres -c "CREATE DATABASE <name> OWNER ogami;"`. A concurrent contributor uses `ogami_test`. Symptoms of collision are `deadlock detected` plus schema-missing errors for objects that exist when queried afterwards — those are phantoms, not findings.
- Before running tests the worktree needs writable dirs: `mkdir -p api/storage/framework/{cache/data,sessions,views} api/storage/logs api/bootstrap/cache` then `find api/storage api/bootstrap/cache -type d -exec chmod 777 {} +`. Use `-type d` — a bare `chmod -R` sets the exec bit on tracked files and dirties the worktree.
- `declare(strict_types=1);` on every PHP file.
- Migration numbers are assigned in this plan: Task 3 uses `0473`, Task 5 uses `0474`. Highest existing is `0472`. Do not pick your own.
- After any change to the governance files, BOTH validators must exit 0:
  `node scripts/verify-audit-finding-lifecycle.mjs` and `node scripts/verify-audit-acceptance-manifest.mjs`.
- When editing `docs/AUDIT-ACCEPTANCE-MANIFEST-2026-08-13.json`, preserve its compact one-gate-per-line formatting. Do not rewrite it with a JSON pretty-printer — that produces a 200-line diff for a one-line change.
- Pint is NOT clean on this repository and CI does not run it. If Pint flags a file you touched, prove whether it is pre-existing by extracting the same file at `main` and running Pint on that copy before treating it as yours.
- Commit with an explicit file list. Never `git add -A`.

## File Structure

| File | Responsibility | Task |
|---|---|---|
| `api/phpunit.xml` | Declare the 8 env keys the suite depends on | 1 |
| `docs/SYSTEM-AUDIT-FINDINGS-2026-08-16.md`, `docs/SYSTEM-AUDIT-FINDING-LIFECYCLE.json` | F-042 open → verified | 1 |
| `docs/AUDIT-ACCEPTANCE-MANIFEST-2026-08-13.json` | Repoint gate filters that do not run their finding's tests | 2 |
| `api/database/migrations/0473_drop_workflow_definitions_amount_threshold.php` | **Create.** Remove the unread column | 3 |
| `api/app/Common/Models/WorkflowDefinition.php`, `api/database/seeders/WorkflowSeeder.php` | Drop the column from the model; write the step threshold as a JSON string | 3 |
| ~44 files under `api/app/` | Give every `diffIn*` call an explicit absolute flag | 4 |
| `api/tests/Unit/CarbonDiffSignConventionTest.php` | Extend with the no-bare-diffIn guard | 4 |
| `api/app/Common/Enums/AlertType.php`, `api/app/Common/Services/AlertEngineService.php`, `api/database/migrations/0474_seed_scheduler_stale_alert_settings.php` | Raise an alert when the scheduler goes stale | 5 |
| `CLAUDE.md` | Document the duplicate-migration-number hazard | 6 |

---

### Task 1: Finish F-042 — declare the env keys the suite depends on

**Files:**
- Modify: `api/phpunit.xml`
- Modify: `docs/SYSTEM-AUDIT-FINDINGS-2026-08-16.md`, `docs/SYSTEM-AUDIT-FINDING-LIFECYCLE.json`

**Context.** F-042 is currently `mitigated`, not `verified`. `force="true"` on `APP_ENV` landed already, so the suite now runs as `app.env=testing`. That made Laravel load `api/.env.testing` when the file exists — and it is **untracked**, so *which* env file loads is machine-dependent. F-042's own Recommended Improvement says the remaining work is to declare, in the tracked `<env>` block, the keys the suite depends on but does not declare. Its Evidence section names exactly eight:

```
COMPANY_CERTIFICATION
COMPANY_SALES_INBOX_EMAIL
COMPANY_EMAIL_BRAND_NAME
COMPANY_LOGO_PATH
ADMIN_NAME
ADMIN_EMAIL
ADMIN_PASSWORD
ACCOUNTING_FUNCTIONAL_CURRENCY_CODE
```

- [ ] **Step 1: Find each key's real consumer and its current fallback**

For each of the eight, `grep -rn "<KEY>" api/app api/database api/config` and record what reads it and what it falls back to when unset. Put the findings in your report. Do not guess a value — derive it from the code's own default, or from `api/.env.example` if that names one.

- [ ] **Step 2: Declare all eight in `api/phpunit.xml`**

Add them to the existing `<php>` block, in the same style as the seven `COMPANY_*` keys already there, each with a brief comment naming its consumer. Values must be test-appropriate and deterministic, matching the in-code default where one exists.

**Do NOT add `force="true"` to any of them.** F-042 is explicit that the attribute belongs on `APP_ENV` and that entry only: `DB_HOST`/`DB_DATABASE`/`DB_PASSWORD` are deliberately non-forced so CI's job env can outrank the file, and forcing them would point the GitHub runner at the Compose hostname `db`, which does not resolve there. The same reasoning applies to any new key CI might need to override.

- [ ] **Step 3: Verify with a full suite run on your own database**

The point of declaring these is that the suite no longer depends on an untracked file. Run the **full** suite (not a filter) in the one-off container. Then prove the independence claim: re-run with `-e APP_ENV=testing` and confirm the same result, since `.env.testing` is absent from the worktree — that is exactly the CI-shaped state.

Report both runs' `Tests:` lines.

- [ ] **Step 4: Flip F-042 to verified**

In `docs/SYSTEM-AUDIT-FINDING-LIFECYCLE.json`, set F-042 `status` to `verified`, `evidence_date` to `2026-08-18`, and extend `verification_scope` and `regression_proof` with what you measured. Preserve the file's compact one-row-per-line formatting.

In `docs/SYSTEM-AUDIT-FINDINGS-2026-08-16.md`, append to F-042's Status note: the eight keys now declared, and that the Ideal Process is met because the suite's configuration no longer varies with whether an untracked file is present.

Both validators must exit 0.

---

### Task 2: Audit the remaining 28 gate filters for subject fit

**Files:**
- Modify: `docs/AUDIT-ACCEPTANCE-MANIFEST-2026-08-13.json`
- Modify: `docs/SYSTEM-AUDIT-FINDINGS-2026-08-16.md`, `docs/SYSTEM-AUDIT-FINDING-LIFECYCLE.json` (only if you extend F-046)

**Context.** F-046 fixed five `focused_test` gate filters and added a static resolver proving every filter now matches *something*. It explicitly did NOT check whether a resolving filter runs the *right* tests. That distinction mattered: `--filter=BIR` resolved to 22 passing tests — invoice VAT assertions plus a calendar test matching `bir` inside `birthdays` — while running **none** of `EffectivenessDatedBracketsTest`, the class covering the finding it certified. `--filter` is a case-insensitive regex over `Namespace\Class::method`, so a wrong-but-plausible string is likely to match something unrelated rather than fail.

F-046's `verification_scope` records the limit: "Does NOT assess whether a resolving gate's tests are the RIGHT tests beyond the five corrected here."

- [ ] **Step 1: For every `focused_test` gate, list what it actually runs**

There are 34. Five (F-006, F-009, F-015, F-037, F-038) were corrected under F-046 — verify them but expect them to pass. One (F-031) is the SPA gate with no `--filter` — skip it.

For each remaining gate, get the selected test names:
```
vendor/bin/phpunit --filter='<VALUE>' --list-tests
```
Record the gate id, its filter, the count, and the class names selected.

- [ ] **Step 2: Judge subject fit against the finding**

For each gate, read its finding's entry in `docs/SYSTEM-AUDIT-FINDINGS-2026-08-13.md` (or the 2026-08-16 register) — specifically its **Evidence** line, which usually names the covering tests and their counts. A gate fits when the selected tests include the classes that cover the finding's control. Flag a gate as **misfitting** when either holds:
- it selects **none** of the classes its Evidence names, or
- its selected count is wildly larger than the Evidence claims *and* the extra classes are unrelated to the finding's subject (the F-038 shape).

A gate that selects a superset including the right classes is FINE — do not churn it.

- [ ] **Step 3: Repoint only the misfitting gates**

For each, choose a filter that selects the classes the Evidence names, verify it exits 0, and record before/after counts. Prefer naming classes over broad substrings. Quote alternations with single quotes: `--filter='Foo|Bar'`.

- [ ] **Step 4: Record the result**

If you repointed any gate, extend F-046 in both `docs/SYSTEM-AUDIT-FINDINGS-2026-08-16.md` and its lifecycle `regression_proof` with what you changed and the measured counts, and narrow its `verification_scope` — it currently disclaims exactly this audit, and that disclaimer must now reflect what you did check.

If every remaining gate fits, do NOT invent a change. Say so in your report, and add one sentence to F-046's Status note recording that the remaining 28 were audited for subject fit and found sound — a measured negative result is the deliverable.

Both validators must exit 0.

---

### Task 3: Remove the threshold column nothing reads

**Files:**
- Create: `api/database/migrations/0473_drop_workflow_definitions_amount_threshold.php`
- Modify: `api/app/Common/Models/WorkflowDefinition.php`, `api/database/seeders/WorkflowSeeder.php`
- Test: `api/tests/Feature/Approvals/ApprovalThresholdBoundaryTest.php` (extend)

**Context.** `workflow_definitions.amount_threshold` is a real `decimal(15,2)` column (`0009_create_workflow_definitions_table.php:18`), is in `WorkflowDefinition::$fillable` and cast `decimal:2` (`:11`, `:15`), and is **seeded to 50000.00** on two rows (`WorkflowSeeder.php:60,71`) — and is read by **nothing** in `api/app/`. The live gate is the per-step `threshold` key inside the `steps` JSON, which `ApprovalService::submit()` reads. Two mechanisms, same value, one reader; the column looks authoritative and is not.

Separately, `WorkflowSeeder.php:65,75` writes that step threshold as a PHP float `50000.00`, which `json_encode`s to `50000`. `ApprovalService`'s docblock asks for a JSON **string** (`"50000.00"`), because a JSON number carrying centavos is decoded to a PHP float before the service sees it. Harmless at `50000` only because it is binary-exact.

- [ ] **Step 1: Re-verify the column has no reader before removing it**

`grep -rn 'amount_threshold' api/app api/database api/tests`. Expected: the migration, the model's `$fillable`/`$casts`, the seeder, and comments. If you find a **read** — anything that selects, filters or compares it — STOP and report BLOCKED with the location. Removing a column something reads is destructive.

- [ ] **Step 2: Write the failing test for the seeder's JSON type**

Extend `api/tests/Feature/Approvals/ApprovalThresholdBoundaryTest.php` with a test that runs `WorkflowSeeder` and asserts the shipped step threshold is stored as a JSON **string**, not a number:

```php
public function test_the_seeded_step_threshold_is_stored_as_a_json_string(): void
{
    $this->seed(\Database\Seeders\WorkflowSeeder::class);

    $steps = \App\Common\Models\WorkflowDefinition::query()
        ->where('workflow_type', 'purchase_request')
        ->value('steps');

    $vp = collect($steps)->firstWhere('role', 'system_admin');

    $this->assertIsString($vp['threshold'], 'a JSON number carrying centavos decodes to a float upstream of ApprovalService');
    $this->assertSame('50000.00', $vp['threshold']);
}
```

Run it and confirm it fails on the type assertion — that is the RED you need before changing the seeder.

- [ ] **Step 3: Make it pass**

Change `WorkflowSeeder.php:65,75` to `'threshold' => '50000.00'` (a string). Leave the numeric comparison semantics alone — `ApprovalService` already casts to string and compares with `Money::lt`.

- [ ] **Step 4: Drop the column**

Migration `0473_drop_workflow_definitions_amount_threshold.php`:
- `up()`: drop `amount_threshold` from `workflow_definitions`.
- `down()`: re-add it as `->decimal('amount_threshold', 15, 2)->nullable()`. Do NOT attempt to restore the seeded values in `down()` — they were never read, so reconstructing them would invent data.

Remove `amount_threshold` from the model's `$fillable` and `$casts`, and remove it from `WorkflowSeeder`'s `updateOrCreate` payload (`:179`) and from the two workflow definition arrays (`:60`, `:71`).

- [ ] **Step 5: Verify**

`--filter='Approval|Workflow'` must pass, and the migration must run and roll back cleanly on your own database (`migrate` then `migrate:rollback --step=1` then `migrate`). Report all three.

---

### Task 4: Give every `diffIn*` call an explicit absolute flag

**Files:**
- Modify: ~44 files under `api/app/`
- Modify: `api/tests/Unit/CarbonDiffSignConventionTest.php`

**Context.** Carbon 2 returned `diffIn*` as an absolute int; Carbon 3 (`nesbot/carbon` 3.13.1, via Laravel 12) returns it **signed** as `argument − receiver`, and as a float. Four bugs came from that one change — `PunchSessionizer` collapsing a whole punch file into one attendance day, every MRP work order coming out urgent, MTBF pinned at 0 and availability at null, and the critical AR-overdue alert being unable to fire. None threw; all four produced quietly wrong business output.

A static guard was prototyped during that work and **rejected as undecidable**: whether a call is safe depends on which operand is the earlier instant, which is semantic. Scanning for a diff compared inline flags 5 sites and all 5 are correct. The broader rule — no bare `diffIn*` — covers ~44 sites, which is why it was rejected then: an allowlist that size is churn.

This task removes that objection by **normalising the 44 sites** so the broad rule needs no allowlist. Once every call states its intent explicitly, "no bare `diffIn*`" becomes enforceable.

- [ ] **Step 1: Enumerate the sites**

```
grep -rnE 'diffIn[A-Za-z]+\(' api/app --include=*.php
```
Classify each as already-explicit (passes a second argument, or is wrapped in `abs(`) or bare. Only bare sites change. Put the full list in your report with its classification.

- [ ] **Step 2: Normalise each bare site — preserving behaviour exactly**

This is the delicate part. For each bare call, decide what it currently *means* and keep that meaning:

- If the receiver is unambiguously the earlier instant (`$created_at->diffInHours(now())`, `$from->diffInDays($to)`, or a call guarded by a preceding comparison), the current signed result is already positive. Make it explicit with `, true` — an absolute magnitude — only if that cannot change the value. Where the receiver could be later in some code path, `, true` is a **behaviour change**; prefer swapping the operands so the receiver is the earlier instant, and say so in your report.
- If the result is used as a signed quantity on purpose (a countdown that may go negative), pass `, false` and leave the arithmetic alone.
- Do NOT "fix" any site's logic in this task. If you find a site whose sign looks wrong — a fifth bug — do NOT change its behaviour. Record it in your report as a suspected defect with the file:line and your reasoning, and leave it bare. A behaviour fix needs its own test and its own review, which this task does not provide.

Cast to `(int)` only where the surrounding code already did.

- [ ] **Step 3: Add the guard**

Extend `api/tests/Unit/CarbonDiffSignConventionTest.php` with a test that scans `api/app` and fails on any `diffIn*` call that neither passes a second argument nor sits inside `abs(`. Give it a clear failure message naming the file, line and the offending line's text, and a docblock explaining why explicitness is required here. If you had to leave any site bare under Step 2's last bullet, the guard must carry a short, commented allowlist naming those files with the reason — an allowlist of suspected defects, not of style exceptions.

- [ ] **Step 4: Verify**

The guard must pass. Then run the suites covering the touched modules — at minimum `--filter='Attendance|DTR|Overtime|Punch|Payroll|Mrp|MRP|Maintenance|Downtime|Alert|Approval|Dashboard|Quality'` — and report the counts. Any behaviour change you made deliberately under Step 2 must be named in your report with the reasoning.

---

### Task 5: Alert when the scheduler goes stale

**Files:**
- Modify: `api/app/Common/Enums/AlertType.php`, `api/app/Common/Services/AlertEngineService.php`
- Create: `api/database/migrations/0474_seed_scheduler_stale_alert_settings.php`
- Test: `api/tests/Feature/Alerts/SchedulerStaleAlertTest.php` (create)

**Context.** Thirteen cron entries carry this system — `mrp:run-daily`, `alerts:run`, `payroll:auto-create-period`, `ncr:escalate` and others. If the scheduler container dies, every one of them silently stops and nothing says so. `App\Common\Services\SchedulerExecutionLedger::health(int $staleMinutes = 15)` already computes staleness and already distinguishes the cases (no tick ever recorded; the latest tick still `running` past the threshold; the latest tick finished longer ago than the threshold; a gap between consecutive ticks). The gap is only that nothing consumes it.

`AlertEngineService::runAllChecks()` is itself driven by `alerts:run` every 15 minutes, and its `raise()` is the single entry point for alerts. Note the honest limitation and record it: an alert raised by the scheduler cannot fire when the scheduler is completely dead, because the thing that would raise it is also scheduled. This check catches a *stalled or partially failing* scheduler, and a dead one is caught only once it restarts. State that plainly in the code comment and the finding — do not present it as full dead-man coverage.

- [ ] **Step 1: Read `SchedulerExecutionLedger::health()` and use its real shape**

Read the method before writing anything and use the array keys it actually returns. Do not assume its shape from this brief.

- [ ] **Step 2: Write the failing test**

`api/tests/Feature/Alerts/SchedulerStaleAlertTest.php`, following the conventions in `api/tests/Feature/Alerts/ArOverdueAlertBandTest.php`. Two cases:
- a ledger whose latest tick finished well past the threshold → `runAllChecks()` raises one alert of the new type at `AlertSeverity::Critical`
- a ledger ticking normally → no such alert is raised

Confirm both fail for the right reason before implementing — the first because the type does not exist yet.

- [ ] **Step 3: Implement**

Add one case to `AlertType` (e.g. `SchedulerStale = 'scheduler_stale'`), following the enum's existing style. Add a `checkScheduler()` to `AlertEngineService`, registered in `runAllChecks()`'s array alongside `inventory`/`production`/`finance`/`quality` so it inherits the `safe()` wrapper. Read the threshold from settings via the service's existing `positiveIntSetting()` helper.

Migration `0474_seed_scheduler_stale_alert_settings.php` seeds the threshold setting, following the shape of `0290_seed_alert_approval_and_quality_policy_settings.php` exactly, including a `down()` that deletes the key it added. Default the threshold to `30` minutes — `alerts:run` fires every 15, so 30 gives one missed tick of slack before alerting.

- [ ] **Step 4: Register the finding**

Add a new finding to `docs/SYSTEM-AUDIT-FINDINGS-2026-08-16.md` as `### F-047`, following the field-by-field shape of the F-046 entry immediately above it. Add its lifecycle row and its acceptance gate, and raise the manifest validator's explicit gate count from 46 to 47 — the count is deliberately explicit so growing the registry stays a reviewable edit.

Its Impact must state the limitation from the Context above rather than overclaiming.

- [ ] **Step 5: Verify**

The new test passes, `--filter='Alert|Scheduler'` passes, the migration runs and rolls back cleanly, and both validators exit 0.

---

### Task 6: Document the duplicate-migration-number hazard

**Files:**
- Modify: `CLAUDE.md`

**Context.** Four migration numbers are used twice: `0198` (`add_label_description_to_settings`, `create_accounting_periods_table`), `0427` (`seed_company_coordinates`, `seed_payroll_compute_stale_threshold`), `0442` (`seed_maintenance_dashboard_window_setting`, `update_company_plant_location_settings`), `0450` (`add_render_kind_to_dashboard_widgets`, `rename_edge_system_user_settings`). All four pairs are **pre-existing on `origin/main`** — the same set is present there, so this is not new breakage.

**Renaming them would be destructive and is explicitly out of scope.** Laravel's migrator records the filename (without `.php`) in the `migrations` table and matches on it, so renaming an already-applied migration makes Laravel treat it as new and attempt to re-run it. On a deployed database that re-runs schema operations that have already been applied.

Ordering is unaffected: `Migrator` sorts by filename, so a duplicated numeric prefix still orders deterministically by the rest of the name. The cost is human — two files claiming one sequence number make "highest + 1" ambiguous and invite a third collision.

- [ ] **Step 1: Verify the claim before documenting it**

Re-derive the duplicate list yourself and confirm the four pairs. Confirm they exist on `origin/main` too (`git ls-tree origin/main --name-only api/database/migrations/`). If your list differs from the one above, document what you measured, not this brief.

- [ ] **Step 2: Document it in `CLAUDE.md`**

Extend the existing "Migration numbering" section. State: the four known duplicate prefixes; that they are pre-existing and ordering is unaffected because the migrator sorts by full filename; that they must NOT be renamed, with the `migrations`-table reason; and that "highest + 1" should be derived by checking for an existing prefix first. Match the surrounding style — that file is terse and reason-first.

No code, no test. Verification is that the claim is true and the note is accurate.
