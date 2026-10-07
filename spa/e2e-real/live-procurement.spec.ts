import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { localIsoDate } from '../src/lib/formatDate';
import { baseUrl } from './helpers';

const PASSWORD = 'password';
const BASE_URL = baseUrl();
const runErrors: string[] = [];
const abortedApiGets: Array<{ page: Page; url: string }> = [];
const successfulApiGets: Array<{ page: Page; url: string }> = [];

test.beforeEach(() => {
  runErrors.length = 0;
  abortedApiGets.length = 0;
  successfulApiGets.length = 0;
});

test.afterEach(() => {
  const matchedResponses = new Set<number>();
  const unmatchedAborts = abortedApiGets.filter((request) => {
    const responseIndex = successfulApiGets.findIndex((response, index) =>
      !matchedResponses.has(index)
      && response.page === request.page
      && response.url === request.url,
    );
    if (responseIndex < 0) return true;
    matchedResponses.add(responseIndex);
    return false;
  });
  expect([
    ...runErrors,
    ...unmatchedAborts.map(({ url }) => `aborted API GET without identical successful replacement: ${url}`),
  ]).toEqual([]);
});

async function login(page: Page, email: string): Promise<void> {
  await page.goto('/sign-in');
  expect(new URL(page.url()).origin).toBe(BASE_URL);
  await page.getByLabel('Email').fill(email);
  await page.getByRole('textbox', { name: 'Password' }).fill(PASSWORD);
  const responsePromise = page.waitForResponse((response) =>
    response.request().method() === 'POST'
    && response.url().includes('/api/v1/auth/sign-in'),
  );
  await page.getByRole('button', { name: /sign in/i }).click();
  const response = await responsePromise;
  expect(response.status(), `Login failed for ${email}.`).toBe(200);
  await page.waitForURL((url) => url.pathname !== '/sign-in', { timeout: 20_000 });
  await page.locator('#main-content').waitFor({ state: 'attached', timeout: 20_000 });
}

async function newContext(browser: Browser): Promise<{ context: BrowserContext; page: Page }> {
  const context = await browser.newContext({ baseURL: BASE_URL });
  const page = await context.newPage();
  page.on('pageerror', (error) => runErrors.push(`page error: ${error.message}`));
  page.on('console', (message) => {
    const text = message.text();
    if (message.type() === 'error' && !(text.includes('401') && text.includes('Unauthorized'))) {
      runErrors.push(`console error: ${text}`);
    }
  });
  page.on('requestfailed', (request) => {
    const errorText = request.failure()?.errorText ?? '';
    if (request.method() === 'GET' && request.url().includes('/api/') && errorText === 'net::ERR_ABORTED') {
      // React.StrictMode may cancel an initial read during its development remount.
      abortedApiGets.push({ page, url: request.url() });
      return;
    }
    runErrors.push(`request failed: ${request.method()} ${request.url()} ${errorText}`);
  });
  page.on('response', (response) => {
    const url = response.url();
    if (response.request().method() === 'GET' && url.includes('/api/') && response.status() < 400) {
      successfulApiGets.push({ page, url });
    }
    if (!url.includes('/api/') || response.status() < 400) return;
    if (response.status() === 401 && url.includes('/api/v1/auth/user')) return;
    runErrors.push(`API response ${response.status()}: ${response.request().method()} ${url}`);
  });
  return { context, page };
}

async function apiHeaders(context: BrowserContext): Promise<Record<string, string>> {
  const xsrf = (await context.cookies(BASE_URL)).find((cookie) => cookie.name === 'XSRF-TOKEN')?.value;
  return {
    Accept: 'application/json',
    Origin: BASE_URL,
    Referer: `${BASE_URL}/`,
    ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
  };
}

type LiveBillState = {
  status: string;
  total_amount: string;
  amount_paid: string;
  balance: string;
  cancelled_at: string | null;
  journal_entry: { id: string; status: string } | null;
  payments: Array<{
    id: string;
    reference_number: string;
    status: string;
    journal_entry_id: string | null;
    voided_at: string | null;
    voided_by: { id: string } | null;
    void_reason: string | null;
    void_reversal_journal_entry: { id: string; status: string } | null;
    can_void?: boolean;
    void_block_reason?: string | null;
    approval_records: Array<{
      step_order: number;
      role_slug: string;
      action: string;
      acted_at: string | null;
      approver: { id: string } | null;
    }>;
  }>;
};

async function readBillState(page: Page, billUrl: string): Promise<LiveBillState> {
  const billHash = billUrl.split('/').pop();
  if (!billHash) throw new Error('Bill detail link has no record id.');
  const response = await page.request.get(`${BASE_URL}/api/v1/bills/${billHash}`, {
    headers: await apiHeaders(page.context()),
  });
  expect(response.status()).toBe(200);
  return (await response.json() as { data: LiveBillState }).data;
}

async function readApiData<T>(page: Page, path: string): Promise<T> {
  const response = await page.request.get(`${BASE_URL}/api/v1${path}`, {
    headers: await apiHeaders(page.context()),
  });
  expect(response.status(), `${path}: ${await response.text()}`).toBe(200);
  return (await response.json() as { data: T }).data;
}

async function readAutomationActorRoles(page: Page): Promise<unknown[]> {
  const response = await page.request.get(`${BASE_URL}/api/v1/admin/settings`, {
    headers: await apiHeaders(page.context()),
  });
  expect(response.status()).toBe(200);
  const body = await response.json() as {
    data: Record<string, Array<{ key: string; value: unknown }>>;
  };
  const setting = Object.values(body.data).flat()
    .find((row) => row.key === 'system.automation.actor_roles');
  expect(Array.isArray(setting?.value)).toBe(true);
  return setting!.value as unknown[];
}

async function writeAutomationActorRoles(page: Page, roles: unknown[], reason: string): Promise<void> {
  const response = await page.request.put(
    `${BASE_URL}/api/v1/admin/settings/system.automation.actor_roles`,
    {
      headers: await apiHeaders(page.context()),
      data: { value: roles, reason },
    },
  );
  expect(response.status(), await response.text()).toBe(200);
}

async function selectDepartment(page: Page, departmentName = 'Production'): Promise<void> {
  const select = page.getByRole('combobox', { name: 'Department' });
  await expect(select).toBeVisible();
  await select.click();
  const department = page.getByRole('option', { name: departmentName, exact: true });
  await expect(department).toBeVisible();
  await department.click();
  await expect(select).toHaveValue(departmentName);
}

async function createManualPr(
  browser: Browser,
  input: { description: string; quantity: string; unitPrice: string; itemCode?: string; priority?: string },
): Promise<{ context: BrowserContext; page: Page; url: string }> {
  const { context, page } = await newContext(browser);
  await login(page, 'purchasing@ogami.test');
  await page.goto('/purchasing/purchase-requests/create');
  await expect(page.getByRole('heading', { name: 'New purchase request' })).toBeVisible();

  await page.getByLabel('Priority').selectOption(input.priority ?? 'normal');
  await page.getByLabel('Sourcing method').selectOption('direct_po');
  await selectDepartment(page);
  await page.getByLabel('Reason').fill(`Live E2E: ${input.description}`);
  await page.getByLabel('Quantity').fill(input.quantity);
  if (input.itemCode) {
    const item = page.locator('select[aria-label="Item"]');
    await expect.poll(() => item.locator('option').count(), { timeout: 20_000 }).toBeGreaterThan(1);
    const itemOptions = await item.locator('option').evaluateAll((nodes) =>
      nodes.map((node) => ({ value: node.value, text: node.textContent ?? '' })),
    );
    const selectedItem = itemOptions.find((option) => option.text.includes(input.itemCode!));
    if (!selectedItem?.value) throw new Error(`Catalog item ${input.itemCode} was not available.`);
    await item.selectOption(selectedItem.value);
  } else {
    await page.getByLabel('Description').fill(input.description);
    await page.getByRole('textbox', { name: 'Unit', exact: true }).fill(input.unitPrice === '50000.00' ? 'lot' : 'pcs');
    await page.getByLabel('Estimated unit price').fill(input.unitPrice);
  }

  await page.getByRole('button', { name: 'Submit for approval', exact: true }).click();
  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  await dialog.getByRole('button', { name: 'Submit', exact: true }).click();
  // Exclude the create route itself. `/create` also matches a bare `[^/]+`
  // detail pattern, which let a failed POST continue as if a PR had been
  // created and hid the actual API error behind a later status assertion.
  await page.waitForURL(/\/purchasing\/purchase-requests\/(?!create$)[^/]+$/, { timeout: 20_000 });
  await expect(page.getByText('Pending', { exact: true }).first()).toBeVisible({ timeout: 20_000 });

  return { context, page, url: page.url() };
}

async function approvePr(browser: Browser, email: string, url: string, final = true): Promise<void> {
  const { context, page } = await newContext(browser);
  try {
    await login(page, email);
    await page.goto(url);
    await expect(page.getByRole('button', { name: 'Approve', exact: true })).toBeVisible();

    const acknowledge = page.getByRole('button', { name: 'Finance acknowledge', exact: true });
    if (await acknowledge.count() > 0 && await acknowledge.isVisible()) {
      await acknowledge.click();
      await expect(page.getByText('Finance acknowledged', { exact: true })).toBeVisible();
    }

    await page.getByRole('button', { name: 'Approve', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Approve', exact: true }).click();
    if (final) {
      await expect(page.getByText('Approved', { exact: true }).first()).toBeVisible({ timeout: 20_000 });
    } else {
      const timeline = page.getByLabel('Approval timeline');
      await expect(page.getByText('Pending', { exact: true }).first()).toBeVisible({ timeout: 20_000 });
      await expect(timeline.getByText('Step 1 — Finance officer', { exact: true })).toBeVisible();
      await expect(timeline.getByText('Approved', { exact: true })).toBeVisible();
      await expect(timeline.getByText('Step 2 — Vice president', { exact: true })).toBeVisible();
      await expect(timeline.getByText('Pending', { exact: true })).toBeVisible();
    }
  } finally {
    await context.close();
  }
}

async function rejectPr(browser: Browser, url: string): Promise<void> {
  const { context, page } = await newContext(browser);
  try {
    await login(page, 'finance@ogami.test');
    await page.goto(url);
    await expect(page.getByRole('button', { name: 'Reject', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Reject', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await dialog.getByLabel('Rejection reason').fill('Live E2E rejection for budget review.');
    await dialog.getByRole('button', { name: 'Reject', exact: true }).click();
    await expect(page.getByText('Rejected', { exact: true }).first()).toBeVisible({ timeout: 20_000 });
  } finally {
    await context.close();
  }
}

async function approvePo(browser: Browser, email: string, url: string): Promise<void> {
  const { context, page } = await newContext(browser);
  try {
    await login(page, email);
    await page.goto(url);
    const approveButton = page.getByRole('button', { name: 'Approve', exact: true });
    const approvedState = page.getByText('Approved', { exact: true }).first();
    await expect(approveButton.or(approvedState)).toBeVisible({ timeout: 20_000 });
    if (await approveButton.isVisible()) {
      await approveButton.click();
      const dialog = page.getByRole('dialog');
      await expect(dialog).toBeVisible();
      await dialog.getByRole('button', { name: 'Approve', exact: true }).click();
    }
    await expect(approvedState).toBeVisible({ timeout: 20_000 });
  } finally {
    await context.close();
  }
}

async function createCatalogPrToSentPo(browser: Browser): Promise<{ poUrl: string; poId: string; poNumber: string; vendorName: string }> {
  const created = await createManualPr(browser, {
    description: 'Catalog resin replenishment',
    quantity: '5',
    unitPrice: '120.00',
    itemCode: 'RM-001',
  });
  try {
    await approvePr(browser, 'finance@ogami.test', created.url);
  } finally {
    await created.context.close();
  }

  const purchasing = await newContext(browser);
  let poUrl: string;
  try {
    await login(purchasing.page, 'purchasing@ogami.test');
    await purchasing.page.goto(created.url);
    const convertButton = purchasing.page.getByRole('button', { name: 'Convert to PO', exact: true });
    const poLink = purchasing.page.locator('a[href*="/purchasing/purchase-orders/"]').first();
    await expect(convertButton.or(poLink).first()).toBeVisible({ timeout: 45_000 });

    if (await convertButton.count() > 0 && await convertButton.isVisible()) {
      await convertButton.click();
      const conversion = purchasing.page.getByRole('dialog');
      await expect(conversion).toContainText('Assign a supplier per line');
      await conversion.getByLabel('Supplier').selectOption({ label: 'Audit E2E Resin Supplier' });
      const createOrders = conversion.getByRole('button', { name: /Create purchase order/ });
      await expect(createOrders).toBeEnabled();
      await createOrders.click();
      await purchasing.page.waitForURL(/\/purchasing\/purchase-orders\/[^/]+$/, { timeout: 20_000 });
    } else {
      await expect(poLink).toBeVisible();
      await poLink.click();
      await purchasing.page.waitForURL(/\/purchasing\/purchase-orders\/[^/]+$/, { timeout: 20_000 });
    }

    poUrl = purchasing.page.url();
    await expect(purchasing.page.getByRole('heading').filter({ hasText: 'PO-' })).toBeVisible({ timeout: 20_000 });
    const submitButton = purchasing.page.getByRole('button', { name: 'Submit', exact: true });
    await expect(submitButton).toBeVisible({ timeout: 20_000 });
    await submitButton.click();
    const submitDialog = purchasing.page.getByRole('dialog');
    await submitDialog.getByRole('button', { name: 'Submit', exact: true }).click();
    await expect(purchasing.page.getByText('Pending approval', { exact: true })).toBeVisible({ timeout: 20_000 });
  } finally {
    await purchasing.context.close();
  }

  await approvePo(browser, 'finance@ogami.test', poUrl);

  const sender = await newContext(browser);
  try {
    await login(sender.page, 'purchasing@ogami.test');
    await sender.page.goto(poUrl);
    const sendButton = sender.page.getByRole('button', { name: 'Mark as sent', exact: true });
    const sentState = sender.page.getByText('Sent', { exact: true }).last();
    await expect(sendButton.or(sentState)).toBeVisible({ timeout: 20_000 });
    if (await sendButton.count() > 0 && await sendButton.isVisible()) {
      await sendButton.click();
      const sendDialog = sender.page.getByRole('dialog');
      await sendDialog.getByRole('button', { name: 'Mark as sent', exact: true }).click();
      await expect(sender.page.getByText('Sent', { exact: true }).last()).toBeVisible({ timeout: 20_000 });
    }
    const poNumber = (await sender.page.locator('h1').first().innerText()).match(/PO-\S+/)?.[0];
    if (!poNumber) throw new Error('The generated PO number was not readable.');
    const vendorName = await sender.page.locator('dt').filter({ hasText: /Vendor/i })
      .locator('xpath=following-sibling::dd[1]').innerText();
    return { poUrl, poId: poUrl.split('/').pop()!, poNumber, vendorName };
  } finally {
    await sender.context.close();
  }
}

async function submitLotChecklistInspection(page: Page, inspectionId: string, sampleSize: number): Promise<void> {
  const checklist = page.getByRole('region', { name: 'Inspection checklist' });
  const passButtons = checklist.getByRole('listitem').getByRole('button', { name: /^PASS / });
  await expect(passButtons.first()).toBeVisible();
  const requirementCount = await passButtons.count();
  for (let index = 0; index < requirementCount; index += 1) await passButtons.nth(index).click();

  const coverageConfirmation = checklist.getByRole('checkbox', {
    name: 'I confirm all required checks on the planned sample were completed',
  });
  await expect(checklist.getByRole('spinbutton')).toHaveCount(0);
  const submitInspection = page.getByRole('button', { name: 'Submit inspection', exact: true });
  await expect(submitInspection).toBeDisabled();
  await coverageConfirmation.check();
  await expect(submitInspection).toBeEnabled();

  const responsePromise = page.waitForResponse((response) =>
    response.request().method() === 'POST'
    && response.url().includes(`/api/v1/quality/inspections/${inspectionId}/batch-result`),
  );
  await submitInspection.click();
  const response = await responsePromise;
  const submission = response.request().postDataJSON() as {
    coverage_confirmed: boolean;
    inspected_quantity?: number;
    sample_defect_count?: number;
    items: Array<{ id: string; result: string }>;
  };
  expect(submission.coverage_confirmed).toBe(true);
  expect(submission).not.toHaveProperty('inspected_quantity');
  expect(submission).not.toHaveProperty('sample_defect_count');
  expect(submission.items).toHaveLength(requirementCount);
  expect(response.status()).toBe(200);
  const result = await response.json() as {
    data?: {
      status?: string;
      proposed_result?: string | null;
      defect_count?: number;
      sample_defect_count?: number | null;
      inspected_quantity?: number | null;
      can_review?: boolean;
      result_authors?: Array<{ id: string; name: string }>;
    };
  };
  expect(result.data).toMatchObject({
    status: 'awaiting_review',
    proposed_result: 'passed',
    defect_count: 0,
    sample_defect_count: 0,
    inspected_quantity: sampleSize,
    can_review: false,
  });
  expect(result.data?.result_authors).toHaveLength(1);
  await expect(page.getByText(/Awaiting checker.*passed/i).first()).toBeVisible({ timeout: 20_000 });
}

async function createDraftBillFromFreshGrn(browser: Browser): Promise<string> {
  const { poNumber } = await createCatalogPrToSentPo(browser);
  const warehouse = await newContext(browser);
  try {
    await login(warehouse.page, 'warehouse@ogami.test');
    await warehouse.page.goto('/inventory/grn');
    await expect(warehouse.page.getByText(poNumber, { exact: true })).toBeVisible({ timeout: 20_000 });
    await warehouse.page.getByText(poNumber, { exact: true }).click();
    await warehouse.page.waitForURL(/\/inventory\/grn\/[^/]+$/, { timeout: 20_000 });
    const grnId = warehouse.page.url().split('/').pop()!;

    const bin = warehouse.page.locator('select[aria-label^="Bin "]');
    await expect.poll(() => bin.locator('option').count(), { timeout: 20_000 }).toBeGreaterThan(1);
    const binOptions = await bin.locator('option').evaluateAll((nodes) =>
      nodes.map((node) => ({ value: (node as HTMLOptionElement).value })),
    );
    const binValue = binOptions.find((option) => option.value !== '')?.value;
    if (!binValue) throw new Error('No usable warehouse bin was available.');
    await bin.selectOption(binValue);
    await warehouse.page.locator('input[aria-label^="Entered quantity "]').fill('5');
    const finalizeResponsePromise = warehouse.page.waitForResponse((response) =>
      response.request().method() === 'PATCH'
      && response.url().endsWith(`/api/v1/inventory/grn/${grnId}/finalize`),
    );
    await warehouse.page.getByRole('button', { name: 'Finalize receiving', exact: true }).click();
    await warehouse.page.getByRole('dialog').getByRole('button', { name: 'Finalize GRN', exact: true }).click();
    const finalizeResponse = await finalizeResponsePromise;
    expect(finalizeResponse.status(), await finalizeResponse.text()).toBe(200);
    const finalized = (await finalizeResponse.json() as {
      data: {
        status: string;
        bill: { id: string } | null;
        items: Array<{
          item: { id: string };
          quantity_received: string;
          quantity_accepted: string;
          inspection: { id: string; inspection_number: string; status: string } | null;
        }>;
      };
    }).data;
    expect(finalized).toMatchObject({ status: 'pending_qc', bill: null });
    expect(finalized.items).toHaveLength(1);
    expect(finalized.items[0]).toMatchObject({
      quantity_received: '5.000',
      quantity_accepted: '0.000',
      inspection: { status: 'draft' },
    });
    const { id: itemId } = finalized.items[0].item;
    const inspectionId = finalized.items[0].inspection!.id;
    const inspectionNumber = finalized.items[0].inspection!.inspection_number;
    const movementsBeforeReview = await readApiData<Array<{ reference_id: string }>>(
      warehouse.page,
      `/inventory/stock-movements?item_id=${itemId}&movement_type=grn_receipt&reference_type=goods_receipt_note&per_page=100`,
    );
    expect(movementsBeforeReview.filter((movement) => movement.reference_id === grnId)).toHaveLength(0);

    const qc = await newContext(browser);
    try {
      await login(qc.page, 'qc@ogami.test');
      await qc.page.goto('/quality/inspections');
      const listResponsePromise = qc.page.waitForResponse((response) =>
        response.request().method() === 'GET'
        && response.url().includes('/api/v1/quality/inspections?'),
      );
      await qc.page.getByPlaceholder('Search inspection, product').fill(inspectionNumber);
      const listResponse = await listResponsePromise;
      expect(listResponse.status()).toBe(200);
      const listed = (await listResponse.json() as {
        data: Array<{
          id: string;
          inspection_number: string;
          inspection_mode: string;
          capture_method: string;
          sampling_basis: string;
          source_quantity: string;
          source_uom_code: string;
          sample_size: number;
        }>;
      }).data.find((inspection) => inspection.id === inspectionId);
      expect(listed).toMatchObject({
        inspection_number: inspectionNumber,
        inspection_mode: 'lot_checklist',
        capture_method: 'checklist',
        sampling_basis: 'lot',
        source_quantity: '5.000',
        source_uom_code: 'kg',
      });
      expect(listed!.sample_size).toBeGreaterThan(0);

      await qc.page.goto(`/quality/inspections/${inspectionId}`);
      await submitLotChecklistInspection(qc.page, inspectionId, listed!.sample_size);

      const inspectionBeforeMakerReview = await readApiData<{
        status: string;
        result_version: number;
      }>(qc.page, `/quality/inspections/${inspectionId}`);
      expect(inspectionBeforeMakerReview.status).toBe('awaiting_review');
      const makerReview = await qc.page.request.patch(`${BASE_URL}/api/v1/quality/inspections/${inspectionId}/review`, {
        headers: await apiHeaders(qc.context),
        data: {
          decision: 'passed',
          remarks: 'The result maker cannot approve their own inspection.',
          expected_result_version: inspectionBeforeMakerReview.result_version,
        },
      });
      expect(makerReview.status()).toBe(403);
      expect((await makerReview.json() as { message?: string }).message)
        .toContain('You cannot review an inspection you performed.');
      expect(await readApiData(qc.page, `/quality/inspections/${inspectionId}`))
        .toEqual(inspectionBeforeMakerReview);
      const pendingGrn = await readApiData<{
        status: string;
        bill: { id: string } | null;
        items: Array<{ quantity_accepted: string; inspection: { status: string } }>;
      }>(warehouse.page, `/inventory/grn/${grnId}`);
      expect(pendingGrn).toMatchObject({
        status: 'pending_qc',
        bill: null,
        items: [{ quantity_accepted: '0.000', inspection: { status: 'awaiting_review' } }],
      });
    } finally {
      await qc.context.close();
    }

    const reviewer = await newContext(browser);
    let billId = '';
    try {
      await login(reviewer.page, 'production@ogami.test');
      await reviewer.page.goto('/notifications');
      await expect(reviewer.page.getByText(`Inspection ${inspectionNumber} awaits your review`, { exact: true }))
        .toBeVisible({ timeout: 20_000 });
      await reviewer.page.goto(`/quality/inspections/${inspectionId}`);
      const approvePass = reviewer.page.getByRole('button', { name: 'Approve pass', exact: true });
      await expect(approvePass).toBeVisible();
      const reviewResponsePromise = reviewer.page.waitForResponse((response) =>
        response.request().method() === 'PATCH'
        && response.url().endsWith(`/api/v1/quality/inspections/${inspectionId}/review`),
      );
      await approvePass.click();
      await reviewer.page.getByRole('dialog').getByRole('button', { name: 'Approve pass', exact: true }).click();
      const reviewResponse = await reviewResponsePromise;
      expect(reviewResponse.status()).toBe(200);
      expect((await reviewResponse.json() as { data: { status: string; reviewer: { id: string } | null } }).data.status)
        .toBe('passed');
      await expect(reviewer.page.getByText('Passed', { exact: true }).first()).toBeVisible();

      const acceptedGrn = await readApiData<{
        status: string;
        bill: { id: string; status: string; total_amount: string } | null;
        items: Array<{ quantity_received: string; quantity_accepted: string; inspection: { status: string } }>;
      }>(warehouse.page, `/inventory/grn/${grnId}`);
      expect(acceptedGrn).toMatchObject({
        status: 'accepted',
        items: [{ quantity_received: '5.000', quantity_accepted: '5.000', inspection: { status: 'passed' } }],
      });
      expect(acceptedGrn.bill).toMatchObject({ status: 'draft' });
      billId = acceptedGrn.bill!.id;
    } finally {
      await reviewer.context.close();
    }

    const audit = await newContext(browser);
    try {
      await login(audit.page, 'admin@ogami.test');
      const grnAudit = await readApiData<Array<{
        user: { email: string } | null;
        new_values: Record<string, unknown> | null;
      }>>(audit.page, `/admin/audit-logs/entity?model_type=GoodsReceiptNote&model_id=${grnId}&per_page=100`);
      expect(grnAudit.some((entry) => entry.user?.email === 'warehouse@ogami.test'
        && entry.new_values?.status === 'pending_qc')).toBe(true);
      expect(grnAudit.some((entry) => entry.new_values?.status === 'accepted')).toBe(true);
      const inspectionAudit = await readApiData<Array<{
        user: { email: string } | null;
        new_values: Record<string, unknown> | null;
      }>>(audit.page, `/admin/audit-logs/entity?model_type=Inspection&model_id=${inspectionId}&per_page=100`);
      expect(inspectionAudit.some((entry) => entry.user?.email === 'qc@ogami.test'
        && entry.new_values?.status === 'awaiting_review')).toBe(true);
      expect(inspectionAudit.some((entry) => entry.user?.email === 'production@ogami.test'
        && entry.new_values?.status === 'passed')).toBe(true);
    } finally {
      await audit.context.close();
    }

    await warehouse.page.reload({ waitUntil: 'domcontentloaded' });
    const billLink = warehouse.page.getByRole('link', { name: /^BILL-/ }).first();
    await expect(billLink).toBeVisible({ timeout: 20_000 });
    const href = await billLink.getAttribute('href');
    if (!href || href.split('/').pop() !== billId) throw new Error('Accepted GRN bill link does not match its linked bill id.');

    const movements = await readApiData<Array<{
      reference_id: string | null;
      movement_type: string;
      quantity: string;
      unit_cost: string;
      total_cost: string;
    }>>(warehouse.page, `/inventory/stock-movements?item_id=${itemId}&movement_type=grn_receipt&reference_type=goods_receipt_note&per_page=100`);
    expect(movements.filter((movement) => movement.reference_id === grnId)).toEqual([
      expect.objectContaining({
        movement_type: 'grn_receipt',
        quantity: '5.000',
        unit_cost: '120.0000',
        total_cost: '600.00',
      }),
    ]);
    return href;
  } finally {
    await warehouse.context.close();
  }
}

async function assertRmaApprovalQueue(
  browser: Browser,
  email: string,
  url: string,
  rmaNumber: string,
  stepOrder: number,
): Promise<void> {
  const { context, page } = await newContext(browser);
  try {
    await login(page, email);
    const boardResponsePromise = page.waitForResponse((response) =>
      response.request().method() === 'GET'
      && response.url().includes('/api/v1/approvals/board'),
    );
    await page.goto('/approvals');
    const boardResponse = await boardResponsePromise;
    expect(boardResponse.status()).toBe(200);
    const board = await boardResponse.json() as {
      data?: {
        my_action?: Array<{
          id: string;
          type: string;
          number: string;
          link: string;
          step_order: number;
          role_slug: string;
        }>;
      };
    };
    const card = board.data?.my_action?.find((row) => row.id === url.split('/').pop() && row.type === 'return_request');
    expect(card).toMatchObject({
      number: rmaNumber,
      link: url.replace(BASE_URL, ''),
      step_order: stepOrder,
      role_slug: stepOrder === 1 ? 'department_head' : 'production_manager',
    });
    const myActionColumn = page.getByText('My action required', { exact: true }).locator('xpath=../..');
    await expect(myActionColumn.getByRole('button').filter({ hasText: rmaNumber })).toBeVisible();
  } finally {
    await context.close();
  }
}

async function approveRma(browser: Browser, email: string, url: string, final: boolean, rmaNumber: string): Promise<void> {
  const { context, page } = await newContext(browser);
  const id = url.split('/').pop()!;
  const expectedRoles = final ? ['department_head', 'production_manager'] : ['department_head'];
  try {
    await login(page, email);
    const beforeReadPromise = page.waitForResponse((response) =>
      response.request().method() === 'GET'
      && response.url().includes(`/api/v1/return-management/return-requests/${id}`),
    );
    await page.goto(url);
    const beforeRead = await beforeReadPromise;
    const before = await beforeRead.json() as {
      data?: {
        status?: string;
        pending_approval_step?: number | null;
        actions?: { can_approve?: boolean };
        approval_records?: Array<{ step_order: number; role_slug: string; action: string }>;
      };
    };
    expect(before.data).toMatchObject({
      status: 'pending_approval',
      pending_approval_step: final ? 2 : 1,
      actions: { can_approve: true },
    });
    expect(before.data?.approval_records?.filter((row) => row.action === 'approved').map((row) => row.role_slug))
      .toEqual(final ? ['department_head'] : []);

    const approveButton = page.getByRole('button', { name: 'Approve', exact: true });
    await expect(approveButton).toBeVisible({ timeout: 20_000 });
    const approvalResponsePromise = page.waitForResponse((response) =>
      response.request().method() === 'POST'
      && response.url().includes(`/api/v1/return-management/return-requests/${id}/approve`),
    );
    const afterReadPromise = page.waitForResponse((response) =>
      response.request().method() === 'GET'
      && response.url().includes(`/api/v1/return-management/return-requests/${id}`),
    );
    await approveButton.click();
    const dialog = page.getByRole('dialog');
    await dialog.getByRole('button', { name: 'Approve', exact: true }).click();
    const approvalResponse = await approvalResponsePromise;
    expect(approvalResponse.status()).toBe(200);
    const approvalResult = await approvalResponse.json() as { data?: { status?: string } };
    expect(approvalResult.data?.status).toBe(final ? 'approved' : 'pending_approval');

    const afterRead = await afterReadPromise;
    const after = await afterRead.json() as {
      data?: {
        rma_number?: string;
        status?: string;
        pending_approval_step?: number | null;
        approval_records?: Array<{ step_order: number; role_slug: string; action: string; approver?: { id: string } | null }>;
      };
    };
    expect(after.data?.rma_number).toBe(rmaNumber);
    expect(after.data?.status).toBe(final ? 'approved' : 'pending_approval');
    expect(after.data?.pending_approval_step).toBe(final ? null : 2);
    const completed = after.data?.approval_records?.filter((row) => row.action === 'approved') ?? [];
    expect(completed.map((row) => row.role_slug)).toEqual(expectedRoles);
    expect(new Set(completed.map((row) => row.approver?.id)).size).toBe(completed.length);
  } finally {
    await context.close();
  }
}

async function rejectRma(browser: Browser, email: string, url: string): Promise<void> {
  const { context, page } = await newContext(browser);
  try {
    await login(page, email);
    await page.goto(url);
    const rejectButton = page.getByRole('button', { name: 'Reject', exact: true });
    await expect(rejectButton).toBeVisible({ timeout: 20_000 });
    await rejectButton.click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Rejection reason').fill('Customer credit request rejected during live E2E review.');
    await dialog.getByRole('button', { name: 'Reject', exact: true }).click();
    await expect(page.getByText('Rejected', { exact: true }).first()).toBeVisible({ timeout: 20_000 });
  } finally {
    await context.close();
  }
}

async function approvePaymentRequest(browser: Browser, email: string, billUrl: string, paymentReference: string, final: boolean): Promise<void> {
  const { context, page } = await newContext(browser);
  try {
    await login(page, email);
    const boardResponsePromise = page.waitForResponse((response) =>
      response.request().method() === 'GET'
      && response.url().includes('/api/v1/approvals/board'),
    );
    await page.goto('/approvals');
    const boardResponse = await boardResponsePromise;
    expect(boardResponse.status()).toBe(200);
    const board = await boardResponse.json() as {
      data?: { my_action?: Array<{ type: string; number: string; link: string }> };
    };
    expect(board.data?.my_action).toContainEqual(expect.objectContaining({
      type: 'bill_payment',
      number: paymentReference,
      link: billUrl,
    }));
    const queueCard = page
      .getByRole('button', { name: new RegExp(paymentReference) })
      .filter({ hasText: 'Open record to act' });
    await expect(queueCard).toBeVisible({ timeout: 20_000 });
    await queueCard.click();
    await expect(page).toHaveURL(new URL(billUrl, BASE_URL).toString());
    const approve = page.getByRole('button', { name: 'Approve', exact: true });
    await expect(approve).toBeVisible({ timeout: 20_000 });
    const decisionPromise = page.waitForResponse((response) =>
      response.request().method() === 'POST'
      && response.url().includes('/payments/')
      && response.url().endsWith('/approve'),
    );
    await approve.click();
    const decision = await decisionPromise;
    expect(decision.status()).toBe(200);
    const payment = (await decision.json() as {
      data: {
        id: string;
        status: string;
        journal_entry_id?: string | null;
        approval_records: Array<{ step_order: number; role_slug: string; action: string; acted_at: string | null; approver: { id: string } | null }>;
      };
    }).data;
    expect(payment.status).toBe(final ? 'posted' : 'pending_approval');
    expect(payment.approval_records.map(({ step_order, role_slug, action }) => ({ step_order, role_slug, action })).sort((a, b) => a.step_order - b.step_order)).toEqual([
      { step_order: 1, role_slug: 'finance_officer', action: 'approved' },
      { step_order: 2, role_slug: 'vice_president', action: final ? 'approved' : 'pending' },
    ]);
    const firstApproval = payment.approval_records.find((record) => record.step_order === 1);
    const secondApproval = payment.approval_records.find((record) => record.step_order === 2);
    expect(firstApproval?.approver?.id).toBeTruthy();
    expect(firstApproval?.acted_at).toBeTruthy();
    if (final) {
      expect(secondApproval?.approver?.id).toBeTruthy();
      expect(secondApproval?.approver?.id).not.toBe(firstApproval?.approver?.id);
      expect(secondApproval?.acted_at).toBeTruthy();
    } else {
      expect(secondApproval?.approver).toBeNull();
      expect(secondApproval?.acted_at).toBeNull();
    }
    const billHash = billUrl.split('/').pop();
    if (!billHash) throw new Error('Bill detail link has no record id.');
    const billState = await readBillState(page, billUrl);
    const linkedPayment = billState.payments.find((record) => record.id === payment.id);
    expect(linkedPayment).toBeDefined();
    if (!linkedPayment) throw new Error('Approved payment is missing from its bill.');
    if (final) {
      expect(billState.status).toBe('partial');
      expect(billState.amount_paid).toBe('1.00');
      expect(Math.round(Number(billState.balance) * 100)).toBe(
        Math.round(Number(billState.total_amount) * 100) - 100,
      );
      expect(linkedPayment.status).toBe('posted');
      expect(linkedPayment.journal_entry_id).toBeTruthy();
    } else {
      expect(billState.status).toBe('unpaid');
      expect(billState.amount_paid).toBe('0.00');
      expect(billState.balance).toBe(billState.total_amount);
      expect(linkedPayment.status).toBe('pending_approval');
      expect(linkedPayment.journal_entry_id).toBeNull();
    }
    const duplicate = await page.request.post(
      `${BASE_URL}/api/v1/bills/${billHash}/payments/${payment.id}/approve`,
      { headers: await apiHeaders(context), data: {} },
    );
    expect(duplicate.status()).toBe(final ? 422 : 403);
    const duplicateBody = await duplicate.json() as { message?: string };
    expect(duplicateBody.message).toBe(final
      ? 'Only pending payment requests can be approved.'
      : 'A different user must act at each approval step.');
    expect(await readBillState(page, billUrl)).toEqual(billState);
    await expect(page.getByText(new RegExp(paymentReference))).toBeVisible({ timeout: 20_000 });
    if (final) await expect(page.getByText('Partially paid', { exact: true }).first()).toBeVisible({ timeout: 20_000 });
    await expect(page.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
    if (final) {
      const completedBoardPromise = page.waitForResponse((response) =>
        response.request().method() === 'GET'
        && response.url().includes('/api/v1/approvals/board'),
      );
      await page.goto('/approvals');
      const completedBoard = await completedBoardPromise;
      expect(completedBoard.status()).toBe(200);
      const boardState = await completedBoard.json() as {
        data?: {
          my_action?: Array<{ type: string; number: string }>;
          approved?: Array<{ type: string; number: string; link: string }>;
        };
      };
      expect(boardState.data?.my_action?.some((card) => card.number === paymentReference)).toBe(false);
      expect(boardState.data?.approved).toContainEqual(expect.objectContaining({
        type: 'bill_payment',
        number: paymentReference,
        link: billUrl,
      }));
    }
  } finally {
    await context.close();
  }
}

function futureDate(days: number): string {
  const date = new Date();
  date.setDate(date.getDate() + days);
  return localIsoDate(date);
}

test.describe('Live procurement runbook', () => {
  test.describe.configure({ mode: 'serial' });
  let poId = '';
  let poNumber = '';
  let vendorName = '';
  let billUrl = '';
  let rmaNumber = '';

  test('sign-in retains its authenticated session after clearing guest queries', async ({ browser }) => {
    const { context, page } = await newContext(browser);
    let signInStarted = false;
    let signInReturned = false;
    let releaseGuestResponse!: () => void;
    const guestResponseGate = new Promise<void>((resolve) => {
      releaseGuestResponse = resolve;
    });
    let landingQueryAfterSubmit!: () => void;
    const landingQueryAfterSubmitPromise = new Promise<void>((resolve) => {
      landingQueryAfterSubmit = resolve;
    });
    let landingQueryAfterSubmitSeen = false;

    try {
      await context.route('**/api/v1/landing/**', async (route) => {
        if (route.request().method() !== 'GET') {
          await route.continue();
          return;
        }
        const submittedBeforeSignIn = signInStarted && !signInReturned;
        const response = await route.fetch();
        if (submittedBeforeSignIn) await guestResponseGate;
        await route.fulfill({ response });
        if (signInStarted && !landingQueryAfterSubmitSeen) {
          landingQueryAfterSubmitSeen = true;
          landingQueryAfterSubmit();
        }
      });

      const initialContentResponse = page.waitForResponse((response) =>
        response.request().method() === 'GET'
        && response.url().includes('/api/v1/landing/content'),
      );
      const initialContactResponse = page.waitForResponse((response) =>
        response.request().method() === 'GET'
        && response.url().includes('/api/v1/landing/contact'),
      );
      await page.goto('/sign-in');
      expect(new URL(page.url()).origin).toBe(BASE_URL);
      await expect(page.getByLabel('Email')).toBeVisible();
      const initialResponses = await Promise.all([initialContentResponse, initialContactResponse]);
      expect(initialResponses.map((response) => response.status())).toEqual([200, 200]);
      await page.getByLabel('Email').fill('purchasing@ogami.test');
      await page.getByRole('textbox', { name: 'Password' }).fill(PASSWORD);
      const signInResponsePromise = page.waitForResponse((response) =>
        response.request().method() === 'POST'
        && response.url().includes('/api/v1/auth/sign-in'),
      );
      page.on('response', (response) => {
        if (response.request().method() === 'POST' && response.url().includes('/api/v1/auth/sign-in')) {
          signInReturned = true;
          releaseGuestResponse();
        }
      });
      signInStarted = true;
      await page.getByRole('button', { name: /sign in/i }).click();
      const signInResponse = await signInResponsePromise;
      expect(signInResponse.status()).toBe(200);
      signInReturned = true;
      releaseGuestResponse();
      await landingQueryAfterSubmitPromise;
      await page.waitForURL((url) => url.pathname !== '/sign-in', { timeout: 20_000 });
      await page.locator('#main-content').waitFor({ state: 'attached', timeout: 20_000 });
      const response = await page.request.get(`${BASE_URL}/api/v1/auth/user`, {
        headers: await apiHeaders(context),
      });
      expect(response.status()).toBe(200);
    } finally {
      releaseGuestResponse();
      await context.close();
    }
  });

  test('RBAC denial and Department Head department boundary', async ({ browser }) => {
    const warehouse = await newContext(browser);
    try {
      await login(warehouse.page, 'warehouse@ogami.test');
      await warehouse.page.goto('/purchasing/purchase-requests');
      await expect(warehouse.page.getByText('Page not found', { exact: true })).toBeVisible();
    } finally {
      await warehouse.context.close();
    }

    const departmentHead = await newContext(browser);
    try {
      await login(departmentHead.page, 'depthead@ogami.test');
      await departmentHead.page.goto('/purchasing/purchase-requests/create');
      const department = departmentHead.page.getByRole('combobox', { name: 'Department' });
      await expect(department).toBeDisabled();
      await expect(department).toHaveValue('Production');
    } finally {
      await departmentHead.context.close();
    }
  });

  test('Sales Order confirmation creates an MRP plan and auto-PR handoff', async ({ browser }) => {
    test.setTimeout(180_000);
    const sales = await newContext(browser);
    let soNumber = '';
    try {
      await login(sales.page, 'crm@ogami.test');
      await sales.page.goto('/crm/sales-orders/create');
      await expect(sales.page.getByRole('heading', { name: 'New sales order' })).toBeVisible();

      const customer = sales.page.locator('select[name="customer_id"]');
      await expect.poll(() => customer.locator('option').count(), { timeout: 20_000 }).toBeGreaterThan(1);
      const customerOptions = await customer.locator('option').evaluateAll((nodes) =>
        nodes.map((node) => ({ value: node.value, text: node.textContent ?? '' })),
      );
      const customerChoice = customerOptions.find((option) => option.text.includes('Honda'))
        ?? customerOptions.find((option) => option.value !== '');
      if (!customerChoice?.value) throw new Error('No active customer was available.');
      await customer.selectOption(customerChoice.value);

      const product = sales.page.locator('select[name="items.0.product_id"]');
      await expect.poll(() => product.locator('option').count(), { timeout: 20_000 }).toBeGreaterThan(1);
      const productOptions = await product.locator('option').evaluateAll((nodes) =>
        nodes.map((node) => ({ value: node.value, text: node.textContent ?? '' })),
      );
      const productChoice = productOptions.find((option) => option.text.includes('WB-002'))
        ?? productOptions.find((option) => option.value !== '');
      if (!productChoice?.value) throw new Error('No active product was available.');
      await product.selectOption(productChoice.value);

      await sales.page.locator('input[name="items.0.quantity"]').fill('5000');
      await sales.page.locator('input[name="items.0.delivery_date"]').fill(futureDate(30));
      await sales.page.getByRole('button', { name: 'Save & confirm', exact: true }).click();
      await sales.page.waitForURL(/\/crm\/sales-orders\/(?!create$)[^/]+$/, { timeout: 30_000 });
      await expect(sales.page.getByText('Confirmed', { exact: true }).first()).toBeVisible({ timeout: 30_000 });
      soNumber = (await sales.page.locator('h1').first().innerText()).match(/SO-\S+/)?.[0] ?? '';

      const mrpLink = sales.page.locator('a[href*="/mrp/plans/"]');
      await expect.poll(async () => {
        await sales.page.reload({ waitUntil: 'networkidle' });
        return await mrpLink.count();
      }, { timeout: 75_000, intervals: [1_000, 2_000, 5_000] }).toBeGreaterThan(0);
    } finally {
      await sales.context.close();
    }

    const purchasing = await newContext(browser);
    try {
      await login(purchasing.page, 'purchasing@ogami.test');
      const responsePromise = purchasing.page.waitForResponse((response) =>
        response.request().method() === 'GET'
        && response.url().includes('/api/v1/purchasing/purchase-requests?'),
      );
      await purchasing.page.goto('/purchasing/purchase-requests?per_page=100&is_auto_generated=true');
      const response = await responsePromise;
      expect(response.ok()).toBeTruthy();
      const body = await response.json() as { data?: Array<{ id: string; status: string; pr_number: string; reason?: string }> };
      const autoPr = body.data?.find((row) => row.reason?.includes(soNumber));
      expect(autoPr, `No auto-generated PR was linked to ${soNumber}`).toBeTruthy();
      expect(autoPr?.status).toBe('draft');

      await purchasing.page.goto(`/purchasing/purchase-requests/${autoPr!.id}`);
      await expect(purchasing.page.getByText('AUTO', { exact: true })).toBeVisible();
      await purchasing.page.getByRole('radio', { name: /Direct PO/ }).check();
      await purchasing.page.getByRole('button', { name: 'Save sourcing method', exact: true }).click();
      await purchasing.page.getByRole('button', { name: 'Submit', exact: true }).click();
      const dialog = purchasing.page.getByRole('dialog');
      await dialog.getByRole('button', { name: 'Submit', exact: true }).click();
      await expect(purchasing.page.getByText('Pending', { exact: true }).first()).toBeVisible({ timeout: 20_000 });
    } finally {
      await purchasing.context.close();
    }
  });

  test('manual low-value PR reaches Finance and skips VP', async ({ browser }) => {
    const created = await createManualPr(browser, {
      description: 'Low-value packaging replenishment',
      quantity: '5',
      unitPrice: '120.00',
    });
    try {
      await expect(created.page.getByText('Finance', { exact: true })).toBeVisible();
      await approvePr(browser, 'finance@ogami.test', created.url);
      await created.page.reload();
      await expect(created.page.getByText('Approved', { exact: true }).first()).toBeVisible();
      await expect(created.page.getByLabel('Approval timeline').getByText('Skipped', { exact: true })).toBeVisible();
    } finally {
      await created.context.close();
    }
  });

  test('exact PHP 50,000 PR requires Finance then VP', async ({ browser }) => {
    const created = await createManualPr(browser, {
      description: 'Exact threshold tooling request',
      quantity: '1',
      unitPrice: '50000.00',
    });
    try {
      const vpBeforeFinance = await newContext(browser);
      try {
        await login(vpBeforeFinance.page, 'vp@ogami.test');
        await vpBeforeFinance.page.goto(created.url);
        await expect(vpBeforeFinance.page.getByText('Pending', { exact: true }).first()).toBeVisible();
        await expect(vpBeforeFinance.page.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
      } finally {
        await vpBeforeFinance.context.close();
      }

      await approvePr(browser, 'finance@ogami.test', created.url, false);

      const vp = await newContext(browser);
      try {
        await login(vp.page, 'vp@ogami.test');
        await vp.page.goto(created.url);
        await expect(vp.page.getByLabel('Approval timeline').getByText('Step 1 — Finance officer', { exact: true })).toBeVisible();
        await expect(vp.page.getByLabel('Approval timeline').getByText('Approved', { exact: true })).toBeVisible();
        await expect(vp.page.getByLabel('Approval timeline').getByText('Step 2 — Vice president', { exact: true })).toBeVisible();
        await expect(vp.page.getByLabel('Approval timeline').getByText('Pending', { exact: true })).toBeVisible();
        await expect(vp.page.getByRole('button', { name: 'Approve', exact: true })).toBeVisible();
        await vp.page.getByRole('button', { name: 'Approve', exact: true }).click();
        const dialog = vp.page.getByRole('dialog');
        await dialog.getByRole('button', { name: 'Approve', exact: true }).click();
        await expect(vp.page.getByText('Approved', { exact: true }).first()).toBeVisible({ timeout: 20_000 });
      } finally {
        await vp.context.close();
      }
    } finally {
      await created.context.close();
    }
  });

  test('Finance rejection terminates the PR chain', async ({ browser }) => {
    const created = await createManualPr(browser, {
      description: 'Rejected emergency purchase',
      quantity: '2',
      unitPrice: '300.00',
      priority: 'urgent',
    });
    try {
      await rejectPr(browser, created.url);
      await created.page.reload();
      await expect(created.page.getByText('Rejected', { exact: true }).first()).toBeVisible();
      await expect(created.page.getByLabel('Approval timeline').getByText('Skipped', { exact: true })).toHaveCount(1);
    } finally {
      await created.context.close();
    }
  });

  test('finance-only customer return only offers rejection to Finance', async ({ browser }) => {
    const creator = await newContext(browser);
    let rmaUrl: string;
    try {
      await login(creator.page, 'customerservice@ogami.test');
      await creator.page.goto('/return-management/new');
      await creator.page.getByLabel('Type').selectOption('customer_return');
      const customer = creator.page.locator('select[name="customer_id"]');
      await expect.poll(() => customer.locator('option').count(), { timeout: 20_000 }).toBeGreaterThan(1);
      const customerOptions = await customer.locator('option').evaluateAll((nodes) =>
        nodes.map((node) => ({ value: node.value, text: node.textContent ?? '' })),
      );
      const customerChoice = customerOptions.find((option) => option.text.includes('Honda Philippines'));
      if (!customerChoice?.value) throw new Error('Honda customer was not available for the customer return scenario.');
      await customer.selectOption(customerChoice.value);
      await creator.page.getByLabel(/Finance-only credit/).check();
      await creator.page.getByLabel('Finance-only reason').fill('Commercial credit requested without physical goods returning.');
      await creator.page.getByLabel('Reason Code').selectOption({ index: 1 });
      await creator.page.getByRole('button', { name: 'Add Item', exact: true }).click();
      const product = creator.page.locator('select[name="items.0.product_id"]');
      await expect.poll(() => product.locator('option').count(), { timeout: 20_000 }).toBeGreaterThan(1);
      const productOptions = await product.locator('option').evaluateAll((nodes) =>
        nodes.map((node) => ({ value: node.value, text: node.textContent ?? '' })),
      );
      const productChoice = productOptions.find((option) => option.text.includes('WB-002'));
      if (!productChoice?.value) throw new Error('WB-002 was not available for the customer return scenario.');
      await product.selectOption(productChoice.value);
      await creator.page.getByLabel('Qty').fill('1');
      await creator.page.getByLabel('Unit price').fill('31.00');
      await creator.page.getByRole('button', { name: 'Create Return Request', exact: true }).click();
      await creator.page.waitForURL(/\/return-management\/(?!new$)[^/]+$/, { timeout: 20_000 });
      rmaUrl = creator.page.url();
      await creator.page.getByRole('button', { name: 'Submit for Approval', exact: true }).click();
      const submitDialog = creator.page.getByRole('dialog');
      await submitDialog.getByRole('button', { name: 'Submit', exact: true }).click();
      await expect(creator.page.getByText('Pending Approval', { exact: true }).first()).toBeVisible({ timeout: 20_000 });
    } finally {
      await creator.context.close();
    }

    const departmentHead = await newContext(browser);
    try {
      await login(departmentHead.page, 'depthead@ogami.test');
      await departmentHead.page.goto(rmaUrl);
      const approvalTimeline = departmentHead.page.getByLabel('Approval timeline');
      await expect(approvalTimeline.getByText('Step 1 — Finance officer', { exact: true })).toBeVisible();
      await expect(approvalTimeline.getByText('Pending', { exact: true })).toBeVisible();
      await expect(departmentHead.page.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
      await expect(departmentHead.page.getByRole('button', { name: 'Reject', exact: true })).toHaveCount(0);
      await expect(departmentHead.page.getByText('Pending Approval', { exact: true }).first()).toBeVisible();
    } finally {
      await departmentHead.context.close();
    }

    await rejectRma(browser, 'finance@ogami.test', rmaUrl);
  });

  test('PR to accepted GRN recovers one draft bill after automation actor loss', async ({ browser }) => {
    test.setTimeout(360_000);
    ({ poId, poNumber, vendorName } = await createCatalogPrToSentPo(browser));
    const warehouse = await newContext(browser);
    try {
      await login(warehouse.page, 'warehouse@ogami.test');
      await warehouse.page.goto('/inventory/grn');
      await expect(warehouse.page.getByText(poNumber, { exact: true })).toBeVisible({ timeout: 20_000 });
      await warehouse.page.getByText(poNumber, { exact: true }).click();
      await warehouse.page.waitForURL(/\/inventory\/grn\/[^/]+$/, { timeout: 20_000 });
      const grnId = warehouse.page.url().split('/').pop()!;
      const expectedReceipt = warehouse.page.getByText('Expected receipt', { exact: false });
      await expect(expectedReceipt).toBeVisible({ timeout: 20_000 });
      const bin = warehouse.page.locator('select[aria-label^="Bin "]');
      await expect.poll(() => bin.locator('option').count(), { timeout: 20_000 }).toBeGreaterThan(1);
      const binOptions = await bin.locator('option').evaluateAll((nodes) =>
        nodes.map((node) => ({ value: node.value, text: node.textContent ?? '' })),
      );
      const binValue = binOptions.find((option) => option.value !== '')?.value;
      if (!binValue) throw new Error('No usable warehouse bin was available.');
      await bin.selectOption(binValue);
      await warehouse.page.locator('input[aria-label^="Entered quantity "]').fill('5');
      const finalizeResponsePromise = warehouse.page.waitForResponse((response) =>
        response.request().method() === 'PATCH'
        && response.url().includes(`/api/v1/inventory/grn/${grnId}/finalize`),
      );
      await warehouse.page.getByRole('button', { name: 'Finalize receiving', exact: true }).click();
      const finalizeDialog = warehouse.page.getByRole('dialog');
      await finalizeDialog.getByRole('button', { name: 'Finalize GRN', exact: true }).click();
      const finalizeResponse = await finalizeResponsePromise;
      expect(finalizeResponse.status()).toBe(200);
      const finalizedGrn = await finalizeResponse.json() as {
        data?: {
          status?: string;
          bill?: { id: string } | null;
          items?: Array<{
            quantity_received: string;
            quantity_accepted: string;
            inspection?: { id: string; status: string } | null;
          }>;
        };
      };
      expect(finalizedGrn.data).toMatchObject({
        status: 'pending_qc',
        bill: null,
      });
      expect(finalizedGrn.data?.items?.[0]?.inspection?.status).toBe('draft');
      expect(Number(finalizedGrn.data?.items?.[0]?.quantity_received)).toBe(5);
      expect(Number(finalizedGrn.data?.items?.[0]?.quantity_accepted)).toBe(0);
      const pendingQc = warehouse.page.getByText(/Pending qc/i).first();
      await expect(pendingQc).toBeVisible({ timeout: 20_000 });

      const qcNumberLocator = warehouse.page.getByText(/QC-\d{6}-\d+/, { exact: false }).first();
      await expect(qcNumberLocator).toBeVisible({ timeout: 20_000 });
      const qcNumber = (await qcNumberLocator.innerText()).match(/QC-\d{6}-\d+/)?.[0] ?? '';
      if (!qcNumber) throw new Error('Incoming QC number was not readable.');

      const qc = await newContext(browser);
      let inspectionId: string;
      try {
        await login(qc.page, 'qc@ogami.test');
        await qc.page.goto('/quality/inspections');
        const inspectionListResponse = qc.page.waitForResponse((response) =>
          response.request().method() === 'GET'
          && response.url().includes('/api/v1/quality/inspections?'),
        );
        await qc.page.getByPlaceholder('Search inspection, product').fill(qcNumber);
        const inspectionResponse = await inspectionListResponse;
        const inspectionBody = await inspectionResponse.json() as {
          data?: Array<{
            id: string;
            inspection_number: string;
            inspection_mode: string;
            capture_method: string;
            sample_size: number;
            sampling_basis: string;
            source_quantity: string;
            source_uom_code: string;
          }>;
        };
        await expect(qc.page.getByText(qcNumber, { exact: true })).toBeVisible({ timeout: 20_000 });
        const inspection = inspectionBody.data?.find((row) => row.inspection_number === qcNumber);
        expect(inspection, `Filtered inspection ${qcNumber} was not in the API response`).toBeTruthy();
        expect(inspection!.inspection_mode).toBe('lot_checklist');
        expect(inspection!.capture_method).toBe('checklist');
        expect(inspection!.sampling_basis).toBe('lot');
        expect(inspection!.source_quantity).toBe('5.000');
        expect(inspection!.source_uom_code).toBe('kg');
        expect(inspection!.sample_size).toBeGreaterThan(0);
        inspectionId = inspection!.id;
        await qc.page.goto(`/quality/inspections/${inspectionId}`);
        await qc.page.waitForURL(/\/quality\/inspections\/[^/]+$/, { timeout: 20_000 });
        await submitLotChecklistInspection(qc.page, inspectionId, inspection!.sample_size);
      } finally {
        await qc.context.close();
      }

      const pendingGrnResponsePromise = warehouse.page.waitForResponse((response) =>
        response.request().method() === 'GET'
        && response.url().includes(`/api/v1/inventory/grn/${grnId}`),
      );
      await warehouse.page.reload({ waitUntil: 'domcontentloaded' });
      const pendingGrnResponse = await pendingGrnResponsePromise;
      const pendingGrn = await pendingGrnResponse.json() as {
        data?: {
          status?: string;
          bill?: { id: string } | null;
          items?: Array<{
            quantity_accepted: string;
            inspection?: { status: string } | null;
          }>;
        };
      };
      expect(pendingGrn.data).toMatchObject({
        status: 'pending_qc',
        bill: null,
      });
      expect(pendingGrn.data?.items?.[0]?.inspection?.status).toBe('awaiting_review');
      expect(Number(pendingGrn.data?.items?.[0]?.quantity_accepted)).toBe(0);

      const recoveryAdmin = await newContext(browser);
      await login(recoveryAdmin.page, 'admin@ogami.test');
      const originalAutomationRoles = await readAutomationActorRoles(recoveryAdmin.page);

      const reviewer = await newContext(browser);
      await login(reviewer.page, 'production@ogami.test');
      await reviewer.page.goto('/notifications');
      await expect(reviewer.page.getByText(`Inspection ${qcNumber} awaits your review`, { exact: true }))
        .toBeVisible({ timeout: 20_000 });
      await reviewer.page.goto(`/quality/inspections/${inspectionId}`);
      const approvePass = reviewer.page.getByRole('button', { name: 'Approve pass', exact: true });
      await expect(approvePass).toBeVisible({ timeout: 20_000 });
      const reviewResponsePromise = reviewer.page.waitForResponse((response) =>
        response.request().method() === 'PATCH'
        && response.url().includes(`/api/v1/quality/inspections/${inspectionId}/review`),
      );
      await writeAutomationActorRoles(
        recoveryAdmin.page,
        ['audit_missing_automation_actor'],
        'Exercise the accepted GRN listener recovery path in the isolated E2E database.',
      );
      try {
        await approvePass.click();
        const reviewDialog = reviewer.page.getByRole('dialog');
        await reviewDialog.getByRole('button', { name: 'Approve pass', exact: true }).click();
        const reviewResponse = await reviewResponsePromise;
        expect(reviewResponse.status()).toBe(200);
        const reviewedInspection = await reviewResponse.json() as {
          data?: {
            status?: string;
            reviewed_at?: string | null;
            result_authors?: Array<{ id: string; name: string }>;
            reviewer?: { id: string; name: string } | null;
          };
        };
        expect(reviewedInspection.data?.status).toBe('passed');
        expect(reviewedInspection.data?.reviewed_at).toBeTruthy();
        expect(reviewedInspection.data?.reviewer?.id)
          .not.toBe(reviewedInspection.data?.result_authors?.[0]?.id);
        await expect(reviewer.page.getByText('Passed', { exact: true }).first()).toBeVisible({ timeout: 20_000 });
      } finally {
        await writeAutomationActorRoles(
          recoveryAdmin.page,
          originalAutomationRoles,
          'Restore the original automation actor roles after the isolated E2E recovery scenario.',
        );
        await reviewer.context.close();
      }

      const runListResponse = await recoveryAdmin.page.request.get(
        `${BASE_URL}/api/v1/chain/listener-runs?attention=false&per_page=100&search=AutoCreateBillOnGrnAccepted`,
        { headers: await apiHeaders(recoveryAdmin.context) },
      );
      expect(runListResponse.status()).toBe(200);
      const runList = await runListResponse.json() as {
        data: { items: Array<{
          id: string;
          queue: { status: string };
          outcome: { status: string; code: string };
          resolution: { status: string };
          correlation: { outbox_id: string; replayed_from_id: string | null };
          chain_step?: { entity_type: string; entity_hash_id: string | null } | null;
        }> };
      };
      const failedRun = runList.data.items.find((run) => run.chain_step?.entity_hash_id === grnId);
      expect(failedRun, `Failed bill listener for GRN ${grnId} must be visible to recovery.`).toBeTruthy();
      expect(failedRun).toMatchObject({
        queue: { status: 'failed' },
        outcome: { status: 'failed' },
        resolution: { status: 'open' },
      });

      const grnBeforeRecovery = await recoveryAdmin.page.request.get(
        `${BASE_URL}/api/v1/inventory/grn/${grnId}`,
        { headers: await apiHeaders(recoveryAdmin.context) },
      );
      expect(grnBeforeRecovery.status()).toBe(200);
      const unbilledGrn = await grnBeforeRecovery.json() as {
        data?: { status?: string; bill?: { id: string } | null };
      };
      expect(unbilledGrn.data).toMatchObject({ status: 'accepted', bill: null });

      await recoveryAdmin.page.goto('/chains/recovery');
      await expect(recoveryAdmin.page.getByRole('heading', { name: 'Automation recovery' })).toBeVisible();
      const recoverySearch = recoveryAdmin.page.getByRole('textbox', { name: 'Search listener runs' });
      await recoverySearch.fill(failedRun!.correlation.outbox_id);
      const failedRow = recoveryAdmin.page.locator('article')
        .filter({ hasText: failedRun!.correlation.outbox_id })
        .filter({ hasText: 'failed' });
      await expect(failedRow).toBeVisible();
      const replayResponsePromise = recoveryAdmin.page.waitForResponse((response) =>
        response.request().method() === 'POST'
        && response.url().endsWith(`/api/v1/chain/listener-runs/${failedRun!.id}/replay`),
      );
      await failedRow.getByRole('button', { name: 'Replay', exact: true }).click();
      const replayDialog = recoveryAdmin.page.getByRole('dialog');
      await replayDialog.getByRole('button', { name: 'Queue replay', exact: true }).click();
      const replayResponse = await replayResponsePromise;
      expect(replayResponse.status()).toBe(202);
      await expect(recoveryAdmin.page.getByText('The selected listener replay was queued.')).toBeVisible();
      const replayResult = await replayResponse.json() as {
        data?: { source_run_id: string; replay_count: number };
      };
      expect(replayResult.data).toMatchObject({ source_run_id: failedRun!.id, replay_count: 1 });
      await expect(failedRow).toContainText('1 replay');

      await failedRow.getByRole('button', { name: 'Resolve', exact: true }).click();
      const resolveDialog = recoveryAdmin.page.getByRole('dialog');
      await resolveDialog.getByRole('textbox', { name: 'Resolution note' })
        .fill('Restored the automation actor and confirmed the supplier bill replay.');
      const resolveResponsePromise = recoveryAdmin.page.waitForResponse((response) =>
        response.request().method() === 'POST'
        && response.url().endsWith(`/api/v1/chain/listener-runs/${failedRun!.id}/resolve`),
      );
      await resolveDialog.getByRole('button', { name: 'Save resolution', exact: true }).click();
      const resolveResponse = await resolveResponsePromise;
      expect(resolveResponse.status()).toBe(200);
      await expect(recoveryAdmin.page.getByText('Resolution note saved.')).toBeVisible();

      const settledRunsResponse = await recoveryAdmin.page.request.get(
        `${BASE_URL}/api/v1/chain/listener-runs?attention=false&per_page=100&search=${failedRun!.correlation.outbox_id}`,
        { headers: await apiHeaders(recoveryAdmin.context) },
      );
      expect(settledRunsResponse.status()).toBe(200);
      const settledRuns = await settledRunsResponse.json() as { data: { items: typeof runList.data.items } };
      const resolvedSource = settledRuns.data.items.find((run) => run.id === failedRun!.id);
      const completedReplay = settledRuns.data.items.find((run) => run.correlation.replayed_from_id === failedRun!.id);
      expect(resolvedSource).toMatchObject({
        queue: { status: 'failed' },
        resolution: { status: 'resolved' },
      });
      expect(completedReplay).toMatchObject({
        queue: { status: 'completed' },
        outcome: { status: 'completed', code: 'supplier_bill_staged' },
      });
      expect(completedReplay?.correlation.outbox_id).toBe(failedRun!.correlation.outbox_id);

      const grnAfterRecovery = await recoveryAdmin.page.request.get(
        `${BASE_URL}/api/v1/inventory/grn/${grnId}`,
        { headers: await apiHeaders(recoveryAdmin.context) },
      );
      expect(grnAfterRecovery.status()).toBe(200);
      const billedGrn = await grnAfterRecovery.json() as {
        data?: {
          status?: string;
          bill?: { id: string; status: string; total_amount: string } | null;
        };
      };
      expect(billedGrn.data?.status).toBe('accepted');
      expect(billedGrn.data?.bill?.status).toBe('draft');

      await recoveryAdmin.context.close();
      const billLink = warehouse.page.getByRole('link', { name: /^BILL-/ }).first();
      const acceptedGrnResponsePromise = warehouse.page.waitForResponse((response) =>
        response.request().method() === 'GET'
        && response.url().includes(`/api/v1/inventory/grn/${grnId}`),
      );
      await warehouse.page.reload({ waitUntil: 'domcontentloaded' });
      const acceptedGrnResponse = await acceptedGrnResponsePromise;
      const acceptedGrn = await acceptedGrnResponse.json() as {
        data?: {
          status?: string;
          bill?: { id: string; status: string; total_amount: string } | null;
          items?: Array<{
            quantity_received: string;
            quantity_accepted: string;
            inspection?: { status: string } | null;
          }>;
        };
      };
      await expect(billLink).toBeVisible({ timeout: 20_000 });
      billUrl = await billLink.getAttribute('href') ?? '';
      if (!billUrl) throw new Error('The accepted GRN did not expose its generated bill link.');
      expect(acceptedGrn.data?.status).toBe('accepted');
      expect(acceptedGrn.data?.items?.[0]?.inspection?.status).toBe('passed');
      expect(acceptedGrn.data?.bill?.status).toBe('draft');
      expect(acceptedGrn.data?.bill?.id).toBe(billUrl.split('/').pop());
      expect(Number(acceptedGrn.data?.items?.[0]?.quantity_received)).toBe(5);
      expect(Number(acceptedGrn.data?.items?.[0]?.quantity_accepted)).toBe(5);
    } finally {
      await warehouse.context.close();
    }
  });

  test('post supplier bill and approve its partial payment', async ({ browser }) => {
    test.setTimeout(300_000);
    expect(billUrl).not.toBe('');
    const partialPaymentReference = `LIVE-E2E-PARTIAL-${Date.now()}`;
    const finance = await newContext(browser);
    try {
      await login(finance.page, 'finance@ogami.test');
      await finance.page.goto(billUrl);
      const postButton = finance.page.getByRole('button', { name: 'Post bill', exact: true });
      const partialStatus = finance.page.getByText('Partially paid', { exact: true }).last();
      const unpaidStatus = finance.page.getByText('Unpaid', { exact: true }).last();
      await expect.poll(async () =>
        Number(await postButton.isVisible()) + Number(await partialStatus.isVisible()) + Number(await unpaidStatus.isVisible()),
      { timeout: 20_000 }).toBeGreaterThan(0);
      if (await postButton.count() > 0 && await postButton.isVisible()) {
        await postButton.click();
        const postDialog = finance.page.getByRole('dialog');
        await postDialog.getByRole('button', { name: 'Post bill', exact: true }).click();
        await expect(finance.page.getByText('Unpaid', { exact: true }).last()).toBeVisible({ timeout: 20_000 });
      } else {
        if (await partialStatus.count() > 0 && await partialStatus.isVisible()) {
          await expect(partialStatus).toBeVisible({ timeout: 20_000 });
        } else {
          await expect(unpaidStatus).toBeVisible({ timeout: 20_000 });
        }
      }

      await finance.page.getByRole('button', { name: 'Record payment', exact: true }).click();
      const payment = finance.page.getByRole('dialog');
      const cash = payment.getByLabel('Cash account');
      await expect.poll(() => cash.locator('option').count(), { timeout: 20_000 }).toBeGreaterThan(1);
      const cashOptions = await cash.locator('option').evaluateAll((nodes) =>
        nodes.map((node) => ({ value: node.value, text: node.textContent ?? '' })),
      );
      const cashChoice = cashOptions.find((option) => /^1010\b/.test(option.text))
        ?? cashOptions.find((option) => /^1020\b/.test(option.text));
      if (!cashChoice?.value) throw new Error('No concrete cash or bank account was available.');
      await cash.selectOption(cashChoice.value);
      await payment.getByLabel('Payment date').fill(localIsoDate());
      await payment.getByLabel(/Amount \(max/).fill('1.00');
      await payment.getByLabel('Method').selectOption('bank_transfer');
      await payment.getByLabel('Reference no.').fill(partialPaymentReference);
      const billHash = billUrl.split('/').pop();
      if (!billHash) throw new Error('Bill detail link has no record id.');
      const recordResponsePromise = finance.page.waitForResponse((response) =>
        response.request().method() === 'POST'
        && response.url().includes(`/api/v1/bills/${billHash}/payments`),
      );
      await payment.getByRole('button', { name: 'Record', exact: true }).click();
      const recordResponse = await recordResponsePromise;
      expect(recordResponse.status()).toBe(201);
      const createdPayment = (await recordResponse.json() as {
        data: { id: string; status: string; approval_records: Array<{ step_order: number; role_slug: string; action: string }> };
      }).data;
      expect(createdPayment.status).toBe('pending_approval');
      const originalRequest = recordResponse.request();
      const idempotencyKey = (await originalRequest.allHeaders())['idempotency-key'];
      if (!idempotencyKey) throw new Error('Payment request omitted its idempotency key.');
      const originalPayload = originalRequest.postDataJSON() as Record<string, unknown>;
      const beforeChangedReplay = await readBillState(finance.page, billUrl);
      const changedReplay = await finance.page.request.post(recordResponse.url(), {
        headers: { ...(await apiHeaders(finance.context)), 'Idempotency-Key': idempotencyKey },
        data: { ...originalPayload, reference_number: `${partialPaymentReference}-corrected` },
      });
      expect(changedReplay.status()).toBe(422);
      expect((await changedReplay.json() as { message?: string }).message).toBe(
        'The idempotency key was already used with a different payment payload.',
      );
      expect(await readBillState(finance.page, billUrl)).toEqual(beforeChangedReplay);
      const prematureVoid = await finance.page.request.post(
        `${BASE_URL}/api/v1/bills/${billHash}/payments/${createdPayment.id}/void`,
        { headers: await apiHeaders(finance.context), data: { reason: 'Cannot void a pending request.' } },
      );
      expect(prematureVoid.status()).toBe(422);
      expect((await prematureVoid.json() as { message?: string }).message).toBe('Only posted payments can be voided.');
      expect(await readBillState(finance.page, billUrl)).toEqual(beforeChangedReplay);
      expect(createdPayment.approval_records.map(({ step_order, role_slug, action }) => ({ step_order, role_slug, action })).sort((a, b) => a.step_order - b.step_order)).toEqual([
        { step_order: 1, role_slug: 'finance_officer', action: 'pending' },
        { step_order: 2, role_slug: 'vice_president', action: 'pending' },
      ]);
      await finance.page.reload({ waitUntil: 'domcontentloaded' });
      await expect(finance.page.getByText(new RegExp(partialPaymentReference))).toBeVisible({ timeout: 20_000 });
      const beforeRejectedApprovals = await readBillState(finance.page, billUrl);
      const ownApproval = await finance.page.request.post(
        `${BASE_URL}/api/v1/bills/${billHash}/payments/${createdPayment.id}/approve`,
        { headers: await apiHeaders(finance.context), data: {} },
      );
      expect(ownApproval.status()).toBe(403);
      expect((await ownApproval.json() as { message?: string }).message).toBe(
        'You cannot act on a record you submitted.',
      );
      expect(await readBillState(finance.page, billUrl)).toEqual(beforeRejectedApprovals);

      const outOfOrderActor = await newContext(browser);
      try {
        await login(outOfOrderActor.page, 'vp@ogami.test');
        const outOfOrderApproval = await outOfOrderActor.page.request.post(
          `${BASE_URL}/api/v1/bills/${billHash}/payments/${createdPayment.id}/approve`,
          { headers: await apiHeaders(outOfOrderActor.context), data: {} },
        );
        expect(outOfOrderApproval.status()).toBe(403);
        expect((await outOfOrderApproval.json() as { message?: string }).message).toBe(
          "Only users with role 'finance_officer' can approve this step.",
        );
        expect(await readBillState(outOfOrderActor.page, billUrl)).toEqual(beforeRejectedApprovals);
      } finally {
        await outOfOrderActor.context.close();
      }

      const unauthorizedActor = await newContext(browser);
      try {
        await login(unauthorizedActor.page, 'warehouse@ogami.test');
        const deniedBillRead = await unauthorizedActor.page.request.get(
          `${BASE_URL}/api/v1/bills/${billHash}`,
          { headers: await apiHeaders(unauthorizedActor.context) },
        );
        expect(deniedBillRead.status()).toBe(403);
        const deniedPaymentAction = await unauthorizedActor.page.request.post(
          `${BASE_URL}/api/v1/bills/${billHash}/payments/${createdPayment.id}/approve`,
          { headers: await apiHeaders(unauthorizedActor.context), data: {} },
        );
        expect(deniedPaymentAction.status()).toBe(403);
        expect(await readBillState(finance.page, billUrl)).toEqual(beforeRejectedApprovals);
      } finally {
        await unauthorizedActor.context.close();
      }
    } finally {
      await finance.context.close();
    }

    await approvePaymentRequest(browser, 'finance2@ogami.test', billUrl, partialPaymentReference, false);
    await approvePaymentRequest(browser, 'vp@ogami.test', billUrl, partialPaymentReference, true);

    const settledView = await newContext(browser);
    try {
      await login(settledView.page, 'finance@ogami.test');
      await settledView.page.goto(billUrl);
      await expect(settledView.page.getByText('Partially paid', { exact: true }).first()).toBeVisible({ timeout: 20_000 });
      const billHash = billUrl.split('/').pop();
      if (!billHash) throw new Error('Bill detail link has no record id.');
      const billResponse = await settledView.page.request.get(`${BASE_URL}/api/v1/bills/${billHash}`, {
        headers: await apiHeaders(settledView.context),
      });
      expect(billResponse.status()).toBe(200);
      const billState = (await billResponse.json() as {
        data: {
          status: string;
          total_amount: string;
          amount_paid: string;
          balance: string;
          payments: Array<{
            id: string;
            reference_number: string;
            status: string;
            journal_entry_id: string | null;
          }>;
        };
      }).data;
      expect(billState.status).toBe('partial');
      expect(billState.amount_paid).toBe('1.00');
      expect(Math.round(Number(billState.balance) * 100)).toBe(
        Math.round(Number(billState.total_amount) * 100) - 100,
      );
      const payments = billState.payments.filter((payment) => payment.reference_number === partialPaymentReference);
      expect(payments).toHaveLength(1);
      const postedPayment = payments[0];
      expect(postedPayment.status).toBe('posted');
      expect(postedPayment.journal_entry_id).toBeTruthy();
      const journalResponse = await settledView.page.request.get(
        `${BASE_URL}/api/v1/journal-entries/${postedPayment.journal_entry_id}`,
        { headers: await apiHeaders(settledView.context) },
      );
      expect(journalResponse.status()).toBe(200);
      const journal = (await journalResponse.json() as {
        data: {
          status: string;
          reference_type: string;
          reference_id: string;
          total_debit: string;
          total_credit: string;
          lines: Array<{ debit: string; credit: string }>;
        };
      }).data;
      expect(journal.status).toBe('posted');
      expect(journal.reference_type).toBe('bill_payment');
      expect(journal.reference_id).toBe(postedPayment.id);
      expect(journal.total_debit).toBe('1.00');
      expect(journal.total_credit).toBe('1.00');
      expect(journal.lines.reduce((sum, line) => sum + Math.round(Number(line.debit) * 100), 0)).toBe(100);
      expect(journal.lines.reduce((sum, line) => sum + Math.round(Number(line.credit) * 100), 0)).toBe(100);

      const beforeSelfVoid = await readBillState(settledView.page, billUrl);
      const selfVoid = await settledView.page.request.post(
        `${BASE_URL}/api/v1/bills/${billHash}/payments/${postedPayment.id}/void`,
        { headers: await apiHeaders(settledView.context), data: { reason: 'Payment maker cannot void the posting.' } },
      );
      expect(selfVoid.status()).toBe(403);
      expect((await selfVoid.json() as { message?: string }).message).toBe(
        'A different authorized user must void this payment.',
      );
      expect(await readBillState(settledView.page, billUrl)).toEqual(beforeSelfVoid);
      await expect(settledView.page.getByRole('button', { name: 'Void', exact: true })).toHaveCount(0);
      await expect(settledView.page.getByText('A different authorized user must void this payment.', { exact: true })).toBeVisible();

      const voidReason = 'Bank transfer was rejected; issuing a replacement payment.';
      const voidActor = await newContext(browser);
      try {
        await login(voidActor.page, 'finance2@ogami.test');
        await voidActor.page.goto(billUrl);
        const voidButton = voidActor.page.getByRole('button', { name: 'Void', exact: true });
        await expect(voidButton).toBeVisible();
        const actorResponse = await voidActor.page.request.get(`${BASE_URL}/api/v1/auth/user`, {
          headers: await apiHeaders(voidActor.context),
        });
        expect(actorResponse.status()).toBe(200);
        const voidActorId = ((await actorResponse.json()) as { data: { id: string } }).data.id;
        const voidDialog = voidActor.page.getByRole('dialog');
        await voidButton.click();
        await voidDialog.getByRole('textbox', { name: 'Void reason' }).fill(voidReason);
        const voidResponsePromise = voidActor.page.waitForResponse((response) =>
          response.request().method() === 'POST'
          && response.url().endsWith(`/api/v1/bills/${billHash}/payments/${postedPayment.id}/void`),
        );
        await voidDialog.getByRole('button', { name: 'Void payment', exact: true }).click();
        const voidResponse = await voidResponsePromise;
        expect(voidResponse.status()).toBe(200);
        const voidedPayment = (await voidResponse.json() as {
          data: {
            status: string;
            voided_at: string | null;
            voided_by: { id: string } | null;
            void_reason: string | null;
            void_reversal_journal_entry: { id: string; status: string } | null;
          };
        }).data;
        expect(voidedPayment).toMatchObject({
          status: 'voided',
          voided_at: expect.any(String),
          voided_by: { id: voidActorId },
          void_reason: voidReason,
          void_reversal_journal_entry: { status: 'posted' },
        });
        expect(voidedPayment.void_reversal_journal_entry?.id).toBeTruthy();
        await expect(voidActor.page.getByText('Payment voided and the bill balance was recalculated.')).toBeVisible();

        const voidedBillState = await readBillState(voidActor.page, billUrl);
        expect(voidedBillState.status).toBe('unpaid');
        expect(voidedBillState.amount_paid).toBe('0.00');
        expect(voidedBillState.balance).toBe(voidedBillState.total_amount);
        const voidedRecord = voidedBillState.payments.find((payment) => payment.id === postedPayment.id);
        expect(voidedRecord).toMatchObject({
          status: 'voided',
          journal_entry_id: postedPayment.journal_entry_id,
          void_reason: voidReason,
          void_reversal_journal_entry: { id: voidedPayment.void_reversal_journal_entry?.id, status: 'posted' },
        });
        expect(voidedRecord?.voided_at).toBeTruthy();
        expect(voidedRecord?.voided_by?.id).toBe(voidActorId);

        const reversalId = voidedPayment.void_reversal_journal_entry!.id;
        const reversalResponse = await voidActor.page.request.get(
          `${BASE_URL}/api/v1/journal-entries/${reversalId}`,
          { headers: await apiHeaders(voidActor.context) },
        );
        expect(reversalResponse.status()).toBe(200);
        const reversal = (await reversalResponse.json() as {
          data: { status: string; reference_type: string; total_debit: string; total_credit: string; lines: Array<{ debit: string; credit: string }> };
        }).data;
        expect(reversal.status).toBe('posted');
        expect(reversal.reference_type).toBe('journal_entry_reversal');
        expect(reversal.total_debit).toBe('1.00');
        expect(reversal.total_credit).toBe('1.00');
        expect(reversal.lines.reduce((sum, line) => sum + Math.round(Number(line.debit) * 100), 0)).toBe(100);
        expect(reversal.lines.reduce((sum, line) => sum + Math.round(Number(line.credit) * 100), 0)).toBe(100);

        const duplicateVoid = await voidActor.page.request.post(
          `${BASE_URL}/api/v1/bills/${billHash}/payments/${postedPayment.id}/void`,
          { headers: await apiHeaders(voidActor.context), data: { reason: 'Do not reverse this payment twice.' } },
        );
        expect(duplicateVoid.status()).toBe(422);
        expect((await duplicateVoid.json() as { message?: string }).message).toBe('Only posted payments can be voided.');
        expect(await readBillState(voidActor.page, billUrl)).toEqual(voidedBillState);
      } finally {
        await voidActor.context.close();
      }
    } finally {
      await settledView.context.close();
    }
  });

  test('cancels an unpaid bill and routes its pending payment to rejection', async ({ browser }) => {
    test.setTimeout(420_000);
    billUrl = await createDraftBillFromFreshGrn(browser);
    const reference = 'LIVE-E2E-PENDING-CANCEL-' + Date.now();
    const maker = await newContext(browser);
    const secondFinance = await newContext(browser);
    const auditAdmin = await newContext(browser);
    let billHash = '';
    let paymentId = '';
    let uiCancelStatus: number | undefined;
    let directCancelStatus: number | undefined;
    let cancelToastVisible: boolean | undefined;
    let cancelButtonEnabled: boolean | undefined;
    let cancellationStateCorrect: boolean | undefined;
    let cancellationReversalCorrect = false;
    let cancellationAuditCorrect: boolean | undefined;
    let paymentQueueVisible: boolean | undefined;
    let cancelledApproveStatus: number | undefined;
    let cancelledApproveMessage: string | undefined;
    let approveAttemptLeftStateUnchanged: boolean | undefined;
    let approveVisibleOnCancelledBill: boolean | undefined;
    let recoveryNoteVisible: boolean | undefined;
    let rejectButtonVisible: boolean | undefined;
    let cancelledRejectCopyVisible = false;
    let rejectionWasViaUi = false;
    let finalStateCorrect: boolean | undefined;
    let rejectionAuditCorrect: boolean | undefined;
    let cleanupFailure: string | undefined;

    try {
      await login(maker.page, 'finance@ogami.test');
      await maker.page.goto(billUrl);
      const billHashFromUrl = billUrl.split('/').pop();
      if (!billHashFromUrl) throw new Error('Bill detail link has no record id.');
      const postResponsePromise = maker.page.waitForResponse((response) =>
        response.request().method() === 'POST'
        && response.url().endsWith(`/api/v1/bills/${billHashFromUrl}/post`),
      );
      await maker.page.getByRole('button', { name: 'Post bill', exact: true }).click();
      await maker.page.getByRole('dialog').getByRole('button', { name: 'Post bill', exact: true }).click();
      const postResponse = await postResponsePromise;
      expect(postResponse.status(), await postResponse.text()).toBe(200);
      expect(await readBillState(maker.page, billUrl)).toMatchObject({
        status: 'unpaid',
        amount_paid: '0.00',
        journal_entry: { status: 'posted' },
      });
      await expect(maker.page.getByRole('button', { name: 'Record payment', exact: true })).toBeVisible();
      const startingState = await readBillState(maker.page, billUrl);
      expect(startingState.status).toBe('unpaid');
      expect(startingState.amount_paid).toBe('0.00');
      expect(startingState.balance).toBe(startingState.total_amount);

      billHash = billUrl.split('/').pop() ?? '';
      if (!billHash) throw new Error('Bill detail link has no record id.');

      // Keep an authorized Finance page stale across request creation and first approval.
      await login(secondFinance.page, 'finance2@ogami.test');
      await secondFinance.page.goto(billUrl);
      await expect(secondFinance.page.getByRole('button', { name: 'Cancel', exact: true })).toBeVisible();

      await maker.page.getByRole('button', { name: 'Record payment', exact: true }).click();
      const form = maker.page.getByRole('dialog');
      const cash = form.getByLabel('Cash account');
      await expect.poll(() => cash.locator('option').count(), { timeout: 20_000 }).toBeGreaterThan(1);
      const cashOptions = await cash.locator('option').evaluateAll((nodes) =>
        nodes.map((node) => ({ value: node.value, text: node.textContent ?? '' })),
      );
      const cashChoice = cashOptions.find((option) => /^1010\b/.test(option.text))
        ?? cashOptions.find((option) => /^1020\b/.test(option.text));
      if (!cashChoice?.value) throw new Error('No concrete cash or bank account was available.');
      await cash.selectOption(cashChoice.value);
      await form.getByLabel('Payment date').fill(localIsoDate());
      await form.getByLabel(/Amount \(max/).fill('1.00');
      await form.getByLabel('Method').selectOption('bank_transfer');
      await form.getByLabel('Reference no.').fill(reference);

      const paymentResponsePromise = maker.page.waitForResponse((response) =>
        response.request().method() === 'POST'
        && response.url().endsWith('/api/v1/bills/' + billHash + '/payments'),
      );
      await form.getByRole('button', { name: 'Record', exact: true }).click();
      const paymentResponse = await paymentResponsePromise;
      expect(paymentResponse.status()).toBe(201);
      const payment = (await paymentResponse.json() as {
        data: { id: string; status: string };
      }).data;
      paymentId = payment.id;
      expect(payment.status).toBe('pending_approval');
      const createdBillState = await readBillState(maker.page, billUrl);
      expect(createdBillState).toMatchObject({
        status: 'unpaid',
        amount_paid: '0.00',
        balance: createdBillState.total_amount,
      });
      expect(createdBillState.payments.find((entry) => entry.id === payment.id)).toMatchObject({
        status: 'pending_approval',
        journal_entry_id: null,
        approval_records: [
          { step_order: 1, role_slug: 'finance_officer', action: 'pending', approver: null },
          { step_order: 2, role_slug: 'vice_president', action: 'pending', approver: null },
        ],
      });
      const paymentTimeline = maker.page.getByLabel('Approval timeline');
      await expect(paymentTimeline.getByText('Step 1 — Finance officer', { exact: true })).toBeVisible();
      await expect(paymentTimeline.getByText('Step 2 — Vice president', { exact: true })).toBeVisible();
      await expect(maker.page.getByText(new RegExp(reference))).toBeVisible({ timeout: 20_000 });

      await approvePaymentRequest(browser, 'finance2@ogami.test', billUrl, reference, false);
      await maker.page.reload({ waitUntil: 'domcontentloaded' });
      const beforeCancel = await readBillState(maker.page, billUrl);
      const pendingPayment = beforeCancel.payments.find((entry) => entry.id === payment.id);
      expect(beforeCancel.status).toBe('unpaid');
      expect(beforeCancel.amount_paid).toBe('0.00');
      expect(beforeCancel.balance).toBe(beforeCancel.total_amount);
      expect(beforeCancel.journal_entry?.status).toBe('posted');
      expect(pendingPayment).toMatchObject({
        status: 'pending_approval',
        journal_entry_id: null,
        approval_records: [
          { step_order: 1, role_slug: 'finance_officer', action: 'approved', approver: { id: expect.any(String) } },
          { step_order: 2, role_slug: 'vice_president', action: 'pending', approver: null },
        ],
      });

      cancelButtonEnabled = await maker.page
        .getByRole('button', { name: 'Cancel', exact: true })
        .isEnabled();
      const cancelUrl = BASE_URL + '/api/v1/bills/' + billHash + '/cancel';
      await secondFinance.page.getByRole('button', { name: 'Cancel', exact: true }).click();
      const dialog = secondFinance.page.getByRole('dialog');
      await expect(dialog.getByText('This will reverse the associated journal entry. This action cannot be undone.')).toBeVisible();
      const uiResponsePromise = secondFinance.page.waitForResponse((response) =>
        response.request().method() === 'PATCH' && response.url() === cancelUrl,
      );
      const directResponsePromise = maker.page.request.patch(cancelUrl, {
        headers: await apiHeaders(maker.context),
        data: {},
      });
      const clickPromise = dialog.getByRole('button', { name: 'Cancel bill', exact: true }).click();
      const [uiResponse, directResponse] = await Promise.all([uiResponsePromise, directResponsePromise]);
      await clickPromise;
      uiCancelStatus = uiResponse.status();
      directCancelStatus = directResponse.status();
      const uiCancelBody = await uiResponse.json() as { data?: { status?: string } };
      const directCancelBody = await directResponse.json() as { data?: { status?: string } };
      cancelToastVisible = await secondFinance.page
        .getByText('Bill cancelled.', { exact: true })
        .isVisible()
        .catch(() => false);

      const afterCancel = await readBillState(maker.page, billUrl);
      const paymentAfterCancel = afterCancel.payments.find((entry) => entry.id === payment.id);
      cancellationStateCorrect = uiCancelBody.data?.status === 'cancelled'
        && directCancelBody.data?.status === 'cancelled'
        && afterCancel.status === 'cancelled'
        && afterCancel.amount_paid === '0.00'
        && afterCancel.balance === '0.00'
        && afterCancel.journal_entry?.status === 'reversed'
        && paymentAfterCancel?.status === 'pending_approval'
        && paymentAfterCancel.journal_entry_id === null
        && paymentAfterCancel.approval_records[0]?.action === 'approved'
        && paymentAfterCancel.approval_records[1]?.action === 'pending';

      await login(auditAdmin.page, 'admin@ogami.test');
      type AuditEntry = {
        action: string;
        old_values: Record<string, unknown> | null;
        new_values: Record<string, unknown> | null;
        user: { email: string } | null;
      };
      const billAuditResponse = await auditAdmin.page.request.get(
        BASE_URL + '/api/v1/admin/audit-logs/entity?' + new URLSearchParams({
          model_type: 'Bill',
          model_id: billHash,
          per_page: '100',
        }).toString(),
        { headers: await apiHeaders(auditAdmin.context) },
      );
      const billAudit = billAuditResponse.status() === 200
        ? ((await billAuditResponse.json()) as { data?: AuditEntry[] }).data ?? []
        : [];
      const cancellationEvents = billAudit.filter((entry) =>
        entry.action === 'updated' && entry.new_values?.status === 'cancelled',
      );
      const cancellationEvent = cancellationEvents[0];
      cancellationAuditCorrect = billAuditResponse.status() === 200
        && cancellationEvents.length === 1
        && cancellationEvent?.old_values?.status === 'unpaid'
        && ['finance@ogami.test', 'finance2@ogami.test'].includes(cancellationEvent?.user?.email ?? '');
      expect(cancellationAuditCorrect).toBe(true);

      if (afterCancel.journal_entry?.id) {
        const sourceResponse = await maker.page.request.get(
          BASE_URL + '/api/v1/journal-entries/' + afterCancel.journal_entry.id,
          { headers: await apiHeaders(maker.context) },
        );
        if (sourceResponse.status() === 200) {
          const source = (await sourceResponse.json() as {
            data: { status: string; reversed_by_entry_id: string | null };
          }).data;
          if (source.status === 'reversed' && source.reversed_by_entry_id) {
            const reversalResponse = await maker.page.request.get(
              BASE_URL + '/api/v1/journal-entries/' + source.reversed_by_entry_id,
              { headers: await apiHeaders(maker.context) },
            );
            if (reversalResponse.status() === 200) {
              const reversal = (await reversalResponse.json() as {
                data: {
                  status: string;
                  reference_type: string;
                  total_debit: string;
                  total_credit: string;
                  lines: Array<{ debit: string; credit: string }>;
                };
              }).data;
              const totalCents = Math.round(Number(afterCancel.total_amount) * 100);
              cancellationReversalCorrect = reversal.status === 'posted'
                && reversal.reference_type === 'journal_entry_reversal'
                && Math.round(Number(reversal.total_debit) * 100) === totalCents
                && Math.round(Number(reversal.total_credit) * 100) === totalCents
                && reversal.lines.reduce((sum, line) => sum + Math.round(Number(line.debit) * 100), 0) === totalCents
                && reversal.lines.reduce((sum, line) => sum + Math.round(Number(line.credit) * 100), 0) === totalCents;
            }
          }
        }
      }

      expect(uiCancelStatus).toBe(200);
      expect(directCancelStatus).toBe(200);
      expect(cancelToastVisible).toBe(true);
      expect(cancelButtonEnabled).toBe(true);
      expect(cancellationStateCorrect).toBe(true);
      expect(cancellationReversalCorrect).toBe(true);

      const vicePresident = await newContext(browser);
      try {
        await login(vicePresident.page, 'vp@ogami.test');
        const boardResponsePromise = vicePresident.page.waitForResponse((response) =>
          response.request().method() === 'GET'
          && response.url().includes('/api/v1/approvals/board'),
        );
        await vicePresident.page.goto('/approvals');
        const boardResponse = await boardResponsePromise;
        const board = await boardResponse.json() as {
          data?: { my_action?: Array<{ type: string; number: string; link: string }> };
        };
        const queueCard = vicePresident.page
          .getByRole('button', { name: new RegExp(reference) })
          .filter({ hasText: 'Open record to act' });
        const queueCardVisible = await queueCard.isVisible().catch(() => false);
        paymentQueueVisible = boardResponse.status() === 200
          && Boolean(board.data?.my_action?.some((item) =>
            item.type === 'bill_payment' && item.number === reference && item.link === billUrl,
          ))
          && queueCardVisible;
        if (queueCardVisible) {
          await queueCard.click();
          await expect(vicePresident.page).toHaveURL(new URL(billUrl, BASE_URL).toString());
        } else {
          await vicePresident.page.goto(billUrl);
        }

        const rejectButton = vicePresident.page.getByRole('button', { name: 'Reject', exact: true });
        await rejectButton.waitFor({ state: 'visible', timeout: 10_000 }).catch(() => undefined);
        rejectButtonVisible = await rejectButton.isVisible().catch(() => false);
        approveVisibleOnCancelledBill = await vicePresident.page
          .getByRole('button', { name: 'Approve', exact: true })
          .isVisible()
          .catch(() => false);
        recoveryNoteVisible = await vicePresident.page
          .getByText('Reject this request; the cancelled bill cannot be paid.', { exact: true })
          .isVisible()
          .catch(() => false);

        const beforeApproveAttempt = await readBillState(vicePresident.page, billUrl);
        const approveResponse = await vicePresident.page.request.post(
          BASE_URL + '/api/v1/bills/' + billHash + '/payments/' + payment.id + '/approve',
          { headers: await apiHeaders(vicePresident.context), data: {} },
        );
        cancelledApproveStatus = approveResponse.status();
        cancelledApproveMessage = ((await approveResponse.json()) as { message?: string }).message ?? '';
        const afterApproveAttempt = await readBillState(vicePresident.page, billUrl);
        const beforePendingPayment = beforeApproveAttempt.payments.find((entry) => entry.id === payment.id);
        const afterPendingPayment = afterApproveAttempt.payments.find((entry) => entry.id === payment.id);
        approveAttemptLeftStateUnchanged = beforeApproveAttempt.status === afterApproveAttempt.status
          && beforeApproveAttempt.amount_paid === afterApproveAttempt.amount_paid
          && beforeApproveAttempt.balance === afterApproveAttempt.balance
          && beforeApproveAttempt.journal_entry?.status === afterApproveAttempt.journal_entry?.status
          && beforePendingPayment?.status === afterPendingPayment?.status
          && beforePendingPayment?.journal_entry_id === afterPendingPayment?.journal_entry_id
          && beforePendingPayment?.approval_records.map((row) => row.action).join(',') ===
            afterPendingPayment?.approval_records.map((row) => row.action).join(',');

        expect(cancelledApproveStatus).toBe(422);
        expect(cancelledApproveMessage).toBe('Cannot approve a payment on a cancelled bill.');
        expect(approveAttemptLeftStateUnchanged).toBe(true);

        if (rejectButtonVisible) {
          await rejectButton.click();
          const rejectDialog = vicePresident.page.getByRole('dialog');
          cancelledRejectCopyVisible = await rejectDialog
            .getByText('Rejecting this request will close the pending payment while keeping the bill cancelled.', { exact: true })
            .isVisible()
            .catch(() => false);
          await rejectDialog.getByLabel('Rejection reason').fill('Bill cancellation requested while payment awaited approval.');
          const rejectResponsePromise = vicePresident.page.waitForResponse((response) =>
            response.request().method() === 'POST'
            && response.url().includes('/api/v1/bills/' + billHash + '/payments/')
            && response.url().endsWith('/reject'),
          );
          await rejectDialog.getByRole('button', { name: 'Reject payment', exact: true }).click();
          const rejectResponse = await rejectResponsePromise;
          rejectionWasViaUi = rejectResponse.status() === 200;
          if (!rejectionWasViaUi) {
            const cleanupResponse = await vicePresident.page.request.post(
              BASE_URL + '/api/v1/bills/' + billHash + '/payments/' + payment.id + '/reject',
              {
                headers: await apiHeaders(vicePresident.context),
                data: { remarks: 'E2E cleanup after the rejection control failed.' },
              },
            );
            if (cleanupResponse.status() !== 200) throw new Error('Could not settle the pending payment in E2E cleanup.');
          }
        } else {
          const cleanupResponse = await vicePresident.page.request.post(
            BASE_URL + '/api/v1/bills/' + billHash + '/payments/' + payment.id + '/reject',
            {
              headers: await apiHeaders(vicePresident.context),
              data: { remarks: 'E2E cleanup after the rejection control was missing.' },
            },
          );
          if (cleanupResponse.status() !== 200) throw new Error('Could not settle the pending payment in E2E cleanup.');
        }

        const afterReject = await readBillState(vicePresident.page, billUrl);
        const rejectedPayment = afterReject.payments.find((entry) => entry.id === payment.id);
        finalStateCorrect = afterReject.status === 'cancelled'
          && afterReject.amount_paid === '0.00'
          && afterReject.balance === '0.00'
          && afterReject.journal_entry?.status === 'reversed'
          && rejectedPayment?.status === 'rejected'
          && rejectedPayment.journal_entry_id === null
          && rejectedPayment.approval_records[0]?.action === 'approved'
          && rejectedPayment.approval_records[1]?.action === 'rejected';

        const completedBoardPromise = vicePresident.page.waitForResponse((response) =>
          response.request().method() === 'GET'
          && response.url().includes('/api/v1/approvals/board'),
        );
        await vicePresident.page.goto('/approvals');
        const completedBoardResponse = await completedBoardPromise;
        const completedBoard = await completedBoardResponse.json() as {
          data?: { my_action?: Array<{ type: string; number: string; link: string }> };
        };
        const paymentStillQueued = completedBoard.data?.my_action?.some((item) =>
          item.type === 'bill_payment' && item.number === reference,
        ) ?? false;
        finalStateCorrect = finalStateCorrect && completedBoardResponse.status() === 200 && !paymentStillQueued;
        expect(finalStateCorrect).toBe(true);

        const paymentAuditResponse = await auditAdmin.page.request.get(
          BASE_URL + '/api/v1/admin/audit-logs/entity?' + new URLSearchParams({
            model_type: 'BillPayment',
            model_id: payment.id,
            per_page: '100',
          }).toString(),
          { headers: await apiHeaders(auditAdmin.context) },
        );
        const paymentAudit = paymentAuditResponse.status() === 200
          ? ((await paymentAuditResponse.json()) as { data?: AuditEntry[] }).data ?? []
          : [];
        const rejectionEvents = paymentAudit.filter((entry) =>
          entry.action === 'updated' && entry.new_values?.status === 'rejected',
        );
        const rejectionEvent = rejectionEvents[0];
        rejectionAuditCorrect = paymentAuditResponse.status() === 200
          && rejectionEvents.length === 1
          && rejectionEvent?.old_values?.status === 'pending_approval'
          && rejectionEvent?.user?.email === 'vp@ogami.test';
        expect(rejectionAuditCorrect).toBe(true);
      } finally {
        await vicePresident.context.close();
      }
    } finally {
      try {
        if (billHash && paymentId) {
          const currentState = await readBillState(maker.page, billUrl);
          const unsettled = currentState.payments.find((entry) => entry.id === paymentId);
          if (unsettled?.status === 'pending_approval') {
            const nextStep = unsettled.approval_records
              .filter((record) => record.action === 'pending')
              .sort((a, b) => a.step_order - b.step_order)[0];
            const recoveryEmail = nextStep?.role_slug === 'finance_officer'
              ? 'finance2@ogami.test'
              : 'vp@ogami.test';
            const recoveryActor = await newContext(browser);
            try {
              await login(recoveryActor.page, recoveryEmail);
              const recoveryResponse = await recoveryActor.page.request.post(
                BASE_URL + '/api/v1/bills/' + billHash + '/payments/' + paymentId + '/reject',
                {
                  headers: await apiHeaders(recoveryActor.context),
                  data: { remarks: 'E2E cleanup after an earlier assertion failed.' },
                },
              );
              if (recoveryResponse.status() !== 200) {
                cleanupFailure = 'Could not settle the pending payment in E2E cleanup.';
              }
            } finally {
              await recoveryActor.context.close();
            }
          }
        }
      } finally {
        await maker.context.close();
        await secondFinance.context.close();
        await auditAdmin.context.close();
      }
    }

    expect(cleanupFailure).toBeUndefined();
    expect(uiCancelStatus).toBe(200);
    expect(directCancelStatus).toBe(200);
    expect(cancelToastVisible).toBe(true);
    expect(cancelButtonEnabled).toBe(true);
    expect(cancellationStateCorrect).toBe(true);
    expect(cancellationReversalCorrect).toBe(true);
    expect(cancellationAuditCorrect).toBe(true);
    expect(paymentQueueVisible).toBe(true);
    expect(cancelledApproveStatus).toBe(422);
    expect(cancelledApproveMessage).toBe('Cannot approve a payment on a cancelled bill.');
    expect(approveAttemptLeftStateUnchanged).toBe(true);
    expect(rejectButtonVisible).toBe(true);
    expect(rejectionWasViaUi).toBe(true);
    expect(finalStateCorrect).toBe(true);
    expect(rejectionAuditCorrect).toBe(true);
    expect(approveVisibleOnCancelledBill).toBe(false);
    expect(recoveryNoteVisible).toBe(true);
    expect(cancelledRejectCopyVisible).toBe(true);
  });

  test('return accepted goods through receipt, disposition, and replacement PO', async ({ browser }) => {
    test.setTimeout(360_000);
    expect(poId).not.toBe('');
    expect(poNumber).not.toBe('');
    expect(vendorName).not.toBe('');
    const returnCreator = await newContext(browser);
    let rmaUrl: string;
    try {
      await login(returnCreator.page, 'purchasing@ogami.test');
      await returnCreator.page.goto('/return-management/new');
      await returnCreator.page.getByLabel('Type').selectOption('supplier_return');

      const supplier = returnCreator.page.getByRole('combobox', { name: 'Supplier', exact: true });
      const sourceResponsePromise = returnCreator.page.waitForResponse((response) =>
        response.request().method() === 'GET'
        && response.url().includes('/api/v1/return-management/return-requests/source-options'),
      );
      await supplier.click();
      const supplierChoice = returnCreator.page.getByRole('option', { name: vendorName.trim(), exact: true });
      await expect(supplierChoice).toBeVisible({ timeout: 20_000 });
      await supplierChoice.click();
      await expect(supplier).toHaveValue(vendorName.trim());
      const sourceResponse = await sourceResponsePromise;
      expect(sourceResponse.status()).toBe(200);
      const sourceBody = await sourceResponse.json() as {
        data?: { supplier?: { goodsReceipts?: Array<{
          label: string;
          purchase_order_id: string;
          lines: Array<{ id: string; label: string; remaining_quantity: string }>;
        }> } };
      };

      const reasonCode = returnCreator.page.getByLabel('Reason Code');
      await expect.poll(() => reasonCode.locator('option').count(), { timeout: 20_000 }).toBeGreaterThan(1);
      const reasonOptions = await reasonCode.locator('option').evaluateAll((nodes) =>
        nodes.map((node) => ({ value: node.value, text: node.textContent ?? '' })),
      );
      await reasonCode.selectOption(
        reasonOptions.find((option) => option.value === 'quality_issue')?.value
          ?? reasonOptions.find((option) => option.value !== '')!.value,
      );
      await returnCreator.page.getByLabel('Description').fill('Incoming resin failed supplier quality review.');
      await returnCreator.page.getByRole('button', { name: 'Add Item', exact: true }).click();

      const sourceLine = returnCreator.page.getByRole('combobox', { name: 'Accepted GRN line', exact: true });
      const sourceReceipt = sourceBody.data?.supplier?.goodsReceipts?.find((receipt) =>
        receipt.purchase_order_id === poId
        && receipt.lines.some((line) => Number(line.remaining_quantity) > 0),
      );
      const sourceReceiptLine = sourceReceipt?.lines.find((line) => Number(line.remaining_quantity) > 0);
      if (!sourceReceiptLine) throw new Error(`The generated PO ${poNumber} had no returnable accepted GRN line.`);
      await sourceLine.click();
      const sourceChoice = returnCreator.page.getByRole('option')
        .filter({ hasText: `GRN ${sourceReceipt.label} · ${sourceReceiptLine.label}` });
      await expect(sourceChoice).toBeVisible({ timeout: 20_000 });
      await sourceChoice.click();
      await expect(sourceLine).toHaveValue(new RegExp(sourceReceipt.label));
      await returnCreator.page.getByLabel('Qty').fill('5');
      await returnCreator.page.getByRole('button', { name: 'Create Return Request', exact: true }).click();
      await returnCreator.page.waitForURL(/\/return-management\/(?!new$)[^/]+$/, { timeout: 20_000 });
      rmaUrl = returnCreator.page.url();
      await expect(returnCreator.page.getByText('Draft', { exact: true }).first()).toBeVisible({ timeout: 20_000 });

      await returnCreator.page.getByRole('button', { name: 'Submit for Approval', exact: true }).click();
      const submitDialog = returnCreator.page.getByRole('dialog');
      const submitResponsePromise = returnCreator.page.waitForResponse((response) =>
        response.request().method() === 'POST'
        && response.url().includes('/api/v1/return-management/return-requests/')
        && response.url().endsWith('/submit'),
      );
      await submitDialog.getByRole('button', { name: 'Submit', exact: true }).click();
      const submitResponse = await submitResponsePromise;
      expect(submitResponse.status()).toBe(200);
      const submitted = await submitResponse.json() as {
        data?: {
          rma_number?: string;
          status?: string;
        };
      };
      rmaNumber = submitted.data?.rma_number ?? '';
      expect(rmaNumber).not.toBe('');
      expect(submitted.data?.status).toBe('pending_approval');
      const submittedStateResponse = await returnCreator.page.request.get(
        `${BASE_URL}/api/v1/return-management/return-requests/${rmaUrl.split('/').pop()}`,
        { headers: await apiHeaders(returnCreator.context) },
      );
      expect(submittedStateResponse.status()).toBe(200);
      const submittedState = await submittedStateResponse.json() as {
        data?: {
          status?: string;
          pending_approval_step?: number | null;
          approval_records?: Array<{ step_order: number; action: string; role_slug: string }>;
          disposition_status?: string | null;
          receipts?: Array<{ id: string }>;
          inspections?: Array<{ id: string }>;
        };
      };
      expect(submittedState.data).toMatchObject({
        status: 'pending_approval',
        pending_approval_step: 1,
        disposition_status: null,
      });
      expect(submittedState.data?.approval_records?.map((record) => [record.step_order, record.action, record.role_slug]))
        .toEqual([[1, 'pending', 'department_head'], [2, 'pending', 'production_manager']]);
      expect(submittedState.data?.receipts).toHaveLength(0);
      expect(submittedState.data?.inspections).toHaveLength(0);
      await expect(returnCreator.page.getByText('Pending Approval', { exact: true }).first()).toBeVisible({ timeout: 20_000 });
    } finally {
      await returnCreator.context.close();
    }

    await assertRmaApprovalQueue(browser, 'depthead@ogami.test', rmaUrl, rmaNumber, 1);
    await approveRma(browser, 'depthead@ogami.test', rmaUrl, false, rmaNumber);
    await assertRmaApprovalQueue(browser, 'production@ogami.test', rmaUrl, rmaNumber, 2);
    await approveRma(browser, 'production@ogami.test', rmaUrl, true, rmaNumber);

    const receiver = await newContext(browser);
    try {
      await login(receiver.page, 'warehouse@ogami.test');
      await receiver.page.goto(rmaUrl);
      await receiver.page.getByRole('button', { name: 'Record Receipt', exact: true }).click();
      const receiveDialog = receiver.page.getByRole('dialog');
      const quantity = receiveDialog.getByRole('spinbutton').first();
      await quantity.fill('5');
      await expect(receiveDialog.getByRole('checkbox')).toBeChecked();
      const receiptResponsePromise = receiver.page.waitForResponse((response) =>
        response.request().method() === 'POST'
        && response.url().includes(`/api/v1/return-management/return-requests/${rmaUrl.split('/').pop()}/receive`),
      );
      await receiveDialog.getByRole('button', { name: 'Record Final Receipt', exact: true }).click();
      const receiptResponse = await receiptResponsePromise;
      expect(receiptResponse.status()).toBe(200);
      const receiptPayload = receiptResponse.request().postDataJSON() as Record<string, unknown>;
      const received = await receiptResponse.json() as {
        data?: {
          rma_number?: string;
          status?: string;
          received_at?: string;
          receipts?: Array<{ id: string; final_receipt: boolean; items: Array<{ quantity: string }> }>;
          items?: Array<{ returned_quantity: string; receipt_recorded: boolean }>;
        };
      };
      expect(received.data?.rma_number).toBe(rmaNumber);
      expect(received.data?.status).toBe('received');
      expect(received.data?.received_at).toBeTruthy();
      expect(received.data?.receipts).toHaveLength(1);
      expect(received.data?.receipts?.[0]).toMatchObject({ final_receipt: true });
      expect(received.data?.receipts?.[0]?.items.map((item) => Number(item.quantity))).toEqual([5]);
      expect(received.data?.items?.map((item) => [Number(item.returned_quantity), item.receipt_recorded]))
        .toEqual([[5, true]]);

      const replayResponse = await receiver.page.request.post(receiptResponse.url(), {
        data: receiptPayload,
        headers: await apiHeaders(receiver.context),
      });
      expect(replayResponse.status()).toBe(200);
      const replayed = await replayResponse.json() as typeof received;
      expect(replayed.data?.receipts).toHaveLength(1);
      expect(replayed.data?.receipts?.[0]?.id).toBe(received.data?.receipts?.[0]?.id);
      expect(replayed.data?.items?.map((item) => Number(item.returned_quantity))).toEqual([5]);
      await expect(receiver.page.getByText('Received', { exact: true }).first()).toBeVisible({ timeout: 20_000 });
    } finally {
      await receiver.context.close();
    }

    const inspector = await newContext(browser);
    try {
      await login(inspector.page, 'purchasing@ogami.test');
      await inspector.page.goto(rmaUrl);
      const id = rmaUrl.split('/').pop()!;
      const receivedState = await readApiData<{
        status: string;
        items: Array<{ id: string; item_id: string | null; product_id: string | null }>;
      }>(inspector.page, `/return-management/return-requests/${id}`);
      expect(receivedState.status).toBe('received');
      expect(receivedState.items).toHaveLength(1);
      expect(receivedState.items[0].item_id).toBeTruthy();
      const stockItemId = receivedState.items[0].item_id!;
      const stockPaths = [
        `/inventory/stock-levels?item_id=${encodeURIComponent(stockItemId)}&per_page=100`,
        `/inventory/stock-movements?item_id=${encodeURIComponent(stockItemId)}&per_page=100`,
      ];
      const stockBeforeInspection = await Promise.all(stockPaths.map((path) =>
        readApiData<unknown[]>(inspector.page, path),
      ));
      await inspector.page.getByRole('button', { name: /Stage Quality Handoff|Continue to Disposition/ }).click();
      const inspectDialog = inspector.page.getByRole('dialog');
      await expect(inspectDialog).toContainText('Creates the item-linked Quality inspection records.');
      const inspectResponsePromise = inspector.page.waitForResponse((response) =>
        response.request().method() === 'POST'
        && response.url().includes(`/api/v1/return-management/return-requests/${id}/inspect`),
      );
      await inspectDialog.getByRole('button', { name: 'Stage Handoff', exact: true }).click();
      const inspectResponse = await inspectResponsePromise;
      expect(inspectResponse.status()).toBe(200);
      const inspected = await inspectResponse.json() as {
        data?: {
          rma_number?: string;
          status?: string;
          inspection_handoff?: { status?: string; message?: string | null };
          inspections?: Array<{ id: string; inspection_number: string; status: string; return_request_item_id: string | null }>;
          items?: Array<{ id: string; item_id: string | null; product_id: string | null }>;
        };
      };
      expect(inspected.data).toMatchObject({
        rma_number: rmaNumber,
        status: 'inspected',
        inspection_handoff: { status: 'generated' },
      });
      const returnItemId = inspected.data?.items?.[0]?.id;
      const inspection = inspected.data?.inspections?.[0];
      if (!returnItemId || !inspection) throw new Error('The supplier return did not stage its line-linked inspection.');
      expect(inspected.data?.inspections).toHaveLength(1);
      expect(inspection).toMatchObject({ status: 'draft', return_request_item_id: returnItemId });
      expect(inspected.data?.items).toMatchObject([{ id: returnItemId, item_id: stockItemId, product_id: null }]);
      const inspectedState = await readApiData<{
        status: string;
        disposition_status?: string | null;
        replacement_purchase_order?: { id: string } | null;
        stock_movement?: { id: string } | null;
        inspections: Array<{ id: string; status: string }>;
        items: Array<{ id: string; item_id: string | null; product_id: string | null }>;
      }>(inspector.page, `/return-management/return-requests/${id}`);
      expect(inspectedState).toMatchObject({
        status: 'inspected',
        disposition_status: null,
        replacement_purchase_order: null,
        stock_movement: null,
        inspections: [{ id: inspection.id, status: 'draft' }],
      });
      expect(inspectedState.items).toMatchObject([{ id: returnItemId, item_id: stockItemId, product_id: null }]);
      expect(await Promise.all(stockPaths.map((path) => readApiData<unknown[]>(inspector.page, path))))
        .toEqual(stockBeforeInspection);
      await expect(inspector.page.getByText('Inspected', { exact: true }).first()).toBeVisible({ timeout: 20_000 });
      await expect(inspector.page.getByText('Quality handoff staged', { exact: true })).toBeVisible();

      const warehouseTree = await readApiData<Array<{
        is_active: boolean;
        zones?: Array<{
          zone_type: string;
          locations?: Array<{ id: string; is_active: boolean; is_blocked: boolean }>;
        }>;
      }>>(inspector.page, '/inventory/warehouse');
      const sourceLocation = warehouseTree.flatMap((warehouse) =>
        warehouse.is_active ? (warehouse.zones ?? []).flatMap((zone) =>
          ['quarantine', 'scrap'].includes(zone.zone_type) ? [] : (zone.locations ?? [])
            .filter((location) => location.is_active && !location.is_blocked),
        ) : [],
      )[0];
      if (!sourceLocation) throw new Error('No usable warehouse location was available for supplier return disposition.');

      const stateBeforeEarlyDispose = await readApiData<Record<string, unknown>>(
        inspector.page,
        `/return-management/return-requests/${id}`,
      );
      const earlyDispose = await inspector.page.request.post(
        `${BASE_URL}/api/v1/return-management/return-requests/${id}/dispose`,
        {
          headers: await apiHeaders(inspector.context),
          data: {
            dispositions: [{ item_id: returnItemId, disposition: 'return_to_supplier' }],
            create_replacement_po: true,
            location_id: sourceLocation.id,
          },
        },
      );
      expect(earlyDispose.status()).toBe(422);
      expect((await earlyDispose.json() as { message?: string }).message)
        .toBe('Every stock-item return requires independently checked QC before disposition; restock requires PASS.');
      expect(await readApiData<Record<string, unknown>>(inspector.page, `/return-management/return-requests/${id}`))
        .toEqual(stateBeforeEarlyDispose);
      expect(await Promise.all(stockPaths.map((path) => readApiData<unknown[]>(inspector.page, path))))
        .toEqual(stockBeforeInspection);
      await expect(inspector.page.getByRole('button', { name: 'Dispose Items', exact: true })).toHaveCount(0);

      const qc = await newContext(browser);
      let inspectionNumber = '';
      try {
        await login(qc.page, 'qc@ogami.test');
        await qc.page.goto('/quality/inspections');
        const inspectionsResponsePromise = qc.page.waitForResponse((response) =>
          response.request().method() === 'GET'
          && response.url().includes('/api/v1/quality/inspections?'),
        );
        await qc.page.getByPlaceholder('Search inspection, product').fill(inspection.inspection_number);
        const inspectionsResponse = await inspectionsResponsePromise;
        expect(inspectionsResponse.status()).toBe(200);
        const inspectionRows = (await inspectionsResponse.json() as {
          data?: Array<{ id: string; inspection_number: string; sample_size: number }>;
        }).data;
        const listedInspection = inspectionRows?.find((row) => row.id === inspection.id);
        expect(listedInspection).toMatchObject({
          inspection_number: inspection.inspection_number,
        });
        expect(listedInspection!.sample_size).toBeGreaterThan(0);
        inspectionNumber = listedInspection!.inspection_number;
        await qc.page.goto(`/quality/inspections/${inspection.id}`);
        await submitLotChecklistInspection(qc.page, inspection.id, listedInspection!.sample_size);

        const inspectionBeforeSelfReview = await readApiData<{
          status: string;
          result_version: number;
          proposed_result: string | null;
        }>(qc.page, `/quality/inspections/${inspection.id}`);
        expect(inspectionBeforeSelfReview).toMatchObject({ status: 'awaiting_review', proposed_result: 'passed' });
        const selfReview = await qc.page.request.patch(
          `${BASE_URL}/api/v1/quality/inspections/${inspection.id}/review`,
          {
            headers: await apiHeaders(qc.context),
            data: {
              decision: 'passed',
              remarks: 'The result maker cannot review this inspection.',
              expected_result_version: inspectionBeforeSelfReview.result_version,
            },
          },
        );
        expect(selfReview.status()).toBe(403);
        expect((await selfReview.json() as { message?: string }).message)
          .toContain('You cannot review an inspection you performed.');
        expect(await readApiData(qc.page, `/quality/inspections/${inspection.id}`)).toEqual(inspectionBeforeSelfReview);
      } finally {
        await qc.context.close();
      }

      const awaitingReviewState = await readApiData<{
        status: string;
        disposition_status: string | null;
        replacement_purchase_order: { id: string } | null;
        stock_movement: { id: string } | null;
        inspections: Array<{ id: string; status: string }>;
      }>(inspector.page, `/return-management/return-requests/${id}`);
      expect(awaitingReviewState).toMatchObject({
        status: 'inspected',
        disposition_status: null,
        replacement_purchase_order: null,
        stock_movement: null,
        inspections: [{ id: inspection.id, status: 'awaiting_review' }],
      });
      const stateBeforePendingDispose = await readApiData<Record<string, unknown>>(
        inspector.page,
        `/return-management/return-requests/${id}`,
      );
      const pendingDispose = await inspector.page.request.post(
        `${BASE_URL}/api/v1/return-management/return-requests/${id}/dispose`,
        {
          headers: await apiHeaders(inspector.context),
          data: {
            dispositions: [{ item_id: returnItemId, disposition: 'return_to_supplier' }],
            create_replacement_po: true,
            location_id: sourceLocation.id,
          },
        },
      );
      expect(pendingDispose.status()).toBe(422);
      expect((await pendingDispose.json() as { message?: string }).message)
        .toBe('Every stock-item return requires independently checked QC before disposition; restock requires PASS.');
      expect(await readApiData<Record<string, unknown>>(inspector.page, `/return-management/return-requests/${id}`))
        .toEqual(stateBeforePendingDispose);
      expect(await Promise.all(stockPaths.map((path) => readApiData<unknown[]>(inspector.page, path))))
        .toEqual(stockBeforeInspection);
      await inspector.page.reload({ waitUntil: 'domcontentloaded' });
      await expect(inspector.page.getByRole('button', { name: 'Dispose Items', exact: true })).toHaveCount(0);

      const reviewer = await newContext(browser);
      try {
        await login(reviewer.page, 'production@ogami.test');
        await reviewer.page.goto('/notifications');
        await expect(reviewer.page.getByText(`Inspection ${inspectionNumber} awaits your review`, { exact: true }))
          .toBeVisible({ timeout: 20_000 });
        await reviewer.page.goto(`/quality/inspections/${inspection.id}`);
        const approvePass = reviewer.page.getByRole('button', { name: 'Approve pass', exact: true });
        await expect(approvePass).toBeVisible({ timeout: 20_000 });
        const reviewResponsePromise = reviewer.page.waitForResponse((response) =>
          response.request().method() === 'PATCH'
          && response.url().endsWith(`/api/v1/quality/inspections/${inspection.id}/review`),
        );
        await approvePass.click();
        await reviewer.page.getByRole('dialog').getByRole('button', { name: 'Approve pass', exact: true }).click();
        const reviewResponse = await reviewResponsePromise;
        expect(reviewResponse.status()).toBe(200);
        const reviewedInspection = await reviewResponse.json() as {
          data?: {
            status?: string;
            reviewed_at?: string | null;
            result_authors?: Array<{ id: string }>;
            reviewer?: { id: string } | null;
          };
        };
        expect(reviewedInspection.data?.status).toBe('passed');
        expect(reviewedInspection.data?.reviewed_at).toBeTruthy();
        expect(reviewedInspection.data?.reviewer?.id)
          .not.toBe(reviewedInspection.data?.result_authors?.[0]?.id);
        await expect(reviewer.page.getByText('Passed', { exact: true }).first()).toBeVisible({ timeout: 20_000 });
      } finally {
        await reviewer.context.close();
      }

      const qualityCompleteState = await readApiData<{
        status: string;
        disposition_status: string | null;
        replacement_purchase_order: { id: string } | null;
        stock_movement: { id: string } | null;
        inspections: Array<{ id: string; status: string }>;
      }>(inspector.page, `/return-management/return-requests/${id}`);
      expect(qualityCompleteState).toMatchObject({
        status: 'inspected',
        disposition_status: null,
        replacement_purchase_order: null,
        stock_movement: null,
        inspections: [{ id: inspection.id, status: 'passed' }],
      });
      expect(await Promise.all(stockPaths.map((path) => readApiData<unknown[]>(inspector.page, path))))
        .toEqual(stockBeforeInspection);
      await inspector.page.reload({ waitUntil: 'domcontentloaded' });
      await expect(inspector.page.getByRole('button', { name: 'Dispose Items', exact: true })).toBeEnabled();

      const disposeButton = inspector.page.getByRole('button', { name: 'Dispose Items', exact: true });
      await expect(disposeButton).toBeEnabled();

      await disposeButton.click();
      const disposeDialog = inspector.page.getByRole('dialog');
      const disposition = disposeDialog.getByRole('combobox', { name: 'Disposition' });
      await expect(disposition.locator('option[value="return_to_supplier"]')).toHaveCount(1);
      await disposition.selectOption('return_to_supplier');
      const restockLocation = disposeDialog.getByRole('combobox', { name: 'Restock location' });
      await expect.poll(() => restockLocation.locator('option').count(), { timeout: 20_000 }).toBeGreaterThan(1);
      const locationOptions = await restockLocation.locator('option').evaluateAll((nodes) =>
        nodes.map((node) => ({ value: node.value, text: node.textContent ?? '' })),
      );
      const locationChoice = locationOptions.find((option) => option.value !== '');
      if (!locationChoice?.value) throw new Error('No warehouse location was available for supplier return disposal.');
      const stockLevelsBeforeDispose = stockBeforeInspection[0] as Array<{
        location: { id: string };
        quantity: string;
      }>;
      const sourceQuantityBeforeDispose = stockLevelsBeforeDispose.find(
        (level) => level.location.id === locationChoice.value,
      )?.quantity;
      if (sourceQuantityBeforeDispose === undefined) {
        throw new Error('The selected supplier-return source location has no stock balance.');
      }
      expect(Number(sourceQuantityBeforeDispose)).toBeGreaterThanOrEqual(5);
      await restockLocation.selectOption(locationChoice.value);
      await disposeDialog.getByLabel(/Raise a replacement purchase order/).check();
      const dispositionResponsePromise = inspector.page.waitForResponse((response) =>
        response.request().method() === 'POST'
        && response.url().includes(`/api/v1/return-management/return-requests/${id}/dispose`),
      );
      await disposeDialog.getByRole('button', { name: 'Record Disposition', exact: true }).click();
      const dispositionResponse = await dispositionResponsePromise;
      expect(dispositionResponse.status()).toBe(200);
      const dispositionPayload = dispositionResponse.request().postDataJSON() as Record<string, unknown>;
      const disposed = await dispositionResponse.json() as { data?: {
        status?: string;
        disposition_status?: string;
        replacement_purchase_order?: { id: string; po_number: string; status: string } | null;
        stock_movement?: {
          id: string;
          quantity: string;
          movement_type: string;
          from_location: { id: string } | null;
        } | null;
        items?: Array<{ id: string; disposition: string; moved_quantity: string | null; stock_movement_id: string | null }>;
      } };
      expect(disposed.data).toMatchObject({
        status: 'inspected',
        disposition_status: 'disposed',
        replacement_purchase_order: { status: 'draft' },
        stock_movement: { quantity: '5.000', movement_type: 'return_to_vendor' },
      });
      expect(disposed.data?.items).toMatchObject([{
        id: returnItemId,
        disposition: 'return_to_supplier',
        moved_quantity: '5.000',
        stock_movement_id: disposed.data?.stock_movement?.id,
      }]);
      expect(disposed.data?.stock_movement).toMatchObject({
        quantity: '5.000',
        movement_type: 'return_to_vendor',
        from_location: { id: locationChoice.value },
      });
      const stockAfterDispose = await readApiData<Array<{ location: { id: string }; quantity: string }>>(
        inspector.page,
        stockPaths[0],
      );
      const sourceQuantityAfterDispose = stockAfterDispose.find(
        (level) => level.location.id === locationChoice.value,
      )?.quantity ?? '0.000';
      expect(Number(sourceQuantityAfterDispose).toFixed(3))
        .toBe((Number(sourceQuantityBeforeDispose) - 5).toFixed(3));
      const movementsAfterDispose = await readApiData<Array<{
        id: string;
        movement_type: string;
        quantity: string;
        reference_type: string | null;
        reference_id: string | null;
      }>>(inspector.page, stockPaths[1]);
      const movementsBeforeDispose = stockBeforeInspection[1] as Array<{ id: string }>;
      expect(movementsAfterDispose).toHaveLength(movementsBeforeDispose.length + 1);
      expect(movementsAfterDispose).toContainEqual(expect.objectContaining({
        id: disposed.data?.stock_movement?.id,
        movement_type: 'return_to_vendor',
        quantity: '5.000',
        reference_type: 'return_request',
        reference_id: id,
      }));
      await expect(inspector.page.getByText('Disposed', { exact: true }).first()).toBeVisible({ timeout: 20_000 });
      await expect(inspector.page.locator('dt').filter({ hasText: 'Replacement PO' }).locator('xpath=following-sibling::dd[1]').locator('a')).toBeVisible({ timeout: 20_000 });

      const duplicateDispose = await inspector.page.request.post(dispositionResponse.url(), {
        data: dispositionPayload,
        headers: await apiHeaders(inspector.context),
      });
      expect(duplicateDispose.status()).toBe(422);
      const unchangedResponse = await inspector.page.request.get(
        `${BASE_URL}/api/v1/return-management/return-requests/${id}`,
        { headers: await apiHeaders(inspector.context) },
      );
      expect(unchangedResponse.status()).toBe(200);
      const unchanged = await unchangedResponse.json() as typeof disposed;
      expect(unchanged.data).toMatchObject({
        disposition_status: 'disposed',
        replacement_purchase_order: { id: disposed.data?.replacement_purchase_order?.id },
        stock_movement: { id: disposed.data?.stock_movement?.id, quantity: '5.000' },
      });
      expect(unchanged.data?.items?.[0]?.moved_quantity).toBe('5.000');
      expect(await readApiData(inspector.page, stockPaths[0])).toEqual(stockAfterDispose);
      expect(await readApiData(inspector.page, stockPaths[1])).toEqual(movementsAfterDispose);

      const completeResponsePromise = inspector.page.waitForResponse((response) =>
        response.request().method() === 'POST'
        && response.url().includes(`/api/v1/return-management/return-requests/${id}/complete`),
      );
      await inspector.page.getByRole('button', { name: 'Complete RMA', exact: true }).click();
      const completeDialog = inspector.page.getByRole('dialog');
      await completeDialog.getByRole('button', { name: 'Confirm Complete', exact: true }).click();
      const completeResponse = await completeResponsePromise;
      expect(completeResponse.status()).toBe(200);
      const completed = await completeResponse.json() as { data?: { status?: string; stock_movement?: { id: string } | null; items?: Array<{ moved_quantity: string | null }> } };
      expect(completed.data).toMatchObject({
        status: 'completed',
        stock_movement: { id: disposed.data?.stock_movement?.id },
      });
      expect(completed.data?.items?.map((item) => item.moved_quantity)).toEqual(['5.000']);
      expect(await readApiData(inspector.page, stockPaths[0])).toEqual(stockAfterDispose);
      expect(await readApiData(inspector.page, stockPaths[1])).toEqual(movementsAfterDispose);
      await expect(inspector.page.getByText('Completed', { exact: true }).first()).toBeVisible({ timeout: 20_000 });
    } finally {
      await inspector.context.close();
    }
  });
});
