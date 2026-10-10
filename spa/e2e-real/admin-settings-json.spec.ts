import { expect, test, type Page } from '@playwright/test';
import { baseUrl, captureNetwork, loginAs, relevantErrors, statefulHeaders } from './helpers';

const settingKey = 'alerts.critical.notification_roles';
const settingLabel = 'Critical Alert Notification Roles';

async function criticalAlertRoles(page: Page): Promise<Record<string, unknown>> {
  const response = await page.request.get(`${baseUrl()}/api/v1/admin/settings`, {
    headers: statefulHeaders(),
  });
  expect(response.ok(), 'admin settings API must load').toBe(true);

  const body = (await response.json()) as {
    data: Record<string, Array<{ key: string; value: unknown }>>;
  };
  const row = body.data.alerts?.find((setting) => setting.key === settingKey);
  expect(row, `${settingKey} must exist in the seeded settings`).toBeDefined();
  expect(row?.value).toEqual(expect.any(Object));

  return row?.value as Record<string, unknown>;
}

test('admin can find and update JSON settings through the real settings page', async ({ page }) => {
  const network = captureNetwork(page);
  let original: Record<string, unknown> | undefined;

  try {
    await loginAs(page, 'admin@ogami.test', 'password');
    const savedRoles = await criticalAlertRoles(page);
    original = savedRoles;

    await page.goto('/admin/settings');
    await expect(page.getByRole('heading', { name: 'Settings & Configuration' })).toBeVisible();
    await page.getByRole('button', { name: 'Unlock to Edit' }).click();
    await page.getByRole('textbox', { name: 'Search settings' }).fill(settingLabel);
    await page.getByRole('button', { name: 'Other Settings' }).click();

    const editor = page.getByRole('textbox', { name: `${settingLabel} JSON` });
    await expect(editor).toBeVisible();
    await page.setViewportSize({ width: 375, height: 812 });
    await expect(editor).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await page.setViewportSize({ width: 1280, height: 900 });

    const malformed = '{ invalid JSON';
    await editor.fill(malformed);
    await editor.blur();
    await expect(page.getByRole('alert')).toHaveText('Enter valid JSON before saving.');
    await expect(editor).toHaveValue(malformed);
    await expect.poll(() => criticalAlertRoles(page)).toEqual(savedRoles);

    await editor.fill('[]');
    await editor.blur();
    await expect(page.getByRole('alert')).toHaveText('Value must remain a JSON object.');
    await expect(editor).toHaveValue('[]');
    await expect.poll(() => criticalAlertRoles(page)).toEqual(savedRoles);

    const changed = { ...savedRoles, audit_ui_probe: ['system_admin'] };
    await editor.fill(JSON.stringify(changed, null, 2));
    await editor.blur();

    await expect(page.getByText(`Saved setting ${settingKey}`)).toBeVisible();
    await expect.poll(() => criticalAlertRoles(page)).toEqual(changed);
  } finally {
    const editor = page.getByRole('textbox', { name: `${settingLabel} JSON` });
    if (original && await editor.count()) {
      await editor.fill(JSON.stringify(original, null, 2));
      await editor.blur();
      await expect.poll(() => criticalAlertRoles(page)).toEqual(original);
    }
    network.stop();
  }

  expect(network.failedRequests).toEqual([]);
  expect(relevantErrors(network)).toEqual([]);
});
