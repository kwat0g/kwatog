import { expect, test } from '@playwright/test';
import { baseUrl, captureNetwork, loginAs, ROLES, statefulHeaders } from './helpers';

test('Driver can read and update only their own notification preferences', async ({ page }) => {
  const driver = ROLES.find((candidate) => candidate.slug === 'driver')!;
  await loginAs(page, driver.email, driver.password);
  const net = captureNetwork(page);

  await page.goto('/self-service/notification-preferences');
  await expect(page.getByRole('heading', { name: 'Notification Preferences' })).toBeVisible();

  const getUrl = `${baseUrl()}/api/v1/notification-preferences`;
  const options = await page.request.get(`${getUrl}/options`, { headers: statefulHeaders() });
  expect(options.status()).toBe(200);

  const cookies = await page.context().cookies(baseUrl());
  const xsrf = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN');
  expect(xsrf).toBeTruthy();
  const update = await page.request.put(getUrl, {
    headers: {
      ...statefulHeaders(),
      'X-XSRF-TOKEN': decodeURIComponent(xsrf!.value),
    },
    data: {
      user_id: 'another-user',
      preferences: [{ notification_type: 'leave.approved', channel: 'in_app', enabled: false }],
    },
  });
  expect(update.status()).toBe(200);

  const preferences = await page.request.get(getUrl, { headers: statefulHeaders() });
  expect(preferences.status()).toBe(200);
  expect(await preferences.json()).toMatchObject({
    data: [{ notification_type: 'leave.approved', channel: 'in_app', enabled: false }],
  });
  expect(net.failedRequests).toEqual([]);
  expect(net.errors).toEqual([]);
  net.stop();
});
