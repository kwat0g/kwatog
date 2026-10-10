import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import { grnApi } from '@/api/inventory/grn';
import { supplierActivityApi } from '@/api/b2b/supplier-activity';
import { warehouseApi } from '@/api/inventory/warehouse';
import type { GoodsReceiptNote } from '@/types/inventory';
import GrnDetailPage from './detail';

vi.mock('@/api/inventory/grn', () => ({
  grnApi: { show: vi.fn(), options: vi.fn(), finalize: vi.fn() },
}));
vi.mock('@/api/b2b/supplier-activity', () => ({
  supplierActivityApi: { forGoodsReceipt: vi.fn() },
}));
vi.mock('@/api/inventory/warehouse', () => ({ warehouseApi: { tree: vi.fn() } }));
vi.mock('@/hooks/useChainProgress', () => ({ useChainProgress: () => undefined }));
const permissions = vi.hoisted(() => ({ manageCases: true }));
vi.mock('@/hooks/usePermission', () => ({
  usePermission: () => ({
    can: (permission: string) =>
      permission !== 'return_management.manage' || permissions.manageCases,
  }),
}));
afterEach(() => {
  vi.clearAllMocks();
  permissions.manageCases = true;
});

const draft: GoodsReceiptNote = {
  id: 'grn-1',
  grn_number: 'GRN-202610-0001',
  status: 'draft',
  status_label: 'Draft',
  received_date: null,
  rejected_reason: null,
  remarks: null,
  accepted_at: null,
  vendor: { id: 'vendor-1', name: 'Asia Pacific Polymers' },
  purchase_order: { id: 'po-1', po_number: 'PO-202610-0001' },
  receiver: null,
  acceptor: null,
  created_at: '2026-10-08T00:00:00Z',
  items: [
    {
      id: 'line-1',
      purchase_order_item_id: 'po-line-1',
      item: {
        id: 'item-1',
        code: 'PKG-001',
        name: 'Standard Poly Bag',
        unit_of_measure: 'pcs',
        quality_plan_ready: true,
      },
      quantity_received: '0.000',
      quantity_received_in_uom: null,
      received_uom_conversion_factor: null,
      quantity_accepted: '0.000',
      quantity_outstanding: '20000.000',
      unit_cost: '0.50',
      received_uom_code: null,
      lot_number: null,
      supplier_lot_reference: null,
      expiry_date: null,
      moisture_percentage: null,
      coa_document_path: null,
      coa_verified: false,
      remarks: null,
    },
  ],
};

function renderDraft() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/inventory/grn/grn-1']}>
        <Routes>
          <Route path="/inventory/grn/:id" element={<GrnDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
  return client;
}

it('shows chain and details first, auto-matches the supplier shipment, and validates received goods', async () => {
  vi.mocked(grnApi.show).mockResolvedValue({
    ...draft,
    items: [
      {
        ...draft.items![0],
        item: {
          ...draft.items![0].item!,
          stored_bins: [{ id: 'bin-1', full_code: 'MAIN-GEN-A1', quantity: '25.000' }],
        },
      },
      {
        ...draft.items![0],
        id: 'line-2',
        purchase_order_item_id: 'po-line-2',
        item: { ...draft.items![0].item!, id: 'item-2', code: 'PKG-002', name: 'Second item' },
        quantity_outstanding: '8000.000',
      },
    ],
  });
  vi.mocked(grnApi.options).mockResolvedValue({
    statuses: [{ value: 'draft', label: 'Draft' }],
    default_qc_result: 'pending',
  });
  vi.mocked(supplierActivityApi.forGoodsReceipt).mockResolvedValue({
    shipment: null,
    documents: [],
    shipments: [
      {
        id: 'received-shipment', can_edit: false,
        shipped_date: '2026-10-07', carrier: null, tracking_number: null,
        estimated_arrival: '2026-10-12', notes: null, updated_at: null,
        items: [{ purchase_order_item_id: 'po-line-1', quantity_shipped: '10000.00' }],
      },
      {
        id: 'shipment-1',
        can_edit: true,
        shipped_date: '2026-10-08',
        carrier: null,
        tracking_number: null,
        estimated_arrival: '2026-10-13',
        notes: null,
        updated_at: null,
        items: [{ purchase_order_item_id: 'po-line-1', quantity_shipped: '20000.00' }],
      },
    ],
  });
  vi.mocked(warehouseApi.tree).mockResolvedValue([
    {
      id: 'warehouse-1',
      name: 'Main',
      code: 'MAIN',
      address: null,
      is_active: true,
      zones: [
        {
          id: 'zone-1',
          name: 'General',
          code: 'GEN',
          zone_type: 'staging',
          locations: [
            {
              id: 'bin-1',
              zone_id: 'zone-1',
              code: 'A1',
              rack: null,
              bin: null,
              is_active: true,
              is_blocked: false,
              full_code: 'MAIN-GEN-A1',
            },
          ],
        },
      ],
    },
  ]);
  vi.mocked(grnApi.finalize).mockResolvedValue(draft);
  const client = renderDraft();

  const finalize = await screen.findByRole('button', { name: 'Finalize receiving' });
  expect(finalize).toBeDisabled();
  expect(
    screen
      .getByText('Procure-to-pay chain')
      .compareDocumentPosition(screen.getByText('Receipt details')) &
      Node.DOCUMENT_POSITION_FOLLOWING,
  ).toBeTruthy();
  expect(
    screen
      .getByText('Receipt details')
      .compareDocumentPosition(screen.getByText('Record received goods')) &
      Node.DOCUMENT_POSITION_FOLLOWING,
  ).toBeTruthy();
  expect(screen.queryByText('Receipt created — awaiting QC / acceptance')).not.toBeInTheDocument();
  await waitFor(() => expect(screen.getByText('Matched')).toBeInTheDocument());
  await screen.findByText('Suggested from current stock');
  expect(screen.getByLabelText('Bin PKG-001')).toHaveValue('bin-1');
  expect(screen.getAllByText('Add supplier lot or expiry (optional)')).toHaveLength(2);
  expect(screen.queryByLabelText('Received UOM')).not.toBeInTheDocument();
  expect(screen.queryByLabelText('Moisture %')).not.toBeInTheDocument();
  expect(screen.queryByLabelText('COA path')).not.toBeInTheDocument();
  expect(screen.queryByText(/Unannounced delivery/i)).not.toBeInTheDocument();
  fireEvent.change(screen.getByLabelText('Entered quantity PKG-001'), {
    target: { value: '20000' },
  });
  expect(finalize).toBeEnabled();
  fireEvent.change(screen.getByLabelText('Entered quantity PKG-001'), {
    target: { value: '20001' },
  });
  expect(finalize).toBeDisabled();
  expect(screen.getByText('Maximum 20,000 pcs')).toBeInTheDocument();
  fireEvent.change(screen.getByLabelText('Entered quantity PKG-001'), {
    target: { value: '20000' },
  });
  expect(finalize).toBeEnabled();
  fireEvent.change(screen.getByLabelText('Entered quantity PKG-002'), {
    target: { value: '8000' },
  });
  expect(finalize).toBeDisabled();
  fireEvent.change(screen.getByLabelText('Bin PKG-002'), { target: { value: 'bin-1' } });
  expect(finalize).toBeEnabled();
  fireEvent.click(screen.getAllByText('Add supplier lot or expiry (optional)')[0]);
  fireEvent.change(screen.getAllByLabelText('Supplier lot / batch')[0], {
    target: { value: 'SUP-LOT-7' },
  });
  fireEvent.click(finalize);
  fireEvent.click(screen.getByRole('button', { name: 'Finalize GRN' }));
  await waitFor(() =>
    expect(grnApi.finalize).toHaveBeenCalledWith(
      'grn-1',
      expect.objectContaining({
        supplier_shipment_id: 'shipment-1',
        items: expect.arrayContaining([
          expect.objectContaining({
            location_id: 'bin-1',
            received_uom_code: 'pcs',
            supplier_lot_reference: 'SUP-LOT-7',
          }),
        ]),
      }),
    ),
  );
  client.clear();
});

it('asks which existing bin to use when the item is stored in several bins', async () => {
  vi.mocked(grnApi.show).mockResolvedValue({
    ...draft,
    items: [
      {
        ...draft.items![0],
        item: {
          ...draft.items![0].item!,
          stored_bins: [
            { id: 'bin-1', full_code: 'MAIN-GEN-A1', quantity: '25.000' },
            { id: 'bin-2', full_code: 'MAIN-GEN-A2', quantity: '10.000' },
          ],
        },
      },
    ],
  });
  vi.mocked(grnApi.options).mockResolvedValue({
    statuses: [{ value: 'draft', label: 'Draft' }],
    default_qc_result: 'pending',
  });
  vi.mocked(supplierActivityApi.forGoodsReceipt).mockResolvedValue({
    shipment: null,
    documents: [],
    shipments: [
      {
        id: 'shipment-1',
        shipped_date: '2026-10-08',
        carrier: null,
        tracking_number: null,
        estimated_arrival: null,
        notes: null,
        updated_at: null,
      },
    ],
  });
  const bin = (id: string, code: string) => ({
    id,
    zone_id: 'zone-1',
    code,
    rack: null,
    bin: null,
    is_active: true,
    is_blocked: false,
    full_code: `MAIN-GEN-${code}`,
  });
  vi.mocked(warehouseApi.tree).mockResolvedValue([
    {
      id: 'warehouse-1',
      name: 'Main',
      code: 'MAIN',
      address: null,
      is_active: true,
      zones: [
        {
          id: 'zone-1',
          name: 'General',
          code: 'GEN',
          zone_type: 'staging',
          locations: [bin('bin-1', 'A1'), bin('bin-2', 'A2')],
        },
      ],
    },
  ]);
  const client = renderDraft();

  await screen.findByText('Choose a bin holding this item');
  expect(screen.getByLabelText('Bin PKG-001')).toHaveValue('');
  fireEvent.change(screen.getByLabelText('Entered quantity PKG-001'), { target: { value: '10' } });
  expect(screen.getByRole('button', { name: 'Finalize receiving' })).toBeDisabled();
  fireEvent.change(screen.getByLabelText('Bin PKG-001'), { target: { value: 'bin-2' } });
  expect(screen.getByRole('button', { name: 'Finalize receiving' })).toBeEnabled();
  client.clear();
});

it('does not offer unannounced receiving when the supplier has no shipment', async () => {
  vi.mocked(grnApi.show).mockResolvedValue(draft);
  vi.mocked(grnApi.options).mockResolvedValue({
    statuses: [{ value: 'draft', label: 'Draft' }],
    default_qc_result: 'pending',
  });
  vi.mocked(supplierActivityApi.forGoodsReceipt).mockResolvedValue({
    shipment: null,
    documents: [],
    shipments: [],
  });
  vi.mocked(warehouseApi.tree).mockResolvedValue([]);
  const client = renderDraft();

  expect(await screen.findByText(/No supplier shipment reported yet/)).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Finalize receiving' })).toBeDisabled();
  expect(screen.queryByText(/Unannounced delivery/i)).not.toBeInTheDocument();
  client.clear();
});

it('requires the warehouse to choose which shipment arrived when the supplier sent more than one', async () => {
  vi.mocked(grnApi.show).mockResolvedValue(draft);
  vi.mocked(grnApi.options).mockResolvedValue({
    statuses: [{ value: 'draft', label: 'Draft' }],
    default_qc_result: 'pending',
  });
  const shipment = {
    shipped_date: '2026-10-08',
    carrier: null,
    tracking_number: null,
    estimated_arrival: null,
    notes: null,
    updated_at: null,
  };
  vi.mocked(supplierActivityApi.forGoodsReceipt).mockResolvedValue({
    shipment: null,
    documents: [],
    shipments: [
      { ...shipment, id: 'shipment-1' },
      { ...shipment, id: 'shipment-2' },
    ],
  });
  vi.mocked(warehouseApi.tree).mockResolvedValue([]);
  const client = renderDraft();

  const select = await screen.findByLabelText(/Supplier shipment/);
  expect(screen.getByRole('status')).toHaveTextContent('select the shipment that arrived');
  expect(screen.queryByRole('option', { name: /Unannounced/i })).not.toBeInTheDocument();
  fireEvent.change(select, { target: { value: 'shipment-2' } });
  expect(screen.getByRole('status')).toHaveTextContent(
    'Enter the quantity that physically arrived',
  );
  client.clear();
});

it('offers warehouse a shortage report beside the supplier dispatch difference', async () => {
  permissions.manageCases = false;
  vi.mocked(grnApi.show).mockResolvedValue({
    ...draft,
    status: 'pending_qc',
    received_date: '2026-10-08',
    items: [{ ...draft.items![0], quantity_received: '18000.000' }],
    supplier_shipment: {
      id: 'shipment-1',
      tracking_number: null,
      items: [
        {
          purchase_order_item_id: 'po-line-1',
          quantity_shipped: '20000.00',
          quantity_received: '18000.00',
          difference: '-2000.00',
          unit: 'pcs',
        },
      ],
    },
  });
  vi.mocked(grnApi.options).mockResolvedValue({ statuses: [], default_qc_result: 'pending' });
  vi.mocked(warehouseApi.tree).mockResolvedValue([]);
  const client = renderDraft();

  expect(await screen.findByText('Short by 2,000 pcs')).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Report shortage' })).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Report a problem' })).not.toBeInTheDocument();
  client.clear();
});
