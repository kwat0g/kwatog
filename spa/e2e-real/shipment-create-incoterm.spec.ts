import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, test, type BrowserContext, type Page } from '@playwright/test';
import { baseUrl, loginAs, statefulCsrfHeaders, statefulHeaders } from './helpers';

const tag = process.env.E2E_SHIPMENT_TAG;
if (!tag || !/^[a-z0-9-]{1,6}$/.test(tag)) throw new Error('Set E2E_SHIPMENT_TAG to an unused 1–6 character lowercase tag.');
const fixture = JSON.parse(readFileSync(resolve(`../api/storage/app/shipment-create-e2e-${tag}.json`), 'utf8')) as {
  tag: string;
  po: string;
  po_number: string;
  po_item: string;
  item: string;
  incoterm: string;
};
if (fixture.tag !== tag) throw new Error(`Expected shipment fixture ${tag}; loaded ${fixture.tag ?? 'untagged fixture'}.`);

let impexContext: BrowserContext;
let adminContext: BrowserContext;
let impex: Page;
let admin: Page;
const errors: string[] = [];
const expectedConsoleErrors: string[] = [];
let expectedOptionsFailure = false;
let expectedPurchaseOrderFailure = false;

async function readData<T>(page: Page, path: string): Promise<T> {
  const response = await page.request.get(`${baseUrl()}${path}`, { headers: statefulHeaders() });
  const body = await response.text();
  expect(response.status(), `${path}: ${body}`).toBe(200);
  return (JSON.parse(body) as { data: T }).data;
}

type AuditEntry = {
  action: string;
  user: { email: string } | null;
  new_values: Record<string, unknown> | null;
};

type ShipmentState = {
  id: string;
  status: string;
  freight_cost: string;
  insurance_cost: string;
  duties_amount: string;
  brokerage_fee: string;
  other_charges: string;
  landed_cost_total: string | null;
  allocation_method: string | null;
  landed_cost_calculated_at: string | null;
  landed_costs: Array<{
    purchase_order_item: { id: string; description: string } | null;
    allocated_freight: string;
    allocated_insurance: string;
    allocated_duties: string;
    allocated_brokerage: string;
    allocated_other: string;
    total_allocated: string;
  }>;
  container_number: string | null;
  incoterm: string | null;
  atd: string | null;
  customs_clearance_date: string | null;
  ata: string | null;
  goods_receipt_note: { id: string; grn_number: string; status: string; received_date: string | null } | null;
  documents: Array<{ id: string; document_type: string; original_filename: string; deleted_at: string | null }>;
};

type ContainerRow = {
  id: string;
  container_number: string;
  seal_number: string | null;
  gross_weight_kg: number | null;
  deleted_at: string | null;
};

test.beforeAll(async ({ browser }) => {
  impexContext = await browser.newContext({ baseURL: baseUrl() });
  adminContext = await browser.newContext({ baseURL: baseUrl() });
  impex = await impexContext.newPage();
  admin = await adminContext.newPage();
  impex.setDefaultTimeout(30_000);
  admin.setDefaultTimeout(30_000);
  for (const [role, page] of [['impex', impex], ['admin', admin]] as const) {
    page.on('console', (message) => {
      if (message.type() !== 'error') return;
      const text = message.text();
      if (
        text.includes('503 (Service Unavailable)') &&
        (expectedOptionsFailure || expectedPurchaseOrderFailure)
      ) {
        expectedConsoleErrors.push(`${role}: ${text}`);
        return;
      }
      errors.push(`${role}: ${text}`);
    });
    page.on('pageerror', (error) => errors.push(`${role}: ${error.message}`));
    page.on('requestfailed', (request) => {
      if (request.url().includes('/api/')) errors.push(`${role}: ${request.method()} ${request.url()}: ${request.failure()?.errorText ?? 'request failed'}`);
    });
    page.on('response', (response) => {
      const expectedFailure = (expectedOptionsFailure
        && response.url().endsWith('/api/v1/supply-chain/shipments/options'))
        || (expectedPurchaseOrderFailure && response.url().includes('/api/v1/supply-chain/shipments/purchase-order-options'));
      if (response.url().includes('/api/') && response.status() >= 500 && !expectedFailure) {
        errors.push(`${role}: ${response.status()} ${response.url()}`);
      }
    });
  }
  await loginAs(impex, 'impex@ogami.test', 'password');
  await loginAs(admin, 'admin@ogami.test', 'password');
});

test.afterAll(async () => {
  await impexContext.close();
  await adminContext.close();
  expect([...new Set(errors)]).toEqual([]);
});

test('purchase order lookup failure can be retried', async () => {
  expectedPurchaseOrderFailure = true;
  const consoleErrorsBefore = expectedConsoleErrors.length;
  await impex.route('**/api/v1/supply-chain/shipments/purchase-order-options**', (route) =>
    route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'Simulated PO failure' }) }),
  );
  const failedLookupPromise = impex.waitForResponse((response) =>
    response.url().includes('/api/v1/supply-chain/shipments/purchase-order-options') && response.request().method() === 'GET',
  );
  await impex.goto('/supply-chain/shipments/create', { waitUntil: 'domcontentloaded' });
  expect((await failedLookupPromise).status()).toBe(503);
  await expect(impex.getByText('Failed to load purchase orders.')).toBeVisible();
  await expect.poll(() => expectedConsoleErrors.length).toBeGreaterThan(consoleErrorsBefore);
  const purchaseOrder = impex.getByRole('combobox', { name: 'Purchase order' });
  await expect(purchaseOrder).toBeDisabled();

  await impex.unroute('**/api/v1/supply-chain/shipments/purchase-order-options**');
  const retryPromise = impex.waitForResponse((response) =>
    response.url().includes('/api/v1/supply-chain/shipments/purchase-order-options') && response.request().method() === 'GET',
  );
  await impex.getByRole('button', { name: 'Retry purchase orders', exact: true }).click();
  expect((await retryPromise).status()).toBe(200);
  expectedPurchaseOrderFailure = false;
  await purchaseOrder.click();
  await expect(impex.getByRole('option').filter({ hasText: fixture.po_number })).toBeVisible();
  await purchaseOrder.press('Escape');
});

test('Impex completes gated shipment handoff through the real UI', async () => {
  await impex.goto('/supply-chain/shipments/create', { waitUntil: 'domcontentloaded' });
  await expect(impex.getByRole('heading', { name: 'New shipment' })).toBeVisible();
  const purchaseOrder = impex.getByRole('combobox', { name: 'Purchase order' });
  await purchaseOrder.fill(fixture.po_number);
  await impex.getByRole('option').filter({ hasText: fixture.po_number }).click();
  await expect(purchaseOrder).toHaveValue(new RegExp(fixture.po_number));

  const freight = impex.getByLabel('Freight cost', { exact: true });
  expect(await freight.count()).toBe(1);
  await freight.fill('10.00');
  await impex.getByLabel('Insurance cost', { exact: true }).fill('2.00');
  await impex.getByLabel('Duties', { exact: true }).fill('4.00');
  await impex.getByLabel('Brokerage fee', { exact: true }).fill('1.00');
  await impex.getByLabel('Other charges', { exact: true }).fill('3.00');

  const incoterm = impex.getByLabel('Incoterm');
  await expect(incoterm).toHaveValue(fixture.incoterm);
  await expect(incoterm).toHaveAttribute('readonly', '');
  const firstContainer = `TCLU${tag.toUpperCase().padEnd(7, '0')}`;
  const secondContainer = `FCIU${tag.toUpperCase().padEnd(7, '1')}`;
  await impex.getByLabel('Container number').fill(firstContainer);
  expectedOptionsFailure = true;
  const consoleErrorsBefore = expectedConsoleErrors.length;
  await impex.route('**/api/v1/supply-chain/shipments/options', (route) =>
    route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'Simulated options failure' }) }),
  );
  const optionsFailurePromise = impex.waitForResponse((response) =>
    response.url().endsWith('/api/v1/supply-chain/shipments/options') && response.request().method() === 'GET',
  );

  const createResponsePromise = impex.waitForResponse((response) =>
    response.url().endsWith('/api/v1/supply-chain/shipments') && response.request().method() === 'POST',
  );
  await impex.getByRole('button', { name: 'Create shipment', exact: true }).click();
  const createResponse = await createResponsePromise;
  expect(createResponse.status(), await createResponse.text()).toBe(201);
  expect((await optionsFailurePromise).status()).toBe(503);
  await expect.poll(() => expectedConsoleErrors.length).toBeGreaterThan(consoleErrorsBefore);
  const created = (await createResponse.json()).data;
  expect(created).toMatchObject({
    status: 'ordered',
    incoterm: fixture.incoterm,
    freight_cost: '10.00',
    insurance_cost: '2.00',
    duties_amount: '4.00',
    brokerage_fee: '1.00',
    other_charges: '3.00',
    container_number: firstContainer,
    purchase_order: { id: fixture.po, po_number: fixture.po_number },
  });

  await expect(impex).toHaveURL(new RegExp(`/supply-chain/shipments/${created.id}$`));
  await expect(impex.getByText(fixture.incoterm, { exact: true })).toBeVisible();
  await expect(impex.getByRole('heading', { name: 'Shipment containers' })).toBeVisible();
  await expect(impex.getByText('Shipment options failed to load. Status, document, and container actions are unavailable.'))
    .toBeVisible();
  await expect(impex.getByRole('button', { name: 'Add container', exact: true })).toBeDisabled();
  await expect(impex.getByLabel('Document type')).toBeDisabled();
  await expect(impex.locator('input[type="file"]')).toBeDisabled();
  await impex.unroute('**/api/v1/supply-chain/shipments/options');
  const optionsRetryPromise = impex.waitForResponse((response) =>
    response.url().endsWith('/api/v1/supply-chain/shipments/options') && response.request().method() === 'GET',
  );
  await impex.getByRole('button', { name: 'Retry shipment options', exact: true }).click();
  expect((await optionsRetryPromise).status()).toBe(200);
  expectedOptionsFailure = false;
  await expect(impex.getByRole('button', { name: 'Mark Shipped', exact: true })).toBeVisible();
  const shipmentPath = `/api/v1/supply-chain/shipments/${created.id}`;
  const state = () => readData<ShipmentState>(impex, shipmentPath);
  const shipmentAudit = () => readData<AuditEntry[]>(
    admin,
    `/api/v1/admin/audit-logs/entity?model_type=Shipment&model_id=${encodeURIComponent(created.id)}&per_page=100`,
  );
  const containers = () => readData<ContainerRow[]>(impex, `${shipmentPath}/containers?trashed=with`);
  const observed = await state();
  expect(observed).toMatchObject({
    id: created.id,
    status: 'ordered',
    container_number: firstContainer,
    incoterm: fixture.incoterm,
    freight_cost: '10.00',
    insurance_cost: '2.00',
    duties_amount: '4.00',
    brokerage_fee: '1.00',
    other_charges: '3.00',
    purchase_order: { id: fixture.po, po_number: fixture.po_number },
    goods_receipt_note: null,
  });
  expect(observed.landed_costs).toEqual([]);
  await impex.getByLabel('Allocation method', { exact: true }).selectOption('by_value');
  const calculatePromise = impex.waitForResponse((response) =>
    response.url().endsWith(`${shipmentPath}/calculate-landed-cost`)
    && response.request().method() === 'POST',
  );
  await impex.getByRole('button', { name: 'Calculate landed costs', exact: true }).click();
  const calculateResponse = await calculatePromise;
  expect(calculateResponse.status(), await calculateResponse.text()).toBe(200);
  const calculated = await state();
  expect(calculated).toMatchObject({
    landed_cost_total: '20.00',
    allocation_method: 'by_value',
    landed_costs: [expect.objectContaining({
      purchase_order_item: expect.objectContaining({ id: fixture.po_item }),
      allocated_freight: '10.00',
      allocated_insurance: '2.00',
      allocated_duties: '4.00',
      allocated_brokerage: '1.00',
      allocated_other: '3.00',
      total_allocated: '20.00',
    })],
  });
  expect(calculated.landed_cost_calculated_at).not.toBeNull();
  expect(await shipmentAudit()).toEqual(expect.arrayContaining([
    expect.objectContaining({
      action: 'updated',
      user: expect.objectContaining({ email: 'impex@ogami.test' }),
      new_values: expect.objectContaining({ landed_cost_total: '20.00' }),
    }),
  ]));

  await impex.getByRole('button', { name: 'Edit landed costs', exact: true }).click();
  await impex.getByRole('spinbutton', { name: 'Other charges', exact: true }).fill('5.00');
  const updateCostsPromise = impex.waitForResponse((response) =>
    response.url().endsWith(shipmentPath) && response.request().method() === 'PATCH',
  );
  await impex.getByRole('button', { name: 'Save landed costs', exact: true }).click();
  const updateCosts = await updateCostsPromise;
  expect(updateCosts.status(), await updateCosts.text()).toBe(200);
  const updatedCosts = await state();
  expect(updatedCosts).toMatchObject({
    other_charges: '5.00',
    landed_cost_total: '22.00',
    allocation_method: 'by_value',
    landed_costs: [expect.objectContaining({
      allocated_other: '5.00',
      total_allocated: '22.00',
    })],
  });
  expect(await shipmentAudit()).toEqual(expect.arrayContaining([
    expect.objectContaining({
      action: 'updated',
      user: expect.objectContaining({ email: 'impex@ogami.test' }),
      new_values: expect.objectContaining({ other_charges: '5.00' }),
    }),
  ]));
  const initialContainers = await containers();
  expect(initialContainers).toEqual([
    expect.objectContaining({ container_number: firstContainer, deleted_at: null }),
  ]);
  const initialContainerAudit = await readData<AuditEntry[]>(
    admin,
    `/api/v1/admin/audit-logs/entity?model_type=Container&model_id=${encodeURIComponent(initialContainers[0].id)}&per_page=100`,
  );
  expect(initialContainerAudit).toEqual(expect.arrayContaining([
    expect.objectContaining({ action: 'created', user: expect.objectContaining({ email: 'impex@ogami.test' }) }),
  ]));

  const shipmentEventsBeforeSkip = await shipmentAudit();
  const skipped = await impex.request.patch(`${baseUrl()}${shipmentPath}/status`, {
    headers: await statefulCsrfHeaders(impex),
    data: { status: 'received' },
  });
  expect(skipped.status(), await skipped.text()).toBe(422);
  expect(await state()).toMatchObject({ status: 'ordered', goods_receipt_note: null });
  expect(await shipmentAudit()).toHaveLength(shipmentEventsBeforeSkip.length);

  await impex.getByRole('button', { name: 'Add container', exact: true }).click();
  const addContainerDialog = impex.getByRole('dialog');
  await addContainerDialog.getByLabel('Container number').fill(secondContainer);
  await expect(addContainerDialog.getByLabel('Container size')).toHaveValue('40ft');
  await expect(addContainerDialog.getByLabel('Container type')).toHaveValue('dry');
  const addContainerResponsePromise = impex.waitForResponse((response) =>
    response.url().endsWith(`${shipmentPath}/containers`) && response.request().method() === 'POST',
  );
  await addContainerDialog.getByRole('button', { name: 'Save container', exact: true }).click();
  const addContainerResponse = await addContainerResponsePromise;
  expect(addContainerResponse.status(), await addContainerResponse.text()).toBe(201);
  const second = (await addContainerResponse.json()).data as { id: string };
  expect(await containers()).toEqual(expect.arrayContaining([
    expect.objectContaining({ container_number: firstContainer, deleted_at: null }),
    expect.objectContaining({ id: second.id, container_number: secondContainer, deleted_at: null }),
  ]));

  let containerAudit = await readData<AuditEntry[]>(
    admin,
    `/api/v1/admin/audit-logs/entity?model_type=Container&model_id=${encodeURIComponent(second.id)}&per_page=100`,
  );
  expect(containerAudit).toEqual(expect.arrayContaining([
    expect.objectContaining({ action: 'created', user: expect.objectContaining({ email: 'impex@ogami.test' }) }),
  ]));

  await impex.getByRole('button', { name: `Edit container ${secondContainer}` }).click();
  const editContainerDialog = impex.getByRole('dialog');
  await editContainerDialog.getByLabel('Seal number').fill(`SEAL-${tag.toUpperCase()}`);
  await editContainerDialog.getByLabel('Gross weight (kg)').fill('25400.00');
  const editContainerResponsePromise = impex.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/supply-chain/containers/${second.id}`) && response.request().method() === 'PUT',
  );
  await editContainerDialog.getByRole('button', { name: 'Save container', exact: true }).click();
  const editContainerResponse = await editContainerResponsePromise;
  expect(editContainerResponse.status(), await editContainerResponse.text()).toBe(200);
  expect(await containers()).toEqual(expect.arrayContaining([
    expect.objectContaining({ id: second.id, seal_number: `SEAL-${tag.toUpperCase()}`, gross_weight_kg: 25400 }),
  ]));

  await impex.getByRole('button', { name: `Archive container ${secondContainer}` }).click();
  const archiveContainerDialog = impex.getByRole('dialog');
  const archiveContainerResponsePromise = impex.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/supply-chain/containers/${second.id}`) && response.request().method() === 'DELETE',
  );
  await archiveContainerDialog.getByRole('button', { name: 'Archive', exact: true }).click();
  const archiveContainerResponse = await archiveContainerResponsePromise;
  expect(archiveContainerResponse.status()).toBe(204);
  const archivedRows = await containers();
  expect(archivedRows.find((row) => row.id === second.id)?.deleted_at).not.toBeNull();
  expect(archivedRows.filter((row) => row.deleted_at === null)).toHaveLength(1);

  const restoreContainerResponsePromise = impex.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/supply-chain/containers/${second.id}/restore`) && response.request().method() === 'PATCH',
  );
  await impex.getByRole('button', { name: 'Restore', exact: true }).click();
  expect((await restoreContainerResponsePromise).status()).toBe(200);
  expect((await containers()).filter((row) => row.deleted_at === null)).toHaveLength(2);
  containerAudit = await readData<AuditEntry[]>(
    admin,
    `/api/v1/admin/audit-logs/entity?model_type=Container&model_id=${encodeURIComponent(second.id)}&per_page=100`,
  );
  expect(containerAudit.map((row) => row.action)).toEqual(expect.arrayContaining(['created', 'updated', 'deleted']));

  const primaryUpdated = `CMAU${tag.toUpperCase().padEnd(7, '2')}`;
  await impex.getByRole('button', { name: `Edit container ${firstContainer}` }).click();
  const editPrimaryDialog = impex.getByRole('dialog');
  await editPrimaryDialog.getByLabel('Container number').fill(primaryUpdated);
  const editPrimaryResponsePromise = impex.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/supply-chain/containers/${initialContainers[0].id}`)
      && response.request().method() === 'PUT',
  );
  await editPrimaryDialog.getByRole('button', { name: 'Save container', exact: true }).click();
  expect((await editPrimaryResponsePromise).status()).toBe(200);
  expect(await state()).toMatchObject({ container_number: primaryUpdated });
  expect(await readData<Array<{ id: string }>>(impex, `/api/v1/supply-chain/shipments?search=${primaryUpdated}`))
    .toEqual(expect.arrayContaining([expect.objectContaining({ id: created.id })]));

  await impex.getByRole('button', { name: `Archive container ${primaryUpdated}` }).click();
  const archivePrimaryDialog = impex.getByRole('dialog');
  const archivePrimaryResponsePromise = impex.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/supply-chain/containers/${initialContainers[0].id}`)
      && response.request().method() === 'DELETE',
  );
  await archivePrimaryDialog.getByRole('button', { name: 'Archive', exact: true }).click();
  expect((await archivePrimaryResponsePromise).status()).toBe(204);
  expect(await state()).toMatchObject({ container_number: secondContainer });
  expect(await readData<Array<{ id: string }>>(impex, `/api/v1/supply-chain/shipments?search=${primaryUpdated}`))
    .not.toEqual(expect.arrayContaining([expect.objectContaining({ id: created.id })]));

  const restorePrimaryResponsePromise = impex.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/supply-chain/containers/${initialContainers[0].id}/restore`)
      && response.request().method() === 'PATCH',
  );
  await impex.getByRole('button', { name: 'Restore', exact: true }).click();
  expect((await restorePrimaryResponsePromise).status()).toBe(200);
  expect(await state()).toMatchObject({ container_number: primaryUpdated });

  const evidence = async (documentType: string) => {
    const filename = `${documentType}-${tag}.pdf`;
    await impex.getByLabel('Document type').selectOption(documentType);
    const uploadPromise = impex.waitForResponse((response) =>
      response.url().endsWith(`${shipmentPath}/documents`) && response.request().method() === 'POST',
    );
    await impex.locator('input[type="file"]').setInputFiles({
      name: filename,
      mimeType: 'application/pdf',
      buffer: Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n'),
    });
    const upload = await uploadPromise;
    expect(upload.status(), await upload.text()).toBe(201);
    const current = await state();
    const document = current.documents.find((candidate) => candidate.document_type === documentType);
    expect(document).toMatchObject({ document_type: documentType, original_filename: filename, deleted_at: null });
    return document!;
  };

  const bill = await evidence('bill_of_lading');
  await impex.getByRole('button', { name: `Archive document ${bill.original_filename}` }).click();
  const archiveDocumentDialog = impex.getByRole('dialog').last();
  const archiveDocumentPromise = impex.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/supply-chain/shipment-documents/${bill.id}`) && response.request().method() === 'DELETE',
  );
  await archiveDocumentDialog.getByRole('button', { name: 'Archive', exact: true }).click();
  expect((await archiveDocumentPromise).status()).toBe(204);
  expect((await state()).documents.find((document) => document.id === bill.id)?.deleted_at).not.toBeNull();

  await impex.getByRole('button', { name: `Restore document ${bill.original_filename}` }).click();
  const restoreDocumentDialog = impex.getByRole('dialog').last();
  const restoreDocumentPromise = impex.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/supply-chain/shipment-documents/${bill.id}/restore`) && response.request().method() === 'PATCH',
  );
  await restoreDocumentDialog.getByRole('button', { name: 'Restore', exact: true }).click();
  expect((await restoreDocumentPromise).status()).toBe(200);
  expect((await state()).documents.find((document) => document.id === bill.id)?.deleted_at).toBeNull();

  await evidence('commercial_invoice');
  await evidence('packing_list');
  await evidence('import_entry');

  const transition = async (next: string, label: string, stamp?: keyof ShipmentState) => {
    await impex.getByRole('button', { name: `Mark ${label}`, exact: true }).click();
    const statusDialog = impex.getByRole('dialog');
    const responsePromise = impex.waitForResponse((response) =>
      response.url().endsWith(`${shipmentPath}/status`) && response.request().method() === 'PATCH',
    );
    await statusDialog.getByRole('button', { name: 'Confirm', exact: true }).click();
    const response = await responsePromise;
    expect(response.status(), await response.text()).toBe(200);
    const current = await state();
    expect(current.status).toBe(next);
    if (stamp) expect(current[stamp]).not.toBeNull();
    const events = await shipmentAudit();
    expect(events).toEqual(expect.arrayContaining([
      expect.objectContaining({
        action: 'updated',
        user: expect.objectContaining({ email: 'impex@ogami.test' }),
        new_values: expect.objectContaining({ status: next }),
      }),
    ]));
    return { current, events };
  };

  await transition('shipped', 'Shipped', 'atd');
  const afterShipped = await state();
  const afterShippedEvents = await shipmentAudit();
  const repeated = await impex.request.patch(`${baseUrl()}${shipmentPath}/status`, {
    headers: await statefulCsrfHeaders(impex),
    data: { status: 'shipped' },
  });
  expect(repeated.status(), await repeated.text()).toBe(422);
  expect(await state()).toMatchObject({ status: 'shipped', atd: afterShipped.atd });
  expect(await shipmentAudit()).toHaveLength(afterShippedEvents.length);

  await transition('in_transit', 'In transit');
  await transition('customs', 'Customs');
  const beforeClear = await state();
  const beforeClearEvents = await shipmentAudit();
  await expect(impex.getByRole('button', { name: 'Mark Cleared', exact: true })).toBeDisabled();
  await expect(impex.getByRole('status').getByText(/Upload .*boc release/i)).toBeVisible();
  const rejectedClear = await impex.request.patch(`${baseUrl()}${shipmentPath}/status`, {
    headers: await statefulCsrfHeaders(impex),
    data: { status: 'cleared' },
  });
  expect(rejectedClear.status()).toBe(422);
  expect(await rejectedClear.text()).toContain('boc_release');
  expect(await state()).toMatchObject({ status: 'customs', customs_clearance_date: null });
  expect(await shipmentAudit()).toHaveLength(beforeClearEvents.length);
  expect(beforeClear.status).toBe('customs');

  await evidence('boc_release');
  await expect(impex.getByRole('button', { name: 'Mark Cleared', exact: true })).toBeEnabled();
  await transition('cleared', 'Cleared', 'customs_clearance_date');
  const { current: received } = await transition('received', 'Received', 'ata');
  expect(received.goods_receipt_note).toMatchObject({ status: 'draft', received_date: null });
  await expect(impex.getByText(received.goods_receipt_note!.grn_number, { exact: true })).toBeVisible();

  const grn = await readData<{
    id: string;
    status: string;
    received_date: string | null;
    items: Array<{ quantity_received: string; quantity_accepted: string }>;
  }>(admin, `/api/v1/inventory/grn/${received.goods_receipt_note!.id}`);
  expect(grn).toMatchObject({ status: 'draft', received_date: null });
  expect(grn.items).toHaveLength(1);
  expect(Number(grn.items[0].quantity_received)).toBe(0);
  expect(Number(grn.items[0].quantity_accepted)).toBe(0);
  expect(await readData<Array<unknown>>(admin, `/api/v1/inventory/stock-movements?item_id=${fixture.item}&per_page=100`)).toHaveLength(0);

  const beforeRepeatReceive = await shipmentAudit();
  const repeatedReceive = await impex.request.patch(`${baseUrl()}${shipmentPath}/status`, {
    headers: await statefulCsrfHeaders(impex),
    data: { status: 'received' },
  });
  expect(repeatedReceive.status()).toBe(422);
  expect(await shipmentAudit()).toHaveLength(beforeRepeatReceive.length);
  expect((await state()).goods_receipt_note?.id).toBe(received.goods_receipt_note?.id);

});
