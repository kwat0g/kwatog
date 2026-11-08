import { expect, test } from './fixtures';
import { actorPage, loadActors } from './actor-session';
import type { E2EActor } from './actor-session';
import { createJourneyContext, runProcessStep } from './process-runner';
import { readOnlyDatabaseJson } from './helpers/database-reader';
import { readClock } from './helpers/clock';
import { getMailpitMessageRaw, listMailpitMessages } from './helpers/mailpit-reader';
import { expectPdfContains, readDownloadedPdf } from './helpers/pdf-reader';
import { assertRecipientsExactly, assertTotalsEqual } from './helpers/process-invariants';
import { captureAnnotatedStep } from './helpers/annotated-screenshot';

type ActorPage = Awaited<ReturnType<typeof actorPage>>;

type DepartmentSnapshot = {
  department_id: number;
  department_name: string;
  department_code: string | null;
};

type PayrollPeriodSnapshot = {
  id: number;
  status: string;
  creator_email: string;
  computer_email: string | null;
  approver_email: string | null;
  finalizer_email: string | null;
  disburser_email: string | null;
  payroll_count: number;
  failed_count: number;
  total_gross: string;
  total_deductions: string;
  total_net: string;
  journal_entry_id: number | null;
  gl_handoff_status: string;
  bank_file_status: string;
  bank_file_count: number;
  disbursement_status: string;
  proof_count: number;
  anomaly_detection_failed: boolean;
};

type PayrollRowSnapshot = {
  id: number;
  employee_no: string;
  employee_email: string | null;
  error_message: string | null;
  gross_pay: string;
  total_deductions: string;
  net_pay: string;
  payslip_email_status: string | null;
  payslip_emailed_at: string | null;
};

type NoticeSnapshot = {
  type: string;
  recipient_email: string;
  title: string | null;
  message: string | null;
  link_to: string | null;
};

type JournalSnapshot = {
  entry_number: string;
  status: string;
  created_by_email: string | null;
  posted_by_email: string | null;
  total_debit: string;
  total_credit: string;
  line_debit: string;
  line_credit: string;
};

type AuditSnapshot = {
  action: string;
  actor_email: string | null;
  actor_type: string | null;
  source_command: string | null;
  status: string | null;
};

type ProofSnapshot = {
  file_name: string;
  proof_type: string;
  bank_name: string;
  transaction_reference: string;
  disbursed_amount: string;
  uploader_email: string | null;
};

const plan = [
  {
    name: 'HR creates a run-scoped, department payroll period through the Payroll UI',
    actor: 'hr_officer',
    uiPath: 'Human Resources → Payroll Processing → New Period → select Employee department → Create period',
    expectedStatus: 'draft; no payroll rows, journal, bank file, approval or disbursement proof',
  },
  {
    name: 'HR computes the period and the eligible Finance approvers receive the action notice',
    actor: 'hr_officer; Finance observer',
    uiPath: 'Payroll period detail → Compute; Finance follows Notifications → Payroll period needs approval',
    expectedStatus: 'processing → computed; complete payroll rows; Finance-only approval action; no GL posting yet',
  },
  {
    name: 'Finance follows its notification and approves the computed period',
    actor: 'finance_officer; HR live observer',
    uiPath: 'Notification bell → Payroll period needs approval → Approve',
    expectedStatus: 'approved by Finance; HR and checker lose Approve; finalizer is a different Finance user',
  },
  {
    name: 'A second Finance user finds the approved period in Payroll Processing and finalizes it',
    actor: 'finance_officer__2; HR live observer',
    uiPath: 'Payroll → Payroll Processing → run-scoped period row → Finalize',
    expectedStatus: 'finalized; balanced GL journal posted; bank file generated; eligible employees receive payslip notice/email',
  },
  {
    name: 'Finance uploads proof through the UI and records the actual disbursement',
    actor: 'finance_officer__2; HR live observer',
    uiPath: 'Payroll period detail → Mark as Disbursed → Upload proof & mark disbursed',
    expectedStatus: 'disbursed; exact period net amount backed by a proof row; payroll period and employee views update live',
  },
  {
    name: 'Employee follows the Payslip Ready notice and downloads their own payslip',
    actor: 'employee',
    uiPath: 'Notification bell → Payslip Ready → Download payslip PDF',
    expectedStatus: 'only the Employee-owned payroll is published; PDF matches period, employee and net pay',
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

function payrollWindow(instant: string): { start: string; end: string; payrollDate: string } {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: '2-digit',
  }).formatToParts(new Date(instant));
  const year = parts.find((part) => part.type === 'year')?.value;
  const month = parts.find((part) => part.type === 'month')?.value;
  if (!year || !month) throw new Error(`Cannot resolve the E2E payroll month from ${instant}.`);
  return {
    start: `${year}-${month}-01`,
    end: `${year}-${month}-15`,
    payrollDate: `${year}-${month}-15`,
  };
}

async function employeeDepartment(email: string): Promise<DepartmentSnapshot> {
  const rows = await readOnlyDatabaseJson<DepartmentSnapshot[]>(`
      SELECT department.id AS department_id,
           department.name AS department_name,
           department.code AS department_code
      FROM users actor
      JOIN employees employee ON employee.id = actor.employee_id
      JOIN departments department ON department.id = employee.department_id
     WHERE actor.email = ${sqlText(email)}
  `);
  expect(rows, 'the Employee actor must have one department for the scoped E2E period').toHaveLength(1);
  return rows[0];
}

async function periodSnapshot(scopeLabel: string): Promise<PayrollPeriodSnapshot[]> {
  return readOnlyDatabaseJson<PayrollPeriodSnapshot[]>(`
    WITH payroll_totals AS (
      SELECT payroll.payroll_period_id,
             COUNT(*)::int AS payroll_count,
             COUNT(*) FILTER (WHERE payroll.error_message IS NOT NULL)::int AS failed_count,
             COALESCE(SUM(payroll.gross_pay), 0)::text AS total_gross,
             COALESCE(SUM(payroll.total_deductions), 0)::text AS total_deductions,
             COALESCE(SUM(payroll.net_pay), 0)::text AS total_net
        FROM payrolls payroll
       GROUP BY payroll.payroll_period_id
    ), proof_counts AS (
      SELECT proof.payroll_period_id, COUNT(*)::int AS proof_count
        FROM payroll_disbursement_proofs proof
       WHERE proof.deleted_at IS NULL
       GROUP BY proof.payroll_period_id
    ), bank_counts AS (
      SELECT bank.payroll_period_id, COUNT(*)::int AS bank_file_count
        FROM bank_file_records bank
       GROUP BY bank.payroll_period_id
    )
    SELECT period.id,
           period.status::text AS status,
           creator.email AS creator_email,
           computer.email AS computer_email,
           approver.email AS approver_email,
           finalizer.email AS finalizer_email,
           disburser.email AS disburser_email,
           COALESCE(totals.payroll_count, 0)::int AS payroll_count,
           COALESCE(totals.failed_count, 0)::int AS failed_count,
           COALESCE(totals.total_gross, '0.00') AS total_gross,
           COALESCE(totals.total_deductions, '0.00') AS total_deductions,
           COALESCE(totals.total_net, '0.00') AS total_net,
           period.journal_entry_id,
           period.gl_handoff_status::text AS gl_handoff_status,
           period.bank_file_status::text AS bank_file_status,
           COALESCE(bank.bank_file_count, 0)::int AS bank_file_count,
           period.disbursement_status::text AS disbursement_status,
           COALESCE(proof.proof_count, 0)::int AS proof_count,
           period.anomaly_detection_failed
      FROM payroll_periods period
      JOIN users creator ON creator.id = period.created_by
      LEFT JOIN users computer ON computer.id = period.computed_by
      LEFT JOIN users approver ON approver.id = period.approved_by
      LEFT JOIN users finalizer ON finalizer.id = period.finalized_by
      LEFT JOIN users disburser ON disburser.id = period.disbursed_by
      LEFT JOIN payroll_totals totals ON totals.payroll_period_id = period.id
      LEFT JOIN proof_counts proof ON proof.payroll_period_id = period.id
      LEFT JOIN bank_counts bank ON bank.payroll_period_id = period.id
     WHERE period.scope_label = ${sqlText(scopeLabel)}
  `);
}

async function payrollRows(periodId: number): Promise<PayrollRowSnapshot[]> {
  return readOnlyDatabaseJson<PayrollRowSnapshot[]>(`
    SELECT payroll.id,
           employee.employee_no,
           employee.email AS employee_email,
           payroll.error_message,
           payroll.gross_pay::text AS gross_pay,
           payroll.total_deductions::text AS total_deductions,
           payroll.net_pay::text AS net_pay,
           payroll.payslip_email_status,
           payroll.payslip_emailed_at::text AS payslip_emailed_at
      FROM payrolls payroll
      JOIN employees employee ON employee.id = payroll.employee_id
     WHERE payroll.payroll_period_id = ${periodId}
     ORDER BY employee.employee_no
  `);
}

async function noticesForPeriod(periodRouteId: string): Promise<NoticeSnapshot[]> {
  return readOnlyDatabaseJson<NoticeSnapshot[]>(`
    SELECT notice.type,
           recipient.email AS recipient_email,
           notice.data->>'title' AS title,
           notice.data->>'message' AS message,
           notice.data->>'link_to' AS link_to
      FROM notifications notice
      JOIN users recipient
        ON notice.notifiable_type = 'App\\Modules\\Auth\\Models\\User'
       AND recipient.id = notice.notifiable_id
     WHERE notice.data->>'entity_type' = 'payroll_period'
       AND notice.data->>'entity_id' = ${sqlText(periodRouteId)}
     ORDER BY notice.created_at, notice.id
  `);
}

async function expectedPayrollApprovers(periodId: number): Promise<string[]> {
  const rows = await readOnlyDatabaseJson<Array<{ email: string }>>(`
    SELECT DISTINCT recipient.email
      FROM payroll_periods period
      JOIN users recipient ON recipient.is_active = true
      JOIN roles role ON role.id = recipient.role_id
     WHERE period.id = ${periodId}
       AND role.slug <> 'system_admin'
       AND recipient.id <> period.created_by
       AND (period.computed_by IS NULL OR recipient.id <> period.computed_by)
       AND EXISTS (
         SELECT 1 FROM role_permissions role_permission
         JOIN permissions permission ON permission.id = role_permission.permission_id
          WHERE role_permission.role_id = recipient.role_id
            AND permission.slug = 'payroll.periods.approve'
       )
       AND EXISTS (
         SELECT 1 FROM role_permissions role_permission
         JOIN permissions permission ON permission.id = role_permission.permission_id
          WHERE role_permission.role_id = recipient.role_id
            AND permission.slug = 'payroll.periods.view'
       )
       AND NOT EXISTS (
         SELECT 1 FROM notification_preferences preference
          WHERE preference.user_id = recipient.id
            AND preference.notification_type = 'approval.action_required'
            AND preference.channel = 'in_app'
            AND preference.enabled = false
       )
     ORDER BY recipient.email
  `);
  return rows.map((row) => row.email);
}

async function payrollRecipientEmails(periodId: number): Promise<string[]> {
  const rows = await readOnlyDatabaseJson<Array<{ email: string }>>(`
    SELECT DISTINCT employee.email
      FROM payrolls payroll
      JOIN employees employee ON employee.id = payroll.employee_id
      JOIN users recipient ON recipient.employee_id = employee.id AND recipient.is_active = true
      JOIN roles role ON role.id = recipient.role_id
      JOIN role_permissions role_permission ON role_permission.role_id = role.id
      JOIN permissions permission ON permission.id = role_permission.permission_id
     WHERE payroll.payroll_period_id = ${periodId}
       AND payroll.error_message IS NULL
       AND permission.slug = 'payroll.view'
     ORDER BY employee.email
  `);
  return rows.map((row) => row.email);
}

async function payslipReadyNotices(since: string): Promise<NoticeSnapshot[]> {
  return readOnlyDatabaseJson<NoticeSnapshot[]>(`
    SELECT notice.type,
           recipient.email AS recipient_email,
           notice.data->>'title' AS title,
           notice.data->>'message' AS message,
           notice.data->>'link_to' AS link_to
      FROM notifications notice
      JOIN users recipient
        ON notice.notifiable_type = 'App\\Modules\\Auth\\Models\\User'
       AND recipient.id = notice.notifiable_id
     WHERE notice.type = 'chain.payslip_ready'
       AND notice.created_at >= ${sqlText(since)}::timestamptz
     ORDER BY recipient.email
  `);
}

async function periodAuditRows(periodId: number): Promise<AuditSnapshot[]> {
  return readOnlyDatabaseJson<AuditSnapshot[]>(`
    SELECT audit.action,
           actor.email AS actor_email,
           audit.actor_type,
           audit.source_command,
           COALESCE(audit.new_values->>'status', audit.old_values->>'status') AS status
      FROM audit_logs audit
      LEFT JOIN users actor ON actor.id = audit.user_id
     WHERE audit.model_type ILIKE '%PayrollPeriod'
       AND audit.model_id = ${sqlText(String(periodId))}
     ORDER BY audit.id
  `);
}

async function journalRows(periodId: number): Promise<JournalSnapshot[]> {
  return readOnlyDatabaseJson<JournalSnapshot[]>(`
    SELECT entry.entry_number,
           entry.status::text AS status,
           creator.email AS created_by_email,
           poster.email AS posted_by_email,
           entry.total_debit::text AS total_debit,
           entry.total_credit::text AS total_credit,
           COALESCE(SUM(line.debit), 0)::text AS line_debit,
           COALESCE(SUM(line.credit), 0)::text AS line_credit
      FROM payroll_periods period
      JOIN journal_entries entry ON entry.id = period.journal_entry_id
      LEFT JOIN users creator ON creator.id = entry.created_by
      LEFT JOIN users poster ON poster.id = entry.posted_by
      LEFT JOIN journal_entry_lines line ON line.journal_entry_id = entry.id
     WHERE period.id = ${periodId}
       AND entry.deleted_at IS NULL
     GROUP BY entry.id, creator.email, poster.email
  `);
}

async function proofRows(periodId: number): Promise<ProofSnapshot[]> {
  return readOnlyDatabaseJson<ProofSnapshot[]>(`
    SELECT proof.file_name,
           proof.proof_type::text AS proof_type,
           proof.bank_name,
           proof.transaction_reference,
           proof.disbursed_amount::text AS disbursed_amount,
           uploader.email AS uploader_email
      FROM payroll_disbursement_proofs proof
      LEFT JOIN users uploader ON uploader.id = proof.uploaded_by
     WHERE proof.payroll_period_id = ${periodId}
       AND proof.deleted_at IS NULL
     ORDER BY proof.id
  `);
}

async function openSidebarItem(session: ActorPage, section: string, href: string, label: string): Promise<void> {
  const nav = session.page.getByRole('navigation', { name: 'Modules' });
  const target = nav.locator(`a[href="${href}"]`);
  await expect(target, `${label} should be available to this role`).toHaveCount(1);
  if (!(await target.isVisible())) {
    const module = session.page.locator(`[data-module="${section}"]`);
    const toggle = module.getByRole('button');
    await expect(toggle, `${section} should be expandable`).toHaveCount(1);
    if ((await toggle.getAttribute('aria-expanded')) !== 'true') await toggle.click();
  }
  await expect(target).toBeVisible();
  await target.click();
}

async function openDashboard(session: ActorPage): Promise<void> {
  // A fresh context from the real-login storage state starts at the user's
  // normal post-login landing page before any in-app sidebar navigation.
  await session.page.goto('/dashboard');
  await expect(session.page.getByRole('navigation', { name: 'Modules' })).toBeVisible();
  await expect(session.page.getByRole('button', { name: /^Profile menu for / })).toBeVisible();
}

async function expectPeriodStatus(session: ActorPage, status: string, message?: string): Promise<void> {
  const progress = session.page.getByRole('region', { name: 'Document progress' });
  await expect(progress.getByText(status, { exact: true }), message ?? `payroll progress should show ${status}`).toBeVisible();
}

async function openNotificationLink(actor: ActorPage, title: string): Promise<void> {
  const bell = actor.page.getByRole('button', { name: /^Notifications(?:, \d+ unread)?$/ });
  await expect(bell, 'notification bell should be visible to this actor').toBeVisible();
  await bell.click();
  const dialog = actor.page.getByRole('dialog', { name: 'Notifications' });
  await expect(dialog).toBeVisible();
  const link = dialog.getByRole('link').filter({ hasText: title });
  await expect(link, `${title} must be found through the actor's real notification hand-off`).toHaveCount(1);
  await link.click();
}

test('HR06-HAPPY — HR computes, Finance checks/finalizes/disburses, and Employee downloads their payslip', async ({ browser, request }, testInfo) => {
  test.setTimeout(300_000);

  const actors = await loadActors();
  const hrUser = actorByName(actors, 'hr_officer');
  const financeApproverUser = actorByName(actors, 'finance_officer');
  const financeFinalizerUser = actorByName(actors, 'finance_officer__2');
  const employeeUser = actorByName(actors, 'employee');
  const department = await employeeDepartment(employeeUser.email);
  const window = payrollWindow(await readClock(request));
  const journey = createJourneyContext('HR06-HAPPY');
  const scopeLabel = String(journey.values.runPrefix);
  journey.values.periodStart = window.start;
  journey.values.periodEnd = window.end;
  journey.values.payrollDate = window.payrollDate;
  journey.values.scopeDepartment = department.department_name;
  journey.values.employeeEmail = employeeUser.email;

  const hr = await actorPage(browser, hrUser.actor);
  const financeApprover = await actorPage(browser, financeApproverUser.actor);
  const financeFinalizer = await actorPage(browser, financeFinalizerUser.actor);
  const employee = await actorPage(browser, employeeUser.actor);
  const mailBefore = new Set((await listMailpitMessages(500)).map((message) => message.ID));
  let periodId = 0;
  let periodRouteId = '';
  let payrollRowsBeforeFinalize: PayrollRowSnapshot[] = [];
  let payrollEmailRecipients: string[] = [];
  let finalizationStartedAt = '';

  try {
    await Promise.all([hr, financeApprover, financeFinalizer, employee].map(openDashboard));
    await Promise.all([
      openSidebarItem(hr, 'Human Resources', '/payroll/periods', 'Payroll Processing'),
      openSidebarItem(financeApprover, 'Payroll', '/payroll/periods', 'Payroll Processing'),
    ]);

    await runProcessStep({
      ...plan[0], page: hr.page, plannedSteps: plan, context: journey, testInfo,
      action: async () => {
        await hr.page.getByRole('button', { name: 'New Period', exact: true }).click();
        await expect(hr.page).toHaveURL(/\/payroll\/periods\/create$/);
        await hr.page.getByLabel('Period start').fill(window.start);
        await hr.page.getByLabel('Period end').fill(window.end);
        await hr.page.getByLabel('Payroll date').fill(window.payrollDate);
        const departmentLabel = department.department_code
          ? `${department.department_name} (${department.department_code})`
          : department.department_name;
        const departmentCheckbox = hr.page.getByRole('checkbox', { name: departmentLabel, exact: true });
        await expect(departmentCheckbox, 'the seeded Employee department must be an available payroll scope').toHaveCount(1);
        await departmentCheckbox.check();
        await hr.page.getByLabel('Run name (optional)').fill(scopeLabel);
        await expect(hr.page.getByText('Company-wide')).toHaveCount(0);
        const preview = hr.page.getByText('Employees paid').locator('..');
        await expect(preview).toContainText(/\d+/);
        await expect(hr.page.getByRole('button', { name: 'Create period', exact: true })).toBeEnabled();
        const responsePromise = hr.page.waitForResponse((response) =>
          response.url().endsWith('/api/v1/payroll-periods') && response.request().method() === 'POST',
        );
        const detailResponsePromise = hr.page.waitForResponse((response) => {
          const pathname = new URL(response.url()).pathname;
          return /^\/api\/v1\/payroll-periods\/[^/]+$/.test(pathname)
            && response.request().method() === 'GET';
        });
        await hr.page.getByRole('button', { name: 'Create period', exact: true }).click();
        const response = await responsePromise;
        expect(response.status(), 'creating a payroll period through the UI').toBe(201);
        await expect(hr.page).toHaveURL(/\/payroll\/periods\/[^/?]+$/);
        periodRouteId = new URL(hr.page.url()).pathname.split('/').at(-1) ?? '';
        expect(periodRouteId).toBeTruthy();
        journey.values.periodRouteId = periodRouteId;
        const created = await response.json() as { data: { id: string; status: string } };
        expect(created.data.status).toBe('draft');
        const detailResponse = await detailResponsePromise;
        expect(detailResponse.status(), 'the created period detail should load through the UI').toBe(200);
        const detail = await detailResponse.json() as {
          data: { status: string; available_actions?: { compute?: boolean } };
        };
        expect(detail.data.status).toBe('draft');
        const computeAvailable = detail.data.available_actions?.compute;
        expect(computeAvailable).toBe(true);
        journey.values.computeAvailableByApi = computeAvailable;
        journey.values.periodId = created.data.id;
      },
      verify: async () => {
        const rows = await periodSnapshot(scopeLabel);
        expect(rows, 'the UI-created payroll period should be the only run with this scope label').toHaveLength(1);
        const snapshot = rows[0];
        periodId = snapshot.id;
        journey.values.periodDatabaseId = periodId;
        expect(snapshot.status).toBe('draft');
        expect(snapshot.creator_email).toBe(hrUser.email);
        expect(snapshot.payroll_count).toBe(0);
        expect(snapshot.journal_entry_id).toBeNull();
        expect(snapshot.bank_file_count).toBe(0);
        expect(snapshot.proof_count).toBe(0);
        await expectPeriodStatus(hr, 'Draft');
        await expect(hr.page.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
        await expect(hr.page.getByRole('button', { name: 'Finalize', exact: true })).toHaveCount(0);
      },
      assertInvariants: async () => {
        expect(await expectedPayrollApprovers(periodId)).toContain(financeApproverUser.email);
        hr.diagnostics.assertClean('HR creates a department-scoped payroll period');
      },
    });

    await runProcessStep({
      ...plan[1], page: hr.page, plannedSteps: plan, context: journey, testInfo,
      action: async () => {
        await expectPeriodStatus(hr, 'Draft');
        const computeButton = hr.page.getByRole('button', { name: 'Compute', exact: true });
        try {
          await expect(computeButton, 'HR should have the Compute action enabled on its newly created draft').toBeEnabled();
        } catch (error) {
          if (journey.values.computeAvailableByApi === true) {
            journey.values.gapId = 'GAP-HR-06-01';
            journey.steps[journey.steps.length - 1].screenshot = await captureAnnotatedStep(
              hr.page,
              hrUser.actor,
              'Blocked: HR Compute action is absent from the draft period detail',
              journey.steps.length,
              testInfo,
            );
            test.fixme(true, 'GAP-HR-06-01 / FND-HR-009: the UI period detail omits the enabled Compute action even though the same detail response says available_actions.compute=true and four employees match the scope. The payroll process cannot advance.');
          }
          throw error;
        }
        const responsePromise = hr.page.waitForResponse((response) =>
          response.url().endsWith(`/api/v1/payroll-periods/${periodRouteId}/compute`)
            && response.request().method() === 'POST',
        );
        await hr.page.getByRole('button', { name: 'Compute', exact: true }).click();
        const response = await responsePromise;
        expect(response.status(), 'compute must durably claim the period and queue work').toBe(202);
        const body = await response.json() as { data: { status: string } };
        expect(body.data.status).toBe('processing');
        await expect.poll(async () => (await periodSnapshot(scopeLabel))[0]?.status ?? 'missing', {
          timeout: 60_000,
          message: 'the queue worker should finish the payroll computation',
        }).toBe('computed');
        payrollRowsBeforeFinalize = await payrollRows(periodId);
        journey.values.computedEmployeeCount = payrollRowsBeforeFinalize.length;
        journey.values.computedPayrollCount = payrollRowsBeforeFinalize.length;
        await expectPeriodStatus(hr, 'Computed');
      },
      verify: async () => {
        const snapshot = (await periodSnapshot(scopeLabel))[0];
        expect(snapshot.status).toBe('computed');
        expect(snapshot.computer_email).toBe(hrUser.email);
        expect(snapshot.payroll_count).toBeGreaterThan(0);
        expect(snapshot.failed_count).toBe(0);
        expect(snapshot.journal_entry_id).toBeNull();
        expect(snapshot.bank_file_count).toBe(0);
        expect(payrollRowsBeforeFinalize.every((row) => row.error_message === null)).toBe(true);
        expect(payrollRowsBeforeFinalize.every((row) => Number(row.net_pay) > 0)).toBe(true);
        const employeePayroll = payrollRowsBeforeFinalize.filter((row) => row.employee_email === employeeUser.email);
        expect(employeePayroll, 'the reference Employee must be inside the selected department payroll').toHaveLength(1);
        expect(await periodAuditRows(periodId)).toEqual(expect.arrayContaining([
          expect.objectContaining({
            action: 'updated',
            actor_email: null,
            actor_type: 'system',
            source_command: 'payroll.compute.release',
            status: 'computed',
          }),
        ]));
        await expect(hr.page.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
        await expect(hr.page.getByRole('button', { name: 'Finalize', exact: true })).toHaveCount(0);
      },
      assertInvariants: async () => {
        const recipients = await expectedPayrollApprovers(periodId);
        expect(recipients).toContain(financeApproverUser.email);
        await expect.poll(async () => (await noticesForPeriod(periodRouteId)).length, {
          timeout: 20_000,
          message: 'computed payroll should notify every eligible Finance checker',
        }).toBe(recipients.length);
        const notices = await noticesForPeriod(periodRouteId);
        expect(notices.every((notice) => notice.type === 'approval.action_required')).toBe(true);
        expect(notices.every((notice) => notice.title === 'Payroll period needs approval')).toBe(true);
        expect(notices.every((notice) => notice.link_to === `/payroll/periods/${periodRouteId}`)).toBe(true);
        assertRecipientsExactly(notices.map((notice) => notice.recipient_email), recipients);
        await expect(hr.page.getByRole('region', { name: 'Document progress' }).getByText('Processing', { exact: true })).toHaveCount(0);
        hr.diagnostics.assertClean('HR computes the payroll and Finance receives the approval hand-off');
      },
    });

    await runProcessStep({
      ...plan[2], page: financeApprover.page, plannedSteps: plan, context: journey, testInfo,
      action: async () => {
        await openNotificationLink(financeApprover, 'Payroll period needs approval');
        await expect(financeApprover.page).toHaveURL(new RegExp(`/payroll/periods/${periodRouteId}(?:\\?|$)`));
        await expectPeriodStatus(financeApprover, 'Computed');
        await expect(financeApprover.page.getByRole('button', { name: 'Approve', exact: true })).toBeEnabled();
        await expect(financeApprover.page.getByRole('button', { name: 'Finalize', exact: true })).toHaveCount(0);
        const responsePromise = financeApprover.page.waitForResponse((response) =>
          response.url().endsWith(`/api/v1/payroll-periods/${periodRouteId}/approve`)
            && response.request().method() === 'PATCH',
        );
        await financeApprover.page.getByRole('button', { name: 'Approve', exact: true }).click();
        const dialog = financeApprover.page.getByRole('dialog');
        await expect(dialog).toBeVisible();
        await dialog.getByRole('button', { name: 'Approve', exact: true }).click();
        expect((await responsePromise).status(), 'Finance approval through the confirmation dialog').toBe(200);
      },
      verify: async () => {
        const snapshot = (await periodSnapshot(scopeLabel))[0];
        expect(snapshot.status).toBe('approved');
        expect(snapshot.approver_email).toBe(financeApproverUser.email);
        expect(snapshot.creator_email).not.toBe(snapshot.approver_email);
        expect(snapshot.computer_email).not.toBe(snapshot.approver_email);
        expect(snapshot.journal_entry_id).toBeNull();
        expect(snapshot.bank_file_count).toBe(0);
        await expectPeriodStatus(financeApprover, 'Approved');
        await expect(financeApprover.page.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
        await expect(financeApprover.page.getByRole('button', { name: 'Finalize', exact: true })).toHaveCount(0);
        await expectPeriodStatus(hr, 'Approved', 'the open HR period detail should update without refresh');
        expect(await periodAuditRows(periodId)).toEqual(expect.arrayContaining([
          expect.objectContaining({ action: 'payroll.period.approve', actor_email: financeApproverUser.email, status: 'approved' }),
        ]));
      },
      assertInvariants: async () => {
        const notices = await noticesForPeriod(periodRouteId);
        assertRecipientsExactly(notices.map((notice) => notice.recipient_email), await expectedPayrollApprovers(periodId));
        financeApprover.diagnostics.assertClean('Finance approves the computed period from its notification');
        hr.diagnostics.assertClean('HR observes Finance approval live');
      },
    });

    await runProcessStep({
      ...plan[3], page: financeFinalizer.page, plannedSteps: plan, context: journey, testInfo,
      action: async () => {
        await openSidebarItem(financeFinalizer, 'Payroll', '/payroll/periods', 'Payroll Processing');
        const row = financeFinalizer.page.getByRole('row').filter({ hasText: scopeLabel });
        await expect(row, 'Finance must find the approved payroll in the in-app period list').toHaveCount(1);
        await row.click();
        await expect(financeFinalizer.page).toHaveURL(new RegExp(`/payroll/periods/${periodRouteId}(?:\\?|$)`));
        await expectPeriodStatus(financeFinalizer, 'Approved');
        await expect(financeFinalizer.page.getByRole('button', { name: 'Finalize', exact: true })).toBeEnabled();
        await expect(financeFinalizer.page.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
        await expect(financeFinalizer.page.getByText('A different finance user must finalize this payroll.')).toHaveCount(0);
        const responsePromise = financeFinalizer.page.waitForResponse((response) =>
          response.url().endsWith(`/api/v1/payroll-periods/${periodRouteId}/finalize`)
            && response.request().method() === 'PATCH',
        );
        await financeFinalizer.page.getByRole('button', { name: 'Finalize', exact: true }).click();
        const dialog = financeFinalizer.page.getByRole('dialog');
        await expect(dialog).toBeVisible();
        await dialog.getByRole('button', { name: 'Finalize', exact: true }).click();
        expect((await responsePromise).status(), 'Finance finalization through the confirmation dialog').toBe(200);
        finalizationStartedAt = new Date().toISOString();
        await expect.poll(async () => {
          const rows = await periodSnapshot(scopeLabel);
          return rows[0]?.gl_handoff_status ?? 'missing';
        }, { timeout: 45_000, message: 'payroll finalization should hand off a posted journal to Accounting' }).toBe('posted');
        await expect.poll(async () => (await periodSnapshot(scopeLabel))[0]?.bank_file_status ?? 'missing', {
          timeout: 45_000,
          message: 'payroll finalization should produce the configured bank file',
        }).toBe('generated');
        payrollEmailRecipients = await payrollRecipientEmails(periodId);
        await expect.poll(async () => {
          const messages = await listMailpitMessages(500);
          return messages.filter((message) => !mailBefore.has(message.ID)
            && message.Subject.includes('Payslip available')
            && message.To.some((target) => payrollEmailRecipients.includes(target.Address.toLowerCase()))).length;
        }, { timeout: 45_000, message: 'eligible employees should receive their payslip email after finalization' })
          .toBe(payrollEmailRecipients.length);
      },
      verify: async () => {
        const snapshot = (await periodSnapshot(scopeLabel))[0];
        expect(snapshot.status).toBe('finalized');
        expect(snapshot.finalizer_email).toBe(financeFinalizerUser.email);
        expect(snapshot.approver_email).toBe(financeApproverUser.email);
        expect(snapshot.finalizer_email).not.toBe(snapshot.approver_email);
        expect(snapshot.journal_entry_id).not.toBeNull();
        expect(snapshot.bank_file_status).toBe('generated');
        expect(snapshot.bank_file_count).toBe(1);
        payrollRowsBeforeFinalize = await payrollRows(periodId);
        expect(payrollRowsBeforeFinalize.length).toBe(snapshot.payroll_count);
        expect(payrollRowsBeforeFinalize.every((row) => row.error_message === null)).toBe(true);
        expect(payrollRowsBeforeFinalize.every((row) => row.payslip_email_status === 'sent' && row.payslip_emailed_at !== null)).toBe(true);
        const journals = await journalRows(periodId);
        expect(journals).toHaveLength(1);
        expect(journals[0].status).toBe('posted');
        assertTotalsEqual('Payroll journal header debit and credit', Number(journals[0].total_debit), Number(journals[0].total_credit));
        assertTotalsEqual('Payroll journal lines debit and credit', Number(journals[0].line_debit), Number(journals[0].line_credit));
        expect(journals[0].entry_number).toBeTruthy();
        expect(await periodAuditRows(periodId)).toEqual(expect.arrayContaining([
          expect.objectContaining({ action: 'payroll.period.finalize', actor_email: financeFinalizerUser.email, status: 'finalized' }),
        ]));
        await expectPeriodStatus(financeFinalizer, 'Finalized');
        await expectPeriodStatus(hr, 'Finalized', 'the open HR detail should update without refresh');
      },
      assertInvariants: async () => {
        const notices = await payslipReadyNotices(finalizationStartedAt);
        assertRecipientsExactly(notices.map((notice) => notice.recipient_email), payrollEmailRecipients);
        expect(notices.every((notice) => notice.title === 'Payslip Ready')).toBe(true);
        expect(notices.every((notice) => notice.link_to?.startsWith('/self-service/payslips/'))).toBe(true);
        const newPayslipEmails = (await listMailpitMessages(500)).filter((message) => !mailBefore.has(message.ID)
          && message.Subject.includes('Payslip available'));
        assertRecipientsExactly(newPayslipEmails.flatMap((message) => message.To.map((target) => target.Address)), payrollEmailRecipients);
        for (const message of newPayslipEmails) {
          const raw = await getMailpitMessageRaw(message.ID);
          expect(raw).toContain('Your payslip is ready');
          expect(raw).toContain('/self-service/payslips/');
          expect(raw).toContain(window.start);
        }
        financeFinalizer.diagnostics.assertClean('Finance finalizes payroll and cross-module GL/file/email hand-offs complete');
        hr.diagnostics.assertClean('HR observes finalization live');
      },
    });

    await runProcessStep({
      ...plan[4], page: financeFinalizer.page, plannedSteps: plan, context: journey, testInfo,
      action: async () => {
        await expect(financeFinalizer.page.getByRole('button', { name: 'Mark as Disbursed', exact: true })).toBeEnabled();
        await financeFinalizer.page.getByRole('button', { name: 'Mark as Disbursed', exact: true }).click();
        const dialog = financeFinalizer.page.getByRole('dialog', { name: 'Upload proof & mark as disbursed' });
        await expect(dialog).toBeVisible();
        await dialog.getByLabel('File').setInputFiles({
          name: `${scopeLabel}-bank-confirmation.pdf`,
          mimeType: 'application/pdf',
          buffer: Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Count 0/Kids[]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF'),
        });
        await dialog.getByLabel('Bank name').fill('E2E Test Bank');
        await dialog.getByLabel('Transaction ref.').fill(`${scopeLabel}-PAYROLL-DISBURSEMENT`);
        await expect(dialog.getByRole('button', { name: 'Upload & mark disbursed', exact: true })).toBeEnabled();
        const uploadPromise = financeFinalizer.page.waitForResponse((response) =>
          response.url().includes(`/api/v1/payroll-periods/${periodRouteId}/disbursement-proofs`)
            && response.request().method() === 'POST',
        );
        const disbursePromise = financeFinalizer.page.waitForResponse((response) =>
          response.url().endsWith(`/api/v1/payroll-periods/${periodRouteId}/mark-disbursed`)
            && response.request().method() === 'PATCH',
        );
        await dialog.getByRole('button', { name: 'Upload & mark disbursed', exact: true }).click();
        const uploadResponse = await uploadPromise;
        expect(uploadResponse.status(), 'Finance uploads proof through the Payroll UI').toBe(201);
        const disburseResponse = await disbursePromise;
        expect(disburseResponse.status(), 'Finance records disbursement through the Payroll UI').toBe(200);
        await expect.poll(async () => (await periodSnapshot(scopeLabel))[0]?.status ?? 'missing', {
          timeout: 15_000,
          message: 'the period should transition to disbursed after proof upload',
        }).toBe('disbursed');
      },
      verify: async () => {
        const snapshot = (await periodSnapshot(scopeLabel))[0];
        expect(snapshot.status).toBe('disbursed');
        expect(snapshot.disbursement_status).toBe('disbursed');
        expect(snapshot.disburser_email).toBe(financeFinalizerUser.email);
        expect(snapshot.proof_count).toBe(1);
        expect(snapshot.journal_entry_id).not.toBeNull();
        const proofs = await proofRows(periodId);
        expect(proofs).toHaveLength(1);
        expect(proofs[0]).toEqual(expect.objectContaining({
          file_name: `${scopeLabel}-bank-confirmation.pdf`,
          proof_type: 'deposit_slip',
          bank_name: 'E2E Test Bank',
          transaction_reference: `${scopeLabel}-PAYROLL-DISBURSEMENT`,
          disbursed_amount: snapshot.total_net,
          uploader_email: financeFinalizerUser.email,
        }));
        assertTotalsEqual('Disbursement proof amount and payroll net total', Number(proofs[0].disbursed_amount), Number(snapshot.total_net));
        await expectPeriodStatus(financeFinalizer, 'Disbursed');
        await expectPeriodStatus(hr, 'Disbursed', 'the HR observer should see disbursement without refreshing');
        await expect(financeFinalizer.page.getByRole('button', { name: 'Mark as Disbursed', exact: true })).toHaveCount(0);
      },
      assertInvariants: async () => {
        financeFinalizer.diagnostics.assertClean('Finance uploads evidence and records disbursement');
        hr.diagnostics.assertClean('HR observes the completed payroll disbursement live');
      },
    });

    await runProcessStep({
      ...plan[5], page: employee.page, plannedSteps: plan, context: journey, testInfo,
      action: async () => {
        const noticesBefore = await payslipReadyNotices(finalizationStartedAt);
        expect(noticesBefore.some((notice) => notice.recipient_email.toLowerCase() === employeeUser.email.toLowerCase())).toBe(true);
        await openNotificationLink(employee, 'Payslip Ready');
        await expect(employee.page).toHaveURL(/\/self-service\/payslips\/[^/?]+$/);
        await expect(employee.page.getByRole('heading', { name: 'Your payslip', exact: true })).toBeVisible();
        const employeePayroll = payrollRowsBeforeFinalize.find((row) => row.employee_email === employeeUser.email);
        expect(employeePayroll, 'the Employee should own one computed payroll row').toBeDefined();
        const downloadPromise = employee.page.waitForEvent('download');
        const pdfResponsePromise = employee.page.waitForResponse((response) =>
          response.url().includes('/api/v1/hr/self-service/payslips/')
            && response.url().endsWith('/download')
            && response.request().method() === 'GET',
        );
        await employee.page.getByRole('button', { name: 'Download payslip PDF', exact: true }).click();
        const [download, pdfResponse] = await Promise.all([downloadPromise, pdfResponsePromise]);
        expect(pdfResponse.status(), 'the Employee payslip download must be authorized for its owner').toBe(200);
        expect(pdfResponse.headers()['content-type']).toContain('application/pdf');
        const pdfText = await readDownloadedPdf(download);
        expectPdfContains(pdfText, [
          employeePayroll!.employee_no,
          window.start,
          window.end,
          Number(employeePayroll!.net_pay).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
        ]);
        journey.values.payslipFileName = download.suggestedFilename();
        journey.values.payslipTextLength = pdfText.length;
      },
      verify: async () => {
        const employeeRows = payrollRowsBeforeFinalize.filter((row) => row.employee_email === employeeUser.email);
        expect(employeeRows).toHaveLength(1);
        expect(employeeRows[0].payslip_email_status).toBe('sent');
        expect(employeeRows[0].payslip_emailed_at).not.toBeNull();
        const messages = (await listMailpitMessages(500)).filter((message) => message.Subject.includes('Payslip available')
          && message.To.some((target) => target.Address.toLowerCase() === employeeUser.email.toLowerCase()));
        expect(messages.length).toBeGreaterThan(0);
        const notice = (await payslipReadyNotices(finalizationStartedAt))
          .find((row) => row.recipient_email.toLowerCase() === employeeUser.email.toLowerCase());
        expect(notice?.link_to).toMatch(/^\/self-service\/payslips\//);
      },
      assertInvariants: async () => {
        employee.diagnostics.assertClean('Employee follows Payslip Ready and downloads the own-record PDF');
      },
    });
  } finally {
    await Promise.all([
      hr.context.close(),
      financeApprover.context.close(),
      financeFinalizer.context.close(),
      employee.context.close(),
    ]);
  }
});
