import { expect, test } from '@playwright/test';
import {
  baseUrl,
  loginAs,
  loginViaUi,
  statefulCsrfHeaders,
  statefulHeaders,
} from './helpers';

type ApiPage<T> = { data?: T[] | { data?: T[] } };
type RevenueAccount = { id: string; code: string; type: string; is_active: boolean };

function rowsFrom<T>(body: ApiPage<T>): T[] {
  const data = body.data;
  return Array.isArray(data) ? data : data?.data ?? [];
}

function manilaDate(): string {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit',
  }).format(new Date());
}

test('customer invoice detail and list refresh after finance applies a credit across roles', async ({ browser }) => {
  test.setTimeout(180_000);

  const customerContext = await browser.newContext({ baseURL: baseUrl() });
  const makerContext = await browser.newContext({ baseURL: baseUrl() });
  const approverContext = await browser.newContext({ baseURL: baseUrl() });
  const finalizerContext = await browser.newContext({ baseURL: baseUrl() });
  const customerDetail = await customerContext.newPage();
  const customerList = await customerContext.newPage();
  const makerPage = await makerContext.newPage();
  const approverPage = await approverContext.newPage();
  const finalizerPage = await finalizerContext.newPage();
  const invoiceFrames: Array<{ event: string; channel: string; data: unknown }> = [];
  const invoiceAuthStatuses: number[] = [];
  let invoiceId: string | undefined;
  let creditNoteId: string | undefined;
  let creditNoteApplied = false;

  customerDetail.on('websocket', (socket) => {
    socket.on('framereceived', (frame) => {
      try {
        const message = JSON.parse(String(frame.payload)) as {
          event?: string;
          channel?: string;
          data?: unknown;
        };
        if (message.event === 'chain.refresh' && message.channel?.startsWith('private-customer.invoice.')) {
          invoiceFrames.push({ event: message.event, channel: message.channel, data: message.data });
        }
      } catch {
        // Pusher control frames are not JSON application events.
      }
    });
  });
  customerDetail.on('response', (response) => {
    if (response.url().includes('/api/v1/b2b/customer/broadcasting/auth')) {
      invoiceAuthStatuses.push(response.status());
    }
  });

  try {
    await loginViaUi(customerDetail, 'portal@cust.test', 'password');
    const customerHeaders = statefulHeaders();
    const customerResponse = await customerDetail.request.get(
      `${baseUrl()}/api/v1/b2b/customer/me`,
      { headers: customerHeaders },
    );
    expect(customerResponse.status()).toBe(200);
    const customerId = (await customerResponse.json() as { data: { customer_id: string } }).data.customer_id;

    await loginAs(makerPage, 'admin@ogami.test', 'password');
    await loginAs(approverPage, 'finance@ogami.test', 'password');
    await loginAs(finalizerPage, 'finance2@ogami.test', 'password');
    const financeHeaders = await statefulCsrfHeaders(finalizerPage);
    const accountsResponse = await finalizerPage.request.get(
      `${baseUrl()}/api/v1/accounts?type=revenue&is_active=true&per_page=200`,
      { headers: statefulHeaders() },
    );
    expect(accountsResponse.status()).toBe(200);
    const revenueAccount = rowsFrom<RevenueAccount>(await accountsResponse.json() as ApiPage<RevenueAccount>)
      .find((account) => account.code === '4010' && account.type === 'revenue' && account.is_active);
    expect(revenueAccount, 'the chart of accounts needs its standard sales revenue account').toBeTruthy();

    // The prebill and its matching credit note net to zero in the GL. The
    // small, uniquely described transaction is kept only after full offset,
    // so the live dev database receives no open test receivable.
    const reason = `Realtime cross-role ${Date.now()}`;
    const invoiceResponse = await makerPage.request.post(`${baseUrl()}/api/v1/invoices`, {
      headers: await statefulCsrfHeaders(makerPage),
      data: {
        customer_id: customerId,
        lifecycle_type: 'prebill',
        prebill_reason: reason,
        date: manilaDate(),
        due_date: manilaDate(),
        is_vatable: false,
        remarks: reason,
        items: [{
          revenue_account_id: revenueAccount!.id,
          description: reason,
          quantity: '1.00',
          unit: 'ea',
          unit_price: '0.02',
        }],
      },
    });
    expect(invoiceResponse.status(), await invoiceResponse.text()).toBe(201);
    invoiceId = (await invoiceResponse.json() as { data: { id: string } }).data.id;

    const approvalResponse = await approverPage.request.post(
      `${baseUrl()}/api/v1/invoices/${invoiceId}/approve-prebill`,
      { headers: await statefulCsrfHeaders(approverPage) },
    );
    expect(approvalResponse.status(), await approvalResponse.text()).toBe(200);

    const finalizeInvoice = await finalizerPage.request.patch(
      `${baseUrl()}/api/v1/invoices/${invoiceId}/finalize`,
      { headers: financeHeaders },
    );
    expect(finalizeInvoice.status(), await finalizeInvoice.text()).toBe(200);
    const finalized = (await finalizeInvoice.json() as {
      data: { id: string; invoice_number: string; status: string; balance: string };
    }).data;
    expect(finalized.status).toBe('finalized');
    expect(Number(finalized.balance)).toBe(0.02);

    await customerDetail.goto(`/portal/customer/invoices/${invoiceId}`, { waitUntil: 'domcontentloaded' });
    await expect(customerDetail.getByText('Balance Due', { exact: true })).toBeVisible();
    const detailBalanceCard = customerDetail.getByText('Balance Due', { exact: true }).locator('..');
    const initialDetailText = await detailBalanceCard.innerText();
    expect(initialDetailText).toMatch(/0\.02/);
    await expect(customerDetail.getByText('Awaiting payment', { exact: true })).toBeVisible();

    await customerList.goto('/portal/customer/invoices', { waitUntil: 'domcontentloaded' });
    const invoiceRow = customerList.getByRole('row').filter({ hasText: finalized.invoice_number });
    await expect(invoiceRow).toBeVisible();
    await expect(invoiceRow.getByText('Finalized', { exact: true })).toBeVisible();
    const initialListText = await invoiceRow.innerText();
    await expect.poll(() => invoiceAuthStatuses, {
      message: 'customer invoice channel should authorize with the customer portal session',
      timeout: 10_000,
    }).toContain(200);

    const beforeActionDetailReads = await customerDetail.evaluate(() =>
      performance.getEntriesByType('resource').filter((entry) => entry.name.includes('/api/v1/b2b/customer/invoices/')).length,
    );
    await expect.poll(() => invoiceFrames.length, {
      message: 'create, approve and finalize should each signal the customer invoice channel once',
      timeout: 10_000,
    }).toBe(3);
    const noteResponse = await finalizerPage.request.post(`${baseUrl()}/api/v1/accounting/credit-notes`, {
      headers: financeHeaders,
      data: {
        type: 'customer',
        customer_id: customerId,
        invoice_id: invoiceId,
        date: manilaDate(),
        reason,
        is_vatable: false,
        lines: [{ account_id: revenueAccount!.id, description: reason, amount: '0.02' }],
      },
    });
    expect([200, 201], await noteResponse.text()).toContain(noteResponse.status());
    creditNoteId = (await noteResponse.json() as { data: { id: string } }).data.id;

    const finalizeNote = await finalizerPage.request.post(
      `${baseUrl()}/api/v1/accounting/credit-notes/${creditNoteId}/finalize`,
      { headers: financeHeaders },
    );
    expect(finalizeNote.status(), await finalizeNote.text()).toBe(200);

    const frameCountBeforeApply = invoiceFrames.length;
    const applyStartedAt = Date.now();
    const applyCredit = await finalizerPage.request.post(
      `${baseUrl()}/api/v1/accounting/credit-notes/${creditNoteId}/apply`,
      {
        headers: financeHeaders,
        data: { operation_key: crypto.randomUUID(), invoice_id: invoiceId, amount: '0.02' },
      },
    );
    expect(applyCredit.status(), await applyCredit.text()).toBe(200);
    creditNoteApplied = true;

    await expect.poll(() => detailBalanceCard.innerText(), {
      message: 'customer detail should refetch the committed invoice without a reload',
      timeout: 10_000,
    }).not.toBe(initialDetailText);
    await expect(customerDetail.getByText('Paid', { exact: true })).toBeVisible();
    await expect(detailBalanceCard).toContainText(/0\.00/);
    await expect(customerDetail.getByRole('button', { name: 'Submit payment' })).toHaveCount(0);
    await expect.poll(() => invoiceRow.innerText(), {
      message: 'customer list should refresh its status and balance without a reload',
      timeout: 10_000,
    }).not.toBe(initialListText);
    await expect(invoiceRow.getByText('Paid', { exact: true })).toBeVisible();
    await expect.poll(() => invoiceFrames.length, {
      message: 'the customer detail should receive the private invoice refresh event',
      timeout: 10_000,
    }).toBe(frameCountBeforeApply + 1);

    const payload = invoiceFrames[invoiceFrames.length - 1].data;
    const eventData = typeof payload === 'string' ? JSON.parse(payload) as Record<string, unknown> : payload as Record<string, unknown>;
    expect(Object.keys(eventData).sort()).toEqual(['event_id', 'refresh']);
    const afterActionDetailReads = await customerDetail.evaluate(() =>
      performance.getEntriesByType('resource').filter((entry) => entry.name.includes('/api/v1/b2b/customer/invoices/')).length,
    );
    expect(afterActionDetailReads).toBeGreaterThan(beforeActionDetailReads);
    const latencyMs = Date.now() - applyStartedAt;
    test.info().annotations.push({ type: 'action-start-to-customer-ui-ms', description: String(latencyMs) });
    expect(latencyMs).toBeLessThan(5_000);
  } finally {
    if (invoiceId && !creditNoteApplied) {
      const headers = await statefulCsrfHeaders(finalizerPage).catch(() => statefulHeaders());
      if (creditNoteId) {
        await finalizerPage.request.post(`${baseUrl()}/api/v1/accounting/credit-notes/${creditNoteId}/void`, {
          headers,
          data: { reason: 'Clean up an unapplied customer invoice realtime test credit.' },
        }).catch(() => undefined);
      }
      await finalizerPage.request.patch(`${baseUrl()}/api/v1/invoices/${invoiceId}/cancel`, { headers }).catch(() => undefined);
    }
    await customerContext.close();
    await makerContext.close();
    await approverContext.close();
    await finalizerContext.close();
  }
});
