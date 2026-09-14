import { firefox } from '../../spa/node_modules/playwright/index.mjs';

const base = 'http://localhost';
const artifact = '/home/kwat0g/Desktop/kwatog/audit/live-2026-09-14/artifacts';
const browser = await firefox.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await context.newPage();
const results = [];
const ids = {};
let actor = 'signed-out';
let lastLogin = 0;

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const data = (body) => body?.data ?? body;
const list = (body) => Array.isArray(data(body)) ? data(body) : [];
const idOf = (body) => data(body)?.id ?? data(body)?.hash_id ?? null;
const short = (value) => JSON.stringify(value ?? '').slice(0, 1200);

function record(status, name, detail = '') {
  results.push({ status, actor, name, detail });
  console.log(`${status} ${name}${detail ? ` :: ${detail}` : ''}`);
}

function expect(name, response, statuses, detail = '') {
  const expected = Array.isArray(statuses) ? statuses : [statuses];
  const ok = expected.includes(response.status);
  record(ok ? 'PASS' : 'FAIL', name, `HTTP ${response.status}; expected ${expected.join('/')} ${detail}; body=${short(response.body)}`);
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
      ...(options.body === undefined ? {} : { 'Content-Type': 'application/json' }),
      ...(options.headers ?? {}),
    };
    const response = await fetch(`/api/v1${path}`, {
      method: options.method ?? 'GET', credentials: 'include', headers,
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
    });
    const text = await response.text();
    let body;
    try { body = JSON.parse(text); } catch { body = text; }
    return {
      status: response.status,
      body,
      contentType: response.headers.get('content-type'),
      disposition: response.headers.get('content-disposition'),
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
  await page.locator('input[type="password"]').first().fill('password');
  await page.getByRole('button', { name: /sign in|log in/i }).click();
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 25000 });
  actor = alias;
  lastLogin = Date.now();
  expect(`${alias} authenticated`, await api('/auth/user'), 200);
}

async function ui(path, name) {
  const response = await page.goto(`${base}${path}`, { waitUntil: 'domcontentloaded' });
  await page.locator('body').waitFor({ state: 'visible', timeout: 20000 });
  await page.waitForFunction(() => document.body.innerText.trim().length > 30, null, { timeout: 25000 });
  const text = await page.locator('body').innerText();
  await page.screenshot({ path: `${artifact}/s41-s45-${name}.png`, fullPage: true }).catch(() => {});
  const bad = /application error|internal server error|something went wrong/i.test(text);
  record(bad ? 'FAIL' : 'PASS', `${name} UI`, `document=${response?.status() ?? 'none'} uiUrl=${page.url()}`);
  return text;
}

async function read(path, name, statuses = 200) {
  const response = await api(path);
  expect(name, response, statuses);
  return response;
}

function nestedRows(value, keys = []) {
  let current = value;
  for (const key of keys) current = current?.[key];
  return Array.isArray(current) ? current : [];
}

try {
  // S41 — direct API only. The UI route is deliberately absent from the SPA.
  await login('qc', 'qc@ogami.test');
  await ui('/quality/inspections', 'qc-quality-review');
  const ppapIndex = await read('/quality/ppap?per_page=100', 'S41 PPAP list');
  const existingPpap = list(ppapIndex.body)[0];
  record(page.url().includes('/quality/ppap') ? 'FAIL' : 'PASS', 'S41 PPAP SPA route absence', `no employee PPAP route mounted; current UI ${page.url()}`);
  if (existingPpap?.vendor?.id && existingPpap?.item?.id) {
    ids.ppapVendor = existingPpap.vendor.id;
    ids.ppapItem = existingPpap.item.id;
    ids.ppapProduct = existingPpap.product?.id ?? null;
    ids.ppapExisting = existingPpap.id;
  }
  await expect('S41 strict PPAP level rejects numeric-looking value', await api('/quality/ppap', {
    method: 'POST', body: { vendor_id: ids.ppapVendor ?? 'invalid', item_id: ids.ppapItem ?? 'invalid', ppap_level: '1e0', notes: 'invalid level probe' },
  }), 422);
  if (!ids.ppapVendor || !ids.ppapItem) {
    blocked('S41 PPAP disposable lifecycle', 'PPAP list had no vendor/item hash fixture and QC cannot manufacture a vendor or item through an unrelated module.');
  } else {
    const create = await api('/quality/ppap', { method: 'POST', body: {
      vendor_id: ids.ppapVendor, item_id: ids.ppapItem, product_id: ids.ppapProduct,
      ppap_level: '3', submission_date: '2026-09-14', notes: 'Disposable S41 PPAP approval path',
    } });
    expect('S41 disposable PPAP create', create, 201);
    ids.ppap = idOf(create.body);
    const detail = ids.ppap ? await read(`/quality/ppap/${ids.ppap}`, 'S41 PPAP detail') : null;
    const element = detail ? data(detail.body)?.elements?.[0] : null;
    if (ids.ppap) {
      await expect('S41 PPAP partial edit', await api(`/quality/ppap/${ids.ppap}`, { method: 'PUT', body: { notes: 'S41 prefilled partial edit' } }), 200);
      if (element?.id) {
        ids.ppapElement = element.id;
        await expect('S41 PPAP element accepted update', await api(`/quality/ppap/${ids.ppap}/elements/${element.id}`, { method: 'PATCH', body: { status: 'accepted', notes: 'S41 PSW evidence accepted' } }), 200);
      } else {
        blocked('S41 PPAP element update', 'Disposable PPAP did not return its auto-attached Part Submission Warrant element.');
      }
      await expect('S41 PPAP submit', await api(`/quality/ppap/${ids.ppap}/submit`, { method: 'PATCH' }), 200);
      await expect('S41 PPAP submit replay terminal guard', await api(`/quality/ppap/${ids.ppap}/submit`, { method: 'PATCH' }), 422);
      await expect('S41 PPAP review', await api(`/quality/ppap/${ids.ppap}/review`, { method: 'PATCH' }), 200);
      await expect('S41 PPAP approve', await api(`/quality/ppap/${ids.ppap}/approve`, { method: 'PATCH' }), 200);
      await expect('S41 approved PPAP edit terminal guard', await api(`/quality/ppap/${ids.ppap}`, { method: 'PUT', body: { notes: 'illegal approved edit' } }), 422);
      if (ids.ppapElement) await expect('S41 approved PPAP evidence freeze', await api(`/quality/ppap/${ids.ppap}/elements/${ids.ppapElement}`, { method: 'PATCH', body: { status: 'rejected' } }), 422);
      const duplicate = await api('/quality/ppap', { method: 'POST', body: {
        vendor_id: ids.ppapVendor, item_id: ids.ppapItem, product_id: ids.ppapProduct,
        ppap_level: '3', submission_date: '2026-09-14', notes: 'S41 exact duplicate probe',
      } });
      if (duplicate.status === 201) {
        ids.ppapDuplicate = idOf(duplicate.body);
        observed('S41 exact duplicate PPAP create', `HTTP 201 created second disposable submission ${ids.ppapDuplicate}; PPAP may allow revisions, no unique contract is documented.`);
      } else expect('S41 exact duplicate PPAP create validation', duplicate, 422);
    }
    const elementCount = data(detail?.body)?.elements?.length ?? 0;
    if (elementCount < 18) blocked('S41 18-element PPAP update set', `The disposable API creation contract auto-attached ${elementCount} element; no add-element route exists and seeded PPAPs were preserved.`);
  }

  // S42 — maintenance execution and reversible schedule CRUD.
  await login('maintenance', 'maintenance@ogami.test');
  await ui('/maintenance', 'maintenance-home');
  await ui('/maintenance/schedules', 'maintenance-schedules');
  await ui('/maintenance/work-orders', 'maintenance-work-orders');
  await ui('/maintenance/downtime', 'maintenance-downtime');
  await read('/maintenance/schedules/options', 'S42 maintenance schedule options');
  const woOptions = await read('/maintenance/work-orders/options', 'S42 maintenance WO options');
  const assignees = await read('/maintenance/work-orders/assignees', 'S42 maintenance assignees');
  const schedules = await read('/maintenance/schedules?per_page=100', 'S42 maintenance schedule list');
  const woList = await read('/maintenance/work-orders?per_page=100', 'S42 maintenance WO list');
  for (const path of ['/maintenance/downtime-analytics/summary', '/maintenance/downtime-analytics/policy', '/maintenance/downtime-analytics/daily-trend', '/maintenance/downtime-analytics/top-machines', '/maintenance/downtime-analytics/all-machines', '/maintenance/downtime-analytics/pareto']) await read(path, `S42 ${path.split('/').pop()}`);
  await expect('S42 invalid maintenance WO target hash', await api('/maintenance/work-orders', { method: 'POST', body: { maintainable_type: 'machine', maintainable_id: 'raw-integer-1', type: 'corrective', priority: 'low', description: 'invalid target' } }), 422);
  const existingWo = list(woList.body)[0];
  const maintainable = existingWo?.maintainable ?? {};
  const targetType = maintainable.type ?? existingWo?.maintainable_type;
  const targetId = maintainable.id ?? existingWo?.maintainable_id;
  const assignee = list(assignees.body)[0]?.id;
  const types = data(woOptions.body)?.types ?? [];
  const priorities = data(woOptions.body)?.priorities ?? [];
  if (!targetType || !targetId || !assignee) {
    blocked('S42 disposable maintenance WO execution', 'Maintenance list/options did not expose a valid maintainable target and active assignee; seeded work orders were preserved.');
  } else {
    const createWo = await api('/maintenance/work-orders', { method: 'POST', body: {
      maintainable_type: targetType, maintainable_id: targetId,
      type: types.find((x) => x.value === 'corrective')?.value ?? 'corrective',
      priority: priorities.find((x) => x.value === 'low')?.value ?? 'low',
      description: 'Disposable S42 complete maintenance work order',
    } });
    expect('S42 disposable maintenance WO create', createWo, 201);
    ids.maintenanceWo = idOf(createWo.body);
    if (ids.maintenanceWo) {
      await expect('S42 maintenance assign', await api(`/maintenance/work-orders/${ids.maintenanceWo}/assign`, { method: 'PATCH', body: { employee_id: assignee } }), 200);
      await expect('S42 maintenance start', await api(`/maintenance/work-orders/${ids.maintenanceWo}/start`, { method: 'PATCH' }), 200);
      await expect('S42 maintenance lifecycle log', await api(`/maintenance/work-orders/${ids.maintenanceWo}/logs`, { method: 'POST', body: { description: 'S42 diagnostic and repair log' } }), 201);
      const invalidSpare = await api(`/maintenance/work-orders/${ids.maintenanceWo}/spare-parts`, { method: 'POST', body: { item_id: 'invalid', location_id: 'invalid', quantity: '1.000' } });
      expect('S42 invalid spare part references', invalidSpare, 422);
      await expect('S42 maintenance complete exact downtime', await api(`/maintenance/work-orders/${ids.maintenanceWo}/complete`, { method: 'PATCH', body: { remarks: 'S42 completed disposable repair', downtime_minutes: 17 } }), 200);
      await expect('S42 completed maintenance terminal replay', await api(`/maintenance/work-orders/${ids.maintenanceWo}/complete`, { method: 'PATCH', body: { downtime_minutes: 17 } }), 422);
      await read(`/maintenance/work-orders/${ids.maintenanceWo}`, 'S42 maintenance WO final detail');
    }
    const cancelWo = await api('/maintenance/work-orders', { method: 'POST', body: { maintainable_type: targetType, maintainable_id: targetId, type: 'corrective', priority: 'low', description: 'Disposable S42 cancellation work order' } });
    expect('S42 disposable cancellation WO create', cancelWo, 201);
    ids.maintenanceCancelWo = idOf(cancelWo.body);
    if (ids.maintenanceCancelWo) {
      await expect('S42 maintenance cancel', await api(`/maintenance/work-orders/${ids.maintenanceCancelWo}/cancel`, { method: 'PATCH', body: { reason: 'S42 cleanup cancellation' } }), 200);
      await expect('S42 cancelled maintenance terminal replay', await api(`/maintenance/work-orders/${ids.maintenanceCancelWo}/start`, { method: 'PATCH' }), 422);
    }
  }
  if (targetType && targetId) {
    const createSchedule = await api('/maintenance/schedules', { method: 'POST', body: {
      maintainable_type: targetType, maintainable_id: targetId, description: 'Disposable S42 monthly schedule', interval_type: 'days', interval_value: 30, last_performed_at: '2026-09-01', is_active: true,
    } });
    expect('S42 maintenance schedule create', createSchedule, 201);
    ids.maintenanceSchedule = idOf(createSchedule.body);
    if (ids.maintenanceSchedule) {
      await expect('S42 schedule partial edit', await api(`/maintenance/schedules/${ids.maintenanceSchedule}`, { method: 'PUT', body: { description: 'Disposable S42 edited schedule' } }), 200);
      await expect('S42 schedule archive', await api(`/maintenance/schedules/${ids.maintenanceSchedule}`, { method: 'DELETE' }), 204);
      await expect('S42 schedule restore', await api(`/maintenance/schedules/${ids.maintenanceSchedule}/restore`, { method: 'PATCH' }), 200);
      await expect('S42 schedule final archive', await api(`/maintenance/schedules/${ids.maintenanceSchedule}`, { method: 'DELETE' }), 204);
    }
  } else blocked('S42 schedule CRUD', 'No valid disposable maintainable target was exposed.');

  // S43 — assets, QR, depreciation and disposal separation of duties.
  await login('finance', 'finance@ogami.test');
  await ui('/assets', 'finance-assets');
  await ui('/assets/create', 'finance-assets-create');
  const assetOptions = await read('/assets/options', 'S43 asset options');
  await read('/assets?per_page=100', 'S43 asset list');
  const category = data(assetOptions.body)?.categories?.[0]?.value ?? 'equipment';
  const asset = await api('/assets', { method: 'POST', body: {
    name: 'Disposable S43 Depreciable Asset', description: 'S43 exact money and disposal fixture', category,
    acquisition_date: '2026-09-14', acquisition_cost: '1200.00', useful_life_years: 2, depreciation_method: 'straight_line', salvage_value: '0.00', location: 'S43 audit bay',
  } });
  expect('S43 disposable asset create', asset, 201);
  ids.asset = idOf(asset.body);
  await expect('S43 invalid salvage above acquisition cost', await api('/assets', { method: 'POST', body: { name: 'invalid salvage', category, acquisition_date: '2026-09-14', acquisition_cost: '10.00', useful_life_years: 1, salvage_value: '10.01' } }), 422);
  if (ids.asset) {
    await read(`/assets/${ids.asset}`, 'S43 asset detail');
    await expect('S43 asset partial edit', await api(`/assets/${ids.asset}`, { method: 'PUT', body: { location: 'S43 edited bay' } }), 200);
    const qr = await read(`/assets/${ids.asset}/qr`, 'S43 asset QR payload');
    if (!data(qr.body)?.asset_id && !data(qr.body)?.url) observed('S43 QR payload shape', `HTTP 200 payload did not expose an obvious asset_id/url: ${short(qr.body)}`);
    await expect('S43 depreciation run current month', await api('/asset-depreciations/run', { method: 'POST', body: { year: 2026, month: 9 } }), 200);
    const dep = await read(`/asset-depreciations?asset_id=${ids.asset}&year=2026&month=9`, 'S43 exact asset depreciation row');
    const depRow = list(dep.body)[0];
    if (depRow) {
      record(depRow.depreciation_amount === '50.00' ? 'PASS' : 'FAIL', 'S43 exact straight-line depreciation money', `asset=${ids.asset} expected=50.00 actual=${depRow.depreciation_amount}; accumulated_after=${depRow.accumulated_after}; journal=${depRow.journal_entry_id}`);
    } else blocked('S43 exact depreciation row', 'Current-month run returned no row for the disposable asset; no seeded asset was changed further.');
    const depReplay = await api('/asset-depreciations/run', { method: 'POST', body: { year: 2026, month: 9 } });
    expect('S43 depreciation run idempotent replay', depReplay, 200);
    if (depRow && short(depReplay.body).includes(depRow.journal_entry_id ?? '__missing__')) record('PASS', 'S43 depreciation replay preserves journal identity', `journal=${depRow.journal_entry_id}`);
    const disposal = await api(`/assets/${ids.asset}/dispose`, { method: 'POST', body: { disposal_amount: '600.00', disposed_date: '2026-09-14', remarks: 'S43 disposable disposal SoD probe' } });
    expect('S43 finance disposal request', disposal, 200);
    if (disposal.status === 200) {
      const selfApprove = await api(`/assets/${ids.asset}/dispose/approve`, { method: 'POST', body: { remarks: 'finance self-approval probe' } });
      expect('S43 finance self-approval denial', selfApprove, [403, 422]);
      await expect('S43 finance disposal cancel', await api(`/assets/${ids.asset}/dispose/cancel`, { method: 'POST' }), 200);
    }
    await expect('S43 archive asset with depreciation reference blocked', await api(`/assets/${ids.asset}`, { method: 'DELETE' }), 422);
  }
  await expect('S43 finance wrong-role asset disposal approval denial', await api('/assets/not-a-hash/dispose/approve', { method: 'POST', body: {} }), [403, 404]);

  // S43 executive boundary is a separate session, with no mutation of seeded assets.
  await login('vp', 'vp@ogami.test');
  await ui('/assets', 'vp-assets');
  await expect('S43 VP asset list read', await api('/assets?per_page=20'), 200);
  if (ids.asset) await expect('S43 VP stale cancelled disposal approval denial', await api(`/assets/${ids.asset}/dispose/approve`, { method: 'POST', body: {} }), [403, 422]);

  // S44 — forecasting reads, manual override and advisory MRP inclusion.
  await login('ppc', 'ppc@ogami.test');
  await ui('/forecasting/demand', 'ppc-forecast-demand');
  await ui('/forecasting/stock-out', 'ppc-forecast-stockout');
  await ui('/forecasting/accuracy', 'ppc-forecast-accuracy');
  const forecastOptions = await read('/forecasting/demand-forecasts/options', 'S44 forecast options');
  await read('/forecasting/settings', 'S44 forecast settings');
  const forecastList = await read('/forecasting/demand-forecasts?per_page=100', 'S44 demand forecast list');
  await read('/forecasting/accuracy?year=2026', 'S44 legacy accuracy');
  await read('/forecasting/accuracy/summary?year=2026', 'S44 accuracy summary');
  await read('/forecasting/accuracy/products?year=2026', 'S44 accuracy products');
  await read('/forecasting/stock-out?horizon_days=30', 'S44 stockout projection');
  await read('/forecasting/mrp-projection?year=2026&month=10', 'S44 forecast MRP projection');
  const forecast = list(forecastList.body)[0];
  const forecastProduct = forecast?.product?.id ?? forecast?.product_id;
  if (!forecastProduct) {
    blocked('S44 manual forecast and historical demand', 'No seeded demand forecast product hash was returned; no product was invented or changed.');
  } else {
    await read(`/forecasting/demand-forecasts/historical?product_id=${forecastProduct}&months_back=3`, 'S44 historical demand');
    const nextMonth = new Date(Date.UTC(2026, 9, 1));
    const year = nextMonth.getUTCFullYear(); const month = nextMonth.getUTCMonth() + 1;
    const existingNext = list(forecastList.body).find((row) => row.product?.id === forecastProduct && Number(row.forecast_year) === year && Number(row.forecast_month) === month);
    if (existingNext) {
      blocked('S44 manual override behavior', `The next forecast period already has seeded forecast ${existingNext.id}; preserving seeded forecasts prevented a manual overwrite probe.`);
    } else {
      const manual = await api('/forecasting/demand-forecasts/manual', { method: 'POST', body: { product_id: forecastProduct, forecast_year: year, forecast_month: month, forecasted_quantity: 17, confidence_level: 88 } });
      expect('S44 disposable manual forecast create', manual, 201);
      ids.forecast = idOf(manual.body);
      const recompute = await api('/forecasting/demand-forecasts/recompute', { method: 'POST', body: { product_id: forecastProduct, method: 'moving_avg', horizon_months: 1, lookback_months: 3, overwrite_manual: false } });
      expect('S44 recompute preserves manual override', recompute, 200);
      record(Array.isArray(recompute.body?.skipped_manual) && recompute.body.skipped_manual.length > 0 ? 'PASS' : 'FAIL', 'S44 manual override skip evidence', `skipped_manual=${short(recompute.body?.skipped_manual)}; forecast=${ids.forecast}`);
    }
    const productDetail = await read(`/crm/products/${forecastProduct}`, 'S44 product detail for inclusion');
    const beforeInclude = data(productDetail.body)?.include_forecast_in_mrp;
    const inclusion = await api(`/forecasting/products/${forecastProduct}/mrp-inclusion`, { method: 'PATCH', body: { include_forecast_in_mrp: !Boolean(beforeInclude) } });
    expect('S44 product MRP inclusion toggle', inclusion, 200);
    await expect('S44 product MRP inclusion toggle restore', await api(`/forecasting/products/${forecastProduct}/mrp-inclusion`, { method: 'PATCH', body: { include_forecast_in_mrp: Boolean(beforeInclude) } }), 200);
    await expect('S44 invalid forecast product hash', await api('/forecasting/demand-forecasts/historical?product_id=raw-integer-1&months_back=3'), [404, 422]);
  }
  await expect('S44 PPC cannot mutate asset master', await api('/assets', { method: 'POST', body: {} }), 403);

  // S45 — customer RMA: CRM maker, department and production approval, warehouse receipt/disposition, QC inspection.
  await login('crm', 'crm@ogami.test');
  await ui('/return-management', 'crm-rma-list');
  await ui('/return-management/new', 'crm-rma-create');
  await read('/return-management/options', 'S45 RMA options');
  const customers = await read('/crm/customers?per_page=100', 'S45 CRM customer source list');
  const customer = list(customers.body)[0];
  if (!customer?.id) {
    blocked('S45 customer RMA chain', 'No customer hash was available to resolve a source document.');
  } else {
    const source = await read(`/return-management/return-requests/source-options?type=customer_return&customer_id=${customer.id}`, 'S45 customer RMA source options');
    const sourceData = data(source.body)?.customer ?? {};
    const invoice = sourceData.invoices?.find((doc) => doc.lines?.some((line) => Number(line.remaining_quantity) > 0 && line.product_id && line.item_id));
    const sourceLine = invoice?.lines?.find((line) => Number(line.remaining_quantity) > 0 && line.product_id && line.item_id);
    ids.rmaCustomer = customer.id;
    if (!invoice || !sourceLine) {
      blocked('S45 source-backed customer RMA chain', 'No customer invoice line with remaining quantity, product hash, and stockable item hash was available; seeded RMAs and invoices were preserved.');
    } else {
      ids.rmaSourceInvoice = invoice.id; ids.rmaSourceInvoiceItem = sourceLine.id;
      const invalidRma = await api('/return-management/return-requests', { method: 'POST', body: { type: 'customer_return', customer_id: customer.id, items: [{ product_id: sourceLine.product_id, item_id: sourceLine.item_id, quantity: '0.000', source_invoice_item_id: sourceLine.id }] } });
      expect('S45 invalid zero-quantity RMA', invalidRma, 422);
      const rma = await api('/return-management/return-requests', { method: 'POST', body: {
        type: 'customer_return', customer_id: customer.id, invoice_id: invoice.id, reason_code: 'defect', reason_description: 'Disposable S45 customer return chain',
        items: [{ product_id: sourceLine.product_id, item_id: sourceLine.item_id, quantity: '1.000', unit_price: sourceLine.unit_price, source_invoice_item_id: sourceLine.id, condition: 'defective', reason: 'S45 disposable return line' }],
      } });
      expect('S45 disposable customer RMA create', rma, 201);
      ids.rma = idOf(rma.body);
      if (ids.rma) {
        const rmaDetail = await read(`/return-management/return-requests/${ids.rma}`, 'S45 RMA detail before submit');
        const rmaLine = data(rmaDetail.body)?.items?.[0];
        await expect('S45 RMA prefilled partial edit', await api(`/return-management/return-requests/${ids.rma}`, { method: 'PATCH', body: { customer_notes: 'S45 partial edit retained', reason_description: 'S45 edited return reason' } }), 200);
        await expect('S45 RMA submit', await api(`/return-management/return-requests/${ids.rma}/submit`, { method: 'POST' }), 200);
        await expect('S45 RMA submit replay terminal guard', await api(`/return-management/return-requests/${ids.rma}/submit`, { method: 'POST' }), 422);
        await login('depthead', 'depthead@ogami.test');
        await ui(`/return-management/${ids.rma}`, 'depthead-rma-review');
        await expect('S45 department RMA approve', await api(`/return-management/return-requests/${ids.rma}/approve`, { method: 'POST', body: { remarks: 'S45 department review' } }), 200);
        await expect('S45 department RMA approval replay wrong step', await api(`/return-management/return-requests/${ids.rma}/approve`, { method: 'POST', body: {} }), [403, 422]);
        await login('production', 'production@ogami.test');
        await ui(`/return-management/${ids.rma}`, 'production-rma-approval');
        await expect('S45 production RMA approve', await api(`/return-management/return-requests/${ids.rma}/approve`, { method: 'POST', body: { remarks: 'S45 production approval' } }), 200);
        await login('warehouse', 'warehouse@ogami.test');
        await ui(`/return-management/${ids.rma}`, 'warehouse-rma-receive');
        const beforeRma = await read(`/return-management/return-requests/${ids.rma}`, 'S45 approved RMA before receipt');
        const lineHash = data(beforeRma.body)?.items?.[0]?.id ?? rmaLine?.id;
        await expect('S45 warehouse receive invalid over-quantity', await api(`/return-management/return-requests/${ids.rma}/receive`, { method: 'POST', body: { received_quantities: { [lineHash]: '2.000' } } }), 422);
        const received = await api(`/return-management/return-requests/${ids.rma}/receive`, { method: 'POST', body: { received_quantities: { [lineHash]: '1.000' } } });
        expect('S45 warehouse receive quarantine', received, 200);
        await expect('S45 receive replay terminal guard', await api(`/return-management/return-requests/${ids.rma}/receive`, { method: 'POST', body: { received_quantities: { [lineHash]: '1.000' } } }), 422);
        await login('qc', 'qc@ogami.test');
        await ui(`/return-management/${ids.rma}`, 'qc-rma-inspection');
        const staged = await api(`/return-management/return-requests/${ids.rma}/inspect`, { method: 'POST', body: { internal_notes: 'S45 QC inspection staged' } });
        expect('S45 QC RMA inspection staging', staged, 200);
        const stagedDetail = await read(`/return-management/return-requests/${ids.rma}`, 'S45 RMA inspection detail');
        ids.rmaInspection = data(stagedDetail.body)?.inspection_id;
        if (ids.rmaInspection) {
          const inspection = await read(`/quality/inspections/${ids.rmaInspection}`, 'S45 return inspection detail');
          const measurements = data(inspection.body)?.measurements ?? [];
          if (measurements.length) {
            const rows = measurements.map((m) => ({ id: m.id, measured_value: String(m.nominal_value ?? '100.0000'), is_pass: true, notes: 'S45 returned item actual' }));
            await expect('S45 QC return inspection measurements', await api(`/quality/inspections/${ids.rmaInspection}/measurements`, { method: 'PATCH', body: { measurements: rows } }), 200);
            await expect('S45 QC return inspection complete', await api(`/quality/inspections/${ids.rmaInspection}/complete`, { method: 'POST' }), 200);
          } else blocked('S45 QC return inspection completion', 'Auto-created return inspection had no measurement rows.');
        } else if (data(staged.body)?.inspection_handoff_status === 'manual_required') {
          blocked('S45 QC inspection handoff', `RMA remained received with manual_required handoff: ${data(staged.body)?.inspection_handoff_message}`);
        }
        await login('warehouse', 'warehouse@ogami.test');
        const afterInspection = await read(`/return-management/return-requests/${ids.rma}`, 'S45 RMA before disposal');
        const disposeLine = data(afterInspection.body)?.items?.[0]?.id ?? lineHash;
        const dispose = await api(`/return-management/return-requests/${ids.rma}/dispose`, { method: 'POST', body: { dispositions: [{ item_id: disposeLine, disposition: 'scrap', notes: 'S45 scrap creates NCR and credit' }] } });
        expect('S45 warehouse dispose scrap', dispose, [200, 422]);
        if (dispose.status === 200) {
          const disposed = data(dispose.body);
          observed('S45 RMA cross-module disposal effects', `status=${disposed?.status}; disposition_status=${disposed?.disposition_status}; credit_note=${disposed?.credit_note?.id ?? disposed?.credit_note_id ?? null}; NCR=${disposed?.items?.[0]?.ncr_id ?? null}; stockMovement=${disposed?.stock_movement_id ?? null}`);
          await expect('S45 warehouse RMA complete', await api(`/return-management/return-requests/${ids.rma}/complete`, { method: 'POST', body: {} }), 200);
          await expect('S45 RMA terminal complete replay', await api(`/return-management/return-requests/${ids.rma}/complete`, { method: 'POST', body: {} }), 422);
        } else blocked('S45 dispose/complete effects', `Dispose returned ${dispose.status}; inspect status before dispose was ${data(afterInspection.body)?.status}.`);
      }
    }
    // A separate finance-only RMA exercises draft cancel without reserving the source line.
    const financeOnly = await api('/return-management/return-requests', { method: 'POST', body: { type: 'customer_return', customer_id: customer.id, finance_only: true, finance_only_reason: 'S45 disposable no-goods credit probe', items: [{ product_id: sourceLine.product_id, quantity: '1.000', unit_price: sourceLine.unit_price }] } });
    expect('S45 finance-only RMA create', financeOnly, 201);
    ids.rmaCancel = idOf(financeOnly.body);
    if (ids.rmaCancel) {
      await expect('S45 RMA cancel', await api(`/return-management/return-requests/${ids.rmaCancel}/cancel`, { method: 'POST', body: { reason: 'S45 disposable cancel cleanup' } }), 200);
      await expect('S45 cancelled RMA terminal replay', await api(`/return-management/return-requests/${ids.rmaCancel}/submit`, { method: 'POST' }), 422);
    }
  }
} catch (error) {
  record('FAIL', 'S41-S45 live harness', error instanceof Error ? error.stack ?? error.message : String(error));
} finally {
  await logout().catch(() => {});
  await browser.close();
}

const counts = results.reduce((out, row) => ({ ...out, [row.status]: (out[row.status] ?? 0) + 1 }), {});
console.log(JSON.stringify({ ids, counts, results }, null, 2));
