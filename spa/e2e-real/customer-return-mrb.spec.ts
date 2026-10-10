import { readFileSync } from 'node:fs';
import { expect, test, type Browser, type Page } from '@playwright/test';
import { baseUrl, loginAs, statefulCsrfHeaders } from './helpers';

const BASE_URL = baseUrl();
const fixturePath = process.env.E2E_RMA_FIXTURE_PATH;
if (!fixturePath) throw new Error('Set E2E_RMA_FIXTURE_PATH to an isolated customer-return fixture manifest.');

type Fixture = {
  tag: string;
  customer: string;
  product: string;
  part_number: string;
  item: string;
  invoice: string;
  invoice_number: string;
  invoice_line: string;
  unit_price: string;
  quantity: string;
  quarantine_location: string;
  quarantine_warehouse: string;
  target_location: string;
};

type Actor = {
  email: string;
  page: Page;
  context: Awaited<ReturnType<Browser['newContext']>>;
  monitor: BrowserMonitor;
};

type BrowserMonitor = {
  consoleErrors: string[];
  pageErrors: string[];
  failedResponses: Array<{ method: string; url: string; status: number }>;
  failedRequests: Array<{ method: string; url: string; error: string }>;
  successfulGets: string[];
  abortedGets: string[];
};

type RmaItem = {
  id: string;
  item_id: string | null;
  product_id: string | null;
  quantity: string;
  returned_quantity: string;
  remaining_quantity: string;
  unit_price: string;
  original_unit_price: string | null;
  total: string;
  source_invoice_item_id: string | null;
  source_allocation: { source_kind: string; quantity: string; unit_price: string } | null;
  receipt_recorded: boolean;
  quarantine_status: string | null;
  quarantine_location_id: string | null;
  disposition: string | null;
  moved_quantity: string | null;
  stock_movement_id: string | null;
  mrb: { id: string; status: string; release_movement_id: string | null } | null;
};

type RmaState = {
  id: string;
  rma_number: string;
  type: string;
  status: string;
  pending_approval_step: number | null;
  disposition_status: string | null;
  creator: { id: string; name: string } | null;
  approved_by: { id: string; name: string } | null;
  completed_by: { id: string; name: string } | null;
  invoice: { id: string; invoice_number: string } | null;
  credit_note: { id: string; status: string; total_amount: string } | null;
  inspection: { id: string; status: string } | null;
  inspections: Array<{ id: string; inspection_number: string; status: string }>;
  inspection_handoff: { status: string | null };
  receipts: Array<{
    id: string;
    request_key: string | null;
    final_receipt: boolean;
    items: Array<{ return_request_item_id: string; quantity: string; stock_movement_id: string | null }>;
  }>;
  approval_records: Array<{
    step_order: number;
    role_slug: string;
    action: string;
    acted_at: string | null;
    approver: { id: string; name: string } | null;
  }>;
  items: RmaItem[];
};

type QualityInspectionState = {
  id: string;
  status: string;
  result_version: number;
  proposed_result: string | null;
  can_review: boolean;
  inspector: { id: string } | null;
  reviewer: { id: string } | null;
  measurements: Array<{ id: string; item_result: string | null }>;
};

type StockLevel = {
  location: { id: string; code: string };
  quantity: string;
  weighted_avg_cost: string;
  total_value: string;
};

type StockMovement = {
  id: string;
  movement_type: string;
  quantity: string;
  unit_cost: string;
  total_cost: string;
  reference_type: string | null;
  reference_id: string | null;
  from_location: { id: string; code: string } | null;
  to_location: { id: string; code: string } | null;
  gl_handoff: { status: string; journal_entry: { id: string; entry_number: string } | null };
};

type StockSnapshot = { levels: StockLevel[]; movements: StockMovement[] };

type AuditRow = {
  model_type: string;
  model_id: string | null;
  action: string;
  old_values: Record<string, unknown>;
  new_values: Record<string, unknown>;
  user: { id: string; role: { slug: string } | null } | null;
};

type ApprovalCard = {
  id: string;
  type: string;
  number: string;
  link: string;
  step_order: number;
  role_slug: string;
};

function monitorPage(page: Page): BrowserMonitor {
  const monitor: BrowserMonitor = {
    consoleErrors: [],
    pageErrors: [],
    failedResponses: [],
    failedRequests: [],
    successfulGets: [],
    abortedGets: [],
  };
  page.on('console', (message) => {
    if (message.type() !== 'error') return;
    const text = message.text();
    if (text.includes('401') && text.includes('Unauthorized')) return;
    monitor.consoleErrors.push(text.slice(0, 500));
  });
  page.on('pageerror', (error) => monitor.pageErrors.push(error.message.slice(0, 500)));
  page.on('response', (response) => {
    const url = response.url();
    const method = response.request().method();
    if (method === 'GET' && url.includes('/api/v1/') && response.status() < 400) {
      monitor.successfulGets.push(url);
    }
    if (url.includes('/api/v1/') && response.status() >= 400) {
      monitor.failedResponses.push({ method, url, status: response.status() });
    }
  });
  page.on('requestfailed', (request) => {
    const url = request.url();
    const method = request.method();
    const error = request.failure()?.errorText ?? 'Request failed';
    if (method === 'GET' && url.includes('/api/v1/') && error === 'net::ERR_ABORTED') {
      monitor.abortedGets.push(url);
      return;
    }
    monitor.failedRequests.push({ method, url, error });
  });
  return monitor;
}

async function openActor(browser: Browser, email: string): Promise<Actor> {
  const context = await browser.newContext({ baseURL: BASE_URL });
  const page = await context.newPage();
  await loginAs(page, email, 'password');
  return { email, page, context, monitor: monitorPage(page) };
}

async function apiData<T>(page: Page, path: string): Promise<T> {
  const response = await page.request.get(`${BASE_URL}/api/v1${path}`, {
    headers: await statefulCsrfHeaders(page),
  });
  expect(response.status(), `${path}: ${await response.text()}`).toBe(200);
  return (await response.json() as { data: T }).data;
}

async function readRma(page: Page, id: string): Promise<RmaState> {
  return apiData<RmaState>(page, `/return-management/return-requests/${id}`);
}

async function readStock(page: Page, itemId: string): Promise<StockSnapshot> {
  const item = encodeURIComponent(itemId);
  const [levels, movements] = await Promise.all([
    apiData<StockLevel[]>(page, `/inventory/stock-levels?item_id=${item}&per_page=100`),
    apiData<StockMovement[]>(page, `/inventory/stock-movements?item_id=${item}&per_page=100`),
  ]);
  return { levels, movements };
}

async function readMrb(page: Page, id: string): Promise<Record<string, unknown>> {
  return apiData<Record<string, unknown>>(page, `/inventory/mrb/${id}`);
}

async function auditTrail(page: Page, modelType: string, id: string): Promise<AuditRow[]> {
  const query = new URLSearchParams({ model_type: modelType, model_id: id, per_page: '100' });
  return apiData<AuditRow[]>(page, `/admin/audit-logs/entity?${query.toString()}`);
}

async function post(page: Page, path: string, data: unknown) {
  return page.request.post(`${BASE_URL}/api/v1${path}`, {
    headers: await statefulCsrfHeaders(page),
    data,
  });
}

async function expectAuditActor(
  adminPage: Page,
  modelType: string,
  id: string,
  actorId: string,
): Promise<void> {
  const logs = await auditTrail(adminPage, modelType, id);
  expect(logs.some((row) => row.user?.id === actorId), `${modelType} ${id} lacks an audit entry for ${actorId}`).toBe(true);
}

function expectApprovalActor(state: RmaState, stepOrder: number, actorId: string): void {
  const approval = state.approval_records.find((row) => row.step_order === stepOrder);
  expect(approval).toMatchObject({
    action: 'approved',
    approver: { id: actorId },
  });
  expect(approval?.acted_at).toBeTruthy();
}

function quantityAt(snapshot: StockSnapshot, locationId: string): string {
  return snapshot.levels.find((level) => level.location.id === locationId)?.quantity ?? '0.000';
}

function assertStock(snapshot: StockSnapshot, input: {
  quarantine: string;
  quarantineQuantity: string;
  target: string;
  targetQuantity: string;
  movements: number;
}): void {
  expect(quantityAt(snapshot, input.quarantine)).toBe(input.quarantineQuantity);
  expect(quantityAt(snapshot, input.target)).toBe(input.targetQuantity);
  expect(snapshot.movements).toHaveLength(input.movements);
}

function auditUnexpectedBrowserFailures(monitors: BrowserMonitor[], rmaId: string): string[] {
  const issues: string[] = [];
  const expectedDisposePath = `/api/v1/return-management/return-requests/${rmaId}/dispose`;
  for (const monitor of monitors) {
    const expectedDisposeGate = monitor.failedResponses.some((response) =>
      response.method === 'POST'
      && response.url.includes(expectedDisposePath)
      && response.status === 422,
    );
    issues.push(...monitor.consoleErrors
      .filter((error) => !(expectedDisposeGate
        && error === 'Failed to load resource: the server responded with a status of 422 (Unprocessable Content)'))
      .map((error) => `console: ${error}`));
    issues.push(...monitor.pageErrors.map((error) => `page: ${error}`));
    for (const response of monitor.failedResponses) {
      const expectedGate = response.method === 'POST'
        && response.url.includes(expectedDisposePath)
        && response.status === 422;
      if (!expectedGate) issues.push(`response ${response.status}: ${response.method} ${response.url}`);
    }
    issues.push(...monitor.failedRequests.map((request) =>
      `request failed: ${request.method} ${request.url} ${request.error}`,
    ));
    const unmatched = [...monitor.abortedGets];
    for (const success of monitor.successfulGets) {
      const index = unmatched.indexOf(success);
      if (index >= 0) unmatched.splice(index, 1);
    }
    issues.push(...unmatched.map((url) => `aborted GET without successful replacement: ${url}`));
  }
  return issues;
}

test('invoice-backed physical customer returns wait for independent MRB release', async ({ browser }) => {
  test.setTimeout(600_000);
  const fixture = JSON.parse(readFileSync(fixturePath, 'utf8')) as Fixture;
  const actors: Actor[] = [];
  const open = async (email: string): Promise<Actor> => {
    const actor = await openActor(browser, email);
    actors.push(actor);
    return actor;
  };

  try {
    const cs = await open('customerservice@ogami.test');
    const createdBy = await apiData<{ id: string }>(cs.page, '/auth/user');
    await cs.page.goto('/return-management/new');
    await cs.page.getByLabel('Type').selectOption('customer_return');

    const customerName = `Customer Return E2E ${fixture.tag}`;
    const customer = cs.page.getByRole('combobox', { name: 'Customer' });
    await customer.fill(customerName);
    await cs.page.getByRole('option', { name: customerName, exact: true }).click();
    await cs.page.getByLabel('Reason Code').selectOption('quality_issue');
    await cs.page.getByLabel('Description').fill(`Independent MRB release audit ${fixture.tag}.`);
    await cs.page.getByRole('button', { name: 'Add Item', exact: true }).click();

    const source = cs.page.getByRole('combobox', { name: 'Source line' });
    await source.fill(fixture.invoice_number);
    await cs.page.getByRole('option').filter({ hasText: fixture.invoice_number }).click();
    await expect(cs.page.locator('input[name="items.0.item_id"]')).toHaveValue(fixture.item);
    await cs.page.getByLabel('Qty').fill(fixture.quantity);

    const createResponsePromise = cs.page.waitForResponse((response) =>
      response.request().method() === 'POST'
      && response.url().endsWith('/api/v1/return-management/return-requests'),
    );
    await cs.page.getByRole('button', { name: 'Create Return Request', exact: true }).click();
    const createResponse = await createResponsePromise;
    expect(createResponse.status(), await createResponse.text()).toBe(201);
    await cs.page.waitForURL(/\/return-management\/(?!new$)[^/]+$/, { timeout: 20_000 });
    const rmaId = cs.page.url().split('/').pop()!;
    const rmaUrl = `/return-management/${rmaId}`;
    const created = await readRma(cs.page, rmaId);
    expect(created).toMatchObject({
      id: rmaId,
      type: 'customer_return',
      status: 'draft',
      invoice: { id: fixture.invoice, invoice_number: fixture.invoice_number },
      disposition_status: null,
      inspections: [],
      receipts: [],
    });
    expect(created.items).toHaveLength(1);
    expect(created.items[0]).toMatchObject({
      product_id: fixture.product,
      item_id: fixture.item,
      quantity: fixture.quantity,
      unit_price: fixture.unit_price,
      original_unit_price: fixture.unit_price,
      total: '100.00',
      source_invoice_item_id: fixture.invoice_line,
      source_allocation: {
        source_kind: 'invoice_item',
        quantity: fixture.quantity,
        unit_price: fixture.unit_price,
      },
      quarantine_status: null,
      mrb: null,
    });
    const auditAdmin = await open('admin@ogami.test');
    expect((await readStock(auditAdmin.page, fixture.item)).movements).toHaveLength(0);
    await expectAuditActor(auditAdmin.page, 'ReturnRequest', rmaId, createdBy.id);

    const sourceOptions = await apiData<{
      customer: { invoices: Array<{ id: string; lines: Array<{ id: string; remaining_quantity: string }> }> };
    }>(
      cs.page,
      `/return-management/return-requests/source-options?type=customer_return&customer_id=${fixture.customer}`,
    );
    const sourceLine = sourceOptions.customer.invoices
      .find((invoice) => invoice.id === fixture.invoice)?.lines
      .find((line) => line.id === fixture.invoice_line);
    expect(sourceLine?.remaining_quantity).toBe('0.000');

    const submitResponsePromise = cs.page.waitForResponse((response) =>
      response.request().method() === 'POST'
      && response.url().endsWith(`/api/v1/return-management/return-requests/${rmaId}/submit`),
    );
    await cs.page.getByRole('button', { name: 'Submit for Approval', exact: true }).click();
    await cs.page.getByRole('dialog').getByRole('button', { name: 'Submit', exact: true }).click();
    const submitResponse = await submitResponsePromise;
    expect(submitResponse.status(), await submitResponse.text()).toBe(200);
    let state = await readRma(cs.page, rmaId);
    expect(state).toMatchObject({ status: 'pending_approval', pending_approval_step: 1 });
    expect(state.approval_records.map((row) => [row.step_order, row.role_slug, row.action])).toEqual([
      [1, 'department_head', 'pending'],
      [2, 'production_manager', 'pending'],
    ]);
    expect(state.receipts).toHaveLength(0);
    assertStock(await readStock(auditAdmin.page, fixture.item), {
      quarantine: fixture.quarantine_location, quarantineQuantity: '0.000',
      target: fixture.target_location, targetQuantity: '0.000', movements: 0,
    });
    await expectAuditActor(auditAdmin.page, 'ReturnRequest', rmaId, createdBy.id);

    const noSelfApproval = await post(cs.page, `/return-management/return-requests/${rmaId}/approve`, {});
    expect(noSelfApproval.status()).toBe(403);
    expect(await readRma(cs.page, rmaId)).toEqual(state);

    const production = await open('production@ogami.test');
    await production.page.goto(rmaUrl);
    await expect(production.page.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
    const outOfOrder = await post(production.page, `/return-management/return-requests/${rmaId}/approve`, {});
    expect(outOfOrder.status()).toBe(403);
    expect(await readRma(production.page, rmaId)).toEqual(state);
    const productionBefore = await apiData<{ my_action: ApprovalCard[] }>(production.page, '/approvals/board');
    expect(productionBefore.my_action.some((card) => card.id === rmaId)).toBe(false);

    const departmentHead = await open('depthead@ogami.test');
    await departmentHead.page.goto('/approvals');
    const departmentBoard = await apiData<{ my_action: ApprovalCard[] }>(departmentHead.page, '/approvals/board');
    expect(departmentBoard.my_action).toContainEqual(expect.objectContaining({
      id: rmaId,
      type: 'return_request',
      number: state.rma_number,
      link: rmaUrl,
      step_order: 1,
      role_slug: 'department_head',
    }));
    await departmentHead.page.goto(rmaUrl);
    await expect(departmentHead.page.getByRole('button', { name: 'Approve', exact: true })).toBeVisible();
    const deptApprovePromise = departmentHead.page.waitForResponse((response) =>
      response.request().method() === 'POST'
      && response.url().endsWith(`/api/v1/return-management/return-requests/${rmaId}/approve`),
    );
    await departmentHead.page.getByRole('button', { name: 'Approve', exact: true }).click();
    await departmentHead.page.getByRole('dialog').getByRole('button', { name: 'Approve', exact: true }).click();
    const deptApprove = await deptApprovePromise;
    expect(deptApprove.status(), await deptApprove.text()).toBe(200);
    state = await readRma(departmentHead.page, rmaId);
    expect(state).toMatchObject({ status: 'pending_approval', pending_approval_step: 2 });
    expect(state.approval_records.map((row) => [row.step_order, row.role_slug, row.action])).toEqual([
      [1, 'department_head', 'approved'],
      [2, 'production_manager', 'pending'],
    ]);
    expect(state.approval_records[0].approver?.id).toBeTruthy();
    expect(state.receipts).toHaveLength(0);
    assertStock(await readStock(auditAdmin.page, fixture.item), {
      quarantine: fixture.quarantine_location, quarantineQuantity: '0.000',
      target: fixture.target_location, targetQuantity: '0.000', movements: 0,
    });
    expectApprovalActor(state, 1, (await apiData<{ id: string }>(departmentHead.page, '/auth/user')).id);
    const productionQueue = await apiData<{ my_action: ApprovalCard[] }>(production.page, '/approvals/board');
    expect(productionQueue.my_action).toContainEqual(expect.objectContaining({
      id: rmaId, step_order: 2, role_slug: 'production_manager',
    }));
    const departmentQueueAfter = await apiData<{ my_action: ApprovalCard[] }>(departmentHead.page, '/approvals/board');
    expect(departmentQueueAfter.my_action.some((card) => card.id === rmaId)).toBe(false);

    await production.page.goto(rmaUrl);
    await expect(production.page.getByRole('button', { name: 'Approve', exact: true })).toBeVisible();
    const productionApprovePromise = production.page.waitForResponse((response) =>
      response.request().method() === 'POST'
      && response.url().endsWith(`/api/v1/return-management/return-requests/${rmaId}/approve`),
    );
    await production.page.getByRole('button', { name: 'Approve', exact: true }).click();
    await production.page.getByRole('dialog').getByRole('button', { name: 'Approve', exact: true }).click();
    const productionApprove = await productionApprovePromise;
    expect(productionApprove.status(), await productionApprove.text()).toBe(200);
    state = await readRma(production.page, rmaId);
    expect(state).toMatchObject({ status: 'approved', pending_approval_step: null });
    expect(state.approval_records.map((row) => [row.role_slug, row.action])).toEqual([
      ['department_head', 'approved'],
      ['production_manager', 'approved'],
    ]);
    expect(new Set(state.approval_records.map((row) => row.approver?.id)).size).toBe(2);
    expect(state.receipts).toHaveLength(0);
    assertStock(await readStock(auditAdmin.page, fixture.item), {
      quarantine: fixture.quarantine_location, quarantineQuantity: '0.000',
      target: fixture.target_location, targetQuantity: '0.000', movements: 0,
    });
    expectApprovalActor(state, 2, (await apiData<{ id: string }>(production.page, '/auth/user')).id);
    const duplicateApproval = await post(production.page, `/return-management/return-requests/${rmaId}/approve`, {});
    expect(duplicateApproval.status()).toBe(422);
    expect(await readRma(production.page, rmaId)).toEqual(state);

    const warehouse = await open('warehouse@ogami.test');
    await cs.page.goto(rmaUrl);
    await expect(cs.page.getByRole('button', { name: 'Record Receipt', exact: true })).toHaveCount(0);
    await warehouse.page.goto(rmaUrl);
    await expect(warehouse.page.getByRole('button', { name: 'Record Receipt', exact: true })).toBeVisible();
    await warehouse.page.getByRole('button', { name: 'Record Receipt', exact: true }).click();
    const receiptDialog = warehouse.page.getByRole('dialog');
    await receiptDialog.getByRole('spinbutton').first().fill(fixture.quantity);
    await expect(receiptDialog.getByRole('checkbox')).toBeChecked();
    const receiptResponsePromise = warehouse.page.waitForResponse((response) =>
      response.request().method() === 'POST'
      && response.url().endsWith(`/api/v1/return-management/return-requests/${rmaId}/receive`),
    );
    await receiptDialog.getByRole('button', { name: 'Record Final Receipt', exact: true }).click();
    const receiptResponse = await receiptResponsePromise;
    expect(receiptResponse.status(), await receiptResponse.text()).toBe(200);
    const receiptPayload = receiptResponse.request().postDataJSON() as Record<string, unknown>;
    state = await readRma(warehouse.page, rmaId);
    expect(state.status).toBe('received');
    expect(state.receipts).toHaveLength(1);
    expect(state.receipts[0]).toMatchObject({ final_receipt: true });
    expect(state.receipts[0].items).toEqual([expect.objectContaining({ quantity: fixture.quantity })]);
    expect(state.items[0]).toMatchObject({
      returned_quantity: fixture.quantity,
      receipt_recorded: true,
      quarantine_status: 'held',
      quarantine_location_id: fixture.quarantine_location,
    });
    let stock = await readStock(auditAdmin.page, fixture.item);
    assertStock(stock, {
      quarantine: fixture.quarantine_location, quarantineQuantity: fixture.quantity,
      target: fixture.target_location, targetQuantity: '0.000', movements: 1,
    });
    expect(stock.movements[0]).toMatchObject({
      quantity: fixture.quantity,
      movement_type: 'adjustment_in',
      reference_type: 'return_request',
      reference_id: rmaId,
      to_location: { id: fixture.quarantine_location },
      unit_cost: '0.0000',
      total_cost: '0.00',
      gl_handoff: { status: 'not_required' },
    });
    await expectAuditActor(auditAdmin.page, 'ReturnRequest', rmaId, (await apiData<{ id: string }>(warehouse.page, '/auth/user')).id);

    const receiptReplay = await post(warehouse.page, `/return-management/return-requests/${rmaId}/receive`, receiptPayload);
    expect(receiptReplay.status()).toBe(200);
    const replayedReceipt = (await receiptReplay.json() as { data: RmaState }).data;
    expect(replayedReceipt.receipts).toHaveLength(1);
    expect(replayedReceipt.receipts[0].id).toBe(state.receipts[0].id);
    expect(await readRma(warehouse.page, rmaId)).toEqual(state);
    stock = await readStock(auditAdmin.page, fixture.item);
    assertStock(stock, {
      quarantine: fixture.quarantine_location, quarantineQuantity: fixture.quantity,
      target: fixture.target_location, targetQuantity: '0.000', movements: 1,
    });

    const qc = await open('qc@ogami.test');
    await qc.page.goto(rmaUrl);
    await expect(qc.page.getByRole('button', { name: 'Stage Quality Handoff', exact: true })).toBeVisible();
    const inspectResponsePromise = qc.page.waitForResponse((response) =>
      response.request().method() === 'POST'
      && response.url().endsWith(`/api/v1/return-management/return-requests/${rmaId}/inspect`),
    );
    await qc.page.getByRole('button', { name: 'Stage Quality Handoff', exact: true }).click();
    await qc.page.getByRole('dialog').getByRole('button', { name: 'Stage Handoff', exact: true }).click();
    const inspectResponse = await inspectResponsePromise;
    expect(inspectResponse.status(), await inspectResponse.text()).toBe(200);
    state = await readRma(qc.page, rmaId);
    expect(state).toMatchObject({
      status: 'inspected',
      inspection_handoff: { status: 'generated' },
    });
    expect(state.inspections).toHaveLength(1);
    expect(state.inspections[0].status).toBe('draft');
    const inspectionId = state.inspections[0].id;
    const inspectionNumber = state.inspections[0].inspection_number;
    expect(state.disposition_status).toBeNull();
    expect(state.items[0].mrb).toBeNull();
    assertStock(await readStock(auditAdmin.page, fixture.item), {
      quarantine: fixture.quarantine_location, quarantineQuantity: fixture.quantity,
      target: fixture.target_location, targetQuantity: '0.000', movements: 1,
    });
    const inspectionRetry = await post(qc.page, `/return-management/return-requests/${rmaId}/retry-inspection`, {});
    expect(inspectionRetry.status()).toBe(200);
    const retriedRma = (await inspectionRetry.json() as { data: RmaState }).data;
    expect(retriedRma.inspections).toHaveLength(1);
    expect(retriedRma.inspections[0].id).toBe(inspectionId);
    expect(await readRma(qc.page, rmaId)).toMatchObject({ status: 'inspected', inspections: [{ id: inspectionId }] });

    await cs.page.goto(rmaUrl);
    await expect(cs.page.getByRole('button', { name: 'Dispose Items', exact: true })).toHaveCount(0);
    const qualityBlocker = cs.page.getByRole('status').filter({ hasText: 'Disposition waiting for Quality review' });
    await expect(qualityBlocker).toContainText(
      'Disposition is blocked until Quality completes and reviews every physical return inspection.',
    );
    const beforeEarlyDispose = await readRma(cs.page, rmaId);
    const earlyDispose = await post(cs.page, `/return-management/return-requests/${rmaId}/dispose`, {
      dispositions: [{ item_id: beforeEarlyDispose.items[0].id, disposition: 'restock' }],
    });
    expect(earlyDispose.status()).toBe(422);
    const earlyDisposeBody = await earlyDispose.json() as { message: string };
    expect(earlyDisposeBody.message).toBe(
      'Every stock-item return requires independently checked QC before disposition; restock requires PASS.',
    );
    state = await readRma(cs.page, rmaId);
    expect(state).toEqual(beforeEarlyDispose);
    expect(state.disposition_status).toBeNull();
    expect(state.items[0].mrb).toBeNull();
    assertStock(await readStock(auditAdmin.page, fixture.item), {
      quarantine: fixture.quarantine_location, quarantineQuantity: fixture.quantity,
      target: fixture.target_location, targetQuantity: '0.000', movements: 1,
    });

    await qc.page.goto('/quality/inspections');
    await qc.page.getByPlaceholder('Search inspection, product').fill(inspectionNumber);
    await expect(qc.page.getByText(inspectionNumber, { exact: true })).toBeVisible();
    await qc.page.goto(`/quality/inspections/${inspectionId}`);
    const checklist = qc.page.getByRole('region', { name: 'Inspection checklist' });
    const passButton = checklist.getByRole('listitem').getByRole('button', { name: /^PASS / }).first();
    await expect(passButton).toBeVisible();
    await passButton.click();
    const submitInspection = qc.page.getByRole('button', { name: 'Submit inspection', exact: true });
    await expect(submitInspection).toBeDisabled();
    const beforeQualitySubmit = await apiData<QualityInspectionState>(
      qc.page,
      `/quality/inspections/${inspectionId}`,
    );
    expect(beforeQualitySubmit).toMatchObject({ id: inspectionId, status: 'draft' });
    expect(beforeQualitySubmit.measurements).toHaveLength(1);
    const inspectionAuditBeforeCoverage = await auditTrail(auditAdmin.page, 'Inspection', inspectionId);
    const missingCoverage = await qc.page.request.post(
      `${BASE_URL}/api/v1/quality/inspections/${inspectionId}/batch-result`,
      {
        headers: await statefulCsrfHeaders(qc.page),
        data: {
          expected_result_version: beforeQualitySubmit.result_version,
          coverage_confirmed: false,
          items: beforeQualitySubmit.measurements.map(({ id }) => ({ id, result: 'passed' })),
        },
      },
    );
    expect(missingCoverage.status()).toBe(422);
    expect((await missingCoverage.json() as { errors?: Record<string, string[]> }).errors)
      .toHaveProperty('coverage_confirmed');
    expect(await apiData<QualityInspectionState>(qc.page, `/quality/inspections/${inspectionId}`))
      .toEqual(beforeQualitySubmit);
    expect(await readRma(qc.page, rmaId)).toEqual(state);
    expect(await readStock(auditAdmin.page, fixture.item)).toEqual(stock);
    expect(await auditTrail(auditAdmin.page, 'Inspection', inspectionId)).toEqual(inspectionAuditBeforeCoverage);
    const fullCoverage = checklist.getByRole('checkbox', {
      name: 'I confirm every piece was inspected against all required checks',
    });
    await expect(fullCoverage).toBeVisible();
    await fullCoverage.check();
    await expect(submitInspection).toBeEnabled();
    const qualitySubmitPromise = qc.page.waitForResponse((response) =>
      response.request().method() === 'POST'
      && response.url().endsWith(`/api/v1/quality/inspections/${inspectionId}/batch-result`),
    );
    await submitInspection.click();
    const qualitySubmit = await qualitySubmitPromise;
    expect(qualitySubmit.status(), await qualitySubmit.text()).toBe(200);
    const inspectorId = (await apiData<{ id: string }>(qc.page, '/auth/user')).id;
    let inspectionState = await apiData<QualityInspectionState>(
      qc.page,
      `/quality/inspections/${inspectionId}`,
    );
    expect(inspectionState).toMatchObject({
      status: 'awaiting_review',
      proposed_result: 'passed',
      can_review: false,
      inspector: { id: inspectorId },
      reviewer: null,
    });
    state = await readRma(qc.page, rmaId);
    expect(state).toMatchObject({
      status: 'inspected',
      disposition_status: null,
      inspections: [{ id: inspectionId, status: 'awaiting_review' }],
    });
    expect(state.items[0].mrb).toBeNull();
    expect(state.disposition_status).toBeNull();
    assertStock(await readStock(auditAdmin.page, fixture.item), {
      quarantine: fixture.quarantine_location, quarantineQuantity: fixture.quantity,
      target: fixture.target_location, targetQuantity: '0.000', movements: 1,
    });
    await qc.page.reload();
    await expect(qc.page.getByRole('button', { name: 'Approve pass', exact: true })).toHaveCount(0);
    const selfReview = await qc.page.request.patch(
      `${BASE_URL}/api/v1/quality/inspections/${inspectionId}/review`,
      {
        headers: await statefulCsrfHeaders(qc.page),
        data: { decision: 'passed', expected_result_version: inspectionState.result_version },
      },
    );
    expect(selfReview.status()).toBe(403);
    expect((await selfReview.json() as { message?: string }).message)
      .toContain('You cannot review an inspection you performed.');
    expect(await apiData<QualityInspectionState>(qc.page, `/quality/inspections/${inspectionId}`))
      .toEqual(inspectionState);
    expect(await readRma(qc.page, rmaId)).toEqual(state);
    expect(await readStock(auditAdmin.page, fixture.item)).toEqual(stock);

    const pendingReviewDispose = await post(cs.page, `/return-management/return-requests/${rmaId}/dispose`, {
      dispositions: [{ item_id: state.items[0].id, disposition: 'restock' }],
    });
    expect(pendingReviewDispose.status()).toBe(422);
    expect((await pendingReviewDispose.json() as { message?: string }).message)
      .toContain('independently checked QC');
    expect(await readRma(cs.page, rmaId)).toEqual(state);
    expect(await readStock(auditAdmin.page, fixture.item)).toEqual(stock);

    const reviewerId = (await apiData<{ id: string }>(production.page, '/auth/user')).id;
    await production.page.goto(`/quality/inspections/${inspectionId}`);
    const approvePass = production.page.getByRole('button', { name: 'Approve pass', exact: true });
    await expect(approvePass).toBeVisible();
    await approvePass.click();
    const reviewDialog = production.page.getByRole('dialog');
    await expect(reviewDialog).toContainText('Approve inspection pass?');
    const reviewPromise = production.page.waitForResponse((response) =>
      response.request().method() === 'PATCH'
      && response.url().endsWith(`/api/v1/quality/inspections/${inspectionId}/review`),
    );
    await reviewDialog.getByRole('button', { name: 'Approve pass', exact: true }).click();
    const reviewResponse = await reviewPromise;
    expect(reviewResponse.status(), await reviewResponse.text()).toBe(200);
    inspectionState = await apiData<QualityInspectionState>(
      production.page,
      `/quality/inspections/${inspectionId}`,
    );
    expect(inspectionState).toMatchObject({
      status: 'passed',
      proposed_result: 'passed',
      can_review: false,
      inspector: { id: inspectorId },
      reviewer: { id: reviewerId },
    });
    const auditBeforeDuplicateReview = await auditTrail(auditAdmin.page, 'Inspection', inspectionId);
    const duplicateReview = await production.page.request.patch(
      `${BASE_URL}/api/v1/quality/inspections/${inspectionId}/review`,
      {
        headers: await statefulCsrfHeaders(production.page),
        data: { decision: 'passed', expected_result_version: inspectionState.result_version },
      },
    );
    expect(duplicateReview.status()).toBe(422);
    expect(await apiData<QualityInspectionState>(production.page, `/quality/inspections/${inspectionId}`))
      .toEqual(inspectionState);
    expect(await auditTrail(auditAdmin.page, 'Inspection', inspectionId)).toEqual(auditBeforeDuplicateReview);
    state = await readRma(production.page, rmaId);
    expect(state).toMatchObject({
      status: 'inspected',
      inspections: [{ id: inspectionId, status: 'passed' }],
    });
    expect(state.items[0].mrb).toBeNull();
    assertStock(await readStock(auditAdmin.page, fixture.item), {
      quarantine: fixture.quarantine_location, quarantineQuantity: fixture.quantity,
      target: fixture.target_location, targetQuantity: '0.000', movements: 1,
    });
    await expectAuditActor(auditAdmin.page, 'Inspection', inspectionId, inspectorId);
    await expectAuditActor(auditAdmin.page, 'Inspection', inspectionId, reviewerId);

    await cs.page.goto(rmaUrl);
    await expect(cs.page.getByRole('button', { name: 'Dispose Items', exact: true })).toBeVisible();
    await cs.page.getByRole('button', { name: 'Dispose Items', exact: true }).click();
    const disposeDialog = cs.page.getByRole('dialog');
    await disposeDialog.getByRole('combobox', { name: 'Disposition' }).selectOption('restock');
    const disposePromise = cs.page.waitForResponse((response) =>
      response.request().method() === 'POST'
      && response.url().endsWith(`/api/v1/return-management/return-requests/${rmaId}/dispose`),
    );
    await disposeDialog.getByRole('button', { name: 'Record Disposition', exact: true }).click();
    const disposeResponse = await disposePromise;
    expect(disposeResponse.status(), await disposeResponse.text()).toBe(200);
    const dispositionPayload = disposeResponse.request().postDataJSON() as Record<string, unknown>;
    state = await readRma(cs.page, rmaId);
    expect(state).toMatchObject({ status: 'inspected', disposition_status: 'disposed' });
    expect(state.credit_note?.status).toBe('draft');
    expect(Number(state.credit_note?.total_amount)).toBeGreaterThan(0);
    expect(state.items[0]).toMatchObject({ disposition: 'restock', moved_quantity: null });
    expect(state.items[0].mrb).toMatchObject({ status: 'held', release_movement_id: null });
    await cs.page.reload();
    const mrbBlocker = cs.page.getByRole('status').filter({ hasText: 'Independent MRB release required' });
    await expect(mrbBlocker).toContainText(
      'An Inventory reviewer who did not inspect this return must record reinspection evidence for the full held quantity before this RMA can complete.',
    );
    const mrbId = state.items[0].mrb!.id;
    stock = await readStock(auditAdmin.page, fixture.item);
    assertStock(stock, {
      quarantine: fixture.quarantine_location, quarantineQuantity: fixture.quantity,
      target: fixture.target_location, targetQuantity: '0.000', movements: 1,
    });
    expect((await apiData<Array<{ id: string }>>(warehouse.page, '/inventory/mrb?per_page=100'))
      .some((row) => row.id === mrbId)).toBe(true);
    await expectAuditActor(auditAdmin.page, 'ReturnRequest', rmaId, createdBy.id);
    const mrbBeforeRelease = await readMrb(qc.page, mrbId);
    expect(mrbBeforeRelease).toMatchObject({
      id: mrbId,
      status: 'held',
      quantity: fixture.quantity,
      held_by_id: createdBy.id,
      release_movement_id: null,
      return_request: { id: rmaId, line_id: state.items[0].id, disposition: 'restock' },
      inspection: { id: inspectionId, status: 'passed' },
      quarantine_location: { id: fixture.quarantine_location, warehouse_id: fixture.quarantine_warehouse, zone_type: 'quarantine' },
    });
    expect(mrbBeforeRelease.actions).toMatchObject({ can_release: true, can_release_good_stock: false });
    await expectAuditActor(auditAdmin.page, 'MaterialReviewRecord', mrbId, createdBy.id);

    const duplicateDispose = await post(cs.page, `/return-management/return-requests/${rmaId}/dispose`, dispositionPayload);
    expect(duplicateDispose.status()).toBe(422);
    expect(await readRma(cs.page, rmaId)).toEqual(state);
    expect(await readStock(auditAdmin.page, fixture.item)).toEqual(stock);
    await expect(cs.page.getByRole('button', { name: 'Complete RMA', exact: true })).toHaveCount(0);
    const earlyComplete = await post(cs.page, `/return-management/return-requests/${rmaId}/complete`, {});
    expect(earlyComplete.status()).toBe(422);
    expect(await readRma(cs.page, rmaId)).toEqual(state);
    expect(await readStock(auditAdmin.page, fixture.item)).toEqual(stock);

    await qc.page.goto(`/inventory/mrb/${mrbId}`);
    await expect(qc.page.getByRole('button', { name: 'Release', exact: true })).toBeVisible();
    await qc.page.getByRole('button', { name: 'Release', exact: true }).click();
    const qcReleaseDialog = qc.page.getByRole('dialog');
    await expect(qcReleaseDialog.getByRole('status'))
      .toContainText('Another independent reviewer must release good stock');
    await expect(qcReleaseDialog.locator('select[name="disposition"] option[value="use_as_is"]')).toHaveCount(0);
    const forbiddenQcRelease = await post(qc.page, `/inventory/mrb/${mrbId}/release`, {
      disposition: 'use_as_is',
      target_location_id: fixture.target_location,
      inspected_quantity: fixture.quantity,
      evidence_reference: `RMA ${state.rma_number}; reinspection E2E-${fixture.tag}`,
      recovery_passed: true,
    });
    expect(forbiddenQcRelease.status()).toBe(422);
    expect((await forbiddenQcRelease.json() as { message?: string }).message)
      .toContain('independent of every customer-return inspection maker');
    expect(await readMrb(qc.page, mrbId)).toEqual(mrbBeforeRelease);
    expect(await readRma(qc.page, rmaId)).toEqual(state);
    expect(await readStock(auditAdmin.page, fixture.item)).toEqual(stock);

    const warehouseQueue = await apiData<Array<{ id: string }>>(warehouse.page, '/inventory/mrb?per_page=100');
    expect(warehouseQueue.some((record) => record.id === mrbId)).toBe(true);
    await warehouse.page.goto(`/inventory/mrb/${mrbId}`);
    await expect(warehouse.page.getByRole('button', { name: 'Release', exact: true })).toBeVisible();
    const warehouseMrbBeforeRelease = await readMrb(warehouse.page, mrbId);
    expect(warehouseMrbBeforeRelease.actions).toMatchObject({ can_release: true, can_release_good_stock: true });
    const mrbAuditBeforeRelease = await auditTrail(auditAdmin.page, 'MaterialReviewRecord', mrbId);
    for (const inspectedQuantity of ['0.999', '1.001']) {
      const invalidQuantityRelease = await post(warehouse.page, `/inventory/mrb/${mrbId}/release`, {
        disposition: 'use_as_is',
        target_location_id: fixture.target_location,
        inspected_quantity: inspectedQuantity,
        evidence_reference: `RMA ${state.rma_number}; reinspection E2E-${fixture.tag}`,
        recovery_passed: true,
      });
      expect(invalidQuantityRelease.status()).toBe(422);
      expect((await invalidQuantityRelease.json() as { message?: string }).message)
        .toContain('entire held quantity');
      expect(await readMrb(warehouse.page, mrbId)).toEqual(warehouseMrbBeforeRelease);
      expect(await readRma(warehouse.page, rmaId)).toEqual(state);
      expect(await readStock(auditAdmin.page, fixture.item)).toEqual(stock);
      expect(await auditTrail(auditAdmin.page, 'MaterialReviewRecord', mrbId)).toEqual(mrbAuditBeforeRelease);
    }
    await warehouse.page.getByRole('button', { name: 'Release', exact: true }).click();
    const releaseDialog = warehouse.page.getByRole('dialog');
    await releaseDialog.getByLabel('Disposition').selectOption('use_as_is');
    await releaseDialog.getByLabel('Target good location').selectOption(fixture.target_location);
    const evidence = `RMA ${state.rma_number}; customer return reinspection ${inspectionNumber}; E2E-${fixture.tag}`;
    await releaseDialog.getByLabel('Completed reinspection evidence reference').fill(evidence);
    await releaseDialog.getByLabel('Completed reinspection confirms all held material passes every requirement.').check();
    const releasePromise = warehouse.page.waitForResponse((response) =>
      response.request().method() === 'POST'
      && response.url().endsWith(`/api/v1/inventory/mrb/${mrbId}/release`),
    );
    await releaseDialog.getByRole('button', { name: 'Release', exact: true }).click();
    const releaseResponse = await releasePromise;
    expect(releaseResponse.status(), await releaseResponse.text()).toBe(200);
    const releasePayload = releaseResponse.request().postDataJSON() as Record<string, unknown>;
    const released = (await releaseResponse.json() as { data: Record<string, unknown> }).data;
    const warehouseActor = await apiData<{ id: string; name: string }>(warehouse.page, '/auth/user');
    expect(released).toMatchObject({
      id: mrbId,
      status: 'released',
      disposition: 'use_as_is',
      quantity: fixture.quantity,
      release_inspected_quantity: fixture.quantity,
      release_evidence_reference: evidence,
      released_by: warehouseActor.name,
    });
    expect(released.release_movement_id).toBeTruthy();
    await expectAuditActor(auditAdmin.page, 'MaterialReviewRecord', mrbId, warehouseActor.id);
    stock = await readStock(auditAdmin.page, fixture.item);
    assertStock(stock, {
      quarantine: fixture.quarantine_location, quarantineQuantity: '0.000',
      target: fixture.target_location, targetQuantity: fixture.quantity, movements: 2,
    });
    const transfer = stock.movements.find((movement) => movement.id === released.release_movement_id);
    expect(transfer).toMatchObject({
      movement_type: 'transfer',
      quantity: fixture.quantity,
      reference_type: 'material_review_record',
      reference_id: mrbId,
      from_location: { id: fixture.quarantine_location },
      to_location: { id: fixture.target_location },
      gl_handoff: { status: 'not_required' },
    });
    expect(stock.movements.filter((movement) => movement.to_location?.id === fixture.quarantine_location))
      .toHaveLength(1);
    state = await readRma(warehouse.page, rmaId);
    expect(state.status).toBe('inspected');
    expect(state.items[0]).toMatchObject({
      disposition: 'restock',
      quarantine_status: 'released',
      moved_quantity: fixture.quantity,
      stock_movement_id: released.release_movement_id,
      mrb: { id: mrbId, status: 'released', release_movement_id: released.release_movement_id },
    });

    await cs.page.goto(rmaUrl);
    await expect(cs.page.getByRole('status').filter({ hasText: 'Independent MRB release required' })).toHaveCount(0);
    await expect(cs.page.getByRole('button', { name: 'Cancel', exact: true })).toHaveCount(0);
    const returnAuditBeforeCancel = await auditTrail(auditAdmin.page, 'ReturnRequest', rmaId);
    const cancelAfterRelease = await post(cs.page, `/return-management/return-requests/${rmaId}/cancel`, {
      reason: 'Attempted cancellation after the MRB released physical stock.',
    });
    expect(cancelAfterRelease.status()).toBe(422);
    expect((await cancelAfterRelease.json() as { message?: string }).message)
      .toContain('Only draft, pending-approval, or approved returns can be cancelled.');
    expect(await readRma(cs.page, rmaId)).toEqual(state);
    expect(await readMrb(warehouse.page, mrbId)).toMatchObject(released);
    expect(await readStock(auditAdmin.page, fixture.item)).toEqual(stock);
    expect(await auditTrail(auditAdmin.page, 'ReturnRequest', rmaId)).toEqual(returnAuditBeforeCancel);

    const duplicateRelease = await post(warehouse.page, `/inventory/mrb/${mrbId}/release`, releasePayload);
    expect(duplicateRelease.status()).toBe(422);
    expect(await readMrb(warehouse.page, mrbId)).toMatchObject(released);
    expect(await readRma(warehouse.page, rmaId)).toEqual(state);
    expect(await readStock(auditAdmin.page, fixture.item)).toEqual(stock);

    await cs.page.goto(rmaUrl);
    await expect(cs.page.getByRole('button', { name: 'Complete RMA', exact: true })).toBeVisible();
    await cs.page.getByRole('button', { name: 'Complete RMA', exact: true }).click();
    const completeDialog = cs.page.getByRole('dialog');
    await expect(completeDialog).toContainText('Returned stock has completed its required MRB disposition.');
    const completePromise = cs.page.waitForResponse((response) =>
      response.request().method() === 'POST'
      && response.url().endsWith(`/api/v1/return-management/return-requests/${rmaId}/complete`),
    );
    await completeDialog.getByRole('button', { name: 'Confirm Complete', exact: true }).click();
    const completeResponse = await completePromise;
    expect(completeResponse.status(), await completeResponse.text()).toBe(200);
    expect((await completeResponse.json() as { data: Partial<RmaState> }).data).toMatchObject({
      id: rmaId,
      status: 'completed',
      disposition_status: 'disposed',
    });
    const completed = await readRma(cs.page, rmaId);
    expect(completed).toMatchObject({
      id: rmaId,
      status: 'completed',
      disposition_status: 'disposed',
      creator: { id: createdBy.id },
      completed_by: { id: createdBy.id },
    });
    expect(completed.items[0]).toMatchObject({
      moved_quantity: fixture.quantity,
      stock_movement_id: released.release_movement_id,
      mrb: { id: mrbId, status: 'released', release_movement_id: released.release_movement_id },
    });
    await expectAuditActor(auditAdmin.page, 'ReturnRequest', rmaId, createdBy.id);
    expect(await readStock(auditAdmin.page, fixture.item)).toEqual(stock);

    const duplicateComplete = await post(cs.page, `/return-management/return-requests/${rmaId}/complete`, {});
    expect(duplicateComplete.status()).toBe(422);
    expect(await readRma(cs.page, rmaId)).toEqual(completed);
    expect(await readStock(auditAdmin.page, fixture.item)).toEqual(stock);
    const editAfterCompletion = await cs.page.request.patch(
      `${BASE_URL}/api/v1/return-management/return-requests/${rmaId}`,
      {
        headers: await statefulCsrfHeaders(cs.page),
        data: { reason_description: 'attempted edit after downstream MRB release' },
      },
    );
    expect(editAfterCompletion.status()).toBe(422);
    expect(await readRma(cs.page, rmaId)).toEqual(completed);
    const cancelAfterCompletion = await post(cs.page, `/return-management/return-requests/${rmaId}/cancel`, {
      reason: 'Attempted cancel after physical release and completion.',
    });
    expect(cancelAfterCompletion.status()).toBe(422);
    expect(await readRma(cs.page, rmaId)).toEqual(completed);
    expect(await readStock(auditAdmin.page, fixture.item)).toEqual(stock);

    const browserIssues = auditUnexpectedBrowserFailures(actors.map((actor) => actor.monitor), rmaId);
    expect(browserIssues).toEqual([]);
  } finally {
    await Promise.all(actors.map((actor) => actor.context.close()));
  }
});
