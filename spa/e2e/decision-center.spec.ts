import { expect, test, type Page, type Route } from '@playwright/test';
import { mockAuth, type MockUser } from './helpers';
import { recommendationFixture } from '../src/pages/decision-center/test-fixtures';
import type { Recommendation } from '../src/types/dss';

const user: MockUser = {
  id: 'dss-user',
  name: 'Decision Reviewer',
  email: 'reviewer@example.test',
  roleSlug: 'finance_officer',
  roleName: 'Finance Officer',
  permissions: ['dss.view'],
  features: ['dss'],
};

const recommendations: Recommendation[] = [
  recommendationFixture(),
  recommendationFixture({
    id: 'rec-finance',
    detector_id: 'dss.receivables_priority',
    domain: 'finance',
    type: 'receivables_priority',
    severity: 'warning',
    owner_role_slugs: ['finance_officer'],
    rationale: {
      customer: 'Northstar Foods',
      overdue_invoice_count: 2,
      open_balance: '18500.00',
      oldest_due_date: '2026-10-01',
      days_overdue: 22,
      first_dunning_tier_days: 7,
    },
  }),
  recommendationFixture({
    id: 'rec-maintenance',
    detector_id: 'dss.preventive_maintenance_overdue',
    domain: 'maintenance',
    type: 'preventive_maintenance_overdue',
    severity: 'info',
    rationale: {
      maintainable_type: 'machine',
      overdue_count: 3,
      oldest_due_at: '2026-10-20T09:00:00Z',
    },
  }),
  recommendationFixture({
    id: 'rec-production',
    detector_id: 'dss.work_order_intervention',
    domain: 'production',
    type: 'work_order_overdue',
    severity: 'warning',
    rationale: {
      work_order: 'WO-202610-0042',
      status: 'in_progress',
      planned_end: '2026-10-21T17:00:00Z',
      days_overdue: 2,
      quantity_target: 80,
      quantity_good: 62,
    },
  }),
];

const options = {
  domains: [
    { value: 'cross_module', label: 'Business operations' },
    { value: 'finance', label: 'Finance' },
    { value: 'maintenance', label: 'Maintenance' },
    { value: 'production', label: 'Production' },
  ],
  owner_roles: [{ value: 'finance_officer', label: 'Finance team' }],
  statuses: [
    { value: 'open', label: 'New' },
    { value: 'snoozed', label: 'Follow-up due' },
  ],
  severities: [
    { value: 'critical', label: 'Critical' },
    { value: 'warning', label: 'Warning' },
    { value: 'info', label: 'Information' },
  ],
};
const coverage = {
  engine_enabled: true,
  available_detectors: 4,
  shadow_detectors: 0,
  advisory_detectors: 4,
  disabled_detectors: 0,
  failed_detectors: 0,
  partial_detectors: 0,
  checks: [],
};

function scoped(params: URLSearchParams): Recommendation[] {
  return recommendations.filter(
    (rec) =>
      (!params.has('severity') || rec.severity === params.get('severity')) &&
      (!params.has('domain') || rec.domain === params.get('domain')) &&
      (!params.has('owner_role') || rec.owner_role_slugs?.includes(params.get('owner_role')!)) &&
      (!params.has('status') || rec.status === params.get('status')),
  );
}

async function mockDecisionCenter(page: Page, listHandler?: (route: Route) => Promise<void>) {
  await mockAuth(page, user);
  await page.route('**/api/v1/dss/recommendations?*', (route) => {
    if (listHandler) return listHandler(route);
    const data = scoped(new URL(route.request().url()).searchParams);
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data,
        meta: {
          current_page: 1,
          last_page: 1,
          per_page: 20,
          total: data.length,
          from: data.length ? 1 : null,
          to: data.length || null,
        },
      }),
    });
  });
  await page.route('**/api/v1/dss/recommendations/summary**', (route) => {
    const data = scoped(new URL(route.request().url()).searchParams);
    const counts = { critical: 0, warning: 0, info: 0 };
    data.forEach((rec) => counts[rec.severity]++);
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: { total: data.length, by_severity: counts } }),
    });
  });
  await page.route('**/api/v1/dss/recommendations/options', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: options }),
    }),
  );
  await page.route('**/api/v1/dss/recommendations/rec-*', (route) => {
    const recommendation = recommendations.find((rec) =>
      new URL(route.request().url()).pathname.endsWith(rec.id),
    );
    return route.fulfill({
      status: recommendation ? 200 : 404,
      contentType: 'application/json',
      body: JSON.stringify(recommendation ? { data: recommendation } : { message: 'Not found' }),
    });
  });
  await page.route('**/api/v1/dss/recommendations/*/events**', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: [], next_cursor: null }),
    }),
  );
  await page.route('**/api/v1/dss/coverage', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: coverage }),
    }),
  );
}

test('shows business-safe decisions, filters server results, and preserves phone list state', async ({
  page,
}) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await mockDecisionCenter(page);
  await page.goto('/decision-center');

  await expect(page.getByRole('heading', { name: 'Decision Center' })).toBeVisible();
  await expect(page.getByText('Confirmed delivery awaiting customer invoice')).toBeVisible();
  const firstDecision = page.getByRole('row', {
    name: /Confirmed delivery awaiting customer invoice/,
  });
  await expect(page.getByRole('columnheader', { name: 'Priority' })).toHaveAttribute(
    'aria-sort',
    'descending',
  );
  await firstDecision.focus();
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(/\/decision-center\/rec-1$/);
  await expect(page.getByRole('region', { name: 'Recorded evidence' })).toBeVisible();
  await expect(page.getByText('No activity recorded yet.')).toBeVisible();
  await page.locator('main a[href="/decision-center"]').click();
  await expect(page).toHaveURL('/decision-center');

  const initialText = await page.locator('body').innerText();
  expect(initialText).not.toContain('dss.chain_bottlenecks');
  expect(initialText).not.toContain('ChainBottleneckService');
  expect(initialText).not.toContain('hours_stuck');
  expect(initialText).not.toContain('confidence');
  expect(initialText).not.toContain('"rationale"');

  await page.getByRole('link', { name: 'Confirmed delivery awaiting customer invoice' }).click();
  await expect(page).toHaveURL(/\/decision-center\/rec-1$/);
  const evidence = page.getByRole('region', { name: 'Recorded evidence' });
  await expect(evidence).toBeVisible();
  await expect(evidence.getByText('DR-202610-0002')).toBeVisible();
  await expect(evidence.getByText('Confirmed')).toBeVisible();
  await expect(evidence.getByRole('cell', { name: '336.5' })).toBeVisible();
  await page.screenshot({ path: 'test-results/decision-center-desktop.png', fullPage: true });
  await page.locator('main a[href="/decision-center"]').click();
  await expect(page).toHaveURL('/decision-center');

  await page.getByLabel('Severity').selectOption('warning');
  await expect(page.getByText('Northstar Foods')).toBeVisible();
  await expect(page.getByText('Confirmed delivery awaiting customer invoice')).toHaveCount(0);
  await expect(page.getByText(/Review delayed production/)).toBeVisible();

  await page.setViewportSize({ width: 1100, height: 900 });
  await page.getByRole('link', { name: /Review delayed production/ }).click();
  await expect(page).toHaveURL(/\/decision-center\/rec-production\?severity=warning/);
  await page.locator('main a[href="/decision-center?severity=warning"]').click();
  await expect(page).toHaveURL(/\/decision-center\?severity=warning/);

  await page.setViewportSize({ width: 390, height: 844 });
  const productionRow = page.getByRole('link', { name: /Review delayed production/ });
  await productionRow.click();
  await expect(page).toHaveURL(/\/decision-center\/rec-production\?severity=warning/);
  await expect(page.getByRole('heading', { name: 'Recorded evidence' })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
  await page.screenshot({ path: 'test-results/decision-center-mobile-detail.png', fullPage: true });
  await page.locator('main a[href="/decision-center?severity=warning"]').click();
  await expect(page).toHaveURL(/\/decision-center\?severity=warning/);
  await expect(page.getByLabel('Severity')).toHaveValue('warning');
  await expect(page.getByText('Northstar Foods')).toBeVisible();
  await expect(page.getByText('Confirmed delivery awaiting customer invoice')).toHaveCount(0);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
});

test('replaces stale recommendations with the load error after refresh fails', async ({ page }) => {
  let listRequests = 0;
  await mockDecisionCenter(page, (route) => {
    listRequests += 1;
    if (listRequests > 1) {
      return route.fulfill({
        status: 500,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'Unavailable' }),
      });
    }
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: recommendations,
        meta: {
          current_page: 1,
          last_page: 1,
          per_page: 20,
          total: recommendations.length,
          from: 1,
          to: recommendations.length,
        },
      }),
    });
  });
  await page.goto('/decision-center');
  await expect(page.getByText('Confirmed delivery awaiting customer invoice')).toBeVisible();
  await page.getByRole('button', { name: 'Refresh Decision Center' }).click();
  await expect(page.getByText('Failed to load recommendations')).toBeVisible();
  await expect(page.getByText('Confirmed delivery awaiting customer invoice')).toHaveCount(0);
});

test('shows permission-scoped master-data records and their direct fix links', async ({ page }) => {
  await mockDecisionCenter(page);
  const recommendation = recommendationFixture({
    id: 'rec-hygiene',
    detector_id: 'dss.master_hygiene',
    domain: 'platform',
    type: 'master_hygiene',
    severity: 'warning',
    rationale: {
      gap: 'items_missing_reorder_point',
      affected_count: 1,
      evidence: ['RM-01'],
      why_it_matters: 'Items without a usable reorder point are invisible to auto-replenishment.',
    },
    action: { route: '/inventory/items', label: 'Open master data' },
  });
  await page.route('**/api/v1/dss/recommendations/rec-hygiene', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: recommendation }),
    }),
  );
  await page.route('**/api/v1/dss/recommendations/rec-hygiene/affected-records**', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: [{
          reference: 'RM-01',
          name: 'Resin mix',
          requirement: 'Usable reorder point',
          destination: '/inventory/items/item_hash/edit',
        }],
        total: 1,
        next_cursor: null,
        historical: false,
      }),
    }),
  );

  await page.goto('/decision-center/rec-hygiene');

  await expect(page.getByRole('table', { name: 'Affected master-data records' })).toBeVisible();
  await expect(page.getByRole('cell', { name: 'RM-01', exact: true })).toBeVisible();
  await expect(page.getByText('Usable reorder point')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Open record' })).toHaveAttribute(
    'href',
    '/inventory/items/item_hash/edit',
  );
  await expect(page.locator('body')).not.toContainText('items_missing_reorder_point');
});
