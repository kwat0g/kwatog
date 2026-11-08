import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { AxiosError } from 'axios';
import { expect, it, vi } from 'vitest';
import { manilaIsoDate } from '@/lib/formatDate';
import { returnManagementApi } from '@/api/returnManagement';
import { creditNotesApi } from '@/api/accounting/credit-notes';
import type { CreditNote } from '@/types/accounting';
import ReturnRequestDetailPage from './detail';

vi.mock('@/api/returnManagement', () => ({ returnManagementApi: { get: vi.fn(), splitItem: vi.fn(), submit: vi.fn(), approve: vi.fn(), options: vi.fn().mockResolvedValue({ reasons: [], resolutions: [], conditions: [], dispositions: [] }) } }));
vi.mock('@/api/accounting/credit-notes', () => ({ creditNotesApi: { finalize: vi.fn() } }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true }) }));
vi.mock('@/hooks/useRealtimeRecord', () => ({ useRealtimeRecord: vi.fn() }));
vi.mock('@/stores/authStore', () => ({ useAuthStore: (selector: (state: { user: { id: string; role: { id: string; slug: string }; permissions: string[]; features: string[] } }) => unknown) => selector({ user: { id: 'user-1', role: { id: 'role-1', slug: 'purchasing_officer' }, permissions: [], features: [] } }) }));
vi.mock('react-router-dom', async original => ({ ...(await original<typeof import('react-router-dom')>()), useParams: () => ({ id: 'rma-1' }) }));

it('renders approval controls from server available_actions', async () => {
  vi.mocked(returnManagementApi.get).mockResolvedValue({
    id: 'rma-1', rma_number: 'RMA-1', type: 'customer_return', type_label: 'Customer return',
    status: 'pending_approval', status_label: 'Pending approval', is_editable: false,
    item_count: 0, created_at: '', updated_at: '', items: [],
    actions: { can_approve: false, can_reject: false },
    available_actions: { can_approve: true, can_reject: true, can_cancel: true },
  });
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter><ReturnRequestDetailPage /></MemoryRouter></QueryClientProvider>);

  expect(await screen.findByRole('button', { name: 'Approve' })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Reject' })).toBeInTheDocument();
  client.clear();
});

it('refreshes the RMA and clears a stale approval confirmation after a 409', async () => {
  const pending = {
    id: 'rma-1', rma_number: 'RMA-1', type: 'customer_return' as const, type_label: 'Customer return',
    status: 'pending_approval', status_label: 'Pending approval', is_editable: false,
    item_count: 0, created_at: '', updated_at: '', items: [],
    available_actions: { can_approve: true, can_reject: true, can_cancel: false },
  };
  const approved = {
    ...pending, status: 'approved', status_label: 'Approved',
    available_actions: { can_approve: false, can_reject: false, can_cancel: false },
  };
  let reads = 0;
  vi.mocked(returnManagementApi.get).mockImplementation(() => Promise.resolve(++reads === 1 ? pending : approved));
  vi.mocked(returnManagementApi.approve).mockRejectedValue(new AxiosError('This RMA changed.', 'ERR_BAD_REQUEST', undefined, undefined, {
    data: { message: 'This RMA changed.', code: 'return_request_state_conflict' },
    status: 409, statusText: 'Conflict', headers: {}, config: {} as never,
  }));
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter><ReturnRequestDetailPage /></MemoryRouter></QueryClientProvider>);

  fireEvent.click(await screen.findByRole('button', { name: 'Approve' }));
  fireEvent.click(screen.getAllByRole('button', { name: 'Approve' }).at(-1)!);
  await waitFor(() => expect(returnManagementApi.approve).toHaveBeenCalled());
  await waitFor(() => expect(reads).toBeGreaterThan(1));
  expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
  client.clear();
});

it('hides Cancel when the server reports physical receipt effects', async () => {
  vi.mocked(returnManagementApi.get).mockResolvedValue({
    id: 'rma-1', rma_number: 'RMA-1', type: 'customer_return', type_label: 'Customer return',
    status: 'approved', status_label: 'Approved', is_editable: false, received_at: '2026-11-05T00:00:00Z',
    item_count: 0, created_at: '', updated_at: '', items: [],
    available_actions: { can_approve: false, can_reject: false, can_cancel: false },
  });
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter><ReturnRequestDetailPage /></MemoryRouter></QueryClientProvider>);

  expect(await screen.findAllByText('Approved')).toHaveLength(2);
  expect(screen.queryByRole('button', { name: 'Cancel' })).not.toBeInTheDocument();
  client.clear();
});

it('renders workflow controls from server-projected actions instead of local permission checks', async () => {
  vi.mocked(returnManagementApi.get).mockResolvedValue({
    id: 'rma-1', rma_number: 'RMA-1', type: 'customer_return', type_label: 'Customer return',
    status: 'approved', status_label: 'Approved', is_editable: false,
    item_count: 0, created_at: '', updated_at: '', items: [],
    available_actions: {
      can_approve: false, can_reject: false, can_cancel: false,
      can_submit: false, can_edit: false, can_receive: false,
      can_inspect: false, can_retry_inspection_handoff: false,
      can_dispose: false, can_complete: false,
    },
  });
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter><ReturnRequestDetailPage /></MemoryRouter></QueryClientProvider>);

  expect(await screen.findAllByText('Approved')).toHaveLength(2);
  expect(screen.queryByRole('button', { name: 'Record Receipt' })).not.toBeInTheDocument();
  client.clear();
});

it('splits whole received pieces using the exact return line', async () => {
  const record = { id: 'rma-1', rma_number: 'RMA-1', type: 'customer_return' as const, type_label: 'Customer return', status: 'received', status_label: 'Received', is_editable: false, item_count: 1, created_at: '', updated_at: '', available_actions: { can_approve: false, can_reject: false, can_cancel: false, can_split: true }, items: [{ id: 'line-1', item_id: 'item-1', quantity: '100', returned_quantity: '100', received_total: '100', unit_price: '10', total: '1000', available_actions: { can_split: true }, item: { id: 'item-1', code: 'PART-1', name: 'Part', unit_of_measure: 'PCS' } }] };
  vi.mocked(returnManagementApi.get).mockResolvedValue(record);
  vi.mocked(returnManagementApi.splitItem).mockResolvedValue(record);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter><ReturnRequestDetailPage /></MemoryRouter></QueryClientProvider>);
  fireEvent.click(await screen.findByRole('button', { name: 'Split quantity' }));
  const quantity = screen.getByRole('spinbutton', { name: 'Quantity for the new line' });
  fireEvent.change(quantity, { target: { value: '1.5' } });
  expect(screen.getByRole('button', { name: 'Split line' })).toBeDisabled();
  fireEvent.change(quantity, { target: { value: '1' } });
  fireEvent.click(screen.getByRole('button', { name: 'Split line' }));
  await waitFor(() => expect(returnManagementApi.splitItem).toHaveBeenCalledWith('rma-1', 'line-1', '1'));
  client.clear();
});

it('renders the split control from the line server action projection', async () => {
  vi.mocked(returnManagementApi.get).mockResolvedValue({
    id: 'rma-1', rma_number: 'RMA-1', type: 'customer_return', type_label: 'Customer return',
    status: 'received', status_label: 'Received', is_editable: false, item_count: 1,
    created_at: '', updated_at: '',
    available_actions: { can_approve: false, can_reject: false, can_cancel: false, can_split: true },
    items: [{
      id: 'line-1', item_id: 'item-1', quantity: '100', returned_quantity: '100', received_total: '100',
      unit_price: '10', total: '1000', available_actions: { can_split: false },
      item: { id: 'item-1', code: 'PART-1', name: 'Part', unit_of_measure: 'PCS' },
    }],
  });
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter><ReturnRequestDetailPage /></MemoryRouter></QueryClientProvider>);

  await waitFor(() => expect(returnManagementApi.get).toHaveBeenCalled());
  expect(screen.queryByRole('button', { name: 'Split quantity' })).not.toBeInTheDocument();
  client.clear();
});

it('disables an open split when the latest received total no longer supports it', async () => {
  const record = {
    id: 'rma-1', rma_number: 'RMA-1', type: 'customer_return' as const,
    type_label: 'Customer return', status: 'received', status_label: 'Received',
    disposition_status: 'pending', is_editable: false, item_count: 1,
    available_actions: { can_approve: false, can_reject: false, can_cancel: false, can_split: true },
    created_at: '', updated_at: '',
    items: [{ id: 'line-1', item_id: 'item-1', quantity: '100', returned_quantity: '100', received_total: '100', unit_price: '10', total: '1000', available_actions: { can_split: true }, item: { id: 'item-1', code: 'PART-1', name: 'Part', unit_of_measure: 'KG' } }],
  };
  vi.mocked(returnManagementApi.get).mockResolvedValue(record);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter><ReturnRequestDetailPage /></MemoryRouter></QueryClientProvider>);
  fireEvent.click(await screen.findByRole('button', { name: 'Split quantity' }));
  const quantity = screen.getByRole('spinbutton', { name: 'Quantity for the new line' });
  fireEvent.change(quantity, { target: { value: '2' } });
  expect(screen.getByRole('button', { name: 'Split line' })).toBeEnabled();

  await act(async () => {
    client.setQueryData(['return-request', 'rma-1'], {
      ...record,
      items: [{ ...record.items[0], received_total: '1', returned_quantity: '1' }],
    });
  });

  await waitFor(() => expect(screen.getByRole('button', { name: 'Split line' })).toBeDisabled());
  expect(quantity).toHaveValue(2);
  expect(screen.getByText(/current received quantity no longer supports this split/i)).toBeInTheDocument();
  client.clear();
});

it('requires the delivery date when Purchasing sends the QC return', async () => {
  const record = { id: 'rma-1', rma_number: 'RMA-1', type: 'supplier_return' as const, type_label: 'Supplier return', status: 'draft', status_label: 'Draft', is_editable: false, requires_defect_evidence: true, reason_description: 'QC-1: Two torn bags.', item_count: 1, created_at: '', updated_at: '', available_actions: { can_approve: false, can_reject: false, can_cancel: false, can_submit: true }, items: [{ id: 'line-1', item_id: 'item-1', quantity: '2', returned_quantity: '2', receipt_recorded: true, reused_incoming_inspection_id: 'qc-1', moved_quantity: '2', unit_price: '0.50', total: '1.00' }] };
  let getCount = 0;
  vi.mocked(returnManagementApi.get).mockImplementation(() => {
    getCount += 1;
    return getCount === 1 ? Promise.resolve(record) : new Promise(() => {});
  });
  vi.mocked(returnManagementApi.submit).mockResolvedValue({ ...record, status: 'completed', status_label: 'Completed' });
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter><ReturnRequestDetailPage /></MemoryRouter></QueryClientProvider>);
  fireEvent.click(await screen.findByRole('button', { name: 'Send to supplier' }));
  expect(screen.getByLabelText(/Required delivery date/)).toHaveAttribute('min', manilaIsoDate());
  const send = screen.getAllByRole('button', { name: 'Send to supplier' }).at(-1)!;
  expect(send).toBeDisabled();
  fireEvent.change(screen.getByLabelText(/Photo of defective items/), { target: { files: [new File(['photo'], 'defect.png', { type: 'image/png' })] } });
  fireEvent.change(screen.getByLabelText(/Required delivery date/), { target: { value: '2099-01-01' } });
  expect(send).toBeEnabled();
  fireEvent.submit(send.closest('form')!);
  await waitFor(() => expect(returnManagementApi.submit).toHaveBeenCalledWith('rma-1', expect.any(FormData)));
  await waitFor(() => expect(client.getQueryData<{ status: string }>(['return-request', 'rma-1'])?.status).toBe('completed'));
  expect(vi.mocked(returnManagementApi.submit).mock.calls[0][1]?.get('required_delivery_date')).toBe('2099-01-01');
  expect(screen.queryByRole('button', { name: 'Record Receipt' })).not.toBeInTheDocument();
  client.clear();
});

it('does not let a pre-mutation detail read replace the returned RMA state', async () => {
  const record = { id: 'rma-1', rma_number: 'RMA-1', type: 'supplier_return' as const, type_label: 'Supplier return', status: 'draft', status_label: 'Draft', is_editable: false, requires_defect_evidence: true, reason_description: 'QC-1: Two torn bags.', item_count: 1, created_at: '', updated_at: '', available_actions: { can_approve: false, can_reject: false, can_cancel: false, can_submit: true }, items: [{ id: 'line-1', item_id: 'item-1', quantity: '2', returned_quantity: '2', receipt_recorded: true, reused_incoming_inspection_id: 'qc-1', moved_quantity: '2', unit_price: '0.50', total: '1.00' }] };
  const updated = { ...record, status: 'completed', status_label: 'Completed' };
  let getCount = 0;
  vi.mocked(returnManagementApi.get).mockImplementation(() => {
    getCount += 1;
    if (getCount === 1) return Promise.resolve(record);
    return new Promise(() => {});
  });
  vi.mocked(returnManagementApi.submit).mockResolvedValue(updated);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter><ReturnRequestDetailPage /></MemoryRouter></QueryClientProvider>);
  fireEvent.click(await screen.findByRole('button', { name: 'Send to supplier' }));

  void client.refetchQueries({ queryKey: ['return-request', 'rma-1'] });
  await waitFor(() => expect(getCount).toBe(2));
  fireEvent.change(screen.getByLabelText(/Photo of defective items/), { target: { files: [new File(['photo'], 'defect.png', { type: 'image/png' })] } });
  fireEvent.change(screen.getByLabelText(/Required delivery date/), { target: { value: '2099-01-01' } });
  fireEvent.submit(screen.getAllByRole('button', { name: 'Send to supplier' }).at(-1)!.closest('form')!);
  await waitFor(() => expect(returnManagementApi.submit).toHaveBeenCalled());
  await waitFor(() => expect(getCount).toBeGreaterThanOrEqual(3));

  expect(client.getQueryData<{ status: string }>(['return-request', 'rma-1'])?.status).toBe('completed');
  expect(screen.queryByRole('button', { name: 'Record Receipt' })).not.toBeInTheDocument();
  client.clear();
});

it('updates the open RMA when its linked credit note is finalized', async () => {
  const record = {
    id: 'rma-1', rma_number: 'RMA-1', type: 'customer_return' as const, type_label: 'Customer return',
    status: 'completed', status_label: 'Completed', is_editable: false, item_count: 0,
    created_at: '', updated_at: '', items: [],
    credit_note: { id: 'cn-1', credit_note_number: null, type: 'customer_return', status: 'draft', total_amount: '50.00' },
  };
  let getCount = 0;
  vi.mocked(returnManagementApi.get).mockImplementation(() => {
    getCount += 1;
    if (getCount === 1) return Promise.resolve(record);
    return new Promise(() => {});
  });
  vi.mocked(creditNotesApi.finalize).mockResolvedValue({
    id: 'cn-1', credit_note_number: 'CN-2026-0001', type: 'customer_return', status: 'finalized', total_amount: '50.00',
  } as unknown as CreditNote);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter><ReturnRequestDetailPage /></MemoryRouter></QueryClientProvider>);
  fireEvent.click(await screen.findByRole('button', { name: 'Finalize' }));

  void client.refetchQueries({ queryKey: ['return-request', 'rma-1'] });
  await waitFor(() => expect(getCount).toBe(2));
  fireEvent.click(screen.getByRole('button', { name: 'Finalize credit note' }));

  await waitFor(() => expect(screen.queryByRole('button', { name: 'Finalize' })).not.toBeInTheDocument());
  expect(client.getQueryData<{ credit_note?: { status: string } }>(['return-request', 'rma-1'])?.credit_note?.status)
    .toBe('finalized');
  client.clear();
});
