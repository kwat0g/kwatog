// Real-API acceptance check. Requires tests/Browser/return_case_fixture.php in an isolated DB.
// NODE_PATH=./spa/node_modules node scripts/return-management-headless.cjs
const { chromium, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const { randomUUID } = require('node:crypto');
const BASE = process.env.RETURN_TEST_URL || 'http://127.0.0.1:5210';
const OUT = process.env.RETURN_TEST_OUTPUT || '/tmp/ogami-return-headless';
const fixture = JSON.parse(fs.readFileSync(process.env.RETURN_TEST_FIXTURE || '/tmp/return-case-role-fixture.json', 'utf8'));
fs.mkdirSync(OUT, { recursive: true });
const stateFile = path.join(OUT, 'state.json');
const state = fs.existsSync(stateFile) ? JSON.parse(fs.readFileSync(stateFile, 'utf8')) : {};
const previousReport = process.env.RETURN_TEST_PHASE === 'customer' && fs.existsSync(path.join(OUT, 'report.json')) ? JSON.parse(fs.readFileSync(path.join(OUT, 'report.json'), 'utf8')) : null;
const checks = previousReport?.checks ?? [];
const findings = state.findings || [];
state.findings = findings;
const pages = [];
const errors = [];
const save = () => fs.writeFileSync(stateFile, JSON.stringify(state, null, 2));
const pass = (name) => { if (!checks.includes(name)) checks.push(name); console.log('PASS:', name); };
const internal = '/return-management/cases';
async function api(page, method, url, body) {
  const result = await page.evaluate(async ({ method, url, body }) => {
    const xsrf = document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='))?.slice(11);
    const response = await fetch('/api/v1' + url, { method, credentials: 'include', headers: {
      Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest',
      ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
    }, ...(body ? { body: JSON.stringify(body) } : {}) });
    const data = await response.json();
    return { status: response.status, body: data };
  }, { method, url, body });
  return result;
}
async function ok(page, method, url, body) {
  const result = await api(page, method, url, body);
  if (result.status < 200 || result.status >= 300) throw new Error(`${method} ${url}: ${result.status} ${JSON.stringify(result.body).slice(0,1800)}`);
  return result.body.data ?? result.body;
}
async function login(browser, email, mobile = false) {
  const context = await browser.newContext({ baseURL: BASE, viewport: mobile ? { width: 390, height: 844 } : { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
  const page = await context.newPage();
  page.setDefaultTimeout(20000);
  pages.push({ page, email });
  page.on('pageerror', (e) => errors.push(`${email}: ${e.message}`));
  await page.goto('/sign-in');
  await page.getByLabel('Email', { exact: true }).fill(email);
  await page.getByLabel('Password', { exact: true }).fill(process.env.RETURN_TEST_PASSWORD || 'password');
  const response = page.waitForResponse((r) => r.url().endsWith('/api/v1/auth/sign-in') && r.request().method() === 'POST');
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  const signedIn = await response;
  expect(signedIn.status(), `${email} login: ${await signedIn.text()}`).toBe(200);
  await expect(page).not.toHaveURL(/\/sign-in$/);
  pass(`Real cookie login: ${email}`);
  return page;
}
async function act(page, id, body) { return ok(page, 'POST', `${internal}/${id}/actions`, body); }
async function show(page, id) { return ok(page, 'GET', `${internal}/${id}`); }
async function casePage(page, id, realm = 'internal') {
  await page.goto(realm === 'internal' ? `${internal}/${id}` : `/portal/${realm}/problems/${id}`);
  await expect(page.getByRole('heading', { name: 'Reported problem', exact: true })).toBeVisible();
}
async function submitIntake(page, kind, source, realm, expected, received, defective) {
  const url = realm === 'customer' ? '/portal/customer/problems' : internal;
  await page.goto(`${url}/new?source_kind=${kind}&source_id=${source}`);
  await expect(page.getByText('Affected goods', { exact: true })).toBeVisible();
  await page.getByRole('checkbox').first().check();
  if (expected !== null) await page.getByLabel('Expected for this shipment').fill(expected);
  for (const [label, value] of [['Actually received', received], ['Damaged or defective', defective]]) {
    const input = page.getByLabel(label, { exact: true });
    if (await input.isDisabled()) expect(Number(await input.inputValue())).toBe(Number(value));
    else await input.fill(value);
  }
  await page.getByLabel('Explain the problem').fill(realm === 'customer' ? 'Ten parts missing and two damaged when unpacked.' : (Number(defective) > 0 ? 'One kilogram of resin is contaminated.' : 'Ten kilograms missing from the supplier shipment.'));
  await page.getByLabel('Preferred outcome').selectOption(realm === 'customer' ? 'credit' : 'redelivery');
  if (realm === 'customer') await page.locator('input[type=file]').setInputFiles({ name: 'delivery-evidence.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF') });
  const response = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith(realm === 'customer' ? '/api/v1/b2b/customer/problems' : '/api/v1/return-management/cases'));
  await page.getByRole('button', { name: 'Submit report', exact: true }).click();
  const submitted = await response;
  const body = await submitted.json();
  expect(submitted.status(), JSON.stringify(body)).toBe(201);
  await expect(page).toHaveURL(new RegExp(`${url}/${body.data.id}$`));
  return body.data.id;
}
async function agreeViaUi(page, id, resolution) {
  await casePage(page, id);
  await page.getByRole('button', { name: 'Agree action', exact: true }).click();
  await page.getByLabel('Agreed action and next step').fill(resolution === 'redelivery' ? 'Supplier will send the missing quantity in separate shipments.' : 'Verify the missing and damaged goods; Finance will review the credit.');
  await page.getByRole('combobox', { name: /^Resolution/ }).selectOption(resolution);
  const response = page.waitForResponse((r) => r.url().endsWith(`/cases/${id}/actions`) && r.request().method() === 'POST');
  await page.getByRole('button', { name: 'Record agreement' }).click();
  const saved = await response;
  expect(saved.status(), await saved.text()).toBe(200);
}
async function linkViaUi(page, id, grn) {
  await casePage(page, id);
  await page.getByRole('button', { name: 'Link completed work', exact: true }).first().click();
  await page.getByRole('checkbox', { name: grn.grn_number, exact: true }).check();
  const response = page.waitForResponse((r) => r.url().endsWith(`/cases/${id}/actions`) && r.request().method() === 'POST');
  await page.getByRole('button', { name: 'Save links', exact: true }).click();
  const saved = await response;
  expect(saved.status(), await saved.text()).toBe(200);
  return (await saved.json()).data;
}
async function approveReturn(browser, manager, rmaId, checker) {
  let rma = await ok(manager, 'GET', `/return-management/return-requests/${rmaId}`);
  if (rma.status === 'draft') {
    await ok(manager, 'POST', `/return-management/return-requests/${rmaId}/submit`);
    expect((await api(manager, 'POST', `/return-management/return-requests/${rmaId}/approve`)).status).toBeGreaterThanOrEqual(400);
    const head = await login(browser, 'depthead@ogami.test');
    await ok(head, 'POST', `/return-management/return-requests/${rmaId}/approve`, { remarks: 'Verified source and agreed return quantity.' });
    await head.context().close();
    await ok(checker, 'POST', `/return-management/return-requests/${rmaId}/approve`);
    pass('Physical return follows two independent approval steps');
  }
}
async function receiveSplit(warehouse, rmaId, first, second) {
  const url = `/return-management/return-requests/${rmaId}`;
  let rma = await ok(warehouse, 'GET', url);
  if (rma.status !== 'approved') return rma;
  const line = rma.items[0];
  if (Number(line.returned_quantity) === 0) {
    const shortFinal = await api(warehouse, 'POST', url + '/receive', { received_quantities: { [line.id]: first }, final_receipt: true, request_key: randomUUID(), quarantine_location_id: fixture.quarantine });
    expect(shortFinal.status, JSON.stringify(shortFinal.body)).toBe(422);
    expect(Number((await ok(warehouse, 'GET', url)).items[0].returned_quantity)).toBe(0);
    const payload = { received_quantities: { [line.id]: first }, final_receipt: false, request_key: randomUUID(), quarantine_location_id: fixture.quarantine };
    const partial = await ok(warehouse, 'POST', url + '/receive', payload);
    const retry = await ok(warehouse, 'POST', url + '/receive', payload);
    expect(Number(partial.items[0].returned_quantity)).toBe(Number(first));
    expect(Number(retry.items[0].returned_quantity)).toBe(Number(first));
    expect(retry.receipts).toHaveLength(1);
  }
  rma = await ok(warehouse, 'POST', url + '/receive', { received_quantities: { [line.id]: second }, final_receipt: true, request_key: randomUUID(), quarantine_location_id: fixture.quarantine });
  expect(rma.status).toBe('received');
  expect(rma.receipts).toHaveLength(2);
  pass('Short final receipt is blocked; split receipts and response-loss retry count goods once');
  return rma;
}
async function finishReturn(manager, qc, checker, rmaId, disposition) {
  const url = `/return-management/return-requests/${rmaId}`;
  let rma = await ok(manager, 'GET', url);
  if (rma.status === 'received') rma = await ok(qc, 'POST', url + '/inspect', { internal_notes: 'Returned goods verified against the case.' });
  for (const row of rma.inspections || []) {
    let inspection = await ok(qc, 'GET', `/quality/inspections/${row.id}`);
    if (['draft', 'in_progress'].includes(inspection.status)) {
      await ok(qc, 'PATCH', `/quality/inspections/${row.id}/measurements`, { measurements: inspection.measurements.map((measurement) => ({ id: measurement.id, is_pass: true, notes: 'Condition assessed for return disposition.' })) });
      inspection = await ok(qc, 'POST', `/quality/inspections/${row.id}/complete`);
    }
    if (inspection.status === 'awaiting_review') {
      expect((await api(qc, 'PATCH', `/quality/inspections/${row.id}/review`, { decision: 'passed' })).status).toBe(403);
      await ok(checker, 'PATCH', `/quality/inspections/${row.id}/review`, { decision: 'passed', remarks: 'Independent check of returned goods.' });
    }
  }
  if (rma.status !== 'completed') {
    if (rma.disposition_status !== 'disposed') {
      if (rma.type === 'customer_return') {
        await manager.goto(`/return-management/${rmaId}`);
        await manager.getByRole('button', { name: 'Dispose Items', exact: true }).click();
        await manager.getByRole('combobox', { name: 'Disposition', exact: true }).selectOption(disposition);
        if (['restock', 'rework'].includes(disposition)) await manager.getByRole('combobox', { name: 'Restock location', exact: true }).selectOption(fixture.location);
        const response = manager.waitForResponse((res) => res.url().endsWith(`${rmaId}/dispose`) && res.request().method() === 'POST');
        await manager.getByRole('button', { name: 'Record Disposition', exact: true }).click();
        const disposed = await response;
        expect(disposed.status(), await disposed.text()).toBe(200);
        rma = await ok(manager, 'GET', url);
      } else rma = await ok(manager, 'POST', url + '/dispose', { dispositions: rma.items.map((line) => ({ item_id: line.id, disposition, notes: 'Agreed case disposition.' })), location_id: fixture.location, create_replacement_po: false });
    }
    const moved = rma.items[0].moved_quantity;
    rma = await ok(manager, 'POST', url + '/complete', {});
    expect(rma.items[0].moved_quantity).toBe(moved);
    expect(rma.status).toBe('completed');
  }
  pass(`${rma.type}: inspection, ${disposition}, and completion preserve the settled quantity`);
  return ok(manager, 'GET', url);
}
(async () => {
  const browser = await chromium.launch({ headless: true, args: ['--disable-dev-shm-usage', '--no-sandbox'] });
  try {
    if (process.env.RETURN_TEST_PHASE === 'links') {
      const service = await login(browser, 'customerservice@ogami.test');
      const record = await show(service, state.customerCase);
      const rma = await ok(service, 'GET', `/return-management/return-requests/${record.return_request.id}`);
      expect(rma.source_case.id).toBe(record.id);
      await service.goto(`/return-management/${rma.id}`);
      await service.getByRole('link', { name: `Problem: ${record.case_number}`, exact: true }).click();
      await expect(service).toHaveURL(new RegExp(`${internal}/${record.id}$`));
      pass('Internal RMA links back to the originating case');
      const customer = await login(browser, 'customer.return@ogami.test', true);
      await casePage(customer, record.id, 'customer');
      await customer.getByRole('link', { name: rma.rma_number, exact: true }).click();
      await expect(customer).toHaveURL(new RegExp(`/portal/customer/returns/${rma.id}$`));
      await customer.getByRole('link', { name: record.case_number, exact: true }).click();
      await expect(customer).toHaveURL(new RegExp(`/portal/customer/problems/${record.id}$`));
      await expect(customer.getByText('Report actions', { exact: true })).toHaveCount(0);
      pass('Customer follows case to physical return and back; closed case has no empty action panel');
      expect(errors).toEqual([]);
      fs.writeFileSync(path.join(OUT, 'links-report.json'), JSON.stringify({ headless: true, realApi: true, checks, errors }, null, 2));
      return;
    }
    let purchasing;
    let record;
    if (process.env.RETURN_TEST_PHASE !== 'customer') {
    purchasing = await login(browser, 'purchasing@ogami.test');
    if (!state.supplierCase) { state.supplierCase = await submitIntake(purchasing, 'purchase_order', fixture.po, 'internal', '10', '0', '0'); save(); }
    record = await show(purchasing, state.supplierCase);
    if (record.status === 'submitted') await agreeViaUi(purchasing, record.id, 'redelivery');
    pass('Purchasing submits and agrees a supplier shortage through the UI');
    const supplier = await login(browser, 'supplier.return@ogami.test');
    await casePage(supplier, state.supplierCase, 'supplier');
    await expect(supplier.getByRole('button', { name: 'Resolve case', exact: true })).toHaveCount(0);
    const denied = await api(supplier, 'POST', `/b2b/supplier/problems/${state.supplierCase}/actions`, { action: 'link_resolution', resolution_goods_receipt_note_ids: [] });
    expect(denied.status).toBe(403);
    if ((await show(purchasing, state.supplierCase)).status !== 'resolved') {
      await supplier.getByRole('button', { name: 'Acknowledge agreed action' }).click();
      await expect(supplier.getByText('Agreed action acknowledged.', { exact: true })).toBeVisible();
    }
    pass('Supplier can acknowledge but cannot settle its own case');
    const warehouse = await login(browser, 'warehouse@ogami.test');
    expect((await api(warehouse, 'POST', `${internal}/${state.supplierCase}/actions`, { action: 'resolve' })).status).toBe(403);
    pass('Warehouse cannot resolve a supplier case');
    const qc = await login(browser, 'qc@ogami.test');
    const checker = await login(browser, 'production@ogami.test');
    for (const quantity of ['4', '6']) {
      const key = `receipt${quantity}`;
      if (!state[key]) {
        const receipt = await ok(warehouse, 'POST', '/inventory/grn', { purchase_order_id: fixture.po, items: [{ purchase_order_item_id: fixture.po_line, item_id: fixture.item, location_id: fixture.location, quantity_received: quantity, received_uom_code: 'KG', unit_cost: '20.0000', material_lot_number: `HEADLESS-${quantity}` }] });
        state[key] = receipt.id; save();
      }
      let grn = await ok(warehouse, 'GET', `/inventory/grn/${state[key]}`);
      if (grn.status !== 'accepted') {
        await expect.poll(async () => { grn = await ok(warehouse, 'GET', `/inventory/grn/${state[key]}`); return grn.qc_inspection?.id; }, { timeout: 30000 }).toBeTruthy();
        let inspection = await ok(qc, 'GET', `/quality/inspections/${grn.qc_inspection.id}`);
        if (['draft', 'in_progress'].includes(inspection.status)) inspection = await ok(qc, 'POST', `/quality/inspections/${inspection.id}/lot-result`, { checklist: inspection.measurements.map((row) => ({ id: row.id, is_pass: true })), measurements: [], sample_defect_count: 0, complete: true });
        if (inspection.status === 'awaiting_review') {
          if (!state.qcSelfReviewChecked) {
            const review = await api(qc, 'PATCH', `/quality/inspections/${inspection.id}/review`, { decision: 'passed' });
            if (review.status === 200) {
              findings.push({ severity: 'high', area: 'Incoming QC', message: 'The QC user who recorded lot results could approve the same inspection (HTTP 200); inspector still referenced the warehouse creator.', inspection: inspection.id });
              inspection = review.body.data;
            } else expect(review.status).toBe(403);
            expect(review.status, 'The inspection result author must not review their own work').toBe(403);
            pass('Incoming QC result author cannot self-approve; independent checker can');
            state.qcSelfReviewChecked = true; save();
          }
          if (inspection.status === 'awaiting_review') await ok(checker, 'PATCH', `/quality/inspections/${inspection.id}/review`, { decision: 'passed', remarks: 'Accepted incoming replacement goods after independent review.' });
        }
        await expect.poll(async () => { grn = await ok(warehouse, 'GET', `/inventory/grn/${state[key]}`); return grn.status; }, { timeout: 30000 }).toBe('accepted');
      }
      pass(`Warehouse receives ${quantity} kg; completed QC makes it eligible for linking`);
      record = await show(purchasing, state.supplierCase);
      if (!record.resolution_receipts.some((receipt) => receipt.id === grn.id)) record = await linkViaUi(purchasing, state.supplierCase, grn);
      if (quantity === '4' && record.resolution_receipts.length === 1) {
        expect(record.lines[0].redelivered_quantity).toBe('4.000');
        expect(record.lines[0].remaining_redelivery_quantity).toBe('6.000');
        const response = purchasing.waitForResponse((r) => r.url().endsWith(`/cases/${state.supplierCase}/actions`) && r.request().method() === 'POST');
        await purchasing.getByRole('button', { name: 'Resolve case', exact: true }).click();
        expect((await response).status()).toBe(422);
        await expect(purchasing.getByRole('alert').first()).toBeVisible();
        await purchasing.screenshot({ path: path.join(OUT, 'purchasing-partial.png'), fullPage: true });
        state.partialVerified = true; save();
        pass('Partial receipt shows 4 received / 6 remaining and blocks early resolution');
      }
    }
    record = await show(purchasing, state.supplierCase);
    expect(state.partialVerified).toBe(true);
    expect(record.lines[0].redelivered_quantity).toBe('10.000');
    expect(record.lines[0].remaining_redelivery_quantity).toBe('0.000');
    expect(record.resolution_receipts).toHaveLength(2);
    if (record.status !== 'resolved') {
      const retry = await act(purchasing, record.id, { action: 'link_resolution', resolution_goods_receipt_note_ids: [state.receipt4, state.receipt6] });
      expect(retry.lines[0].redelivered_quantity).toBe('10.000');
      await casePage(purchasing, record.id);
      await purchasing.getByRole('button', { name: 'Resolve case', exact: true }).click();
      await expect.poll(async () => (await show(purchasing, record.id)).status).toBe('resolved');
    }
    pass('4 + 6 kg completes the shortage; retry cannot double-count accepted goods');
    await casePage(supplier, state.supplierCase, 'supplier');
    for (const receipt of record.resolution_receipts) await expect(supplier.getByText(receipt.grn_number, { exact: true })).toHaveCount(1);
    await supplier.screenshot({ path: path.join(OUT, 'supplier-resolved.png'), fullPage: true });
    pass('Supplier sees both receipts once and the completed case');
    if (!state.supplierDefectCase) { state.supplierDefectCase = await submitIntake(purchasing, 'grn', state.receipt4, 'internal', '4', '4', '1'); save(); }
    let defectCase = await show(purchasing, state.supplierDefectCase);
    if (defectCase.status === 'submitted') await agreeViaUi(purchasing, defectCase.id, 'return_goods');
    defectCase = await show(purchasing, defectCase.id);
    if (!defectCase.return_request) {
      await casePage(purchasing, defectCase.id);
      await purchasing.getByRole('button', { name: 'Create return request', exact: true }).first().click();
      const response = purchasing.waitForResponse((r) => r.url().endsWith(`/cases/${defectCase.id}/actions`) && r.request().method() === 'POST');
      await purchasing.getByRole('button', { name: 'Create return request', exact: true }).last().click();
      const created = await response;
      expect(created.status(), await created.text()).toBe(200);
      defectCase = await show(purchasing, defectCase.id);
    }
    const supplierRma = await ok(purchasing, 'GET', `/return-management/return-requests/${defectCase.return_request.id}`);
    expect(Number(supplierRma.items[0].quantity)).toBe(1);
    expect(supplierRma.type).toBe('supplier_return');
    await purchasing.screenshot({ path: path.join(OUT, 'supplier-defective-return.png'), fullPage: true });
    pass('Purchasing reports a raw-material defect against its GRN and prepares a 1 kg supplier return');
    await approveReturn(browser, purchasing, supplierRma.id, checker);
    await receiveSplit(warehouse, supplierRma.id, '0.400', '0.600');
    await finishReturn(purchasing, qc, checker, supplierRma.id, 'return_to_supplier');
    if ((await show(purchasing, defectCase.id)).status !== 'resolved') await act(purchasing, defectCase.id, { action: 'resolve' });
    pass('Supplier defective raw material is shipped back and its case closes');
    for (const page of [warehouse, qc, checker, supplier]) await page.context().close();
    }
    const customer = await login(browser, 'customer.return@ogami.test', true);
    if (!state.customerCase) { state.customerCase = await submitIntake(customer, 'delivery', fixture.delivery, 'customer', null, '90', '2'); save(); }
    await casePage(customer, state.customerCase, 'customer');
    const customerRecord = await ok(customer, 'GET', `/b2b/customer/problems/${state.customerCase}`);
    const submittedEvent = customerRecord.events.find((event) => event.action === 'submitted');
    if (submittedEvent && Math.abs(Date.parse(customerRecord.created_at) - Date.parse(submittedEvent.created_at)) > 60000 && !findings.some((finding) => finding.area === 'Case timeline')) {
      findings.push({ severity: 'medium', area: 'Case timeline', message: 'The submitted event appears eight hours before case creation; application and database-default timestamps use inconsistent time zones.', caseCreated: customerRecord.created_at, eventCreated: submittedEvent.created_at });
      save();
    }
    expect(Math.abs(Date.parse(customerRecord.created_at) - Date.parse(submittedEvent.created_at))).toBeLessThan(60000);
    pass('Submitted event and case creation agree on timezone');
    expect(customerRecord.lines[0].missing_quantity).toBe('10.000');
    expect(customerRecord.lines[0].defective_quantity).toBe('2.000');
    expect(customerRecord.attachments.length).toBeGreaterThan(0);
    expect(await customer.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await customer.screenshot({ path: path.join(OUT, 'customer-mobile-report.png'), fullPage: true });
    expect((await api(customer, 'GET', `/b2b/customer/problems/${state.supplierCase}`)).status).toBe(403);
    pass('Customer mobile reports missing + damaged goods, uploads evidence, and cannot access supplier cases');
    const service = await login(browser, 'customerservice@ogami.test');
    record = await show(service, state.customerCase);
    if (['submitted', 'information_needed', 'under_review'].includes(record.status)) {
      if (record.status === 'submitted') await act(service, record.id, { action: 'request_info', message: 'Please confirm the carton count.' });
      await casePage(customer, record.id, 'customer');
      await customer.getByRole('textbox', { name: /^Message/ }).fill('Nine cartons arrived; two parts were cracked.');
      await customer.getByRole('button', { name: 'Send reply', exact: true }).click();
      await expect(customer.getByText('Nine cartons arrived; two parts were cracked.', { exact: true })).toBeVisible();
      await agreeViaUi(service, record.id, 'credit');
    }
    record = await show(service, state.customerCase);
    if (!record.return_request) await act(service, record.id, { action: 'create_return' });
    record = await show(service, state.customerCase);
    const customerRma = await ok(service, 'GET', `/return-management/return-requests/${record.return_request.id}`);
    expect(Number(customerRma.items[0].quantity)).toBe(2);
    pass('Customer Service follows up, customer replies, and only the 2 damaged goods enter the physical return');
    if (purchasing) await purchasing.context().close();
    const finance = await login(browser, 'finance@ogami.test');
    await casePage(finance, state.customerCase);
    await expect(finance.getByRole('button', { name: 'Agree action', exact: true })).toHaveCount(0);
    record = await show(finance, state.customerCase);
    if (!record.credit_note) {
      await finance.getByRole('button', { name: 'Create credit note', exact: true }).click();
      const response = finance.waitForResponse((r) => r.url().endsWith(`/cases/${state.customerCase}/actions`) && r.request().method() === 'POST');
      await finance.getByRole('button', { name: 'Create credit note', exact: true }).last().click();
      const saved = await response;
      expect(saved.status(), await saved.text()).toBe(200);
    }
    record = await show(finance, state.customerCase);
    expect(record.credit_note.status).toBe('draft');
    expect((await api(service, 'POST', `${internal}/${state.customerCase}/actions`, { action: 'resolve' })).status).toBe(422);
    await finance.screenshot({ path: path.join(OUT, 'finance-credit-draft.png'), fullPage: true });
    pass('Finance prepares a credit draft; incomplete return/credit cannot be marked resolved');
    const returnWarehouse = await login(browser, 'warehouse@ogami.test');
    const returnQc = await login(browser, 'qc@ogami.test');
    const returnChecker = await login(browser, 'production@ogami.test');
    const returnManager = service;
    await approveReturn(browser, service, customerRma.id, returnChecker);
    await receiveSplit(returnWarehouse, customerRma.id, '1.000', '1.000');
    const completed = await finishReturn(returnManager, returnQc, returnChecker, customerRma.id, process.env.RETURN_TEST_DISPOSITION || 'scrap');
    expect(completed.credit_note).toBeTruthy();
    expect((await api(service, 'POST', `${internal}/${state.customerCase}/actions`, { action: 'resolve' })).status).toBe(422);
    let credited = 0;
    for (const creditId of new Set([record.credit_note.id, completed.credit_note.id])) {
      let credit = await ok(finance, 'GET', `/accounting/credit-notes/${creditId}`);
      credited += Number(credit.subtotal);
      if (credit.status === 'draft') credit = await ok(finance, 'POST', `/accounting/credit-notes/${creditId}/finalize`);
      expect(credit.status).toBe('finalized');
    }
    expect(credited).toBe(300);
    await act(service, state.customerCase, { action: 'resolve' });
    await casePage(customer, state.customerCase, 'customer');
    const settled = await ok(customer, 'GET', `/b2b/customer/problems/${state.customerCase}`);
    expect(settled.status).toBe('resolved');
    await customer.screenshot({ path: path.join(OUT, 'customer-completed-credit.png'), fullPage: true });
    pass('Finance issues 250 shortage + 50 defect credit; customer sees the resolved case');
    expect(errors).toEqual([]);
    pass('No uncaught browser errors');
    fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify({ headless: true, realApi: true, checks, findings, state, errors }, null, 2));
    console.log(`${checks.length} checks passed; ${findings.length} findings. Evidence: ${OUT}`);
    if (findings.length) process.exitCode = 1;
  } catch (error) {
    for (const { page, email } of pages) {
      if (!page.isClosed()) {
        await page.screenshot({ path: path.join(OUT, `failure-${email.replace(/[^a-z]/gi, '-')}.png`), fullPage: true }).catch(() => {});
        fs.writeFileSync(path.join(OUT, `failure-${email}.txt`), await page.locator('body').innerText().catch(() => 'Unavailable'));
      }
    }
    fs.writeFileSync(path.join(OUT, process.env.RETURN_TEST_PHASE === 'links' ? 'links-report.json' : 'report.json'), JSON.stringify({ headless: true, realApi: true, checks, findings, state, errors, failure: String(error.stack) }, null, 2));
    console.error(error);
    process.exitCode = 1;
  } finally { await browser.close(); }
})();
