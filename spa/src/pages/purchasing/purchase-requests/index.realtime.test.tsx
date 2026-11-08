import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { purchaseRequestsApi } from '@/api/purchasing/purchase-requests';
import type { PaginatedResponse } from '@/types';
import type { PurchaseRequest, PurchaseRequestActions } from '@/types/purchasing';
import PurchaseRequestsListPage from './index';

vi.mock('@/api/purchasing/purchase-requests', () => ({
  purchaseRequestsApi: {
    list: vi.fn(),
    options: vi.fn(),
    bulkApprove: vi.fn(),
  },
}));
vi.mock('@/hooks/usePermission', () => ({
  usePermission: () => ({ can: (permission: string) => ['purchasing.pr.approve', 'purchasing.po.create'].includes(permission) }),
}));
vi.mock('@/hooks/useRealtimeRecord', () => ({ useRealtimeList: vi.fn() }));
vi.mock('react-hot-toast', () => ({ default: { error: vi.fn(), success: vi.fn() } }));

const request = (overrides: Partial<PurchaseRequest> = {}): PurchaseRequest => ({
  id: 'pr-1',
  pr_number: 'PR-202611-0001',
  date: '2026-11-05',
  required_delivery_date: null,
  reason: 'Realtime actor refresh',
  priority: 'normal',
  status: 'pending',
  po_conversion_status: 'not_started',
  sourcing_method: 'direct_po',
  po_conversion_note: null,
  po_conversion_at: null,
  is_auto_generated: false,
  auto_generated_reason: null,
  is_urgent: false,
  urgency_reason: null,
  has_overdue_approval: false,
  current_approval_step: 1,
  submitted_at: '2026-11-05T00:00:00Z',
  approved_at: null,
  total_estimated_amount: '100.00',
  requester: { id: 'employee-1', name: 'Requester' },
  department: { id: 'dept-1', name: 'Production', code: 'PROD' },
  template: null,
  available_actions: requestActions(false),
  ...overrides,
});

function requestActions(canConvert: boolean): PurchaseRequestActions {
  return {
    can_view: true,
    can_update: false,
    can_delete: false,
    can_submit: false,
    can_cancel: false,
    can_approve: false,
    can_reject: false,
    can_acknowledge_budget: false,
    can_convert: canConvert,
    can_start_rfq: false,
    can_set_sourcing_method: false,
    can_print: true,
  };
}

function page(data: PurchaseRequest[]): PaginatedResponse<PurchaseRequest> {
  return {
    data,
    links: { first: '', last: '', prev: null, next: null },
    meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length, from: data.length ? 1 : null, to: data.length || null },
  };
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return {
    queryClient,
    ...render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter><PurchaseRequestsListPage /></MemoryRouter>
      </QueryClientProvider>,
    ),
  };
}

describe('Purchase Request list realtime actions', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    localStorage.clear();
    vi.mocked(purchaseRequestsApi.list).mockResolvedValue(page([request()]));
    vi.mocked(purchaseRequestsApi.options).mockResolvedValue({
      statuses: [
        { value: 'pending', label: 'Pending' },
        { value: 'approved', label: 'Approved' },
      ],
      priorities: [{ value: 'normal', label: 'Normal' }],
      default_priority: 'normal',
    });
  });

  it('shows the successful bulk approval in the actor list before refetch finishes', async () => {
    let listCalls = 0;
    vi.mocked(purchaseRequestsApi.list).mockImplementation(() => {
      listCalls += 1;
      return listCalls === 1
        ? Promise.resolve(page([request({ available_actions: { ...requestActions(false), can_approve: true } })]))
        : new Promise(() => {});
    });
    vi.mocked(purchaseRequestsApi.bulkApprove).mockResolvedValue([
      { id: 'pr-1', status: 'approved', message: null },
    ]);
    renderPage();

    const row = await screen.findByRole('row', { name: /PR-202611-0001/ });
    fireEvent.click(within(row).getByRole('checkbox', { name: 'Select row' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Approve selected' }));

    await waitFor(() => expect(purchaseRequestsApi.bulkApprove).toHaveBeenCalledWith(['pr-1']));
    expect(await within(row).findByText('Approved', { exact: true })).toBeInTheDocument();
    expect(within(row).queryByRole('button', { name: 'Convert to PO' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Approve selected' })).not.toBeInTheDocument();
  });

  it('renders Convert to PO only when the API allows it for that row', async () => {
    const blocked = request({
      id: 'pr-blocked',
      pr_number: 'PR-BLOCKED',
      status: 'approved',
      status_label: 'Approved',
      available_actions: requestActions(false),
    });
    const allowed = request({
      id: 'pr-allowed',
      pr_number: 'PR-ALLOWED',
      status: 'approved',
      status_label: 'Approved',
      available_actions: requestActions(true),
    });
    vi.mocked(purchaseRequestsApi.list).mockResolvedValue(page([blocked, allowed]));
    renderPage();

    const blockedRow = await screen.findByRole('row', { name: /PR-BLOCKED/ });
    const allowedRow = screen.getByRole('row', { name: /PR-ALLOWED/ });
    expect(within(blockedRow).queryByRole('button', { name: 'Convert to PO' })).not.toBeInTheDocument();
    expect(within(allowedRow).getByRole('button', { name: 'Convert to PO' })).toBeInTheDocument();
  });

  it('removes a selected row from bulk approval when the server action changes', async () => {
    const actionable = request({ available_actions: { ...requestActions(false), can_approve: true } });
    vi.mocked(purchaseRequestsApi.list).mockResolvedValue(page([actionable]));
    const { queryClient } = renderPage();

    const row = await screen.findByRole('row', { name: /PR-202611-0001/ });
    fireEvent.click(within(row).getByRole('checkbox', { name: 'Select row' }));
    expect(await screen.findByRole('button', { name: 'Approve selected' })).toBeInTheDocument();

    const query = queryClient.getQueryCache().find({
      queryKey: ['purchasing', 'purchase-requests'],
      exact: false,
    });
    expect(query).toBeDefined();
    act(() => {
      queryClient.setQueryData(query!.queryKey, page([
        { ...actionable, available_actions: { ...requestActions(false), can_approve: false } },
      ]));
    });

    await waitFor(() => expect(screen.queryByRole('button', { name: 'Approve selected' })).not.toBeInTheDocument());
    expect(within(row).getByRole('checkbox', { name: 'Select row' })).toBeDisabled();
  });
});
