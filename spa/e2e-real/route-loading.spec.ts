import { expect, test } from '@playwright/test';
import { loginAs } from './helpers';

test('shows a loading status while business policies load on a deep link', async ({ page }) => {
  await loginAs(page, 'finance@ogami.test', 'password');

  const cdp = await page.context().newCDPSession(page);
  await cdp.send('Network.enable');
  await cdp.send('Network.emulateNetworkConditions', {
    offline: false,
    latency: 800,
    downloadThroughput: -1,
    uploadThroughput: -1,
    connectionType: 'wifi',
  });

  try {
    await page.goto('/accounting', { waitUntil: 'domcontentloaded' });
    const main = page.locator('main');
    await main.waitFor({ state: 'visible' });
    await expect(main.getByRole('status', { name: 'Loading' })).toBeVisible({ timeout: 500 });
    await expect(main.getByRole('heading', { name: 'Journal Entries' })).toBeVisible();
  } finally {
    await cdp.detach();
  }
});
