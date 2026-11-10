import { expect, test } from '@playwright/test';
import { loginViaUi, resolveIsolatedPortalEmail } from './helpers';

const portals = [
  {
    realm: 'customer',
    actor: 'customer_portal',
    email: 'portal@cust.test',
    navigation: 'Customer Portal navigation',
  },
  {
    realm: 'supplier',
    actor: 'supplier_portal',
    email: 'portal@supp.test',
    navigation: 'Supplier Portal navigation',
  },
] as const;

for (const portal of portals) {
  test(`${portal.realm} account can skip and replay its first-run walkthrough`, async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });

    await page.goto(`/portal/${portal.realm}/login`, { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/sign-in$/);
    await loginViaUi(page, await resolveIsolatedPortalEmail(portal.actor, portal.email), 'password');
    await expect(page).toHaveURL(new RegExp(`/portal/${portal.realm}$`));

    const guide = page.getByRole('dialog');
    await expect(guide.getByRole('heading', { name: `Welcome to your ${portal.realm} portal` })).toBeVisible();
    await expect(guide.getByRole('button', { name: 'Start walkthrough' })).toBeVisible();
    const bounds = await guide.boundingBox();
    expect(bounds).not.toBeNull();
    expect(bounds!.x).toBeGreaterThanOrEqual(0);
    expect(bounds!.x + bounds!.width).toBeLessThanOrEqual(390);

    await guide.getByRole('button', { name: 'Skip walkthrough' }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await page.getByRole('button', { name: 'Open portal navigation' }).click();
    await expect(page.getByRole('navigation', { name: portal.navigation })).toBeVisible();
    await page.getByRole('complementary')
      .getByRole('button', { name: 'Close portal navigation' }).click();
    await page.getByRole('button', { name: 'Help' }).click();
    await expect(page.getByRole('dialog').getByRole('heading', {
      name: `Welcome to your ${portal.realm} portal`,
    })).toBeVisible();

    await page.getByRole('dialog').getByRole('button', { name: 'Close' }).click();
    await page.getByRole('button', { name: 'Open portal navigation' }).click();
    await page.getByRole('button', { name: 'Sign out', exact: true }).click();
    await expect(page).toHaveURL(/\/sign-in$/);
  });
}
