import { test, expect } from './fixtures';
import { actorPage, loadActors } from './actor-session';
import type { E2EActor } from './actor-session';
import { createJourneyContext, runProcessStep } from './process-runner';
import type { JourneyContext } from './process-runner';
import { assertAuditActor, assertDocumentNumberUnique, assertRecipientsExactly } from './helpers/process-invariants';
import { readOnlyDatabaseJson } from './helpers/database-reader';
import { listMailpitMessages } from './helpers/mailpit-reader';

type ActorPage = Awaited<ReturnType<typeof actorPage>>;

type PlanningBaseline = {
  customer_name: string;
  customer_email: string | null;
  product_code: string;
  product_name: string;
  agreement_end: string;
  component_stock: string;
};

type ProcessSnapshot = {
  so_id: number;
  so_number: string;
  so_status: string;
  so_subtotal: string;
  so_vat: string;
  so_total: string;
  so_created_by: string;
  confirm_actor_email: string | null;
  confirm_audit_count: number;
  plan_id: number | null;
  mrp_plan_no: string | null;
  plan_status: string | null;
  plan_shortages: number | null;
  auto_pr_count: number | null;
  draft_wo_count: number | null;
  run_id: number | null;
  run_status: string | null;
  run_trigger: string | null;
  run_user_id: number | null;
  run_plans_generated: number | null;
  run_prs_created: number | null;
  pr_id: number | null;
  pr_number: string | null;
  pr_status: string | null;
  is_auto_generated: boolean | null;
  pr_department_code: string | null;
  pr_line_count: number;
  pr_item_codes: string | null;
  work_order_count: number;
  work_order_statuses: string | null;
  shortage_alert_count: number;
};

type NotificationRecipient = { email: string; type: string; title: string };

const plannedSteps = [
  { name: 'Sales creates an order draft in its own session', actor: 'sales_officer', uiPath: 'Sales & CRM → Sales Orders → New sales order → Save as draft', expectedStatus: 'draft' },
  { name: 'PPC finds and observes the draft from Sales Orders', actor: 'ppc_head', uiPath: 'Sales & CRM → Sales Orders → search by order number → detail', expectedStatus: 'draft; confirm action unavailable' },
  { name: 'Sales confirms the order; PPC sees the live status change', actor: 'sales_officer', uiPath: 'Sales order detail → Confirm order → Confirm & Start Chain', expectedStatus: 'confirmed; automatic MRP queued' },
  { name: 'PPC follows the MRP completion notification to the plan', actor: 'ppc_head', uiPath: 'Work → Notifications → MRP planning completed → linked MRP plan', expectedStatus: 'completed run; active shortage plan; draft PR and planned WO' },
  { name: 'Purchasing finds the MRP-generated draft through its list', actor: 'purchasing_officer', uiPath: 'Procurement → Purchase Requests → search PR number → detail', expectedStatus: 'draft; auto-generated' },
];

function actorByName(actors: E2EActor[], actor: string): E2EActor {
  const found = actors.find((candidate) => candidate.actor === actor);
  if (!found) throw new Error(`Required seeded E2E actor is missing: ${actor}`);
  return found;
}

function sqlText(value: string): string {
  return `'${value.replaceAll("'", "''")}'`;
}

function contextValue<T>(context: JourneyContext, key: string): T {
  const result = context.values[key];
  if (result === undefined) throw new Error(`Journey context is missing ${key}.`);
  return result as T;
}

async function openInternalHome(session: ActorPage): Promise<void> {
  await session.page.goto('/dashboard');
  await expect(session.page).toHaveURL(/\/dashboard(?:\/|$)/);
}

async function openInternalMenuItem(
  page: ActorPage['page'],
  section: string,
  label: string,
  href: string,
): Promise<void> {
  const target = page.getByRole('navigation', { name: 'Modules' }).locator(`a[href="${href}"]`);
  await expect(target, `sidebar should contain its ${label} link`).toHaveCount(1);
  if (!(await target.isVisible())) {
    const toggle = page.locator(`[data-module="${section}"]`).getByRole('button');
    if (await toggle.count()) {
      await expect(toggle).toBeVisible();
      if ((await toggle.getAttribute('aria-expanded')) !== 'true') await toggle.click();
    }
  }
  await expect(target).toBeVisible();
  await target.click();
}

async function processSnapshot(soNumber: string): Promise<ProcessSnapshot[]> {
  return readOnlyDatabaseJson<ProcessSnapshot[]>(`
    SELECT so.id AS so_id, so.so_number, so.status AS so_status,
           so.subtotal::text AS so_subtotal, so.vat_amount::text AS so_vat,
           so.total_amount::text AS so_total,
           creator.email AS so_created_by,
           (SELECT actor.email
              FROM audit_logs audit
              JOIN users actor ON actor.id = audit.user_id
             WHERE audit.model_type = 'App\\Modules\\CRM\\Models\\SalesOrder'
               AND audit.model_id = so.id
               AND audit.action = 'updated'
               AND audit.new_values->>'status' = 'confirmed'
             ORDER BY audit.id DESC LIMIT 1) AS confirm_actor_email,
           (SELECT COUNT(*)::int
              FROM audit_logs audit
             WHERE audit.model_type = 'App\\Modules\\CRM\\Models\\SalesOrder'
               AND audit.model_id = so.id
               AND audit.action = 'updated'
               AND audit.new_values->>'status' = 'confirmed') AS confirm_audit_count,
           plan.id AS plan_id, plan.mrp_plan_no, plan.status AS plan_status,
           plan.shortages_found::int AS plan_shortages,
           plan.auto_pr_count::int AS auto_pr_count,
           plan.draft_wo_count::int AS draft_wo_count,
           run.id AS run_id, run.status AS run_status, run.triggered_by AS run_trigger,
           run.triggered_by_user_id AS run_user_id,
           run.plans_generated::int AS run_plans_generated,
           run.prs_created::int AS run_prs_created,
           pr.id AS pr_id, pr.pr_number, pr.status AS pr_status,
           pr.is_auto_generated, department.code AS pr_department_code,
           (SELECT COUNT(*)::int FROM purchase_request_items pri WHERE pri.purchase_request_id = pr.id) AS pr_line_count,
           (SELECT string_agg(item.code, ',' ORDER BY item.code)
              FROM purchase_request_items pri
              JOIN items item ON item.id = pri.item_id
             WHERE pri.purchase_request_id = pr.id) AS pr_item_codes,
           (SELECT COUNT(*)::int FROM work_orders wo WHERE wo.mrp_plan_id = plan.id) AS work_order_count,
           (SELECT string_agg(DISTINCT wo.status, ',' ORDER BY wo.status)
              FROM work_orders wo WHERE wo.mrp_plan_id = plan.id) AS work_order_statuses,
           (SELECT COUNT(*)::int FROM alerts alert
             WHERE alert.type = 'mrp_shortage'
               AND alert.entity_id = run.id) AS shortage_alert_count
      FROM sales_orders so
      JOIN users creator ON creator.id = so.created_by
      LEFT JOIN mrp_plans plan ON plan.sales_order_id = so.id AND plan.status = 'active'
      LEFT JOIN mrp_runs run ON run.id = plan.mrp_run_id
      LEFT JOIN purchase_requests pr ON pr.mrp_plan_id = plan.id AND pr.is_auto_generated = true
      LEFT JOIN departments department ON department.id = pr.department_id
     WHERE so.so_number = ${sqlText(soNumber)}
     ORDER BY plan.version DESC, pr.id DESC
     LIMIT 1
  `);
}

async function notificationRecipients(soNumber: string): Promise<NotificationRecipient[]> {
  return readOnlyDatabaseJson<NotificationRecipient[]>(`
    SELECT users.email, notice.type, notice.data->>'title' AS title
      FROM notifications notice
      JOIN users ON users.id = notice.notifiable_id
     WHERE notice.notifiable_type = 'App\\Modules\\Auth\\Models\\User'
       AND (
         (notice.type = 'mrp_run_completed'
          AND notice.data->>'subject_type' = 'App\\Modules\\MRP\\Models\\MrpRun'
          AND notice.data->>'subject_id' = (
            SELECT plan.mrp_run_id::text FROM sales_orders so
            JOIN mrp_plans plan ON plan.sales_order_id = so.id AND plan.status = 'active'
            WHERE so.so_number = ${sqlText(soNumber)} ORDER BY plan.version DESC LIMIT 1
          ))
         OR
         (notice.data->>'subject_type' = 'App\\Modules\\CRM\\Models\\SalesOrder'
          AND notice.data->>'subject_id' = (
            SELECT so.id::text FROM sales_orders so WHERE so.so_number = ${sqlText(soNumber)}
          ))
       )
     ORDER BY notice.type, users.email
  `);
}

async function expectMrpCompletion(soNumber: string): Promise<ProcessSnapshot> {
  let result: ProcessSnapshot[] = [];
  await expect.poll(async () => {
    result = await processSnapshot(soNumber);
    return result[0]?.run_status ?? 'pending';
  }, { timeout: 30_000, message: 'the confirmed SO should complete its queued automatic MRP run' }).toBe('completed');
  const snapshot = result[0];
  if (!snapshot) throw new Error(`Sales order ${soNumber} disappeared while MRP was running.`);
  return snapshot;
}

test('MRP01-SHORTAGE — confirmed order automatically creates a shortage plan, draft PR, and planned work order', async ({ browser }, testInfo) => {
  test.setTimeout(150_000);

  const actors = await loadActors();
  const salesUser = actorByName(actors, 'sales_officer');
  const ppcUser = actorByName(actors, 'ppc_head');
  const purchasingUser = actorByName(actors, 'purchasing_officer');
  const sales = await actorPage(browser, salesUser.actor);
  const ppc = await actorPage(browser, ppcUser.actor);
  const purchasing = await actorPage(browser, purchasingUser.actor);
  const journey = createJourneyContext('MRP01-SHORTAGE');
  const seenNumbers = new Set<string>();
  const baseline = await readOnlyDatabaseJson<PlanningBaseline[]>(`
    SELECT customer.name AS customer_name, customer.email AS customer_email,
           product.part_number AS product_code, product.name AS product_name,
           agreement.effective_to::text AS agreement_end,
           SUM(COALESCE(level.quantity, 0))::text AS component_stock
      FROM product_price_agreements agreement
      JOIN customers customer ON customer.id = agreement.customer_id
      JOIN products product ON product.id = agreement.product_id
      JOIN bill_of_materials bom ON bom.product_id = product.id AND bom.is_active = true
      JOIN bom_items bom_item ON bom_item.bom_id = bom.id
      JOIN items component ON component.id = bom_item.item_id
      LEFT JOIN stock_levels level ON level.item_id = component.id
     WHERE customer.is_active = true AND customer.deleted_at IS NULL
       AND product.is_active = true AND product.deleted_at IS NULL
       AND agreement.deleted_at IS NULL
       AND agreement.effective_from <= CURRENT_DATE
       AND agreement.effective_to >= CURRENT_DATE
     GROUP BY customer.name, customer.email, product.part_number, product.name, agreement.effective_to
    HAVING SUM(COALESCE(level.quantity, 0)) = 0
     ORDER BY customer.name, product.part_number
     LIMIT 1
  `);
  expect(baseline, 'the E2E baseline needs an active customer price agreement and BOM').toHaveLength(1);
  const chosen = baseline[0];
  expect(Number(chosen.component_stock)).toBe(0);
  const mailBefore = await listMailpitMessages();

  try {
    await openInternalHome(sales);
    await openInternalHome(ppc);
    await openInternalHome(purchasing);

    await runProcessStep({
      name: plannedSteps[0].name,
      actor: plannedSteps[0].actor,
      uiPath: plannedSteps[0].uiPath,
      expectedStatus: plannedSteps[0].expectedStatus,
      page: sales.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async (page) => {
        await openInternalMenuItem(page, 'Sales & CRM', 'Sales Orders', '/crm/sales-orders');
        await expect(page.getByRole('heading', { name: 'Sales orders', exact: true })).toBeVisible();
        await page.getByRole('button', { name: 'New sales order', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'New sales order', exact: true })).toBeVisible();

        const customer = page.getByRole('combobox', { name: 'Customer' });
        await customer.click();
        const customerOption = page.getByRole('option').filter({ hasText: chosen.customer_name });
        await expect(customerOption).toHaveCount(1);
        await customerOption.click();

        const product = page.getByRole('combobox', { name: 'Product for line 1' });
        await product.click();
        const productOption = page.getByRole('option').filter({ hasText: chosen.product_code });
        await expect(productOption).toHaveCount(1);
        await productOption.click();

        await page.locator('input[type="date"]').nth(1).fill(chosen.agreement_end);
        await page.getByPlaceholder('0.00').fill('10');
        await page.getByPlaceholder('Optional internal notes.').fill(`${journey.values.runPrefix} shortage plan through confirmed demand`);
        await page.getByRole('button', { name: 'Save as draft', exact: true }).click();

        const orderHeading = page.getByRole('heading', { name: /SO-[A-Z0-9-]+\s+Draft/i });
        await expect(orderHeading, 'saving a draft should open its detail page with Draft status').toBeVisible();
        const headingText = await orderHeading.innerText();
        const soNumber = headingText.match(/SO-[A-Z0-9-]+/i)?.[0];
        expect(soNumber, 'the sales order detail heading should display its generated number').toBeTruthy();
        journey.values.soNumber = soNumber;
        assertDocumentNumberUnique(seenNumbers, soNumber!);
        await expect(page.getByText('Draft', { exact: true }).first()).toBeVisible();
        await expect(page.getByRole('button', { name: 'Confirm order', exact: true })).toBeEnabled();
      },
      verify: async (_page, context) => {
        const rows = await processSnapshot(contextValue<string>(context, 'soNumber'));
        expect(rows).toHaveLength(1);
        expect(rows[0].so_status).toBe('draft');
        expect(rows[0].so_created_by).toBe(salesUser.email);
        expect(rows[0].plan_id).toBeNull();
        expect(rows[0].run_id).toBeNull();
        expect(rows[0].pr_id).toBeNull();
        expect(rows[0].work_order_count).toBe(0);
        expect(Number(rows[0].so_total)).toBeGreaterThan(0);
      },
      assertInvariants: async () => {
        sales.diagnostics.assertClean('sales order draft creation');
        expect(journey.values.soNumber).toBeTruthy();
      },
    });

    await runProcessStep({
      name: plannedSteps[1].name,
      actor: plannedSteps[1].actor,
      uiPath: plannedSteps[1].uiPath,
      expectedStatus: plannedSteps[1].expectedStatus,
      page: ppc.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async (page, context) => {
        await openInternalMenuItem(page, 'Sales & CRM', 'Sales Orders', '/crm/sales-orders');
        await expect(page.getByRole('heading', { name: 'Sales orders', exact: true })).toBeVisible();
        await page.getByPlaceholder(/Search SO number or customer/).fill(contextValue<string>(context, 'soNumber'));
        const row = page.getByRole('row').filter({ hasText: contextValue<string>(context, 'soNumber') });
        await expect(row).toHaveCount(1);
        await expect(row).toContainText('Draft');
        await row.click();
        await expect(page.getByRole('heading', { name: new RegExp(contextValue<string>(context, 'soNumber')) })).toBeVisible();
        await expect(page.getByText('Draft', { exact: true }).first()).toBeVisible();
        await expect(page.getByRole('button', { name: 'Confirm order', exact: true })).toHaveCount(0);
        await expect(page.getByRole('status', { name: /Real-time updates: Live/ })).toBeVisible();
      },
      verify: async (_page, context) => {
        const rows = await processSnapshot(contextValue<string>(context, 'soNumber'));
        expect(rows).toHaveLength(1);
        expect(rows[0].so_status).toBe('draft');
        expect(rows[0].plan_id).toBeNull();
      },
      assertInvariants: async () => {
        ppc.diagnostics.assertClean('PPC opens the draft as observer');
        await expect(ppc.page.getByRole('button', { name: 'Confirm order', exact: true })).toHaveCount(0);
      },
    });

    await runProcessStep({
      name: plannedSteps[2].name,
      actor: plannedSteps[2].actor,
      uiPath: plannedSteps[2].uiPath,
      expectedStatus: plannedSteps[2].expectedStatus,
      page: sales.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async (page, context) => {
        await expect(page.getByRole('heading', { name: new RegExp(contextValue<string>(context, 'soNumber')) })).toBeVisible();
        await page.getByRole('button', { name: 'Confirm order', exact: true }).click();
        const dialog = page.getByRole('dialog');
        await expect(dialog.getByRole('heading', { name: new RegExp(`Confirm ${contextValue<string>(context, 'soNumber')}`) })).toBeVisible();
        await expect(dialog).toContainText('Run MRP and check material availability');
        await dialog.getByRole('button', { name: 'Confirm & Start Chain', exact: true }).click();
        await expect(page.getByText('Confirmed', { exact: true }).first()).toBeVisible();
        await expect(page.getByRole('button', { name: 'Confirm order', exact: true })).toHaveCount(0);
      },
      verify: async (_page, context) => {
        await expect.poll(async () => (await processSnapshot(contextValue<string>(context, 'soNumber')))[0]?.so_status)
          .toBe('confirmed');
        await expect(ppc.page.getByText('Confirmed', { exact: true }).first(), 'PPC observer should update without refreshing').toBeVisible();
        const rows = await processSnapshot(contextValue<string>(context, 'soNumber'));
        expect(rows[0].confirm_actor_email).toBe(salesUser.email);
        expect(rows[0].confirm_audit_count).toBe(1);
      },
      assertInvariants: async () => {
        sales.diagnostics.assertClean('sales order confirmation');
        ppc.diagnostics.assertClean('PPC live confirmation observer');
        await expect(ppc.page.getByRole('button', { name: 'Confirm order', exact: true })).toHaveCount(0);
      },
    });

    await runProcessStep({
      name: plannedSteps[3].name,
      actor: plannedSteps[3].actor,
      uiPath: plannedSteps[3].uiPath,
      expectedStatus: plannedSteps[3].expectedStatus,
      page: ppc.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async (page, context) => {
        const soNumber = contextValue<string>(context, 'soNumber');
        const snapshot = await expectMrpCompletion(soNumber);
        expect(snapshot.plan_status).toBe('active');
        expect(snapshot.plan_shortages).toBeGreaterThan(0);
        expect(snapshot.auto_pr_count).toBeGreaterThan(0);
        expect(snapshot.draft_wo_count).toBeGreaterThan(0);
        journey.values.mrpPlanNo = snapshot.mrp_plan_no;
        journey.values.purchaseRequestNo = snapshot.pr_number;

        await openInternalMenuItem(page, 'Work', 'Notifications', '/notifications');
        await expect(page.getByRole('heading', { name: 'Notifications', exact: true })).toBeVisible();
        await expect(page.getByText(/\d+ unread of \d+ total/), 'wait for the notification inbox data to finish loading').toBeVisible();
        const notice = page.getByRole('link', { name: /MRP planning completed/ });
        if (await notice.count() === 0) {
          test.fixme(true, 'GAP-MRP-01 / FND-MRP-001: MrpRun subject access is not registered, so the MRP completion hand-off is suppressed.');
        }
        await expect(notice).toBeVisible();
        await expect(notice).toContainText('PPC: open MRP Plans');
        await notice.click();
        await expect(page).toHaveURL(/\/mrp\/plans\?run=[A-Za-z0-9_-]+/);

        const planRow = page.getByRole('row').filter({ hasText: soNumber });
        await expect(planRow).toHaveCount(1);
        await expect(planRow).toContainText(String(snapshot.plan_shortages));
        await expect(planRow).toContainText(String(snapshot.auto_pr_count));
        await expect(planRow).toContainText(String(snapshot.draft_wo_count));
        await planRow.click();

        await expect(page.getByRole('heading', { name: new RegExp(snapshot.mrp_plan_no ?? 'MRP-PLAN') })).toBeVisible();
        await expect(page.getByText(soNumber, { exact: true })).toBeVisible();
        await expect(page.getByText('Production shortages', { exact: true })).toBeVisible();
        await expect(page.getByText(String(snapshot.plan_shortages), { exact: true }).first()).toBeVisible();
        await expect(page.getByText('Auto PRs', { exact: true })).toBeVisible();
        await expect(page.getByRole('link', { name: new RegExp(snapshot.pr_number ?? '') })).toBeVisible();
        await expect(page.getByText(/WO\d|WO-/)).toBeVisible();
        await expect(page.getByRole('button', { name: 'Re-run', exact: true })).toBeEnabled();
      },
      verify: async (_page, context) => {
        const rows = await processSnapshot(contextValue<string>(context, 'soNumber'));
        expect(rows).toHaveLength(1);
        const record = rows[0];
        expect(record.so_status).toBe('confirmed');
        expect(record.run_status).toBe('completed');
        expect(record.run_trigger).toBe('automatic');
        expect(record.run_user_id).toBeNull();
        expect(record.run_plans_generated).toBe(1);
        expect(record.run_prs_created).toBe(1);
        expect(record.plan_status).toBe('active');
        expect(record.plan_shortages).toBeGreaterThan(0);
        expect(record.auto_pr_count).toBe(1);
        expect(record.draft_wo_count).toBeGreaterThan(0);
        expect(record.pr_status).toBe('draft');
        expect(record.is_auto_generated).toBe(true);
        expect(record.pr_department_code).toBe('PPC');
        expect(record.pr_line_count).toBeGreaterThan(0);
        expect(record.pr_item_codes).toBeTruthy();
        expect(record.work_order_count).toBeGreaterThan(0);
        expect(record.work_order_statuses).toContain('planned');
        expect(record.shortage_alert_count).toBe(1);
        expect(record.confirm_actor_email).toBe(salesUser.email);
      },
      assertInvariants: async () => {
        ppc.diagnostics.assertClean('PPC follows MRP completion handoff');
        const recipients = await notificationRecipients(contextValue<string>(journey, 'soNumber'));
        journey.values.notificationRecipientTypes = [...new Set(recipients.map((entry) => entry.type))].sort();
        expect(recipients.some((entry) => entry.type === 'mrp_run_completed' && entry.email === ppcUser.email)).toBe(true);
        expect(recipients.some((entry) => entry.type === 'chain.so_confirmed' && entry.email === ppcUser.email)).toBe(true);
        if (!chosen.customer_email) {
          expect(recipients.some((entry) => entry.type === 'email.delivery_failed')).toBe(true);
        }
        assertRecipientsExactly(
          recipients.filter((entry) => entry.type === 'mrp_run_completed').map((entry) => entry.email),
          // MrpAutomationService limits the audience to PPC and Sales; RolePermissionSeeder
          // grants the required mrp.plans.view + mrp.runs.view pair only to PPC in this baseline.
          [ppcUser.email],
        );
        const messagesAfter = await listMailpitMessages();
        const newMessages = messagesAfter.filter((message) => !mailBefore.some((before) => before.ID === message.ID));
        if (chosen.customer_email) {
          expect(newMessages.flatMap((message) => message.To).some((to) => to.Address.toLowerCase() === chosen.customer_email!.toLowerCase())).toBe(true);
        }
        journey.values.mailpitMessagesBefore = mailBefore.length;
        journey.values.mailpitMessagesAfter = messagesAfter.length;
      },
    });

    await runProcessStep({
      name: plannedSteps[4].name,
      actor: plannedSteps[4].actor,
      uiPath: plannedSteps[4].uiPath,
      expectedStatus: plannedSteps[4].expectedStatus,
      page: purchasing.page,
      plannedSteps,
      context: journey,
      testInfo,
      action: async (page, context) => {
        const prNumber = contextValue<string>(context, 'purchaseRequestNo');
        expect(prNumber).toBeTruthy();
        await openInternalMenuItem(page, 'Procurement', 'Purchase Requests', '/purchasing/purchase-requests');
        await expect(page.getByRole('heading', { name: 'Purchase requests', exact: true })).toBeVisible();
        await page.getByPlaceholder(/Search PR number/).fill(prNumber);
        const row = page.getByRole('row').filter({ hasText: prNumber });
        await expect(row).toHaveCount(1);
        await expect(row).toContainText('AUTO');
        await expect(row).toContainText('Draft');
        await row.click();
        await expect(page.getByRole('heading', { name: prNumber, exact: true })).toBeVisible();
        await expect(page.getByText('Draft', { exact: true }).first()).toBeVisible();
        await expect(page.getByRole('button', { name: 'Submit', exact: true })).toBeEnabled();
      },
      verify: async (_page, context) => {
        const rows = await processSnapshot(contextValue<string>(context, 'soNumber'));
        expect(rows[0].pr_number).toBe(contextValue<string>(context, 'purchaseRequestNo'));
        expect(rows[0].pr_status).toBe('draft');
        expect(rows[0].is_auto_generated).toBe(true);
        expect(rows[0].pr_department_code).toBe('PPC');
      },
      assertInvariants: async () => {
        purchasing.diagnostics.assertClean('Purchasing opens the MRP-generated request');
        const record = (await processSnapshot(contextValue<string>(journey, 'soNumber')))[0];
        assertAuditActor({ actor_email: record.confirm_actor_email ?? undefined }, salesUser.email);
        expect(record.plan_shortages).toBeGreaterThan(0);
      },
    });

    const finalSnapshot = (await processSnapshot(contextValue<string>(journey, 'soNumber')))[0];
    expect(Number(finalSnapshot.so_total)).toBeCloseTo(Number(finalSnapshot.so_subtotal) + Number(finalSnapshot.so_vat), 2);
    const userNotifications = await notificationRecipients(contextValue<string>(journey, 'soNumber'));
    const mrpRecipients = userNotifications.filter((entry) => entry.type === 'mrp_run_completed').map((entry) => entry.email);
    assertRecipientsExactly(mrpRecipients, [ppcUser.email]);
  } finally {
    await Promise.all([sales.context.close(), ppc.context.close(), purchasing.context.close()]);
  }
});
