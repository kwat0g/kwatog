import { expect, test } from '@playwright/test';
import { captureNetwork, loginAs, relevantErrors } from './helpers';

test('system role editor is read-only and omits retired self-post permission', async ({ page }) => {
  const network = captureNetwork(page);

  await loginAs(page, 'admin@ogami.test', 'password');
  await page.goto('/admin/roles');
  await expect(page.getByRole('heading', { name: 'Roles & permissions' })).toBeVisible();
  await page.getByRole('button', { name: 'Edit permissions for System Administrator' }).click();

  await expect(page.getByRole('heading', { name: 'System Administrator permissions' })).toBeVisible();
  await expect(page.getByText('System role — read-only')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Save changes' })).toBeDisabled();

  await page
    .getByRole('textbox', { name: 'Search permissions' })
    .fill('accounting.journal.self_post_override');
  await expect(page.getByRole('heading', { name: 'No matching permissions' })).toBeVisible();
  await expect(page.getByText(/accounting\.journal\.self_post_override/)).toHaveCount(0);

  await page.setViewportSize({ width: 375, height: 812 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);

  network.stop();
  expect(network.failedRequests).toEqual([]);
  expect(relevantErrors(network)).toEqual([]);
});
