import { firefox } from '../../spa/node_modules/playwright/index.mjs';

const base = 'http://localhost';
const artifact = '/home/kwat0g/Desktop/kwatog/audit/live-2026-09-14/artifacts';
const browser = await firefox.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await context.newPage();
const results = [];
let actor = 'signed-out';
let guard = 'none';
let lastLogin = 0;
const ids = {};

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const data = (body) => body?.data ?? body;
const rows = (body) => Array.isArray(data(body)) ? data(body) : [];
const idOf = (body) => data(body)?.id ?? data(body)?.hash_id ?? null;
const short = (value) => JSON.stringify(value ?? '').slice(0, 900);

function record(status, name, detail = '') {
  results.push({ status, actor, name, detail });
  console.log(`${status} ${actor} ${name}${detail ? ` :: ${detail}` : ''}`);
}

function expect(name, response, accepted, detail = '') {
  const statuses = Array.isArray(accepted) ? accepted : [accepted];
  const ok = statuses.includes(response.status);
  record(ok ? 'PASS' : 'FAIL', name, `HTTP ${response.status}; expected ${statuses.join('/')} ${detail}; body=${short(response.body)}`);
  return ok;
}

function blocked(name, detail) { record('BLOCKED', name, detail); }
function observed(name, detail) { record('OBSERVED', name, detail); }

async function api(path, options = {}) {
  return page.evaluate(async ({ path, options }) => {
    const token = document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='))?.slice(10);
    const headers = {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}),
      ...(options.body === undefined && !options.form ? {} : { 'Content-Type': 'application/json' }),
      ...(options.headers ?? {}),
    };
    let body = options.body === undefined ? undefined : JSON.stringify(options.body);
    if (options.form) {
      const form = new FormData();
      for (const [key, value] of Object.entries(options.form.fields ?? {})) form.append(key, String(value));
      if (options.form.file) {
        const bytes = Uint8Array.from(options.form.file.bytes);
        form.append(options.form.file.field ?? 'file', new File([bytes], options.form.file.name, { type: options.form.file.type }));
      }
      delete headers['Content-Type'];
      body = form;
    }
    const requestPath = path.startsWith('/api/') || path.startsWith('/sanctum/') ? path : `/api/v1${path}`;
    const response = await fetch(requestPath, {
      method: options.method ?? 'GET', credentials: 'include', headers, body,
    });
    const text = await response.text();
    let parsed;
    try { parsed = JSON.parse(text); } catch { parsed = text; }
    return {
      status: response.status,
      body: parsed,
      contentType: response.headers.get('content-type'),
      disposition: response.headers.get('content-disposition'),
      requestId: response.headers.get('x-request-id'),
    };
  }, { path, options });
}

async function logout() {
  if (actor !== 'signed-out') {
    const path = guard === 'supplier' ? '/b2b/supplier/logout' : guard === 'customer' ? '/b2b/customer/logout' : '/auth/logout';
    await api(path, { method: 'POST' }).catch(() => {});
  }
  await context.clearCookies();
  actor = 'signed-out';
  guard = 'none';
}

async function loginInternal(alias, email) {
  await logout();
  const wait = Math.max(0, 13000 - (Date.now() - lastLogin));
  if (wait) await sleep(wait);
  await page.goto(`${base}/login`, { waitUntil: 'domcontentloaded' });
  await page.locator('input[type="email"], input[name="email"]').first().fill(email);
  await page.locator('input[type="password"]').first().fill('password');
  await page.getByRole('button', { name: /sign in|log in/i }).click();
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 25000 });
  actor = alias; guard = 'internal'; lastLogin = Date.now();
  expect(`${alias} auth user`, await api('/auth/user'), 200);
}

async function loginPortal(type, alias, email) {
  await logout();
  const wait = Math.max(0, 13000 - (Date.now() - lastLogin));
  if (wait) await sleep(wait);
  const prefix = type === 'supplier' ? '/portal/supplier' : '/portal/customer';
  await page.goto(`${base}${prefix}/login`, { waitUntil: 'domcontentloaded' });
  await expect(`${alias} portal CSRF`, await api('/sanctum/csrf-cookie'), [200, 204]);
  const login = await api(`/b2b/${type}/login`, { method: 'POST', body: { email, password: 'password' } });
  expect(`${alias} ${type} login`, login, 200);
  if (login.status !== 200) throw new Error(`${alias} ${type} login did not establish a session`);
  await page.goto(`${base}${prefix}`, { waitUntil: 'domcontentloaded' });
  await page.waitForURL((url) => url.pathname === prefix || (url.pathname.startsWith(`${prefix}/`) && !url.pathname.endsWith('/login')), { timeout: 25000 });
  actor = alias; guard = type; lastLogin = Date.now();
  expect(`${alias} ${type} me`, await api(`/b2b/${type}/me`), 200);
}

async function ui(path, name, viewport = null) {
  if (viewport) await page.setViewportSize(viewport);
  const response = await page.goto(`${base}${path}`, { waitUntil: 'domcontentloaded' });
  await page.locator('body').waitFor({ state: 'visible', timeout: 20000 });
  await page.waitForFunction(() => document.body.innerText.trim().length > 20, null, { timeout: 25000 });
  const text = await page.locator('body').innerText();
  const overflow = await page.evaluate(() => ({ scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth }));
  await page.screenshot({ path: `${artifact}/s46-s49-b7-${name}.png`, fullPage: true }).catch(() => {});
  const authRedirect = !path.endsWith('/login') && guard !== 'internal' && page.url().endsWith('/login');
  const bad = authRedirect || /application error|internal server error|something went wrong/i.test(text);
  record(bad ? 'FAIL' : 'PASS', `${name} UI`, `document=${response?.status() ?? 'none'} uiUrl=${page.url()} overflow=${overflow.scrollWidth > overflow.clientWidth}`);
  return text;
}

async function read(path, name, statuses = 200) {
  const response = await api(path);
  expect(name, response, statuses);
  return response;
}

async function fileProbe(path, name, expected = 200) {
  const response = await api(path);
  expect(name, response, expected, `content-type=${response.contentType} disposition=${response.disposition}`);
  return response;
}

async function dimensions(name) {
  const value = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
    buttons: [...document.querySelectorAll('button,a')].slice(0, 100).map((el) => {
      const r = el.getBoundingClientRect();
      return { text: (el.textContent ?? '').trim().slice(0, 30), w: Math.round(r.width), h: Math.round(r.height) };
    }).filter((x) => x.w > 0 && x.h > 0),
  }));
  const small = value.buttons.filter((button) => button.w < 40 || button.h < 40);
  observed(`${name} touch/overflow`, `viewport=${value.clientWidth}; scrollWidth=${value.scrollWidth}; horizontalOverflow=${value.scrollWidth > value.clientWidth}; ${small.length} visible links/buttons below 40px in sampled controls`);
}

try {
  // S47 supplier portal: reads, safe disposable PO actions, files, invoice,
  // schedules/listings/PPAP and password enumeration/guard checks.
  await loginPortal('supplier', 'supplier-portal', 'portal@supp.test');
  const supplierMe = await read('/b2b/supplier/me', 'S47 supplier tenant identity');
  ids.supplierTenant = data(supplierMe.body)?.vendor?.id ?? data(supplierMe.body)?.vendor_id ?? null;
  for (const path of ['/portal/supplier', '/portal/supplier/purchase-orders', '/portal/supplier/invoices', '/portal/supplier/deliveries', '/portal/supplier/statement-of-account', '/portal/supplier/delivery-schedules', '/portal/supplier/item-listings']) await ui(path, `supplier-${path.split('/').filter(Boolean).pop()}`);
  await read('/b2b/supplier/dashboard', 'S47 supplier dashboard');
  const poList = await read('/b2b/supplier/purchase-orders?per_page=100', 'S47 supplier PO list');
  const po = rows(poList.body)[0];
  if (!po?.id) {
    blocked('S47 supplier PO action set', 'Supplier tenant list returned no own PO hash; no PO was invented.');
  } else {
    ids.supplierPo = po.id;
    const detail = await read(`/b2b/supplier/purchase-orders/${po.id}`, 'S47 supplier PO detail');
    await fileProbe(`/b2b/supplier/purchase-orders/${po.id}/pdf`, 'S47 supplier PO PDF');
    await expect('S47 supplier invalid response validation', await api(`/b2b/supplier/purchase-orders/${po.id}/respond`, { method: 'POST', body: { type: 'invalid' } }), 422);
    const ack = await api(`/b2b/supplier/purchase-orders/${po.id}/acknowledge`, { method: 'POST', body: { notes: 'LIVE-B7 supplier acknowledgement' } });
    expect('S47 supplier PO acknowledge', ack, [200, 201, 422]);
    if ([200, 201].includes(ack.status)) {
      const response = await api(`/b2b/supplier/purchase-orders/${po.id}/respond`, { method: 'POST', body: { type: 'accept', notes: 'LIVE-B7 supplier accepts disposable audit PO' } });
      expect('S47 supplier PO response', response, [201, 422]);
      const replay = await api(`/b2b/supplier/purchase-orders/${po.id}/respond`, { method: 'POST', body: { type: 'accept', notes: 'LIVE-B7 exact response replay' } });
      expect('S47 supplier PO response replay guard', replay, [409, 422]);
    } else blocked('S47 supplier PO response lifecycle', `PO ${po.id} was not actionable; acknowledge returned ${ack.status}, state preserved.`);
    const shipment = await api(`/b2b/supplier/purchase-orders/${po.id}/shipment-update`, { method: 'POST', body: { carrier: 'B7 Audit Carrier', tracking_number: `B7-${Date.now()}`, estimated_arrival: '2026-10-15', notes: 'Disposable live audit shipment update' } });
    expect('S47 supplier shipment update', shipment, [200, 422]);
    const docOptions = await read('/b2b/supplier/purchase-orders/shipping-documents/options', 'S47 supplier shipping document options');
    const docs = await read(`/b2b/supplier/purchase-orders/${po.id}/shipping-documents`, 'S47 supplier shipping document list');
    const existingDoc = rows(docs.body)[0];
    if (existingDoc?.id) {
      ids.supplierDoc = existingDoc.id;
      await fileProbe(`/b2b/supplier/shipping-documents/${existingDoc.id}/download`, 'S47 supplier document download');
    } else if ([200, 201].includes(shipment.status)) {
      const form = { fields: { document_type: data(docOptions.body)?.document_types?.[0]?.value ?? 'packing_list', notes: 'LIVE-B7 disposable supplier document' }, file: { name: 'live-b7-packing-list.pdf', type: 'application/pdf', bytes: [37, 80, 68, 70, 45, 66, 55] } };
      const uploaded = await api(`/b2b/supplier/purchase-orders/${po.id}/shipping-documents`, { method: 'POST', form });
      expect('S47 supplier shipping document upload', uploaded, 201);
      ids.supplierDoc = idOf(uploaded.body);
      if (ids.supplierDoc) await fileProbe(`/b2b/supplier/shipping-documents/${ids.supplierDoc}/download`, 'S47 supplier uploaded document download');
    } else blocked('S47 supplier document upload', 'No safe sent/receiving-stage PO state for a new document and no existing document fixture.');
    const invoiceNumber = `B7-SUP-${Date.now()}`;
    const invoice = await api(`/b2b/supplier/purchase-orders/${po.id}/submit-invoice`, { method: 'POST', form: { fields: { bill_number: invoiceNumber, date: '2026-09-14', remarks: 'LIVE-B7 disposable supplier invoice draft' } } });
    expect('S47 supplier invoice draft', invoice, [201, 422]);
    if (invoice.status === 201) {
      ids.supplierInvoice = idOf(invoice.body);
      const replay = await api(`/b2b/supplier/purchase-orders/${po.id}/submit-invoice`, { method: 'POST', form: { fields: { bill_number: invoiceNumber, date: '2026-09-14', remarks: 'LIVE-B7 exact invoice replay' } } });
      expect('S47 supplier invoice duplicate replay', replay, 201);
      if (ids.supplierInvoice) await fileProbe(`/b2b/supplier/invoices/${ids.supplierInvoice}/pdf`, 'S47 supplier invoice PDF');
    } else blocked('S47 supplier invoice draft', `Invoice draft requires an accepted GRN; API returned HTTP ${invoice.status} without creating a bill.`);
  }
  await read('/b2b/supplier/invoices?per_page=100', 'S47 supplier invoices');
  await read('/b2b/supplier/deliveries?per_page=100', 'S47 supplier deliveries');
  await read('/b2b/supplier/statement-of-account', 'S47 supplier statement of account');
  await read('/b2b/supplier/delivery-schedules?per_page=100', 'S47 supplier delivery schedules');
  await read('/b2b/supplier/item-listings?per_page=100', 'S47 supplier item listings');
  await read('/b2b/supplier/item-catalog?per_page=100', 'S47 supplier item catalog');
  await read('/b2b/supplier/ppap-submissions?per_page=100', 'S47 supplier PPAP submissions');
  const knownForgot = await api('/b2b/supplier/forgot-password', { method: 'POST', body: { email: 'portal@supp.test' } });
  const unknownForgot = await api('/b2b/supplier/forgot-password', { method: 'POST', body: { email: `unknown-b7-${Date.now()}@example.test` } });
  expect('S47 supplier forgot-password known', knownForgot, 200);
  expect('S47 supplier forgot-password unknown', unknownForgot, 200);
  record(JSON.stringify(knownForgot.body) === JSON.stringify(unknownForgot.body) ? 'PASS' : 'FAIL', 'S47 supplier forgot-password enumeration parity', `known=${short(knownForgot.body)} unknown=${short(unknownForgot.body)}`);
  await expect('S47 supplier invalid change-password does not mutate', await api('/b2b/supplier/change-password', { method: 'POST', body: { current_password: 'wrong-password', new_password: 'B7-Invalid1!', new_password_confirmation: 'B7-Invalid1!' } }), 422);
  await logout();
  await page.goto(`${base}/portal/customer`, { waitUntil: 'domcontentloaded' });
  expect('S47 supplier session cannot enter customer guard', await api('/b2b/customer/me'), 401);

  // S48 customer portal: catalog, a disposable draft order and response,
  // invoice/delivery/proof reads, complaint/RMA, SOA/schedules and password.
  await loginPortal('customer', 'customer-portal', 'portal@cust.test');
  const customerMe = await read('/b2b/customer/me', 'S48 customer tenant identity');
  ids.customerTenant = data(customerMe.body)?.customer?.id ?? data(customerMe.body)?.customer_id ?? null;
  for (const path of ['/portal/customer', '/portal/customer/orders', '/portal/customer/invoices', '/portal/customer/deliveries', '/portal/customer/complaints', '/portal/customer/statement-of-account', '/portal/customer/delivery-schedules']) await ui(path, `customer-${path.split('/').filter(Boolean).pop()}`);
  const catalog = await read('/b2b/customer/catalog', 'S48 customer catalog');
  const product = rows(catalog.body)[0];
  if (product?.id) {
    const orderCreate = await api('/b2b/customer/orders', { method: 'POST', body: { date: '2026-09-14', notes: 'LIVE-B7 disposable customer portal order', items: [{ product_id: product.id, quantity: '1.000', delivery_date: '2026-10-20' }] } });
    expect('S48 customer disposable order', orderCreate, 201);
    ids.customerOrder = idOf(orderCreate.body);
    if (ids.customerOrder) {
      await read(`/b2b/customer/orders/${ids.customerOrder}`, 'S48 customer disposable order detail');
      await read(`/b2b/customer/orders/${ids.customerOrder}/chain`, 'S48 customer disposable order chain');
      const response = await api(`/b2b/customer/orders/${ids.customerOrder}/respond`, { method: 'POST', body: { type: 'accept', notes: 'LIVE-B7 customer response' } });
      expect('S48 customer order response', response, [201, 422]);
      const replay = await api(`/b2b/customer/orders/${ids.customerOrder}/respond`, { method: 'POST', body: { type: 'accept', notes: 'LIVE-B7 exact customer response replay' } });
      expect('S48 customer response replay guard', replay, [409, 422]);
    }
  } else blocked('S48 customer order create/response', 'Customer catalog returned no orderable product, so no order was invented.');
  const invoices = await read('/b2b/customer/invoices?per_page=100', 'S48 customer invoices');
  const invoice = rows(invoices.body)[0];
  if (invoice?.id) { await read(`/b2b/customer/invoices/${invoice.id}`, 'S48 customer invoice detail'); await fileProbe(`/b2b/customer/invoices/${invoice.id}/pdf`, 'S48 customer invoice PDF'); }
  else blocked('S48 customer invoice PDF ownership', 'Customer invoice list returned no own finalized/partial/paid invoice hash.');
  const deliveries = await read('/b2b/customer/deliveries?per_page=100', 'S48 customer deliveries');
  const delivery = rows(deliveries.body)[0];
  if (delivery?.id) {
    ids.customerDelivery = delivery.id;
    const deliveryDetail = await read(`/b2b/customer/deliveries/${delivery.id}`, 'S48 customer delivery detail');
    const proof = data(deliveryDetail.body)?.proofs?.[0];
    if (proof?.id) await fileProbe(`/b2b/customer/deliveries/${delivery.id}/proofs/${proof.id}/view`, 'S48 customer proof ownership');
    const confirm = await api(`/b2b/customer/deliveries/${delivery.id}/confirm`, { method: 'POST', body: { receiver_name: 'LIVE-B7 audit receiver', receiver_position: 'Receiving', delivery_remarks: 'Disposable confirmation probe' } });
    expect('S48 customer delivery confirm', confirm, [200, 422]);
    if (confirm.status === 200) expect('S48 customer delivery confirm replay idempotency', await api(`/b2b/customer/deliveries/${delivery.id}/confirm`, { method: 'POST', body: { receiver_name: 'LIVE-B7 audit receiver', receiver_position: 'Receiving', delivery_remarks: 'Exact confirm replay' } }), 200);
  } else blocked('S48 customer delivery/proof/confirm', 'Customer tenant returned no delivery fixture; no seeded delivery was created or altered.');
  const complaintOptions = await read('/b2b/customer/complaints/options', 'S48 customer complaint options');
  const severity = data(complaintOptions.body)?.severities?.[0]?.value ?? 'minor';
  const complaint = await api('/b2b/customer/complaints', { method: 'POST', body: { severity, description: 'LIVE-B7 disposable customer complaint', affected_quantity: 1 } });
  expect('S48 customer complaint create', complaint, 201);
  ids.customerComplaint = idOf(complaint.body);
  if (ids.customerComplaint) await read(`/b2b/customer/complaints/${ids.customerComplaint}/8d-report`, 'S48 customer complaint 8D read', [200, 404]);
  await read('/b2b/customer/complaints?per_page=100', 'S48 customer complaints');
  await read('/b2b/customer/return-requests/source-options', 'S48 customer RMA source options');
  await read('/b2b/customer/return-requests?per_page=100', 'S48 customer RMAs');
  await read('/b2b/customer/statement-of-account', 'S48 customer statement of account');
  await read('/b2b/customer/delivery-schedules?per_page=100', 'S48 customer schedules');
  const knownCustForgot = await api('/b2b/customer/forgot-password', { method: 'POST', body: { email: 'portal@cust.test' } });
  const unknownCustForgot = await api('/b2b/customer/forgot-password', { method: 'POST', body: { email: `unknown-b7-${Date.now()}@example.test` } });
  expect('S48 customer forgot-password known', knownCustForgot, 200);
  expect('S48 customer forgot-password unknown', unknownCustForgot, 200);
  record(JSON.stringify(knownCustForgot.body) === JSON.stringify(unknownCustForgot.body) ? 'PASS' : 'FAIL', 'S48 customer forgot-password enumeration parity', `known=${short(knownCustForgot.body)} unknown=${short(unknownCustForgot.body)}`);
  await expect('S48 customer invalid change-password does not mutate', await api('/b2b/customer/change-password', { method: 'POST', body: { current_password: 'wrong-password', new_password: 'B7-Invalid1!', new_password_confirmation: 'B7-Invalid1!' } }), 422);
  await logout();
  await page.goto(`${base}/portal/supplier`, { waitUntil: 'domcontentloaded' });
  expect('S48 customer session cannot enter supplier guard', await api('/b2b/supplier/me'), 401);

  // S46 internal portal-access admin and schedule review. Disposable invites
  // are left inactive as explicit audit residuals; seeded access is untouched.
  await loginInternal('admin', 'admin@ogami.test');
  await ui('/accounting/portal-access', 'admin-portal-access');
  const suppliers = await read('/b2b/portal-access/suppliers?per_page=100', 'S46 admin supplier portal access');
  const customers = await read('/b2b/portal-access/customers?per_page=100', 'S46 admin customer portal access');
  const schedules = await read('/b2b/portal-access/delivery-schedules?per_page=100', 'S46 admin delivery schedules');
  const supplierUser = rows(suppliers.body).find((row) => row.email === 'portal@supp.test');
  const customerUser = rows(customers.body).find((row) => row.email === 'portal@cust.test');
  const vendorId = supplierUser?.vendor?.id ?? ids.supplierTenant;
  const customerId = customerUser?.customer?.id ?? ids.customerTenant;
  if (!vendorId || !customerId) blocked('S46 portal invite lifecycle fixtures', `admin list did not expose vendor/customer hash IDs; vendor=${vendorId} customer=${customerId}`);
  else {
    const suffix = Date.now();
    const inviteSupplier = await api(`/b2b/portal-access/suppliers/${vendorId}/invite`, { method: 'POST', body: { name: `LIVE-B7 Supplier ${suffix}`, email: `live-b7-supplier-${suffix}@example.test` } });
    expect('S46 supplier invite', inviteSupplier, 201); ids.invitedSupplier = idOf(inviteSupplier.body);
    if (ids.invitedSupplier) {
      expect('S46 supplier resend', await api(`/b2b/portal-access/suppliers/${ids.invitedSupplier}/resend`, { method: 'POST' }), 200);
      expect('S46 supplier deactivate', await api(`/b2b/portal-access/suppliers/${ids.invitedSupplier}/deactivate`, { method: 'PATCH' }), 200);
      expect('S46 supplier resend inactive guard', await api(`/b2b/portal-access/suppliers/${ids.invitedSupplier}/resend`, { method: 'POST' }), 422);
      expect('S46 supplier reactivate', await api(`/b2b/portal-access/suppliers/${ids.invitedSupplier}/reactivate`, { method: 'PATCH' }), 200);
      expect('S46 supplier revoke tokens', await api(`/b2b/portal-access/suppliers/${ids.invitedSupplier}/tokens`, { method: 'DELETE' }), 200);
      expect('S46 supplier final deactivate cleanup', await api(`/b2b/portal-access/suppliers/${ids.invitedSupplier}/deactivate`, { method: 'PATCH' }), 200);
    }
    const inviteCustomer = await api(`/b2b/portal-access/customers/${customerId}/invite`, { method: 'POST', body: { name: `LIVE-B7 Customer ${suffix}`, email: `live-b7-customer-${suffix}@example.test` } });
    expect('S46 customer invite', inviteCustomer, 201); ids.invitedCustomer = idOf(inviteCustomer.body);
    if (ids.invitedCustomer) {
      expect('S46 customer resend', await api(`/b2b/portal-access/customers/${ids.invitedCustomer}/resend`, { method: 'POST' }), 200);
      expect('S46 customer deactivate', await api(`/b2b/portal-access/customers/${ids.invitedCustomer}/deactivate`, { method: 'PATCH' }), 200);
      expect('S46 customer resend inactive guard', await api(`/b2b/portal-access/customers/${ids.invitedCustomer}/resend`, { method: 'POST' }), 422);
      expect('S46 customer reactivate', await api(`/b2b/portal-access/customers/${ids.invitedCustomer}/reactivate`, { method: 'PATCH' }), 200);
      expect('S46 customer final deactivate cleanup', await api(`/b2b/portal-access/customers/${ids.invitedCustomer}/deactivate`, { method: 'PATCH' }), 200);
    }
  }
  const schedule = rows(schedules.body)[0];
  if (schedule?.id) {
    ids.internalSchedule = schedule.id;
    expect('S46 admin schedule detail', await api(`/b2b/portal-access/delivery-schedules/${schedule.id}`), 200);
    const ack = await api(`/b2b/portal-access/delivery-schedules/${schedule.id}/acknowledge`, { method: 'POST' });
    expect('S46 admin schedule acknowledge', ack, [200, 422]);
    if (ack.status === 200) expect('S46 admin schedule acknowledge replay guard', await api(`/b2b/portal-access/delivery-schedules/${schedule.id}/acknowledge`, { method: 'POST' }), 200);
  } else blocked('S46 internal schedule acknowledge/reject', 'No schedule hash was returned by the admin review list; no seeded schedule was invented.');
  await logout();
  await loginInternal('employee', 'employee@ogami.test');
  expect('S46 nearest wrong-role portal access API', await api('/b2b/portal-access/suppliers'), 403);
  await logout();
  expect('S46 signed-out portal access API', await api('/b2b/portal-access/suppliers'), 401);

  // S49 driver, floor and maintenance mobile. Read-first: no seeded delivery,
  // production output, QC result, or maintenance state is mutated here.
  await loginInternal('driver', 'driver@ogami.test');
  await ui('/driver', 'driver-assigned-deliveries', { width: 390, height: 844 });
  await dimensions('S49 driver mobile');
  const driverRows = await read('/driver/deliveries?per_page=100', 'S49 driver assigned deliveries');
  const driverDelivery = rows(driverRows.body)[0];
  if (driverDelivery?.id) {
    ids.driverDelivery = driverDelivery.id;
    await ui(`/driver/${driverDelivery.id}`, 'driver-delivery-detail', { width: 390, height: 844 });
    await read(`/driver/deliveries/${driverDelivery.id}`, 'S49 driver assigned delivery detail');
  } else blocked('S49 driver assigned delivery fixture', 'Driver list returned no assigned delivery; no other delivery was targeted.');
  expect('S49 driver broad delivery denial', await api('/supply-chain/deliveries'), 403);
  expect('S49 driver vehicle-list denial', await api('/supply-chain/vehicles'), 403);
  await logout();
  await loginInternal('production', 'production@ogami.test');
  await ui('/factory', 'factory-floor-tablet', { width: 768, height: 1024 });
  await dimensions('S49 factory floor');
  await read('/production/work-orders?status[]=in_progress&status[]=confirmed&status[]=paused&per_page=50', 'S49 factory active work orders');
  await ui('/factory/qc', 'factory-qc-high-contrast', { width: 768, height: 1024 });
  await dimensions('S49 factory QC');
  expect('S49 production accounting denial', await api('/journal-entries'), 403);
  await logout();
  await loginInternal('maintenance', 'maintenance@ogami.test');
  await ui('/maintenance/mobile', 'maintenance-mobile-tablet', { width: 768, height: 1024 });
  await dimensions('S49 maintenance mobile');
  await read('/maintenance/work-orders?per_page=50', 'S49 maintenance mobile work orders');
  expect('S49 maintenance accounting denial', await api('/accounts'), 403);
  await logout();

  blocked('S47 supplier A/B tenant comparison', `Only portal@supp.test exists, linked to vendor hash ${ids.supplierTenant ?? 'unknown'}; no second supplier identity/tenant was present and none was invented.`);
  blocked('S48 customer A/B tenant comparison', `Only portal@cust.test exists, linked to customer hash ${ids.customerTenant ?? 'unknown'}; no second customer identity/tenant was present and none was invented.`);
} catch (error) {
  record('FAIL', 'S46-S49 B7 harness', error instanceof Error ? error.stack ?? error.message : String(error));
} finally {
  await logout().catch(() => {});
  await browser.close();
}

const counts = results.reduce((out, row) => ({ ...out, [row.status]: (out[row.status] ?? 0) + 1 }), {});
console.log(JSON.stringify({ ids, counts, results }, null, 2));
if ((counts.FAIL ?? 0) > 0) process.exitCode = 1;
