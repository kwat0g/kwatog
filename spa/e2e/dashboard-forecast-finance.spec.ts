/**
 * E2E test: Finance Dashboard — Revenue Forecast Panel
 *
 * Covers: loading, error, data (up/stable/down trends), empty.
 * API calls intercepted via shared helpers — no backend needed.
 */
import { test, expect } from '@playwright/test';
import { USERS, mockAuth, mockDashboardApi, setupDashboard } from './helpers';

// ── Helpers ──────────────────────────────────────────────────────────────

const API_URL = '/api/v1/dashboards/finance';
const PAGE_URL = '/dashboard/finance';

function makeRevenueData(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    cash_balance: '2500000.00',
    ar_outstanding: '850000.00',
    ap_outstanding: '420000.00',
    revenue_mtd: '680000.00',
    ar_aging_summary: { current: '500000.00', d1_30: '200000.00', d31_60: '80000.00', d61_90: '40000.00', d91_plus: '30000.00', total: '850000.00' },
    ap_aging_summary: { current: '250000.00', d1_30: '100000.00', d31_60: '40000.00', d61_90: '20000.00', d91_plus: '10000.00', total: '420000.00' },
    recent_journal_entries: [],
    top_overdue_customers: [],
    payroll_pipeline: { draft: 2, processing: 1, approved: 0, finalized: 4, disbursed: 3, total: 10 },
    unposted_jes: { count: 5, oldest_date: '2026-05-01' },
    ap_due_this_week: { count: 3, total: '45000.00', items: [] },
    budget_vs_actual_top: null,
    revenue_forecast: {
      historical: [
        { year: 2025, month: 7, value: 520000.0 },
        { year: 2025, month: 8, value: 540000.0 },
        { year: 2025, month: 9, value: 510000.0 },
        { year: 2025, month: 10, value: 580000.0 },
        { year: 2025, month: 11, value: 610000.0 },
        { year: 2025, month: 12, value: 590000.0 },
        { year: 2026, month: 1, value: 620000.0 },
        { year: 2026, month: 2, value: 600000.0 },
        { year: 2026, month: 3, value: 650000.0 },
        { year: 2026, month: 4, value: 660000.0 },
        { year: 2026, month: 5, value: 680000.0 },
        { year: 2026, month: 6, value: 680000.0 },
      ],
      forecast: [
        { year: 2026, month: 7, value: 670000.0, confidence: 88 },
        { year: 2026, month: 8, value: 690000.0, confidence: 85 },
        { year: 2026, month: 9, value: 700000.0, confidence: 82 },
        { year: 2026, month: 10, value: 720000.0, confidence: 78 },
        { year: 2026, month: 11, value: 730000.0, confidence: 74 },
        { year: 2026, month: 12, value: 750000.0, confidence: 70 },
      ],
      trend: 'up',
      kpi: { label: 'Projected Revenue (6mo)', value: '710000.00', unit: 'PHP', trend: 'up' },
    },
    ...overrides,
  };
}

// ── Tests ────────────────────────────────────────────────────────────────

test.describe('Finance Dashboard — Revenue Forecast Panel', () => {

  test('renders revenue forecast with uptrend and formatted PHP values', async ({ page }) => {
    await setupDashboard(page, API_URL, USERS.finance, makeRevenueData());

    await expect(page.getByText('Finance Officer Dashboard')).toBeVisible();
    await expect(page.getByText('Revenue Forecast (6 months)')).toBeVisible();
    await expect(page.getByText('Projected Revenue (6mo)')).toBeVisible();

    // Value should be formatted as peso (with comma).
    await expect(page.getByText(/710,000/)).toBeVisible();

    // Trend indicator shows "up".
    await expect(page.locator('text=up').first()).toBeVisible();

    // Legend.
    await expect(page.getByText('Historical')).toBeVisible();
    await expect(page.getByText('Forecast')).toBeVisible();
  });

  test('shows stable trend when revenue is flat', async ({ page }) => {
    const data = makeRevenueData({
      revenue_forecast: {
        historical: [
          { year: 2026, month: 1, value: 600000.0 },
          { year: 2026, month: 2, value: 605000.0 },
          { year: 2026, month: 3, value: 598000.0 },
          { year: 2026, month: 4, value: 602000.0 },
          { year: 2026, month: 5, value: 600000.0 },
          { year: 2026, month: 6, value: 603000.0 },
        ],
        forecast: [{ year: 2026, month: 7, value: 601000.0, confidence: 85 }],
        trend: 'stable',
        kpi: { label: 'Projected Revenue (6mo)', value: '601000.00', unit: 'PHP', trend: 'stable' },
      },
    });

    await setupDashboard(page, API_URL, USERS.finance, data);
    await expect(page.locator('text=stable').first()).toBeVisible();
  });

  test('shows down trend when revenue is declining', async ({ page }) => {
    const data = makeRevenueData({
      revenue_forecast: {
        historical: [
          { year: 2026, month: 1, value: 700000.0 },
          { year: 2026, month: 2, value: 680000.0 },
          { year: 2026, month: 3, value: 650000.0 },
          { year: 2026, month: 4, value: 620000.0 },
          { year: 2026, month: 5, value: 600000.0 },
          { year: 2026, month: 6, value: 580000.0 },
        ],
        forecast: [
          { year: 2026, month: 7, value: 560000.0, confidence: 85 },
          { year: 2026, month: 8, value: 540000.0, confidence: 82 },
        ],
        trend: 'down',
        kpi: { label: 'Projected Revenue (6mo)', value: '550000.00', unit: 'PHP', trend: 'down' },
      },
    });

    await setupDashboard(page, API_URL, USERS.finance, data);
    await expect(page.locator('text=down').first()).toBeVisible();
  });

  test('shows empty state when no revenue forecast data exists', async ({ page }) => {
    const data = makeRevenueData({
      revenue_forecast: {
        historical: [], forecast: [], trend: 'stable',
        kpi: { label: 'Projected Revenue (6mo)', value: '—', unit: '—', trend: 'stable' },
      },
    });

    await setupDashboard(page, API_URL, USERS.finance, data);
    await expect(page.getByText(/No data yet/)).toBeVisible();
  });

  test('shows error state when API fails', async ({ page }) => {
    await setupDashboard(page, API_URL, USERS.finance, {}, { status: 500 });
    await expect(page.getByText(/Failed to load finance dashboard/)).toBeVisible();
  });

  test('shows loading skeleton during fetch', async ({ page }) => {
    await mockAuth(page, USERS.finance);
    await mockDashboardApi(page, API_URL, {}, { hang: true });

    await page.goto(PAGE_URL);
    await expect(page.locator('.animate-pulse').first()).toBeVisible({ timeout: 5000 });
  });

});
