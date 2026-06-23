/**
 * Leave module page objects.
 */
import type { Page, Locator } from '@playwright/test';
import { BasePage } from './BasePage';

export class LeaveListPage extends BasePage {
  constructor(page: Page) { super(page); }

  get heading(): Locator { return this.page.getByText('Leave Requests'); }
  get fileLeaveButton(): Locator { return this.page.getByRole('button', { name: /file leave|new leave/i }); }
  get table(): Locator { return this.page.locator('table').first(); }

  /** Open a leave request by its reference text shown in the row. */
  async openRequest(containingText: string): Promise<void> {
    await this.table.getByText(containingText).first().click();
    await this.page.waitForLoadState('networkidle');
  }
}

export class LeaveCreatePage extends BasePage {
  constructor(page: Page) { super(page); }

  get typeSelect(): Locator { return this.page.getByLabel(/leave type/i); }
  get startDateInput(): Locator { return this.page.getByLabel(/start date/i); }
  get endDateInput(): Locator { return this.page.getByLabel(/end date/i); }
  get reasonInput(): Locator { return this.page.getByLabel(/reason/i); }
  get submitButton(): Locator { return this.page.getByRole('button', { name: /submit|file leave/i }); }
  get halfDaySelect(): Locator { return this.page.getByLabel(/half.day/i); }

  async fillForm(type: string, start: string, end: string, reason: string): Promise<void> {
    await this.typeSelect.click();
    await this.page.getByRole('option', { name: type }).click();
    await this.startDateInput.fill(start);
    await this.endDateInput.fill(end);
    await this.reasonInput.fill(reason);
  }

  async submit(): Promise<void> {
    await this.submitButton.click();
  }
}

export class LeaveDetailPage extends BasePage {
  constructor(page: Page) { super(page); }

  get statusChip(): Locator { return this.page.locator('[data-status], .status-chip, span:has-text("pending_dept"), span:has-text("pending_hr"), span:has-text("approved"), span:has-text("rejected")').first(); }
  get approveDeptButton(): Locator { return this.page.getByRole('button', { name: /approve.*department|dept.*approve/i }); }
  get approveHrButton(): Locator { return this.page.getByRole('button', { name: /approve.*hr|hr.*approve/i }); }
  get rejectButton(): Locator { return this.page.getByRole('button', { name: /reject/i }); }
  get cancelButton(): Locator { return this.page.getByRole('button', { name: /cancel/i }); }

  async approveDept(): Promise<void> { await this.approveDeptButton.click(); }
  async approveHR(): Promise<void> { await this.approveHrButton.click(); }

  async expectStatus(text: string): Promise<void> {
    await this.page.getByText(text).first().waitFor({ state: 'visible', timeout: 5000 });
  }
}

/** Self-service leave page (employee view). */
export class SelfServiceLeavePage extends BasePage {
  constructor(page: Page) { super(page); }

  get fileLeaveButton(): Locator { return this.page.getByRole('button', { name: /file leave/i }); }
  get leaveBalanceText(): Locator { return this.page.locator('text=/\\d+(\\.\\d+)?\\s*(days|day)/i').first(); }

  async captureBalance(): Promise<string> {
    const txt = await this.leaveBalanceText.textContent();
    return txt ?? '';
  }
}
