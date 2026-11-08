import { expect, type Locator, type Page } from '@playwright/test';

export async function assertStatusBadge(badge: Locator, expectedStatus: string): Promise<void> {
  await expect(badge).toHaveText(expectedStatus);
}

export async function assertAvailableActions(
  page: Page,
  available: string[],
  unavailable: string[],
): Promise<void> {
  for (const action of available) await expect(page.getByRole('button', { name: action, exact: true })).toBeEnabled();
  for (const action of unavailable) await expect(page.getByRole('button', { name: action, exact: true })).toHaveCount(0);
}

export async function assertActionDisabledWithReason(page: Page, action: string, reason: string): Promise<void> {
  await expect(page.getByRole('button', { name: action, exact: true })).toBeDisabled();
  await expect(page.getByText(reason, { exact: false })).toBeVisible();
}

export async function assertPhpCurrency(locator: Locator, amount: number): Promise<void> {
  const expected = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(amount);
  await expect(locator).toContainText(expected);
}

export function assertTotalsEqual(label: string, left: number, right: number): void {
  const leftCents = Math.round((left + Number.EPSILON) * 100);
  const rightCents = Math.round((right + Number.EPSILON) * 100);
  expect(leftCents, `${label} totals should match to the centavo`).toBe(rightCents);
}

export function assertDocumentNumberUnique(context: Set<string>, documentNumber: string): void {
  expect(documentNumber, 'document number should be present').not.toBe('');
  expect(context.has(documentNumber), `duplicate document number: ${documentNumber}`).toBe(false);
  context.add(documentNumber);
}

export async function assertSecondViewerRealtime(locator: Locator, expectedText: string): Promise<void> {
  await expect(locator, 'the observer screen should update without a refresh').toHaveText(expectedText);
}

export async function assertSidebarBadgeCount(locator: Locator, expectedCount: number): Promise<void> {
  await expect(locator).toHaveText(String(expectedCount));
}

export function assertAuditActor(auditEntry: { actor_email?: string; actor?: { email?: string } }, expectedEmail: string): void {
  expect(auditEntry.actor_email ?? auditEntry.actor?.email).toBe(expectedEmail);
}

export function assertRecipientsExactly(actual: string[], expected: string[]): void {
  expect([...actual].map((email) => email.toLowerCase()).sort()).toEqual(
    [...expected].map((email) => email.toLowerCase()).sort(),
  );
}
