import { expect, test } from '@playwright/test';
import { captureNetwork, loginAs } from './helpers';

test('denied factory routes render their not-found state inside a main landmark', async ({ page }) => {
  await loginAs(page, 'ppc@ogami.test', 'password');
  const network = captureNetwork(page);

  for (const route of ['/factory', '/factory/qc']) {
    await page.goto(route, { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { name: 'Page not found' })).toBeVisible();
    await expect(page.getByRole('main')).toHaveCount(1);
  }

  expect(network.errors).toEqual([]);
  expect(network.failedRequests).toEqual([]);
  network.stop();
});
