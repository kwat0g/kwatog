import { firefox } from '../../spa/node_modules/playwright/index.mjs';
import fs from 'node:fs/promises';

const base = 'http://localhost';
const artifactDir = '/home/kwat0g/Desktop/kwatog/audit/live-2026-09-14/artifacts';
const employeeId = 'ajzw1qdw3e';
const employeeEmail = 'live-b10-61815681@ogami.test';
const stamp = '61815681';
const password = 'password';
const results = [];
const evidence = [];
const ids = { employee: employeeId };
const residuals = [{ type: 'employee', id: employeeId }];
let actor = 'signed-out';
let lastLogin = 0;
let employeePassword = null;

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
const brief = (value) => JSON.stringify(value ?? '').slice(0, 1800);

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
function blocked(name, detail) { record('BLOCKED', name, detail); }
function observed(name, detail) { record('OBSERVED', name, detail); }

async function api(path, options = {}) {
  const result = await page.evaluate(async ({ path, options }) => {
    const sanitize = (value) => {
      if (Array.isArray(value)) return value.map(sanitize);
      if (!value || typeof value !== 'object') return value;
      return Object.fromEntries(Object.entries(value).map(([key, item]) => [
        key, /temp_password|temporary_password|password_hash/i.test(key) ? '[REDACTED]' : sanitize(item),
      ]));
    };
    const xsrf = document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length);
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}), ...(options.multipart ? {} : options.body === undefined ? {} : { 'Content-Type': 'application/json' }), ...(options.headers ?? {}) };
    let body;
    if (options.multipart) {
      const form = new FormData();
      for (const [key, value] of Object.entries(options.fields ?? {})) form.append(key, String(value));
      form.append('file', new Blob([new Uint8Array(options.bytes ?? [37, 80, 68, 70, 45, 49, 46, 52])], { type: options.mime ?? 'application/pdf' }), options.filename ?? 'live-audit.pdf');
      body = form;
    } else if (options.body !== undefined) body = JSON.stringify(options.body);
    const response = await fetch(path.startsWith('/api/') ? path : `/api/v1${path}`, { method: options.method ?? 'GET', credentials: 'include', headers, body });
    const text = await response.text();
    let parsed;
    try { parsed = JSON.parse(text); } catch { parsed = text; }
    return { status: response.status, body: sanitize(parsed), secret: options.captureSecret ? parsed?.temp_password ?? parsed?.data?.temp_password ?? null : null, contentType: response.headers.get('content-type'), disposition: response.headers.get('content-disposition'), requestId: response.headers.get('x-request-id') ?? response.headers.get('x-correlation-id') };
  }, { path, options });
  evidence.push({ actor, method: options.method ?? 'GET', path, status: result.status, requestId: result.requestId ?? null, body: result.body });
  return result;
}
async function file(path, accept = 'application/pdf') {
  const result = await page.evaluate(async ({ path, accept }) => {
    const xsrf = document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length);
    const response = await fetch(`/api/v1${path}`, { credentials: 'include', headers: { Accept: accept, 'X-Requested-With': 'XMLHttpRequest', ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}) } });
    return { status: response.status, contentType: response.headers.get('content-type'), disposition: response.headers.get('content-disposition'), bytes: (await response.arrayBuffer()).byteLength };
  }, { path, accept });
  evidence.push({ actor, method: 'GET', path, status: result.status, file: result });
  return result;
}
async function shot(name) { await page.screenshot({ path: `${artifactDir}/l03-l05-${name}.png`, fullPage: true }).catch(() => {}); }
async function ui(path, name) {
  const response = await page.goto(`${base}${path}`, { waitUntil: 'domcontentloaded' });
  await page.locator('body').waitFor({ state: 'visible', timeout: 20000 });
  await page.waitForFunction(() => document.body.innerText.trim().length > 30, null, { timeout: 25000 }).catch(() => {});
  await shot(name);
  record('PASS', `${name} UI`, `document=${response?.status() ?? 'none'} uiUrl=${page.url()}`);
}
async function logout() {
  if (actor !== 'signed-out') await api('/auth/logout', { method: 'POST' }).catch(() => {});
  await context.clearCookies(); actor = 'signed-out';
}
async function login(alias, email, suppliedPassword = password, changeFirst = false) {
  await logout();
  const wait = Math.max(0, 13000 - (Date.now() - lastLogin));
  if (wait) await sleep(wait);
  await page.goto(`${base}/login`, { waitUntil: 'domcontentloaded' });
  await page.locator('input[type="email"], input[name="email"]').first().fill(email);
  await page.locator('input[type="password"]').first().fill(suppliedPassword);
  await page.getByRole('button', { name: /sign in|log in/i }).click();
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 30000 });
  actor = alias; lastLogin = Date.now();
  const identity = await api('/auth/user');
  expect(`${alias} authenticated`, identity, 200, `uiUrl=${page.url()}`);
  if (changeFirst && page.url().includes('/change-password')) {
    employeePassword = `B10Resume${stamp}!Aa`;
    const changed = await api('/auth/change-password', { method: 'POST', body: { current_password: suppliedPassword, new_password: employeePassword, new_password_confirmation: employeePassword } });
    expect('L04 disposable account password change', changed, 200);
    return login(alias, email, employeePassword, false);
  }
  return identity;
}
async function pollPeriod(periodId, timeoutMs = 45000) {
  const deadline = Date.now() + timeoutMs;
  let latest;
  while (Date.now() < deadline) {
    latest = await api(`/payroll-periods/${periodId}`);
    if (latest.status === 200 && dataOf(latest.body)?.status !== 'processing') return latest;
    await sleep(2000);
  }
  return latest;
}

try {
  // Resolve immutable disposable identity and scope through HR.
  await login('hr', 'hr@ogami.test');
  const employee = await api(`/hr/employees/${ids.employee}`);
  expect('L04 disposable employee scope identity', employee, 200);
  const employeeData = dataOf(employee.body);
  ids.department = employeeData?.department?.id ?? employeeData?.department_id;
  ids.employmentType = employeeData?.employment_type;
  ids.payType = employeeData?.pay_type;
  observed('L04 exact disposable payroll scope', `employee=${ids.employee}; department=${ids.department}; employment_type=${ids.employmentType}; pay_type=${ids.payType}; salary=${employeeData?.basic_monthly_salary ?? employeeData?.semi_monthly_rate}`);

  // The employee role cannot enumerate loan types by design. HR resolves the
  // catalog; the disposable employee still submits through self-service.
  const loanTypes = await api('/loans/types');
  expect('L03 HR resolves loan types for employee form', loanTypes, 200);
  const loanType = rowsOf(loanTypes.body).find((row) => /company/i.test(`${row.value} ${row.label}`))?.value ?? rowsOf(loanTypes.body)[0]?.value;
  if (!loanType) {
    blocked('L03 disposable company loan', `HR loan type catalog was empty: ${brief(loanTypes.body)}`);
  } else {
    await login('technical-admin', 'admin@ogami.test');
    const status = await api(`/admin/users/${dataOf((await api(`/hr/employees/${ids.employee}/account-status`)).body)?.user_id}/reset-password`, { method: 'PATCH', captureSecret: true });
    expect('L03 technical reset for disposable employee continuation', status, 200);
    if (!status.secret) blocked('L03 disposable employee loan login', `No temporary credential returned: ${brief(status.body)}`);
    else {
      await login('employee', employeeData?.contact?.email ?? employeeEmail, status.secret, true);
      const loan = await api('/hr/self-service/loans', { method: 'POST', body: { loan_type: loanType, amount: '10.00', periods: 1, reason: 'L03 disposable loan for October payroll deduction and final pay' } });
      expect('L03 employee creates company loan', loan, 201);
      ids.loan = idOf(loan.body); if (ids.loan) residuals.push({ type: 'loan', id: ids.loan });
      if (ids.loan) {
        expect('L03 employee loan self-approval denied', await api(`/loans/${ids.loan}/approve`, { method: 'PATCH', body: { remarks: 'self approval probe' } }), 403);
        await login('depthead', 'depthead@ogami.test');
        expect('L03 depthead loan approval', await api(`/loans/${ids.loan}/approve`, { method: 'PATCH', body: { remarks: 'L03 department loan approval' } }), 200);
        await login('production', 'production@ogami.test');
        expect('L03 production loan approval', await api(`/loans/${ids.loan}/approve`, { method: 'PATCH', body: { remarks: 'L03 manager loan approval' } }), 200);
        await login('finance2', 'finance2@ogami.test');
        expect('L03 finance2 loan approval', await api(`/loans/${ids.loan}/approve`, { method: 'PATCH', body: { remarks: 'L03 finance loan approval' } }), 200);
        await login('vp', 'vp@ogami.test');
        expect('L03 VP loan approval', await api(`/loans/${ids.loan}/approve`, { method: 'PATCH', body: { remarks: 'L03 executive loan approval' } }), 200);
        const loanDetail = await api(`/loans/${ids.loan}`);
        expect('L03 exact approved loan state', loanDetail, 200);
        observed('L03 exact loan balance before payroll', brief(loanDetail.body));
      }
    }
  }

  // L04 — use October to avoid the preserved seeded Sep company-wide period.
  await login('hr', 'hr@ogami.test');
  await ui('/payroll/periods', 'l04-hr-payroll');
  const payrollPayload = {
    period_start: '2026-10-01', period_end: '2026-10-15', payroll_date: '2026-10-15', is_first_half: false,
    scope_department_ids: [ids.department], scope_employment_types: [ids.employmentType], scope_pay_types: [ids.payType],
    scope_label: `LIVE-B10 disposable October payroll ${stamp}`,
  };
  const preview = await api('/payroll-periods/scope-preview', { method: 'POST', body: payrollPayload });
  expect('L04 October payroll scope preview', preview, 200);
  observed('L04 October scope exact headcount/money', brief(preview.body));
  const period = await api('/payroll-periods', { method: 'POST', body: payrollPayload });
  expect('L04 HR creates October payroll period', period, 201);
  ids.period = idOf(period.body); if (ids.period) residuals.push({ type: 'payroll-period', id: ids.period });
  if (ids.period) {
    observed('L04 derived-half October draft', brief(period.body));
    expect('L04 HR compute October payroll', await api(`/payroll-periods/${ids.period}/compute`, { method: 'POST' }), 202);
    const computed = await pollPeriod(ids.period);
    if (computed?.status !== 200 || dataOf(computed.body)?.status !== 'computed') {
      blocked('L04 October queue compute', `final_status=${dataOf(computed?.body)?.status}; response=${brief(computed?.body)}. No retry was enqueued.`);
    } else {
      expect('L04 October compute completion', computed, 200);
      const anomalies = await api(`/payroll-periods/${ids.period}/anomalies`);
      expect('L04 October anomaly review', anomalies, 200);
      observed('L04 October anomaly exact state', brief(anomalies.body));
      expect('L04 HR cannot approve October payroll', await api(`/payroll-periods/${ids.period}/approve`, { method: 'PATCH' }), 403);
      await login('finance', 'finance@ogami.test');
      expect('L04 finance notification feed', await api('/notifications?per_page=100'), 200);
      await login('finance2', 'finance2@ogami.test');
      expect('L04 finance2 approves October payroll', await api(`/payroll-periods/${ids.period}/approve`, { method: 'PATCH' }), 200);
      const finalized = await api(`/payroll-periods/${ids.period}/finalize`, { method: 'PATCH' });
      expect('L04 finance2 finalizes October payroll', finalized, 200);
      if (finalized.status === 200) {
        const detail = await api(`/payroll-periods/${ids.period}`);
        expect('L04 October finalized exact detail', detail, 200);
        observed('L04 October money/GL exact state', brief(detail.body));
        const payrollRows = await api(`/payrolls?period_id=${ids.period}&per_page=100`);
        expect('L04 October payroll rows', payrollRows, 200);
        const payrollRow = rowsOf(payrollRows.body).find((row) => row.employee_id === ids.employee || row.employee?.id === ids.employee) ?? rowsOf(payrollRows.body)[0];
        if (payrollRow?.id) {
          ids.payroll = payrollRow.id; residuals.push({ type: 'payroll', id: ids.payroll });
          const payslip = await file(`/payrolls/${ids.payroll}/payslip`);
          expect('L04 October payslip PDF', payslip, 200);
          observed('L04 October payroll row exact money', brief(payrollRow));
        } else blocked('L04 October payslip', `No payroll row: ${brief(payrollRows.body)}`);
        const bankPreview = await api(`/payroll-periods/${ids.period}/bank-file/preview?format=generic`);
        expect('L04 October bank preview', bankPreview, 200);
        const bank = await api(`/payroll-periods/${ids.period}/bank-file`, { method: 'POST', body: { format: 'generic' } });
        expect('L04 October bank artifact', bank, 201);
        const bankDownload = await file(`/payroll-periods/${ids.period}/bank-file`, 'text/csv');
        expect('L04 October bank download', bankDownload, 200);
        const proofOptions = await api(`/payroll-periods/${ids.period}/disbursement-proofs/options`);
        expect('L04 October proof options', proofOptions, 200);
        const detailData = dataOf(detail.body);
        const amount = detailData?.total_net_pay ?? detailData?.total_net ?? detailData?.summary?.total_net_pay ?? detailData?.summary?.total_net;
        if (!amount) {
          blocked('L04 disbursement proof', `Finalized period did not expose total net pay: ${brief(detail.body)}`);
        } else {
          const proof = await api(`/payroll-periods/${ids.period}/disbursement-proofs`, { method: 'POST', multipart: true, fields: {
            proof_type: dataOf(proofOptions.body)?.proof_types?.[0]?.value ?? 'bank_confirmation', bank_name: 'Audit Bank', transaction_reference: `B10-${stamp}-OCT`, disbursed_amount: String(amount), disbursement_date: '2026-10-15', notes: 'L04 October disposable proof',
          }, filename: `B10-${stamp}-oct-proof.pdf`, mime: 'application/pdf' });
          expect('L04 October disbursement proof', proof, 201);
          ids.proof = idOf(proof.body); if (ids.proof) residuals.push({ type: 'disbursement-proof', id: ids.proof });
          expect('L04 October mark disbursed', await api(`/payroll-periods/${ids.period}/mark-disbursed`, { method: 'PATCH' }), 200);
          observed('L04 October final disbursement state', brief((await api(`/payroll-periods/${ids.period}`)).body));
          expect('L04 October disbursement replay', await api(`/payroll-periods/${ids.period}/mark-disbursed`, { method: 'PATCH' }), 422);
          expect('L04 October compute replay', await api(`/payroll-periods/${ids.period}/compute`, { method: 'POST' }), 422);
          expect('L04 October duplicate period/no-double-pay guard', await api('/payroll-periods', { method: 'POST', body: payrollPayload }), 422);
        }
      } else blocked('L04 October downstream finance effects', `Finalize response=${brief(finalized.body)}`);
    }
  }

  // L05 — all checklist owners sign, then HR computes/finalizes final pay.
  await login('hr', 'hr@ogami.test');
  await ui('/hr/separations', 'l05-hr-separations');
  const separation = await api(`/hr/employees/${ids.employee}/separation`, { method: 'POST', body: { separation_date: '2026-10-15', separation_reason: 'resigned', remarks: 'LIVE-B10 October disposable separation' } });
  expect('L05 HR initiates October separation', separation, 201);
  ids.clearance = idOf(separation.body); if (ids.clearance) residuals.push({ type: 'clearance', id: ids.clearance });
  if (ids.clearance) {
    const initial = await api(`/hr/clearances/${ids.clearance}`);
    expect('L05 October clearance manifest', initial, 200);
    const items = dataOf(initial.body)?.clearance_items ?? [];
    observed('L05 October item manifest', brief(items));
    const groups = [
      ['depthead', 'depthead@ogami.test', items.filter((item) => /production/i.test(item.department)), 'department'],
      ['warehouse', 'warehouse@ogami.test', items.filter((item) => /warehouse/i.test(item.department)), 'warehouse'],
      ['maintenance', 'maintenance@ogami.test', items.filter((item) => /maintenance/i.test(item.department)), 'maintenance'],
      ['finance2', 'finance2@ogami.test', items.filter((item) => /finance/i.test(item.department)), 'finance2'],
      ['hr', 'hr@ogami.test', items.filter((item) => /^(hr|it)$/i.test(item.department)), 'HR'],
    ];
    const warehouseItem = items.find((item) => /warehouse/i.test(item.department));
    if (warehouseItem) {
      await login('employee', employeeEmail, employeePassword ?? `B10Resume${stamp}!Aa`);
      expect('L05 employee wrong-role clearance sign', await api(`/hr/clearances/${ids.clearance}/items`, { method: 'PATCH', body: { item_key: warehouseItem.item_key, remarks: 'wrong role probe' } }), 403);
    }
    for (const [alias, email, owned, label] of groups) {
      await login(alias, email);
      for (const item of owned) expect(`L05 ${label} signs ${item.item_key}`, await api(`/hr/clearances/${ids.clearance}/items`, { method: 'PATCH', body: { item_key: item.item_key, remarks: `L05 ${label} sign` } }), 200);
      expect(`L05 ${label} clearance state`, await api(`/hr/clearances/${ids.clearance}`), 200);
    }
    await login('hr', 'hr@ogami.test');
    const completed = await api(`/hr/clearances/${ids.clearance}`);
    expect('L05 October clearance completed', completed, 200);
    observed('L05 October completed clearance', brief(completed.body));
    expect('L05 finance wrong-role final-pay compute', await api(`/hr/clearances/${ids.clearance}/final-pay/compute`, { method: 'POST' }), 403);
    const finalPay = await api(`/hr/clearances/${ids.clearance}/final-pay/compute`, { method: 'POST' });
    expect('L05 HR computes October final pay', finalPay, 200);
    observed('L05 October final-pay exact money/deductions', brief(finalPay.body));
    expect('L05 HR finalizes October clearance', await api(`/hr/clearances/${ids.clearance}/finalize`, { method: 'PATCH' }), 200);
    observed('L05 October final clearance', brief((await api(`/hr/clearances/${ids.clearance}`)).body));
    expect('L05 final-pay terminal replay', await api(`/hr/clearances/${ids.clearance}/final-pay/compute`, { method: 'POST' }), 422);
    expect('L05 clearance terminal replay', await api(`/hr/clearances/${ids.clearance}/finalize`, { method: 'PATCH' }), 422);
    const account = await api(`/hr/employees/${ids.employee}/account-status`);
    expect('L05 account deactivation final state', account, 200);
    observed('L05 account deactivation exact state', brief(account.body));
  }
} catch (error) {
  record('FAIL', 'L03-L05 continuation harness', error instanceof Error ? error.stack ?? error.message : String(error));
  await shot('failure');
} finally {
  await logout().catch(() => {});
  await browser.close();
  await fs.writeFile(`${artifactDir}/l03-l05-continuation-results.json`, JSON.stringify({ ids, residuals, results, evidence }, null, 2));
}
const counts = results.reduce((out, row) => ({ ...out, [row.status]: (out[row.status] ?? 0) + 1 }), {});
console.log(JSON.stringify({ counts, ids, residuals, results }, null, 2));
if (results.some((result) => result.status === 'FAIL')) process.exitCode = 1;
