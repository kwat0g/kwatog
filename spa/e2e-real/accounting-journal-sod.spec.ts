import { expect, test, type Page } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { baseUrl, captureNetwork, loginAs, relevantErrors, ROLES, statefulHeaders } from './helpers';
import { localIsoDate } from '../src/lib/formatDate';

type JournalEntryData = {
  date: string;
  description: string;
  total_debit: string;
  total_credit: string;
  status: string;
  reference_type: string | null;
  reference_id: string | null;
  reversed_by_entry_id: string | null;
  created_by: { id: string; name: string } | null;
  posted_by: { id: string; name: string } | null;
  posted_at: string | null;
  lines: Array<{
    account: { id: string; code: string; normal_balance?: string } | null;
    debit: string;
    credit: string;
  }>;
};

type InvoiceData = {
  id: string;
  invoice_number: string | null;
  date: string;
  lifecycle_type: string;
  prebill_reason: string | null;
  prebill_approved_at: string | null;
  prebill_approved_by: { id: string; name: string } | null;
  created_by: { id: string; name: string } | null;
  subtotal: string;
  vat_amount: string;
  total_amount: string;
  amount_paid: string;
  balance: string;
  status: string;
  status_history?: Array<{
    event_type: 'created' | 'updated' | 'prebill_approved' | 'status_changed';
    from_status: string | null;
    to_status: string | null;
    actor_name: string | null;
    actor_type: string;
    occurred_at: string;
  }>;
  journal_entry: { id: string; entry_number: string; status: string } | null;
  items?: Array<{ description: string; quantity: string; unit_price: string; total: string }>;
};

type AuditLog = {
  action: string;
  model_id: string;
  new_values: Record<string, unknown>;
  user: { email: string } | null;
};

const auditDate = () =>
  localIsoDate(new Date(2027, 0, 1 + Math.floor(Math.random() * 3650)));

async function mutationHeaders(page: Page): Promise<Record<string, string>> {
  const xsrf = (await page.context().cookies(baseUrl())).find((cookie) => cookie.name === 'XSRF-TOKEN');
  expect(xsrf).toBeTruthy();
  return { ...statefulHeaders(), 'X-XSRF-TOKEN': decodeURIComponent(xsrf!.value) };
}

async function createDraft(page: Page, description: string): Promise<{ id: string; date: string }> {
  const userResponse = await page.request.get(`${baseUrl()}/api/v1/auth/user`, { headers: statefulHeaders() });
  expect(userResponse.status()).toBe(200);
  const currentUser = (await userResponse.json()).data as { id: string; name: string };

  const accountsResponse = await page.request.get(`${baseUrl()}/api/v1/accounts?per_page=200&is_active=true`, {
    headers: statefulHeaders(),
  });
  const accountsBody = await accountsResponse.text();
  expect(accountsResponse.status(), accountsBody).toBe(200);
  const accounts = (JSON.parse(accountsBody).data) as Array<{ id: string; code: string }>;
  const cash = accounts.find((account) => account.code === '1010');
  const equity = accounts.find((account) => account.code === '3010');
  expect(cash).toBeTruthy();
  expect(equity).toBeTruthy();

  const date = auditDate();
  await page.goto('/accounting/journal-entries/create');
  await expect(page.getByRole('heading', { name: 'New journal entry' })).toBeVisible();
  await page.getByLabel('Date').fill(date);
  await page.getByLabel('Description').fill(description);
  await page.locator('select[name="lines.0.account_id"]').selectOption(cash!.id);
  await page.locator('input[name="lines.0.debit"]').fill('1.00');
  await page.locator('select[name="lines.1.account_id"]').selectOption(equity!.id);
  await page.locator('input[name="lines.1.credit"]').fill('1.00');
  await page.getByRole('button', { name: 'Save draft' }).click();
  await expect(page.getByRole('button', { name: 'Post', exact: true })).toBeVisible();

  const id = new URL(page.url()).pathname.split('/').at(-1);
  expect(id).toBeTruthy();
  const created = await readEntry(page, id!);
  expect(created).toMatchObject({
    date,
    description,
    total_debit: '1.00',
    total_credit: '1.00',
    status: 'draft',
    created_by: { id: currentUser.id, name: currentUser.name },
    posted_by: null,
    posted_at: null,
  });
  expect(created.lines).toHaveLength(2);
  return { id: id!, date };
}

async function readEntry(page: Page, id: string): Promise<JournalEntryData> {
  const response = await page.request.get(`${baseUrl()}/api/v1/journal-entries/${id}`, {
    headers: statefulHeaders(),
  });
  expect(response.status()).toBe(200);
  return (await response.json()).data as JournalEntryData;
}

async function readInvoice(page: Page, id: string): Promise<InvoiceData> {
  const response = await page.request.get(`${baseUrl()}/api/v1/invoices/${id}`, {
    headers: statefulHeaders(),
  });
  expect(response.status()).toBe(200);
  return (await response.json()).data as InvoiceData;
}

async function invoiceAudit(page: Page, id: string): Promise<AuditLog[]> {
  const response = await page.request.get(
    `${baseUrl()}/api/v1/admin/audit-logs/entity?model_type=Invoice&model_id=${encodeURIComponent(id)}&per_page=100`,
    { headers: statefulHeaders() },
  );
  expect(response.status()).toBe(200);
  return (await response.json()).data as AuditLog[];
}

async function trialBalance(page: Page, from: string, to = from): Promise<{
  accounts: Array<{ code: string; debit_total: string; credit_total: string; balance: string }>;
  totals: { debit: string; credit: string };
}> {
  const response = await page.request.get(
    `${baseUrl()}/api/v1/accounting/statements/trial-balance?from=${from}&to=${to}`,
    { headers: statefulHeaders() },
  );
  expect(response.status()).toBe(200);
  return (await response.json()).data;
}

function addMoney(...amounts: string[]): string {
  const cents = amounts.reduce((total, amount) => total + BigInt(amount.replace('.', '')), 0n);
  return `${cents / 100n}.${String(cents % 100n).padStart(2, '0')}`;
}

test('Finance maker cannot self-post through the API or the UI', async ({ page }) => {
  const finance = ROLES.find((candidate) => candidate.slug === 'finance_officer')!;
  await loginAs(page, finance.email, finance.password);
  const currentUserResponse = await page.request.get(`${baseUrl()}/api/v1/auth/user`, {
    headers: statefulHeaders(),
  });
  expect(currentUserResponse.status()).toBe(200);
  const currentUser = (await currentUserResponse.json()).data as { permissions: string[] };
  expect(currentUser.permissions).toContain('accounting.journal.create');
  expect(currentUser.permissions).toContain('accounting.journal.post');
  const net = captureNetwork(page);
  const { id, date } = await createDraft(page, `SoD Finance API ${randomUUID()}`);
  const baseline = await trialBalance(page, date);

  const directResponse = await page.request.patch(`${baseUrl()}/api/v1/journal-entries/${id}/post`, {
    headers: await mutationHeaders(page),
  });
  const directBody = await directResponse.json();
  const afterDirect = await readEntry(page, id);
  expect(afterDirect).toMatchObject({ status: 'draft', posted_by: null, posted_at: null });
  expect((await trialBalance(page, date)).totals).toEqual(baseline.totals);
  expect(directResponse.status()).toBe(403);
  expect(directBody.message).toContain('segregation of duties');

  const uiResponsePromise = page.waitForResponse((response) =>
    response.request().method() === 'PATCH' && response.url().endsWith(`/journal-entries/${id}/post`),
  );
  await page.getByRole('button', { name: 'Post', exact: true }).click();
  const confirm = page.getByRole('dialog', { name: 'Post journal entry?' });
  await expect(confirm).toBeVisible();
  await confirm.getByRole('button', { name: 'Post', exact: true }).click();
  const uiResponse = await uiResponsePromise;
  await expect(page.getByText(/segregation of duties/i).first()).toBeVisible();
  const afterUi = await readEntry(page, id);
  expect(afterUi).toMatchObject({ status: 'draft', posted_by: null, posted_at: null });
  expect((await trialBalance(page, date)).totals).toEqual(baseline.totals);
  expect(uiResponse.status()).toBe(403);
  expect(net.failedRequests).toEqual([
    expect.objectContaining({ method: 'PATCH', status: 403 }),
  ]);
  expect(net.errors).toEqual([expect.stringContaining('403 (Forbidden)')]);
  net.stop();
});

test('a different Finance officer can post the draft through the UI', async ({ browser }) => {
  const makerContext = await browser.newContext({ baseURL: baseUrl() });
  const checkerContext = await browser.newContext({ baseURL: baseUrl() });
  const adminContext = await browser.newContext({ baseURL: baseUrl() });
  const maker = await makerContext.newPage();
  const checker = await checkerContext.newPage();
  const admin = await adminContext.newPage();

  try {
    const finance = ROLES.find((candidate) => candidate.slug === 'finance_officer')!;
    await loginAs(maker, finance.email, finance.password);
    const makerUserResponse = await maker.request.get(`${baseUrl()}/api/v1/auth/user`, {
      headers: statefulHeaders(),
    });
    expect(makerUserResponse.status()).toBe(200);
    const makerUser = (await makerUserResponse.json()).data as { id: string; permissions: string[] };
    expect(makerUser.permissions).toContain('accounting.coa.view');
    expect(makerUser.permissions).toContain('accounting.journal.post');

    const description = `SoD checker path ${randomUUID()}`;
    const { id, date } = await createDraft(maker, description);
    const baseline = await trialBalance(maker, date);
    const beforePost = await readEntry(maker, id);
    expect(beforePost).toMatchObject({
      status: 'draft',
      created_by: { id: makerUser.id },
      posted_by: null,
      posted_at: null,
      total_debit: '1.00',
      total_credit: '1.00',
    });
    expect((await trialBalance(maker, date)).totals).toEqual(baseline.totals);

    await loginAs(checker, 'finance2@ogami.test', 'password');
    const checkerUserResponse = await checker.request.get(`${baseUrl()}/api/v1/auth/user`, {
      headers: statefulHeaders(),
    });
    expect(checkerUserResponse.status()).toBe(200);
    const checkerUser = (await checkerUserResponse.json()).data as { id: string; permissions: string[] };
    expect(checkerUser.permissions).toContain('accounting.journal.post');

    const checkerDraft = await readEntry(checker, id);
    expect(checkerDraft).toMatchObject({ status: 'draft', created_by: { id: makerUser.id }, posted_by: null });
    expect((await trialBalance(checker, date)).totals).toEqual(baseline.totals);
    await checker.goto(`/accounting/journal-entries/${id}`);
    await expect(checker.getByText(description)).toBeVisible();
    await expect(checker.getByRole('button', { name: 'Post', exact: true })).toBeVisible();

    const uiResponsePromise = checker.waitForResponse((response) =>
      response.request().method() === 'PATCH' && response.url().endsWith(`/journal-entries/${id}/post`),
    );
    await checker.getByRole('button', { name: 'Post', exact: true }).click();
    const confirm = checker.getByRole('dialog', { name: 'Post journal entry?' });
    await expect(confirm).toBeVisible();
    await confirm.getByRole('button', { name: 'Post', exact: true }).click();
    expect((await uiResponsePromise).status()).toBe(200);

    const posted = await readEntry(checker, id);
    expect(posted).toMatchObject({
      status: 'posted',
      created_by: { id: makerUser.id },
      posted_by: { id: checkerUser.id },
      total_debit: '1.00',
      total_credit: '1.00',
    });
    expect(posted.posted_at).toBeTruthy();
    expect(posted.lines).toHaveLength(2);
    expect((await trialBalance(checker, date)).totals).toEqual({
      debit: addMoney(baseline.totals.debit, '1.00'),
      credit: addMoney(baseline.totals.credit, '1.00'),
    });

    const systemAdmin = ROLES.find((candidate) => candidate.slug === 'system_admin')!;
    await loginAs(admin, systemAdmin.email, systemAdmin.password);
    const auditResponse = await admin.request.get(
      `${baseUrl()}/api/v1/admin/audit-logs/entity?model_type=JournalEntry&model_id=${encodeURIComponent(id)}&per_page=100`,
      { headers: statefulHeaders() },
    );
    expect(auditResponse.status()).toBe(200);
    const auditData = (await auditResponse.json()).data as Array<{
      action: string;
      model_id: string;
      old_values: Record<string, unknown>;
      new_values: Record<string, unknown>;
      user: { email: string } | null;
    }>;
    expect(auditData).toContainEqual(expect.objectContaining({
      action: 'updated',
      model_id: id,
      user: expect.objectContaining({ email: 'finance2@ogami.test' }),
      old_values: expect.objectContaining({ status: 'draft' }),
      new_values: expect.objectContaining({ status: 'posted' }),
    }));
  } finally {
    await Promise.all([makerContext.close(), checkerContext.close(), adminContext.close()]);
  }
});

test('System Admin cannot bypass maker-checker by self-posting through the API', async ({ page }) => {
  const admin = ROLES.find((candidate) => candidate.slug === 'system_admin')!;
  await loginAs(page, admin.email, admin.password);
  const { id, date } = await createDraft(page, `SoD Admin API ${randomUUID()}`);
  const baseline = await trialBalance(page, date);

  const response = await page.request.patch(`${baseUrl()}/api/v1/journal-entries/${id}/post`, {
    headers: await mutationHeaders(page),
  });
  const body = await response.json();
  const after = await readEntry(page, id);
  const totals = (await trialBalance(page, date)).totals;

  expect(after).toMatchObject({ status: 'draft', posted_by: null, posted_at: null });
  expect(totals).toEqual(baseline.totals);
  expect(response.status()).toBe(403);
  expect(body.message).toContain('segregation of duties');

  const matrixResponse = await page.request.get(`${baseUrl()}/api/v1/admin/sod/matrix`, {
    headers: statefulHeaders(),
  });
  expect(matrixResponse.status()).toBe(200);
  const matrix = (await matrixResponse.json()).data as Array<{ code: string; active: boolean }>;
  expect(matrix).toContainEqual(expect.objectContaining({ code: 'je_create_vs_post', active: true }));

  const violationsResponse = await page.request.get(`${baseUrl()}/api/v1/admin/sod/violations`, {
    headers: statefulHeaders(),
  });
  expect(violationsResponse.status()).toBe(200);
  const violations = (await violationsResponse.json()).data as Array<{
    user: { email: string };
    violations: Array<{ code: string }>;
  }>;
  expect(violations.some((row) =>
    row.user.email === 'finance@ogami.test' &&
    row.violations.some((violation: { code: string }) => violation.code === 'je_create_vs_post'),
  )).toBe(true);
});

test('System Admin cannot bypass maker-checker by self-posting through the UI', async ({ page }) => {
  const admin = ROLES.find((candidate) => candidate.slug === 'system_admin')!;
  await loginAs(page, admin.email, admin.password);
  const net = captureNetwork(page);
  const { id, date } = await createDraft(page, `SoD Admin UI ${randomUUID()}`);
  const baseline = await trialBalance(page, date);

  const uiResponsePromise = page.waitForResponse((response) =>
    response.request().method() === 'PATCH' && response.url().endsWith(`/journal-entries/${id}/post`),
  );
  await page.getByRole('button', { name: 'Post', exact: true }).click();
  const confirm = page.getByRole('dialog', { name: 'Post journal entry?' });
  await expect(confirm).toBeVisible();
  await confirm.getByRole('button', { name: 'Post', exact: true }).click();
  const uiResponse = await uiResponsePromise;
  const after = await readEntry(page, id);
  const totals = (await trialBalance(page, date)).totals;

  expect(after).toMatchObject({ status: 'draft', posted_by: null, posted_at: null });
  expect(totals).toEqual(baseline.totals);
  expect(uiResponse.status()).toBe(403);
  expect(await page.getByText(/segregation of duties/i).count()).toBeGreaterThan(0);
  expect(net.failedRequests).toEqual([
    expect.objectContaining({ method: 'PATCH', status: 403 }),
  ]);
  expect(net.errors).toEqual([expect.stringContaining('403 (Forbidden)')]);
  net.stop();
});

test('prebill approval, posting, and source reversal use separate gated steps', async ({ browser, page }) => {
  const makerContext = page.context();
  const auditContext = await browser.newContext({ baseURL: baseUrl() });
  const approverContext = await browser.newContext({ baseURL: baseUrl() });
  const posterContext = await browser.newContext({ baseURL: baseUrl() });
  const auditReader = await auditContext.newPage();
  const approver = await approverContext.newPage();
  const poster = await posterContext.newPage();
  const admin = ROLES.find((candidate) => candidate.slug === 'system_admin')!;
  const maker = ROLES.find((candidate) => candidate.slug === 'finance_officer')!;
  const makerNet = captureNetwork(page);
  const approverNet = captureNetwork(approver);
  const posterNet = captureNetwork(poster);

  try {
    // Audit-log viewing is an admin-only permission; transaction steps below
    // still run under the maker, approver, and poster identities.
    await loginAs(auditReader, admin.email, admin.password);
    await loginAs(page, maker.email, maker.password);
    const makerUserResponse = await page.request.get(`${baseUrl()}/api/v1/auth/user`, {
      headers: statefulHeaders(),
    });
    expect(makerUserResponse.status()).toBe(200);
    const makerUser = (await makerUserResponse.json()).data as { id: string; email: string; permissions: string[] };
    expect(makerUser.permissions).toContain('accounting.invoices.create');
    expect(makerUser.permissions).toContain('accounting.invoices.prebill_approve');

    const accountsResponse = await page.request.get(
      `${baseUrl()}/api/v1/accounts?per_page=200&type=revenue&is_active=true`,
      { headers: statefulHeaders() },
    );
    expect(accountsResponse.status()).toBe(200);
    const accounts = (await accountsResponse.json()).data as Array<{ id: string; code: string }>;
    const revenue = accounts.find((account) => account.code === '4010');
    expect(revenue).toBeTruthy();

    await page.goto('/accounting/invoices/create');
    await expect(page.getByRole('heading', { name: 'New invoice' })).toBeVisible();
    const firstCustomer = page.locator('select[name="customer_id"] option').nth(1);
    const customerId = await firstCustomer.getAttribute('value');
    const customerName = (await firstCustomer.textContent())?.trim();
    expect(customerId).toBeTruthy();
    expect(customerName).toBeTruthy();
    await page.getByRole('combobox', { name: 'Customer', exact: true }).click();
    await page.getByRole('listbox', { name: 'Customer' })
      .getByRole('option', { name: customerName!, exact: true })
      .click();
    const periodStart = await page.locator('input[name="date"]').inputValue();
    const invoiceDate = auditDate();
    await page.locator('input[name="date"]').fill(invoiceDate);
    const baselineTrialBalance = await trialBalance(page, periodStart, invoiceDate);
    const prebillReason = `Approval chain ${randomUUID()}`;
    await page.getByLabel('Prebill approval reason').fill(prebillReason);
    await page.locator('select[name="items.0.revenue_account_id"]').selectOption(revenue!.id);
    await page.locator('input[name="items.0.description"]').fill('Prepaid equipment');
    await page.locator('input[name="items.0.quantity"]').fill('1.00');
    await page.locator('input[name="items.0.unit_price"]').fill('100.00');
    await expect(page.getByText('Subtotal: ₱100.00')).toBeVisible();
    await expect(page.getByText('VAT: ₱12.00')).toBeVisible();
    await expect(page.getByText('Total: ₱112.00')).toBeVisible();
    const createResponsePromise = page.waitForResponse((response) =>
      response.request().method() === 'POST' && new URL(response.url()).pathname === '/api/v1/invoices',
    );
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    const createResponse = await createResponsePromise;
    expect(createResponse.status()).toBe(201);
    const invoiceId = ((await createResponse.json()).data as InvoiceData).id;
    await expect(page).toHaveURL(new RegExp(`/accounting/invoices/${invoiceId}$`));

    const draft = await readInvoice(page, invoiceId);
    expect(draft).toMatchObject({
      id: invoiceId,
      invoice_number: null,
      date: invoiceDate,
      lifecycle_type: 'prebill',
      prebill_reason: prebillReason,
      prebill_approved_by: null,
      prebill_approved_at: null,
      created_by: { id: makerUser.id },
      subtotal: '100.00',
      vat_amount: '12.00',
      total_amount: '112.00',
      amount_paid: '0.00',
      balance: '112.00',
      status: 'draft',
      journal_entry: null,
    });
    expect(draft.status_history).toEqual(expect.arrayContaining([
      expect.objectContaining({
        event_type: 'created',
        from_status: null,
        to_status: 'draft',
        actor_name: makerUser.name,
      }),
    ]));
    await expect(page.getByText('Invoice draft created.')).toBeVisible();
    await expect(page.getByText(`By ${makerUser.name}`, { exact: true })).toBeVisible();
    expect((await trialBalance(page, periodStart, invoiceDate)).totals).toEqual(baselineTrialBalance.totals);
    expect(await invoiceAudit(auditReader, invoiceId)).toContainEqual(expect.objectContaining({
      action: 'created',
      model_id: invoiceId,
      user: expect.objectContaining({ email: makerUser.email }),
    }));
    await expect(page.getByRole('button', { name: 'Approve prebill', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Finalize', exact: true })).toHaveCount(0);
    await expect(page.getByText(/awaiting prebill approval/i)).toBeVisible();

    const approvalUrl = `${baseUrl()}/api/v1/invoices/${invoiceId}/approve-prebill`;
    const selfApproval = await page.request.post(approvalUrl, { headers: await mutationHeaders(page) });
    expect(selfApproval.status()).toBe(403);
    expect((await selfApproval.json()).message).toContain('segregation of duties');
    const selfFinalize = await page.request.patch(
      `${baseUrl()}/api/v1/invoices/${invoiceId}/finalize`,
      { headers: await mutationHeaders(page) },
    );
    expect(selfFinalize.status()).toBe(403);
    const afterSelfAttempts = await readInvoice(page, invoiceId);
    expect(afterSelfAttempts).toMatchObject({
      status: 'draft',
      prebill_approved_by: null,
      prebill_approved_at: null,
      journal_entry: null,
    });
    expect((await trialBalance(page, periodStart, invoiceDate)).totals).toEqual(baselineTrialBalance.totals);

    await loginAs(approver, 'finance2@ogami.test', 'password');
    const approverUserResponse = await approver.request.get(`${baseUrl()}/api/v1/auth/user`, {
      headers: statefulHeaders(),
    });
    expect(approverUserResponse.status()).toBe(200);
    const approverUser = (await approverUserResponse.json()).data as { id: string; permissions: string[] };
    expect(approverUser.permissions).toContain('accounting.invoices.prebill_approve');
    const approvalQueueResponse = await approver.request.get(
      `${baseUrl()}/api/v1/invoices?status=draft&per_page=100`,
      { headers: statefulHeaders() },
    );
    expect(approvalQueueResponse.status()).toBe(200);
    const queueInvoices = (await approvalQueueResponse.json()).data as InvoiceData[];
    expect(queueInvoices).toContainEqual(expect.objectContaining({
      id: invoiceId,
      status: 'draft',
      lifecycle_type: 'prebill',
      prebill_approved_at: null,
    }));
    await approver.goto('/accounting/invoices?status=draft');
    await expect(approver.getByText(/awaiting prebill approval/i).first()).toBeVisible();
    await approver.goto(`/accounting/invoices/${invoiceId}`);
    await expect(approver.getByRole('button', { name: 'Approve prebill', exact: true })).toBeVisible();
    await expect(approver.getByRole('button', { name: 'Finalize', exact: true })).toHaveCount(0);

    const approveResponsePromise = approver.waitForResponse((response) =>
      response.request().method() === 'POST' && response.url().endsWith(`/invoices/${invoiceId}/approve-prebill`),
    );
    await approver.getByRole('button', { name: 'Approve prebill', exact: true }).click();
    const approveDialog = approver.getByRole('dialog', { name: 'Approve prebill invoice?' });
    await expect(approveDialog).toBeVisible();
    await approveDialog.getByRole('button', { name: 'Approve', exact: true }).click();
    expect((await approveResponsePromise).status()).toBe(200);

    const approved = await readInvoice(approver, invoiceId);
    expect(approved).toMatchObject({
      status: 'draft',
      created_by: { id: makerUser.id },
      prebill_approved_by: { id: approverUser.id },
      prebill_reason: prebillReason,
      vat_amount: '12.00',
      total_amount: '112.00',
      balance: '112.00',
      journal_entry: null,
    });
    expect(approved.status_history).toEqual(expect.arrayContaining([
      expect.objectContaining({
        event_type: 'prebill_approved',
        from_status: 'draft',
        to_status: 'draft',
        actor_name: approverUser.name,
      }),
    ]));
    expect(approved.prebill_approved_at).toBeTruthy();
    await expect(approver.getByText('Prebill approved.')).toBeVisible();
    await expect(approver.getByText(`By ${approverUser.name}`, { exact: true })).toBeVisible();
    expect((await trialBalance(approver, periodStart, invoiceDate)).totals).toEqual(baselineTrialBalance.totals);
    expect(await invoiceAudit(auditReader, invoiceId)).toContainEqual(expect.objectContaining({
      action: 'updated',
      model_id: invoiceId,
      user: expect.objectContaining({ email: 'finance2@ogami.test' }),
      new_values: expect.objectContaining({ prebill_approved_by: expect.anything() }),
    }));
    await expect(approver.getByRole('button', { name: 'Approve prebill', exact: true })).toHaveCount(0);
    await expect(approver.getByRole('button', { name: 'Finalize', exact: true })).toHaveCount(0);
    await expect(approver.getByText(/approved by/i)).toBeVisible();

    const revisedReason = `Revised terms ${randomUUID()}`;
    await page.goto(`/accounting/invoices/${invoiceId}`);
    await expect(page.getByRole('button', { name: 'Edit draft', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Edit draft', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/accounting/invoices/${invoiceId}/edit$`));
    await expect(page.getByRole('heading', { name: 'Edit invoice' })).toBeVisible();
    await expect(page.getByLabel('Prebill approval reason')).toHaveValue(prebillReason);
    await expect(page.locator('input[name="items.0.unit_price"]')).toHaveValue('100');
    await page.getByLabel('Prebill approval reason').fill(revisedReason);
    await page.locator('input[name="items.0.unit_price"]').fill('200.00');
    await expect(page.getByText('Subtotal: ₱200.00')).toBeVisible();
    await expect(page.getByText('VAT: ₱24.00')).toBeVisible();
    await expect(page.getByText('Total: ₱224.00')).toBeVisible();
    const editResponsePromise = page.waitForResponse((response) =>
      response.request().method() === 'PUT' && response.url().endsWith(`/invoices/${invoiceId}`),
    );
    await page.getByRole('button', { name: 'Save changes', exact: true }).click();
    expect((await editResponsePromise).status()).toBe(200);
    await expect(page).toHaveURL(new RegExp(`/accounting/invoices/${invoiceId}$`));

    const edited = await readInvoice(page, invoiceId);
    expect(edited).toMatchObject({
      status: 'draft',
      lifecycle_type: 'prebill',
      prebill_reason: revisedReason,
      prebill_approved_by: null,
      prebill_approved_at: null,
      subtotal: '200.00',
      vat_amount: '24.00',
      total_amount: '224.00',
      amount_paid: '0.00',
      balance: '224.00',
      journal_entry: null,
      items: [{ description: 'Prepaid equipment', quantity: '1.00', unit_price: '200.00', total: '200.00' }],
    });
    expect(edited.status_history).toEqual(expect.arrayContaining([
      expect.objectContaining({
        event_type: 'updated',
        from_status: 'draft',
        to_status: 'draft',
        actor_name: makerUser.name,
      }),
    ]));
    expect((await trialBalance(page, periodStart, invoiceDate)).totals).toEqual(baselineTrialBalance.totals);
    expect(await invoiceAudit(auditReader, invoiceId)).toContainEqual(expect.objectContaining({
      action: 'updated',
      model_id: invoiceId,
      user: expect.objectContaining({ email: makerUser.email }),
      new_values: expect.objectContaining({ prebill_reason: revisedReason, total_amount: '224.00' }),
    }));
    await expect(page.getByText(/awaiting prebill approval/i)).toBeVisible();
    await expect(page.getByRole('button', { name: 'Approve prebill', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Finalize', exact: true })).toHaveCount(0);

    const selfApproveEdited = await page.request.post(
      `${baseUrl()}/api/v1/invoices/${invoiceId}/approve-prebill`,
      { headers: await mutationHeaders(page) },
    );
    expect(selfApproveEdited.status()).toBe(403);
    expect((await readInvoice(page, invoiceId)).prebill_approved_by).toBeNull();
    expect((await trialBalance(page, periodStart, invoiceDate)).totals).toEqual(baselineTrialBalance.totals);

    await approver.goto(`/accounting/invoices/${invoiceId}`);
    await expect(approver.getByRole('button', { name: 'Approve prebill', exact: true })).toBeVisible();
    await expect(approver.getByRole('button', { name: 'Finalize', exact: true })).toHaveCount(0);
    const reapproveResponsePromise = approver.waitForResponse((response) =>
      response.request().method() === 'POST' && response.url().endsWith(`/invoices/${invoiceId}/approve-prebill`),
    );
    await approver.getByRole('button', { name: 'Approve prebill', exact: true }).click();
    const reapproveDialog = approver.getByRole('dialog', { name: 'Approve prebill invoice?' });
    await expect(reapproveDialog).toBeVisible();
    await reapproveDialog.getByRole('button', { name: 'Approve', exact: true }).click();
    expect((await reapproveResponsePromise).status()).toBe(200);
    const reapproved = await readInvoice(approver, invoiceId);
    expect(reapproved).toMatchObject({
      status: 'draft',
      prebill_reason: revisedReason,
      prebill_approved_by: { id: approverUser.id },
      subtotal: '200.00',
      vat_amount: '24.00',
      total_amount: '224.00',
      balance: '224.00',
      journal_entry: null,
    });
    expect(reapproved.prebill_approved_at).toBeTruthy();
    expect((await trialBalance(approver, periodStart, invoiceDate)).totals).toEqual(baselineTrialBalance.totals);
    const approvalAudits = (await invoiceAudit(auditReader, invoiceId)).filter((entry) =>
      entry.action === 'updated' && entry.new_values.prebill_approved_by != null,
    );
    expect(approvalAudits).toHaveLength(2);
    expect(approvalAudits.every((entry) => entry.user?.email === 'finance2@ogami.test')).toBe(true);

    const approverPost = await approver.request.patch(
      `${baseUrl()}/api/v1/invoices/${invoiceId}/finalize`,
      { headers: await mutationHeaders(approver) },
    );
    expect(approverPost.status()).toBe(403);
    expect((await readInvoice(approver, invoiceId)).status).toBe('draft');
    expect((await trialBalance(approver, periodStart, invoiceDate)).totals).toEqual(baselineTrialBalance.totals);

    await loginAs(poster, 'finance3@ogami.test', 'password');
    const posterUserResponse = await poster.request.get(`${baseUrl()}/api/v1/auth/user`, {
      headers: statefulHeaders(),
    });
    expect(posterUserResponse.status()).toBe(200);
    const posterUser = (await posterUserResponse.json()).data as { id: string; permissions: string[] };
    expect(posterUser.permissions).toContain('accounting.invoices.create');
    await poster.goto(`/accounting/invoices/${invoiceId}`);
    await expect(poster.getByRole('button', { name: 'Finalize', exact: true })).toBeVisible();

    const finalizeResponsePromise = poster.waitForResponse((response) =>
      response.request().method() === 'PATCH' && response.url().endsWith(`/invoices/${invoiceId}/finalize`),
    );
    await poster.getByRole('button', { name: 'Finalize', exact: true }).click();
    const finalizeDialog = poster.getByRole('dialog', { name: 'Finalize invoice?' });
    await expect(finalizeDialog).toBeVisible();
    await finalizeDialog.getByRole('button', { name: 'Finalize', exact: true }).click();
    expect((await finalizeResponsePromise).status()).toBe(200);

    const finalized = await readInvoice(poster, invoiceId);
    expect(finalized).toMatchObject({
      status: 'finalized',
      created_by: { id: makerUser.id },
      prebill_approved_by: { id: approverUser.id },
      vat_amount: '24.00',
      total_amount: '224.00',
      amount_paid: '0.00',
      balance: '224.00',
      journal_entry: { status: 'posted' },
    });
    expect(finalized.status_history).toEqual(expect.arrayContaining([
      expect.objectContaining({
        event_type: 'status_changed',
        from_status: 'draft',
        to_status: 'finalized',
        actor_name: posterUser.name,
      }),
    ]));
    await expect(poster.getByText('Status changed from draft to finalized.')).toBeVisible();
    await expect(poster.getByText(`By ${posterUser.name}`, { exact: true })).toBeVisible();
    expect(finalized.invoice_number).toMatch(/^INV-\d{6}-\d{4}$/);
    const sourceJournalId = finalized.journal_entry!.id;
    const sourceJournal = await readEntry(poster, sourceJournalId);
    expect(sourceJournal).toMatchObject({
      reference_type: 'invoice',
      reference_id: invoiceId,
      status: 'posted',
      total_debit: '224.00',
      total_credit: '224.00',
      created_by: null,
      posted_by: { id: posterUser.id },
    });
    expect(sourceJournal.lines).toHaveLength(3);
    const postedTrialBalance = await trialBalance(poster, periodStart, invoiceDate);
    expect(postedTrialBalance.totals).toEqual({
      debit: addMoney(baselineTrialBalance.totals.debit, '224.00'),
      credit: addMoney(baselineTrialBalance.totals.credit, '224.00'),
    });
    expect(await invoiceAudit(auditReader, invoiceId)).toContainEqual(expect.objectContaining({
      action: 'updated',
      model_id: invoiceId,
      user: expect.objectContaining({ email: 'finance3@ogami.test' }),
      new_values: expect.objectContaining({ status: 'finalized' }),
    }));

    const sourceReverse = await poster.request.post(
      `${baseUrl()}/api/v1/journal-entries/${sourceJournalId}/reverse`,
      {
        headers: await mutationHeaders(poster),
        data: { reverse_date: invoiceDate, reason: 'Attempted direct source reversal.' },
      },
    );
    expect(sourceReverse.status()).toBe(403);
    expect((await sourceReverse.json()).message).toContain('source document');
    expect((await readEntry(poster, sourceJournalId)).status).toBe('posted');
    expect((await trialBalance(poster, periodStart, invoiceDate)).totals).toEqual(postedTrialBalance.totals);
    await poster.goto(`/accounting/journal-entries/${sourceJournalId}`);
    await expect(poster.getByRole('button', { name: 'Reverse', exact: true })).toHaveCount(0);

    await poster.goto(`/accounting/invoices/${invoiceId}`);
    await expect(poster.getByRole('button', { name: 'Cancel', exact: true })).toBeVisible();
    const cancelResponsePromise = poster.waitForResponse((response) =>
      response.request().method() === 'PATCH' && response.url().endsWith(`/invoices/${invoiceId}/cancel`),
    );
    await poster.getByRole('button', { name: 'Cancel', exact: true }).click();
    const cancelDialog = poster.getByRole('dialog', { name: 'Cancel this invoice?' });
    await expect(cancelDialog).toBeVisible();
    await cancelDialog.getByRole('button', { name: 'Cancel invoice', exact: true }).click();
    expect((await cancelResponsePromise).status()).toBe(200);

    const cancelled = await readInvoice(poster, invoiceId);
    expect(cancelled).toMatchObject({
      status: 'cancelled',
      balance: '0.00',
      amount_paid: '0.00',
      journal_entry: { status: 'reversed' },
    });
    expect(cancelled.status_history).toEqual(expect.arrayContaining([
      expect.objectContaining({
        event_type: 'status_changed',
        from_status: 'finalized',
        to_status: 'cancelled',
        actor_name: posterUser.name,
      }),
    ]));
    await expect(poster.getByText(/Cancelled after prebill approval/i)).toBeVisible();
    await expect(poster.getByText('Status changed from finalized to cancelled.')).toBeVisible();
    const reversedSource = await readEntry(poster, sourceJournalId);
    expect(reversedSource.status).toBe('reversed');
    expect(reversedSource.reversed_by_entry_id).toBeTruthy();
    const reversal = await readEntry(poster, reversedSource.reversed_by_entry_id!);
    expect(reversal).toMatchObject({
      reference_type: 'journal_entry_reversal',
      reference_id: sourceJournalId,
      status: 'posted',
      total_debit: '224.00',
      total_credit: '224.00',
      posted_by: { id: posterUser.id },
    });
    expect(reversal.lines).toHaveLength(3);
    const cancelledTrialBalance = await trialBalance(poster, periodStart, invoiceDate);
    expect(cancelledTrialBalance.totals).toEqual({
      debit: addMoney(baselineTrialBalance.totals.debit, '448.00'),
      credit: addMoney(baselineTrialBalance.totals.credit, '448.00'),
    });
    const reversedAccounts = new Set(sourceJournal.lines.map((line) => line.account!.code));
    expect(cancelledTrialBalance.accounts
      .filter((account) => reversedAccounts.has(account.code))
      .every((account) => account.balance === (
        baselineTrialBalance.accounts.find((baseline) => baseline.code === account.code)?.balance ?? '0.00'
      ))).toBe(true);
    expect(Object.fromEntries(reversal.lines.map((line) => [line.account!.id, {
      debit: line.debit,
      credit: line.credit,
    }]))).toEqual(Object.fromEntries(sourceJournal.lines.map((line) => [line.account!.id, {
      debit: line.credit,
      credit: line.debit,
    }])));
    expect(await invoiceAudit(auditReader, invoiceId)).toContainEqual(expect.objectContaining({
      action: 'updated',
      model_id: invoiceId,
      user: expect.objectContaining({ email: 'finance3@ogami.test' }),
      new_values: expect.objectContaining({ status: 'cancelled' }),
    }));
  } finally {
    makerNet.stop();
    approverNet.stop();
    posterNet.stop();
    await Promise.all([auditContext.close(), approverContext.close(), posterContext.close()]);
    void makerContext;
  }

  for (const net of [makerNet, approverNet, posterNet]) {
    expect(net.failedRequests).toEqual([]);
    expect(relevantErrors(net)).toEqual([]);
  }
});
