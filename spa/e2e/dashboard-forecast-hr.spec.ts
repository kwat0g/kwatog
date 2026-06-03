/**
 * E2E test: HR Dashboard — Headcount Forecast Panel
 *
 * Covers: loading, error, data (up/stable/down trends), empty.
 * API calls intercepted via shared helpers — no backend needed.
 */
import { test, expect } from '@playwright/test';
import { USERS, mockAuth, mockDashboardApi, setupDashboard } from './helpers';

// ── Helpers ──────────────────────────────────────────────────────────────

const API_URL = '/api/v1/dashboards/hr';
const PAGE_URL = '/dashboard/hr';

function makeHeadcountData(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    kpis: [
      { label: 'Active Headcount', value: '150', unit: 'count' },
      { label: 'On Leave Today', value: '5', unit: 'count' },
      { label: 'Pending Leave', value: '12', unit: 'count' },
      { label: 'Open Clearances', value: '3', unit: 'count' },
    ],
    panels: {
      by_department: [{ label: 'Production', count: 80 }, { label: 'Admin', count: 30 }],
      recent_hires: [], pending_leaves: [],
      attendance_summary: { present: 120, late: 8, absent: 5, on_leave: 5 },
      probation_alerts: [], leave_calendar_week: [],
      hr_calendar_events: { holidays: [], birthdays: [], birthdays_count: 0 },
      pending_my_action: { leave_requests: 5, profile_updates: 2, clearances: 1, total: 8 },
      headcount_forecast: {
        historical: [
          { year: 2026, month: 1, value: 142 },
          { year: 2026, month: 2, value: 145 },
          { year: 2026, month: 3, value: 147 },
          { year: 2026, month: 4, value: 148 },
          { year: 2026, month: 5, value: 150 },
          { year: 2026, month: 6, value: 150 },
        ],
        forecast: [
          { year: 2026, month: 7, value: 151, confidence: 85 },
          { year: 2026, month: 8, value: 152, confidence: 82 },
          { year: 2026, month: 9, value: 153, confidence: 78 },
          { year: 2026, month: 10, value: 153, confidence: 75 },
          { year: 2026, month: 11, value: 154, confidence: 72 },
          { year: 2026, month: 12, value: 155, confidence: 68 },
        ],
        trend: 'up',
        kpi: { label: 'Projected Headcount', value: '153', unit: 'count', trend: 'up' },
      },
    },
    ...overrides,
  };
}

// ── Tests ────────────────────────────────────────────────────────────────

test.describe('HR Dashboard — Headcount Forecast Panel', () => {

  test('renders historical + forecast bars with up trend', async ({ page }) => {
    await setupDashboard(page, API_URL, USERS.hr, makeHeadcountData());

    await expect(page.getByText('HR Officer Dashboard')).toBeVisible();
    await expect(page.getByText('Headcount Forecast')).toBeVisible();
    await expect(page.getByText('Projected Headcount')).toBeVisible();
    await expect(page.getByText(/153/)).toBeVisible();
    await expect(page.locator('text=up').first()).toBeVisible();
    await expect(page.getByText('Historical')).toBeVisible();
    await expect(page.getByText('Forecast')).toBeVisible();
  });

  test('shows stable trend when headcount is flat', async ({ page }) => {
    const data = makeHeadcountData({
      panels: {
        headcount_forecast: {
          historical: [
            { year: 2026, month: 1, value: 150 },
            { year: 2026, month: 2, value: 150 },
            { year: 2026, month: 3, value: 151 },
            { year: 2026, month: 4, value: 150 },
            { year: 2026, month: 5, value: 150 },
            { year: 2026, month: 6, value: 150 },
          ],
          forecast: [{ year: 2026, month: 7, value: 150, confidence: 80 }],
          trend: 'stable',
          kpi: { label: 'Projected Headcount', value: '150', unit: 'count', trend: 'stable' },
        },
      },
    });

    await setupDashboard(page, API_URL, USERS.hr, data);
    await expect(page.locator('text=stable').first()).toBeVisible();
  });

  test('shows down trend when headcount is declining', async ({ page }) => {
    const data = makeHeadcountData({
      panels: {
        headcount_forecast: {
          historical: [
            { year: 2026, month: 1, value: 155 },
            { year: 2026, month: 2, value: 152 },
            { year: 2026, month: 3, value: 150 },
            { year: 2026, month: 4, value: 148 },
            { year: 2026, month: 5, value: 145 },
            { year: 2026, month: 6, value: 142 },
          ],
          forecast: [
            { year: 2026, month: 7, value: 140, confidence: 85 },
            { year: 2026, month: 8, value: 138, confidence: 82 },
          ],
          trend: 'down',
          kpi: { label: 'Projected Headcount', value: '139', unit: 'count', trend: 'down' },
        },
      },
    });

    await setupDashboard(page, API_URL, USERS.hr, data);
    await expect(page.locator('text=down').first()).toBeVisible();
  });

  test('shows empty state when no forecast data exists', async ({ page }) => {
    const data = makeHeadcountData({
      panels: {
        headcount_forecast: {
          historical: [], forecast: [], trend: 'stable',
          kpi: { label: 'Projected Headcount', value: '—', unit: '—', trend: 'stable' },
        },
      },
    });
    await setupDashboard(page, API_URL, USERS.hr, data);
    await expect(page.getByText(/No data yet/)).toBeVisible();
  });

  test('shows error state when API fails', async ({ page }) => {
    await setupDashboard(page, API_URL, USERS.hr, {}, { status: 500 });
    await expect(page.getByText(/Failed to load/)).toBeVisible();
  });

  test('shows loading skeleton during fetch', async ({ page }) => {
    // Only mock auth — dashboard route never resolves, capturing loading state.
    await mockAuth(page, USERS.hr);
    await mockDashboardApi(page, API_URL, {}, { hang: true });

    await page.goto(PAGE_URL);
    await expect(page.locator('.animate-pulse').first()).toBeVisible({ timeout: 5000 });
  });

});
