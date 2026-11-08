import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AxiosError } from 'axios';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { purchaseOrdersApi } from '@/api/purchasing/purchase-orders';
import type { PurchaseOrder, PurchaseOrderResponse } from '@/types/purchasing';
import PurchaseOrderDetailPage from './detail';

vi.mock('@/api/purchasing/purchase-orders', () => ({
  purchaseOrdersApi: {
    show: vi.fn(),
    options: vi.fn(),
    submit: vi.fn(),
    approve: vi.fn(),
    reject: vi.fn(),
    send: vi.fn(),
    cancel: vi.fn(),
    close: vi.fn(),
    shortClose: vi.fn(),
    acknowledgeBudget: vi.fn(),
    update: vi.fn(),
    acceptResponse: vi.fn(),
    rejectResponse: vi.fn(),
    pdfUrl: vi.fn(() => '/api/v1/purchasing/purchase-orders/po-1/pdf'),
  },
}));
vi.mock('@/api/accounting/bills', () => ({ billsApi: { postDraft: vi.fn() } }));
vi.mock('@/hooks/useChainProgress', () => ({ useChainProgress: () => undefined }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true }) }));
vi.mock('./components/SupplierActivityPanel', () => ({ SupplierActivityPanel: () => null }));

function purchaseOrder(status: PurchaseOrder['status'], canSubmit: boolean): PurchaseOrder {
  return {
    id: 'po-1',
    po_number: 'PO-202611-0001',
    date: '2026-11-01',
    expected_delivery_date: null,
    confirmed_delivery_date: null,
    latest_response: null,
    subtotal: '100.00',
    vat_amount: '0.00',
    total_amount: '100.00',
    is_vatable: false,
    status,
    status_label: status === 'pending_approval' ? 'Pending approval' : 'Draft',
    requires_vp_approval: false,
    is_auto_generated: false,
    incoterm: null,
    has_overdue_approval: false,
    current_approval_step: 0,
    approved_at: null,
    sent_to_supplier_at: null,
    remarks: null,
    quantity_received_pct: 0,
    quantity_accepted_pct: 0,
    vendor: { id: 'vendor-1', name: 'Test vendor', contact_person: null, email: null },
    purchase_request: null,
    items: [],
    goods_receipt_notes: [],
    bills: [],
    approval_records: [],
    actions: {
      can_view: true,
      can_update: false,
      can_delete: false,
      can_submit: canSubmit,
      can_approve: false,
      can_reject: false,
      can_send: false,
      can_cancel: false,
      can_close: false,
      can_print: true,
    },
  } as PurchaseOrder;
}

function renderPage(value: PurchaseOrder) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  vi.mocked(purchaseOrdersApi.show).mockResolvedValue(value);
  vi.mocked(purchaseOrdersApi.options).mockResolvedValue({
    statuses: [
      { value: 'draft', label: 'Draft' },
      { value: 'pending_approval', label: 'Pending approval' },
    ],
    incoterms: [],
  });

  const view = render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/purchasing/purchase-orders/po-1']}>
        <Routes>
          <Route path="/purchasing/purchase-orders/:id" element={<PurchaseOrderDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );

  return { ...view, queryClient };
}

describe('PurchaseOrderDetailPage mutation freshness', () => {
  beforeEach(() => vi.clearAllMocks());

  it('shows the submitted API record and removes the stale submit action immediately', async () => {
    const draft = purchaseOrder('draft', true);
    const submitted = purchaseOrder('pending_approval', false);
    const { queryClient } = renderPage(draft);
    await screen.findByRole('button', { name: 'Submit' });
    vi.mocked(purchaseOrdersApi.submit).mockResolvedValue(submitted);
    const detailReadsBeforeSubmit = vi.mocked(purchaseOrdersApi.show).mock.calls.length;

    fireEvent.click(screen.getByRole('button', { name: 'Submit' }));
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Submit' }));

    await waitFor(() => expect(screen.getByText('Pending approval')).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: 'Submit' })).not.toBeInTheDocument();
    expect(purchaseOrdersApi.show).toHaveBeenCalledTimes(detailReadsBeforeSubmit);
    expect(queryClient.getQueryData(['purchasing', 'purchase-orders', 'detail', 'po-1']))
      .toMatchObject({ status: 'pending_approval', actions: { can_submit: false } });
  });

  it('refetches the current PO when submit is based on a stale state', async () => {
    const draft = purchaseOrder('draft', true);
    const pending = purchaseOrder('pending_approval', false);
    renderPage(draft);
    await screen.findByRole('button', { name: 'Submit' });
    vi.mocked(purchaseOrdersApi.show).mockResolvedValue(pending);
    const conflict = new AxiosError('This PO is no longer a draft.', '409', undefined, undefined, {
      status: 409,
      data: { code: 'purchase_order_state_conflict', message: 'This PO is no longer a draft.' },
      statusText: 'Conflict',
      headers: {},
      config: {} as never,
    });
    vi.mocked(purchaseOrdersApi.submit).mockRejectedValue(conflict);

    fireEvent.click(screen.getByRole('button', { name: 'Submit' }));
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Submit' }));

    expect(await screen.findByText('Pending approval')).toBeVisible();
    expect(screen.queryByRole('button', { name: 'Submit' })).not.toBeInTheDocument();
    expect(purchaseOrdersApi.show).toHaveBeenCalledTimes(2);
  });

  it('fails closed instead of reconstructing actions when the server map is missing', async () => {
    const legacyPendingOrder = { ...purchaseOrder('pending_approval', true), actions: null };
    renderPage(legacyPendingOrder);

    expect(await screen.findByText('Pending approval')).toBeVisible();
    expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Reject' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Submit' })).not.toBeInTheDocument();
  });

  it('shows the resolved supplier response from the mutation response while PO refetch is pending', async () => {
    const pending: PurchaseOrderResponse = {
      id: 'response-1',
      type: 'propose',
      status: 'pending',
      proposed_delivery_date: null,
      notes: null,
      responded_at: '2026-11-02T00:00:00Z',
      resolved_at: null,
      resolution_notes: null,
      items: [],
      available_actions: { accept: true, reject: true },
    };
    const draft = { ...purchaseOrder('draft', false), latest_response: pending };
    const { queryClient } = renderPage(draft);
    await screen.findByRole('button', { name: 'Accept' });
    vi.mocked(purchaseOrdersApi.show).mockImplementation(() => new Promise(() => undefined));
    vi.mocked(purchaseOrdersApi.acceptResponse).mockResolvedValue({
      ...pending,
      status: 'accepted',
      resolved_at: '2026-11-02T00:01:00Z',
      available_actions: { accept: false, reject: false },
    });

    fireEvent.click(screen.getByRole('button', { name: 'Accept' }));

    await waitFor(() => expect(screen.getByText('accepted')).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: 'Accept' })).not.toBeInTheDocument();
    expect(queryClient.getQueryData<PurchaseOrder>(['purchasing', 'purchase-orders', 'detail', 'po-1']))
      .toMatchObject({ latest_response: { id: 'response-1', status: 'accepted' } });
  });

  it('hides supplier response decisions when the server denies them', async () => {
    const pending = {
      id: 'response-1',
      type: 'propose',
      status: 'pending',
      proposed_delivery_date: null,
      notes: null,
      responded_at: '2026-11-02T00:00:00Z',
      resolved_at: null,
      resolution_notes: null,
      items: [],
      available_actions: { accept: false, reject: false },
    } as PurchaseOrderResponse;
    renderPage({ ...purchaseOrder('sent', false), latest_response: pending });

    expect(await screen.findByText('pending')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Accept' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Reject' })).not.toBeInTheDocument();
  });
});
