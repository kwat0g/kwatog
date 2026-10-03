import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { localIsoDate } from '../src/lib/formatDate';

const PASSWORD = 'password';
const BASE_URL = process.env.E2E_BASE_URL ?? 'http://localhost';
const runErrors: string[] = [];

test.beforeEach(() => {
  runErrors.length = 0;
});

test.afterEach(() => {
  expect(runErrors).toEqual([]);
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
    runErrors.push(`request failed: ${request.method()} ${request.url()} ${request.failure()?.errorText ?? ''}`);
  });
  page.on('response', (response) => {
    const url = response.url();
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

async function selectDepartment(page: Page, preferredCode = 'PROD'): Promise<void> {
  const select = page.getByLabel('Department');
  await expect(select).toBeVisible();
  await expect.poll(() => select.locator('option').count(), { timeout: 20_000 }).toBeGreaterThan(1);
  const options = await select.locator('option').evaluateAll((nodes) =>
    nodes.map((node) => ({ value: node.value, text: node.textContent ?? '' })),
  );
  const chosen = options.find((option) => option.text.includes(preferredCode))
    ?? options.find((option) => option.value !== '');
  if (!chosen?.value) throw new Error('No department option was available.');
  await select.selectOption(chosen.value);
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
    await expect.poll(async () => {
      await purchasing.page.reload({ waitUntil: 'networkidle' });
      return (await convertButton.count()) + (await poLink.count());
    }, { timeout: 45_000, intervals: [1_000, 2_000, 5_000] }).toBeGreaterThan(0);

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

async function approvePaymentRequest(browser: Browser, email: string, billUrl: string, final: boolean): Promise<void> {
  const { context, page } = await newContext(browser);
  try {
    await login(page, email);
    await page.goto(billUrl);
    const approve = page.getByRole('button', { name: 'Approve', exact: true });
    await expect(approve).toBeVisible({ timeout: 20_000 });
    await approve.click();
    await expect(page.getByText(/LIVE-E2E-PARTIAL/)).toBeVisible({ timeout: 20_000 });
    if (final) await expect(page.getByText('Partially paid', { exact: true }).first()).toBeVisible({ timeout: 20_000 });
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
      const department = departmentHead.page.getByLabel('Department');
      await expect(department).toBeDisabled();
      await expect(department.locator('option:checked')).toContainText('Production');
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
      const product = creator.page.getByLabel('Credited product');
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
        await qc.page.getByPlaceholder('Search inspection number or product').fill(qcNumber);
        const inspectionResponse = await inspectionListResponse;
        const inspectionBody = await inspectionResponse.json() as {
          data?: Array<{ id: string; inspection_number: string }>;
        };
        await expect(qc.page.getByText(qcNumber, { exact: true })).toBeVisible({ timeout: 20_000 });
        const inspection = inspectionBody.data?.find((row) => row.inspection_number === qcNumber);
        expect(inspection, `Filtered inspection ${qcNumber} was not in the API response`).toBeTruthy();
        inspectionId = inspection!.id;
        await qc.page.goto(`/quality/inspections/${inspectionId}`);
        await qc.page.waitForURL(/\/quality\/inspections\/[^/]+$/, { timeout: 20_000 });
        await qc.page.getByRole('button', { name: 'Tick all', exact: true }).click();
        await qc.page.getByRole('group', { name: 'Defective pieces found' })
          .getByRole('button', { name: '0', exact: true }).click();
        const lotResultResponsePromise = qc.page.waitForResponse((response) =>
          response.request().method() === 'POST'
          && response.url().includes(`/api/v1/quality/inspections/${inspectionId}/lot-result`),
        );
        const submitResult = qc.page.getByRole('button', { name: 'Submit result', exact: true });
        await expect(submitResult).toBeEnabled();
        await submitResult.click();
        const lotResultResponse = await lotResultResponsePromise;
        expect(lotResultResponse.status()).toBe(200);
        const lotResult = await lotResultResponse.json() as {
          data?: {
            status?: string;
            proposed_result?: string | null;
            defect_count?: number;
            sample_defect_count?: number | null;
            can_review?: boolean;
            result_authors?: Array<{ id: string; name: string }>;
          };
        };
        expect(lotResult.data).toMatchObject({
          status: 'awaiting_review',
          proposed_result: 'passed',
          defect_count: 0,
          sample_defect_count: 0,
          can_review: false,
        });
        expect(lotResult.data?.result_authors).toHaveLength(1);
        await expect(qc.page.getByText(/Awaiting checker.*passed/i).first()).toBeVisible({ timeout: 20_000 });
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
      await payment.getByLabel('Reference no.').fill('LIVE-E2E-PARTIAL');
      await payment.getByRole('button', { name: 'Record', exact: true }).click();
      await finance.page.reload({ waitUntil: 'domcontentloaded' });
      await expect(finance.page.getByText(/LIVE-E2E-PARTIAL/)).toBeVisible({ timeout: 20_000 });
    } finally {
      await finance.context.close();
    }

    await approvePaymentRequest(browser, 'finance2@ogami.test', billUrl, false);
    await approvePaymentRequest(browser, 'vp@ogami.test', billUrl, true);

    const settledView = await newContext(browser);
    try {
      await login(settledView.page, 'finance@ogami.test');
      await settledView.page.goto(billUrl);
      await expect(settledView.page.getByText('Partially paid', { exact: true }).first()).toBeVisible({ timeout: 20_000 });
    } finally {
      await settledView.context.close();
    }
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

      const supplier = returnCreator.page.getByLabel('Supplier');
      await expect.poll(() => supplier.locator('option').count(), { timeout: 20_000 }).toBeGreaterThan(1);
      const supplierOptions = await supplier.locator('option').evaluateAll((nodes) =>
        nodes.map((node) => ({ value: node.value, text: node.textContent ?? '' })),
      );
      const supplierChoice = supplierOptions.find((option) => option.text.trim() === vendorName.trim())
        ?? supplierOptions.find((option) => option.text.includes(vendorName.trim()));
      if (!supplierChoice?.value) throw new Error(`Generated PO vendor ${vendorName} was not available for return creation.`);
      const sourceResponsePromise = returnCreator.page.waitForResponse((response) =>
        response.request().method() === 'GET'
        && response.url().includes('/api/v1/return-management/return-requests/source-options'),
      );
      await supplier.selectOption(supplierChoice.value);

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

      const sourceLine = returnCreator.page.getByLabel('Accepted GRN line');
      await expect.poll(() => sourceLine.locator('option').count(), { timeout: 30_000 }).toBeGreaterThan(1);
      const sourceResponse = await sourceResponsePromise;
      const sourceBody = await sourceResponse.json() as {
        data?: { supplier?: { goodsReceipts?: Array<{ purchase_order_id: string; lines: Array<{ id: string; remaining_quantity: string }> }> } };
      };
      const sourceReceipt = sourceBody.data?.supplier?.goodsReceipts?.find((receipt) =>
        receipt.purchase_order_id === poId
        && receipt.lines.some((line) => Number(line.remaining_quantity) > 0),
      );
      const sourceReceiptLine = sourceReceipt?.lines.find((line) => Number(line.remaining_quantity) > 0);
      if (!sourceReceiptLine) throw new Error(`The generated PO ${poNumber} had no returnable accepted GRN line.`);
      const sourceOptions = await sourceLine.locator('option').evaluateAll((nodes) =>
        nodes.map((node) => ({ value: node.value, text: node.textContent ?? '' })),
      );
      const sourceChoice = sourceOptions.find((option) => option.value === `grn_item:${sourceReceiptLine.id}`);
      if (!sourceChoice?.value) throw new Error('The generated accepted GRN line was not available for return creation.');
      await sourceLine.selectOption(sourceChoice.value);
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
      await inspector.page.getByRole('button', { name: /Stage Quality Handoff|Continue to Disposition/ }).click();
      const inspectDialog = inspector.page.getByRole('dialog');
      await expect(inspectDialog).toContainText('No product-linked Quality inspection is required for this return.');
      const inspectResponsePromise = inspector.page.waitForResponse((response) =>
        response.request().method() === 'POST'
        && response.url().includes(`/api/v1/return-management/return-requests/${rmaUrl.split('/').pop()}/inspect`),
      );
      await inspectDialog.getByRole('button', { name: /Stage Handoff|Continue/ }).click();
      const inspectResponse = await inspectResponsePromise;
      expect(inspectResponse.status()).toBe(200);
      const inspected = await inspectResponse.json() as {
        data?: {
          rma_number?: string;
          status?: string;
          inspection_handoff?: { status?: string };
          inspections?: Array<{ id: string; status: string }>;
          items?: Array<{ id: string; product_id: string | null }>;
        };
      };
      expect(inspected.data).toMatchObject({
        rma_number: rmaNumber,
        status: 'inspected',
        inspection_handoff: { status: 'not_required' },
      });
      expect(inspected.data?.inspections).toEqual([]);
      const inspectStateResponse = await inspector.page.request.get(
        `${BASE_URL}/api/v1/return-management/return-requests/${rmaUrl.split('/').pop()}`,
        { headers: await apiHeaders(inspector.context) },
      );
      expect(inspectStateResponse.status()).toBe(200);
      const inspectedState = await inspectStateResponse.json() as { data?: {
        disposition_status?: string | null;
        replacement_purchase_order?: { id: string } | null;
        stock_movement?: { id: string } | null;
        items?: Array<{ id: string; product_id: string | null }>;
      } };
      expect(inspectedState.data).toMatchObject({
        disposition_status: null,
        replacement_purchase_order: null,
        stock_movement: null,
      });
      expect(inspectedState.data?.items?.map((item) => item.product_id)).toEqual([null]);
      const returnItemId = inspectedState.data?.items?.[0]?.id;
      if (!returnItemId) throw new Error('The inspected RMA had no returned line.');
      await expect(inspector.page.getByText('Inspected', { exact: true }).first()).toBeVisible({ timeout: 20_000 });
      await expect(inspector.page.getByText('Not required', { exact: true })).toBeVisible();

      const id = rmaUrl.split('/').pop()!;
      const disposeButton = inspector.page.getByRole('button', { name: 'Dispose Items', exact: true });
      await expect(disposeButton).toBeVisible();
      await expect(disposeButton).toBeEnabled();

      await disposeButton.click();
      const disposeDialog = inspector.page.getByRole('dialog');
      const disposition = disposeDialog.getByLabel('Disposition');
      await expect.poll(() => disposition.locator('option').count(), { timeout: 20_000 }).toBeGreaterThan(1);
      await disposition.selectOption('return_to_supplier');
      const restockLocation = disposeDialog.getByLabel('Restock location');
      await expect.poll(() => restockLocation.locator('option').count(), { timeout: 20_000 }).toBeGreaterThan(1);
      const locationOptions = await restockLocation.locator('option').evaluateAll((nodes) =>
        nodes.map((node) => ({ value: node.value, text: node.textContent ?? '' })),
      );
      const locationChoice = locationOptions.find((option) => option.value !== '');
      if (!locationChoice?.value) throw new Error('No warehouse location was available for supplier return disposal.');
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
        stock_movement?: { id: string; quantity: string; movement_type: string } | null;
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
      await expect(inspector.page.getByText('Completed', { exact: true }).first()).toBeVisible({ timeout: 20_000 });
    } finally {
      await inspector.context.close();
    }
  });
});
