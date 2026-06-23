/**
 * Admin + Purchasing page objects.
 */
import type { Page, Locator } from '@playwright/test';
import { BasePage } from './BasePage';

// ══════════════════════════════════════════════════════════════════════════════
// Admin
// ══════════════════════════════════════════════════════════════════════════════

export class AdminUsersPage extends BasePage {
  constructor(page: Page) { super(page); }

  get heading(): Locator { return this.page.getByText(/users|manage users/i); }
  get createUserButton(): Locator { return this.page.getByRole('button', { name: /new user|create user/i }); }
  get table(): Locator { return this.page.locator('table').first(); }
}

export class AdminRolesPage extends BasePage {
  constructor(page: Page) { super(page); }

  get heading(): Locator { return this.page.getByText(/roles|manage roles/i); }
  get compareButton(): Locator { return this.page.getByRole('link', { name: /compare/i }); }
}

export class AdminAuditLogPage extends BasePage {
  constructor(page: Page) { super(page); }

  get heading(): Locator { return this.page.getByText(/audit log/i); }
  get table(): Locator { return this.page.locator('table').first(); }
}

// ══════════════════════════════════════════════════════════════════════════════
// Purchasing
// ══════════════════════════════════════════════════════════════════════════════

export class PurchaseRequestListPage extends BasePage {
  constructor(page: Page) { super(page); }

  get heading(): Locator { return this.page.getByText(/purchase request/i); }
  get createButton(): Locator { return this.page.getByRole('button', { name: /new.*request|create.*request/i }); }
  get table(): Locator { return this.page.locator('table').first(); }
}

export class PurchaseOrderListPage extends BasePage {
  constructor(page: Page) { super(page); }

  get heading(): Locator { return this.page.getByText(/purchase order/i); }
  get createButton(): Locator { return this.page.getByRole('button', { name: /new.*order|create.*order/i }); }
  get table(): Locator { return this.page.locator('table').first(); }
}

export class PurchaseOrderDetailPage extends BasePage {
  constructor(page: Page) { super(page); }

  get approveButton(): Locator { return this.page.getByRole('button', { name: /^approve$/i }); }
  get sendButton(): Locator { return this.page.getByRole('button', { name: /send/i }); }
  get statusChip(): Locator {
    return this.page.locator('span:has-text("Draft"), span:has-text("Pending Approval"), span:has-text("Approved"), span:has-text("Sent"), span:has-text("Received")').first();
  }

  async expectStatus(text: string): Promise<void> {
    await this.page.getByText(text, { exact: true }).first().waitFor({ state: 'visible', timeout: 5000 });
  }
}

// ══════════════════════════════════════════════════════════════════════════════
// Production
// ══════════════════════════════════════════════════════════════════════════════

export class WorkOrderListPage extends BasePage {
  constructor(page: Page) { super(page); }

  get heading(): Locator { return this.page.getByText(/work order/i); }
  get createButton(): Locator { return this.page.getByRole('button', { name: /new.*work order|create/i }); }
}

export class WorkOrderDetailPage extends BasePage {
  constructor(page: Page) { super(page); }

  get confirmButton(): Locator { return this.page.getByRole('button', { name: /confirm/i }); }
  get startButton(): Locator { return this.page.getByRole('button', { name: /start/i }); }
  get recordOutputButton(): Locator { return this.page.getByRole('button', { name: /record output/i }); }
  get completeButton(): Locator { return this.page.getByRole('button', { name: /complete/i }); }
  get statusChip(): Locator {
    return this.page.locator('span:has-text("Planned"), span:has-text("Confirmed"), span:has-text("In Progress"), span:has-text("Completed"), span:has-text("Closed")').first();
  }

  async expectStatus(text: string): Promise<void> {
    await this.page.getByText(text, { exact: true }).first().waitFor({ state: 'visible', timeout: 5000 });
  }
}

// ══════════════════════════════════════════════════════════════════════════════
// Quality
// ══════════════════════════════════════════════════════════════════════════════

export class InspectionListPage extends BasePage {
  constructor(page: Page) { super(page); }

  get heading(): Locator { return this.page.getByText(/inspection/i).first(); }
  get createButton(): Locator { return this.page.getByRole('button', { name: /new inspection/i }); }
}

export class NcrListPage extends BasePage {
  constructor(page: Page) { super(page); }

  get heading(): Locator { return this.page.getByText(/NCR|non.conformance/i).first(); }
}

export class InspectionDetailPage extends BasePage {
  constructor(page: Page) { super(page); }

  get completeButton(): Locator { return this.page.getByRole('button', { name: /complete/i }); }
  get recordMeasurementsButton(): Locator { return this.page.getByRole('button', { name: /record|measure/i }); }

  async expectStatus(text: string): Promise<void> {
    await this.page.getByText(text, { exact: true }).first().waitFor({ state: 'visible', timeout: 5000 });
  }
}

// ══════════════════════════════════════════════════════════════════════════════
// Dashboard / Notifications
// ══════════════════════════════════════════════════════════════════════════════

export class DashboardPage extends BasePage {
  constructor(page: Page) { super(page); }

  /** The KPI stat cards that every dashboard shows. */
  get statCards(): Locator { return this.page.locator('[class*="stat"], [class*="kpi"], [class*="metric"]').first(); }
  get heading(): Locator { return this.page.locator('h1').first(); }
}

export class NotificationsPage extends BasePage {
  constructor(page: Page) { super(page); }

  get heading(): Locator { return this.page.getByText(/notification/i); }
  get emptyState(): Locator { return this.page.getByText(/no notification/i); }
}
