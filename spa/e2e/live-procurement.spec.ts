import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';

const PASSWORD = 'password';

async function login(page: Page, email: string): Promise<void> {
  await page.goto('/sign-in');
  await page.getByLabel('Email').fill(email);
  await page.getByRole('textbox', { name: 'Password' }).fill(PASSWORD);
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).not.toHaveURL(/\/sign-in$/, { timeout: 20_000 });
  await page.locator('#main-content').waitFor({ state: 'attached', timeout: 20_000 });
}

async function newContext(browser: Browser): Promise<{ context: BrowserContext; page: Page }> {
  const context = await browser.newContext({ baseURL: 'http://localhost' });
  return { context, page: await context.newPage() };
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

async function approvePr(browser: Browser, email: string, url: string): Promise<void> {
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
    await expect(page.getByText('Approved', { exact: true }).first()).toBeVisible({ timeout: 20_000 });
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
      await expect(purchasing.page.getByRole('dialog')).toContainText('Assign a supplier per line');
      await purchasing.page.getByRole('dialog').getByRole('button', { name: /Create purchase order/ }).click();
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

async function approveRma(browser: Browser, email: string, url: string, final: boolean): Promise<void> {
  const { context, page } = await newContext(browser);
  try {
    await login(page, email);
    await page.goto(url);
    const approveButton = page.getByRole('button', { name: 'Approve', exact: true });
    await expect(approveButton).toBeVisible({ timeout: 20_000 });
    await approveButton.click();
    const dialog = page.getByRole('dialog');
    await dialog.getByRole('button', { name: 'Approve', exact: true }).click();
    await expect(
      final
        ? page.getByText('Approved', { exact: true }).first()
        : page.getByText('Pending Approval', { exact: true }).first(),
    ).toBeVisible({ timeout: 20_000 });
  } finally {
    await context.close();
  }
}

async function rejectRma(browser: Browser, url: string): Promise<void> {
  const { context, page } = await newContext(browser);
  try {
    await login(page, 'depthead@ogami.test');
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
  return date.toISOString().slice(0, 10);
}

test.describe('Live procurement runbook', () => {
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
      await sales.page.waitForURL(/\/crm\/sales-orders\/[^/]+$/, { timeout: 30_000 });
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

      await approvePr(browser, 'finance@ogami.test', created.url);

      const vp = await newContext(browser);
      try {
        await login(vp.page, 'vp@ogami.test');
        await vp.page.goto(created.url);
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

  test('generated finance-only customer return can be rejected by Department Head', async ({ browser }) => {
    const creator = await newContext(browser);
    let rmaUrl: string;
    try {
      await login(creator.page, 'crm@ogami.test');
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

    await rejectRma(browser, rmaUrl);
  });

  test('generated manual PR continues through PO, GRN, QC, bill, and payment', async ({ browser }) => {
    test.setTimeout(240_000);
    const { poId, poNumber, vendorName } = await createCatalogPrToSentPo(browser);
    const warehouse = await newContext(browser);
    let billUrl: string;
    try {
      await login(warehouse.page, 'warehouse@ogami.test');
      await warehouse.page.goto('/inventory/grn');
      await expect(warehouse.page.getByText(poNumber, { exact: true })).toBeVisible({ timeout: 20_000 });
      await warehouse.page.getByText(poNumber, { exact: true }).click();
      await warehouse.page.waitForURL(/\/inventory\/grn\/[^/]+$/, { timeout: 20_000 });
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
      await warehouse.page.locator('input[aria-label^="Qty "]').fill('5');
      await warehouse.page.getByRole('button', { name: 'Finalize receiving', exact: true }).click();
      const finalizeDialog = warehouse.page.getByRole('dialog');
      await finalizeDialog.getByRole('button', { name: 'Finalize GRN', exact: true }).click();
      const pendingQc = warehouse.page.getByText(/Pending qc/i).first();
      await expect(pendingQc).toBeVisible({ timeout: 20_000 });

        const qcNumberLocator = warehouse.page.getByText(/QC-\d{6}-\d+/, { exact: false }).first();
        await expect(qcNumberLocator).toBeVisible({ timeout: 20_000 });
        const qcNumber = (await qcNumberLocator.innerText()).match(/QC-\d{6}-\d+/)?.[0] ?? '';
        if (!qcNumber) throw new Error('Incoming QC number was not readable.');

        const qc = await newContext(browser);
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
          await qc.page.goto(`/quality/inspections/${inspection!.id}`);
          await qc.page.waitForURL(/\/quality\/inspections\/[^/]+$/, { timeout: 20_000 });
          await expect(qc.page.getByRole('button', { name: 'Complete', exact: true })).toBeVisible({ timeout: 20_000 });
          const measured = qc.page.locator('input[aria-label^="Measured value"]');
          const visualResults = qc.page.locator('select[aria-label="Visual result"]');
          await expect.poll(async () => (await measured.count()) + (await visualResults.count()), { timeout: 20_000 }).toBeGreaterThan(0);
          for (let index = 0; index < await measured.count(); index += 1) {
            const row = measured.nth(index).locator('xpath=ancestor::tr');
            const nominal = await row.locator('td').nth(1).innerText();
            const value = nominal.match(/-?\d+(?:\.\d+)?/)?.[0] ?? '0';
            await measured.nth(index).fill(value);
          }
          for (let index = 0; index < await visualResults.count(); index += 1) {
            await visualResults.nth(index).selectOption('pass');
          }
          const save = qc.page.getByRole('button', { name: /Save \(/ });
          if (await save.count() > 0 && await save.isVisible()) {
            await save.click();
            await expect(qc.page.getByText('Measurements saved', { exact: true })).toBeVisible({ timeout: 20_000 });
          }
          await qc.page.getByRole('button', { name: 'Complete', exact: true }).click();
          const completeDialog = qc.page.getByRole('dialog');
          await completeDialog.getByRole('button', { name: 'Complete', exact: true }).click();
          await expect(qc.page.getByText('Passed', { exact: true }).first()).toBeVisible({ timeout: 20_000 });
        } finally {
          await qc.context.close();
        }
      const billLink = warehouse.page.locator('a[href*="/accounting/bills/"]');
      await expect.poll(async () => {
        await warehouse.page.reload({ waitUntil: 'networkidle' });
        return await billLink.count();
      }, { timeout: 45_000, intervals: [1_000, 2_000, 5_000] }).toBeGreaterThan(0);
      billUrl = await billLink.first().getAttribute('href') ?? '';
      if (!billUrl) throw new Error('The accepted GRN did not expose its generated bill link.');
    } finally {
      await warehouse.context.close();
    }

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
      await payment.getByLabel('Payment date').fill(new Date().toISOString().slice(0, 10));
      await payment.getByLabel(/Amount \(max/).fill('1.00');
      await payment.getByLabel('Method').selectOption('bank_transfer');
      await payment.getByLabel('Reference no.').fill('LIVE-E2E-PARTIAL');
      await payment.getByRole('button', { name: 'Record', exact: true }).click();
      await finance.page.reload({ waitUntil: 'networkidle' });
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
      await submitDialog.getByRole('button', { name: 'Submit', exact: true }).click();
      await expect(returnCreator.page.getByText('Pending Approval', { exact: true }).first()).toBeVisible({ timeout: 20_000 });
    } finally {
      await returnCreator.context.close();
    }

    await approveRma(browser, 'depthead@ogami.test', rmaUrl, false);
    await approveRma(browser, 'production@ogami.test', rmaUrl, true);

    const receiver = await newContext(browser);
    try {
      await login(receiver.page, 'warehouse@ogami.test');
      await receiver.page.goto(rmaUrl);
      await receiver.page.getByRole('button', { name: 'Record Receipt', exact: true }).click();
      const receiveDialog = receiver.page.getByRole('dialog');
      await receiveDialog.getByRole('button', { name: 'Record Receipt', exact: true }).click();
      await expect(receiver.page.getByText('Received', { exact: true }).first()).toBeVisible({ timeout: 20_000 });
    } finally {
      await receiver.context.close();
    }

    const inspector = await newContext(browser);
    try {
      await login(inspector.page, 'purchasing@ogami.test');
      await inspector.page.goto(rmaUrl);
      await inspector.page.getByRole('button', { name: 'Stage Quality Handoff', exact: true }).click();
      const inspectDialog = inspector.page.getByRole('dialog');
      await inspectDialog.getByRole('button', { name: 'Stage Handoff', exact: true }).click();
      await expect(inspector.page.getByText('Inspected', { exact: true }).first()).toBeVisible({ timeout: 20_000 });

      await inspector.page.getByRole('button', { name: 'Dispose Items', exact: true }).click();
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
      await disposeDialog.getByRole('button', { name: 'Record Disposition', exact: true }).click();
      await expect(inspector.page.getByText('Disposed', { exact: true }).first()).toBeVisible({ timeout: 20_000 });
      await expect(inspector.page.locator('dt').filter({ hasText: 'Replacement PO' }).locator('xpath=following-sibling::dd[1]').locator('a')).toBeVisible({ timeout: 20_000 });

      await inspector.page.getByRole('button', { name: 'Complete RMA', exact: true }).click();
      const completeDialog = inspector.page.getByRole('dialog');
      await completeDialog.getByRole('button', { name: 'Confirm Complete', exact: true }).click();
      await expect(inspector.page.getByText('Completed', { exact: true }).first()).toBeVisible({ timeout: 20_000 });
    } finally {
      await inspector.context.close();
    }
  });
});
