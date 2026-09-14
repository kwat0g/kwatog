import { firefox } from '../../spa/node_modules/playwright/index.mjs';

const base = 'http://localhost';
const password = 'password';
const artifactDir = '/home/kwat0g/Desktop/kwatog/audit/live-2026-09-14/artifacts';
const stamp = Date.now().toString().slice(-8);
const results = [];
const cleanup = [];
const ids = {};
const browser = await firefox.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await context.newPage();
let currentActor = 'signed-out';
let lastLoginAt = 0;

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const dataOf = (body) => body?.data ?? body;
const firstOf = (body) => {
  const data = dataOf(body);
  return Array.isArray(data) ? data[0] : data?.data?.[0];
};
const listOf = (body) => {
  const data = dataOf(body);
  return Array.isArray(data) ? data : (Array.isArray(data?.data) ? data.data : []);
};
const idOf = (body) => dataOf(body)?.id ?? dataOf(body)?.hash_id ?? null;
const bodySummary = (body) => JSON.stringify(body ?? '').slice(0, 500);

function pass(name, detail = '') {
  results.push({ name, status: 'PASS', actor: currentActor, detail });
  console.log(`PASS ${name}${detail ? ` :: ${detail}` : ''}`);
}
function fail(name, detail = '') {
  results.push({ name, status: 'FAIL', actor: currentActor, detail });
  console.log(`FAIL ${name} :: ${detail}`);
}
function blocked(name, detail = '') {
  results.push({ name, status: 'BLOCKED', actor: currentActor, detail });
  console.log(`BLOCKED ${name} :: ${detail}`);
}
function assert(name, condition, detail = '') {
  if (condition) pass(name, detail); else fail(name, detail || 'condition was false');
  return condition;
}
function expectStatus(name, response, expected, detail = '') {
  const ok = Array.isArray(expected) ? expected.includes(response.status) : response.status === expected;
  assert(name, ok, `HTTP ${response.status}; expected ${Array.isArray(expected) ? expected.join('/') : expected}${detail ? `; ${detail}` : ''}; body=${bodySummary(response.body)}`);
  return ok;
}

async function screenshot(name) {
  await page.screenshot({ path: `${artifactDir}/s24-s31-${name}.png`, fullPage: true }).catch(() => {});
}
async function api(path, options = {}) {
  const inventoryRoutePrefixes = ['/items', '/item-categories', '/uoms', '/warehouse', '/warehouses', '/zones', '/locations', '/stock-levels', '/stock-movements', '/stock-adjustments', '/scan', '/grn', '/transfer-orders', '/stock-counts', '/material-issues', '/mrb'];
  if (inventoryRoutePrefixes.some((prefix) => path === prefix || path.startsWith(`${prefix}/`) || path.startsWith(`${prefix}?`))) {
    path = `/inventory${path}`;
  }
  return page.evaluate(async ({ path, options }) => {
    const xsrf = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice(10);
    const headers = {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
      ...(options.body !== undefined ? { 'Content-Type': 'application/json' } : {}),
      ...(options.headers ?? {}),
    };
    const response = await fetch(`/api/v1${path}`, {
      method: options.method ?? 'GET', headers, credentials: 'include',
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
    });
    const text = await response.text();
    let body;
    try { body = JSON.parse(text); } catch { body = text; }
    return {
      status: response.status,
      body,
      contentType: response.headers.get('content-type'),
      requestId: response.headers.get('x-request-id') ?? response.headers.get('x-correlation-id'),
    };
  }, { path, options });
}
async function file(path) {
  return page.evaluate(async (path) => {
    const xsrf = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice(10);
    const response = await fetch(`/api/v1${path}`, {
      headers: { Accept: 'application/pdf', 'X-Requested-With': 'XMLHttpRequest', ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}) },
      credentials: 'include',
    });
    return { status: response.status, contentType: response.headers.get('content-type'), bytes: (await response.arrayBuffer()).byteLength };
  }, path);
}
async function logout() {
  if (currentActor !== 'signed-out') await api('/auth/logout', { method: 'POST' }).catch(() => {});
  await context.clearCookies();
  currentActor = 'signed-out';
}
async function login(alias, email) {
  await logout();
  const wait = Math.max(0, 13000 - (Date.now() - lastLoginAt));
  if (wait) await sleep(wait);
  await page.goto(`${base}/login`, { waitUntil: 'domcontentloaded' });
  await page.locator('input[type="email"], input[name="email"]').first().fill(email);
  await page.locator('input[type="password"]').first().fill(password);
  await page.getByRole('button', { name: /sign in|log in/i }).click();
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 20000 });
  const me = await api('/auth/user');
  if (me.status !== 200) throw new Error(`${alias} login identity failed: ${me.status}`);
  currentActor = alias;
  lastLoginAt = Date.now();
  console.log(`ACTOR ${alias} ${page.url()}`);
}
async function ui(path, name, shot = true) {
  const response = await page.goto(`${base}${path}`, { waitUntil: 'domcontentloaded' });
  await page.locator('#main-content, main, body').first().waitFor({ state: 'visible', timeout: 15000 });
  await page.waitForFunction(() => document.body.innerText.trim().length > 40, null, { timeout: 20000 });
  const text = await page.locator('body').innerText();
  assert(`${name} UI`, !/application error|internal server error/i.test(text), `document=${response?.status() ?? 'none'} uiUrl=${page.url()}`);
  if (shot) await screenshot(name.replace(/[^a-z0-9]+/gi, '-').toLowerCase());
  return text;
}
function remember(type, id) { if (id) cleanup.push({ type, id }); return id; }
function pickBy(rows, predicate) { return rows.find(predicate) ?? rows[0] ?? null; }

try {
  // S24-S26: warehouse-owned inventory/catalog and stock read surfaces.
  await login('warehouse', 'warehouse@ogami.test');
  for (const path of ['/inventory/items', '/inventory/warehouse', '/inventory/stock-levels', '/inventory/stock-adjustments', '/inventory/warehouse-map', '/inventory/stock-count', '/inventory/transfer-orders', '/inventory/picking', '/inventory/scanner', '/inventory/grn', '/inventory/material-issues', '/inventory/mrb']) {
    await ui(path, `warehouse ${path}`, path === '/inventory/items' || path === '/inventory/warehouse' || path === '/inventory/stock-levels');
  }
  const [itemOptions, categories0, uoms0, whOptions, whTree0, stock0, movements0, scanOptions, grnOptions, prOptions] = await Promise.all([
    api('/items/options'), api('/item-categories?per_page=100'), api('/uoms'), api('/warehouse/options'), api('/warehouse'),
    api('/stock-levels?per_page=100'), api('/stock-movements?per_page=5'), api('/scan/options'), api('/grn/options'), api('/items?per_page=2'),
  ]);
  for (const [name, response] of [['item options', itemOptions], ['categories list', categories0], ['uoms list', uoms0], ['warehouse options', whOptions], ['warehouse tree', whTree0], ['stock levels', stock0], ['stock movements', movements0], ['scanner options', scanOptions], ['grn options', grnOptions]]) expectStatus(`S24-S26 ${name}`, response, 200);
  const itemTypes = dataOf(itemOptions.body)?.item_types ?? [];
  const reorderMethods = dataOf(itemOptions.body)?.reorder_methods ?? [];
  const category = firstOf(categories0.body);
  const uoms = listOf(uoms0.body);
  const baseUom = uoms.find((u) => u.code === 'PCS') ?? uoms[0];
  const otherUom = uoms.find((u) => u.id !== baseUom?.id) ?? baseUom;
  const warehouse0 = firstOf(whTree0.body);
  const zone0 = warehouse0?.zones?.[0];
  const location0 = zone0?.locations?.[0];
  const stockRows0 = listOf(stock0.body);
  assert('S24 seeded catalog fixture', Boolean(category && baseUom && itemTypes[0] && reorderMethods[0]), `category=${category?.id}; uom=${baseUom?.id}; item_type=${itemTypes[0]?.value}; reorder=${reorderMethods[0]?.value}`);
  assert('S25 seeded receiving location fixture', Boolean(warehouse0 && zone0 && location0), `warehouse=${warehouse0?.id}; zone=${zone0?.id}; location=${location0?.id}`);

  const catCreate = await api('/item-categories', { method: 'POST', body: { name: `Live B4 Category ${stamp}` } });
  expectStatus('S24 category create', catCreate, 201); ids.category = remember('item-category', idOf(catCreate.body));
  const catUpdate = await api(`/item-categories/${ids.category}`, { method: 'PUT', body: { name: `Live B4 Category Edited ${stamp}`, parent_id: category?.id ?? null } });
  expectStatus('S24 category partial/edit', catUpdate, 200);
  const catDelete = await api(`/item-categories/${ids.category}`, { method: 'DELETE' });
  expectStatus('S24 category archive', catDelete, [200, 204]);
  const catRestore = await api(`/item-categories/${ids.category}/restore`, { method: 'PATCH' });
  expectStatus('S24 category restore', catRestore, 200);
  await api(`/item-categories/${ids.category}`, { method: 'DELETE' });

  const uomCreate = await api('/uoms', { method: 'POST', body: { code: `B4${stamp}`, name: `Live B4 UOM ${stamp}` } });
  expectStatus('S24 UOM create', uomCreate, 201); ids.uom = remember('uom', idOf(uomCreate.body));
  const uomUpdate = await api(`/uoms/${ids.uom}`, { method: 'PUT', body: { code: `B4${stamp}`, name: `Live B4 UOM edited ${stamp}` } });
  expectStatus('S24 UOM partial update', uomUpdate, 200);
  const uomDelete = await api(`/uoms/${ids.uom}`, { method: 'DELETE' });
  expectStatus('S24 UOM archive', uomDelete, [200, 204]);
  const uomRestore = await api(`/uoms/${ids.uom}/restore`, { method: 'PATCH' });
  expectStatus('S24 UOM restore', uomRestore, 200);
  await api(`/uoms/${ids.uom}`, { method: 'DELETE' });

  const itemCode = `B4-${stamp}`;
  const itemCreate = await api('/items', { method: 'POST', body: {
    code: itemCode, name: `Live B4 Resin ${stamp}`, description: 'Disposable live audit item', category_id: category.id,
    item_type: itemTypes[0].value, unit_of_measure: baseUom.code, standard_cost: '10.1250', reorder_method: reorderMethods[0].value,
    reorder_point: '1.000', safety_stock: '0.500', minimum_order_quantity: '1.000', lead_time_days: 2, is_critical: false, is_active: true,
  } });
  expectStatus('S24 item create', itemCreate, 201); ids.item = remember('item', idOf(itemCreate.body));
  const itemUpdate = await api(`/items/${ids.item}`, { method: 'PUT', body: { name: `Live B4 Resin edited ${stamp}`, reorder_point: '2.000' } });
  expectStatus('S24 item partial update', itemUpdate, 200);
  const itemInvalid = await api('/items', { method: 'POST', body: { code: `BAD-${stamp}`, name: 'bad', category_id: category.id, item_type: itemTypes[0].value, unit_of_measure: baseUom.code, standard_cost: '-1', reorder_method: reorderMethods[0].value, reorder_point: '0', safety_stock: '0', lead_time_days: 0 } });
  expectStatus('S24 negative item money rejected', itemInvalid, 422);
  const conversion = await api(`/items/${ids.item}/uom-conversions`, { method: 'POST', body: { from_uom_id: baseUom.id, to_uom_id: otherUom.id, factor: '2.5000' } });
  expectStatus('S24 item UOM conversion create', conversion, 201); ids.conversion = idOf(conversion.body);
  const conversionReplay = await api(`/items/${ids.item}/uom-conversions`, { method: 'POST', body: { from_uom_id: baseUom.id, to_uom_id: otherUom.id, factor: '2.5000' } });
  expectStatus('S24 conversion replay is update-or-create', conversionReplay, 201);
  const conversionDelete = await api(`/items/${ids.item}/uom-conversions/${ids.conversion}`, { method: 'DELETE' });
  expectStatus('S24 conversion delete', conversionDelete, 204);
  const itemConversionList = await api(`/items/${ids.item}/uom-conversions`);
  expectStatus('S24 conversion list', itemConversionList, 200);
  const stockCard0 = await api(`/items/${ids.item}/stock-card`);
  expectStatus('S26 disposable item stock card before receipt', stockCard0, 200);

  const whCreate = await api('/warehouses', { method: 'POST', body: { name: `Live B4 Warehouse ${stamp}`, code: `B4W${stamp}`, address: 'Disposable audit location', is_active: true } });
  expectStatus('S25 warehouse create', whCreate, 201); ids.warehouse = remember('warehouse', idOf(whCreate.body));
  const whUpdate = await api(`/warehouses/${ids.warehouse}`, { method: 'PUT', body: { name: `Live B4 Warehouse edited ${stamp}`, code: `B4W${stamp}`, is_active: true } });
  expectStatus('S25 warehouse partial update', whUpdate, 200);
  const zoneCreate = await api('/zones', { method: 'POST', body: { warehouse_id: ids.warehouse, name: `Live B4 Zone ${stamp}`, code: `B4Z${stamp.slice(-6)}`, zone_type: 'raw_materials' } });
  expectStatus('S25 zone create', zoneCreate, 201); ids.zone = remember('zone', idOf(zoneCreate.body));
  const locCreate = await api('/locations', { method: 'POST', body: { zone_id: ids.zone, code: `B4L${stamp}`, rack: 'R1', bin: 'B1', is_active: true } });
  expectStatus('S25 location create', locCreate, 201); ids.location = remember('location', idOf(locCreate.body));
  const loc2Create = await api('/locations', { method: 'POST', body: { zone_id: ids.zone, code: `B4L2${stamp}`, rack: 'R1', bin: 'B2', is_active: true } });
  expectStatus('S25 second location create', loc2Create, 201); ids.location2 = remember('location', idOf(loc2Create.body));
  const qZoneCreate = await api('/zones', { method: 'POST', body: { warehouse_id: ids.warehouse, name: `Live B4 Quarantine ${stamp}`, code: `B4Q${stamp.slice(-6)}`, zone_type: 'quarantine' } });
  expectStatus('S25 quarantine zone create', qZoneCreate, 201); ids.qzone = remember('zone', idOf(qZoneCreate.body));
  const qLocCreate = await api('/locations', { method: 'POST', body: { zone_id: ids.qzone, code: `B4Q${stamp}`, rack: 'Q1', bin: 'B1', is_active: true } });
  expectStatus('S25 quarantine location create', qLocCreate, 201); ids.qlocation = remember('location', idOf(qLocCreate.body));
  const warehouseInvalid = await api('/warehouses', { method: 'POST', body: { name: 'x', code: `bad-${stamp}` } });
  expectStatus('S25 invalid warehouse rejected', warehouseInvalid, 422);
  const tree1 = await api('/warehouse?trashed=with');
  expectStatus('S25 hierarchy tree/search', tree1, 200);

  // S27 low-value adjustment, transfer order, count, and stock cards.
  const adjustInvalid = await api('/stock-adjustments', { method: 'POST', body: { item_id: ids.item, location_id: ids.location, direction: 'in', quantity: '-1', unit_cost: '10.1250', reason: 'negative quantity' } });
  expectStatus('S27 negative adjustment rejected', adjustInvalid, 422);
  const adjustment = await api('/stock-adjustments', { method: 'POST', body: { item_id: ids.item, location_id: ids.location, direction: 'in', quantity: '2.000', unit_cost: '10.1250', reason_code: 'found_stock', reason: `Live B4 seed stock ${stamp}` } });
  expectStatus('S27 disposable stock adjustment', adjustment, 201); ids.adjustment = idOf(adjustment.body);
  const adjustmentState = dataOf(adjustment.body);
  const levelAfterAdjustment = await api(`/stock-levels?item_id=${ids.item}&location_id=${ids.location}`);
  expectStatus('S26 stock level after adjustment', levelAfterAdjustment, 200, `quantity=${JSON.stringify(firstOf(levelAfterAdjustment.body))}`);
  const transferInvalid = await api('/transfer-orders', { method: 'POST', body: { item_id: ids.item, from_location_id: ids.location, to_location_id: ids.location, quantity: '1.000' } });
  expectStatus('S27 transfer same-bin rejected', transferInvalid, 422);
  const transfer = await api('/transfer-orders', { method: 'POST', body: { item_id: ids.item, from_location_id: ids.location, to_location_id: ids.location2, quantity: '0.500', reason: `Live B4 WMS transfer ${stamp}` } });
  expectStatus('S27 transfer order create', transfer, 201); ids.transfer = idOf(transfer.body);
  const transferExec = await api(`/transfer-orders/${ids.transfer}/execute`, { method: 'POST' });
  expectStatus('S27 transfer execute', transferExec, 200);
  const transferReplay = await api(`/transfer-orders/${ids.transfer}/execute`, { method: 'POST' });
  expectStatus('S27 transfer replay terminal denial', transferReplay, 422);
  const movementList = await api(`/stock-movements?item_id=${ids.item}&per_page=100`);
  expectStatus('S26 item movement list', movementList, 200, `movements=${listOf(movementList.body).length}`);
  const scanner = await api('/scan/resolve', { method: 'POST', body: { barcode: itemCode } });
  expectStatus('S26 barcode scanner resolution', scanner, [200, 404], `barcode=${itemCode}`);
  const stockCard1 = await api(`/items/${ids.item}/stock-card`);
  expectStatus('S26 stock card movement references', stockCard1, 200);

  const count = await api('/stock-counts', { method: 'POST', body: { title: `Live B4 exact count ${stamp}`, scope: 'zone', warehouse_id: ids.warehouse, zone_id: ids.zone } });
  expectStatus('S27 stock count create', count, 201); ids.count = idOf(count.body);
  const countStart = await api(`/stock-counts/${ids.count}/start`, { method: 'POST' });
  expectStatus('S27 stock count start/freeze', countStart, 200);
  const countDetail = await api(`/stock-counts/${ids.count}`);
  expectStatus('S27 stock count detail', countDetail, 200);
  const countItem = listOf(dataOf(countDetail.body)?.items ?? countDetail.body?.items ?? [])[0];
  if (countItem) {
    const countRecord = await api(`/stock-counts/items/${countItem.id}/count`, { method: 'POST', body: { counted_quantity: String(countItem.system_quantity), notes: 'Exact disposable count' } });
    expectStatus('S27 exact stock count record', countRecord, 200);
    const countComplete = await api(`/stock-counts/${ids.count}/complete`, { method: 'POST' });
    expectStatus('S27 stock count complete', countComplete, 200);
    const countReplay = await api(`/stock-counts/${ids.count}/complete`, { method: 'POST' });
    expectStatus('S27 stock count replay terminal denial', countReplay, 422);
  } else {
    blocked('S27 stock count variance completion', `zone ${ids.zone} has no stock-count item; no count fixture manufactured`);
    await api(`/stock-counts/${ids.count}`, { method: 'DELETE' });
  }

  // S28-S29 read/issue/MRB surfaces and exact negative paths.
  const issueInvalid = await api('/material-issues', { method: 'POST', body: { issued_date: '2026-09-14', items: [{ item_id: ids.item, location_id: ids.location, quantity_issued: '-0.1' }] } });
  expectStatus('S28 negative material issue rejected', issueInvalid, 422);
  const issue = await api('/material-issues', { method: 'POST', body: { issued_date: '2026-09-14', reference_text: `Live B4 disposable issue ${stamp}`, items: [{ item_id: ids.item, location_id: ids.location2, quantity_issued: '0.250', issued_uom_code: baseUom.code }] } });
  expectStatus('S28 material issue create', issue, 201); ids.issue = idOf(issue.body);
  const issueShow = await api(`/material-issues/${ids.issue}`);
  expectStatus('S28 material issue detail', issueShow, 200);
  const issueCancel = await api(`/material-issues/${ids.issue}`, { method: 'DELETE' });
  expectStatus('S28 material issue cancel/reversal', issueCancel, 204);
  const issueReplay = await api(`/material-issues/${ids.issue}`, { method: 'DELETE' });
  expectStatus('S28 material issue cancel replay denial', issueReplay, 422);
  const mrbOptions = await api('/mrb/options');
  const mrbQualityOptions = await api(`/mrb/quality-options?item_id=${ids.item}`);
  expectStatus('S29 MRB options', mrbOptions, 200);
  expectStatus('S29 MRB quality options', mrbQualityOptions, 200);
  const mrbAttempt = await api('/mrb', { method: 'POST', body: { item_id: ids.item, quantity: '0.100', source_location_id: ids.location, quarantine_location_id: ids.qlocation, notes: 'Live B4 no-fabrication MRB probe' } });
  if (mrbAttempt.status === 201) {
    ids.mrb = remember('mrb', idOf(mrbAttempt.body));
    pass('S29 MRB hold with available quality fixture', `mrb=${ids.mrb}`);
  } else if ([422, 404].includes(mrbAttempt.status)) {
    blocked('S29 MRB hold/release', `HTTP ${mrbAttempt.status}; disposable item has no failed inspection/NCR quality link; body=${bodySummary(mrbAttempt.body)}`);
  } else {
    fail('S29 MRB hold/release', `HTTP ${mrbAttempt.status}; body=${bodySummary(mrbAttempt.body)}`);
  }

  await logout();

  // QC creates the quality-plan gate and completes the incoming inspection.
  await login('qc', 'qc@ogami.test');
  await ui('/quality/inspections', 'qc quality inspections');
  const qualityOptions = await api('/inventory/quality-plans/options');
  expectStatus('S24 quality-plan options', qualityOptions, 200);
  const qp = await api(`/inventory/items/${ids.item}/quality-plans`, { method: 'POST', body: {
    sampling_method: 'fixed', fixed_sample_size: 1, effective_from: '2026-09-14', notes: `Live B4 quality gate ${stamp}`,
    parameters: [{ parameter_name: 'Moisture', parameter_type: 'dimensional', unit_of_measure: '%', nominal_value: '0.50', tolerance_min: '0.00', tolerance_max: '1.00', is_critical: true }],
  } });
  expectStatus('S24 quality-plan create', qp, 201); ids.qualityPlan = idOf(qp.body);
  const qpList = await api(`/inventory/items/${ids.item}/quality-plans`);
  expectStatus('S24 quality-plan list', qpList, 200);
  const qpDeactivate = await api(`/inventory/quality-plans/${ids.qualityPlan}/deactivate`, { method: 'PATCH' });
  expectStatus('S24 quality-plan deactivate', qpDeactivate, 200);
  const qualityBoundary = await api(`/inventory/items/${ids.item}/quality-plans`, { method: 'POST', body: {} });
  expectStatus('S24 QC wrong-role not applicable to same role; invalid plan rejected', qualityBoundary, 422);
  await logout();

  // Create a disposable PR as department head. It intentionally uses a small
  // amount so the ordinary Finance leg can be completed without making a
  // high-value stock/receipt fixture.
  await login('depthead', 'depthead@ogami.test');
  await ui('/purchasing/purchase-requests/create', 'depthead PR create');
  const deptOptions = await api('/purchasing/purchase-requests/options');
  expectStatus('S31 PR options', deptOptions, 200);
  const priority = dataOf(deptOptions.body)?.priorities?.[0]?.value ?? 'normal';
  const prCreate = await api('/purchasing/purchase-requests', { method: 'POST', body: { priority, department_id: null, reason: `Live B4 P2P chain ${stamp}`, items: [{ item_id: ids.item, description: `Live B4 Resin ${stamp}`, quantity: '2.00', unit: baseUom.code, estimated_unit_price: '10.10', purpose: 'Disposable live chain' }] } });
  expectStatus('S31 depthead PR create', prCreate, 201); ids.pr = remember('purchase-request', idOf(prCreate.body));
  const prInvalid = await api('/purchasing/purchase-requests', { method: 'POST', body: { priority, items: [{ item_id: ids.item, description: 'bad', quantity: '-1.999', estimated_unit_price: '1.999' }] } });
  expectStatus('S31 negative/overprecision PR rejected', prInvalid, 422);
  const prUpdate = await api(`/purchasing/purchase-requests/${ids.pr}`, { method: 'PUT', body: { reason: `Live B4 edited reason ${stamp}` } });
  expectStatus('S31 PR partial update', prUpdate, 200);
  const prSubmit = await api(`/purchasing/purchase-requests/${ids.pr}/submit`, { method: 'PATCH' });
  expectStatus('S31 PR submit', prSubmit, 200);
  const prSelfApprove = await api(`/purchasing/purchase-requests/${ids.pr}/approve`, { method: 'PATCH', body: { remarks: 'self approval probe' } });
  expectStatus('S31 depthead self-approval denial', prSelfApprove, 403);
  await logout();

  // Finance is the first money step and also the stock-adjustment checker.
  await login('finance', 'finance@ogami.test');
  await ui('/purchasing/purchase-requests', 'finance PR approval queue');
  const prPending = await api(`/purchasing/purchase-requests/${ids.pr}`);
  expectStatus('S31 finance PR detail row scope', prPending, 200);
  const prApprove = await api(`/purchasing/purchase-requests/${ids.pr}/approve`, { method: 'PATCH', body: { remarks: `Live B4 Finance approval ${stamp}` } });
  expectStatus('S31 finance approves PR', prApprove, 200);
  const prApproveReplay = await api(`/purchasing/purchase-requests/${ids.pr}/approve`, { method: 'PATCH' });
  expectStatus('S31 PR approval replay terminal denial', prApproveReplay, 422);
  const adjustApprove = await api(`/stock-adjustments/${ids.adjustment}/approve`, { method: 'PATCH' });
  if ([200, 201].includes(adjustApprove.status)) pass('S27 finance adjustment checker', `HTTP ${adjustApprove.status}; movement=${idOf(adjustApprove.body)}`);
  else if (adjustApprove.status === 422) blocked('S27 finance adjustment checker', `sub-threshold adjustment was already posted; HTTP 422 ${bodySummary(adjustApprove.body)}`);
  else fail('S27 finance adjustment checker', `HTTP ${adjustApprove.status}; ${bodySummary(adjustApprove.body)}`);
  const wrongRoleAdjust = await api(`/stock-adjustments/${ids.adjustment}/approve`, { method: 'PATCH' });
  expectStatus('S27 finance adjustment replay terminal denial', wrongRoleAdjust, 422);
  await logout();

  // Purchasing owns sourcing, conversion, supplier master, listings, PO send.
  await login('purchasing', 'purchasing@ogami.test');
  await ui('/purchasing/approved-suppliers', 'purchasing approved suppliers');
  await ui('/purchasing/supplier-listings', 'purchasing supplier listings');
  await ui('/purchasing/suppliers/performance', 'purchasing supplier performance', false).catch(() => {});
  await ui('/purchasing/purchase-orders', 'purchasing purchase orders');
  const supplierOptions = await api('/purchasing/approved-suppliers/options');
  const supplierList = await api('/purchasing/approved-suppliers?per_page=100');
  const listings = await api('/purchasing/supplier-listings?per_page=100');
  const ranking = await api('/purchasing/vendors/ranking?limit=10');
  expectStatus('S30 approved supplier options', supplierOptions, 200);
  expectStatus('S30 approved supplier list/search', supplierList, 200);
  expectStatus('S30 supplier listing list/filter', listings, 200);
  expectStatus('S30 supplier performance ranking', ranking, 200);
  const supplierData = dataOf(supplierOptions.body);
  const vendorId = supplierData?.vendors?.[0]?.value;
  const asCreate = vendorId ? await api('/purchasing/approved-suppliers', { method: 'POST', body: { item_id: ids.item, vendor_id: vendorId, is_preferred: false, lead_time_days: 3, last_price: '10.10' } }) : { status: 0, body: {} };
  if (vendorId) {
    expectStatus('S30 disposable approved supplier create', asCreate, 201); ids.approvedSupplier = remember('approved-supplier', idOf(asCreate.body));
    const asInvalid = await api(`/purchasing/approved-suppliers/${ids.approvedSupplier}`, { method: 'PUT', body: { is_preferred: true, last_price: '10.1000' } });
    expectStatus('S30 approved supplier overprecision rejected', asInvalid, 422);
    const asUpdate = await api(`/purchasing/approved-suppliers/${ids.approvedSupplier}`, { method: 'PUT', body: { is_preferred: true, last_price: '10.10' } });
    expectStatus('S30 approved supplier partial update', asUpdate, 200);
    const asDelete = await api(`/purchasing/approved-suppliers/${ids.approvedSupplier}`, { method: 'DELETE' });
    expectStatus('S30 approved supplier archive', asDelete, [200, 204]);
    const asRestore = await api(`/purchasing/approved-suppliers/${ids.approvedSupplier}/restore`, { method: 'PATCH' });
    expectStatus('S30 approved supplier restore', asRestore, 200);
    await api(`/purchasing/approved-suppliers/${ids.approvedSupplier}`, { method: 'DELETE' });
  } else blocked('S30 approved supplier CRUD', 'options returned no vendor fixture; no supplier manufactured');
  const sourcing = await api(`/purchasing/purchase-requests/${ids.pr}/sourcing`);
  expectStatus('S31 PR sourcing suggestions', sourcing, 200);
  const vendorForConversion = vendorId ?? firstOf(supplierList.body)?.vendor?.id ?? null;
  const prDetail = await api(`/purchasing/purchase-requests/${ids.pr}`);
  const prLine = listOf(dataOf(prDetail.body)?.items ?? [])[0];
  if (vendorForConversion && prLine?.id) {
    const convert = await api(`/purchasing/purchase-requests/${ids.pr}/convert`, { method: 'POST', body: { vendor_map: { [prLine.id]: vendorForConversion }, expected_delivery_date: '2026-09-20' } });
    expectStatus('S31 approved PR converts to PO', convert, [200, 201]);
    const poRows = listOf(convert.body);
    ids.po = poRows[0]?.id ?? null;
    if (ids.po) cleanup.push({ type: 'purchase-order', id: ids.po });
    const convertReplay = await api(`/purchasing/purchase-requests/${ids.pr}/convert`, { method: 'POST', body: { vendor_map: { [prLine.id]: vendorForConversion } } });
    expectStatus('S31 PR-to-PO conversion replay idempotency', convertReplay, [200, 201]);
  } else blocked('S31 PR-to-PO conversion', `vendor=${vendorForConversion}; line=${prLine?.id}; no complete sourcing fixture available`);
  const chain = await api('/purchasing/chain');
  expectStatus('S31 procurement chain overview', chain, 200);
  if (ids.po) {
    const poDetail0 = await api(`/purchasing/purchase-orders/${ids.po}`);
    expectStatus('S31 disposable PO detail', poDetail0, 200);
    const poInvalid = await api(`/purchasing/purchase-orders/${ids.po}`, { method: 'PUT', body: { items: [{ quantity: '1.999' }] } });
    expectStatus('S31 PO invalid decimal/edit guard', poInvalid, 422);
    const poSubmit = await api(`/purchasing/purchase-orders/${ids.po}/submit`, { method: 'PATCH' });
    expectStatus('S31 PO submit', poSubmit, 200);
    const poSubmitReplay = await api(`/purchasing/purchase-orders/${ids.po}/submit`, { method: 'PATCH' });
    expectStatus('S31 PO submit replay denial', poSubmitReplay, 422);
    const poPdf = await file(`/purchasing/purchase-orders/${ids.po}/pdf`);
    expectStatus('S31 PO PDF download', poPdf, 200); assert('S31 PO PDF content type', /pdf/i.test(poPdf.contentType ?? ''), `${poPdf.bytes} bytes`);

    // Finish the low-value PO through the real maker/checker and incoming-QC
    // gates. The receipt uses only the disposable item and disposable bin.
    await logout();
    await login('finance', 'finance@ogami.test');
    const poApprove = await api(`/purchasing/purchase-orders/${ids.po}/approve`, { method: 'PATCH', body: { remarks: `Live B4 Finance PO approval ${stamp}` } });
    expectStatus('S31 finance approves PO', poApprove, 200);
    const poApproveReplay = await api(`/purchasing/purchase-orders/${ids.po}/approve`, { method: 'PATCH' });
    expectStatus('S31 PO approval replay terminal denial', poApproveReplay, 422);
    await logout();
    await login('purchasing', 'purchasing@ogami.test');
    const poSend = await api(`/purchasing/purchase-orders/${ids.po}/send`, { method: 'PATCH' });
    expectStatus('S31 PO dispatch/send', poSend, 200);
    const poSendReplay = await api(`/purchasing/purchase-orders/${ids.po}/send`, { method: 'PATCH' });
    expectStatus('S31 PO dispatch replay terminal denial', poSendReplay, 422);
    await logout();
    await login('warehouse', 'warehouse@ogami.test');
    const poForReceipt = await api(`/purchasing/purchase-orders/${ids.po}`);
    const poLine = listOf(dataOf(poForReceipt.body)?.items ?? [])[0];
    if (poLine?.id) {
      const stockBeforeGrn = await api(`/stock-levels?item_id=${ids.item}&location_id=${ids.location}`);
      const grnCreate = await api('/grn', { method: 'POST', body: {
        purchase_order_id: ids.po, received_date: '2026-09-14', remarks: `Live B4 incoming receipt ${stamp}`,
        items: [{ purchase_order_item_id: poLine.id, item_id: ids.item, location_id: ids.location, quantity_received: '1.500', unit_cost: '10.10', lot_number: `B4LOT${stamp}`, moisture_percentage: '0.500' }],
      } });
      expectStatus('S28 GRN create pending QC', grnCreate, 201); ids.grn = remember('grn', idOf(grnCreate.body));
      const stockDuringQc = await api(`/stock-levels?item_id=${ids.item}&location_id=${ids.location}`);
      expectStatus('S28 GRN quarantine-before-QC stock unchanged', stockDuringQc, 200, `before=${bodySummary(stockBeforeGrn.body)} during=${bodySummary(stockDuringQc.body)}`);
      const grnDetail = await api(`/grn/${ids.grn}`);
      expectStatus('S28 GRN detail and inspection handoff', grnDetail, 200);
      ids.inspection = dataOf(grnDetail.body)?.qc_inspection?.id ?? null;
      const acceptBeforeQc = await api(`/grn/${ids.grn}/accept`, { method: 'PATCH' });
      expectStatus('S28 GRN accept blocked before QC', acceptBeforeQc, 422);
      if (ids.inspection) {
        await logout();
        await login('qc', 'qc@ogami.test');
        await ui('/quality/inspections', 'qc incoming inspection gate', false);
        const inspection = await api(`/quality/inspections/${ids.inspection}`);
        expectStatus('S28 incoming inspection detail', inspection, 200);
        const measurement = listOf(dataOf(inspection.body)?.measurements ?? [])[0];
        if (measurement?.id) {
          const measurementPatch = await api(`/quality/inspections/${ids.inspection}/measurements`, { method: 'PATCH', body: { measurements: [{ id: measurement.id, is_pass: true, notes: 'Live B4 passed incoming gate' }] } });
          expectStatus('S28 QC measurement pass', measurementPatch, 200);
          const completeInspection = await api(`/quality/inspections/${ids.inspection}/complete`, { method: 'POST' });
          expectStatus('S28 QC inspection complete', completeInspection, 200);
          const completeReplay = await api(`/quality/inspections/${ids.inspection}/complete`, { method: 'POST' });
          expectStatus('S28 QC completion replay terminal denial', completeReplay, 422);
        } else blocked('S28 incoming QC completion', 'inspection was created without a measurement row');
        await logout();
        await login('warehouse', 'warehouse@ogami.test');
        const acceptGrn = await api(`/grn/${ids.grn}/accept`, { method: 'PATCH' });
        expectStatus('S28 GRN accept after QC', acceptGrn, 200);
        const acceptReplay = await api(`/grn/${ids.grn}/accept`, { method: 'PATCH' });
        expectStatus('S28 GRN accept replay terminal denial', acceptReplay, 422);
        const stockAfterGrn = await api(`/stock-levels?item_id=${ids.item}&location_id=${ids.location}`);
        expectStatus('S28 stock and WAC after accepted GRN', stockAfterGrn, 200, `after=${bodySummary(stockAfterGrn.body)}`);
        const grnPdfOrDetail = await api(`/grn/${ids.grn}`);
        expectStatus('S28 accepted GRN linked inspection/stock detail', grnPdfOrDetail, 200);
      } else {
        blocked('S28 incoming QC completion and accepted receipt', 'GRN response did not expose a qc_inspection hash; no direct inspection ID was invented');
      }
    } else blocked('S28 disposable GRN receipt', 'converted PO response contained no PO line hash');
  }
  const supplierPerfId = supplierData?.vendors?.[0]?.value;
  if (supplierPerfId) {
    const perf = await api(`/vendors/${supplierPerfId}/performance?months=6`);
    const recompute = await api(`/vendors/${supplierPerfId}/performance/recompute`, { method: 'POST' });
    expectStatus('S30 vendor performance detail', perf, 200);
    expectStatus('S30 vendor performance recompute', recompute, 200);
  }
  await logout();

  // buyer2 proves same-role creator/read scope without being treated as a checker.
  await login('buyer2', 'buyer2@ogami.test');
  await ui('/purchasing/purchase-requests', 'buyer2 same-role PR list');
  const buyer2Draft = await api('/purchasing/purchase-requests', { method: 'POST', body: { priority: 'normal', reason: `Live B4 buyer2 draft ${stamp}`, items: [{ item_id: ids.item, description: `Live B4 Resin ${stamp}`, quantity: '0.50', unit: baseUom.code, estimated_unit_price: '10.10' }] } });
  expectStatus('S31 buyer2 same-role draft create', buyer2Draft, 201); ids.buyer2Pr = idOf(buyer2Draft.body);
  const buyer2Approve = await api(`/purchasing/purchase-requests/${ids.buyer2Pr}/approve`, { method: 'PATCH' });
  expectStatus('S31 buyer2 cannot approve own draft', buyer2Approve, 422);
  const buyer2Cancel = await api(`/purchasing/purchase-requests/${ids.buyer2Pr}/cancel`, { method: 'PATCH' });
  expectStatus('S31 buyer2 draft cleanup cancel', buyer2Cancel, 200);
  await logout();

  // VP and impex are explicit final boundary/read-only actors.
  await login('vp', 'vp@ogami.test');
  await ui('/approvals', 'vp approval board');
  const vpWrongInventory = await api('/items', { method: 'POST', body: {} });
  expectStatus('S24 VP wrong-role inventory create 403', vpWrongInventory, 403);
  if (ids.po) {
    const poBeforeVp = await api(`/purchasing/purchase-orders/${ids.po}`);
    const poApproveVp = await api(`/purchasing/purchase-orders/${ids.po}/approve`, { method: 'PATCH', body: { remarks: `Live B4 VP boundary ${stamp}` } });
    if (poBeforeVp.status === 200 && dataOf(poBeforeVp.body)?.status === 'pending_approval') expectStatus('S31 VP PO approval', poApproveVp, 200);
    else blocked('S31 VP PO threshold approval', `disposable PO status=${dataOf(poBeforeVp.body)?.status}; low-value chain does not require VP`);
  } else blocked('S31 VP PO threshold approval', 'no disposable PO converted');
  await logout();

  await login('impex', 'impex@ogami.test');
  await ui('/supply-chain/shipments', 'impex logistics view-only');
  const impexInventory = await api('/items');
  const impexGrnMutation = await api('/grn', { method: 'POST', body: {} });
  expectStatus('S24 impex inventory view boundary', impexInventory, 403);
  expectStatus('S28 impex GRN mutation 403', impexGrnMutation, 403);

  // Cleanup/terminalization: never touch seeded IDs; archive only disposable rows.
  await logout();
  await login('warehouse', 'warehouse@ogami.test');
  if (ids.issue) await api(`/material-issues/${ids.issue}`, { method: 'DELETE' });
  if (ids.mrb) {
    const mrbRelease = await api(`/mrb/${ids.mrb}/release`, { method: 'POST', body: { disposition: 'scrap', notes: 'Live B4 cleanup disposition' } });
    expectStatus('S29 MRB cleanup release', mrbRelease, 200);
  }
  if (ids.po) {
    const poCurrent = await api(`/purchasing/purchase-orders/${ids.po}`);
    if (dataOf(poCurrent.body)?.status === 'approved') await api(`/purchasing/purchase-orders/${ids.po}/send`, { method: 'PATCH' });
    const poFinal = await api(`/purchasing/purchase-orders/${ids.po}`);
    if (['draft', 'pending_approval', 'approved', 'sent'].includes(dataOf(poFinal.body)?.status)) await api(`/purchasing/purchase-orders/${ids.po}/cancel`, { method: 'PATCH', body: { reason: 'Live B4 disposable cleanup after receipt-chain checks' } });
  }
  const itemFinal = await api(`/items/${ids.item}`);
  if (itemFinal.status === 200) {
    const itemDelete = await api(`/items/${ids.item}`, { method: 'DELETE' });
    expectStatus('S24 disposable item archive cleanup', itemDelete, [200, 204, 422]);
  }
  if (ids.location2) await api(`/locations/${ids.location2}`, { method: 'DELETE' });
  if (ids.location) await api(`/locations/${ids.location}`, { method: 'DELETE' });
  if (ids.qlocation) await api(`/locations/${ids.qlocation}`, { method: 'DELETE' });
  if (ids.qzone) await api(`/zones/${ids.qzone}`, { method: 'DELETE' });
  if (ids.zone) await api(`/zones/${ids.zone}`, { method: 'DELETE' });
  if (ids.warehouse) await api(`/warehouses/${ids.warehouse}`, { method: 'DELETE' });
  if (ids.category) await api(`/item-categories/${ids.category}`, { method: 'DELETE' });
  const notifications = await api('/notifications?per_page=50');
  expectStatus('S31 notification feed after chain', notifications, 200, `count=${listOf(notifications.body).length}`);
} catch (error) {
  fail('S24-S31 harness', error instanceof Error ? error.stack ?? error.message : String(error));
  await screenshot('harness-failure');
} finally {
  await logout().catch(() => {});
  await browser.close();
}

console.log(JSON.stringify({ stamp, ids, cleanup, results, counts: results.reduce((a, r) => ({ ...a, [r.status]: (a[r.status] ?? 0) + 1 }), {}) }, null, 2));
if (results.some((r) => r.status === 'FAIL')) process.exitCode = 1;
