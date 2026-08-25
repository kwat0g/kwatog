# M016 — People / Recruitment & Careers inventory

Audit date: 2026-08-24  
Claim: people / recruitment-careers  
Registry tier: 4  
Surface: L

## Release surface

| Area | Evidence reviewed | Operational role |
|---|---|---|
| Public API | `api/app/Modules/HR/routes.php:312-319`; `PublicRecruitmentController`; public resources | Open-posting discovery, posting detail, resume application, tracking-code status lookup |
| HR API | `api/app/Modules/HR/routes.php:283-309`; recruitment controllers/requests/resources | Posting lifecycle, application pipeline, interviews, notes, resume download, employee-conversion prefill |
| Authorization | `api/app/Modules/HR/routes.php:25-26,283-309`; `StoreJobPostingRequest`; `AdvanceApplicationRequest`; `RolePermissionSeeder.php:359-364,454-469` | Sanctum, HR/recruitment feature gates, view/manage/application/hire permissions |
| Core state | `RecruitmentService`; JobPosting, JobApplication, ApplicationInterview, ApplicationNote models/enums | Posting status, candidate stage, interviews, rejection, notes, conversion link |
| Persistence | migrations `0248`–`0251`; `2026_08_15_100000_add_unique_application_email_per_posting.php` | Posting/application/interview/note storage, soft deletion, unique posting/email constraint |
| Files and mail | `RecruitmentService.php:75-157,204-293`; recruitment mailables/templates; local disk | Private resume storage, confirmation/status/interview email, HR fallback notifications |
| Background operations | `CheckRecruitmentBottlenecks`; `api/routes/console.php:153-158` | Hourly reminders for stalled applications, overdue interviews, and expired postings |
| Cross-module write | `EmployeeController.php:151-169`; `EmployeeService.php:172-255`; `RecruitmentService.php:336-364` | Hired applicant → employee prefill and conversion link; filled-posting calculation |
| SPA | public careers pages; HR recruitment dashboard, posting CRUD/detail, application list/detail; recruitment API/types/routes | Candidate application/tracking and HR pipeline operations |
| Verification | `api/tests/Feature/HR/{PublicRecruitmentTest,RecruitmentApplicationTest,RecruitmentPostingTest,RecruitmentBottleneckCommandTest}.php`; no recruitment-specific E2E spec | Positive API regression coverage; no browser production journey or concurrency harness |

## Dependency and change context

- Registry dependencies are ready for this surface: `employee-master` is Plan Ready and `corporate-site-contact` is Verified. Recruitment also hands off to the employee-create flow, so that boundary was audited explicitly.
- The current branch is `main` at `b269eafd`; the worktree already contains unrelated user-owned changes. No application source file was changed by this audit.
- Recent recruitment work added the public careers pages, the HR pipeline, unique application-email handling, interview mail, bottleneck recovery, and SPA filter/archive conventions. The audit therefore checked the API/UI contract rather than treating the presence of those controls as proof that they are wired end to end.

## Critical workflows checked

1. Public open-posting list/detail → resume upload → duplicate-email protection → tracking code.
2. HR draft → open → closed/filled posting lifecycle, soft-delete/restore route, and public deadline behavior.
3. New → screening → interview → offer → hired/rejected application pipeline.
4. Interview scheduling, rescheduling/outcome update, candidate notifications, and tracking-page display.
5. Hired application → employee creation → `converted_employee_id`/filled-posting handoff.
6. Resume access boundary, HR permissions, feature flags, role grants, queued mail, and hourly bottleneck recovery.
7. SPA list search/sort/page-size controls, archive controls, public tracking, loading/error states, and available regression coverage.

## Verification and evidence limits

- `docker compose run --rm --no-deps api php artisan test ...` over the four focused recruitment classes passed: **22 tests / 63 assertions**.
- `docker compose run --rm --no-deps api php artisan route:list --path=recruitment --json` registered **20** expected public and HR recruitment routes with feature/permission middleware visible.
- PHP lint over recruitment enums, models, mail, resources, controllers, and service passed.
- `npm run typecheck` and targeted ESLint over the recruitment API/pages passed.
- `npm run build` reached TypeScript build setup but was blocked by the pre-existing root-owned `spa/tsconfig.tsbuildinfo` (`EACCES`); no source/type error was emitted.
- A direct host PHP test attempt could not resolve Compose-only PostgreSQL host `db`; the same suite passed inside the Compose network. No live authenticated browser run, production-like dataset, mail-provider delivery, concurrent two-connection test, or restore/employee-conversion rehearsal was available.
