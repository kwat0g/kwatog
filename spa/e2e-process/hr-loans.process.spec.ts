import { test, expect } from './fixtures';
import type { Browser, TestInfo } from '@playwright/test';
import { actorPage, loadActors } from './actor-session';
import type { E2EActor } from './actor-session';
import { createJourneyContext, runProcessStep } from './process-runner';
import { assertAuditActor, assertRecipientsExactly, assertSecondViewerRealtime } from './helpers/process-invariants';
import { captureAnnotatedStep } from './helpers/annotated-screenshot';
import { readOnlyDatabaseJson } from './helpers/database-reader';
import { listMailpitMessages } from './helpers/mailpit-reader';

type ActorPage = Awaited<ReturnType<typeof actorPage>>;
type LoanSnapshot = {
  loan_id: number;
  loan_no: string;
  status: string;
  loan_type: string;
  principal: string;
  total_paid: string;
  balance: string;
  pay_periods_total: number;
  pay_periods_remaining: number;
  purpose: string;
  requester_email: string;
  employee_id: number;
  department_id: number;
  disbursement_journal_entry_id: number | null;
  payment_count: number;
};
type ApprovalSnapshot = {
  step_order: number;
  role_slug: string;
  action: string;
  approver_email: string | null;
  remarks: string | null;
};
type NoticeSnapshot = { type: string; recipient_email: string; link_to: string | null; dedupe_key: string | null };
type AuditSnapshot = { action: string; actor_email: string | null; status: string | null };
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

const plannedSteps = [
  { name: 'Employee submits a cash advance request', actor: 'employee', uiPath: 'Profile menu → Loans → Apply for a loan → Cash Advance', expectedStatus: 'pending; Department Head is the current approval step; no journal entry' },
  { name: 'Department Head finds and approves the first step', actor: 'department_head', uiPath: 'Sidebar → Approval Queue → My action required → Cash Advance loan → Approve', expectedStatus: 'pending; Finance is the current approval step; no disbursement' },
  { name: 'Finance follows the approval notification and approves', actor: 'finance_officer', uiPath: 'Notification bell → Loan approval notice → Approve', expectedStatus: 'pending; Vice President is the current approval step; no disbursement' },
  { name: 'Vice President follows the approval notification and approves', actor: 'vice_president', uiPath: 'Notification bell → Loan approval notice → Approve', expectedStatus: 'active; one balanced disbursement journal entry; Employee is notified' },
];

function actorByName(actors: E2EActor[], name: string): E2EActor {
  const actor = actors.find((candidate) => candidate.actor === name);
  if (!actor) throw new Error(`Required seeded E2E actor is missing: ${name}`);
  return actor;
}

function sqlText(value: string): string {
  return `'${value.replaceAll("'", "''")}'`;
}

async function openDashboard(session: ActorPage): Promise<void> {
  await session.page.goto('/dashboard');
  await expect(session.page.getByRole('navigation', { name: 'Modules' })).toBeVisible();
  await expect(session.page.getByRole('button', { name: /^Profile menu for / })).toBeVisible();
}

async function openEmployeeLoans(session: ActorPage): Promise<void> {
  await openDashboard(session);
  await session.page.getByRole('button', { name: /^Profile menu for / }).click();
  await session.page.getByRole('menuitem', { name: 'Loans', exact: true }).click();
  await expect(session.page).toHaveURL(/\/self-service\/loans(?:\?|$)/);
  await expect(session.page.getByRole('heading', { name: 'Loans & Cash Advances', exact: true })).toBeVisible();
  await expect(session.page.getByRole('button', { name: 'Apply for a loan', exact: true }).first()).toBeEnabled();
}

async function openSidebarItem(session: ActorPage, section: string, href: string, label: string): Promise<void> {
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

async function openLoansObserver(session: ActorPage): Promise<void> {
  await openDashboard(session);
  await openSidebarItem(session, 'Human Resources', '/hr/loans', 'Employee Loans');
  await expect(session.page.getByRole('heading', { name: 'Loans & Cash Advance', exact: true })).toBeVisible();
}

async function loanSnapshot(purpose: string): Promise<LoanSnapshot[]> {
  return readOnlyDatabaseJson<LoanSnapshot[]>(`
    SELECT loan.id AS loan_id, loan.loan_no, loan.status::text AS status,
           loan.loan_type, loan.principal::text AS principal, loan.total_paid::text AS total_paid,
           loan.balance::text AS balance, loan.pay_periods_total, loan.pay_periods_remaining,
           loan.purpose, requester.email AS requester_email, employee.id AS employee_id,
           employee.department_id, loan.disbursement_journal_entry_id,
           (SELECT COUNT(*)::int FROM loan_payments payment WHERE payment.loan_id = loan.id) AS payment_count
      FROM employee_loans loan
      JOIN employees employee ON employee.id = loan.employee_id
      JOIN users requester ON requester.employee_id = employee.id
     WHERE loan.purpose = ${sqlText(purpose)}
     ORDER BY loan.id DESC
     LIMIT 1
  `);
}

async function approvalRows(loanId: number): Promise<ApprovalSnapshot[]> {
  return readOnlyDatabaseJson<ApprovalSnapshot[]>(`
    SELECT approval.step_order, approval.role_slug, approval.action, approval.remarks,
           approver.email AS approver_email
      FROM approval_records approval
      LEFT JOIN users approver ON approver.id = approval.approver_id
     WHERE approval.approvable_id = ${loanId}
       AND approval.approvable_type ILIKE '%EmployeeLoan%'
       AND approval.is_current = true
     ORDER BY approval.step_order
  `);
}

async function noticesForLoan(hashId: string): Promise<NoticeSnapshot[]> {
  return readOnlyDatabaseJson<NoticeSnapshot[]>(`
    SELECT notification.type, recipient.email AS recipient_email,
           notification.data->>'link_to' AS link_to, notification.dedupe_key
      FROM notifications notification
      JOIN users recipient ON recipient.id = notification.notifiable_id
     WHERE notification.data->>'entity_id' = ${sqlText(hashId)}
     ORDER BY notification.id
  `);
}

async function auditRows(loanId: number): Promise<AuditSnapshot[]> {
  return readOnlyDatabaseJson<AuditSnapshot[]>(`
    SELECT audit.action, actor.email AS actor_email,
           COALESCE(audit.new_values->>'status', audit.old_values->>'status') AS status
      FROM audit_logs audit
      LEFT JOIN users actor ON actor.id = audit.user_id
     WHERE (
       audit.model_id::text = ${sqlText(String(loanId))}
       AND audit.model_type ILIKE '%EmployeeLoan%'
     ) OR (
       audit.model_id IN (
         SELECT approval.id
           FROM approval_records approval
          WHERE approval.approvable_id = ${loanId}
            AND approval.approvable_type ILIKE '%EmployeeLoan%'
       )
       AND audit.model_type ILIKE '%ApprovalRecord%'
     )
     ORDER BY audit.id
  `);
}

async function journalRows(loanId: number): Promise<JournalSnapshot[]> {
  return readOnlyDatabaseJson<JournalSnapshot[]>(`
    SELECT entry.entry_number, entry.status, creator.email AS created_by_email,
           poster.email AS posted_by_email,
           entry.total_debit::text AS total_debit, entry.total_credit::text AS total_credit,
           COALESCE(SUM(line.debit), 0)::text AS line_debit,
           COALESCE(SUM(line.credit), 0)::text AS line_credit
      FROM journal_entries entry
      LEFT JOIN users creator ON creator.id = entry.created_by
      LEFT JOIN users poster ON poster.id = entry.posted_by
      LEFT JOIN journal_entry_lines line ON line.journal_entry_id = entry.id
     WHERE entry.reference_type = 'loan_disbursement'
       AND entry.reference_id = ${loanId}
       AND entry.deleted_at IS NULL
     GROUP BY entry.id, creator.email, poster.email
  `);
}

async function waitForLoan(purpose: string, expectedStatus: string): Promise<LoanSnapshot> {
  let rows: LoanSnapshot[] = [];
  await expect.poll(async () => {
    rows = await loanSnapshot(purpose);
    return rows[0]?.status ?? 'missing';
  }, { timeout: 15_000, message: `loan should reach ${expectedStatus}` }).toBe(expectedStatus);
  if (!rows[0]) throw new Error(`Loan request disappeared while waiting for ${expectedStatus}.`);
  return rows[0];
}

async function waitForNotification(actor: ActorPage, loan: LoanSnapshot, hashId: string): Promise<void> {
  const bell = actor.page.getByRole('button', { name: /^Notifications(?:, \d+ unread)?$/ });
  await expect(bell, 'the notification bell should be present').toBeVisible();
  await bell.click();
  const dialog = actor.page.getByRole('dialog', { name: 'Notifications' });
  await expect(dialog).toBeVisible();
  const link = dialog.getByRole('link').filter({ hasText: loan.loan_no });
  await expect(link, `the real approval hand-off should include ${loan.loan_no}`).toHaveCount(1);
  await expect(link).toHaveAttribute('href', `/hr/loans/${hashId}`);
  await link.click();
  await expect(actor.page).toHaveURL(new RegExp(`/hr/loans/${hashId}(?:\\?|$)`));
  await expect(actor.page.getByRole('heading', { name: new RegExp(loan.loan_no) })).toBeVisible();
}

async function openApprovalQueue(actor: ActorPage, loan: LoanSnapshot, hashId: string): Promise<void> {
  await openDashboard(actor);
  const nav = actor.page.getByRole('navigation', { name: 'Modules' });
  const queueLink = nav.getByRole('link', { name: 'Approval Queue', exact: true });
  await expect(queueLink).toBeVisible();
  await queueLink.click();
  await expect(actor.page).toHaveURL(/\/approvals(?:\?|$)/);
  await expect(actor.page.getByRole('heading', { name: 'Approvals', exact: true })).toBeVisible();
  const card = actor.page.getByRole('button').filter({ hasText: loan.loan_no });
  await expect(card, `Department Head should find ${loan.loan_no} in My action required`).toHaveCount(1);
  await card.click();
  await expect(actor.page).toHaveURL(new RegExp(`/hr/loans/${hashId}(?:\\?|$)`));
  await expect(actor.page.getByRole('heading', { name: new RegExp(loan.loan_no) })).toBeVisible();
}

async function approveThroughUi(session: ActorPage, hashId: string): Promise<void> {
  const approveButton = session.page.getByRole('button', { name: 'Approve', exact: true });
  await expect(approveButton).toHaveCount(1);
  await approveButton.click();
  const confirm = session.page.getByRole('dialog', { name: 'Approve loan request?' });
  await expect(confirm).toBeVisible();
  const responsePromise = session.page.waitForResponse((response) =>
    response.request().method() === 'PATCH'
      && response.url().includes(`/api/v1/loans/${hashId}/approve`),
  );
  await confirm.getByRole('button', { name: 'Approve', exact: true }).click();
  const response = await responsePromise;
  expect(response.status(), 'approval is submitted through the acting role UI').toBe(200);
  await expect(session.page.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
  await expect(session.page.getByRole('button', { name: 'Reject', exact: true })).toHaveCount(0);
}

test('HR05-CASH-ADVANCE — Employee request advances through Department Head, Finance, and VP before disbursement', async ({ browser }, testInfo) => {
  test.setTimeout(180_000);
  const actors = await loadActors();
  const employeeUser = actorByName(actors, 'employee');
  const departmentHeadUser = actorByName(actors, 'department_head');
  const financeUser = actorByName(actors, 'finance_officer');
  const financeSecondUser = actorByName(actors, 'finance_officer__2');
  const vpUser = actorByName(actors, 'vice_president');
  const observerUser = actorByName(actors, 'hr_officer');
  const journey = createJourneyContext('HR05-CASH-ADVANCE');
  const purpose = `${String(journey.values.runPrefix)} cash advance for process verification`;
  const amount = '1000.00';
  const periods = 2;
  const expectedApprovers = [departmentHeadUser.email, financeUser.email, vpUser.email];
  const financeRoleRecipients = [financeUser.email, financeSecondUser.email];
  const participants = [employeeUser.email, ...expectedApprovers, financeSecondUser.email, observerUser.email];
  const emailsBefore = await listMailpitMessages();
  const mailIdsBefore = new Set(emailsBefore.map((message) => message.ID));
  const employee = await actorPage(browser, employeeUser.actor);
  const departmentHead = await actorPage(browser, departmentHeadUser.actor);
  const finance = await actorPage(browser, financeUser.actor);
  const vp = await actorPage(browser, vpUser.actor);
  const observer = await actorPage(browser, observerUser.actor);

  try {
    // Keep startup requests bounded on the 4 GB runner. Actor contexts are all
    // isolated and ready, but load each role's app shell only when that role
    // owns the current step.
    await openEmployeeLoans(employee);
    await openLoansObserver(observer);

    await runProcessStep({
      ...plannedSteps[0],
      page: employee.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => {
        const applyButtons = employee.page.getByRole('button', { name: 'Apply for a loan', exact: true });
        await expect(applyButtons).toHaveCount(2);
        await applyButtons.first().click();
        const dialog = employee.page.getByRole('dialog', { name: 'Apply for a Loan' });
        await expect(dialog).toBeVisible();
        await dialog.getByRole('combobox', { name: /^Type/ }).selectOption('cash_advance');
        await dialog.getByLabel(/^Amount/).fill(amount);
        await dialog.getByLabel(/^Periods \(months\)/).fill(String(periods));
        await dialog.getByLabel('Reason (optional)', { exact: true }).fill(purpose);
        await expect(dialog.getByText('Estimated monthly deduction', { exact: true })).toBeVisible();
        await expect.poll(async () => {
          const content = await dialog.locator('span.font-mono.tabular-nums.font-medium.text-primary').last().textContent();
          return content?.trim() ?? '';
        }, { timeout: 15_000, message: 'amortization preview should render a calculated amount' }).not.toBe('…');
        const responsePromise = employee.page.waitForResponse((response) =>
          response.url().includes('/api/v1/hr/self-service/loans') && response.request().method() === 'POST',
        );
        await dialog.getByRole('button', { name: 'Submit request', exact: true }).click();
        const response = await responsePromise;
        expect(response.status(), 'Employee submits the cash advance in self-service').toBe(201);
        const responseBody = await response.json() as { data?: { id?: unknown } };
        const loanHashId = responseBody.data?.id;
        if (typeof loanHashId !== 'string') throw new Error('The successful self-service response did not include the public loan ID.');
        journey.values.loanHashId = loanHashId;
        await expect(dialog).toBeHidden();
      },
      verify: async () => {
        const loan = await waitForLoan(purpose, 'pending');
        expect(loan.loan_type).toBe('cash_advance');
        expect(loan.principal).toBe(amount);
        expect(loan.total_paid).toBe('0.00');
        expect(Number(loan.balance)).toBeGreaterThanOrEqual(Number(loan.principal));
        expect(loan.pay_periods_total).toBe(periods);
        expect(loan.pay_periods_remaining).toBe(periods);
        expect(loan.payment_count).toBe(0);
        expect(loan.disbursement_journal_entry_id).toBeNull();
        expect(loan.requester_email).toBe(employeeUser.email);
        const approvals = await approvalRows(loan.loan_id);
        expect(approvals.map(({ step_order, role_slug, action, approver_email }) => [step_order, role_slug, action, approver_email])).toEqual([
          [1, 'department_head', 'pending', null],
          [2, 'finance_officer', 'pending', null],
          [3, 'vice_president', 'pending', null],
        ]);
        journey.values.loanNo = loan.loan_no;
        journey.values.loanPurpose = purpose;
        journey.values.principal = loan.principal;
        journey.values.employeeId = loan.employee_id;
        const observerRow = observer.page.getByRole('row').filter({ hasText: loan.loan_no });
        await expect(observerRow, 'HR loan list should receive the new record without reload').toHaveCount(1);
        await observerRow.click();
        const hashId = String(journey.values.loanHashId);
        await expect(observer.page).toHaveURL(new RegExp(`/hr/loans/${hashId}(?:\\?|$)`));
        await expect(observer.page.getByRole('heading', { name: new RegExp(loan.loan_no) })).toContainText('Pending');
      },
      assertInvariants: async () => {
        const loan = (await loanSnapshot(purpose))[0];
        expect(loan).toBeTruthy();
        expect(await journalRows(loan.loan_id)).toHaveLength(0);
        const hashId = String(journey.values.loanHashId);
        await expect.poll(async () =>
          (await noticesForLoan(hashId)).filter((notice) => notice.type === 'loans.submitted').length,
        {
          timeout: 15_000,
          message: 'queued loan-submission FYI should reach the configured Finance audience',
        }).toBeGreaterThanOrEqual(financeRoleRecipients.length);
        const notices = await noticesForLoan(hashId);
        const submitted = notices.filter((notice) => notice.type === 'loans.submitted').map((notice) => notice.recipient_email);
        assertRecipientsExactly(submitted, financeRoleRecipients);
        const actionRequired = notices.filter((notice) => notice.type === 'approval.action_required').map((notice) => notice.recipient_email);
        expect(actionRequired.every((recipient) => recipient === departmentHeadUser.email)).toBe(true);
        journey.values.initialApprovalNotificationRecipients = actionRequired;
        expect(notices.filter((notice) => notice.type === 'approval.action_required').every((notice) => notice.link_to === `/hr/loans/${hashId}`)).toBe(true);
      },
    });

    const approvalActors: Array<{ actor: ActorPage; user: E2EActor; index: number }> = [
      { actor: departmentHead, user: departmentHeadUser, index: 1 },
      { actor: finance, user: financeUser, index: 2 },
      { actor: vp, user: vpUser, index: 3 },
    ];

    for (const item of approvalActors) {
      await runProcessStep({
        ...plannedSteps[item.index],
        page: item.actor.page,
        plannedSteps,
        context: journey,
        testInfo,
        action: async () => {
          const before = await waitForLoan(purpose, 'pending');
          if (item.index === 1) {
            await openApprovalQueue(item.actor, before, String(journey.values.loanHashId));
          } else {
            await openDashboard(item.actor);
            await waitForNotification(item.actor, before, String(journey.values.loanHashId));
          }
          await expect(item.actor.page.getByRole('button', { name: 'Reject', exact: true })).toBeEnabled();
          await approveThroughUi(item.actor, String(journey.values.loanHashId));
        },
        verify: async () => {
          const isFinal = item.index === 3;
          const expectedStatus = isFinal ? 'active' : 'pending';
          const loan = await waitForLoan(purpose, expectedStatus);
          const approvals = await approvalRows(loan.loan_id);
          expect(approvals).toHaveLength(3);
          for (let index = 0; index < approvals.length; index += 1) {
            const approval = approvals[index];
            if (index < item.index) {
              expect(approval.action).toBe('approved');
              expect(approval.approver_email).toBe(expectedApprovers[index]);
            } else {
              expect(approval.action).toBe('pending');
              expect(approval.approver_email).toBeNull();
            }
          }
          if (isFinal) {
            expect(loan.disbursement_journal_entry_id).not.toBeNull();
            expect(loan.payment_count).toBe(0);
            const observerStatus = observer.page.getByRole('heading', { name: new RegExp(loan.loan_no) }).getByText('Active', { exact: true });
            const activeLoans = employee.page.getByRole('region', { name: 'Active loans' });
            const employeeRow = activeLoans.getByRole('row').filter({
              has: employee.page.getByText('Cash Advance', { exact: true }),
            });
            await expect(employeeRow, 'the Employee self-service list should show one Cash Advance row').toHaveCount(1);
            await assertSecondViewerRealtime(observerStatus, 'Active');
            await assertSecondViewerRealtime(employeeRow.getByText('Active', { exact: true }), 'Active');
            await expect(observer.page.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
            await expect(employee.page.getByRole('button', { name: /^Withdraw Cash Advance$/ })).toHaveCount(0);
          } else {
            expect(loan.disbursement_journal_entry_id).toBeNull();
            await expect(observer.page.getByRole('heading', { name: new RegExp(loan.loan_no) })).toContainText('Pending');
          }
        },
        assertInvariants: async () => {
          const loan = (await loanSnapshot(purpose))[0];
          const approvals = await approvalRows(loan.loan_id);
          const audits = await auditRows(loan.loan_id);
          const currentApproval = approvals[item.index - 1];
          expect(currentApproval.action).toBe('approved');
          expect(currentApproval.approver_email).toBe(item.user.email);
          const actorAudit = audits.find((entry) => entry.actor_email === item.user.email && entry.action === 'updated');
          expect(actorAudit, 'the acting role should be the loan audit actor for its approval').toBeTruthy();
          assertAuditActor({ actor_email: actorAudit!.actor_email ?? undefined }, item.user.email);
          const hashId = String(journey.values.loanHashId);
          if (item.index === 3) {
            await expect.poll(async () =>
              (await noticesForLoan(hashId)).filter((notice) => notice.type === 'loans.approved').length,
            {
              timeout: 15_000,
              message: 'queued loan outcome notice should reach the Employee',
            }).toBeGreaterThanOrEqual(1);
          }
          const notices = await noticesForLoan(hashId);
          const actionRequired = notices.filter((notice) => notice.type === 'approval.action_required');
          const expectedActionRecipients = [
            ...(item.index >= 1 ? financeRoleRecipients : []),
            ...(item.index >= 2 ? [vpUser.email] : []),
          ];
          assertRecipientsExactly(actionRequired.map((notice) => notice.recipient_email), expectedActionRecipients);
          expect(actionRequired.every((notice) => notice.link_to === `/hr/loans/${hashId}`)).toBe(true);
          if (item.index === 3) {
            const outcomes = notices.filter((notice) => notice.type === 'loans.approved').map((notice) => notice.recipient_email);
            assertRecipientsExactly(outcomes, [employeeUser.email]);
            expect(notices.filter((notice) => notice.type === 'approval.completed')).toHaveLength(0);
            const journals = await journalRows(loan.loan_id);
            expect(journals).toHaveLength(1);
            expect(journals[0].status).toBe('posted');
            expect(journals[0].created_by_email).toBeNull();
            expect(journals[0].posted_by_email).toBe(vpUser.email);
            expect(Number(journals[0].total_debit)).toBe(Number(journals[0].total_credit));
            expect(Number(journals[0].line_debit)).toBe(Number(journals[0].line_credit));
          } else {
            expect(await journalRows(loan.loan_id)).toHaveLength(0);
          }
        },
      });
    }

    const finalLoan = await waitForLoan(purpose, 'active');
    const emailsAfter = await listMailpitMessages();
    const newParticipantEmails = emailsAfter.filter((message) =>
      !mailIdsBefore.has(message.ID)
      && message.To.some((recipient) => participants.includes(recipient.Address.toLowerCase())),
    );
    expect(newParticipantEmails, 'loan request and approval hand-offs are in-app notifications, with no email template configured').toHaveLength(0);
    expect(finalLoan.pay_periods_remaining).toBe(periods);
    await Promise.all([employee.diagnostics.assertClean(), departmentHead.diagnostics.assertClean(), finance.diagnostics.assertClean(), vp.diagnostics.assertClean(), observer.diagnostics.assertClean()]);
  } finally {
    await Promise.all([employee.context.close(), departmentHead.context.close(), finance.context.close(), vp.context.close(), observer.context.close()]);
  }
});

test('HR05-REJECT — Department Head rejects a cash advance and the remaining approval steps stop', async ({ browser }, testInfo) => {
  test.setTimeout(150_000);
  const actors = await loadActors();
  const employeeUser = actorByName(actors, 'employee');
  const departmentHeadUser = actorByName(actors, 'department_head');
  const financeUser = actorByName(actors, 'finance_officer');
  const financeSecondUser = actorByName(actors, 'finance_officer__2');
  const observerUser = actorByName(actors, 'hr_officer');
  const journey = createJourneyContext('HR05-REJECT');
  const purpose = `${String(journey.values.runPrefix)} cash advance rejection verification`;
  const reason = `E2E rejection: ${String(journey.values.runPrefix)} missing business justification`;
  const amount = '1000.00';
  const periods = 2;
  const financeRoleRecipients = [financeUser.email, financeSecondUser.email];
  const participants = [employeeUser.email, departmentHeadUser.email, ...financeRoleRecipients, observerUser.email];
  const plannedSteps = [
    { name: 'Employee submits a cash advance request', actor: 'employee', uiPath: 'Profile menu → Loans → Apply for a loan → Cash Advance', expectedStatus: 'pending; Department Head is the current approval step; no journal entry' },
    { name: 'Department Head finds and rejects the request', actor: 'department_head', uiPath: 'Sidebar → Approval Queue → My action required → Cash Advance loan → Reject with reason', expectedStatus: 'rejected; later approval steps skipped; Employee receives outcome notices; no journal entry' },
  ];
  const emailsBefore = await listMailpitMessages();
  const mailIdsBefore = new Set(emailsBefore.map((message) => message.ID));
  const employee = await actorPage(browser, employeeUser.actor);
  const departmentHead = await actorPage(browser, departmentHeadUser.actor);
  const observer = await actorPage(browser, observerUser.actor);

  try {
    await openEmployeeLoans(employee);
    await openLoansObserver(observer);

    await runProcessStep({
      ...plannedSteps[0],
      page: employee.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => {
        const applyButtons = employee.page.getByRole('button', { name: 'Apply for a loan', exact: true });
        await expect(applyButtons).toHaveCount(2);
        await applyButtons.first().click();
        const dialog = employee.page.getByRole('dialog', { name: 'Apply for a Loan' });
        await expect(dialog).toBeVisible();
        await dialog.getByRole('combobox', { name: /^Type/ }).selectOption('cash_advance');
        await dialog.getByLabel(/^Amount/).fill(amount);
        await dialog.getByLabel(/^Periods \(months\)/).fill(String(periods));
        await dialog.getByLabel('Reason (optional)', { exact: true }).fill(purpose);
        await expect(dialog.getByText('Estimated monthly deduction', { exact: true })).toBeVisible();
        await expect.poll(async () => {
          const content = await dialog.locator('span.font-mono.tabular-nums.font-medium.text-primary').last().textContent();
          return content?.trim() ?? '';
        }, { timeout: 15_000, message: 'amortization preview should render a calculated amount' }).not.toBe('…');
        const responsePromise = employee.page.waitForResponse((response) =>
          response.url().includes('/api/v1/hr/self-service/loans') && response.request().method() === 'POST',
        );
        await dialog.getByRole('button', { name: 'Submit request', exact: true }).click();
        const response = await responsePromise;
        expect(response.status(), 'Employee submits the cash advance in self-service').toBe(201);
        const responseBody = await response.json() as { data?: { id?: unknown } };
        const loanHashId = responseBody.data?.id;
        if (typeof loanHashId !== 'string') throw new Error('The successful self-service response did not include the public loan ID.');
        journey.values.loanHashId = loanHashId;
        await expect(dialog).toBeHidden();
      },
      verify: async () => {
        const loan = await waitForLoan(purpose, 'pending');
        expect(loan.loan_type).toBe('cash_advance');
        expect(loan.principal).toBe(amount);
        expect(loan.pay_periods_total).toBe(periods);
        expect(loan.disbursement_journal_entry_id).toBeNull();
        expect(loan.requester_email).toBe(employeeUser.email);
        const approvals = await approvalRows(loan.loan_id);
        expect(approvals.map(({ step_order, role_slug, action, approver_email }) => [step_order, role_slug, action, approver_email])).toEqual([
          [1, 'department_head', 'pending', null],
          [2, 'finance_officer', 'pending', null],
          [3, 'vice_president', 'pending', null],
        ]);
        journey.values.loanNo = loan.loan_no;
        journey.values.loanPurpose = purpose;
        journey.values.principal = loan.principal;
        const observerRow = observer.page.getByRole('row').filter({ hasText: loan.loan_no });
        await expect(observerRow, 'HR loan list should receive the new record without reload').toHaveCount(1);
        await observerRow.click();
        const hashId = String(journey.values.loanHashId);
        await expect(observer.page).toHaveURL(new RegExp(`/hr/loans/${hashId}(?:\\?|$)`));
        await expect(observer.page.getByRole('heading', { name: new RegExp(loan.loan_no) })).toContainText('Pending');
        const employeeRow = employee.page.getByRole('region', { name: 'Active loans' }).getByRole('row').filter({ has: employee.page.getByText('Cash Advance', { exact: true }) });
        await expect(employeeRow, 'Employee self-service should show the pending Cash Advance').toHaveCount(1);
      },
      assertInvariants: async () => {
        const loan = (await loanSnapshot(purpose))[0];
        expect(loan).toBeTruthy();
        expect(await journalRows(loan.loan_id)).toHaveLength(0);
        const hashId = String(journey.values.loanHashId);
        await expect.poll(async () =>
          (await noticesForLoan(hashId)).filter((notice) => notice.type === 'loans.submitted').length,
        { timeout: 15_000, message: 'queued loan-submission FYI should reach configured Finance recipients' }).toBeGreaterThanOrEqual(financeRoleRecipients.length);
        const notices = await noticesForLoan(hashId);
        assertRecipientsExactly(notices.filter((notice) => notice.type === 'loans.submitted').map((notice) => notice.recipient_email), financeRoleRecipients);
        assertRecipientsExactly(notices.filter((notice) => notice.type === 'approval.action_required').map((notice) => notice.recipient_email), []);
      },
    });

    await runProcessStep({
      ...plannedSteps[1],
      page: departmentHead.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => {
        const before = await waitForLoan(purpose, 'pending');
        await openApprovalQueue(departmentHead, before, String(journey.values.loanHashId));
        const rejectButton = departmentHead.page.getByRole('button', { name: 'Reject', exact: true });
        await expect(rejectButton).toBeEnabled();
        await rejectButton.click();
        const dialog = departmentHead.page.getByRole('dialog', { name: 'Reject loan request' });
        await expect(dialog).toBeVisible();
        const reasonField = dialog.getByRole('textbox', { name: /Reason for rejection/ });
        await expect(reasonField).toHaveAttribute('required', '');
        const confirmButton = dialog.getByRole('button', { name: 'Confirm reject', exact: true });
        await expect(confirmButton, 'a rejection reason is required before the decision can be submitted').toBeDisabled();
        await reasonField.fill(reason);
        await expect(confirmButton).toBeEnabled();
        const responsePromise = departmentHead.page.waitForResponse((response) =>
          response.request().method() === 'PATCH'
            && response.url().includes(`/api/v1/loans/${String(journey.values.loanHashId)}/reject`),
        );
        await confirmButton.click();
        const response = await responsePromise;
        expect(response.status(), 'Department Head submits the rejection through the loan UI').toBe(200);
        await expect(dialog).toBeHidden();
      },
      verify: async () => {
        const loan = await waitForLoan(purpose, 'rejected');
        expect(loan.disbursement_journal_entry_id).toBeNull();
        expect(loan.payment_count).toBe(0);
        const approvals = await approvalRows(loan.loan_id);
        expect(approvals).toHaveLength(3);
        expect(approvals.map(({ step_order, role_slug, action, approver_email, remarks }) => [step_order, role_slug, action, approver_email, remarks])).toEqual([
          [1, 'department_head', 'rejected', departmentHeadUser.email, reason],
          [2, 'finance_officer', 'skipped', null, null],
          [3, 'vice_president', 'skipped', null, null],
        ]);
        const hrStatus = observer.page.getByRole('heading', { name: new RegExp(loan.loan_no) }).getByText('Rejected', { exact: true });
        await assertSecondViewerRealtime(hrStatus, 'Rejected');
        const history = employee.page.getByRole('region', { name: 'Loan history' });
        const historyRow = history.getByRole('row').filter({ has: employee.page.getByText('Cash Advance', { exact: true }) });
        await expect(historyRow, 'Employee self-service should move the rejected request into history without reload').toHaveCount(1);
        await assertSecondViewerRealtime(historyRow.getByText('Rejected', { exact: true }), 'Rejected');
        await expect(employee.page.getByRole('button', { name: /^Withdraw Cash Advance$/ })).toHaveCount(0);
      },
      assertInvariants: async () => {
        const loan = (await loanSnapshot(purpose))[0];
        const approvals = await approvalRows(loan.loan_id);
        const audits = await auditRows(loan.loan_id);
        expect(approvals[0].action).toBe('rejected');
        expect(approvals[0].approver_email).toBe(departmentHeadUser.email);
        const actorAudit = audits.find((entry) => entry.actor_email === departmentHeadUser.email && entry.action === 'updated');
        expect(actorAudit, 'the rejecting role should be the loan audit actor').toBeTruthy();
        assertAuditActor({ actor_email: actorAudit!.actor_email ?? undefined }, departmentHeadUser.email);
        const hashId = String(journey.values.loanHashId);
        for (const type of ['approval.rejected', 'loans.rejected']) {
          await expect.poll(async () => (await noticesForLoan(hashId)).filter((notice) => notice.type === type).length,
            { timeout: 15_000, message: `${type} outcome notification should reach the Employee` }).toBeGreaterThanOrEqual(1);
        }
        const notices = await noticesForLoan(hashId);
        for (const type of ['approval.rejected', 'loans.rejected']) {
          const outcome = notices.filter((notice) => notice.type === type);
          assertRecipientsExactly(outcome.map((notice) => notice.recipient_email), [employeeUser.email]);
        }
        expect(notices.filter((notice) => notice.type === 'approval.action_required')).toHaveLength(0);
        expect(notices.filter((notice) => ['approval.completed', 'loans.approved'].includes(notice.type))).toHaveLength(0);
        expect(await journalRows(loan.loan_id)).toHaveLength(0);
        const emailsAfter = await listMailpitMessages();
        const newParticipantEmails = emailsAfter.filter((message) =>
          !mailIdsBefore.has(message.ID)
          && message.To.some((recipient) => participants.includes(recipient.Address.toLowerCase())),
        );
        expect(newParticipantEmails, 'rejection and approval hand-offs use in-app notices, with no participant email').toHaveLength(0);
      },
    });

    await Promise.all([employee.diagnostics.assertClean(), departmentHead.diagnostics.assertClean(), observer.diagnostics.assertClean()]);
  } finally {
    await Promise.all([employee.context.close(), departmentHead.context.close(), observer.context.close()]);
  }
});

type CompanyLoanThresholdVariant = {
  journeyId: string;
  employeeActor: string;
  amount: string;
  requiresVp: boolean;
  expectManualRepaymentGap?: boolean;
};

async function runCompanyLoanThresholdJourney(
  browser: Browser,
  testInfo: TestInfo,
  variant: CompanyLoanThresholdVariant,
): Promise<void> {
  test.setTimeout(180_000);
  const actors = await loadActors();
  const employeeUser = actorByName(actors, variant.employeeActor);
  const departmentHeadUser = actorByName(actors, 'department_head');
  const hrUser = actorByName(actors, 'hr_officer');
  const financeUser = actorByName(actors, 'finance_officer');
  const financeSecondUser = actorByName(actors, 'finance_officer__2');
  const vpUser = actorByName(actors, 'vice_president');
  const journey = createJourneyContext(variant.journeyId);
  const purpose = `${String(journey.values.runPrefix)} ${variant.journeyId.toLowerCase()} company loan`;
  const amount = variant.amount;
  const finalApprovalStep = variant.requiresVp ? 4 : 3;
  const periods = 2;
  const financeRoleRecipients = [financeUser.email, financeSecondUser.email];
  const participants = [employeeUser.email, departmentHeadUser.email, hrUser.email, ...financeRoleRecipients, vpUser.email];
  const plannedSteps = [
    { name: `Employee submits a company loan for ₱${amount}`, actor: employeeUser.actor, uiPath: 'Profile menu → Loans → Apply for a loan → Company Loan', expectedStatus: `pending; Department Head is current; VP ${variant.requiresVp ? 'remains pending' : 'threshold step is skipped at submission'}; no journal` },
    { name: 'Department Head finds and approves the request', actor: 'department_head', uiPath: 'Sidebar → Approval Queue → My action required → Company Loan → Approve', expectedStatus: `pending; HR Officer is current; VP ${variant.requiresVp ? 'remains pending' : 'remains skipped'}` },
    { name: 'HR Officer follows the approval hand-off and approves', actor: 'hr_officer', uiPath: 'Notification bell → Approval needed notice → Approve', expectedStatus: `pending; Finance is current; VP ${variant.requiresVp ? 'remains pending' : 'remains skipped'}` },
    { name: 'Finance follows the approval hand-off and approves', actor: 'finance_officer', uiPath: 'Notification bell → Approval needed notice → Approve', expectedStatus: variant.requiresVp ? 'pending; VP is current; no journal yet' : 'active; VP remains skipped; one balanced disbursement journal; Employee is notified' },
    ...(variant.requiresVp ? [{ name: 'Vice President follows the approval hand-off and completes the loan', actor: 'vice_president', uiPath: 'Notification bell → Approval needed notice → Approve', expectedStatus: 'active; one balanced disbursement journal; Employee is notified' }] : []),
    ...(variant.expectManualRepaymentGap ? [
      { name: 'Finance opens the active loan and checks repayment actions', actor: 'finance_officer', uiPath: 'Sidebar → Human Resources → Employee Loans → active loan', expectedStatus: 'active; no repayment recorded; detail shows current balance' },
      { name: 'Finance records a partial manual repayment', actor: 'finance_officer', uiPath: 'Employee Loans → active loan → Record payment', expectedStatus: 'active; payment ledger, reduced balance and balanced repayment journal' },
      { name: 'Finance settles the remaining balance through the UI', actor: 'finance_officer', uiPath: 'Employee Loans → active loan → Record payment', expectedStatus: 'paid; zero outstanding balance and payment ledger reconciles' },
    ] : []),
  ];
  const emailsBefore = await listMailpitMessages();
  const mailIdsBefore = new Set(emailsBefore.map((message) => message.ID));
  const employee = await actorPage(browser, employeeUser.actor);
  const departmentHead = await actorPage(browser, departmentHeadUser.actor);
  const hr = await actorPage(browser, hrUser.actor);
  const finance = await actorPage(browser, financeUser.actor);
  const vp = await actorPage(browser, vpUser.actor);

  try {
    await openEmployeeLoans(employee);

    await runProcessStep({
      ...plannedSteps[0],
      page: employee.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async () => {
        const applyButtons = employee.page.getByRole('button', { name: 'Apply for a loan', exact: true });
        await expect(applyButtons).toHaveCount(2);
        await applyButtons.first().click();
        const dialog = employee.page.getByRole('dialog', { name: 'Apply for a Loan' });
        await expect(dialog).toBeVisible();
        await dialog.getByRole('combobox', { name: /^Type/ }).selectOption('company_loan');
        await dialog.getByLabel(/^Amount/).fill(amount);
        await dialog.getByLabel(/^Periods \(months\)/).fill(String(periods));
        await dialog.getByLabel('Reason (optional)', { exact: true }).fill(purpose);
        await expect(dialog.getByText('Estimated monthly deduction', { exact: true })).toBeVisible();
        await expect.poll(async () => {
          const content = await dialog.locator('span.font-mono.tabular-nums.font-medium.text-primary').last().textContent();
          return content?.trim() ?? '';
        }, { timeout: 15_000, message: 'company-loan amortization preview should render a calculated amount' }).not.toBe('…');
        const responsePromise = employee.page.waitForResponse((response) =>
          response.url().includes('/api/v1/hr/self-service/loans') && response.request().method() === 'POST',
        );
        await dialog.getByRole('button', { name: 'Submit request', exact: true }).click();
        const response = await responsePromise;
        expect(response.status(), 'Employee submits the company loan through self-service').toBe(201);
        const responseBody = await response.json() as { data?: { id?: unknown } };
        const loanHashId = responseBody.data?.id;
        if (typeof loanHashId !== 'string') throw new Error('The successful company-loan response did not include the public loan ID.');
        journey.values.loanHashId = loanHashId;
        await expect(dialog).toBeHidden();
      },
      verify: async () => {
        const loan = await waitForLoan(purpose, 'pending');
        expect(loan.loan_type).toBe('company_loan');
        expect(loan.principal).toBe(amount);
        expect(loan.total_paid).toBe('0.00');
        expect(loan.balance).toBe(amount);
        expect(loan.pay_periods_total).toBe(periods);
        expect(loan.pay_periods_remaining).toBe(periods);
        expect(loan.payment_count).toBe(0);
        expect(loan.disbursement_journal_entry_id).toBeNull();
        expect(loan.requester_email).toBe(employeeUser.email);
        const approvals = await approvalRows(loan.loan_id);
        expect(approvals.map(({ step_order, role_slug, action, approver_email }) => [step_order, role_slug, action, approver_email])).toEqual([
          [1, 'department_head', 'pending', null],
          [2, 'hr_officer', 'pending', null],
          [3, 'finance_officer', 'pending', null],
          [4, 'vice_president', variant.requiresVp ? 'pending' : 'skipped', null],
        ]);
        journey.values.loanNo = loan.loan_no;
        journey.values.loanPurpose = purpose;
        journey.values.principal = loan.principal;
        journey.values.employeeId = loan.employee_id;
        const employeeRow = employee.page.getByRole('region', { name: 'Active loans' }).getByRole('row').filter({
          has: employee.page.getByText('Company Loan', { exact: true }),
        });
        await expect(employeeRow, 'Employee self-service should show the pending Company Loan').toHaveCount(1);
        await expect(employeeRow).toContainText('Pending');
      },
      assertInvariants: async () => {
        const loan = (await loanSnapshot(purpose))[0];
        expect(loan).toBeTruthy();
        expect(loan.principal).toBe(amount);
        expect(await journalRows(loan.loan_id)).toHaveLength(0);
        const hashId = String(journey.values.loanHashId);
        await expect.poll(async () =>
          (await noticesForLoan(hashId)).filter((notice) => notice.type === 'loans.submitted').length,
        { timeout: 15_000, message: 'queued submission FYI should reach the Finance role audience' }).toBeGreaterThanOrEqual(financeRoleRecipients.length);
        const notices = await noticesForLoan(hashId);
        assertRecipientsExactly(notices.filter((notice) => notice.type === 'loans.submitted').map((notice) => notice.recipient_email), financeRoleRecipients);
        assertRecipientsExactly(
          notices.filter((notice) => notice.type === 'approval.action_required' && notice.dedupe_key?.endsWith(':1')).map((notice) => notice.recipient_email),
          [],
        );
        expect(notices.every((notice) => notice.link_to === `/hr/loans/${hashId}`)).toBe(true);
        expect(notices.some((notice) => notice.recipient_email === vpUser.email)).toBe(false);
      },
    });

    const approvalActors: Array<{ actor: ActorPage; user: E2EActor; stepIndex: number }> = [
      { actor: departmentHead, user: departmentHeadUser, stepIndex: 1 },
      { actor: hr, user: hrUser, stepIndex: 2 },
      { actor: finance, user: financeUser, stepIndex: 3 },
      ...(variant.requiresVp ? [{ actor: vp, user: vpUser, stepIndex: 4 }] : []),
    ];

    for (const item of approvalActors) {
      await runProcessStep({
        ...plannedSteps[item.stepIndex],
        page: item.actor.page,
        plannedSteps,
        context: journey,
        testInfo,
        action: async () => {
          const before = await waitForLoan(purpose, 'pending');
          if (item.stepIndex === 1) {
            await openApprovalQueue(item.actor, before, String(journey.values.loanHashId));
          } else {
            await openDashboard(item.actor);
            await waitForNotification(item.actor, before, String(journey.values.loanHashId));
          }
          await expect(item.actor.page.getByRole('button', { name: 'Approve', exact: true })).toBeEnabled();
          await approveThroughUi(item.actor, String(journey.values.loanHashId));
        },
        verify: async () => {
          const isFinal = item.stepIndex === finalApprovalStep;
          const expectedStatus = isFinal ? 'active' : 'pending';
          const loan = await waitForLoan(purpose, expectedStatus);
          const approvals = await approvalRows(loan.loan_id);
          expect(approvals.map(({ step_order, role_slug, action, approver_email }) => [step_order, role_slug, action, approver_email])).toEqual([
            [1, 'department_head', 'approved', departmentHeadUser.email],
            [2, 'hr_officer', item.stepIndex >= 2 ? 'approved' : 'pending', item.stepIndex >= 2 ? hrUser.email : null],
            [3, 'finance_officer', item.stepIndex >= 3 ? 'approved' : 'pending', item.stepIndex >= 3 ? financeUser.email : null],
            [4, 'vice_president', variant.requiresVp ? (item.stepIndex >= 4 ? 'approved' : 'pending') : 'skipped', variant.requiresVp && item.stepIndex >= 4 ? vpUser.email : null],
          ]);
          if (isFinal) {
            expect(loan.disbursement_journal_entry_id).not.toBeNull();
            expect(loan.payment_count).toBe(0);
            const employeeRow = employee.page.getByRole('region', { name: 'Active loans' }).getByRole('row').filter({
              has: employee.page.getByText('Company Loan', { exact: true }),
            });
            await expect(employeeRow).toHaveCount(1);
            await assertSecondViewerRealtime(employeeRow.getByText('Active', { exact: true }), 'Active');
            await expect(item.actor.page.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
          } else {
            expect(loan.disbursement_journal_entry_id).toBeNull();
          }
          await expect(item.actor.page.getByRole('button', { name: 'Reject', exact: true })).toHaveCount(0);

          if (item.stepIndex === 3 && !variant.requiresVp) {
            await openDashboard(vp);
            const nav = vp.page.getByRole('navigation', { name: 'Modules' });
            const queueLink = nav.getByRole('link', { name: 'Approval Queue', exact: true });
            await expect(queueLink, 'VP should have access to their approval queue').toBeVisible();
            await queueLink.click();
            await expect(vp.page.getByRole('heading', { name: 'Approvals', exact: true })).toBeVisible();
            await expect(vp.page.getByRole('button').filter({ hasText: loan.loan_no }), 'the skipped threshold step must not appear as VP work').toHaveCount(0);
          }
        },
        assertInvariants: async () => {
          const loan = (await loanSnapshot(purpose))[0];
          const approvals = await approvalRows(loan.loan_id);
          const audits = await auditRows(loan.loan_id);
          const currentApproval = approvals[item.stepIndex - 1];
          expect(currentApproval.action).toBe('approved');
          expect(currentApproval.approver_email).toBe(item.user.email);
          const actorAudit = audits.find((entry) => entry.actor_email === item.user.email && entry.action === 'updated');
          expect(actorAudit, 'the acting role should be the loan audit actor for its approval').toBeTruthy();
          assertAuditActor({ actor_email: actorAudit!.actor_email ?? undefined }, item.user.email);
          const hashId = String(journey.values.loanHashId);
          const notices = await noticesForLoan(hashId);
          const stepRecipients = new Map<number, string[]>([
            [1, []],
            [2, [hrUser.email]],
            [3, financeRoleRecipients],
            [4, variant.requiresVp ? [vpUser.email] : []],
          ]);
          for (const [stepOrder, expectedRecipients] of stepRecipients) {
            assertRecipientsExactly(
              notices.filter((notice) => notice.type === 'approval.action_required' && notice.dedupe_key?.endsWith(`:${stepOrder}`)).map((notice) => notice.recipient_email),
              stepOrder <= item.stepIndex + 1 ? expectedRecipients : [],
            );
          }
          if (variant.requiresVp && item.stepIndex >= 3) {
            expect(notices.filter((notice) => notice.type === 'approval.action_required' && notice.recipient_email === vpUser.email)).toHaveLength(1);
          } else {
            expect(notices.filter((notice) => notice.type === 'approval.action_required' && notice.recipient_email === vpUser.email)).toHaveLength(0);
          }
          expect(notices.filter((notice) => notice.type === 'approval.action_required').every((notice) => notice.link_to === `/hr/loans/${hashId}`)).toBe(true);
          if (item.stepIndex === finalApprovalStep) {
            await expect.poll(async () =>
              (await noticesForLoan(hashId)).filter((notice) => notice.type === 'loans.approved').length,
            { timeout: 15_000, message: 'queued company-loan outcome notice should reach the Employee' }).toBeGreaterThanOrEqual(1);
            const finalNotices = await noticesForLoan(hashId);
            const outcomes = finalNotices.filter((notice) => notice.type === 'loans.approved').map((notice) => notice.recipient_email);
            assertRecipientsExactly(outcomes, [employeeUser.email]);
            expect(finalNotices.filter((notice) => notice.type === 'approval.completed')).toHaveLength(0);
            const journals = await journalRows(loan.loan_id);
            expect(journals).toHaveLength(1);
            expect(journals[0].status).toBe('posted');
            expect(journals[0].created_by_email).toBeNull();
            expect(journals[0].posted_by_email).toBe(item.user.email);
            expect(Number(journals[0].total_debit)).toBe(Number(journals[0].total_credit));
            expect(Number(journals[0].line_debit)).toBe(Number(journals[0].line_credit));
          } else {
            expect(await journalRows(loan.loan_id)).toHaveLength(0);
          }
        },
      });
    }

    const finalLoan = await waitForLoan(purpose, 'active');
    expect(finalLoan.pay_periods_remaining).toBe(periods);
    const emailsAfter = await listMailpitMessages();
    const newParticipantEmails = emailsAfter.filter((message) =>
      !mailIdsBefore.has(message.ID)
      && message.To.some((recipient) => participants.includes(recipient.Address.toLowerCase())),
    );
    expect(newParticipantEmails, 'company-loan submission and approval hand-offs are in-app notifications').toHaveLength(0);

    if (variant.expectManualRepaymentGap) {
      const loan = (await loanSnapshot(purpose))[0];
      expect(loan.status).toBe('active');
      expect(loan.payment_count).toBe(0);

      await runProcessStep({
        ...plannedSteps[plannedSteps.length - 3],
        page: finance.page,
        plannedSteps,
        context: journey,
        testInfo,
        action: async () => {
          await openSidebarItem(finance, 'Human Resources', '/hr/loans', 'Employee Loans');
          await expect(finance.page.getByRole('heading', { name: 'Loans & Cash Advance', exact: true })).toBeVisible();
          const row = finance.page.getByRole('row').filter({ hasText: loan.loan_no });
          await expect(row, 'Finance should find the active loan through the Loans list').toHaveCount(1);
          await row.click();
          await expect(finance.page.getByRole('heading', { name: new RegExp(loan.loan_no) })).toBeVisible();
          await expect(finance.page.getByText('Active', { exact: true })).toBeVisible();
          await expect(finance.page.getByText('No payments yet.', { exact: true })).toBeVisible();
          await expect(finance.page.getByRole('button', { name: /record payment|make payment/i })).toHaveCount(0);
        },
        verify: async () => {
          const current = (await loanSnapshot(purpose))[0];
          expect(current.status).toBe('active');
          expect(current.payment_count).toBe(0);
          expect(current.total_paid).toBe('0.00');
          expect(await journalRows(current.loan_id)).toHaveLength(1);
        },
        assertInvariants: async () => finance.diagnostics.assertClean('Finance views the active loan but has no manual repayment action'),
      });

      await runProcessStep({
        ...plannedSteps[plannedSteps.length - 2],
        page: finance.page,
        plannedSteps,
        context: journey,
        testInfo,
        action: async () => {
          journey.steps[journey.steps.length - 1].screenshot = await captureAnnotatedStep(
            finance.page,
            'finance_officer',
            'Manual repayment form is absent from the active loan detail',
            journey.steps.length,
            testInfo,
          );
          test.fixme(true, 'GAP-HR-05-03: the loan API and service accept manual repayments, but the Loans SPA detail exposes no Record payment action or payment form. Do not post a repayment through the API.');
        },
        verify: async () => {},
        assertInvariants: async () => finance.diagnostics.assertClean('partial repayment is blocked at the absent UI action'),
      });
    }

    await Promise.all([
      employee.diagnostics.assertClean(), departmentHead.diagnostics.assertClean(), hr.diagnostics.assertClean(),
      finance.diagnostics.assertClean(), vp.diagnostics.assertClean(),
    ]);
  } finally {
    await Promise.all([
      employee.context.close(), departmentHead.context.close(), hr.context.close(), finance.context.close(), vp.context.close(),
    ]);
  }
}

test('HR05-LOAN-UNDER-THRESHOLD — Company loan below ₱50,000 skips VP and completes through Finance', async ({ browser }, testInfo) => {
  await runCompanyLoanThresholdJourney(browser, testInfo, {
    journeyId: 'HR05-LOAN-UNDER-THRESHOLD', employeeActor: 'employee', amount: '29999.99', requiresVp: false,
  });
});

test('HR05-LOAN-AT-THRESHOLD — Company loan at ₱50,000 requires VP approval', async ({ browser }, testInfo) => {
  await runCompanyLoanThresholdJourney(browser, testInfo, {
    journeyId: 'HR05-LOAN-AT-THRESHOLD', employeeActor: 'employee__loan_threshold', amount: '50000.00', requiresVp: true,
  });
});

test('HR05-LOAN-OVER-THRESHOLD — Company loan above ₱50,000 requires VP approval', async ({ browser }, testInfo) => {
  await runCompanyLoanThresholdJourney(browser, testInfo, {
    journeyId: 'HR05-LOAN-OVER-THRESHOLD', employeeActor: 'employee__loan_threshold', amount: '50000.01', requiresVp: true,
  });
});

test('HR05-PARTIAL-PAYMENT — manual loan repayment is blocked by missing UI (GAP-HR-05-03)', async ({ browser }, testInfo) => {
  await runCompanyLoanThresholdJourney(browser, testInfo, {
    journeyId: 'HR05-PARTIAL-PAYMENT', employeeActor: 'employee', amount: '29999.99', requiresVp: false,
    expectManualRepaymentGap: true,
  });
});
