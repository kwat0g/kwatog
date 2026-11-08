import { test, expect } from './fixtures';
import { actorPage, loadActors } from './actor-session';
import type { E2EActor } from './actor-session';
import { createJourneyContext, runProcessStep } from './process-runner';
import type { JourneyContext } from './process-runner';
import { assertDocumentNumberUnique, assertRecipientsExactly, assertTotalsEqual } from './helpers/process-invariants';
import { readOnlyDatabaseJson } from './helpers/database-reader';
import { findMailpitMessagesFor, getMailpitMessage } from './helpers/mailpit-reader';
import { expectPdfContains, readPdfBytes } from './helpers/pdf-reader';

type ActorPage = Awaited<ReturnType<typeof actorPage>>;
type PrRow = {
  pr_number: string;
  status: string;
  po_conversion_status: string | null;
  estimated_total: string;
  requester_email: string;
};
type ApprovalRow = { step_order: number; role_slug: string; action: string; approver_email: string | null };
type PoRow = {
  po_number: string;
  status: string;
  subtotal: string;
  vat_amount: string;
  total_amount: string;
  pr_number: string | null;
  vendor_name: string;
  vendor_email: string;
};

const plannedSteps = [
  { name: 'Create a department-owned draft PR', actor: 'department_head__hr', uiPath: 'Procurement → Purchase Requests → New PR', expectedStatus: 'draft' },
  { name: 'Open the PR from the purchasing list as a live observer', actor: 'purchasing_officer__2', uiPath: 'Procurement → Purchase Requests → PR row', expectedStatus: 'draft' },
  { name: 'Submit the draft PR through its detail action', actor: 'department_head__hr', uiPath: 'PR detail → Submit', expectedStatus: 'pending' },
  { name: 'Finance finds and approves the PR from Approval Queue', actor: 'finance_officer', uiPath: 'Work → Approval Queue → PR card → PR detail', expectedStatus: 'approved' },
  { name: 'Purchasing converts the approved PR using its qualified supplier', actor: 'purchasing_officer', uiPath: 'Procurement → Purchase Requests → PR row → Convert to PO', expectedStatus: 'draft PO / converted PR' },
  { name: 'Open the PO from the purchasing list as a live observer', actor: 'purchasing_officer__2', uiPath: 'Procurement → Purchase Orders → PO row', expectedStatus: 'draft' },
  { name: 'Purchasing submits the draft PO for approval', actor: 'purchasing_officer', uiPath: 'PO detail → Submit', expectedStatus: 'pending_approval' },
  { name: 'Finance finds and approves the PO from Approval Queue', actor: 'finance_officer', uiPath: 'Work → Approval Queue → PO card → PO detail', expectedStatus: 'sent when supplier portal dispatch completes' },
  { name: 'Purchasing downloads and verifies the issued PO PDF', actor: 'purchasing_officer', uiPath: 'PO detail → PDF', expectedStatus: 'sent' },
  { name: 'Supplier finds and accepts the PO through its portal', actor: 'supplier_portal', uiPath: 'Supplier portal → Purchase Orders → PO row → Accept', expectedStatus: 'acknowledged' },
];

function actorByName(actors: E2EActor[], actor: string): E2EActor {
  const match = actors.find((candidate) => candidate.actor === actor);
  if (!match) throw new Error(`Required seeded E2E actor is missing: ${actor}`);
  return match;
}

function sqlText(value: string): string {
  return `'${value.replaceAll("'", "''")}'`;
}

function value<T>(context: JourneyContext, key: string): T {
  const result = context.values[key];
  if (result === undefined) throw new Error(`Journey context is missing ${key}.`);
  return result as T;
}

function futureManilaDate(daysAhead: number): string {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit',
  }).formatToParts(new Date());
  const part = (type: string) => parts.find((entry) => entry.type === type)?.value ?? '';
  const date = new Date(`${part('year')}-${part('month')}-${part('day')}T12:00:00+08:00`);
  date.setUTCDate(date.getUTCDate() + daysAhead);
  return date.toISOString().slice(0, 10);
}

async function openInternalMenuItem(page: ActorPage['page'], section: string, label: string, href?: string): Promise<void> {
  const target = href
    ? page.getByRole('navigation', { name: 'Modules' }).locator(`a[href="${href}"]`)
    : page.getByRole('link', { name: label, exact: true });
  if (href) await expect(target, `sidebar should contain its ${label} link`).toHaveCount(1);
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

async function openInternalHome(actor: ActorPage): Promise<void> {
  await actor.page.goto('/dashboard');
  await expect(actor.page).toHaveURL(/\/dashboard(?:\/|$)/);
}

async function openPortalHome(actor: ActorPage): Promise<void> {
  await actor.page.goto('/portal/supplier');
  await expect(actor.page).toHaveURL(/\/portal\/supplier(?:\/|$)/);
}

async function openApprovalCard(actor: ActorPage, number: string): Promise<void> {
  await openInternalHome(actor);
  await openInternalMenuItem(actor.page, 'Work', 'Approval Queue', '/approvals');
  const card = actor.page.getByRole('button').filter({ hasText: number });
  await expect(card).toHaveCount(1);
  await card.click();
}

async function assertPrDatabase(
  context: JourneyContext,
  expectedStatus: string,
  requesterEmail: string,
): Promise<PrRow> {
  const prNumber = value<string>(context, 'prNumber');
  const rows = await readOnlyDatabaseJson<PrRow[]>(`
    SELECT pr.pr_number, pr.status, pr.po_conversion_status,
           COALESCE(SUM(line.quantity * line.estimated_unit_price), 0)::text AS estimated_total,
           requester.email AS requester_email
    FROM purchase_requests pr
    JOIN users requester ON requester.id = pr.requested_by
    LEFT JOIN purchase_request_items line ON line.purchase_request_id = pr.id AND line.deleted_at IS NULL
    WHERE pr.pr_number = ${sqlText(prNumber)}
    GROUP BY pr.id, requester.email
  `);
  expect(rows, `one E2E PR should exist for ${prNumber}`).toHaveLength(1);
  expect(rows[0].status).toBe(expectedStatus);
  expect(rows[0].requester_email).toBe(requesterEmail);
  return rows[0];
}

async function assertPrApprovals(context: JourneyContext, expectedFinanceAction: string): Promise<ApprovalRow[]> {
  const prNumber = value<string>(context, 'prNumber');
  const rows = await readOnlyDatabaseJson<ApprovalRow[]>(`
    SELECT approval.step_order, approval.role_slug, approval.action, approver.email AS approver_email
    FROM approval_records approval
    LEFT JOIN users approver ON approver.id = approval.approver_id
    WHERE approval.approvable_id = (SELECT id FROM purchase_requests WHERE pr_number = ${sqlText(prNumber)})
      AND approval.approvable_type ILIKE '%PurchaseRequest'
      AND approval.is_current = true
    ORDER BY approval.step_order
  `);
  expect(rows.map(({ role_slug, action }) => [role_slug, action])).toEqual([
    ['finance_officer', expectedFinanceAction],
    ['vice_president', 'skipped'],
  ]);
  return rows;
}

async function assertAuditActor(
  context: JourneyContext,
  modelName: 'PurchaseRequest' | 'PurchaseOrder',
  expectedEmail: string,
): Promise<void> {
  const number = value<string>(context, modelName === 'PurchaseRequest' ? 'prNumber' : 'poNumber');
  const table = modelName === 'PurchaseRequest' ? 'purchase_requests' : 'purchase_orders';
  const keyColumn = modelName === 'PurchaseRequest' ? 'pr_number' : 'po_number';
  const rows = await readOnlyDatabaseJson<Array<{ email: string }>>(`
    SELECT DISTINCT actor.email
    FROM audit_logs log
    JOIN users actor ON actor.id = log.user_id
    WHERE log.model_type ILIKE ${sqlText(`%${modelName}%`)}
      AND log.model_id = (SELECT id FROM ${table} WHERE ${keyColumn} = ${sqlText(number)})
  `);
  expect(rows.map((row) => row.email), `${modelName} audit should identify ${expectedEmail}`).toContain(expectedEmail);
}

async function assertApprovalRecipients(context: JourneyContext, expectedEmails: string[]): Promise<void> {
  const number = value<string>(context, 'notificationNumber');
  const rows = await readOnlyDatabaseJson<Array<{ email: string }>>(`
    SELECT DISTINCT recipient.email
    FROM notifications notice
    JOIN users recipient
      ON notice.notifiable_type = 'App\\Modules\\Auth\\Models\\User'
     AND recipient.id = notice.notifiable_id
    WHERE notice.type = 'approval.action_required'
      AND notice.data ->> 'message' LIKE ${sqlText(`%${number}%`)}
  `);
  assertRecipientsExactly(rows.map((row) => row.email), expectedEmails);
}

async function assertBadgeCount(page: ActorPage['page'], linkName: string, expectedCount: number): Promise<void> {
  const link = page.getByRole('link', { name: new RegExp(linkName) });
  const badge = link.locator('[aria-label*=" — "]');
  await expect(badge).toHaveText(String(expectedCount));
}

async function expectStatus(page: ActorPage['page'], status: string): Promise<void> {
  await expect(page.locator('[data-layout="page-header"]').getByText(status, { exact: true })).toBeVisible();
}

async function queryPo(context: JourneyContext): Promise<PoRow> {
  const poNumber = value<string>(context, 'poNumber');
  const rows = await readOnlyDatabaseJson<PoRow[]>(`
    SELECT po.po_number, po.status, po.subtotal::text AS subtotal,
           po.vat_amount::text AS vat_amount, po.total_amount::text AS total_amount,
           pr.pr_number, vendor.name AS vendor_name, vendor.email AS vendor_email
    FROM purchase_orders po
    JOIN vendors vendor ON vendor.id = po.vendor_id
    LEFT JOIN purchase_requests pr ON pr.id = po.purchase_request_id
    WHERE po.po_number = ${sqlText(poNumber)}
  `);
  expect(rows, `one E2E PO should exist for ${poNumber}`).toHaveLength(1);
  return rows[0];
}

async function assertPoAuditActor(context: JourneyContext, expectedEmail: string): Promise<void> {
  await assertAuditActor(context, 'PurchaseOrder', expectedEmail);
}

test('PR01-DIRECT-PO-UNDER-THRESHOLD — department need to supplier-acknowledged PO', async ({ browser }, testInfo) => {
  test.setTimeout(8 * 60 * 1000);
  const actors = await loadActors();
  const requesterIdentity = actorByName(actors, 'department_head__hr');
  const financeIdentity = actorByName(actors, 'finance_officer');
  const financeAudience = actors.filter((candidate) => candidate.role === 'finance_officer').map((candidate) => candidate.email);
  const context = createJourneyContext('PR01-DIRECT-PO-UNDER-THRESHOLD');
  const actorSessions: Array<{ actor: string; session: ActorPage }> = [];

  const openActor = async (actor: string): Promise<ActorPage> => {
    const session = await actorPage(browser, actor);
    actorSessions.push({ actor, session });
    return session;
  };

  const requester = await openActor(requesterIdentity.actor);
  const prObserver = await openActor('purchasing_officer__2');
  const buyer = await openActor('purchasing_officer');
  const poObserver = await openActor('purchasing_officer__2');
  const finance = await openActor(financeIdentity.actor);
  const supplier = await openActor('supplier_portal');
  const usedDocumentNumbers = new Set<string>();

  const checkDiagnostics = (session: ActorPage) => session.diagnostics.assertClean(context.journeyId);
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
    assertInvariants: async () => { await invariants(); checkDiagnostics(session); },
  });

  try {
    await step(
      requesterIdentity.actor,
      'Create a department-owned draft PR',
      'Procurement → Purchase Requests → New PR → Save draft',
      'draft',
      requester,
      async () => {
        await openInternalHome(requester);
        await openInternalMenuItem(requester.page, 'Procurement', 'Purchase Requests', '/purchasing/purchase-requests');
        await requester.page.getByRole('button', { name: 'New PR', exact: true }).click();
        await expect(requester.page.getByRole('heading', { name: 'New purchase request' })).toBeVisible();
        await requester.page.getByRole('combobox', { name: 'Priority', exact: true }).selectOption('normal');
        await requester.page.getByRole('combobox', { name: 'Sourcing method', exact: true }).selectOption('direct_po');
        await requester.page.getByLabel('Needed by', { exact: true }).fill(futureManilaDate(14));
        await requester.page.getByLabel('Reason', { exact: true }).fill(`${context.values.runPrefix} resin replenishment for the HR maintenance cell`);
        const itemSelect = requester.page.getByRole('combobox', { name: 'Item', exact: true });
        await itemSelect.fill('RM-001');
        const catalogOption = requester.page.getByRole('option').filter({ hasText: 'RM-001' });
        await expect(catalogOption).toBeVisible();
        await catalogOption.click();
        await requester.page.getByLabel('Quantity', { exact: true }).fill('2');
        await expect(requester.page.getByLabel('Estimated unit price', { exact: true })).toHaveValue('120.00');
        await requester.page.getByRole('button', { name: 'Save draft', exact: true }).click();
        await expect(requester.page).toHaveURL(/\/purchasing\/purchase-requests\/[^/?#]+(?:[?#].*)?$/);
        await expect(requester.page).not.toHaveURL(/\/purchasing\/purchase-requests\/new(?:[/?#]|$)/);
        const prHeading = requester.page.getByRole('heading', { level: 1, name: /^PR-/ });
        await expect(prHeading).toBeVisible();
        const title = await prHeading.innerText();
        const prNumber = title.match(/PR-[A-Za-z0-9-]+/)?.[0];
        expect(prNumber, `PR document number should appear in its detail heading: ${title}`).toBeTruthy();
        context.values.prNumber = prNumber;
        context.values.department = 'HR';
        context.values.itemCode = 'RM-001';
        context.values.quantity = 2;
        context.values.estimatedUnitPrice = 120;
        context.values.neededBy = futureManilaDate(14);
        context.values.requesterEmail = requesterIdentity.email;
        assertDocumentNumberUnique(usedDocumentNumbers, prNumber!);
      },
      async () => {
        await expectStatus(requester.page, 'Draft');
        await expect(requester.page.getByRole('button', { name: 'Submit', exact: true })).toBeVisible();
        await expect(requester.page.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
      },
      async () => {
        const row = await assertPrDatabase(context, 'draft', requesterIdentity.email);
        assertTotalsEqual('PR line estimate', Number(row.estimated_total), 240);
        await assertAuditActor(context, 'PurchaseRequest', requesterIdentity.email);
        const aslRows = await readOnlyDatabaseJson<Array<{ qualification_status: string; is_preferred: boolean }>>(`
          SELECT supplier.qualification_status, supplier.is_preferred
          FROM approved_suppliers supplier
          JOIN items item ON item.id = supplier.item_id
          JOIN vendors vendor ON vendor.id = supplier.vendor_id
          WHERE item.code = 'RM-001' AND vendor.name = 'E2E Supplier Ltd.'
        `);
        expect(aslRows).toEqual([{ qualification_status: 'approved', is_preferred: true }]);
      },
    );

    await step(
      'purchasing_officer__2',
      'Open the PR from the purchasing list as a live observer',
      'Procurement → Purchase Requests → matching PR row',
      'draft',
      prObserver,
      async () => {
        await openInternalHome(prObserver);
        await openInternalMenuItem(prObserver.page, 'Procurement', 'Purchase Requests', '/purchasing/purchase-requests');
        const row = prObserver.page.getByRole('row').filter({ hasText: value<string>(context, 'prNumber') });
        await expect(row).toHaveCount(1);
        await row.click();
        await expect(prObserver.page).toHaveURL(/\/purchasing\/purchase-requests\/[^/]+$/);
        await expectStatus(prObserver.page, 'Draft');
      },
      async () => {
        await expect(prObserver.page.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
        expect((await assertPrDatabase(context, 'draft', requesterIdentity.email)).pr_number).toBe(value<string>(context, 'prNumber'));
      },
      async () => { await assertAuditActor(context, 'PurchaseRequest', requesterIdentity.email); },
    );

    await step(
      requesterIdentity.actor,
      'Submit the draft PR through its detail action',
      'PR detail → Submit → confirmation dialog',
      'pending',
      requester,
      async () => {
        await requester.page.getByRole('button', { name: 'Submit', exact: true }).click();
        const dialog = requester.page.getByRole('dialog');
        await expect(dialog.getByRole('heading', { name: 'Submit for approval?' })).toBeVisible();
        await dialog.getByRole('button', { name: 'Submit', exact: true }).click();
        await expectStatus(requester.page, 'Pending');
        context.values.notificationNumber = value<string>(context, 'prNumber');
      },
      async () => {
        await expect(requester.page.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
        await expectStatus(prObserver.page, 'Pending');
      },
      async () => {
        const row = await assertPrDatabase(context, 'pending', requesterIdentity.email);
        assertTotalsEqual('PR amount versus under-threshold ceiling', Number(row.estimated_total), 240);
        const approvals = await assertPrApprovals(context, 'pending');
        expect(approvals[0].approver_email).toBeNull();
        await assertApprovalRecipients(context, financeAudience);
        await assertAuditActor(context, 'PurchaseRequest', requesterIdentity.email);
        checkDiagnostics(prObserver);
      },
    );

    await step(
      financeIdentity.actor,
      'Finance finds and approves the PR from Approval Queue',
      'Work → Approval Queue → matching PR card → Approve',
      'approved',
      finance,
      async () => {
        await openApprovalCard(finance, value<string>(context, 'prNumber'));
        await expectStatus(finance.page, 'Pending');
        await expect(finance.page.getByRole('button', { name: 'Approve', exact: true })).toBeEnabled();
        await expect(finance.page.getByRole('button', { name: 'Reject', exact: true })).toBeEnabled();
        await assertBadgeCount(finance.page, 'Approval Queue', 1);
        await finance.page.getByRole('button', { name: 'Approve', exact: true }).click();
        const dialog = finance.page.getByRole('dialog');
        await expect(dialog.getByRole('heading', { name: 'Approve this PR?' })).toBeVisible();
        await dialog.getByRole('button', { name: 'Approve', exact: true }).click();
        await expectStatus(finance.page, 'Approved');
      },
      async () => {
        await expectStatus(prObserver.page, 'Approved');
        await expect(prObserver.page.getByRole('button', { name: 'Convert to PO', exact: true })).toHaveCount(0);
      },
      async () => {
        await assertPrDatabase(context, 'approved', requesterIdentity.email);
        const approvals = await assertPrApprovals(context, 'approved');
        expect(approvals[0]).toMatchObject({ role_slug: 'finance_officer', action: 'approved', approver_email: financeIdentity.email });
        expect(approvals[1]).toMatchObject({ role_slug: 'vice_president', action: 'skipped', approver_email: null });
        await assertAuditActor(context, 'PurchaseRequest', financeIdentity.email);
        checkDiagnostics(prObserver);
      },
    );

    await step(
      'purchasing_officer',
      'Purchasing converts the approved PR using its qualified supplier',
      'Procurement → Purchase Requests → matching PR row → Convert to PO',
      'draft PO / converted PR',
      buyer,
      async () => {
        await openInternalHome(buyer);
        await openInternalMenuItem(buyer.page, 'Procurement', 'Purchase Requests', '/purchasing/purchase-requests');
        const row = buyer.page.getByRole('row').filter({ hasText: value<string>(context, 'prNumber') });
        await expect(row).toHaveCount(1);
        await row.click();
        await expectStatus(buyer.page, 'Approved');
        await expect(buyer.page.getByRole('button', { name: 'Convert to PO', exact: true })).toBeEnabled();
        await buyer.page.getByRole('button', { name: 'Convert to PO', exact: true }).click();
        const dialog = buyer.page.getByRole('dialog');
        await expect(dialog.getByRole('heading', { name: new RegExp(value<string>(context, 'prNumber')) })).toBeVisible();
        const supplierSelect = dialog.getByRole('combobox', { name: 'Supplier', exact: true });
        await expect(supplierSelect).toHaveValue(/.+/);
        await expect(dialog.getByText(/Not an approved supplier for this item/)).toHaveCount(0);
        await dialog.getByRole('button', { name: 'Create purchase order', exact: true }).click();
        await expect(buyer.page).toHaveURL(/\/purchasing\/purchase-orders\/[^/]+$/);
        const title = await buyer.page.locator('h1').innerText();
        const poNumber = title.match(/PO-[A-Za-z0-9-]+/)?.[0];
        expect(poNumber, `PO document number should appear in its detail heading: ${title}`).toBeTruthy();
        context.values.poNumber = poNumber;
        context.values.poId = buyer.page.url().split('/').at(-1);
        assertDocumentNumberUnique(usedDocumentNumbers, poNumber!);
      },
      async () => {
        await expectStatus(buyer.page, 'Draft');
        await expect(buyer.page.getByRole('button', { name: 'Submit', exact: true })).toBeVisible();
        await expect(buyer.page.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
      },
      async () => {
        const pr = await assertPrDatabase(context, 'converted', requesterIdentity.email);
        expect(pr.po_conversion_status).toBe('converted');
        const po = await queryPo(context);
        expect(po).toMatchObject({ status: 'draft', pr_number: value<string>(context, 'prNumber'), vendor_name: 'E2E Supplier Ltd.' });
        assertTotalsEqual('PO subtotal versus PR estimate', Number(po.subtotal), Number(pr.estimated_total));
        assertTotalsEqual('PO subtotal plus VAT versus PO total', Number(po.subtotal) + Number(po.vat_amount), Number(po.total_amount));
        await assertPoAuditActor(context, actorByName(actors, 'purchasing_officer').email);
      },
    );

    await step(
      'purchasing_officer__2',
      'Open the PO from the purchasing list as a live observer',
      'Procurement → Purchase Orders → matching PO row',
      'draft',
      poObserver,
      async () => {
        await openInternalHome(poObserver);
        await openInternalMenuItem(poObserver.page, 'Procurement', 'Purchase Orders', '/purchasing/purchase-orders');
        const row = poObserver.page.getByRole('row').filter({ hasText: value<string>(context, 'poNumber') });
        await expect(row).toHaveCount(1);
        await row.click();
        await expect(poObserver.page).toHaveURL(/\/purchasing\/purchase-orders\/[^/]+$/);
        await expectStatus(poObserver.page, 'Draft');
      },
      async () => {
        await expect(poObserver.page.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
        expect((await queryPo(context)).status).toBe('draft');
      },
      async () => { await assertPoAuditActor(context, actorByName(actors, 'purchasing_officer').email); },
    );

    await step(
      'purchasing_officer',
      'Purchasing submits the draft PO for approval',
      'PO detail → Submit → confirmation dialog',
      'pending_approval',
      buyer,
      async () => {
        await buyer.page.getByRole('button', { name: 'Submit', exact: true }).click();
        const dialog = buyer.page.getByRole('dialog');
        await expect(dialog.getByRole('heading', { name: 'Submit PO for approval?' })).toBeVisible();
        await dialog.getByRole('button', { name: 'Submit', exact: true }).click();
        await expectStatus(buyer.page, 'Pending approval');
        context.values.notificationNumber = value<string>(context, 'poNumber');
      },
      async () => {
        await expectStatus(poObserver.page, 'Pending approval');
      },
      async () => {
        expect((await queryPo(context)).status).toBe('pending_approval');
        await assertApprovalRecipients(context, financeAudience);
        await assertPoAuditActor(context, actorByName(actors, 'purchasing_officer').email);
        checkDiagnostics(poObserver);
      },
    );

    await step(
      financeIdentity.actor,
      'Finance finds and approves the PO from Approval Queue',
      'Work → Approval Queue → matching PO card → Approve',
      'sent when portal dispatch succeeds',
      finance,
      async () => {
        await openApprovalCard(finance, value<string>(context, 'poNumber'));
        await expectStatus(finance.page, 'Pending approval');
        await expect(finance.page.getByRole('button', { name: 'Approve', exact: true })).toBeEnabled();
        await finance.page.getByRole('button', { name: 'Approve', exact: true }).click();
        const dialog = finance.page.getByRole('dialog');
        await expect(dialog.getByRole('heading', { name: 'Approve this PO?' })).toBeVisible();
        await dialog.getByRole('button', { name: 'Approve', exact: true }).click();
      },
      async () => {
        // With an active supplier portal recipient, the approved-PO dispatch
        // automatically publishes the portal order and advances sent status.
        await expectStatus(poObserver.page, 'Sent');
        await expectStatus(buyer.page, 'Sent');
        await expect(buyer.page.getByRole('button', { name: 'Mark as sent', exact: true })).toHaveCount(0);
      },
      async () => {
        const po = await queryPo(context);
        expect(po.status).toBe('sent');
        const approvals = await readOnlyDatabaseJson<Array<{ role_slug: string; action: string; approver_email: string | null }>>(`
          SELECT approval.role_slug, approval.action, approver.email AS approver_email
          FROM approval_records approval
          LEFT JOIN users approver ON approver.id = approval.approver_id
          WHERE approval.approvable_id = (SELECT id FROM purchase_orders WHERE po_number = ${sqlText(value<string>(context, 'poNumber'))})
            AND approval.approvable_type ILIKE '%PurchaseOrder'
            AND approval.is_current = true
          ORDER BY approval.step_order
        `);
        expect(approvals).toEqual([
          { role_slug: 'finance_officer', action: 'approved', approver_email: financeIdentity.email },
          { role_slug: 'vice_president', action: 'skipped', approver_email: null },
        ]);
        await assertPoAuditActor(context, financeIdentity.email);
        await assertApprovalRecipients(context, financeAudience);
        const dispatch = await readOnlyDatabaseJson<Array<{ status: string; channel: string | null; recipient_count: number }>>(`
          SELECT dispatch.status, dispatch.channel, dispatch.recipient_count
          FROM supplier_order_dispatches dispatch
          JOIN purchase_orders po ON po.id = dispatch.purchase_order_id
          WHERE po.po_number = ${sqlText(value<string>(context, 'poNumber'))}
        `);
        expect(dispatch).toEqual([{ status: 'confirmed', channel: 'supplier_portal', recipient_count: 1 }]);
        const poNoticeRows = await readOnlyDatabaseJson<Array<{ email: string }>>(`
          SELECT recipient.email
          FROM notifications notice
          JOIN supplier_portal_users recipient
            ON notice.notifiable_type = 'App\\Modules\\B2B\\Models\\SupplierPortalUser'
           AND recipient.id = notice.notifiable_id
          WHERE notice.type = 'purchase_order.published'
            AND notice.data ->> 'message' LIKE ${sqlText(`%${value<string>(context, 'poNumber')}%`)}
        `);
        assertRecipientsExactly(poNoticeRows.map((row) => row.email), [actorByName(actors, 'supplier_portal').email]);
        const vendorEmails = await readOnlyDatabaseJson<Array<{ email: string }>>(`
          SELECT vendor.email
          FROM purchase_orders po JOIN vendors vendor ON vendor.id = po.vendor_id
          WHERE po.po_number = ${sqlText(value<string>(context, 'poNumber'))}
        `);
        expect(vendorEmails).toHaveLength(1);
        context.values.vendorEmail = vendorEmails[0].email;
        await expect.poll(
          async () => findMailpitMessagesFor(vendorEmails[0].email),
          { timeout: 20_000, message: 'supplier PO email should reach the isolated Mailpit recipient' },
        ).toHaveLength(1);
        const messages = await findMailpitMessagesFor(vendorEmails[0].email);
        const body = JSON.stringify(await getMailpitMessage(messages[0].ID));
        expect(body).toContain(value<string>(context, 'poNumber'));
        checkDiagnostics(poObserver);
        checkDiagnostics(buyer);
      },
    );

    await step(
      'purchasing_officer',
      'Purchasing downloads and verifies the issued PO PDF',
      'PO detail → PDF button',
      'sent',
      buyer,
      async () => {
        const poId = value<string>(context, 'poId');
        const responsePromise = buyer.page.waitForResponse((response) => {
          const url = new URL(response.url());
          return url.pathname === `/api/v1/purchasing/purchase-orders/${poId}/pdf` && response.status() === 200;
        });
        await buyer.page.getByRole('button', { name: 'PDF', exact: true }).click();
        const response = await responsePromise;
        const bytes = await response.body();
        const pdfText = await readPdfBytes(Buffer.from(bytes));
        const po = await queryPo(context);
        expectPdfContains(pdfText, [po.po_number, po.vendor_name, value<string>(context, 'itemCode'), Number(po.total_amount).toFixed(2)]);
        context.values.pdfVerified = true;
      },
      async () => {
        await expectStatus(buyer.page, 'Sent');
      },
      async () => {
        expect(context.values.pdfVerified).toBe(true);
        const po = await queryPo(context);
        expect(po.status).toBe('sent');
        await assertPoAuditActor(context, actorByName(actors, 'purchasing_officer').email);
      },
    );

    await step(
      'supplier_portal',
      'Supplier finds and accepts the PO through its portal',
      'Supplier portal → Purchase Orders → matching PO row → Accept PO',
      'acknowledged',
      supplier,
      async () => {
        await openPortalHome(supplier);
        await openInternalMenuItem(supplier.page, 'Supplier', 'Purchase Orders');
        const row = supplier.page.getByRole('row').filter({ hasText: value<string>(context, 'poNumber') });
        await expect(row).toHaveCount(1);
        await row.click();
        await expectStatus(supplier.page, 'Sent');
        await expect(supplier.page.getByRole('button', { name: 'Accept', exact: true })).toBeEnabled();
        await supplier.page.getByRole('button', { name: 'Accept', exact: true }).click();
        const dialog = supplier.page.getByRole('dialog');
        await expect(dialog.getByRole('heading', { name: 'Accept purchase order' })).toBeVisible();
        await dialog.getByRole('button', { name: 'Accept PO', exact: true }).click();
        await expectStatus(supplier.page, 'Acknowledged');
      },
      async () => {
        await expectStatus(poObserver.page, 'Acknowledged');
        await expectStatus(buyer.page, 'Acknowledged');
      },
      async () => {
        const po = await queryPo(context);
        expect(po.status).toBe('acknowledged');
        expect(po.pr_number).toBe(value<string>(context, 'prNumber'));
        assertTotalsEqual('PO subtotal plus VAT versus PO total', Number(po.subtotal) + Number(po.vat_amount), Number(po.total_amount));
        const supplierAudit = await readOnlyDatabaseJson<Array<{ actor_type: string }>>(`
          SELECT DISTINCT log.actor_type
          FROM audit_logs log
          WHERE log.model_type ILIKE '%PurchaseOrder'
            AND log.model_id = (SELECT id FROM purchase_orders WHERE po_number = ${sqlText(value<string>(context, 'poNumber'))})
        `);
        expect(supplierAudit.map((entry) => entry.actor_type)).toContain('supplier_portal');
        checkDiagnostics(poObserver);
        checkDiagnostics(buyer);
      },
    );

    testInfo.annotations.push({ type: 'journey', description: context.journeyId });
    testInfo.annotations.push({ type: 'run-id', description: context.runId });
  } finally {
    for (const { session } of actorSessions) await session.context.close().catch(() => undefined);
  }
});
