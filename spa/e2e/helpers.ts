/**
 * Shared Playwright E2E helpers for dashboard forecast panel tests.
 *
 * Reduces route-mocking duplication across dashboard test files by providing:
 * - Pre-built user fixtures for each role
 * - `mockAuth()` — mocks Sanctum CSRF + user endpoint
 * - `mockDashboardApi()` — mocks a dashboard endpoint with optional status/delay
 * - `setupDashboard()` — combines auth + dashboard API mocking + navigation
 *
 * Usage in a spec file:
 *
 *   import { test, expect } from '@playwright/test';
 *   import { USERS, setupDashboard } from '../helpers';
 *
 *   test('renders forecast', async ({ page }) => {
 *     await setupDashboard(page, '/api/v1/dashboards/hr', USERS.hr, makeData());
 *     await expect(page.getByText('Headcount Forecast')).toBeVisible();
 *   });
 *
 *   test('shows loading', async ({ page }) => {
 *     // Use mockAuth directly and leave dashboard route unhandled (never resolves).
 *     await mockAuth(page, USERS.hr);
 *     await page.goto('/dashboard/hr');
 *     await expect(page.locator('.animate-pulse').first()).toBeVisible();
 *   });
 */
import type { Page } from '@playwright/test';

// ── User Fixtures ─────────────────────────────────────────────────────────

export interface MockUser {
  id: string;
  name: string;
  email: string;
  roleSlug: string;
  roleName: string;
  permissions: string[];
  employee?: { id: string; employee_no: string } | null;
  features?: string[];
}

export const USERS: Record<string, MockUser> = {
  hr: {
    id: 'u1', name: 'HR Officer', email: 'hr@ogami.test',
    roleSlug: 'hr_officer', roleName: 'HR Officer',
    permissions: ['dashboard.hr.view'],
    employee: { id: 'e1', employee_no: 'EMP001' },
  },
  finance: {
    id: 'u2', name: 'Finance Officer', email: 'finance@ogami.test',
    roleSlug: 'finance_officer', roleName: 'Finance Officer',
    permissions: ['accounting.dashboard.view'],
    employee: null,
  },
  qc: {
    id: 'u3', name: 'QC Inspector', email: 'qc@ogami.test',
    roleSlug: 'qc_inspector', roleName: 'QC Inspector',
    permissions: ['dashboard.quality.view'],
    employee: null,
  },
};

// ── Auth Mocking ──────────────────────────────────────────────────────────

/**
 * Mock Sanctum CSRF cookie + `/api/v1/user` endpoint for the given user.
 *
 * Call this BEFORE `page.goto()` when you need full control over API mocking
 * (e.g. for loading state tests where the dashboard route should never resolve).
 */
export async function mockAuth(page: Page, user: MockUser): Promise<void> {
  await page.route('**/sanctum/csrf-cookie', async (route) => {
    await route.fulfill({ status: 204 });
  });
  await page.route('**/api/v1/user', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          id: user.id,
          name: user.name,
          email: user.email,
          role: { id: `${user.id}_role`, slug: user.roleSlug, name: user.roleName },
          permissions: user.permissions,
          features: user.features ?? [],
          employee: user.employee ?? null,
          is_active: true,
          must_change_password: false,
          theme_mode: 'light',
          sidebar_collapsed: false,
        },
      }),
    });
  });
}

// ── Dashboard API Mocking ─────────────────────────────────────────────────

export interface MockDashboardApiOptions {
  /** HTTP status code (default 200). Pass 500 to simulate error. */
  status?: number;
  /** Delay in ms before fulfilling the response (for loading state). */
  delay?: number;
  /** If true, the request never resolves (for loading skeleton tests). */
  hang?: boolean;
}

/**
 * Mock a dashboard API endpoint.
 *
 * @param page    Playwright page
 * @param url     API URL pattern (e.g. `/api/v1/dashboards/hr`)
 * @param data    Mock response payload
 * @param opts    Status, delay, or hang options
 */
export async function mockDashboardApi(
  page: Page,
  url: string,
  data: Record<string, unknown> = {},
  opts: MockDashboardApiOptions = {},
): Promise<void> {
  const { status = 200, delay = 0, hang = false } = opts;

  await page.route(`**${url}`, async (route) => {
    if (hang) {
      // Never resolve — used for loading skeleton tests.
      await new Promise<never>(() => {});
      return;
    }
    if (delay > 0) {
      await new Promise((r) => setTimeout(r, delay));
    }
    const body = status === 200
      ? { data }
      : { message: 'Server error' };
    await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
  });
}

// ── Full Dashboard Setup ──────────────────────────────────────────────────

/**
 * One-shot: mock auth + dashboard API, then navigate to the page.
 *
 * This covers the common case (happy path, error, empty). For loading state
 * tests, use `mockAuth()` + `mockDashboardApi(..., { hang: true })` + `page.goto()`.
 */
export async function setupDashboard(
  page: Page,
  apiUrl: string,
  user: MockUser,
  mockData: Record<string, unknown> = {},
  opts: MockDashboardApiOptions = {},
): Promise<void> {
  await mockAuth(page, user);
  await mockDashboardApi(page, apiUrl, mockData, opts);
  await page.goto(urlFromApi(apiUrl));
  await page.waitForLoadState('networkidle');
}

// ── Internal helpers ──────────────────────────────────────────────────────

/** Convert API URL like `/api/v1/dashboards/hr` to SPA route like `/dashboard/hr`. */
function urlFromApi(apiUrl: string): string {
  const match = apiUrl.match(/\/api\/v1\/dashboards\/(.+)/);
  return match ? `/dashboard/${match[1]}` : '/';
}
