import { expect, test } from '@playwright/test';
import { loginAs } from './helpers-extended';

/**
 * W1.3 — the approvals surface at both widths.
 *
 * The plan's example was "`vice_president` approves from a phone". Approving is
 * a per-module detail action, but the whole approvals module shares one dialog,
 * so the rule can be checked here without standing up a record from every
 * module: the delegation card's revoke confirm is a `ConfirmDialog`, which is a
 * `Modal`, which is the same primitive every approval prompt uses.
 *
 * Two assertions, because either alone is weak: the presentation attribute says
 * the rule ran, and the geometry says the result is actually a sheet — a
 * `data-presentation="sheet"` panel that still floats in the middle of a 390px
 * viewport would satisfy the first and fail a user.
 */
const DELEGATION = {
  id: 'delegation-1',
  role_slug: 'finance_officer',
  starts_at: '2026-10-01',
  ends_at: '2026-10-31',
  reason: 'Annual leave cover',
  is_active: true,
  role_is_current: true,
  can_revoke: true,
  delegator: { id: 'user-delegator', name: 'Maria Santos', is_active: true },
  delegate: { id: 'user-delegate', name: 'Juan Bautista', is_active: true },
  created_at: null,
  updated_at: null,
};

async function openRevokeConfirm(page: import('@playwright/test').Page) {
  await page.route('**/api/v1/**', (route) => route.fulfill({ json: { data: [] } }));
  await page.route('**/api/v1/approval-delegations', (route) =>
    route.fulfill({ json: { data: [DELEGATION] } }),
  );
  await loginAs(page, 'finance', '/approvals/delegations');

  await page.getByRole('button', { name: 'Revoke', exact: true }).click();
  return page.getByRole('dialog');
}

test('an approval prompt is a centred dialog on a desktop', async ({ page }) => {
  const dialog = await openRevokeConfirm(page);
  await expect(dialog.locator('[data-presentation]')).toHaveAttribute('data-presentation', 'dialog');
  await expect(dialog.getByRole('button', { name: 'Revoke cover' })).toBeVisible();
});

test('the same approval prompt is a bottom sheet on a phone', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  const dialog = await openRevokeConfirm(page);

  const panel = dialog.locator('[data-presentation="sheet"]');
  await expect(panel).toBeVisible();

  // Polled: the sheet rises on a keyframe, so a first measurement lands low.
  await expect
    .poll(
      async () => {
        const box = await panel.boundingBox();
        return box
          ? { x: Math.round(box.x), width: Math.round(box.width), bottom: Math.round(box.y + box.height) }
          : null;
      },
      { timeout: 3_000 },
    )
    .toEqual({ x: 0, width: 390, bottom: 844 });

  // The destructive action is still reachable with a thumb: 44px floor on the
  // dialog's own footer controls, which is where the confirm lives.
  const confirm = dialog.getByRole('button', { name: 'Revoke cover' });
  const confirmBox = await confirm.boundingBox();
  expect(confirmBox!.height).toBeGreaterThanOrEqual(44);
});
