/**
 * Payroll module page objects.
 */
import type { Page, Locator } from '@playwright/test';
import { BasePage } from './BasePage';

export class PayrollPeriodListPage extends BasePage {
  constructor(page: Page) { super(page); }

  get heading(): Locator { return this.page.getByText('Payroll Periods'); }
  get newPeriodButton(): Locator { return this.page.getByRole('button', { name: /new period/i }); }
  get table(): Locator { return this.page.locator('table').first(); }

  async openPeriod(containingText: string): Promise<void> {
    await this.table.getByText(containingText).first().click();
    await this.page.waitForLoadState('networkidle');
  }
}

export class PayrollPeriodDetailPage extends BasePage {
  constructor(page: Page) { super(page); }

  get statusChip(): Locator {
    return this.page.locator('span:has-text("Draft"), span:has-text("Processing"), span:has-text("Approved"), span:has-text("Finalized")').first();
  }
  get computeButton(): Locator { return this.page.getByRole('button', { name: /compute/i }); }
  get approveButton(): Locator { return this.page.getByRole('button', { name: /^approve$/i }); }
  get finalizeButton(): Locator { return this.page.getByRole('button', { name: /finalize/i }); }
  get bankFileLink(): Locator { return this.page.getByRole('link', { name: /bank file/i }); }
  get employeeCount(): Locator { return this.page.getByText(/\\d+\\s*employees/i).first(); }

  async expectStatus(text: string): Promise<void> {
    await this.page.getByText(text, { exact: true }).first().waitFor({ state: 'visible', timeout: 5000 });
  }
  async clickCompute(): Promise<void> { await this.computeButton.click(); }
  async clickApprove(): Promise<void> { await this.approveButton.click(); }
  async clickFinalize(): Promise<void> { await this.finalizeButton.click(); }
  /** Assert the finalize button is NOT visible (user lacks the perm). */
  async expectNoFinalize(): Promise<void> {
    await this.finalizeButton.waitFor({ state: 'hidden', timeout: 3000 });
  }
}

export class PayrollAdjustmentCreatePage extends BasePage {
  constructor(page: Page) { super(page); }

  get periodSelect(): Locator { return this.page.getByLabel(/period/i); }
  get typeSelect(): Locator { return this.page.getByLabel(/adjustment type/i); }
  get employeeSelect(): Locator { return this.page.getByLabel(/employee/i); }
  get amountInput(): Locator { return this.page.getByLabel(/amount/i); }
  get submitButton(): Locator { return this.page.getByRole('button', { name: /submit|create/i }); }
}

export class PayslipPage extends BasePage {
  constructor(page: Page) { super(page); }

  get netPayText(): Locator { return this.page.getByText(/net pay/i); }
  get grossPayText(): Locator { return this.page.getByText(/gross pay/i); }
  get employeeName(): Locator { return this.page.locator('text=/[A-Z][a-z]+\\s[A-Z][a-z]+/').first(); }
}
