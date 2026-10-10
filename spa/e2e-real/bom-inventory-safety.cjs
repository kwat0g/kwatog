const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { execFileSync } = require('node:child_process');

(async () => {
  const base = 'http://localhost:18080';
  const browser = await chromium.launch({ headless: true });
  const output = '.impeccable/review/bom-inventory';
  fs.mkdirSync(output, { recursive: true });
  const errors = [];
  async function session(email) {
    const context = await browser.newContext({ baseURL: base, viewport: { width: 1440, height: 1000 } });
    await context.request.get('/sanctum/csrf-cookie', { headers: { Origin: base, Referer: base + '/' } });
    async function api(method, route, data) {
      const cookie = (await context.cookies()).find(row => row.name === 'XSRF-TOKEN');
      return context.request.fetch('/api/v1' + route, { method, data, headers: { Origin: base, Referer: base + '/', 'X-XSRF-TOKEN': decodeURIComponent(cookie.value) } });
    }
    const response = await api('POST', '/auth/sign-in', { email, password: 'password' });
    assert(response.ok(), await response.text());
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    page.on('response', response => { if (response.url().includes('/api/') && response.status() >= 500) errors.push(`${response.status()} ${response.url()}`); });
    return { page, api };
  }
  const admin = await session('admin@ogami.test');
  const production = await session('production@ogami.test');
  const planner = await session('ppc@ogami.test');
  const warehouse = await session('warehouse@ogami.test');
  const qc = await session('qc@ogami.test');
  const customer = await session('portal@cust.test');
  async function success(actor, method, route, data, status) {
    const response = await actor.api(method, route, data);
    assert(status ? response.status() === status : response.ok(), await response.text());
    return (await response.json()).data;
  }
  try {
    const tag = String(Date.now()).slice(-8);
    const categories = await success(admin, 'GET', '/inventory/item-categories');
    const category = categories.find(row => row.item_type === 'raw_material');
    const finishedCategory = categories.find(row => row.item_type === 'finished_good');
    assert(category && finishedCategory);
    const product = await success(admin, 'POST', '/crm/products', { part_number: 'BOM-E2E-' + tag, name: 'BOM valuation test ' + tag, unit_of_measure: 'PCS', standard_cost: '20.00', base_selling_price: '30.00', minimum_selling_price: '25.00', portal_visibility: 'restricted', is_active: true }, 201);
    const itemData = { category_id: category.id, unit_of_measure: 'PCS', standard_cost: '3.0000', reorder_method: 'fixed_quantity', reorder_point: '0', safety_stock: '0', minimum_order_quantity: '1', lead_time_days: 0, is_active: true };
    const material = await success(admin, 'POST', '/inventory/items', { ...itemData, code: 'MAT-E2E-' + tag, name: 'BOM material', item_type: 'raw_material' }, 201);
    const fg = await success(admin, 'POST', '/inventory/items', { ...itemData, category_id: finishedCategory.id, code: product.part_number, name: product.name, item_type: 'finished_good', standard_cost: '20.0000' }, 201);
    const bom = await success(planner, 'POST', '/mrp/boms', { product_id: product.id, items: [{ item_id: material.id, quantity_per_unit: '2', unit: 'PCS', waste_factor: '0' }] }, 201);
    assert.equal(bom.total_cost, '6.00');
    assert.equal(bom.estimated_margin.profit_per_unit, '24.00');
    assert.equal(bom.estimated_margin.label, 'Material contribution estimate');
    assert.equal((await warehouse.api('POST', '/mrp/boms/' + bom.id + '/recalculate-cost')).status(), 403);
    assert([401,403].includes((await customer.api('GET', '/mrp/boms/' + bom.id)).status()));
    assert.equal((await admin.api('PUT', '/inventory/items/' + material.id, { is_active: false })).status(), 422);
    assert.equal((await admin.api('PUT', '/crm/products/' + product.id, { part_number: 'BREAK-' + tag })).status(), 422);
    await planner.page.goto('/mrp/boms/' + bom.id);
    await planner.page.getByRole('heading', { name: 'Material contribution estimate', exact: true }).waitFor();
    await planner.page.screenshot({ path: output + '/bom-desktop.png', fullPage: true });
    await planner.page.setViewportSize({ width: 390, height: 844 });
    assert(await planner.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    await planner.page.screenshot({ path: output + '/bom-mobile.png', fullPage: true });
    const wo = await success(production, 'POST', '/production/work-orders', { product_id: product.id, quantity_target: 100, planned_start: new Date().toISOString(), planned_end: new Date(Date.now()+86400000).toISOString() }, 201);
    assert.equal(wo.bom_version, 1);
    assert.equal(wo.unit_standard_cost, '20.0000');
    fs.writeFileSync('/tmp/bom-e2e-identities.json', JSON.stringify({ product: product.id, wo: wo.id }));
    execFileSync('docker', ['cp', '/tmp/bom-e2e-identities.json', 'ogami-audit-20261002-api:/tmp/bom-e2e-identities.json']);
    execFileSync('docker', ['exec', '-w', '/var/www', 'ogami-audit-20261002-api', 'php', 'artisan', 'tinker', '--execute=require "tests/Browser/bom_inventory_fixture.php";']);
    await success(admin, 'PUT', '/crm/products/' + product.id, { standard_cost: '40.00' });
    const recorded = await success(production, 'POST', '/production/work-orders/' + wo.id + '/outputs', { good_count: 100, reject_count: 0 }, 201);
    const freshWo = await success(production, 'GET', '/production/work-orders/' + wo.id);
    const batch = freshWo.outputs.find(row => row.id === recorded.id);
    assert(batch);
    let stock = await success(warehouse, 'GET', '/inventory/items/' + fg.id);
    assert.equal(stock.on_hand_quantity, '100.000');
    assert.equal(stock.quality_held_quantity, '100.000');
    assert.equal(stock.available_quantity, '0.000');
    const receipt = execFileSync('docker', ['exec', '-w', '/var/www', 'ogami-audit-20261002-api', 'php', 'artisan', 'tinker', '--execute=echo json_encode(\\App\\Modules\\Inventory\\Models\\StockMovement::where("reference_type", "work_order_output")->where("reference_id", \\App\\Modules\\Production\\Models\\WorkOrderOutput::whereHash("'+batch.id+'")->firstOrFail()->id)->first()->only(["unit_cost", "total_cost"]));'], { encoding: 'utf8' }).trim();
    assert.equal(JSON.parse(receipt).unit_cost, '20.0000');
    assert.equal(JSON.parse(receipt).total_cost, '2000.00');
    await warehouse.page.goto('/inventory/items/' + fg.id);
    await warehouse.page.getByText('Usable now', { exact: true }).waitFor();
    await warehouse.page.screenshot({ path: output + '/inventory-held-desktop.png', fullPage: true });
    await success(production, 'POST', '/production/work-orders/' + wo.id + '/complete');
    let inspections;
    for (let attempt = 0; attempt < 15; attempt++) {
      inspections = await success(qc, 'GET', '/quality/inspections?product_id=' + product.id + '&per_page=100');
      if (inspections.some(row => row.work_order_output?.id === batch.id)) break;
      await new Promise(resolve => setTimeout(resolve, 1000));
    }
    const latest = await success(qc, 'GET', '/quality/inspections?product_id=' + product.id + '&per_page=100');
    const outgoing = latest.find(row => row.work_order_output?.id === batch.id || row.work_order_output_id === batch.id);
    const inspectionId = outgoing?.id || batch.inspections?.[0]?.id;
    assert(inspectionId, JSON.stringify(batch));
    const inspection = await success(qc, 'GET', '/quality/inspections/' + inspectionId);
    await success(qc, 'POST', '/quality/inspections/' + inspectionId + '/item-results', { items: inspection.measurements.map(row => ({ id: row.id, result: 'passed' })), submit: false });
    const result = await success(qc, 'POST', '/quality/inspections/' + inspectionId + '/batch-result', { result: 'passed', coverage_confirmed: true, inspected_quantity: inspection.sample_size, sample_defect_count: 0, critical_defect: false, defects_removed_confirmed: false });
    assert.equal((await qc.api('PATCH', '/quality/inspections/' + inspectionId + '/review', { decision: 'passed' })).status(), 403);
    await success(admin, 'PATCH', '/quality/inspections/' + inspectionId + '/review', { decision: 'passed' });
    stock = await success(warehouse, 'GET', '/inventory/items/' + fg.id);
    assert.equal(stock.quality_held_quantity, '0.000');
    assert.equal(stock.available_quantity, '100.000');
    await production.page.setViewportSize({ width: 1440, height: 1000 });
    await production.page.goto('/production/work-orders/' + wo.id);
    await production.page.getByRole('heading', { name: 'Production cost snapshot', exact: true }).waitFor();
    await production.page.screenshot({ path: output + '/work-order-desktop.png', fullPage: true });
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ roles: 6, bom_margin: 'materials only', frozen_unit_cost: '20.0000', receipt_value: '2000.00', pending_qc_usable: 0, released_usable: 100, browser_errors: errors, fixtures: { product: product.id, wo: wo.id } }));
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
