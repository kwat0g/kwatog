import { expect, type Page, type Request, type Response } from '@playwright/test';

/**
 * Shared helpers for real-backend E2E (no mocks allowed).
 *
 * Auth is Sanctum SPA mode with HTTP-only cookies:
 *   1. GET /sanctum/csrf-cookie  -> XSRF-TOKEN cookie
 *   2. POST /api/v1/auth/sign-in -> session cookie (httpOnly)
 * The browser context carries cookies automatically afterwards.
 */

/** Base + stateful headers. Sanctum's EnsureFrontendRequestsAreStateful only
 *  starts a session when Origin/Referer matches a stateful domain — bare
 *  API clients (curl, page.request) without them get 401/500. Real browsers
 *  always send these, so the harness must too. */
export function baseUrl(): string {
  const base = process.env.E2E_BASE_URL;
  if (!base) throw new Error('E2E_BASE_URL must point to the isolated test application.');
  return base.replace(/\/$/, '');
}

export function statefulHeaders(): Record<string, string> {
  const base = baseUrl();
  return { Referer: `${base}/`, Origin: base, Accept: 'application/json' };
}

export async function statefulCsrfHeaders(page: Page): Promise<Record<string, string>> {
  const xsrf = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN');
  if (!xsrf) throw new Error('XSRF-TOKEN cookie is missing.');
  return { ...statefulHeaders(), 'X-XSRF-TOKEN': decodeURIComponent(xsrf.value) };
}

export async function assignCrewThroughUi(
  page: Page,
  workOrderId: string,
  actors: Page[],
  options: { reworkNcrId?: string },
) {
  const crewQuery = options.reworkNcrId ? `?rework_ncr_id=${encodeURIComponent(options.reworkNcrId)}` : '';
  const crewUrl = `${baseUrl()}/api/v1/production/work-orders/${workOrderId}/crew${crewQuery}`;
  const beforeResponse = await page.request.get(crewUrl, { headers: statefulHeaders() });
  expect(beforeResponse.status()).toBe(200);
  const before = (await beforeResponse.json()).data;
  expect(before.current).toBeNull();
  expect(actors.length).toBeGreaterThanOrEqual(before.minimum_workers);
  if (before.maximum_workers !== null) expect(actors.length).toBeLessThanOrEqual(before.maximum_workers);

  const employees = await Promise.all(actors.map(async (actor) => {
    const response = await actor.request.get(`${baseUrl()}/api/v1/auth/user`, { headers: statefulHeaders() });
    expect(response.status()).toBe(200);
    const employee = (await response.json()).data?.employee;
    expect(employee?.id).toBeTruthy();
    expect(employee?.employee_no).toBeTruthy();
    expect(employee?.full_name).toBeTruthy();
    return employee as { id: string; employee_no: string; full_name: string };
  }));

  await page.goto(`/production/work-orders/${workOrderId}`, { waitUntil: 'domcontentloaded' });
  const panelName = options.reworkNcrId ? 'Repair crew' : 'Production crew';
  const manage = options.reworkNcrId
    ? page.getByRole('heading', { name: panelName, exact: true }).locator('xpath=../../..')
        .getByRole('button', { name: 'Assign workers', exact: true })
    : page.getByRole('button', { name: 'Assign workers', exact: true }).first();
  await manage.click();
  const dialog = page.getByRole('dialog');
  await expect(dialog.getByRole('heading', {
    name: options.reworkNcrId ? 'Assign repair workers' : 'Assign workers',
  })).toBeVisible();

  await expect(dialog.getByLabel('Number of workers')).toHaveCount(0);

  for (const employee of employees) {
    const search = dialog.getByRole('combobox', { name: 'Add worker' });
    const optionsResponse = page.waitForResponse((response) => {
      const url = new URL(response.url());
      return url.pathname.endsWith(`/production/work-orders/${workOrderId}/crew-options`)
        && url.searchParams.get('search') === employee.employee_no
        && url.searchParams.get('rework_ncr_id') === (options.reworkNcrId ?? null);
    });
    await search.fill(employee.employee_no);
    const response = await optionsResponse;
    expect(response.status()).toBe(200);
    const available = (await response.json()).data as Array<{ id: string; has_active_account: boolean }>;
    expect(available).toContainEqual(expect.objectContaining({ id: employee.id, has_active_account: true }));
    await page.getByRole('option', {
      name: `${employee.employee_no} · ${employee.full_name}`,
      exact: true,
    }).click();
  }

  await dialog.getByLabel('Output recorder').selectOption(employees[0].id);
  await expect(dialog.getByLabel('Handover reason')).toHaveCount(0);
  const saved = page.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/production/work-orders/${workOrderId}/crew`)
    && response.request().method() === 'POST',
  );
  await dialog.getByRole('button', { name: 'Save workers', exact: true }).click();
  const response = await saved;
  expect(response.status(), await response.text()).toBe(200);
  await expect(dialog).toHaveCount(0);

  const afterResponse = await page.request.get(crewUrl, { headers: statefulHeaders() });
  expect(afterResponse.status()).toBe(200);
  const after = (await afterResponse.json()).data;
  expect(after.current.members.map((member: { id: string }) => member.id).sort()).toEqual(employees.map((employee) => employee.id).sort());
  expect(after.current.recorder_employee_id).toBe(employees[0].id);
  expect(after.current.reason).toBe('Initial crew assignment');
  expect(after.history).toHaveLength(before.history.length + 1);
  return after;
}

export async function navigateSpa(page: Page, route: string): Promise<void> {
  await page.evaluate(async (nextRoute) => {
    const previousMain = document.querySelector('main');
    const previousMarkup = previousMain?.innerHTML;
    await new Promise<void>((resolve) => {
      let timeout: number;
      const observer = new MutationObserver(() => {
        const currentMain = document.querySelector('main');
        if (currentMain === previousMain && currentMain?.innerHTML === previousMarkup) return;
        observer.disconnect();
        window.clearTimeout(timeout);
        window.requestAnimationFrame(() => window.requestAnimationFrame(() => resolve()));
      });
      observer.observe(document.body, { childList: true, subtree: true, characterData: true });
      window.history.pushState({}, '', nextRoute);
      window.dispatchEvent(new PopStateEvent('popstate'));
      // ponytail: identical not-found views fall through at 500 ms; add a router path marker if exact route commits matter.
      timeout = window.setTimeout(() => {
        observer.disconnect();
        resolve();
      }, 500);
    });
  }, route);
}

export const ROLES: Array<{ slug: string; email: string; password: string }> = [
  { slug: 'system_admin', email: 'admin@ogami.test', password: 'password' },
  { slug: 'vice_president', email: 'vp@ogami.test', password: 'password' },
  { slug: 'hr_officer', email: 'hr@ogami.test', password: 'password' },
  { slug: 'finance_officer', email: 'finance@ogami.test', password: 'password' },
  { slug: 'production_manager', email: 'production@ogami.test', password: 'password' },
  { slug: 'ppc_head', email: 'ppc@ogami.test', password: 'password' },
  { slug: 'purchasing_officer', email: 'purchasing@ogami.test', password: 'password' },
  { slug: 'warehouse_staff', email: 'warehouse@ogami.test', password: 'password' },
  { slug: 'qc_inspector', email: 'qc@ogami.test', password: 'password' },
  { slug: 'maintenance_tech', email: 'maintenance@ogami.test', password: 'password' },
  { slug: 'impex_officer', email: 'impex@ogami.test', password: 'password' },
  { slug: 'department_head', email: 'depthead@ogami.test', password: 'password' },
  { slug: 'sales_officer', email: 'crm@ogami.test', password: 'password' },
  { slug: 'customer_service_officer', email: 'customerservice@ogami.test', password: 'password' },
  { slug: 'driver', email: 'driver@ogami.test', password: 'password' },
  { slug: 'employee', email: 'employee@ogami.test', password: 'password' },
];

export interface NetworkCapture {
  errors: string[];
  failedRequests: Array<{ url: string; method: string; status: number }>;
  requestFailures: Array<{ url: string; method: string; error: string }>;
  waitForApiIdle: (quietMs?: number, timeoutMs?: number) => Promise<void>;
  stop: () => void;
}

/** Console errors minus known-benign noise (see below). */
export function relevantErrors(net: NetworkCapture): string[] {
  return net.errors.filter((e) => {
    // Browser-generated noise: the signed-out session probe GETs /auth/user
    // and 401s before login. Expected, no user impact, unsuppressible via JS.
    if (e.includes('401') && e.includes('Unauthorized')) return false;
    return true;
  });
}
/** Collect browser errors, failed requests, and failed API responses before navigation. */
export function captureNetwork(page: Page): NetworkCapture {
  const errors: string[] = [];
  const failedRequests: NetworkCapture['failedRequests'] = [];
  const requestFailures: NetworkCapture['requestFailures'] = [];
  const pendingApi = new Set<Request>();
  let lastApiActivity = Date.now();

  const onConsole = (msg: { type: () => string; text: () => string }) => {
    if (msg.type() === 'error') errors.push(msg.text().slice(0, 500));
  };
  const onPageError = (error: Error) => errors.push(`Page error: ${error.message.slice(0, 500)}`);
  const onResponse = (res: Response) => {
    const url = res.url();
    if (url.includes('/api/v1/')) onRequestDone(res.request());
    if (res.status() >= 400) {
      const req: Request = res.request();
      failedRequests.push({ url: url.slice(0, 200), method: req.method(), status: res.status() });
    }
  };
  const onRequest = (request: Request) => {
    if (!request.url().includes('/api/v1/')) return;
    pendingApi.add(request);
    lastApiActivity = Date.now();
  };
  const onRequestDone = (request: Request) => {
    if (pendingApi.delete(request)) lastApiActivity = Date.now();
  };
  const onRequestFailed = (request: Request) => {
    const failure = request.failure();
    requestFailures.push({
      url: request.url().slice(0, 200),
      method: request.method(),
      error: failure?.errorText ?? 'Request failed',
    });
    onRequestDone(request);
  };
  page.on('console', onConsole as never);
  page.on('pageerror', onPageError);
  page.on('response', onResponse);
  page.on('request', onRequest);
  page.on('requestfinished', onRequestDone);
  page.on('requestfailed', onRequestFailed);
  return {
    errors,
    failedRequests,
    requestFailures,
    waitForApiIdle: async (quietMs = 500, timeoutMs = 15_000) => {
      const deadline = Date.now() + timeoutMs;
      while (pendingApi.size > 0 || Date.now() - lastApiActivity < quietMs) {
        if (Date.now() >= deadline) {
          const pending = [...pendingApi].map((request) => `${request.method()} ${request.url()}`).slice(0, 5);
          throw new Error(
            `API requests did not settle within ${timeoutMs} ms${pending.length ? `: ${pending.join(', ')}` : ''}`,
          );
        }
        await sleep(50);
      }
    },
    stop: () => {
      page.off('console', onConsole as never);
      page.off('pageerror', onPageError);
      page.off('response', onResponse);
      page.off('request', onRequest);
      page.off('requestfinished', onRequestDone);
      page.off('requestfailed', onRequestFailed);
    },
  };
}

async function sleep(ms: number): Promise<void> {
  await new Promise((r) => setTimeout(r, ms));
}

/**
 * Log in via the real API inside the browser context (cookies land in the
 * context, so subsequent page.goto() calls are authenticated).
 * Honors the auth rate limiter (5/min/IP): backs off on 429 using Retry-After.
 */
export async function loginAs(
  page: Page,
  email: string,
  password: string,
): Promise<void> {
  const base = baseUrl();
  for (let attempt = 0; attempt < 8; attempt += 1) {
    await page.request.get(`${base}/sanctum/csrf-cookie`, { headers: statefulHeaders() });
    const cookies = await page.context().cookies();
    const xsrf = cookies.find((c) => c.name === 'XSRF-TOKEN');
    const postHeaders: Record<string, string> = { ...statefulHeaders() };
    if (xsrf) postHeaders['X-XSRF-TOKEN'] = decodeURIComponent(xsrf.value);
    const res = await page.request.post(`${base}/api/v1/auth/sign-in`, {
      headers: postHeaders,
      data: { email, password },
    });
    if (res.status() === 429 || res.status() === 423) {
      const retryAfter = Number(res.headers()['retry-after'] ?? 15);
      await sleep((Number.isFinite(retryAfter) ? retryAfter : 15) * 1000 + 500);
      continue;
    }
    expect(res.ok(), `sign-in failed for ${email}: ${res.status()} ${await res.text()}`).toBe(true);
    return;
  }
  throw new Error(`sign-in rate-limited for ${email} after 8 attempts`);
}

/** UI-driven login (exercises the real sign-in form). Use sparingly (rate limit). */
export async function loginViaUi(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/sign-in');
  await page.getByRole('textbox', { name: 'Email' }).fill(email);
  await page.getByRole('textbox', { name: 'Password' }).fill(password);
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).not.toHaveURL(/sign-in/, { timeout: 20_000 });
}
