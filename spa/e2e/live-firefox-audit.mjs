import { firefox } from 'playwright';

const baseURL = 'http://localhost';
const password = 'password';
const browser = await firefox.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await context.newPage();
const results = [];

function record(name, status, detail = '') {
  results.push({ name, status, detail });
  console.log(`${status === 'PASS' ? 'PASS' : 'FAIL'} ${name}${detail ? `: ${detail}` : ''}`);
}

async function screenshot(name) {
  await page.screenshot({
    path: `test-results/live-${name}.png`,
    fullPage: true,
  }).catch(() => {});
}

async function api(path, options = {}) {
  return page.evaluate(async ({ path, options }) => {
    const token = document.cookie
      .split('; ')
      .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
      ?.slice('XSRF-TOKEN='.length);
    const headers = {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}),
      ...(options.body ? { 'Content-Type': 'application/json' } : {}),
      ...(options.headers ?? {}),
    };
    const response = await fetch(path, {
      ...options,
      headers,
      credentials: 'include',
      body: options.body ? JSON.stringify(options.body) : undefined,
    });
    const text = await response.text();
    let body = null;
    try {
      body = JSON.parse(text);
    } catch {
      body = text;
    }
    return { status: response.status, body };
  }, { path, options });
}

async function logout() {
  await api('/api/v1/auth/logout', { method: 'POST' });
  await context.clearCookies();
}

async function login(email) {
  await page.goto(`${baseURL}/login`, { waitUntil: 'domcontentloaded' });
  await page.locator('input[type="email"], input[name="email"]').first().fill(email);
  await page.locator('input[type="password"]').first().fill(password);
  await page.getByRole('button', { name: /sign in|log in/i }).click();
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 15_000 });
  const identity = await api('/api/v1/auth/user');
  if (identity.status !== 200) {
    throw new Error(`${email} identity returned ${identity.status}`);
  }
}

async function goto(path, name) {
  await page.goto(`${baseURL}${path}`, { waitUntil: 'domcontentloaded' });
  await page.locator('#main-content, main, body').first().waitFor({ state: 'visible', timeout: 10_000 });
  await page.waitForFunction(() => document.body.innerText.trim().length > 100, null, { timeout: 15_000 });
  const text = await page.locator('body').innerText();
  if (!text.trim()) throw new Error(`${name} rendered an empty body`);
  return text;
}

async function expectStatus(name, response, expected) {
  if (response.status !== expected) {
    throw new Error(`${name} returned ${response.status}, expected ${expected}`);
  }
  record(name, 'PASS', `HTTP ${response.status}`);
}

try {
  // System administrator sanity check: this is the only unrestricted-account test.
  await login('admin@ogami.test');
  for (const path of ['/admin/users', '/hr/employees', '/accounting/journal-entries', '/production/work-orders', '/quality/inspections']) {
    const text = await goto(path, `admin ${path}`);
    if (/page not found|not found/i.test(text)) throw new Error(`admin could not reach ${path}`);
    record(`admin reaches ${path}`, 'PASS');
  }
  await expectStatus('admin employee API', await api('/api/v1/hr/employees'), 200);

  await logout();
  await login('employee@ogami.test');
  await expectStatus('employee cannot read employees API', await api('/api/v1/hr/employees'), 403);
  await expectStatus('employee cannot read admin users API', await api('/api/v1/admin/users'), 403);
  const deniedText = await goto('/hr/employees', 'employee denied HR page');
  if (!/not found|forbidden|permission|access/i.test(deniedText)) {
    throw new Error('employee denied page did not expose a denied/not-found state');
  }
  record('employee denied HR page', 'PASS');
  const selfServiceText = await goto('/self-service/leave', 'employee self-service leave');
  if (!/my leave requests/i.test(selfServiceText)) throw new Error('self-service leave page did not load');
  record('employee reaches self-service leave', 'PASS');

  const beforeBalances = await api('/api/v1/leaves/balances/me');
  await page.getByRole('button', { name: /new request/i }).first().click();
  await page.getByRole('button', { name: /submit request/i }).click();
  if (!await page.getByText(/select a leave type|required/i).first().isVisible()) {
    throw new Error('empty leave submit did not show field validation');
  }
  record('leave invalid submit shows field error', 'PASS');

  const leaveType = page.getByLabel('Leave type');
  await leaveType.selectOption({ label: 'Service Incentive Leave' });
  await page.getByLabel('Start date').fill('2026-09-18');
  await page.getByLabel('End date').fill('2026-09-18');
  await page.getByLabel(/reason/i).fill('Live Firefox audit');
  const createResponse = page.waitForResponse((response) =>
    response.url().includes('/api/v1/leaves/requests') && response.request().method() === 'POST',
  );
  await page.getByRole('button', { name: /submit request/i }).click();
  const createResult = await createResponse;
  const created = await createResult.json();
  const leaveId = created?.data?.id;
  if (!leaveId) throw new Error(`leave create returned HTTP ${createResult.status()}: ${JSON.stringify(created)}`);
  const createdDetail = await api(`/api/v1/leaves/requests/${leaveId}`);
  await expectStatus('created leave detail', createdDetail, 200);
  if (createdDetail.body?.data?.status !== 'pending_dept') {
    throw new Error(`new leave detail status was ${createdDetail.body?.data?.status ?? 'missing'}`);
  }
  record('employee creates leave request', 'PASS', `${leaveId} pending_dept`);

  await logout();
  await login('depthead@ogami.test');
  await expectStatus('department head cannot take HR approval step', await api(`/api/v1/leaves/requests/${leaveId}/approve-hr`, { method: 'PATCH' }), 403);
  await goto(`/hr/leaves/${leaveId}`, 'department head leave detail');
  await page.getByText('Request details', { exact: true }).waitFor({ state: 'visible', timeout: 15_000 });
  const approveButtons = page.getByRole('button', { name: 'Approve', exact: true });
  if (await approveButtons.count() === 0) throw new Error('department head has no department approval action');
  await approveButtons.first().click();
  await page.getByRole('button', { name: 'Approve', exact: true }).last().click();
  await page.getByText(/pending hr/i).waitFor({ state: 'visible', timeout: 10_000 });
  record('department head approves leave', 'PASS', 'pending_hr');

  const notifications = await api('/api/v1/notifications?per_page=50');
  if (notifications.status === 200) {
    record('department head notification feed responds', 'PASS');
  } else {
    record('department head notification feed responds', 'FAIL', `HTTP ${notifications.status}`);
  }

  await logout();
  await login('hr@ogami.test');
  await goto(`/hr/leaves/${leaveId}`, 'HR leave detail');
  await page.getByText('Request details', { exact: true }).waitFor({ state: 'visible', timeout: 15_000 });
  const hrApprove = page.getByRole('button', { name: 'Approve', exact: true });
  if (await hrApprove.count() === 0) throw new Error('HR has no HR approval action');
  await hrApprove.first().click();
  await page.getByRole('button', { name: 'Approve', exact: true }).last().click();
  await page.getByText(/approved/i).first().waitFor({ state: 'visible', timeout: 10_000 });
  record('HR approves leave', 'PASS', 'approved');

  await logout();
  await login('employee@ogami.test');
  const afterBalances = await api('/api/v1/leaves/balances/me');
  await expectStatus('employee balance after leave approval', afterBalances, 200);
  if (beforeBalances.status === 200 && afterBalances.status === 200) {
    const before = JSON.stringify(beforeBalances.body);
    const after = JSON.stringify(afterBalances.body);
    if (before === after) throw new Error('leave approval did not change the employee balance response');
    record('leave balance changes after approval', 'PASS');
  }
  const selfServiceList = await api('/api/v1/hr/self-service/leave-requests?per_page=50');
  await expectStatus('employee self-service leave list after approval', selfServiceList, 200);
  const listed = selfServiceList.body?.data?.some((request) => request.id === leaveId);
  if (!listed) {
    await goto('/self-service/leave', 'employee refreshed self-service leave');
    await screenshot('approved-leave-missing-from-list');
    record('approved leave appears in self-service list', 'FAIL', `${leaveId} absent from API list`);
  } else {
    record('approved leave appears in self-service list', 'PASS');
  }
} catch (error) {
  await screenshot('failure');
  record('live audit', 'FAIL', error instanceof Error ? error.message : String(error));
} finally {
  await logout().catch(() => {});
  await browser.close();
}

if (results.some((result) => result.status === 'FAIL')) process.exitCode = 1;
