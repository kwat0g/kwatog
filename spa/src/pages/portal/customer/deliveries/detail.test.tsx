import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import CustomerDeliveryDetailPage from './detail';
import { customerPortalApi } from '@/api/b2b/customer';
import type { PortalDeliveryDetail, PortalReceiptGroup } from '@/types/b2b';

/**
 * O2C audit 2026-09-25 — "Confirm Receipt" showed on every delivered shipment
 * and answered 422 until the driver's proof existed, which the customer cannot
 * upload. The page now follows the server's `can_confirm` flag and explains
 * the wait instead.
 */
vi.mock('@/api/b2b/customer', () => ({
  customerPortalApi: { getDelivery: vi.fn(), confirmDelivery: vi.fn(), viewDeliveryProof: vi.fn(), getReceiptGroup: vi.fn().mockResolvedValue(null), confirmReceiptGroup: vi.fn() },
}));
vi.mock('react-router-dom', async (importOriginal) => ({
  ...(await importOriginal<typeof import('react-router-dom')>()),
  useParams: () => ({ id: 'dr-1' }),
}));

const delivered = {
  id: 'dr-1',
  delivery_number: 'DR-202609-0001',
  delivered_at: '2026-09-25T08:00:00Z',
  status: 'delivered',
  status_label: 'Delivered',
  scheduled_date: '2026-09-25',
  sales_order: { id: 'so-1', so_number: 'SO-202609-0001' },
  items: [],
  proofs: [],
  receiver_name: null,
  confirmed_at: null,
  driver: null,
} as PortalDeliveryDetail;

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <CustomerDeliveryDetailPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
  return client;
}

const confirmable: PortalDeliveryDetail = {
  ...delivered, can_confirm: true,
  items: [
    { id: 'line-2', part_number: 'PC-001', name: 'Pivot Cap Cover Type A', quantity_delivered: '2.000', unit_of_measure: 'pcs' },
    { id: 'line-498', part_number: 'PC-001', name: 'Pivot Cap Cover Type A', quantity_delivered: '498.000', unit_of_measure: 'pcs' },
  ],
};

async function openReceipt() {
  fireEvent.click(await screen.findByRole('button', { name: 'Confirm Receipt' }));
  return within(screen.getByRole('dialog', { name: 'Confirm Receipt' }));
}

describe('CustomerDeliveryDetailPage', () => {
  it('shows the delivered products and quantities from the portal response', async () => {
    vi.mocked(customerPortalApi.getDelivery).mockResolvedValue({
      ...delivered, can_confirm: false,
      items: [{ id: 'item-1', part_number: 'WB-001', name: 'Standard Wiper Bushing', quantity_dispatched: '100', quantity_delivered: '99' }],
    });
    renderPage();
    expect(await screen.findByRole('row', { name: /WB-001 Standard Wiper Bushing 99/ })).toBeInTheDocument();
  });

  it('hides Confirm Receipt and explains the wait until a proof of delivery exists', async () => {
    vi.mocked(customerPortalApi.getDelivery).mockResolvedValue({ ...delivered, can_confirm: false });

    renderPage();

    expect(await screen.findByText(/Awaiting our driver's proof of delivery/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Confirm Receipt/ })).not.toBeInTheDocument();
  });

  it('offers Confirm Receipt once the server says the shipment can be confirmed', async () => {
    vi.mocked(customerPortalApi.getDelivery).mockResolvedValue({ ...delivered, can_confirm: true });

    renderPage();

    expect(await screen.findByRole('button', { name: /Confirm Receipt/ })).toBeInTheDocument();
    expect(screen.queryByText(/Awaiting our driver's proof of delivery/)).not.toBeInTheDocument();
  });
  it('links to an open problem report instead of asking for another proof', async () => {
    vi.mocked(customerPortalApi.getDelivery).mockResolvedValue({
      ...delivered, can_confirm: false,
      billing_hold: { case_id: 'case-1', case_number: 'CASE-1', message: 'Awaiting resolution.' },
    });
    renderPage();
    expect(await screen.findByRole('link', { name: 'CASE-1' })).toHaveAttribute('href', '/portal/customer/problems/case-1');
    expect(screen.queryByRole('button', { name: /Confirm Receipt/ })).not.toBeInTheDocument();
    expect(screen.queryByText(/Awaiting our driver's proof of delivery/)).not.toBeInTheDocument();
  });

  it('opens a modal with exact shipment lines and closes without confirming', async () => {
    vi.mocked(customerPortalApi.getDelivery).mockResolvedValue(confirmable);
    renderPage();
    const modal = await openReceipt();
    expect(modal.getByLabelText(/Received qty · line 1/)).toHaveValue('2');
    expect(modal.getByLabelText(/Received qty · line 2/)).toHaveValue('498');
    expect(modal.getByText('Shipment line 2 · Expected 498 pcs')).toBeInTheDocument();
    expect(modal.getByLabelText(/Received by/)).toHaveAttribute('maxlength', '100');
    expect(modal.getByRole('button', { name: 'Confirm receipt' })).toBeDisabled();
    fireEvent.click(modal.getByRole('button', { name: 'Cancel' }));
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });

  it.each([
    ['', 'Enter the received quantity.'],
    ['-1', 'Enter a non-negative number with up to 2 decimal places.'],
    ['1e3', 'Enter a non-negative number with up to 2 decimal places.'],
    ['0.0001', 'Enter a non-negative number with up to 2 decimal places.'],
    ['2.1', 'Enter a whole number of pieces.'],
    ['3', 'Maximum 2 pcs.'],
  ])('validates received quantity %s immediately', async (value, message) => {
    vi.mocked(customerPortalApi.getDelivery).mockResolvedValue(confirmable);
    renderPage();
    const modal = await openReceipt();
    fireEvent.change(modal.getByLabelText(/Received by/), { target: { value: 'Toyota receiver' } });
    fireEvent.change(modal.getByLabelText(/Received qty · line 1/), { target: { value } });
    expect(modal.getByText(message)).toHaveClass('text-danger-fg');
    expect(modal.getByLabelText(/Received qty · line 1/)).toHaveAttribute('aria-invalid', 'true');
    expect(modal.getByRole('button', { name: 'Confirm receipt' })).toBeDisabled();
  });

  it('directs a shortage to problem reporting instead of confirming the invoice quantity', async () => {
    vi.mocked(customerPortalApi.getDelivery).mockResolvedValue(confirmable);
    const spy = vi.mocked(customerPortalApi.confirmDelivery).mockClear();
    renderPage();
    const modal = await openReceipt();
    fireEvent.change(modal.getByLabelText(/Received qty · line 2/), { target: { value: '497' } });
    expect(modal.getByText('Quantity is short. Report a problem before confirming.')).toHaveClass('text-danger-fg');
    expect(modal.getByRole('button', { name: 'Report a problem' })).toBeInTheDocument();
    fireEvent.submit(modal.getByRole('button', { name: 'Confirm receipt' }).closest('form')!);
    expect(spy).not.toHaveBeenCalled();
  });

  it('submits each line once, prevents duplicate submission and refreshes related portal data', async () => {
    vi.mocked(customerPortalApi.getDelivery).mockResolvedValue(confirmable);
    let resolve!: (value: { data: PortalDeliveryDetail; message: string }) => void;
    const spy = vi.mocked(customerPortalApi.confirmDelivery).mockClear().mockImplementation(() => new Promise((done) => { resolve = done; }));
    const client = renderPage();
    client.setQueryData(['portal', 'customer', 'orders'], []);
    client.setQueryData(['portal', 'customer', 'order-chain', 'so-1'], {});
    client.setQueryData(['portal', 'customer', 'invoices'], []);
    const modal = await openReceipt();
    fireEvent.change(modal.getByLabelText(/Received by/), { target: { value: '  Juan Dela Cruz  ' } });
    const form = modal.getByRole('button', { name: 'Confirm receipt' }).closest('form')!;
    fireEvent.submit(form);
    fireEvent.submit(form);
    await waitFor(() => expect(spy).toHaveBeenCalledTimes(1));
    expect(spy).toHaveBeenCalledWith('dr-1', {
      receiver_name: 'Juan Dela Cruz', receiver_position: undefined, delivery_remarks: undefined,
      lines: [{ delivery_item_id: 'line-2', received_quantity: '2' }, { delivery_item_id: 'line-498', received_quantity: '498' }],
    });
    fireEvent.click(modal.getByRole('button', { name: 'Close modal' }));
    fireEvent.keyDown(document, { key: 'Escape' });
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    vi.mocked(customerPortalApi.getDelivery).mockResolvedValue({ ...confirmable, status: 'confirmed', can_confirm: false });
    resolve({ data: { ...confirmable, status: 'confirmed', can_confirm: false }, message: 'Confirmed.' });
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(client.getQueryState(['portal', 'customer', 'orders'])?.isInvalidated).toBe(true);
    expect(client.getQueryState(['portal', 'customer', 'order-chain', 'so-1'])?.isInvalidated).toBe(true);
    expect(client.getQueryState(['portal', 'customer', 'invoices'])?.isInvalidated).toBe(true);
  });

  it('keeps entries and displays server field errors when confirmation fails', async () => {
    vi.mocked(customerPortalApi.getDelivery).mockResolvedValue(confirmable);
    vi.mocked(customerPortalApi.confirmDelivery).mockRejectedValue({ response: { data: {
      message: 'Check receipt quantities.', errors: { 'lines.1.received_quantity': ['Delivery quantity changed. Refresh and check again.'] },
    } } });
    renderPage();
    const modal = await openReceipt();
    fireEvent.change(modal.getByLabelText(/Received by/), { target: { value: 'Juan' } });
    fireEvent.click(modal.getByRole('button', { name: 'Confirm receipt' }));
    expect(await modal.findByText('Delivery quantity changed. Refresh and check again.')).toHaveClass('text-danger-fg');
    expect(modal.getByLabelText(/Received by/)).toHaveValue('Juan');
    expect(modal.getByLabelText(/Received qty · line 2/)).toHaveValue('498');
  });

  it('disables a modal left open when the server puts confirmation on hold', async () => {
    vi.mocked(customerPortalApi.getDelivery).mockResolvedValue(confirmable);
    const client = renderPage();
    const modal = await openReceipt();
    fireEvent.change(modal.getByLabelText(/Received by/), { target: { value: 'Juan' } });
    client.setQueryData(['portal', 'customer', 'delivery', 'dr-1'], { ...confirmable, can_confirm: false });
    expect(await modal.findByText('Receipt confirmation is on hold. Check the updated delivery record.')).toHaveClass('text-danger-fg');
    expect(modal.getByRole('button', { name: 'Confirm receipt' })).toBeDisabled();
  });

  it('accepts supported decimal units without rounding', async () => {
    vi.mocked(customerPortalApi.getDelivery).mockResolvedValue({ ...confirmable, items: [
      { ...confirmable.items[0], quantity_delivered: '0.12', unit_of_measure: 'kg' },
    ] });
    renderPage();
    const modal = await openReceipt();
    fireEvent.change(modal.getByLabelText(/Received by/), { target: { value: 'Juan' } });
    expect(modal.getByLabelText(/Received qty · line 1/)).toHaveValue('0.12');
    expect(modal.getByRole('button', { name: 'Confirm receipt' })).toBeEnabled();
  });

});

const receiptGroup: PortalReceiptGroup = {
  case: { id: 'case-1', case_number: 'CASE-1', status: 'in_progress' },
  version: 'a'.repeat(64), can_confirm: true, blocked_reason: null,
  shipments: [
    { kind: 'original', already_confirmed: false, delivery: { ...confirmable, can_confirm: false } },
    { kind: 'replacement', already_confirmed: false, delivery: {
      ...confirmable, id: 'dr-2', delivery_number: 'DR-2', sales_order: { id: 'so-2', so_number: 'SO-2' },
      items: [{ id: 'replacement-2', part_number: 'PC-001', name: 'Pivot Cap Cover Type A', quantity_delivered: '2.000', unit_of_measure: 'pcs' }],
    } },
  ],
};

describe('combined shipment receipts', () => {
  it('reviews separate shipments and submits receiver details once', async () => {
    vi.mocked(customerPortalApi.getDelivery).mockResolvedValue(receiptGroup.shipments[0].delivery);
    vi.mocked(customerPortalApi.getReceiptGroup).mockResolvedValue(receiptGroup);
    const submit = vi.mocked(customerPortalApi.confirmReceiptGroup).mockClear().mockResolvedValue({ data: { ...receiptGroup, can_confirm: false }, message: 'Confirmed.' });
    const single = vi.mocked(customerPortalApi.confirmDelivery).mockClear();
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Confirm received shipments' }));
    const modal = within(screen.getByRole('dialog', { name: 'Confirm received shipments' }));
    expect(modal.getByText('Replacement shipment · No charge')).toBeInTheDocument();
    expect(screen.queryByText('502 pcs')).not.toBeInTheDocument();
    fireEvent.change(modal.getByLabelText(/DR-2 · Received qty · line 1/), { target: { value: '3' } });
    expect(modal.getByText('Maximum 2 pcs.')).toHaveClass('text-danger-fg');
    expect(modal.getByRole('button', { name: 'Confirm received shipments' })).toBeDisabled();
    fireEvent.change(modal.getByLabelText(/DR-2 · Received qty · line 1/), { target: { value: '2' } });
    fireEvent.change(modal.getByLabelText(/Received by/), { target: { value: ' Toyota receiver ' } });
    fireEvent.click(modal.getByRole('button', { name: 'Confirm received shipments' }));
    await waitFor(() => expect(submit).toHaveBeenCalledTimes(1));
    expect(submit).toHaveBeenCalledWith('dr-1', {
      version: 'a'.repeat(64), receiver_name: 'Toyota receiver', receiver_position: undefined, delivery_remarks: undefined,
      shipments: [
        { delivery_id: 'dr-1', lines: [{ delivery_item_id: 'line-2', received_quantity: '2' }, { delivery_item_id: 'line-498', received_quantity: '498' }] },
        { delivery_id: 'dr-2', lines: [{ delivery_item_id: 'replacement-2', received_quantity: '2' }] },
      ],
    });
    expect(single).not.toHaveBeenCalled();
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    vi.mocked(customerPortalApi.getReceiptGroup).mockResolvedValue(null);
  });

  it('keeps confirmed original read-only without overwriting its receiver', async () => {
    const confirmed = { ...receiptGroup, shipments: receiptGroup.shipments.map((shipment, index) => index === 0
      ? { ...shipment, already_confirmed: true, delivery: { ...shipment.delivery, status: 'confirmed', receiver_name: 'Earlier receiver' } } : shipment) };
    vi.mocked(customerPortalApi.getDelivery).mockResolvedValue(confirmed.shipments[0].delivery);
    vi.mocked(customerPortalApi.getReceiptGroup).mockResolvedValue(confirmed);
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Confirm received shipments' }));
    const modal = within(screen.getByRole('dialog', { name: 'Confirm received shipments' }));
    expect(modal.getByText('Receipt already confirmed')).toBeInTheDocument();
    expect(modal.getByLabelText(/DR-202609-0001 · Received qty · line 1/)).toHaveAttribute('readonly');
    expect(modal.getByText(/Earlier receiver/)).toBeInTheDocument();
    fireEvent.click(modal.getByRole('button', { name: 'Cancel' }));
    vi.mocked(customerPortalApi.getReceiptGroup).mockResolvedValue(null);
  });
});

it('blocked grouped receipt preserves valid single confirmation', async () => {
  vi.mocked(customerPortalApi.getDelivery).mockResolvedValue(confirmable);
  vi.mocked(customerPortalApi.getReceiptGroup).mockResolvedValue({ ...receiptGroup, can_confirm: false, blocked_reason: 'Another problem report must be resolved.' });
  renderPage();
  expect(await screen.findByRole('button', { name: 'Confirm Receipt' })).toBeInTheDocument();
  expect(await screen.findByText(/Combined receipt: Another problem/)).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Confirm received shipments' })).not.toBeInTheDocument();
  vi.mocked(customerPortalApi.getReceiptGroup).mockResolvedValue(null);
});

it('stale grouped receipt preserves inputs and requires explicit refreshed review', async () => {
  vi.mocked(customerPortalApi.getDelivery).mockResolvedValue(receiptGroup.shipments[0].delivery);
  vi.mocked(customerPortalApi.getReceiptGroup).mockResolvedValue(receiptGroup);
  const submit = vi.mocked(customerPortalApi.confirmReceiptGroup).mockClear().mockRejectedValue({ response: { data: { errors: { version: ['Shipment details changed.'] } } } });
  renderPage();
  fireEvent.click(await screen.findByRole('button', { name: 'Confirm received shipments' }));
  const modal = within(screen.getByRole('dialog', { name: 'Confirm received shipments' }));
  fireEvent.change(modal.getByLabelText(/Received by/), { target: { value: 'Toyota receiver' } });
  fireEvent.click(modal.getByRole('button', { name: 'Confirm received shipments' }));
  expect(await modal.findByText('Shipment details changed.')).toHaveClass('text-danger-fg');
  expect(modal.getByRole('button', { name: 'Confirm received shipments' })).toBeDisabled();
  expect(modal.getByLabelText(/Received by/)).toHaveValue('Toyota receiver');
  vi.mocked(customerPortalApi.getReceiptGroup).mockResolvedValue({ ...receiptGroup, version: 'b'.repeat(64) });
  fireEvent.click(modal.getByRole('button', { name: 'Review refreshed shipments' }));
  await waitFor(() => expect(modal.getByRole('button', { name: 'Confirm received shipments' })).toBeEnabled());
  expect(submit).toHaveBeenCalledTimes(1);
  fireEvent.click(modal.getByRole('button', { name: 'Cancel' }));
  vi.mocked(customerPortalApi.getReceiptGroup).mockResolvedValue(null);
});
