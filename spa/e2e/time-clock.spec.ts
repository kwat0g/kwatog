/**
 * E2E: Time in/out with geolocation and distance validation.
 *
 * Tests geofence enforcement (200 m radius from plant coordinates),
 * location capture on punch, and out-of-range rejection with server reason.
 */
import { test, expect } from './fixtures';
import { loginAs } from './helpers-extended';

const STATUS = {
  next_action: 'in',
  shift: { name: 'Day', start: '06:00', end: '14:00' },
  punches: [],
  tardiness_minutes: null,
  radius_m: 200,
};

test.describe('Time in/out', () => {
  test('sends the browser GPS fix when the employee taps Time In', async ({ page, context }) => {
    await context.grantPermissions(['geolocation']);
    await context.setGeolocation({ latitude: 14.286, longitude: 120.9345, accuracy: 15 });

    let body: Record<string, number> | null = null;
    await page.route('**/api/v1/attendance/punch/status', (r) => r.fulfill({ json: { data: STATUS } }));
    await page.route('**/api/v1/attendance/punch', async (r) => {
      body = r.request().postDataJSON();
      await r.fulfill({ status: 201, json: { data: { direction: 'in', punched_at: new Date().toISOString(), distance_m: '3.00', status: { ...STATUS, next_action: 'out' } } } });
    });

    await loginAs(page, 'employee', '/self-service');
    await page.getByRole('button', { name: /time in/i }).click();

    await expect.poll(() => body).not.toBeNull();
    expect(body).toMatchObject({ lat: 14.286, lng: 120.9345 });
  });

  test('shows the server reason when out of range', async ({ page, context }) => {
    await context.grantPermissions(['geolocation']);
    await context.setGeolocation({ latitude: 14.5, longitude: 121.0, accuracy: 10 });

    await page.route('**/api/v1/attendance/punch/status', (r) => r.fulfill({ json: { data: STATUS } }));
    await page.route('**/api/v1/attendance/punch', (r) =>
      r.fulfill({ status: 422, json: { message: 'You are 26000 m from the plant. Time in/out works only within 200 m.', code: 'out_of_range' } }));

    await loginAs(page, 'employee', '/self-service');
    await page.getByRole('button', { name: /time in/i }).click();

    await expect(page.getByRole('alert')).toContainText('26000 m from the plant');
  });
});
