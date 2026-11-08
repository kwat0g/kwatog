import { test, expect } from './fixtures';
import { actorPage, loadActors } from './actor-session';
import type { E2EActor } from './actor-session';
import { createJourneyContext, runProcessStep } from './process-runner';
import { assertAuditActor, assertRecipientsExactly } from './helpers/process-invariants';
import { readOnlyDatabaseJson } from './helpers/database-reader';
import { readApiJson } from './helpers/api-reader';
import { listMailpitMessages } from './helpers/mailpit-reader';
import { captureAnnotatedStep } from './helpers/annotated-screenshot';
import { clearClock, freezeClock } from './helpers/clock';

type ActorPage = Awaited<ReturnType<typeof actorPage>>;

type PunchBaseline = {
  employee_id: number;
  employee_no: string;
  employee_name: string;
  work_date: string;
  shift_name: string;
  shift_start: string;
  shift_end: string;
  break_minutes: number;
  grace_minutes: number;
  is_extended: boolean;
  auto_ot_hours: number | null;
  active_assignment_count: number;
  latitude: number;
  longitude: number;
  radius_m: number;
  accuracy_cap_m: number;
};

type PunchSnapshot = {
  employee_id: number;
  employee_no: string;
  attendance_id: number | null;
  attendance_status: string | null;
  time_in_local: string | null;
  time_out_local: string | null;
  regular_hours: string | null;
  overtime_hours: string | null;
  tardiness_minutes: number | null;
  undertime_minutes: number | null;
  punches: Array<{
    direction: 'in' | 'out';
    punched_at_local: string;
    latitude: number;
    longitude: number;
    accuracy_m: number;
    distance_m: number;
  }>;
  audits: Array<{ action: string; actor_email: string | null }>;
  overtime_count: number;
  attendance_notification_count: number;
};

type OvertimeBaseline = {
  employee_id: number;
  employee_no: string;
  employee_name: string;
  department_id: number;
  work_date: string;
  minimum_hours: number;
  maximum_hours: number;
  request_future_days: number;
  existing_request_count: number;
};

type OvertimeSnapshot = {
  request_id: number;
  employee_id: number;
  employee_no: string;
  department_id: number;
  requester_email: string;
  date: string;
  hours_requested: string;
  reason: string;
  status: string;
  approved_by_email: string | null;
  approved_at: string | null;
  rejection_reason: string | null;
  is_auto_detected: boolean;
  attendance_count: number;
};

type OvertimeAuditRow = {
  action: string;
  actor_email: string | null;
  status: string | null;
};

type OvertimeNotificationRow = {
  type: string;
  recipient_email: string;
  link_to: string | null;
};

const plannedSteps = [
  { name: 'Employee opens the self-service time clock', actor: 'employee', uiPath: 'Profile menu → Dashboard', expectedStatus: 'Day Shift shown; not clocked in; Time in enabled' },
  { name: 'HR opens the attendance list for the selected workday', actor: 'hr_officer', uiPath: 'Human Resources → Attendance & DTR → Date filter', expectedStatus: 'no row for this employee before the first punch' },
  { name: 'Employee times in and HR sees the record update live', actor: 'employee / hr_officer observer', uiPath: 'Self-service Dashboard → Time in; HR Attendance & DTR remains open', expectedStatus: 'present; first punch in; time_out empty' },
  { name: 'Employee times out and HR sees the DTR update live', actor: 'employee / hr_officer observer', uiPath: 'Self-service Dashboard → Time out; HR Attendance & DTR remains open', expectedStatus: 'present; in/out punches recorded; regular hours computed' },
  { name: 'Employee checks the completed record in DTR', actor: 'employee', uiPath: 'Profile menu → DTR', expectedStatus: 'same date, time in, time out, hours and Present status' },
];

const overtimeSteps = [
  { name: 'Employee submits a self-service overtime request', actor: 'employee', uiPath: 'Profile menu → Overtime → Apply for OT → Submit for approval', expectedStatus: 'pending; Employee can cancel; only eligible Department Head receives the approval notice' },
  { name: 'Department Head opens the dashboard hand-off and approves', actor: 'department_head', uiPath: 'Dashboard → Pending Approvals → linked Overtime row → Approve', expectedStatus: 'approved; Employee sees the updated status without refreshing; no further approval action' },
];

const overtimeRejectSteps = [
  overtimeSteps[0],
  { name: 'Department Head follows the dashboard hand-off and rejects with a reason', actor: 'department_head', uiPath: 'Dashboard → Pending Approvals → linked Overtime row → Reject → Confirm reject', expectedStatus: 'rejected with reason; Employee sees the decision without refreshing; no further action' },
];

const csvImportSteps = [
  { name: 'HR opens the biometric DTR import while Employee DTR is open', actor: 'hr_officer; employee observer', uiPath: 'Human Resources → Attendance & DTR → Import DTR; Employee Profile menu → DTR', expectedStatus: 'import form ready; no attendance record exists yet' },
  { name: 'HR uploads one valid and one invalid biometric row', actor: 'hr_officer', uiPath: 'Import DTR → Browse files → select CSV → Import', expectedStatus: '2 rows; 1 imported and 1 skipped with the unknown employee number reported' },
  { name: 'Employee DTR receives the imported record live', actor: 'employee observer', uiPath: 'Already-open Profile menu → DTR', expectedStatus: 'Present DTR row shows the imported punches and computed hours without refresh' },
  { name: 'Employee reloads DTR and confirms the imported record persists', actor: 'employee', uiPath: 'Reload the open DTR page', expectedStatus: 'same DTR date, punches, hours and status remain after reload' },
];

function actorByName(actors: E2EActor[], actor: string): E2EActor {
  const found = actors.find((candidate) => candidate.actor === actor);
  if (!found) throw new Error(`Required seeded E2E actor is missing: ${actor}`);
  return found;
}

function sqlText(value: string): string {
  return `'${value.replaceAll("'", "''")}'`;
}

async function overtimeBaseline(employeeEmail: string): Promise<OvertimeBaseline> {
  const rows = await readOnlyDatabaseJson<OvertimeBaseline[]>(`
    WITH target AS (
      SELECT employee.id AS employee_id,
             employee.employee_no,
             employee.first_name || ' ' || employee.last_name AS employee_name,
             employee.department_id,
             timezone('Asia/Manila', CURRENT_TIMESTAMP)::date AS work_date
        FROM users actor
        JOIN employees employee ON employee.id = actor.employee_id
       WHERE actor.email = ${sqlText(employeeEmail)}
    )
    SELECT target.employee_id,
           target.employee_no,
           target.employee_name,
           target.department_id,
           target.work_date::text AS work_date,
           (SELECT (setting.value #>> '{}')::numeric
              FROM settings setting WHERE setting.key = 'attendance.ot.request_min_hours') AS minimum_hours,
           (SELECT (setting.value #>> '{}')::numeric
              FROM settings setting WHERE setting.key = 'attendance.ot.request_max_hours') AS maximum_hours,
           (SELECT (setting.value #>> '{}')::int
              FROM settings setting WHERE setting.key = 'attendance.ot.request_future_days') AS request_future_days,
           (SELECT COUNT(*)::int FROM overtime_requests request
             WHERE request.employee_id = target.employee_id) AS existing_request_count
      FROM target
  `);
  expect(rows, 'the seeded Employee and overtime reference settings should resolve').toHaveLength(1);
  return rows[0];
}

async function eligibleOvertimeSubmissionRecipients(employeeEmail: string): Promise<string[]> {
  const rows = await readOnlyDatabaseJson<Array<{ email: string }>>(`
    WITH requester AS (
      SELECT employee.id AS employee_id, employee.department_id
        FROM users actor
        JOIN employees employee ON employee.id = actor.employee_id
       WHERE actor.email = ${sqlText(employeeEmail)}
    )
    SELECT DISTINCT recipient.email
      FROM requester
      JOIN settings setting ON setting.key = 'attendance.overtime_submitted.notification_roles'
      JOIN users recipient ON recipient.is_active = true AND recipient.employee_id <> requester.employee_id
      JOIN roles role ON role.id = recipient.role_id
       AND role.slug IN (SELECT jsonb_array_elements_text(setting.value::jsonb))
      JOIN employees recipient_employee ON recipient_employee.id = recipient.employee_id
       AND recipient_employee.department_id = requester.department_id
      JOIN role_permissions role_permission ON role_permission.role_id = role.id
      JOIN permissions permission ON permission.id = role_permission.permission_id
       AND permission.slug = 'attendance.ot.approve'
     ORDER BY recipient.email
  `);
  return rows.map((row) => row.email);
}

async function overtimeSnapshot(reason: string): Promise<OvertimeSnapshot[]> {
  return readOnlyDatabaseJson<OvertimeSnapshot[]>(`
    SELECT request.id AS request_id,
           employee.id AS employee_id,
           employee.employee_no,
           employee.department_id,
           requester.email AS requester_email,
           request.date::text AS date,
           request.hours_requested::text AS hours_requested,
           request.reason,
           request.status,
           approver.email AS approved_by_email,
           request.approved_at::text AS approved_at,
           request.rejection_reason,
           request.is_auto_detected,
           (SELECT COUNT(*)::int FROM attendances attendance
             WHERE attendance.employee_id = request.employee_id
               AND attendance.date = request.date) AS attendance_count
      FROM overtime_requests request
      JOIN employees employee ON employee.id = request.employee_id
      JOIN users requester ON requester.employee_id = employee.id
      LEFT JOIN users approver ON approver.id = request.approved_by
     WHERE request.reason = ${sqlText(reason)}
     ORDER BY request.id
  `);
}

async function overtimeAuditRows(reason: string): Promise<OvertimeAuditRow[]> {
  return readOnlyDatabaseJson<OvertimeAuditRow[]>(`
    SELECT audit.action,
           actor.email AS actor_email,
           COALESCE(audit.new_values->>'status', audit.old_values->>'status') AS status
      FROM audit_logs audit
      JOIN overtime_requests request
        ON audit.model_type ILIKE '%OvertimeRequest'
       AND audit.model_id = request.id
      LEFT JOIN users actor ON actor.id = audit.user_id
     WHERE request.reason = ${sqlText(reason)}
     ORDER BY audit.id
  `);
}

async function overtimeNotificationRows(publicId: string): Promise<OvertimeNotificationRow[]> {
  return readOnlyDatabaseJson<OvertimeNotificationRow[]>(`
    SELECT notice.type,
           recipient.email AS recipient_email,
           notice.data->>'link_to' AS link_to
      FROM notifications notice
      JOIN users recipient
        ON recipient.id = notice.notifiable_id
       AND notice.notifiable_type = 'App\\Modules\\Auth\\Models\\User'
     WHERE notice.data->>'entity_type' = 'overtime_request'
       AND notice.data->>'entity_id' = ${sqlText(publicId)}
     ORDER BY notice.created_at, notice.id
  `);
}

async function openSelfServiceOvertime(employee: ActorPage): Promise<void> {
  await employee.page.goto('/dashboard');
  await expect(employee.page.getByRole('navigation', { name: 'Modules' })).toBeVisible();
  await employee.page.getByRole('button', { name: /^Profile menu for / }).click();
  await employee.page.getByRole('menuitem', { name: 'Overtime', exact: true }).click();
  await expect(employee.page).toHaveURL(/\/self-service\/overtime(?:\?|$)/);
  await expect(employee.page.getByRole('heading', { name: 'Overtime Requests', exact: true })).toBeVisible();
}

async function openOvertimeApprovalDashboardLink(
  departmentHead: ActorPage,
  workDate: string,
  publicId: string,
): Promise<void> {
  await departmentHead.page.goto('/dashboard');
  await expect(departmentHead.page.getByRole('navigation', { name: 'Modules' })).toBeVisible();
  const humanResourcesBadge = departmentHead.page.getByRole('button', { name: /^Human Resources:/ });
  await expect(humanResourcesBadge).toHaveAccessibleName(/Overtime requests awaiting your approval: 1/);

  const pendingApprovals = departmentHead.page.getByRole('heading', { name: 'Pending Approvals', exact: true });
  await expect(pendingApprovals).toBeVisible();
  const approvalRow = departmentHead.page.getByRole('row')
    .filter({ hasText: 'Overtime' })
    .filter({ hasText: workDate });
  await expect(approvalRow, 'the Department Head should find the submitted request in Pending Approvals').toHaveCount(1);
  const linkedRecord = approvalRow.getByRole('link');
  await expect(linkedRecord).toHaveAttribute('href', `/hr/attendance/overtime/${publicId}`);
  await linkedRecord.click();
  await expect(departmentHead.page).toHaveURL(`/hr/attendance/overtime/${publicId}`);
}

async function punchBaseline(email: string): Promise<PunchBaseline> {
  const rows = await readOnlyDatabaseJson<PunchBaseline[]>(`
    WITH target_employee AS (
      SELECT employee.id AS employee_id,
             employee.employee_no,
             employee.first_name || ' ' || employee.last_name AS employee_name
        FROM users actor
        JOIN employees employee ON employee.id = actor.employee_id
       WHERE actor.email = ${sqlText(email)}
    ), candidates AS (
      SELECT employee.employee_id,
             (timezone('Asia/Manila', CURRENT_TIMESTAMP)::date + day_offset.day)::date AS work_date
        FROM target_employee employee
        CROSS JOIN generate_series(1, 90) AS day_offset(day)
       WHERE EXTRACT(ISODOW FROM timezone('Asia/Manila', CURRENT_TIMESTAMP)::date + day_offset.day) <> 7
         AND NOT EXISTS (
           SELECT 1 FROM holidays holiday
            WHERE holiday.deleted_at IS NULL
              AND (holiday.date = (timezone('Asia/Manila', CURRENT_TIMESTAMP)::date + day_offset.day)::date
                OR (holiday.is_recurring
                  AND EXTRACT(MONTH FROM holiday.date) = EXTRACT(MONTH FROM timezone('Asia/Manila', CURRENT_TIMESTAMP)::date + day_offset.day)
                  AND EXTRACT(DAY FROM holiday.date) = EXTRACT(DAY FROM timezone('Asia/Manila', CURRENT_TIMESTAMP)::date + day_offset.day)))
         )
         AND NOT EXISTS (
           SELECT 1 FROM attendances attendance
            WHERE attendance.employee_id = employee.employee_id
              AND attendance.date = (timezone('Asia/Manila', CURRENT_TIMESTAMP)::date + day_offset.day)::date
         )
         AND NOT EXISTS (
           SELECT 1 FROM employee_shift_assignments assignment
            WHERE assignment.employee_id = employee.employee_id
              AND assignment.effective_date <= (timezone('Asia/Manila', CURRENT_TIMESTAMP)::date + day_offset.day)::date
              AND (assignment.end_date IS NULL OR assignment.end_date >= (timezone('Asia/Manila', CURRENT_TIMESTAMP)::date + day_offset.day)::date)
              AND assignment.working_days IS NOT NULL
              AND jsonb_typeof(assignment.working_days::jsonb) = 'array'
              AND jsonb_array_length(assignment.working_days::jsonb) > 0
              AND NOT EXISTS (
                SELECT 1 FROM jsonb_array_elements_text(assignment.working_days::jsonb) AS working_day(iso_day)
                 WHERE working_day.iso_day::int = EXTRACT(ISODOW FROM timezone('Asia/Manila', CURRENT_TIMESTAMP)::date + day_offset.day)::int
              )
         )
       ORDER BY work_date
       LIMIT 1
    )
    SELECT employee.employee_id,
           employee.employee_no,
           employee.employee_name,
           candidate.work_date::text AS work_date,
           shift.name AS shift_name,
           shift.start_time::text AS shift_start,
           shift.end_time::text AS shift_end,
           shift.break_minutes::int AS break_minutes,
           shift.grace_minutes::int AS grace_minutes,
           shift.is_extended,
           shift.auto_ot_hours::float AS auto_ot_hours,
           (SELECT COUNT(*)::int FROM employee_shift_assignments assignment
             WHERE assignment.employee_id = employee.employee_id
               AND assignment.effective_date <= candidate.work_date
               AND (assignment.end_date IS NULL OR assignment.end_date >= candidate.work_date)) AS active_assignment_count,
           (SELECT (setting.value #>> '{}')::float FROM settings setting WHERE setting.key = 'company.latitude') AS latitude,
           (SELECT (setting.value #>> '{}')::float FROM settings setting WHERE setting.key = 'company.longitude') AS longitude,
           (SELECT (setting.value #>> '{}')::float FROM settings setting WHERE setting.key = 'company.punch_radius_m') AS radius_m,
           (SELECT (setting.value #>> '{}')::float FROM settings setting WHERE setting.key = 'company.punch_max_accuracy_m') AS accuracy_cap_m
      FROM target_employee employee
      JOIN candidates candidate ON candidate.employee_id = employee.employee_id
      LEFT JOIN LATERAL (
        SELECT assignment.shift_id
          FROM employee_shift_assignments assignment
         WHERE assignment.employee_id = employee.employee_id
           AND assignment.effective_date <= candidate.work_date
           AND (assignment.end_date IS NULL OR assignment.end_date >= candidate.work_date)
         ORDER BY assignment.effective_date DESC, assignment.id DESC
         LIMIT 1
      ) assignment ON true
      LEFT JOIN shifts default_shift ON default_shift.is_default = true AND default_shift.is_active = true
      JOIN shifts shift ON shift.id = COALESCE(assignment.shift_id, default_shift.id)
  `);
  expect(rows, 'seeded employee and an unoccupied non-holiday workday should resolve').toHaveLength(1);
  const baseline = rows[0];
  expect(baseline.shift_name, 'selected shift must be active and resolved from employee assignment/default').toBeTruthy();
  expect(baseline.latitude).toEqual(expect.any(Number));
  expect(baseline.longitude).toEqual(expect.any(Number));
  expect(baseline.radius_m).toBeGreaterThan(0);
  expect(baseline.accuracy_cap_m).toBeGreaterThan(0);
  expect(baseline.is_extended, 'the selected baseline shift should not create an OT variant').toBe(false);
  expect(baseline.auto_ot_hours).toBeNull();
  return baseline;
}

async function punchSnapshot(email: string, date: string): Promise<PunchSnapshot[]> {
  return readOnlyDatabaseJson<PunchSnapshot[]>(`
    WITH target_employee AS (
      SELECT actor.id AS user_id, employee.id AS employee_id, employee.employee_no
        FROM users actor
        JOIN employees employee ON employee.id = actor.employee_id
       WHERE actor.email = ${sqlText(email)}
    )
    SELECT employee.employee_id,
           employee.employee_no,
           attendance.id AS attendance_id,
           attendance.status AS attendance_status,
           CASE WHEN attendance.time_in IS NULL THEN NULL ELSE to_char(attendance.time_in, 'YYYY-MM-DD HH24:MI:SS') END AS time_in_local,
           CASE WHEN attendance.time_out IS NULL THEN NULL ELSE to_char(attendance.time_out, 'YYYY-MM-DD HH24:MI:SS') END AS time_out_local,
           attendance.regular_hours::text AS regular_hours,
           attendance.overtime_hours::text AS overtime_hours,
           attendance.tardiness_minutes,
           attendance.undertime_minutes,
           COALESCE((
             SELECT json_agg(json_build_object(
               'direction', punch.direction,
               'punched_at_local', to_char(punch.punched_at, 'YYYY-MM-DD HH24:MI:SS'),
               'latitude', punch.latitude::float,
               'longitude', punch.longitude::float,
               'accuracy_m', punch.accuracy_m::float,
               'distance_m', punch.distance_m::float
             ) ORDER BY punch.punched_at, punch.id)
               FROM attendance_punches punch
              WHERE punch.attendance_id = attendance.id
           ), '[]'::json) AS punches,
           COALESCE((
             SELECT json_agg(json_build_object('action', audit.action, 'actor_email', actor.email) ORDER BY audit.id)
               FROM audit_logs audit
               LEFT JOIN users actor ON actor.id = audit.user_id
              WHERE (audit.model_id = attendance.id AND audit.model_type ILIKE '%Attendance')
                 OR (audit.model_type ILIKE '%AttendancePunch'
                   AND audit.model_id IN (SELECT punch.id FROM attendance_punches punch WHERE punch.attendance_id = attendance.id))
           ), '[]'::json) AS audits,
           (SELECT COUNT(*)::int FROM overtime_requests overtime
             WHERE overtime.employee_id = employee.employee_id AND overtime.date = ${sqlText(date)}::date) AS overtime_count,
           (SELECT COUNT(*)::int FROM notifications notification
             WHERE notification.data->>'subject_type' ILIKE '%Attendance%'
               AND notification.data->>'subject_id' = attendance.id::text) AS attendance_notification_count
      FROM target_employee employee
      LEFT JOIN attendances attendance
        ON attendance.employee_id = employee.employee_id AND attendance.date = ${sqlText(date)}::date
  `);
}

async function assertSnapshot(email: string, date: string, expectedPunchCount: number): Promise<PunchSnapshot> {
  let rows: PunchSnapshot[] = [];
  await expect.poll(async () => {
    rows = await punchSnapshot(email, date);
    return rows[0]?.punches.length ?? -1;
  }, { timeout: 15_000, message: `employee DTR should contain ${expectedPunchCount} UI-recorded punch(es)` }).toBe(expectedPunchCount);
  if (!rows[0]) throw new Error(`Attendance snapshot for ${email} on ${date} disappeared.`);
  return rows[0];
}

async function assertImportedDtr(
  employeeEmail: string,
  employeeNo: string,
  hrEmail: string,
  date: string,
  shiftStart: string,
  shiftEnd: string,
  unknownEmployeeNo: string,
): Promise<void> {
  const rows = await punchSnapshot(employeeEmail, date);
  expect(rows).toHaveLength(1);
  const snapshot = rows[0];
  expect(snapshot).toMatchObject({
    employee_no: employeeNo,
    attendance_id: expect.any(Number),
    attendance_status: 'present',
    time_in_local: localDateTime(date, shiftStart),
    time_out_local: localDateTime(date, shiftEnd),
    regular_hours: '7.50',
    overtime_hours: '0.00',
    tardiness_minutes: 0,
    undertime_minutes: 0,
    punches: [],
    overtime_count: 0,
    attendance_notification_count: 0,
  });
  expect(snapshot.attendance_id).toBeGreaterThan(0);
  expect(snapshot.audits.length, 'the imported DTR should have an audit entry').toBeGreaterThan(0);
  for (const audit of snapshot.audits) {
    assertAuditActor({ actor_email: audit.actor_email ?? undefined }, hrEmail);
  }

  const rejectedEmployeeRows = await readOnlyDatabaseJson<Array<{ employee_no: string }>>(`
    SELECT employee_no
      FROM employees
     WHERE employee_no = ${sqlText(unknownEmployeeNo)}
  `);
  expect(rejectedEmployeeRows, 'the invalid CSV row must not create employee master data').toHaveLength(0);
}

function localInstant(date: string, time: string): string {
  return `${date}T${time.slice(0, 8)}+08:00`;
}

function localDateTime(date: string, time: string): string {
  return `${date} ${time.slice(0, 8)}`;
}

function manilaTime(value: string): string {
  return new Intl.DateTimeFormat('en-US', {
    timeZone: 'Asia/Manila',
    hour: 'numeric',
    minute: '2-digit',
  }).format(new Date(value));
}

function dateLabel(date: string): string {
  return new Intl.DateTimeFormat('en-US', {
    timeZone: 'Asia/Manila',
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  }).format(new Date(`${date}T12:00:00+08:00`));
}

async function openEmployeeClock(employee: ActorPage): Promise<void> {
  await employee.page.goto('/dashboard');
  await expect(employee.page.getByRole('navigation', { name: 'Modules' })).toBeVisible();
  await employee.page.getByRole('button', { name: /^Profile menu for / }).click();
  await employee.page.getByRole('menuitem', { name: 'Dashboard', exact: true }).click();
  await expect(employee.page).toHaveURL(/\/self-service(?:\?|$)/);
  await expect(employee.page.getByRole('heading', { name: 'Time in / out', exact: true })).toBeVisible();
}

async function openAttendanceList(hr: ActorPage, date: string, employeeNo: string): Promise<void> {
  await hr.page.goto('/dashboard');
  await expect(hr.page.getByRole('navigation', { name: 'Modules' })).toBeVisible();
  const nav = hr.page.getByRole('navigation', { name: 'Modules' });
  const attendanceLink = nav.locator('a[href="/hr/attendance"]');
  if (!(await attendanceLink.isVisible())) {
    const section = hr.page.locator('[data-module="Human Resources"]');
    const toggle = section.getByRole('button');
    if (await toggle.count() !== 1) throw new Error('Human Resources sidebar section did not resolve uniquely.');
    if (await toggle.getAttribute('aria-expanded') !== 'true') await toggle.click();
  }
  await expect(attendanceLink).toBeVisible();
  await attendanceLink.click();
  await expect(hr.page).toHaveURL(/\/hr\/attendance(?:\?|$)/);
  await expect(hr.page.getByRole('heading', { name: 'Daily Time Records', exact: true })).toBeVisible();

  await hr.page.getByLabel('Date from').fill(date);
  const filtered = hr.page.waitForResponse((response) => {
    const url = new URL(response.url());
    return url.pathname.endsWith('/api/v1/attendance/attendances')
      && url.searchParams.get('from') === date
      && url.searchParams.get('to') === date;
  });
  await hr.page.getByLabel('Date to').fill(date);
  const response = await filtered;
  expect(response.status(), 'HR date-filtered attendance list should be readable').toBe(200);
  await expect(hr.page.getByText('No attendance found', { exact: true })).toBeVisible();
  await expect(hr.page.getByRole('row').filter({ hasText: employeeNo })).toHaveCount(0);
}

async function openSelfServiceDtr(employee: ActorPage, targetDate: string): Promise<void> {
  await employee.page.getByRole('button', { name: /^Profile menu for / }).click();
  await employee.page.getByRole('menuitem', { name: 'DTR', exact: true }).click();
  await expect(employee.page).toHaveURL(/\/self-service\/dtr(?:\?|$)/);
  await expect(employee.page.getByRole('heading', { name: 'Daily Time Record', exact: true })).toBeVisible();

  const target = new Date(`${targetDate}T12:00:00+08:00`);
  const monthState = await employee.page.evaluate(() => ({
    year: new Date().getFullYear(),
    month: new Date().getMonth() + 1,
  }));
  let delta = (target.getFullYear() - monthState.year) * 12 + target.getMonth() + 1 - monthState.month;
  if (delta < 0) throw new Error('The source-selected future workday is earlier than the browser DTR month.');
  while (delta > 0) {
    await employee.page.getByRole('button', { name: 'Next month' }).click();
    delta -= 1;
  }
}

test('HR03-PUNCH: employee punches in and out; HR and employee DTR views reflect the same record', async ({ browser, request }, testInfo) => {
  const actors = await loadActors();
  const employeeActor = actorByName(actors, 'employee');
  const hrActor = actorByName(actors, 'hr_officer');
  const baseline = await punchBaseline(employeeActor.email);
  const journey = createJourneyContext('HR03-PUNCH');
  journey.values.employeeNo = baseline.employee_no;
  journey.values.workDate = baseline.work_date;
  journey.values.shift = baseline.shift_name;
  journey.values.shiftStart = baseline.shift_start;
  journey.values.shiftEnd = baseline.shift_end;

  const employee = await actorPage(browser, employeeActor.actor, { timezoneId: 'Asia/Manila' });
  const hr = await actorPage(browser, hrActor.actor, { timezoneId: 'Asia/Manila' });
  const geoOrigin = new URL(process.env.E2E_BASE_URL!).origin;
  const mailIdsBefore = (await listMailpitMessages(500)).map((message) => message.ID).sort();
  const mailpitEmployeeIdsBefore = (await listMailpitMessages(500))
    .filter((message) => message.To.some((recipient) => recipient.Address.toLowerCase() === employeeActor.email.toLowerCase()))
    .map((message) => message.ID)
    .sort();
  let clockFrozen = false;
  let primaryError: unknown;

  try {
    await employee.context.grantPermissions(['geolocation'], { origin: geoOrigin });
    await employee.context.setGeolocation({
      latitude: baseline.latitude,
      longitude: baseline.longitude,
      accuracy: Math.min(5, baseline.accuracy_cap_m),
    });

    await runProcessStep({
      ...plannedSteps[0],
      page: employee.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => openEmployeeClock(employee),
      verify: async (page) => {
        await expect(page.getByText('Not clocked in', { exact: true })).toBeVisible();
        await expect(page.getByText(new RegExp(`^${baseline.shift_name} shift`))).toBeVisible();
        await expect(page.getByRole('button', { name: 'Time in', exact: true })).toBeEnabled();
      },
      assertInvariants: async () => {
        const initial = await punchSnapshot(employeeActor.email, baseline.work_date);
        expect(initial).toHaveLength(1);
        expect(initial[0].attendance_id).toBeNull();
        expect(initial[0].punches).toEqual([]);
        employee.diagnostics.assertClean('Employee opens the time clock');
      },
    });

    await runProcessStep({
      ...plannedSteps[1],
      page: hr.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => openAttendanceList(hr, baseline.work_date, baseline.employee_no),
      verify: async () => {
        await expect(hr.page.getByText('No attendance found', { exact: true })).toBeVisible();
      },
      assertInvariants: async () => {
        hr.diagnostics.assertClean('HR opens the filtered attendance list');
      },
    });

    await freezeClock(request, localInstant(baseline.work_date, baseline.shift_start));
    clockFrozen = true;
    await runProcessStep({
      ...plannedSteps[2],
      page: employee.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async (page) => {
        const cookies = await employee.context.cookies(geoOrigin);
        const csrfCookie = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN');
        const sessionCookie = cookies.find((cookie) => cookie.httpOnly);
        const punchResponse = page.waitForResponse((response) =>
          response.url().endsWith('/api/v1/attendance/punch') && response.request().method() === 'POST');
        const punchRequest = page.waitForRequest((candidate) =>
          candidate.url().endsWith('/api/v1/attendance/punch') && candidate.method() === 'POST');
        await page.getByRole('button', { name: 'Time in', exact: true }).click();
        const request = await punchRequest;
        const requestHeaders = await request.allHeaders();
        const requestCookieNames = (requestHeaders.cookie ?? '').split(';').map((part) => part.trim().split('=')[0]).filter(Boolean);
        const csrfHeader = requestHeaders['x-xsrf-token'];
        const csrfMatchesCookie = Boolean(csrfCookie && csrfHeader && (
          csrfHeader === csrfCookie.value || csrfHeader === decodeURIComponent(csrfCookie.value)
        ));
        const sessionDiagnostics = {
          csrfCookiePresent: Boolean(csrfCookie),
          csrfHeaderPresent: Boolean(csrfHeader),
          csrfHeaderMatchesCookie: csrfMatchesCookie,
          sessionCookiePresent: Boolean(sessionCookie),
          sessionCookieSent: Boolean(sessionCookie && requestCookieNames.includes(sessionCookie.name)),
        };
        journey.values.punchSessionDiagnostics = sessionDiagnostics;
        const response = await punchResponse;
        const responseBody = await response.text();
        expect(response.status(), `UI time-in should create one punch; session diagnostics: ${JSON.stringify(sessionDiagnostics)}; response: ${responseBody.slice(0, 500)}`).toBe(201);
      },
      verify: async (page) => {
        await expect(page.getByText('Not clocked in', { exact: true })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Time out', exact: true })).toBeEnabled();
        await expect(page.getByText(new RegExp(`^Timed in at ${manilaTime(localInstant(baseline.work_date, baseline.shift_start))} · \\d+ m from the plant centre\\.$`))).toBeVisible();
        const snapshot = await assertSnapshot(employeeActor.email, baseline.work_date, 1);
        expect(snapshot.attendance_status).toBe('present');
        expect(snapshot.time_in_local).toBe(localDateTime(baseline.work_date, baseline.shift_start));
        expect(snapshot.time_out_local).toBeNull();
        expect(snapshot.tardiness_minutes).toBe(0);
        expect(snapshot.punches[0]).toMatchObject({ direction: 'in' });
        expect(snapshot.punches[0].punched_at_local).toBe(localDateTime(baseline.work_date, baseline.shift_start));
        expect(snapshot.punches[0].latitude).toBeCloseTo(baseline.latitude, 5);
        expect(snapshot.punches[0].longitude).toBeCloseTo(baseline.longitude, 5);
        expect(snapshot.punches[0].accuracy_m).toBeLessThanOrEqual(baseline.accuracy_cap_m);
        expect(snapshot.punches[0].distance_m).toBeLessThanOrEqual(baseline.radius_m);
        expect(snapshot.overtime_count).toBe(0);
        expect(snapshot.attendance_notification_count).toBe(0);
        expect(snapshot.audits).toHaveLength(2);
        for (const audit of snapshot.audits) {
          assertAuditActor({ actor_email: audit.actor_email ?? undefined }, employeeActor.email);
        }
        const hrRow = hr.page.getByRole('row').filter({ hasText: baseline.employee_no });
        await expect(hrRow, 'the HR viewer should receive the attendance event without refresh').toBeVisible({ timeout: 20_000 });
        await expect(hrRow).toContainText('Present');
        await expect(hrRow).toContainText(manilaTime(localInstant(baseline.work_date, baseline.shift_start)));
        await captureAnnotatedStep(hr.page, 'hr_officer', 'HR sees the employee time-in without refreshing', 6, testInfo);
      },
      assertInvariants: async () => {
        employee.diagnostics.assertClean('Employee records time in');
        hr.diagnostics.assertClean('HR receives the live time-in update');
      },
    });

    await freezeClock(request, localInstant(baseline.work_date, baseline.shift_end));
    await runProcessStep({
      ...plannedSteps[3],
      page: employee.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async (page) => {
        const punchResponse = page.waitForResponse((response) =>
          response.url().endsWith('/api/v1/attendance/punch') && response.request().method() === 'POST');
        await page.getByRole('button', { name: 'Time out', exact: true }).click();
        const response = await punchResponse;
        const responseBody = await response.text();
        expect(response.status(), `UI time-out should create one punch; response: ${responseBody.slice(0, 500)}`).toBe(201);
      },
      verify: async (page) => {
        await expect(page.getByText('Not clocked in', { exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Time in', exact: true })).toBeEnabled();
        await expect(page.getByText(new RegExp(`^Timed out at ${manilaTime(localInstant(baseline.work_date, baseline.shift_end))} · \\d+ m from the plant centre\\.$`))).toBeVisible();
        const snapshot = await assertSnapshot(employeeActor.email, baseline.work_date, 2);
        expect(snapshot.attendance_status).toBe('present');
        expect(snapshot.time_in_local).toBe(localDateTime(baseline.work_date, baseline.shift_start));
        expect(snapshot.time_out_local).toBe(localDateTime(baseline.work_date, baseline.shift_end));
        expect(snapshot.regular_hours).toBe('7.50');
        expect(snapshot.overtime_hours).toBe('0.00');
        expect(snapshot.tardiness_minutes).toBe(0);
        expect(snapshot.undertime_minutes).toBe(0);
        expect(snapshot.punches.map((punch) => punch.direction)).toEqual(['in', 'out']);
        expect(snapshot.punches.map((punch) => punch.punched_at_local)).toEqual([
          localDateTime(baseline.work_date, baseline.shift_start),
          localDateTime(baseline.work_date, baseline.shift_end),
        ]);
        expect(snapshot.overtime_count).toBe(0);
        expect(snapshot.attendance_notification_count).toBe(0);
        expect(snapshot.audits).toHaveLength(4);
        for (const audit of snapshot.audits) {
          assertAuditActor({ actor_email: audit.actor_email ?? undefined }, employeeActor.email);
        }

        const hrRow = hr.page.getByRole('row').filter({ hasText: baseline.employee_no });
        await expect(hrRow, 'the HR DTR row should update after time-out without refresh').toContainText(
          manilaTime(localInstant(baseline.work_date, baseline.shift_end)),
          { timeout: 20_000 },
        );
        await expect(hrRow).toContainText('7.50');
        await captureAnnotatedStep(hr.page, 'hr_officer', 'HR sees the completed DTR without refreshing', 7, testInfo);
      },
      assertInvariants: async () => {
        employee.diagnostics.assertClean('Employee records time out');
        hr.diagnostics.assertClean('HR receives the live time-out update');
      },
    });

    await runProcessStep({
      ...plannedSteps[4],
      page: employee.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: () => openSelfServiceDtr(employee, baseline.work_date),
      verify: async (page) => {
        const dateRow = page.getByRole('row').filter({ hasText: dateLabel(baseline.work_date) });
        await expect(dateRow).toHaveCount(1);
        await expect(dateRow).toContainText(manilaTime(localInstant(baseline.work_date, baseline.shift_start)));
        await expect(dateRow).toContainText(manilaTime(localInstant(baseline.work_date, baseline.shift_end)));
        await expect(dateRow).toContainText('7.5');
        await expect(dateRow).toContainText('Present');
      },
      assertInvariants: async () => {
        const snapshot = await assertSnapshot(employeeActor.email, baseline.work_date, 2);
        expect(snapshot.attendance_id).toBeGreaterThan(0);
        expect(snapshot.audits).toHaveLength(4);
        expect(snapshot.punches.every((punch) => punch.accuracy_m <= baseline.accuracy_cap_m)).toBe(true);
        expect(snapshot.punches.every((punch) => punch.distance_m <= baseline.radius_m)).toBe(true);
        expect(snapshot.attendance_notification_count).toBe(0);
        const mailIdsAfter = (await listMailpitMessages(500)).map((message) => message.ID).sort();
        expect(mailIdsAfter).toEqual(mailIdsBefore);
        const mailpitEmployeeIdsAfter = (await listMailpitMessages(500))
          .filter((message) => message.To.some((recipient) => recipient.Address.toLowerCase() === employeeActor.email.toLowerCase()))
          .map((message) => message.ID)
          .sort();
        expect(mailpitEmployeeIdsAfter).toEqual(mailpitEmployeeIdsBefore);
        employee.diagnostics.assertClean('Employee reviews the completed DTR');
        hr.diagnostics.assertClean('HR retains the completed attendance view');
      },
    });
  } catch (error) {
    primaryError = error;
    throw error;
  } finally {
    const cleanupErrors: string[] = [];
    try {
      if (clockFrozen) await clearClock(request);
    } catch (error) {
      cleanupErrors.push(`clock reset: ${error instanceof Error ? error.message : String(error)}`);
    } finally {
      const closeResults = await Promise.allSettled([employee.context.close(), hr.context.close()]);
      closeResults.forEach((result, index) => {
        if (result.status === 'rejected') {
          cleanupErrors.push(`${index === 0 ? 'employee' : 'HR'} context close: ${result.reason instanceof Error ? result.reason.message : String(result.reason)}`);
        }
      });
    }
    if (cleanupErrors.length > 0) {
      await testInfo.attach('journey-cleanup-errors.txt', {
        body: cleanupErrors.join('\n'),
        contentType: 'text/plain',
      });
      if (primaryError === undefined) throw new Error(cleanupErrors.join('\n'));
    }
  }
});

const missingPunchSteps = [
  { name: 'Employee records time in for a scheduled shift', actor: 'employee', uiPath: 'Profile menu → Dashboard → Time in / out → Time in', expectedStatus: 'present with time_in; time_out is empty; one geofenced punch is audited' },
  { name: 'HR finds the prior-day record in Missing time-out', actor: 'hr_officer', uiPath: 'Human Resources → Attendance & DTR → Date and Time out filters', expectedStatus: 'the employee row shows Missing and is correctable' },
  { name: 'Employee opens the DTR as the correction observer', actor: 'employee', uiPath: 'Profile menu → DTR', expectedStatus: 'the same date shows the existing time-in and no time-out' },
  { name: 'HR corrects the missing time-out through the attendance row', actor: 'hr_officer', uiPath: 'Attendance & DTR → employee row → Correct record → Save correction', expectedStatus: 'present; time_out and regular hours are recomputed; HR actor is audited' },
  { name: 'Employee DTR receives the correction without refresh', actor: 'employee observer', uiPath: 'Already-open Profile menu → DTR', expectedStatus: 'time-out and recomputed regular hours appear live' },
  { name: 'Employee reloads DTR and confirms the saved correction', actor: 'employee', uiPath: 'Profile menu → DTR', expectedStatus: 'the refreshed row matches the corrected attendance record' },
];

async function openMissingPunchList(hr: ActorPage, date: string, employeeNo: string): Promise<void> {
  await hr.page.goto('/dashboard');
  await expect(hr.page.getByRole('navigation', { name: 'Modules' })).toBeVisible();
  const nav = hr.page.getByRole('navigation', { name: 'Modules' });
  const attendanceLink = nav.locator('a[href="/hr/attendance"]');
  if (!(await attendanceLink.isVisible())) {
    const section = hr.page.locator('[data-module="Human Resources"]');
    const toggle = section.getByRole('button');
    if (await toggle.count() !== 1) throw new Error('Human Resources sidebar section did not resolve uniquely.');
    if (await toggle.getAttribute('aria-expanded') !== 'true') await toggle.click();
  }
  await expect(attendanceLink).toBeVisible();
  await attendanceLink.click();
  await expect(hr.page).toHaveURL(/\/hr\/attendance(?:\?|$)/);
  await expect(hr.page.getByRole('heading', { name: 'Daily Time Records', exact: true })).toBeVisible();

  await hr.page.getByLabel('Date from').fill(date);
  const dateResponse = hr.page.waitForResponse((response) => {
    const url = new URL(response.url());
    return url.pathname.endsWith('/api/v1/attendance/attendances')
      && url.searchParams.get('from') === date
      && url.searchParams.get('to') === date;
  });
  await hr.page.getByLabel('Date to').fill(date);
  expect((await dateResponse).status(), 'HR date-filtered DTR list should be readable').toBe(200);

  const missingResponse = hr.page.waitForResponse((response) => {
    const url = new URL(response.url());
    return url.pathname.endsWith('/api/v1/attendance/attendances')
      && url.searchParams.get('from') === date
      && url.searchParams.get('to') === date
      && url.searchParams.get('missing_time_out') === '1';
  });
  await hr.page.getByRole('combobox', { name: 'Time out', exact: true }).click();
  await hr.page.getByRole('option', { name: 'Missing time-out', exact: true }).click();
  expect((await missingResponse).status(), 'HR missing-time-out filter should return the record').toBe(200);

  const row = hr.page.getByRole('row').filter({ hasText: employeeNo });
  await expect(row).toHaveCount(1);
  await expect(row).toContainText('Missing');
}

test('HR03-MISSING-PUNCH: employee time-in is corrected by HR and the open employee DTR updates live', async ({ browser, request }, testInfo) => {
  const actors = await loadActors();
  const employeeActor = actorByName(actors, 'employee');
  const hrActor = actorByName(actors, 'hr_officer');
  const baseline = await punchBaseline(employeeActor.email);
  const journey = createJourneyContext('HR03-MISSING-PUNCH');
  journey.values.employeeNo = baseline.employee_no;
  journey.values.workDate = baseline.work_date;
  journey.values.shift = baseline.shift_name;
  journey.values.shiftStart = baseline.shift_start;
  journey.values.shiftEnd = baseline.shift_end;

  const employee = await actorPage(browser, employeeActor.actor, { timezoneId: 'Asia/Manila' });
  const hr = await actorPage(browser, hrActor.actor, { timezoneId: 'Asia/Manila' });
  const geoOrigin = new URL(process.env.E2E_BASE_URL!).origin;
  const journeyActorEmails = new Set([employeeActor.email, hrActor.email].map((email) => email.toLowerCase()));
  const journeyActorMailIds = async () => (await listMailpitMessages(500))
    .filter((message) => message.To.some((recipient) => journeyActorEmails.has(recipient.Address.toLowerCase())))
    .map((message) => message.ID)
    .sort();
  const mailIdsBefore = await journeyActorMailIds();
  let clockFrozen = false;
  let primaryError: unknown;

  try {
    await employee.context.grantPermissions(['geolocation'], { origin: geoOrigin });
    await employee.context.setGeolocation({
      latitude: baseline.latitude,
      longitude: baseline.longitude,
      accuracy: Math.min(5, baseline.accuracy_cap_m),
    });

    await runProcessStep({
      ...missingPunchSteps[0],
      page: employee.page,
      plannedSteps: missingPunchSteps,
      context: journey,
      testInfo,
      action: async () => {
        await employee.page.clock.setFixedTime(new Date(localInstant(baseline.work_date, baseline.shift_start)));
        await freezeClock(request, localInstant(baseline.work_date, baseline.shift_start));
        clockFrozen = true;
        await openEmployeeClock(employee);
        const responsePromise = employee.page.waitForResponse((response) =>
          response.url().endsWith('/api/v1/attendance/punch')
          && response.request().method() === 'POST');
        await employee.page.getByRole('button', { name: 'Time in', exact: true }).click();
        const response = await responsePromise;
        expect(response.status(), 'Employee time-in should create the attendance through the UI').toBe(201);
      },
      verify: async (page) => {
        await expect(page.getByRole('button', { name: 'Time out', exact: true })).toBeEnabled();
        const snapshot = await assertSnapshot(employeeActor.email, baseline.work_date, 1);
        expect(snapshot.attendance_status).toBe('present');
        expect(snapshot.time_in_local).toBe(localDateTime(baseline.work_date, baseline.shift_start));
        expect(snapshot.time_out_local).toBeNull();
        expect(snapshot.punches[0].direction).toBe('in');
        expect(snapshot.punches[0].punched_at_local).toBe(localDateTime(baseline.work_date, baseline.shift_start));
      },
      assertInvariants: async () => {
        employee.diagnostics.assertClean('Employee records a time-in for the missing-punch journey');
      },
    });

    await runProcessStep({
      ...missingPunchSteps[1],
      page: hr.page,
      plannedSteps: missingPunchSteps,
      context: journey,
      testInfo,
      action: async () => {
        const nextDay = localInstant(nextLocalDate(baseline.work_date), '00:01:00');
        await freezeClock(request, nextDay);
        await hr.page.clock.setFixedTime(new Date(nextDay));
        await openMissingPunchList(hr, baseline.work_date, baseline.employee_no);
      },
      verify: async () => {
        const row = hr.page.getByRole('row').filter({ hasText: baseline.employee_no });
        await expect(row).toContainText('Present');
        await expect(row).toContainText('Missing');
        await expect(row).toContainText(manilaTime(localInstant(baseline.work_date, baseline.shift_start)));
      },
      assertInvariants: async () => {
        const snapshot = await assertSnapshot(employeeActor.email, baseline.work_date, 1);
        expect(snapshot.time_out_local).toBeNull();
        expect(snapshot.overtime_count).toBe(0);
        expect(snapshot.attendance_notification_count).toBe(0);
        hr.diagnostics.assertClean('HR finds the previous-day missing time-out through the DTR filters');
      },
    });

    await runProcessStep({
      ...missingPunchSteps[2],
      page: employee.page,
      plannedSteps: missingPunchSteps,
      context: journey,
      testInfo,
      action: async () => {
        await employee.page.clock.setFixedTime(new Date(localInstant(nextLocalDate(baseline.work_date), '00:01:00')));
        await openSelfServiceDtr(employee, baseline.work_date);
      },
      verify: async (page) => {
        const row = page.getByRole('row').filter({ hasText: dateLabel(baseline.work_date) });
        await expect(row).toHaveCount(1);
        await expect(row).toContainText(manilaTime(localInstant(baseline.work_date, baseline.shift_start)));
      },
      assertInvariants: async () => {
        const snapshot = await assertSnapshot(employeeActor.email, baseline.work_date, 1);
        expect(snapshot.attendance_status).toBe('present');
        expect(snapshot.time_out_local).toBeNull();
        employee.diagnostics.assertClean('Employee opens DTR while HR prepares the correction');
      },
    });

    await runProcessStep({
      ...missingPunchSteps[3],
      page: hr.page,
      plannedSteps: missingPunchSteps,
      context: journey,
      testInfo,
      action: async (page) => {
        const row = page.getByRole('row').filter({ hasText: baseline.employee_no });
        await row.click();
        const modal = page.getByRole('dialog', { name: 'Correct attendance record' });
        await expect(modal).toBeVisible();
        await expect(modal.getByLabel('Time in')).toHaveValue(baseline.shift_start.slice(0, 5));
        await expect(modal.getByLabel('Time out')).toHaveValue('');
        await modal.getByLabel('Time out').fill(baseline.shift_end.slice(0, 5));
        await modal.getByLabel('Remarks').fill(`E2E ${journey.runId}: correct missed time-out from shift record`);
        const correction = page.waitForResponse((response) =>
          response.url().match(/\/api\/v1\/attendance\/attendances\/[^/]+$/) !== null
          && response.request().method() === 'PUT');
        await modal.getByRole('button', { name: 'Save correction', exact: true }).click();
        const response = await correction;
        const body = await response.text();
        expect(response.status(), `HR correction submitted through the UI should succeed; response: ${body.slice(0, 500)}`).toBe(200);
      },
      verify: async (page) => {
        await expect(page.getByRole('dialog', { name: 'Correct attendance record' })).toHaveCount(0);
        const snapshot = await assertSnapshot(employeeActor.email, baseline.work_date, 1);
        expect(snapshot.attendance_status).toBe('present');
        expect(snapshot.time_in_local).toBe(localDateTime(baseline.work_date, baseline.shift_start));
        expect(snapshot.time_out_local).toBe(localDateTime(baseline.work_date, baseline.shift_end));
        expect(snapshot.regular_hours).toBe('7.50');
        expect(snapshot.overtime_hours).toBe('0.00');
        expect(snapshot.audits.some((audit) => audit.actor_email?.toLowerCase() === hrActor.email.toLowerCase())).toBe(true);
        journey.values.correctedAttendance = {
          status: snapshot.attendance_status,
          timeIn: snapshot.time_in_local,
          timeOut: snapshot.time_out_local,
          regularHours: snapshot.regular_hours,
          overtimeHours: snapshot.overtime_hours,
          correctionAuditActor: hrActor.email,
        };
        const row = page.getByRole('row').filter({ hasText: baseline.employee_no });
        await expect(row, 'a corrected record should leave the active Missing time-out filter').toHaveCount(0);
        await expect(page.getByText('No attendance found', { exact: true })).toBeVisible();
      },
      assertInvariants: async () => {
        const snapshot = await assertSnapshot(employeeActor.email, baseline.work_date, 1);
        expect(snapshot.attendance_status).toBe('present');
        expect(snapshot.time_in_local).toBe(localDateTime(baseline.work_date, baseline.shift_start));
        expect(snapshot.time_out_local).toBe(localDateTime(baseline.work_date, baseline.shift_end));
        expect(snapshot.regular_hours).toBe('7.50');
        expect(snapshot.overtime_hours).toBe('0.00');
        expect(snapshot.tardiness_minutes).toBe(0);
        expect(snapshot.undertime_minutes).toBe(0);
        expect(snapshot.punches).toHaveLength(1);
        expect(snapshot.attendance_notification_count).toBe(0);
        expect(snapshot.audits.some((audit) => audit.actor_email?.toLowerCase() === hrActor.email.toLowerCase())).toBe(true);
        hr.diagnostics.assertClean('HR saves the attendance correction through its own session');
      },
    });

    await runProcessStep({
      ...missingPunchSteps[4],
      page: employee.page,
      plannedSteps: missingPunchSteps,
      context: journey,
      testInfo,
      action: async () => undefined,
      verify: async (page) => {
        const row = page.getByRole('row').filter({ hasText: dateLabel(baseline.work_date) });
        await captureAnnotatedStep(page, 'employee', 'Employee DTR remains open after HR correction', 5, testInfo);
        await expect(row, 'attendance_changed should refresh the open employee DTR without a manual reload')
          .toContainText(manilaTime(localInstant(baseline.work_date, baseline.shift_end)));
        await expect(row).toContainText('7.5');
      },
      assertInvariants: async () => {
        const snapshot = await assertSnapshot(employeeActor.email, baseline.work_date, 1);
        expect(snapshot.time_out_local).toBe(localDateTime(baseline.work_date, baseline.shift_end));
        employee.diagnostics.assertClean('Employee observes HR correction in the already-open DTR');
        hr.diagnostics.assertClean('HR retains the corrected attendance list');
        expect(await journeyActorMailIds()).toEqual(mailIdsBefore);
      },
    });

    await runProcessStep({
      ...missingPunchSteps[5],
      page: employee.page,
      plannedSteps: missingPunchSteps,
      context: journey,
      testInfo,
      action: async (page) => { await page.reload(); },
      verify: async (page) => {
        const row = page.getByRole('row').filter({ hasText: dateLabel(baseline.work_date) });
        await expect(row).toContainText(manilaTime(localInstant(baseline.work_date, baseline.shift_end)));
        await expect(row).toContainText('7.5');
        await expect(row).toContainText('Present');
      },
      assertInvariants: async () => {
        employee.diagnostics.assertClean('Employee confirms saved DTR after reloading');
      },
    });
  } catch (error) {
    primaryError = error;
    throw error;
  } finally {
    const cleanupErrors: string[] = [];
    try {
      if (clockFrozen) await clearClock(request);
    } catch (error) {
      cleanupErrors.push(`clock reset: ${error instanceof Error ? error.message : String(error)}`);
    } finally {
      const closeResults = await Promise.allSettled([employee.context.close(), hr.context.close()]);
      closeResults.forEach((result, index) => {
        if (result.status === 'rejected') {
          cleanupErrors.push(`${index === 0 ? 'employee' : 'HR'} context close: ${result.reason instanceof Error ? result.reason.message : String(result.reason)}`);
        }
      });
    }
    if (cleanupErrors.length > 0) {
      await testInfo.attach('journey-cleanup-errors.txt', {
        body: cleanupErrors.join('\n'),
        contentType: 'text/plain',
      });
      if (primaryError === undefined) throw new Error(cleanupErrors.join('\n'));
    }
  }
});

test('HR03-OVERTIME-APPROVE: employee request reaches its department head and updates the open employee view', async ({ browser }, testInfo) => {
  const actors = await loadActors();
  const employeeActor = actorByName(actors, 'employee');
  const departmentHeadActor = actorByName(actors, 'department_head');
  const baseline = await overtimeBaseline(employeeActor.email);
  expect(baseline.existing_request_count, 'the fresh E2E baseline must have no overtime requests for this Employee').toBe(0);
  expect(baseline.minimum_hours).toBeGreaterThan(0);
  expect(baseline.maximum_hours).toBeGreaterThanOrEqual(baseline.minimum_hours);
  expect(baseline.request_future_days).toBeGreaterThanOrEqual(0);

  const expectedSubmissionRecipients = await eligibleOvertimeSubmissionRecipients(employeeActor.email);
  assertRecipientsExactly(expectedSubmissionRecipients, [departmentHeadActor.email]);

  const journey = createJourneyContext('HR03-OVERTIME-APPROVE');
  const reason = `${journey.values.runPrefix} OT approval journey`;
  journey.values.employeeNo = baseline.employee_no;
  journey.values.workDate = baseline.work_date;
  journey.values.hoursRequested = baseline.minimum_hours.toFixed(1);
  journey.values.reason = reason;

  const employee = await actorPage(browser, employeeActor.actor, { timezoneId: 'Asia/Manila' });
  const departmentHead = await actorPage(browser, departmentHeadActor.actor, { timezoneId: 'Asia/Manila' });
  const mailIdsBefore = (await listMailpitMessages(500)).map((message) => message.ID).sort();
  let primaryError: unknown;

  try {
    const browserNow = localInstant(baseline.work_date, '12:00:00');
    await employee.page.clock.setFixedTime(new Date(browserNow));
    await departmentHead.page.clock.setFixedTime(new Date(browserNow));

    await runProcessStep({
      ...overtimeSteps[0],
      page: employee.page,
      plannedSteps: overtimeSteps,
      context: journey,
      testInfo,
      action: async (page) => {
        await openSelfServiceOvertime(employee);
        await expect(page.getByText('No overtime requests yet', { exact: true })).toBeVisible();
        const applyAction = page.locator('[data-layout="page-header"]').getByRole('button', { name: 'Apply for OT', exact: true });
        await expect(applyAction, 'the page header should expose one Apply for OT action').toHaveCount(1);
        await expect(applyAction).toBeEnabled();
        await applyAction.click();
        const modal = page.getByRole('dialog', { name: 'Apply for Overtime' });
        await expect(modal).toBeVisible();
        await modal.getByLabel('Date').fill(baseline.work_date);
        await modal.getByRole('button', { name: baseline.minimum_hours.toFixed(1), exact: true }).click();
        await modal.getByLabel('Reason').fill(reason);
        const submitted = page.waitForResponse((response) =>
          new URL(response.url()).pathname === '/api/v1/hr/self-service/overtime'
          && response.request().method() === 'POST');
        await modal.getByRole('button', { name: 'Submit for approval', exact: true }).click();
        const response = await submitted;
        const responseText = await response.text();
        expect(response.status(), `Employee UI request should submit; response: ${responseText.slice(0, 500)}`).toBe(201);
        const responseBody = JSON.parse(responseText) as { data?: { id?: string } };
        expect(responseBody.data?.id, 'the UI-created overtime response should include its public record id').toBeTruthy();
        journey.values.overtimeId = responseBody.data!.id!;
      },
      verify: async (page) => {
        const row = page.getByRole('row').filter({ hasText: reason });
        await expect(row).toHaveCount(1);
        await expect(row).toContainText('Pending');
        await expect(row.getByRole('button', { name: 'Cancel this overtime request' })).toBeVisible();
        await expect(row.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
      },
      assertInvariants: async () => {
        const snapshot = await overtimeSnapshot(reason);
        expect(snapshot).toHaveLength(1);
        expect(snapshot[0]).toMatchObject({
          employee_id: baseline.employee_id,
          employee_no: baseline.employee_no,
          department_id: baseline.department_id,
          requester_email: employeeActor.email,
          date: baseline.work_date,
          hours_requested: baseline.minimum_hours.toFixed(1),
          reason,
          status: 'pending',
          approved_by_email: null,
          approved_at: null,
          is_auto_detected: false,
          attendance_count: 0,
        });
        const audits = await overtimeAuditRows(reason);
        const createdAudit = audits.find((audit) => audit.action === 'created');
        expect(createdAudit, 'the overtime request should have a creation audit entry').toBeDefined();
        expect(createdAudit!.actor_email).toBe(employeeActor.email);

        const publicId = String(journey.values.overtimeId);
        const expectedNotice = `attendance.ot_submitted|${departmentHeadActor.email.toLowerCase()}|/hr/attendance/overtime/${publicId}`;
        await expect.poll(async () => (await overtimeNotificationRows(publicId)).map((notice) =>
          `${notice.type}|${notice.recipient_email.toLowerCase()}|${notice.link_to}`).sort(), {
          timeout: 15_000,
          message: 'only the eligible same-department approver should receive the linked overtime submission notice',
        }).toEqual([expectedNotice]);
        const notices = await overtimeNotificationRows(publicId);
        assertRecipientsExactly(notices.map((notice) => notice.recipient_email), [departmentHeadActor.email]);
        expect(notices[0].link_to).toBe(`/hr/attendance/overtime/${publicId}`);
        employee.diagnostics.assertClean('Employee submits an overtime request through self-service');
      },
    });

    await runProcessStep({
      ...overtimeSteps[1],
      page: departmentHead.page,
      plannedSteps: overtimeSteps,
      context: journey,
      testInfo,
      action: async (page) => {
        await openOvertimeApprovalDashboardLink(departmentHead, baseline.work_date, String(journey.values.overtimeId));
        const heading = page.getByRole('heading', { name: /Overtime request/ });
        await expect(heading).toContainText('Pending');
        await expect(page.getByText(reason, { exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Approve', exact: true })).toBeEnabled();
        await expect(page.getByRole('button', { name: 'Reject', exact: true })).toBeEnabled();
        await expect(page.getByRole('button', { name: 'Cancel request', exact: true })).toBeEnabled();
        await page.getByRole('button', { name: 'Approve', exact: true }).click();
        const confirmation = page.getByRole('dialog', { name: 'Approve overtime request?' });
        await expect(confirmation).toBeVisible();
        const approved = page.waitForResponse((response) =>
          /\/api\/v1\/attendance\/overtime-requests\/[^/]+\/approve$/.test(new URL(response.url()).pathname)
          && response.request().method() === 'PATCH');
        await confirmation.getByRole('button', { name: 'Approve', exact: true }).click();
        const response = await approved;
        const responseText = await response.text();
        expect(response.status(), `Department Head UI approval should succeed; response: ${responseText.slice(0, 500)}`).toBe(200);
      },
      verify: async (page) => {
        const heading = page.getByRole('heading', { name: /Overtime request/ });
        await expect(heading).toContainText('Approved');
        await expect(page.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Reject', exact: true })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Cancel request', exact: true })).toHaveCount(0);
        await expect(page.getByRole('button', { name: /^View all Overtime signals:/ })).toHaveCount(0);

        const observerRow = employee.page.getByRole('row').filter({ hasText: reason });
        await expect(observerRow, 'the already-open Employee overtime list should reflect the Department Head decision live')
          .toHaveCount(1);
        await expect(observerRow).toContainText('Approved');
        await expect(observerRow.getByRole('button', { name: 'Cancel this overtime request' })).toHaveCount(0);
        await expect(employee.page.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
        await captureAnnotatedStep(employee.page, 'employee', 'Employee sees approved overtime without refreshing', 3, testInfo);
      },
      assertInvariants: async () => {
        const snapshot = await overtimeSnapshot(reason);
        expect(snapshot).toHaveLength(1);
        expect(snapshot[0]).toMatchObject({
          employee_id: baseline.employee_id,
          employee_no: baseline.employee_no,
          department_id: baseline.department_id,
          requester_email: employeeActor.email,
          date: baseline.work_date,
          hours_requested: baseline.minimum_hours.toFixed(1),
          reason,
          status: 'approved',
          approved_by_email: departmentHeadActor.email,
          is_auto_detected: false,
          attendance_count: 0,
        });
        expect(snapshot[0].approved_at).toBeTruthy();
        const audits = await overtimeAuditRows(reason);
        const createdAudit = audits.find((audit) => audit.action === 'created');
        const approvedAudit = audits.find((audit) => audit.status === 'approved');
        expect(createdAudit).toBeDefined();
        expect(approvedAudit).toBeDefined();
        expect(createdAudit!.actor_email).toBe(employeeActor.email);
        expect(approvedAudit!.actor_email).toBe(departmentHeadActor.email);

        const publicId = String(journey.values.overtimeId);
        const expectedNotices = [
          `attendance.ot_approved|${employeeActor.email.toLowerCase()}|/self-service/overtime/${publicId}`,
          `attendance.ot_submitted|${departmentHeadActor.email.toLowerCase()}|/hr/attendance/overtime/${publicId}`,
        ].sort();
        await expect.poll(async () => (await overtimeNotificationRows(publicId)).map((notice) =>
          `${notice.type}|${notice.recipient_email.toLowerCase()}|${notice.link_to}`).sort(), {
          timeout: 15_000,
          message: 'submission and decision notifications should reach only the eligible approver and request owner',
        }).toEqual(expectedNotices);
        const notices = await overtimeNotificationRows(publicId);
        assertRecipientsExactly(notices.map((notice) => notice.recipient_email), [employeeActor.email, departmentHeadActor.email]);
        const mailIdsAfter = (await listMailpitMessages(500)).map((message) => message.ID).sort();
        expect(mailIdsAfter, 'overtime request and approval should not emit email in this configured flow').toEqual(mailIdsBefore);
        const badges = await readApiJson<{ data: Record<string, { count: number }> }>(departmentHead.context, '/api/v1/dashboards/badges');
        expect(badges.data.overtime?.count ?? 0, 'the approved request should leave the actionable overtime badge').toBe(0);
        employee.diagnostics.assertClean('Employee observes the approved overtime status in the already-open self-service list');
        departmentHead.diagnostics.assertClean('Department Head approves from the actionable badge queue');
      },
    });
  } catch (error) {
    primaryError = error;
    throw error;
  } finally {
    const closeResults = await Promise.allSettled([employee.context.close(), departmentHead.context.close()]);
    const cleanupErrors = closeResults.flatMap((result, index) => result.status === 'rejected'
      ? [`${index === 0 ? 'employee' : 'Department Head'} context close: ${result.reason instanceof Error ? result.reason.message : String(result.reason)}`]
      : []);
    if (cleanupErrors.length > 0) {
      await testInfo.attach('journey-cleanup-errors.txt', {
        body: cleanupErrors.join('\n'),
        contentType: 'text/plain',
      });
      if (primaryError === undefined) throw new Error(cleanupErrors.join('\n'));
    }
  }
});

test('HR03-OVERTIME-REJECT: department head rejects an employee request and the employee sees the reason live', async ({ browser }, testInfo) => {
  const actors = await loadActors();
  const employeeActor = actorByName(actors, 'employee');
  const departmentHeadActor = actorByName(actors, 'department_head');
  const baseline = await overtimeBaseline(employeeActor.email);
  expect(baseline.existing_request_count, 'the fresh E2E baseline must have no overtime requests for this Employee').toBe(0);
  expect(baseline.minimum_hours).toBeGreaterThan(0);
  expect(baseline.maximum_hours).toBeGreaterThanOrEqual(baseline.minimum_hours);
  expect(baseline.request_future_days).toBeGreaterThanOrEqual(0);

  const expectedSubmissionRecipients = await eligibleOvertimeSubmissionRecipients(employeeActor.email);
  assertRecipientsExactly(expectedSubmissionRecipients, [departmentHeadActor.email]);

  const journey = createJourneyContext('HR03-OVERTIME-REJECT');
  const reason = `${journey.values.runPrefix} OT rejection journey`;
  const rejectionReason = `${journey.values.runPrefix} Coverage was already assigned.`;
  journey.values.employeeNo = baseline.employee_no;
  journey.values.workDate = baseline.work_date;
  journey.values.hoursRequested = baseline.minimum_hours.toFixed(1);
  journey.values.reason = reason;
  journey.values.rejectionReason = rejectionReason;

  const employee = await actorPage(browser, employeeActor.actor, { timezoneId: 'Asia/Manila' });
  const departmentHead = await actorPage(browser, departmentHeadActor.actor, { timezoneId: 'Asia/Manila' });
  const journeyActorEmails = new Set([employeeActor.email, departmentHeadActor.email].map((email) => email.toLowerCase()));
  const journeyActorMailIds = async () => (await listMailpitMessages(500))
    .filter((message) => message.To.some((recipient) => journeyActorEmails.has(recipient.Address.toLowerCase())))
    .map((message) => message.ID)
    .sort();
  const mailIdsBefore = await journeyActorMailIds();
  let primaryError: unknown;

  try {
    const browserNow = localInstant(baseline.work_date, '12:00:00');
    await employee.page.clock.setFixedTime(new Date(browserNow));
    await departmentHead.page.clock.setFixedTime(new Date(browserNow));

    await runProcessStep({
      ...overtimeRejectSteps[0],
      page: employee.page,
      plannedSteps: overtimeRejectSteps,
      context: journey,
      testInfo,
      action: async (page) => {
        await openSelfServiceOvertime(employee);
        await expect(page.getByText('No overtime requests yet', { exact: true })).toBeVisible();
        const applyAction = page.locator('[data-layout="page-header"]').getByRole('button', { name: 'Apply for OT', exact: true });
        await expect(applyAction).toHaveCount(1);
        await applyAction.click();
        const modal = page.getByRole('dialog', { name: 'Apply for Overtime' });
        await expect(modal).toBeVisible();
        await modal.getByLabel('Date').fill(baseline.work_date);
        await modal.getByRole('button', { name: baseline.minimum_hours.toFixed(1), exact: true }).click();
        await modal.getByLabel('Reason').fill(reason);
        const submitted = page.waitForResponse((response) =>
          new URL(response.url()).pathname === '/api/v1/hr/self-service/overtime'
          && response.request().method() === 'POST');
        await modal.getByRole('button', { name: 'Submit for approval', exact: true }).click();
        const response = await submitted;
        const responseText = await response.text();
        expect(response.status(), `Employee UI request should submit; response: ${responseText.slice(0, 500)}`).toBe(201);
        const responseBody = JSON.parse(responseText) as { data?: { id?: string } };
        expect(responseBody.data?.id, 'the UI-created overtime response should include its public record id').toBeTruthy();
        journey.values.overtimeId = responseBody.data!.id!;
      },
      verify: async (page) => {
        const row = page.getByRole('row').filter({ hasText: reason });
        await expect(row).toHaveCount(1);
        await expect(row).toContainText('Pending');
        await expect(row.getByRole('button', { name: 'Cancel this overtime request' })).toBeVisible();
        await expect(row.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
      },
      assertInvariants: async () => {
        const snapshot = await overtimeSnapshot(reason);
        expect(snapshot).toHaveLength(1);
        expect(snapshot[0]).toMatchObject({
          employee_id: baseline.employee_id,
          employee_no: baseline.employee_no,
          department_id: baseline.department_id,
          requester_email: employeeActor.email,
          date: baseline.work_date,
          hours_requested: baseline.minimum_hours.toFixed(1),
          reason,
          status: 'pending',
          approved_by_email: null,
          approved_at: null,
          rejection_reason: null,
          is_auto_detected: false,
          attendance_count: 0,
        });
        const audits = await overtimeAuditRows(reason);
        const createdAudit = audits.find((audit) => audit.action === 'created');
        expect(createdAudit, 'the overtime request should have a creation audit entry').toBeDefined();
        expect(createdAudit!.actor_email).toBe(employeeActor.email);

        const publicId = String(journey.values.overtimeId);
        const expectedNotice = `attendance.ot_submitted|${departmentHeadActor.email.toLowerCase()}|/hr/attendance/overtime/${publicId}`;
        await expect.poll(async () => (await overtimeNotificationRows(publicId)).map((notice) =>
          `${notice.type}|${notice.recipient_email.toLowerCase()}|${notice.link_to}`).sort(), {
          timeout: 15_000,
          message: 'only the eligible same-department approver should receive the linked overtime submission notice',
        }).toEqual([expectedNotice]);
        const notices = await overtimeNotificationRows(publicId);
        assertRecipientsExactly(notices.map((notice) => notice.recipient_email), [departmentHeadActor.email]);
        employee.diagnostics.assertClean('Employee submits an overtime request through self-service');
      },
    });

    await runProcessStep({
      ...overtimeRejectSteps[1],
      page: departmentHead.page,
      plannedSteps: overtimeRejectSteps,
      context: journey,
      testInfo,
      action: async (page) => {
        await openOvertimeApprovalDashboardLink(departmentHead, baseline.work_date, String(journey.values.overtimeId));
        const heading = page.getByRole('heading', { name: /Overtime request/ });
        await expect(heading).toContainText('Pending');
        await expect(page.getByText(reason, { exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Approve', exact: true })).toBeEnabled();
        await expect(page.getByRole('button', { name: 'Reject', exact: true })).toBeEnabled();
        await expect(page.getByRole('button', { name: 'Cancel request', exact: true })).toBeEnabled();
        await page.getByRole('button', { name: 'Reject', exact: true }).click();
        const rejectionDialog = page.getByRole('dialog', { name: 'Reject overtime request' });
        await expect(rejectionDialog).toBeVisible();
        const confirmReject = rejectionDialog.getByRole('button', { name: 'Confirm reject', exact: true });
        await expect(confirmReject).toBeDisabled();
        await rejectionDialog.getByLabel('Reason for rejection').fill(rejectionReason);
        await expect(confirmReject).toBeEnabled();
        const rejected = page.waitForResponse((response) =>
          /\/api\/v1\/attendance\/overtime-requests\/[^/]+\/reject$/.test(new URL(response.url()).pathname)
          && response.request().method() === 'PATCH');
        await confirmReject.click();
        const response = await rejected;
        const responseText = await response.text();
        expect(response.status(), `Department Head UI rejection should succeed; response: ${responseText.slice(0, 500)}`).toBe(200);
      },
      verify: async (page) => {
        const heading = page.getByRole('heading', { name: /Overtime request/ });
        await expect(heading).toContainText('Rejected');
        await expect(page.getByText(rejectionReason, { exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Reject', exact: true })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Cancel request', exact: true })).toHaveCount(0);

        const employeeRow = employee.page.getByRole('row').filter({ hasText: reason });
        await expect(employeeRow, 'the open Employee overtime list should show the rejection live').toContainText('Rejected');
        await expect(employeeRow).toContainText(rejectionReason);
        await expect(employeeRow.getByRole('button', { name: 'Cancel this overtime request' })).toHaveCount(0);
      },
      assertInvariants: async () => {
        const snapshot = await overtimeSnapshot(reason);
        expect(snapshot).toHaveLength(1);
        expect(snapshot[0]).toMatchObject({
          employee_id: baseline.employee_id,
          employee_no: baseline.employee_no,
          department_id: baseline.department_id,
          requester_email: employeeActor.email,
          date: baseline.work_date,
          hours_requested: baseline.minimum_hours.toFixed(1),
          reason,
          status: 'rejected',
          approved_by_email: departmentHeadActor.email,
          rejection_reason: rejectionReason,
          is_auto_detected: false,
          attendance_count: 0,
        });
        expect(snapshot[0].approved_at).toBeTruthy();

        const audits = await overtimeAuditRows(reason);
        const createdAudit = audits.find((audit) => audit.action === 'created');
        const rejectedAudit = audits.find((audit) => audit.status === 'rejected');
        expect(createdAudit).toBeDefined();
        expect(rejectedAudit).toBeDefined();
        expect(createdAudit!.actor_email).toBe(employeeActor.email);
        expect(rejectedAudit!.actor_email).toBe(departmentHeadActor.email);

        const publicId = String(journey.values.overtimeId);
        const expectedNotices = [
          `attendance.ot_rejected|${employeeActor.email.toLowerCase()}|/self-service/overtime/${publicId}`,
          `attendance.ot_submitted|${departmentHeadActor.email.toLowerCase()}|/hr/attendance/overtime/${publicId}`,
        ].sort();
        await expect.poll(async () => (await overtimeNotificationRows(publicId)).map((notice) =>
          `${notice.type}|${notice.recipient_email.toLowerCase()}|${notice.link_to}`).sort(), {
          timeout: 15_000,
          message: 'the submission notice should go to the approver and the rejection notice only to the request owner',
        }).toEqual(expectedNotices);
        const notices = await overtimeNotificationRows(publicId);
        assertRecipientsExactly(notices.map((notice) => notice.recipient_email), [employeeActor.email, departmentHeadActor.email]);
        expect(notices.find((notice) => notice.type === 'attendance.ot_rejected')?.link_to)
          .toBe(`/self-service/overtime/${publicId}`);
        expect(await journeyActorMailIds(), 'overtime rejection should not emit email in this configured flow').toEqual(mailIdsBefore);
        const badges = await readApiJson<{ data: Record<string, { count: number }> }>(departmentHead.context, '/api/v1/dashboards/badges');
        expect(badges.data.overtime?.count ?? 0, 'a rejected request should leave the actionable overtime badge').toBe(0);
        employee.diagnostics.assertClean('Employee observes the rejected overtime status and reason in the open list');
        departmentHead.diagnostics.assertClean('Department Head rejects through the actionable dashboard hand-off');
      },
    });

    await captureAnnotatedStep(employee.page, 'employee', 'Employee sees the rejected overtime and reason without refreshing', 3, testInfo);
  } catch (error) {
    primaryError = error;
    throw error;
  } finally {
    const closeResults = await Promise.allSettled([employee.context.close(), departmentHead.context.close()]);
    const cleanupErrors = closeResults.flatMap((result, index) => result.status === 'rejected'
      ? [`${index === 0 ? 'employee' : 'Department Head'} context close: ${result.reason instanceof Error ? result.reason.message : String(result.reason)}`]
      : []);
    if (cleanupErrors.length > 0) {
      await testInfo.attach('journey-cleanup-errors.txt', {
        body: cleanupErrors.join('\n'),
        contentType: 'text/plain',
      });
      if (primaryError === undefined) throw new Error(cleanupErrors.join('\n'));
    }
  }
});

test('HR03-CSV-VALIDATION: HR imports a valid DTR row and receives a row-level error for an unknown employee', async ({ browser }, testInfo) => {
  const actors = await loadActors();
  const employeeActor = actorByName(actors, 'employee');
  const hrActor = actorByName(actors, 'hr_officer');
  const baseline = await punchBaseline(employeeActor.email);
  const journey = createJourneyContext('HR03-CSV-VALIDATION');
  const unknownEmployeeNo = `UNKNOWN-${journey.runId.slice(0, 8)}`;
  const fileName = `${journey.values.runPrefix}-biometric-dtr.csv`;
  const csv = [
    'employee_no,date,time_in,time_out',
    `${baseline.employee_no},${baseline.work_date},${baseline.shift_start},${baseline.shift_end}`,
    `${unknownEmployeeNo},${baseline.work_date},${baseline.shift_start},${baseline.shift_end}`,
  ].join('\n');
  journey.values.employeeNo = baseline.employee_no;
  journey.values.workDate = baseline.work_date;
  journey.values.shift = baseline.shift_name;
  journey.values.shiftStart = baseline.shift_start;
  journey.values.shiftEnd = baseline.shift_end;
  journey.values.unknownEmployeeNo = unknownEmployeeNo;
  journey.values.csvFileName = fileName;

  const hr = await actorPage(browser, hrActor.actor, { timezoneId: 'Asia/Manila' });
  const employee = await actorPage(browser, employeeActor.actor, { timezoneId: 'Asia/Manila' });
  const journeyActorEmails = new Set([employeeActor.email, hrActor.email].map((email) => email.toLowerCase()));
  const journeyActorMailIds = async () => (await listMailpitMessages(500))
    .filter((message) => message.To.some((recipient) => journeyActorEmails.has(recipient.Address.toLowerCase())))
    .map((message) => message.ID)
    .sort();
  const mailIdsBefore = await journeyActorMailIds();
  const plannedSteps = csvImportSteps;
  let primaryError: unknown;

  try {
    const browserNow = localInstant(baseline.work_date, '12:00:00');
    await hr.page.clock.setFixedTime(new Date(browserNow));
    await employee.page.clock.setFixedTime(new Date(browserNow));

    await runProcessStep({
      ...plannedSteps[0],
      page: hr.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => {
        await openAttendanceList(hr, baseline.work_date, baseline.employee_no);
        await hr.page.getByRole('button', { name: 'Import DTR', exact: true }).click();
        await expect(hr.page).toHaveURL(/\/hr\/attendance\/import(?:\?|$)/);
        await expect(hr.page.getByRole('heading', { name: 'Import biometric DTR', exact: true })).toBeVisible();
        await employee.page.goto('/dashboard');
        await expect(employee.page.getByRole('navigation', { name: 'Modules' })).toBeVisible();
        await openSelfServiceDtr(employee, baseline.work_date);
      },
      verify: async () => {
        await expect(hr.page.getByRole('button', { name: 'Import', exact: true })).toBeDisabled();
        const employeeRow = employee.page.getByRole('row').filter({ hasText: dateLabel(baseline.work_date) });
        await expect(employeeRow, 'the Employee DTR should have no row before the CSV upload').toHaveCount(0);
      },
      assertInvariants: async () => {
        const initial = await punchSnapshot(employeeActor.email, baseline.work_date);
        expect(initial).toHaveLength(1);
        expect(initial[0].attendance_id).toBeNull();
        expect(initial[0].punches).toEqual([]);
        expect(await journeyActorMailIds()).toEqual(mailIdsBefore);
        hr.diagnostics.assertClean('HR opens the DTR import form through the attendance page');
        employee.diagnostics.assertClean('Employee opens DTR as a second-viewer observer');
      },
    });

    await runProcessStep({
      ...plannedSteps[1],
      page: hr.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async (page) => {
        await page.locator('input[type="file"][accept=".csv,text/csv"]').setInputFiles({
          name: fileName,
          mimeType: 'text/csv',
          buffer: Buffer.from(csv, 'utf8'),
        });
        await expect(page.getByText(fileName, { exact: true })).toBeVisible();
        const uploaded = page.waitForResponse((response) =>
          new URL(response.url()).pathname === '/api/v1/attendance/attendances/import'
          && response.request().method() === 'POST');
        await page.getByRole('button', { name: 'Import', exact: true }).click();
        const response = await uploaded;
        const responseText = await response.text();
        expect(response.status(), `HR biometric CSV upload should return its row summary; response: ${responseText.slice(0, 500)}`).toBe(200);
        const body = JSON.parse(responseText) as {
          data?: { total?: number; imported?: number; skipped?: number; errors?: Array<{ row: number; message: string }> };
        };
        expect(body.data).toMatchObject({
          total: 2,
          imported: 1,
          skipped: 1,
          errors: [{ row: 3, message: `Unknown employee_no '${unknownEmployeeNo}'.` }],
        });
        journey.values.importSummary = body.data;
      },
      verify: async (page) => {
        const summary = page.getByRole('heading', { name: 'Import summary', exact: true });
        await expect(summary).toBeVisible();
        const total = page.getByText('Total', { exact: true }).locator('xpath=..');
        const imported = page.getByText('Imported', { exact: true }).locator('xpath=..');
        const skipped = page.getByText('Skipped', { exact: true }).locator('xpath=..');
        await expect(total).toContainText('2');
        await expect(imported).toContainText('1');
        await expect(skipped).toContainText('1');
        await expect(page.getByText('Row 3', { exact: true })).toBeVisible();
        await expect(page.getByText(`Unknown employee_no '${unknownEmployeeNo}'.`, { exact: true })).toBeVisible();
      },
      assertInvariants: async () => {
        await assertImportedDtr(
          employeeActor.email,
          baseline.employee_no,
          hrActor.email,
          baseline.work_date,
          baseline.shift_start,
          baseline.shift_end,
          unknownEmployeeNo,
        );
        expect(await journeyActorMailIds()).toEqual(mailIdsBefore);
        hr.diagnostics.assertClean('HR receives a partial-success summary after CSV import');
        employee.diagnostics.assertClean('Employee DTR remains an open realtime observer during CSV import');
      },
    });

    await runProcessStep({
      ...plannedSteps[2],
      page: employee.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => undefined,
      verify: async (page) => {
        const employeeRow = page.getByRole('row').filter({ hasText: dateLabel(baseline.work_date) });
        await expect(employeeRow, 'attendance_changed should update the Employee DTR without reload')
          .toHaveCount(1, { timeout: 20_000 });
        await expect(employeeRow).toContainText('Present');
        await expect(employeeRow).toContainText(manilaTime(localInstant(baseline.work_date, baseline.shift_start)));
        await expect(employeeRow).toContainText(manilaTime(localInstant(baseline.work_date, baseline.shift_end)));
        await expect(employeeRow).toContainText('7.5');
      },
      assertInvariants: async () => {
        await assertImportedDtr(
          employeeActor.email,
          baseline.employee_no,
          hrActor.email,
          baseline.work_date,
          baseline.shift_start,
          baseline.shift_end,
          unknownEmployeeNo,
        );
        employee.diagnostics.assertClean('Employee observes the imported DTR without refresh');
        hr.diagnostics.assertClean('HR retains the partial-success import summary');
      },
    });

    await runProcessStep({
      ...plannedSteps[3],
      page: employee.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async (page) => {
        await page.reload();
        await expect(page.getByRole('heading', { name: 'Daily Time Record', exact: true })).toBeVisible();
      },
      verify: async (page) => {
        const employeeRow = page.getByRole('row').filter({ hasText: dateLabel(baseline.work_date) });
        await expect(employeeRow).toHaveCount(1);
        await expect(employeeRow).toContainText('Present');
        await expect(employeeRow).toContainText(manilaTime(localInstant(baseline.work_date, baseline.shift_start)));
        await expect(employeeRow).toContainText(manilaTime(localInstant(baseline.work_date, baseline.shift_end)));
        await expect(employeeRow).toContainText('7.5');
      },
      assertInvariants: async () => {
        await assertImportedDtr(
          employeeActor.email,
          baseline.employee_no,
          hrActor.email,
          baseline.work_date,
          baseline.shift_start,
          baseline.shift_end,
          unknownEmployeeNo,
        );
        expect(await journeyActorMailIds()).toEqual(mailIdsBefore);
        employee.diagnostics.assertClean('Employee confirms the imported DTR persists after reload');
        hr.diagnostics.assertClean('HR retains the import summary after the DTR side effect');
      },
    });
  } catch (error) {
    primaryError = error;
    throw error;
  } finally {
    const closeResults = await Promise.allSettled([hr.context.close(), employee.context.close()]);
    const cleanupErrors = closeResults.flatMap((result, index) => result.status === 'rejected'
      ? [`${index === 0 ? 'HR' : 'employee'} context close: ${result.reason instanceof Error ? result.reason.message : String(result.reason)}`]
      : []);
    if (cleanupErrors.length > 0) {
      await testInfo.attach('journey-cleanup-errors.txt', {
        body: cleanupErrors.join('\n'),
        contentType: 'text/plain',
      });
      if (primaryError === undefined) throw new Error(cleanupErrors.join('\n'));
    }
  }
});

function nextLocalDate(date: string): string {
  const [year, month, day] = date.split('-').map(Number);
  const next = new Date(Date.UTC(year, month - 1, day + 1));
  return `${next.getUTCFullYear()}-${String(next.getUTCMonth() + 1).padStart(2, '0')}-${String(next.getUTCDate()).padStart(2, '0')}`;
}
