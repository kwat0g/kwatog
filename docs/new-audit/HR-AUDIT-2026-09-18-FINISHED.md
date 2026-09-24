# HR Audit - FINISHED

Date: 2026-09-18
Remediation completed: 2026-09-23
Current verdict: **FINISHED for the audited HR scope.**

The original report mixed pre-remediation findings with current findings. This
file is the reconciled result. Every numbered item is either fixed and covered,
shown to be a stale/false finding, or explicitly dispositioned as a business
policy decision.

## Completed Controls

1. Profile-update payloads use `EncryptedArrayCast`; review-list reads use
   `DepartmentScope`; direct bank edits are prohibited; review mutations repeat
   row authorization; submitted fields are validated; and bank numbers are
   redacted in review resources unless the caller has the sensitive or Finance
   permission.
2. Employee archive/restore is transactional. Account deactivation caused by
   archive is recorded and only that account state is re-enabled on restore.
   Existing archived rows are backfilled by migration `0523`.
3. Self-service home payslips use `PayrollPublicationPolicy`, so errored
   finalized payroll rows are not advertised.
4. Employee document soft-delete retains the private file, making restore and
   download reversible.
5. Employee updates recompute onboarding and employee creation records the
   initial effective salary basis in `employee_salary_history`.
6. Employee-number unique races map to validation errors. Initial shift
   assignments are idempotent and have a database uniqueness backstop.
7. Sensitive resource fields use the same system-admin/sensitive-permission
   decision. `Employee.status` is no longer mass-assignable.
8. Generic employee lifecycle transitions cannot enter terminal states.
   Finalized clearance uses the explicit `transitionAfterClearance()` boundary.
9. Onboarding reminders exclude `system_admin`, including when that role is
   present in a configured audience.
10. Missing provisioning roles produce a terminal configuration outcome;
    queued provisioning jobs have bounded retries and backoff.
11. Training expiry chooses the newest completion by `completed_at`, with ID as
    a tie-breaker. Assigned training catalog rows cannot be archived.
12. Revived employee skills clear stale certification evidence when no new
    certificate is supplied.
13. Skill and training `active=false` filters use real boolean parsing.
14. Position sort direction is normalized. The existing migration
    `0490_guard_active_position_titles` supplies the database uniqueness guard.
15. Recruitment conversion locks the employee and prevents one employee from
    being linked to multiple applications. Migration `0523` adds the unique
    database backstop.
16. Salary adjustment requests serialize on the employee, reject multiple
    pending adjustments, and record the actual final approver in employment
    history.
17. Scope-cut dead property and directory clients/pages/controllers/services
    were removed. Employee resources no longer eager-load or expose the dead
    property projection.

## Finding Dispositions

| Finding | Disposition |
|---:|---|
| 1 | Fixed. The original plaintext/company-wide wording was stale after the encryption and list-scope pass; the residual bank-write, mutation-scope, validation, and response-redaction gaps are closed. |
| 2 | Fixed. Restore now repairs only archive-caused account deactivation. |
| 3 | Closed as stale. `DepartmentScope` documents that a null department permission intentionally means department-plus-self visibility; a same-department competence-management regression test now covers it. |
| 4 | Fixed. Home payslips share the canonical publication predicate. |
| 5 | Fixed. Soft-delete no longer destroys the file. |
| 6 | Fixed. Employee updates recompute onboarding and creation records the initial salary basis. |
| 7 | Fixed. Unique employee-number errors are mapped and initial shift insertion is idempotent/database-backed. |
| 8 | Fixed. Sensitive visibility tiers are aligned. |
| 9 | Fixed. `status` is removed from `$fillable`; lifecycle services use explicit writes. |
| 10 | Fixed. Terminal state entry is clearance-only. |
| 11 | Fixed. System-admin is excluded from onboarding reminders. |
| 12 | Fixed. Provisioning configuration failures are terminal and queue retries are bounded. |
| 13 | Fixed. Completion chronology, not row ID alone, determines the current certificate. |
| 14 | Fixed. Training deletion now protects assignment history. |
| 15 | Closed as false/stale wording. Laravel `mimes:` inspects file content to infer MIME; it is not extension-only validation. Existing private-storage and MIME-recording controls remain. |
| 16 | Fixed. Reassignment clears stale certification metadata and removes obsolete evidence after commit. |
| 17 | Fixed. Query-string booleans are parsed with `filter_var`. |
| 18 | Fixed. Sort direction is normalized; the active-title unique index already existed and is retained. |
| 19 | Dispositioned as a workflow policy. A department head must belong to the target department, and the employee/position foreign-key model makes automatic one-call reassignment unsafe. The API returns a clear validation error; create the department first, then assign the head through the employee workflow. |
| 20 | Closed as intentional permission design. Department lookup access is deliberately narrower than the full department-management page, and the training namespaces are catalog versus employee-record permissions. |
| 21 | Fixed. Import position resolution uses the same case-insensitive title semantics as the position service and database guard. |
| 22 | Fixed. Pending salary adjustments serialize and final approval attribution is now correct. |
| 23 | Dispositioned as intentional self-service policy. A session employee may issue their own salary-bearing COE; the endpoint resolves only that employee and has no cross-employee access path. |

## Verification

- Focused HR remediation suites: **47 passed, 164 assertions**.
- Full HR feature directory: **236 passed, 896 assertions, 1 unrelated failure**
  in `SelfServiceOvertimeLifecycleTest` (a rejected overtime request was
  returned as pending; outside this audit's changed paths).
- SPA TypeScript check: **passed** with `npm run typecheck`.
- PHP syntax checks: **passed** for all changed HR PHP files and migration.
- Migration `0523_harden_hr_archive_restore_invariants.php` ran successfully
  through the isolated PostgreSQL test database.
- The one full-suite failure is outside the findings remediated here and was
  not reverted.

## Residual Boundary

The COE salary behavior and one-call department-head assignment are explicit
policy choices, not hidden defects. Future changes to either policy must add a
new finding and regression contract rather than silently changing this audit's
disposition.
