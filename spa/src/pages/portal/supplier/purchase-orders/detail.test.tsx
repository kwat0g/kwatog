import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import { supplierPortalApi } from '@/api/b2b/supplier';
import { formatPeso } from '@/lib/formatNumber';
import SupplierPurchaseOrderDetailPage from './detail';
import type { PortalPoDetail } from '@/types/b2b';

vi.mock('react-router-dom', async (importOriginal) => ({
  ...(await importOriginal<typeof import('react-router-dom')>()),
  useParams: () => ({ id: 'po-1' }),
}));
vi.mock('@/api/b2b/supplier', () => ({ supplierPortalApi: {
  getPo: vi.fn(), listShippingDocuments: vi.fn(), uploadShippingDocument: vi.fn(), respondToPurchaseOrder: vi.fn(),
  createShipment: vi.fn(), updateShipmentById: vi.fn(), submitInvoice: vi.fn(),
} }));
afterEach(() => { cleanup(); vi.clearAllMocks(); vi.useRealTimers(); });

it('opens invoice entry in a modal and submits the selected receipt', async () => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date(2026, 9, 9, 0, 30));
  const po: PortalPoDetail = {
    id: 'po-1', po_number: 'PO-1', date: '2026-10-08', total_amount: '11200.00', status: 'received',
    expected_delivery_date: '2026-10-13', confirmed_delivery_date: '2026-10-13', sent_to_supplier_at: '2026-10-08', incoterm: null,
    latest_response: null, shipments: [], items: [], bills: [], invoiceable_bill_portions: [{
      id: 'bill-1', grn_id: 'grn-1', grn_number: 'GRN-1', quantity: '20000.00', gross_total: '11200.00',
    }], goods_receipt_notes: [{
      id: 'grn-1', grn_number: 'GRN-1', received_date: '2026-10-09', status: 'accepted',
      status_label: 'Accepted', supplier_invoice_number: null, can_invoice: true,
    }],
    capabilities: { can_acknowledge: false, can_accept: false, can_propose: false, can_decline: false,
      can_respond: false, can_update_shipment: false, can_upload_document: false, can_submit_invoice: true, can_schedule_delivery: false },
  };
  vi.mocked(supplierPortalApi.getPo).mockResolvedValue(po);
  vi.mocked(supplierPortalApi.listShippingDocuments).mockResolvedValue([]);
  vi.mocked(supplierPortalApi.submitInvoice).mockResolvedValue({ message: 'Invoice submitted.' } as Awaited<ReturnType<typeof supplierPortalApi.submitInvoice>>);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter><SupplierPurchaseOrderDetailPage /></MemoryRouter></QueryClientProvider>);

  const open = await screen.findByRole('button', { name: 'Submit invoice' });
  expect(screen.queryByLabelText('Your invoice #')).not.toBeInTheDocument();
  fireEvent.click(open);
  expect(screen.getByLabelText(/Invoice date/)).toHaveValue('2026-10-09');
  expect(screen.getByLabelText(/Invoice date/)).toHaveAttribute('readonly');
  fireEvent.click(within(screen.getByRole('dialog', { name: 'Submit invoice' })).getByRole('button', { name: 'Cancel' }));
  vi.setSystemTime(new Date(2026, 9, 10, 0, 30));
  fireEvent.click(screen.getByRole('button', { name: 'Submit invoice' }));
  const modal = screen.getByRole('dialog', { name: 'Submit invoice' });
  expect(within(modal).getByLabelText(/Invoice date/)).toHaveValue('2026-10-10');
  expect(within(modal).getByText('GRN-1')).toBeInTheDocument();
  expect(within(modal).queryByRole('checkbox')).not.toBeInTheDocument();
  const total = within(modal).getByLabelText('Invoiceable total (before withholding tax)');
  expect(total).toHaveAttribute('readonly');
  expect(total).toHaveValue(formatPeso('11200.00'));
  fireEvent.change(within(modal).getByLabelText(/Your invoice #/), { target: { value: 'TEST-INV-001' } });
  fireEvent.submit(modal.querySelector('form')!);

  await waitFor(() => expect(supplierPortalApi.submitInvoice).toHaveBeenCalledTimes(1));
  const [poId, form] = vi.mocked(supplierPortalApi.submitInvoice).mock.calls[0];
  expect(poId).toBe('po-1');
  expect(form.get('bill_number')).toBe('TEST-INV-001');
  expect(form.get('date')).toBe('2026-10-10');
  expect(form.getAll('bill_ids[]')).toEqual(['bill-1']);
  expect(form.get('gross_total')).toBe('11200.00');
  await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Submit invoice' })).not.toBeInTheDocument());
  client.clear();
});

it('hides invoice entry until a linked replacement passes QC', async () => {
  const po: PortalPoDetail = {
    id: 'po-1', po_number: 'PO-1', date: '2026-10-09', total_amount: '112.00', status: 'partially_received',
    expected_delivery_date: '2026-10-13', confirmed_delivery_date: '2026-10-13', sent_to_supplier_at: '2026-10-09', incoterm: null,
    latest_response: null, bills: [], items: [{ id: 'line-1', part_number: 'PKG-001', name: 'Poly Bag', unit: 'pcs',
      quantity_ordered: '100.00', quantity_dispatch_limit: '102.00', quantity_received: '98.00', quantity_accepted: '98.00',
      quantity_remaining: '2.00', quantity_schedulable: '2.00', unit_price: '1.00', total_price: '100.00' }],
    shipments: [{ id: 'shipment-1', quantity_recorded: true, items: [{ purchase_order_item_id: 'line-1', quantity_shipped: '100.00' }],
      shipped_date: '2026-10-09', estimated_arrival: '2026-10-13', carrier: null, tracking_number: null, notes: null, updated_at: null }],
    goods_receipt_notes: [{ id: 'grn-1', grn_number: 'GRN-1', received_date: '2026-10-09', status: 'partial_accepted',
      status_label: 'Partially Accepted', supplier_invoice_number: null, can_invoice: false }],
    invoiceable_bill_portions: [{ id: 'bill-1', grn_id: 'grn-1', grn_number: 'GRN-1', quantity: '98.00', gross_total: '109.76' }],
    supplier_returns: [{ id: 'rma-1', rma_number: 'RMA-1', status: 'completed', status_label: 'Completed', problem: 'Torn bags',
      replacement_due_date: '2026-10-13', replacement_pending: true, defect_photo_name: null, items: [{ part_number: 'PKG-001', quantity: '2.00', unit: 'pcs' }] }],
    capabilities: { can_acknowledge: false, can_accept: false, can_propose: false, can_decline: false,
      can_respond: false, can_update_shipment: true, can_upload_document: true, can_submit_invoice: false, can_schedule_delivery: false },
  };
  vi.mocked(supplierPortalApi.getPo).mockResolvedValue(po);
  vi.mocked(supplierPortalApi.listShippingDocuments).mockResolvedValue([]);
  vi.mocked(supplierPortalApi.createShipment).mockResolvedValue({} as Awaited<ReturnType<typeof supplierPortalApi.createShipment>>);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter><SupplierPurchaseOrderDetailPage /></MemoryRouter></QueryClientProvider>);

  expect(await screen.findByText(/Submit invoice becomes available after the replacement pieces pass QC/)).toBeInTheDocument();
  expect(screen.getByText('Ship replacements against PO-1 by Oct 13, 2026.')).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Submit invoice' })).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Add shipment' }));
  expect(screen.getByLabelText('Shipment purpose')).toHaveValue('rma-1');
  fireEvent.change(screen.getByLabelText(/Estimated arrival/), { target: { value: '2026-10-13' } });
  fireEvent.change(screen.getByLabelText('Shipped quantity for PKG-001'), { target: { value: '2.00' } });
  fireEvent.change(screen.getByLabelText(/Photo of dispatched items/), { target: { files: [new File(['picture'], 'replacement.jpg', { type: 'image/jpeg' })] } });
  fireEvent.submit(screen.getByRole('dialog', { name: 'Add shipment' }).querySelector('form')!);
  await waitFor(() => expect(supplierPortalApi.createShipment).toHaveBeenCalledTimes(1));
  expect(vi.mocked(supplierPortalApi.createShipment).mock.calls[0][1].get('return_request_id')).toBe('rma-1');
  client.clear();
});

it('hides Edit for a received shipment while the replacement shipment remains editable', async () => {
  const po: PortalPoDetail = {
    id: 'po-1', po_number: 'PO-1', date: '2026-10-09', total_amount: '100.00', status: 'partially_received',
    expected_delivery_date: '2026-10-13', confirmed_delivery_date: '2026-10-13', sent_to_supplier_at: '2026-10-09', incoterm: null,
    latest_response: null, bills: [], goods_receipt_notes: [], items: [{
      id: 'line-1', part_number: 'PKG-001', name: 'Poly Bag', unit: 'pcs', quantity_ordered: '100.00',
      quantity_dispatch_limit: '100.00', quantity_received: '90.00', quantity_accepted: '90.00',
      quantity_remaining: '10.00', quantity_schedulable: '10.00', unit_price: '1.00', total_price: '100.00',
    }], shipments: [
      { id: 'received', can_edit: false, quantity_recorded: true, items: [{ purchase_order_item_id: 'line-1', quantity_shipped: '90.00' }],
        shipped_date: '2026-10-09', estimated_arrival: '2026-10-13', carrier: null, tracking_number: null, notes: null, updated_at: null },
      { id: 'replacement', can_edit: true, quantity_recorded: true, items: [{ purchase_order_item_id: 'line-1', quantity_shipped: '10.00' }],
        shipped_date: '2026-10-09', estimated_arrival: '2026-10-13', carrier: null, tracking_number: null, notes: null, updated_at: null },
    ], capabilities: { can_acknowledge: false, can_accept: false, can_propose: false, can_decline: false,
      can_respond: false, can_update_shipment: true, can_upload_document: false, can_submit_invoice: false, can_schedule_delivery: false },
  };
  vi.mocked(supplierPortalApi.getPo).mockResolvedValue(po);
  vi.mocked(supplierPortalApi.listShippingDocuments).mockResolvedValue([]);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter><SupplierPurchaseOrderDetailPage /></MemoryRouter></QueryClientProvider>);

  const receivedRow = (await screen.findByText('PKG-001: 90.00 pcs')).closest('tr')!;
  const replacementRow = screen.getByText('PKG-001: 10.00 pcs').closest('tr')!;
  expect(within(receivedRow).queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument();
  expect(within(replacementRow).getByRole('button', { name: 'Edit' })).toBeInTheDocument();
  fireEvent.click(within(replacementRow).getByRole('button', { name: 'Edit' }));
  const shippedDate = within(screen.getByRole('dialog', { name: 'Edit shipment' })).getByLabelText(/Shipped date/);
  expect(shippedDate).toHaveValue('2026-10-09');
  expect(shippedDate).toHaveAttribute('readonly');
  client.clear();
});

it('submits one supplier invoice for two selected GRN portions', async () => {
  const po: PortalPoDetail = {
    id: 'po-1', po_number: 'PO-1', date: '2026-10-08', total_amount: '11200.00', status: 'received',
    expected_delivery_date: '2026-10-13', confirmed_delivery_date: '2026-10-13', sent_to_supplier_at: '2026-10-08', incoterm: null,
    latest_response: null, shipments: [], items: [], bills: [], supplier_returns: [{
      id: 'rma-1', rma_number: 'RMA-1', status: 'completed', status_label: 'Completed', problem: 'Torn bags',
      replacement_due_date: '2026-10-13', replacement_pending: false, defect_photo_name: null,
      items: [{ part_number: 'PKG-001', quantity: '2.00', unit: 'pcs' }],
    }], invoiceable_bill_portions: [
      { id: 'bill-1', grn_id: 'grn-1', grn_number: 'GRN-1', quantity: '12000.00', gross_total: '6720.00' },
      { id: 'bill-2', grn_id: 'grn-2', grn_number: 'GRN-2', quantity: '8000.00', gross_total: '4480.00' },
    ], goods_receipt_notes: [
      { id: 'grn-1', grn_number: 'GRN-1', received_date: '2026-10-09', status: 'accepted', status_label: 'Accepted', supplier_invoice_number: null, can_invoice: true },
      { id: 'grn-2', grn_number: 'GRN-2', received_date: '2026-10-10', status: 'accepted', status_label: 'Accepted', supplier_invoice_number: null, can_invoice: true },
    ],
    capabilities: { can_acknowledge: false, can_accept: false, can_propose: false, can_decline: false,
      can_respond: false, can_update_shipment: false, can_upload_document: false, can_submit_invoice: true, can_schedule_delivery: false },
  };
  vi.mocked(supplierPortalApi.getPo).mockResolvedValue(po);
  vi.mocked(supplierPortalApi.listShippingDocuments).mockResolvedValue([]);
  vi.mocked(supplierPortalApi.submitInvoice).mockResolvedValue({ message: 'Invoice submitted.' } as Awaited<ReturnType<typeof supplierPortalApi.submitInvoice>>);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter><SupplierPurchaseOrderDetailPage /></MemoryRouter></QueryClientProvider>);

  fireEvent.click(await screen.findByRole('button', { name: 'Submit invoice' }));
  expect(screen.getByText('Replacement received and accepted.')).toBeInTheDocument();
  expect(screen.queryByText(/Ship replacements against/)).not.toBeInTheDocument();
  const modal = screen.getByRole('dialog', { name: 'Submit invoice' });
  expect(within(modal).getByRole('checkbox', { name: /GRN-1/ })).toBeChecked();
  fireEvent.click(within(modal).getByRole('checkbox', { name: /GRN-2/ }));
  const total = within(modal).getByLabelText('Invoiceable total (before withholding tax)');
  expect(total).toHaveValue(formatPeso('11200.00'));
  fireEvent.click(within(modal).getByRole('checkbox', { name: /GRN-1/ }));
  expect(total).toHaveValue(formatPeso('4480.00'));
  fireEvent.click(within(modal).getByRole('checkbox', { name: /GRN-1/ }));
  fireEvent.change(within(modal).getByLabelText(/Your invoice #/), { target: { value: 'TEST-INV-002' } });
  fireEvent.submit(modal.querySelector('form')!);

  await waitFor(() => expect(supplierPortalApi.submitInvoice).toHaveBeenCalledTimes(1));
  const form = vi.mocked(supplierPortalApi.submitInvoice).mock.calls[0][1];
  expect(form.getAll('bill_ids[]').sort()).toEqual(['bill-1', 'bill-2']);
  expect(form.get('gross_total')).toBe('11200.00');
  client.clear();
});

it.each([
  ['draft', 0, '1 expected GRN'],
  ['pending_qc', 1, '0 expected GRNs'],
] as const)('counts %s receipts truthfully and explains available actions', async (status, count, helper) => {
  const po: PortalPoDetail = {
    id: 'po-1', po_number: 'PO-1', date: '2026-10-08', total_amount: '100.00', status: 'supplier_accepted',
    expected_delivery_date: '2026-10-13', confirmed_delivery_date: '2026-10-13', sent_to_supplier_at: null, incoterm: null,
    latest_response: null, shipments: [], items: [{
      id: 'line-1', part_number: 'PKG-001', name: 'Standard Poly Bag', quantity_ordered: '20000.00', quantity_dispatch_limit: '20000.00',
      quantity_received: '0.00', quantity_accepted: '0.00', quantity_remaining: '20000.00',
      quantity_schedulable: '20000.00', unit_price: '0.50', total_price: '10000.00', unit: 'pcs',
    }], goods_receipt_notes: [{
      id: 'grn-1', grn_number: 'GRN-1', received_date: null, status,
      status_label: status === 'draft' ? 'Draft' : 'Pending QC', supplier_invoice_number: null, can_invoice: false,
    }], bills: [],
    capabilities: { can_acknowledge: false, can_accept: false, can_propose: false, can_decline: false,
      can_respond: false, can_update_shipment: true, can_upload_document: true, can_submit_invoice: false, can_schedule_delivery: true },
  };
  vi.mocked(supplierPortalApi.getPo).mockResolvedValue(po);
  vi.mocked(supplierPortalApi.listShippingDocuments).mockResolvedValue([]);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter><SupplierPurchaseOrderDetailPage /></MemoryRouter></QueryClientProvider>);
  expect(await screen.findByText('GRN-1')).toBeInTheDocument();
  expect(screen.getByText('Received GRNs').parentElement).toHaveTextContent(String(count));
  expect(screen.getByText(helper)).toBeInTheDocument();
  expect(screen.getByText(status === 'draft' ? 'Draft' : 'Pending QC')).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Plan delivery' })).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Add shipment' })).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Upload dispatch photo' })).not.toBeInTheDocument();
  expect(screen.getByText(/Add a shipment after dispatch/)).toBeInTheDocument();
    client.clear();
});

it('records dispatched PO quantity without changing received quantity', async () => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date(2026, 9, 9, 0, 30));
  const po: PortalPoDetail = {
    id: 'po-1', po_number: 'PO-1', date: '2026-10-08', total_amount: '100.00', status: 'supplier_accepted',
    expected_delivery_date: '2026-10-13', confirmed_delivery_date: '2026-10-13', sent_to_supplier_at: null, incoterm: null,
    latest_response: null, shipments: [], goods_receipt_notes: [], bills: [], items: [{
      id: 'line-1', part_number: 'PKG-001', name: 'Standard Poly Bag', quantity_ordered: '20000.00', quantity_dispatch_limit: '20000.00',
      quantity_received: '0.00', quantity_accepted: '0.00', quantity_remaining: '20000.00',
      quantity_schedulable: '20000.00', unit_price: '0.50', total_price: '10000.00', unit: 'pcs',
    }], capabilities: { can_acknowledge: false, can_accept: false, can_propose: false, can_decline: false,
      can_respond: false, can_update_shipment: true, can_upload_document: false, can_submit_invoice: false, can_schedule_delivery: false },
  };
  vi.mocked(supplierPortalApi.getPo).mockResolvedValue(po);
  vi.mocked(supplierPortalApi.listShippingDocuments).mockResolvedValue([]);
  vi.mocked(supplierPortalApi.createShipment).mockResolvedValue({} as Awaited<ReturnType<typeof supplierPortalApi.createShipment>>);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter><SupplierPurchaseOrderDetailPage /></MemoryRouter></QueryClientProvider>);
  fireEvent.click(await screen.findByRole('button', { name: 'Add shipment' }));
  expect(screen.getByLabelText(/Shipped date/)).toHaveValue('2026-10-09');
  expect(screen.getByLabelText(/Shipped date/)).toHaveAttribute('readonly');
  fireEvent.click(within(screen.getByRole('dialog', { name: 'Add shipment' })).getByRole('button', { name: 'Cancel' }));
  vi.setSystemTime(new Date(2026, 9, 10, 0, 30));
  fireEvent.click(screen.getByRole('button', { name: 'Add shipment' }));
  expect(screen.getByLabelText(/Shipped date/)).toHaveValue('2026-10-10');
  fireEvent.change(screen.getByLabelText(/Estimated arrival/), { target: { value: '2026-10-13' } });
  fireEvent.change(screen.getByLabelText('Shipped quantity for PKG-001'), { target: { value: '12000.00' } });
  fireEvent.change(screen.getByLabelText(/Reason for partial shipment/), { target: { value: 'Balance follows next week.' } });
  const form = screen.getByRole('dialog', { name: 'Add shipment' }).querySelector('form')!;
  fireEvent.submit(form);
  expect(supplierPortalApi.createShipment).not.toHaveBeenCalled();
  const photo = new File(['picture'], 'dispatch.jpg', { type: 'image/jpeg' });
  fireEvent.change(screen.getByLabelText(/Photo of dispatched items/), { target: { files: [photo] } });
  fireEvent.submit(form);
  await waitFor(() => expect(supplierPortalApi.createShipment).toHaveBeenCalledTimes(1));
  const [poId, payload] = vi.mocked(supplierPortalApi.createShipment).mock.calls[0];
  expect(poId).toBe('po-1');
  expect(payload.get('shipped_date')).toBe('2026-10-10');
  expect(payload.get('items[0][purchase_order_item_id]')).toBe('line-1');
  expect(payload.get('items[0][quantity_shipped]')).toBe('12000.00');
  expect(payload.get('dispatch_photo')).toBe(photo);
  expect(screen.getAllByText('0.00').length).toBeGreaterThan(0);
  client.clear();
});

it('shows remaining quantity, blocks excess, and requires a reason for a partial shipment', async () => {
  const po: PortalPoDetail = {
    id: 'po-1', po_number: 'PO-1', date: '2026-10-08', total_amount: '100.00', status: 'supplier_accepted',
    expected_delivery_date: '2026-10-13', confirmed_delivery_date: '2026-10-13', sent_to_supplier_at: null, incoterm: null,
    latest_response: null, goods_receipt_notes: [], bills: [], items: [{
      id: 'line-1', part_number: 'PKG-001', name: 'Standard Poly Bag', quantity_ordered: '20000.00', quantity_dispatch_limit: '20000.00',
      quantity_received: '0.00', quantity_accepted: '0.00', quantity_remaining: '20000.00',
      quantity_schedulable: '0.00', unit_price: '0.50', total_price: '10000.00', unit: 'pcs',
    }], shipments: [{
      id: 'shipment-1', quantity_recorded: true, items: [{ purchase_order_item_id: 'line-1', quantity_shipped: '12000.00' }],
      shipped_date: '2026-10-08', estimated_arrival: '2026-10-13', carrier: null,
      tracking_number: null, notes: 'Balance follows.', updated_at: null,
    }], capabilities: { can_acknowledge: false, can_accept: false, can_propose: false, can_decline: false,
      can_respond: false, can_update_shipment: true, can_upload_document: false, can_submit_invoice: false, can_schedule_delivery: true },
  };
  vi.mocked(supplierPortalApi.getPo).mockResolvedValue(po);
  vi.mocked(supplierPortalApi.listShippingDocuments).mockResolvedValue([]);
  vi.mocked(supplierPortalApi.createShipment).mockResolvedValue({} as Awaited<ReturnType<typeof supplierPortalApi.createShipment>>);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter><SupplierPurchaseOrderDetailPage /></MemoryRouter></QueryClientProvider>);
  expect(await screen.findByRole('button', { name: 'Add shipment' })).toBeInTheDocument();
  fireEvent.click(await screen.findByRole('button', { name: 'Add shipment' }));
  expect(screen.getByText('Remaining to dispatch: 8,000 pcs')).toBeInTheDocument();
  fireEvent.change(screen.getByLabelText(/Estimated arrival/), { target: { value: '2026-10-13' } });
  fireEvent.change(screen.getByLabelText(/Photo of dispatched items/), { target: { files: [new File(['picture'], 'dispatch.jpg', { type: 'image/jpeg' })] } });
  fireEvent.change(screen.getByLabelText('Shipped quantity for PKG-001'), { target: { value: '7000.00' } });
  expect(screen.getByLabelText(/Reason for partial shipment/)).toBeRequired();
  const form = screen.getByRole('dialog', { name: 'Add shipment' }).querySelector('form')!;
  fireEvent.submit(form);
  expect(supplierPortalApi.createShipment).not.toHaveBeenCalled();
  fireEvent.change(screen.getByLabelText('Shipped quantity for PKG-001'), { target: { value: '9000.00' } });
  expect(screen.getByText('Maximum 8,000 pcs')).toBeInTheDocument();
  fireEvent.submit(form);
  expect(supplierPortalApi.createShipment).not.toHaveBeenCalled();
  fireEvent.change(screen.getByLabelText('Shipped quantity for PKG-001'), { target: { value: '8000.00' } });
  expect(screen.getByLabelText('Notes (optional)')).toBeInTheDocument();
  fireEvent.submit(form);
  await waitFor(() => expect(supplierPortalApi.createShipment).toHaveBeenCalledTimes(1));
  expect(vi.mocked(supplierPortalApi.createShipment).mock.calls[0][1].get('items[0][quantity_shipped]')).toBe('8000.00');
  client.clear();
});

it('requires quantities on an older shipment before another can be added', async () => {
  const po: PortalPoDetail = {
    id: 'po-1', po_number: 'PO-1', date: '2026-10-08', total_amount: '100.00', status: 'supplier_accepted',
    expected_delivery_date: '2026-10-13', confirmed_delivery_date: '2026-10-13', sent_to_supplier_at: null, incoterm: null,
    latest_response: null, goods_receipt_notes: [], bills: [], items: [{
      id: 'line-1', part_number: 'PKG-001', name: 'Standard Poly Bag', quantity_ordered: '20000.00', quantity_dispatch_limit: '20000.00',
      quantity_received: '0.00', quantity_accepted: '0.00', quantity_remaining: '20000.00',
      quantity_schedulable: '0.00', unit_price: '0.50', total_price: '10000.00', unit: 'pcs',
    }], shipments: [{
      id: 'shipment-1', quantity_recorded: false, items: [], shipped_date: '2026-10-08',
      estimated_arrival: '2026-10-13', carrier: 'DHL', tracking_number: null,
      notes: null, updated_at: null,
    }], capabilities: { can_acknowledge: false, can_accept: false, can_propose: false, can_decline: false,
      can_respond: false, can_update_shipment: true, can_upload_document: false, can_submit_invoice: false, can_schedule_delivery: false },
  };
  vi.mocked(supplierPortalApi.getPo).mockResolvedValue(po);
  vi.mocked(supplierPortalApi.listShippingDocuments).mockResolvedValue([]);
  vi.mocked(supplierPortalApi.updateShipmentById).mockResolvedValue({} as Awaited<ReturnType<typeof supplierPortalApi.updateShipmentById>>);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter><SupplierPurchaseOrderDetailPage /></MemoryRouter></QueryClientProvider>);
  const add = await screen.findByRole('button', { name: 'Add shipment' });
  expect(add).toBeDisabled();
  fireEvent.click(screen.getAllByRole('button', { name: 'Record quantities' }).at(-1)!);
  fireEvent.change(screen.getByLabelText('Shipped quantity for PKG-001'), { target: { value: '12000.00' } });
  fireEvent.change(screen.getByLabelText(/Reason for partial shipment/), { target: { value: 'Balance follows.' } });
  fireEvent.click(screen.getAllByRole('button', { name: 'Record quantities' }).at(-1)!);
  await waitFor(() => expect(supplierPortalApi.updateShipmentById).toHaveBeenCalledWith('po-1', 'shipment-1', expect.objectContaining({
    items: [{ purchase_order_item_id: 'line-1', quantity_shipped: '12000.00' }],
    notes: 'Balance follows.',
  })));
  client.clear();
});

it('offers a photo backfill on an earlier shipment without one', async () => {
  const po: PortalPoDetail = {
    id: 'po-1', po_number: 'PO-1', date: '2026-10-08', total_amount: '100.00', status: 'supplier_accepted',
    expected_delivery_date: '2026-10-13', confirmed_delivery_date: '2026-10-13', sent_to_supplier_at: null, incoterm: null,
    latest_response: null, goods_receipt_notes: [], bills: [], items: [], shipments: [{
      id: 'shipment-1', quantity_recorded: true, items: [], shipped_date: '2026-10-08',
      estimated_arrival: '2026-10-13', carrier: null, tracking_number: null, notes: null, updated_at: null,
    }], capabilities: { can_acknowledge: false, can_accept: false, can_propose: false, can_decline: false,
      can_respond: false, can_update_shipment: false, can_upload_document: true, can_submit_invoice: false, can_schedule_delivery: false },
  };
  vi.mocked(supplierPortalApi.getPo).mockResolvedValue(po);
  vi.mocked(supplierPortalApi.listShippingDocuments).mockResolvedValue([]);
  vi.mocked(supplierPortalApi.uploadShippingDocument).mockResolvedValue({} as Awaited<ReturnType<typeof supplierPortalApi.uploadShippingDocument>>);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter><SupplierPurchaseOrderDetailPage /></MemoryRouter></QueryClientProvider>);
  fireEvent.click(await screen.findByRole('button', { name: 'Add photo' }));
  const modal = screen.getByRole('dialog', { name: 'Add dispatch photo' });
  const photo = new File(['picture'], 'dispatch.jpg', { type: 'image/jpeg' });
  fireEvent.change(within(modal).getByLabelText(/Photo of dispatched items/), { target: { files: [photo] } });
  fireEvent.submit(modal.querySelector('form')!);
  await waitFor(() => expect(supplierPortalApi.uploadShippingDocument).toHaveBeenCalledTimes(1));
  const [poId, form] = vi.mocked(supplierPortalApi.uploadShippingDocument).mock.calls[0];
  expect(poId).toBe('po-1');
  expect(form.get('supplier_shipment_id')).toBe('shipment-1');
  expect(form.get('file')).toBe(photo);
  client.clear();
});

it('shows a failed shipping document read and retries without hiding the purchase order', async () => {
  const po: PortalPoDetail = {
    id: 'po-1', po_number: 'PO-1', date: '2026-10-04', total_amount: '100.00', status: 'supplier_accepted',
    expected_delivery_date: null, confirmed_delivery_date: null, sent_to_supplier_at: null, incoterm: null,
    latest_response: null, shipments: [], items: [], goods_receipt_notes: [], bills: [],
    capabilities: { can_acknowledge: false, can_accept: false, can_propose: false, can_decline: false,
      can_respond: false, can_update_shipment: false, can_upload_document: true, can_submit_invoice: false, can_schedule_delivery: false },
  };
  vi.mocked(supplierPortalApi.getPo).mockResolvedValue(po);
  vi.mocked(supplierPortalApi.listShippingDocuments).mockRejectedValueOnce(new Error('Unavailable')).mockResolvedValue([]);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter><SupplierPurchaseOrderDetailPage /></MemoryRouter></QueryClientProvider>);
  expect(await screen.findByRole('alert')).toHaveTextContent('Shipping documents could not be loaded');
  expect(screen.getByText('PO-1')).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Retry documents' }));
  await waitFor(() => expect(screen.queryByRole('alert')).not.toBeInTheDocument());
  client.clear();
});

it('accepts the required date as written and sends date changes through Propose changes', async () => {
  const po: PortalPoDetail = {
    id: 'po-1', po_number: 'PO-1', date: '2026-10-08', total_amount: '100.00', status: 'sent',
    expected_delivery_date: '2026-10-13', confirmed_delivery_date: null, sent_to_supplier_at: null, incoterm: null,
    latest_response: null, shipments: [], items: [], goods_receipt_notes: [], bills: [],
    capabilities: { can_acknowledge: true, can_accept: true, can_propose: true, can_decline: true,
      can_respond: true, can_update_shipment: false, can_upload_document: false, can_submit_invoice: false, can_schedule_delivery: false },
  };
  vi.mocked(supplierPortalApi.getPo).mockResolvedValue(po);
  vi.mocked(supplierPortalApi.listShippingDocuments).mockResolvedValue([]);
  vi.mocked(supplierPortalApi.respondToPurchaseOrder).mockResolvedValue(po);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter><SupplierPurchaseOrderDetailPage /></MemoryRouter></QueryClientProvider>);

  fireEvent.click(await screen.findByRole('button', { name: 'Accept' }));
  const accept = screen.getByRole('dialog', { name: 'Accept purchase order' });
  expect(within(accept).getByText('Required delivery date')).toBeInTheDocument();
  expect(within(accept).queryByLabelText('Confirmed delivery date (optional)')).not.toBeInTheDocument();
  fireEvent.click(within(accept).getByRole('button', { name: 'Accept PO' }));
  await waitFor(() => expect(supplierPortalApi.respondToPurchaseOrder).toHaveBeenCalledWith('po-1', { type: 'accept', notes: undefined }));

  fireEvent.click(screen.getByRole('button', { name: 'Propose changes' }));
  const propose = screen.getByRole('dialog', { name: 'Propose changes' });
  fireEvent.change(within(propose).getByLabelText('Proposed delivery date (optional)'), { target: { value: '2026-10-15' } });
  fireEvent.click(within(propose).getByRole('button', { name: 'Send proposal' }));
  await waitFor(() => expect(supplierPortalApi.respondToPurchaseOrder).toHaveBeenCalledWith('po-1', {
    type: 'propose', proposed_delivery_date: '2026-10-15', notes: undefined, items: [],
  }));
  client.clear();
});
