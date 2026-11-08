import { randomUUID } from 'node:crypto';
import { test, expect } from './fixtures';
import { actorPage, loadActors } from './actor-session';
import type { E2EActor } from './actor-session';
import { createJourneyContext, runProcessStep } from './process-runner';
import { readOnlyDatabaseJson } from './helpers/database-reader';
import { readClock } from './helpers/clock';
import { findMailpitMessagesFor } from './helpers/mailpit-reader';

type ActorPage = Awaited<ReturnType<typeof actorPage>>;

type EmployeeBaseline = {
  employee_id: number;
  employee_no: string;
  first_name: string;
  last_name: string;
};

type AuditEntry = {
  action: string;
  actor_email: string | null;
  status: string | null;
};

type CourseAuditEntry = Pick<AuditEntry, 'action' | 'actor_email'>;

type CourseSnapshot = {
  training_id: number;
  name: string;
  is_active: boolean;
  validity_months: number;
  audit_entries: CourseAuditEntry[];
};

type EmployeeTrainingSnapshot = {
  record_id: number;
  employee_no: string;
  course_name: string;
  status: string;
  scheduled_for: string;
  completed_at: string | null;
  expires_at: string | null;
  created_by_email: string | null;
  audit_entries: AuditEntry[];
  notification_count: number;
};

const plannedSteps = [
  {
    name: 'HR creates a run-scoped training course through Training Programs',
    actor: 'hr_officer',
    uiPath: 'Human Resources → Training Programs → Add Training → Create training',
    expectedStatus: 'active training course; no employee assignment exists',
  },
  {
    name: 'HR assigns the course to a department employee and the upcoming badge updates',
    actor: 'hr_officer; department_head__hr observer',
    uiPath: 'Human Resources → Employees → employee detail → Trainings → Assign',
    expectedStatus: 'scheduled; employee is eligible for the upcoming hand-off; HR can complete',
  },
  {
    name: 'Department Head follows the upcoming-training sidebar hand-off',
    actor: 'department_head__hr',
    uiPath: 'Human Resources → Employee Training Records badge → Upcoming / expiring → linked row',
    expectedStatus: 'scheduled detail is visible; observer has no completion or cancellation action',
  },
  {
    name: 'HR records completion and the open observer detail updates live',
    actor: 'hr_officer; department_head__hr observer',
    uiPath: 'Employee detail → Trainings → Mark training complete → Complete training',
    expectedStatus: 'completed with the completion date and validity-derived expiry date',
  },
];

function actorByName(actors: E2EActor[], actor: string): E2EActor {
  const found = actors.find((candidate) => candidate.actor === actor);
  if (!found) throw new Error(`Required seeded E2E actor is missing: ${actor}`);
  return found;
}

function sqlText(value: string): string {
  return `'${value.replaceAll("'", "''")}'`;
}

function manilaDate(instant: string): string {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(new Date(instant));
  const part = (type: string) => parts.find((candidate) => candidate.type === type)?.value;
  const year = part('year');
  const month = part('month');
  const day = part('day');
  if (!year || !month || !day) throw new Error(`Cannot resolve Manila business date from ${instant}.`);
  return `${year}-${month}-${day}`;
}

function addValidityMonths(date: string, months: number): string {
  const result = new Date(`${date}T00:00:00.000Z`);
  result.setUTCMonth(result.getUTCMonth() + months);
  return result.toISOString().slice(0, 10);
}

async function employeeForEmail(email: string): Promise<EmployeeBaseline> {
  const rows = await readOnlyDatabaseJson<EmployeeBaseline[]>(`
    SELECT employee.id AS employee_id,
           employee.employee_no,
           employee.first_name,
           employee.last_name
      FROM users actor
      JOIN employees employee ON employee.id = actor.employee_id
     WHERE actor.email = ${sqlText(email)}
  `);
  expect(rows, 'the department-scoped observer must resolve to its seeded Employee row').toHaveLength(1);
  return rows[0];
}

async function courseForName(name: string): Promise<CourseSnapshot[]> {
  return readOnlyDatabaseJson<CourseSnapshot[]>(`
    SELECT course.id AS training_id,
           course.name,
           course.is_active,
           course.validity_months,
           COALESCE((
             SELECT json_agg(json_build_object(
               'action', audit.action,
               'actor_email', actor.email
             ) ORDER BY audit.id)
               FROM audit_logs audit
               LEFT JOIN users actor ON actor.id = audit.user_id
              WHERE audit.model_type ILIKE '%Training'
                AND audit.model_type NOT ILIKE '%EmployeeTraining'
                AND audit.model_id = course.id
           ), '[]'::json) AS audit_entries
      FROM trainings course
     WHERE course.name = ${sqlText(name)}
  `);
}

async function employeeTrainingFor(employeeNo: string, courseName: string): Promise<EmployeeTrainingSnapshot[]> {
  return readOnlyDatabaseJson<EmployeeTrainingSnapshot[]>(`
    SELECT record.id AS record_id,
           employee.employee_no,
           course.name AS course_name,
           record.status::text AS status,
           record.scheduled_for::text AS scheduled_for,
           record.completed_at::text AS completed_at,
           record.expires_at::text AS expires_at,
           creator.email AS created_by_email,
           COALESCE((
             SELECT json_agg(json_build_object(
               'action', audit.action,
               'actor_email', actor.email,
               'status', audit.new_values->>'status'
             ) ORDER BY audit.id)
               FROM audit_logs audit
               LEFT JOIN users actor ON actor.id = audit.user_id
              WHERE audit.model_type ILIKE '%EmployeeTraining'
                AND audit.model_id = record.id
           ), '[]'::json) AS audit_entries,
           (SELECT COUNT(*)::int
              FROM notifications notice
             WHERE notice.data->>'subject_type' ILIKE '%EmployeeTraining'
               AND notice.data->>'subject_id' = record.id::text) AS notification_count
      FROM employee_trainings record
      JOIN employees employee ON employee.id = record.employee_id
      JOIN trainings course ON course.id = record.training_id
      LEFT JOIN users creator ON creator.id = record.created_by
     WHERE employee.employee_no = ${sqlText(employeeNo)}
       AND course.name = ${sqlText(courseName)}
  `);
}

async function mailIdsForActors(emails: string[]): Promise<string[]> {
  const messages = await Promise.all(emails.map((email) => findMailpitMessagesFor(email)));
  return messages.flat().map((message) => message.ID).sort();
}

async function openSidebarItem(
  session: ActorPage,
  section: string,
  href: string,
  label: string,
): Promise<void> {
  const nav = session.page.getByRole('navigation', { name: 'Modules' });
  const target = nav.locator(`a[href="${href}"]`);
  await expect(target, `the ${label} route should be available to this role`).toHaveCount(1);
  if (!(await target.isVisible())) {
    const module = session.page.locator(`[data-module="${section}"]`);
    const toggle = module.getByRole('button');
    await expect(toggle, `the ${section} sidebar group should be expandable`).toHaveCount(1);
    if ((await toggle.getAttribute('aria-expanded')) !== 'true') await toggle.click();
  }
  await expect(target).toBeVisible();
  await target.click();
}

test('HR07-TRAINING-COMPLETE — HR assigns and completes training while the department-scoped observer follows the upcoming badge', async ({ browser, request }, testInfo) => {
  test.setTimeout(180_000);

  const actors = await loadActors();
  const hrActor = actorByName(actors, 'hr_officer');
  const observerActor = actorByName(actors, 'department_head__hr');
  const employee = await employeeForEmail(observerActor.email);
  const employeeDisplayName = [employee.first_name, employee.last_name]
    .map((part) => part.trim().replace(/\s+/g, ' '))
    .filter(Boolean)
    .join(' ');
  const clockNow = await readClock(request);
  const workDate = manilaDate(clockNow);
  const courseName = `E2E-${randomUUID().slice(0, 8)} Competence Training`;
  const courseDescription = `Run-scoped employee training for ${courseName}`;
  const expectedExpiry = addValidityMonths(workDate, 12);
  const journey = createJourneyContext('HR07-TRAINING-COMPLETE');
  journey.values.employeeNo = employee.employee_no;
  journey.values.employeeName = employeeDisplayName;
  journey.values.courseName = courseName;
  journey.values.scheduledFor = workDate;
  journey.values.completedAt = workDate;
  journey.values.expiresAt = expectedExpiry;

  const hr = await actorPage(browser, hrActor.actor, { timezoneId: 'Asia/Manila' });
  const observer = await actorPage(browser, observerActor.actor, { timezoneId: 'Asia/Manila' });
  const actorEmails = [hrActor.email, observerActor.email];
  const mailIdsBefore = await mailIdsForActors(actorEmails);
  let primaryError: unknown;

  try {
    await hr.page.clock.setFixedTime(new Date(clockNow));
    await observer.page.clock.setFixedTime(new Date(clockNow));

    await runProcessStep({
      ...plannedSteps[0],
      page: hr.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => {
        // actorPage creates an authenticated context with no initial document.
        // Land at the application's post-login destination before navigating
        // to Training Programs through the sidebar.
        await hr.page.goto('/dashboard');
        await expect(hr.page.getByRole('navigation', { name: 'Modules' })).toBeVisible();
        await openSidebarItem(hr, 'Human Resources', '/hr/trainings', 'Training Programs');
        await expect(hr.page.getByRole('heading', { name: 'Trainings', exact: true })).toBeVisible();
        await hr.page.getByRole('button', { name: 'Add Training', exact: true }).click();
        await expect(hr.page.getByRole('heading', { name: 'Add training', exact: true })).toBeVisible();
        await observer.page.goto('/dashboard');
        await expect(observer.page.getByRole('navigation', { name: 'Modules' })).toBeVisible();
      },
      verify: async () => {
        await hr.page.getByLabel(/^Name\s*\*?$/).fill(courseName);
        await hr.page.getByLabel('Description', { exact: true }).fill(courseDescription);
        await hr.page.getByLabel('Duration (hours)', { exact: true }).fill('2');
        await hr.page.getByLabel('Validity (months)', { exact: true }).fill('12');
        const responsePromise = hr.page.waitForResponse((response) =>
          new URL(response.url()).pathname === '/api/v1/hr/trainings'
          && response.request().method() === 'POST');
        await hr.page.getByRole('button', { name: 'Create training', exact: true }).click();
        const response = await responsePromise;
        expect(response.status(), 'course master data should be created by the HR UI').toBe(201);
        await expect(hr.page.getByRole('heading', { name: 'Trainings', exact: true })).toBeVisible();
        await expect(hr.page.getByRole('row').filter({ hasText: courseName })).toHaveCount(1);
      },
      assertInvariants: async () => {
        const courses = await courseForName(courseName);
        expect(courses).toHaveLength(1);
        expect(courses[0]).toMatchObject({ name: courseName, is_active: true, validity_months: 12 });
        expect(courses[0].audit_entries).toEqual([{ action: 'created', actor_email: hrActor.email }]);
        expect(await employeeTrainingFor(employee.employee_no, courseName)).toHaveLength(0);
        expect(await mailIdsForActors(actorEmails)).toEqual(mailIdsBefore);
        hr.diagnostics.assertClean('HR creates the active training course through the catalog UI');
        observer.diagnostics.assertClean('Department Head opens the dashboard before the hand-off');
      },
    });

    await runProcessStep({
      ...plannedSteps[1],
      page: hr.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => {
        await openSidebarItem(hr, 'Human Resources', '/hr/employees', 'Employees');
        await expect(hr.page.getByRole('heading', { name: 'Employees', exact: true })).toBeVisible();
        const employeeSearch = hr.page.getByPlaceholder('Search name or employee no…');
        await employeeSearch.fill(employee.employee_no);
        const employeeRow = hr.page.getByRole('row').filter({ hasText: employee.employee_no });
        await expect(employeeRow, 'HR should find the in-department Employee through the list').toHaveCount(1);
        await employeeRow.click();
        await expect(hr.page.getByRole('heading', { name: `${employeeDisplayName} Active`, exact: true }))
          .toBeVisible();
        await hr.page.getByRole('tab', { name: 'Trainings', exact: true }).click();
        await expect(hr.page.getByRole('heading', { name: 'Trainings (0)', exact: true })).toBeVisible();
        await hr.page.getByRole('button', { name: 'Assign', exact: true }).click();
        const assignmentDialog = hr.page.getByRole('dialog', { name: 'Assign training' });
        await expect(assignmentDialog).toBeVisible();
        await hr.page.getByLabel('Training', { exact: true }).selectOption({ label: courseName });
        await hr.page.getByLabel('Scheduled for', { exact: true }).fill(workDate);
        const responsePromise = hr.page.waitForResponse((response) =>
          new URL(response.url()).pathname.endsWith('/trainings')
          && response.request().method() === 'POST');
        await assignmentDialog.getByRole('button', { name: 'Assign', exact: true }).click();
        const response = await responsePromise;
        expect(response.status(), 'the employee training assignment should be created by HR through the UI').toBe(201);
        const body = await response.json() as { data?: { id?: string } };
        if (!body.data?.id) throw new Error('The UI-created training assignment response had no public id.');
        journey.values.employeeTrainingPublicId = body.data.id;
      },
      verify: async () => {
        const assignedRow = hr.page.getByRole('row').filter({ hasText: courseName });
        await expect(assignedRow).toHaveCount(1);
        await expect(assignedRow).toContainText('Scheduled');
        await expect(hr.page.getByRole('button', { name: `Mark ${courseName} complete`, exact: true })).toBeVisible();
        const upcomingSignal = observer.page.getByRole('button', { name: /View all Employee Training Records signals:.*Trainings upcoming or expiring soon: 1/ });
        await expect(upcomingSignal, 'the employee-scoped upcoming badge should update for the assigned record').toBeVisible();
      },
      assertInvariants: async () => {
        const rows = await employeeTrainingFor(employee.employee_no, courseName);
        expect(rows).toHaveLength(1);
        expect(rows[0]).toMatchObject({
          employee_no: employee.employee_no,
          course_name: courseName,
          status: 'scheduled',
          scheduled_for: workDate,
          completed_at: null,
          expires_at: null,
          created_by_email: hrActor.email,
          notification_count: 0,
        });
        expect(rows[0].audit_entries).toEqual([{ action: 'created', actor_email: hrActor.email, status: 'scheduled' }]);
        expect(await mailIdsForActors(actorEmails)).toEqual(mailIdsBefore);
        hr.diagnostics.assertClean('HR assigns a course to the department-scoped Employee');
        observer.diagnostics.assertClean('the Department Head receives the upcoming-training badge');
      },
    });

    await runProcessStep({
      ...plannedSteps[2],
      page: observer.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => {
        const signalButton = observer.page.getByRole('button', { name: /View all Employee Training Records signals:/ });
        await signalButton.click();
        const signalMenu = observer.page.getByRole('dialog', { name: 'Employee Training Records signals' });
        const upcomingLink = signalMenu.getByRole('link', { name: /Trainings upcoming or expiring soon: 1/ });
        await expect(upcomingLink).toBeVisible();
        await upcomingLink.click();
        await expect(observer.page).toHaveURL(/\/hr\/employee-trainings\?signal=upcoming/);
        await expect(observer.page.getByRole('heading', { name: 'Employee Training Records', exact: true })).toBeVisible();
        const trainingRow = observer.page.getByRole('row').filter({ hasText: courseName });
        await expect(trainingRow, 'the badge destination should list the newly assigned course').toHaveCount(1);
        await trainingRow.click();
        await expect(observer.page).toHaveURL(/\/hr\/employee-trainings\//);
      },
      verify: async () => {
        await expect(observer.page.getByRole('heading', { name: courseName, exact: true })).toBeVisible();
        await expect(observer.page.getByText('Scheduled', { exact: true })).toBeVisible();
        await expect(observer.page.getByText(workDate, { exact: true })).toBeVisible();
        await expect(observer.page.getByRole('button', { name: /complete/i })).toHaveCount(0);
        await expect(observer.page.getByRole('button', { name: /^Cancel$/ })).toHaveCount(0);
      },
      assertInvariants: async () => {
        const rows = await employeeTrainingFor(employee.employee_no, courseName);
        expect(rows).toHaveLength(1);
        expect(rows[0].status).toBe('scheduled');
        expect(await mailIdsForActors(actorEmails)).toEqual(mailIdsBefore);
        observer.diagnostics.assertClean('Department Head finds and opens the work from the upcoming badge');
        hr.diagnostics.assertClean('HR retains the scheduled training assignment');
      },
    });

    await runProcessStep({
      ...plannedSteps[3],
      page: observer.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => {
        const row = hr.page.getByRole('row').filter({ hasText: courseName });
        await expect(row).toContainText('Scheduled');
        await hr.page.getByRole('button', { name: `Mark ${courseName} complete`, exact: true }).click();
        await expect(hr.page.getByRole('dialog', { name: `Complete ${courseName}` })).toBeVisible();
        await hr.page.getByRole('button', { name: 'Complete training', exact: true }).click();
        await expect(row).toContainText('Completed');
      },
      verify: async () => {
        await expect(observer.page.getByText('Completed', { exact: true }), 'the open Department Head detail should update without reload').toBeVisible();
        await expect(observer.page.getByText(workDate, { exact: true })).toBeVisible();
        await expect(observer.page.getByText(expectedExpiry, { exact: true })).toBeVisible();
        await expect(observer.page.getByRole('button', { name: /View all Employee Training Records signals:/ })).toHaveCount(0);
        await expect(hr.page.getByRole('button', { name: `Mark ${courseName} complete`, exact: true })).toHaveCount(0);
      },
      assertInvariants: async () => {
        const rows = await employeeTrainingFor(employee.employee_no, courseName);
        expect(rows).toHaveLength(1);
        expect(rows[0]).toMatchObject({
          employee_no: employee.employee_no,
          course_name: courseName,
          status: 'completed',
          scheduled_for: workDate,
          completed_at: workDate,
          expires_at: expectedExpiry,
          created_by_email: hrActor.email,
          notification_count: 0,
        });
        expect(rows[0].audit_entries).toEqual([
          { action: 'created', actor_email: hrActor.email, status: 'scheduled' },
          { action: 'updated', actor_email: hrActor.email, status: 'completed' },
        ]);
        expect(await mailIdsForActors(actorEmails)).toEqual(mailIdsBefore);
        hr.diagnostics.assertClean('HR records completion and retains the final training state');
        observer.diagnostics.assertClean('Department Head observes completion in the already-open detail');
      },
    });
  } catch (error) {
    primaryError = error;
    throw error;
  } finally {
    const cleanupErrors: string[] = [];
    for (const session of [hr, observer]) {
      try {
        await session.context.close();
      } catch (error) {
        cleanupErrors.push(error instanceof Error ? error.message : String(error));
      }
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
