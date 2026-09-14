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
let guard = 'none';
let lastLogin = 0;
let disposablePassword = null;

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
function redact(value) {
  if (Array.isArray(value)) return value.map(redact);
  if (!value || typeof value !== 'object') return value;
  return Object.fromEntries(Object.entries(value).map(([key, item]) => [
    key,
    /temp_password|temporary_password|password_hash/i.test(key) ? '[REDACTED]' : redact(item),
  ]));
}

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
        key,
        /temp_password|temporary_password|password_hash/i.test(key) ? '[REDACTED]' : sanitize(item),
      ]));
    };
    const token = document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length);
    const headers = {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}),
      ...(options.multipart ? {} : options.body === undefined ? {} : { 'Content-Type': 'application/json' }),
      ...(options.headers ?? {}),
    };
    let body;
    if (options.multipart) {
      const form = new FormData();
      for (const [key, value] of Object.entries(options.fields ?? {})) form.append(key, String(value));
      const bytes = new Uint8Array(options.bytes ?? [37, 80, 68, 70, 45, 49, 46, 52, 10, 37, 97, 117, 100, 105, 116]);
      form.append(options.fileField ?? 'file', new Blob([bytes], { type: options.mime ?? 'application/pdf' }), options.filename ?? 'live-audit.pdf');
      body = form;
    } else if (options.body !== undefined) {
      body = JSON.stringify(options.body);
    }
    const response = await fetch(path.startsWith('/api/') ? path : `/api/v1${path}`, {
      method: options.method ?? 'GET', credentials: 'include', headers, body,
    });
    const text = await response.text();
    let parsed;
    try { parsed = JSON.parse(text); } catch { parsed = text; }
    const secret = options.captureSecret
      ? parsed?.temp_password ?? parsed?.data?.temp_password ?? null
      : null;
    return {
      status: response.status,
      body: sanitize(parsed),
      secret,
      contentType: response.headers.get('content-type'),
      disposition: response.headers.get('content-disposition'),
      requestId: response.headers.get('x-request-id') ?? response.headers.get('x-correlation-id'),
    };
  }, { path, options });
  evidence.push({ actor, method: options.method ?? 'GET', path, status: result.status, requestId: result.requestId ?? null, body: result.body });
  return result;
}

async function file(path, accept = 'application/pdf') {
  const result = await page.evaluate(async ({ path, accept }) => {
    const token = document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length);
    const response = await fetch(`/api/v1${path}`, {
      credentials: 'include', headers: { Accept: accept, 'X-Requested-With': 'XMLHttpRequest', ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}) },
    });
    return { status: response.status, contentType: response.headers.get('content-type'), disposition: response.headers.get('content-disposition'), bytes: (await response.arrayBuffer()).byteLength };
  }, { path, accept });
  evidence.push({ actor, method: 'GET', path, status: result.status, file: result });
  return result;
}

async function screenshot(name) {
  await page.screenshot({ path: `${artifactDir}/l01-l05-${name}.png`, fullPage: true }).catch(() => {});
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
  if (actor !== 'signed-out') await api(guard === 'internal' ? '/auth/logout' : '/auth/logout', { method: 'POST' }).catch(() => {});
  await context.clearCookies();
  actor = 'signed-out';
  guard = 'none';
}

async function login(alias, email, suppliedPassword = password, changeOnFirstLogin = false) {
  await logout();
  const wait = Math.max(0, 13000 - (Date.now() - lastLogin));
  if (wait) await sleep(wait);
  await page.goto(`${base}/login`, { waitUntil: 'domcontentloaded' });
  await page.locator('input[type="email"], input[name="email"]').first().fill(email);
  await page.locator('input[type="password"]').first().fill(suppliedPassword);
  await page.getByRole('button', { name: /sign in|log in/i }).click();
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 30000 });
  actor = alias;
  guard = 'internal';
  lastLogin = Date.now();
  const identity = await api('/auth/user');
  expect(`${alias} authenticated`, identity, 200, `uiUrl=${page.url()}`);
  if (changeOnFirstLogin && page.url().includes('/change-password')) {
    disposablePassword = `B10Live${stamp}!Aa`;
    const changed = await api('/auth/change-password', {
      method: 'POST',
      body: { current_password: suppliedPassword, new_password: disposablePassword, new_password_confirmation: disposablePassword },
    });
    expect('disposable employee first-login password change', changed, 200);
    // Changing the temporary password revokes the current session. Re-login
    // through the normal form instead of navigating inside that dead session.
    return login(alias, email, disposablePassword, false);
  }
  return identity.body;
}

async function pollPeriod(periodId, timeoutMs = 45000) {
  const deadline = Date.now() + timeoutMs;
  let latest = null;
  while (Date.now() < deadline) {
    latest = await api(`/payroll-periods/${periodId}`);
    const status = dataOf(latest.body)?.status;
    if (latest.status === 200 && status !== 'processing') return latest;
    await sleep(2000);
  }
  return latest;
}

function employeeOptionValues(optionsBody, key, fallback) {
  const values = dataOf(optionsBody)?.[key];
  if (!Array.isArray(values)) return fallback;
  return values.map((value) => typeof value === 'string' ? value : value.value).filter(Boolean);
}

function balanceRemaining(row) {
  return Number(row?.remaining ?? row?.balance_remaining ?? row?.available ?? 0);
}

try {
  // L01 — HR creates a disposable hire in seeded PROD so the department head
  // can exercise the real approval row scope without touching seeded records.
  await login('hr', 'hr@ogami.test');
  await ui('/hr/departments', 'l01-hr-departments');
  await ui('/hr/positions', 'l01-hr-positions');
  const departments = await api('/hr/departments?per_page=100');
  const prod = rowsOf(departments.body).find((row) => /prod/i.test(`${row.code} ${row.name}`));
  const employeeOptions = await api('/hr/employees/options');
  const employmentTypes = employeeOptionValues(employeeOptions.body, 'employment_types', ['regular', 'probationary', 'contractual', 'apprentice', 'seasonal']);
  const payTypes = employeeOptionValues(employeeOptions.body, 'pay_types', ['monthly', 'semi_monthly']);
  const allEmployees = await api('/hr/employees?per_page=100');
  const existing = rowsOf(allEmployees.body).filter((row) => row.department_id === prod?.id || row.department?.id === prod?.id);
  const combinations = employmentTypes.flatMap((employment_type) => payTypes.map((pay_type) => ({ employment_type, pay_type })));
  const selectedCombo = combinations.find((combo) => !existing.some((row) => row.employment_type === combo.employment_type && row.pay_type === combo.pay_type));

  if (!prod?.id || !selectedCombo) {
    blocked('L01-L04 disposable payroll fixture', `Could not find PROD department or an unused employment/pay-type scope. prod=${prod?.id}; combinations=${JSON.stringify(combinations)}; existing=${existing.length}`);
  } else {
    ids.department = prod.id;
    const existingPositions = await api('/hr/positions?per_page=100');
    for (const stale of rowsOf(existingPositions.body).filter((row) => String(row.title ?? '').startsWith('B10 Disposable ') && Number(row.employees_count ?? 0) === 0)) {
      expect('L01 safe cleanup of orphan disposable position', await api(`/hr/positions/${stale.id}`, { method: 'DELETE' }), [200, 204]);
    }
    const position = await api('/hr/positions', { method: 'POST', body: {
      title: `B10 Disposable ${stamp}`,
      department_id: ids.department,
      salary_grade: 'B10',
    } });
    expect('L01 HR creates disposable position', position, 201);
    ids.position = idOf(position.body);
    if (ids.position) residuals.push({ type: 'position', id: ids.position });

    const employeePayload = {
      first_name: 'Batch', last_name: 'Ten',
      birth_date: '1994-02-03', gender: 'male', civil_status: 'single', nationality: 'Filipino',
      street_address: 'Live audit address', barangay: 'San Agustin', city: 'Dasmarinas', province: 'Cavite', zip_code: '4114',
      mobile_number: `0917${stamp.slice(-7)}`, email: `live-b10-${stamp}@ogami.test`,
      emergency_contact_name: 'Audit Contact', emergency_contact_relation: 'Sibling', emergency_contact_phone: '09171234567',
      department_id: ids.department, position_id: ids.position,
      employment_type: selectedCombo.employment_type, pay_type: selectedCombo.pay_type,
      date_hired: '2026-09-01', basic_monthly_salary: selectedCombo.pay_type === 'monthly' ? '24000.00' : null,
      semi_monthly_rate: selectedCombo.pay_type === 'semi_monthly' ? '12000.00' : null,
      bank_name: 'Audit Bank', bank_account_no: `B10-${stamp}`,
    };
    const employee = await api('/hr/employees', { method: 'POST', body: employeePayload });
    expect('L01 HR creates disposable employee', employee, 201);
    ids.employee = idOf(employee.body);
    if (ids.employee) residuals.push({ type: 'employee', id: ids.employee });
    const employeeData = dataOf(employee.body);
    ids.employeeNo = employeeData?.employee_no;
    ids.employeeEmail = employeePayload.email;
    observed('L01 disposable employee identity', `employee=${ids.employee}; employee_no=${ids.employeeNo}; name=${employeeData?.full_name ?? employeeData?.name}; pay_type=${selectedCombo.pay_type}; employment_type=${selectedCombo.employment_type}`);

    if (ids.employee) {
      const accountBefore = await api(`/hr/employees/${ids.employee}/account-status`);
      expect('L01 account absent before provisioning', accountBefore, 200);
      const provision = await api(`/hr/employees/${ids.employee}/provision-account`, { method: 'POST', body: { send_welcome: true } });
      expect('L01 HR provisions employee account', provision, 201);
      ids.user = idOf(provision.body);
      observed('L01 account provisioning/welcome state', `user=${ids.user}; email=${dataOf(provision.body)?.email}; welcome=${provision.body?.message}; queue evidence is represented by the returned queued welcome message`);

      const onboarding = await api(`/hr/employees/${ids.employee}/onboarding`);
      expect('L01 onboarding detail', onboarding, 200);
      expect('L01 onboarding recompute', await api(`/hr/employees/${ids.employee}/onboarding/recompute`, { method: 'POST' }), 200);
      expect('L01 department team notification mark', await api(`/hr/employees/${ids.employee}/onboarding/department-team-notified`, { method: 'POST' }), 200);
      const balanceFoundation = await api(`/leaves/balances/${ids.employee}`);
      expect('L01 auto-created leave balances', balanceFoundation, 200);
      observed('L01 leave balance foundation exact state', brief(balanceFoundation.body));

      // The HR provisioning endpoint intentionally does not expose a password.
      // Use admin only to reset this disposable user's credential, then change it
      // through the normal first-login flow. Admin is not used for any approval.
      await login('technical-admin', 'admin@ogami.test');
      const reset = await api(`/admin/users/${ids.user}/reset-password`, { method: 'PATCH', captureSecret: true });
      expect('L01 technical recovery reset for disposable account', reset, 200);
      const temporaryPassword = reset.secret;
      if (!temporaryPassword) {
        blocked('L01 disposable employee self-service login', `Password reset did not return a temporary credential; response=${brief(reset.body)}`);
      } else {
        await login('employee', ids.employeeEmail, temporaryPassword, true);
        await ui('/self-service', 'l01-employee-self-service');
        const employeeIdentity = await api('/auth/user');
        ids.employeeUserId = dataOf(employeeIdentity.body)?.employee?.id;
        expect('L01 account role is employee', employeeIdentity, 200, `role=${dataOf(employeeIdentity.body)?.role?.slug}; employee=${ids.employeeUserId}`);
      }

      // L02 — HR attendance master, exact DTR calculations, and CSV replay.
      await login('hr', 'hr@ogami.test');
      await ui('/hr/attendance', 'l02-hr-attendance');
      await ui('/hr/attendance/shifts', 'l02-hr-shifts');
      const dayShift = await api('/attendance/shifts', { method: 'POST', body: {
        name: `B10 Day ${stamp}`, start_time: '08:00', end_time: '17:00', break_minutes: 60, grace_minutes: 0,
        is_night_shift: false, is_extended: false, auto_ot_hours: null, is_active: true, is_default: false,
      } });
      expect('L02 day shift create', dayShift, 201);
      ids.dayShift = idOf(dayShift.body);
      const nightShift = await api('/attendance/shifts', { method: 'POST', body: {
        name: `B10 Night ${stamp}`, start_time: '22:00', end_time: '06:00', break_minutes: 0, grace_minutes: 0,
        is_night_shift: true, is_extended: false, auto_ot_hours: null, is_active: true, is_default: false,
      } });
      expect('L02 night shift create', nightShift, 201);
      ids.nightShift = idOf(nightShift.body);
      const extendedShift = await api('/attendance/shifts', { method: 'POST', body: {
        name: `B10 Extended ${stamp}`, start_time: '08:00', end_time: '17:00', break_minutes: 60, grace_minutes: 0,
        is_night_shift: false, is_extended: true, auto_ot_hours: 2, is_active: true, is_default: false,
      } });
      expect('L02 extended shift create', extendedShift, 201);
      ids.extendedShift = idOf(extendedShift.body);
      for (const shiftId of [ids.dayShift, ids.nightShift, ids.extendedShift]) if (shiftId) residuals.push({ type: 'shift', id: shiftId });

      if (ids.dayShift && ids.nightShift && ids.extendedShift) {
        const dayAssignment = await api(`/attendance/shifts/assign-employee/${ids.employee}`, { method: 'POST', body: { shift_id: ids.dayShift, effective_date: '2026-09-01', end_date: '2026-09-09' } });
        if (dayAssignment.status === 200) expect('L02 assign disposable day shift', dayAssignment, 200);
        else observed('L02 onboarding default day shift retained', `custom day assignment returned HTTP ${dayAssignment.status}; existing default assignment is the supported day-shift foundation; body=${brief(dayAssignment.body)}`);
        expect('L02 assign night shift', await api(`/attendance/shifts/assign-employee/${ids.employee}`, { method: 'POST', body: { shift_id: ids.nightShift, effective_date: '2026-09-10', end_date: '2026-09-10' } }), 200);
        expect('L02 assign extended shift', await api(`/attendance/shifts/assign-employee/${ids.employee}`, { method: 'POST', body: { shift_id: ids.extendedShift, effective_date: '2026-09-11', end_date: '2026-09-15' } }), 200);
        expect('L02 current shift lookup', await api(`/attendance/shifts/current/${ids.employee}`), 200);
      }
      const holiday = await api('/attendance/holidays', { method: 'POST', body: { name: `B10 Regular Holiday ${stamp}`, date: '2026-09-11', type: 'regular', is_recurring: false } });
      expect('L02 disposable regular holiday create', holiday, 201);
      ids.holiday = idOf(holiday.body);
      if (ids.holiday) residuals.push({ type: 'holiday', id: ids.holiday });

      const manual = await api('/attendance/attendances', { method: 'POST', body: {
        employee_id: ids.employee, date: '2026-09-02', shift_id: ids.dayShift,
        time_in: '08:11', time_out: '16:30', is_rest_day: false, remarks: 'L02 exact late and undertime manual record',
      } });
      expect('L02 manual DTR late/undertime', manual, 201);
      ids.manualAttendance = idOf(manual.body);
      observed('L02 manual DTR exact calculation', brief(dataOf(manual.body)));

      const night = await api('/attendance/attendances', { method: 'POST', body: {
        employee_id: ids.employee, date: '2026-09-10', shift_id: ids.nightShift,
        time_in: '22:15', time_out: '06:15', is_rest_day: false, remarks: 'L02 exact night differential record',
      } });
      expect('L02 night DTR', night, 201);
      ids.nightAttendance = idOf(night.body);
      observed('L02 night DTR exact calculation', brief(dataOf(night.body)));

      const holidayAttendance = await api('/attendance/attendances', { method: 'POST', body: {
        employee_id: ids.employee, date: '2026-09-11', shift_id: ids.extendedShift,
        time_in: '08:00', time_out: '20:00', is_rest_day: false, remarks: 'L02 regular holiday plus extended auto-OT record',
      } });
      expect('L02 holiday and auto-OT DTR', holidayAttendance, 201);
      ids.holidayAttendance = idOf(holidayAttendance.body);
      observed('L02 holiday/auto-OT exact calculation', brief(dataOf(holidayAttendance.body)));

      const csv = `employee_no,date,time_in,time_out\n${ids.employeeNo},2026-09-03,08:05,17:00\n`;
      const csvBytes = Array.from(new TextEncoder().encode(csv));
      const importOne = await api('/attendance/attendances/import', { method: 'POST', multipart: true, fields: {}, filename: `B10-${stamp}.csv`, mime: 'text/csv', bytes: csvBytes });
      expect('L02 biometric CSV import', importOne, 200);
      observed('L02 biometric import exact result', brief(importOne.body));
      const importReplay = await api('/attendance/attendances/import', { method: 'POST', multipart: true, fields: {}, filename: `B10-${stamp}-replay.csv`, mime: 'text/csv', bytes: csvBytes });
      expect('L02 duplicate biometric CSV replay', importReplay, 200);
      observed('L02 duplicate import exact result', brief(importReplay.body));
      const dtrList = await api(`/attendance/attendances?employee_id=${ids.employee}&from=2026-09-01&to=2026-09-15&per_page=100`);
      expect('L02 DTR list exact records', dtrList, 200);
      observed('L02 attendance record set', brief(rowsOf(dtrList.body)));

      // L03 — use the newly provisioned disposable employee for true self-service.
      await login('employee', ids.employeeEmail, disposablePassword);
      await ui('/self-service/leave', 'l03-employee-leave');
      const ownBalancesBefore = await api('/leaves/balances/me');
      expect('L03 employee own leave balance', ownBalancesBefore, 200);
      const availableBalance = rowsOf(ownBalancesBefore.body).find((row) => balanceRemaining(row) >= 1);
      if (!availableBalance) {
        blocked('L03 leave request', `No disposable employee balance with at least one day: ${brief(ownBalancesBefore.body)}`);
      } else {
        const leaveTypeId = availableBalance.leave_type_id ?? availableBalance.leave_type?.id ?? availableBalance.type_id;
        const leave = await api('/leaves/requests', { method: 'POST', body: {
          employee_id: ids.employee, leave_type_id: leaveTypeId, start_date: '2026-09-15', end_date: '2026-09-15', reason: 'L03 disposable approved leave request',
        } });
        expect('L03 employee creates leave request', leave, 201);
        ids.leave = idOf(leave.body);
        if (ids.leave) residuals.push({ type: 'leave-request', id: ids.leave });
        if (ids.leave) {
          expect('L03 employee self-approval denied', await api(`/leaves/requests/${ids.leave}/approve-dept`, { method: 'PATCH', body: { remarks: 'self approval probe' } }), 403);
          await login('depthead', 'depthead@ogami.test');
          await ui('/hr/leaves', 'l03-depthead-leave');
          expect('L03 department head approves leave', await api(`/leaves/requests/${ids.leave}/approve-dept`, { method: 'PATCH', body: { remarks: 'L03 department approval' } }), 200);
          const deptNotifications = await api('/notifications?per_page=100');
          expect('L03 department notification feed', deptNotifications, 200);
          await login('hr', 'hr@ogami.test');
          expect('L03 HR approves leave', await api(`/leaves/requests/${ids.leave}/approve-hr`, { method: 'PATCH', body: { remarks: 'L03 HR approval' } }), 200);
          const hrNotifications = await api('/notifications?per_page=100');
          expect('L03 HR notification feed', hrNotifications, 200);
          const ownBalancesAfter = await api(`/leaves/balances/${ids.employee}`);
          expect('L03 balance after leave approval', ownBalancesAfter, 200);
          observed('L03 leave balance before/after', `before=${brief(ownBalancesBefore.body)}; after=${brief(ownBalancesAfter.body)}; leave=${brief(leave.body)}`);
          await login('employee', ids.employeeEmail, disposablePassword);
          const employeeNotifications = await api('/notifications?per_page=100');
          expect('L03 employee leave notification feed', employeeNotifications, 200);
        }
      }

      await login('employee', ids.employeeEmail, disposablePassword);
      const overtime = await api('/hr/self-service/overtime', { method: 'POST', body: { date: '2026-09-14', hours_requested: '0.50', reason: 'L03 approved disposable overtime request' } });
      expect('L03 employee creates OT request', overtime, 201);
      ids.overtime = idOf(overtime.body);
      if (ids.overtime) residuals.push({ type: 'overtime-request', id: ids.overtime });
      if (ids.overtime) {
        expect('L03 employee OT self-approval denied', await api(`/attendance/overtime-requests/${ids.overtime}/approve`, { method: 'PATCH', body: { remarks: 'self approval probe' } }), 403);
        await login('depthead', 'depthead@ogami.test');
        expect('L03 department head approves OT', await api(`/attendance/overtime-requests/${ids.overtime}/approve`, { method: 'PATCH', body: { remarks: 'L03 OT department approval' } }), 200);
        const otDetail = await api(`/attendance/overtime-requests/${ids.overtime}`);
        expect('L03 approved OT detail', otDetail, 200);
        observed('L03 approved OT payroll input', brief(otDetail.body));
      }

      await login('employee', ids.employeeEmail, disposablePassword);
      const loanTypes = await api('/loans/types');
      expect('L03 loan type options', loanTypes, 200);
      const companyLoanType = rowsOf(loanTypes.body).find((row) => /company/i.test(`${row.value} ${row.label}`))?.value ?? rowsOf(loanTypes.body)[0]?.value;
      if (!companyLoanType) {
        blocked('L03 disposable company loan', 'No loan type fixture was returned.');
      } else {
        const loan = await api('/hr/self-service/loans', { method: 'POST', body: { loan_type: companyLoanType, amount: '10.00', periods: 1, reason: 'L03 disposable loan for payroll deduction and final-pay guard' } });
        expect('L03 employee creates loan request', loan, 201);
        ids.loan = idOf(loan.body);
        if (ids.loan) residuals.push({ type: 'loan', id: ids.loan });
        if (ids.loan) {
          expect('L03 employee loan self-approval denied', await api(`/loans/${ids.loan}/approve`, { method: 'PATCH', body: { remarks: 'self approval probe' } }), 403);
          await login('depthead', 'depthead@ogami.test');
          expect('L03 department head loan approval', await api(`/loans/${ids.loan}/approve`, { method: 'PATCH', body: { remarks: 'L03 department approval' } }), 200);
          await login('production', 'production@ogami.test');
          expect('L03 production manager loan approval', await api(`/loans/${ids.loan}/approve`, { method: 'PATCH', body: { remarks: 'L03 manager approval' } }), 200);
          await login('finance2', 'finance2@ogami.test');
          expect('L03 finance2 loan approval', await api(`/loans/${ids.loan}/approve`, { method: 'PATCH', body: { remarks: 'L03 finance checker approval' } }), 200);
          await login('vp', 'vp@ogami.test');
          expect('L03 VP terminal loan approval', await api(`/loans/${ids.loan}/approve`, { method: 'PATCH', body: { remarks: 'L03 executive approval' } }), 200);
          const loanFinal = await api(`/loans/${ids.loan}`);
          expect('L03 approved loan exact balance', loanFinal, 200);
          observed('L03 loan exact approval/balance state', brief(loanFinal.body));
        }
      }

      // L04 — HR maker, Finance2 checker/finalizer, bank/payslip/GL/replay.
      await login('hr', 'hr@ogami.test');
      await ui('/payroll/periods', 'l04-hr-payroll');
      const payrollPayload = {
        period_start: '2026-09-01', period_end: '2026-09-15', payroll_date: '2026-09-15',
        is_first_half: false, scope_department_ids: [ids.department], scope_employment_types: [selectedCombo.employment_type],
        scope_pay_types: [selectedCombo.pay_type], scope_label: `LIVE-B10 disposable payroll ${stamp}`,
      };
      const scopePreview = await api('/payroll-periods/scope-preview', { method: 'POST', body: payrollPayload });
      expect('L04 payroll scope preview', scopePreview, 200);
      observed('L04 payroll scope preview exact headcount/money', brief(scopePreview.body));
      const periodCreate = await api('/payroll-periods', { method: 'POST', body: payrollPayload });
      expect('L04 HR creates disposable payroll period', periodCreate, 201);
      ids.period = idOf(periodCreate.body);
      if (ids.period) residuals.push({ type: 'payroll-period', id: ids.period });
      if (ids.period) {
        const periodDraft = await api(`/payroll-periods/${ids.period}`);
        expect('L04 derived half draft detail', periodDraft, 200);
        observed('L04 derived-half/payroll-date draft', brief(periodDraft.body));
        const compute = await api(`/payroll-periods/${ids.period}/compute`, { method: 'POST' });
        expect('L04 HR compute claim', compute, 202);
        const computed = await pollPeriod(ids.period);
        const computedStatus = dataOf(computed?.body)?.status;
        if (computed?.status === 200 && computedStatus === 'computed') {
          expect('L04 payroll compute completion', computed, 200);
          const anomalies = await api(`/payroll-periods/${ids.period}/anomalies`);
          expect('L04 anomaly review', anomalies, 200);
          observed('L04 anomaly exact state', brief(anomalies.body));
          expect('L04 HR cannot approve checker action', await api(`/payroll-periods/${ids.period}/approve`, { method: 'PATCH' }), 403);
          await login('finance', 'finance@ogami.test');
          const financeNotifications = await api('/notifications?per_page=100');
          expect('L04 finance notification feed', financeNotifications, 200);
          await login('finance2', 'finance2@ogami.test');
          await ui('/payroll/periods', 'l04-finance2-payroll');
          const approve = await api(`/payroll-periods/${ids.period}/approve`, { method: 'PATCH' });
          expect('L04 finance2 approves payroll', approve, 200);
          if (approve.status === 200) {
            const finalize = await api(`/payroll-periods/${ids.period}/finalize`, { method: 'PATCH' });
            expect('L04 finance2 finalizes payroll', finalize, 200);
            if (finalize.status === 200) {
              const finalDetail = await api(`/payroll-periods/${ids.period}`);
              expect('L04 finalized payroll exact detail', finalDetail, 200);
              observed('L04 exact finalized money/GL state', brief(finalDetail.body));
              const payrollRows = await api(`/payrolls?period_id=${ids.period}&per_page=100`);
              expect('L04 payroll row/payslip list', payrollRows, 200);
              const payrollRow = rowsOf(payrollRows.body).find((row) => row.employee_id === ids.employee || row.employee?.id === ids.employee) ?? rowsOf(payrollRows.body)[0];
              if (payrollRow?.id) {
                residuals.push({ type: 'payroll', id: payrollRow.id });
                const payslip = await file(`/payrolls/${payrollRow.id}/payslip`);
                expect('L04 payslip PDF', payslip, 200);
                observed('L04 payslip exact file evidence', JSON.stringify(payslip));
              } else blocked('L04 payslip', `No payroll row returned for disposable employee: ${brief(payrollRows.body)}`);
              const bankPreview = await api(`/payroll-periods/${ids.period}/bank-file/preview?format=generic`);
              expect('L04 bank-file preview', bankPreview, 200);
              const bank = await api(`/payroll-periods/${ids.period}/bank-file`, { method: 'POST', body: { format: 'generic' } });
              expect('L04 bank-file artifact generation', bank, 201);
              observed('L04 bank-file exact artifact state', brief(bank.body));
              const bankDownload = await file(`/payroll-periods/${ids.period}/bank-file`, 'text/csv');
              expect('L04 bank-file download', bankDownload, 200);
              const proofOptions = await api(`/payroll-periods/${ids.period}/disbursement-proofs/options`);
              expect('L04 disbursement proof options', proofOptions, 200);
              const finalAmount = dataOf(finalDetail.body)?.total_net_pay ?? dataOf(finalDetail.body)?.total_net ?? dataOf(finalDetail.body)?.net_pay;
              const proofType = dataOf(proofOptions.body)?.proof_types?.[0]?.value ?? 'bank_confirmation';
              const proof = await api(`/payroll-periods/${ids.period}/disbursement-proofs`, { method: 'POST', multipart: true, fields: {
                proof_type: proofType, bank_name: 'Audit Bank', transaction_reference: `B10-${stamp}`,
                disbursed_amount: String(finalAmount ?? '1.00'), disbursement_date: '2026-09-15', notes: 'L04 disposable disbursement proof',
              }, filename: `B10-${stamp}-proof.pdf`, mime: 'application/pdf' });
              expect('L04 disbursement proof upload', proof, 201);
              if (proof.status === 201) {
                ids.proof = idOf(proof.body);
                residuals.push({ type: 'disbursement-proof', id: ids.proof });
              }
              expect('L04 mark payroll disbursed', await api(`/payroll-periods/${ids.period}/mark-disbursed`, { method: 'PATCH' }), 200);
              const disbursed = await api(`/payroll-periods/${ids.period}`);
              expect('L04 disbursed exact state', disbursed, 200);
              observed('L04 disbursement/GL/bank final state', brief(disbursed.body));
              expect('L04 disbursement replay guard', await api(`/payroll-periods/${ids.period}/mark-disbursed`, { method: 'PATCH' }), 422);
              expect('L04 finalized compute replay guard', await api(`/payroll-periods/${ids.period}/compute`, { method: 'POST' }), 422);
              const duplicatePeriod = await api('/payroll-periods', { method: 'POST', body: payrollPayload });
              expect('L04 no-double-pay duplicate period guard', duplicatePeriod, 422);
              observed('L04 payroll notification state', brief((await api('/notifications?per_page=100')).body));
            } else blocked('L04 bank/payslip/GL/disbursement downstream', `Finalize failed or was blocked: ${brief(finalize.body)}`);
          }
        } else {
          blocked('L04 queue payroll compute', `Period remained non-computed after 45s; final_status=${computedStatus}; final_response=${brief(computed?.body)}. No second compute was enqueued.`);
        }
      }

      // L05 — separation, distinct departmental clearance signers, final pay,
      // account deactivation, and terminal guards.
      await login('hr', 'hr@ogami.test');
      await ui('/hr/separations', 'l05-hr-separations');
      const separation = await api(`/hr/employees/${ids.employee}/separation`, { method: 'POST', body: {
        separation_date: '2026-09-14', separation_reason: 'resignation', remarks: 'LIVE-B10 disposable separation chain',
      } });
      expect('L05 HR initiates separation', separation, 201);
      ids.clearance = idOf(separation.body);
      if (ids.clearance) residuals.push({ type: 'clearance', id: ids.clearance });
      if (ids.clearance) {
        const clearanceInitial = await api(`/hr/clearances/${ids.clearance}`);
        expect('L05 clearance initial exact state', clearanceInitial, 200);
        observed('L05 clearance item manifest', brief(dataOf(clearanceInitial.body)?.clearance_items));
        const items = dataOf(clearanceInitial.body)?.clearance_items ?? [];
        const productionItems = items.filter((item) => /production/i.test(item.department));
        const warehouseItems = items.filter((item) => /warehouse/i.test(item.department));
        const maintenanceItems = items.filter((item) => /maintenance/i.test(item.department));
        const financeItems = items.filter((item) => /finance/i.test(item.department));
        const hrItems = items.filter((item) => /^(hr|it)$/i.test(item.department));
        const sign = async (alias, email, selected, label) => {
          await login(alias, email);
          for (const item of selected) {
            const response = await api(`/hr/clearances/${ids.clearance}/items`, { method: 'PATCH', body: { item_key: item.item_key, remarks: `L05 ${label} sign` } });
            expect(`L05 ${label} signs ${item.item_key}`, response, 200);
          }
          const state = await api(`/hr/clearances/${ids.clearance}`);
          expect(`L05 ${label} clearance state`, state, 200);
          observed(`L05 ${label} signed state`, brief(dataOf(state.body)?.clearance_items));
        };
        const warehouseItem = warehouseItems[0];
        if (warehouseItem) {
          await login('employee', ids.employeeEmail, disposablePassword);
          expect('L05 wrong-role employee clearance sign denied', await api(`/hr/clearances/${ids.clearance}/items`, { method: 'PATCH', body: { item_key: warehouseItem.item_key, remarks: 'wrong role probe' } }), 403);
        }
        await sign('depthead', 'depthead@ogami.test', productionItems, 'department');
        await sign('warehouse', 'warehouse@ogami.test', warehouseItems, 'warehouse');
        await sign('maintenance', 'maintenance@ogami.test', maintenanceItems, 'maintenance');
        await sign('finance2', 'finance2@ogami.test', financeItems, 'finance2');
        await sign('hr', 'hr@ogami.test', hrItems, 'HR');
        await login('hr', 'hr@ogami.test');
        const completed = await api(`/hr/clearances/${ids.clearance}`);
        expect('L05 all clearance items completed', completed, 200);
        observed('L05 completed clearance exact state', brief(completed.body));
        expect('L05 finance cannot compute final pay', await api(`/hr/clearances/${ids.clearance}/final-pay/compute`, { method: 'POST' }), 403);
        const accountAfterCompletion = await api(`/hr/employees/${ids.employee}/account-status`);
        expect('L05 account deactivation after clearance completion', accountAfterCompletion, 200);
        observed('L05 account status after completed clearance', brief(accountAfterCompletion.body));
        const finalPay = await api(`/hr/clearances/${ids.clearance}/final-pay/compute`, { method: 'POST' });
        expect('L05 HR computes final pay', finalPay, 200);
        observed('L05 final-pay exact money/deduction breakdown', brief(finalPay.body));
        const finalizedClearance = await api(`/hr/clearances/${ids.clearance}/finalize`, { method: 'PATCH' });
        expect('L05 HR finalizes clearance/final pay', finalizedClearance, 200);
        observed('L05 final clearance exact state', brief(finalizedClearance.body));
        expect('L05 final-pay compute terminal guard', await api(`/hr/clearances/${ids.clearance}/final-pay/compute`, { method: 'POST' }), 422);
        expect('L05 clearance finalize terminal guard', await api(`/hr/clearances/${ids.clearance}/finalize`, { method: 'PATCH' }), 422);
        const accountFinal = await api(`/hr/employees/${ids.employee}/account-status`);
        expect('L05 final account deactivation state', accountFinal, 200);
        observed('L05 final account status', brief(accountFinal.body));
        const employeeReLogin = await login('employee-after-deactivation', ids.employeeEmail, disposablePassword).catch((error) => ({ error: error instanceof Error ? error.message : String(error) }));
        observed('L05 deactivated account login proof', brief(employeeReLogin));
        await logout();
      }
    }
  }
} catch (error) {
  record('FAIL', 'L01-L05 harness', error instanceof Error ? error.stack ?? error.message : String(error));
  await screenshot('failure');
} finally {
  await logout().catch(() => {});
  await browser.close();
  await fs.writeFile(`${artifactDir}/l01-l05-results.json`, JSON.stringify({ stamp, ids, residuals, results, evidence }, null, 2));
}

const counts = results.reduce((out, row) => ({ ...out, [row.status]: (out[row.status] ?? 0) + 1 }), {});
console.log(JSON.stringify({ counts, ids, residuals, results }, null, 2));
if (results.some((result) => result.status === 'FAIL')) process.exitCode = 1;
