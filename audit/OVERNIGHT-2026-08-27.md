# Overnight session — 2026-08-27

**Goal:** drive the suite to zero failures except items that genuinely require a
human decision. Commit per module. Never run a full suite while agents are live
(that combination OOM-killed the stack on 2026-08-26). Max 4 concurrent agents.

Branch: `audit/2026-08-26-five-modules`. `main` untouched. Nothing pushed.
Recovery snapshot if anything goes wrong: `git checkout wip-snapshot-2026-08-26`.

---

## READ THIS FIRST — 7 decisions only you can make

None of these are blocked on work. Each was deliberately left undecided because
picking wrong moves money, changes who can see what, or trades away an integrity
guarantee. Evidence for each is in the named module's `fix-log.md`.

1. **Audit immutability vs. user deletability.** `audit_logs.user_id` has an
   `ON DELETE SET NULL` FK, and `audit_logs` carries an `audit_logs_prevent_update`
   trigger forbidding any update. So **a user with audit rows cannot be deleted at
   all, in production** — which contradicts CLAUDE.md listing users as
   soft-deletable. Either the FK becomes `RESTRICT` with an explicit "archive,
   never delete" policy, or the trigger gains a narrow exception for the FK
   cascade. Do not let anyone "fix" this by loosening the trigger to make tests
   pass. → `finance/chart-of-accounts-periods`

2. **Customer portal reactivation.** `inviteSupplier()` refuses invitations to
   inactive accounts, safe because suppliers have a `reactivateSupplier` endpoint.
   Customers have none — nothing anywhere deactivates a `CustomerPortalUser`, so
   re-invitation is the only way to re-enable one. Mirroring the guard would
   permanently strand any inactive customer account. Either keep re-invite as the
   reactivation path, or build customer deactivate/reactivate/revoke endpoints
   first. → `commercial/customer-portal`

3. **Scrapped-line credits (money).** The code credits scrapped return lines. The
   test named for *not* crediting them has always posted `return_to_supplier`,
   never `scrap`. The agent's reading is that the code is right — the customer is
   owed the credit and scrapping is our loss — making the `dispose()` docblock the
   wrong part. Nothing was changed either way. → `supply-chain/returns-rma`

4. **AP `payments` visibility.** `AccountsPayableHardeningTest` asserts the
   supplier bill resource exposes no `payments` key, but `invoiceDetail()`
   eager-loads payments so it is populated live. Fields are
   date/amount/method/reference/status, no journal or GL, and suppliers already
   see `amount_paid`/`balance`. Partly a test artifact, partly a real
   supplier-visibility call. → `supply-chain/supplier-portal`

5. **Routing rollback blocked by retired molds.** `activate()` re-validates a
   stored routing, so rollback to a historical version is refused if its mold has
   since been retired. Correct for IATF traceability, but it means an emergency
   rollback can be blocked by unrelated master-data state.
   → `manufacturing/production-routings`

6. **Complaint cancellation under IATF retention.** `ComplaintController`
   publishes `ComplaintStatus::cases()` verbatim, so the UI offers a selectable
   **Cancelled** filter that can only ever return zero rows, while the escalation
   service treats the status as load-bearing. Is cancelling a complaint permitted
   under IATF 16949 retention, and if so may it happen once an NCR exists?
   → `commercial/customer-complaints-8d`

7. **Raw-punch import reachability.** The service and `PunchSessionizer` are
   complete and tested but unreachable — no route, no UI. The deciding evidence is
   what the FCIE Dasmariñas terminals actually export; if they emit raw punches,
   exposing it is required for real hardware. → `people/attendance-dtr`

8. **Training re-completion — two committed tests demand opposite behaviour.**
   `EmployeeTrainingStateMachine:37-39` short-circuits `$current === $target` to a
   silent return, so re-completing a completed training rewrites `completed_at`,
   recomputes `expires_at`, clears `last_alert_*` and can swap the certificate —
   with a 200. Removing that makes a *different* committed test fail:
   - `EmployeeTrainingExpiresAtTest:67-89` (`d035e062`, 2026-06-15, a deliberate
     `test(t3.4.b)`) requires re-completion to **succeed** — it *is* the retake path.
   - `EmployeeTrainingAssignTest:143-171` (`167de85e`, the "NOT REVIEWED" batch)
     requires **422**.

   The newer test and the state machine landed in the same unreviewed commit, and
   the state machine cannot satisfy its own new test — **it was committed red.**
   This is a recertification policy question (HR / IATF 16949): may a completed
   training be re-completed, and if so is early renewal allowed? Three costed
   options are in the module's `fix-log.md`. The fix was implemented, verified,
   and reverted; only a BLOCKED-ON-DECISION comment remains so nobody fixes it in
   isolation. → `people/employee-master`

   Related, not blocking: the same blanket `$current === $target` no-op exists in
   `ComplaintService`, `ReturnRequestStateMachine`, `LoanStateMachine` and
   `InspectionStateMachine`. Whatever you decide here probably applies to those.

9. **Dept-head PR auto-approve reads a column that does not exist.**
   `PurchaseRequestService.php:296` reads `$requester->employee->is_department_head`.
   That column is **not in the `employees` table** — I confirmed against the live
   schema (0 rows in `information_schema`), and that line is its only reference
   anywhere in `api/app` or `api/database`. Eloquent returns null for a missing
   attribute rather than throwing, so the auto-approve branch at `:298-321` is
   simply unreachable dead code — while `approval.pr.dept_head_auto_approve_threshold`
   is seeded live at **₱5,000**, is admin-editable, and `:223` still documents the
   feature. An operator can tune a threshold that does nothing.

   It is also a booby trap: that branch approves **every step as the requester**,
   and `PurchaseRequest` has no `approvalSubmitterId()` override — so simply adding
   the column would immediately raise the segregation-of-duties refusal from
   *inside* `submit()`, rolling the submission back with a 403. Options:
   (a) keep it, which needs the column **plus** an explicit self-approval design
   (an SoD-exempt system actor, or model it as a threshold *skip* — the mechanism
   `submit($…, $total)` already has); or (b) drop it: delete the branch, the
   settings row, and the validator entry. Zero test coverage either way.
   → `platform/approval-workflows`

10. **The local suite and CI disagree about `APP_ENV`, and the comment saying
    otherwise is wrong.** `config('app.env')` resolves to **`local`**, not
    `testing`, when the suite runs here. `api/phpunit.xml`'s
    `<env name="APP_ENV" value="testing" force="true"/>` writes `$_ENV` and
    `putenv()` but **never `$_SERVER`**, and phpdotenv's `ServerConstAdapter` reads
    `$_SERVER` first — where `docker-compose.yml:12` has already put
    `APP_ENV=local`. Measured inside PHPUnit: `$_SERVER=local`, `$_ENV=testing`,
    `getenv=testing`, resolved config **`local`**.

    Consequence: all **5** `app()->environment('testing')` branches are dead
    locally and live in CI — `HasHashId::resolveRouteBinding`,
    `resolveSoftDeletableRouteBinding`, `ActivityFeedService`,
    `AuditLogController::decodePublicId`, and `LogSlowQueries`. So CI and local
    disagree about identifier handling on **every hashid route**, and a test can
    pass in one and fail in the other. Three entity-trail tests were doing exactly
    that.

    **`api/phpunit.xml:40-56` documents this under "finding F-042" and asserts
    `force="true"` resolves it. It does not.** A comment asserting a fix that does
    not work is worse than no comment, because it stops the next person checking.

    Not fixed autonomously: it is a shared file with ~2400-test blast radius, and
    changing it flips which `.env` file Laravel loads. Your call whether the right
    move is to set `APP_ENV=testing` in the compose api service, drop the
    `$_SERVER` value, or delete the five escape hatches and make the tests use
    hashids everywhere (my preference — the hatches exist only to let tests skip
    encoding, and hiding a production code path behind an env check is what let
    this go unnoticed). → `platform/audit-activity`

11. **P0 — a balance sheet dated after its fiscal year is imbalanced. MEASURED, not
    inferred.** An agent posted one legitimate FY2025 cash sale of ₱10,000 and asked
    for two balance sheets:
    - `2025-12-31` → assets 10000.00, L+E 10000.00, balanced **true**
    - `2026-04-30` → assets 10000.00, L+E **0.00**, balanced **false**

    `Services/Statements/BalanceSheetService.php` sums assets/liabilities/equity from
    inception but adds net income for the **current fiscal year only**, so prior-year
    profit sits in neither retained earnings nor current-year income.
    `accounting/periods` offers **monthly** close only — there is no annual close and
    no retained-earnings roll. Options: (a) a controlled annual close into retained
    earnings, or (b) derive the cumulative closed-period result at read time. Both
    alter a reported figure, which is why it was not decided.
    → `finance/financial-statements`

12. **Every machine-generated journal entry loses its maker.**
    `JournalEntryService.php:139`:
    ```php
    'created_by' => empty($data['reference_type']) ? $user?->id : null
    ```
    An actor **is** supplied — payroll derives it from `payroll_periods.finalized_by`
    rather than `Auth::id()`, so it works on queued paths too — and this line discards
    it for any entry carrying a `reference_type`. `posted_by` and the `audit_logs` row
    still land, so the gap is specifically `created_by`. `git log -S` dates the line to
    `167de85e`, the batch commit of ~50 crashed sessions; the test and the actor
    derivation both predate it.

    **It cannot be naively reverted.** `assertNotSelfPosting()` keys
    segregation-of-duties on `created_by === $by->id`, and five writers pass the same
    `$by` to `create()` then `post()` — at the default `je_self_post_limit = 0` they
    would all start returning 403 for non-admins. The sharpening fact: payroll uses
    `postSystem()`, which never consults `created_by`, so recording payroll's maker
    provably cannot trip that guard. Line 139 keys on `reference_type` when what
    matters is the **posting path**. Any fix must live in `create()`, since
    `2026_08_25_100000_harden_journal_immutability` installs triggers rejecting
    post-hoc mutation. Three options are costed in the module's fix-log.
    `PayrollMoneyFindingsRegressionTest` is left **red on purpose** — it is a true
    statement about production. → `people/payslip-statutory-disbursement`

13. **IATF Chain 2 control is half-closed: `coa_verified` can never become true.**
    I verified this independently. The only writes to `grn_items.coa_verified` in
    `api/app` are two hard-coded `false` literals (`GrnService.php:240`, `:437`), and
    the Quality side never touches it either. Yet it is published at
    `GrnItemResource.php:38` and the SPA renders
    `spa/src/pages/inventory/grn/detail.tsx:410` as "Verified by Quality" or
    "Pending Quality verification" — so that label can only ever read **Pending,
    permanently**.

    The receiving-side guard is correct and should stay: goods-receiving's own finding
    GRN-07 classifies receiver self-certification as broken, and
    `moisture_percentage`, `coa_document_path` and `material_lot_number` all still
    persist from receiving — only the *verdict* is refused. But nobody, **including
    QC**, can record that a supplier's certificate of analysis was actually checked.
    For a resin cert on an IATF-controlled incoming material, that is a control that
    looks present and is not. Three options are written up: model COA as an inspection
    spec parameter, add a dedicated `quality.coa.verify` transition, or drop the flag
    from the contract. Decides who may certify supplier material quality.
    → `quality/calibration-quality-analytics` + `inventory/goods-receiving`

14. **The MRP planning run mutates frozen cost snapshots.** M02's planning-time recost
    (`BomCostingService.php:85`) rewrites five money columns, `cost_basis`, `costed_at`
    and every `BomItem.unit_cost`/`extended_cost` from inside a planning run. That
    directly contradicts the unresolved mutable-vs-immutable costing question the
    module's own `audit-report.md:123-125` raises. Whether a plan may retroactively
    change the costs a previous plan was built on is a business call.
    → `manufacturing/bom-mrp-planning`

    Also confirmed and unfixed there: the **MRP cancellation race**.
    `runForActiveSalesOrders()` snapshots candidates outside any transaction and
    `runForSalesOrder()` locks the prior plan but never re-reads `$so->status`, while
    `SalesOrderService::cancel()` correctly re-reads under lock *and cleans up* MRP
    artifacts. So a run committing after that cleanup writes a fresh Active plan,
    draft auto-PRs and planned work orders against a cancelled order that nothing
    will collect. The mechanical fix is small; the decision is what the run then
    *reports* (silent skip / failed-SO, which makes routine cancellations render as
    `partial` and trips `rerun()`'s throw / a new skipped counter).

Also queued behind you, not a decision but only you can do it:
**`sudo chown -R $USER spa/node_modules spa/test-results`** (or `rm -rf
spa/node_modules/.vite-temp spa/test-results`). Root ownership there blocks BOTH
Vitest and Playwright. Two separate sessions misdiagnosed it as "the compose
image has no Chromium" — Chromium is present on the host; Vite simply cannot
write its config temp file, so the dev server never boots.

---

## The one defect that caused most of today

`2026_08_25_210000_enforce_one_active_holiday_per_date` ran
`DROP INDEX IF EXISTS holidays_date_name_unique`, but `0023_create_holidays_table`
backs that name with a UNIQUE **constraint**, which PostgreSQL refuses to drop
that way (`SQLSTATE 2BP01`). That single line failed `migrate:fresh` **repo-wide**.

Consequence: ~50 audit sessions wrote complete `fix-log.md` files, committed their
code, and **never executed a single line of it**. Every module resumed on
2026-08-26 was in that state. Their "fixes" were source-only, and running them is
what exposed the real bugs:

- a credit note paying for 10 units when 8 came back (money)
- every purchase request stalled at approval step 2, forever
- a restore path that had never once worked
- a filename regex that made **every** delivery-proof download a 500
- 8D SLA escalation that could never fire, behind a cron reporting SUCCESS every
  15 minutes for a day
- a customer portal account that could be silently taken over by another customer

Fixed in `c7b2c483`. The lesson is recorded in CLAUDE.md.

---

## Process changes made (so this cannot recur)

- `audit/scripts/02-module-session.md` gained **Step 7b: commit before releasing**,
  incremental `fix-log.md` writing, orphan-lock semantics, per-session test
  databases, and the fact that `regenerate-registry.sh` is not parallel-safe. Its
  `/audit/scripts/...` paths were also wrong — no such filesystem root — so every
  command in it failed instantly as written.
- CLAUDE.md: migration max corrected (was 0474, actually 0478) with instructions
  to verify rather than trust it; the `0NNN_` vs `2026_*` ordering trap documented
  (numbered files always run first, and guards silently no-op); and a warning that
  `catch (Throwable) → Log::*` around both work *and* its failure-recorder makes a
  dead subsystem look healthy.
- `audit/domains/*/*/.lock/` is now gitignored — 88 lock files had been committed
  by accident, so a fresh checkout would have resurrected 44 stale locks.

---

## Status log (appended as the night proceeds)
