import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AxiosError } from 'axios';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { salesOrdersApi } from '@/api/crm/salesOrders';
import type { SalesOrder, SalesOrderChainStep } from '@/types/crm';
import SalesOrderDetailPage from './detail';

const { canPermission } = vi.hoisted(() => ({ canPermission: vi.fn((_permission: string) => false) }));

vi.mock('@/api/crm/salesOrders', () => ({
  salesOrdersApi: {
    show: vi.fn(),
    agreements: vi.fn().mockResolvedValue({ sales_order_id: 'so-1', so_number: 'SO-1', can_publish: false, can_record_external: false, can_acknowledge: false, revisions: [] }),
    agreementFile: vi.fn(),
    publishAgreement: vi.fn(),
    recordAgreementAcknowledgment: vi.fn(),
    chain: vi.fn(),
    confirm: vi.fn(),
    cancel: vi.fn(),
    proposeDeliveryDate: vi.fn(),
  },
}));
vi.mock('@/api/accounting/invoices', () => ({ invoicesApi: { finalize: vi.fn() } }));
vi.mock('@/hooks/useChainProgress', () => ({ useChainProgress: () => undefined }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: canPermission }) }));

const order = (status_history: unknown[] = []): SalesOrder =>
  ({
    id: 'so-1',
    so_number: 'SO-202610-0001',
    date: '2026-10-01',
    subtotal: '100.00',
    vat_amount: '12.00',
    total_amount: '112.00',
    status: 'cancelled',
    status_label: 'Cancelled',
    payment_terms_days: 30,
    delivery_terms: null,
    incoterm: null,
    notes: null,
    is_editable: false,
    is_cancellable: false,
    item_count: 0,
    customer: { id: 'customer-1', name: 'Test customer' },
    creator: { id: 'user-1', name: 'Sales maker' },
    items: [],
    created_at: '2026-10-01T09:00:00Z',
    updated_at: '2026-10-02T14:00:00Z',
    status_history,
  }) as unknown as SalesOrder;

function renderPage(value: SalesOrder, steps: SalesOrderChainStep[] = []) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  vi.mocked(salesOrdersApi.show).mockResolvedValue(value);
  vi.mocked(salesOrdersApi.chain).mockResolvedValue(steps);

  const view = render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/crm/sales-orders/so-1']}>
        <Routes>
          <Route path="/crm/sales-orders/:id" element={<SalesOrderDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );

  return { ...view, queryClient };
}

describe('SalesOrderDetailPage activity', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    canPermission.mockReturnValue(false);
  });

  const textContent = (value: string) => (_: string, element: Element | null) =>
    element?.textContent === value;

  it('renders recorded status events with actors and cancellation comment', async () => {
    renderPage(
      order([
        {
          event_type: 'created',
          status: 'draft',
          status_label: 'Draft',
          previous_status: null,
          previous_status_label: null,
          actor_name: 'Sales maker',
          occurred_at: '2026-10-01T09:00:00Z',
          comment: null,
        },
        {
          event_type: 'status_changed',
          status: 'confirmed',
          status_label: 'Confirmed',
          previous_status: 'draft',
          previous_status_label: 'Draft',
          actor_name: 'Sales checker',
          occurred_at: '2026-10-01T10:00:00Z',
          comment: null,
        },
        {
          event_type: 'status_changed',
          status: 'cancelled',
          status_label: 'Cancelled',
          previous_status: 'confirmed',
          previous_status_label: 'Confirmed',
          actor_name: 'Sales manager',
          occurred_at: '2026-10-02T14:00:00Z',
          comment: 'Customer withdrew',
        },
      ]),
    );

    expect(
      await screen.findByText(textContent('Sales order SO-202610-0001 created with status Draft.')),
    ).toBeInTheDocument();
    expect(
      screen.getByText(textContent('Status changed from Draft to Confirmed.')),
    ).toBeInTheDocument();
    expect(
      screen.getByText(textContent('Status changed from Confirmed to Cancelled.')),
    ).toBeInTheDocument();
    expect(screen.getByText(/By Sales checker/)).toBeInTheDocument();
    expect(screen.getByText(/Customer withdrew/)).toBeInTheDocument();
    expect(screen.queryByText('Status: Cancelled')).not.toBeInTheDocument();
  });

  it('explains when legacy orders have no recorded status history', async () => {
    renderPage(order());

    expect(await screen.findByText('No status changes have been recorded.')).toBeInTheDocument();
  });
});

it('renders the confirmed API record immediately without refetching stale detail data', async () => {
  const draft = {
    ...order(),
    status: 'draft',
    status_label: 'Draft',
    is_editable: true,
    is_cancellable: true,
    available_actions: { can_confirm: true },
    item_count: 1,
    capabilities: { awaiting_delivery_response: false },
  } as SalesOrder;
  const confirmed = {
    ...draft,
    status: 'confirmed',
    status_label: 'Confirmed',
    is_editable: false,
    is_cancellable: false,
    available_actions: { can_confirm: false },
    updated_at: '2026-11-02T12:01:00.000000Z',
  } as SalesOrder;
  canPermission.mockImplementation((permission: string) => permission === 'crm.sales_orders.confirm');
  const { queryClient } = renderPage(draft);
  await screen.findByRole('button', { name: 'Confirm order' });
  vi.mocked(salesOrdersApi.confirm).mockResolvedValue({
    data: confirmed,
    chain_result: {
      so_number: confirmed.so_number,
      planning_status: 'queued',
      work_orders_created: 0,
      auto_scheduled: 0,
      needs_manual: 0,
      shortages: 0,
      prs_created: 0,
      work_orders: [],
      scheduling_conflicts: [],
    },
  });
  const detailReadsBeforeConfirm = vi.mocked(salesOrdersApi.show).mock.calls.length;

  fireEvent.click(screen.getByRole('button', { name: 'Confirm order' }));
  fireEvent.click(await screen.findByRole('button', { name: 'Confirm & Start Chain' }));

  await waitFor(() => expect(screen.getByText('Confirmed')).toBeInTheDocument());
  expect(screen.queryByRole('button', { name: 'Confirm order' })).not.toBeInTheDocument();
  expect(salesOrdersApi.show).toHaveBeenCalledTimes(detailReadsBeforeConfirm);
  expect(queryClient.getQueryData(['crm', 'sales-orders', 'detail', 'so-1'])).toMatchObject({ status: 'confirmed' });
});

it('refetches and closes the confirmation dialog when the order state has changed', async () => {
  const draft = {
    ...order(),
    status: 'draft',
    status_label: 'Draft',
    is_editable: true,
    is_cancellable: true,
    available_actions: { can_confirm: true },
    item_count: 1,
    capabilities: { awaiting_delivery_response: false },
  } as SalesOrder;
  const confirmed = {
    ...draft,
    status: 'confirmed',
    status_label: 'Confirmed',
    available_actions: { can_confirm: false },
  } as SalesOrder;
  canPermission.mockImplementation((permission: string) => permission === 'crm.sales_orders.confirm');
  renderPage(draft);
  await screen.findByRole('button', { name: 'Confirm order' });
  vi.mocked(salesOrdersApi.show).mockResolvedValue(confirmed);
  vi.mocked(salesOrdersApi.confirm).mockRejectedValue(new AxiosError(
    'This sales order is no longer awaiting confirmation.',
    '409',
    undefined,
    undefined,
    {
      status: 409,
      data: {
        code: 'sales_order_state_conflict',
        message: 'This sales order is no longer awaiting confirmation.',
      },
      statusText: 'Conflict',
      headers: {},
      config: {} as never,
    },
  ));

  fireEvent.click(screen.getByRole('button', { name: 'Confirm order' }));
  fireEvent.click(await screen.findByRole('button', { name: 'Confirm & Start Chain' }));

  await waitFor(() => expect(salesOrdersApi.show).toHaveBeenCalledTimes(2));
  expect(await screen.findByText('Confirmed')).toBeVisible();
  expect(screen.queryByRole('button', { name: 'Confirm order' })).not.toBeInTheDocument();
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
});

it('leaves active order and chain freshness to the shared realtime hook', async () => {
  const { queryClient } = renderPage({ ...order(), status: 'in_production', status_label: 'In Production' });
  await waitFor(() => expect(salesOrdersApi.show).toHaveBeenCalled());
  await waitFor(() => expect(salesOrdersApi.chain).toHaveBeenCalled());

  const detailOptions = queryClient.getQueryCache().find({ queryKey: ['crm', 'sales-orders', 'detail', 'so-1'] })?.options as { refetchInterval?: unknown } | undefined;
  const chainOptions = queryClient.getQueryCache().find({ queryKey: ['crm', 'sales-orders', 'chain', 'so-1'] })?.options as { refetchInterval?: unknown } | undefined;
  expect(detailOptions?.refetchInterval).toBeUndefined();
  expect(chainOptions?.refetchInterval).toBeUndefined();
});

it('shows required delivery date and pending proposal without commercial edit controls', async () => {
  renderPage({
    ...order(),
    status: 'draft',
    status_label: 'Draft',
    submission_source: 'customer_portal',
    requested_delivery_dates: ['2026-10-10'],
    delivery_dates: ['2026-10-10'],
    capabilities: { can_propose_delivery: true, awaiting_delivery_response: true },
    latest_response: {
      id: 'proposal-1',
      type: 'delivery_date',
      status: 'pending',
      proposed_delivery_date: '2026-10-14',
      notes: 'Capacity delay',
    },
  });
  expect(await screen.findByText('Required delivery date')).toBeVisible();
  expect(
    screen.getByText('Waiting for customer acceptance. Confirmation is blocked.'),
  ).toBeVisible();
  expect(screen.queryByRole('button', { name: /^Edit$/ })).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Propose delivery date' })).toBeVisible();
});

it('shows delivery readiness from completed production and QC', async () => {
  renderPage({ ...order(), status: 'in_production', status_label: 'In Production' }, [
    { key: 'in_production', label: 'In Production', state: 'done', date: '2026-10-09' },
    { key: 'qc_outgoing', label: 'QC Outgoing', state: 'done', date: '2026-10-09' },
    { key: 'delivered', label: 'Delivered', state: 'active', date: null },
  ]);
  expect(await screen.findByText('Ready for delivery')).toBeVisible();
});

it('shows awaiting QC after production completes', async () => {
  renderPage({ ...order(), status: 'in_production', status_label: 'In Production' }, [
    { key: 'in_production', label: 'In Production', state: 'done', date: '2026-10-09' },
    { key: 'qc_outgoing', label: 'QC Outgoing', state: 'active', date: null },
  ]);
  expect(await screen.findByText('Awaiting QC')).toBeVisible();
});

it('does not render confirmation when the API denies the action', async () => {
  canPermission.mockImplementation((permission: string) => permission === 'crm.sales_orders.confirm');
  renderPage({
    ...order(),
    status: 'draft',
    status_label: 'Draft',
    available_actions: { can_confirm: false },
  } as SalesOrder);

  await screen.findByText('Draft');
  expect(screen.queryByRole('button', { name: 'Confirm order' })).not.toBeInTheDocument();
});

it('does not render edit or cancel when the API denies those actions', async () => {
  canPermission.mockImplementation((permission: string) =>
    permission === 'crm.sales_orders.update' || permission === 'crm.sales_orders.cancel',
  );
  renderPage({
    ...order(),
    status: 'draft',
    status_label: 'Draft',
    is_editable: true,
    is_cancellable: true,
    available_actions: { can_confirm: false, can_edit: false, can_cancel: false },
  } as SalesOrder);

  await screen.findByText('Draft');
  expect(screen.queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Cancel' })).not.toBeInTheDocument();
});
