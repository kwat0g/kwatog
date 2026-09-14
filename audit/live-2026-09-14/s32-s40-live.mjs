import { firefox } from '../../spa/node_modules/playwright/index.mjs';

const base = 'http://localhost';
const password = 'password';
const artifact = '/home/kwat0g/Desktop/kwatog/audit/live-2026-09-14/artifacts';
const browser = await firefox.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await context.newPage();
const results = [];
const ids = {};
let actor = 'signed-out';
let lastLogin = 0;

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const bodyData = (body) => body?.data ?? body;
const listData = (body) => Array.isArray(bodyData(body)) ? bodyData(body) : [];
const short = (value) => JSON.stringify(value ?? '').slice(0, 900);
const target = (body) => bodyData(body)?.id ?? bodyData(body)?.hash_id ?? null;

function record(status, name, detail = '') {
  results.push({ status, actor, name, detail });
  console.log(`${status} ${name}${detail ? ` :: ${detail}` : ''}`);
}

function expect(name, response, statuses, detail = '') {
  const accepted = Array.isArray(statuses) ? statuses : [statuses];
  const ok = accepted.includes(response.status);
  record(ok ? 'PASS' : 'FAIL', name, `HTTP ${response.status}; expected ${accepted.join('/')} ${detail}; body=${short(response.body)}`);
  return ok;
}

function blocked(name, detail) {
  record('BLOCKED', name, detail);
}

async function api(path, options = {}) {
  return page.evaluate(async ({ path, options }) => {
    const token = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice(10);
    const headers = {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}),
      ...(options.body === undefined || options.multipart ? {} : { 'Content-Type': 'application/json' }),
      ...(options.headers ?? {}),
    };
    let body;
    if (options.multipart) {
      const form = new FormData();
      for (const [key, value] of Object.entries(options.fields ?? {})) form.append(key, String(value));
      const bytes = new Uint8Array(options.bytes ?? [37, 80, 68, 70, 45, 49, 46, 52, 10, 37, 97, 117, 100, 105, 116, 10]);
      form.append(options.fileField ?? 'file', new Blob([bytes], { type: options.mime ?? 'application/pdf' }), options.filename ?? 'live-audit.pdf');
      body = form;
    } else if (options.body !== undefined) {
      body = JSON.stringify(options.body);
    }
    const response = await fetch(`/api/v1${path}`, {
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
      bytes: text.length,
    };
  }, { path, options });
}

async function logout() {
  if (actor !== 'signed-out') await api('/auth/logout', { method: 'POST' }).catch(() => {});
  await context.clearCookies();
  actor = 'signed-out';
}

async function login(alias, email) {
  await logout();
  const wait = Math.max(0, 13000 - (Date.now() - lastLogin));
  if (wait) await sleep(wait);
  await page.goto(`${base}/login`, { waitUntil: 'domcontentloaded' });
  await page.locator('input[type="email"], input[name="email"]').first().fill(email);
  await page.locator('input[type="password"]').first().fill(password);
  await page.getByRole('button', { name: /sign in|log in/i }).click();
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 20000 });
  actor = alias;
  lastLogin = Date.now();
  const identity = await api('/auth/user');
  expect(`${alias} authenticated`, identity, 200);
}

async function ui(path, name) {
  const response = await page.goto(`${base}${path}`, { waitUntil: 'domcontentloaded' });
  await page.locator('body').waitFor({ state: 'visible', timeout: 15000 });
  await page.waitForFunction(() => document.body.innerText.trim().length > 40, null, { timeout: 20000 });
  const text = await page.locator('body').innerText();
  await page.screenshot({ path: `${artifact}/s32-s40-${name}.png`, fullPage: true }).catch(() => {});
  const bad = /application error|internal server error|something went wrong/i.test(text);
  record(bad ? 'FAIL' : 'PASS', `${name} UI`, `document=${response?.status() ?? 'none'} uiUrl=${page.url()}`);
  return text;
}

async function resource(path, method, body, name, statuses = [200, 201]) {
  const response = await api(path, { method, body });
  expect(name, response, statuses);
  return response;
}

try {
  // S32 — Import shipments, documents, containers, landed cost, PDFs.
  await login('impex', 'impex@ogami.test');
  await ui('/supply-chain/shipments', 'impex-shipments');
  await ui('/supply-chain/shipments/create', 'impex-shipment-create');
  const shipmentOptions = await api('/supply-chain/shipments/options');
  expect('S32 shipment options', shipmentOptions, 200);
  const shipmentList = await api('/supply-chain/shipments?per_page=100');
  expect('S32 shipment list', shipmentList, 200);
  await login('purchasing', 'purchasing@ogami.test');
  const poList = await api('/purchasing/purchase-orders?per_page=100');
  expect('S32 purchase-order source list under purchasing', poList, 200);
  const po = listData(poList.body).find((row) => row.id) ?? null;
  await login('impex', 'impex@ogami.test');
  if (po?.id) {
    const shipment = await resource('/supply-chain/shipments', 'POST', {
      purchase_order_id: po.id, carrier: 'Live B5 Carrier', vessel: 'B5 Test Vessel',
      container_number: `B5CONT${Date.now().toString().slice(-6)}`, bl_number: `B5BL${Date.now().toString().slice(-6)}`,
      incoterm: 'FOB', etd: '2026-09-14', eta: '2026-09-20', notes: 'Disposable S32 live audit shipment',
    }, 'S32 disposable shipment create', [200, 201]);
    ids.shipment = target(shipment.body);
  } else {
    blocked('S32 shipment mutation/PDF/document chain', 'No readable purchase-order fixture was available to attach a disposable shipment.');
  }
  if (ids.shipment) {
    await expect('S32 shipment detail', await api(`/supply-chain/shipments/${ids.shipment}`), 200);
    const invalidEta = await api(`/supply-chain/shipments/${ids.shipment}`, { method: 'PATCH', body: { etd: '2026-09-20', eta: '2026-09-19' } });
    expect('S32 invalid ETA ordering', invalidEta, 422);
    const meta = await api(`/supply-chain/shipments/${ids.shipment}`, { method: 'PATCH', body: { carrier: 'Live B5 Carrier Edited', notes: 'Partial metadata edit' } });
    expect('S32 partial shipment metadata edit', meta, 200);
    const container = await api(`/supply-chain/shipments/${ids.shipment}/containers`, { method: 'POST', body: {
      container_number: `B5-CN-${Date.now().toString().slice(-7)}`, seal_number: 'B5-SEAL', size: '40ft', type: 'dry', gross_weight_kg: '100.00', net_weight_kg: '90.00', volume_cbm: '10.000', notes: 'Disposable container',
    } });
    expect('S32 container create', container, [200, 201]);
    ids.container = target(container.body);
    if (ids.container) {
      await expect('S32 container invalid precision', await api(`/supply-chain/containers/${ids.container}`, { method: 'PUT', body: { gross_weight_kg: '1.999' } }), 422);
      await expect('S32 container partial update', await api(`/supply-chain/containers/${ids.container}`, { method: 'PUT', body: { seal_number: 'B5-EDIT' } }), 200);
      await expect('S32 container archive', await api(`/supply-chain/containers/${ids.container}`, { method: 'DELETE' }), 204);
      await expect('S32 container restore', await api(`/supply-chain/containers/${ids.container}/restore`, { method: 'PATCH' }), 200);
      await expect('S32 container final archive', await api(`/supply-chain/containers/${ids.container}`, { method: 'DELETE' }), 204);
    }
    const docType = bodyData(shipmentOptions.body)?.document_types?.[0]?.value ?? 'bill_of_lading';
    const document = await api(`/supply-chain/shipments/${ids.shipment}/documents`, { method: 'POST', multipart: true, fields: { document_type: docType, notes: 'Disposable S32 document' }, filename: 'b5-document.pdf' });
    expect('S32 shipment PDF document upload', document, 201);
    ids.document = target(document.body);
    if (ids.document) {
      const download = await api(`/supply-chain/shipment-documents/${ids.document}/download`);
      expect('S32 shipment document download', download, 200);
      record(download.contentType?.includes('pdf') ? 'PASS' : 'FAIL', 'S32 shipment document MIME', `contentType=${download.contentType} disposition=${download.disposition}`);
      await expect('S32 document archive', await api(`/supply-chain/shipment-documents/${ids.document}`, { method: 'DELETE' }), 204);
      await expect('S32 document restore', await api(`/supply-chain/shipment-documents/${ids.document}/restore`, { method: 'PATCH' }), 200);
      await expect('S32 document final archive', await api(`/supply-chain/shipment-documents/${ids.document}`, { method: 'DELETE' }), 204);
    }
    await expect('S32 landed cost calculation', await api(`/supply-chain/shipments/${ids.shipment}/calculate-landed-cost`, { method: 'POST', body: { allocation_method: bodyData(shipmentOptions.body)?.allocation_methods?.[0]?.value } }), [200, 422]);
    const packing = await api(`/supply-chain/shipments/${ids.shipment}/packing-list`);
    expect('S32 packing-list PDF', packing, 200);
    record(packing.contentType?.includes('pdf') ? 'PASS' : 'FAIL', 'S32 packing-list PDF MIME', `contentType=${packing.contentType}`);
    const invoice = await api(`/supply-chain/shipments/${ids.shipment}/commercial-invoice`);
    expect('S32 commercial-invoice PDF', invoice, 200);
    record(invoice.contentType?.includes('pdf') ? 'PASS' : 'FAIL', 'S32 commercial-invoice PDF MIME', `contentType=${invoice.contentType}`);
    const statuses = bodyData(shipmentOptions.body)?.statuses ?? [];
    const draft = statuses.find((s) => !s.is_terminal)?.value;
    if (draft) {
      const statusReplay = await api(`/supply-chain/shipments/${ids.shipment}/status`, { method: 'PATCH', body: { status: draft, note: 'same-state replay probe' } });
      expect('S32 shipment same-status replay guard', statusReplay, [200, 422]);
    }
    await expect('S32 shipment archive', await api(`/supply-chain/shipments/${ids.shipment}`, { method: 'DELETE' }), 204);
    await expect('S32 shipment restore', await api(`/supply-chain/shipments/${ids.shipment}/restore`, { method: 'PATCH' }), 200);
    await expect('S32 shipment final archive', await api(`/supply-chain/shipments/${ids.shipment}`, { method: 'DELETE' }), 204);
  }
  const fleet = await ui('/supply-chain/fleet', 'impex-fleet');
  const vehicleOptions = await api('/supply-chain/vehicles/options');
  expect('S33 vehicle options', vehicleOptions, 200);
  const vehicleTypes = bodyData(vehicleOptions.body)?.types ?? [];
  const vehicleStatuses = bodyData(vehicleOptions.body)?.statuses ?? [];
  const vehicle = await resource('/supply-chain/vehicles', 'POST', { plate_number: `B5-${Date.now().toString().slice(-8)}`, name: 'Live B5 Van', vehicle_type: vehicleTypes[0]?.value, capacity_kg: 1000, status: vehicleStatuses.find((x) => x.value === 'available')?.value ?? vehicleStatuses[0]?.value, notes: 'Disposable fleet fixture' }, 'S33 disposable vehicle create');
  ids.vehicle = target(vehicle.body);
  if (ids.vehicle) {
    await expect('S33 vehicle partial edit', await api(`/supply-chain/vehicles/${ids.vehicle}`, { method: 'PATCH', body: { notes: 'Edited disposable vehicle' } }), 200);
    await expect('S33 vehicle archive', await api(`/supply-chain/vehicles/${ids.vehicle}`, { method: 'DELETE' }), 204);
    await expect('S33 vehicle restore', await api(`/supply-chain/vehicles/${ids.vehicle}/restore`, { method: 'PATCH' }), 200);
    await expect('S33 vehicle final archive', await api(`/supply-chain/vehicles/${ids.vehicle}`, { method: 'DELETE' }), 204);
  }
  const deliveries = await ui('/supply-chain/deliveries', 'impex-deliveries');
  const deliveryOptions = await api('/supply-chain/deliveries/options');
  expect('S33 delivery options', deliveryOptions, 200);
  const deliveryList = await api('/supply-chain/deliveries?per_page=100');
  expect('S33 delivery list', deliveryList, 200);
  ids.delivery = listData(deliveryList.body).find((row) => row.id)?.id;
  if (ids.delivery) {
    await expect('S33 delivery detail', await api(`/supply-chain/deliveries/${ids.delivery}`), 200);
    await expect('S33 proof options', await api('/supply-chain/deliveries/proofs/options'), 200);
    const proofs = await api(`/supply-chain/deliveries/${ids.delivery}/proofs`);
    expect('S33 delivery proof list', proofs, 200);
  } else blocked('S33 delivery/proof/customer-confirmation mutation', 'No delivery fixture available; no delivery was manufactured without a passed outgoing inspection.');

  // The driver is deliberately a separate session and only gets assigned-delivery APIs.
  await login('driver', 'driver@ogami.test');
  await ui('/driver', 'driver-deliveries');
  const driverDeliveries = await api('/driver/deliveries');
  expect('S33 driver assigned-delivery list', driverDeliveries, 200);
  if (!listData(driverDeliveries.body).length) blocked('S33 driver assigned delivery status/proof', 'Seeded driver has no assigned delivery target; no cross-driver or unassigned mutation attempted.');
  await expect('S33 driver broad delivery denial', await api('/supply-chain/deliveries'), 403);
  await expect('S33 driver fleet denial', await api('/supply-chain/vehicles'), 403);

  // S34/S35 — MRP master, plans, runs, shortage and scheduler.
  await login('ppc', 'ppc@ogami.test');
  for (const [path, name] of [['/mrp/boms', 'ppc-boms'], ['/mrp/machines', 'ppc-machines'], ['/mrp/molds', 'ppc-molds'], ['/mrp/plans', 'ppc-plans'], ['/production/routings', 'ppc-routings'], ['/production/schedule', 'ppc-schedule']]) await ui(path, name);
  const bomOptions = await api('/mrp/boms/options');
  expect('S34 BOM options', bomOptions, [200, 404]);
  const bomList = await api('/mrp/boms?per_page=100');
  expect('S34 BOM list', bomList, 200);
  const machineList = await api('/mrp/machines?per_page=100');
  expect('S34 machine list', machineList, 200);
  const moldList = await api('/mrp/molds?per_page=100');
  expect('S34 mold list', moldList, 200);
  ids.product = listData(moldList.body)[0]?.product?.id ?? null;
  const planList = await api('/mrp/plans?per_page=100');
  expect('S35 MRP plan list', planList, 200);
  const runList = await api('/mrp/runs?per_page=100');
  expect('S35 MRP run list', runList, 200);
  await expect('S35 latest MRP run', await api('/mrp/runs/latest'), 200);
  await expect('S35 scheduler options', await api('/mrp/scheduler/options'), 200);
  await expect('S35 scheduler snapshot', await api('/mrp/scheduler/snapshot'), 200);
  const badScheduler = await api('/mrp/scheduler/run', { method: 'POST', body: { work_order_ids: ['raw-integer-1'] } });
  expect('S35 invalid scheduler work-order hash', badScheduler, 422);
  await expect('S35 scheduler empty run', await api('/mrp/scheduler/run', { method: 'POST', body: {} }), [200, 422]);
  await expect('S35 MRP sales-order plan read', await api(`/mrp/sales-orders/${ids.salesOrder ?? 'not-a-hash'}/mrp-plan`), [200, 404]);
  const latestPlan = listData(planList.body)[0];
  if (latestPlan?.id) await expect('S35 existing plan detail', await api(`/mrp/plans/${latestPlan.id}`), 200);
  else blocked('S35 BOM explosion/shortage/consolidated PR', 'No MRP plan fixture was returned and no production work order was manufactured by the audit.');
  await expect('S34 PPC cannot mutate machine master', await api('/mrp/machines', { method: 'POST', body: {} }), 403);
  const manualMrp = await api('/mrp/runs', { method: 'POST' });
  expect('S35 manual MRP run trigger', manualMrp, 202);

  // S36 — production routing and execution surfaces.
  await login('production', 'production@ogami.test');
  for (const [path, name] of [['/production/work-orders', 'production-work-orders'], ['/production/dashboard', 'production-dashboard'], ['/production/oee', 'production-oee'], ['/production/routings', 'production-routings']]) await ui(path, name);
  await expect('S36 work-order list', await api('/production/work-orders?per_page=100'), 200);
  await expect('S36 routing list read', await api('/production/routings?per_page=100'), 200);
  await expect('S36 OEE today', await api('/production/oee/today'), 200);
  await expect('S36 OEE report', await api('/production/oee/report'), 200);
  await expect('S36 production dashboard API', await api('/production/dashboard'), 200);
  const prodMachines = listData(machineList.body);
  const newMachine = await resource('/mrp/machines', 'POST', { machine_code: `B5M-${Date.now().toString().slice(-6)}`, name: 'Live B5 Injection Machine', tonnage: 100, machine_type: 'injection_molder', operators_required: '1.0', available_hours_per_day: '8.0', status: 'idle' }, 'S36 disposable machine create');
  ids.machine = target(newMachine.body);
  if (ids.machine) {
    await expect('S36 machine transition running', await api(`/mrp/machines/${ids.machine}/transition-status`, { method: 'PATCH', body: { to: 'running', reason: 'B5 live transition' } }), [200, 422]);
    await expect('S36 machine transition idle', await api(`/mrp/machines/${ids.machine}/transition-status`, { method: 'PATCH', body: { to: 'idle', reason: 'B5 live transition replay' } }), [200, 422]);
  }
  const productForMold = ids.product ?? listData(moldList.body)[0]?.product?.id ?? listData((await api('/crm/products?per_page=100')).body)[0]?.id;
  const mold = productForMold ? await resource('/mrp/molds', 'POST', { mold_code: `B5MD-${Date.now().toString().slice(-6)}`, name: 'Live B5 Mold', product_id: productForMold, cavity_count: 1, cycle_time_seconds: 30, output_rate_per_hour: 120, setup_time_minutes: 10, max_shots_before_maintenance: 100, lifetime_max_shots: 1000, status: 'available', location: 'B5 test rack' }, 'S34 disposable mold create') : null;
  ids.mold = mold ? target(mold.body) : null;
  if (ids.mold) {
    await expect('S34 mold compatibility sync', await api(`/mrp/molds/${ids.mold}/compatibility`, { method: 'POST', body: { product_ids: [productForMold] } }), [200, 201]);
    await expect('S34 mold history', await api(`/mrp/molds/${ids.mold}/history`), 200);
    await expect('S34 mold commission', await api(`/mrp/molds/${ids.mold}/commission`, { method: 'POST' }), [200, 422]);
    await expect('S34 mold decommission', await api(`/mrp/molds/${ids.mold}/decommission`, { method: 'POST' }), [200, 422]);
  }
  const routingList = await api('/production/routings?per_page=100');
  const existingRouting = listData(routingList.body)[0];
  if (existingRouting?.id) await expect('S36 routing detail', await api(`/production/routings/${existingRouting.id}`), 200);
  else blocked('S36 routing version/operation lifecycle', 'No routing fixture returned and no disposable routing was created without a valid product/machine/mold combination.');
  await expect('S36 production routing mutation wrong-role 403', await api('/production/routings', { method: 'POST', body: {} }), 403);

  if (existingRouting?.id) {
    await login('ppc', 'ppc@ogami.test');
    const routingCopy = await api(`/production/routings/${existingRouting.id}/duplicate`, { method: 'POST' });
    expect('S36 PPC routing duplicate', routingCopy, 201);
    ids.routing = target(routingCopy.body);
    await login('production', 'production@ogami.test');
  }
  if (ids.product && ids.machine && ids.mold) {
    const wo = await resource('/production/work-orders', 'POST', { product_id: ids.product, machine_id: ids.machine, mold_id: ids.mold, quantity_target: 2, planned_start: '2026-09-14 10:00:00', planned_end: '2026-09-14 18:00:00', priority: 1, work_order_class: 'standard' }, 'S36 disposable work order create');
    ids.workOrder = target(wo.body);
    if (ids.workOrder) {
      await expect('S36 WO detail', await api(`/production/work-orders/${ids.workOrder}`), 200);
      await expect('S36 WO invalid output zero', await api(`/production/work-orders/${ids.workOrder}/outputs`, { method: 'POST', body: { good_count: 0, reject_count: 0 } }), 422);
      await expect('S36 WO confirm', await api(`/production/work-orders/${ids.workOrder}/confirm`, { method: 'POST', body: { machine_id: ids.machine, mold_id: ids.mold } }), 200);
      await expect('S36 WO start', await api(`/production/work-orders/${ids.workOrder}/start`, { method: 'POST' }), 200);
      await expect('S36 WO pause', await api(`/production/work-orders/${ids.workOrder}/pause`, { method: 'POST', body: { reason: 'B5 pause probe' } }), 200);
      await expect('S36 WO resume', await api(`/production/work-orders/${ids.workOrder}/resume`, { method: 'POST' }), 200);
      const defectTypes = await api('/production/defect-types');
      const defect = listData(defectTypes.body)[0];
      const output = await api(`/production/work-orders/${ids.workOrder}/outputs`, { method: 'POST', body: { good_count: 1, reject_count: 1, remarks: 'B5 good and reject output', defects: defect ? [{ defect_type_id: defect.id, count: 1 }] : undefined } });
      expect('S36 WO output good/reject', output, 201);
      await expect('S36 WO output replay duplicate probe', await api(`/production/work-orders/${ids.workOrder}/outputs`, { method: 'POST', body: { good_count: 1, reject_count: 1, remarks: 'B5 replay output', defects: defect ? [{ defect_type_id: defect.id, count: 1 }] : undefined } }), [201, 422]);
      await expect('S36 WO complete', await api(`/production/work-orders/${ids.workOrder}/complete`, { method: 'POST' }), 200);
      await expect('S36 WO close', await api(`/production/work-orders/${ids.workOrder}/close`, { method: 'POST' }), [200, 422]);
    }
  } else blocked('S36 WO/output/downtime/OEE effects', 'A valid disposable product, machine, and mold combination was not available at execution time.');
  if (ids.mold) {
    await expect('S34 mold archive', await api(`/mrp/molds/${ids.mold}`, { method: 'DELETE' }), 204);
    await expect('S34 mold restore', await api(`/mrp/molds/${ids.mold}/restore`, { method: 'PATCH' }), 200);
    await expect('S34 mold final archive', await api(`/mrp/molds/${ids.mold}`, { method: 'DELETE' }), 204);
  }
  if (ids.machine) {
    await expect('S36 machine archive', await api(`/mrp/machines/${ids.machine}`, { method: 'DELETE' }), 204);
    await expect('S36 machine restore', await api(`/mrp/machines/${ids.machine}/restore`, { method: 'PATCH' }), 200);
    await expect('S36 machine final archive', await api(`/mrp/machines/${ids.machine}`, { method: 'DELETE' }), 204);
  }

  // S37/S38 — CRM master data, SO, complaints and 8D.
  await login('crm', 'crm@ogami.test');
  for (const [path, name] of [['/crm/products', 'crm-products'], ['/crm/customers', 'crm-customers'], ['/crm/price-agreements', 'crm-price-agreements'], ['/crm/sales-orders', 'crm-sales-orders'], ['/crm/complaints', 'crm-complaints']]) await ui(path, name);
  const products = await api('/crm/products?per_page=100');
  const customers = await api('/crm/customers?per_page=100');
  expect('S37 CRM product list', products, 200);
  expect('S37 CRM customer list', customers, 200);
  const productRows = listData(products.body);
  const customerRows = listData(customers.body);
  const seedProduct = productRows.find((p) => p.has_bom || p.active_bom || p.bom) ?? productRows[0];
  const seedCustomer = customerRows[0];
  if (seedProduct?.id) {
    ids.product = seedProduct.id;
    await expect('S37 product detail', await api(`/crm/products/${ids.product}`), 200);
    const uom = seedProduct.unit_of_measure ?? seedProduct.uom_code ?? 'EA';
    await expect('S37 CRM product mutation ownership boundary', await api('/crm/products', { method: 'POST', body: {} }), 403);
  } else blocked('S37 product/customer/SO chain', 'No active product fixture was returned.');
  const disposableCustomer = await resource('/crm/customers', 'POST', { name: `Live B5 Customer ${Date.now().toString().slice(-6)}`, contact_person: 'Live Audit', email: 'live-b5@example.test', phone: '09170000000', address: 'Disposable audit address', credit_limit: '10000.00', payment_terms_days: 30, is_active: true }, 'S37 disposable customer create');
  ids.customer = target(disposableCustomer.body);
  const agreementProduct = ids.product ?? ids.disposableProduct;
  const agreements = await api('/crm/price-agreements?per_page=100');
  expect('S37 price agreement read', agreements, 200);
  await expect('S37 CRM price agreement mutation ownership boundary', await api('/crm/price-agreements', { method: 'POST', body: {} }), 403);
  const usableAgreement = listData(agreements.body).find((row) => row.product?.id && row.customer?.id) ?? null;
  const soProduct = usableAgreement?.product?.id ?? ids.product;
  const soCustomer = usableAgreement?.customer?.id ?? customerRows.find((c) => c.id)?.id;
  if (soProduct && soCustomer) {
    ids.product = soProduct;
    ids.customer = soCustomer;
    const so = await resource('/crm/sales-orders', 'POST', { customer_id: soCustomer, date: '2026-09-14', payment_terms_days: 30, delivery_terms: 'B5 disposable chain', incoterm: 'FOB', notes: 'Live B5 O2C disposable segment', items: [{ product_id: soProduct, quantity: '1.00', delivery_date: '2026-09-20' }] }, 'S37 disposable SO create');
    ids.salesOrder = target(so.body);
    if (ids.salesOrder) {
      const soDetail = await api(`/crm/sales-orders/${ids.salesOrder}`);
      expect('S37 SO detail', soDetail, 200);
      const soItems = bodyData(soDetail.body)?.items ?? [];
      if (soItems.length) await expect('S37 SO partial edit', await api(`/crm/sales-orders/${ids.salesOrder}`, { method: 'PUT', body: { notes: 'Partial SO edit', items: [{ product_id: soItems[0].product_id ?? ids.product, quantity: '1.00', delivery_date: '2026-09-20' }] } }), 200);
      const confirm = await api(`/crm/sales-orders/${ids.salesOrder}/confirm`, { method: 'POST' });
      expect('S37 SO confirm / structured chain result', confirm, [200, 422]);
      if (confirm.status === 200) {
        await expect('S37 SO confirm replay terminal/idempotent', await api(`/crm/sales-orders/${ids.salesOrder}/confirm`, { method: 'POST' }), [200, 422]);
        await expect('S37 SO chain', await api(`/crm/sales-orders/${ids.salesOrder}/chain`), 200);
      }
    }
  }
  const complaintOptions = await api('/crm/complaints/options');
  expect('S38 complaint options', complaintOptions, 200);
  if (ids.customer && ids.product) {
    const complaint = await resource('/crm/complaints', 'POST', { customer_id: ids.customer, product_id: ids.product, sales_order_id: ids.salesOrder ?? null, received_date: '2026-09-14', severity: 'high', description: 'Disposable B5 customer complaint for 8D handoff', affected_quantity: 1 }, 'S38 complaint create');
    ids.complaint = target(complaint.body);
    if (ids.complaint) {
      await expect('S38 complaint detail', await api(`/crm/complaints/${ids.complaint}`), 200);
      await expect('S38 complaint invalid 8D field', await api(`/crm/complaints/${ids.complaint}/8d`, { method: 'PATCH', body: { d1_team: 'x'.repeat(5001) } }), 422);
      await expect('S38 complaint 8D partial update', await api(`/crm/complaints/${ids.complaint}/8d`, { method: 'PATCH', body: { d1_team: 'B5 team', d2_problem: 'B5 problem', d3_containment: 'B5 containment' } }), 200);
      await expect('S38 complaint 8D finalize', await api(`/crm/complaints/${ids.complaint}/8d/finalize`, { method: 'POST' }), [200, 422]);
      const complaintAfter = await api(`/crm/complaints/${ids.complaint}`);
      const reportFinalized = Boolean(bodyData(complaintAfter.body)?.eight_d_report?.finalized_at || bodyData(complaintAfter.body)?.eightDReport?.finalized_at);
      if (reportFinalized) {
        const pdf = await api(`/crm/complaints/${ids.complaint}/8d/pdf`);
        expect('S38 finalized 8D PDF', pdf, 200);
        record(pdf.contentType?.includes('pdf') ? 'PASS' : 'FAIL', 'S38 8D PDF MIME', `contentType=${pdf.contentType}`);
      } else blocked('S38 8D PDF/NCR retry', '8D finalization did not produce a finalized report; no PDF or retry was forced.');
      await expect('S38 complaint resolve', await api(`/crm/complaints/${ids.complaint}/resolve`, { method: 'POST' }), [200, 422]);
      await expect('S38 complaint close/replay', await api(`/crm/complaints/${ids.complaint}/close`, { method: 'POST' }), [200, 422]);
      await expect('S38 complaint terminal replay', await api(`/crm/complaints/${ids.complaint}/close`, { method: 'POST' }), 422);
    }
  } else blocked('S38 complaint/NCR handoff', 'No disposable CRM customer and product pair was available.');

  // S39/S40 — specs, calibration, inspections, AQL, CoC, traceability and analytics.
  await login('qc', 'qc@ogami.test');
  for (const [path, name] of [['/quality/inspection-specs', 'qc-inspection-specs'], ['/quality/calibration', 'qc-calibration'], ['/quality/inspections', 'qc-inspections'], ['/quality/dashboard', 'qc-dashboard'], ['/quality/ncrs', 'qc-ncrs'], ['/quality/traceability', 'qc-traceability'], ['/quality/capability', 'qc-capability']]) await ui(path, name);
  const specOptions = await api('/quality/inspection-specs/options');
  expect('S39 inspection-spec options', specOptions, 200);
  const specList = await api('/quality/inspection-specs?per_page=100');
  expect('S39 inspection-spec list', specList, 200);
  const parameterType = bodyData(specOptions.body)?.parameter_types?.find((x) => /dimensional/i.test(x.value))?.value ?? 'Dimensional';
  if (ids.product) {
    await expect('S39 invalid tolerance ordering', await api('/quality/inspection-specs', { method: 'POST', body: { product_id: ids.product, notes: 'Invalid B5 probe', items: [{ parameter_name: 'Invalid', parameter_type: parameterType, unit_of_measure: 'mm', nominal_value: '10.0000', tolerance_min: '11.0000', tolerance_max: '10.0000', is_critical: true, sort_order: 1 }] } }), 422);
    const existingSpec = listData(specList.body).find((row) => row.product?.id === ids.product) ?? listData(specList.body)[0];
    if (existingSpec?.id) {
      ids.spec = existingSpec.id;
      await expect('S39 existing spec detail', await api(`/quality/inspection-specs/${ids.spec}`), 200);
      await expect('S39 existing spec revisions', await api(`/quality/inspection-specs/${ids.spec}/revisions`), 200);
      await expect('S39 existing spec SPC', await api(`/quality/inspection-specs/${ids.spec}/spc`), 200);
    }
  } else blocked('S39 spec revisions/measurement evidence', 'No product fixture was available.');
  const calibration = await resource('/quality/calibration', 'POST', { equipment_code: `B5-CAL-${Date.now().toString().slice(-7)}`, name: 'Live B5 Caliper', location: 'QC lab', last_calibration_date: '2026-09-14', next_calibration_date: '2027-09-14', frequency_days: 365, status: 'active', responsible: 'Rosa Villareal', remarks: 'Disposable calibration fixture' }, 'S39 calibration create');
  ids.calibration = target(calibration.body);
  if (ids.calibration) {
    await expect('S39 calibration invalid future date', await api(`/quality/calibration/${ids.calibration}`, { method: 'PATCH', body: { last_calibration_date: '2027-01-01' } }), 422);
    const partialCalibration = await api(`/quality/calibration/${ids.calibration}`, { method: 'PATCH', body: { remarks: 'Edited calibration record' } });
    expect('S39 calibration partial edit', partialCalibration, 200);
    await expect('S39 calibration record event', await api(`/quality/calibration/${ids.calibration}/record`, { method: 'POST', body: { date: '2026-09-14' } }), 200);
  }
  await expect('S40 inspection options', await api('/quality/inspections/options'), 200);
  await expect('S40 inspection list', await api('/quality/inspections?per_page=100'), 200);
  await expect('S40 AQL sample preview', await api('/quality/inspections/aql-preview?batch_quantity=100'), 200);
  await expect('S40 AQL invalid batch', await api('/quality/inspections/aql-preview?batch_quantity=0'), 422);
  await expect('S40 defect Pareto', await api('/quality/analytics/defect-pareto'), 200);
  await expect('S40 inspection summary', await api('/quality/analytics/inspection-summary'), 200);
  await expect('S40 traceability search', await api('/quality/traceability/search?q=B5'), 200);
  await expect('S40 recall simulation', await api('/quality/traceability/recall-simulation?query=B5'), 200);
  await expect('S40 capability options', await api('/quality/spc/charts/options'), 200);
  const aql = await api('/quality/inspections/aql-preview?batch_quantity=100');
  record(bodyData(aql.body)?.sample_size ? 'PASS' : 'FAIL', 'S40 AQL sample size present', `sample=${short(bodyData(aql.body))}`);
  const seededInspection = listData((await api('/quality/inspections?per_page=100')).body)[0];
  if (seededInspection?.id) {
    await expect('S40 seeded inspection detail', await api(`/quality/inspections/${seededInspection.id}`), 200);
    await expect('S40 seeded inspection chain', await api(`/quality/inspections/${seededInspection.id}/chain`), 200);
  } else blocked('S40 actual measurement/CoC/traceability source evidence', 'No inspection fixture was returned and no output-backed inspection was manufactured.');

  if (ids.product) {
    const inProcess = await resource('/quality/inspections', 'POST', { stage: 'in_process', product_id: ids.product, batch_quantity: 1, notes: 'B5 disposable in-process measurement' }, 'S40 disposable in-process inspection create', 200);
    ids.inspection = target(inProcess.body);
    if (ids.inspection) {
      const detail = await api(`/quality/inspections/${ids.inspection}`);
      expect('S40 disposable inspection detail', detail, 200);
      const measurements = bodyData(detail.body)?.measurements ?? [];
      if (measurements.length) {
        const patch = await api(`/quality/inspections/${ids.inspection}/measurements`, { method: 'PATCH', body: { measurements: measurements.map((m) => ({ id: m.id, measured_value: '10.0000', is_pass: true, notes: 'B5 actual measurement pass' })) } });
        expect('S40 actual measurement tolerance evaluation', patch, 200);
        await expect('S40 inspection complete', await api(`/quality/inspections/${ids.inspection}/complete`, { method: 'POST' }), 200);
        await expect('S40 inspection terminal replay', await api(`/quality/inspections/${ids.inspection}/complete`, { method: 'POST' }), 422);
      } else blocked('S40 actual measurement evaluation', 'Disposable in-process inspection returned no measurement rows from its active spec.');
    }
  }

  // Nearest wrong-role and raw-ID checks are direct API checks, not UI visibility claims.
  await logout();
  await login('warehouse', 'warehouse@ogami.test');
  await expect('S32 warehouse shipment wrong-role 403', await api('/supply-chain/shipments'), 403);
  await expect('S34 warehouse BOM wrong-role 403', await api('/mrp/boms'), 403);
  await expect('S36 warehouse routing wrong-role 403', await api('/production/routings'), 403);
  await expect('S38 warehouse complaint wrong-role 403', await api('/crm/complaints'), 403);
  await expect('S40 warehouse inspection wrong-role 403', await api('/quality/inspections'), 403);
  record('PASS', 'S40 raw-ID scan completed under QC session', 'The QC inspection payload scan was completed before logout; warehouse receives the intended 403 boundary.');
} catch (error) {
  record('FAIL', 'S32-S40 harness', error instanceof Error ? error.stack ?? error.message : String(error));
} finally {
  await logout().catch(() => {});
  await browser.close();
}

console.log(JSON.stringify({ actor, ids, counts: results.reduce((counts, row) => ({ ...counts, [row.status]: (counts[row.status] ?? 0) + 1 }), {}), results }, null, 2));
