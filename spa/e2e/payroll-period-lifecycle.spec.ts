/**
 * E2E test: Payroll Period Lifecycle
 *
 * Covers: list with status chips, create, process (compute), approve + finalize.
 * All API calls are intercepted via Playwright route mocking — no backend needed.
 *
 * Status lifecycle: draft → processing → approved → finalized
 */
import { test, expect } from '@playwright/test';
import { mockAuth, type MockUser } from './helpers';

// ── Constants ─────────────────────────────────────────────────────────────

const LIST_URL  = '/payroll/periods';
const API_LIST  = '**/api/v1/payroll-periods';

/** Hash-ID used consistently across all tests for the single mock period. */
const PERIOD_ID = 'pK2mR7';

// ── User fixture ──────────────────────────────────────────────────────────

/**
 * Payroll officer with all lifecycle permissions.
 * Includes `payroll.periods.compute` and `payroll.periods.approve` so the
 * Compute / Approve / Finalize buttons render on the detail page.
 */
const PAYROLL_USER: MockUser = {
  id:       'u10',
  name:     'Payroll Officer',
  email:    'payroll@ogami.test',
  roleSlug: 'payroll_officer',
  roleName: 'Payroll Officer',
  permissions: [
    'payroll.periods.view',
    'payroll.periods.create',
    'payroll.periods.process',
    'payroll.periods.compute',
    'payroll.periods.approve',
    'payroll.periods.finalize',
  ],
  employee: null,
};

// ── Mock data factories ───────────────────────────────────────────────────

/** Builds a single PayrollPeriod object at the given status. */
function makePeriod(status: 'draft' | 'processing' | 'approved' | 'finalized', overrides: Record<string, unknown> = {}) {
  const statusLabels: Record<string, string> = {
    draft:      'Draft',
    processing: 'Processing',
    approved:   'Approved',
    finalized:  'Finalized',
  };

  return {
    id:                  PERIOD_ID,
    label:               'May 2026 — 1st Half',
    period_start:        '2026-05-01',
    period_end:          '2026-05-15',
    payroll_date:        '2026-05-15',
    is_first_half:       true,
    is_thirteenth_month: false,
    is_auto_created:     false,
    auto_created_at:     null,
    status,
    status_label:        statusLabels[status],
    employee_count:      status === 'draft' ? 0 : 45,
    creator:             { id: 'u10', name: 'Payroll Officer' },
    summary: status === 'draft' ? null : {
      employee_count:   45,
      total_gross:      '2847500.00',
      total_deductions: '312150.00',
      total_net:        '2535350.00',
      failed_count:     0,
    },
    disbursement_proofs: [],
    ...overrides,
  };
}

/** Wraps a period in a paginated list envelope. */
function makeListResponse(periods: ReturnType<typeof makePeriod>[]) {
  return {
    data: periods,
    meta: {
      current_page: 1,
      last_page:    1,
      per_page:     25,
      total:        periods.length,
      from:         periods.length > 0 ? 1 : null,
      to:           periods.length > 0 ? periods.length : null,
    },
    links: { first: null, last: null, prev: null, next: null },
  };
}

/** Wraps a period in a single-resource envelope. */
function makeDetailResponse(period: ReturnType<typeof makePeriod>) {
  return { data: period };
}

// ── Tests ─────────────────────────────────────────────────────────────────

test.describe('Payroll Period Lifecycle', () => {

  // ── Test 1: List with status chips ──────────────────────────────────────

  test('renders payroll periods list with status chips', async ({ page }) => {
    await mockAuth(page, PAYROLL_USER);

    // Return four periods covering every lifecycle status.
    await page.route(API_LIST, async (route) => {
      if (route.request().method() !== 'GET') { await route.continue(); return; }
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(makeListResponse([
          makePeriod('draft'),
          makePeriod('processing', { id: 'aB3nX1', label: 'Apr 2026 — 2nd Half', period_start: '2026-04-16', period_end: '2026-04-30', payroll_date: '2026-04-30', is_first_half: false }),
          makePeriod('approved',   { id: 'cD4pY2', label: 'Apr 2026 — 1st Half', period_start: '2026-04-01', period_end: '2026-04-15', payroll_date: '2026-04-15', is_first_half: true  }),
          makePeriod('finalized',  { id: 'eF5qZ3', label: 'Mar 2026 — 2nd Half', period_start: '2026-03-16', period_end: '2026-03-31', payroll_date: '2026-03-31', is_first_half: false }),
        ])),
      });
    });

    await page.goto(LIST_URL);
    await page.waitForLoadState('networkidle');

    // Page heading
    await expect(page.getByText('Payroll Periods')).toBeVisible();

    // New Period button is visible (user has create permission)
    await expect(page.getByRole('button', { name: /New Period/i })).toBeVisible();

    // All four status chips are present
    await expect(page.getByText('Draft')).toBeVisible();
    await expect(page.getByText('Processing')).toBeVisible();
    await expect(page.getByText('Approved')).toBeVisible();
    await expect(page.getByText('Finalized')).toBeVisible();

    // Period date ranges are rendered
    await expect(page.getByText(/May 2026/)).toBeVisible();
    await expect(page.getByText(/Apr 2026/)).toBeVisible();

    // Total period count in subtitle
    await expect(page.getByText(/4 periods/)).toBeVisible();
  });

  // ── Test 2: Create a new payroll period ──────────────────────────────────

  test('creates a new payroll period with correct date range', async ({ page }) => {
    await mockAuth(page, PAYROLL_USER);

    // List starts empty so the "Create period" empty-state button is shown.
    await page.route(API_LIST, async (route) => {
      if (route.request().method() !== 'GET') { await route.continue(); return; }
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(makeListResponse([])),
      });
    });

    // POST create → returns a draft period, then navigates to detail.
    await page.route(API_LIST, async (route) => {
      if (route.request().method() !== 'POST') { await route.continue(); return; }
      await route.fulfill({
        status: 201,
        contentType: 'application/json',
        body: JSON.stringify(makeDetailResponse(makePeriod('draft'))),
      });
    });

    // Detail page GET after redirect.
    await page.route(`**/api/v1/payroll-periods/${PERIOD_ID}`, async (route) => {
      if (route.request().method() !== 'GET') { await route.continue(); return; }
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(makeDetailResponse(makePeriod('draft'))),
      });
    });

    // Payrolls list (empty at draft stage).
    await page.route(`**/api/v1/payrolls*`, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0, from: null, to: null } }),
      });
    });

    await page.goto('/payroll/periods/create');
    await page.waitForLoadState('networkidle');

    await expect(page.getByText('New Payroll Period')).toBeVisible();

    // Fill in the form fields
    await page.getByLabel(/Period start/i).fill('2026-05-01');
    await page.getByLabel(/Period end/i).fill('2026-05-15');
    await page.getByLabel(/Payroll date/i).fill('2026-05-15');
    // Cycle dropdown defaults to "1st half" — leave as-is

    // Submit
    await page.getByRole('button', { name: /Create period/i }).click();

    // After success → redirected to detail page
    await page.waitForURL(`**/payroll/periods/${PERIOD_ID}`);
    await expect(page.getByText('May 2026 — 1st Half')).toBeVisible();
    await expect(page.getByText('Draft')).toBeVisible();
  });

  // ── Test 3: Process (compute) a payroll period ───────────────────────────

  test('processes a payroll period and shows employee count', async ({ page }) => {
    await mockAuth(page, PAYROLL_USER);

    // Starts as draft so the Compute button is rendered.
    const draftPeriod = makePeriod('draft');

    await page.route(`**/api/v1/payroll-periods/${PERIOD_ID}`, async (route) => {
      if (route.request().method() !== 'GET') { await route.continue(); return; }
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(makeDetailResponse(draftPeriod)),
      });
    });

    // POST /compute → responds with processing status.
    await page.route(`**/api/v1/payroll-periods/${PERIOD_ID}/compute`, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(makeDetailResponse(makePeriod('processing'))),
      });
    });

    // Empty payrolls list (draft stage, none computed yet).
    await page.route(`**/api/v1/payrolls*`, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: [],
          meta: { current_page: 1, last_page: 1, per_page: 100, total: 0, from: null, to: null },
        }),
      });
    });

    await page.goto(`/payroll/periods/${PERIOD_ID}`);
    await page.waitForLoadState('networkidle');

    // Verify we're on the draft detail page
    await expect(page.getByText('May 2026 — 1st Half')).toBeVisible();
    await expect(page.getByText('Draft')).toBeVisible();

    // Compute button is visible because status is draft and user has compute permission
    const computeBtn = page.getByRole('button', { name: /Compute/i });
    await expect(computeBtn).toBeVisible();

    // After clicking Compute, the API returns the processing status.
    // Intercept the subsequent GET for the period to return processing state.
    let fetchCount = 0;
    await page.route(`**/api/v1/payroll-periods/${PERIOD_ID}`, async (route) => {
      if (route.request().method() !== 'GET') { await route.continue(); return; }
      fetchCount++;
      // Second+ fetch returns processing with 45 employees
      const status = fetchCount > 1 ? 'processing' : 'draft';
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(makeDetailResponse(makePeriod(status as 'draft' | 'processing'))),
      });
    });

    await computeBtn.click();

    // Success toast: "Computation queued."
    await expect(page.getByText(/Computation queued/i)).toBeVisible({ timeout: 5000 });

    // The page auto-polls while processing — verify processing indicator appears.
    await expect(page.getByText(/Processing/i).first()).toBeVisible({ timeout: 5000 });
  });

  // ── Test 4: Approve and finalize a payroll period ────────────────────────

  test('approves and finalizes a payroll period', async ({ page }) => {
    await mockAuth(page, PAYROLL_USER);

    // Start at draft (approve requires status=draft per the detail page logic).
    const draftPeriod = makePeriod('draft');
    const approvedPeriod = makePeriod('approved');
    const finalizedPeriod = makePeriod('finalized');

    let periodState = draftPeriod;

    await page.route(`**/api/v1/payroll-periods/${PERIOD_ID}`, async (route) => {
      if (route.request().method() !== 'GET') { await route.continue(); return; }
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(makeDetailResponse(periodState)),
      });
    });

    // PATCH /approve → flip state to approved.
    await page.route(`**/api/v1/payroll-periods/${PERIOD_ID}/approve`, async (route) => {
      periodState = approvedPeriod;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(makeDetailResponse(approvedPeriod)),
      });
    });

    // PATCH /finalize → flip state to finalized.
    await page.route(`**/api/v1/payroll-periods/${PERIOD_ID}/finalize`, async (route) => {
      periodState = finalizedPeriod;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(makeDetailResponse(finalizedPeriod)),
      });
    });

    // Payrolls (45 employees, all computed).
    await page.route(`**/api/v1/payrolls*`, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: Array.from({ length: 3 }, (_, i) => ({
            id:               `pay${i + 1}`,
            pay_type:         'monthly',
            days_worked:      '11.00',
            gross_pay:        '31638.89',
            total_deductions: '3468.33',
            adjustment_amount:'0.00',
            net_pay:          '28170.56',
            computed_at:      '2026-05-10T08:00:00Z',
            error_message:    null,
            employee: {
              id:          `emp${i + 1}`,
              employee_no: `OGM-2024-00${10 + i}`,
              full_name:   `Employee ${i + 1}`,
            },
          })),
          meta: { current_page: 1, last_page: 1, per_page: 100, total: 45, from: 1, to: 3 },
        }),
      });
    });

    await page.goto(`/payroll/periods/${PERIOD_ID}`);
    await page.waitForLoadState('networkidle');

    // ── Step A: Approve ────────────────────────────────────────────────────

    await expect(page.getByText('May 2026 — 1st Half')).toBeVisible();
    await expect(page.getByText('Draft')).toBeVisible();

    const approveBtn = page.getByRole('button', { name: /Approve/i });
    await expect(approveBtn).toBeVisible();
    await approveBtn.click();

    await expect(page.getByText('Period approved.')).toBeVisible({ timeout: 5000 });

    // After invalidation the page re-fetches and shows approved status.
    await expect(page.getByText('Approved')).toBeVisible({ timeout: 5000 });

    // ── Step B: Finalize ───────────────────────────────────────────────────

    const finalizeBtn = page.getByRole('button', { name: /Finalize/i });
    await expect(finalizeBtn).toBeVisible();
    await finalizeBtn.click();

    await expect(page.getByText(/Period finalized/i)).toBeVisible({ timeout: 5000 });

    // Finalized status chip is shown.
    await expect(page.getByText('Finalized')).toBeVisible({ timeout: 5000 });

    // Bank file download link is present (canBankFile = true when finalized).
    await expect(page.getByRole('link', { name: /Bank file/i })).toBeVisible();

    // Employee count stat card confirms 45 employees processed.
    await expect(page.getByText('45')).toBeVisible();
  });

});
