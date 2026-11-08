import { test, expect } from './fixtures';
import { actorPage, loadActors } from './actor-session';
import type { E2EActor } from './actor-session';
import { createJourneyContext, runProcessStep } from './process-runner';
import type { JourneyContext } from './process-runner';
import { assertRecipientsExactly } from './helpers/process-invariants';
import { readOnlyDatabaseJson } from './helpers/database-reader';
import { captureAnnotatedStep } from './helpers/annotated-screenshot';

type ActorPage = Awaited<ReturnType<typeof actorPage>>;
type AdjustmentRow = {
  id: number;
  item_code: string;
  status: string;
  direction: string;
  quantity: string;
  unit_cost: string;
  value: string;
  stock_movement_id: number | null;
  on_hand: string;
  requester_email: string;
  approver_email: string | null;
  movement_type: string | null;
  movement_quantity: string | null;
  movement_total_cost: string | null;
  movement_actor_email: string | null;
  movement_count: number;
  resolved_by_email: string | null;
  resolution_reason: string | null;
};

const stockAdjustmentSubjectType = 'App\\Modules\\Inventory\\Models\\StockAdjustment';

const plannedSteps = [
  { name: 'Create a high-value inbound adjustment as Warehouse', actor: 'warehouse_staff', uiPath: 'Inventory → Stock Adjustments → New adjustment → Submit', expectedStatus: 'pending' },
  { name: 'Finance finds the work through its approval notification', actor: 'finance_officer', uiPath: 'Work → Notifications → Stock adjustment needs approval', expectedStatus: 'pending' },
  { name: 'Finance approves; Warehouse observes the live stock transition', actor: 'finance_officer', uiPath: 'Linked Stock Adjustments row → Approve', expectedStatus: 'approved' },
];

function actorByName(actors: E2EActor[], actor: string): E2EActor {
  const match = actors.find((candidate) => candidate.actor === actor);
  if (!match) throw new Error(`Required seeded E2E actor is missing: ${actor}`);
  return match;
}

function sqlText(value: string): string {
  return `'${value.replaceAll("'", "''")}'`;
}

function contextValue<T>(context: JourneyContext, key: string): T {
  const result = context.values[key];
  if (result === undefined) throw new Error(`Journey context is missing ${key}.`);
  return result as T;
}

async function openInternalHome(actor: ActorPage): Promise<void> {
  await actor.page.goto('/dashboard');
  await expect(actor.page).toHaveURL(/\/dashboard(?:\/|$)/);
}

async function openMenuItem(page: ActorPage['page'], section: string, label: string, href: string): Promise<void> {
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

async function openTransfers(session: ActorPage): Promise<void> {
  await openInternalHome(session);
  await openMenuItem(session.page, 'Warehouse', 'Inventory Transfers', '/inventory/transfer-orders');
  await expect(session.page.getByRole('heading', { name: 'Transfer Orders', exact: true })).toBeVisible();
  await expect(session.page.getByRole('status', { name: /Real-time updates: Live/ })).toBeVisible();
}

async function adjustmentByReason(context: JourneyContext, reason = contextValue<string>(context, 'reason')): Promise<AdjustmentRow> {
  const rows = await readOnlyDatabaseJson<AdjustmentRow[]>(`
    SELECT adjustment.id, item.code AS item_code, adjustment.status,
           adjustment.direction, adjustment.quantity::text, adjustment.unit_cost::text,
           adjustment.value::text, adjustment.stock_movement_id,
           COALESCE(level.quantity, 0)::numeric(14, 3)::text AS on_hand,
           requester.email AS requester_email, approver.email AS approver_email,
           movement.movement_type, movement.quantity::text AS movement_quantity,
           movement.total_cost::text AS movement_total_cost,
           movement_actor.email AS movement_actor_email,
           resolver.email AS resolved_by_email, adjustment.resolution_reason,
           (SELECT COUNT(*)::int FROM stock_movements counted
            WHERE counted.reference_type = 'stock_adjustment'
              AND counted.reference_id = adjustment.id) AS movement_count
    FROM stock_adjustments adjustment
    JOIN items item ON item.id = adjustment.item_id
    JOIN users requester ON requester.id = adjustment.requested_by
    LEFT JOIN users approver ON approver.id = adjustment.approved_by
    LEFT JOIN stock_levels level
      ON level.item_id = adjustment.item_id AND level.location_id = adjustment.location_id
    LEFT JOIN stock_movements movement ON movement.id = adjustment.stock_movement_id
    LEFT JOIN users movement_actor ON movement_actor.id = movement.created_by
    LEFT JOIN users resolver ON resolver.id = adjustment.resolved_by
    WHERE adjustment.reason = ${sqlText(reason)}
  `);
  expect(rows, `one stock adjustment should match ${reason}`).toHaveLength(1);
  return rows[0];
}

async function notificationRows(context: JourneyContext, reason = contextValue<string>(context, 'reason')): Promise<Array<{
  type: string;
  email: string;
  notification_id: string;
  entity_id: string;
  link_to: string;
  read_at: string | null;
}>> {
  return readOnlyDatabaseJson<Array<{
    type: string;
    email: string;
    notification_id: string;
    entity_id: string;
    link_to: string;
    read_at: string | null;
  }>>(`
    SELECT notice.type, recipient.email, notice.id::text AS notification_id,
           notice.data ->> 'entity_id' AS entity_id,
           notice.data ->> 'link_to' AS link_to,
           notice.read_at::text AS read_at
    FROM notifications notice
    JOIN users recipient
      ON notice.notifiable_type LIKE '%Auth%User'
     AND recipient.id = notice.notifiable_id
    WHERE notice.data ->> 'subject_type' = ${sqlText(stockAdjustmentSubjectType)}
      AND notice.data ->> 'subject_id' = (
        SELECT adjustment.id::text FROM stock_adjustments adjustment
        WHERE adjustment.reason = ${sqlText(reason)}
      )
  `);
}

async function submitHighValueInboundAdjustment(
  session: ActorPage,
  reason: string,
  quantity: number,
): Promise<void> {
  await openMenuItem(session.page, 'Warehouse', 'Stock Adjustments', '/inventory/stock-adjustments');
  await expect(session.page.getByRole('heading', { name: 'Stock Adjustments', exact: true })).toBeVisible();
  await session.page.getByRole('link', { name: 'New adjustment', exact: true }).click();
  await expect(session.page.getByRole('heading', { name: 'Stock adjustment', exact: true })).toBeVisible();

  await session.page.getByRole('combobox', { name: /Direction/ }).selectOption('in');
  const itemInput = session.page.getByRole('combobox', { name: 'Item', exact: true });
  await itemInput.fill('RM-001');
  const itemOption = session.page.getByRole('option').filter({ hasText: 'RM-001' }).first();
  await expect(itemOption).toBeVisible();
  await itemOption.click();

  const location = session.page.getByRole('combobox', { name: /Location/ });
  await expect(location).toBeEnabled();
  await location.click();
  const firstLocation = session.page.getByRole('option').filter({ hasText: 'Main Warehouse' }).first();
  await expect(firstLocation).toBeVisible();
  await firstLocation.click();

  await session.page.getByRole('spinbutton', { name: /Quantity/ }).fill(String(quantity));
  await session.page.getByRole('spinbutton', { name: /Unit cost/ }).fill('120');
  await session.page.getByRole('combobox', { name: /Reason code/ }).selectOption('found_stock');
  await session.page.getByRole('textbox', { name: /Reason \(recorded for audit\)/ }).fill(reason);
  await expect(session.page.getByText('Finance approval required', { exact: true })).toBeVisible();
  await session.page.getByRole('button', { name: 'Review adjustment', exact: true }).click();
  const confirmation = session.page.getByRole('dialog');
  await expect(confirmation.getByRole('heading', { name: 'Submit stock adjustment?' })).toBeVisible();
  await expect(confirmation).toContainText('Stock stays unchanged until Finance approves.');
  await confirmation.getByRole('button', { name: 'Submit adjustment', exact: true }).click();

  await expect(session.page).toHaveURL(/\/inventory\/stock-adjustments\?status=pending(?:&|$)/);
  const row = session.page.getByRole('row').filter({ hasText: reason });
  await expect(row).toHaveCount(1);
  await expect(row).toContainText('Awaiting Finance approval');
}

async function submitLowValueInboundAdjustment(session: ActorPage, reason: string): Promise<void> {
  await openMenuItem(session.page, 'Warehouse', 'Stock Adjustments', '/inventory/stock-adjustments');
  await expect(session.page.getByRole('heading', { name: 'Stock Adjustments', exact: true })).toBeVisible();
  await session.page.getByRole('link', { name: 'New adjustment', exact: true }).click();
  await expect(session.page.getByRole('heading', { name: 'Stock adjustment', exact: true })).toBeVisible();

  await session.page.getByRole('combobox', { name: /Direction/ }).selectOption('in');
  const itemInput = session.page.getByRole('combobox', { name: 'Item', exact: true });
  await itemInput.fill('RM-001');
  const itemOption = session.page.getByRole('option').filter({ hasText: 'RM-001' }).first();
  await expect(itemOption).toBeVisible();
  await itemOption.click();

  const location = session.page.getByRole('combobox', { name: /Location/ });
  await expect(location).toBeEnabled();
  await location.click();
  const firstLocation = session.page.getByRole('option').filter({ hasText: 'Main Warehouse' }).first();
  await expect(firstLocation).toBeVisible();
  await firstLocation.click();

  await session.page.getByRole('spinbutton', { name: /Quantity/ }).fill('1');
  await session.page.getByRole('spinbutton', { name: /Unit cost/ }).fill('120');
  await session.page.getByRole('combobox', { name: /Reason code/ }).selectOption('found_stock');
  await session.page.getByRole('textbox', { name: /Reason \(recorded for audit\)/ }).fill(reason);
  await expect(session.page.getByText('Finance approval required', { exact: true })).toHaveCount(0);
  await session.page.getByRole('button', { name: 'Review adjustment', exact: true }).click();
  const confirmation = session.page.getByRole('dialog');
  await expect(confirmation.getByRole('heading', { name: 'Submit stock adjustment?' })).toBeVisible();
  await expect(confirmation).toContainText('Stock changes when the adjustment is posted.');
  await confirmation.getByRole('button', { name: 'Submit adjustment', exact: true }).click();

  await expect(session.page).toHaveURL('/inventory/stock-levels?view=movements');
  await expect(session.page.getByRole('heading', { name: 'Stock movements', exact: true })).toBeVisible();
  await openMenuItem(session.page, 'Warehouse', 'Stock Adjustments', '/inventory/stock-adjustments');
  const row = session.page.getByRole('row').filter({ hasText: reason });
  await expect(row).toHaveCount(1);
  await expect(row).toContainText('Approved by');
}

async function assertAuditActors(
  context: JourneyContext,
  expectedEmails: string[],
  reason = contextValue<string>(context, 'reason'),
): Promise<void> {
  const rows = await readOnlyDatabaseJson<Array<{ email: string }>>(`
    SELECT DISTINCT actor.email
    FROM audit_logs log
    JOIN users actor ON actor.id = log.user_id
    WHERE log.model_type ILIKE '%StockAdjustment%'
      AND log.model_id = (
        SELECT adjustment.id FROM stock_adjustments adjustment
        WHERE adjustment.reason = ${sqlText(reason)}
      )
  `);
  for (const email of expectedEmails) expect(rows.map((row) => row.email)).toContain(email);
}

test('INV01-HIGH-VALUE-APPROVAL — Warehouse adjustment to Finance approval and posted stock', async ({ browser }, testInfo) => {
  test.setTimeout(3 * 60 * 1000);
  const actors = await loadActors();
  const warehouseIdentity = actorByName(actors, 'warehouse_staff');
  const financeIdentity = actorByName(actors, 'finance_officer');
  // The notification audience comes from inventory.adjust.approve, whose
  // permission resolver deliberately includes the system-admin wildcard.
  const expectedRecipients = actors
    .filter((candidate) => candidate.role === 'finance_officer' || candidate.role === 'system_admin')
    .map((candidate) => candidate.email);
  const journey = createJourneyContext('INV01-HIGH-VALUE-APPROVAL');
  const sessions: Array<{ actor: string; session: ActorPage }> = [];
  const openActor = async (actor: string): Promise<ActorPage> => {
    const session = await actorPage(browser, actor);
    sessions.push({ actor, session });
    return session;
  };
  const warehouse = await openActor(warehouseIdentity.actor);
  const finance = await openActor(financeIdentity.actor);
  const reason = `${journey.values.runPrefix} found stock after count variance`;
  journey.values.reason = reason;
  journey.values.itemCode = 'RM-001';
  journey.values.quantity = 250;
  journey.values.unitCost = 120;
  journey.values.value = 30000;
  journey.values.requesterEmail = warehouseIdentity.email;
  journey.values.approverEmail = financeIdentity.email;

  const checkDiagnostics = (session: ActorPage) => session.diagnostics.assertClean(journey.journeyId);
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
    name, actor, uiPath, expectedStatus, page: session.page, plannedSteps, context: journey, testInfo,
    action,
    verify,
    assertInvariants: async () => { await invariants(); checkDiagnostics(session); },
  });

  try {
    await step(
      warehouseIdentity.actor,
      'Create a high-value inbound adjustment as Warehouse',
      'Inventory → Stock Adjustments → New adjustment → Submit',
      'pending',
      warehouse,
      async () => {
        await openInternalHome(warehouse);
        const browserCrypto = await warehouse.page.evaluate(() => ({
          origin: window.location.origin,
          secureContext: window.isSecureContext,
          randomUUID: typeof window.crypto.randomUUID,
        }));
        expect(browserCrypto).toEqual({
          origin: new URL(process.env.E2E_BASE_URL!).origin,
          secureContext: true,
          randomUUID: 'function',
        });
        await openMenuItem(warehouse.page, 'Warehouse', 'Stock Adjustments', '/inventory/stock-adjustments');
        const routeObservation = await warehouse.page.evaluate(() => ({
          pathname: window.location.pathname,
          title: document.title,
          headings: Array.from(document.querySelectorAll('h1')).map((heading) => heading.textContent?.trim() ?? ''),
          rootChildCount: document.getElementById('root')?.childElementCount ?? 0,
        }));
        console.log('INV01 Stock Adjustments route observation', routeObservation);
        checkDiagnostics(warehouse);
        expect(routeObservation.pathname).toBe('/inventory/stock-adjustments');
        await expect(warehouse.page.getByRole('heading', { name: 'Stock Adjustments', exact: true })).toBeVisible();
        await warehouse.page.getByRole('link', { name: 'New adjustment', exact: true }).click();
        await expect(warehouse.page.getByRole('heading', { name: 'Stock adjustment', exact: true })).toBeVisible();

        await warehouse.page.getByRole('combobox', { name: /Direction/ }).selectOption('in');
        const itemInput = warehouse.page.getByRole('combobox', { name: 'Item', exact: true });
        await itemInput.fill('RM-001');
        const itemOption = warehouse.page.getByRole('option').filter({ hasText: 'RM-001' }).first();
        await expect(itemOption).toBeVisible();
        await itemOption.click();

        const location = warehouse.page.getByRole('combobox', { name: /Location/ });
        await expect(location).toBeEnabled();
        await location.click();
        const firstLocation = warehouse.page.getByRole('option').filter({ hasText: 'Main Warehouse' }).first();
        await expect(firstLocation, 'baseline must expose an active receiving location in the form').toBeVisible();
        journey.values.locationLabel = (await firstLocation.innerText()).trim();
        await firstLocation.click();

        await warehouse.page.getByRole('spinbutton', { name: /Quantity/ }).fill('250');
        await warehouse.page.getByRole('spinbutton', { name: /Unit cost/ }).fill('120');
        await warehouse.page.getByRole('combobox', { name: /Reason code/ }).selectOption('found_stock');
        await warehouse.page.getByRole('textbox', { name: /Reason \(recorded for audit\)/ }).fill(reason);
        await expect(warehouse.page.getByText('Finance approval required', { exact: true })).toBeVisible();
        await warehouse.page.getByRole('button', { name: 'Review adjustment', exact: true }).click();
        const confirmation = warehouse.page.getByRole('dialog');
        await expect(confirmation.getByRole('heading', { name: 'Submit stock adjustment?' })).toBeVisible();
        await expect(confirmation).toContainText('Stock stays unchanged until Finance approves.');
        const postResponses: import('@playwright/test').Response[] = [];
        const postRequests: string[] = [];
        const failedPosts: string[] = [];
        const recordPostRequest = (request: import('@playwright/test').Request) => {
          if (request.method() === 'POST') postRequests.push(new URL(request.url()).pathname);
        };
        const recordPostResponse = (response: import('@playwright/test').Response) => {
          if (response.request().method() === 'POST') postResponses.push(response);
        };
        const recordFailedPost = (request: import('@playwright/test').Request) => {
          if (request.method() === 'POST') {
            const path = new URL(request.url()).pathname;
            failedPosts.push(`${path}: ${request.failure()?.errorText ?? 'request failed'}`);
          }
        };
        warehouse.page.on('request', recordPostRequest);
        warehouse.page.on('response', recordPostResponse);
        warehouse.page.on('requestfailed', recordFailedPost);
        const submitButton = confirmation.getByRole('button', { name: 'Submit adjustment', exact: true });
        await expect(submitButton).toBeEnabled();
        await submitButton.click();
        try {
          await expect.poll(() => postResponses.length + failedPosts.length, { timeout: 15_000 }).toBeGreaterThan(0);
        } catch (error) {
          console.log('INV01 adjustment submission transport', { postRequests, failedPosts });
          checkDiagnostics(warehouse);
          throw error;
        }
        warehouse.page.off('request', recordPostRequest);
        warehouse.page.off('response', recordPostResponse);
        warehouse.page.off('requestfailed', recordFailedPost);
        expect(failedPosts, 'adjustment submission must complete over HTTP').toEqual([]);
        const submitResponse = postResponses.at(-1)!;
        const submitPath = new URL(submitResponse.url()).pathname;
        if (submitResponse.status() >= 400) {
          const body = await submitResponse.json().catch(() => ({})) as { message?: unknown; errors?: unknown };
          console.log('INV01 adjustment submission response', {
            status: submitResponse.status(),
            path: submitPath,
            message: typeof body.message === 'string' ? body.message : undefined,
            errors: body.errors,
          });
        }
        await expect(warehouse.page).toHaveURL(/\/inventory\/stock-adjustments\?status=pending(?:&|$)/);
        const row = warehouse.page.getByRole('row').filter({ hasText: reason });
        await expect(row).toHaveCount(1);
        await expect(row).toContainText('Awaiting Finance approval');
      },
      async () => {
        const row = warehouse.page.getByRole('row').filter({ hasText: reason });
        await expect(row.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
      },
      async () => {
        const adjustment = await adjustmentByReason(journey);
        expect(adjustment).toMatchObject({
          item_code: 'RM-001', status: 'pending', direction: 'in', quantity: '250.000',
          unit_cost: '120.0000', value: '30000.00', stock_movement_id: null,
          on_hand: '0.000', requester_email: warehouseIdentity.email, movement_count: 0,
        });
        const threshold = await readOnlyDatabaseJson<Array<{ value: number }>>(`
          SELECT (value #>> '{}')::numeric::int AS value FROM settings
          WHERE key = 'inventory.adjustment_approval_threshold'
        `);
        expect(threshold).toEqual([{ value: 25000 }]);
        const noticeRows = await notificationRows(journey);
        assertRecipientsExactly(noticeRows.map((entry) => entry.email), expectedRecipients);
        expect(noticeRows.every((entry) => entry.link_to === `/inventory/stock-adjustments?id=${entry.entity_id}`)).toBe(true);
        await assertAuditActors(journey, [warehouseIdentity.email]);
      },
    );

    await step(
      financeIdentity.actor,
      'Finance finds the work through its approval notification',
      'Work → Notifications → Stock adjustment needs approval',
      'pending',
      finance,
      async () => {
        await openInternalHome(finance);
        await openMenuItem(finance.page, 'Work', 'Notifications', '/notifications');
        await expect(finance.page.getByRole('heading', { name: 'Notifications', exact: true })).toBeVisible();
        const notice = finance.page.getByRole('link', { name: /Stock adjustment needs approval/ });
        await expect(notice).toBeVisible();
        await notice.click();
        await expect(finance.page).toHaveURL(/\/inventory\/stock-adjustments\?id=[^&]+$/);
        const row = finance.page.getByRole('row').filter({ hasText: reason });
        await expect(row).toHaveCount(1);
        await expect(row).toContainText('Awaiting Finance approval');
      },
      async () => {
        const row = finance.page.getByRole('row').filter({ hasText: reason });
        await expect(row.getByRole('button', { name: 'Approve', exact: true })).toBeEnabled();
        await expect(row.getByRole('button', { name: 'Reject', exact: true })).toBeEnabled();
        const statusFilter = warehouse.page.getByRole('combobox', { name: 'Status', exact: true });
        await statusFilter.click();
        const allStatuses = warehouse.page.getByRole('option', { name: 'Status: All', exact: true });
        await expect(allStatuses).toBeVisible();
        await allStatuses.click();
        await expect.poll(() => new URL(warehouse.page.url()).searchParams.has('status')).toBe(false);
        const observerRow = warehouse.page.getByRole('row').filter({ hasText: reason });
        await expect(observerRow).toContainText('Awaiting Finance approval');
        const noticeRows = await notificationRows(journey);
        expect(noticeRows.find((entry) => entry.email === financeIdentity.email)?.read_at).toBeTruthy();
      },
      async () => {
        const adjustment = await adjustmentByReason(journey);
        expect(adjustment.status).toBe('pending');
        expect(adjustment.stock_movement_id).toBeNull();
        expect(adjustment.on_hand).toBe('0.000');
      },
    );

    await step(
      financeIdentity.actor,
      'Finance approves; Warehouse observes the live stock transition',
      'Linked Stock Adjustments row → Approve',
      'approved',
      finance,
      async () => {
        const row = finance.page.getByRole('row').filter({ hasText: reason });
        await row.getByRole('button', { name: 'Approve', exact: true }).click();
        const confirmation = finance.page.getByRole('dialog');
        await expect(confirmation.getByRole('heading', { name: 'Approve adjustment?' })).toBeVisible();
        await confirmation.getByRole('button', { name: 'Approve', exact: true }).click();
        await expect(row).toContainText('Approved by');
      },
      async () => {
        const observerRow = warehouse.page.getByRole('row').filter({ hasText: reason });
        await expect(observerRow, 'Warehouse list should receive the approved status without refresh').toContainText('Approved by');
        await expect(observerRow.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
      },
      async () => {
        const adjustment = await adjustmentByReason(journey);
        expect(adjustment).toMatchObject({
          status: 'approved', direction: 'in', quantity: '250.000', unit_cost: '120.0000',
          value: '30000.00', requester_email: warehouseIdentity.email,
          approver_email: financeIdentity.email, movement_type: 'adjustment_in',
          movement_quantity: '250.000', movement_total_cost: '30000.00',
          movement_actor_email: financeIdentity.email, movement_count: 1, on_hand: '250.000',
        });
        await assertAuditActors(journey, [warehouseIdentity.email, financeIdentity.email]);
        const outcomes = await readOnlyDatabaseJson<Array<{ email: string }>>(`
          SELECT DISTINCT recipient.email
          FROM notifications notice
          JOIN users recipient ON notice.notifiable_id = recipient.id
          WHERE notice.type = 'approval.completed'
            AND notice.data ->> 'subject_type' = ${sqlText(stockAdjustmentSubjectType)}
            AND notice.data ->> 'subject_id' = ${sqlText(String(adjustment.id))}
        `);
        assertRecipientsExactly(outcomes.map((entry) => entry.email), [warehouseIdentity.email]);
      },
    );
  } finally {
    for (const { session } of sessions.reverse()) {
      await session.context.close();
    }
  }
});

test('INV01-HIGH-REJECT-RESUBMIT — Finance rejects a request and approves the Warehouse replacement', async ({ browser }, testInfo) => {
  test.setTimeout(4 * 60 * 1000);
  const actors = await loadActors();
  const warehouseIdentity = actorByName(actors, 'warehouse_staff');
  const financeIdentity = actorByName(actors, 'finance_officer');
  const expectedApprovers = actors
    .filter((candidate) => candidate.role === 'finance_officer' || candidate.role === 'system_admin')
    .map((candidate) => candidate.email);
  const journey = createJourneyContext('INV01-HIGH-REJECT-RESUBMIT');
  const originalReason = `${journey.values.runPrefix} rejected stock adjustment`;
  const replacementReason = `${journey.values.runPrefix} replacement stock adjustment`;
  const rejectionReason = 'Quantity needs a second physical count before posting.';
  journey.values.originalReason = originalReason;
  journey.values.replacementReason = replacementReason;
  journey.values.rejectionReason = rejectionReason;
  journey.values.originalQuantity = 250;
  journey.values.replacementQuantity = 260;
  journey.values.unitCost = 120;
  journey.values.originalValue = 30000;
  journey.values.replacementValue = 31200;

  const plannedSteps = [
    { name: 'Finance opens Stock Adjustments as a live observer', actor: financeIdentity.actor, uiPath: 'Finance → Stock Adjustments', expectedStatus: 'no request yet' },
    { name: 'Warehouse creates a high-value request; Finance sees it live', actor: warehouseIdentity.actor, uiPath: 'Warehouse → Stock Adjustments → New adjustment → Submit', expectedStatus: 'pending' },
    { name: 'Finance follows the approval notice and rejects with a reason', actor: financeIdentity.actor, uiPath: 'Work → Notifications → Stock adjustment needs approval → Reject', expectedStatus: 'rejected' },
    { name: 'Warehouse follows the rejection notice and submits a replacement', actor: warehouseIdentity.actor, uiPath: 'Work → Notifications → Stock adjustment rejected → New adjustment → Submit', expectedStatus: 'old rejected; replacement pending' },
    { name: 'Finance follows the replacement approval notice and approves', actor: financeIdentity.actor, uiPath: 'Work → Notifications → Stock adjustment needs approval → Approve', expectedStatus: 'replacement approved' },
  ];
  const sessions: Array<{ actor: string; session: ActorPage }> = [];
  const openActor = async (actor: string): Promise<ActorPage> => {
    const session = await actorPage(browser, actor);
    sessions.push({ actor, session });
    return session;
  };
  const warehouse = await openActor(warehouseIdentity.actor);
  const finance = await openActor(financeIdentity.actor);
  const checkDiagnostics = () => {
    for (const { actor, session } of sessions) session.diagnostics.assertClean(`${journey.journeyId}:${actor}`);
  };
  const step = (
    actor: string,
    name: string,
    uiPath: string,
    expectedStatus: string,
    session: ActorPage,
    action: () => Promise<void>,
    verify: () => Promise<void>,
    invariants: () => Promise<void>,
  ) => runProcessStep({
    name, actor, uiPath, expectedStatus, page: session.page, plannedSteps, context: journey, testInfo,
    action,
    verify,
    assertInvariants: async () => { await invariants(); checkDiagnostics(); },
  });

  try {
    await step(
      financeIdentity.actor,
      'Finance opens Stock Adjustments as a live observer',
      'Finance → Stock Adjustments',
      'no request yet',
      finance,
      async () => {
        await openInternalHome(finance);
        await openMenuItem(finance.page, 'Finance', 'Stock Adjustments', '/inventory/stock-adjustments');
        await expect(finance.page.getByRole('heading', { name: 'Stock Adjustments', exact: true })).toBeVisible();
        await expect(finance.page.getByRole('row').filter({ hasText: originalReason })).toHaveCount(0);
        await expect(finance.page.getByRole('row').filter({ hasText: replacementReason })).toHaveCount(0);
        await captureAnnotatedStep(finance.page, financeIdentity.actor, 'Finance prepares to review the request', 1, testInfo);
      },
      async () => {
        const rows = await readOnlyDatabaseJson<Array<{ count: number }>>(`
          SELECT COUNT(*)::int AS count FROM stock_adjustments
          WHERE reason IN (${sqlText(originalReason)}, ${sqlText(replacementReason)})
        `);
        expect(rows).toEqual([{ count: 0 }]);
      },
      async () => await expect(finance.page).toHaveURL('/inventory/stock-adjustments'),
    );

    await step(
      warehouseIdentity.actor,
      'Warehouse creates the original high-value request; Finance sees it live',
      'Warehouse → Stock Adjustments → New adjustment → Submit',
      'pending',
      warehouse,
      async () => {
        await openInternalHome(warehouse);
        await submitHighValueInboundAdjustment(warehouse, originalReason, 250);
        const statusFilter = warehouse.page.getByRole('combobox', { name: 'Status', exact: true });
        await statusFilter.click();
        const allStatuses = warehouse.page.getByRole('option', { name: 'Status: All', exact: true });
        await expect(allStatuses).toBeVisible();
        await allStatuses.click();
        await expect.poll(() => new URL(warehouse.page.url()).searchParams.has('status')).toBe(false);
      },
      async () => {
        const financeRow = finance.page.getByRole('row').filter({ hasText: originalReason });
        await expect(financeRow, 'the open Finance list should receive the pending record without refresh').toHaveCount(1);
        await expect(financeRow).toContainText('Awaiting Finance approval');
        await expect(financeRow.getByRole('button', { name: 'Approve', exact: true })).toBeEnabled();
        await expect(financeRow.getByRole('button', { name: 'Reject', exact: true })).toBeEnabled();
        const warehouseRow = warehouse.page.getByRole('row').filter({ hasText: originalReason });
        await expect(warehouseRow.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
        await expect(warehouseRow.getByRole('button', { name: 'Reject', exact: true })).toHaveCount(0);
      },
      async () => {
        const adjustment = await adjustmentByReason(journey, originalReason);
        expect(adjustment).toMatchObject({
          item_code: 'RM-001', status: 'pending', direction: 'in', quantity: '250.000',
          unit_cost: '120.0000', value: '30000.00', stock_movement_id: null,
          on_hand: '0.000', requester_email: warehouseIdentity.email, movement_count: 0,
        });
        const notices = await notificationRows(journey, originalReason);
        const approvalNotices = notices.filter((notice) => notice.type === 'approval.action_required');
        assertRecipientsExactly(approvalNotices.map((notice) => notice.email), expectedApprovers);
        await assertAuditActors(journey, [warehouseIdentity.email], originalReason);
      },
    );

    await step(
      financeIdentity.actor,
      'Finance follows the approval notice and rejects with a reason',
      'Work → Notifications → Stock adjustment needs approval → Reject',
      'rejected',
      finance,
      async () => {
        await openInternalHome(finance);
        await openMenuItem(finance.page, 'Work', 'Notifications', '/notifications');
        const notice = finance.page.getByRole('link', { name: /Stock adjustment needs approval/ }).first();
        await expect(notice).toBeVisible();
        await notice.click();
        await expect(finance.page).toHaveURL(/\/inventory\/stock-adjustments\?id=[^&]+$/);
        const row = finance.page.getByRole('row').filter({ hasText: originalReason });
        await expect(row).toContainText('Awaiting Finance approval');
        await row.getByRole('button', { name: 'Reject', exact: true }).click();
        const dialog = finance.page.getByRole('dialog');
        await expect(dialog.getByRole('heading', { name: 'Reject adjustment?', exact: true })).toBeVisible();
        await dialog.getByRole('textbox', { name: 'Rejection reason', exact: true }).fill(rejectionReason);
        await dialog.getByRole('button', { name: 'Reject adjustment', exact: true }).click();
        await expect(row).toContainText('Rejected by');
        await openMenuItem(finance.page, 'Finance', 'Stock Adjustments', '/inventory/stock-adjustments');
        await expect(finance.page.getByRole('row').filter({ hasText: originalReason })).toContainText('Rejected by');
      },
      async () => {
        const warehouseRow = warehouse.page.getByRole('row').filter({ hasText: originalReason });
        await expect(warehouseRow, 'Warehouse should receive the rejection without refreshing').toContainText('Rejected by');
        await expect(warehouseRow.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
        await expect(warehouseRow.getByRole('button', { name: 'Reject', exact: true })).toHaveCount(0);
        await captureAnnotatedStep(warehouse.page, warehouseIdentity.actor, 'Warehouse observes the rejected request', 3, testInfo);
      },
      async () => {
        const adjustment = await adjustmentByReason(journey, originalReason);
        expect(adjustment).toMatchObject({
          status: 'rejected', quantity: '250.000', value: '30000.00', stock_movement_id: null,
          on_hand: '0.000', requester_email: warehouseIdentity.email,
          resolved_by_email: financeIdentity.email, resolution_reason: rejectionReason, movement_count: 0,
        });
        const notices = await notificationRows(journey, originalReason);
        assertRecipientsExactly(
          notices.filter((notice) => notice.type === 'approval.rejected').map((notice) => notice.email),
          [warehouseIdentity.email],
        );
        await assertAuditActors(journey, [warehouseIdentity.email, financeIdentity.email], originalReason);
      },
    );

    await step(
      warehouseIdentity.actor,
      'Warehouse follows the rejection notice and submits a replacement',
      'Work → Notifications → Stock adjustment rejected → New adjustment → Submit',
      'original rejected; replacement pending',
      warehouse,
      async () => {
        await openInternalHome(warehouse);
        await openMenuItem(warehouse.page, 'Work', 'Notifications', '/notifications');
        const notice = warehouse.page.getByRole('link', { name: /Stock adjustment rejected/ }).first();
        await expect(notice).toBeVisible();
        await notice.click();
        await expect(warehouse.page).toHaveURL(/\/inventory\/stock-adjustments\?id=[^&]+$/);
        const originalRow = warehouse.page.getByRole('row').filter({ hasText: originalReason });
        await expect(originalRow).toContainText('Rejected by');
        await expect(originalRow).toContainText(rejectionReason);
        await expect(originalRow.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
        await expect(originalRow.getByRole('button', { name: 'Reject', exact: true })).toHaveCount(0);
        await submitHighValueInboundAdjustment(warehouse, replacementReason, 260);
        const statusFilter = warehouse.page.getByRole('combobox', { name: 'Status', exact: true });
        await statusFilter.click();
        const allStatuses = warehouse.page.getByRole('option', { name: 'Status: All', exact: true });
        await expect(allStatuses).toBeVisible();
        await allStatuses.click();
        await expect.poll(() => new URL(warehouse.page.url()).searchParams.has('status')).toBe(false);
      },
      async () => {
        const financeReplacement = finance.page.getByRole('row').filter({ hasText: replacementReason });
        await expect(financeReplacement, 'Finance observer should receive the replacement without refresh').toHaveCount(1);
        await expect(financeReplacement).toContainText('Awaiting Finance approval');
        await expect(finance.page.getByRole('row').filter({ hasText: originalReason })).toContainText('Rejected by');
      },
      async () => {
        const original = await adjustmentByReason(journey, originalReason);
        expect(original).toMatchObject({ status: 'rejected', stock_movement_id: null, movement_count: 0 });
        const replacement = await adjustmentByReason(journey, replacementReason);
        expect(replacement).toMatchObject({
          item_code: 'RM-001', status: 'pending', direction: 'in', quantity: '260.000',
          unit_cost: '120.0000', value: '31200.00', stock_movement_id: null,
          on_hand: '0.000', requester_email: warehouseIdentity.email, movement_count: 0,
        });
        const notices = await notificationRows(journey, replacementReason);
        assertRecipientsExactly(
          notices.filter((notice) => notice.type === 'approval.action_required').map((notice) => notice.email),
          expectedApprovers,
        );
        await assertAuditActors(journey, [warehouseIdentity.email], replacementReason);
      },
    );

    await step(
      financeIdentity.actor,
      'Finance follows the replacement approval notice and approves',
      'Work → Notifications → Stock adjustment needs approval → Approve',
      'replacement approved',
      finance,
      async () => {
        await openInternalHome(finance);
        await openMenuItem(finance.page, 'Work', 'Notifications', '/notifications');
        const notice = finance.page.getByRole('link', { name: /Stock adjustment needs approval/ }).first();
        await expect(notice).toBeVisible();
        await notice.click();
        await expect(finance.page).toHaveURL(/\/inventory\/stock-adjustments\?id=[^&]+$/);
        const row = finance.page.getByRole('row').filter({ hasText: replacementReason });
        await expect(row).toContainText('Awaiting Finance approval');
        await row.getByRole('button', { name: 'Approve', exact: true }).click();
        const dialog = finance.page.getByRole('dialog');
        await expect(dialog.getByRole('heading', { name: 'Approve adjustment?', exact: true })).toBeVisible();
        await dialog.getByRole('button', { name: 'Approve', exact: true }).click();
        await expect(row).toContainText('Approved by');
      },
      async () => {
        const warehouseRow = warehouse.page.getByRole('row').filter({ hasText: replacementReason });
        await expect(warehouseRow, 'Warehouse should receive the replacement approval without refreshing').toContainText('Approved by');
        await expect(warehouseRow.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
        await expect(warehouseRow.getByRole('button', { name: 'Reject', exact: true })).toHaveCount(0);
        await captureAnnotatedStep(finance.page, financeIdentity.actor, 'Finance approves the replacement request', 5, testInfo);
      },
      async () => {
        const original = await adjustmentByReason(journey, originalReason);
        expect(original).toMatchObject({ status: 'rejected', stock_movement_id: null, movement_count: 0 });
        const replacement = await adjustmentByReason(journey, replacementReason);
        expect(replacement).toMatchObject({
          status: 'approved', quantity: '260.000', unit_cost: '120.0000', value: '31200.00',
          requester_email: warehouseIdentity.email, approver_email: financeIdentity.email,
          movement_type: 'adjustment_in', movement_quantity: '260.000',
          movement_total_cost: '31200.00', movement_actor_email: financeIdentity.email,
          movement_count: 1, on_hand: '260.000',
        });
        const notices = await notificationRows(journey, replacementReason);
        assertRecipientsExactly(
          notices.filter((notice) => notice.type === 'approval.completed').map((notice) => notice.email),
          [warehouseIdentity.email],
        );
        await assertAuditActors(journey, [warehouseIdentity.email, financeIdentity.email], replacementReason);
      },
    );
  } finally {
    for (const { session } of sessions.reverse()) await session.context.close();
  }
});

test('INV01-HIGH-WITHDRAW — Warehouse withdraws a pending request and Finance sees the closed hand-off', async ({ browser }, testInfo) => {
  test.setTimeout(3 * 60 * 1000);
  const actors = await loadActors();
  const warehouseIdentity = actorByName(actors, 'warehouse_staff');
  const financeIdentity = actorByName(actors, 'finance_officer');
  const expectedApprovers = actors
    .filter((candidate) => candidate.role === 'finance_officer' || candidate.role === 'system_admin')
    .map((candidate) => candidate.email);
  const journey = createJourneyContext('INV01-HIGH-WITHDRAW');
  const reason = `${journey.values.runPrefix} withdrawn high-value adjustment`;
  journey.values.reason = reason;
  journey.values.quantity = 250;
  journey.values.unitCost = 120;
  journey.values.value = 30000;
  const plannedSteps = [
    { name: 'Finance opens Stock Adjustments as a live observer', actor: financeIdentity.actor, uiPath: 'Finance → Stock Adjustments', expectedStatus: 'no request yet' },
    { name: 'Warehouse submits a high-value request; Finance sees it live', actor: warehouseIdentity.actor, uiPath: 'Warehouse → Stock Adjustments → New adjustment → Submit', expectedStatus: 'pending' },
    { name: 'Warehouse withdraws its pending request', actor: warehouseIdentity.actor, uiPath: 'Stock Adjustments row → Withdraw → Confirm', expectedStatus: 'withdrawn' },
    { name: 'Finance follows the original notice and confirms the hand-off is closed', actor: financeIdentity.actor, uiPath: 'Work → Notifications → Stock adjustment needs approval → linked record', expectedStatus: 'withdrawn; no approval actions' },
  ];
  const sessions: Array<{ actor: string; session: ActorPage }> = [];
  const openActor = async (actor: string): Promise<ActorPage> => {
    const session = await actorPage(browser, actor);
    sessions.push({ actor, session });
    return session;
  };
  const warehouse = await openActor(warehouseIdentity.actor);
  const finance = await openActor(financeIdentity.actor);
  const checkDiagnostics = () => {
    for (const { actor, session } of sessions) session.diagnostics.assertClean(`${journey.journeyId}:${actor}`);
  };
  const step = (
    actor: string,
    name: string,
    uiPath: string,
    expectedStatus: string,
    session: ActorPage,
    action: () => Promise<void>,
    verify: () => Promise<void>,
    invariants: () => Promise<void>,
  ) => runProcessStep({
    name, actor, uiPath, expectedStatus, page: session.page, plannedSteps, context: journey, testInfo,
    action,
    verify,
    assertInvariants: async () => { await invariants(); checkDiagnostics(); },
  });

  try {
    await step(
      financeIdentity.actor,
      'Finance opens Stock Adjustments as a live observer',
      'Finance → Stock Adjustments',
      'no request yet',
      finance,
      async () => {
        await openInternalHome(finance);
        await openMenuItem(finance.page, 'Finance', 'Stock Adjustments', '/inventory/stock-adjustments');
        await expect(finance.page.getByRole('heading', { name: 'Stock Adjustments', exact: true })).toBeVisible();
        await expect(finance.page.getByRole('row').filter({ hasText: reason })).toHaveCount(0);
      },
      async () => {
        const rows = await readOnlyDatabaseJson<Array<{ count: number }>>(`
          SELECT COUNT(*)::int AS count FROM stock_adjustments WHERE reason = ${sqlText(reason)}
        `);
        expect(rows).toEqual([{ count: 0 }]);
      },
      async () => await expect(finance.page).toHaveURL('/inventory/stock-adjustments'),
    );

    await step(
      warehouseIdentity.actor,
      'Warehouse submits a high-value request; Finance sees it live',
      'Warehouse → Stock Adjustments → New adjustment → Submit',
      'pending',
      warehouse,
      async () => {
        await openInternalHome(warehouse);
        await submitHighValueInboundAdjustment(warehouse, reason, 250);
        const statusFilter = warehouse.page.getByRole('combobox', { name: 'Status', exact: true });
        await statusFilter.click();
        const allStatuses = warehouse.page.getByRole('option', { name: 'Status: All', exact: true });
        await expect(allStatuses).toBeVisible();
        await allStatuses.click();
        await expect.poll(() => new URL(warehouse.page.url()).searchParams.has('status')).toBe(false);
      },
      async () => {
        const financeRow = finance.page.getByRole('row').filter({ hasText: reason });
        await expect(financeRow, 'the open Finance list should receive the pending record without refresh').toHaveCount(1);
        await expect(financeRow).toContainText('Awaiting Finance approval');
        await expect(financeRow.getByRole('button', { name: 'Approve', exact: true })).toBeEnabled();
        await expect(financeRow.getByRole('button', { name: 'Reject', exact: true })).toBeEnabled();
        await expect(financeRow.getByRole('button', { name: 'Withdraw', exact: true })).toHaveCount(0);
        const warehouseRow = warehouse.page.getByRole('row').filter({ hasText: reason });
        await expect(warehouseRow.getByRole('button', { name: 'Withdraw', exact: true })).toBeEnabled();
        await expect(warehouseRow.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
        await expect(warehouseRow.getByRole('button', { name: 'Reject', exact: true })).toHaveCount(0);
      },
      async () => {
        const adjustment = await adjustmentByReason(journey);
        expect(adjustment).toMatchObject({
          status: 'pending', quantity: '250.000', value: '30000.00', stock_movement_id: null,
          on_hand: '0.000', requester_email: warehouseIdentity.email, movement_count: 0,
        });
        const notices = await notificationRows(journey);
        assertRecipientsExactly(
          notices.filter((notice) => notice.type === 'approval.action_required').map((notice) => notice.email),
          expectedApprovers,
        );
        await assertAuditActors(journey, [warehouseIdentity.email]);
      },
    );

    await step(
      warehouseIdentity.actor,
      'Warehouse withdraws its pending request',
      'Stock Adjustments row → Withdraw → Confirm',
      'withdrawn',
      warehouse,
      async () => {
        const row = warehouse.page.getByRole('row').filter({ hasText: reason });
        await row.getByRole('button', { name: 'Withdraw', exact: true }).click();
        const confirmation = warehouse.page.getByRole('dialog');
        await expect(confirmation.getByRole('heading', { name: 'Withdraw adjustment?', exact: true })).toBeVisible();
        await expect(confirmation).toContainText('It will close without posting a stock movement.');
        await confirmation.getByRole('button', { name: 'Withdraw', exact: true }).click();
        await expect(row).toContainText('Withdrawn by');
      },
      async () => {
        const financeRow = finance.page.getByRole('row').filter({ hasText: reason });
        await expect(financeRow, 'Finance should see the withdrawn status without refreshing').toContainText('Withdrawn by');
        await expect(financeRow.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
        await expect(financeRow.getByRole('button', { name: 'Reject', exact: true })).toHaveCount(0);
        await expect(financeRow.getByRole('button', { name: 'Withdraw', exact: true })).toHaveCount(0);
      },
      async () => {
        const adjustment = await adjustmentByReason(journey);
        expect(adjustment).toMatchObject({
          status: 'withdrawn', quantity: '250.000', value: '30000.00', stock_movement_id: null,
          on_hand: '0.000', requester_email: warehouseIdentity.email,
          resolved_by_email: warehouseIdentity.email, resolution_reason: null, movement_count: 0,
        });
        const notices = await notificationRows(journey);
        expect(notices.filter((notice) => notice.type === 'approval.action_required')).toHaveLength(expectedApprovers.length);
        expect(notices.filter((notice) => notice.type === 'approval.rejected' || notice.type === 'approval.completed')).toHaveLength(0);
        await assertAuditActors(journey, [warehouseIdentity.email]);
      },
    );

    await step(
      financeIdentity.actor,
      'Finance follows the original notice and confirms the hand-off is closed',
      'Work → Notifications → Stock adjustment needs approval → linked record',
      'withdrawn; no approval actions',
      finance,
      async () => {
        await openInternalHome(finance);
        await openMenuItem(finance.page, 'Work', 'Notifications', '/notifications');
        const notice = finance.page.getByRole('link', { name: /Stock adjustment needs approval/ }).first();
        await expect(notice).toBeVisible();
        await notice.click();
        await expect(finance.page).toHaveURL(/\/inventory\/stock-adjustments\?id=[^&]+$/);
      },
      async () => {
        const row = finance.page.getByRole('row').filter({ hasText: reason });
        await expect(row).toContainText('Withdrawn by');
        await expect(row.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
        await expect(row.getByRole('button', { name: 'Reject', exact: true })).toHaveCount(0);
      },
      async () => {
        const notices = await notificationRows(journey);
        const handoff = notices.filter((notice) => notice.type === 'approval.action_required');
        assertRecipientsExactly(handoff.map((notice) => notice.email), expectedApprovers);
        expect(handoff.find((notice) => notice.email === financeIdentity.email)?.read_at).toBeTruthy();
        const adjustment = await adjustmentByReason(journey);
        expect(adjustment).toMatchObject({ status: 'withdrawn', stock_movement_id: null, movement_count: 0, on_hand: '0.000' });
      },
    );
  } finally {
    for (const { session } of sessions.reverse()) await session.context.close();
  }
});

type StockCountRow = {
  id: number;
  session_number: string;
  title: string;
  scope: string;
  status: string;
  total_locations: number;
  item_count: number;
  creator_email: string;
};

async function stockCountByTitle(context: JourneyContext): Promise<StockCountRow> {
  const title = contextValue<string>(context, 'title');
  const rows = await readOnlyDatabaseJson<StockCountRow[]>(`
    SELECT session.id, session.session_number, session.title, session.scope,
           session.status, session.total_locations,
           (SELECT COUNT(*)::int FROM stock_count_items item
            WHERE item.session_id = session.id) AS item_count,
           creator.email AS creator_email
    FROM stock_count_sessions session
    JOIN users creator ON creator.id = session.created_by
    WHERE session.title = ${sqlText(title)}
  `);
  expect(rows, `one stock-count session should match ${title}`).toHaveLength(1);
  return rows[0];
}

type StockCountVarianceState = {
  id: number;
  session_number: string;
  status: string;
  total_locations: number;
  counted_locations: number;
  variance_count: number;
  variance_value: string;
  creator_email: string;
  completer_email: string | null;
  item_code: string;
  item_status: string;
  system_quantity: string;
  counted_quantity: string | null;
  variance: string;
  variance_percent: string;
  counter_email: string | null;
  verifier_email: string | null;
  on_hand: string;
  movement_count: number;
  movement_type: string | null;
  movement_quantity: string | null;
  movement_total_cost: string | null;
  movement_actor_email: string | null;
  adjustment_count: number;
  adjustment_status: string | null;
};

async function stockCountVarianceState(context: JourneyContext): Promise<StockCountVarianceState> {
  const title = contextValue<string>(context, 'title');
  const rows = await readOnlyDatabaseJson<StockCountVarianceState[]>(`
    SELECT session.id, session.session_number, session.status,
           session.total_locations, session.counted_locations,
           session.variance_count, session.variance_value::text,
           creator.email AS creator_email, completer.email AS completer_email,
           item.code AS item_code, count_line.status AS item_status,
           count_line.system_quantity::text, count_line.counted_quantity::text,
           count_line.variance::text, count_line.variance_percent::text,
           counter.email AS counter_email, verifier.email AS verifier_email,
           COALESCE(level.quantity, 0)::numeric(14, 3)::text AS on_hand,
           (SELECT COUNT(*)::int FROM stock_movements movement
            WHERE movement.reference_type = 'stock_count_session'
              AND movement.reference_id = session.id) AS movement_count,
           (SELECT movement.movement_type::text FROM stock_movements movement
            WHERE movement.reference_type = 'stock_count_session'
              AND movement.reference_id = session.id ORDER BY movement.id LIMIT 1) AS movement_type,
           (SELECT movement.quantity::text FROM stock_movements movement
            WHERE movement.reference_type = 'stock_count_session'
              AND movement.reference_id = session.id ORDER BY movement.id LIMIT 1) AS movement_quantity,
           (SELECT movement.total_cost::text FROM stock_movements movement
            WHERE movement.reference_type = 'stock_count_session'
              AND movement.reference_id = session.id ORDER BY movement.id LIMIT 1) AS movement_total_cost,
           (SELECT movement_actor.email FROM stock_movements movement
            JOIN users movement_actor ON movement_actor.id = movement.created_by
            WHERE movement.reference_type = 'stock_count_session'
              AND movement.reference_id = session.id ORDER BY movement.id LIMIT 1) AS movement_actor_email,
           (SELECT COUNT(*)::int FROM stock_adjustments adjustment
            WHERE adjustment.stock_movement_id IN (
              SELECT movement.id FROM stock_movements movement
              WHERE movement.reference_type = 'stock_count_session'
                AND movement.reference_id = session.id
            )) AS adjustment_count,
           (SELECT adjustment.status::text FROM stock_adjustments adjustment
            WHERE adjustment.stock_movement_id IN (
              SELECT movement.id FROM stock_movements movement
              WHERE movement.reference_type = 'stock_count_session'
                AND movement.reference_id = session.id
            ) ORDER BY adjustment.id LIMIT 1) AS adjustment_status
    FROM stock_count_sessions session
    JOIN users creator ON creator.id = session.created_by
    LEFT JOIN users completer ON completer.id = session.approved_by
    LEFT JOIN stock_count_items count_line ON count_line.session_id = session.id
    LEFT JOIN items item ON item.id = count_line.item_id
    LEFT JOIN users counter ON counter.id = count_line.counted_by
    LEFT JOIN users verifier ON verifier.id = count_line.verified_by
    LEFT JOIN stock_levels level
      ON level.item_id = count_line.item_id AND level.location_id = count_line.location_id
    WHERE session.title = ${sqlText(title)}
  `);
  expect(rows, `one count line should exist for ${title}`).toHaveLength(1);
  return rows[0];
}

async function assertStockCountItemAuditActors(context: JourneyContext, expectedEmails: string[]): Promise<void> {
  const rows = await readOnlyDatabaseJson<Array<{ email: string }>>(`
    SELECT DISTINCT actor.email
    FROM audit_logs log
    JOIN users actor ON actor.id = log.user_id
    WHERE log.model_type ILIKE '%StockCountItem%'
      AND log.model_id = (
        SELECT count_line.id
        FROM stock_count_items count_line
        JOIN stock_count_sessions session ON session.id = count_line.session_id
        WHERE session.title = ${sqlText(contextValue<string>(context, 'title'))}
      )
  `);
  const actual = rows.map((row) => row.email);
  for (const email of expectedEmails) expect(actual).toContain(email);
}

async function assertStockCountAuditActor(context: JourneyContext, expectedEmail: string): Promise<void> {
  const rows = await readOnlyDatabaseJson<Array<{ email: string }>>(`
    SELECT DISTINCT actor.email
    FROM audit_logs log
    JOIN users actor ON actor.id = log.user_id
    WHERE log.model_type ILIKE '%StockCountSession%'
      AND log.model_id = (
        SELECT session.id FROM stock_count_sessions session
        WHERE session.title = ${sqlText(contextValue<string>(context, 'title'))}
      )
  `);
  expect(rows.map((row) => row.email)).toContain(expectedEmail);
}

test('INV01-COUNT-CANCEL — Warehouse cancels an empty draft count and observer sees the update', async ({ browser }, testInfo) => {
  test.setTimeout(3 * 60 * 1000);
  const actors = await loadActors();
  const warehouseIdentity = actorByName(actors, 'warehouse_staff');
  const observerIdentity = actorByName(actors, 'system_admin');
  const journey = createJourneyContext('INV01-COUNT-CANCEL');
  journey.values.title = `${journey.values.runPrefix} warehouse count cancellation`;
  const plannedSteps = [
    { name: 'Warehouse creates a draft count session', actor: warehouseIdentity.actor, uiPath: 'Warehouse → Warehouse Map → Stock Count → New session → Create session', expectedStatus: 'draft' },
    { name: 'Observer opens the count session from the session list', actor: observerIdentity.actor, uiPath: 'Warehouse → Warehouse Map → Stock Count → Select the listed session', expectedStatus: 'draft' },
    { name: 'Warehouse cancels the draft; observer sees the live status change', actor: warehouseIdentity.actor, uiPath: 'Open count session → Cancel → Confirm Cancel session', expectedStatus: 'cancelled' },
  ];
  const sessions: Array<{ actor: string; session: ActorPage }> = [];
  const openActor = async (actor: string): Promise<ActorPage> => {
    const session = await actorPage(browser, actor);
    sessions.push({ actor, session });
    return session;
  };
  const warehouse = await openActor(warehouseIdentity.actor);
  const observer = await openActor(observerIdentity.actor);
  const title = contextValue<string>(journey, 'title');
  const checkAllDiagnostics = () => {
    for (const { actor, session } of sessions) {
      session.diagnostics.assertClean(`${journey.journeyId}:${actor}`);
    }
  };
  const step = (
    actor: string,
    name: string,
    uiPath: string,
    expectedStatus: string,
    session: ActorPage,
    action: () => Promise<void>,
    verify: () => Promise<void>,
    invariants: () => Promise<void>,
  ) => runProcessStep({
    name, actor, uiPath, expectedStatus, page: session.page, plannedSteps, context: journey, testInfo,
    action,
    verify,
    assertInvariants: async () => { await invariants(); checkAllDiagnostics(); },
  });

  try {
    await step(
      warehouseIdentity.actor,
      'Warehouse creates a draft count session',
      'Warehouse → Warehouse Map → Stock Count → New session → Create session',
      'draft',
      warehouse,
      async () => {
        await openInternalHome(warehouse);
        await openMenuItem(warehouse.page, 'Warehouse', 'Warehouse Map', '/inventory/warehouse-map');
        await expect(warehouse.page.getByRole('heading', { name: 'Warehouse map', exact: true })).toBeVisible();
        await warehouse.page.getByRole('radio', { name: 'Stock Count', exact: true }).click();
        await expect(warehouse.page).toHaveURL('/inventory/warehouse-map?view=count');
        await expect(warehouse.page.getByRole('heading', { name: 'Stock counts', exact: true })).toBeVisible();
        await warehouse.page.getByRole('button', { name: 'New session', exact: true }).click();
        const modal = warehouse.page.getByRole('dialog');
        await expect(modal.getByRole('heading', { name: 'New count session', exact: true })).toBeVisible();
        await modal.getByRole('textbox', { name: 'Title', exact: true }).fill(title);
        const scopeSelect = modal.getByRole('combobox', { name: /^Scope/ });
        await expect(scopeSelect).toHaveCount(1);
        await scopeSelect.selectOption('warehouse');
        const warehouseSelect = modal.getByRole('combobox', { name: /^Warehouse/ });
        await expect(warehouseSelect).toHaveCount(1);
        const firstWarehouseOption = warehouseSelect.locator('option').nth(1);
        const warehouseId = await firstWarehouseOption.getAttribute('value');
        expect(warehouseId, 'E2E reference data must expose an active warehouse').toBeTruthy();
        await warehouseSelect.selectOption(warehouseId!);
        await modal.getByRole('button', { name: 'Create session', exact: true }).click();
        await expect(warehouse.page.getByText('No stock items are in this scope. Cancel and choose a scope with stock.', { exact: true })).toBeVisible();
        await expect(warehouse.page.getByRole('button', { name: 'Start count', exact: true })).toBeDisabled();
        await expect(warehouse.page.getByRole('button', { name: 'Cancel', exact: true })).toBeEnabled();
        await expect(warehouse.page.getByText('Draft', { exact: true }).first()).toBeVisible();
      },
      async () => {
        await expect(warehouse.page.getByText(title, { exact: true }).first()).toBeVisible();
      },
      async () => {
        const session = await stockCountByTitle(journey);
        journey.values.sessionNumber = session.session_number;
        expect(session).toMatchObject({
          title, scope: 'warehouse', status: 'draft', total_locations: 0,
          item_count: 0, creator_email: warehouseIdentity.email,
        });
        const movements = await readOnlyDatabaseJson<Array<{ count: number }>>(`
          SELECT COUNT(*)::int AS count FROM stock_movements movement
          WHERE movement.reference_type = 'stock_count_session'
            AND movement.reference_id = ${session.id}
        `);
        expect(movements).toEqual([{ count: 0 }]);
        await assertStockCountAuditActor(journey, warehouseIdentity.email);
      },
    );

    await step(
      observerIdentity.actor,
      'Observer opens the count session from the session list',
      'Warehouse → Warehouse Map → Stock Count → Select the listed session',
      'draft',
      observer,
      async () => {
        await openInternalHome(observer);
        await openMenuItem(observer.page, 'Warehouse', 'Warehouse Map', '/inventory/warehouse-map');
        await expect(observer.page.getByRole('heading', { name: 'Warehouse map', exact: true })).toBeVisible();
        await observer.page.getByRole('radio', { name: 'Stock Count', exact: true }).click();
        await expect(observer.page.getByRole('heading', { name: 'Stock counts', exact: true })).toBeVisible();
        const sessionCard = observer.page.getByRole('button').filter({ hasText: title });
        await expect(sessionCard).toHaveCount(1);
        await expect(sessionCard).toContainText('Draft');
        await sessionCard.click();
        await expect(observer.page.getByText('No stock items are in this scope. Cancel and choose a scope with stock.', { exact: true })).toBeVisible();
        journey.values.observerUrl = observer.page.url();
      },
      async () => {
        const sessionCard = observer.page.getByRole('button').filter({ hasText: title });
        await expect(sessionCard).toContainText('Draft');
      },
      async () => {
        expect((await stockCountByTitle(journey)).status).toBe('draft');
      },
    );

    await step(
      warehouseIdentity.actor,
      'Warehouse cancels the draft; observer sees the live status change',
      'Open count session → Cancel → Confirm Cancel session',
      'cancelled',
      warehouse,
      async () => {
        await warehouse.page.getByRole('button', { name: 'Cancel', exact: true }).click();
        const confirmation = warehouse.page.getByRole('dialog');
        await expect(confirmation.getByRole('heading', { name: 'Cancel session?', exact: true })).toBeVisible();
        await expect(confirmation).toContainText('All count data will be lost.');
        await confirmation.getByRole('button', { name: 'Cancel session', exact: true }).click();
        const warehouseCard = warehouse.page.getByRole('button').filter({ hasText: title });
        await expect(warehouseCard).toContainText('Cancelled');
      },
      async () => {
        const observerCard = observer.page.getByRole('button').filter({ hasText: title });
        await expect(observerCard, 'observer should receive the cancellation without refresh').toContainText('Cancelled');
        await expect(observer.page.url()).toBe(contextValue<string>(journey, 'observerUrl'));
        await expect(observer.page.getByRole('button', { name: 'Start count', exact: true })).toHaveCount(0);
        await expect(observer.page.getByRole('button', { name: 'Cancel', exact: true })).toHaveCount(0);
        await captureAnnotatedStep(
          observer.page,
          observerIdentity.actor,
          'Observer confirms cancelled count without refresh',
          4,
          testInfo,
        );
      },
      async () => {
        const session = await stockCountByTitle(journey);
        expect(session).toMatchObject({
          status: 'cancelled', scope: 'warehouse', total_locations: 0,
          item_count: 0, creator_email: warehouseIdentity.email,
        });
        const movements = await readOnlyDatabaseJson<Array<{ count: number }>>(`
          SELECT COUNT(*)::int AS count FROM stock_movements movement
          WHERE movement.reference_type = 'stock_count_session'
            AND movement.reference_id = ${session.id}
        `);
        expect(movements).toEqual([{ count: 0 }]);
        await assertStockCountAuditActor(journey, warehouseIdentity.email);
      },
    );
  } finally {
    for (const { session } of sessions.reverse()) {
      await session.context.close();
    }
  }
});

test('INV01-LOW-ADJUST — Warehouse posts a sub-threshold adjustment and Finance observes it live', async ({ browser }, testInfo) => {
  test.setTimeout(3 * 60 * 1000);
  const actors = await loadActors();
  const warehouseIdentity = actorByName(actors, 'warehouse_staff');
  const financeIdentity = actorByName(actors, 'finance_officer');
  const journey = createJourneyContext('INV01-LOW-ADJUST');
  const reason = `${journey.values.runPrefix} low-value found stock`;
  journey.values.reason = reason;
  journey.values.itemCode = 'RM-001';
  journey.values.quantity = 1;
  journey.values.unitCost = 120;
  journey.values.value = 120;

  const plannedSteps = [
    { name: 'Finance opens Stock Adjustments as a live observer', actor: financeIdentity.actor, uiPath: 'Finance → Stock Adjustments', expectedStatus: 'no adjustment yet' },
    { name: 'Warehouse posts the low-value adjustment; Finance sees the live record', actor: warehouseIdentity.actor, uiPath: 'Warehouse → Stock Adjustments → New adjustment → Submit', expectedStatus: 'approved' },
  ];
  const sessions: Array<{ actor: string; session: ActorPage }> = [];
  const openActor = async (actor: string): Promise<ActorPage> => {
    const session = await actorPage(browser, actor);
    sessions.push({ actor, session });
    return session;
  };
  const finance = await openActor(financeIdentity.actor);
  const warehouse = await openActor(warehouseIdentity.actor);
  const checkDiagnostics = () => {
    for (const { actor, session } of sessions) session.diagnostics.assertClean(`${journey.journeyId}:${actor}`);
  };
  const step = (
    actor: string,
    name: string,
    uiPath: string,
    expectedStatus: string,
    session: ActorPage,
    action: () => Promise<void>,
    verify: () => Promise<void>,
    invariants: () => Promise<void>,
  ) => runProcessStep({
    name, actor, uiPath, expectedStatus, page: session.page, plannedSteps, context: journey, testInfo,
    action,
    verify,
    assertInvariants: async () => { await invariants(); checkDiagnostics(); },
  });

  try {
    await step(
      financeIdentity.actor,
      'Finance opens Stock Adjustments as a live observer',
      'Finance → Stock Adjustments',
      'no adjustment yet',
      finance,
      async () => {
        await openInternalHome(finance);
        await openMenuItem(finance.page, 'Finance', 'Stock Adjustments', '/inventory/stock-adjustments');
        await expect(finance.page.getByRole('heading', { name: 'Stock Adjustments', exact: true })).toBeVisible();
        await expect(finance.page.getByRole('row').filter({ hasText: reason })).toHaveCount(0);
      },
      async () => {
        const rows = await readOnlyDatabaseJson<Array<{ count: number }>>(`
          SELECT COUNT(*)::int AS count FROM stock_adjustments WHERE reason = ${sqlText(reason)}
        `);
        expect(rows).toEqual([{ count: 0 }]);
      },
      async () => {
        await expect(finance.page).toHaveURL(/\/inventory\/stock-adjustments(?:\?.*)?$/);
      },
    );

    await step(
      warehouseIdentity.actor,
      'Warehouse posts the low-value adjustment; Finance sees the live record',
      'Warehouse → Stock Adjustments → New adjustment → Submit',
      'approved',
      warehouse,
      async () => {
        await openInternalHome(warehouse);
        await openMenuItem(warehouse.page, 'Warehouse', 'Stock Adjustments', '/inventory/stock-adjustments');
        await expect(warehouse.page.getByRole('heading', { name: 'Stock Adjustments', exact: true })).toBeVisible();
        await warehouse.page.getByRole('link', { name: 'New adjustment', exact: true }).click();
        await expect(warehouse.page.getByRole('heading', { name: 'Stock adjustment', exact: true })).toBeVisible();

        await warehouse.page.getByRole('combobox', { name: /Direction/ }).selectOption('in');
        const itemInput = warehouse.page.getByRole('combobox', { name: 'Item', exact: true });
        await itemInput.fill('RM-001');
        const itemOption = warehouse.page.getByRole('option').filter({ hasText: 'RM-001' }).first();
        await expect(itemOption).toBeVisible();
        await itemOption.click();

        const location = warehouse.page.getByRole('combobox', { name: /Location/ });
        await expect(location).toBeEnabled();
        await location.click();
        const firstLocation = warehouse.page.getByRole('option').filter({ hasText: 'Main Warehouse' }).first();
        await expect(firstLocation).toBeVisible();
        journey.values.locationLabel = (await firstLocation.innerText()).trim();
        await firstLocation.click();

        await warehouse.page.getByRole('spinbutton', { name: /Quantity/ }).fill('1');
        await warehouse.page.getByRole('spinbutton', { name: /Unit cost/ }).fill('120');
        await warehouse.page.getByRole('combobox', { name: /Reason code/ }).selectOption('found_stock');
        await warehouse.page.getByRole('textbox', { name: /Reason \(recorded for audit\)/ }).fill(reason);
        await expect(warehouse.page.getByText('Finance approval required', { exact: true })).toHaveCount(0);
        await expect(warehouse.page.getByText(/Adjustments above .*25,000.* require Finance approval\./)).toBeVisible();
        await warehouse.page.getByRole('button', { name: 'Review adjustment', exact: true }).click();
        const confirmation = warehouse.page.getByRole('dialog');
        await expect(confirmation.getByRole('heading', { name: 'Submit stock adjustment?' })).toBeVisible();
        await expect(confirmation).toContainText('Stock changes when the adjustment is posted.');
        await confirmation.getByRole('button', { name: 'Submit adjustment', exact: true }).click();

        await expect(warehouse.page).toHaveURL('/inventory/stock-levels?view=movements');
        await expect(warehouse.page.getByRole('heading', { name: 'Stock movements', exact: true })).toBeVisible();
        await openMenuItem(warehouse.page, 'Warehouse', 'Stock Adjustments', '/inventory/stock-adjustments');
        const warehouseRow = warehouse.page.getByRole('row').filter({ hasText: reason });
        await expect(warehouseRow).toHaveCount(1);
        await expect(warehouseRow).toContainText('Approved by');
        await expect(warehouseRow.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
        await expect(warehouseRow.getByRole('button', { name: 'Reject', exact: true })).toHaveCount(0);
        await expect(warehouseRow.getByRole('button', { name: 'Withdraw', exact: true })).toHaveCount(0);
      },
      async () => {
        const observerRow = finance.page.getByRole('row').filter({ hasText: reason });
        await expect(observerRow, 'Finance list should receive the approved adjustment without refresh').toHaveCount(1);
        await expect(observerRow).toContainText('Approved by');
        await expect(observerRow.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
        await expect(observerRow.getByRole('button', { name: 'Reject', exact: true })).toHaveCount(0);
        await expect(observerRow.getByRole('button', { name: 'Withdraw', exact: true })).toHaveCount(0);
        await captureAnnotatedStep(finance.page, financeIdentity.actor, 'Finance sees approved adjustment without refresh', 3, testInfo);
      },
      async () => {
        const adjustment = await adjustmentByReason(journey);
        expect(adjustment).toMatchObject({
          item_code: 'RM-001', status: 'approved', direction: 'in', quantity: '1.000',
          unit_cost: '120.0000', value: '120.00', requester_email: warehouseIdentity.email,
          approver_email: warehouseIdentity.email, movement_type: 'adjustment_in',
          movement_quantity: '1.000', movement_total_cost: '120.00',
          movement_actor_email: warehouseIdentity.email, movement_count: 1, on_hand: '1.000',
        });
        const notices = await readOnlyDatabaseJson<Array<{ count: number }>>(`
          SELECT COUNT(*)::int AS count FROM notifications notice
          WHERE notice.data ->> 'subject_type' = ${sqlText(stockAdjustmentSubjectType)}
            AND notice.data ->> 'subject_id' = (
            SELECT adjustment.id::text FROM stock_adjustments adjustment
            WHERE adjustment.reason = ${sqlText(reason)}
          )
        `);
        expect(notices).toEqual([{ count: 0 }]);
        await assertAuditActors(journey, [warehouseIdentity.email]);
      },
    );
  } finally {
    for (const { session } of sessions.reverse()) await session.context.close();
  }
});

test('INV01-COUNT-VARIANCE — Warehouse counts, approves and reconciles a variance through independent actors', async ({ browser }, testInfo) => {
  test.setTimeout(4 * 60 * 1000);
  const actors = await loadActors();
  const warehouseIdentity = actorByName(actors, 'warehouse_staff');
  const checkerIdentity = actorByName(actors, 'warehouse_staff__2');
  const finisherIdentity = actorByName(actors, 'warehouse_staff__3');
  const observerIdentity = actorByName(actors, 'system_admin');
  const journey = createJourneyContext('INV01-COUNT-VARIANCE');
  const stockReason = `${journey.values.runPrefix} count prerequisite stock`;
  const title = `${journey.values.runPrefix} warehouse cycle count variance`;
  journey.values.reason = stockReason;
  journey.values.title = title;
  journey.values.itemCode = 'RM-001';
  journey.values.expectedSystemQuantity = 1;
  journey.values.countedQuantity = 2;
  journey.values.variance = 1;
  journey.values.variancePercent = 100;

  const plannedSteps = [
    { name: 'Observer opens the Stock Count list before work is created', actor: observerIdentity.actor, uiPath: 'Warehouse → Warehouse Map → Stock Count', expectedStatus: 'no session' },
    { name: 'Warehouse posts one low-value inbound adjustment as count prerequisite', actor: warehouseIdentity.actor, uiPath: 'Warehouse → Stock Adjustments → New adjustment → Submit', expectedStatus: 'approved; on-hand 1 kg' },
    { name: 'Warehouse creates a warehouse-scoped count session', actor: warehouseIdentity.actor, uiPath: 'Warehouse → Warehouse Map → Stock Count → New session → Create session', expectedStatus: 'draft' },
    { name: 'Warehouse starts the count; observer sees the live state', actor: warehouseIdentity.actor, uiPath: 'Count session → Start count → Start counting', expectedStatus: 'in_progress' },
    { name: 'Warehouse records a physical count of 2 kg; observer sees the variance', actor: warehouseIdentity.actor, uiPath: 'Count line → Count → Record', expectedStatus: 'counted; 100% variance' },
    { name: 'Independent Warehouse checker approves the above-tolerance variance', actor: checkerIdentity.actor, uiPath: 'Warehouse → Warehouse Map → Stock Count → Select the count → Approve variance', expectedStatus: 'verified' },
    { name: 'Independent Warehouse finisher completes and reconciles stock', actor: finisherIdentity.actor, uiPath: 'Warehouse → Warehouse Map → Stock Count → Select the count → Complete session', expectedStatus: 'completed; adjusted; on-hand 2 kg' },
  ];
  const sessions: Array<{ actor: string; session: ActorPage }> = [];
  const openActor = async (actor: string): Promise<ActorPage> => {
    const session = await actorPage(browser, actor);
    sessions.push({ actor, session });
    return session;
  };
  const warehouse = await openActor(warehouseIdentity.actor);
  const checker = await openActor(checkerIdentity.actor);
  const finisher = await openActor(finisherIdentity.actor);
  const observer = await openActor(observerIdentity.actor);
  const checkDiagnostics = () => {
    for (const { actor, session } of sessions) session.diagnostics.assertClean(`${journey.journeyId}:${actor}`);
  };
  const step = (
    actor: string,
    name: string,
    uiPath: string,
    expectedStatus: string,
    session: ActorPage,
    action: () => Promise<void>,
    verify: () => Promise<void>,
    invariants: () => Promise<void>,
  ) => runProcessStep({
    name, actor, uiPath, expectedStatus, page: session.page, plannedSteps, context: journey, testInfo,
    action,
    verify,
    assertInvariants: async () => { await invariants(); checkDiagnostics(); },
  });
  const openCountList = async (session: ActorPage): Promise<void> => {
    await openInternalHome(session);
    await openMenuItem(session.page, 'Warehouse', 'Warehouse Map', '/inventory/warehouse-map');
    await expect(session.page.getByRole('heading', { name: 'Warehouse map', exact: true })).toBeVisible();
    await session.page.getByRole('radio', { name: 'Stock Count', exact: true }).click();
    await expect(session.page).toHaveURL('/inventory/warehouse-map?view=count');
    await expect(session.page.getByRole('heading', { name: 'Stock counts', exact: true })).toBeVisible();
  };
  const openSessionFromList = async (session: ActorPage): Promise<import('@playwright/test').Locator> => {
    await openCountList(session);
    const card = session.page.getByRole('button').filter({ hasText: title });
    await expect(card, 'actor should locate the count through the Stock Count list').toHaveCount(1);
    await card.click();
    await expect(session.page.getByRole('heading', { name: new RegExp(title.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')) })).toBeVisible();
    return card;
  };

  try {
    await step(
      observerIdentity.actor,
      'Observer opens the Stock Count list before work is created',
      'Warehouse → Warehouse Map → Stock Count',
      'no session',
      observer,
      async () => {
        await openCountList(observer);
        await expect(observer.page.getByRole('button').filter({ hasText: title })).toHaveCount(0);
      },
      async () => {
        const rows = await readOnlyDatabaseJson<Array<{ count: number }>>(`
          SELECT COUNT(*)::int AS count FROM stock_count_sessions WHERE title = ${sqlText(title)}
        `);
        expect(rows).toEqual([{ count: 0 }]);
      },
      async () => await expect(observer.page.getByRole('heading', { name: 'Stock counts', exact: true })).toBeVisible(),
    );

    await step(
      warehouseIdentity.actor,
      'Warehouse posts one low-value inbound adjustment as count prerequisite',
      'Warehouse → Stock Adjustments → New adjustment → Submit',
      'approved; on-hand 1 kg',
      warehouse,
      async () => {
        await openInternalHome(warehouse);
        await submitLowValueInboundAdjustment(warehouse, stockReason);
      },
      async () => await expect(warehouse.page.getByRole('row').filter({ hasText: stockReason })).toContainText('Approved by'),
      async () => {
        const adjustment = await adjustmentByReason(journey, stockReason);
        expect(adjustment).toMatchObject({
          item_code: 'RM-001', status: 'approved', direction: 'in', quantity: '1.000',
          unit_cost: '120.0000', value: '120.00', stock_movement_id: expect.any(Number),
          requester_email: warehouseIdentity.email, movement_type: 'adjustment_in',
          movement_quantity: '1.000', movement_total_cost: '120.00',
          movement_actor_email: warehouseIdentity.email, movement_count: 1, on_hand: '1.000',
        });
      },
    );

    await step(
      warehouseIdentity.actor,
      'Warehouse creates a warehouse-scoped count session',
      'Warehouse → Warehouse Map → Stock Count → New session → Create session',
      'draft',
      warehouse,
      async () => {
        await openCountList(warehouse);
        await warehouse.page.getByRole('button', { name: 'New session', exact: true }).click();
        const modal = warehouse.page.getByRole('dialog');
        await expect(modal.getByRole('heading', { name: 'New count session', exact: true })).toBeVisible();
        await modal.getByRole('textbox', { name: 'Title', exact: true }).fill(title);
        await modal.getByRole('combobox', { name: /^Scope/ }).selectOption('warehouse');
        const warehouseSelect = modal.getByRole('combobox', { name: /^Warehouse/ });
        const warehouseId = await warehouseSelect.locator('option').nth(1).getAttribute('value');
        expect(warehouseId, 'E2E reference data must expose an active warehouse').toBeTruthy();
        await warehouseSelect.selectOption(warehouseId!);
        const form = modal.locator('form');
        const formState = await form.evaluate((element) => {
          const submittedForm = element as HTMLFormElement;
          return {
            valid: submittedForm.checkValidity(),
            fields: Array.from(submittedForm.elements)
              .filter((field): field is HTMLInputElement | HTMLSelectElement =>
                field instanceof HTMLInputElement || field instanceof HTMLSelectElement,
              )
              .map((field) => ({
                name: field.name,
                value: field.value,
                required: field.required,
                valid: field.validity.valid,
              })),
          };
        });
        expect(formState).toMatchObject({
          valid: true,
          fields: expect.arrayContaining([
            { name: 'title', value: title, required: true, valid: true },
            { name: 'scope', value: 'warehouse', required: true, valid: true },
            { name: 'warehouse_id', value: warehouseId, required: true, valid: true },
          ]),
        });
        const createButton = modal.getByRole('button', { name: 'Create session', exact: true });
        await expect(createButton).toBeEnabled();
        const createResponsePromise = warehouse.page.waitForResponse((response) => {
          const url = new URL(response.url());
          return response.request().method() === 'POST' && url.pathname === '/api/v1/inventory/stock-counts';
        }, { timeout: 12_000 });
        await createButton.click();
        const createResponse = await createResponsePromise;
        expect(createResponse.status(), 'stock-count creation should be accepted by the API').toBe(201);
        const createdSession = await createResponse.json() as { data?: { id?: string } };
        expect(createdSession.data?.id, 'creation response should identify the session opened by the UI').toBeTruthy();
        journey.values.sessionId = createdSession.data?.id;
        await expect(warehouse.page.getByText('Draft', { exact: true }).first()).toBeVisible();
        await expect(warehouse.page.getByRole('row').filter({ hasText: 'RM-001' })).toContainText('1.000');
      },
      async () => {
        const card = observer.page.getByRole('button').filter({ hasText: title });
        await expect(card, 'observer should receive the new session without refreshing').toHaveCount(1);
        await expect(card).toContainText('Draft');
        await card.click();
        await expect(observer.page.getByRole('heading').filter({ hasText: title })).toHaveCount(1);
        await expect(observer.page.getByRole('row').filter({ hasText: 'RM-001' })).toContainText('Pending');
      },
      async () => {
        const session = await stockCountByTitle(journey);
        journey.values.sessionNumber = session.session_number;
        expect(session).toMatchObject({ title, scope: 'warehouse', status: 'draft', total_locations: 1, item_count: 1, creator_email: warehouseIdentity.email });
        const state = await stockCountVarianceState(journey);
        expect(state).toMatchObject({ status: 'draft', item_code: 'RM-001', item_status: 'pending', system_quantity: '1.000', counted_quantity: null, on_hand: '1.000', movement_count: 0 });
        await assertStockCountAuditActor(journey, warehouseIdentity.email);
      },
    );

    await step(
      warehouseIdentity.actor,
      'Warehouse starts the count; observer sees the live state',
      'Count session → Start count → Start counting',
      'in_progress',
      warehouse,
      async () => {
        await warehouse.page.getByRole('button', { name: 'Start count', exact: true }).click();
        const confirmation = warehouse.page.getByRole('dialog');
        await expect(confirmation.getByRole('heading', { name: 'Start count session?', exact: true })).toBeVisible();
        await confirmation.getByRole('button', { name: 'Start counting', exact: true }).click();
        await expect(warehouse.page.getByText('In Progress', { exact: true }).first()).toBeVisible();
        await expect(warehouse.page.getByRole('row').filter({ hasText: 'RM-001' }).getByRole('button', { name: 'Count', exact: true })).toBeEnabled();
      },
      async () => await expect(observer.page.getByRole('button').filter({ hasText: title })).toContainText('In Progress'),
      async () => {
        const state = await stockCountVarianceState(journey);
        expect(state).toMatchObject({ status: 'in_progress', item_status: 'pending', system_quantity: '1.000', on_hand: '1.000', movement_count: 0 });
      },
    );

    await step(
      warehouseIdentity.actor,
      'Warehouse records a physical count of 2 kg; observer sees the variance',
      'Count line → Count → Record',
      'counted; 100% variance',
      warehouse,
      async () => {
        const line = warehouse.page.getByRole('row').filter({ hasText: 'RM-001' });
        await line.getByRole('button', { name: 'Count', exact: true }).click();
        const modal = warehouse.page.getByRole('dialog');
        await expect(modal.getByRole('heading', { name: /Count —/ })).toBeVisible();
        await expect(modal).toContainText('System quantity: 1.000');
        await modal.getByRole('spinbutton', { name: 'Counted quantity' }).fill('2');
        await modal.getByRole('button', { name: 'Record', exact: true }).click();
        await expect(warehouse.page.getByRole('row').filter({ hasText: 'RM-001' })).toContainText('Counted');
        await expect(warehouse.page.getByRole('row').filter({ hasText: 'RM-001' })).toContainText('+1.000');
        await expect(warehouse.page.getByRole('row').filter({ hasText: 'RM-001' })).toContainText('100.0%');
        await expect(warehouse.page.getByRole('row').filter({ hasText: 'RM-001' }).getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
      },
      async () => {
        const line = observer.page.getByRole('row').filter({ hasText: 'RM-001' });
        await expect(line, 'observer should receive the count line update without refreshing').toContainText('Counted');
        await expect(line).toContainText('+1.000');
      },
      async () => {
        const state = await stockCountVarianceState(journey);
        expect(state).toMatchObject({
          status: 'in_progress', item_status: 'counted', system_quantity: '1.000',
          counted_quantity: '2.000', variance: '1.000', variance_percent: '100.00',
          counter_email: warehouseIdentity.email, verifier_email: null,
          on_hand: '1.000', movement_count: 0,
        });
      },
    );

    await step(
      checkerIdentity.actor,
      'Independent Warehouse checker approves the above-tolerance variance',
      'Warehouse → Warehouse Map → Stock Count → Select the count → Approve variance',
      'verified',
      checker,
      async () => {
        await openSessionFromList(checker);
        const line = checker.page.getByRole('row').filter({ hasText: 'RM-001' });
        await expect(line).toContainText('Counted');
        await expect(line.getByRole('button', { name: 'Approve', exact: true })).toBeEnabled();
        await line.getByRole('button', { name: 'Approve', exact: true }).click();
        const confirmation = checker.page.getByRole('dialog');
        await expect(confirmation.getByRole('heading', { name: 'Approve variance?', exact: true })).toBeVisible();
        await expect(confirmation).toContainText('100.00%');
        await confirmation.getByRole('button', { name: 'Approve', exact: true }).click();
        await expect(line).toContainText('Verified');
      },
      async () => {
        const line = observer.page.getByRole('row').filter({ hasText: 'RM-001' });
        await expect(line, 'observer should receive variance verification without refreshing').toContainText('Verified');
        await expect(observer.page.getByRole('button').filter({ hasText: title })).toContainText('In Progress');
      },
      async () => {
        const state = await stockCountVarianceState(journey);
        expect(state).toMatchObject({
          status: 'in_progress', item_status: 'verified', variance: '1.000', variance_percent: '100.00',
          counter_email: warehouseIdentity.email, verifier_email: checkerIdentity.email,
          on_hand: '1.000', movement_count: 0,
        });
        await assertStockCountItemAuditActors(journey, [warehouseIdentity.email, checkerIdentity.email]);
      },
    );

    await step(
      finisherIdentity.actor,
      'Independent Warehouse finisher completes and reconciles stock',
      'Warehouse → Warehouse Map → Stock Count → Select the count → Complete session',
      'completed; adjusted; on-hand 2 kg',
      finisher,
      async () => {
        await openSessionFromList(finisher);
        await expect(finisher.page.getByRole('button', { name: 'Complete session', exact: true })).toBeEnabled();
        const line = finisher.page.getByRole('row').filter({ hasText: 'RM-001' });
        await expect(line).toContainText('Verified');
        await finisher.page.getByRole('button', { name: 'Complete session', exact: true }).click();
        const confirmation = finisher.page.getByRole('dialog');
        await expect(confirmation.getByRole('heading', { name: 'Complete count session?', exact: true })).toBeVisible();
        await expect(confirmation).toContainText('Variances above 2%');
        await confirmation.getByRole('button', { name: 'Complete', exact: true }).click();
        await expect(finisher.page.getByText('Completed', { exact: true }).first()).toBeVisible();
        await expect(line).toContainText('Adjusted');
      },
      async () => {
        await expect(observer.page.getByRole('button').filter({ hasText: title }), 'observer should see completed status without refreshing').toContainText('Completed');
        await expect(observer.page.getByRole('row').filter({ hasText: 'RM-001' })).toContainText('Adjusted');
      },
      async () => {
        const state = await stockCountVarianceState(journey);
        expect(state).toMatchObject({
          status: 'completed', total_locations: 1, counted_locations: 1,
          variance_count: 1, variance_value: '120.00', completer_email: finisherIdentity.email,
          item_code: 'RM-001', item_status: 'adjusted', system_quantity: '1.000',
          counted_quantity: '2.000', variance: '1.000', variance_percent: '100.00',
          counter_email: warehouseIdentity.email, verifier_email: checkerIdentity.email,
          on_hand: '2.000', movement_count: 1, movement_type: 'adjustment_in',
          movement_quantity: '1.000', movement_total_cost: '120.00',
          movement_actor_email: finisherIdentity.email, adjustment_count: 1, adjustment_status: 'approved',
        });
        await assertStockCountAuditActor(journey, warehouseIdentity.email);
        await assertStockCountItemAuditActors(journey, [warehouseIdentity.email, checkerIdentity.email, finisherIdentity.email]);
        const duplicateMovements = await readOnlyDatabaseJson<Array<{ count: number }>>(`
          SELECT COUNT(*)::int AS count FROM stock_movements movement
          WHERE movement.reference_type = 'stock_count_session'
            AND movement.reference_id = ${state.id}
        `);
        expect(duplicateMovements).toEqual([{ count: 1 }]);
      },
    );
  } finally {
    for (const { session } of sessions.reverse()) await session.context.close();
  }
});

type TransferState = {
  id: number;
  transfer_number: string;
  status: string;
  quantity: string;
  reason: string;
  source_code: string;
  destination_code: string;
  creator_email: string;
  transferrer_email: string | null;
  source_on_hand: string;
  destination_on_hand: string;
  movement_count: number;
  movement_type: string | null;
  movement_quantity: string | null;
  movement_unit_cost: string | null;
  movement_total_cost: string | null;
  movement_journal_entry_id: string | null;
  movement_gl_handoff_status: string | null;
  movement_actor_email: string | null;
  notification_count: number;
};

async function transferState(context: JourneyContext): Promise<TransferState> {
  const reason = contextValue<string>(context, 'reason');
  const rows = await readOnlyDatabaseJson<TransferState[]>(`
    SELECT transfer.id, transfer.transfer_number, transfer.status, transfer.quantity::text,
           transfer.reason, source.code AS source_code, destination.code AS destination_code,
           creator.email AS creator_email, transferrer.email AS transferrer_email,
           COALESCE(source_level.quantity, 0)::numeric(14, 3)::text AS source_on_hand,
           COALESCE(destination_level.quantity, 0)::numeric(14, 3)::text AS destination_on_hand,
           (SELECT COUNT(*)::int FROM stock_movements movement
            WHERE movement.reference_type = 'stock_transfer'
              AND movement.reference_id = transfer.id) AS movement_count,
           movement.movement_type, movement.quantity::text AS movement_quantity,
           movement.unit_cost::text AS movement_unit_cost,
           movement.total_cost::text AS movement_total_cost,
           movement.journal_entry_id::text AS movement_journal_entry_id,
           movement.gl_handoff_status AS movement_gl_handoff_status,
           (SELECT COUNT(*)::int FROM notifications notice
            WHERE notice.data ->> 'subject_type' ILIKE '%TransferOrder%'
              AND notice.data ->> 'subject_id' = transfer.id::text) AS notification_count,
           movement_actor.email AS movement_actor_email
    FROM transfer_orders transfer
    JOIN warehouse_locations source ON source.id = transfer.from_location_id
    JOIN warehouse_locations destination ON destination.id = transfer.to_location_id
    JOIN users creator ON creator.id = transfer.created_by
    LEFT JOIN users transferrer ON transferrer.id = transfer.transferred_by
    LEFT JOIN stock_levels source_level
      ON source_level.item_id = transfer.item_id AND source_level.location_id = transfer.from_location_id
    LEFT JOIN stock_levels destination_level
      ON destination_level.item_id = transfer.item_id AND destination_level.location_id = transfer.to_location_id
    LEFT JOIN stock_movements movement
      ON movement.reference_type = 'stock_transfer' AND movement.reference_id = transfer.id
    LEFT JOIN users movement_actor ON movement_actor.id = movement.created_by
    WHERE transfer.reason = ${sqlText(reason)}
  `);
  expect(rows, `one transfer order should match ${reason}`).toHaveLength(1);
  return rows[0];
}

async function assertTransferAuditActors(context: JourneyContext, expectedEmails: string[]): Promise<void> {
  const rows = await readOnlyDatabaseJson<Array<{ email: string }>>(`
    SELECT DISTINCT actor.email
    FROM audit_logs log
    JOIN users actor ON actor.id = log.user_id
    WHERE log.model_type ILIKE '%TransferOrder%'
      AND log.model_id = (
        SELECT transfer.id FROM transfer_orders transfer
        WHERE transfer.reason = ${sqlText(contextValue<string>(context, 'reason'))}
      )
  `);
  const actual = rows.map((row) => row.email);
  for (const email of expectedEmails) expect(actual).toContain(email);
}

test('INV01-TRANSFER — Warehouse creates and another Warehouse user executes a location transfer', async ({ browser }, testInfo) => {
  test.setTimeout(4 * 60 * 1000);
  const actors = await loadActors();
  const makerIdentity = actorByName(actors, 'warehouse_staff');
  const executorIdentity = actorByName(actors, 'warehouse_staff__2');
  const observerIdentity = actorByName(actors, 'system_admin');
  const journey = createJourneyContext('INV01-TRANSFER');
  const stockReason = `${journey.values.runPrefix} transfer prerequisite stock`;
  const reason = `${journey.values.runPrefix} location transfer`;
  journey.values.stockReason = stockReason;
  journey.values.reason = reason;
  journey.values.itemCode = 'RM-001';
  journey.values.quantity = '0.500';

  const plannedSteps = [
    { name: 'Observer opens Inventory Transfers before work exists', actor: observerIdentity.actor, uiPath: 'Warehouse → Inventory Transfers', expectedStatus: 'no transfer' },
    { name: 'Warehouse posts 1 kg as a UI-created stock prerequisite', actor: makerIdentity.actor, uiPath: 'Warehouse → Stock Adjustments → New adjustment → Submit', expectedStatus: 'approved; source has 1 kg' },
    { name: 'Warehouse maker creates a pending transfer order', actor: makerIdentity.actor, uiPath: 'Warehouse → Inventory Transfers → New transfer → Create transfer order', expectedStatus: 'pending; maker cannot execute' },
    { name: 'Independent Warehouse executor finds and executes the transfer', actor: executorIdentity.actor, uiPath: 'Warehouse → Inventory Transfers → select pending transfer → Execute', expectedStatus: 'transferred; balances moved once' },
  ];
  const sessions: Array<{ actor: string; session: ActorPage }> = [];
  const openActor = async (actor: string): Promise<ActorPage> => {
    const session = await actorPage(browser, actor);
    sessions.push({ actor, session });
    return session;
  };
  const maker = await openActor(makerIdentity.actor);
  const executor = await openActor(executorIdentity.actor);
  const observer = await openActor(observerIdentity.actor);
  const checkDiagnostics = () => {
    for (const { actor, session } of sessions) session.diagnostics.assertClean(`${journey.journeyId}:${actor}`);
  };
  const step = (
    actor: string,
    name: string,
    uiPath: string,
    expectedStatus: string,
    session: ActorPage,
    action: () => Promise<void>,
    verify: () => Promise<void>,
    invariants: () => Promise<void>,
  ) => runProcessStep({
    name, actor, uiPath, expectedStatus, page: session.page, plannedSteps, context: journey, testInfo,
    action,
    verify,
    assertInvariants: async () => { await invariants(); checkDiagnostics(); },
  });
  const selectTransfer = async (session: ActorPage, transferNumber: string): Promise<void> => {
    const card = session.page.getByRole('button').filter({ hasText: transferNumber });
    await expect(card, 'actor should locate the transfer through the Inventory Transfers list').toHaveCount(1);
    await card.click();
    await expect(session.page.getByRole('region', { name: 'Transfer details' })).toContainText(transferNumber);
  };

  try {
    await step(
      observerIdentity.actor,
      'Observer opens Inventory Transfers before work exists',
      'Warehouse → Inventory Transfers',
      'no transfer',
      observer,
      async () => {
        await openTransfers(observer);
        await expect(observer.page.getByRole('heading', { name: 'No transfer orders', exact: true })).toBeVisible();
      },
      async () => {
        const rows = await readOnlyDatabaseJson<Array<{ count: number }>>(`
          SELECT COUNT(*)::int AS count FROM transfer_orders WHERE reason = ${sqlText(reason)}
        `);
        expect(rows).toEqual([{ count: 0 }]);
      },
      async () => await expect(observer.page.getByRole('heading', { name: 'No transfer orders', exact: true })).toBeVisible(),
    );

    await step(
      makerIdentity.actor,
      'Warehouse posts 1 kg as a UI-created stock prerequisite',
      'Warehouse → Stock Adjustments → New adjustment → Submit',
      'approved; source has 1 kg',
      maker,
      async () => {
        await openInternalHome(maker);
        await submitLowValueInboundAdjustment(maker, stockReason);
      },
      async () => await expect(maker.page.getByRole('row').filter({ hasText: stockReason })).toContainText('Approved by'),
      async () => {
        const rows = await readOnlyDatabaseJson<Array<{ quantity: string; movement_count: number; source_code: string }>>(`
          SELECT COALESCE(level.quantity, 0)::numeric(14, 3)::text AS quantity,
                 (SELECT COUNT(*)::int FROM stock_movements movement
                  WHERE movement.reference_type = 'stock_adjustment'
                    AND movement.reference_id = adjustment.id) AS movement_count,
                 location.code AS source_code
          FROM stock_adjustments adjustment
          JOIN warehouse_locations location ON location.id = adjustment.location_id
          LEFT JOIN stock_levels level ON level.item_id = adjustment.item_id AND level.location_id = adjustment.location_id
          WHERE adjustment.reason = ${sqlText(stockReason)}
        `);
        expect(rows).toHaveLength(1);
        expect(rows[0]).toMatchObject({ quantity: '1.000', movement_count: 1 });
        expect(rows[0].source_code).toBeTruthy();
        journey.values.sourceCode = rows[0].source_code;
      },
    );

    await step(
      makerIdentity.actor,
      'Warehouse maker creates a pending transfer order',
      'Warehouse → Inventory Transfers → New transfer → Create transfer order',
      'pending; maker cannot execute',
      maker,
      async () => {
        await openTransfers(maker);
        await maker.page.getByRole('button', { name: 'New transfer', exact: true }).click();
        const modal = maker.page.getByRole('dialog');
        await expect(modal.getByRole('heading', { name: 'New transfer order', exact: true })).toBeVisible();
        const item = modal.getByRole('combobox', { name: 'Item', exact: true });
        await item.fill('RM-001');
        // SearchableSelect renders its listbox into a document-level portal.
        const itemOption = maker.page.getByRole('option').filter({ hasText: 'RM-001' }).first();
        await expect(itemOption).toBeVisible();
        await itemOption.click();

        const source = modal.getByRole('combobox', { name: 'From location', exact: true });
        await expect(source).toBeEnabled();
        const sourceOption = source.locator('option').filter({ hasText: '1 kg available' }).first();
        await expect(sourceOption).toHaveCount(1);
        const sourceId = await sourceOption.getAttribute('value');
        expect(sourceId).toBeTruthy();
        await source.selectOption(sourceId!);

        const destination = modal.getByRole('combobox', { name: 'To location', exact: true });
        await expect(destination).toBeEnabled();
        const destinationId = await destination.locator('option').nth(1).getAttribute('value');
        expect(destinationId, 'E2E baseline must expose a second active stock destination').toBeTruthy();
        journey.values.destinationId = destinationId;
        await destination.selectOption(destinationId!);
        await modal.getByRole('spinbutton', { name: 'Quantity', exact: true }).fill('0.500');
        await modal.getByRole('textbox', { name: 'Reason (optional)', exact: true }).fill(reason);
        const create = modal.getByRole('button', { name: 'Create transfer order', exact: true });
        await expect(create).toBeEnabled();
        const responsePromise = maker.page.waitForResponse((response) => {
          const url = new URL(response.url());
          return response.request().method() === 'POST' && url.pathname === '/api/v1/inventory/transfer-orders';
        }, { timeout: 12_000 });
        await create.click();
        const response = await responsePromise;
        expect(response.status(), 'transfer order creation should be accepted by the API').toBe(201);
        const payload = await response.json() as { data?: { id?: string; transfer_number?: string } };
        expect(payload.data?.id, 'UI creation response should identify its transfer order').toBeTruthy();
        expect(payload.data?.transfer_number).toMatch(/^TO-/);
        journey.values.transferId = payload.data?.id;
        journey.values.transferNumber = payload.data?.transfer_number;
        await expect(maker.page.getByRole('region', { name: 'Transfer details' })).toContainText('Pending');
        await expect(maker.page.getByText('Another warehouse user must execute a transfer you created.', { exact: true })).toBeVisible();
        await expect(maker.page.getByRole('button', { name: 'Execute', exact: true })).toHaveCount(0);
      },
      async () => {
        const transferNumber = contextValue<string>(journey, 'transferNumber');
        await selectTransfer(observer, transferNumber);
        const details = observer.page.getByRole('region', { name: 'Transfer details' });
        await expect(details).toContainText('Pending');
        await expect(details).toContainText('RM-001');
        await expect(details).toContainText('0.5 kg');
        await captureAnnotatedStep(observer.page, observerIdentity.actor, 'Observer finds the pending transfer live', 3, testInfo);
      },
      async () => {
        const state = await transferState(journey);
        expect(state).toMatchObject({
          status: 'pending', quantity: '0.500', reason,
          creator_email: makerIdentity.email, transferrer_email: null,
          source_on_hand: '1.000', destination_on_hand: '0.000', movement_count: 0,
          movement_type: null, movement_actor_email: null, notification_count: 0,
        });
        await assertTransferAuditActors(journey, [makerIdentity.email]);
      },
    );

    await step(
      executorIdentity.actor,
      'Independent Warehouse executor finds and executes the transfer',
      'Warehouse → Inventory Transfers → select pending transfer → Execute',
      'transferred; balances moved once',
      executor,
      async () => {
        await openTransfers(executor);
        const transferNumber = contextValue<string>(journey, 'transferNumber');
        await selectTransfer(executor, transferNumber);
        const details = executor.page.getByRole('region', { name: 'Transfer details' });
        await expect(details.getByRole('button', { name: 'Execute', exact: true })).toBeEnabled();
        await expect(details.getByRole('button', { name: 'Cancel', exact: true })).toBeEnabled();
        await details.getByRole('button', { name: 'Execute', exact: true }).click();
        const confirmation = executor.page.getByRole('dialog');
        await expect(confirmation.getByRole('heading', { name: 'Execute transfer?', exact: true })).toBeVisible();
        await expect(confirmation).toContainText('0.5 kg');
        await expect(confirmation).toContainText('A stock movement will be recorded.');
        const execute = confirmation.getByRole('button', { name: 'Execute', exact: true });
        await expect(execute).toBeEnabled();
        const responsePromise = executor.page.waitForResponse((response) => {
          const url = new URL(response.url());
          return response.request().method() === 'POST'
            && url.pathname.endsWith(`/inventory/transfer-orders/${contextValue<string>(journey, 'transferId')}/execute`);
        }, { timeout: 12_000 });
        await execute.click();
        const response = await responsePromise;
        expect(response.status(), 'transfer execution should be accepted by the API').toBe(200);
        await expect(details).toContainText('Transferred');
        await expect(details.getByRole('button', { name: 'Execute', exact: true })).toHaveCount(0);
        await expect(details.getByRole('button', { name: 'Cancel', exact: true })).toHaveCount(0);
      },
      async () => {
        const details = observer.page.getByRole('region', { name: 'Transfer details' });
        await expect(details, 'observer should receive the transfer status without refresh').toContainText('Transferred');
        await expect(details).toContainText('Transferred by');
        await expect(details.getByRole('button', { name: 'Execute', exact: true })).toHaveCount(0);
        await expect(details.getByRole('button', { name: 'Cancel', exact: true })).toHaveCount(0);
        await captureAnnotatedStep(observer.page, observerIdentity.actor, 'Observer confirms transfer and actions close live', 4, testInfo);
      },
      async () => {
        const state = await transferState(journey);
        expect(state).toMatchObject({
          status: 'transferred', quantity: '0.500', reason,
          creator_email: makerIdentity.email, transferrer_email: executorIdentity.email,
          source_on_hand: '0.500', destination_on_hand: '0.500', movement_count: 1,
          movement_type: 'transfer', movement_quantity: '0.500',
          movement_unit_cost: '120.0000', movement_total_cost: '60.00',
          movement_journal_entry_id: null, movement_gl_handoff_status: 'not_required',
          notification_count: 0,
          movement_actor_email: executorIdentity.email,
        });
        expect(state.source_code).toBe(contextValue<string>(journey, 'sourceCode'));
        await assertTransferAuditActors(journey, [makerIdentity.email, executorIdentity.email]);
      },
    );
  } finally {
    for (const { session } of sessions.reverse()) await session.context.close();
  }
});

test('INV01-TRANSFER-CANCEL — Warehouse cancels a pending transfer without changing stock', async ({ browser }, testInfo) => {
  test.setTimeout(4 * 60 * 1000);
  const actors = await loadActors();
  const makerIdentity = actorByName(actors, 'warehouse_staff');
  const observerIdentity = actorByName(actors, 'system_admin');
  const journey = createJourneyContext('INV01-TRANSFER-CANCEL');
  const stockReason = `${journey.values.runPrefix} transfer-cancel prerequisite stock`;
  const reason = `${journey.values.runPrefix} cancelled location transfer`;
  journey.values.stockReason = stockReason;
  journey.values.reason = reason;
  journey.values.itemCode = 'RM-001';
  journey.values.quantity = '0.500';

  const plannedSteps = [
    { name: 'Observer opens Inventory Transfers before work exists', actor: observerIdentity.actor, uiPath: 'Warehouse → Inventory Transfers', expectedStatus: 'no transfer' },
    { name: 'Warehouse posts 1 kg as a UI-created stock prerequisite', actor: makerIdentity.actor, uiPath: 'Warehouse → Stock Adjustments → New adjustment → Submit', expectedStatus: 'approved; source has 1 kg' },
    { name: 'Warehouse maker creates a pending transfer order', actor: makerIdentity.actor, uiPath: 'Warehouse → Inventory Transfers → New transfer → Create transfer order', expectedStatus: 'pending; maker may cancel but cannot execute' },
    { name: 'Warehouse maker cancels the pending transfer', actor: makerIdentity.actor, uiPath: 'Transfer detail → Cancel → Confirm Cancel transfer', expectedStatus: 'cancelled; stock unchanged' },
  ];
  const sessions: Array<{ actor: string; session: ActorPage }> = [];
  const openActor = async (actor: string): Promise<ActorPage> => {
    const session = await actorPage(browser, actor);
    sessions.push({ actor, session });
    return session;
  };
  const maker = await openActor(makerIdentity.actor);
  const observer = await openActor(observerIdentity.actor);
  const checkDiagnostics = () => {
    for (const { actor, session } of sessions) session.diagnostics.assertClean(`${journey.journeyId}:${actor}`);
  };
  const step = (
    actor: string,
    name: string,
    uiPath: string,
    expectedStatus: string,
    session: ActorPage,
    action: () => Promise<void>,
    verify: () => Promise<void>,
    invariants: () => Promise<void>,
  ) => runProcessStep({
    name, actor, uiPath, expectedStatus, page: session.page, plannedSteps, context: journey, testInfo,
    action,
    verify,
    assertInvariants: async () => { await invariants(); checkDiagnostics(); },
  });
  const selectTransfer = async (session: ActorPage, transferNumber: string): Promise<void> => {
    const card = session.page.getByRole('button').filter({ hasText: transferNumber });
    await expect(card, 'actor should locate the transfer through the Inventory Transfers list').toHaveCount(1);
    await card.click();
    await expect(session.page.getByRole('region', { name: 'Transfer details' })).toContainText(transferNumber);
  };

  try {
    await step(
      observerIdentity.actor,
      'Observer opens Inventory Transfers before work exists',
      'Warehouse → Inventory Transfers',
      'no transfer',
      observer,
      async () => {
        await openTransfers(observer);
        await expect(observer.page.getByRole('heading', { name: 'No transfer orders', exact: true })).toBeVisible();
      },
      async () => {
        const rows = await readOnlyDatabaseJson<Array<{ count: number }>>(`
          SELECT COUNT(*)::int AS count FROM transfer_orders WHERE reason = ${sqlText(reason)}
        `);
        expect(rows).toEqual([{ count: 0 }]);
      },
      async () => await expect(observer.page.getByRole('heading', { name: 'No transfer orders', exact: true })).toBeVisible(),
    );

    await step(
      makerIdentity.actor,
      'Warehouse posts 1 kg as a UI-created stock prerequisite',
      'Warehouse → Stock Adjustments → New adjustment → Submit',
      'approved; source has 1 kg',
      maker,
      async () => {
        await openInternalHome(maker);
        await submitLowValueInboundAdjustment(maker, stockReason);
      },
      async () => await expect(maker.page.getByRole('row').filter({ hasText: stockReason })).toContainText('Approved by'),
      async () => {
        const rows = await readOnlyDatabaseJson<Array<{ quantity: string; movement_count: number }>>(`
          SELECT COALESCE(level.quantity, 0)::numeric(14, 3)::text AS quantity,
                 (SELECT COUNT(*)::int FROM stock_movements movement
                  WHERE movement.reference_type = 'stock_adjustment'
                    AND movement.reference_id = adjustment.id) AS movement_count
          FROM stock_adjustments adjustment
          LEFT JOIN stock_levels level ON level.item_id = adjustment.item_id AND level.location_id = adjustment.location_id
          WHERE adjustment.reason = ${sqlText(stockReason)}
        `);
        expect(rows).toEqual([{ quantity: '1.000', movement_count: 1 }]);
      },
    );

    await step(
      makerIdentity.actor,
      'Warehouse maker creates a pending transfer order',
      'Warehouse → Inventory Transfers → New transfer → Create transfer order',
      'pending; maker may cancel but cannot execute',
      maker,
      async () => {
        await openTransfers(maker);
        await maker.page.getByRole('button', { name: 'New transfer', exact: true }).click();
        const modal = maker.page.getByRole('dialog');
        await expect(modal.getByRole('heading', { name: 'New transfer order', exact: true })).toBeVisible();
        const item = modal.getByRole('combobox', { name: 'Item', exact: true });
        await item.fill('RM-001');
        const itemOption = maker.page.getByRole('option').filter({ hasText: 'RM-001' }).first();
        await expect(itemOption).toBeVisible();
        await itemOption.click();
        const source = modal.getByRole('combobox', { name: 'From location', exact: true });
        await expect(source).toBeEnabled();
        const sourceOption = source.locator('option').filter({ hasText: '1 kg available' }).first();
        await expect(sourceOption).toHaveCount(1);
        await source.selectOption((await sourceOption.getAttribute('value'))!);
        const destination = modal.getByRole('combobox', { name: 'To location', exact: true });
        await expect(destination).toBeEnabled();
        const destinationId = await destination.locator('option').nth(1).getAttribute('value');
        expect(destinationId, 'E2E baseline must expose a second active stock destination').toBeTruthy();
        await destination.selectOption(destinationId!);
        await modal.getByRole('spinbutton', { name: 'Quantity', exact: true }).fill('0.500');
        await modal.getByRole('textbox', { name: 'Reason (optional)', exact: true }).fill(reason);
        const create = modal.getByRole('button', { name: 'Create transfer order', exact: true });
        await expect(create).toBeEnabled();
        const responsePromise = maker.page.waitForResponse((response) => {
          const url = new URL(response.url());
          return response.request().method() === 'POST' && url.pathname === '/api/v1/inventory/transfer-orders';
        }, { timeout: 12_000 });
        await create.click();
        const response = await responsePromise;
        expect(response.status()).toBe(201);
        const payload = await response.json() as { data?: { id?: string; transfer_number?: string } };
        expect(payload.data?.id).toBeTruthy();
        expect(payload.data?.transfer_number).toMatch(/^TO-/);
        journey.values.transferId = payload.data?.id;
        journey.values.transferNumber = payload.data?.transfer_number;
        const details = maker.page.getByRole('region', { name: 'Transfer details' });
        await expect(details).toContainText('Pending');
        await expect(maker.page.getByText('Another warehouse user must execute a transfer you created.', { exact: true })).toBeVisible();
        await expect(details.getByRole('button', { name: 'Execute', exact: true })).toHaveCount(0);
        await expect(details.getByRole('button', { name: 'Cancel', exact: true })).toBeEnabled();
      },
      async () => {
        await selectTransfer(observer, contextValue<string>(journey, 'transferNumber'));
        await expect(observer.page.getByRole('region', { name: 'Transfer details' })).toContainText('Pending');
        await captureAnnotatedStep(observer.page, observerIdentity.actor, 'Observer finds pending transfer before cancellation', 3, testInfo);
      },
      async () => {
        const state = await transferState(journey);
        expect(state).toMatchObject({
          status: 'pending', source_on_hand: '1.000', destination_on_hand: '0.000',
          movement_count: 0, notification_count: 0,
        });
      },
    );

    await step(
      makerIdentity.actor,
      'Warehouse maker cancels the pending transfer',
      'Transfer detail → Cancel → Confirm Cancel transfer',
      'cancelled; stock unchanged',
      maker,
      async () => {
        const details = maker.page.getByRole('region', { name: 'Transfer details' });
        await expect(details.getByRole('button', { name: 'Cancel', exact: true })).toBeEnabled();
        await details.getByRole('button', { name: 'Cancel', exact: true }).click();
        const confirmation = maker.page.getByRole('dialog');
        await expect(confirmation.getByRole('heading', { name: 'Cancel transfer?', exact: true })).toBeVisible();
        await expect(confirmation).toContainText('No stock movement will be recorded.');
        const confirm = confirmation.getByRole('button', { name: 'Cancel transfer', exact: true });
        await expect(confirm).toBeEnabled();
        await confirm.click();
        await expect(details).toContainText('Cancelled');
        await expect(details.getByRole('button', { name: 'Cancel', exact: true })).toHaveCount(0);
        await expect(details.getByRole('button', { name: 'Execute', exact: true })).toHaveCount(0);
      },
      async () => {
        const details = observer.page.getByRole('region', { name: 'Transfer details' });
        await expect(details, 'observer should receive cancellation without refreshing').toContainText('Cancelled');
        await expect(details.getByRole('button', { name: 'Cancel', exact: true })).toHaveCount(0);
        await captureAnnotatedStep(observer.page, observerIdentity.actor, 'Observer confirms cancellation without stock movement', 4, testInfo);
      },
      async () => {
        const state = await transferState(journey);
        expect(state).toMatchObject({
          status: 'cancelled', source_on_hand: '1.000', destination_on_hand: '0.000',
          movement_count: 0, movement_type: null, movement_actor_email: null, notification_count: 0,
        });
        await assertTransferAuditActors(journey, [makerIdentity.email]);
      },
    );
  } finally {
    for (const { session } of sessions.reverse()) await session.context.close();
  }
});

test('INV01-NEGATIVE-STOCK — Warehouse cannot submit an outbound adjustment beyond available stock', async ({ browser }, testInfo) => {
  test.setTimeout(4 * 60 * 1000);
  const actors = await loadActors();
  const warehouseIdentity = actorByName(actors, 'warehouse_staff');
  const financeIdentity = actorByName(actors, 'finance_officer');
  const journey = createJourneyContext('INV01-NEGATIVE-STOCK');
  const stockReason = `${journey.values.runPrefix} negative-stock prerequisite`;
  const rejectedReason = `${journey.values.runPrefix} outbound exceeds available`;
  journey.values.stockReason = stockReason;
  journey.values.reason = rejectedReason;
  journey.values.itemCode = 'RM-001';
  journey.values.stockQuantity = '1.000';
  journey.values.attemptedQuantity = '1.001';

  const plannedSteps = [
    { name: 'Finance opens Stock Adjustments before the Warehouse attempt', actor: financeIdentity.actor, uiPath: 'Finance → Stock Adjustments', expectedStatus: 'no matching adjustment' },
    { name: 'Warehouse posts 1 kg of prerequisite stock through the UI', actor: warehouseIdentity.actor, uiPath: 'Warehouse → Stock Adjustments → New adjustment → Submit', expectedStatus: 'approved; 1.000 kg available' },
    { name: 'Warehouse enters an outbound adjustment above available stock', actor: warehouseIdentity.actor, uiPath: 'Warehouse → Stock Adjustments → New adjustment', expectedStatus: 'not submitted; UI shows available quantity and disables Review adjustment' },
  ];
  const sessions: Array<{ actor: string; session: ActorPage }> = [];
  const openActor = async (actor: string): Promise<ActorPage> => {
    const session = await actorPage(browser, actor);
    sessions.push({ actor, session });
    return session;
  };
  const warehouse = await openActor(warehouseIdentity.actor);
  const finance = await openActor(financeIdentity.actor);
  const checkDiagnostics = () => {
    for (const { actor, session } of sessions) session.diagnostics.assertClean(`${journey.journeyId}:${actor}`);
  };
  const step = (
    actor: string,
    name: string,
    uiPath: string,
    expectedStatus: string,
    session: ActorPage,
    action: () => Promise<void>,
    verify: () => Promise<void>,
    invariants: () => Promise<void>,
  ) => runProcessStep({
    name, actor, uiPath, expectedStatus, page: session.page, plannedSteps, context: journey, testInfo,
    action,
    verify,
    assertInvariants: async () => { await invariants(); checkDiagnostics(); },
  });

  try {
    await step(
      financeIdentity.actor,
      'Finance opens Stock Adjustments before the Warehouse attempt',
      'Finance → Stock Adjustments',
      'no matching adjustment',
      finance,
      async () => {
        await openInternalHome(finance);
        await openMenuItem(finance.page, 'Finance', 'Stock Adjustments', '/inventory/stock-adjustments');
        await expect(finance.page.getByRole('heading', { name: 'Stock Adjustments', exact: true })).toBeVisible();
        await expect(finance.page.getByRole('row').filter({ hasText: rejectedReason })).toHaveCount(0);
      },
      async () => {
        const rows = await readOnlyDatabaseJson<Array<{ count: number }>>(`
          SELECT COUNT(*)::int AS count FROM stock_adjustments
          WHERE reason IN (${sqlText(stockReason)}, ${sqlText(rejectedReason)})
        `);
        expect(rows).toEqual([{ count: 0 }]);
      },
      async () => await expect(finance.page).toHaveURL(/\/inventory\/stock-adjustments(?:\?.*)?$/),
    );

    await step(
      warehouseIdentity.actor,
      'Warehouse posts 1 kg of prerequisite stock through the UI',
      'Warehouse → Stock Adjustments → New adjustment → Submit',
      'approved; 1.000 kg available',
      warehouse,
      async () => {
        await openInternalHome(warehouse);
        await submitLowValueInboundAdjustment(warehouse, stockReason);
      },
      async () => {
        await expect(finance.page.getByRole('row').filter({ hasText: stockReason })).toContainText('Approved by');
        await expect(finance.page.getByRole('row').filter({ hasText: rejectedReason })).toHaveCount(0);
      },
      async () => {
        const rows = await readOnlyDatabaseJson<Array<{ status: string; quantity: string; on_hand: string; movement_count: number }>>(`
          SELECT adjustment.status, adjustment.quantity::numeric(14, 3)::text AS quantity,
                 level.quantity::numeric(14, 3)::text AS on_hand,
                 (SELECT COUNT(*)::int FROM stock_movements movement
                  WHERE movement.reference_type = 'stock_adjustment' AND movement.reference_id = adjustment.id) AS movement_count
          FROM stock_adjustments adjustment
          JOIN stock_levels level ON level.item_id = adjustment.item_id AND level.location_id = adjustment.location_id
          WHERE adjustment.reason = ${sqlText(stockReason)}
        `);
        expect(rows).toEqual([{ status: 'approved', quantity: '1.000', on_hand: '1.000', movement_count: 1 }]);
        await assertAuditActors(journey, [warehouseIdentity.email], stockReason);
      },
    );

    await step(
      warehouseIdentity.actor,
      'Warehouse enters an outbound adjustment above available stock',
      'Warehouse → Stock Adjustments → New adjustment',
      'not submitted; UI shows available quantity and disables Review adjustment',
      warehouse,
      async () => {
        await openMenuItem(warehouse.page, 'Warehouse', 'Stock Adjustments', '/inventory/stock-adjustments');
        await expect(warehouse.page.getByRole('heading', { name: 'Stock Adjustments', exact: true })).toBeVisible();
        await warehouse.page.getByRole('link', { name: 'New adjustment', exact: true }).click();
        await expect(warehouse.page.getByRole('heading', { name: 'Stock adjustment', exact: true })).toBeVisible();
        await warehouse.page.getByRole('combobox', { name: /Direction/ }).selectOption('out');
        const itemInput = warehouse.page.getByRole('combobox', { name: 'Item', exact: true });
        await itemInput.fill('RM-001');
        const itemOption = warehouse.page.getByRole('option').filter({ hasText: 'RM-001' }).first();
        await expect(itemOption).toBeVisible();
        await itemOption.click();
        const location = warehouse.page.getByRole('combobox', { name: /Location/ });
        await expect(location).toBeEnabled();
        await expect(location).toHaveValue(/Main Warehouse/);
        await expect(warehouse.page.getByText(/Filled from the item.s stocked location\./)).toBeVisible();
        await warehouse.page.getByRole('spinbutton', { name: /Quantity/ }).fill('1.001');
        const reasonCode = warehouse.page.getByRole('combobox', { name: /Reason code/ });
        await reasonCode.selectOption({ index: 1 });
        await warehouse.page.getByRole('textbox', { name: /Reason \(recorded for audit\)/ }).fill(rejectedReason);
        await expect(warehouse.page.getByText('Only 1 kg can be removed from this location.', { exact: true })).toBeVisible();
        await expect(warehouse.page.getByRole('button', { name: 'Review adjustment', exact: true })).toBeDisabled();
        await expect(warehouse.page.getByRole('dialog')).toHaveCount(0);
        await captureAnnotatedStep(warehouse.page, warehouseIdentity.actor, 'Outbound quantity above stock is blocked in the UI', 3, testInfo);
      },
      async () => {
        await expect(finance.page.getByRole('row').filter({ hasText: rejectedReason })).toHaveCount(0);
        await expect(warehouse.page.getByRole('button', { name: 'Review adjustment', exact: true })).toBeDisabled();
      },
      async () => {
        const rows = await readOnlyDatabaseJson<Array<{ on_hand: string; stock_adjustments: number; attempt_adjustments: number; movement_count: number; notification_count: number }>>(`
          SELECT level.quantity::numeric(14, 3)::text AS on_hand,
                 (SELECT COUNT(*)::int FROM stock_adjustments adjustment WHERE adjustment.reason = ${sqlText(stockReason)}) AS stock_adjustments,
                 (SELECT COUNT(*)::int FROM stock_adjustments adjustment WHERE adjustment.reason = ${sqlText(rejectedReason)}) AS attempt_adjustments,
                 (SELECT COUNT(*)::int FROM stock_movements movement
                  WHERE movement.reference_type = 'stock_adjustment'
                    AND movement.reference_id IN (SELECT adjustment.id FROM stock_adjustments adjustment WHERE adjustment.reason IN (${sqlText(stockReason)}, ${sqlText(rejectedReason)}))) AS movement_count,
                 (SELECT COUNT(*)::int FROM notifications notice
                  WHERE notice.data ->> 'subject_type' = ${sqlText(stockAdjustmentSubjectType)}
                    AND notice.data ->> 'subject_id' IN (SELECT adjustment.id::text FROM stock_adjustments adjustment WHERE adjustment.reason IN (${sqlText(stockReason)}, ${sqlText(rejectedReason)}))) AS notification_count
          FROM stock_levels level
          JOIN stock_adjustments adjustment ON adjustment.item_id = level.item_id AND adjustment.location_id = level.location_id
          WHERE adjustment.reason = ${sqlText(stockReason)}
        `);
        expect(rows).toEqual([{
          on_hand: '1.000', stock_adjustments: 1, attempt_adjustments: 0,
          movement_count: 1, notification_count: 0,
        }]);
        await assertAuditActors(journey, [warehouseIdentity.email], stockReason);
      },
    );
  } finally {
    for (const { session } of sessions.reverse()) await session.context.close();
  }
});

test('INV01-DUPLICATE-SUBMIT — Warehouse double-clicks submit without duplicating stock', async ({ browser }, testInfo) => {
  test.setTimeout(3 * 60 * 1000);
  const actors = await loadActors();
  const warehouseIdentity = actorByName(actors, 'warehouse_staff');
  const financeIdentity = actorByName(actors, 'finance_officer');
  const journey = createJourneyContext('INV01-DUPLICATE-SUBMIT');
  const reason = `${journey.values.runPrefix} duplicate submit protection`;
  journey.values.reason = reason;
  journey.values.itemCode = 'RM-001';
  journey.values.quantity = '1.000';
  journey.values.unitCost = '120.0000';
  journey.values.expectedValue = '120.00';

  const plannedSteps = [
    { name: 'Finance opens Stock Adjustments before Warehouse submits', actor: financeIdentity.actor, uiPath: 'Finance → Stock Adjustments', expectedStatus: 'no matching adjustment' },
    { name: 'Warehouse double-clicks confirmation; Finance observes one posted adjustment', actor: warehouseIdentity.actor, uiPath: 'Warehouse → Stock Adjustments → New adjustment → Submit adjustment', expectedStatus: 'one approved adjustment and one movement' },
  ];
  const sessions: Array<{ actor: string; session: ActorPage }> = [];
  const openActor = async (actor: string): Promise<ActorPage> => {
    const session = await actorPage(browser, actor);
    sessions.push({ actor, session });
    return session;
  };
  const finance = await openActor(financeIdentity.actor);
  const warehouse = await openActor(warehouseIdentity.actor);
  const checkDiagnostics = () => {
    for (const { actor, session } of sessions) session.diagnostics.assertClean(`${journey.journeyId}:${actor}`);
  };
  const step = (
    actor: string,
    name: string,
    uiPath: string,
    expectedStatus: string,
    session: ActorPage,
    action: () => Promise<void>,
    verify: () => Promise<void>,
    invariants: () => Promise<void>,
  ) => runProcessStep({
    name, actor, uiPath, expectedStatus, page: session.page, plannedSteps, context: journey, testInfo,
    action,
    verify,
    assertInvariants: async () => { await invariants(); checkDiagnostics(); },
  });

  try {
    await step(
      financeIdentity.actor,
      'Finance opens Stock Adjustments before Warehouse submits',
      'Finance → Stock Adjustments',
      'no matching adjustment',
      finance,
      async () => {
        await openInternalHome(finance);
        await openMenuItem(finance.page, 'Finance', 'Stock Adjustments', '/inventory/stock-adjustments');
        await expect(finance.page.getByRole('heading', { name: 'Stock Adjustments', exact: true })).toBeVisible();
        await expect(finance.page.getByRole('row').filter({ hasText: reason })).toHaveCount(0);
      },
      async () => {
        const rows = await readOnlyDatabaseJson<Array<{ count: number }>>(`
          SELECT COUNT(*)::int AS count FROM stock_adjustments WHERE reason = ${sqlText(reason)}
        `);
        expect(rows).toEqual([{ count: 0 }]);
      },
      async () => await expect(finance.page).toHaveURL(/\/inventory\/stock-adjustments(?:\?.*)?$/),
    );

    await step(
      warehouseIdentity.actor,
      'Warehouse double-clicks confirmation; Finance observes one posted adjustment',
      'Warehouse → Stock Adjustments → New adjustment → Submit adjustment',
      'one approved adjustment and one movement',
      warehouse,
      async () => {
        await openInternalHome(warehouse);
        await openMenuItem(warehouse.page, 'Warehouse', 'Stock Adjustments', '/inventory/stock-adjustments');
        await expect(warehouse.page.getByRole('heading', { name: 'Stock Adjustments', exact: true })).toBeVisible();
        await warehouse.page.getByRole('link', { name: 'New adjustment', exact: true }).click();
        await expect(warehouse.page.getByRole('heading', { name: 'Stock adjustment', exact: true })).toBeVisible();
        await warehouse.page.getByRole('combobox', { name: /Direction/ }).selectOption('in');
        const itemInput = warehouse.page.getByRole('combobox', { name: 'Item', exact: true });
        await itemInput.fill('RM-001');
        const itemOption = warehouse.page.getByRole('option').filter({ hasText: 'RM-001' }).first();
        await expect(itemOption).toBeVisible();
        await itemOption.click();
        const location = warehouse.page.getByRole('combobox', { name: /Location/ });
        await expect(location).toBeEnabled();
        await location.click();
        const mainLocation = warehouse.page.getByRole('option').filter({ hasText: 'Main Warehouse' }).first();
        await expect(mainLocation).toBeVisible();
        journey.values.locationLabel = (await mainLocation.innerText()).trim();
        await mainLocation.click();
        await warehouse.page.getByRole('spinbutton', { name: /Quantity/ }).fill('1');
        await warehouse.page.getByRole('spinbutton', { name: /Unit cost/ }).fill('120');
        await warehouse.page.getByRole('combobox', { name: /Reason code/ }).selectOption('found_stock');
        await warehouse.page.getByRole('textbox', { name: /Reason \(recorded for audit\)/ }).fill(reason);
        await expect(warehouse.page.getByText('Finance approval required', { exact: true })).toHaveCount(0);
        await warehouse.page.getByRole('button', { name: 'Review adjustment', exact: true }).click();
        const confirmation = warehouse.page.getByRole('dialog');
        await expect(confirmation.getByRole('heading', { name: 'Submit stock adjustment?' })).toBeVisible();
        await expect(confirmation).toContainText('Stock changes when the adjustment is posted.');

        const postRequests: import('@playwright/test').Request[] = [];
        const postResponses: import('@playwright/test').Response[] = [];
        const failedPosts: string[] = [];
        const isCreateRequest = (url: string) => new URL(url).pathname.endsWith('/inventory/stock-adjustments');
        const recordRequest = (request: import('@playwright/test').Request) => {
          if (request.method() === 'POST' && isCreateRequest(request.url())) postRequests.push(request);
        };
        const recordResponse = (response: import('@playwright/test').Response) => {
          if (response.request().method() === 'POST' && isCreateRequest(response.url())) postResponses.push(response);
        };
        const recordFailure = (request: import('@playwright/test').Request) => {
          if (request.method() === 'POST' && isCreateRequest(request.url())) {
            failedPosts.push(request.failure()?.errorText ?? 'request failed');
          }
        };
        warehouse.page.on('request', recordRequest);
        warehouse.page.on('response', recordResponse);
        warehouse.page.on('requestfailed', recordFailure);
        try {
          const confirmButton = confirmation.getByRole('button', { name: 'Submit adjustment', exact: true });
          await expect(confirmButton).toBeEnabled();
          await confirmButton.dblclick();
          await expect(warehouse.page).toHaveURL('/inventory/stock-levels?view=movements');
          await expect(warehouse.page.getByRole('heading', { name: 'Stock movements', exact: true })).toBeVisible();
          await expect.poll(() => postResponses.length + failedPosts.length, { timeout: 15_000 }).toBeGreaterThan(0);
          await expect.poll(() => postResponses.length).toBe(postRequests.length);
        } finally {
          warehouse.page.off('request', recordRequest);
          warehouse.page.off('response', recordResponse);
          warehouse.page.off('requestfailed', recordFailure);
        }

        expect(failedPosts, 'the adjustment request should complete over HTTP').toEqual([]);
        expect(postRequests.length, 'the double-click should reach the create endpoint').toBeGreaterThan(0);
        expect(postRequests.length, 'one physical double-click should issue at most two create requests').toBeLessThanOrEqual(2);
        expect(postResponses.map((response) => response.status())).toEqual(postRequests.map(() => 201));
        const idempotencyKeys = await Promise.all(postRequests.map((request) => request.headerValue('Idempotency-Key')));
        expect(idempotencyKeys.every((key) => !!key)).toBe(true);
        expect(new Set(idempotencyKeys).size).toBe(1);

        await openMenuItem(warehouse.page, 'Warehouse', 'Stock Adjustments', '/inventory/stock-adjustments');
        const row = warehouse.page.getByRole('row').filter({ hasText: reason });
        await expect(row).toHaveCount(1);
        await expect(row).toContainText('Approved by');
        await captureAnnotatedStep(warehouse.page, warehouseIdentity.actor, 'Warehouse sees one approved record after double-click', 2, testInfo);
      },
      async () => {
        const row = finance.page.getByRole('row').filter({ hasText: reason });
        await expect(row, 'Finance list should show one approved record without refresh').toHaveCount(1);
        await expect(row).toContainText('Approved by');
        await expect(row.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
        await expect(row.getByRole('button', { name: 'Reject', exact: true })).toHaveCount(0);
        await expect(row.getByRole('button', { name: 'Withdraw', exact: true })).toHaveCount(0);
      },
      async () => {
        const adjustment = await adjustmentByReason(journey);
        expect(adjustment).toMatchObject({
          item_code: 'RM-001', status: 'approved', direction: 'in', quantity: '1.000',
          unit_cost: '120.0000', value: '120.00', requester_email: warehouseIdentity.email,
          approver_email: warehouseIdentity.email, movement_type: 'adjustment_in',
          movement_quantity: '1.000', movement_total_cost: '120.00',
          movement_actor_email: warehouseIdentity.email, movement_count: 1, on_hand: '1.000',
        });
        const storedKeys = await readOnlyDatabaseJson<Array<{ idempotency_key: string | null; idempotency_fingerprint: string | null }>>(`
          SELECT idempotency_key, idempotency_fingerprint FROM stock_adjustments
          WHERE reason = ${sqlText(reason)}
        `);
        expect(storedKeys).toHaveLength(1);
        expect(storedKeys[0].idempotency_key).toBeTruthy();
        expect(storedKeys[0].idempotency_fingerprint).toBeTruthy();
        const noticeRows = await readOnlyDatabaseJson<Array<{ count: number }>>(`
          SELECT COUNT(*)::int AS count FROM notifications notice
          WHERE notice.data ->> 'subject_type' = ${sqlText(stockAdjustmentSubjectType)}
            AND notice.data ->> 'subject_id' = (
              SELECT adjustment.id::text FROM stock_adjustments adjustment
              WHERE adjustment.reason = ${sqlText(reason)}
            )
        `);
        expect(noticeRows).toEqual([{ count: 0 }]);
        await assertAuditActors(journey, [warehouseIdentity.email]);
      },
    );
  } finally {
    for (const { session } of sessions.reverse()) await session.context.close();
  }
});

test('INV01-SCOPE — Inventory role cannot cross an assigned warehouse or location boundary', async () => {
  test.fixme(true, 'GAP-PERM-01: current user and E2E schemas define department roles but no user-to-warehouse/location assignment; the E2E reference baseline has one warehouse, so a scoped boundary cannot be exercised through the UI.');
});
