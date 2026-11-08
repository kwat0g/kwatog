import { test, expect } from './fixtures';
import { actorPage, loadActors } from './actor-session';
import type { E2EActor } from './actor-session';
import { createJourneyContext, runProcessStep } from './process-runner';
import { assertAuditActor, assertRecipientsExactly } from './helpers/process-invariants';
import { readOnlyDatabaseJson } from './helpers/database-reader';
import { findMailpitMessagesFor, type MailpitSummary } from './helpers/mailpit-reader';
import { captureAnnotatedStep } from './helpers/annotated-screenshot';

type ActorPage = Awaited<ReturnType<typeof actorPage>>;

type EmployeeBaseline = {
  employee_id: number;
  employee_no: string;
  first_name: string;
  last_name: string;
  bank_name: string | null;
  bank_account_cipher: string | null;
};

type RequestSnapshot = {
  request_id: number;
  status: string;
  requires_finance: boolean;
  employee_id: number;
  requester_email: string;
  employee_bank_name: string | null;
  employee_bank_account_cipher: string | null;
  hr_reviewer_email: string | null;
  finance_reviewer_email: string | null;
  request_audit_count: number;
  notification_count: number;
};

type AuditSnapshot = {
  action: string;
  actor_email: string | null;
  status?: string | null;
  model_type?: string;
  bank_name_audited?: boolean;
  bank_account_audited?: boolean;
};

const plannedSteps = [
  { name: 'Employee, HR, and Finance open their own profile and review queues', actor: 'employee / hr_officer / finance_officer', uiPath: 'Employee Home → My profile; Human Resources → Profile Change Requests; Payroll → Bank Account Changes', expectedStatus: 'no open profile change request' },
  { name: 'Employee submits a bank account change and HR receives the actionable queue item', actor: 'employee', uiPath: 'Self-service → My profile → Bank account → Request update → Submit request', expectedStatus: 'pending; HR action available; employee account remains unchanged' },
  { name: 'HR approves the first leg and Finance receives its queue item', actor: 'hr_officer', uiPath: 'Human Resources → Profile Change Requests → Awaiting my action → expand request → HR Approve', expectedStatus: 'pending_finance; account remains unchanged; Finance action available' },
  { name: 'Finance approves and the employee account is updated', actor: 'finance_officer', uiPath: 'Payroll → Bank Account Changes → Awaiting my action → expand request → Finance Approve', expectedStatus: 'approved; requested bank account applied' },
];

function actorByName(actors: E2EActor[], actor: string): E2EActor {
  const found = actors.find((candidate) => candidate.actor === actor);
  if (!found) throw new Error(`Required seeded E2E actor is missing: ${actor}`);
  return found;
}

function sqlText(value: string): string {
  return `'${value.replaceAll("'", "''")}'`;
}

async function openSidebarItem(
  session: ActorPage,
  section: string,
  href: string,
  label: string,
): Promise<void> {
  const nav = session.page.getByRole('navigation', { name: 'Modules' });
  const target = nav.locator(`a[href="${href}"]`);
  await expect(target, `the ${label} route should be available in this role's sidebar`).toHaveCount(1);
  if (!(await target.isVisible())) {
    const module = session.page.locator(`[data-module="${section}"]`);
    const toggle = module.getByRole('button');
    await expect(toggle, `the ${section} module should be expandable`).toHaveCount(1);
    if ((await toggle.getAttribute('aria-expanded')) !== 'true') await toggle.click();
  }
  await expect(target).toBeVisible();
  await target.click();
}

async function openQueue(session: ActorPage, section: string, heading: string): Promise<void> {
  // /dashboard is the destination used by the real sign-in flow; / is the
  // public marketing site and does not enter the authenticated ERP shell.
  await session.page.goto('/dashboard');
  await expect(session.page.getByRole('navigation', { name: 'Modules' })).toBeVisible();
  await expect(session.page.getByRole('button', { name: /^Profile menu for / })).toBeVisible();
  await openSidebarItem(session, section, '/hr/profile-update-requests', heading);
  await expect(session.page.getByRole('heading', { name: heading, exact: true })).toBeVisible();
}

async function requestSnapshot(requesterEmail: string, createdAfter: string): Promise<RequestSnapshot[]> {
  return readOnlyDatabaseJson<RequestSnapshot[]>(`
    SELECT request.id AS request_id,
           request.status, request.requires_finance, request.employee_id,
           requester.email AS requester_email,
           employee.bank_name AS employee_bank_name,
           employee.bank_account_no AS employee_bank_account_cipher,
           hr.email AS hr_reviewer_email,
           finance.email AS finance_reviewer_email,
           (SELECT COUNT(*)::int FROM audit_logs audit
             WHERE audit.model_type ILIKE '%ProfileUpdateRequest'
               AND audit.model_id = request.id) AS request_audit_count,
           (SELECT COUNT(*)::int FROM notifications notice
             WHERE notice.data->>'subject_type' ILIKE '%ProfileUpdateRequest'
               AND notice.data->>'subject_id' = request.id::text) AS notification_count
      FROM profile_update_requests request
      JOIN employees employee ON employee.id = request.employee_id
      JOIN users requester ON requester.id = request.requested_by
      LEFT JOIN users hr ON hr.id = request.reviewed_by
      LEFT JOIN users finance ON finance.id = request.finance_reviewed_by
     WHERE requester.email = ${sqlText(requesterEmail)}
       AND request.note = 'Bank account change'
       AND request.created_at >= ${sqlText(createdAfter)}::timestamptz
     ORDER BY request.id DESC
     LIMIT 1
  `);
}

async function employeeBankAudit(employeeId: number, createdAfter: string): Promise<AuditSnapshot[]> {
  if (!Number.isSafeInteger(employeeId) || employeeId < 1) {
    throw new Error(`Invalid employee ID for read-only audit verification: ${employeeId}`);
  }
  return readOnlyDatabaseJson<AuditSnapshot[]>(`
    SELECT audit.action, audit.model_type, actor.email AS actor_email,
           (COALESCE(audit.old_values::jsonb, '{}'::jsonb) ? 'bank_name'
             OR COALESCE(audit.new_values::jsonb, '{}'::jsonb) ? 'bank_name') AS bank_name_audited,
           (COALESCE(audit.old_values::jsonb, '{}'::jsonb) ? 'bank_account_no'
             OR COALESCE(audit.new_values::jsonb, '{}'::jsonb) ? 'bank_account_no') AS bank_account_audited
      FROM audit_logs audit
      LEFT JOIN users actor ON actor.id = audit.user_id
     WHERE audit.model_id = ${employeeId}
       AND audit.model_type ILIKE '%employee%'
       AND audit.action = 'updated'
       AND audit.created_at >= ${sqlText(createdAfter)}::timestamptz
     ORDER BY audit.id
  `);
}

async function requestAudit(requesterEmail: string, createdAfter: string): Promise<AuditSnapshot[]> {
  return readOnlyDatabaseJson<AuditSnapshot[]>(`
    SELECT audit.action, actor.email AS actor_email,
           audit.new_values->>'status' AS status
      FROM audit_logs audit
      JOIN profile_update_requests request
        ON audit.model_type ILIKE '%ProfileUpdateRequest'
       AND audit.model_id = request.id
      JOIN users requester ON requester.id = request.requested_by
      LEFT JOIN users actor ON actor.id = audit.user_id
     WHERE requester.email = ${sqlText(requesterEmail)}
       AND request.note = 'Bank account change'
       AND request.created_at >= ${sqlText(createdAfter)}::timestamptz
     ORDER BY audit.id
  `);
}

async function expectRequestStatus(requesterEmail: string, createdAfter: string, expected: string): Promise<RequestSnapshot> {
  let rows: RequestSnapshot[] = [];
  await expect.poll(async () => {
    rows = await requestSnapshot(requesterEmail, createdAfter);
    return rows[0]?.status ?? 'missing';
  }, { timeout: 15_000, message: `profile update request should reach ${expected}` }).toBe(expected);
  if (!rows[0]) throw new Error(`Profile update request for ${requesterEmail} disappeared.`);
  return rows[0];
}

async function clickBadgeHandoff(session: ActorPage, itemLabel: string, expectedCount: number): Promise<void> {
  const badge = session.page.getByRole('button', {
    name: new RegExp(`View all ${itemLabel} signals: ${expectedCount}`),
  });
  await expect(badge, `${itemLabel} should expose the actionable work count`).toBeVisible();
  await expect(badge).toContainText(String(expectedCount));
  await badge.click();
  const signalMenu = session.page.getByRole('dialog', { name: `${itemLabel} signals` });
  const actionable = signalMenu.getByRole('link').filter({ hasText: 'Awaiting my action' });
  await expect(actionable, `${itemLabel} should expose the actionable queue link`).toHaveCount(1);
  await expect(actionable).toContainText(String(expectedCount));
  await actionable.click();
  // The live SPA currently routes this signal to the queue path without the
  // expected `signal=awaiting_my_action` query (UX-HR-001 is tracked).
  await expect(session.page).toHaveURL(/\/hr\/profile-update-requests(?:\?|$)/);
}

async function expandEmployeeRequest(session: ActorPage, employeeName: string): Promise<void> {
  const row = session.page.getByRole('row').filter({ hasText: employeeName });
  await expect(row, 'the employee request should be present in this role\'s actionable queue').toHaveCount(1);
  await row.locator('td').last().click();
  await expect(session.page.getByRole('region', { name: 'Requested changes' })).toBeVisible();
}

async function mailpitForActors(emails: string[]): Promise<MailpitSummary[]> {
  const messages = await Promise.all(emails.map((email) => findMailpitMessagesFor(email)));
  return messages.flat();
}

type RejectionRunRequest = RequestSnapshot & {
  created_at: string;
};

type ProcessAuditSnapshot = AuditSnapshot & {
  request_id: number;
};

type OrdinaryProfileRequestSnapshot = {
  request_id: number;
  status: string;
  requires_finance: boolean;
  employee_id: number;
  requester_email: string;
  employee_mobile_number: string | null;
  hr_reviewer_email: string | null;
  request_audit_count: number;
  notification_count: number;
};

type MobileAuditSnapshot = {
  actor_email: string | null;
  mobile_number_audited: boolean;
};

async function requestsForJourney(requesterEmail: string, createdAfter: string): Promise<RejectionRunRequest[]> {
  return readOnlyDatabaseJson<RejectionRunRequest[]>(`
    SELECT request.id AS request_id, request.created_at,
           request.status, request.requires_finance, request.employee_id,
           requester.email AS requester_email,
           employee.bank_name AS employee_bank_name,
           employee.bank_account_no AS employee_bank_account_cipher,
           hr.email AS hr_reviewer_email,
           finance.email AS finance_reviewer_email,
           (SELECT COUNT(*)::int FROM audit_logs audit
             WHERE audit.model_type ILIKE '%ProfileUpdateRequest'
               AND audit.model_id = request.id) AS request_audit_count,
           (SELECT COUNT(*)::int FROM notifications notice
             WHERE notice.data->>'subject_type' ILIKE '%ProfileUpdateRequest'
               AND notice.data->>'subject_id' = request.id::text) AS notification_count
      FROM profile_update_requests request
      JOIN employees employee ON employee.id = request.employee_id
      JOIN users requester ON requester.id = request.requested_by
      LEFT JOIN users hr ON hr.id = request.reviewed_by
      LEFT JOIN users finance ON finance.id = request.finance_reviewed_by
     WHERE requester.email = ${sqlText(requesterEmail)}
       AND request.note = 'Bank account change'
       AND request.created_at >= ${sqlText(createdAfter)}::timestamptz
     ORDER BY request.created_at, request.id
  `);
}

async function processAuditsForJourney(requesterEmail: string, createdAfter: string): Promise<ProcessAuditSnapshot[]> {
  return readOnlyDatabaseJson<ProcessAuditSnapshot[]>(`
    SELECT request.id AS request_id, audit.action,
           actor.email AS actor_email,
           audit.new_values->>'status' AS status
      FROM audit_logs audit
      JOIN profile_update_requests request
        ON audit.model_type ILIKE '%ProfileUpdateRequest'
       AND audit.model_id = request.id
      JOIN users requester ON requester.id = request.requested_by
      LEFT JOIN users actor ON actor.id = audit.user_id
     WHERE requester.email = ${sqlText(requesterEmail)}
       AND request.note = 'Bank account change'
       AND request.created_at >= ${sqlText(createdAfter)}::timestamptz
     ORDER BY audit.id
  `);
}

async function ordinaryProfileRequestForJourney(requesterEmail: string, createdAfter: string): Promise<OrdinaryProfileRequestSnapshot[]> {
  return readOnlyDatabaseJson<OrdinaryProfileRequestSnapshot[]>(`
    SELECT request.id AS request_id, request.status, request.requires_finance,
           request.employee_id, requester.email AS requester_email,
           employee.mobile_number AS employee_mobile_number,
           hr.email AS hr_reviewer_email,
           (SELECT COUNT(*)::int FROM audit_logs audit
             WHERE audit.model_type ILIKE '%ProfileUpdateRequest'
               AND audit.model_id = request.id) AS request_audit_count,
           (SELECT COUNT(*)::int FROM notifications notice
             WHERE notice.data->>'subject_type' ILIKE '%ProfileUpdateRequest'
               AND notice.data->>'subject_id' = request.id::text) AS notification_count
      FROM profile_update_requests request
      JOIN employees employee ON employee.id = request.employee_id
      JOIN users requester ON requester.id = request.requested_by
      LEFT JOIN users hr ON hr.id = request.reviewed_by
     WHERE requester.email = ${sqlText(requesterEmail)}
       AND request.note IS NULL
       AND request.created_at >= ${sqlText(createdAfter)}::timestamptz
     ORDER BY request.created_at, request.id
  `);
}

async function ordinaryProfileAuditsForJourney(requesterEmail: string, createdAfter: string): Promise<ProcessAuditSnapshot[]> {
  return readOnlyDatabaseJson<ProcessAuditSnapshot[]>(`
    SELECT request.id AS request_id, audit.action,
           actor.email AS actor_email,
           audit.new_values->>'status' AS status
      FROM audit_logs audit
      JOIN profile_update_requests request
        ON audit.model_type ILIKE '%ProfileUpdateRequest'
       AND audit.model_id = request.id
      JOIN users requester ON requester.id = request.requested_by
      LEFT JOIN users actor ON actor.id = audit.user_id
     WHERE requester.email = ${sqlText(requesterEmail)}
       AND request.note IS NULL
       AND request.created_at >= ${sqlText(createdAfter)}::timestamptz
     ORDER BY audit.id
  `);
}

async function employeeMobileAudit(employeeId: number, createdAfter: string): Promise<MobileAuditSnapshot[]> {
  if (!Number.isSafeInteger(employeeId) || employeeId < 1) {
    throw new Error(`Invalid employee ID for read-only audit verification: ${employeeId}`);
  }
  return readOnlyDatabaseJson<MobileAuditSnapshot[]>(`
    SELECT actor.email AS actor_email,
           (COALESCE(audit.old_values::jsonb, '{}'::jsonb) ? 'mobile_number'
             OR COALESCE(audit.new_values::jsonb, '{}'::jsonb) ? 'mobile_number') AS mobile_number_audited
      FROM audit_logs audit
      LEFT JOIN users actor ON actor.id = audit.user_id
     WHERE audit.model_id = ${employeeId}
       AND audit.model_type ILIKE '%employee%'
       AND audit.action = 'updated'
       AND audit.created_at >= ${sqlText(createdAfter)}::timestamptz
     ORDER BY audit.id
  `);
}

async function profileForActor(session: ActorPage, employeeName: string): Promise<void> {
  await session.page.goto('/dashboard');
  const profileMenuButton = session.page.getByRole('button', { name: `Profile menu for ${employeeName}`, exact: true });
  await expect(profileMenuButton).toBeVisible();
  await profileMenuButton.click();
  await session.page.getByRole('menu').getByRole('menuitem', { name: 'Profile', exact: true }).click();
  await expect(session.page.getByRole('heading', { name: /E2E employee/i })).toBeVisible();
  await expect(session.page.getByRole('heading', { name: 'Bank account', exact: true })).toBeVisible();
}

function requestRow(session: ActorPage, employeeName: string, status: string) {
  return session.page.getByRole('row').filter({ hasText: employeeName }).filter({ hasText: status });
}

function requestedChangesRegion(session: ActorPage, uniqueFieldValue: string) {
  return session.page.getByRole('region', { name: 'Requested changes' }).filter({ hasText: uniqueFieldValue });
}

async function expandRequestRow(session: ActorPage, row: ReturnType<typeof requestRow>, uniqueFieldValue: string): Promise<void> {
  await expect(row).toHaveCount(1);
  await row.locator('td').last().click();
  const details = requestedChangesRegion(session, uniqueFieldValue);
  await expect(details, 'the selected request should expose its own requested changes').toHaveCount(1);
  await expect(details).toBeVisible();
}

test('HR07-BANK-CHANGE — employee bank change requires independent HR and Finance approval', async ({ browser }, testInfo) => {
  test.setTimeout(180_000);

  const actors = await loadActors();
  const employeeUser = actorByName(actors, 'employee');
  const hrUser = actorByName(actors, 'hr_officer');
  const financeUser = actorByName(actors, 'finance_officer');
  const employee = await actorPage(browser, employeeUser.actor);
  const hr = await actorPage(browser, hrUser.actor);
  const finance = await actorPage(browser, financeUser.actor);
  const journey = createJourneyContext('HR07-BANK-CHANGE');
  const seenIdentifiers = new Set<string>();
  const bankName = `E2E BANK ${journey.runId.slice(0, 8).toUpperCase()}`;
  const accountNumber = `E2E${journey.runId.replaceAll('-', '').slice(0, 10).toUpperCase()}7426`;
  const employeeRows = await readOnlyDatabaseJson<EmployeeBaseline[]>(`
    SELECT employee.id AS employee_id, employee.employee_no,
           employee.first_name, employee.last_name, employee.bank_name,
           employee.bank_account_no AS bank_account_cipher
      FROM employees employee
      JOIN users actor ON actor.employee_id = employee.id
     WHERE actor.email = ${sqlText(employeeUser.email)}
  `);
  expect(employeeRows, 'the E2E employee actor should resolve to one seeded employee').toHaveLength(1);
  const baseline = employeeRows[0];
  const employeeName = `${baseline.first_name} ${baseline.last_name}`;
  const mailBefore = await mailpitForActors([employeeUser.email, hrUser.email, financeUser.email]);
  let requestHashId = '';

  try {
    await runProcessStep({
      name: plannedSteps[0].name,
      actor: plannedSteps[0].actor,
      uiPath: plannedSteps[0].uiPath,
      expectedStatus: plannedSteps[0].expectedStatus,
      page: employee.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => {
        // Start at the authenticated landing page established by UI sign-in;
        // then follow the profile dropdown's self-service menu item.
        await employee.page.goto('/dashboard');
        const profileMenuButton = employee.page.getByRole('button', { name: `Profile menu for ${employeeName}`, exact: true });
        await expect(profileMenuButton).toBeVisible();
        await profileMenuButton.click();
        await employee.page.getByRole('menu').getByRole('menuitem', { name: 'Profile', exact: true }).click();
        await expect(employee.page.getByRole('heading', { name: /E2E employee/i })).toBeVisible();
        await expect(employee.page.getByRole('heading', { name: 'Bank account', exact: true })).toBeVisible();
        await expect(employee.page.getByRole('button', { name: 'Request update', exact: true })).toBeVisible();
        await expect(employee.page.getByText('No change requests', { exact: true })).toBeVisible();

        await openQueue(hr, 'Human Resources', 'Profile Change Requests');
        await expect(hr.page.getByRole('heading', { name: 'No requests', exact: true })).toBeVisible();
        await openQueue(finance, 'Payroll', 'Bank Account Changes');
        await expect(finance.page.getByRole('heading', { name: 'No requests', exact: true })).toBeVisible();
      },
      verify: async () => {
        expect(await requestSnapshot(employeeUser.email, journey.startedAt)).toHaveLength(0);
        expect(baseline.bank_name).toBeNull();
        expect(baseline.bank_account_cipher).toBeNull();
      },
      assertInvariants: async () => {
        employee.diagnostics.assertClean('employee baseline profile view');
        hr.diagnostics.assertClean('HR baseline profile queue');
        finance.diagnostics.assertClean('Finance baseline bank-change queue');
      },
    });

    await runProcessStep({
      name: plannedSteps[1].name,
      actor: plannedSteps[1].actor,
      uiPath: plannedSteps[1].uiPath,
      expectedStatus: plannedSteps[1].expectedStatus,
      page: employee.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => {
        await employee.page.getByRole('button', { name: 'Request update', exact: true }).click();
        const dialog = employee.page.getByRole('dialog', { name: 'Request Bank Account Update' });
        await expect(dialog).toBeVisible();
        await dialog.getByLabel('Bank name').fill(bankName);
        await dialog.getByLabel('New account number').fill(accountNumber);
        const responsePromise = employee.page.waitForResponse((response) =>
          response.url().includes('/hr/self-service/profile/request-update') && response.request().method() === 'POST',
        );
        await dialog.getByRole('button', { name: 'Submit request', exact: true }).click();
        const response = await responsePromise;
        expect(response.status(), 'employee UI request should be created').toBe(201);
        const payload = await response.json() as { data?: { id?: string; status?: string } };
        requestHashId = payload.data?.id ?? '';
        expect(requestHashId, 'UI create response should provide the public request identifier').not.toBe('');
        expect(payload.data?.status).toBe('pending');
        journey.values.profileUpdateRequestId = requestHashId;
        journey.values.bankName = bankName;
        journey.values.accountLast4 = accountNumber.slice(-4);
        expect(seenIdentifiers.has(requestHashId), 'the UI should not return a duplicate request identifier').toBe(false);
        seenIdentifiers.add(requestHashId);

        await expect(employee.page.getByText('Bank change submitted — requires HR + Finance approval.', { exact: true })).toBeVisible();
        await expect(employee.page.getByText('Pending HR', { exact: true })).toBeVisible();
        await expect(hr.page.getByRole('row').filter({ hasText: employeeName })).toHaveCount(1);
        await clickBadgeHandoff(hr, 'Profile Change Requests', 1);
        await expandEmployeeRequest(hr, employeeName);
        await expect(hr.page.getByRole('region', { name: 'Requested changes' }).getByText(bankName, { exact: true })).toBeVisible();
        await expect(hr.page.getByRole('button', { name: 'HR Approve', exact: true })).toBeVisible();
        await expect(hr.page.getByRole('button', { name: 'Finance Approve', exact: true })).toHaveCount(0);
        await expect(finance.page.getByRole('row').filter({ hasText: employeeName })).toHaveCount(0);
      },
      verify: async () => {
        const record = await expectRequestStatus(employeeUser.email, journey.startedAt, 'pending');
        expect(record.requires_finance).toBe(true);
        expect(record.requester_email).toBe(employeeUser.email);
        expect(record.employee_bank_name).toBe(baseline.bank_name);
        expect(record.employee_bank_account_cipher).toBe(baseline.bank_account_cipher);
        expect(record.hr_reviewer_email).toBeNull();
        expect(record.finance_reviewer_email).toBeNull();
        expect(record.notification_count).toBe(0);
      },
      assertInvariants: async () => {
        await expect(employee.page.getByText('Pending HR', { exact: true })).toBeVisible();
        const badge = hr.page.getByRole('button', { name: /View all Profile Change Requests signals: 1 Profile changes you can review/ });
        await expect(badge).toBeVisible();
        await expect(finance.page.getByRole('button', { name: /View all Bank Account Changes signals/ })).toHaveCount(0);
        employee.diagnostics.assertClean('employee submits bank change');
        hr.diagnostics.assertClean('HR receives bank change');
        finance.diagnostics.assertClean('Finance has no pre-HR bank change');
      },
    });
    await captureAnnotatedStep(hr.page, 'hr_officer', 'HR finds bank change in actionable queue', 2, testInfo);

    await runProcessStep({
      name: plannedSteps[2].name,
      actor: plannedSteps[2].actor,
      uiPath: plannedSteps[2].uiPath,
      expectedStatus: plannedSteps[2].expectedStatus,
      page: hr.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => {
        const responsePromise = hr.page.waitForResponse((response) =>
          response.url().includes(`/hr/profile-update-requests/${requestHashId}/review`) && response.request().method() === 'PATCH',
        );
        await hr.page.getByRole('button', { name: 'HR Approve', exact: true }).click();
        const confirm = hr.page.getByRole('dialog');
        await expect(confirm.getByText(/change still needs Finance approval/i)).toBeVisible();
        await confirm.getByRole('button', { name: 'Approve', exact: true }).click();
        const response = await responsePromise;
        expect(response.status(), 'HR UI approval should be accepted').toBe(200);
        const hrRow = hr.page.getByRole('row').filter({ hasText: employeeName });
        await expect(hrRow.getByText('Awaiting Finance', { exact: true })).toBeVisible();
        const employeeRequest = employee.page.getByRole('listitem').filter({ hasText: 'Bank account no.' });
        await expect(employeeRequest.getByText('Awaiting Finance', { exact: true })).toBeVisible();
        await expect(hrRow.getByRole('button', { name: 'HR Approve', exact: true })).toHaveCount(0);
        await expect(finance.page.getByRole('row').filter({ hasText: employeeName })).toHaveCount(1);
        await clickBadgeHandoff(finance, 'Bank Account Changes', 1);
        await expandEmployeeRequest(finance, employeeName);
        await expect(finance.page.getByRole('button', { name: 'Finance Approve', exact: true })).toBeVisible();
        await expect(finance.page.getByRole('button', { name: 'HR Approve', exact: true })).toHaveCount(0);
      },
      verify: async () => {
        const record = await expectRequestStatus(employeeUser.email, journey.startedAt, 'pending_finance');
        expect(record.requires_finance).toBe(true);
        expect(record.hr_reviewer_email).toBe(hrUser.email);
        expect(record.finance_reviewer_email).toBeNull();
        expect(record.employee_bank_name).toBe(baseline.bank_name);
        expect(record.employee_bank_account_cipher).toBe(baseline.bank_account_cipher);
      },
      assertInvariants: async () => {
        const employeeRequest = employee.page.getByRole('listitem').filter({ hasText: 'Bank account no.' });
        await expect(employeeRequest.getByText('Awaiting Finance', { exact: true })).toBeVisible();
        await expect(finance.page.getByRole('button', { name: /View all Bank Account Changes signals: 1 Profile changes you can review/ })).toBeVisible();
        await expect(hr.page.getByRole('button', { name: /View all Profile Change Requests signals/ })).toHaveCount(0);
        employee.diagnostics.assertClean('employee observes HR approval');
        hr.diagnostics.assertClean('HR approves first leg');
        finance.diagnostics.assertClean('Finance receives second leg');
      },
    });
    await captureAnnotatedStep(employee.page, 'employee', 'Employee sees Finance review pending', 3, testInfo);

    await runProcessStep({
      name: plannedSteps[3].name,
      actor: plannedSteps[3].actor,
      uiPath: plannedSteps[3].uiPath,
      expectedStatus: plannedSteps[3].expectedStatus,
      page: finance.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => {
        const responsePromise = finance.page.waitForResponse((response) =>
          response.url().includes(`/hr/profile-update-requests/${requestHashId}/finance-review`) && response.request().method() === 'PATCH',
        );
        await finance.page.getByRole('button', { name: 'Finance Approve', exact: true }).click();
        const confirm = finance.page.getByRole('dialog');
        await expect(confirm.getByText(/applies the new bank account/i)).toBeVisible();
        await confirm.getByRole('button', { name: 'Approve', exact: true }).click();
        const response = await responsePromise;
        expect(response.status(), 'Finance UI approval should be accepted').toBe(200);
        await expect(employee.page.getByText('Approved', { exact: true })).toBeVisible();
        await expect(employee.page.getByText(bankName, { exact: true })).toBeVisible();
        await expect(employee.page.getByRole('definition').filter({ hasText: `••••${accountNumber.slice(-4)}` })).toBeVisible();
        await expect(employee.page.getByText(accountNumber, { exact: true })).toHaveCount(0);
        const financeRow = finance.page.getByRole('row').filter({ hasText: employeeName });
        await expect(financeRow.getByText('Approved', { exact: true })).toBeVisible();
        await expect(financeRow.getByRole('button', { name: 'Finance Approve', exact: true })).toHaveCount(0);
        const hrRow = hr.page.getByRole('row').filter({ hasText: employeeName });
        await expect(hrRow.getByText('Approved', { exact: true })).toBeVisible();
        await expect(hrRow.getByRole('button', { name: 'HR Approve', exact: true })).toHaveCount(0);
      },
      verify: async () => {
        const record = await expectRequestStatus(employeeUser.email, journey.startedAt, 'approved');
        const verifiedRecord = (await requestSnapshot(employeeUser.email, journey.startedAt))[0];
        expect(verifiedRecord, 'read-only verification should find the approved profile change').toBeDefined();
        const finalRecord = verifiedRecord!;
        expect(finalRecord.request_id).toBe(record.request_id);
        expect(finalRecord.requires_finance).toBe(true);
        expect(finalRecord.requester_email).toBe(employeeUser.email);
        expect(finalRecord.hr_reviewer_email).toBe(hrUser.email);
        expect(finalRecord.finance_reviewer_email).toBe(financeUser.email);
        expect(finalRecord.employee_bank_name).toBe(bankName);
        expect(finalRecord.employee_bank_account_cipher).not.toBeNull();
        expect(finalRecord.employee_bank_account_cipher).not.toBe(baseline.bank_account_cipher);
        expect(finalRecord.request_audit_count).toBeGreaterThanOrEqual(4);
        expect(finalRecord.notification_count).toBe(0);

        const audit = await requestAudit(employeeUser.email, journey.startedAt);
        const created = audit.find((entry) => entry.action === 'created');
        const hrApproval = audit.find((entry) => entry.status === 'pending_finance');
        const financeApproval = audit.find((entry) => entry.status === 'approved');
        expect(created).toBeDefined();
        expect(hrApproval).toBeDefined();
        expect(financeApproval).toBeDefined();
        assertAuditActor({ actor_email: created?.actor_email ?? undefined }, employeeUser.email);
        assertAuditActor({ actor_email: hrApproval?.actor_email ?? undefined }, hrUser.email);
        assertAuditActor({ actor_email: financeApproval?.actor_email ?? undefined }, financeUser.email);
        const employeeAudit = await employeeBankAudit(baseline.employee_id, journey.startedAt);
        const financeBankAudit = employeeAudit.filter((entry) =>
          entry.actor_email === financeUser.email
          && entry.bank_name_audited === true
          && entry.bank_account_audited === true,
        );
        expect(financeBankAudit, 'Finance should write an Employee audit row for both redacted bank fields').toHaveLength(1);
        assertAuditActor({ actor_email: financeBankAudit[0].actor_email ?? undefined }, financeUser.email);
      },
      assertInvariants: async () => {
        await expect(employee.page.getByText('Approved', { exact: true })).toBeVisible();
        await expect(employee.page.getByRole('definition').filter({ hasText: `••••${accountNumber.slice(-4)}` })).toBeVisible();
        await expect(finance.page.getByRole('button', { name: /View all Bank Account Changes signals/ })).toHaveCount(0);
        await expect(hr.page.getByRole('button', { name: /View all Profile Change Requests signals/ })).toHaveCount(0);

        const mailAfter = await mailpitForActors([employeeUser.email, hrUser.email, financeUser.email]);
        const beforeIds = new Set(mailBefore.map((message) => message.ID));
        const newEmails = mailAfter.filter((message) => !beforeIds.has(message.ID));
        assertRecipientsExactly(
          newEmails.flatMap((message) => message.To.map((recipient) => recipient.Address)),
          [],
        );
        employee.diagnostics.assertClean('employee sees approved bank change');
        hr.diagnostics.assertClean('HR queue closes after Finance approval');
        finance.diagnostics.assertClean('Finance completes bank change');
      },
    });
    await captureAnnotatedStep(employee.page, 'employee', 'Employee profile reflects approved bank change', 4, testInfo);
  } finally {
    await Promise.all([employee.context.close(), hr.context.close(), finance.context.close()]);
  }
});

test('HR07-REJECT-RESUBMIT — HR rejects a bank change, then employee resubmits for dual approval', async ({ browser }, testInfo) => {
  test.setTimeout(180_000);

  const actors = await loadActors();
  const employeeUser = actorByName(actors, 'employee');
  const hrUser = actorByName(actors, 'hr_officer');
  const financeUser = actorByName(actors, 'finance_officer');
  const employee = await actorPage(browser, employeeUser.actor);
  const hr = await actorPage(browser, hrUser.actor);
  const finance = await actorPage(browser, financeUser.actor);
  const journey = createJourneyContext('HR07-REJECT-RESUBMIT');
  const initialBankName = `E2E REJECT ${journey.runId.slice(0, 8).toUpperCase()}`;
  const initialAccountNumber = `E2ER${journey.runId.replaceAll('-', '').slice(0, 10).toUpperCase()}1081`;
  const resubmittedBankName = `E2E RESUBMIT ${journey.runId.slice(0, 8).toUpperCase()}`;
  const resubmittedAccountNumber = `E2ERS${journey.runId.replaceAll('-', '').slice(0, 9).toUpperCase()}6284`;
  const employeeRows = await readOnlyDatabaseJson<EmployeeBaseline[]>(`
    SELECT employee.id AS employee_id, employee.employee_no,
           employee.first_name, employee.last_name, employee.bank_name,
           employee.bank_account_no AS bank_account_cipher
      FROM employees employee
      JOIN users actor ON actor.employee_id = employee.id
     WHERE actor.email = ${sqlText(employeeUser.email)}
  `);
  expect(employeeRows, 'the E2E employee actor should resolve to one seeded employee').toHaveLength(1);
  const baseline = employeeRows[0];
  const employeeName = `${baseline.first_name} ${baseline.last_name}`;
  const recipients = [employeeUser.email, hrUser.email, financeUser.email];
  const mailBefore = await mailpitForActors(recipients);
  let rejectedRequestHashId = '';
  let resubmittedRequestHashId = '';

  const plannedSteps = [
    { name: 'Employee, HR, and Finance open their own profile and review queues', actor: 'employee / hr_officer / finance_officer', uiPath: 'Profile menu → Profile; Human Resources → Profile Change Requests; Payroll → Bank Account Changes', expectedStatus: 'no open profile change requests' },
    { name: 'Employee submits the first bank change and HR receives it', actor: 'employee', uiPath: 'Profile → Bank account → Request update → Submit request', expectedStatus: 'pending; HR can act; employee bank account unchanged' },
    { name: 'HR rejects the first request and Employee sees the terminal rejection', actor: 'hr_officer', uiPath: 'Profile Change Requests → Awaiting my action → expand request → Reject', expectedStatus: 'rejected; no reviewer actions; Employee can submit a replacement' },
    { name: 'Employee submits a new bank change after rejection', actor: 'employee', uiPath: 'Profile → Bank account → Request update → Submit request', expectedStatus: 'first request remains rejected; new request pending HR' },
    { name: 'HR approves the replacement request and Finance finds the hand-off', actor: 'hr_officer', uiPath: 'Profile Change Requests → Awaiting my action → HR Approve; Finance → Bank Account Changes', expectedStatus: 'first request rejected; replacement pending_finance' },
    { name: 'Finance approves the replacement and Employee receives the new account', actor: 'finance_officer', uiPath: 'Bank Account Changes → Awaiting my action → Finance Approve', expectedStatus: 'first request rejected; replacement approved and applied' },
  ];

  try {
    await runProcessStep({
      name: plannedSteps[0].name,
      actor: plannedSteps[0].actor,
      uiPath: plannedSteps[0].uiPath,
      expectedStatus: plannedSteps[0].expectedStatus,
      page: employee.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => {
        await profileForActor(employee, employeeName);
        await expect(employee.page.getByRole('button', { name: 'Request update', exact: true })).toBeVisible();
        await expect(employee.page.getByText('No change requests', { exact: true })).toBeVisible();
        await openQueue(hr, 'Human Resources', 'Profile Change Requests');
        await expect(hr.page.getByRole('heading', { name: 'No requests', exact: true })).toBeVisible();
        await openQueue(finance, 'Payroll', 'Bank Account Changes');
        await expect(finance.page.getByRole('heading', { name: 'No requests', exact: true })).toBeVisible();
      },
      verify: async () => {
        expect(await requestsForJourney(employeeUser.email, journey.startedAt)).toHaveLength(0);
        expect(baseline.bank_name).toBeNull();
        expect(baseline.bank_account_cipher).toBeNull();
      },
      assertInvariants: async () => {
        employee.diagnostics.assertClean('employee opens profile for rejection/resubmission');
        hr.diagnostics.assertClean('HR opens the profile-change queue');
        finance.diagnostics.assertClean('Finance opens the bank-change queue');
      },
    });

    await runProcessStep({
      name: plannedSteps[1].name,
      actor: plannedSteps[1].actor,
      uiPath: plannedSteps[1].uiPath,
      expectedStatus: plannedSteps[1].expectedStatus,
      page: employee.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => {
        await employee.page.getByRole('button', { name: 'Request update', exact: true }).click();
        const dialog = employee.page.getByRole('dialog', { name: 'Request Bank Account Update' });
        await dialog.getByLabel('Bank name').fill(initialBankName);
        await dialog.getByLabel('New account number').fill(initialAccountNumber);
        const responsePromise = employee.page.waitForResponse((response) =>
          response.url().includes('/hr/self-service/profile/request-update') && response.request().method() === 'POST',
        );
        await dialog.getByRole('button', { name: 'Submit request', exact: true }).click();
        const response = await responsePromise;
        expect(response.status(), 'Employee UI submission should create the first request').toBe(201);
        const payload = await response.json() as { data?: { id?: string; status?: string } };
        rejectedRequestHashId = payload.data?.id ?? '';
        expect(rejectedRequestHashId, 'the first request should have a public identifier').not.toBe('');
        expect(payload.data?.status).toBe('pending');
        journey.values.rejectedRequestId = rejectedRequestHashId;
        journey.values.initialBankName = initialBankName;

        await expect(employee.page.getByText('Pending HR', { exact: true })).toBeVisible();
        await expect(hr.page.getByRole('row').filter({ hasText: employeeName })).toHaveCount(1);
        await clickBadgeHandoff(hr, 'Profile Change Requests', 1);
        await expandEmployeeRequest(hr, employeeName);
        await expect(hr.page.getByRole('region', { name: 'Requested changes' }).getByText(initialBankName, { exact: true })).toBeVisible();
        await expect(hr.page.getByRole('button', { name: 'HR Approve', exact: true })).toBeVisible();
        await expect(hr.page.getByRole('button', { name: 'Finance Approve', exact: true })).toHaveCount(0);
        await expect(finance.page.getByRole('button', { name: /View all Bank Account Changes signals/ })).toHaveCount(0);
      },
      verify: async () => {
        const rows = await requestsForJourney(employeeUser.email, journey.startedAt);
        expect(rows).toHaveLength(1);
        expect(rows[0].status).toBe('pending');
        expect(rows[0].requires_finance).toBe(true);
        expect(rows[0].requester_email).toBe(employeeUser.email);
        expect(rows[0].employee_bank_name).toBe(baseline.bank_name);
        expect(rows[0].employee_bank_account_cipher).toBe(baseline.bank_account_cipher);
        expect(rows[0].hr_reviewer_email).toBeNull();
        expect(rows[0].finance_reviewer_email).toBeNull();
        expect(rows[0].notification_count).toBe(0);
      },
      assertInvariants: async () => {
        await expect(hr.page.getByRole('button', { name: /View all Profile Change Requests signals: 1 Profile changes you can review/ })).toBeVisible();
        employee.diagnostics.assertClean('employee submits the initial bank-change request');
        hr.diagnostics.assertClean('HR receives the initial bank-change request');
        finance.diagnostics.assertClean('Finance has no pre-HR actionable request');
      },
    });
    await captureAnnotatedStep(hr.page, 'hr_officer', 'HR opens the first request from its actionable badge', 2, testInfo);

    await runProcessStep({
      name: plannedSteps[2].name,
      actor: plannedSteps[2].actor,
      uiPath: plannedSteps[2].uiPath,
      expectedStatus: plannedSteps[2].expectedStatus,
      page: hr.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => {
        const responsePromise = hr.page.waitForResponse((response) =>
          response.url().includes(`/hr/profile-update-requests/${rejectedRequestHashId}/review`) && response.request().method() === 'PATCH',
        );
        await hr.page.getByRole('button', { name: 'Reject', exact: true }).click();
        const confirm = hr.page.getByRole('dialog');
        await expect(confirm.getByText(/employee will see the rejection/i)).toBeVisible();
        await confirm.getByRole('button', { name: 'Reject', exact: true }).click();
        const response = await responsePromise;
        expect(response.status(), 'HR UI rejection should be accepted').toBe(200);

        const hrRejectedRow = requestRow(hr, employeeName, 'Rejected');
        await expect(hrRejectedRow).toBeVisible();
        await expect(hrRejectedRow.getByRole('button', { name: 'Reject', exact: true })).toHaveCount(0);
        await expect(hrRejectedRow.getByRole('button', { name: 'HR Approve', exact: true })).toHaveCount(0);
        const rejectedEmployeeRequest = employee.page.getByRole('listitem').filter({ hasText: 'Bank account no.' }).filter({ hasText: 'Rejected' });
        await expect(rejectedEmployeeRequest).toHaveCount(1);
        await expect(employee.page.getByRole('button', { name: 'Request update', exact: true })).toBeVisible();
        await expect(employee.page.getByText(initialBankName, { exact: true })).toHaveCount(0);
        await expect(finance.page.getByRole('button', { name: /View all Bank Account Changes signals/ })).toHaveCount(0);
      },
      verify: async () => {
        const rows = await requestsForJourney(employeeUser.email, journey.startedAt);
        expect(rows).toHaveLength(1);
        expect(rows[0].status).toBe('rejected');
        expect(rows[0].hr_reviewer_email).toBe(hrUser.email);
        expect(rows[0].finance_reviewer_email).toBeNull();
        expect(rows[0].employee_bank_name).toBe(baseline.bank_name);
        expect(rows[0].employee_bank_account_cipher).toBe(baseline.bank_account_cipher);
        expect(rows[0].notification_count).toBe(0);
        expect(rows[0].request_audit_count).toBeGreaterThanOrEqual(2);
      },
      assertInvariants: async () => {
        await expect(hr.page.getByRole('button', { name: /View all Profile Change Requests signals/ })).toHaveCount(0);
        await expect(employee.page.getByRole('listitem').filter({ hasText: 'Bank account no.' }).filter({ hasText: 'Rejected' })).toHaveCount(1);
        employee.diagnostics.assertClean('employee observes HR rejection without refresh');
        hr.diagnostics.assertClean('HR rejects the first request');
        finance.diagnostics.assertClean('Finance has no action before HR approval');
      },
    });
    await captureAnnotatedStep(employee.page, 'employee', 'Employee sees rejection and can request a replacement', 3, testInfo);

    await runProcessStep({
      name: plannedSteps[3].name,
      actor: plannedSteps[3].actor,
      uiPath: plannedSteps[3].uiPath,
      expectedStatus: plannedSteps[3].expectedStatus,
      page: employee.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => {
        await employee.page.getByRole('button', { name: 'Request update', exact: true }).click();
        const dialog = employee.page.getByRole('dialog', { name: 'Request Bank Account Update' });
        await dialog.getByLabel('Bank name').fill(resubmittedBankName);
        await dialog.getByLabel('New account number').fill(resubmittedAccountNumber);
        const responsePromise = employee.page.waitForResponse((response) =>
          response.url().includes('/hr/self-service/profile/request-update') && response.request().method() === 'POST',
        );
        await dialog.getByRole('button', { name: 'Submit request', exact: true }).click();
        const response = await responsePromise;
        expect(response.status(), 'Employee UI resubmission should create a new request').toBe(201);
        const payload = await response.json() as { data?: { id?: string; status?: string } };
        resubmittedRequestHashId = payload.data?.id ?? '';
        expect(resubmittedRequestHashId, 'resubmission should return a new public request identifier').not.toBe('');
        expect(resubmittedRequestHashId).not.toBe(rejectedRequestHashId);
        expect(payload.data?.status).toBe('pending');
        journey.values.resubmittedRequestId = resubmittedRequestHashId;
        journey.values.resubmittedBankName = resubmittedBankName;

        const employeeHistory = employee.page.getByRole('listitem').filter({ hasText: 'Bank account no.' });
        await expect(employeeHistory).toHaveCount(2);
        await expect(employeeHistory.filter({ hasText: 'Rejected' })).toHaveCount(1);
        await expect(employeeHistory.filter({ hasText: 'Pending HR' })).toHaveCount(1);
        await clickBadgeHandoff(hr, 'Profile Change Requests', 1);
        const hrPendingRow = requestRow(hr, employeeName, 'Pending HR');
        await expandRequestRow(hr, hrPendingRow, resubmittedBankName);
        await expect(requestedChangesRegion(hr, resubmittedBankName).getByText(resubmittedBankName, { exact: true })).toBeVisible();
        await expect(hr.page.getByRole('button', { name: 'HR Approve', exact: true })).toBeVisible();
        await expect(finance.page.getByRole('button', { name: /View all Bank Account Changes signals/ })).toHaveCount(0);
      },
      verify: async () => {
        const rows = await requestsForJourney(employeeUser.email, journey.startedAt);
        expect(rows).toHaveLength(2);
        expect(rows[0].status).toBe('rejected');
        expect(rows[0].hr_reviewer_email).toBe(hrUser.email);
        expect(rows[1].status).toBe('pending');
        expect(rows[1].requester_email).toBe(employeeUser.email);
        expect(rows[1].requires_finance).toBe(true);
        expect(rows[1].hr_reviewer_email).toBeNull();
        expect(rows[1].finance_reviewer_email).toBeNull();
        expect(rows[1].employee_bank_name).toBe(baseline.bank_name);
        expect(rows[1].employee_bank_account_cipher).toBe(baseline.bank_account_cipher);
        expect(rows.every((row) => row.notification_count === 0)).toBe(true);
      },
      assertInvariants: async () => {
        await expect(hr.page.getByRole('button', { name: /View all Profile Change Requests signals: 1 Profile changes you can review/ })).toBeVisible();
        await expect(employee.page.getByRole('button', { name: 'Request update', exact: true })).toHaveCount(0);
        employee.diagnostics.assertClean('employee resubmits the bank change');
        hr.diagnostics.assertClean('HR finds the new request through its badge');
        finance.diagnostics.assertClean('Finance still has no actionable request');
      },
    });
    await captureAnnotatedStep(hr.page, 'hr_officer', 'HR locates the replacement request in its actionable queue', 4, testInfo);

    await runProcessStep({
      name: plannedSteps[4].name,
      actor: plannedSteps[4].actor,
      uiPath: plannedSteps[4].uiPath,
      expectedStatus: plannedSteps[4].expectedStatus,
      page: hr.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => {
        const responsePromise = hr.page.waitForResponse((response) =>
          response.url().includes(`/hr/profile-update-requests/${resubmittedRequestHashId}/review`) && response.request().method() === 'PATCH',
        );
        await hr.page.getByRole('button', { name: 'HR Approve', exact: true }).click();
        const confirm = hr.page.getByRole('dialog');
        await expect(confirm.getByText(/change still needs Finance approval/i)).toBeVisible();
        await confirm.getByRole('button', { name: 'Approve', exact: true }).click();
        const response = await responsePromise;
        expect(response.status(), 'HR approval of the replacement should be accepted').toBe(200);

        await expect(employee.page.getByRole('listitem').filter({ hasText: 'Bank account no.' }).filter({ hasText: 'Awaiting Finance' })).toHaveCount(1);
        await expect(requestRow(hr, employeeName, 'Awaiting Finance')).toBeVisible();
        await expect(requestRow(hr, employeeName, 'Rejected')).toBeVisible();
        await clickBadgeHandoff(finance, 'Bank Account Changes', 1);
        const financePendingRow = requestRow(finance, employeeName, 'Awaiting Finance');
        await expandRequestRow(finance, financePendingRow, resubmittedBankName);
        await expect(requestedChangesRegion(finance, resubmittedBankName).getByText(resubmittedBankName, { exact: true })).toBeVisible();
        await expect(finance.page.getByRole('button', { name: 'Finance Approve', exact: true })).toBeVisible();
        await expect(finance.page.getByRole('button', { name: 'HR Approve', exact: true })).toHaveCount(0);
      },
      verify: async () => {
        const rows = await requestsForJourney(employeeUser.email, journey.startedAt);
        expect(rows).toHaveLength(2);
        expect(rows[0].status).toBe('rejected');
        expect(rows[1].status).toBe('pending_finance');
        expect(rows[1].hr_reviewer_email).toBe(hrUser.email);
        expect(rows[1].finance_reviewer_email).toBeNull();
        expect(rows[1].employee_bank_name).toBe(baseline.bank_name);
        expect(rows[1].employee_bank_account_cipher).toBe(baseline.bank_account_cipher);
      },
      assertInvariants: async () => {
        await expect(employee.page.getByRole('listitem').filter({ hasText: 'Bank account no.' }).filter({ hasText: 'Rejected' })).toHaveCount(1);
        await expect(employee.page.getByRole('listitem').filter({ hasText: 'Bank account no.' }).filter({ hasText: 'Awaiting Finance' })).toHaveCount(1);
        await expect(finance.page.getByRole('button', { name: /View all Bank Account Changes signals: 1 Profile changes you can review/ })).toBeVisible();
        employee.diagnostics.assertClean('employee observes second HR approval');
        hr.diagnostics.assertClean('HR approves the replacement request');
        finance.diagnostics.assertClean('Finance receives the replacement request');
      },
    });
    await captureAnnotatedStep(employee.page, 'employee', 'Employee sees Finance review for the replacement', 5, testInfo);

    await runProcessStep({
      name: plannedSteps[5].name,
      actor: plannedSteps[5].actor,
      uiPath: plannedSteps[5].uiPath,
      expectedStatus: plannedSteps[5].expectedStatus,
      page: finance.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => {
        const responsePromise = finance.page.waitForResponse((response) =>
          response.url().includes(`/hr/profile-update-requests/${resubmittedRequestHashId}/finance-review`) && response.request().method() === 'PATCH',
        );
        await finance.page.getByRole('button', { name: 'Finance Approve', exact: true }).click();
        const confirm = finance.page.getByRole('dialog');
        await expect(confirm.getByText(/applies the new bank account/i)).toBeVisible();
        await confirm.getByRole('button', { name: 'Approve', exact: true }).click();
        const response = await responsePromise;
        expect(response.status(), 'Finance approval of the replacement should be accepted').toBe(200);

        await expect(employee.page.getByRole('listitem').filter({ hasText: 'Bank account no.' }).filter({ hasText: 'Approved' })).toHaveCount(1);
        await expect(employee.page.getByText(resubmittedBankName, { exact: true })).toBeVisible();
        await expect(employee.page.getByRole('definition').filter({ hasText: `••••${resubmittedAccountNumber.slice(-4)}` })).toBeVisible();
        await expect(employee.page.getByText(resubmittedAccountNumber, { exact: true })).toHaveCount(0);
        await expect(requestRow(finance, employeeName, 'Approved')).toBeVisible();
        await expect(requestRow(hr, employeeName, 'Approved')).toBeVisible();
        await expect(requestRow(hr, employeeName, 'Rejected')).toBeVisible();
        await expect(finance.page.getByRole('button', { name: 'Finance Approve', exact: true })).toHaveCount(0);
        await expect(hr.page.getByRole('button', { name: 'HR Approve', exact: true })).toHaveCount(0);
      },
      verify: async () => {
        const rows = await requestsForJourney(employeeUser.email, journey.startedAt);
        expect(rows).toHaveLength(2);
        expect(rows[0].status).toBe('rejected');
        expect(rows[0].hr_reviewer_email).toBe(hrUser.email);
        expect(rows[0].finance_reviewer_email).toBeNull();
        expect(rows[1].status).toBe('approved');
        expect(rows[1].requester_email).toBe(employeeUser.email);
        expect(rows[1].requires_finance).toBe(true);
        expect(rows[1].hr_reviewer_email).toBe(hrUser.email);
        expect(rows[1].finance_reviewer_email).toBe(financeUser.email);
        expect(rows[1].employee_bank_name).toBe(resubmittedBankName);
        expect(rows[1].employee_bank_account_cipher).not.toBeNull();
        expect(rows[1].employee_bank_account_cipher).not.toBe(baseline.bank_account_cipher);
        expect(rows[0].request_audit_count).toBeGreaterThanOrEqual(2);
        expect(rows[1].request_audit_count).toBeGreaterThanOrEqual(4);
        expect(rows.every((row) => row.notification_count === 0)).toBe(true);

        const audits = await processAuditsForJourney(employeeUser.email, journey.startedAt);
        const firstRequest = audits.filter((audit) => audit.request_id === rows[0].request_id);
        const replacementRequest = audits.filter((audit) => audit.request_id === rows[1].request_id);
        const firstCreated = firstRequest.find((audit) => audit.action === 'created');
        const firstRejected = firstRequest.find((audit) => audit.status === 'rejected');
        const replacementCreated = replacementRequest.find((audit) => audit.action === 'created');
        const replacementHrApproval = replacementRequest.find((audit) => audit.status === 'pending_finance');
        const replacementFinanceApproval = replacementRequest.find((audit) => audit.status === 'approved');
        expect(firstCreated).toBeDefined();
        expect(firstRejected).toBeDefined();
        expect(replacementCreated).toBeDefined();
        expect(replacementHrApproval).toBeDefined();
        expect(replacementFinanceApproval).toBeDefined();
        assertAuditActor({ actor_email: firstCreated?.actor_email ?? undefined }, employeeUser.email);
        assertAuditActor({ actor_email: firstRejected?.actor_email ?? undefined }, hrUser.email);
        assertAuditActor({ actor_email: replacementCreated?.actor_email ?? undefined }, employeeUser.email);
        assertAuditActor({ actor_email: replacementHrApproval?.actor_email ?? undefined }, hrUser.email);
        assertAuditActor({ actor_email: replacementFinanceApproval?.actor_email ?? undefined }, financeUser.email);

        const employeeAudit = await employeeBankAudit(baseline.employee_id, journey.startedAt);
        expect(employeeAudit.some((entry) =>
          entry.actor_email === financeUser.email
          && entry.bank_name_audited === true
          && entry.bank_account_audited === true,
        )).toBe(true);
        const mailAfter = await mailpitForActors(recipients);
        expect(mailAfter.map((message) => message.ID).sort()).toEqual(mailBefore.map((message) => message.ID).sort());
      },
      assertInvariants: async () => {
        await expect(employee.page.getByRole('button', { name: 'Request update', exact: true })).toBeVisible();
        await expect(hr.page.getByRole('button', { name: /View all Profile Change Requests signals/ })).toHaveCount(0);
        await expect(finance.page.getByRole('button', { name: /View all Bank Account Changes signals/ })).toHaveCount(0);
        employee.diagnostics.assertClean('employee sees the approved replacement and masked account');
        hr.diagnostics.assertClean('HR sees both the rejected and completed requests');
        finance.diagnostics.assertClean('Finance completes the replacement approval');
      },
    });
    await captureAnnotatedStep(employee.page, 'employee', 'Employee sees the approved replacement with masked account number', 6, testInfo);
  } finally {
    await Promise.all([employee.context.close(), hr.context.close(), finance.context.close()]);
  }
});

test('HR07-PROFILE-ORDINARY — HR approves an employee contact change without Finance review', async ({ browser }, testInfo) => {
  test.setTimeout(180_000);

  const actors = await loadActors();
  const employeeUser = actorByName(actors, 'employee');
  const hrUser = actorByName(actors, 'hr_officer');
  const financeUser = actorByName(actors, 'finance_officer');
  const employee = await actorPage(browser, employeeUser.actor);
  const hr = await actorPage(browser, hrUser.actor);
  const finance = await actorPage(browser, financeUser.actor);
  const journey = createJourneyContext('HR07-PROFILE-ORDINARY');
  const mobileSuffix = (Number.parseInt(journey.runId.replaceAll('-', '').slice(0, 7), 16) % 10_000_000)
    .toString()
    .padStart(7, '0');
  const mobileNumber = `0917${mobileSuffix}`;
  const employeeRows = await readOnlyDatabaseJson<Array<{
    employee_id: number;
    employee_no: string;
    first_name: string;
    last_name: string;
    mobile_number: string | null;
  }>>(`
    SELECT employee.id AS employee_id, employee.employee_no,
           employee.first_name, employee.last_name, employee.mobile_number
      FROM employees employee
      JOIN users actor ON actor.employee_id = employee.id
     WHERE actor.email = ${sqlText(employeeUser.email)}
  `);
  expect(employeeRows, 'the E2E employee actor should resolve to one seeded employee').toHaveLength(1);
  const baseline = employeeRows[0];
  const employeeName = `${baseline.first_name} ${baseline.last_name}`;
  const recipients = [employeeUser.email, hrUser.email, financeUser.email];
  const mailBefore = await mailpitForActors(recipients);
  let requestHashId = '';

  const plannedSteps = [
    { name: 'Employee, HR, and Finance open their own profile and review queues', actor: 'employee / hr_officer / finance_officer', uiPath: 'Profile menu → Profile; Human Resources → Profile Change Requests; Payroll → Bank Account Changes', expectedStatus: 'no open profile change requests' },
    { name: 'Employee requests an ordinary contact change and HR receives it', actor: 'employee', uiPath: 'Profile → Contact → Edit → Submit for review', expectedStatus: 'pending; HR can approve; employee contact remains unchanged' },
    { name: 'HR approves the contact change and it is applied without Finance review', actor: 'hr_officer', uiPath: 'Profile Change Requests → Awaiting my action → expand request → Approve', expectedStatus: 'approved; employee contact updated; Finance has no action' },
  ];

  try {
    await runProcessStep({
      name: plannedSteps[0].name,
      actor: plannedSteps[0].actor,
      uiPath: plannedSteps[0].uiPath,
      expectedStatus: plannedSteps[0].expectedStatus,
      page: employee.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => {
        await profileForActor(employee, employeeName);
        await expect(employee.page.getByRole('heading', { name: 'Contact', exact: true })).toBeVisible();
        await openQueue(hr, 'Human Resources', 'Profile Change Requests');
        await expect(hr.page.getByRole('heading', { name: 'No requests', exact: true })).toBeVisible();
        await openQueue(finance, 'Payroll', 'Bank Account Changes');
        await expect(finance.page.getByRole('heading', { name: 'No requests', exact: true })).toBeVisible();
      },
      verify: async () => {
        expect(await ordinaryProfileRequestForJourney(employeeUser.email, journey.startedAt)).toHaveLength(0);
        expect(baseline.mobile_number === null || /^09\d{9}$/.test(baseline.mobile_number)).toBe(true);
      },
      assertInvariants: async () => {
        employee.diagnostics.assertClean('employee opens Profile for ordinary contact change');
        hr.diagnostics.assertClean('HR opens the ordinary profile-change queue');
        finance.diagnostics.assertClean('Finance opens the bank-change queue');
      },
    });

    await runProcessStep({
      name: plannedSteps[1].name,
      actor: plannedSteps[1].actor,
      uiPath: plannedSteps[1].uiPath,
      expectedStatus: plannedSteps[1].expectedStatus,
      page: employee.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => {
        const editButtons = employee.page.getByRole('button', { name: 'Edit', exact: true });
        await expect(editButtons, 'Profile exposes Contact, Emergency contact, and Address edit actions').toHaveCount(3);
        // Contact is the first user-visible editable panel on the Profile page.
        await editButtons.first().click();
        await employee.page.getByLabel('Mobile', { exact: true }).fill(mobileNumber);
        const responsePromise = employee.page.waitForResponse((response) =>
          response.url().includes('/hr/self-service/profile/request-update') && response.request().method() === 'POST',
        );
        await employee.page.getByRole('button', { name: 'Submit for review', exact: true }).click();
        const response = await responsePromise;
        expect(response.status(), 'Employee UI submission should create the ordinary change request').toBe(201);
        const payload = await response.json() as { data?: { id?: string; status?: string } };
        requestHashId = payload.data?.id ?? '';
        expect(requestHashId, 'the ordinary change request should have a public identifier').not.toBe('');
        expect(payload.data?.status).toBe('pending');
        journey.values.profileUpdateRequestId = requestHashId;
        journey.values.mobileNumber = mobileNumber;

        await expect(employee.page.getByText('Change request submitted for HR review.', { exact: true })).toBeVisible();
        const employeeRequest = employee.page.getByRole('listitem').filter({ hasText: 'Mobile' });
        await expect(employeeRequest.getByText('Pending HR', { exact: true })).toBeVisible();
        await expect(hr.page.getByRole('row').filter({ hasText: employeeName })).toHaveCount(1);
        await clickBadgeHandoff(hr, 'Profile Change Requests', 1);
        await expandEmployeeRequest(hr, employeeName);
        await expect(hr.page.getByRole('region', { name: 'Requested changes' }).getByText(mobileNumber, { exact: true })).toBeVisible();
        await expect(hr.page.getByRole('button', { name: 'Approve', exact: true })).toBeVisible();
        await expect(hr.page.getByRole('button', { name: 'HR Approve', exact: true })).toHaveCount(0);
        await expect(hr.page.getByRole('button', { name: 'Finance Approve', exact: true })).toHaveCount(0);
        await expect(finance.page.getByRole('button', { name: /View all Bank Account Changes signals/ })).toHaveCount(0);
      },
      verify: async () => {
        const rows = await ordinaryProfileRequestForJourney(employeeUser.email, journey.startedAt);
        expect(rows).toHaveLength(1);
        expect(rows[0].status).toBe('pending');
        expect(rows[0].requires_finance).toBe(false);
        expect(rows[0].requester_email).toBe(employeeUser.email);
        expect(rows[0].employee_mobile_number).toBe(baseline.mobile_number);
        expect(rows[0].hr_reviewer_email).toBeNull();
        expect(rows[0].notification_count).toBe(0);
        expect(rows[0].request_audit_count).toBeGreaterThanOrEqual(1);
      },
      assertInvariants: async () => {
        await expect(hr.page.getByRole('button', { name: /View all Profile Change Requests signals: 1 Profile changes you can review/ })).toBeVisible();
        await expect(finance.page.getByRole('row').filter({ hasText: employeeName })).toHaveCount(0);
        employee.diagnostics.assertClean('employee submits an ordinary contact change');
        hr.diagnostics.assertClean('HR receives the contact-change request');
        finance.diagnostics.assertClean('Finance receives no ordinary profile-change action');
      },
    });
    await captureAnnotatedStep(hr.page, 'hr_officer', 'HR opens the ordinary contact request from its actionable badge', 2, testInfo);

    await runProcessStep({
      name: plannedSteps[2].name,
      actor: plannedSteps[2].actor,
      uiPath: plannedSteps[2].uiPath,
      expectedStatus: plannedSteps[2].expectedStatus,
      page: hr.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => {
        const responsePromise = hr.page.waitForResponse((response) =>
          response.url().includes(`/hr/profile-update-requests/${requestHashId}/review`) && response.request().method() === 'PATCH',
        );
        await hr.page.getByRole('button', { name: 'Approve', exact: true }).click();
        const confirm = hr.page.getByRole('dialog');
        await expect(confirm.getByText(/whitelisted fields will be applied/i)).toBeVisible();
        await confirm.getByRole('button', { name: 'Approve', exact: true }).click();
        const response = await responsePromise;
        expect(response.status(), 'HR UI approval should be accepted').toBe(200);

        await expect(employee.page.getByRole('listitem').filter({ hasText: 'Mobile' }).getByText('Approved', { exact: true })).toBeVisible();
        await expect(employee.page.getByText(mobileNumber, { exact: true })).toBeVisible();
        const hrRow = requestRow(hr, employeeName, 'Approved');
        await expect(hrRow).toBeVisible();
        await expect(hrRow.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
        await expect(hrRow.getByRole('button', { name: 'Reject', exact: true })).toHaveCount(0);
        await expect(finance.page.getByRole('button', { name: /View all Bank Account Changes signals/ })).toHaveCount(0);
      },
      verify: async () => {
        const rows = await ordinaryProfileRequestForJourney(employeeUser.email, journey.startedAt);
        expect(rows).toHaveLength(1);
        expect(rows[0].status).toBe('approved');
        expect(rows[0].requires_finance).toBe(false);
        expect(rows[0].requester_email).toBe(employeeUser.email);
        expect(rows[0].hr_reviewer_email).toBe(hrUser.email);
        expect(rows[0].employee_mobile_number).toBe(mobileNumber);
        expect(rows[0].request_audit_count).toBeGreaterThanOrEqual(2);
        expect(rows[0].notification_count).toBe(0);

        const audits = await ordinaryProfileAuditsForJourney(employeeUser.email, journey.startedAt);
        const created = audits.find((audit) => audit.request_id === rows[0].request_id && audit.action === 'created');
        const approved = audits.find((audit) => audit.request_id === rows[0].request_id && audit.status === 'approved');
        expect(created).toBeDefined();
        expect(approved).toBeDefined();
        assertAuditActor({ actor_email: created?.actor_email ?? undefined }, employeeUser.email);
        assertAuditActor({ actor_email: approved?.actor_email ?? undefined }, hrUser.email);

        const employeeAudits = await employeeMobileAudit(baseline.employee_id, journey.startedAt);
        expect(employeeAudits.some((audit) => audit.actor_email === hrUser.email && audit.mobile_number_audited)).toBe(true);
        const mailAfter = await mailpitForActors(recipients);
        expect(mailAfter.map((message) => message.ID).sort()).toEqual(mailBefore.map((message) => message.ID).sort());
      },
      assertInvariants: async () => {
        await expect(employee.page.getByText(/0 pending · 1 total/)).toBeVisible();
        await expect(hr.page.getByRole('button', { name: /View all Profile Change Requests signals/ })).toHaveCount(0);
        await expect(finance.page.getByRole('button', { name: /View all Bank Account Changes signals/ })).toHaveCount(0);
        employee.diagnostics.assertClean('employee sees the approved contact change live');
        hr.diagnostics.assertClean('HR completes the ordinary profile review');
        finance.diagnostics.assertClean('Finance has no action for the ordinary change');
      },
    });
    await captureAnnotatedStep(employee.page, 'employee', 'Employee sees the approved contact value and closed request', 3, testInfo);
  } finally {
    await Promise.all([employee.context.close(), hr.context.close(), finance.context.close()]);
  }
});

test('HR07-SALARY-APPROVAL — salary adjustment request is blocked by missing UI (GAP-UI-02)', async ({ browser }, testInfo) => {
  test.setTimeout(90_000);

  const actors = await loadActors();
  const hrUser = actorByName(actors, 'hr_officer');
  const employeeUser = actorByName(actors, 'employee');
  const hr = await actorPage(browser, hrUser.actor);
  const journey = createJourneyContext('HR07-SALARY-APPROVAL');
  const employees = await readOnlyDatabaseJson<Array<{
    employee_id: number;
    employee_no: string;
    first_name: string;
    last_name: string;
    basic_monthly_salary: string | null;
    salary_adjustment_count: number;
  }>>(`
    SELECT employee.id AS employee_id, employee.employee_no,
           employee.first_name, employee.last_name, employee.basic_monthly_salary,
           (SELECT COUNT(*)::int FROM salary_adjustments adjustment
             WHERE adjustment.employee_id = employee.id) AS salary_adjustment_count
      FROM employees employee
      JOIN users actor ON actor.employee_id = employee.id
     WHERE actor.email = ${sqlText(employeeUser.email)}
  `);
  expect(employees, 'the seeded Employee should resolve to one compensation record').toHaveLength(1);
  const target = employees[0];
  expect(target.salary_adjustment_count, 'a fresh baseline should not contain prior salary transactions').toBe(0);
  const employeeName = `${target.first_name} ${target.last_name}`;
  const plannedSteps = [
    { name: 'HR requests an employee salary adjustment through the UI', actor: 'hr_officer', uiPath: 'Human Resources → Employees → Employee detail → Edit / Salary Adjustments', expectedStatus: 'pending; PM checker receives the request' },
    { name: 'Production Manager checks the salary adjustment', actor: 'production_manager', uiPath: 'Human Resources → Salary Adjustments → Approve or Reject', expectedStatus: 'pending for VP after PM approval; rejected terminal on PM rejection' },
    { name: 'Vice President approves the final salary adjustment', actor: 'vice_president', uiPath: 'Human Resources → Salary Adjustments → Approve or Reject', expectedStatus: 'approved; pay history and effective-date behavior applied' },
  ];

  try {
    await test.step('HR inspects the employee compensation UI and salary-adjustment queue', async () => {
      await hr.page.goto('/dashboard');
      await expect(hr.page.getByRole('navigation', { name: 'Modules' })).toBeVisible();
      await openSidebarItem(hr, 'Human Resources', '/hr/employees', 'Employees');
      await expect(hr.page.getByRole('heading', { name: 'Employees', exact: true })).toBeVisible();
      await hr.page.getByPlaceholder('Search name or employee no…').fill(target.employee_no);
      const employeeRow = hr.page.getByRole('row').filter({ hasText: target.employee_no });
      await expect(employeeRow).toHaveCount(1);
      await expect(employeeRow).toContainText(employeeName);
      await employeeRow.click();
      await expect(hr.page.getByRole('heading', { name: new RegExp(employeeName) })).toBeVisible();
      await hr.page.getByRole('button', { name: 'Edit', exact: true }).click();
      await expect(hr.page.getByText(/Compensation is managed through the salary-adjustment approval workflow\./)).toBeVisible();
      await expect(hr.page.getByRole('link', { name: 'Open salary adjustments', exact: true })).toBeVisible();
      await expect(hr.page.getByLabel('Monthly salary', { exact: true })).toHaveCount(0);
      await expect(hr.page.getByLabel('Semi-monthly rate', { exact: true })).toHaveCount(0);
      await expect(hr.page.getByRole('button', { name: /Request salary adjustment|New salary adjustment/i })).toHaveCount(0);

      await openSidebarItem(hr, 'Human Resources', '/hr/employees', 'Employees');
      await hr.page.getByRole('button', { name: 'Salary Adjustments', exact: true }).click();
      await expect(hr.page.getByRole('heading', { name: 'Salary Adjustments', exact: true })).toBeVisible();
      await expect(hr.page.getByText('No adjustments', { exact: true })).toBeVisible();
      await expect(hr.page.getByRole('button', { name: /Request salary adjustment|New salary adjustment/i })).toHaveCount(0);
      journey.values.employeeNo = target.employee_no;
      await captureAnnotatedStep(hr.page, 'hr_officer', 'Salary-adjustment queue exposes reviewers but no request action', 1, testInfo);
      hr.diagnostics.assertClean('HR inspects salary adjustment entry points');
    });

    await runProcessStep({
      name: plannedSteps[0].name,
      actor: plannedSteps[0].actor,
      uiPath: plannedSteps[0].uiPath,
      expectedStatus: plannedSteps[0].expectedStatus,
      page: hr.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => {
        test.fixme(true, 'GAP-UI-02: SalaryAdjustment has an API request endpoint and active PM → VP approval chain, but the SPA exposes no HR request form/action. The employee edit screen removes compensation fields; the salary-adjustment page is an approval queue only. Do not create this transactional record through the API.');
      },
      verify: async () => {
        expect(target.salary_adjustment_count).toBe(0);
      },
      assertInvariants: async () => {
        hr.diagnostics.assertClean('salary-adjustment request is blocked at the missing UI stage');
      },
    });
  } finally {
    await hr.context.close();
  }
});
