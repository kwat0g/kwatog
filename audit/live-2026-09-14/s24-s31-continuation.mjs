import { firefox } from '../../spa/node_modules/playwright/index.mjs';

const browser = await firefox.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await context.newPage();
const password = 'password';
const artifact = '/home/kwat0g/Desktop/kwatog/audit/live-2026-09-14/artifacts';
const ids = { po: 'DzAwdKb5ld', item: 'E1mbe9bGrz', location: '9eQbB6qb7G', qlocation: 'VW1wmPxbLZ', warehouse: 'R8epjLbOaD', mrb: 'GqkbAVwxd1' };
const results = [];
let actor = 'signed-out';
let lastLogin = 0;
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const dataOf = (body) => body?.data ?? body;
const bodySummary = (body) => JSON.stringify(body ?? '').slice(0, 700);
function record(status, name, detail = '') { results.push({ status, actor, name, detail }); console.log(`${status} ${name}${detail ? ` :: ${detail}` : ''}`); }
function expect(name, response, statuses, detail = '') { const ok = (Array.isArray(statuses) ? statuses : [statuses]).includes(response.status); record(ok ? 'PASS' : 'FAIL', name, `HTTP ${response.status}; expected ${(Array.isArray(statuses) ? statuses : [statuses]).join('/')} ${detail}; body=${bodySummary(response.body)}`); return ok; }
async function api(path, options = {}) {
  return page.evaluate(async ({ path, options }) => {
    const xsrf = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice(10);
    const r = await fetch(`/api/v1${path}`, { method: options.method ?? 'GET', credentials: 'include', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}), ...(options.body === undefined ? {} : { 'Content-Type': 'application/json' }) }, body: options.body === undefined ? undefined : JSON.stringify(options.body) });
    const text = await r.text(); let body; try { body = JSON.parse(text); } catch { body = text; }
    return { status: r.status, body, contentType: r.headers.get('content-type') };
  }, { path, options });
}
async function logout() { if (actor !== 'signed-out') await api('/auth/logout', { method: 'POST' }).catch(() => {}); await context.clearCookies(); actor = 'signed-out'; }
async function login(alias, email) { await logout(); const wait = Math.max(0, 13000 - (Date.now() - lastLogin)); if (wait) await sleep(wait); await page.goto('http://localhost/login', { waitUntil: 'domcontentloaded' }); await page.locator('input[type="email"],input[name="email"]').first().fill(email); await page.locator('input[type="password"]').first().fill(password); await page.getByRole('button', { name: /sign in|log in/i }).click(); await page.waitForURL((u) => !u.pathname.endsWith('/login')); actor = alias; lastLogin = Date.now(); }
async function ui(path, name) { const r = await page.goto(`http://localhost${path}`, { waitUntil: 'domcontentloaded' }); await page.locator('body').waitFor({ state: 'visible' }); await page.waitForFunction(() => document.body.innerText.trim().length > 40); await page.screenshot({ path: `${artifact}/s24-s31-${name}.png`, fullPage: true }).catch(() => {}); record(/application error|internal server error/i.test(await page.locator('body').innerText()) ? 'FAIL' : 'PASS', `${name} UI`, `document=${r?.status() ?? 'none'} uiUrl=${page.url()}`); }

try {
  await login('purchasing', 'purchasing@ogami.test');
  await ui(`/purchasing/purchase-orders/${ids.po}`, 'purchasing-sent-po-detail');
  const po = await api(`/purchasing/purchase-orders/${ids.po}`);
  expect('continuation sent PO detail', po, 200);
  const line = dataOf(po.body)?.items?.[0];
  const vendorIds = [dataOf(po.body)?.vendor?.id, 'dGypLxpvAg', 'GqkbAVwxd1'].filter(Boolean);
  for (const vendorId of vendorIds) {
    const perf = await api(`/purchasing/vendors/${vendorId}/performance?months=6`);
    expect(`S30 supplier performance route vendor=${vendorId}`, perf, 200);
  }
  if (!line?.id) throw new Error(`PO detail did not expose a line hash: ${bodySummary(po.body)}`);
  await logout();

  await login('warehouse', 'warehouse@ogami.test');
  await ui('/inventory/grn', 'warehouse-grn-continuation');
  const stockBefore = await api(`/inventory/stock-levels?item_id=${ids.item}&location_id=${ids.location}`);
  const grn = await api('/inventory/grn', { method: 'POST', body: { purchase_order_id: ids.po, received_date: '2026-09-14', remarks: 'Live B4 continuation incoming receipt', items: [{ purchase_order_item_id: line.id, item_id: ids.item, location_id: ids.location, quantity_received: '1.500', unit_cost: '10.10', lot_number: 'B4CONT51545426', moisture_percentage: '0.500' }] } });
  expect('S28 GRN create pending_qc continuation', grn, 201);
  const grnId = dataOf(grn.body)?.id;
  const grnDetail = await api(`/inventory/grn/${grnId}`);
  expect('S28 GRN detail continuation', grnDetail, 200);
  const afterCreate = await api(`/inventory/stock-levels?item_id=${ids.item}&location_id=${ids.location}`);
  expect('S28 stock unchanged while pending_qc', afterCreate, 200, `before=${bodySummary(stockBefore.body)} during=${bodySummary(afterCreate.body)}`);
  const inspectionId = dataOf(grnDetail.body)?.qc_inspection?.id;
  expect('S28 incoming inspection hash exposed', { status: inspectionId ? 200 : 404, body: dataOf(grnDetail.body)?.qc_inspection }, 200, `inspection=${inspectionId}`);
  const beforeAccept = await api(`/inventory/grn/${grnId}/accept`, { method: 'PATCH' });
  expect('S28 GRN QC gate denies warehouse pre-QC', beforeAccept, 422);
  await logout();

  await login('qc', 'qc@ogami.test');
  await ui('/quality/inspections', 'qc-incoming-inspection-continuation');
  const inspection = await api(`/quality/inspections/${inspectionId}`);
  expect('S28 QC incoming inspection detail continuation', inspection, 200);
  const measurements = dataOf(inspection.body)?.measurements ?? [];
  if (measurements[0]?.id) {
    const patch = await api(`/quality/inspections/${inspectionId}/measurements`, { method: 'PATCH', body: { measurements: [{ id: measurements[0].id, is_pass: true, notes: 'Continuation pass' }] } });
    expect('S28 QC measurement pass continuation', patch, 200);
    const complete = await api(`/quality/inspections/${inspectionId}/complete`, { method: 'POST' });
    expect('S28 QC complete continuation', complete, 200);
  } else record('BLOCKED', 'S28 QC completion continuation', 'No measurement row exposed; no fixture manufactured');
  await logout();

  await login('warehouse', 'warehouse@ogami.test');
  const accept = await api(`/inventory/grn/${grnId}/accept`, { method: 'PATCH' });
  expect('S28 GRN accept after QC continuation', accept, 200);
  const acceptReplay = await api(`/inventory/grn/${grnId}/accept`, { method: 'PATCH' });
  expect('S28 GRN accept replay continuation', acceptReplay, 422);
  const stockAfter = await api(`/inventory/stock-levels?item_id=${ids.item}&location_id=${ids.location}`);
  expect('S28 stock/WAC after accepted GRN continuation', stockAfter, 200, `after=${bodySummary(stockAfter.body)}`);
  const movements = await api(`/inventory/stock-movements?item_id=${ids.item}&per_page=100`);
  expect('S28 accepted GRN movement reference continuation', movements, 200);
  await logout();

  // MRB cleanup needs a Scrap-zone destination. This is a disposable cleanup
  // fixture, not a business record used to manufacture the original hold.
  await login('warehouse', 'warehouse@ogami.test');
  const scrapZone = await api('/inventory/zones', { method: 'POST', body: { warehouse_id: ids.warehouse, name: 'Live B4 Scrap Cleanup', code: 'B4SCRAP', zone_type: 'scrap' } });
  expect('S29 disposable cleanup scrap zone', scrapZone, 201);
  const scrapZoneId = dataOf(scrapZone.body)?.id;
  const scrapLoc = await api('/inventory/locations', { method: 'POST', body: { zone_id: scrapZoneId, code: 'B4SCRAP1', rack: 'S1', bin: 'B1', is_active: true } });
  expect('S29 disposable cleanup scrap location', scrapLoc, 201);
  const release = await api(`/inventory/mrb/${ids.mrb}/release`, { method: 'POST', body: { disposition: 'scrap', notes: 'Live B4 cleanup disposition' } });
  expect('S29 MRB release to Scrap continuation', release, 200);
  const releaseReplay = await api(`/inventory/mrb/${ids.mrb}/release`, { method: 'POST', body: { disposition: 'scrap', notes: 'replay' } });
  expect('S29 MRB release replay terminal denial', releaseReplay, 422);
  await api(`/inventory/locations/${dataOf(scrapLoc.body)?.id}`, { method: 'DELETE' });
  await api(`/inventory/zones/${scrapZoneId}`, { method: 'DELETE' });
  await logout();
} catch (error) {
  record('FAIL', 'S24-S31 continuation harness', error instanceof Error ? error.stack ?? error.message : String(error));
} finally { await logout().catch(() => {}); await browser.close(); }
console.log(JSON.stringify({ ids, results, counts: results.reduce((a, r) => ({ ...a, [r.status]: (a[r.status] ?? 0) + 1 }), {}) }, null, 2));
