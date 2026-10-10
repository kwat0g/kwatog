import { expect, test } from '@playwright/test';
import { captureNetwork, loginAs, relevantErrors, ROLES } from './helpers';

test('unvisited module peek expires at its header but stays open over its links', async ({ page }) => {
  const network = captureNetwork(page);
  const pageErrors: string[] = [];
  page.on('pageerror', (error) => pageErrors.push(error.message));

  try {
    const admin = ROLES.find((role) => role.slug === 'system_admin')!;
    await loginAs(page, admin.email, admin.password);
    await page.goto('/mrp/plans');

    const sidebar = page.getByLabel('Sidebar');
    const production = sidebar.getByRole('button', { name: /^Production(?:$|:)/ });
    await expect(production).toHaveAttribute('aria-expanded', 'false');

    await production.click();
    await expect(production).toHaveAttribute('aria-expanded', 'true');
    await page.waitForTimeout(5200);
    await expect(production).toHaveAttribute('aria-expanded', 'false');

    await production.click();
    const links = production.locator('xpath=following-sibling::ul');
    await links.getByRole('link').first().hover();
    await page.waitForTimeout(5200);
    await expect(production).toHaveAttribute('aria-expanded', 'true');

    await page.mouse.move(1000, 100);
    await page.waitForTimeout(700);
    await expect(production).toHaveAttribute('aria-expanded', 'false');

    expect(relevantErrors(network)).toEqual([]);
    expect(network.failedRequests).toEqual([]);
    expect(pageErrors).toEqual([]);
  } finally {
    network.stop();
  }
});

test('mobile sidebar traps tabbable focus and closes on Escape', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  const admin = ROLES.find((role) => role.slug === 'system_admin')!;
  await loginAs(page, admin.email, admin.password);
  const network = captureNetwork(page);
  const pageErrors: string[] = [];
  page.on('pageerror', (error) => pageErrors.push(error.message));

  try {
    await page.goto('/admin/users');
    await expect(page).toHaveURL(/\/admin\/users$/);
    await page.getByRole('button', { name: 'Toggle sidebar' }).click();
    const drawer = page.getByRole('dialog', { name: 'Menu' });
    await expect(drawer).toBeVisible();
    const controls = drawer.locator(
      'button:not([disabled]):not([tabindex="-1"]), a[href]:not([tabindex="-1"]), input:not([disabled]):not([type="hidden"]):not([tabindex="-1"]), select:not([disabled]):not([tabindex="-1"]), textarea:not([disabled]):not([tabindex="-1"]), [tabindex]:not([tabindex="-1"])',
    );
    const first = controls.first();
    const last = controls.last();
    await expect.poll(() => controls.count()).toBeGreaterThan(1);

    await last.focus();
    await page.keyboard.press('Tab');
    await expect(first).toBeFocused();
    await page.keyboard.press('Shift+Tab');
    await expect(last).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(drawer).toHaveCount(0);
    expect(network.failedRequests).toEqual([]);
    expect(relevantErrors(network)).toEqual([]);
    expect(pageErrors).toEqual([]);
  } finally {
    network.stop();
  }
});
