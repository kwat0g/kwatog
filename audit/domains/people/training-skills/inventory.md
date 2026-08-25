# M017 — People / Training & Skills inventory

Audit date: 2026-08-24  
Claim: people / training-skills  
Registry tier: 4  
Surface: M

## Release surface

| Area | Evidence reviewed | Operational role |
|---|---|---|
| Training catalog | `api/app/Modules/HR/routes.php:172-187`; TrainingController/Service/Requests/Resource | Create, edit, archive, restore, filter, and display training definitions |
| Employee training records | `api/app/Modules/HR/routes.php:158-166`; EmployeeTrainingController/Service/Requests/Resource | Assign, list, complete, cancel, calculate expiry, and retain certificate metadata |
| Skills catalog and assignments | `api/app/Modules/HR/routes.php:189-224`; SkillController/Service and EmployeeSkillController/Service | Maintain competence definitions, assign proficiency, revoke, restore, and expose employee skill records |
| Matrix and gap analysis | `api/app/Modules/HR/Controllers/TrainingMatrixController.php:19-111`; `EmployeeSkillService.php:69-161` | Employee × skill heatmap, summary counts, department filtering, and gap lookup |
| Authorization and row scope | `api/app/Modules/HR/routes.php:159-224`; `api/database/seeders/RolePermissionSeeder.php:81-85,454-492,670-688`; `api/app/Common/Support/DepartmentScope.php:10-110` | Permission gates, HR-wide access, department-head visibility, and employee/self-service boundaries |
| Persistence and lifecycle | migrations `0190`, `0191`, `0232`, `0444`, `2026_08_13_220000_add_remaining_lifecycle_status_checks`; model casts/traits | Foreign keys, uniqueness, soft deletion, status/alert enums, and audit rows |
| Background operations | `api/app/Console/Commands/CheckTrainingExpiries.php:10-29`; `api/routes/console.php:266-272`; TrainingExpiryService | Daily 30/14/7/expired reminders and status transition to expired |
| Self-service handoff | `api/app/Modules/HR/routes.php:240-264`; `SelfServiceController.php:513-531`; `SelfServiceTrainingsTest.php` | Read-only records for the session employee; consumer belongs to the employee-self-service dependency |
| SPA | `spa/src/routes/hrRoutes.tsx:189-209`; training catalog/matrix pages; employee detail Trainings and Skills tabs; HR API clients/types | HR catalog, matrix, employee assignment, completion/cancellation, and skill management journeys |
| Verification | focused HR feature tests, route registration, PHP lint, SPA typecheck/lint/build | Positive API regression, route wiring, static validation, and build evidence |

## Dependency and change context

- The registry was refreshed before claim. M014 `employee-master` is Plan Ready, so M017’s declared dependency is available for audit. The self-service read endpoint hands off to M024 `employee-self-service`, which is not yet audited.
- The claim was atomic: `./audit/scripts/claim-module.sh people training-skills` created the M017 lock. No application source file was changed by this audit.
- The branch is `main` at `b269eafd` (`feat(backup): add admin backup and restore center`). The worktree contains unrelated user-owned changes in backup, notifications, landing, Docker, and documentation files; they were preserved.

## Critical workflows checked

1. Training catalog create/update/archive/restore and active/inactive visibility.
2. Employee assignment → scheduled → completion/cancellation → validity-based expiry.
3. Employee detail training and skill list/assign/remove actions.
4. Primary training matrix filtering, heatmap status, and summary counts.
5. Secondary skills matrix/gap-analysis API contracts and expiry semantics.
6. Daily expiry scheduler, tier escalation, recipient resolution, and idempotency marker.
7. Department-head versus HR/admin row visibility and hash-ID route binding.
8. Self-service training record scoping and the SPA consumer boundary.
9. Empty/loading/error states, archive controls, form validation, icon actions, and matrix accessibility labels.

## Verification and evidence limits

- `docker compose run --rm --no-deps api php artisan test --filter='(TrainingCatalogTest|EmployeeTrainingAssignTest|TrainingMatrixTest|TrainingExpiryAlertTest|EmployeeTrainingExpiresAtTest)'` passed: **25 tests / 89 assertions**.
- `docker compose run --rm --no-deps api php artisan test tests/Feature/HR/SelfServiceTrainingsTest.php` passed: **2 tests / 6 assertions**.
- Route registration inside Compose passed; the HR route table contains the training, skills, employee subresource, expiry-related self-service, and restore endpoints. The source route definitions were reviewed for middleware and binding behavior.
- PHP lint passed for the M017 controllers, services, command, and related resources.
- `npm run typecheck` and full SPA ESLint passed.
- `npm run build` was blocked before Vite by the pre-existing root-owned `spa/tsconfig.tsbuildinfo` (`EACCES`); no TypeScript source error was emitted.
- A direct host PHPUnit invocation could not resolve the Compose-only PostgreSQL hostname `db`; the same tests passed inside the Compose network.
- No authenticated browser smoke, production-sized dataset, two-connection race test, inactive-catalog mutation test, soft-delete re-assignment test, alert-recipient failure test, or live notification delivery rehearsal was available.
