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
  return process.env.E2E_BASE_URL ?? 'http://localhost';
}

export function statefulHeaders(): Record<string, string> {
  const base = baseUrl();
  return { Referer: `${base}/`, Origin: base, Accept: 'application/json' };
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
/** Collect console errors + failed API responses for a page. Attach BEFORE navigation. */
export function captureNetwork(page: Page): NetworkCapture {
  const errors: string[] = [];
  const failedRequests: NetworkCapture['failedRequests'] = [];

  const onConsole = (msg: { type: () => string; text: () => string }) => {
    if (msg.type() === 'error') errors.push(msg.text().slice(0, 500));
  };
  const onResponse = (res: Response) => {
    const url = res.url();
    if (!url.includes('/api/')) return;
    if (res.status() >= 400) {
      const req: Request = res.request();
      failedRequests.push({ url: url.slice(0, 200), method: req.method(), status: res.status() });
    }
  };
  page.on('console', onConsole as never);
  page.on('response', onResponse);
  return {
    errors,
    failedRequests,
    stop: () => {
      page.off('console', onConsole as never);
      page.off('response', onResponse);
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
    // eslint-disable-next-line no-await-in-loop
    await page.request.get(`${base}/sanctum/csrf-cookie`, { headers: statefulHeaders() });
    // eslint-disable-next-line no-await-in-loop
    const cookies = await page.context().cookies();
    const xsrf = cookies.find((c) => c.name === 'XSRF-TOKEN');
    const postHeaders: Record<string, string> = { ...statefulHeaders() };
    if (xsrf) postHeaders['X-XSRF-TOKEN'] = decodeURIComponent(xsrf.value);
    // eslint-disable-next-line no-await-in-loop
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
