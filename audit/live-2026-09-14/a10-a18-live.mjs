import { firefox } from '../../spa/node_modules/playwright/index.mjs';
import fs from 'node:fs/promises';

const base = 'http://localhost';
const artifact = '/home/kwat0g/Desktop/kwatog/audit/live-2026-09-14/artifacts';
const browser = await firefox.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await context.newPage();
const results = [];
const ids = {};
let actor = 'signed-out';
let guard = 'none';
let lastLogin = 0;

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const bodyData = (body) => body?.data ?? body;
const rows = (body) => {
  const value = bodyData(body);
  if (Array.isArray(value)) return value;
  if (Array.isArray(value?.data)) return value.data;
  return [];
};
const idOf = (body) => bodyData(body)?.id ?? bodyData(body)?.hash_id ?? null;
const short = (value) => JSON.stringify(value ?? '').slice(0, 1400);

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
      ...(options.body === undefined ? {} : { 'Content-Type': 'application/json' }),
      ...(options.headers ?? {}),
    };
    const requestPath = path.startsWith('/api/') || path.startsWith('/sanctum/') ? path : `/api/v1${path}`;
    const response = await fetch(requestPath, {
      method: options.method ?? 'GET', credentials: 'include', headers,
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
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
  expect(`${alias} authenticated`, await api('/auth/user'), 200);
}

async function loginPortal(type, alias, email) {
  await logout();
  const wait = Math.max(0, 13000 - (Date.now() - lastLogin));
  if (wait) await sleep(wait);
  const prefix = type === 'supplier' ? '/portal/supplier' : '/portal/customer';
  await page.goto(`${base}${prefix}/login`, { waitUntil: 'domcontentloaded' });
  expect(`${alias} portal csrf`, await api('/sanctum/csrf-cookie'), [200, 204]);
  const login = await api(`/b2b/${type}/login`, { method: 'POST', body: { email, password: 'password' } });
  expect(`${alias} portal login`, login, 200);
  if (login.status !== 200) throw new Error(`${alias} portal login did not establish a session`);
  await page.goto(`${base}${prefix}`, { waitUntil: 'domcontentloaded' });
  await page.waitForURL((url) => url.pathname === prefix || (url.pathname.startsWith(`${prefix}/`) && !url.pathname.endsWith('/login')), { timeout: 25000 });
  actor = alias; guard = type; lastLogin = Date.now();
  expect(`${alias} portal identity`, await api(`/b2b/${type}/me`), 200);
}

async function ui(path, name) {
  const response = await page.goto(`${base}${path}`, { waitUntil: 'domcontentloaded' });
  await page.locator('body').waitFor({ state: 'visible', timeout: 20000 });
  await page.waitForFunction(() => document.body.innerText.trim().length > 20, null, { timeout: 25000 }).catch(() => {});
  const text = await page.locator('body').innerText();
  await page.screenshot({ path: `${artifact}/a10-a18-${name}.png`, fullPage: true }).catch(() => {});
  const bad = /application error|internal server error|something went wrong/i.test(text);
  record(bad ? 'FAIL' : 'PASS', `${name} UI`, `document=${response?.status() ?? 'none'} uiUrl=${page.url()}`);
  return text;
}

async function read(path, name, statuses = 200) {
  const response = await api(path);
  expect(name, response, statuses);
  return response;
}

async function poll(path, name, wanted, attempts = 12) {
  let response;
  for (let i = 0; i < attempts; i += 1) {
    response = await api(path);
    const value = bodyData(response.body);
    if (response.status === 200 && wanted.includes(value?.status)) {
      record('PASS', name, `status=${value.status}; body=${short(response.body)}`);
      return response;
    }
    await sleep(1500);
  }
  record(response?.status === 200 ? 'BLOCKED' : 'FAIL', name, `wanted=${wanted.join('/')} final=${short(response?.body)}`);
  return response;
}

function firstAccount(accountRows) {
  return accountRows.find((row) => /expense/i.test(String(row.type ?? row.account_type ?? '')) && !row.has_children && row.is_active !== false)
    ?? accountRows.find((row) => row.is_active !== false && !row.parent_id);
}

try {
  // A10 — HR creates/computes; Finance checks/finalizes/disburses.
  await loginInternal('hr', 'hr@ogami.test');
  await ui('/payroll/periods', 'a10-hr-payroll');
  const payrollOptions = await read('/payroll-periods/options', 'A10 payroll options');
  const finDept = bodyData(payrollOptions.body)?.departments?.find((row) => /FIN/i.test(`${row.label} ${row.value}`));
  const payrollPayload = {
    period_start: '2026-10-01', period_end: '2026-10-15', payroll_date: '2026-10-15',
    is_first_half: false, scope_department_ids: finDept ? [finDept.value] : undefined,
    scope_label: `LIVE-B9 disposable payroll ${Date.now()}`,
  };
  const preview = await api('/payroll-periods/scope-preview', { method: 'POST', body: payrollPayload });
  expect('A10 HR payroll scope preview', preview, [200, 422]);
  const periodCreate = await api('/payroll-periods', { method: 'POST', body: payrollPayload });
  expect('A10 disposable payroll period create', periodCreate, [201, 422]);
  ids.payrollPeriod = idOf(periodCreate.body);
  if (!ids.payrollPeriod) {
    blocked('A10 payroll lifecycle', `No disposable period was created; preserved seeded payroll remained untouched. response=${short(periodCreate.body)}`);
  } else {
    await read(`/payroll-periods/${ids.payrollPeriod}`, 'A10 payroll draft detail');
    const compute = await api(`/payroll-periods/${ids.payrollPeriod}/compute`, { method: 'POST' });
    expect('A10 HR compute claim', compute, 202);
    await poll(`/payroll-periods/${ids.payrollPeriod}`, 'A10 compute completion', ['computed', 'completed', 'processing'], 16);
    await read(`/payroll-periods/${ids.payrollPeriod}/anomalies`, 'A10 anomaly review');
    expect('A10 HR cannot approve payroll checker action', await api(`/payroll-periods/${ids.payrollPeriod}/approve`, { method: 'PATCH' }), 403);
    const correction = await api(`/payroll-periods/${ids.payrollPeriod}/request-correction`, { method: 'PATCH', body: { reason: 'LIVE-B9 correction/recompute replay check' } });
    expect('A10 correction request or anomaly gate', correction, [200, 422]);
    if (correction.status === 200) {
      const recompute = await api(`/payroll-periods/${ids.payrollPeriod}/compute`, { method: 'POST' });
      expect('A10 recompute after correction', recompute, 202);
      await poll(`/payroll-periods/${ids.payrollPeriod}`, 'A10 recompute completion', ['computed', 'completed', 'processing'], 16);
    }
    await loginInternal('finance', 'finance@ogami.test');
    const board = await read('/approvals/board?type=payroll&pending_limit=100&history_limit=100', 'A10 payroll approval board');
    record(rows(board.body).some((row) => row.id === ids.payrollPeriod || row.target_id === ids.payrollPeriod) ? 'PASS' : 'OBSERVED', 'A10 approval board payroll link', `target=${ids.payrollPeriod}; rows=${rows(board.body).length}`);
    const approve = await api(`/payroll-periods/${ids.payrollPeriod}/approve`, { method: 'PATCH' });
    expect('A10 Finance approve payroll', approve, [200, 422]);
    if (approve.status === 200) {
      const finalize = await api(`/payroll-periods/${ids.payrollPeriod}/finalize`, { method: 'PATCH' });
      expect('A10 Finance finalize payroll', finalize, [200, 422]);
      if (finalize.status === 200) {
        const detail = await read(`/payroll-periods/${ids.payrollPeriod}`, 'A10 finalized payroll exact state');
        const amount = bodyData(detail.body)?.total_net_pay ?? bodyData(detail.body)?.net_pay ?? null;
        observed('A10 finalized payroll financial references', `period=${ids.payrollPeriod}; total_net_pay=${amount}; detail=${short(detail.body)}`);
        await expect('A10 bank preview', await api(`/payroll-periods/${ids.payrollPeriod}/bank-file/preview?format=generic`), 200);
        const bank = await api(`/payroll-periods/${ids.payrollPeriod}/bank-file`, { method: 'POST', body: { format: 'generic' } });
        expect('A10 bank artifact generation', bank, [201, 422]);
        await read(`/payroll-periods/${ids.payrollPeriod}/bank-file`, 'A10 bank artifact download', [200, 404, 422]);
        const payrollRows = await read(`/payrolls?period_id=${ids.payrollPeriod}&per_page=100`, 'A10 payroll rows and payslips');
        const payroll = rows(payrollRows.body)[0];
        if (payroll?.id) await read(`/payrolls/${payroll.id}/payslip`, 'A10 payslip document', [200, 404]);
        const disburse = await api(`/payroll-periods/${ids.payrollPeriod}/mark-disbursed`, { method: 'PATCH' });
        expect('A10 disbursement proof state', disburse, [200, 422]);
        expect('A10 terminal disbursement replay', await api(`/payroll-periods/${ids.payrollPeriod}/mark-disbursed`, { method: 'PATCH' }), [200, 422]);
        expect('A10 finalized payroll replay', await api(`/payroll-periods/${ids.payrollPeriod}/finalize`, { method: 'PATCH' }), 422);
      } else {
        blocked('A10 downstream GL/bank/payslip/disbursement', `Finalize was blocked by live payroll state: ${short(finalize.body)}`);
      }
    } else {
      blocked('A10 Finance checker/finalizer', `Approval did not reach Finance lifecycle: ${short(approve.body)}`);
    }
    const notifications = await read('/notifications?per_page=100', 'A10 Finance notifications');
    observed('A10 notification recipient evidence', `rows=${rows(notifications.body).length}; target=${ids.payrollPeriod}; body=${short(notifications.body)}`);
  }

  // A11 — Finance maker, self-approval denial, VP approval, close and reporting.
  await loginInternal('finance', 'finance@ogami.test');
  await ui('/budgeting', 'a11-finance-budgeting');
  const budgetOptions = await read('/budgets/options', 'A11 budget options');
  const years = await read('/budgets/fiscal-years', 'A11 fiscal years');
  const accounts = await read('/accounts?per_page=100', 'A11 budget account options');
  const fy = rows(years.body)[0];
  const account = firstAccount(rows(accounts.body));
  if (!fy?.id || !account?.id) {
    blocked('A11 budget lifecycle', `Missing fiscal-year or eligible expense account fixture; fy=${short(fy)} account=${short(account)}`);
  } else {
    const budget = await api('/budgets', { method: 'POST', body: {
      fiscal_year_id: fy.id, budget_type: 'operating', name: `LIVE-B9 disposable budget ${Date.now()}`,
      line_items: [{ account_id: account.id, jan: '1000.00', feb: '0.00', mar: '0.00' }],
    } });
    expect('A11 disposable budget create', budget, 201);
    ids.budget = idOf(budget.body);
    if (ids.budget) {
      await expect('A11 budget draft update', await api(`/budgets/${ids.budget}`, { method: 'PUT', body: { name: 'LIVE-B9 edited disposable budget' } }), 200);
      await expect('A11 budget submit', await api(`/budgets/${ids.budget}/submit`, { method: 'POST' }), 200);
      expect('A11 budget maker self-approval denial', await api(`/budgets/${ids.budget}/approve`, { method: 'POST' }), 422);
      await loginInternal('vp', 'vp@ogami.test');
      await ui('/budgeting', 'a11-vp-budget-review');
      const approveBudget = await api(`/budgets/${ids.budget}/approve`, { method: 'POST' });
      expect('A11 VP budget approval', approveBudget, [200, 422]);
      if (approveBudget.status === 200) {
        expect('A11 active budget approval replay', await api(`/budgets/${ids.budget}/approve`, { method: 'POST' }), 422);
        await loginInternal('finance', 'finance@ogami.test');
        expect('A11 budget close', await api(`/budgets/${ids.budget}/close`, { method: 'POST' }), 200);
        expect('A11 closed budget replay', await api(`/budgets/${ids.budget}/close`, { method: 'POST' }), 422);
        await read(`/budgets/${ids.budget}`, 'A11 closed budget exact money');
      }
    }
    const availability = await api('/budgets/check-availability', { method: 'GET' });
    expect('A11 budget availability route validation', availability, [200, 422]);
    await expect('A11 budget actuals sync', await api(`/budgets/sync-actuals`, { method: 'POST', body: { fiscal_year_id: fy.id } }), [202, 422]);
    await read(`/budgets/budget-vs-actual?fiscal_year_id=${fy.id}`, 'A11 budget versus actual');
  }

  // A12 — supplier-owned listing, Purchasing review, rejection, bulk replay.
  await loginPortal('supplier', 'supplier-portal', 'portal@supp.test');
  await ui('/portal/supplier/item-listings', 'a12-supplier-listings');
  const catalog = await read('/b2b/supplier/item-catalog?per_page=100', 'A12 supplier item catalog');
  const catalogRows = rows(catalog.body);
  if (!catalogRows[0]?.id) {
    blocked('A12 supplier listing lifecycle', 'Supplier catalog was empty; no listing was invented.');
  } else {
    const makeListing = (item, label) => api('/b2b/supplier/item-listings', { method: 'POST', body: {
      item_id: item.id, supplier_item_code: `B9-${Date.now()}-${label}`, supplier_item_name: `LIVE-B9 ${label} offer`,
      price: label === 'approve' ? '12.50' : '13.75', order_uom: 'kg', base_qty_per_order_unit: '1.000', lead_time_days: 14,
      valid_until: '2027-12-31',
    } });
    const listing = await makeListing(catalogRows[0], 'approve');
    expect('A12 supplier listing create', listing, 201);
    ids.listing = idOf(listing.body);
    const listingReject = await makeListing(catalogRows[1] ?? catalogRows[0], 'reject');
    expect('A12 second supplier listing create', listingReject, [201, 422]);
    ids.listingReject = idOf(listingReject.body);
    await loginInternal('purchasing', 'purchasing@ogami.test');
    await ui('/purchasing/supplier-listings', 'a12-purchasing-review');
    const review = await read('/purchasing/supplier-listings?per_page=100', 'A12 purchasing listing review queue');
    const reviewRows = rows(review.body);
    const pending = reviewRows.find((row) => row.id === ids.listing) ?? reviewRows.find((row) => row.status === 'pending');
    if (pending?.id) {
      await expect('A12 internal listing approve', await api(`/purchasing/supplier-listings/${pending.id}/approve`, { method: 'PATCH', body: {} }), 200);
      expect('A12 approved listing replay', await api(`/purchasing/supplier-listings/${pending.id}/approve`, { method: 'PATCH', body: {} }), 422);
      await expect('A12 listing bulk replay outcome', await api('/purchasing/supplier-listings/bulk-approve', { method: 'PATCH', body: { ids: [pending.id] } }), 200);
      const approved = await read('/purchasing/approved-suppliers?per_page=100', 'A12 approved supplier sourcing effect');
      observed('A12 approved listing sourcing evidence', `listing=${pending.id}; approved_matches=${rows(approved.body).filter((row) => row.supplier_item_listing_id === pending.id || row.listing_id === pending.id).length}`);
    } else blocked('A12 approve listing', `Review queue did not expose disposable listing ${ids.listing}; rows=${reviewRows.length}`);
    const rejectTarget = reviewRows.find((row) => row.id === ids.listingReject && row.status === 'pending');
    if (rejectTarget?.id) {
      await expect('A12 internal listing reject', await api(`/purchasing/supplier-listings/${rejectTarget.id}/reject`, { method: 'PATCH', body: { reason: 'LIVE-B9 disposable supplier review rejection' } }), 200);
      expect('A12 rejected listing replay', await api(`/purchasing/supplier-listings/${rejectTarget.id}/reject`, { method: 'PATCH', body: { reason: 'replay' } }), 422);
    } else blocked('A12 reject listing', 'No independent pending disposable listing was available after the approval path.');
    await loginInternal('finance', 'finance@ogami.test');
    if (ids.listing) expect('A12 Finance cannot review supplier listing', await api(`/purchasing/supplier-listings/${ids.listing}/approve`, { method: 'PATCH', body: {} }), 403);
  }

  // A13 — only touch a sent PO when it is explicitly disposable; otherwise preserve seeded procurement.
  await loginPortal('supplier', 'supplier-portal', 'portal@supp.test');
  await ui('/portal/supplier/purchase-orders', 'a13-supplier-po');
  const supplierPos = await read('/b2b/supplier/purchase-orders?per_page=100', 'A13 supplier-owned PO list');
  const disposablePo = rows(supplierPos.body).find((po) => /LIVE|B9|B7/i.test(String(po.po_number ?? po.number ?? po.code ?? '')) && /sent|approved|acknowledged/i.test(String(po.status ?? '')));
  if (!disposablePo?.id) {
    blocked('A13 supplier PO response lifecycle', `No explicitly disposable sent/approved PO was present; supplier rows=${rows(supplierPos.body).length}. Seeded POs were not mutated.`);
  } else {
    ids.supplierPo = disposablePo.id;
    const poDetail = await read(`/b2b/supplier/purchase-orders/${ids.supplierPo}`, 'A13 supplier PO detail');
    const poLine = bodyData(poDetail.body)?.items?.[0] ?? bodyData(poDetail.body)?.lines?.[0];
    expect('A13 supplier PO acknowledge', await api(`/b2b/supplier/purchase-orders/${ids.supplierPo}/acknowledge`, { method: 'POST', body: { notes: 'LIVE-B9 supplier acknowledgement' } }), [200, 422]);
    const response = await api(`/b2b/supplier/purchase-orders/${ids.supplierPo}/respond`, { method: 'POST', body: { type: 'accept', notes: 'LIVE-B9 supplier accepts disposable PO' } });
    expect('A13 supplier PO accept response', response, [201, 422]);
    if (response.status === 201) {
      ids.poResponse = idOf(response.body);
      expect('A13 supplier PO response replay', await api(`/b2b/supplier/purchase-orders/${ids.supplierPo}/respond`, { method: 'POST', body: { type: 'accept', notes: 'replay' } }), [409, 422]);
      await loginInternal('purchasing', 'purchasing@ogami.test');
      const responses = await read(`/purchasing/purchase-orders/${ids.supplierPo}/responses`, 'A13 internal PO response queue');
      const target = rows(responses.body).find((row) => row.id === ids.poResponse) ?? rows(responses.body)[0];
      if (target?.id) {
        await expect('A13 purchasing accepts supplier response', await api(`/purchasing/purchase-order-responses/${target.id}/accept`, { method: 'PATCH', body: {} }), 200);
        expect('A13 accepted response replay', await api(`/purchasing/purchase-order-responses/${target.id}/accept`, { method: 'PATCH', body: {} }), 422);
      } else blocked('A13 internal supplier response action', `No response row returned for ${ids.poResponse}.`);
    }
    if (!poLine) observed('A13 PO line response shape', `PO detail did not expose a line id; detail=${short(poDetail.body)}`);
  }

  // A14 — customer creates a disposable order; CRM releases it for customer confirmation.
  await loginPortal('customer', 'customer-portal', 'portal@cust.test');
  await ui('/portal/customer/orders', 'a14-customer-orders');
  const customerCatalog = await read('/b2b/customer/catalog', 'A14 customer catalog');
  const product = rows(customerCatalog.body)[0];
  if (!product?.id) {
    blocked('A14 customer SO response lifecycle', 'Customer catalog was empty; no order was invented.');
  } else {
    const order = await api('/b2b/customer/orders', { method: 'POST', body: { date: '2026-09-14', notes: 'LIVE-B9 disposable customer response order', items: [{ product_id: product.id, quantity: '1.000', delivery_date: '2026-11-15' }] } });
    expect('A14 disposable customer SO create', order, 201);
    ids.customerSo = idOf(order.body);
    if (ids.customerSo) {
      await loginInternal('crm', 'crm@ogami.test');
      await ui(`/crm/sales-orders/${ids.customerSo}`, 'a14-crm-so');
      const confirm = await api(`/crm/sales-orders/${ids.customerSo}/confirm`, { method: 'POST', body: {} });
      expect('A14 CRM SO confirm for customer response', confirm, [200, 422]);
      const requestConfirmation = await api(`/crm/sales-orders/${ids.customerSo}/request-customer-confirmation`, { method: 'POST', body: {} });
      expect('A14 CRM request customer confirmation', requestConfirmation, [200, 422]);
      if (requestConfirmation.status === 200) {
        await loginPortal('customer', 'customer-portal', 'portal@cust.test');
        const soDetail = await read(`/b2b/customer/orders/${ids.customerSo}`, 'A14 customer SO detail');
        const response = await api(`/b2b/customer/orders/${ids.customerSo}/respond`, { method: 'POST', body: { type: 'accept', notes: 'LIVE-B9 customer accepts disposable SO' } });
        expect('A14 customer accepts SO', response, [201, 422]);
        if (response.status === 201) {
          ids.soResponse = idOf(response.body);
          expect('A14 customer response replay', await api(`/b2b/customer/orders/${ids.customerSo}/respond`, { method: 'POST', body: { type: 'accept', notes: 'replay' } }), [409, 422]);
          await loginInternal('crm', 'crm@ogami.test');
          const soResponses = await read(`/crm/sales-orders/${ids.customerSo}/responses`, 'A14 CRM SO response queue');
          const target = rows(soResponses.body).find((row) => row.id === ids.soResponse) ?? rows(soResponses.body)[0];
          if (target?.id) {
            await expect('A14 CRM accepts customer SO response', await api(`/crm/sales-order-responses/${target.id}/accept`, { method: 'PATCH', body: {} }), 200);
            expect('A14 CRM response replay', await api(`/crm/sales-order-responses/${target.id}/accept`, { method: 'PATCH', body: {} }), 422);
          }
        }
        observed('A14 customer SO state/effect', `so=${ids.customerSo}; detail=${short(soDetail.body)}`);
      } else blocked('A14 customer response negotiation', `The disposable SO was not released by current CRM lifecycle: ${short(requestConfirmation.body)}`);
      await loginInternal('purchasing', 'purchasing@ogami.test');
      if (ids.soResponse) expect('A14 Purchasing cannot accept customer SO response', await api(`/crm/sales-order-responses/${ids.soResponse}/accept`, { method: 'PATCH', body: {} }), 403);
    }
  }

  // A15 — portal-created schedules, internal acknowledge/reject, stale and wrong-role checks.
  await loginPortal('customer', 'customer-portal', 'portal@cust.test');
  await ui('/portal/customer/delivery-schedules', 'a15-customer-schedules');
  const schedule = await api('/b2b/customer/delivery-schedules', { method: 'POST', body: { month: '2026-11', lines: [{ product_name: `LIVE-B9 schedule ${Date.now()}`, quantity: '2.000', notes: 'acknowledge fixture' }] } });
  expect('A15 customer schedule create', schedule, 201);
  ids.schedule = idOf(schedule.body);
  const scheduleReject = await api('/b2b/customer/delivery-schedules', { method: 'POST', body: { month: '2026-12', lines: [{ product_name: `LIVE-B9 reject schedule ${Date.now()}`, quantity: '3.000', notes: 'reject fixture' }] } });
  expect('A15 second customer schedule create', scheduleReject, [201, 422]);
  ids.scheduleReject = idOf(scheduleReject.body);
  await loginInternal('admin', 'admin@ogami.test');
  await ui('/accounting/portal-access', 'a15-internal-schedule-review');
  const schedules = await read('/b2b/portal-access/delivery-schedules?per_page=100', 'A15 internal schedule queue');
  const ackTarget = rows(schedules.body).find((row) => row.id === ids.schedule && row.status === 'pending') ?? rows(schedules.body).find((row) => row.status === 'pending');
  if (ackTarget?.id) {
    await expect('A15 internal schedule acknowledge', await api(`/b2b/portal-access/delivery-schedules/${ackTarget.id}/acknowledge`, { method: 'POST', body: {} }), 200);
    expect('A15 schedule acknowledgement replay', await api(`/b2b/portal-access/delivery-schedules/${ackTarget.id}/acknowledge`, { method: 'POST', body: {} }), 422);
    await loginInternal('finance', 'finance@ogami.test');
    expect('A15 Finance cannot review delivery schedule', await api(`/b2b/portal-access/delivery-schedules/${ackTarget.id}/acknowledge`, { method: 'POST', body: {} }), 403);
  } else blocked('A15 internal schedule acknowledge', 'No pending disposable schedule was visible to the portal-access manager.');
  await loginInternal('admin', 'admin@ogami.test');
  const schedulesAfter = await read('/b2b/portal-access/delivery-schedules?per_page=100', 'A15 schedule rejection queue');
  const rejectTarget = rows(schedulesAfter.body).find((row) => row.id === ids.scheduleReject && row.status === 'pending');
  if (rejectTarget?.id) {
    await expect('A15 internal schedule reject', await api(`/b2b/portal-access/delivery-schedules/${rejectTarget.id}/reject`, { method: 'POST', body: { reason: 'LIVE-B9 schedule rejection fixture' } }), 200);
    expect('A15 rejected schedule replay', await api(`/b2b/portal-access/delivery-schedules/${rejectTarget.id}/reject`, { method: 'POST', body: { reason: 'replay' } }), 422);
  } else blocked('A15 internal schedule reject', 'No independent pending disposable schedule was visible after acknowledgement.');
  const adminNotifications = await read('/notifications?per_page=100', 'A15 internal notifications');
  observed('A15 schedule notification evidence', `rows=${rows(adminNotifications.body).length}; schedule=${ids.schedule}; rejected=${ids.scheduleReject}`);

  // A16 — high-value adjustment checker plus a disposable warehouse-scoped count.
  await loginInternal('warehouse', 'warehouse@ogami.test');
  await ui('/inventory/stock-adjustments', 'a16-stock-adjustments');
  await ui('/inventory/stock-counts', 'a16-stock-counts');
  const adjustmentOptions = await read('/inventory/stock-adjustments/options', 'A16 adjustment options');
  const movementOptions = await read('/inventory/stock-movements/options', 'A16 movement options');
  const stockLevels = await read('/inventory/stock-levels?per_page=100', 'A16 stock levels before adjustment');
  const stockRow = rows(stockLevels.body)[0] ?? rows(movementOptions.body)[0];
  const itemId = stockRow?.item?.id ?? stockRow?.item_id;
  const locationId = stockRow?.location?.id ?? stockRow?.location_id;
  const reasonCode = bodyData(adjustmentOptions.body)?.reasons?.[0]?.value ?? 'other';
  if (!itemId || !locationId) {
    blocked('A16 high-value stock adjustment', `No item/location hash pair was exposed by stock options; row=${short(stockRow)}`);
  } else {
    const adjustment = await api('/inventory/stock-adjustments', { method: 'POST', body: {
      item_id: itemId, location_id: locationId, direction: 'in', quantity: '100.000', unit_cost: '1000.00',
      reason: 'LIVE-B9 disposable high-value checker adjustment', reason_code: reasonCode,
    } });
    expect('A16 warehouse high-value adjustment create', adjustment, 201);
    ids.adjustment = idOf(adjustment.body);
    const adjustmentState = bodyData(adjustment.body)?.status;
    observed('A16 adjustment threshold state', `id=${ids.adjustment}; status=${adjustmentState}; value=${bodyData(adjustment.body)?.total_value ?? 'not-returned'}`);
    if (ids.adjustment && adjustmentState === 'pending') {
      await loginInternal('qc', 'qc@ogami.test');
      expect('A16 QC cannot approve stock adjustment', await api(`/inventory/stock-adjustments/${ids.adjustment}/approve`, { method: 'PATCH', body: {} }), 403);
      await loginInternal('finance', 'finance@ogami.test');
      const before = await read('/inventory/stock-levels?per_page=100', 'A16 stock before checker approval');
      const approvedAdjustment = await api(`/inventory/stock-adjustments/${ids.adjustment}/approve`, { method: 'PATCH', body: {} });
      expect('A16 Finance high-value adjustment approval', approvedAdjustment, 200);
      const after = await read('/inventory/stock-levels?per_page=100', 'A16 stock after checker approval');
      observed('A16 stock movement/value effect', `before=${short(before.body)} after=${short(after.body)} adjustment=${short(approvedAdjustment.body)}`);
      expect('A16 approved adjustment replay', await api(`/inventory/stock-adjustments/${ids.adjustment}/approve`, { method: 'PATCH', body: {} }), 422);
    } else blocked('A16 high-value checker transition', `The live threshold did not produce pending status; no Finance approval was claimed. state=${adjustmentState}`);
  }
  await loginInternal('warehouse', 'warehouse@ogami.test');
  const warehouseList = await read('/inventory/warehouses?per_page=100', 'A16 warehouse options');
  const warehouse = rows(warehouseList.body)[0];
  const countOptions = await read('/inventory/stock-counts/options', 'A16 stock count options');
  if (!warehouse?.id) {
    blocked('A16 stock count variance', 'No warehouse hash was exposed; seeded count sessions were preserved.');
  } else {
    const count = await api('/inventory/stock-counts', { method: 'POST', body: { title: `LIVE-B9 disposable count ${Date.now()}`, scope: 'warehouse', warehouse_id: warehouse.id } });
    expect('A16 warehouse stock count create', count, 201);
    ids.count = idOf(count.body);
    if (ids.count) {
      await expect('A16 stock count start/freeze', await api(`/inventory/stock-counts/${ids.count}/start`, { method: 'POST', body: {} }), 200);
      const detail = await read(`/inventory/stock-counts/${ids.count}`, 'A16 stock count items');
      const countItem = bodyData(detail.body)?.items?.[0];
      if (countItem?.id) {
        const expected = Number(countItem.system_quantity ?? countItem.expected_quantity ?? countItem.book_quantity ?? 0);
        const countValue = (expected + 1).toFixed(3);
        await expect('A16 record count variance', await api(`/inventory/stock-counts/items/${countItem.id}/count`, { method: 'POST', body: { counted_quantity: countValue, notes: 'LIVE-B9 disposable variance' } }), 200);
        await expect('A16 approve count variance', await api(`/inventory/stock-counts/items/${countItem.id}/approve`, { method: 'POST', body: {} }), 200);
        expect('A16 count variance replay', await api(`/inventory/stock-counts/items/${countItem.id}/approve`, { method: 'POST', body: {} }), 422);
        await expect('A16 complete stock count', await api(`/inventory/stock-counts/${ids.count}/complete`, { method: 'POST', body: {} }), 200);
        await read(`/inventory/stock-counts/${ids.count}`, 'A16 completed count exact state');
      } else {
        blocked('A16 count variance checker', `Count session created but returned no item; detail=${short(detail.body)}`);
        await expect('A16 cancel empty disposable count', await api(`/inventory/stock-counts/${ids.count}`, { method: 'DELETE' }), 204);
      }
    }
  }

  // A17 — PPAP maker/reviewer boundary; no QC checker is invented.
  await loginInternal('qc', 'qc@ogami.test');
  await ui('/quality/inspections', 'a17-qc-ppap-boundary');
  const ppaps = await read('/quality/ppap?per_page=100', 'A17 PPAP list');
  const ppapFixture = rows(ppaps.body)[0];
  const vendorId = ppapFixture?.vendor?.id ?? ppapFixture?.vendor_id;
  const itemIdPpap = ppapFixture?.item?.id ?? ppapFixture?.item_id;
  if (!vendorId || !itemIdPpap) {
    blocked('A17 PPAP lifecycle', 'No vendor/item PPAP fixture was available without manufacturing a supplier master record.');
  } else {
    const ppap = await api('/quality/ppap', { method: 'POST', body: { vendor_id: vendorId, item_id: itemIdPpap, product_id: ppapFixture?.product?.id ?? ppapFixture?.product_id, ppap_level: '3', submission_date: '2026-09-14', notes: `LIVE-B9 PPAP maker checker ${Date.now()}` } });
    expect('A17 QC PPAP create', ppap, 201);
    ids.ppap = idOf(ppap.body);
    if (ids.ppap) {
      const detail = await read(`/quality/ppap/${ids.ppap}`, 'A17 PPAP detail');
      const element = bodyData(detail.body)?.elements?.[0];
      if (element?.id) await expect('A17 PPAP element evidence update', await api(`/quality/ppap/${ids.ppap}/elements/${element.id}`, { method: 'PATCH', body: { status: 'accepted', notes: 'LIVE-B9 evidence' } }), 200);
      await expect('A17 PPAP submit', await api(`/quality/ppap/${ids.ppap}/submit`, { method: 'PATCH', body: {} }), 200);
      expect('A17 QC self-review denied', await api(`/quality/ppap/${ids.ppap}/review`, { method: 'PATCH', body: {} }), 403);
      observed('A17 distinct QC checker fixture absent', 'Only qc_inspector is seeded with quality.ppap.manage; no QC manager/checker actor was invented. Independent review/approval is BLOCKED by fixture absence.');
      await expect('A17 PPAP maker rejection cleanup', await api(`/quality/ppap/${ids.ppap}/reject`, { method: 'PATCH', body: { reason: 'LIVE-B9 rejected disposable PPAP after self-review denial' } }), 200);
      expect('A17 terminal PPAP replay', await api(`/quality/ppap/${ids.ppap}/approve`, { method: 'PATCH', body: {} }), 422);
      await loginPortal('supplier', 'supplier-portal', 'portal@supp.test');
      const supplierPpap = await read('/b2b/supplier/ppap-submissions?per_page=100', 'A17 supplier PPAP visibility');
      observed('A17 supplier PPAP scoped visibility', `rows=${rows(supplierPpap.body).length}; target=${ids.ppap}`);
    }
  }

  // A18 — reserved definitions are not live approval types; direct routes are probed safely.
  await loginInternal('admin', 'admin@ogami.test');
  await ui('/approvals', 'a18-approval-board');
  const boardAll = await read('/approvals/board?pending_limit=100&history_limit=100', 'A18 approval board registered types');
  const boardText = short(boardAll.body);
  record(!/department_transfer|separation_clearance|maintenance_request|8d_report|bill_payment|work_order|ncr/.test(boardText) ? 'PASS' : 'FAIL', 'A18 no reserved approval cards', `board=${boardText}`);
  for (const type of ['department_transfer', 'separation_clearance', 'maintenance_request', '8d_report', 'bill_payment', 'work_order', 'ncr']) {
    await expect(`A18 reserved board type ${type} rejected`, await api(`/approvals/board?type=${type}`), 422);
  }
  await loginInternal('hr', 'hr@ogami.test');
  await ui('/hr/clearances', 'a18-clearance-direct');
  const clearances = await read('/hr/clearances?per_page=100', 'A18 separation clearance direct list');
  expect('A18 invalid separation direct action', await api('/hr/employees/not-a-hash/separation', { method: 'POST', body: {} }), [403, 404, 422]);
  if (rows(clearances.body)[0]?.id) expect('A18 malformed clearance sign validation', await api(`/hr/clearances/${rows(clearances.body)[0].id}/items`, { method: 'PATCH', body: {} }), 422);
  await loginInternal('maintenance', 'maintenance@ogami.test');
  await ui('/maintenance/work-orders', 'a18-maintenance-direct');
  await read('/maintenance/work-orders?per_page=20', 'A18 maintenance direct lifecycle list');
  expect('A18 maintenance direct invalid create', await api('/maintenance/work-orders', { method: 'POST', body: {} }), 422);
  await loginInternal('crm', 'crm@ogami.test');
  await ui('/crm/complaints', 'a18-8d-direct');
  const complaints = await read('/crm/complaints?per_page=20', 'A18 8D direct complaint list');
  if (rows(complaints.body)[0]?.id) expect('A18 invalid 8D direct finalize validation', await api(`/crm/complaints/${rows(complaints.body)[0].id}/8d/finalize`, { method: 'POST', body: {} }), 422);
  await loginInternal('finance', 'finance@ogami.test');
  await ui('/accounting/bills', 'a18-bill-payment-direct');
  const bills = await read('/bills?per_page=20', 'A18 bill payment direct list');
  if (rows(bills.body)[0]?.id) expect('A18 invalid bill payment validation', await api(`/bills/${rows(bills.body)[0].id}/payments`, { method: 'POST', body: {} }), 422);
  await loginInternal('production', 'production@ogami.test');
  await read('/production/work-orders?per_page=20', 'A18 work-order direct lifecycle list');
  expect('A18 invalid work-order direct create validation', await api('/production/work-orders', { method: 'POST', body: {} }), 422);
  await loginInternal('qc', 'qc@ogami.test');
  await read('/quality/ncrs?per_page=20', 'A18 NCR direct lifecycle list');
  expect('A18 invalid NCR direct create validation', await api('/quality/ncrs', { method: 'POST', body: {} }), 422);
  await loginInternal('purchasing', 'purchasing@ogami.test');
  expect('A18 purchasing cannot perform NCR direct action', await api('/quality/ncrs', { method: 'POST', body: {} }), 403);
  observed('A18 reserved direct-action boundary', 'Direct lifecycle endpoints validate or deny their own domain actions; no reserved workflow approval card or Approval Board success was observed.');
} catch (error) {
  record('FAIL', 'A10-A18 live harness', error instanceof Error ? error.stack ?? error.message : String(error));
} finally {
  await logout().catch(() => {});
  await browser.close();
  await fs.writeFile(`${artifact}/a10-a18-results.json`, JSON.stringify({ generated_at: new Date().toISOString(), ids, results }, null, 2));
}

const counts = results.reduce((out, row) => ({ ...out, [row.status]: (out[row.status] ?? 0) + 1 }), {});
console.log(`COUNTS ${JSON.stringify(counts)}`);
console.log(`IDS ${JSON.stringify(ids)}`);
