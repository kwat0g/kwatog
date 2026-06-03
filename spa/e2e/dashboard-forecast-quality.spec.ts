/**
 * E2E test: Quality Dashboard — Defect Rate Forecast Panel
 *
 * Covers: loading, error, data (up/stable/down trends), empty.
 * API calls intercepted via shared helpers — no backend needed.
 */
import { test, expect } from '@playwright/test';
import { USERS, mockAuth, mockDashboardApi, setupDashboard } from './helpers';

// ── Helpers ──────────────────────────────────────────────────────────────

const API_URL = '/api/v1/dashboards/quality';
const PAGE_URL = '/dashboard/quality';

function makeQualityData(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    kpis: [
      { label: 'Pending Inspections', value: '8', unit: 'count' },
      { label: 'Pass Rate Today', value: '94.5', unit: 'pct' },
      { label: 'Open NCRs', value: '3', unit: 'count' },
      { label: 'CoCs Gen. MTD', value: '12', unit: 'count' },
    ],
    panels: {
      inspection_queue: [],
      defect_pareto: [],
      ncr_status: [],
      qc_chain_coverage: {
        incoming: { inspected: 10, total: 15, pct: 67 },
        in_process: { inspected: 8, total: 12, pct: 67 },
        outgoing: { inspected: 6, total: 8, pct: 75 },
      },
      defect_rate_forecast: {
        historical: [
          { year: 2026, month: 1, value: 3.2, total: 1200, defects: 38 },
          { year: 2026, month: 2, value: 2.8, total: 1350, defects: 38 },
          { year: 2026, month: 3, value: 3.5, total: 1100, defects: 39 },
          { year: 2026, month: 4, value: 3.1, total: 1280, defects: 40 },
          { year: 2026, month: 5, value: 2.5, total: 1400, defects: 35 },
          { year: 2026, month: 6, value: 2.2, total: 1450, defects: 32 },
        ],
        forecast: [
          { year: 2026, month: 7, value: 2.4, confidence: 85 },
          { year: 2026, month: 8, value: 2.3, confidence: 82 },
          { year: 2026, month: 9, value: 2.3, confidence: 78 },
          { year: 2026, month: 10, value: 2.2, confidence: 75 },
          { year: 2026, month: 11, value: 2.2, confidence: 72 },
          { year: 2026, month: 12, value: 2.1, confidence: 68 },
        ],
        trend: 'down',
        kpi: { label: 'Projected Defect Rate', value: '2.3%', unit: 'pct', trend: 'down' },
      },
    },
    ...overrides,
  };
}

// ── Tests ────────────────────────────────────────────────────────────────

test.describe('Quality Dashboard — Defect Rate Forecast Panel', () => {

  test('renders defect rate forecast with down trend (improving quality)', async ({ page }) => {
    await setupDashboard(page, API_URL, USERS.qc, makeQualityData());

    await expect(page.getByText('QC Dashboard')).toBeVisible();
    await expect(page.getByText('Defect Rate Forecast (6 months)')).toBeVisible();
    await expect(page.getByText('Projected Defect Rate')).toBeVisible();
    await expect(page.getByText('2.3%')).toBeVisible();
    await expect(page.locator('text=down').first()).toBeVisible();
    await expect(page.getByText('Historical')).toBeVisible();
    await expect(page.getByText('Forecast')).toBeVisible();
  });

  test('shows stable trend when defect rate is consistent', async ({ page }) => {
    const data = makeQualityData({
      panels: {
        defect_rate_forecast: {
          historical: [
            { year: 2026, month: 1, value: 2.8, total: 1000, defects: 28 },
            { year: 2026, month: 2, value: 2.9, total: 1100, defects: 32 },
            { year: 2026, month: 3, value: 2.7, total: 1050, defects: 28 },
          ],
          forecast: [{ year: 2026, month: 4, value: 2.8, confidence: 80 }],
          trend: 'stable',
          kpi: { label: 'Projected Defect Rate', value: '2.8%', unit: 'pct', trend: 'stable' },
        },
      },
    });

    await setupDashboard(page, API_URL, USERS.qc, data);
    await expect(page.locator('text=stable').first()).toBeVisible();
  });

  test('shows up trend when defect rate is worsening', async ({ page }) => {
    const data = makeQualityData({
      panels: {
        defect_rate_forecast: {
          historical: [
            { year: 2026, month: 1, value: 2.0, total: 1000, defects: 20 },
            { year: 2026, month: 2, value: 2.5, total: 1100, defects: 28 },
            { year: 2026, month: 3, value: 3.0, total: 1050, defects: 32 },
            { year: 2026, month: 4, value: 3.5, total: 1200, defects: 42 },
            { year: 2026, month: 5, value: 4.0, total: 1150, defects: 46 },
            { year: 2026, month: 6, value: 4.5, total: 1300, defects: 59 },
          ],
          forecast: [
            { year: 2026, month: 7, value: 4.8, confidence: 85 },
            { year: 2026, month: 8, value: 5.0, confidence: 82 },
          ],
          trend: 'up',
          kpi: { label: 'Projected Defect Rate', value: '4.9%', unit: 'pct', trend: 'up' },
        },
      },
    });

    await setupDashboard(page, API_URL, USERS.qc, data);
    await expect(page.locator('text=up').first()).toBeVisible();
  });

  test('shows empty state when no forecast data exists', async ({ page }) => {
    const data = makeQualityData({
      panels: {
        defect_rate_forecast: {
          historical: [], forecast: [], trend: 'stable',
          kpi: { label: 'Projected Defect Rate', value: '—', unit: '—', trend: 'stable' },
        },
      },
    });

    await setupDashboard(page, API_URL, USERS.qc, data);
    await expect(page.getByText(/No data yet/)).toBeVisible();
  });

  test('shows error state when API fails', async ({ page }) => {
    await setupDashboard(page, API_URL, USERS.qc, {}, { status: 500 });
    await expect(page.getByText(/Failed to load dashboard/)).toBeVisible();
  });

  test('shows loading skeleton during fetch', async ({ page }) => {
    await mockAuth(page, USERS.qc);
    await mockDashboardApi(page, API_URL, {}, { hang: true });

    await page.goto(PAGE_URL);
    await expect(page.locator('.animate-pulse').first()).toBeVisible({ timeout: 5000 });
  });

});
