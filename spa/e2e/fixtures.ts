/**
 * Shared Playwright fixtures.
 *
 * The one thing here is an API fallback route, and it exists because of a
 * failure mode that had quietly disabled twenty tests.
 *
 * In E2E there is no backend: `playwright.config.ts` mocks everything and the
 * Vite proxy has nothing behind it. An endpoint a spec has not mocked therefore
 * answers 401 — and the axios interceptor correctly treats a 401 as an expired
 * session, clears the query cache and navigates to /sign-in. So a single endpoint
 * a page acquired *after* its spec was written blanked the page and failed every
 * assertion in that spec, with an error message pointing at whichever locator
 * happened to be checked first. That is what had happened to the chain, payroll
 * and order-to-cash suites.
 *
 * Why a fixture rather than a line in `mockAuth`: Playwright gives priority to
 * the most recently registered matching route. Specs register their mocks at
 * various points — several before calling `loginAs` — so a fallback registered
 * inside `mockAuth` overrode them and made things worse. An `auto` fixture runs
 * before the test body, so every route a spec registers is necessarily later and
 * therefore wins.
 *
 * It answers with an EMPTY payload on purpose. A spec that needs data still has
 * to mock it; this only stops a missing mock from destroying the page underneath
 * assertions about something else. If a test starts passing because of this
 * fixture, the test was asserting nothing.
 */
import { test as base, expect } from '@playwright/test';
export type { Page, Locator } from '@playwright/test';

/*
 * An empty *object*, not an empty list.
 *
 * Almost every API function here ends in `.then(r => r.data.data)`, so `{}`
 * yields `undefined` downstream — which is what "no data" already means
 * throughout the app, and leaves every `x ? … : fallback` guard intact.
 *
 * Returning `{ data: [], meta }` instead looked more helpful and was worse: it
 * made an unmocked response *truthy* but the wrong shape, so
 * `statusCounts ? statusCounts.counts[tile.status] : '—'` — correct code — read
 * `.active` off undefined and crashed the employees page. A fallback must be
 * indistinguishable from absent, not a plausible-looking impostor.
 */
const EMPTY_RESPONSE = {};

// Well-formed, nonempty support responses for reads unrelated to a spec's
// subject. Spec-specific routes register later and override these defaults.
const DEFAULT_READ_RESPONSES: Record<string, unknown> = {
  /*
   * The KPI strip every dashboard mounts asks for six months of trends for all
   * of its codes. `kpiApi.trends` returns `response.data.data` verbatim, so the
   * `{}` fallback hands React Query `undefined` and it throws
   * "Query data cannot be undefined" — a console error the shared diagnostics
   * fixture fails the test on. One `{ data: {} }` here answers with an empty map
   * (`trends?.[code] ?? []` is the consumer's own guard), which is what "no
   * trends" already means. Found while verifying W1.5 against the four dashboard
   * specs, all of which were red before it (F-018).
   */
  '/api/v1/dashboard/kpi/trends': { data: {} },
  '/api/v1/dashboards/finance': { data: {} },
  '/api/v1/dashboards/hr': { data: { kpis: [], panels: {} } },
  '/api/v1/dashboards/admin': { data: { kpis: [], panels: {} } },
  '/api/v1/b2b/customer/notifications': {
    data: [], meta: { current_page: 1, last_page: 1, per_page: 8, total: 0, unread_count: 0 },
  },
  '/api/v1/b2b/supplier/notifications': {
    data: [], meta: { current_page: 1, last_page: 1, per_page: 8, total: 0, unread_count: 0 },
  },
  '/api/v1/b2b/customer/business-policies': {
    data: { functional_currency_code: 'PHP', vat_status: 'VAT Registered', vat_rate: '0.12' },
  },
  '/api/v1/b2b/supplier/business-policies': {
    data: { functional_currency_code: 'PHP', vat_status: 'VAT Registered', vat_rate: '0.12' },
  },
  '/api/v1/leaves/requests/options': {
    data: {
      statuses: [
        { value: 'pending_dept', label: 'Pending dept' },
        { value: 'pending_hr', label: 'Pending hr' },
        { value: 'approved', label: 'Approved' },
        { value: 'rejected', label: 'Rejected' },
        { value: 'cancelled', label: 'Cancelled' },
      ],
      half_day_periods: [
        { value: 'am', label: 'Morning (AM half-day)' },
        { value: 'pm', label: 'Afternoon (PM half-day)' },
      ],
      holiday_dates: [],
    },
  },
  '/api/v1/attendance/overtime-requests/options': {
    data: {
      statuses: [
        { value: 'pending', label: 'Pending' },
        { value: 'approved', label: 'Approved' },
        { value: 'rejected', label: 'Rejected' },
      ],
      minimum_hours: 0.5,
      maximum_hours: 4,
      request_min_hours: 0.5,
      request_future_days: 30,
      request_past_days: 0,
      premium_multiplier: 1.25,
    },
  },
  '/api/v1/attendance/punch/status': {
    data: {
      next_action: 'in',
      shift: null,
      punches: [],
      tardiness_minutes: null,
      radius_m: 200,
    },
  },
  '/api/v1/inventory/items/options': {
    data: {
      item_types: [
        { value: 'raw_material', label: 'Raw Material' },
        { value: 'finished_good', label: 'Finished Good' },
        { value: 'packaging', label: 'Packaging' },
        { value: 'spare_part', label: 'Spare Part' },
      ],
      replenishment_basis: { demand_history_days: 30, coverage_buffer_ratio: 1.2, default_lead_time_days: 14 },
      reorder_methods: [
        { value: 'fixed_quantity', label: 'Fixed Quantity' },
        { value: 'days_of_supply', label: 'Days Of Supply' },
      ],
      adjustment_directions: [
        { value: 'in', label: 'Increase (IN — cycle count over)' },
        { value: 'out', label: 'Decrease (OUT — cycle count short / scrap)' },
      ],
      stock_statuses: [
        { value: 'critical', label: 'Critical' },
        { value: 'low', label: 'Low' },
        { value: 'ok', label: 'OK' },
      ],
    },
  },
  '/api/v1/hr/employees/options': {
    data: {
      statuses: options(['active:Active', 'on_leave:On leave', 'suspended:Suspended', 'resigned:Resigned', 'terminated:Terminated', 'retired:Retired']),
      employment_types: options(['regular:Regular', 'probationary:Probationary', 'contractual:Contractual', 'project_based:Project-Based']),
      pay_types: options(['monthly:Monthly', 'semi_monthly:Semi-monthly']),
      genders: options(['male:Male', 'female:Female']),
      civil_statuses: options(['single:Single', 'married:Married', 'widowed:Widowed', 'separated:Separated', 'divorced:Divorced']),
      separation_reasons: options(['resigned:Resigned', 'terminated:Terminated', 'retired:Retired', 'end_of_contract:End of contract']),
      skill_levels: options(['novice:Novice', 'competent:Competent', 'proficient:Proficient', 'expert:Expert', 'trainer:Trainer']),
    },
  },
  '/api/v1/hr/employees/status-counts': {
    data: { counts: { active: 0, on_leave: 0, suspended: 0, resigned: 0, terminated: 0, retired: 0 }, total: 0 },
  },
  '/api/v1/hr/departments/tree': { data: [] },
  '/api/v1/attendance/shifts/current/1': { data: null },
  '/api/v1/payroll-periods/options': {
    data: {
      statuses: options(['draft:Draft', 'processing:Processing', 'computed:Computed', 'approved:Approved', 'finalized:Finalized', 'disbursed:Disbursed', 'voided:Voided']),
      period_types: [{ value: 'false', label: 'Regular' }, { value: 'true', label: '13th Month' }],
      half_types: [
        { value: 'true', label: '1st half (government deductions apply)' },
        { value: 'false', label: '2nd half' },
      ],
      employment_types: options(['regular:Regular', 'probationary:Probationary', 'contractual:Contractual', 'project_based:Project-Based']),
      pay_types: [{ value: 'monthly', label: 'Monthly' }, { value: 'semi_monthly', label: 'Semi-monthly' }],
      departments: [],
    },
  },
  '/api/v1/payroll-periods/bank-file/options': {
    data: {
      formats: options(['generic:GENERIC CSV', 'bdo:BDO', 'bpi:BPI', 'metrobank:METROBANK']),
      default_format: 'generic',
    },
  },
  '/api/v1/crm/sales-orders/options': { data: { statuses: [] } },
  '/api/v1/invoices/options': {
    data: {
      statuses: options(['draft:Draft', 'finalized:Finalized', 'partial:Partial', 'paid:Paid', 'cancelled:Cancelled']),
    },
  },
  '/api/v1/accounting/options': {
    data: { payment_methods: [], account_types: [], normal_balances: [], credit_note_types: [], credit_note_statuses: [] },
  },
};

function options(values: string[]): Array<{ value: string; label: string }> {
  return values.map((entry) => {
    const separator = entry.indexOf(':');
    return { value: entry.slice(0, separator), label: entry.slice(separator + 1) };
  });
}

export const test = base.extend<{ apiFallback: void; browserDiagnostics: void }>({
  browserDiagnostics: [
    async ({ page }, use, testInfo) => {
      const diagnostics = { pageErrors: [] as string[], consoleErrors: [] as string[], failedRequests: [] as string[], cancelledRequests: [] as string[], badResponses: [] as string[], externalRequests: [] as string[] };
      page.on('pageerror', (error) => diagnostics.pageErrors.push(error.stack ?? error.message));
      page.on('console', (message) => {
        if (message.type() === 'error') diagnostics.consoleErrors.push(message.text());
      });
      page.on('requestfailed', (request) => {
        const failure = request.failure()?.errorText ?? 'failed';
        const entry = `${request.method()} ${request.url()}: ${failure}`;
        if (failure === 'NS_BINDING_ABORTED' || failure === 'net::ERR_ABORTED') diagnostics.cancelledRequests.push(entry);
        else diagnostics.failedRequests.push(entry);
      });
      page.on('response', (response) => {
        if (response.status() >= 400) diagnostics.badResponses.push(`${response.status()} ${response.request().method()} ${response.url()}`);
      });
      await page.route('**/*', async (route) => {
        const url = new URL(route.request().url());
        if (['http:', 'https:'].includes(url.protocol) && !['localhost', '127.0.0.1', '::1'].includes(url.hostname)) {
          diagnostics.externalRequests.push(`${route.request().method()} ${url.href}`);
          await route.abort('blockedbyclient');
          return;
        }
        await route.fallback();
      });
      await use();
      if (!page.isClosed()) await page.evaluate(async () => { await document.fonts.ready; });
      await testInfo.attach('browser-diagnostics.json', {
        body: JSON.stringify(diagnostics, null, 2),
        contentType: 'application/json',
      });
      expect(diagnostics.pageErrors, 'Unhandled browser exceptions').toEqual([]);
      const expectedConsoleErrors = testInfo.annotations
        .filter((annotation) => annotation.type === 'expected-console-error')
        .map((annotation) => annotation.description ?? '');
      expect(
        diagnostics.consoleErrors,
        `Browser console errors; HTTP responses: ${JSON.stringify(diagnostics.badResponses)}`,
      ).toEqual(expectedConsoleErrors);
      expect(diagnostics.failedRequests, 'Failed browser requests').toEqual([]);
      const expectedHttpResponses = testInfo.annotations
        .filter((annotation) => annotation.type === 'expected-http-response')
        .map((annotation) => annotation.description ?? '');
      const responseMatches = (response: string, expected: string) => {
        const [status, method, path] = expected.split(' ');
        const [responseStatus, responseMethod, url] = response.split(' ', 3);

        return responseStatus === status && responseMethod === method && new URL(url).pathname === path;
      };
      for (const expected of new Set(expectedHttpResponses)) {
        const matches = diagnostics.badResponses.filter((response) => responseMatches(response, expected));
        expect(matches, `Expected HTTP response ${expected}`).toHaveLength(
          expectedHttpResponses.filter((response) => response === expected).length,
        );
      }
      expect(
        diagnostics.badResponses.filter((response) => !expectedHttpResponses.some((expected) => responseMatches(response, expected))),
        'Unexpected HTTP error responses',
      ).toEqual([]);
      expect(diagnostics.externalRequests).toEqual([]);
    },
    { auto: true },
  ],
  apiFallback: [
    async ({ page }, use) => {
      await page.route('**/api/v1/**', async (route) => {
        const request = route.request();
        const path = new URL(request.url()).pathname;
        const method = request.method();
        let response = method === 'GET' ? DEFAULT_READ_RESPONSES[path] : undefined;
        if (!response && method === 'GET' && /^\/api\/v1\/leaves\/balances\/[^/]+$/.test(path)) {
          response = { data: [] };
        }
        if (!response && method === 'GET' && /^\/api\/v1\/attendance\/shifts\/current\/[^/]+$/.test(path)) {
          response = { data: null };
        }
        if (!response && method === 'GET' && /^\/api\/v1\/b2b\/purchase-orders\/[^/]+\/supplier-activity$/.test(path)) {
          response = { data: { shipments: [], documents: [], delivery_schedules: [] } };
        }
        if (!response && method === 'GET' && /^\/api\/v1\/payroll-periods\/[^/]+\/disbursement-proofs\/options$/.test(path)) {
          response = { data: { proof_types: [] } };
        }
        if (!response && method === 'POST' && path === '/api/v1/payroll-periods/scope-preview') {
          response = {
            data: {
              is_company_wide: true,
              employee_count: 0,
              already_paid_count: 0,
              already_paid_sample: [],
              total_active: 0,
              estimated_gross: '0.00',
            },
          };
        }
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(response ?? EMPTY_RESPONSE),
        });
      });
      await use();
    },
    { auto: true },
  ],
});

export { expect };
