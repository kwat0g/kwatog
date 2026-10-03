import { randomUUID } from 'node:crypto';
import { expect, test } from '@playwright/test';
import {
  baseUrl,
  captureNetwork,
  loginAs,
  relevantErrors,
  statefulHeaders,
} from './helpers';

type ScheduledExportRow = {
  id: string;
  name: string;
  module: string;
  columns: string[];
  recipients: string[];
  next_run_at: string;
};

test('HR Officer creates and archives an employee export schedule through the UI', async ({ page }) => {
  const network = captureNetwork(page);
  const name = `Audit scheduled export ${randomUUID()}`;
  const base = baseUrl();
  const listUrl = `${base}/api/v1/scheduled-exports`;
  const archivedListUrl = `${listUrl}?trashed=only`;
  const apiHeaders = statefulHeaders();

  try {
    await loginAs(page, 'hr@ogami.test', 'password');
    await page.goto('/admin/scheduled-exports');
    await expect(page.getByRole('heading', { name: 'Scheduled exports', exact: true })).toBeVisible();

    await page.getByRole('button', { name: 'New schedule' }).click();
    await expect(page.getByText('New scheduled export', { exact: true })).toBeVisible();
    await expect(page.getByText('Employee No.')).toBeVisible();

    await page.getByLabel('Schedule name').fill(name);
    await page.getByLabel('Recipients').fill('audit-schedule@ogami.test');
    await page.getByRole('button', { name: 'Create schedule' }).click();
    await expect(page.getByText(/Saved\. Next run:/)).toBeVisible();

    await page.getByRole('button', { name: 'Cancel' }).click();
    const row = page.getByRole('row').filter({ hasText: name });
    await expect(row).toBeVisible();

    const listResponse = await page.request.get(listUrl, { headers: apiHeaders });
    expect(listResponse.status()).toBe(200);
    const listBody = (await listResponse.json()) as { data: ScheduledExportRow[] };
    const created = listBody.data.find((schedule) => schedule.name === name);
    expect(created).toMatchObject({
      name,
      module: 'hr.employees',
      recipients: ['audit-schedule@ogami.test'],
    });
    expect(created?.columns.length).toBeGreaterThan(0);
    expect(created?.next_run_at).toBeTruthy();

    await row.getByRole('button', { name: 'Archive' }).click();
    await expect(page.getByRole('heading', { name: 'Archive scheduled export?' })).toBeVisible();
    await page.getByRole('button', { name: 'Archive', exact: true }).last().click();
    await expect(page.getByText('Scheduled export archived.')).toBeVisible();
    await expect(row).toHaveCount(0);

    const activeResponse = await page.request.get(listUrl, { headers: apiHeaders });
    expect(activeResponse.status()).toBe(200);
    const activeBody = (await activeResponse.json()) as { data: ScheduledExportRow[] };
    expect(activeBody.data.some((schedule) => schedule.name === name)).toBe(false);

    const archivedResponse = await page.request.get(archivedListUrl, { headers: apiHeaders });
    expect(archivedResponse.status()).toBe(200);
    const archivedBody = (await archivedResponse.json()) as { data: ScheduledExportRow[] };
    expect(archivedBody.data.find((schedule) => schedule.name === name)).toBeDefined();

    await page.getByRole('radio', { name: 'Show archived records only' }).click();
    const archivedRow = page.getByRole('row').filter({ hasText: name });
    await expect(archivedRow).toBeVisible();
    await archivedRow.getByRole('button', { name: 'Restore' }).click();
    await expect(page.getByRole('heading', { name: 'Restore scheduled export?' })).toBeVisible();
    await page.getByRole('button', { name: 'Restore', exact: true }).last().click();
    await expect(page.getByText('Scheduled export restored.')).toBeVisible();

    await page.getByRole('radio', { name: 'Show active records' }).click();
    const restoredRow = page.getByRole('row').filter({ hasText: name });
    await expect(restoredRow).toBeVisible();
    await restoredRow.getByRole('button', { name: 'Archive' }).click();
    await expect(page.getByRole('heading', { name: 'Archive scheduled export?' })).toBeVisible();
    await page.getByRole('button', { name: 'Archive', exact: true }).last().click();
    await expect(page.getByText('Scheduled export archived.')).toBeVisible();
    await expect(restoredRow).toHaveCount(0);
  } finally {
    const listResponse = await page.request.get(listUrl, { headers: apiHeaders });
    if (listResponse.ok()) {
      const listBody = (await listResponse.json()) as { data: ScheduledExportRow[] };
      const leftover = listBody.data.find((schedule) => schedule.name === name);
      if (leftover) {
        await page.request.get(`${base}/sanctum/csrf-cookie`, { headers: apiHeaders });
        const xsrf = (await page.context().cookies(base)).find((cookie) => cookie.name === 'XSRF-TOKEN');
        expect(xsrf).toBeTruthy();
        const cleanupResponse = await page.request.delete(
          `${base}/api/v1/scheduled-exports/${leftover.id}`,
          {
            headers: {
              ...apiHeaders,
              'X-XSRF-TOKEN': decodeURIComponent(xsrf!.value),
            },
          },
        );
        expect(cleanupResponse.status()).toBe(204);
      }
    }
    network.stop();
  }

  expect(network.failedRequests).toEqual([]);
  expect(relevantErrors(network)).toEqual([]);
});
