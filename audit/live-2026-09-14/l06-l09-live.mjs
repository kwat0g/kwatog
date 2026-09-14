import { firefox } from '../../spa/node_modules/playwright/index.mjs';
import fs from 'node:fs/promises';

const base = 'http://localhost';
const artifactDir = '/home/kwat0g/Desktop/kwatog/audit/live-2026-09-14/artifacts';
const stamp = Date.now().toString().slice(-8);
const password = 'password';
const results = [];
const evidence = [];
const ids = {};
const residuals = [];
let actor = 'signed-out';
let lastLogin = 0;

const browser = await firefox.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await context.newPage();

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const dataOf = (body) => body?.data ?? body;
const rowsOf = (body) => {
  const value = dataOf(body);
  if (Array.isArray(value)) return value;
  if (Array.isArray(value?.data)) return value.data;
  return [];
};
const idOf = (body) => dataOf(body)?.id ?? dataOf(body)?.hash_id ?? null;
const brief = (value) => JSON.stringify(value ?? '').slice(0, 2400);
const cents = (value) => Math.round(Number(value ?? 0) * 100);
const money = (value) => (cents(value) / 100).toFixed(2);

function record(status, name, detail = '') {
  results.push({ status, actor, name, detail });
  console.log(`${status} ${actor} ${name}${detail ? ` :: ${detail}` : ''}`);
}
function expect(name, response, statuses, detail = '') {
  const accepted = Array.isArray(statuses) ? statuses : [statuses];
  const ok = accepted.includes(response.status);
  record(ok ? 'PASS' : 'FAIL', name, `HTTP ${response.status}; expected ${accepted.join('/')} ${detail}; body=${brief(response.body)}`);
  return ok;
}
function assert(name, condition, detail = '') {
  record(condition ? 'PASS' : 'FAIL', name, detail || (condition ? 'condition true' : 'condition false'));
  return condition;
}
function blocked(name, detail) { record('BLOCKED', name, detail); }
function observed(name, detail) { record('OBSERVED', name, detail); }
function retain(type, id, detail = '') {
  if (id) residuals.push({ type, id, detail });
  return id;
}

async function api(path, options = {}) {
  const response = await page.evaluate(async ({ path, options }) => {
    const sanitize = (value) => {
      if (Array.isArray(value)) return value.map(sanitize);
      if (!value || typeof value !== 'object') return value;
      return Object.fromEntries(Object.entries(value).map(([key, item]) => [
        key,
        /temp_password|temporary_password|password_hash/i.test(key) ? '[REDACTED]' : sanitize(item),
      ]));
    };
    const xsrf = document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length);
    const headers = {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
      ...(options.body === undefined ? {} : { 'Content-Type': 'application/json' }),
      ...(options.headers ?? {}),
    };
    const response = await fetch(path.startsWith('/api/') ? path : `/api/v1${path}`, {
      method: options.method ?? 'GET', credentials: 'include', headers,
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
    });
    const text = await response.text();
    let body;
    try { body = JSON.parse(text); } catch { body = text; }
    return {
      status: response.status,
      body: sanitize(body),
      contentType: response.headers.get('content-type'),
      disposition: response.headers.get('content-disposition'),
      requestId: response.headers.get('x-request-id') ?? response.headers.get('x-correlation-id'),
    };
  }, { path, options });
  evidence.push({ actor, method: options.method ?? 'GET', path, status: response.status, requestId: response.requestId ?? null, body: response.body });
  return response;
}

async function file(path, accept = 'application/pdf') {
  const response = await page.evaluate(async ({ path, accept }) => {
    const xsrf = document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length);
    const r = await fetch(`/api/v1${path}`, {
      credentials: 'include', headers: { Accept: accept, 'X-Requested-With': 'XMLHttpRequest', ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}) },
    });
    return { status: r.status, contentType: r.headers.get('content-type'), disposition: r.headers.get('content-disposition'), bytes: (await r.arrayBuffer()).byteLength };
  }, { path, accept });
  evidence.push({ actor, method: 'GET', path, status: response.status, file: response });
  return response;
}

async function screenshot(name) {
  await page.screenshot({ path: `${artifactDir}/l06-l09-${name}.png`, fullPage: true }).catch(() => {});
}

async function ui(path, name) {
  const response = await page.goto(`${base}${path}`, { waitUntil: 'domcontentloaded' });
  await page.locator('body').waitFor({ state: 'visible', timeout: 20000 });
  await page.waitForFunction(() => document.body.innerText.trim().length > 30, null, { timeout: 25000 }).catch(() => {});
  const text = await page.locator('body').innerText();
  await screenshot(name);
  const bad = /application error|internal server error|something went wrong/i.test(text);
  record(bad ? 'FAIL' : 'PASS', `${name} UI`, `document=${response?.status() ?? 'none'} uiUrl=${page.url()}`);
  return text;
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
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 30000 });
  actor = alias;
  lastLogin = Date.now();
  const me = await api('/auth/user');
  expect(`${alias} authenticated`, me, 200, `uiUrl=${page.url()}`);
  return me;
}

async function poll(path, predicate, timeoutMs = 70000, intervalMs = 2500) {
  const deadline = Date.now() + timeoutMs;
  let latest = null;
  while (Date.now() < deadline) {
    latest = await api(path);
    if (predicate(latest)) return latest;
    await sleep(intervalMs);
  }
  return latest;
}

function findId(value, keys = ['id', 'hash_id']) {
  if (!value || typeof value !== 'object') return null;
  for (const key of keys) if (value[key]) return value[key];
  return null;
}

async function findBilledForGrn(grnId) {
  const bills = await api('/bills?per_page=100');
  const match = rowsOf(bills.body).find((bill) => bill.goods_receipt_note_id === grnId || bill.goods_receipt_notes?.some((row) => row.id === grnId));
  return { bills, match };
}

try {
  await fs.mkdir(artifactDir, { recursive: true });

  // L06 — create only a disposable SO. Confirm publishes the MRP event; do not
  // call the all-active-sales-orders manual run because that mutates seeded SOs.
  await login('crm', 'crm@ogami.test');
  await ui('/crm/sales-orders', 'crm-sales-orders');
  const [customers, products, agreements] = await Promise.all([
    api('/crm/customers?per_page=100'),
    api('/crm/products?per_page=100'),
    api('/crm/price-agreements?per_page=100'),
  ]);
  expect('L06 CRM customer source list', customers, 200);
  expect('L06 CRM product source list', products, 200);
  expect('L06 CRM price-agreement source list', agreements, 200);
  const activeCustomers = rowsOf(customers.body).filter((row) => row.is_active !== false);
  const activeProducts = rowsOf(products.body).filter((row) => row.is_active !== false);
  const agreement = rowsOf(agreements.body).find((row) => {
    const customerId = row.customer_id ?? row.customer?.id;
    const productId = row.product_id ?? row.product?.id;
    return row.is_active !== false && row.status !== 'inactive' && activeCustomers.some((customer) => customer.id === customerId) && activeProducts.some((product) => product.id === productId);
  });
  const agreementCustomer = agreement?.customer_id ?? agreement?.customer?.id;
  const agreementProduct = agreement?.product_id ?? agreement?.product?.id;
  const customer = activeCustomers.find((row) => row.id === agreementCustomer);
  const productRows = activeProducts.filter((row) => row.id === agreementProduct);
  if (!customer?.id || productRows.length === 0) {
    blocked('L06 disposable SO fixture', `No active customer/product pair with an active price agreement. agreement=${brief(agreement)} customers=${activeCustomers.length} products=${activeProducts.length}`);
  } else {
    ids.customer = customer.id;
    await logout();
    await login('ppc', 'ppc@ogami.test');
    await ui('/mrp/boms', 'ppc-boms');
    let bomProduct = null;
    let bomBody = null;
    for (const candidate of productRows.slice(0, 30)) {
      const bom = await api(`/mrp/products/${candidate.id}/bom`);
      if (bom.status === 200) {
        const value = dataOf(bom.body);
        const components = value?.items ?? value?.components ?? value?.lines ?? [];
        if (value && (Array.isArray(components) ? components.length > 0 : true)) {
          bomProduct = candidate;
          bomBody = value;
          break;
        }
      }
    }
    if (!bomProduct) {
      blocked('L06 active BOM fixture', `No active product BOM with component lines was returned for ${productRows.length} active CRM products; no SO was created.`);
    } else {
      ids.product = bomProduct.id;
      observed('L06 source master hashes', `customer=${ids.customer}; product=${ids.product}; bom=${brief(bomBody)}`);
      await logout();
      await login('crm', 'crm@ogami.test');
      const date = new Date().toISOString().slice(0, 10);
      const delivery = new Date(Date.now() + 14 * 86400000).toISOString().slice(0, 10);
      const so = await api('/crm/sales-orders', { method: 'POST', body: {
        customer_id: ids.customer,
        date,
        payment_terms_days: 30,
        delivery_terms: 'Disposable live P2P audit order',
        notes: `LIVE-B11 disposable L06 ${stamp}`,
        items: [{ product_id: ids.product, quantity: '1.00', delivery_date: delivery }],
      } });
      expect('L06 CRM creates disposable SO', so, 201);
      ids.so = idOf(so.body);
      retain('sales-order', ids.so, 'confirmed disposable source for L06-L09');
      if (!ids.so) {
        blocked('L06 confirmed SO and queued MRP source', 'Disposable SO creation did not return a hash ID; no confirm, queue poll, or downstream mutation was attempted.');
        blocked('L07 requisition to PO', 'No confirmed SO/MRP auto-PR source exists.');
        blocked('L08 import and receipt', 'No source sent PO exists.');
        blocked('L09 inventory and AP', 'No source GRN exists.');
      } else {
      if (ids.so) {
        const confirm = await api(`/crm/sales-orders/${ids.so}/confirm`, { method: 'POST' });
        expect('L06 CRM confirms disposable SO', confirm, 200);
        const replay = await api(`/crm/sales-orders/${ids.so}/confirm`, { method: 'POST' });
        expect('L06 SO confirmation replay denied', replay, 422);
        const soDetail = await api(`/crm/sales-orders/${ids.so}`);
        expect('L06 confirmed SO exact detail', soDetail, 200);
        ids.soNumber = dataOf(soDetail.body)?.so_number;
        observed('L06 confirmed SO reference', `so=${ids.so}; so_number=${ids.soNumber}; total=${dataOf(soDetail.body)?.total_amount}; status=${dataOf(soDetail.body)?.status}`);
      }

      // The queue worker owns this handoff. Poll only the new SO's plan.
      await logout();
      await login('ppc', 'ppc@ogami.test');
      const plan = await poll(`/mrp/sales-orders/${ids.so}/mrp-plan`, (response) => response.status === 200 && Boolean(dataOf(response.body)), 90000);
      expect('L06 queued MRP plan for disposable SO', plan, 200);
      const planData = dataOf(plan.body);
      ids.plan = idOf(plan.body);
      retain('mrp-plan', ids.plan, 'queue-created plan for disposable SO');
      observed('L06 exact MRP plan state', `plan=${ids.plan}; plan_number=${planData?.mrp_plan_no}; shortages=${planData?.shortages_found}; auto_pr=${planData?.auto_pr_count}; draft_wos=${planData?.draft_wo_count}; diagnostics=${brief(planData?.diagnostics)}; purchase_requests=${brief(planData?.purchase_requests)}; work_orders=${brief(planData?.work_orders)}`);
      assert('L06 MRP plan links the confirmed SO', planData?.sales_order?.id === ids.so || planData?.generation?.source_id === ids.so, `so=${ids.so}; plan=${brief(planData)}`);
      assert('L06 MRP plan exposes non-numeric references', !/"(?:id|\w+_id)"\s*:\s*\d+(?:\.\d+)?(?:[,}])/i.test(JSON.stringify(planData)), 'recursive plan response reference check');

      if (!planData || Number(planData.shortages_found ?? 0) < 1 || Number(planData.auto_pr_count ?? 0) < 1) {
        blocked('L06 shortage and consolidated auto-PR', `MRP completed with shortages_found=${planData?.shortages_found ?? 'none'}, auto_pr_count=${planData?.auto_pr_count ?? 'none'}; no artificial stock depletion or high-quantity shortage was manufactured.`);
        blocked('L07 requisition to PO', 'No shortage means no source auto-PR; PR approval/conversion and PO approval/send were not manufactured from a manual substitute.');
        blocked('L08 import and receipt', 'No source sent PO exists because the disposable SO MRP plan had no shortage auto-PR.');
        blocked('L09 inventory and AP', 'No source GRN exists; stock, bill, three-way match, post, payment, and QC-fail stock/bill assertions were not manufactured.');
        await ui('/mrp/plans', 'l06-no-shortage-mrp-plans');
      } else {
        // Continue only from the auto-PR linked by the plan.
        const prSummary = planData.purchase_requests?.[0];
        ids.pr = prSummary?.id;
        retain('purchase-request', ids.pr, 'MRP auto-generated PR');
        if (!ids.pr) {
          blocked('L07 auto-PR linkage', `Plan reports auto_pr_count=${planData.auto_pr_count} but no purchase_requests hash was serialized.`);
        } else {
          await logout();
          await login('purchasing', 'purchasing@ogami.test');
          await ui('/purchasing/purchase-requests', 'purchasing-purchase-requests');
          const prDetail = await api(`/purchasing/purchase-requests/${ids.pr}`);
          expect('L07 auto-generated PR detail', prDetail, 200);
          const prData = dataOf(prDetail.body);
          assert('L07 PR is auto-generated and linked to MRP plan', prData?.is_auto_generated === true && (prData?.mrp_plan_id === ids.plan || prData?.mrp_plan?.id === ids.plan), `pr=${brief(prData)}`);
          observed('L07 exact auto-PR state', `pr=${ids.pr}; number=${prData?.pr_number}; status=${prData?.status}; priority=${prData?.priority}; department=${prData?.department?.id ?? prData?.department_id}; total=${prData?.total_estimated_amount}; items=${brief(prData?.items)}`);

          // The MRP service intentionally leaves department ownership for submit.
          // Resolve a real seeded department through the department-head read path;
          // this is an operator edit of the disposable draft, not a DB/service seed.
          if (!prData?.department?.id && !prData?.department_id) {
            await logout();
            await login('depthead', 'depthead@ogami.test');
            const deptRows = await api('/hr/employees?per_page=100');
            const own = rowsOf(deptRows.body).find((row) => /Roberto Santos/i.test(`${row.full_name} ${row.name}`)) ?? rowsOf(deptRows.body)[0];
            ids.department = own?.department?.id ?? own?.department_id;
            observed('L07 owning department resolution', `department=${ids.department}; source=${brief(own)}`);
            await logout();
            await login('purchasing', 'purchasing@ogami.test');
            if (ids.department) {
              const assigned = await api(`/purchasing/purchase-requests/${ids.pr}`, { method: 'PUT', body: { department_id: ids.department } });
              expect('L07 purchasing assigns auto-PR owning department', assigned, 200);
            }
          }
          const submitted = await api(`/purchasing/purchase-requests/${ids.pr}/submit`, { method: 'PATCH' });
          expect('L07 submits auto-PR', submitted, 200);
          const submitReplay = await api(`/purchasing/purchase-requests/${ids.pr}/submit`, { method: 'PATCH' });
          expect('L07 auto-PR submit replay denied', submitReplay, 422);
          const afterSubmit = await api(`/purchasing/purchase-requests/${ids.pr}`);
          const prAfterSubmit = dataOf(afterSubmit.body);
          ids.prTotal = prAfterSubmit?.total_estimated_amount ?? prAfterSubmit?.total_amount ?? prData?.total_estimated_amount;

          // Finance is the current money-only first step. A department-head step
          // is not invented because the seeded WorkflowSeeder is authoritative.
          await logout();
          await login('finance', 'finance@ogami.test');
          await ui('/accounting/coa', 'finance-pr-approval');
          const financeApprove = await api(`/purchasing/purchase-requests/${ids.pr}/approve`, { method: 'PATCH', body: { remarks: `LIVE-B11 Finance PR approval ${stamp}` } });
          expect('L07 Finance approves auto-PR', financeApprove, 200);
          const financeReplay = await api(`/purchasing/purchase-requests/${ids.pr}/approve`, { method: 'PATCH', body: { remarks: 'duplicate finance approval' } });
          expect('L07 Finance approval replay denied', financeReplay, 422);
          let prFinal = dataOf(financeApprove.body);
          if (prFinal?.status !== 'approved') {
            await logout();
            await login('vp', 'vp@ogami.test');
            const vpApprovePr = await api(`/purchasing/purchase-requests/${ids.pr}/approve`, { method: 'PATCH', body: { remarks: `LIVE-B11 VP PR approval ${stamp}` } });
            expect('L07 VP approves threshold auto-PR', vpApprovePr, 200);
            const vpReplayPr = await api(`/purchasing/purchase-requests/${ids.pr}/approve`, { method: 'PATCH', body: { remarks: 'duplicate VP approval' } });
            expect('L07 VP approval replay denied', vpReplayPr, 422);
            prFinal = dataOf(vpApprovePr.body);
          } else {
            blocked('L07 VP PR threshold approval', `Auto-PR total ${ids.prTotal ?? 'unknown'} did not require a second seeded VP step; no high-value fixture was manufactured.`);
          }
          observed('L07 approved PR exact state', `pr=${ids.pr}; number=${prFinal?.pr_number}; status=${prFinal?.status}; total=${prFinal?.total_estimated_amount}; conversion=${prFinal?.po_conversion_status}`);

          await logout();
          await login('purchasing', 'purchasing@ogami.test');
          const sourcing = await api(`/purchasing/purchase-requests/${ids.pr}/sourcing`);
          expect('L07 source auto-PR vendor candidates', sourcing, 200);
          const sourceLines = dataOf(sourcing.body)?.lines ?? [];
          const vendorMap = {};
          for (const line of sourceLines) {
            const vendor = line.suggested_vendor?.id ?? line.suggested_vendor_id ?? line.vendor?.id ?? line.vendor_id ?? line.candidates?.[0]?.vendor_id ?? line.candidates?.[0]?.vendor?.id;
            if (line.id && vendor) vendorMap[line.id] = vendor;
          }
          observed('L07 exact sourcing map', `lines=${brief(sourceLines)}; vendor_map=${JSON.stringify(vendorMap)}`);
          if (Object.keys(vendorMap).length !== sourceLines.length || sourceLines.length === 0) {
            blocked('L07 PR to PO conversion', 'At least one auto-PR line had no approved supplier candidate/price; no unsourced PO or downstream receipt was manufactured.');
            blocked('L08 import and receipt', 'No source converted/sent PO exists.');
            blocked('L09 inventory and AP', 'No source GRN exists.');
          } else {
            const conversion = await api(`/purchasing/purchase-requests/${ids.pr}/convert`, { method: 'POST', body: { vendor_map: vendorMap, expected_delivery_date: new Date(Date.now() + 10 * 86400000).toISOString().slice(0, 10) } });
            expect('L07 converts approved auto-PR by vendor', conversion, 201);
            const poRows = rowsOf(conversion.body);
            ids.po = poRows[0]?.id;
            retain('purchase-order', ids.po, 'sent disposable P2P source PO');
            assert('L07 conversion created one PO per vendor', poRows.length >= 1, `po_rows=${brief(poRows)}`);
            const conversionReplay = await api(`/purchasing/purchase-requests/${ids.pr}/convert`, { method: 'POST', body: { vendor_map: vendorMap, expected_delivery_date: new Date(Date.now() + 10 * 86400000).toISOString().slice(0, 10) } });
            expect('L07 conversion replay is idempotent', conversionReplay, 201);
            assert('L07 conversion replay did not create another PO', rowsOf(conversionReplay.body).map((row) => row.id).join(',') === poRows.map((row) => row.id).join(','), `first=${brief(poRows)} replay=${brief(conversionReplay.body)}`);

            if (!ids.po) {
              blocked('L08 source PO', 'Conversion returned no PO hash.');
              blocked('L09 inventory and AP', 'No source PO/GRN exists.');
            } else {
              const poDetail = await api(`/purchasing/purchase-orders/${ids.po}`);
              expect('L07 converted PO detail', poDetail, 200);
              const poData = dataOf(poDetail.body);
              ids.poNumber = poData?.po_number;
              ids.poTotal = poData?.total_amount;
              ids.poLine = poData?.items?.[0]?.id;
              ids.item = poData?.items?.[0]?.item_id ?? poData?.items?.[0]?.item?.id;
              ids.vendor = poData?.vendor?.id;
              observed('L07 exact PO money/reference state', `po=${ids.po}; number=${ids.poNumber}; vendor=${ids.vendor}; total=${ids.poTotal}; requires_vp=${poData?.requires_vp_approval}; line=${ids.poLine}; item=${ids.item}; status=${poData?.status}`);
              const poSubmit = await api(`/purchasing/purchase-orders/${ids.po}/submit`, { method: 'PATCH' });
              expect('L07 PO submit', poSubmit, 200);
              const poSubmitReplay = await api(`/purchasing/purchase-orders/${ids.po}/submit`, { method: 'PATCH' });
              expect('L07 PO submit replay denied', poSubmitReplay, 422);
              await logout();
              await login('finance', 'finance@ogami.test');
              const poFinance = await api(`/purchasing/purchase-orders/${ids.po}/approve`, { method: 'PATCH', body: { remarks: `LIVE-B11 Finance PO approval ${stamp}` } });
              expect('L07 Finance approves PO', poFinance, 200);
              let poApproved = dataOf(poFinance.body);
              if (poApproved?.status !== 'approved') {
                await logout();
                await login('vp', 'vp@ogami.test');
                const poVp = await api(`/purchasing/purchase-orders/${ids.po}/approve`, { method: 'PATCH', body: { remarks: `LIVE-B11 VP PO approval ${stamp}` } });
                expect('L07 VP approves threshold PO', poVp, 200);
                const poVpReplay = await api(`/purchasing/purchase-orders/${ids.po}/approve`, { method: 'PATCH', body: { remarks: 'duplicate VP PO approval' } });
                expect('L07 VP PO approval replay denied', poVpReplay, 422);
                poApproved = dataOf(poVp.body);
              } else {
                blocked('L07 VP threshold PO approval', `PO total ${ids.poTotal ?? 'unknown'} did not require VP; no high-value fixture was manufactured.`);
              }
              await logout();
              await login('purchasing', 'purchasing@ogami.test');
              await ui('/purchasing/purchase-orders', 'purchasing-purchase-orders');
              const sent = await api(`/purchasing/purchase-orders/${ids.po}/send`, { method: 'PATCH' });
              expect('L07 Purchasing sends PO', sent, 200);
              const sendReplay = await api(`/purchasing/purchase-orders/${ids.po}/send`, { method: 'PATCH' });
              expect('L07 PO send replay denied', sendReplay, 422);
              const poPdf = await file(`/purchasing/purchase-orders/${ids.po}/pdf`);
              expect('L07 PO PDF evidence', poPdf, 200);
              observed('L07 PO sent exact state', `po=${ids.po}; number=${ids.poNumber}; status=${dataOf(sent.body)?.status}; dispatch=${brief(dataOf(sent.body)?.supplier_dispatch)}`);

              // Import shipment/customs/landed cost is optional. This PO has no
              // import obligation, so preserve the optional branch instead of
              // inventing customs data solely for coverage.
              await logout();
              await login('impex', 'impex@ogami.test');
              await ui('/supply-chain/shipments', 'impex-optional-shipment');
              observed('L08 optional import branch', `PO ${ids.poNumber} was sent without an import shipment requirement; shipment/customs/landed-cost mutation was not manufactured.`);

              // L08 — create a disposable warehouse/location, then exercise a
              // failed incoming inspection before the successful receipt.
              await logout();
              await login('warehouse', 'warehouse@ogami.test');
              await ui('/inventory/grn', 'warehouse-grn');
              const warehouse = await api('/inventory/warehouses', { method: 'POST', body: { name: `LIVE-B11 Warehouse ${stamp}`, code: `B11W${stamp}`, address: 'Disposable live audit receiving', is_active: true } });
              expect('L08 disposable receiving warehouse', warehouse, 201);
              ids.warehouse = idOf(warehouse.body); retain('warehouse', ids.warehouse, 'disposable receiving location parent');
              const zone = await api('/inventory/zones', { method: 'POST', body: { warehouse_id: ids.warehouse, name: `LIVE-B11 Raw ${stamp}`, code: `B11Z${stamp.slice(-6)}`, zone_type: 'raw_materials' } });
              expect('L08 disposable raw-material zone', zone, 201);
              ids.zone = idOf(zone.body); retain('zone', ids.zone, 'disposable receiving location parent');
              const location = await api('/inventory/locations', { method: 'POST', body: { zone_id: ids.zone, code: `B11L${stamp}`, rack: 'B11', bin: '01', is_active: true } });
              expect('L08 disposable receiving location', location, 201);
              ids.location = idOf(location.body); retain('location', ids.location, 'empty before receipt; retained for stock evidence');
              const freshPo = await api(`/purchasing/purchase-orders/${ids.po}`);
              const poLine = dataOf(freshPo.body)?.items?.find((line) => line.id === ids.poLine) ?? dataOf(freshPo.body)?.items?.[0];
              ids.poLine = poLine?.id ?? ids.poLine;
              ids.item = poLine?.item_id ?? poLine?.item?.id ?? ids.item;
              const receiveQty = String(poLine?.quantity ?? '1.000');
              const receiveCost = String(poLine?.unit_price ?? '0.01');
              observed('L08 source receipt quantities/cost', `po_line=${ids.poLine}; item=${ids.item}; quantity=${receiveQty}; unit_cost=${receiveCost}; location=${ids.location}`);

              const stockBefore = await api(`/inventory/stock-levels?item_id=${ids.item}&location_id=${ids.location}`);
              expect('L08 disposable location has no stock before receipt', stockBefore, 200);
              const failedGrn = await api('/inventory/grn', { method: 'POST', body: { purchase_order_id: ids.po, received_date: new Date().toISOString().slice(0, 10), remarks: `LIVE-B11 failed incoming QC ${stamp}`, items: [{ purchase_order_item_id: ids.poLine, item_id: ids.item, location_id: ids.location, quantity_received: receiveQty, unit_cost: receiveCost, lot_number: `B11-FAIL-${stamp}`, moisture_percentage: '0.500' }] } });
              expect('L08 creates failed-QC GRN', failedGrn, 201);
              ids.failedGrn = idOf(failedGrn.body); retain('grn', ids.failedGrn, 'rejected incoming-QC branch');
              const failedDetail = await api(`/inventory/grn/${ids.failedGrn}`);
              expect('L08 failed-QC GRN pending_qc', failedDetail, 200);
              ids.failedInspection = dataOf(failedDetail.body)?.qc_inspection?.id;
              retain('inspection', ids.failedInspection, 'failed incoming-QC branch');
              const preQcAccept = await api(`/inventory/grn/${ids.failedGrn}/accept`, { method: 'PATCH' });
              expect('L08 warehouse cannot accept before QC', preQcAccept, 422);
              await logout();
              await login('qc', 'qc@ogami.test');
              await ui('/quality/inspections', 'qc-failed-incoming');
              const failedInspection = await api(`/quality/inspections/${ids.failedInspection}`);
              expect('L08 QC failed inspection detail', failedInspection, 200);
              const failedMeasurements = dataOf(failedInspection.body)?.measurements ?? [];
              const failPatch = await api(`/quality/inspections/${ids.failedInspection}/measurements`, { method: 'PATCH', body: { measurements: failedMeasurements.map((row) => ({ id: row.id, is_pass: false, notes: 'LIVE-B11 intentional incoming QC failure' })) } });
              expect('L08 QC records failed incoming measurement', failPatch, 200);
              const failComplete = await api(`/quality/inspections/${ids.failedInspection}/complete`, { method: 'POST' });
              expect('L08 QC completes failed incoming inspection', failComplete, 200);
              await logout();
              await login('warehouse', 'warehouse@ogami.test');
              const failedStock = await api(`/inventory/stock-levels?item_id=${ids.item}&location_id=${ids.location}`);
              expect('L08 failed inspection adds no stock before GRN rejection', failedStock, 200);
              const rejectGrn = await api(`/inventory/grn/${ids.failedGrn}/reject`, { method: 'PATCH', body: { reason: 'LIVE-B11 incoming inspection failed; reject before stock acceptance' } });
              expect('L08 warehouse rejects failed-QC GRN', rejectGrn, 200);
              const rejectReplay = await api(`/inventory/grn/${ids.failedGrn}/reject`, { method: 'PATCH', body: { reason: 'duplicate rejection' } });
              expect('L08 rejected GRN replay denied', rejectReplay, 422);
              const rejectedStock = await api(`/inventory/stock-levels?item_id=${ids.item}&location_id=${ids.location}`);
              expect('L08 rejected inspection leaves no stock', rejectedStock, 200);
              const failedBills = await findBilledForGrn(ids.failedGrn);
              expect('L08 rejected GRN creates no bill', failedBills.bills, 200);
              assert('L08 failed GRN absent from bill list', !failedBills.match, `failed_grn=${ids.failedGrn}; match=${brief(failedBills.match)}`);

              // Repeat the exact source receipt after the rejected GRN reversed
              // PO quantity_received. This is the successful pass branch.
              const passedGrn = await api('/inventory/grn', { method: 'POST', body: { purchase_order_id: ids.po, received_date: new Date().toISOString().slice(0, 10), remarks: `LIVE-B11 passed incoming QC ${stamp}`, items: [{ purchase_order_item_id: ids.poLine, item_id: ids.item, location_id: ids.location, quantity_received: receiveQty, unit_cost: receiveCost, lot_number: `B11-PASS-${stamp}`, moisture_percentage: '0.500' }] } });
              expect('L08 creates passed-QC GRN', passedGrn, 201);
              ids.grn = idOf(passedGrn.body); retain('grn', ids.grn, 'accepted disposable receipt source');
              const passedDetail = await api(`/inventory/grn/${ids.grn}`);
              expect('L08 passed GRN pending_qc detail', passedDetail, 200);
              ids.inspection = dataOf(passedDetail.body)?.qc_inspection?.id;
              retain('inspection', ids.inspection, 'passed incoming-QC inspection');
              const noAcceptYet = await api(`/inventory/grn/${ids.grn}/accept`, { method: 'PATCH' });
              expect('L08 second warehouse accept blocked before QC', noAcceptYet, 422);
              await logout();
              await login('qc', 'qc@ogami.test');
              const passedInspection = await api(`/quality/inspections/${ids.inspection}`);
              expect('L08 QC passed inspection detail', passedInspection, 200);
              const passedMeasurements = dataOf(passedInspection.body)?.measurements ?? [];
              const passPatch = await api(`/quality/inspections/${ids.inspection}/measurements`, { method: 'PATCH', body: { measurements: passedMeasurements.map((row) => ({ id: row.id, is_pass: true, notes: 'LIVE-B11 incoming QC pass' })) } });
              expect('L08 QC records passed incoming measurement', passPatch, 200);
              const passComplete = await api(`/quality/inspections/${ids.inspection}/complete`, { method: 'POST' });
              expect('L08 QC completes passed incoming inspection', passComplete, 200);
              const completeReplay = await api(`/quality/inspections/${ids.inspection}/complete`, { method: 'POST' });
              expect('L08 passed inspection completion replay denied', completeReplay, 422);
              await logout();
              await login('warehouse', 'warehouse@ogami.test');
              const accepted = await api(`/inventory/grn/${ids.grn}/accept`, { method: 'PATCH' });
              expect('L09 warehouse accepts passed GRN', accepted, 200);
              const acceptReplay = await api(`/inventory/grn/${ids.grn}/accept`, { method: 'PATCH' });
              expect('L09 accepted GRN replay denied', acceptReplay, 422);
              const stockAfter = await api(`/inventory/stock-levels?item_id=${ids.item}&location_id=${ids.location}`);
              expect('L09 accepted GRN stock movement/WAC', stockAfter, 200);
              observed('L09 exact stock after accepted GRN', `item=${ids.item}; location=${ids.location}; quantity=${brief(stockAfter.body)}; movement_source_grn=${ids.grn}`);
              const movements = await api(`/inventory/stock-movements?item_id=${ids.item}&per_page=100`);
              expect('L09 accepted GRN movement references', movements, 200);
              const grnDetailAfter = await api(`/inventory/grn/${ids.grn}`);
              expect('L09 accepted GRN exact detail', grnDetailAfter, 200);
              observed('L09 GRN exact state', `grn=${ids.grn}; number=${dataOf(grnDetailAfter.body)?.grn_number}; status=${dataOf(grnDetailAfter.body)?.status}; accepted_qty=${brief(dataOf(grnDetailAfter.body)?.items)}`);

              // Queue-created supplier bill must be found by GRN source before AP
              // can continue. No manually-created bill is allowed for this pack.
              await logout();
              await login('finance', 'finance@ogami.test');
              await ui('/accounting/bills', 'finance-ap-bills');
              const billPoll = await poll('/bills?per_page=100', (response) => response.status === 200 && rowsOf(response.body).some((bill) => bill.goods_receipt_note_id === ids.grn || bill.goods_receipt_notes?.some((row) => row.id === ids.grn)), 90000);
              expect('L09 queue-created draft bill for accepted GRN', billPoll, 200);
              const bill = rowsOf(billPoll.body).find((row) => row.goods_receipt_note_id === ids.grn || row.goods_receipt_notes?.some((row) => row.id === ids.grn));
              ids.bill = bill?.id;
              retain('bill', ids.bill, 'auto-created draft AP bill');
              if (!ids.bill) {
                blocked('L09 source bill and AP GL', `Accepted GRN ${ids.grn} did not produce a draft bill before queue timeout; no manual bill was created.`);
              } else {
                const billDetail = await api(`/bills/${ids.bill}`);
                expect('L09 draft bill exact source', billDetail, 200);
                const billData = dataOf(billDetail.body);
                ids.billNumber = billData?.bill_number;
                ids.billTotal = billData?.total_amount;
                observed('L09 draft bill exact money/reference', `bill=${ids.bill}; number=${ids.billNumber}; total=${ids.billTotal}; balance=${billData?.balance}; status=${billData?.status}; po=${billData?.purchase_order?.id}; grn=${billData?.goods_receipt_notes?.[0]?.id}`);
                const match = await api(`/purchasing/three-way-match/${ids.bill}`);
                expect('L09 exact three-way match', match, 200);
                observed('L09 three-way match snapshot', brief(match.body));
                const post = await api(`/bills/${ids.bill}/post`, { method: 'POST', body: {} });
                expect('L09 Finance posts matched bill', post, 200);
                const postReplay = await api(`/bills/${ids.bill}/post`, { method: 'POST', body: {} });
                expect('L09 bill post replay denied', postReplay, 422);
                const postedData = dataOf(post.body);
                ids.billJournal = postedData?.journal_entry?.id;
                observed('L09 posted AP GL reference', `bill=${ids.bill}; status=${postedData?.status}; journal=${ids.billJournal}; entry=${postedData?.journal_entry?.entry_number}; total=${postedData?.total_amount}`);

                const accounts = await api('/accounts?per_page=100');
                expect('L09 cash account options', accounts, 200);
                const cash = rowsOf(accounts.body).find((row) => /cash|bank/i.test(`${row.name} ${row.code}`) && row.is_active !== false) ?? rowsOf(accounts.body).find((row) => row.is_active !== false);
                ids.cashAccount = cash?.id;
                observed('L09 payment cash account source', `cash_account=${ids.cashAccount}; account=${brief(cash)}`);
                if (!ids.cashAccount) {
                  blocked('L09 AP payment', 'No active cash/bank account option was available; no payment was manufactured.');
                } else {
                  const half = money(cents(ids.billTotal) % 2 === 0 ? cents(ids.billTotal) / 200 : Math.floor(cents(ids.billTotal) / 2) / 100);
                  const firstAmount = half === '0.00' ? ids.billTotal : half;
                  const paymentBody = { cash_account_id: ids.cashAccount, payment_date: new Date().toISOString().slice(0, 10), amount: firstAmount, payment_method: 'bank_transfer', reference_number: `LIVE-B11-P1-${stamp}` };
                  const missingKey = await api(`/bills/${ids.bill}/payments`, { method: 'POST', body: paymentBody });
                  expect('L09 payment requires idempotency key', missingKey, 422);
                  const key = `live-b11-bill-${stamp}`;
                  const payment = await api(`/bills/${ids.bill}/payments`, { method: 'POST', headers: { 'Idempotency-Key': key }, body: paymentBody });
                  expect('L09 partial AP payment', payment, 201);
                  ids.payment1 = idOf(payment.body); retain('bill-payment', ids.payment1, 'first partial payment');
                  const paymentReplay = await api(`/bills/${ids.bill}/payments`, { method: 'POST', headers: { 'Idempotency-Key': key }, body: paymentBody });
                  expect('L09 partial payment exact replay idempotent', paymentReplay, 201);
                  assert('L09 payment replay returns same payment', idOf(paymentReplay.body) === ids.payment1, `first=${ids.payment1}; replay=${idOf(paymentReplay.body)}`);
                  const afterPartial = await api(`/bills/${ids.bill}`);
                  expect('L09 AP partial aggregate exact state', afterPartial, 200);
                  observed('L09 partial payment money/GL', `bill=${ids.bill}; paid=${dataOf(afterPartial.body)?.amount_paid}; balance=${dataOf(afterPartial.body)?.balance}; status=${dataOf(afterPartial.body)?.status}; payment=${ids.payment1}; payment_je=${dataOf(payment.body)?.journal_entry?.id}`);
                  const remaining = dataOf(afterPartial.body)?.balance;
                  const fullPayment = await api(`/bills/${ids.bill}/payments`, { method: 'POST', headers: { 'Idempotency-Key': `${key}-final` }, body: { ...paymentBody, amount: remaining, reference_number: `LIVE-B11-P2-${stamp}` } });
                  expect('L09 final AP payment', fullPayment, 201);
                  ids.payment2 = idOf(fullPayment.body); retain('bill-payment', ids.payment2, 'final payment');
                  const finalBill = await api(`/bills/${ids.bill}`);
                  expect('L09 bill fully paid exact terminal state', finalBill, 200);
                  const finalData = dataOf(finalBill.body);
                  assert('L09 bill is paid with zero balance', finalData?.status === 'paid' && finalData?.balance === '0.00', `bill=${brief(finalData)}`);
                  observed('L09 final AP/GL references', `bill=${ids.bill}; number=${ids.billNumber}; paid=${finalData?.amount_paid}; balance=${finalData?.balance}; status=${finalData?.status}; source_je=${finalData?.journal_entry?.id}; payment1=${ids.payment1}; payment2=${ids.payment2}; payment1_je=${dataOf(payment.body)?.journal_entry?.id}; payment2_je=${dataOf(fullPayment.body)?.journal_entry?.id}`);
                  await ui(`/accounting/bills/${ids.bill}`, 'finance-paid-bill');
                }
              }
            }
          }
        }
      }
      }
    }
  }
} catch (error) {
  record('FAIL', 'L06-L09 harness', error instanceof Error ? error.stack ?? error.message : String(error));
} finally {
  await logout().catch(() => {});
  await fs.writeFile(`${artifactDir}/l06-l09-results.json`, JSON.stringify({ stamp, ids, results, evidence, residuals, counts: results.reduce((counts, row) => ({ ...counts, [row.status]: (counts[row.status] ?? 0) + 1 }), {}) }, null, 2));
  await browser.close();
}
console.log(JSON.stringify({ stamp, ids, residuals, counts: results.reduce((counts, row) => ({ ...counts, [row.status]: (counts[row.status] ?? 0) + 1 }), {}) }, null, 2));
