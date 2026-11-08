import { test, expect } from './fixtures';
import { actorPage, loadActors } from './actor-session';
import type { E2EActor } from './actor-session';
import { createJourneyContext, runProcessStep } from './process-runner';
import { assertAuditActor, assertRecipientsExactly } from './helpers/process-invariants';
import { readOnlyDatabaseJson } from './helpers/database-reader';
import { listMailpitMessages } from './helpers/mailpit-reader';
import { captureAnnotatedStep } from './helpers/annotated-screenshot';

type ActorPage = Awaited<ReturnType<typeof actorPage>>;
type BalanceBaseline = {
  employee_id: number;
  employee_no: string;
  department_id: number;
  employee_name: string;
  balance_id: number | null;
  balance_year: number | null;
  total_credits: string | null;
  used: string | null;
  remaining: string | null;
  leave_request_count: number;
};
type LeaveSnapshot = {
  request_id: number;
  leave_request_no: string;
  status: string;
  days: string;
  start_date: string;
  end_date: string;
  employee_id: number;
  employee_no: string;
  requester_email: string;
  department_id: number;
  leave_type_code: string;
  dept_approver_email: string | null;
  hr_approver_email: string | null;
  cancelled_by_email: string | null;
  cancelled_at: string | null;
  balance_used: string;
  balance_remaining: string;
  on_leave_attendance_count: number;
  request_audit_count: number;
  rejection_reason: string | null;
};
type ApprovalRow = {
  step_order: number;
  role_slug: string;
  action: string;
  approver_email: string | null;
  is_current: boolean;
};
type NotificationRow = { type: string; recipient_email: string; link_to: string | null };
type AuditRow = {
  action: string;
  actor_email: string | null;
  status: string | null;
};

const plannedSteps = [
  { name: 'Employee files a Vacation Leave request', actor: 'employee', uiPath: 'Profile menu → Leaves → New request', expectedStatus: 'pending_dept; balance and attendance unchanged' },
  { name: 'Department Head follows the Leaves badge and approves', actor: 'department_head', uiPath: 'Dashboard → Human Resources menu → Leave Management signals badge → Awaiting my action → Approve', expectedStatus: 'pending_hr; HR receives actionable hand-off' },
  { name: 'HR follows the Leaves badge and approves', actor: 'hr_officer', uiPath: 'Dashboard → Human Resources menu → Leave Management signals badge → Awaiting my action → Approve', expectedStatus: 'approved; balance consumed and attendance marked on_leave' },
];

function actorByName(actors: E2EActor[], name: string): E2EActor {
  const actor = actors.find((candidate) => candidate.actor === name);
  if (!actor) throw new Error(`Required seeded E2E actor is missing: ${name}`);
  return actor;
}

function sqlText(value: string): string {
  return `'${value.replaceAll("'", "''")}'`;
}

async function openSelfServiceLeaves(employee: ActorPage): Promise<void> {
  await employee.page.goto('/dashboard');
  await expect(employee.page.getByRole('navigation', { name: 'Modules' })).toBeVisible();
  await employee.page.getByRole('button', { name: /^Profile menu for / }).click();
  await employee.page.getByRole('menuitem', { name: 'Leaves', exact: true }).click();
  await expect(employee.page).toHaveURL(/\/self-service\/leaves(?:\?|$)/);
  await expect(employee.page.getByRole('heading', { name: 'My Leave Requests', exact: true })).toBeVisible({ timeout: 20_000 });
}

async function openDashboard(actor: ActorPage): Promise<void> {
  await actor.page.goto('/dashboard');
  await expect(actor.page).toHaveURL(/\/dashboard(?:\/|$)/);
  await expect(actor.page.getByRole('navigation', { name: 'Modules' })).toBeVisible();
  await expect(actor.page.getByRole('button', { name: /^Profile menu for / })).toBeVisible();
}

async function openHumanResourcesMenu(actor: ActorPage): Promise<void> {
  const section = actor.page.getByRole('button', { name: /^Human Resources(?:$|:)/ });
  await expect(section).toBeVisible();
  if (await section.getAttribute('aria-expanded') !== 'true') await section.click();
  await expect(section).toHaveAttribute('aria-expanded', 'true');
  const leaveManagement = actor.page.getByRole('link', { name: /^Leave Management/ });
  await expect(leaveManagement).toBeVisible();
  await leaveManagement.hover();
}

async function openLeaveApprovalFromBadge(actor: ActorPage, expectedCount: number): Promise<void> {
  await openHumanResourcesMenu(actor);
  const badge = actor.page.getByRole('button', {
    name: `View all Leave Management signals: ${expectedCount} Leave requests awaiting your approval`,
    exact: true,
  });
  await expect(badge, 'Leave badge should match actionable leave rows').toBeVisible();
  await badge.click();
  const signalDialog = actor.page.getByRole('dialog', { name: 'Leave Management signals' });
  const actionableLink = signalDialog.getByRole('link', {
    name: new RegExp(`^Leave requests awaiting your approval: ${expectedCount}$`),
  });
  await expect(actionableLink).toHaveCount(1);
  await expect(actionableLink).toContainText(String(expectedCount));
  await actionableLink.click();
  await expect(actor.page).toHaveURL(/\/hr\/leaves\?signal=awaiting_my_approval(?:&|$)/);
  await expect(actor.page.getByRole('heading', { name: 'Leave requests', exact: true })).toBeVisible();
}

async function employeeBalanceBaseline(email: string): Promise<BalanceBaseline[]> {
  return readOnlyDatabaseJson<BalanceBaseline[]>(`
    SELECT employee.id AS employee_id,
           employee.employee_no,
           employee.department_id,
           employee.first_name || ' ' || employee.last_name AS employee_name,
           balance.id AS balance_id,
           balance.year AS balance_year,
           balance.total_credits::text AS total_credits,
           balance.used::text AS used,
           balance.remaining::text AS remaining,
           (SELECT COUNT(*)::int FROM leave_requests request
             WHERE request.employee_id = employee.id) AS leave_request_count
      FROM users actor
      JOIN employees employee ON employee.id = actor.employee_id
      JOIN leave_types leave_type ON leave_type.code = 'VL' AND leave_type.is_active = true
      LEFT JOIN employee_leave_balances balance
        ON balance.employee_id = employee.id
       AND balance.leave_type_id = leave_type.id
       AND balance.year = EXTRACT(YEAR FROM NOW() AT TIME ZONE 'Asia/Manila')::int
     WHERE actor.email = ${sqlText(email)}
  `);
}

async function nextBusinessDay(employeeEmail: string): Promise<string> {
  const rows = await readOnlyDatabaseJson<Array<{ date: string }>>(`
    WITH manila_today AS (
      SELECT (NOW() AT TIME ZONE 'Asia/Manila')::date AS date
    ), candidates AS (
      SELECT (manila_today.date + days_ahead.day)::date AS date
        FROM manila_today
        CROSS JOIN generate_series(2, 60) AS days_ahead(day)
    )
    SELECT candidates.date::text AS date
      FROM candidates
     WHERE EXTRACT(DOW FROM candidates.date) <> 0
       AND NOT EXISTS (
         SELECT 1 FROM holidays holiday
          WHERE holiday.deleted_at IS NULL
            AND (holiday.date = candidates.date
              OR (holiday.is_recurring
                AND TO_CHAR(holiday.date, 'MM-DD') = TO_CHAR(candidates.date, 'MM-DD')))
       )
       AND NOT EXISTS (
         SELECT 1 FROM attendances attendance
         JOIN users actor ON actor.employee_id = attendance.employee_id
          WHERE actor.email = ${sqlText(employeeEmail)}
            AND attendance.date = candidates.date
       )
     ORDER BY candidates.date
     LIMIT 1
  `);
  expect(rows, 'reference calendar should expose a future working day').toHaveLength(1);
  return rows[0].date;
}

async function dateExceedingLeaveBalance(
  startDate: string,
  leaveYear: number,
  remaining: number,
): Promise<{ date: string; days: number }> {
  const rows = await readOnlyDatabaseJson<Array<{ date: string; days: number }>>(`
    WITH params AS (
      SELECT ${sqlText(startDate)}::date AS start_date,
             ${leaveYear}::int AS leave_year,
             ${remaining}::numeric AS remaining
    ), business_dates AS (
      SELECT candidate.day::date AS date
        FROM params
        CROSS JOIN generate_series(
          params.start_date::timestamp,
          make_date(params.leave_year, 12, 31)::timestamp,
          INTERVAL '1 day'
        ) AS candidate(day)
       WHERE EXTRACT(DOW FROM candidate.day) <> 0
         AND NOT EXISTS (
           SELECT 1
             FROM holidays holiday
            WHERE holiday.deleted_at IS NULL
              AND (holiday.date = candidate.day::date
                OR (holiday.is_recurring
                  AND TO_CHAR(holiday.date, 'MM-DD') = TO_CHAR(candidate.day, 'MM-DD')))
         )
    ), ranked AS (
      SELECT business_dates.date,
             ROW_NUMBER() OVER (ORDER BY business_dates.date)::int AS days
        FROM business_dates
    )
    SELECT ranked.date::text AS date, ranked.days
      FROM ranked
      CROSS JOIN params
     WHERE ranked.days > params.remaining
     ORDER BY ranked.date
     LIMIT 1
  `);
  expect(rows, `E2E baseline must allow a future ${leaveYear} date range that exceeds ${remaining} leave days`).toHaveLength(1);
  return rows[0];
}

async function leaveNoticeIdsFor(recipients: string[]): Promise<string[]> {
  if (recipients.length === 0) return [];
  const emailList = recipients.map(sqlText).join(', ');
  const rows = await readOnlyDatabaseJson<Array<{ id: string }>>(`
    SELECT notification.id::text AS id
      FROM notifications notification
      JOIN users recipient
        ON recipient.id = notification.notifiable_id
       AND notification.notifiable_type = 'App\\Modules\\Auth\\Models\\User'
     WHERE recipient.email IN (${emailList})
       AND notification.type LIKE 'leave.%'
     ORDER BY notification.id
  `);
  return rows.map((row) => row.id);
}

async function leaveSnapshot(reason: string): Promise<LeaveSnapshot[]> {
  return readOnlyDatabaseJson<LeaveSnapshot[]>(`
    SELECT request.id AS request_id,
           request.leave_request_no,
           request.status,
           request.days::text AS days,
           request.start_date::text AS start_date,
           request.end_date::text AS end_date,
           employee.id AS employee_id,
           employee.employee_no,
           requester.email AS requester_email,
           employee.department_id,
           leave_type.code AS leave_type_code,
           dept_approver.email AS dept_approver_email,
           hr_approver.email AS hr_approver_email,
           canceller.email AS cancelled_by_email,
           request.cancelled_at::text AS cancelled_at,
           balance.used::text AS balance_used,
           balance.remaining::text AS balance_remaining,
           request.rejection_reason,
           (SELECT COUNT(*)::int FROM attendances attendance
             WHERE attendance.employee_id = employee.id
               AND attendance.date BETWEEN request.start_date AND request.end_date
               AND attendance.status = 'on_leave'
               AND attendance.deleted_at IS NULL
               AND attendance.remarks = 'leave:' || request.leave_request_no) AS on_leave_attendance_count,
           (SELECT COUNT(*)::int FROM audit_logs audit
             WHERE audit.model_type ILIKE '%LeaveRequest'
               AND audit.model_id = request.id) AS request_audit_count
      FROM leave_requests request
      JOIN employees employee ON employee.id = request.employee_id
      JOIN users requester ON requester.employee_id = employee.id
      JOIN leave_types leave_type ON leave_type.id = request.leave_type_id
      LEFT JOIN users dept_approver ON dept_approver.id = request.dept_approver_id
      LEFT JOIN users hr_approver ON hr_approver.id = request.hr_approver_id
      LEFT JOIN users canceller ON canceller.id = request.cancelled_by
      JOIN employee_leave_balances balance
        ON balance.employee_id = employee.id
       AND balance.leave_type_id = leave_type.id
       AND balance.year = EXTRACT(YEAR FROM request.start_date)::int
     WHERE request.reason = ${sqlText(reason)}
  `);
}

async function approvalRows(reason: string): Promise<ApprovalRow[]> {
  return readOnlyDatabaseJson<ApprovalRow[]>(`
    SELECT approval.step_order,
           approval.role_slug,
           approval.action,
           approver.email AS approver_email,
           approval.is_current
      FROM approval_records approval
      LEFT JOIN users approver ON approver.id = approval.approver_id
     WHERE approval.approvable_type ILIKE '%LeaveRequest'
       AND approval.approvable_id = (SELECT id FROM leave_requests WHERE reason = ${sqlText(reason)})
       AND approval.is_current = true
     ORDER BY approval.step_order
  `);
}

async function approvalHistoryRows(reason: string): Promise<ApprovalRow[]> {
  return readOnlyDatabaseJson<ApprovalRow[]>(`
    SELECT approval.step_order,
           approval.role_slug,
           approval.action,
           approver.email AS approver_email,
           approval.is_current
      FROM approval_records approval
      LEFT JOIN users approver ON approver.id = approval.approver_id
     WHERE approval.approvable_type ILIKE '%LeaveRequest'
       AND approval.approvable_id = (SELECT id FROM leave_requests WHERE reason = ${sqlText(reason)})
     ORDER BY approval.step_order
  `);
}

async function notificationRows(publicId: string): Promise<NotificationRow[]> {
  return readOnlyDatabaseJson<NotificationRow[]>(`
    SELECT notification.type,
           recipient.email AS recipient_email,
           notification.data->>'link_to' AS link_to
      FROM notifications notification
      JOIN users recipient
        ON recipient.id = notification.notifiable_id
       AND notification.notifiable_type = 'App\\Modules\\Auth\\Models\\User'
     WHERE notification.data->>'entity_type' = 'leave_request'
       AND notification.data->>'entity_id' = ${sqlText(publicId)}
     ORDER BY notification.created_at, notification.id
  `);
}

async function requestAudits(reason: string): Promise<AuditRow[]> {
  return readOnlyDatabaseJson<AuditRow[]>(`
    SELECT audit.action,
           actor.email AS actor_email,
           COALESCE(audit.new_values->>'status', audit.old_values->>'status') AS status
      FROM audit_logs audit
      JOIN leave_requests request
        ON audit.model_type ILIKE '%LeaveRequest'
       AND audit.model_id = request.id
      LEFT JOIN users actor ON actor.id = audit.user_id
     WHERE request.reason = ${sqlText(reason)}
     ORDER BY audit.id
  `);
}

async function eligibleRecipients(
  notificationType: string,
  permissionSlug: string,
  settingsKey: string,
  departmentId?: number,
): Promise<string[]> {
  const rows = await readOnlyDatabaseJson<Array<{ email: string }>>(`
    SELECT DISTINCT recipient.email
      FROM users recipient
      JOIN roles role ON role.id = recipient.role_id
      LEFT JOIN employees recipient_employee ON recipient_employee.id = recipient.employee_id
     WHERE recipient.is_active = true
       AND role.slug IN (
         SELECT jsonb_array_elements_text(setting.value::jsonb)
           FROM settings setting
          WHERE setting.key = ${sqlText(settingsKey)}
       )
       AND (${departmentId === undefined ? 'true' : `recipient_employee.department_id = ${departmentId}`})
       AND (
         EXISTS (
           SELECT 1 FROM role_permissions role_permission
           JOIN permissions permission ON permission.id = role_permission.permission_id
            WHERE role_permission.role_id = recipient.role_id
              AND permission.slug = ${sqlText(permissionSlug)}
         )
         OR EXISTS (
           SELECT 1 FROM user_permission_overrides permission_override
           JOIN permissions permission ON permission.id = permission_override.permission_id
            WHERE permission_override.user_id = recipient.id
              AND permission.slug = ${sqlText(permissionSlug)}
              AND permission_override.deleted_at IS NULL
              AND (permission_override.expires_at IS NULL OR permission_override.expires_at > NOW())
         )
       )
       AND NOT EXISTS (
         SELECT 1 FROM notification_preferences preference
          WHERE preference.user_id = recipient.id
            AND preference.notification_type = ${sqlText(notificationType)}
            AND preference.channel = 'in_app'
            AND preference.enabled = false
       )
       ${permissionSlug === 'leave.approve_hr' ? `AND (
         EXISTS (
           SELECT 1 FROM role_permissions role_permission
           JOIN permissions permission ON permission.id = role_permission.permission_id
            WHERE role_permission.role_id = recipient.role_id
              AND permission.slug = 'leave.view'
         )
         OR EXISTS (
           SELECT 1 FROM user_permission_overrides permission_override
           JOIN permissions permission ON permission.id = permission_override.permission_id
            WHERE permission_override.user_id = recipient.id
              AND permission.slug = 'leave.view'
              AND permission_override.deleted_at IS NULL
              AND (permission_override.expires_at IS NULL OR permission_override.expires_at > NOW())
         )
       )` : ''}
     ORDER BY recipient.email
  `);
  return rows.map((row) => row.email);
}

async function mailpitIdsFor(recipients: string[]): Promise<string[]> {
  const normalized = new Set(recipients.map((email) => email.toLowerCase()));
  return (await listMailpitMessages())
    .filter((message) => message.To.some((target) => normalized.has(target.Address.toLowerCase())))
    .map((message) => message.ID)
    .sort();
}

async function expectLeaveStatus(reason: string, expected: string): Promise<LeaveSnapshot> {
  let rows: LeaveSnapshot[] = [];
  await expect.poll(async () => {
    rows = await leaveSnapshot(reason);
    return rows[0]?.status ?? 'missing';
  }, { timeout: 20_000, message: `Leave request should reach ${expected}` }).toBe(expected);
  expect(rows, `one UI-created leave request should be present for ${reason}`).toHaveLength(1);
  return rows[0];
}

test('HR04-APPROVE — Employee leave request flows through Department Head and HR to attendance', async ({ browser }, testInfo) => {
  test.setTimeout(120_000);
  const actors = await loadActors();
  const employeeUser = actorByName(actors, 'employee');
  const departmentHeadUser = actorByName(actors, 'department_head');
  const hrUser = actorByName(actors, 'hr_officer');
  const balances = await employeeBalanceBaseline(employeeUser.email);
  expect(balances, 'the seeded Employee should resolve to one Vacation Leave balance row').toHaveLength(1);
  expect(balances[0].balance_id, 'E2E baseline should include opening Vacation Leave entitlement').not.toBeNull();
  expect(Number(balances[0].remaining), 'opening leave balance must cover one day').toBeGreaterThanOrEqual(1);
  expect(balances[0].leave_request_count, 'fresh baseline should not contain leave transactions').toBe(0);

  const employee = await actorPage(browser, employeeUser.actor);
  const departmentHead = await actorPage(browser, departmentHeadUser.actor);
  const hr = await actorPage(browser, hrUser.actor);
  const journey = createJourneyContext('HR04-APPROVE');
  const startDate = await nextBusinessDay(employeeUser.email);
  const endDate = startDate;
  const reason = `${String(journey.values.runPrefix)} leave approval journey`;
  const expectedDays = 1;
  const deptRecipients = await eligibleRecipients(
    'leave.submitted', 'leave.approve_dept', 'leave.submitted.notification_roles', balances[0].department_id,
  );
  const hrRecipients = await eligibleRecipients(
    'leave.pending_hr', 'leave.approve_hr', 'leave.pending_hr.notification_roles',
  );
  expect(deptRecipients).toContain(departmentHeadUser.email);
  expect(hrRecipients).toContain(hrUser.email);
  const intendedRecipients = [...new Set([...deptRecipients, ...hrRecipients, employeeUser.email])];
  const mailBefore = await mailpitIdsFor(intendedRecipients);
  const planned = plannedSteps;

  try {
    await Promise.all([openSelfServiceLeaves(employee), openDashboard(departmentHead), openDashboard(hr)]);
    await Promise.all([openHumanResourcesMenu(departmentHead), openHumanResourcesMenu(hr)]);
    await expect(departmentHead.page.getByRole('button', { name: /^View all Leave Management signals/ })).toHaveCount(0);
    await expect(hr.page.getByRole('button', { name: /^View all Leave Management signals/ })).toHaveCount(0);
    const cleanRequests = await readOnlyDatabaseJson<Array<{ request_count: number }>>(`
      SELECT COUNT(*)::int AS request_count
        FROM leave_requests request
       WHERE request.employee_id = ${balances[0].employee_id}
    `);
    expect(cleanRequests[0]?.request_count).toBe(0);

    await runProcessStep({
      name: planned[0].name,
      actor: planned[0].actor,
      uiPath: planned[0].uiPath,
      expectedStatus: planned[0].expectedStatus,
      page: employee.page,
      plannedSteps: planned,
      context: journey,
      testInfo,
      action: async () => {
        await employee.page.getByRole('button', { name: /New request/ }).click();
        const requestDialog = employee.page.getByRole('dialog', { name: 'File Leave Request' });
        await expect(requestDialog).toBeVisible();
        await requestDialog.getByRole('combobox', { name: /^Leave type/ })
          .selectOption({ label: 'Vacation Leave' });
        await requestDialog.getByLabel(/^Start date/).fill(startDate);
        await requestDialog.getByLabel(/^End date/).fill(endDate);
        await employee.page.getByLabel('Reason (optional)', { exact: true }).fill(reason);
        await expect(employee.page.getByText('Estimated days to be deducted')).toBeVisible();
        const responsePromise = employee.page.waitForResponse((response) =>
          response.url().includes('/api/v1/leaves/requests') && response.request().method() === 'POST',
        );
        await employee.page.getByRole('button', { name: 'Submit request', exact: true }).click();
        const response = await responsePromise;
        expect(response.status(), 'Employee UI submission should create the leave request').toBe(201);
        const requestLink = employee.page.getByRole('link', { name: /^View leave request / });
        await expect(requestLink).toHaveCount(1);
        const linkLabel = await requestLink.getAttribute('aria-label');
        const number = linkLabel?.replace(/^View leave request /, '');
        expect(number, 'UI should display the generated leave request number').toBeTruthy();
        const href = await requestLink.getAttribute('href');
        expect(href, 'Employee should receive an in-app detail link from the leave list').toMatch(/^\/self-service\/leaves\//);
        const publicId = href?.split('/').at(-1) ?? '';
        expect(publicId).not.toBe('');
        journey.values.employeeNo = balances[0].employee_no;
        journey.values.leaveRequestNo = number;
        journey.values.leaveRequestPublicId = publicId;
        journey.values.startDate = startDate;
        journey.values.endDate = endDate;
        journey.values.leaveType = 'VL';
        journey.values.reason = reason;
        await requestLink.click();
        await expect(employee.page.getByRole('heading', { name: new RegExp(number!) })).toBeVisible();
      },
      verify: async () => {
        const snapshot = await expectLeaveStatus(reason, 'pending_dept');
        expect(snapshot.leave_request_no).toBe(journey.values.leaveRequestNo);
        expect(snapshot.days).toBe('1.0');
        expect(snapshot.leave_type_code).toBe('VL');
        expect(snapshot.requester_email).toBe(employeeUser.email);
        expect(snapshot.dept_approver_email).toBeNull();
        expect(snapshot.hr_approver_email).toBeNull();
        expect(Number(snapshot.balance_used)).toBe(Number(balances[0].used));
        expect(Number(snapshot.balance_remaining)).toBe(Number(balances[0].remaining));
        expect(snapshot.on_leave_attendance_count).toBe(0);
        const approvals = await approvalRows(reason);
        expect(approvals.map((row) => [row.step_order, row.role_slug, row.action, row.approver_email])).toEqual([
          [1, 'department_head', 'pending', null],
          [2, 'hr_officer', 'pending', null],
        ]);
        await expect(employee.page.getByRole('heading').filter({ hasText: String(journey.values.leaveRequestNo) }))
          .toContainText('Pending dept');
        await expect(employee.page.getByRole('button', { name: 'Cancel', exact: true })).toBeVisible();
        await expect(employee.page.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
      },
      assertInvariants: async () => {
        await expect.poll(async () => {
          const rows = await readOnlyDatabaseJson<Array<{ type: string; recipient_email: string }>>(`
            SELECT notification.type, recipient.email AS recipient_email
              FROM notifications notification
              JOIN users recipient ON recipient.id = notification.notifiable_id
               AND notification.notifiable_type = 'App\\Modules\\Auth\\Models\\User'
             WHERE notification.data->>'entity_id' = ${sqlText(String(journey.values.leaveRequestPublicId))}
               AND notification.type = 'leave.submitted'
          `);
          return rows.map((row) => row.recipient_email).sort();
        }, { timeout: 20_000, message: 'only eligible Department Heads should receive the initial leave hand-off' })
          .toEqual([...deptRecipients].sort());
        employee.diagnostics.assertClean('Employee submits a leave request through self-service');
      },
    });

    await runProcessStep({
      name: planned[1].name,
      actor: planned[1].actor,
      uiPath: planned[1].uiPath,
      expectedStatus: planned[1].expectedStatus,
      page: departmentHead.page,
      plannedSteps: planned,
      context: journey,
      testInfo,
      action: async () => {
        await openHumanResourcesMenu(departmentHead);
        await expect.poll(async () => {
          const badge = departmentHead.page.getByRole('button', { name: 'View all Leave Management signals: 1 Leave requests awaiting your approval', exact: true });
          return badge.count();
        }, { timeout: 20_000, message: 'Department Head should receive the actionable Leaves badge' }).toBe(1);
        await openLeaveApprovalFromBadge(departmentHead, 1);
        const row = departmentHead.page.getByRole('row').filter({ hasText: String(journey.values.leaveRequestNo) });
        await expect(row).toHaveCount(1);
        await expect(row).toContainText('Pending dept');
        await expect(row.getByRole('button', { name: 'Approve', exact: true })).toBeEnabled();
        await expect(row.getByRole('button', { name: 'Reject', exact: true })).toBeEnabled();
        await row.getByRole('button', { name: 'Approve', exact: true }).click();
        const confirmation = departmentHead.page.getByRole('dialog', { name: 'Approve leave request?' });
        await expect(confirmation).toBeVisible();
        await confirmation.getByRole('button', { name: 'Approve', exact: true }).click();
        // The current-actor approval inbox is filtered to actionable stages.
        // After department approval this request belongs to HR and leaves the DH queue.
        await expect(row).toHaveCount(0);
      },
      verify: async () => {
        const snapshot = await expectLeaveStatus(reason, 'pending_hr');
        expect(snapshot.dept_approver_email).toBe(departmentHeadUser.email);
        expect(snapshot.hr_approver_email).toBeNull();
        expect(Number(snapshot.balance_used)).toBe(Number(balances[0].used));
        expect(Number(snapshot.balance_remaining)).toBe(Number(balances[0].remaining));
        expect(snapshot.on_leave_attendance_count).toBe(0);
        expect(await approvalRows(reason)).toEqual([
          expect.objectContaining({ step_order: 1, role_slug: 'department_head', action: 'approved', approver_email: departmentHeadUser.email }),
          expect.objectContaining({ step_order: 2, role_slug: 'hr_officer', action: 'pending', approver_email: null }),
        ]);
        await expect(employee.page.getByRole('heading').filter({ hasText: String(journey.values.leaveRequestNo) }))
          .toContainText('Pending hr');
        await expect(employee.page.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
      },
      assertInvariants: async () => {
        await expect.poll(async () => {
          const rows = await readOnlyDatabaseJson<Array<{ recipient_email: string }>>(`
            SELECT recipient.email AS recipient_email
              FROM notifications notification
              JOIN users recipient ON recipient.id = notification.notifiable_id
               AND notification.notifiable_type = 'App\\Modules\\Auth\\Models\\User'
             WHERE notification.data->>'entity_id' = ${sqlText(String(journey.values.leaveRequestPublicId))}
               AND notification.type = 'leave.pending_hr'
          `);
          return rows.map((row) => row.recipient_email).sort();
        }, { timeout: 20_000, message: 'only eligible HR approvers should receive the next leave hand-off' })
          .toEqual([...hrRecipients].sort());
        await expect.poll(async () => departmentHead.page.getByRole('button', { name: /^View all Leave Management signals/ }).count())
          .toBe(0);
        employee.diagnostics.assertClean('Employee observes Department Head approval live');
        departmentHead.diagnostics.assertClean('Department Head advances leave to HR');
      },
    });

    await runProcessStep({
      name: planned[2].name,
      actor: planned[2].actor,
      uiPath: planned[2].uiPath,
      expectedStatus: planned[2].expectedStatus,
      page: hr.page,
      plannedSteps: planned,
      context: journey,
      testInfo,
      action: async () => {
        await openHumanResourcesMenu(hr);
        await expect.poll(async () => hr.page.getByRole('button', { name: 'View all Leave Management signals: 1 Leave requests awaiting your approval', exact: true }).count(), {
          timeout: 20_000,
          message: 'HR should receive the actionable Leaves badge after Department Head approval',
        }).toBe(1);
        await openLeaveApprovalFromBadge(hr, 1);
        const row = hr.page.getByRole('row').filter({ hasText: String(journey.values.leaveRequestNo) });
        await expect(row).toHaveCount(1);
        await expect(row).toContainText('Pending hr');
        await expect(row.getByRole('button', { name: 'Approve', exact: true })).toBeEnabled();
        await expect(row.getByRole('button', { name: 'Reject', exact: true })).toBeEnabled();
        await row.getByRole('button', { name: 'Approve', exact: true }).click();
        const confirmation = hr.page.getByRole('dialog', { name: 'Approve leave request?' });
        await expect(confirmation).toBeVisible();
        await confirmation.getByRole('button', { name: 'Approve', exact: true }).click();
        await expect(row).toHaveCount(0);
      },
      verify: async () => {
        const snapshot = await expectLeaveStatus(reason, 'approved');
        expect(snapshot.dept_approver_email).toBe(departmentHeadUser.email);
        expect(snapshot.hr_approver_email).toBe(hrUser.email);
        expect(Number(snapshot.balance_used)).toBe(Number(balances[0].used) + expectedDays);
        expect(Number(snapshot.balance_remaining)).toBe(Number(balances[0].remaining) - expectedDays);
        expect(snapshot.on_leave_attendance_count).toBe(1);
        expect(snapshot.request_audit_count).toBeGreaterThanOrEqual(3);
        expect(await approvalRows(reason)).toEqual([
          expect.objectContaining({ step_order: 1, role_slug: 'department_head', action: 'approved', approver_email: departmentHeadUser.email }),
          expect.objectContaining({ step_order: 2, role_slug: 'hr_officer', action: 'approved', approver_email: hrUser.email }),
        ]);
        await expect(employee.page.getByRole('heading').filter({ hasText: String(journey.values.leaveRequestNo) }))
          .toContainText('Approved');
        await expect(employee.page.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
        await expect(hr.page.getByRole('button', { name: /^View all Leave Management signals/ })).toHaveCount(0);
      },
      assertInvariants: async () => {
        const publicId = String(journey.values.leaveRequestPublicId);
        await expect.poll(async () => (await notificationRows(publicId)).map((row) => row.type).sort(), {
          timeout: 20_000,
          message: 'leave notifications should reflect submission, HR hand-off and final approval',
        }).toEqual(['leave.approved', 'leave.pending_hr', 'leave.submitted']);
        const notifications = await notificationRows(publicId);
        const initial = notifications.filter((row) => row.type === 'leave.submitted').map((row) => row.recipient_email);
        const hrHandoff = notifications.filter((row) => row.type === 'leave.pending_hr').map((row) => row.recipient_email);
        const approved = notifications.filter((row) => row.type === 'leave.approved').map((row) => row.recipient_email);
        assertRecipientsExactly(initial, deptRecipients);
        assertRecipientsExactly(hrHandoff, hrRecipients);
        assertRecipientsExactly(approved, [employeeUser.email]);
        expect(notifications.every((row) => row.link_to?.includes(publicId))).toBe(true);

        const audits = await requestAudits(reason);
        const created = audits.find((audit) => audit.action === 'created');
        const deptApproved = audits.find((audit) => audit.status === 'pending_hr');
        const hrApproved = audits.find((audit) => audit.status === 'approved');
        expect(created).toBeDefined();
        expect(deptApproved).toBeDefined();
        expect(hrApproved).toBeDefined();
        assertAuditActor({ actor_email: created?.actor_email ?? undefined }, employeeUser.email);
        assertAuditActor({ actor_email: deptApproved?.actor_email ?? undefined }, departmentHeadUser.email);
        assertAuditActor({ actor_email: hrApproved?.actor_email ?? undefined }, hrUser.email);

        expect(await mailpitIdsFor(intendedRecipients)).toEqual(mailBefore);
        await expect.poll(async () => employee.page.getByRole('heading')
          .filter({ hasText: String(journey.values.leaveRequestNo) }).innerText())
          .toContain('Approved');
        await expect.poll(async () => departmentHead.page.getByRole('button', { name: /^View all Leave Management signals/ }).count()).toBe(0);
        await expect.poll(async () => hr.page.getByRole('button', { name: /^View all Leave Management signals/ }).count()).toBe(0);
        employee.diagnostics.assertClean('Employee sees final approval, balance and attendance result live');
        departmentHead.diagnostics.assertClean('Department Head has no remaining leave action');
        hr.diagnostics.assertClean('HR completes final leave approval');
      },
    });

    await captureAnnotatedStep(employee.page, 'employee', 'Employee sees HR approval and final leave effects', 4, testInfo);
  } finally {
    await Promise.all([employee.context.close(), departmentHead.context.close(), hr.context.close()]);
  }
});

type RejectionStage = 'department_head' | 'hr_officer';

async function runLeaveRejectionJourney(
  browser: Parameters<typeof actorPage>[0],
  testInfo: import('@playwright/test').TestInfo,
  rejectionStage: RejectionStage,
): Promise<void> {
  const actors = await loadActors();
  const employeeUser = actorByName(actors, 'employee');
  const departmentHeadUser = actorByName(actors, 'department_head');
  const hrUser = actorByName(actors, 'hr_officer');
  const balances = await employeeBalanceBaseline(employeeUser.email);
  expect(balances, 'the seeded Employee should resolve to one Vacation Leave balance row').toHaveLength(1);
  expect(balances[0].balance_id, 'E2E baseline should include opening Vacation Leave entitlement').not.toBeNull();
  expect(Number(balances[0].remaining)).toBeGreaterThanOrEqual(1);
  expect(balances[0].leave_request_count).toBe(0);

  const employee = await actorPage(browser, employeeUser.actor);
  const departmentHead = await actorPage(browser, departmentHeadUser.actor);
  const hr = await actorPage(browser, hrUser.actor);
  const journey = createJourneyContext(`HR04-REJECT-${rejectionStage === 'department_head' ? 'DEPT' : 'HR'}`);
  const startDate = await nextBusinessDay(employeeUser.email);
  const endDate = startDate;
  const reason = `${String(journey.values.runPrefix)} leave rejection journey`;
  const rejectionReason = `${String(journey.values.runPrefix)} ${rejectionStage} rejection reason`;
  const deptRecipients = await eligibleRecipients(
    'leave.submitted', 'leave.approve_dept', 'leave.submitted.notification_roles', balances[0].department_id,
  );
  const hrRecipients = await eligibleRecipients(
    'leave.pending_hr', 'leave.approve_hr', 'leave.pending_hr.notification_roles',
  );
  expect(deptRecipients).toContain(departmentHeadUser.email);
  expect(hrRecipients).toContain(hrUser.email);
  const intendedRecipients = [...new Set([...deptRecipients, ...hrRecipients, employeeUser.email])];
  const mailBefore = await mailpitIdsFor(intendedRecipients);
  const planned = rejectionStage === 'department_head'
    ? [
        { name: 'Employee files a Vacation Leave request', actor: 'employee', uiPath: 'Profile menu → Leaves → New request', expectedStatus: 'pending_dept; balance and attendance unchanged' },
        { name: 'Department Head rejects through the Leaves badge', actor: 'department_head', uiPath: 'Dashboard → Human Resources menu → Leave Management signals badge → Awaiting my action → Reject', expectedStatus: 'rejected; reason and actor recorded; no balance/attendance effect' },
      ]
    : [
        { name: 'Employee files a Vacation Leave request', actor: 'employee', uiPath: 'Profile menu → Leaves → New request', expectedStatus: 'pending_dept; balance and attendance unchanged' },
        { name: 'Department Head follows the Leaves badge and approves', actor: 'department_head', uiPath: 'Dashboard → Human Resources menu → Leave Management signals badge → Awaiting my action → Approve', expectedStatus: 'pending_hr; HR receives actionable hand-off' },
        { name: 'HR rejects through the Leaves badge', actor: 'hr_officer', uiPath: 'Dashboard → Human Resources menu → Leave Management signals badge → Awaiting my action → Reject', expectedStatus: 'rejected; reason and actor recorded; no balance/attendance effect' },
      ];

  try {
    await Promise.all([openSelfServiceLeaves(employee), openDashboard(departmentHead), openDashboard(hr)]);
    await Promise.all([openHumanResourcesMenu(departmentHead), openHumanResourcesMenu(hr)]);
    await expect(departmentHead.page.getByRole('button', { name: /^View all Leave Management signals/ })).toHaveCount(0);
    await expect(hr.page.getByRole('button', { name: /^View all Leave Management signals/ })).toHaveCount(0);
    const cleanRequests = await readOnlyDatabaseJson<Array<{ request_count: number }>>(`
      SELECT COUNT(*)::int AS request_count
        FROM leave_requests request
       WHERE request.employee_id = ${balances[0].employee_id}
    `);
    expect(cleanRequests[0]?.request_count).toBe(0);

    await runProcessStep({
      name: planned[0].name,
      actor: planned[0].actor,
      uiPath: planned[0].uiPath,
      expectedStatus: planned[0].expectedStatus,
      page: employee.page,
      plannedSteps: planned,
      context: journey,
      testInfo,
      action: async () => {
        await employee.page.getByRole('button', { name: /New request/ }).click();
        const dialog = employee.page.getByRole('dialog', { name: 'File Leave Request' });
        await expect(dialog).toBeVisible();
        await dialog.getByRole('combobox', { name: /^Leave type/ }).selectOption({ label: 'Vacation Leave' });
        await dialog.getByLabel(/^Start date/).fill(startDate);
        await dialog.getByLabel(/^End date/).fill(endDate);
        await employee.page.getByLabel('Reason (optional)', { exact: true }).fill(reason);
        const responsePromise = employee.page.waitForResponse((response) =>
          response.url().includes('/api/v1/leaves/requests') && response.request().method() === 'POST',
        );
        await employee.page.getByRole('button', { name: 'Submit request', exact: true }).click();
        const response = await responsePromise;
        expect(response.status()).toBe(201);
        const requestLink = employee.page.getByRole('link', { name: /^View leave request / });
        await expect(requestLink).toHaveCount(1);
        const label = await requestLink.getAttribute('aria-label');
        const number = label?.replace(/^View leave request /, '');
        const href = await requestLink.getAttribute('href');
        const publicId = href?.split('/').at(-1) ?? '';
        expect(number).toBeTruthy();
        expect(href).toMatch(/^\/self-service\/leaves\//);
        expect(publicId).not.toBe('');
        journey.values.employeeNo = balances[0].employee_no;
        journey.values.leaveRequestNo = number;
        journey.values.leaveRequestPublicId = publicId;
        journey.values.startDate = startDate;
        journey.values.endDate = endDate;
        journey.values.leaveType = 'VL';
        journey.values.reason = reason;
        await requestLink.click();
        await expect(employee.page.getByRole('heading', { name: new RegExp(number!) })).toBeVisible();
      },
      verify: async () => {
        const snapshot = await expectLeaveStatus(reason, 'pending_dept');
        expect(snapshot.leave_request_no).toBe(journey.values.leaveRequestNo);
        expect(snapshot.days).toBe('1.0');
        expect(snapshot.rejection_reason).toBeNull();
        expect(Number(snapshot.balance_used)).toBe(Number(balances[0].used));
        expect(Number(snapshot.balance_remaining)).toBe(Number(balances[0].remaining));
        expect(snapshot.on_leave_attendance_count).toBe(0);
        expect(await approvalRows(reason)).toEqual([
          expect.objectContaining({ step_order: 1, role_slug: 'department_head', action: 'pending', approver_email: null }),
          expect.objectContaining({ step_order: 2, role_slug: 'hr_officer', action: 'pending', approver_email: null }),
        ]);
        await expect(employee.page.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
        await expect(employee.page.getByRole('button', { name: 'Cancel', exact: true })).toBeVisible();
      },
      assertInvariants: async () => {
        await expect.poll(async () => (await notificationRows(String(journey.values.leaveRequestPublicId)))
          .filter((row) => row.type === 'leave.submitted').map((row) => row.recipient_email).sort(), {
          timeout: 20_000,
          message: 'the submission notice should reach only the eligible Department Head audience',
        }).toEqual([...deptRecipients].sort());
        employee.diagnostics.assertClean('Employee submits a leave request through self-service');
      },
    });

    if (rejectionStage === 'hr_officer') {
      await runProcessStep({
        name: planned[1].name,
        actor: planned[1].actor,
        uiPath: planned[1].uiPath,
        expectedStatus: planned[1].expectedStatus,
        page: departmentHead.page,
        plannedSteps: planned,
        context: journey,
        testInfo,
        action: async () => {
          await openLeaveApprovalFromBadge(departmentHead, 1);
          const row = departmentHead.page.getByRole('row').filter({ hasText: String(journey.values.leaveRequestNo) });
          await expect(row).toHaveCount(1);
          await expect(row).toContainText('Pending dept');
          await row.getByRole('button', { name: 'Approve', exact: true }).click();
          const confirmation = departmentHead.page.getByRole('dialog', { name: 'Approve leave request?' });
          await expect(confirmation).toBeVisible();
          await confirmation.getByRole('button', { name: 'Approve', exact: true }).click();
          await expect(row).toHaveCount(0);
        },
        verify: async () => {
          const snapshot = await expectLeaveStatus(reason, 'pending_hr');
          expect(snapshot.dept_approver_email).toBe(departmentHeadUser.email);
          expect(snapshot.hr_approver_email).toBeNull();
          expect(Number(snapshot.balance_used)).toBe(Number(balances[0].used));
          expect(Number(snapshot.balance_remaining)).toBe(Number(balances[0].remaining));
          expect(snapshot.on_leave_attendance_count).toBe(0);
          expect(await approvalRows(reason)).toEqual([
            expect.objectContaining({ step_order: 1, role_slug: 'department_head', action: 'approved', approver_email: departmentHeadUser.email }),
            expect.objectContaining({ step_order: 2, role_slug: 'hr_officer', action: 'pending', approver_email: null }),
          ]);
          await expect(employee.page.getByRole('heading').filter({ hasText: String(journey.values.leaveRequestNo) }))
            .toContainText('Pending hr');
        },
        assertInvariants: async () => {
          await expect.poll(async () => (await notificationRows(String(journey.values.leaveRequestPublicId)))
            .filter((row) => row.type === 'leave.pending_hr').map((row) => row.recipient_email).sort(), {
            timeout: 20_000,
            message: 'the Department Head approval should notify only eligible HR approvers',
          }).toEqual([...hrRecipients].sort());
          employee.diagnostics.assertClean('Employee observes Department Head approval live');
          departmentHead.diagnostics.assertClean('Department Head approves the first leave stage');
        },
      });
    }

    const rejectActor = rejectionStage === 'department_head' ? departmentHead : hr;
    const rejectActorUser = rejectionStage === 'department_head' ? departmentHeadUser : hrUser;
    const rejectStepIndex = rejectionStage === 'department_head' ? 1 : 2;
    await runProcessStep({
      name: planned[rejectStepIndex].name,
      actor: planned[rejectStepIndex].actor,
      uiPath: planned[rejectStepIndex].uiPath,
      expectedStatus: planned[rejectStepIndex].expectedStatus,
      page: rejectActor.page,
      plannedSteps: planned,
      context: journey,
      testInfo,
      action: async () => {
        await openHumanResourcesMenu(rejectActor);
        await expect.poll(async () => rejectActor.page.getByRole('button', {
          name: 'View all Leave Management signals: 1 Leave requests awaiting your approval', exact: true,
        }).count(), { timeout: 20_000, message: `${rejectionStage} should receive its actionable leave badge` }).toBe(1);
        await openLeaveApprovalFromBadge(rejectActor, 1);
        const row = rejectActor.page.getByRole('row').filter({ hasText: String(journey.values.leaveRequestNo) });
        await expect(row).toHaveCount(1);
        await expect(row).toContainText(rejectionStage === 'department_head' ? 'Pending dept' : 'Pending hr');
        await expect(row.getByRole('button', { name: 'Reject', exact: true })).toBeEnabled();
        await row.getByRole('button', { name: 'Reject', exact: true }).click();
        const dialog = rejectActor.page.getByRole('dialog', { name: 'Reject leave request' });
        await expect(dialog).toBeVisible();
        await dialog.getByLabel('Reason for rejection').fill(rejectionReason);
        const responsePromise = rejectActor.page.waitForResponse((response) =>
          response.url().includes('/api/v1/leaves/requests/') && response.url().endsWith('/reject')
            && response.request().method() === 'PATCH',
        );
        await dialog.getByRole('button', { name: 'Confirm reject', exact: true }).click();
        const response = await responsePromise;
        expect(response.status()).toBe(200);
        await expect(row).toHaveCount(0);
      },
      verify: async () => {
        const snapshot = await expectLeaveStatus(reason, 'rejected');
        expect(snapshot.rejection_reason).toBe(rejectionReason);
        expect(snapshot.dept_approver_email).toBe(rejectionStage === 'department_head' ? null : departmentHeadUser.email);
        expect(snapshot.hr_approver_email).toBeNull();
        expect(Number(snapshot.balance_used)).toBe(Number(balances[0].used));
        expect(Number(snapshot.balance_remaining)).toBe(Number(balances[0].remaining));
        expect(snapshot.on_leave_attendance_count).toBe(0);
        if (rejectionStage === 'department_head') {
          expect(await approvalRows(reason)).toEqual([
            expect.objectContaining({ step_order: 1, role_slug: 'department_head', action: 'rejected', approver_email: departmentHeadUser.email }),
            expect.objectContaining({ step_order: 2, role_slug: 'hr_officer', action: 'skipped', approver_email: null }),
          ]);
        } else {
          expect(snapshot.dept_approver_email).toBe(departmentHeadUser.email);
          expect(await approvalRows(reason)).toEqual([
            expect.objectContaining({ step_order: 1, role_slug: 'department_head', action: 'approved', approver_email: departmentHeadUser.email }),
            expect.objectContaining({ step_order: 2, role_slug: 'hr_officer', action: 'rejected', approver_email: hrUser.email }),
          ]);
        }
        await expect(employee.page.getByRole('heading').filter({ hasText: String(journey.values.leaveRequestNo) }))
          .toContainText('Rejected');
        await expect(employee.page.getByText(rejectionReason, { exact: true })).toBeVisible();
        await expect(employee.page.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
        await expect(employee.page.getByRole('button', { name: 'Cancel', exact: true })).toHaveCount(0);
      },
      assertInvariants: async () => {
        const publicId = String(journey.values.leaveRequestPublicId);
        const expectedTypes = rejectionStage === 'department_head'
          ? ['leave.rejected', 'leave.submitted']
          : ['leave.pending_hr', 'leave.rejected', 'leave.submitted'];
        await expect.poll(async () => (await notificationRows(publicId)).map((row) => row.type).sort(), {
          timeout: 20_000,
          message: 'rejection notifications should follow the workflow stage',
        }).toEqual(expectedTypes);
        const notices = await notificationRows(publicId);
        assertRecipientsExactly(notices.filter((row) => row.type === 'leave.submitted').map((row) => row.recipient_email), deptRecipients);
        assertRecipientsExactly(notices.filter((row) => row.type === 'leave.pending_hr').map((row) => row.recipient_email), rejectionStage === 'hr_officer' ? hrRecipients : []);
        assertRecipientsExactly(notices.filter((row) => row.type === 'leave.rejected').map((row) => row.recipient_email), [employeeUser.email]);
        expect(notices.every((row) => row.link_to?.includes(publicId))).toBe(true);
        const audits = await requestAudits(reason);
        const rejected = audits.find((audit) => audit.status === 'rejected');
        expect(rejected).toBeDefined();
        assertAuditActor({ actor_email: rejected?.actor_email ?? undefined }, rejectActorUser.email);
        await expect.poll(async () => rejectActor.page.getByRole('button', { name: /^View all Leave Management signals/ }).count()).toBe(0);
        await expect.poll(async () => hr.page.getByRole('button', { name: /^View all Leave Management signals/ }).count()).toBe(0);
        await expect.poll(async () => employee.page.getByRole('heading')
          .filter({ hasText: String(journey.values.leaveRequestNo) }).innerText())
          .toContain('Rejected');
        expect(await mailpitIdsFor(intendedRecipients)).toEqual(mailBefore);
        employee.diagnostics.assertClean('Employee sees the rejected leave and reason live');
        departmentHead.diagnostics.assertClean('Department Head leave rejection workflow');
        hr.diagnostics.assertClean('HR leave rejection workflow');
      },
    });

    await captureAnnotatedStep(employee.page, 'employee', 'Employee sees leave rejection reason and unchanged balance', planned.length + 1, testInfo);
  } finally {
    await Promise.all([employee.context.close(), departmentHead.context.close(), hr.context.close()]);
  }
}

type LeaveCancellationStage = 'pending_dept' | 'pending_hr' | 'approved';
type PlannedLeaveStep = { name: string; actor: string; uiPath: string; expectedStatus: string };

async function runLeaveCancellationJourney(
  browser: Parameters<typeof actorPage>[0],
  testInfo: import('@playwright/test').TestInfo,
  cancellationStage: LeaveCancellationStage,
): Promise<void> {
  const actors = await loadActors();
  const employeeUser = actorByName(actors, 'employee');
  const departmentHeadUser = actorByName(actors, 'department_head');
  const hrUser = actorByName(actors, 'hr_officer');
  const balances = await employeeBalanceBaseline(employeeUser.email);
  expect(balances, 'the seeded Employee should resolve to one Vacation Leave balance row').toHaveLength(1);
  expect(balances[0].balance_id, 'E2E baseline should include opening Vacation Leave entitlement').not.toBeNull();
  expect(Number(balances[0].remaining), 'opening leave balance must cover one day').toBeGreaterThanOrEqual(1);
  expect(balances[0].leave_request_count, 'fresh baseline should not contain leave transactions').toBe(0);

  const employee = await actorPage(browser, employeeUser.actor);
  const departmentHead = await actorPage(browser, departmentHeadUser.actor);
  const hr = await actorPage(browser, hrUser.actor);
  const variant = `HR04-CANCEL-${cancellationStage.replace('_', '-').toUpperCase()}`;
  const journey = createJourneyContext(variant);
  const startDate = await nextBusinessDay(employeeUser.email);
  const reason = `${String(journey.values.runPrefix)} leave cancellation journey`;
  const deptRecipients = await eligibleRecipients(
    'leave.submitted', 'leave.approve_dept', 'leave.submitted.notification_roles', balances[0].department_id,
  );
  const hrRecipients = await eligibleRecipients(
    'leave.pending_hr', 'leave.approve_hr', 'leave.pending_hr.notification_roles',
  );
  expect(deptRecipients).toContain(departmentHeadUser.email);
  expect(hrRecipients).toContain(hrUser.email);
  const intendedRecipients = [...new Set([...deptRecipients, ...hrRecipients, employeeUser.email])];
  const mailBefore = await mailpitIdsFor(intendedRecipients);

  const planned: PlannedLeaveStep[] = [{
    name: 'Employee files a Vacation Leave request',
    actor: 'employee',
    uiPath: 'Profile menu → Leaves → New request',
    expectedStatus: 'pending_dept; balance and attendance unchanged',
  }];
  if (cancellationStage === 'pending_dept') {
    planned.push({
      name: 'Department Head follows the live badge and opens the pending request',
      actor: 'department_head',
      uiPath: 'Dashboard → Human Resources → Leave Management signal → Awaiting my action → request detail',
      expectedStatus: 'pending_dept; Approve/Reject visible; no decision yet',
    });
  } else {
    planned.push({
      name: 'Department Head follows the badge and approves',
      actor: 'department_head',
      uiPath: 'Dashboard → Human Resources → Leave Management signal → Awaiting my action → Approve',
      expectedStatus: 'pending_hr; HR receives actionable work',
    });
    planned.push({
      name: cancellationStage === 'pending_hr'
        ? 'HR follows the badge and opens the pending request'
        : 'HR follows the badge and approves',
      actor: 'hr_officer',
      uiPath: cancellationStage === 'pending_hr'
        ? 'Dashboard → Human Resources → Leave Management signal → Awaiting my action → request detail'
        : 'Dashboard → Human Resources → Leave Management signal → Awaiting my action → Approve',
      expectedStatus: cancellationStage === 'pending_hr'
        ? 'pending_hr; Approve/Reject visible; no decision yet'
        : 'approved; one day consumed and attendance marked on_leave',
    });
  }
  planned.push({
    name: `Employee cancels while ${cancellationStage}`,
    actor: 'employee',
    uiPath: 'Profile menu → Leaves → request detail → Cancel → Yes, cancel',
    expectedStatus: 'cancelled; pending approvals retired or approved balance/attendance reversed',
  });

  const noticeTypes = async (publicId: string, expectedStage: LeaveCancellationStage): Promise<void> => {
    const expectedTypes = ['leave.submitted'];
    if (expectedStage !== 'pending_dept') expectedTypes.push('leave.pending_hr');
    if (expectedStage === 'approved') expectedTypes.push('leave.approved');
    await expect.poll(
      async () => (await notificationRows(publicId)).map((notice) => notice.type).sort(),
      {
        message: 'queued leave notifications are persisted before recipient assertions',
        timeout: 15_000,
      },
    ).toEqual(expectedTypes.sort());
    const notices = await notificationRows(publicId);
    expect(notices.map((notice) => notice.type).sort()).toEqual(expectedTypes.sort());
    assertRecipientsExactly(
      notices.filter((notice) => notice.type === 'leave.submitted').map((notice) => notice.recipient_email),
      deptRecipients,
    );
    assertRecipientsExactly(
      notices.filter((notice) => notice.type === 'leave.pending_hr').map((notice) => notice.recipient_email),
      expectedStage === 'pending_dept' ? [] : hrRecipients,
    );
    assertRecipientsExactly(
      notices.filter((notice) => notice.type === 'leave.approved').map((notice) => notice.recipient_email),
      expectedStage === 'approved' ? [employeeUser.email] : [],
    );
    expect(notices.every((notice) => notice.link_to?.includes(publicId))).toBe(true);
  };

  const currentStatus = (): LeaveCancellationStage => cancellationStage === 'pending_dept'
    ? 'pending_dept'
    : cancellationStage === 'pending_hr' ? 'pending_hr' : 'approved';
  const statusLabel = (status: string): string => ({
    pending_dept: 'Pending dept',
    pending_hr: 'Pending hr',
    approved: 'Approved',
    cancelled: 'Cancelled',
  })[status] ?? status;
  const expectedObserver = cancellationStage === 'pending_dept' ? departmentHead : hr;
  let publicId = '';
  let requestNo = '';

  const openFromQueue = async (actor: ActorPage, status: string): Promise<void> => {
    await openLeaveApprovalFromBadge(actor, 1);
    await actor.page.getByRole('button', { name: 'Kanban view', exact: true }).click();
    const requestLink = actor.page.getByRole('link').filter({ hasText: requestNo });
    await expect(requestLink).toHaveCount(1);
    await requestLink.click();
    await expect(actor.page).toHaveURL(new RegExp(`/hr/leaves/${publicId}$`));
    await expect(actor.page.getByRole('heading').filter({ hasText: requestNo })).toContainText(statusLabel(status));
  };

  const assertSnapshotForStage = async (status: string): Promise<LeaveSnapshot> => {
    const snapshot = await expectLeaveStatus(reason, status);
    expect(snapshot.leave_request_no).toBe(requestNo);
    expect(snapshot.cancelled_by_email).toBeNull();
    expect(snapshot.cancelled_at).toBeNull();
    if (status === 'pending_dept') {
      expect(snapshot.dept_approver_email).toBeNull();
      expect(snapshot.hr_approver_email).toBeNull();
    } else {
      expect(snapshot.dept_approver_email).toBe(departmentHeadUser.email);
      expect(snapshot.hr_approver_email).toBe(status === 'approved' ? hrUser.email : null);
    }
    const hasApproval = status === 'approved';
    expect(Number(snapshot.balance_used)).toBe(Number(balances[0].used) + (hasApproval ? 1 : 0));
    expect(Number(snapshot.balance_remaining)).toBe(Number(balances[0].remaining) - (hasApproval ? 1 : 0));
    expect(snapshot.on_leave_attendance_count).toBe(hasApproval ? 1 : 0);
    return snapshot;
  };

  try {
    await Promise.all([openSelfServiceLeaves(employee), openDashboard(departmentHead), openDashboard(hr)]);
    const cleanRequests = await readOnlyDatabaseJson<Array<{ request_count: number }>>(`
      SELECT COUNT(*)::int AS request_count
        FROM leave_requests request
       WHERE request.employee_id = ${balances[0].employee_id}
    `);
    expect(cleanRequests[0]?.request_count).toBe(0);

    await runProcessStep({
      ...planned[0],
      page: employee.page,
      plannedSteps: planned,
      context: journey,
      testInfo,
      action: async () => {
        await employee.page.getByRole('button', { name: /New request/ }).click();
        const dialog = employee.page.getByRole('dialog', { name: 'File Leave Request' });
        await expect(dialog).toBeVisible();
        await dialog.getByRole('combobox', { name: /^Leave type/ }).selectOption({ label: 'Vacation Leave' });
        await dialog.getByLabel(/^Start date/).fill(startDate);
        await dialog.getByLabel(/^End date/).fill(startDate);
        await employee.page.getByLabel('Reason (optional)', { exact: true }).fill(reason);
        const responsePromise = employee.page.waitForResponse((response) =>
          response.url().includes('/api/v1/leaves/requests') && response.request().method() === 'POST',
        );
        await employee.page.getByRole('button', { name: 'Submit request', exact: true }).click();
        const response = await responsePromise;
        expect(response.status()).toBe(201);
        const requestLink = employee.page.getByRole('link', { name: /^View leave request / });
        await expect(requestLink).toHaveCount(1);
        requestNo = (await requestLink.getAttribute('aria-label'))?.replace(/^View leave request /, '') ?? '';
        const href = await requestLink.getAttribute('href');
        publicId = href?.split('/').at(-1) ?? '';
        expect(requestNo).toBeTruthy();
        expect(href).toMatch(/^\/self-service\/leaves\//);
        journey.values.employeeNo = balances[0].employee_no;
        journey.values.leaveRequestNo = requestNo;
        journey.values.leaveRequestPublicId = publicId;
        journey.values.startDate = startDate;
        journey.values.endDate = startDate;
        journey.values.leaveType = 'VL';
        journey.values.reason = reason;
        await requestLink.click();
        await expect(employee.page.getByRole('heading').filter({ hasText: requestNo })).toContainText('Pending dept');
      },
      verify: async () => {
        const snapshot = await assertSnapshotForStage('pending_dept');
        expect(snapshot.days).toBe('1.0');
        expect(await approvalRows(reason)).toEqual([
          expect.objectContaining({ step_order: 1, role_slug: 'department_head', action: 'pending', approver_email: null }),
          expect.objectContaining({ step_order: 2, role_slug: 'hr_officer', action: 'pending', approver_email: null }),
        ]);
        await expect(employee.page.getByRole('button', { name: 'Cancel', exact: true })).toBeVisible();
        await expect(employee.page.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
      },
      assertInvariants: async () => {
        await noticeTypes(publicId, 'pending_dept');
        expect(await mailpitIdsFor(intendedRecipients)).toEqual(mailBefore);
        employee.diagnostics.assertClean('Employee submits the cancellable leave request');
      },
    });

    if (cancellationStage === 'pending_dept') {
      await runProcessStep({
        ...planned[1],
        page: departmentHead.page,
        plannedSteps: planned,
        context: journey,
        testInfo,
        action: async () => openFromQueue(departmentHead, 'pending_dept'),
        verify: async () => {
          await assertSnapshotForStage('pending_dept');
          await expect(departmentHead.page.getByRole('button', { name: 'Approve', exact: true })).toBeEnabled();
          await expect(departmentHead.page.getByRole('button', { name: 'Reject', exact: true })).toBeEnabled();
        },
        assertInvariants: async () => {
          await noticeTypes(publicId, 'pending_dept');
          departmentHead.diagnostics.assertClean('Department Head opens the live pending request');
        },
      });
    } else {
      await runProcessStep({
        ...planned[1],
        page: departmentHead.page,
        plannedSteps: planned,
        context: journey,
        testInfo,
        action: async () => {
          await openFromQueue(departmentHead, 'pending_dept');
          await departmentHead.page.getByRole('button', { name: 'Approve', exact: true }).click();
          const confirmation = departmentHead.page.getByRole('dialog', { name: 'Approve leave request?' });
          await expect(confirmation).toBeVisible();
          const responsePromise = departmentHead.page.waitForResponse((response) =>
            response.url().endsWith(`/api/v1/leaves/requests/${publicId}/approve-dept`)
              && response.request().method() === 'PATCH',
          );
          await confirmation.getByRole('button', { name: 'Approve', exact: true }).click();
          expect((await responsePromise).status()).toBe(200);
        },
        verify: async () => {
          const snapshot = await assertSnapshotForStage('pending_hr');
          expect(snapshot.dept_approver_email).toBe(departmentHeadUser.email);
          expect(await approvalRows(reason)).toEqual([
            expect.objectContaining({ step_order: 1, role_slug: 'department_head', action: 'approved', approver_email: departmentHeadUser.email }),
            expect.objectContaining({ step_order: 2, role_slug: 'hr_officer', action: 'pending', approver_email: null }),
          ]);
          await expect(employee.page.getByRole('heading').filter({ hasText: requestNo })).toContainText('Pending hr');
          await expect(departmentHead.page.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
          await expect(departmentHead.page.getByRole('button', { name: 'Reject', exact: true })).toHaveCount(0);
        },
        assertInvariants: async () => {
          await noticeTypes(publicId, 'pending_hr');
          expect(await mailpitIdsFor(intendedRecipients)).toEqual(mailBefore);
          departmentHead.diagnostics.assertClean('Department Head approves the leave before cancellation');
        },
      });

      const hrStepIndex = 2;
      if (cancellationStage === 'pending_hr') {
        await runProcessStep({
          ...planned[hrStepIndex],
          page: hr.page,
          plannedSteps: planned,
          context: journey,
          testInfo,
          action: async () => openFromQueue(hr, 'pending_hr'),
          verify: async () => {
            await assertSnapshotForStage('pending_hr');
            await expect(hr.page.getByRole('button', { name: 'Approve', exact: true })).toBeEnabled();
            await expect(hr.page.getByRole('button', { name: 'Reject', exact: true })).toBeEnabled();
            await expect(employee.page.getByRole('button', { name: 'Cancel', exact: true })).toBeVisible();
          },
          assertInvariants: async () => {
            await noticeTypes(publicId, 'pending_hr');
            hr.diagnostics.assertClean('HR receives and opens pending leave through its badge');
          },
        });
      } else {
        await runProcessStep({
          ...planned[hrStepIndex],
          page: hr.page,
          plannedSteps: planned,
          context: journey,
          testInfo,
          action: async () => {
            await openFromQueue(hr, 'pending_hr');
            await hr.page.getByRole('button', { name: 'Approve', exact: true }).click();
            const confirmation = hr.page.getByRole('dialog', { name: 'Approve leave request?' });
            await expect(confirmation).toBeVisible();
            const responsePromise = hr.page.waitForResponse((response) =>
              response.url().endsWith(`/api/v1/leaves/requests/${publicId}/approve-hr`)
                && response.request().method() === 'PATCH',
            );
            await confirmation.getByRole('button', { name: 'Approve', exact: true }).click();
            expect((await responsePromise).status()).toBe(200);
          },
          verify: async () => {
            const snapshot = await assertSnapshotForStage('approved');
            expect(snapshot.hr_approver_email).toBe(hrUser.email);
            expect(await approvalRows(reason)).toEqual([
              expect.objectContaining({ step_order: 1, role_slug: 'department_head', action: 'approved', approver_email: departmentHeadUser.email }),
              expect.objectContaining({ step_order: 2, role_slug: 'hr_officer', action: 'approved', approver_email: hrUser.email }),
            ]);
            await expect(employee.page.getByRole('heading').filter({ hasText: requestNo })).toContainText('Approved');
            await expect(employee.page.getByRole('button', { name: 'Cancel', exact: true })).toBeVisible();
            await expect(hr.page.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
          },
          assertInvariants: async () => {
            await noticeTypes(publicId, 'approved');
            expect(await mailpitIdsFor(intendedRecipients)).toEqual(mailBefore);
            hr.diagnostics.assertClean('HR approves the leave before the owner cancels it');
          },
        });
      }
    }

    const cancellationIndex = planned.length - 1;
    await runProcessStep({
      ...planned[cancellationIndex],
      page: employee.page,
      plannedSteps: planned,
      context: journey,
      testInfo,
      action: async () => {
        const cancelButton = employee.page.getByRole('button', { name: 'Cancel', exact: true });
        await expect(cancelButton).toBeEnabled();
        await cancelButton.click();
        const confirmation = employee.page.getByRole('dialog', { name: 'Cancel leave request?' });
        await expect(confirmation).toBeVisible();
        const responsePromise = employee.page.waitForResponse((response) =>
          response.url().endsWith(`/api/v1/leaves/requests/${publicId}/cancel`)
            && response.request().method() === 'PATCH',
        );
        await confirmation.getByRole('button', { name: 'Yes, cancel', exact: true }).click();
        expect((await responsePromise).status()).toBe(200);
      },
      verify: async () => {
        const snapshot = await expectLeaveStatus(reason, 'cancelled');
        expect(snapshot.leave_request_no).toBe(requestNo);
        expect(snapshot.cancelled_by_email).toBe(employeeUser.email);
        expect(snapshot.cancelled_at).not.toBeNull();
        expect(snapshot.rejection_reason).toBeNull();
        expect(snapshot.dept_approver_email).toBe(cancellationStage === 'pending_dept' ? null : departmentHeadUser.email);
        expect(snapshot.hr_approver_email).toBe(cancellationStage === 'approved' ? hrUser.email : null);
        expect(Number(snapshot.balance_used)).toBe(Number(balances[0].used));
        expect(Number(snapshot.balance_remaining)).toBe(Number(balances[0].remaining));
        expect(snapshot.on_leave_attendance_count).toBe(0);
        const history = await approvalHistoryRows(reason);
        if (cancellationStage === 'pending_dept') {
          expect(history).toEqual([
            expect.objectContaining({ step_order: 1, role_slug: 'department_head', action: 'superseded', approver_email: null, is_current: false }),
            expect.objectContaining({ step_order: 2, role_slug: 'hr_officer', action: 'superseded', approver_email: null, is_current: false }),
          ]);
        } else if (cancellationStage === 'pending_hr') {
          expect(history).toEqual([
            expect.objectContaining({ step_order: 1, role_slug: 'department_head', action: 'approved', approver_email: departmentHeadUser.email, is_current: true }),
            expect.objectContaining({ step_order: 2, role_slug: 'hr_officer', action: 'superseded', approver_email: null, is_current: false }),
          ]);
        } else {
          expect(history).toEqual([
            expect.objectContaining({ step_order: 1, role_slug: 'department_head', action: 'approved', approver_email: departmentHeadUser.email, is_current: true }),
            expect.objectContaining({ step_order: 2, role_slug: 'hr_officer', action: 'approved', approver_email: hrUser.email, is_current: true }),
          ]);
        }
        const cancelAudit = (await requestAudits(reason)).find((audit) => audit.status === 'cancelled');
        expect(cancelAudit).toBeDefined();
        assertAuditActor({ actor_email: cancelAudit?.actor_email ?? undefined }, employeeUser.email);
        const employeeHeading = employee.page.getByRole('heading').filter({ hasText: requestNo });
        await expect(employeeHeading).toContainText('Cancelled');
        await expect(employee.page.getByRole('button', { name: 'Cancel', exact: true })).toHaveCount(0);
        await expect(employee.page.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
        await expect(employee.page.getByRole('button', { name: 'Reject', exact: true })).toHaveCount(0);
        await expect(expectedObserver.page.getByRole('heading').filter({ hasText: requestNo })).toContainText('Cancelled');
        await expect(expectedObserver.page.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
        await expect(expectedObserver.page.getByRole('button', { name: 'Reject', exact: true })).toHaveCount(0);
      },
      assertInvariants: async () => {
        await noticeTypes(publicId, currentStatus());
        expect(await mailpitIdsFor(intendedRecipients)).toEqual(mailBefore);
        await Promise.all([openHumanResourcesMenu(departmentHead), openHumanResourcesMenu(hr)]);
        await expect.poll(async () => departmentHead.page.getByRole('button', { name: /^View all Leave Management signals/ }).count()).toBe(0);
        await expect.poll(async () => hr.page.getByRole('button', { name: /^View all Leave Management signals/ }).count()).toBe(0);
        employee.diagnostics.assertClean('Employee cancels the request and sees restored final effects');
        departmentHead.diagnostics.assertClean('Department Head sees the cancelled request live');
        hr.diagnostics.assertClean('HR sees the cancelled request live');
      },
    });
  } finally {
    await Promise.all([employee.context.close(), departmentHead.context.close(), hr.context.close()]);
  }
}

test('HR04-REJECT-DEPT — Department Head rejection notifies Employee and stops the chain', async ({ browser }, testInfo) => {
  test.setTimeout(120_000);
  await runLeaveRejectionJourney(browser, testInfo, 'department_head');
});

test('HR04-REJECT-HR — HR rejection notifies Employee after the Department Head approval', async ({ browser }, testInfo) => {
  test.setTimeout(120_000);
  await runLeaveRejectionJourney(browser, testInfo, 'hr_officer');
});

test('HR04-CANCEL-PENDING-DEPT — Employee cancels before the Department Head acts', async ({ browser }, testInfo) => {
  test.setTimeout(120_000);
  await runLeaveCancellationJourney(browser, testInfo, 'pending_dept');
});

test('HR04-CANCEL-PENDING-HR — Employee cancels after Department Head approval', async ({ browser }, testInfo) => {
  test.setTimeout(120_000);
  await runLeaveCancellationJourney(browser, testInfo, 'pending_hr');
});

test('HR04-CANCEL-APPROVED — Employee cancels approved leave and restores balance and attendance', async ({ browser }, testInfo) => {
  test.setTimeout(120_000);
  await runLeaveCancellationJourney(browser, testInfo, 'approved');
});

test('HR04-INSUFFICIENT-BALANCE — Employee cannot submit more leave than the annual balance', async ({ browser }, testInfo) => {
  test.setTimeout(120_000);
  const actors = await loadActors();
  const employeeUser = actorByName(actors, 'employee');
  const departmentHeadUser = actorByName(actors, 'department_head');
  const hrUser = actorByName(actors, 'hr_officer');
  const balances = await employeeBalanceBaseline(employeeUser.email);
  expect(balances, 'the seeded Employee should resolve to one Vacation Leave balance row').toHaveLength(1);
  expect(balances[0].balance_id, 'E2E baseline should include opening Vacation Leave entitlement').not.toBeNull();
  expect(Number(balances[0].remaining), 'the E2E baseline must expose a measurable Vacation Leave balance').toBeGreaterThanOrEqual(0);
  expect(balances[0].leave_request_count, 'fresh baseline should not contain leave transactions').toBe(0);

  const employee = await actorPage(browser, employeeUser.actor);
  const departmentHead = await actorPage(browser, departmentHeadUser.actor);
  const hr = await actorPage(browser, hrUser.actor);
  const journey = createJourneyContext('HR04-INSUFFICIENT-BALANCE');
  const startDate = await nextBusinessDay(employeeUser.email);
  expect(Number(startDate.slice(0, 4)), 'the selected date must use the baseline leave-balance year').toBe(balances[0].balance_year);
  const window = await dateExceedingLeaveBalance(startDate, Number(balances[0].balance_year), Number(balances[0].remaining));
  expect(window.days, 'requested business days should exceed the employee annual leave balance').toBeGreaterThan(Number(balances[0].remaining));
  const reason = `${String(journey.values.runPrefix)} insufficient leave balance`;
  const deptRecipients = await eligibleRecipients(
    'leave.submitted', 'leave.approve_dept', 'leave.submitted.notification_roles', balances[0].department_id,
  );
  const hrRecipients = await eligibleRecipients(
    'leave.pending_hr', 'leave.approve_hr', 'leave.pending_hr.notification_roles',
  );
  expect(deptRecipients).toContain(departmentHeadUser.email);
  expect(hrRecipients).toContain(hrUser.email);
  const intendedRecipients = [...new Set([...deptRecipients, ...hrRecipients, employeeUser.email])];
  const noticesBefore = await leaveNoticeIdsFor(intendedRecipients);
  const mailBefore = await mailpitIdsFor(intendedRecipients);
  const planned = [{
    name: 'Employee attempts to submit Vacation Leave beyond the available balance',
    actor: 'employee',
    uiPath: 'Profile menu → Leaves → New request → Submit request',
    expectedStatus: 'submission rejected with 422; no leave record, approval, audit, notification, email, balance or attendance mutation',
  }];

  try {
    await Promise.all([openSelfServiceLeaves(employee), openDashboard(departmentHead), openDashboard(hr)]);
    await Promise.all([openHumanResourcesMenu(departmentHead), openHumanResourcesMenu(hr)]);
    await expect(departmentHead.page.getByRole('button', { name: /^View all Leave Management signals/ })).toHaveCount(0);
    await expect(hr.page.getByRole('button', { name: /^View all Leave Management signals/ })).toHaveCount(0);

    await runProcessStep({
      ...planned[0],
      page: employee.page,
      plannedSteps: planned,
      context: journey,
      testInfo,
      action: async () => {
        await employee.page.getByRole('button', { name: /New request/ }).click();
        const dialog = employee.page.getByRole('dialog', { name: 'File Leave Request' });
        await expect(dialog).toBeVisible();
        await dialog.getByRole('combobox', { name: /^Leave type/ }).selectOption({ label: 'Vacation Leave' });
        await dialog.getByLabel(/^Start date/).fill(startDate);
        await dialog.getByLabel(/^End date/).fill(window.date);
        await employee.page.getByLabel('Reason (optional)', { exact: true }).fill(reason);
        await expect(employee.page.getByText('Estimated days to be deducted')).toBeVisible();
        const estimatedDaysLabel = `${window.days} day${window.days === 1 ? '' : 's'}`;
        await expect(dialog.getByText(estimatedDaysLabel, { exact: true }), 'the form estimate should match server working-day calculation').toBeVisible();

        const responsePromise = employee.page.waitForResponse((response) =>
          response.url().includes('/api/v1/leaves/requests') && response.request().method() === 'POST',
        );
        employee.diagnostics.expectConsoleErrorOnceContaining('422 (Unprocessable Content) at /api/v1/leaves/requests');
        await dialog.getByRole('button', { name: 'Submit request', exact: true }).click();
        const response = await responsePromise;
        expect(response.status(), 'insufficient balance should be a business-rule 422, not a server error').toBe(422);
        const body = await response.json() as { message?: string };
        expect(body.message).toContain('Insufficient leave balance for');
        expect(body.message).toContain(String(balances[0].balance_year));
        expect(body.message).toContain(`${window.days} requested`);
        await expect(employee.page.getByText('Failed to submit leave request.', { exact: true })).toBeVisible();
        journey.values.employeeNo = balances[0].employee_no;
        journey.values.leaveType = 'VL';
        journey.values.startDate = startDate;
        journey.values.endDate = window.date;
        journey.values.requestedDays = window.days;
        journey.values.remainingDays = Number(balances[0].remaining);
        journey.values.apiRejection = body.message;
      },
      verify: async () => {
        await expect(employee.page.getByRole('dialog', { name: 'File Leave Request' })).toBeVisible();
        await expect(employee.page.getByRole('button', { name: 'Submit request', exact: true })).toBeEnabled();
        await expect(employee.page.getByRole('link', { name: /^View leave request / })).toHaveCount(0);
      },
      assertInvariants: async () => {
        const afterBalances = await employeeBalanceBaseline(employeeUser.email);
        expect(afterBalances).toHaveLength(1);
        expect(afterBalances[0].used).toBe(balances[0].used);
        expect(afterBalances[0].remaining).toBe(balances[0].remaining);
        expect(afterBalances[0].leave_request_count).toBe(0);
        const transactionRows = await readOnlyDatabaseJson<Array<{ requests: number; approvals: number; audits: number; leave_attendance: number }>>(`
          SELECT
            (SELECT COUNT(*)::int FROM leave_requests WHERE employee_id = ${balances[0].employee_id}) AS requests,
            (SELECT COUNT(*)::int FROM approval_records WHERE approvable_type ILIKE '%LeaveRequest') AS approvals,
            (SELECT COUNT(*)::int FROM audit_logs WHERE model_type ILIKE '%LeaveRequest') AS audits,
            (SELECT COUNT(*)::int FROM attendances
              WHERE employee_id = ${balances[0].employee_id}
                AND date BETWEEN ${sqlText(startDate)}::date AND ${sqlText(window.date)}::date
                AND status = 'on_leave' AND deleted_at IS NULL) AS leave_attendance
        `);
        expect(transactionRows).toEqual([{ requests: 0, approvals: 0, audits: 0, leave_attendance: 0 }]);
        expect(await leaveNoticeIdsFor(intendedRecipients)).toEqual(noticesBefore);
        expect(await mailpitIdsFor(intendedRecipients)).toEqual(mailBefore);
        await expect(departmentHead.page.getByRole('button', { name: /^View all Leave Management signals/ })).toHaveCount(0);
        await expect(hr.page.getByRole('button', { name: /^View all Leave Management signals/ })).toHaveCount(0);
        employee.diagnostics.assertClean('Employee receives a controlled insufficient-balance refusal');
        departmentHead.diagnostics.assertClean('Department Head receives no leave approval hand-off');
        hr.diagnostics.assertClean('HR receives no leave approval hand-off');
      },
    });
    await captureAnnotatedStep(employee.page, 'employee', 'Employee sees the insufficient-balance response with no request created', 1, testInfo);
  } finally {
    await Promise.all([employee.context.close(), departmentHead.context.close(), hr.context.close()]);
  }
});
