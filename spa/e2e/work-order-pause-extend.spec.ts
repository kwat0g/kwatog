import { test, expect } from '@playwright/test';
import { mockAuth } from './helpers';
import { ROLES } from './helpers-extended';

const PM = ROLES.production;

const baseWo = {
  id: 'wo-pe',
  wo_number: 'WO-202610-0001',
  production_purpose: 'order',
  status: 'in_progress',
  status_label: 'In progress',
  product: { id: 'product', part_number: 'WB-001', name: 'Wiper bushing' },
  quantity_target: 200,
  quantity_produced: 50,
  quantity_good: 50,
  quantity_rejected: 0,
  progress_percentage: 25,
  scrap_rate: '0.00',
  actual_start: '2026-10-06T01:00:00Z',
  actual_end: null,
  planned_start: '2026-10-06T01:00:00Z',
  planned_end: '2026-10-06T02:00:00Z',
  materials: [],
  outputs: [],
  inspections: [],
  children: [],
  next_statuses: [],
  material_lot_references: [],
  pause_reason: null,
  sales_order: null,
  machine: { id: 'machine', machine_code: 'IMM-03', name: 'Sumitomo' },
  mold: { id: 'mold', mold_code: 'M-WB-001', name: 'Wiper bushing mold' },
};

async function mockCommon(page: import('@playwright/test').Page, woId: string) {
  await page.route('**/api/v1/**', (route) =>
    route.fulfill({ json: { data: [], meta: { total: 0, unread_count: 0 } } }),
  );
  await mockAuth(page, PM);
  await page.route(`**/api/v1/production/work-orders/${woId}`, (route) =>
    route.fulfill({ json: { data: { ...baseWo, id: woId } } }),
  );
  await page.route(`**/api/v1/production/work-orders/${woId}/crew`, (route) =>
    route.fulfill({
      json: {
        data: {
          current: null,
          history: [],
          can_assign: false,
          minimum_workers: 1,
          maximum_workers: 1,
          schedule: null,
        },
      },
    }),
  );
}

test('production manager pauses with a breakdown warning, then a clean category', async ({
  page,
}) => {
  await mockCommon(page, 'wo-pe-1');
  await page.route('**/api/v1/production/downtime-categories', (route) =>
    route.fulfill({
      json: {
        data: [
          { value: 'breakdown', label: 'Breakdown', is_planned: false },
          { value: 'material_shortage', label: 'Material shortage', is_planned: false },
        ],
      },
    }),
  );
  let pausedBody: unknown = null;
  await page.route('**/api/v1/production/work-orders/wo-pe-1/pause', async (route) => {
    pausedBody = route.request().postDataJSON();
    await route.fulfill({ json: { data: { ...baseWo, id: 'wo-pe-1', status: 'paused' } } });
  });

  await page.goto('/production/work-orders/wo-pe-1');
  await page.getByRole('button', { name: 'Pause', exact: true }).click();
  const dialog = page.getByRole('dialog');

  await dialog.getByLabel(/Downtime category/).selectOption('breakdown');
  await expect(dialog.getByText(/corrective maintenance/)).toBeVisible();

  await dialog.getByLabel(/Downtime category/).selectOption('material_shortage');
  await expect(dialog.getByText(/corrective maintenance/)).toHaveCount(0);

  await dialog.getByLabel(/Reason/).fill('Resin feed stopped.');
  await dialog.getByRole('button', { name: 'Pause work order' }).click();

  await expect(dialog).toHaveCount(0);
  expect(pausedBody).toEqual({ reason: 'Resin feed stopped.', category: 'material_shortage' });
});

test('production manager extends a run and sends the edited reason', async ({ page }) => {
  await mockCommon(page, 'wo-pe-2');
  await page.route('**/api/v1/production/work-orders/wo-pe-2/execution-readiness', (route) =>
    route.fulfill({
      json: {
        data: {
          ready: true,
          needs_reschedule: false,
          can_review_slot: true,
          blockers: [],
          presence: [],
          expected_crew_id: 'crew-1',
          start_mode: 'extend',
          execution_end: '2026-10-06T12:00:00+08:00',
          schedule: {
            id: 'slot-1',
            updated_at: '2026-10-06T08:00:00+08:00',
            scheduled_start: '2026-10-06T08:00:00+08:00',
            scheduled_end: '2026-10-06T10:00:00+08:00',
            machine_id: 'machine',
            mold_id: 'mold',
          },
        },
      },
    }),
  );
  let extendedBody: Record<string, unknown> | null = null;
  await page.route('**/api/v1/production/work-orders/wo-pe-2/extend-run', async (route) => {
    extendedBody = route.request().postDataJSON();
    await route.fulfill({ json: { data: { ...baseWo, id: 'wo-pe-2' } } });
  });

  await page.goto('/production/work-orders/wo-pe-2');
  await page.getByRole('button', { name: 'Extend run' }).click();
  const dialog = page.getByRole('dialog');

  const reason = dialog.getByLabel(/Extension reason/);
  await expect(reason).toHaveValue('Approved remaining production time extension.');
  await reason.fill('   ');
  await expect(dialog.getByRole('button', { name: 'Approve extension' })).toBeDisabled();

  await reason.fill('Machine slowed after mold change.');
  await dialog.getByRole('button', { name: 'Approve extension' }).click();

  await expect(dialog).toHaveCount(0);
  expect(extendedBody).toMatchObject({
    expected_crew_id: 'crew-1',
    expected_schedule_id: 'slot-1',
    scheduled_end: '2026-10-06T12:00:00+08:00',
    reason: 'Machine slowed after mold change.',
  });
});
