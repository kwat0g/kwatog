import { test, expect } from './fixtures';
import { actorPage, loadActors } from './actor-session';
import type { E2EActor } from './actor-session';
import { createJourneyContext, runProcessStep } from './process-runner';
import { assertAuditActor, assertPhpCurrency, assertRecipientsExactly } from './helpers/process-invariants';
import { readOnlyDatabaseJson } from './helpers/database-reader';
import { clearClock, freezeClock, readClock } from './helpers/clock';
import { getMailpitMessage, listMailpitMessages, type MailpitSummary } from './helpers/mailpit-reader';

type ActorPage = Awaited<ReturnType<typeof actorPage>>;
type ChecklistEntry = { department: string; item_key: string; label: string };
type ClearanceItem = ChecklistEntry & {
  status: 'pending' | 'cleared' | 'blocked';
  signed_by: number | null;
  signed_at: string | null;
  signed_by_email: string | null;
};
type ClearanceSnapshot = {
  clearance_id: number;
  clearance_no: string;
  employee_id: number;
  employee_no: string;
  employee_status: string;
  employee_user_active: boolean | null;
  status: string;
  separation_reason: string;
  separation_date: string;
  final_pay_computed: boolean;
  final_pay_amount: string | null;
  final_pay_breakdown: Record<string, string> | null;
  journal_entry_id: number | null;
  initiated_by_email: string;
  finalized_by_email: string | null;
  items: ClearanceItem[];
};
type AuditSnapshot = { action: string; actor_email: string | null; created_at: string };
type NotificationSnapshot = { type: string; recipient_email: string; link_to: string | null };
type JournalSnapshot = {
  entry_number: string;
  status: string;
  created_by_email: string | null;
  posted_by_email: string | null;
  total_debit: string;
  total_credit: string;
  line_debit: string;
  line_credit: string;
  line_count: number;
};
type HistorySnapshot = {
  change_type: string;
  status: string | null;
  separation_reason: string | null;
  actor_email: string | null;
};

const COMPLETE_SEPARATION_VARIANTS = [
  { id: 'HR08-VOLUNTARY', reason: 'resigned', employeeStatus: 'resigned', description: 'voluntary' },
  { id: 'HR08-TERMINATION', reason: 'terminated', employeeStatus: 'terminated', description: 'termination' },
] as const;

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
    timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit',
  }).formatToParts(new Date(instant));
  const values = Object.fromEntries(parts.map((part) => [part.type, part.value]));
  return `${values.year}-${values.month}-${values.day}`;
}

async function openSidebarItem(session: ActorPage, section: string, href: string, label: string): Promise<void> {
  const nav = session.page.getByRole('navigation', { name: 'Modules' });
  const target = nav.locator(`a[href="${href}"]`);
  await expect(target, `${label} should be available in this role's sidebar`).toHaveCount(1);
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
  const nav = session.page.getByRole('navigation', { name: 'Modules' });
  if (await nav.count() === 0) {
    // The authenticated session's first destination is its dashboard. After
    // that initial landing, all movement stays on sidebar links and in-app UI.
    await session.page.goto('/dashboard');
  } else if (new URL(session.page.url()).pathname !== '/dashboard') {
    await nav.locator('a[href="/dashboard"]').click();
  }
  await expect(nav).toBeVisible();
  await expect(session.page.getByRole('button', { name: /^Profile menu for / })).toBeVisible();
}

async function configuredChecklist(): Promise<ChecklistEntry[]> {
  const rows = await readOnlyDatabaseJson<Array<{ items: ChecklistEntry[] }>>(`
    SELECT value::jsonb AS items
      FROM settings
     WHERE key = 'hr.separation.clearance_checklist'
     LIMIT 1
  `);
  if (!rows[0]?.items?.length) throw new Error('The E2E baseline has no configured separation checklist.');
  const seen = new Set<string>();
  for (const item of rows[0].items) {
    if (!item.department?.trim() || !item.item_key?.trim() || !item.label?.trim() || seen.has(item.item_key)) {
      throw new Error(`The configured separation checklist has an invalid or duplicate item: ${JSON.stringify(item)}`);
    }
    seen.add(item.item_key);
  }
  return rows[0].items;
}

async function employeeBaseline(employeeEmail: string): Promise<Array<{
  employee_id: number; employee_no: string; first_name: string; last_name: string; status: string;
  department_name: string; user_active: boolean;
}>> {
  return readOnlyDatabaseJson(`
    SELECT employee.id AS employee_id, employee.employee_no,
           employee.first_name, employee.last_name, employee.status::text AS status,
           department.name AS department_name, actor.is_active AS user_active
      FROM users actor
      JOIN employees employee ON employee.id = actor.employee_id
      JOIN departments department ON department.id = employee.department_id
     WHERE actor.email = ${sqlText(employeeEmail)}
  `);
}

async function clearanceSnapshot(employeeNo: string, remarks: string): Promise<ClearanceSnapshot[]> {
  return readOnlyDatabaseJson(`
    SELECT clearance.id AS clearance_id, clearance.clearance_no,
           employee.id AS employee_id, employee.employee_no, employee.status::text AS employee_status,
           employee_user.is_active AS employee_user_active,
           clearance.status::text AS status, clearance.separation_reason,
           clearance.separation_date::text AS separation_date,
           clearance.final_pay_computed, clearance.final_pay_amount::text AS final_pay_amount,
           clearance.final_pay_breakdown,
           clearance.journal_entry_id,
           initiator.email AS initiated_by_email, finalizer.email AS finalized_by_email,
           clearance.clearance_items::json AS items
      FROM clearances clearance
      JOIN employees employee ON employee.id = clearance.employee_id
      JOIN users initiator ON initiator.id = clearance.initiated_by
      LEFT JOIN users employee_user ON employee_user.employee_id = employee.id
      LEFT JOIN users finalizer ON finalizer.id = clearance.finalized_by
     WHERE employee.employee_no = ${sqlText(employeeNo)}
       AND clearance.remarks = ${sqlText(remarks)}
     ORDER BY clearance.id DESC
     LIMIT 1
  `);
}

async function clearanceItems(clearanceId: number): Promise<ClearanceItem[]> {
  return readOnlyDatabaseJson(`
    SELECT item->>'department' AS department, item->>'item_key' AS item_key,
           item->>'label' AS label, item->>'status' AS status,
           NULLIF(item->>'signed_by', '')::bigint AS signed_by,
           item->>'signed_at' AS signed_at, signer.email AS signed_by_email
      FROM clearances clearance
      CROSS JOIN LATERAL jsonb_array_elements(clearance.clearance_items::jsonb) AS item
      LEFT JOIN users signer ON signer.id = NULLIF(item->>'signed_by', '')::bigint
     WHERE clearance.id = ${clearanceId}
     ORDER BY item->>'item_key'
  `);
}

async function clearanceAudits(clearanceId: number): Promise<AuditSnapshot[]> {
  return readOnlyDatabaseJson(`
    SELECT audit.action, actor.email AS actor_email, audit.created_at::text AS created_at
      FROM audit_logs audit
      LEFT JOIN users actor ON actor.id = audit.user_id
     WHERE audit.model_id = ${clearanceId}
       AND audit.model_type ILIKE '%Clearance'
     ORDER BY audit.id
  `);
}

function assertClearanceAuditActor(entry: AuditSnapshot | undefined, expectedEmail: string): void {
  assertAuditActor({ actor_email: entry?.actor_email ?? undefined }, expectedEmail);
}

async function separationNotifications(clearanceId: number): Promise<NotificationSnapshot[]> {
  return readOnlyDatabaseJson(`
    SELECT notice.type, recipient.email AS recipient_email,
           notice.data->>'link_to' AS link_to
      FROM notifications notice
      JOIN users recipient ON recipient.id = notice.notifiable_id
     WHERE notice.data->>'subject_type' ILIKE '%Clearance'
       AND notice.data->>'subject_id' = ${sqlText(String(clearanceId))}
       AND notice.type = 'chain.separation_initiated'
     ORDER BY recipient.email
  `);
}

async function clearanceNotifications(clearanceId: number): Promise<NotificationSnapshot[]> {
  return readOnlyDatabaseJson(`
    SELECT notice.type, recipient.email AS recipient_email,
           notice.data->>'link_to' AS link_to
      FROM notifications notice
      JOIN users recipient ON recipient.id = notice.notifiable_id
     WHERE notice.data->>'subject_type' ILIKE '%Clearance'
       AND notice.data->>'subject_id' = ${sqlText(String(clearanceId))}
     ORDER BY notice.type, recipient.email
  `);
}

async function expectedNotificationRecipients(clearanceId: number): Promise<string[]> {
  const rows = await readOnlyDatabaseJson<{ email: string }[]>(`
    SELECT DISTINCT recipient.email
      FROM clearances clearance
      JOIN employees employee ON employee.id = clearance.employee_id
      JOIN users recipient ON recipient.is_active = true AND recipient.id <> clearance.initiated_by
      JOIN roles role ON role.id = recipient.role_id
      JOIN settings configured ON configured.key = 'hr.separation.notification_roles'
      JOIN LATERAL jsonb_array_elements_text(configured.value::jsonb) allowed_role(slug)
        ON allowed_role.slug = role.slug
     WHERE clearance.id = ${clearanceId}
       AND EXISTS (
         SELECT 1 FROM role_permissions rp
         JOIN permissions permission ON permission.id = rp.permission_id
         WHERE rp.role_id = role.id AND permission.slug = 'hr.separation.view'
       )
       AND (
         EXISTS (
           SELECT 1 FROM role_permissions rp
           JOIN permissions permission ON permission.id = rp.permission_id
           WHERE rp.role_id = role.id AND permission.slug = 'hr.separation.view_all'
         )
         OR EXISTS (
           SELECT 1 FROM employees recipient_employee
            WHERE recipient_employee.id = recipient.employee_id
              AND recipient_employee.department_id = employee.department_id
         )
       )
       AND NOT EXISTS (
         SELECT 1 FROM notification_preferences preference
          WHERE preference.user_id = recipient.id
            AND preference.notification_type = 'chain.separation_initiated'
            AND preference.channel = 'in_app'
            AND preference.enabled = false
       )
     ORDER BY recipient.email
  `);
  return rows.map((row) => row.email);
}

async function expectedEmailRecipients(clearanceId: number): Promise<string[]> {
  const rows = await readOnlyDatabaseJson<{ email: string }[]>(`
    SELECT DISTINCT recipient.email
      FROM clearances clearance
      JOIN employees employee ON employee.id = clearance.employee_id
      JOIN users recipient ON recipient.is_active = true AND recipient.id <> clearance.initiated_by
      JOIN roles role ON role.id = recipient.role_id
      JOIN settings configured ON configured.key = 'hr.separation.notification_roles'
      JOIN LATERAL jsonb_array_elements_text(configured.value::jsonb) allowed_role(slug)
        ON allowed_role.slug = role.slug
      JOIN notification_preferences preference
        ON preference.user_id = recipient.id
       AND preference.notification_type = 'chain.separation_initiated'
       AND preference.channel = 'email'
       AND preference.enabled = true
     WHERE clearance.id = ${clearanceId}
       AND EXISTS (
         SELECT 1 FROM role_permissions rp
         JOIN permissions permission ON permission.id = rp.permission_id
         WHERE rp.role_id = role.id AND permission.slug = 'hr.separation.view'
       )
       AND (
         EXISTS (
           SELECT 1 FROM role_permissions rp
           JOIN permissions permission ON permission.id = rp.permission_id
           WHERE rp.role_id = role.id AND permission.slug = 'hr.separation.view_all'
         )
         OR EXISTS (
           SELECT 1 FROM employees recipient_employee
            WHERE recipient_employee.id = recipient.employee_id
              AND recipient_employee.department_id = employee.department_id
         )
       )
     ORDER BY recipient.email
  `);
  return rows.map((row) => row.email);
}

async function journalSnapshot(clearanceId: number): Promise<JournalSnapshot[]> {
  return readOnlyDatabaseJson(`
    SELECT entry.entry_number, entry.status,
           creator.email AS created_by_email, poster.email AS posted_by_email,
           entry.total_debit::text AS total_debit, entry.total_credit::text AS total_credit,
           COALESCE(SUM(line.debit), 0)::text AS line_debit,
           COALESCE(SUM(line.credit), 0)::text AS line_credit,
           COUNT(line.id)::int AS line_count
      FROM journal_entries entry
      JOIN clearances clearance ON clearance.journal_entry_id = entry.id
      LEFT JOIN users creator ON creator.id = entry.created_by
      LEFT JOIN users poster ON poster.id = entry.posted_by
      LEFT JOIN journal_entry_lines line ON line.journal_entry_id = entry.id
     WHERE clearance.id = ${clearanceId}
       AND entry.reference_type ILIKE '%Clearance'
       AND entry.reference_id = clearance.id
     GROUP BY entry.id, creator.email, poster.email
  `);
}

async function separationHistory(employeeId: number): Promise<HistorySnapshot[]> {
  return readOnlyDatabaseJson(`
    SELECT history.change_type,
           history.to_value->>'status' AS status,
           history.to_value->>'separation_reason' AS separation_reason,
           actor.email AS actor_email
      FROM employment_history history
      LEFT JOIN users actor ON actor.id = history.approved_by
     WHERE history.employee_id = ${employeeId}
       AND history.change_type = 'separated'
     ORDER BY history.id DESC
     LIMIT 1
  `);
}

async function waitForClearance(employeeNo: string, remarks: string, expectedStatus: string): Promise<ClearanceSnapshot> {
  let rows: ClearanceSnapshot[] = [];
  await expect.poll(async () => {
    rows = await clearanceSnapshot(employeeNo, remarks);
    return rows[0]?.status ?? 'missing';
  }, { timeout: 20_000, message: `clearance should reach ${expectedStatus}` }).toBe(expectedStatus);
  if (!rows[0]) throw new Error(`Clearance ${employeeNo}/${remarks} was not found.`);
  return rows[0];
}

async function openPendingClearanceFromSidebar(
  session: ActorPage,
  employeeNo: string,
  section = 'Human Resources',
): Promise<void> {
  await openDashboard(session);
  await openSidebarItem(session, section, '/hr/separations', 'Separation Clearance');
  const badge = session.page.getByRole('button', { name: /^View all Separation Clearance signals:/ });
  await expect(badge, 'the actor should receive a pending-clearance sidebar handoff').toBeVisible();
  await badge.click();
  const signalDialog = session.page.getByRole('dialog', { name: 'Separation Clearance signals' });
  const actionable = signalDialog.getByRole('link', {
    name: /Clearances with pending items you can sign: 1/,
  });
  await expect(actionable).toBeVisible();
  await actionable.click();
  await expect(session.page).toHaveURL(/\/hr\/separations\?signal=clearance_pending(?:&|$)/);
  await expect(session.page.getByRole('heading', { name: 'Separations & clearances', exact: true })).toBeVisible();
  const row = session.page.getByRole('row').filter({ hasText: employeeNo });
  await expect(row, `${employeeNo} should be found from the actor's actionable-clearance handoff`).toHaveCount(1);
  await row.click();
}

async function openClearanceFromList(
  session: ActorPage,
  employeeNo: string,
  section = 'Human Resources',
): Promise<void> {
  await openDashboard(session);
  await openSidebarItem(session, section, '/hr/separations', 'Separation Clearance');
  await expect(session.page.getByRole('heading', { name: 'Separations & clearances', exact: true })).toBeVisible();
  const row = session.page.getByRole('row').filter({ hasText: employeeNo });
  await expect(row, `${employeeNo} should be visible to this read-only observer`).toHaveCount(1);
  await row.click();
}

function itemActor(department: string): { actor: string; fallback: boolean } {
  const normalized = department.trim().toLowerCase();
  // Derived from RolePermissionSeeder.php and the E2EAccountSeeder department
  // bindings. HR is an explicit fallback signer for departments whose role
  // scoped row access does not expose this employee's clearance.
  const ownerByDepartment: Record<string, string> = {
    production: 'department_head',
    warehouse: 'warehouse_staff',
    maintenance: 'maintenance_tech',
    finance: 'finance_officer',
    hr: 'hr_officer',
    it: 'hr_officer',
  };
  const actor = ownerByDepartment[normalized];
  if (!actor) throw new Error(`No source-derived E2E clearance actor mapping exists for department ${department}.`);
  return { actor, fallback: normalized === 'it' };
}

async function assertRealtimeItem(observer: ActorPage, label: string): Promise<void> {
  const text = observer.page.getByText(label, { exact: true });
  await expect(text).toBeVisible();
  const itemRow = text.locator('..').locator('..');
  await expect(itemRow.getByText(/Cleared/), 'the already-open observer should show the completed item without refresh').toBeVisible();
}

async function assertInitiationEmails(clearanceId: number, startedAt: string): Promise<void> {
  const expected = await expectedEmailRecipients(clearanceId);
  const sinceMs = Date.parse(startedAt);
  let actual: MailpitSummary[] = [];
  const readMessages = async () => {
    const all = await listMailpitMessages(500);
    actual = all.filter((message) => message.Subject.endsWith('Separation Initiated')
      && Date.parse(message.Created) >= sinceMs);
    return actual.length;
  };
  if (expected.length > 0) {
    await expect.poll(readMessages, {
      timeout: 15_000, message: 'opted-in recipients should receive separation initiation email',
    }).toBe(expected.length);
  } else {
    expect(await readMessages(), 'no email should be sent to recipients who did not opt in').toBe(0);
  }
  const actualRecipients = [...new Set(actual.flatMap((message) => message.To
    .map((to) => to.Address.toLowerCase())))];
  assertRecipientsExactly(actualRecipients, expected);
  for (const message of actual) {
    const content = await getMailpitMessage(message.ID);
    const serialized = JSON.stringify(content);
    expect(serialized).toContain('Separation Initiated');
    expect(serialized).toContain('/hr/separations/');
  }
}

for (const variant of COMPLETE_SEPARATION_VARIANTS) {
test(`${variant.id} — ${variant.description} separation through departmental clearance and final pay`, async ({ browser, request }, testInfo) => {
  test.setTimeout(15 * 60 * 1000);
  const actors = await loadActors();
  const checklist = await configuredChecklist();
  const journey = createJourneyContext(variant.id);
  const hrActor = actorByName(actors, 'hr_officer');
  const employeeActor = actorByName(actors, 'employee');
  const remarks = `${journey.values.runPrefix} ${variant.description} separation`;
  const contexts = new Map<string, ActorPage>();
  const actorNames = new Set([
    'hr_officer', 'finance_officer', 'system_admin',
    'department_head', 'department_head__it', 'warehouse_staff', 'maintenance_tech',
  ]);
  for (const actorName of actorNames) actorByName(actors, actorName);

  const plannedSteps = [
    { name: `HR initiates ${variant.description} separation from the employee record`, actor: 'hr_officer', uiPath: 'Human Resources → Employees → employee → Separate → Start separation', expectedStatus: 'clearance in_progress; employee on_leave; employee account remains active' },
    { name: 'Finance follows the real separation notification', actor: 'finance_officer', uiPath: 'Notification bell → Separation Initiated → clearance', expectedStatus: 'clearance in_progress; only configured and authorized recipients notified' },
    { name: 'System Administrator opens a separate live observer view', actor: 'system_admin (read-only observer)', uiPath: 'Human Resources → Separation Clearance → employee row', expectedStatus: 'clearance in_progress; observer remains on the same record while other roles act' },
    ...checklist.map((item, index) => {
      const owner = itemActor(item.department);
      return {
        name: `${owner.fallback ? 'HR fallback signs' : owner.actor + ' signs'} ${item.department}: ${item.label}`,
        actor: owner.fallback ? 'department_head__it → hr_officer fallback' : owner.actor,
        uiPath: owner.fallback
          ? 'IT Department Head scope check → HR Separation Clearance badge → clearance → Sign'
          : 'Sidebar → Separation Clearance badge → pending-clearance signal → employee row → Sign',
        expectedStatus: index === checklist.length - 1
          ? 'last item cleared; clearance completed and account deactivation queued'
          : 'item cleared by the authorized department actor; clearance remains in_progress',
      };
    }),
    { name: 'HR computes final pay after clearance completion', actor: 'hr_officer', uiPath: 'Separation Clearance → Compute final pay', expectedStatus: 'clearance completed; final pay computed; no journal until finalization' },
    { name: 'HR finalizes and posts final pay', actor: 'hr_officer', uiPath: 'Separation Clearance → Finalize → confirm', expectedStatus: `clearance finalized; employee ${variant.employeeStatus}; final-pay journal posted and balanced; employee account deactivated` },
  ];

  let frozenClock = false;
  try {
    const initialClock = await readClock(request);
    await freezeClock(request, initialClock);
    frozenClock = true;
    const effectiveDate = manilaDate(initialClock);
    const targetRows = await employeeBaseline(employeeActor.email);
    expect(targetRows, 'the seeded employee actor should resolve to one reference Employee').toHaveLength(1);
    const target = targetRows[0];
    expect(target.status, 'the isolated E2E employee should start active').toBe('active');
    expect(target.user_active, 'the isolated E2E employee account should start enabled').toBe(true);
    expect(checklist.length, 'a separation cannot complete with an empty clearance checklist').toBeGreaterThan(0);
    journey.values.employeeNo = target.employee_no;
    journey.values.effectiveDate = effectiveDate;
    journey.values.checklist = checklist;

    for (const actorName of actorNames) contexts.set(actorName, await actorPage(browser, actorName));
    const hr = contexts.get('hr_officer')!;
    const finance = contexts.get('finance_officer')!;
    const adminObserver = contexts.get('system_admin')!;
    const itDepartmentHead = contexts.get('department_head__it')!;

    await runProcessStep({
      ...plannedSteps[0], page: hr.page, plannedSteps, context: journey, testInfo,
      action: async (page) => {
        await openDashboard(hr);
        await openSidebarItem(hr, 'Human Resources', '/hr/employees', 'Employees');
        await expect(page.getByRole('heading', { name: 'Employees', exact: true })).toBeVisible();
        await page.getByPlaceholder(/Search name or employee no/).fill(target.employee_no);
        const employeeRow = page.getByRole('row').filter({ hasText: target.employee_no });
        await expect(employeeRow).toHaveCount(1);
        await employeeRow.click();
        await expect(page.getByRole('heading', { name: /E2E employee/i })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Separate', exact: true })).toBeEnabled();
        await page.getByRole('button', { name: 'Separate', exact: true }).click();
        const modal = page.getByRole('dialog', { name: 'Separate employee' });
        await expect(modal).toBeVisible();
        await modal.getByLabel('Reason').selectOption(variant.reason);
        await modal.getByLabel('Effective date').fill(effectiveDate);
        await modal.getByLabel('Remarks').fill(remarks);
        const responseWait = page.waitForResponse((response) => response.request().method() === 'POST'
          && /\/employees\/[^/]+\/separation(?:\?|$)/.test(new URL(response.url()).pathname));
        await modal.getByRole('button', { name: 'Start separation', exact: true }).click();
        const response = await responseWait;
        expect(response.status(), 'HR initiation should create the clearance through the UI').toBe(201);
        await expect(page.getByText(/Separation initiated\. Complete clearance and final pay/)).toBeVisible();
      },
      verify: async () => {
        const clearance = await waitForClearance(target.employee_no, remarks, 'in_progress');
        expect(clearance.separation_reason).toBe(variant.reason);
        expect(clearance.separation_date).toBe(effectiveDate);
        expect(clearance.employee_status, 'initiation must place the employee on leave until finalization').toBe('on_leave');
        expect(clearance.employee_user_active, 'account access must remain enabled until all clearance items are complete').toBe(true);
        expect(clearance.items).toHaveLength(checklist.length);
        expect(clearance.items.every((item) => item.status === 'pending')).toBe(true);
        journey.values.clearanceId = clearance.clearance_id;
        journey.values.clearanceNo = clearance.clearance_no;
        const audits = await clearanceAudits(clearance.clearance_id);
        expect(audits[0]?.action).toBe('created');
        assertClearanceAuditActor(audits[0], hrActor.email);
        const expected = await expectedNotificationRecipients(clearance.clearance_id);
        let notices: NotificationSnapshot[] = [];
        await expect.poll(async () => {
          notices = await separationNotifications(clearance.clearance_id);
          return notices.length;
        }, { timeout: 20_000, message: 'the queued separation notification should reach exactly its configured audience' })
          .toBe(expected.length);
        assertRecipientsExactly(notices.map((notice) => notice.recipient_email), expected);
        expect(notices.every((notice) => notice.type === 'chain.separation_initiated')).toBe(true);
        await assertInitiationEmails(clearance.clearance_id, initialClock);
        const emailExpected = await expectedEmailRecipients(clearance.clearance_id);
        expect(emailExpected.every((email) => expected.includes(email))).toBe(true);
      },
      assertInvariants: async () => {
        hr.diagnostics.assertClean(`HR initiates ${variant.description} employee separation`);
      },
    });

    const initialClearance = (await clearanceSnapshot(target.employee_no, remarks))[0];
    await runProcessStep({
      ...plannedSteps[1], page: finance.page, plannedSteps, context: journey, testInfo,
      action: async () => {
        // New actor contexts start at about:blank. Enter through the login
        // route so the saved real-UI session restores Finance to its landing page.
        await finance.page.goto('/sign-in');
        await expect(finance.page.getByRole('navigation', { name: 'Modules' })).toBeVisible();
        const bell = finance.page.getByRole('button', { name: /^Notifications(?:, \d+ unread)?$/ });
        await expect(bell, 'Finance should receive the configured in-app separation handoff').toBeVisible();
        await bell.click();
        const dialog = finance.page.getByRole('dialog', { name: 'Notifications' });
        await expect(dialog).toBeVisible();
        const notice = dialog.getByRole('link').filter({ hasText: 'Separation Initiated' });
        await expect(notice).toHaveCount(1);
        await expect(notice).toHaveAttribute('href', /\/hr\/separations\//);
        journey.values.clearanceHref = await notice.getAttribute('href');
        await notice.click();
        await expect(finance.page).toHaveURL(/\/hr\/separations\/[A-Za-z0-9]+(?:\?|$)/);
        await expect(finance.page.getByRole('heading', { name: initialClearance.clearance_no, exact: true })).toBeVisible();
      },
      verify: async () => {
        const notices = await separationNotifications(initialClearance.clearance_id);
        const expected = await expectedNotificationRecipients(initialClearance.clearance_id);
        assertRecipientsExactly(notices.map((notice) => notice.recipient_email), expected);
        expect(expected).toContain(financeActorEmail(actors));
        expect(notices.every((notice) => notice.link_to === journey.values.clearanceHref)).toBe(true);
      },
      assertInvariants: async () => finance.diagnostics.assertClean('Finance follows the in-app separation notice'),
    });

    await runProcessStep({
      ...plannedSteps[2], page: adminObserver.page, plannedSteps, context: journey, testInfo,
      action: async () => {
        await openClearanceFromList(adminObserver, target.employee_no);
        await expect(adminObserver.page.getByRole('heading', { name: initialClearance.clearance_no, exact: true })).toBeVisible();
      },
      verify: async () => {
        await expect(adminObserver.page.getByText('In progress', { exact: true })).toBeVisible();
        expect((await clearanceSnapshot(target.employee_no, remarks))[0]?.status).toBe('in_progress');
      },
      assertInvariants: async () => adminObserver.diagnostics.assertClean('System Administrator observes without acting'),
    });

    for (let index = 0; index < checklist.length; index += 1) {
      const item = checklist[index];
      const owner = itemActor(item.department);
      const acting = contexts.get(owner.actor)!;
      await runProcessStep({
        ...plannedSteps[3 + index], page: acting.page, plannedSteps, context: journey, testInfo,
        action: async () => {
          if (owner.fallback) {
            await openDashboard(itDepartmentHead);
            await openSidebarItem(itDepartmentHead, 'Human Resources', '/hr/separations', 'Separation Clearance');
            await expect(itDepartmentHead.page.getByRole('heading', { name: 'Separations & clearances', exact: true })).toBeVisible();
            await expect(itDepartmentHead.page.getByRole('row').filter({ hasText: target.employee_no }),
              'IT Department Head must not see another department employee clearance').toHaveCount(0);
            itDepartmentHead.diagnostics.assertClean('IT Department Head is excluded by the employee department scope');
          }
          const sidebarSection = owner.actor === 'finance_officer' ? 'Payroll' : 'Human Resources';
          await openPendingClearanceFromSidebar(acting, target.employee_no, sidebarSection);
          const detail = acting.page;
          await expect(detail.getByRole('heading', { name: initialClearance.clearance_no, exact: true })).toBeVisible();
          const label = detail.getByText(item.label, { exact: true });
          await expect(label).toBeVisible();
          const itemRow = label.locator('..').locator('..');
          await expect(itemRow.getByRole('button', { name: 'Sign', exact: true })).toBeEnabled();
          await itemRow.getByRole('button', { name: 'Sign', exact: true }).click();
          await expect(itemRow.getByText(/Cleared/)).toBeVisible();
        },
        verify: async () => {
          const rows = await clearanceItems(initialClearance.clearance_id);
          const signedItem = rows.find((row) => row.item_key === item.item_key);
          expect(signedItem?.status).toBe('cleared');
          expect(signedItem?.signed_by_email, 'the clearance decision must record the role that owned the UI action').toBe(actorByName(actors, owner.actor).email);
          expect(signedItem?.signed_at).toBeTruthy();
          const snapshot = (await clearanceSnapshot(target.employee_no, remarks))[0];
          expect(snapshot.employee_status).toBe('on_leave');
          const allClear = rows.every((row) => row.status === 'cleared');
          expect(snapshot.status).toBe(allClear ? 'completed' : 'in_progress');
          const audits = await clearanceAudits(initialClearance.clearance_id);
          const latest = audits.at(-1);
          expect(latest?.action).toBe('updated');
          assertClearanceAuditActor(latest, actorByName(actors, owner.actor).email);
          if (allClear) {
            await expect.poll(async () => {
              const current = (await clearanceSnapshot(target.employee_no, remarks))[0];
              return current?.employee_user_active ?? true;
            }, { timeout: 20_000, message: 'the completed clearance should queue employee-account deactivation' }).toBe(false);
          }
        },
        assertInvariants: async () => {
          await assertRealtimeItem(adminObserver, item.label);
          const current = (await clearanceSnapshot(target.employee_no, remarks))[0];
          if (current.status === 'completed') {
            await expect(adminObserver.page.getByText('Completed', { exact: true }),
              'the second viewer should receive the completed status without refresh').toBeVisible();
          }
          acting.diagnostics.assertClean(`${owner.actor} signs ${item.item_key}`);
          adminObserver.diagnostics.assertClean(`live observer receives ${item.item_key} clearance update`);
        },
      });
    }

    const completed = (await clearanceSnapshot(target.employee_no, remarks))[0];
    const finalPayPlanIndex = plannedSteps.length - 2;
    await runProcessStep({
      ...plannedSteps[finalPayPlanIndex], page: hr.page, plannedSteps, context: journey, testInfo,
      action: async () => {
        await openClearanceFromList(hr, target.employee_no);
        await expect(hr.page.getByRole('heading', { name: completed.clearance_no, exact: true })).toBeVisible();
        await expect(hr.page.getByText('Completed', { exact: true })).toBeVisible();
        const financeRecord = await openFinanceClearanceForPermissionCheck(finance, target.employee_no, completed.clearance_no);
        expect(financeRecord.status).toBe('completed');
        await expect(finance.page.getByRole('button', { name: 'Compute final pay', exact: true }),
          'Finance may view the completed clearance but must not execute HR final-pay actions').toHaveCount(0);
        await expect(finance.page.getByRole('button', { name: 'Finalize', exact: true })).toHaveCount(0);
        await expect(hr.page.getByRole('button', { name: 'Compute final pay', exact: true })).toBeEnabled();
        const responseWait = hr.page.waitForResponse((response) => response.request().method() === 'POST'
          && /\/clearances\/[^/]+\/final-pay\/compute(?:\?|$)/.test(new URL(response.url()).pathname));
        await hr.page.getByRole('button', { name: 'Compute final pay', exact: true }).click();
        const response = await responseWait;
        expect(response.status(), 'HR final-pay computation should succeed after all clearance items').toBe(200);
      },
      verify: async () => {
        const snapshot = await waitForClearance(target.employee_no, remarks, 'completed');
        expect(snapshot.final_pay_computed).toBe(true);
        expect(snapshot.final_pay_amount).not.toBeNull();
        expect(snapshot.journal_entry_id).toBeNull();
        const net = Number(snapshot.final_pay_amount);
        expect(Number(snapshot.final_pay_breakdown?.net)).toBe(net);
        const audits = await clearanceAudits(snapshot.clearance_id);
        const computeAudit = audits.filter((audit) => audit.action === 'hr.clearance.final_pay_computed').at(-1);
        assertClearanceAuditActor(computeAudit, hrActor.email);
      },
      assertInvariants: async () => {
        await expect(hr.page.getByRole('button', { name: 'Finalize', exact: true })).toBeEnabled();
        await expect(hr.page.getByText(/Final pay breakdown/)).toBeVisible();
        const finalPay = Number((await clearanceSnapshot(target.employee_no, remarks))[0].final_pay_amount);
        await assertPhpCurrency(hr.page.getByText('Final pay', { exact: true }).locator('..'), finalPay);
        await expect(adminObserver.page.getByText('Completed', { exact: true })).toBeVisible();
        await assertRealtimeItem(adminObserver, checklist.at(-1)!.label);
        hr.diagnostics.assertClean('HR computes final pay after complete clearance');
      },
    });

    const finalPayComputed = (await clearanceSnapshot(target.employee_no, remarks))[0];
    await runProcessStep({
      ...plannedSteps[plannedSteps.length - 1], page: hr.page, plannedSteps, context: journey, testInfo,
      action: async () => {
        const finalize = hr.page.getByRole('button', { name: 'Finalize', exact: true });
        await expect(finalize).toBeEnabled();
        await finalize.click();
        const dialog = hr.page.getByRole('dialog', { name: 'Finalize separation?' });
        await expect(dialog).toBeVisible();
        const responseWait = hr.page.waitForResponse((response) => response.request().method() === 'PATCH'
          && /\/clearances\/[^/]+\/finalize(?:\?|$)/.test(new URL(response.url()).pathname));
        await dialog.getByRole('button', { name: 'Finalize', exact: true }).click();
        const response = await responseWait;
        expect(response.status(), 'HR finalization should post the final-pay journal').toBe(200);
      },
      verify: async () => {
        const snapshot = await waitForClearance(target.employee_no, remarks, 'finalized');
        expect(snapshot.employee_status).toBe(variant.employeeStatus);
        expect(snapshot.employee_user_active).toBe(false);
        expect(snapshot.final_pay_computed).toBe(true);
        expect(snapshot.final_pay_amount).toBe(finalPayComputed.final_pay_amount);
        expect(snapshot.journal_entry_id).not.toBeNull();
        expect(snapshot.finalized_by_email).toBe(hrActor.email);
        const journal = await journalSnapshot(snapshot.clearance_id);
        expect(journal).toHaveLength(1);
        expect(journal[0].status).toBe('posted');
        // Source-linked journal entries leave created_by null; the actor is
        // recorded as the poster in posted_by by the final-pay workflow.
        expect(journal[0].created_by_email).toBeNull();
        expect(journal[0].posted_by_email).toBe(hrActor.email);
        expect(journal[0].line_count).toBeGreaterThan(0);
        expect(Number(journal[0].total_debit)).toBe(Number(journal[0].total_credit));
        expect(Number(journal[0].line_debit)).toBe(Number(journal[0].line_credit));
        expect(Number(journal[0].line_debit)).toBe(Number(journal[0].total_debit));
        const history = await separationHistory(snapshot.employee_id);
        expect(history).toHaveLength(1);
        expect(history[0].status).toBe('finalized');
        expect(history[0].separation_reason).toBe(variant.reason);
        expect(history[0].actor_email).toBe(hrActor.email);
        const audits = await clearanceAudits(snapshot.clearance_id);
        assertClearanceAuditActor(audits.at(-1), hrActor.email);
      },
      assertInvariants: async () => {
        await expect(hr.page.locator('[data-layout="page-header"]').getByText('Finalized', { exact: true })).toBeVisible();
        await expect(hr.page.getByRole('region', { name: 'Document progress' })
          .getByText('Finalized', { exact: true })).toBeVisible();
        await expect(hr.page.getByText(/JE posted:/)).toBeVisible();
        await expect(adminObserver.page.locator('[data-layout="page-header"]')
          .getByText('Finalized', { exact: true }),
          'the second viewer should receive finalization without refresh').toBeVisible();
        await expect(adminObserver.page.getByRole('region', { name: 'Document progress' })
          .getByText('Finalized', { exact: true }),
          'the observer process tracker should receive finalization without refresh').toBeVisible();
        await assertRealtimeItem(adminObserver, checklist.at(-1)!.label);
        hr.diagnostics.assertClean(`HR finalizes ${variant.description} separation`);
        adminObserver.diagnostics.assertClean('observer receives clearance finalization');
      },
    });

    journey.values.finalPayAmount = (await clearanceSnapshot(target.employee_no, remarks))[0].final_pay_amount;
    journey.values.journal = (await journalSnapshot(finalPayComputed.clearance_id))[0];
  } finally {
    if (frozenClock) await clearClock(request);
    for (const session of contexts.values()) await session.context.close();
  }
});
}

test('HR08-CANCEL-REOPEN — cancellation is blocked at the missing UI action', async ({ browser, request }, testInfo) => {
  test.setTimeout(10 * 60 * 1000);
  const actors = await loadActors();
  const checklist = await configuredChecklist();
  const journey = createJourneyContext('HR08-CANCEL-REOPEN');
  const hrActor = actorByName(actors, 'hr_officer');
  const employeeActor = actorByName(actors, 'employee');
  const remarks = `${journey.values.runPrefix} rescinded separation cancellation gap`;
  const hr = await actorPage(browser, 'hr_officer');
  const plannedSteps = [
    {
      name: 'HR starts a separation through the Employee UI', actor: 'hr_officer',
      uiPath: 'Human Resources → Employees → employee → Separate → Start separation',
      expectedStatus: 'clearance in_progress; employee on_leave; account remains active; checklist pending',
    },
    {
      name: 'HR looks for the supported cancellation action on the clearance', actor: 'hr_officer',
      uiPath: 'Sidebar → Separation Clearance → employee row → clearance actions',
      expectedStatus: 'HR UI exposes Cancel because the read-only resource advertises cancel; missing action blocks cancellation and re-initiation',
    },
  ];
  let frozenClock = false;
  let initiatedNotifications: NotificationSnapshot[] = [];
  try {
    const initialClock = await readClock(request);
    await freezeClock(request, initialClock);
    frozenClock = true;
    const effectiveDate = manilaDate(initialClock);
    const targetRows = await employeeBaseline(employeeActor.email);
    expect(targetRows, 'the seeded employee actor should resolve to one reference Employee').toHaveLength(1);
    const target = targetRows[0];
    expect(target.status).toBe('active');
    expect(target.user_active).toBe(true);

    await runProcessStep({
      ...plannedSteps[0], page: hr.page, plannedSteps, context: journey, testInfo,
      action: async (page) => {
        await openDashboard(hr);
        await openSidebarItem(hr, 'Human Resources', '/hr/employees', 'Employees');
        await expect(page.getByRole('heading', { name: 'Employees', exact: true })).toBeVisible();
        await page.getByPlaceholder(/Search name or employee no/).fill(target.employee_no);
        const employeeRow = page.getByRole('row').filter({ hasText: target.employee_no });
        await expect(employeeRow).toHaveCount(1);
        await employeeRow.click();
        await expect(page.getByRole('button', { name: 'Separate', exact: true })).toBeEnabled();
        await page.getByRole('button', { name: 'Separate', exact: true }).click();
        const modal = page.getByRole('dialog', { name: 'Separate employee' });
        await expect(modal).toBeVisible();
        await modal.getByLabel('Reason').selectOption('resigned');
        await modal.getByLabel('Effective date').fill(effectiveDate);
        await modal.getByLabel('Remarks').fill(remarks);
        const responseWait = page.waitForResponse((response) => response.request().method() === 'POST'
          && /\/employees\/[^/]+\/separation(?:\?|$)/.test(new URL(response.url()).pathname));
        await modal.getByRole('button', { name: 'Start separation', exact: true }).click();
        const response = await responseWait;
        expect(response.status()).toBe(201);
        await expect(page.getByText(/Separation initiated\. Complete clearance and final pay/)).toBeVisible();
      },
      verify: async () => {
        const clearance = await waitForClearance(target.employee_no, remarks, 'in_progress');
        expect(clearance.separation_reason).toBe('resigned');
        expect(clearance.separation_date).toBe(effectiveDate);
        expect(clearance.employee_status).toBe('on_leave');
        expect(clearance.employee_user_active).toBe(true);
        expect(clearance.final_pay_computed).toBe(false);
        expect(clearance.journal_entry_id).toBeNull();
        expect(clearance.items).toHaveLength(checklist.length);
        expect(clearance.items.every((item) => item.status === 'pending')).toBe(true);
        journey.values.clearanceId = clearance.clearance_id;
        journey.values.clearanceNo = clearance.clearance_no;
        journey.values.employeeNo = target.employee_no;
        journey.values.effectiveDate = effectiveDate;
        journey.values.checklist = checklist;
        const audits = await clearanceAudits(clearance.clearance_id);
        expect(audits.map((entry) => entry.action)).toEqual(['created']);
        assertClearanceAuditActor(audits[0], hrActor.email);
        const expected = await expectedNotificationRecipients(clearance.clearance_id);
        await expect.poll(async () => {
          initiatedNotifications = await separationNotifications(clearance.clearance_id);
          return initiatedNotifications.length;
        }, { timeout: 20_000, message: 'separation initiation notification reaches its configured audience' })
          .toBe(expected.length);
        assertRecipientsExactly(initiatedNotifications.map((notice) => notice.recipient_email), expected);
        await assertInitiationEmails(clearance.clearance_id, initialClock);
      },
      assertInvariants: async () => {
        await expect(hr.page.getByText('On leave', { exact: true })).toBeVisible();
        hr.diagnostics.assertClean('HR initiates a cancellation-gap separation');
      },
    });

    await runProcessStep({
      ...plannedSteps[1], page: hr.page, plannedSteps, context: journey, testInfo,
      gapId: 'GAP-HR-08-02',
      gapReason: 'The backend and read-only resource advertise HR cancellation, but the SPA has no cancellation client or control. Do not call the positive cancellation endpoint; cancel and re-initiation remain blocked at this UI step.',
      action: async (page) => {
        await openClearanceFromList(hr, target.employee_no);
        const before = (await clearanceSnapshot(target.employee_no, remarks))[0];
        expect(before.status).toBe('in_progress');
        await expect(page.getByRole('heading', { name: before.clearance_no, exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: /cancel/i }),
          'the SPA does not expose the cancellation action advertised by the resource').toHaveCount(0);
        const response = await hr.context.request.get(`/api/v1/hr/clearances/${before.clearance_id}`, {
          headers: { Accept: 'application/json' },
        });
        expect(response.status(), 'read-only clearance resource request').toBe(200);
        const payload = await response.json() as { data?: { available_actions?: string[] } };
        expect(payload.data?.available_actions).toContain('cancel');
        const after = (await clearanceSnapshot(target.employee_no, remarks))[0];
        expect(after.status).toBe('in_progress');
        expect(after.employee_status).toBe('on_leave');
        expect(after.employee_user_active).toBe(true);
        expect(after.items.every((item) => item.status === 'pending')).toBe(true);
        expect(await clearanceNotifications(after.clearance_id)).toEqual(initiatedNotifications);
        hr.diagnostics.assertClean('HR observes the missing clearance cancellation action');
      },
      verify: async () => {},
      assertInvariants: async () => {},
    });
  } finally {
    if (frozenClock) await clearClock(request);
    await hr.context.close();
  }
});

function financeActorEmail(actors: E2EActor[]): string {
  return actorByName(actors, 'finance_officer').email.toLowerCase();
}

async function openFinanceClearanceForPermissionCheck(
  finance: ActorPage,
  employeeNo: string,
  clearanceNo: string,
): Promise<{ status: string }> {
  // Sidebar.tsx renders the HR section as Payroll for finance_officer.
  await openClearanceFromList(finance, employeeNo, 'Payroll');
  await expect(finance.page.getByRole('heading', { name: clearanceNo, exact: true })).toBeVisible();
  await expect(finance.page.getByText('Completed', { exact: true })).toBeVisible();
  const snapshots = await readOnlyDatabaseJson<Array<{ status: string }>>(`
    SELECT status::text AS status FROM clearances WHERE clearance_no = ${sqlText(clearanceNo)}
  `);
  return snapshots[0] ?? { status: 'missing' };
}

test('HR08-CLEARANCE-BLOCKED — finalization is refused until every checklist item is signed', async ({ browser, request }, testInfo) => {
  test.setTimeout(15 * 60 * 1000);
  const actors = await loadActors();
  const checklist = await configuredChecklist();
  const journey = createJourneyContext('HR08-CLEARANCE-BLOCKED');
  const hrActor = actorByName(actors, 'hr_officer');
  const employeeActor = actorByName(actors, 'employee');
  const remarks = `${journey.values.runPrefix} incomplete clearance finalization guard`;
  const hr = await actorPage(browser, 'hr_officer');
  const plannedSteps = [
    {
      name: 'HR starts separation and leaves the configured checklist pending',
      actor: 'hr_officer',
      uiPath: 'Human Resources → Employees → employee → Separate → Start separation',
      expectedStatus: 'clearance in_progress; every configured item pending; employee on_leave; account remains active',
    },
    {
      name: 'HR cannot finalize through the UI or out-of-order API while clearance items remain pending',
      actor: 'hr_officer',
      uiPath: 'Human Resources → Separation Clearance → employee clearance; negative finalize request',
      expectedStatus: 'Finalize unavailable; negative request returns 422; no final-pay journal, finalization audit, notification or status change',
    },
  ];
  let frozenClock = false;
  let notificationsBeforeFinalizeAttempt: NotificationSnapshot[] = [];
  try {
    const initialClock = await readClock(request);
    await freezeClock(request, initialClock);
    frozenClock = true;
    const effectiveDate = manilaDate(initialClock);
    const targetRows = await employeeBaseline(employeeActor.email);
    expect(targetRows, 'the seeded employee actor should resolve to one reference Employee').toHaveLength(1);
    const target = targetRows[0];
    expect(target.status, 'the isolated E2E employee should start active').toBe('active');
    expect(target.user_active, 'the isolated E2E employee account should start enabled').toBe(true);

    await runProcessStep({
      ...plannedSteps[0], page: hr.page, plannedSteps, context: journey, testInfo,
      action: async (page) => {
        await openDashboard(hr);
        await openSidebarItem(hr, 'Human Resources', '/hr/employees', 'Employees');
        await expect(page.getByRole('heading', { name: 'Employees', exact: true })).toBeVisible();
        await page.getByPlaceholder(/Search name or employee no/).fill(target.employee_no);
        const employeeRow = page.getByRole('row').filter({ hasText: target.employee_no });
        await expect(employeeRow).toHaveCount(1);
        await employeeRow.click();
        await expect(page.getByRole('button', { name: 'Separate', exact: true })).toBeEnabled();
        await page.getByRole('button', { name: 'Separate', exact: true }).click();
        const modal = page.getByRole('dialog', { name: 'Separate employee' });
        await expect(modal).toBeVisible();
        await modal.getByLabel('Reason').selectOption('resigned');
        await modal.getByLabel('Effective date').fill(effectiveDate);
        await modal.getByLabel('Remarks').fill(remarks);
        const responseWait = page.waitForResponse((response) => response.request().method() === 'POST'
          && /\/employees\/[^/]+\/separation(?:\?|$)/.test(new URL(response.url()).pathname));
        await modal.getByRole('button', { name: 'Start separation', exact: true }).click();
        const response = await responseWait;
        expect(response.status(), 'HR initiation should create the clearance through the UI').toBe(201);
        await expect(page.getByText(/Separation initiated\. Complete clearance and final pay/)).toBeVisible();
      },
      verify: async () => {
        const clearance = await waitForClearance(target.employee_no, remarks, 'in_progress');
        expect(clearance.separation_reason).toBe('resigned');
        expect(clearance.separation_date).toBe(effectiveDate);
        expect(clearance.employee_status).toBe('on_leave');
        expect(clearance.employee_user_active).toBe(true);
        expect(clearance.final_pay_computed).toBe(false);
        expect(clearance.journal_entry_id).toBeNull();
        expect(clearance.items).toHaveLength(checklist.length);
        expect(clearance.items.every((item) => item.status === 'pending')).toBe(true);
        journey.values.clearanceId = clearance.clearance_id;
        journey.values.clearanceNo = clearance.clearance_no;
        journey.values.employeeNo = target.employee_no;
        journey.values.effectiveDate = effectiveDate;
        journey.values.checklist = checklist;
        const audits = await clearanceAudits(clearance.clearance_id);
        expect(audits.map((entry) => entry.action)).toEqual(['created']);
        assertClearanceAuditActor(audits[0], hrActor.email);
        const expected = await expectedNotificationRecipients(clearance.clearance_id);
        let notices: NotificationSnapshot[] = [];
        await expect.poll(async () => {
          notices = await separationNotifications(clearance.clearance_id);
          return notices.length;
        }, { timeout: 20_000, message: 'the initiated clearance notification should reach its configured audience' })
          .toBe(expected.length);
        assertRecipientsExactly(notices.map((notice) => notice.recipient_email), expected);
        await assertInitiationEmails(clearance.clearance_id, initialClock);
      },
      assertInvariants: async () => {
        await expect(hr.page.getByText('On leave', { exact: true })).toBeVisible();
        hr.diagnostics.assertClean('HR starts a clearance-blocked separation');
      },
    });

    await runProcessStep({
      ...plannedSteps[1], page: hr.page, plannedSteps, context: journey, testInfo,
      action: async () => {
        await openClearanceFromList(hr, target.employee_no);
        const before = (await clearanceSnapshot(target.employee_no, remarks))[0];
        await expect(hr.page.getByRole('heading', { name: before.clearance_no, exact: true })).toBeVisible();
        await expect(hr.page.locator('[data-layout="page-header"]')
          .getByText('In progress', { exact: true })).toBeVisible();
        await expect(hr.page.getByRole('button', { name: 'Finalize', exact: true }),
          'HR must not be offered finalization while checklist items are pending').toHaveCount(0);
        notificationsBeforeFinalizeAttempt = await clearanceNotifications(before.clearance_id);
        const origin = new URL(hr.page.url()).origin;
        const csrfCookie = (await hr.context.cookies(origin)).find((cookie) => cookie.name === 'XSRF-TOKEN');
        expect(csrfCookie, 'the HR browser session should carry its Sanctum CSRF cookie').toBeDefined();
        const response = await hr.context.request.fetch(`/api/v1/hr/clearances/${before.clearance_id}/finalize`, {
          method: 'PATCH',
          data: {},
          failOnStatusCode: false,
          headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            Origin: origin,
            Referer: hr.page.url(),
            'X-XSRF-TOKEN': decodeURIComponent(csrfCookie!.value),
          },
        });
        expect(response.status(), 'the HR actor out-of-order finalization attempt must be refused as a business-rule error').toBe(422);
        const body = await response.json() as { message?: string; errors?: Record<string, string[]> };
        expect(body.message).toBe('All clearance items must be signed before finalization.');
        expect(body.errors?.error).toContain('All clearance items must be signed before finalization.');
      },
      verify: async () => {
        const after = (await clearanceSnapshot(target.employee_no, remarks))[0];
        expect(after.status).toBe('in_progress');
        expect(after.employee_status).toBe('on_leave');
        expect(after.employee_user_active).toBe(true);
        expect(after.final_pay_computed).toBe(false);
        expect(after.final_pay_amount).toBeNull();
        expect(after.journal_entry_id).toBeNull();
        expect(after.items.every((item) => item.status === 'pending')).toBe(true);
        expect(await journalSnapshot(after.clearance_id)).toHaveLength(0);
        expect((await separationHistory(after.employee_id)).map((entry) => entry.status)).toEqual(['in_progress']);
        const audits = await clearanceAudits(after.clearance_id);
        expect(audits.map((entry) => entry.action)).toEqual(['created']);
        assertClearanceAuditActor(audits[0], hrActor.email);
        const notificationsAfter = await clearanceNotifications(after.clearance_id);
        expect(notificationsAfter, 'the refused out-of-order action must not add, remove, or redirect a clearance notification')
          .toEqual(notificationsBeforeFinalizeAttempt);
        const notices = await separationNotifications(after.clearance_id);
        assertRecipientsExactly(notices.map((notice) => notice.recipient_email), await expectedNotificationRecipients(after.clearance_id));
      },
      assertInvariants: async () => {
        await expect(hr.page.getByRole('button', { name: 'Finalize', exact: true })).toHaveCount(0);
        await expect(hr.page.locator('[data-layout="page-header"]')
          .getByText('In progress', { exact: true })).toBeVisible();
        hr.diagnostics.assertClean('HR observes that out-of-order finalization had no effect');
      },
    });
  } finally {
    if (frozenClock) await clearClock(request);
    await hr.context.close();
  }
});
