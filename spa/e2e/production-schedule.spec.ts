import { expect, test, type Page } from '@playwright/test';
import { loginAs, mockAuth, ROLES } from './helpers-extended';

const bars = [0, 1, 2].map((index) => ({
  id: `schedule-${index}`,
  updated_at: '2026-10-05T10:00:00.000000Z',
  wo_id: `wo-${index}`,
  wo_number: `WO-202610-001${index + 1}`,
  product_name: [
    'Windshield Wiper Bushing',
    'Pivot Cap Cover Type A',
    'Wiper Bushing (Heavy Duty)',
  ][index],
  product_part_number: ['BU-001', 'PC-001', 'WB-002'][index],
  quantity_target: 50,
  sales_order: { id: 'so-28', so_number: 'SO-202610-0028' },
  mold_code: `MOLD-${index}`,
  start: `2026-10-11T0${index}:00:00+08:00`,
  end: `2026-10-11T0${index + 1}:00:00+08:00`,
  status: 'pending',
  wo_status: 'planned',
  staffing: {
    worker_names: [`Worker ${index + 1}`],
    worker_count: 1,
    minimum_workers: 1,
    recorder_name: `Recorder ${index + 1}`,
    blockers: [],
    approval_blockers: [],
    expected_crew_id: `crew-${index + 1}`,
  },
}));
function snapshot() {
  return {
    from: '2026-10-05T00:00:00+08:00',
    to: '2026-10-20T00:00:00+08:00',
    timezone: 'Asia/Manila',
    rows: [
      {
        machine_id: 'machine-1',
        machine_code: 'IMM-01',
        name: 'Toshiba EC100SX',
        status: 'idle',
        tonnage: 100,
        bars,
      },
    ],
  };
}
async function routes(page: Page) {
  await page.route('**/api/v1/**', (route) => route.fulfill({ json: { data: [] } }));
  await page.route('**/mrp/scheduler/options', (route) =>
    route.fulfill({ json: { data: { default_horizon_days: 14 } } }),
  );
  await page.route('**/mrp/scheduler/snapshot?**', (route) =>
    route.fulfill({ json: { data: snapshot() } }),
  );
}

test('Production Manager sees separate same-day WOs and opens their records for confirmation', async ({
  page,
}) => {
  await page.setViewportSize({ width: 1440, height: 960 });
  await routes(page);
  let confirmations = 0;
  await page.route('**/mrp/scheduler/confirm', (route) => {
    confirmations++;
    return route.fulfill({ status: 500, json: { message: 'Unexpected confirmation' } });
  });
  await loginAs(page, 'production', '/production/schedule');
  const timeline = page.getByRole('region', { name: 'Production timeline' });
  const buttons = timeline.getByRole('button');
  await expect(buttons).toHaveCount(3);
  const boxes = await buttons.evaluateAll((elements) =>
    elements.map((element) => ({
      y: element.getBoundingClientRect().y,
      date: element.getAttribute('data-start-date'),
    })),
  );
  expect(new Set(boxes.map((box) => box.y)).size).toBe(3);
  expect(boxes.map((box) => box.date)).toEqual(['2026-10-11', '2026-10-11', '2026-10-11']);
  await timeline.evaluate((element) => {
    element.scrollLeft = 550;
  });
  await page.screenshot({ path: '/tmp/schedule-desktop.png', fullPage: true });
  await page.getByRole('tab', { name: /Schedule list/ }).click();
  await expect(page.getByRole('row').filter({ hasText: 'WO-202610-0011' })).toContainText(
    'SO-202610-0028',
  );
  await expect(page.getByRole('checkbox')).toHaveCount(0);
  await expect(
    page.getByRole('button', { name: /Confirm selected|Review and assign/ }),
  ).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'WO-202610-0011', exact: true })).toHaveAttribute(
    'href',
    '/production/work-orders/wo-0',
  );
  expect(confirmations).toBe(0);
});

test('PPC reviews schedules on phone with WO handoff and no page overflow', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await routes(page);
  await mockAuth(page, {
    ...ROLES.ppc,
    permissions: [...ROLES.ppc.permissions, 'production.schedule.view'],
  });
  await page.goto('/production/schedule');
  await expect(page.getByText(/confirm each proposal from its work-order record/)).toBeVisible();
  await expect(page.getByRole('button', { name: /Confirm selected/ })).toHaveCount(0);
  await page.getByRole('tab', { name: /Schedule list/ }).click();
  await expect(page.getByRole('row').filter({ hasText: 'WO-202610-0013' })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(
    true,
  );
  await page.screenshot({ path: '/tmp/schedule-mobile.png', fullPage: true });
});

test('schedule read failure recovers saved proposals', async ({ page }) => {
  await routes(page);
  let unavailable = true;
  await page.route('**/mrp/scheduler/snapshot?**', (route) =>
    route.fulfill(
      unavailable
        ? { status: 503, json: { message: 'Unavailable' } }
        : { json: { data: snapshot() } },
    ),
  );
  await loginAs(page, 'production', '/production/schedule');
  const alert = page.getByRole('alert').filter({ hasText: 'Schedule could not be loaded' });
  await expect(alert).toBeVisible();
  await expect(page.getByRole('button', { name: /Confirm selected/ })).toHaveCount(0);
  unavailable = false;
  await page.getByRole('button', { name: 'Retry schedule' }).click();
  await expect(alert).toBeHidden();
  await expect(
    page.getByRole('region', { name: 'Production timeline' }).getByRole('button'),
  ).toHaveCount(3);
});
