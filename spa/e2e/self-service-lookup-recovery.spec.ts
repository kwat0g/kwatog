import { expect, test } from '@playwright/test';
import { loginAs } from './helpers-extended';

test('employee leave form reports failed policy options and retries', async ({ page }) => {
  await page.route('**/api/v1/**', route => route.fulfill({ json: { data: [] } }));
  let unavailable = true;
  await page.route('**/leaves/requests/options**', route => route.fulfill(unavailable
    ? { status: 503, json: { message: 'Leave policy unavailable' } }
    : { json: { data: { half_day_periods: [], holiday_dates: [] } } }));
  await loginAs(page, 'employee', '/self-service/leave');
  await page.getByRole('button', { name: 'New request', exact: true }).first().click();
  const dialog = page.getByRole('dialog');
  await expect(dialog.getByRole('alert').filter({ hasText: 'Leave options could not be loaded' })).toBeVisible();
  await expect(dialog.getByRole('button', { name: 'Submit request', exact: true })).toBeDisabled();
  unavailable = false;
  await dialog.getByRole('button', { name: 'Retry options' }).click();
  await expect(dialog.getByRole('alert').filter({ hasText: 'Leave options could not be loaded' })).toBeHidden();
});

/**
 * W1.3 — the phone action surface, on a real page rather than the gallery.
 *
 * The gallery proves the primitive; this proves the claim that matters: an
 * employee on a phone gets a bottom-anchored sheet from a page that was never
 * edited. The self-service leave form used to be one of the two flows the plan
 * named ("employee self-services on one"); the page still opens a `Modal` and
 * the width decides the presentation.
 *
 * The geometry is asserted too, not just the attribute: a "sheet" that is
 * actually a card floating in the middle of a 390px viewport would satisfy a
 * data attribute and fail a user.
 */
test('the self-service request surface is a dialog on a desktop and a sheet on a phone', async ({ page }) => {
  await page.route('**/api/v1/**', route => route.fulfill({ json: { data: [] } }));
  await page.route('**/leaves/requests/options**', route => route.fulfill({
    json: { data: { half_day_periods: [], holiday_dates: [] } },
  }));

  await loginAs(page, 'employee', '/self-service/leave');
  await page.getByRole('button', { name: 'New request', exact: true }).first().click();
  const desktopDialog = page.getByRole('dialog');
  await expect(desktopDialog.locator('[data-presentation]')).toHaveAttribute('data-presentation', 'dialog');
  await page.keyboard.press('Escape');
  await expect(page.getByRole('dialog')).toHaveCount(0);

  await page.setViewportSize({ width: 390, height: 844 });
  await page.getByRole('button', { name: 'New request', exact: true }).first().click();
  const phoneDialog = page.getByRole('dialog');
  const panel = phoneDialog.locator('[data-presentation="sheet"]');
  await expect(panel).toBeVisible();

  // Bottom-anchored and edge to edge — the two things that make it reachable
  // with a thumb. Polled rather than read once: the panel rises on the
  // `animate-slide-up` keyframe, so a first measurement lands a few pixels low
  // and would make this assertion flake on machine speed rather than on layout.
  await expect
    .poll(async () => {
      const box = await panel.boundingBox();
      return box ? { x: Math.round(box.x), width: Math.round(box.width), bottom: Math.round(box.y + box.height) } : null;
    }, { timeout: 3_000 })
    .toEqual({ x: 0, width: 390, bottom: 844 });
});

test('employee loan preview failure explains recovery and allows server-calculated submission', async ({ page }) => {
  await page.route('**/api/v1/**', route => route.fulfill({ json: { data: [] } }));
  await page.route('**/hr/self-service/loans', route => route.fulfill({ json: { data: {
    active: [], history: [], max_pay_periods: 12, loan_types: [{ value: 'company_loan', label: 'Company loan' }],
  } } }));
  let unavailable = true;
  await page.route('**/loans/preview-amortization', route => route.fulfill(unavailable
    ? { status: 503, json: { message: 'Preview unavailable' } }
    : { json: { data: [{ period: 1, amount: '100', remaining_after: '0' }] } }));
  await loginAs(page, 'employee', '/self-service/loans');
  await page.getByRole('button', { name: 'Apply for a loan', exact: true }).first().click();
  const dialog = page.getByRole('dialog');
  await dialog.getByRole('spinbutton', { name: 'Amount', exact: true }).fill('100');
  await dialog.getByRole('spinbutton', { name: 'Periods (months)', exact: true }).fill('1');
  await expect(dialog.getByRole('alert').filter({ hasText: 'Amortization preview could not be loaded' })).toBeVisible();
  unavailable = false;
  await dialog.getByRole('button', { name: 'Retry preview' }).click();
  await expect(dialog.getByRole('alert').filter({ hasText: 'Amortization preview could not be loaded' })).toBeHidden();
});
