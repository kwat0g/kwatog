import { readFileSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import { mockAuth, USERS } from './helpers';

/**
 * Per-widget export, asserted through the interface a user touches (F-017).
 *
 * The unit tests prove the row builder; this proves the wiring — that the tile
 * header actually carries the control, that it is named after its widget, and
 * that clicking it downloads a file whose CONTENTS are the numbers on screen.
 * A mocked-download assertion alone would pass for a button that exported the
 * wrong widget.
 */

const BREAKDOWN = {
  key: 'quality.inspection_queue',
  name: 'Inspection queue',
  description: null,
  module: 'quality',
  permission: null,
  permission_any: null,
  render_kind: 'breakdown',
  link_path: '/quality/inspections',
  data: {
    total: 9,
    kind: 'count',
    segments: [
      { label: 'Incoming', value: 5, tone: 'neutral' },
      { label: 'In progress', value: 4, tone: 'warning' },
    ],
  },
  x: 0,
  y: 0,
  w: 6,
  h: 5,
  source: 'role',
} as const;

const TABLE = {
  key: 'purchasing.open_pos',
  name: 'Open purchase orders',
  description: null,
  module: 'purchasing',
  permission: null,
  permission_any: null,
  render_kind: 'table',
  link_path: '/purchasing/purchase-orders',
  // 2 of 6 — the tile truncates, and the export has to say so.
  data: {
    columns: [
      { key: 'number', label: 'Document' },
      { key: 'amount', label: 'Amount', numeric: true },
    ],
    rows: [
      { number: 'PO-2026-0001', amount: 1000 },
      { number: 'PO-2026-0002', amount: null },
    ],
    total_count: 6,
  },
  x: 6,
  y: 0,
  w: 6,
  h: 5,
  source: 'role',
} as const;

const SUMMARY = {
  'quality.inspection_queue': {
    key: 'quality.inspection_queue',
    value: '9',
    kind: 'count',
    helper: null,
    available: true,
    updated_at: '2026-10-17T02:00:00Z',
  },
  'purchasing.open_pos': {
    key: 'purchasing.open_pos',
    value: '6',
    kind: 'count',
    helper: null,
    available: true,
    updated_at: '2026-10-17T02:00:00Z',
  },
};

async function openRichDashboard(page: import('@playwright/test').Page) {
  await mockAuth(page, USERS.qc);

  // Registered after mockAuth on purpose: Playwright gives the most recently
  // added matching route priority, so these win over the helper's empty layout.
  await page.route('**/api/v1/dashboard/layout**', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: [BREAKDOWN, TABLE],
        meta: { layout_version: 'widget-export-spec' },
      }),
    }),
  );
  await page.route('**/api/v1/dashboard/widget-data**', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: SUMMARY }),
    }),
  );

  await page.goto('/dashboard/default');
  await expect(page.getByRole('heading', { name: 'Inspection queue' })).toBeVisible();
}

test('every widget tile carries an export control named after its widget', async ({ page }) => {
  await openRichDashboard(page);

  await expect(page.getByRole('button', { name: 'Export Inspection queue as CSV' })).toBeVisible();
  await expect(
    page.getByRole('button', { name: 'Export Open purchase orders as CSV' }),
  ).toBeVisible();
});

test('the breakdown tile downloads the segments and total it draws', async ({ page }) => {
  await openRichDashboard(page);

  const [download] = await Promise.all([
    page.waitForEvent('download'),
    page.getByRole('button', { name: 'Export Inspection queue as CSV' }).click(),
  ]);

  expect(download.suggestedFilename()).toBe('inspection-queue-2026-10-17.csv');

  const csv = readFileSync((await download.path())!, 'utf8');
  expect(csv.split('\r\n')).toEqual([
    'Segment,Value,Share of total (%)',
    'Incoming,5,55.6',
    'In progress,4,44.4',
    'Total,9,',
  ]);
});

test('the table tile states its own truncation instead of passing off a partial list', async ({
  page,
}) => {
  await openRichDashboard(page);

  const [download] = await Promise.all([
    page.waitForEvent('download'),
    page.getByRole('button', { name: 'Export Open purchase orders as CSV' }).click(),
  ]);

  expect(download.suggestedFilename()).toBe('open-purchase-orders-2026-10-17.csv');

  const csv = readFileSync((await download.path())!, 'utf8');
  expect(csv.split('\r\n')).toEqual([
    'Document,Amount',
    'PO-2026-0001,1000',
    'PO-2026-0002,',
    'Showing 2 of 6,',
  ]);
});
