/**
 * Base page object — shared locators present on every authenticated page.
 */
import type { Page, Locator } from '@playwright/test';

export class BasePage {
  constructor(public readonly page: Page) {}

  /** Sidebar — always present after login. */
  get sidebar(): Locator { return this.page.locator('aside').first(); }
  /** Top navbar with breadcrumb + user menu. */
  get topbar(): Locator { return this.page.locator('header').first(); }
  /**
   * There is no "Forbidden" screen for a resource, by design: PermissionGuard
   * renders the same not-found state a genuinely absent record does, so the UI
   * never confirms that a record the user cannot read exists.
   */
  get deniedPageText(): Locator { return this.page.getByText(/page not found/i); }
  /** Generic toast container. */
  get toast(): Locator { return this.page.locator('[role="status"], .toast, [data-sonner-toaster]').first(); }
  /** Skeleton loading placeholder. */
  get skeleton(): Locator { return this.page.locator('.animate-pulse').first(); }

  /** Wait for a toast with given text to appear. */
  async waitForToast(containsText: string, timeout = 5000): Promise<void> {
    await this.page.locator(`text=${containsText}`).first().waitFor({ state: 'visible', timeout });
  }

  /**
   * Assert a route the user may not see is indistinguishable from one that does
   * not exist. This used to assert a distinct "You do not have permission"
   * screen and had been failing since the two were unified — asserting it back
   * would be asserting the information leak back into place.
   */
  async expectDeniedAsNotFound(): Promise<void> {
    await this.deniedPageText.waitFor({ state: 'visible', timeout: 5000 });
    const leak = this.page.getByText(/forbidden|do not have permission|access denied/i);
    if (await leak.count() > 0) {
      throw new Error('Denied route revealed that the resource exists.');
    }
  }

  /** Assert a 404/not-found state. */
  async expectNotFound(): Promise<void> {
    await this.page.getByText(/not found|page does not exist/i).first().waitFor({ state: 'visible', timeout: 5000 });
  }

  /** Open the sidebar nav item by label and click it. */
  async navTo(label: string): Promise<void> {
    await this.sidebar.getByText(label, { exact: false }).first().click();
    await this.page.waitForLoadState('networkidle');
  }

  /** Sign out via the user menu. */
  async logout(): Promise<void> {
    await this.topbar.getByRole('button', { name: /user|account|profile/i }).first().click();
    await this.page.getByText(/sign out|logout/i).first().click();
    await this.page.waitForURL('**/login');
  }
}
