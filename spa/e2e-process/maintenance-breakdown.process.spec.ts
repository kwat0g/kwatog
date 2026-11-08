import { test, expect } from './fixtures';
import { actorPage, loadActors } from './actor-session';
import type { E2EActor } from './actor-session';
import { createJourneyContext, runProcessStep } from './process-runner';
import type { JourneyContext } from './process-runner';
import { readOnlyDatabaseJson } from './helpers/database-reader';
import { clearClock, freezeClock, readClock } from './helpers/clock';

type ActorPage = Awaited<ReturnType<typeof actorPage>>;
type WorkOrderSnapshot = {
  id: number;
  mwo_number: string;
  status: string;
  type: string;
  priority: string;
  verification_required: boolean;
  created_by_email: string;
  assigned_email: string | null;
  machine_code: string;
  machine_status: string;
  started_at: string | null;
  completed_at: string | null;
  downtime_minutes: number;
};
type NotificationRow = { type: string; email: string; title: string };
type AuditRow = { status: string; email: string };

const plannedSteps = [
  { name: 'HR configures Maintenance shift coverage for the work period', actor: 'hr_officer', uiPath: 'Human Resources → Attendance & DTR → Shifts → Bulk assign', expectedStatus: 'configured' },
  { name: 'Create a corrective maintenance work order for a free machine', actor: 'maintenance_tech', uiPath: 'Maintenance → Maintenance Work Orders → New work order → Create work order', expectedStatus: 'open' },
  { name: 'Production manager discovers the order from the work order list', actor: 'production_manager', uiPath: 'Maintenance → Maintenance Work Orders → work order row', expectedStatus: 'open' },
  { name: 'Maintenance supervisor follows assignment-needed notification and assigns the technician', actor: 'maintenance_supervisor', uiPath: 'Notifications → assignment-needed notification → Assign worker', expectedStatus: 'assigned' },
  { name: 'Technician follows assignment notification and starts the order', actor: 'maintenance_tech', uiPath: 'Notifications → assigned notification → Confirm start', expectedStatus: 'in_progress' },
  { name: 'Technician records the passing work result', actor: 'maintenance_tech', uiPath: 'Work order detail → Record work → Save work record', expectedStatus: 'in_progress' },
  { name: 'Technician completes the work order', actor: 'maintenance_tech', uiPath: 'Work order detail → Complete → Confirm complete', expectedStatus: 'completed' },
];

function currentMaintenanceInstant(): string {
  const instant = new Date();
  instant.setUTCSeconds(0, 0);
  return instant.toISOString();
}

function maintenanceShiftPlan(instant: string): { name: string; effectiveFrom: string } {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
  }).formatToParts(new Date(instant));
  const value = (type: string) => parts.find((part) => part.type === type)?.value;
  const year = value('year');
  const month = value('month');
  const day = value('day');
  const hour = Number(value('hour'));
  const minute = Number(value('minute'));
  if (!year || !month || !day || !Number.isInteger(hour) || !Number.isInteger(minute)) {
    throw new Error('Could not derive the Manila shift window from the E2E instant.');
  }
  const today = `${year}-${month}-${day}`;
  if (hour >= 6 && hour < 18) return { name: 'Extended Day', effectiveFrom: today };
  if (hour >= 18) return { name: 'Night Shift', effectiveFrom: today };

  const previousDay = new Date(Date.parse(`${today}T00:00:00+08:00`) - 24 * 60 * 60 * 1000);
  const previousDate = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit',
  }).format(previousDay);
  return { name: 'Night Shift', effectiveFrom: previousDate };
}

function actorByName(actors: E2EActor[], actor: string): E2EActor {
  const match = actors.find((candidate) => candidate.actor === actor);
  if (!match) throw new Error(`Required seeded E2E actor is missing: ${actor}`);
  return match;
}

function contextValue<T>(context: JourneyContext, key: string): T {
  const value = context.values[key];
  if (value === undefined) throw new Error(`Journey context is missing ${key}.`);
  return value as T;
}

function sqlText(value: string): string {
  return `'${value.replaceAll("'", "''")}'`;
}

async function openSession(session: ActorPage): Promise<void> {
  // The setup project signs this actor in through the real login UI and stores
  // that actor's own session. Enter through the sign-in route so the app sends
  // an already-authenticated user to their normal landing page.
  await session.page.goto('/sign-in');
  await expect(session.page).toHaveURL(/\/dashboard(?:\/|$)/);
}

async function openMaintenanceMenu(session: ActorPage): Promise<void> {
  const page = session.page;
  const link = page.getByRole('navigation', { name: 'Modules' }).locator('a[href="/maintenance/work-orders"]');
  await expect(link, 'Maintenance Work Orders should be reachable from the sidebar').toHaveCount(1);
  if (!(await link.isVisible())) {
    // First-visit module expansion is a temporary peek that collapses after
    // five seconds. Use the sidebar's own persistent Expand all control so the
    // subsequent user click remains reachable without timing around that UI.
    const expandAll = page.getByRole('button', { name: 'Expand all', exact: true });
    await expect(expandAll).toBeVisible();
    await expandAll.click();
  }
  await expect(link).toBeVisible();
  await link.click();
  await expect(page.getByRole('heading', { name: 'Maintenance work orders', exact: true })).toBeVisible();
}

async function openAttendance(session: ActorPage): Promise<void> {
  const page = session.page;
  const link = page.getByRole('navigation', { name: 'Modules' }).locator('a[href="/hr/attendance"]');
  await expect(link, 'Attendance & DTR should be reachable from the sidebar').toHaveCount(1);
  if (!(await link.isVisible())) {
    const expandAll = page.getByRole('button', { name: 'Expand all', exact: true });
    await expect(expandAll).toBeVisible();
    await expandAll.click();
  }
  await expect(link).toBeVisible();
  await link.click();
  await expect(page.getByRole('heading', { name: 'Daily Time Records', exact: true })).toBeVisible();
}

async function openMaintenanceNotification(session: ActorPage, number: string): Promise<void> {
  const page = session.page;
  await page.getByRole('button', { name: /^Notifications(?:, \d+ unread)?$/ }).click();
  const tray = page.getByRole('dialog', { name: 'Notifications' });
  await expect(tray).toBeVisible();
  const link = tray.getByRole('link', { name: new RegExp(number) });
  await expect(link, `notification hand-off should identify ${number}`).toHaveCount(1);
  await link.click();
  await expect(page.getByRole('heading', { level: 1 })).toContainText(number);
}

async function expectStatus(page: ActorPage['page'], status: string): Promise<void> {
  const labels: Record<string, string> = {
    open: 'Open', assigned: 'Assigned', in_progress: 'In progress', completed: 'Completed', cancelled: 'Cancelled',
  };
  await expect(page.getByRole('heading', { level: 1 }).getByText(labels[status], { exact: true })).toBeVisible();
}

async function workOrder(context: JourneyContext): Promise<WorkOrderSnapshot> {
  const number = contextValue<string>(context, 'mwoNumber');
  const rows = await readOnlyDatabaseJson<WorkOrderSnapshot[]>(`
    SELECT wo.id, wo.mwo_number, wo.status, wo.type, wo.priority,
           wo.verification_required, creator.email AS created_by_email,
           technician.email AS assigned_email, machine.machine_code,
           machine.status AS machine_status, wo.started_at, wo.completed_at,
           wo.downtime_minutes
    FROM maintenance_work_orders wo
    JOIN users creator ON creator.id = wo.created_by
    LEFT JOIN employees employee ON employee.id = wo.assigned_to
           LEFT JOIN users technician ON technician.employee_id = employee.id
    JOIN machines machine ON wo.maintainable_type = 'machine' AND machine.id = wo.maintainable_id
    WHERE wo.mwo_number = ${sqlText(number)}
  `);
  expect(rows, `one maintenance work order should exist for ${number}`).toHaveLength(1);
  return rows[0];
}

async function assertDocumentAndStatus(context: JourneyContext, status: string, machineStatus: string): Promise<WorkOrderSnapshot> {
  const row = await workOrder(context);
  expect(row.status).toBe(status);
  expect(row.machine_status).toBe(machineStatus);
  expect(row.type).toBe('corrective');
  expect(row.priority).toBe('high');
  expect(row.verification_required).toBe(false);
  return row;
}

async function notifications(context: JourneyContext): Promise<NotificationRow[]> {
  const number = contextValue<string>(context, 'mwoNumber');
  return readOnlyDatabaseJson<NotificationRow[]>(`
    SELECT notice.type, recipient.email,
           notice.data ->> 'title' AS title
    FROM notifications notice
    JOIN users recipient ON recipient.id = notice.notifiable_id
    WHERE notice.notifiable_type LIKE '%User'
      AND notice.data ->> 'title' LIKE ${sqlText(`%${number}%`)}
    ORDER BY notice.type, recipient.email
  `);
}

async function assertNotificationRecipients(
  context: JourneyContext,
  expected: Array<{ type: string; email: string }>,
): Promise<void> {
  await expect.poll(async () => {
    const rows = await notifications(context);
    return rows.map(({ type, email }) => ({ type, email }));
  }, {
    message: 'maintenance notifications should reach exactly the expected recipients',
    timeout: 15_000,
  }).toEqual(expected);
}

async function auditTransitions(context: JourneyContext): Promise<AuditRow[]> {
  const number = contextValue<string>(context, 'mwoNumber');
  return readOnlyDatabaseJson<AuditRow[]>(`
    SELECT audit.new_values ->> 'status' AS status, actor.email
    FROM audit_logs audit
    JOIN users actor ON actor.id = audit.user_id
    WHERE audit.model_type ILIKE '%MaintenanceWorkOrder'
      AND audit.model_id = (SELECT id FROM maintenance_work_orders WHERE mwo_number = ${sqlText(number)})
      AND audit.action = 'updated'
      AND audit.new_values::jsonb ? 'status'
    ORDER BY audit.id
  `);
}

async function assertAuditTransitions(context: JourneyContext, expected: AuditRow[]): Promise<void> {
  expect(await auditTransitions(context)).toEqual(expected);
}

async function assertWorkRecords(context: JourneyContext, expectedStructured: number): Promise<void> {
  const number = contextValue<string>(context, 'mwoNumber');
  const rows = await readOnlyDatabaseJson<Array<{
    finding: string | null; action_taken: string | null; result: string | null; email: string;
  }>>(`
    SELECT log.finding, log.action_taken, log.result, actor.email
    FROM maintenance_logs log
    JOIN maintenance_work_orders wo ON wo.id = log.work_order_id
    JOIN users actor ON actor.id = log.logged_by
    WHERE wo.mwo_number = ${sqlText(number)}
      AND log.finding IS NOT NULL
    ORDER BY log.id
  `);
  expect(rows).toHaveLength(expectedStructured);
  if (expectedStructured > 0) {
    expect(rows).toEqual([{
      finding: contextValue<string>(context, 'finding'),
      action_taken: contextValue<string>(context, 'actionTaken'),
      result: 'passed',
      email: contextValue<string>(context, 'technicianEmail'),
    }]);
  }
}

async function assertDowntime(context: JourneyContext, ended: boolean): Promise<void> {
  const number = contextValue<string>(context, 'mwoNumber');
  const rows = await readOnlyDatabaseJson<Array<{
    category: string; end_time: string | null; duration_minutes: number | null;
  }>>(`
    SELECT downtime.category, downtime.end_time, downtime.duration_minutes
    FROM machine_downtimes downtime
    JOIN maintenance_work_orders wo ON wo.id = downtime.maintenance_order_id
    WHERE wo.mwo_number = ${sqlText(number)}
    ORDER BY downtime.id
  `);
  expect(rows).toHaveLength(1);
  expect(rows[0].category).toBe('planned_maintenance');
  if (ended) {
    expect(rows[0].end_time).not.toBeNull();
    expect(rows[0].duration_minutes).not.toBeNull();
  } else {
    expect(rows[0].end_time).toBeNull();
    expect(rows[0].duration_minutes).toBeNull();
  }
}

test('MNT01-BREAKDOWN — corrective maintenance through assignment, repair log, and completion', async ({ browser, request }, testInfo) => {
  test.setTimeout(6 * 60 * 1000);
  const actors = await loadActors();
  const hrIdentity = actorByName(actors, 'hr_officer');
  const technicianIdentity = actorByName(actors, 'maintenance_tech');
  const supervisorIdentity = actorByName(actors, 'maintenance_supervisor');
  const observerIdentity = actorByName(actors, 'production_manager');
  const context = createJourneyContext('MNT01-BREAKDOWN');
  const fixedMaintenanceInstant = currentMaintenanceInstant();
  const shiftPlan = maintenanceShiftPlan(fixedMaintenanceInstant);
  context.values.workDate = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit',
  }).format(new Date(fixedMaintenanceInstant));
  context.values.testInstant = fixedMaintenanceInstant;
  context.values.shiftName = shiftPlan.name;
  context.values.shiftEffectiveDate = shiftPlan.effectiveFrom;
  const actorSessions: ActorPage[] = [];
  let clockFrozen = false;
  let primaryError: unknown;

  const openActor = async (actor: string): Promise<ActorPage> => {
    const session = await actorPage(browser, actor, { timezoneId: 'Asia/Manila' });
    actorSessions.push(session);
    await openSession(session);
    return session;
  };

  const hr = await openActor(hrIdentity.actor);
  const technician = await openActor(technicianIdentity.actor);
  const supervisor = await openActor(supervisorIdentity.actor);
  const observer = await openActor(observerIdentity.actor);

  const checkDiagnostics = () => {
    for (const session of actorSessions) session.diagnostics.assertClean(context.journeyId);
  };
  const step = async (
    actor: string,
    name: string,
    uiPath: string,
    expectedStatus: string,
    session: ActorPage,
    action: () => Promise<void>,
    verify: () => Promise<void>,
    invariants: () => Promise<void>,
  ) => runProcessStep({
    name, actor, uiPath, expectedStatus, page: session.page, plannedSteps, context, testInfo,
    action,
    verify,
    assertInvariants: async () => { await invariants(); checkDiagnostics(); },
  });

  try {
    // Keep the simulated time within one minute of the host clock so the
    // browser and database-backed Laravel sessions remain valid during the run.
    for (const session of actorSessions) {
      await session.page.clock.setFixedTime(new Date(fixedMaintenanceInstant));
    }
    await freezeClock(request, fixedMaintenanceInstant);
    clockFrozen = true;
    expect(Date.parse(await readClock(request))).toBe(Date.parse(fixedMaintenanceInstant));

    await step(
      hrIdentity.actor,
      'HR configures Maintenance shift coverage for the work period',
      'Human Resources → Attendance & DTR → Shifts → Bulk assign',
      'configured',
      hr,
      async () => {
        await openAttendance(hr);
        await hr.page.getByRole('button', { name: 'Shifts', exact: true }).click();
        await expect(hr.page.getByRole('heading', { name: 'Shifts', exact: true })).toBeVisible();
        await hr.page.getByRole('button', { name: 'Bulk assign', exact: true }).click();
        await expect(hr.page.getByRole('heading', { name: 'Bulk assign shift', exact: true })).toBeVisible();

        await hr.page.getByRole('combobox', { name: 'Department', exact: true }).selectOption({ label: 'Maintenance & Engineering' });
        await hr.page.getByRole('combobox', { name: 'Shift', exact: true }).selectOption({ label: shiftPlan.name });
        await hr.page.getByRole('textbox', { name: 'Effective from', exact: true }).fill(shiftPlan.effectiveFrom);
        for (const day of ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']) {
          await hr.page.getByRole('checkbox', { name: day, exact: true }).check();
        }
        await hr.page.getByRole('button', { name: 'Assign shift', exact: true }).click();
        await expect(hr.page.getByRole('heading', { name: 'Shifts', exact: true })).toBeVisible();
        await expect(hr.page.getByText(/Assigned shift to \d+ employees\./)).toBeVisible();
      },
      async () => {
        const assignment = await readOnlyDatabaseJson<Array<{
          id: number; employee_email: string; shift_name: string; effective_date: string; weekdays_match: boolean;
        }>>(`
          SELECT assignment.id, employee.email AS employee_email, shift.name AS shift_name,
                 assignment.effective_date::text,
                 assignment.working_days::jsonb = '[1,2,3,4,5,6,7]'::jsonb AS weekdays_match
          FROM employee_shift_assignments assignment
          JOIN employees employee ON employee.id = assignment.employee_id
          JOIN shifts shift ON shift.id = assignment.shift_id
          WHERE employee.email = ${sqlText(technicianIdentity.email)}
            AND shift.name = ${sqlText(shiftPlan.name)}
            AND assignment.effective_date = ${sqlText(shiftPlan.effectiveFrom)}::date
            AND assignment.effective_date <= (CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila')::date
            AND (assignment.end_date IS NULL OR assignment.end_date >= (CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila')::date)
        `);
        expect(assignment).toHaveLength(1);
        expect(assignment[0].weekdays_match).toBe(true);
        expect(assignment[0].shift_name).toBe(shiftPlan.name);
        expect(assignment[0].effective_date).toBe(shiftPlan.effectiveFrom);
        context.values.shiftAssignmentId = assignment[0].id;
        context.values.shiftAssignmentEmployee = assignment[0].employee_email;

        const audit = await readOnlyDatabaseJson<Array<{ action: string; email: string }>>(`
          SELECT log.action, actor.email
          FROM audit_logs log
          JOIN users actor ON actor.id = log.user_id
          WHERE log.model_type ILIKE '%EmployeeShiftAssignment'
            AND log.model_id = ${assignment[0].id}
            AND log.action = 'created'
        `);
        expect(audit).toEqual([{ action: 'created', email: hrIdentity.email }]);
      },
      async () => {
        expect(context.values.shiftAssignmentEmployee).toBe(technicianIdentity.email);
        expect(context.values.shiftAssignmentId).toBeTruthy();
      },
    );

    await step(
      technicianIdentity.actor,
      'Create a corrective maintenance work order for a free machine',
      'Maintenance → Maintenance Work Orders → New work order → Create work order',
      'open',
      technician,
      async () => {
        await openMaintenanceMenu(technician);
        await technician.page.getByRole('button', { name: 'New work order', exact: true }).click();
        await expect(technician.page.getByRole('heading', { name: 'New maintenance work order' })).toBeVisible();

        const typeControls = technician.page.getByRole('combobox', { name: 'Type', exact: true });
        await expect(typeControls).toHaveCount(2);
        await expect(technician.page.getByRole('option', { name: 'Machine', exact: true })).toHaveCount(1);
        await typeControls.nth(0).selectOption('machine');
        const target = technician.page.getByRole('combobox', { name: 'Target', exact: true });
        await target.fill('IMM');
        const firstTarget = technician.page.getByRole('listbox', { name: 'Target', exact: true }).getByRole('option').first();
        await expect(firstTarget).toBeVisible();
        const targetLabel = await firstTarget.innerText();
        await firstTarget.click();
        context.values.machineCode = targetLabel.split(' - ')[0].trim();

        await typeControls.nth(1).selectOption('corrective');
        await technician.page.getByRole('combobox', { name: 'Priority', exact: true }).selectOption('high');
        await technician.page.getByRole('textbox', { name: /Description/ }).fill(`${context.values.runPrefix} corrective breakdown inspection and repair`);
        await technician.page.getByRole('button', { name: 'Create work order', exact: true }).click();
        await expect(technician.page.getByRole('heading', { level: 1 })).toContainText(/MWO-/);
        const heading = await technician.page.getByRole('heading', { level: 1 }).innerText();
        const number = heading.match(/MWO-[A-Z0-9-]+/i)?.[0];
        expect(number, 'work order detail should show its generated number').toBeTruthy();
        context.values.mwoNumber = number;
        await expectStatus(technician.page, 'open');
      },
      async () => {
        const row = await assertDocumentAndStatus(context, 'open', 'idle');
        expect(row.created_by_email).toBe(technicianIdentity.email);
        expect(row.assigned_email).toBeNull();
        expect(technician.page.getByRole('button', { name: 'Confirm start', exact: true })).toBeVisible();
        expect(technician.page.getByRole('button', { name: 'Record work', exact: true })).toHaveCount(0);
        expect(technician.page.getByRole('button', { name: 'Complete', exact: true })).toHaveCount(0);
      },
      async () => {
        const number = contextValue<string>(context, 'mwoNumber');
        const matches = await readOnlyDatabaseJson<Array<{ count: number }>>(`
          SELECT COUNT(*)::int AS count FROM maintenance_work_orders WHERE mwo_number = ${sqlText(number)}
        `);
        expect(matches[0].count, 'generated MWO number should be unique').toBe(1);
        await expect.poll(() => notifications(context), {
          message: 'the assignment-needed notification should be delivered to the Maintenance Supervisor',
          timeout: 15_000,
        }).toEqual([{
          type: 'maintenance.assignment_needed',
          email: supervisorIdentity.email,
          title: `Maintenance assignment needed: ${number}`,
        }]);
        await assertAuditTransitions(context, []);
      },
    );

    await step(
      observerIdentity.actor,
      'Production manager discovers the order from the work order list',
      'Maintenance → Maintenance Work Orders → work order row',
      'open',
      observer,
      async () => {
        await openMaintenanceMenu(observer);
        const row = observer.page.getByRole('row').filter({ hasText: contextValue<string>(context, 'mwoNumber') });
        await expect(row).toHaveCount(1);
        await row.click();
        await expectStatus(observer.page, 'open');
      },
      async () => {
        await expect(observer.page.getByRole('button', { name: 'Assign worker', exact: true })).toHaveCount(0);
        await expect(observer.page.getByRole('button', { name: 'Confirm start', exact: true })).toHaveCount(0);
        await expect(observer.page.getByRole('button', { name: 'Record work', exact: true })).toHaveCount(0);
        await expect(observer.page.getByRole('button', { name: 'Complete', exact: true })).toHaveCount(0);
      },
      async () => {
        await assertDocumentAndStatus(context, 'open', 'idle');
        await assertNotificationRecipients(context, [{ type: 'maintenance.assignment_needed', email: supervisorIdentity.email }]);
      },
    );

    await step(
      supervisorIdentity.actor,
      'Maintenance supervisor follows assignment-needed notification and assigns the technician',
      'Notifications → assignment-needed notification → Assign worker',
      'assigned',
      supervisor,
      async () => {
        await openMaintenanceNotification(supervisor, contextValue<string>(context, 'mwoNumber'));
        await expectStatus(supervisor.page, 'open');
        await supervisor.page.getByRole('button', { name: 'Assign worker', exact: true }).click();
        await expect(supervisor.page.getByRole('heading', { name: 'Assign worker', exact: true })).toBeVisible();
        const technicianOption = supervisor.page.getByRole('radio', { name: /E2E maintenance tech/ });
        await expect(technicianOption).toBeVisible();
        await technicianOption.check();
        const presenceAttestation = supervisor.page.getByRole('checkbox', { name: /I confirm E2E maintenance tech is physically present/ });
        await expect(presenceAttestation).toBeVisible();
        await presenceAttestation.check();
        await supervisor.page.getByRole('button', { name: 'Assign technician', exact: true }).click();
        await expectStatus(supervisor.page, 'assigned');
      },
      async () => {
        const row = await assertDocumentAndStatus(context, 'assigned', 'idle');
        expect(row.created_by_email).toBe(technicianIdentity.email);
        expect(row.assigned_email).toBe(technicianIdentity.email);
        await expectStatus(observer.page, 'assigned');
        await expect(observer.page.getByRole('button', { name: 'Assign worker', exact: true })).toHaveCount(0);
      },
      async () => {
        await assertNotificationRecipients(context, [
          { type: 'maintenance.assigned', email: technicianIdentity.email },
          { type: 'maintenance.assignment_needed', email: supervisorIdentity.email },
        ]);
        await assertAuditTransitions(context, [{ status: 'assigned', email: supervisorIdentity.email }]);
      },
    );

    await step(
      technicianIdentity.actor,
      'Technician follows assignment notification and starts the order',
      'Notifications → assigned notification → Confirm start',
      'in_progress',
      technician,
      async () => {
        await openMaintenanceNotification(technician, contextValue<string>(context, 'mwoNumber'));
        await expectStatus(technician.page, 'assigned');
        await technician.page.getByRole('button', { name: 'Confirm start', exact: true }).click();
        await expect(technician.page.getByRole('heading', { name: 'Confirm maintenance start', exact: true })).toBeVisible();
        await technician.page.getByRole('button', { name: 'Confirm & start', exact: true }).click();
        await expectStatus(technician.page, 'in_progress');
      },
      async () => {
        const row = await assertDocumentAndStatus(context, 'in_progress', 'maintenance');
        expect(row.assigned_email).toBe(technicianIdentity.email);
        expect(row.started_at).not.toBeNull();
        await expectStatus(observer.page, 'in_progress');
        await assertDowntime(context, false);
        await expect(technician.page.getByRole('button', { name: 'Record work', exact: true })).toBeVisible();
        await expect(technician.page.getByRole('button', { name: 'Complete', exact: true })).toHaveCount(0);
      },
      async () => {
        await assertNotificationRecipients(context, [
          { type: 'maintenance.assigned', email: technicianIdentity.email },
          { type: 'maintenance.assignment_needed', email: supervisorIdentity.email },
        ]);
        await assertAuditTransitions(context, [
          { status: 'assigned', email: supervisorIdentity.email },
          { status: 'in_progress', email: technicianIdentity.email },
        ]);
        await assertWorkRecords(context, 0);
      },
    );

    await step(
      technicianIdentity.actor,
      'Technician records the passing work result',
      'Work order detail → Record work → Save work record',
      'in_progress',
      technician,
      async () => {
        const finding = `${context.values.runPrefix} inspected the injection unit and isolated a failed sensor.`;
        const actionTaken = 'Replaced the sensor connector and verified the safety interlock and dry cycle.';
        context.values.finding = finding;
        context.values.actionTaken = actionTaken;
        context.values.technicianEmail = technicianIdentity.email;
        await technician.page.getByRole('button', { name: 'Record work', exact: true }).click();
        await expect(technician.page.getByRole('heading', { name: 'Record work', exact: true })).toBeVisible();
        await technician.page.getByRole('textbox', { name: /Finding/ }).fill(finding);
        await technician.page.getByRole('textbox', { name: /Action taken/ }).fill(actionTaken);
        await technician.page.getByRole('combobox', { name: /Result/ }).selectOption('passed');
        await technician.page.getByRole('button', { name: 'Save work record', exact: true }).click();
        await expect(technician.page.getByText(finding, { exact: true })).toBeVisible();
        await expect(technician.page.getByText('Passed', { exact: true })).toBeVisible();
        await expectStatus(technician.page, 'in_progress');
      },
      async () => {
        await assertDocumentAndStatus(context, 'in_progress', 'maintenance');
        await expect(observer.page.getByText(contextValue<string>(context, 'finding'), { exact: true })).toBeVisible();
        await assertWorkRecords(context, 1);
        await expect(technician.page.getByRole('button', { name: 'Complete', exact: true })).toBeVisible();
      },
      async () => {
        await assertAuditTransitions(context, [
          { status: 'assigned', email: supervisorIdentity.email },
          { status: 'in_progress', email: technicianIdentity.email },
        ]);
        await assertDowntime(context, false);
      },
    );

    await step(
      technicianIdentity.actor,
      'Technician completes the work order',
      'Work order detail → Complete → Confirm complete',
      'completed',
      technician,
      async () => {
        await technician.page.getByRole('button', { name: 'Complete', exact: true }).click();
        await expect(technician.page.getByRole('heading', { name: 'Complete work order', exact: true })).toBeVisible();
        await technician.page.getByRole('textbox', { name: /Remarks \(optional\)/ }).fill(`${context.values.runPrefix} repair passed the functional test.`);
        await technician.page.getByRole('button', { name: 'Confirm complete', exact: true }).click();
        await expectStatus(technician.page, 'completed');
      },
      async () => {
        const row = await assertDocumentAndStatus(context, 'completed', 'idle');
        expect(row.completed_at).not.toBeNull();
        expect(row.downtime_minutes).toBeGreaterThanOrEqual(0);
        await expectStatus(observer.page, 'completed');
        await expect(technician.page.getByRole('button', { name: 'Complete', exact: true })).toHaveCount(0);
        await assertDowntime(context, true);
        await assertWorkRecords(context, 1);
      },
      async () => {
        await assertNotificationRecipients(context, [
          { type: 'maintenance.assigned', email: technicianIdentity.email },
          { type: 'maintenance.assignment_needed', email: supervisorIdentity.email },
        ]);
        await assertAuditTransitions(context, [
          { status: 'assigned', email: supervisorIdentity.email },
          { status: 'in_progress', email: technicianIdentity.email },
          { status: 'completed', email: technicianIdentity.email },
        ]);
      },
    );
  } catch (error) {
    for (const [index, session] of actorSessions.entries()) {
      const screenshot = await session.page.screenshot({ fullPage: true }).catch(() => null);
      if (screenshot) {
        await testInfo.attach(`actor-session-${index + 1}-failure.png`, {
          body: screenshot,
          contentType: 'image/png',
        });
      }
    }
    primaryError = error;
    throw error;
  } finally {
    const cleanupErrors: string[] = [];
    try {
      if (clockFrozen) await clearClock(request);
    } catch (error) {
      cleanupErrors.push(`clock reset: ${error instanceof Error ? error.message : String(error)}`);
    }
    const closeResults = await Promise.allSettled(actorSessions.map((session) => session.context.close()));
    closeResults.forEach((result, index) => {
      if (result.status === 'rejected') {
        cleanupErrors.push(`actor context ${index + 1} close: ${result.reason instanceof Error ? result.reason.message : String(result.reason)}`);
      }
    });
    if (cleanupErrors.length > 0) {
      await testInfo.attach('journey-cleanup-errors.txt', {
        body: cleanupErrors.join('\n'),
        contentType: 'text/plain',
      });
      if (primaryError === undefined) throw new Error(cleanupErrors.join('\n'));
    }
  }
});
