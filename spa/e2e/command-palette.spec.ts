/**
 * M009-F15 — command-palette browser accessibility contract.
 *
 * The component tests cover the Tab loop with synthetic jsdom events. This
 * spec deliberately drives a real browser so native Tab traversal, the modal
 * boundary, and focus restoration are all exercised together.
 */
import { test, expect } from './fixtures';
import { loginAs } from './helpers-extended';

const FOCUSABLE_SELECTOR =
  'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

test.describe('command palette accessibility', () => {
  test('keeps real keyboard focus inside the modal and restores its opener', async ({ page }) => {
    await loginAs(page, 'admin', '/dashboard/default');
    await page.waitForFunction(() => {
      const main = document.getElementById('main-content');
      return main instanceof HTMLElement && main.contains(document.activeElement);
    });

    const opener = page.locator('header button').filter({ hasText: 'Search…' });
    await expect(opener).toBeVisible();
    await opener.click();

    const dialog = page.getByRole('dialog', { name: 'Global search' });
    await expect(dialog).toBeVisible();
    await expect(dialog).toHaveAttribute('aria-modal', 'true');

    const focusable = dialog.locator(FOCUSABLE_SELECTOR);
    const focusableCount = await focusable.count();
    expect(focusableCount).toBeGreaterThan(1);
    await expect(focusable.first()).toBeFocused();

    const focusState = () => dialog.evaluate((element, selector) => {
      const elements = Array.from(element.querySelectorAll<HTMLElement>(selector));
      const active = document.activeElement;
      return {
        inside: element.contains(active),
        index: elements.indexOf(active as HTMLElement),
        active: active instanceof HTMLElement
          ? {
            tag: active.tagName.toLowerCase(),
            tabIndex: active.tabIndex,
            ariaLabel: active.getAttribute('aria-label'),
            flatIndex: active.getAttribute('data-flat-index'),
          }
          : active?.nodeName ?? 'none',
      };
    }, FOCUSABLE_SELECTOR);

    // Walk the native browser Tab order until the production trap wraps. The
    // number of selector-matched controls is only an upper bound: browsers
    // can differ in which off-screen controls they include in native order.
    let wrappedForward = false;
    for (let step = 1; step <= focusableCount + 2; step += 1) {
      await page.keyboard.press('Tab');
      const state = await focusState();
      expect(state.inside, `native Tab ${step} escaped the dialog`).toBe(true);
      if (state.index === 0) {
        wrappedForward = true;
        break;
      }
    }
    expect(wrappedForward).toBe(true);

    // At the forward boundary, the production key handler won over the
    // browser's default move to controls behind the modal and wrapped to the
    // first control.
    await expect(focusable.first()).toBeFocused();

    // The reverse boundary must wrap back to the actual last native control.
    await page.keyboard.press('Shift+Tab');
    const reverseState = await focusState();
    expect(reverseState.inside).toBe(true);
    await expect(focusable.last()).toBeFocused();

    // Escape closes the dialog and returns focus to the actual opener.
    await page.keyboard.press('Escape');
    await expect(dialog).toHaveCount(0);
    await expect(opener).toBeFocused();
  });
});
