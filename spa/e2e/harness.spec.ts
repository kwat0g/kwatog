import { expect, test } from './fixtures';

test('Vite E2E server keeps its hot-reload socket on the test origin', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  page.on('console', (message) => {
    if (message.type() === 'error') errors.push(message.text());
  });

  await page.goto('/privacy');
  await page.waitForTimeout(1_000);

  expect(errors.filter((error) => /websocket|connect-src|can't establish a connection/i.test(error))).toEqual([]);
});
