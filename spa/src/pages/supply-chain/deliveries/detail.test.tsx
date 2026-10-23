import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import DeliveryDetailPage from './detail';
import { deliveriesApi, vehiclesApi } from '@/api/supply-chain';
import type { Delivery } from '@/types/supplyChain';

const permissions = vi.hoisted(() => ({ edit: true }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => permissions.edit }) }));
vi.mock('@/hooks/useChainProgress', () => ({ useChainProgress: vi.fn() }));
vi.mock('react-router-dom', async (original) => ({ ...(await original<typeof import('react-router-dom')>()), useParams: () => ({ id: 'dr-1' }) }));
vi.mock('@/api/supply-chain', () => ({
  deliveriesApi: {
    show: vi.fn(), updateStatus: vi.fn(), shipmentPlan: vi.fn(),
    driverOptions: vi.fn().mockResolvedValue([{ id: 'driver-1', name: 'Nestor Flores' }]),
    options: vi.fn().mockResolvedValue({ statuses: [
      { value: 'scheduled', label: 'Scheduled', next_status: 'loading' },
      { value: 'loading', label: 'Loading', next_status: 'in_transit' },
      { value: 'in_transit', label: 'In transit', next_status: 'delivered' },
    ] }),
  },
  deliveryProofsApi: { upload: vi.fn(), options: vi.fn().mockResolvedValue({ proof_types: [{ value: 'signed_dr', label: 'Signed receipt' }, { value: 'photo', label: 'Delivery photo' }, { value: 'loading_photo', label: 'Loading photo' }, { value: 'coc', label: 'Certificate of Conformance' }, { value: 'other', label: 'Other document' }] }) },
  vehiclesApi: { list: vi.fn().mockResolvedValue({ data: [{ id: 'truck-1', name: 'Truck', plate_number: 'ABC-123' }] }) },
}));
const delivery = {
  id: 'dr-1', delivery_number: 'DR-202610-0001', status: 'scheduled', scheduled_date: '2026-10-10',
  sales_order: { id: 'so-1', so_number: 'SO-202610-0025' }, vehicle: null, driver: null,
  stock_reservation_status: 'reserved', can_reserve_stock: false,
  items: [
    { id: 'line-1', sales_order_item_id: 'so-line', product: { part_number: 'PC-001', name: 'Pivot Cap Cover' },
      unit_of_measure: 'pcs', inspection: { id: 'qc-1', inspection_number: 'QC-202610-0007', status: 'failed' }, quantity: 498, unit_price: '35.00' },
    { id: 'line-2', sales_order_item_id: 'so-line', product: { part_number: 'PC-001', name: 'Pivot Cap Cover' },
      unit_of_measure: 'pcs', inspection: { id: 'qc-2', inspection_number: 'QC-202610-0009', status: 'passed' }, quantity: 2, unit_price: '35.00' },
  ],
} as Delivery;
function show(data: Delivery) {
  vi.mocked(deliveriesApi.show).mockResolvedValue(data);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter><DeliveryDetailPage /></MemoryRouter></QueryClientProvider>);
}
beforeEach(() => {
  vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:loading-preview');
  vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {});
});
afterEach(() => { cleanup(); permissions.edit = true; vi.clearAllMocks(); vi.restoreAllMocks(); vi.useRealTimers(); });
const assigned = { ...delivery, scheduled_date: '2026-10-08', expected_delivery_date: '2026-10-10', carrier: 'Ogami fleet', vehicle: { id: 'truck-1', name: 'Truck', plate_number: 'ABC-123' }, driver: { id: 'driver-1', name: 'Nestor Flores' } };
const loadingPhoto = {
  id: 'loading-1', proof_type: 'loading_photo' as const, file_name: 'loaded.jpg', file_size: 10,
  mime_type: 'image/jpeg', is_image: true, notes: null, view_url: '/loading.jpg', deleted_at: null,
  uploader: null, uploaded_at: '2026-10-08T10:00:00Z',
};
it('shows the customer ship-to as a Maps link and offers the printable receipt', async () => {
  show({
    ...delivery,
    sales_order: { id: 'so-1', so_number: 'SO-202610-0025', customer: { id: 'customer-1', name: 'Toyota Motor Philippines' } },
    delivery_address: 'Toyota Plant, Santa Rosa, Laguna',
    delivery_contact_person: 'Ana Reyes',
    delivery_contact_phone: '049-500-1234',
  });

  const mapLink = await screen.findByRole('link', {
    name: /open driving directions to toyota plant, santa rosa, laguna/i,
  });
  expect(mapLink).toHaveAttribute(
    'href',
    'https://www.google.com/maps/dir/?api=1&destination=Toyota%20Plant%2C%20Santa%20Rosa%2C%20Laguna',
  );
  expect(screen.getByText('Toyota Motor Philippines')).toBeVisible();
  expect(screen.getByRole('button', { name: 'Delivery receipt' })).toBeVisible();
});

it('shows released quantities with one shipment action and no premature receipt upload', async () => {
  show(delivery);
  expect(await screen.findByRole('button', { name: 'Arrange shipment' })).toBeVisible();
  expect(screen.queryByRole('button', { name: /Mark Loading|Prepare shipment|Quick photo|Assign driver/ })).not.toBeInTheDocument();
  expect(screen.getByText('500 pcs')).toBeVisible();
  expect(screen.queryByRole('heading', { name: 'Customer receipt' })).not.toBeInTheDocument();
  expect(screen.queryByText('Awaiting invoice')).not.toBeInTheDocument();
});
it('requires complete shipment details and loading photo before dispatch', async () => {
  vi.mocked(deliveriesApi.shipmentPlan).mockResolvedValue({ ...assigned, status: 'in_transit' });
  show(delivery);
  fireEvent.click(await screen.findByRole('button', { name: 'Arrange shipment' }));
  expect(screen.getByRole('button', { name: 'Dispatch shipment' })).toBeDisabled();
  fireEvent.change(screen.getByLabelText('Shipment date', { exact: false }), { target: { value: '2026-10-08' } });
  fireEvent.change(screen.getByLabelText('Expected arrival', { exact: false }), { target: { value: '2026-10-10' } });
  fireEvent.change(screen.getByLabelText('Carrier', { exact: false }), { target: { value: 'Ogami fleet' } });
  await waitFor(() => expect(screen.getByLabelText('Vehicle', { exact: false })).not.toBeDisabled());
  fireEvent.change(screen.getByLabelText('Vehicle', { exact: false }), { target: { value: 'truck-1' } });
  fireEvent.change(screen.getByLabelText('Driver', { exact: false }), { target: { value: 'driver-1' } });
  expect(screen.getByRole('button', { name: 'Save plan' })).toBeEnabled();
  expect(screen.getByRole('button', { name: 'Dispatch shipment' })).toBeDisabled();
  const photo = new File(['loaded goods'], 'loading.jpg', { type: 'image/jpeg' });
  fireEvent.change(screen.getByLabelText('Loading photo', { exact: true }), { target: { files: [photo] } });
  expect(await screen.findByAltText('Selected loading photo')).toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'Dispatch shipment' }));
  await waitFor(() => expect(deliveriesApi.shipmentPlan).toHaveBeenCalledWith('dr-1', {
    scheduled_date: '2026-10-08', expected_delivery_date: '2026-10-10', carrier: 'Ogami fleet',
    vehicle_id: 'truck-1', driver_id: 'driver-1', reason: 'Initial shipment arrangement.', dispatch: true, loading_photo: photo,
  }));
  expect(deliveriesApi.updateStatus).not.toHaveBeenCalled();
});
it('saves future shipment plans without a loading photo', async () => {
  vi.mocked(deliveriesApi.shipmentPlan).mockResolvedValue({ ...assigned, scheduled_date: '2099-10-10', expected_delivery_date: '2099-10-11' });
  show({ ...assigned, scheduled_date: '2099-10-10', expected_delivery_date: '2099-10-11' });
  fireEvent.click(await screen.findByRole('button', { name: 'Arrange shipment' }));
  await waitFor(() => expect(screen.getByRole('button', { name: 'Save plan' })).toBeEnabled());
  expect(screen.getByRole('button', { name: 'Dispatch shipment' })).toBeDisabled();
  expect(screen.getByText(/Shipment date is in the future/)).toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'Save plan' }));
  await waitFor(() => expect(deliveriesApi.shipmentPlan).toHaveBeenCalledWith('dr-1', expect.objectContaining({ dispatch: false, loading_photo: undefined })));
});
it('allows dispatch after Manila midnight even when UTC is on the previous day', async () => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date('2026-10-08T16:30:00Z'));
  show({ ...assigned, scheduled_date: '2026-10-09', proofs: [loadingPhoto] });
  fireEvent.click(await screen.findByRole('button', { name: 'Arrange shipment' }));
  await waitFor(() => expect(screen.getByRole('button', { name: 'Dispatch shipment' })).toBeEnabled());
  expect(screen.queryByText(/Shipment date is in the future/)).not.toBeInTheDocument();
});
it('keeps current assignment when the available fleet list excludes its vehicle', async () => {
  vi.mocked(vehiclesApi.list).mockResolvedValueOnce({
    data: [],
    meta: { current_page: 1, last_page: 1, per_page: 100, total: 0, from: null, to: null },
    links: { first: '/supply-chain/vehicles?page=1', last: '/supply-chain/vehicles?page=1', prev: null, next: null },
  });
  show(assigned);
  fireEvent.click(await screen.findByRole('button', { name: 'Arrange shipment' }));
  await waitFor(() => expect(screen.getByRole('button', { name: 'Save plan' })).toBeEnabled());
  expect(screen.getByLabelText('Vehicle', { exact: false })).toHaveValue('truck-1');
  expect(screen.getByLabelText('Driver', { exact: false })).toHaveValue('driver-1');
});
it('shows inline backend errors and preserves entered shipment details', async () => {
  vi.mocked(deliveriesApi.shipmentPlan).mockRejectedValueOnce({ response: { data: { message: 'Stock reservation needs attention.', errors: { carrier: ['Carrier must be valid.'] } } } });
  show(assigned);
  fireEvent.click(await screen.findByRole('button', { name: 'Arrange shipment' }));
  await waitFor(() => expect(screen.getByRole('button', { name: 'Save plan' })).toBeEnabled());
  fireEvent.click(screen.getByRole('button', { name: 'Save plan' }));
  expect(await screen.findByText('Stock reservation needs attention.')).toBeVisible();
  expect(screen.getByLabelText('Carrier', { exact: false })).toHaveValue('Ogami fleet');
  expect(screen.getByLabelText('Carrier', { exact: false })).toHaveAttribute('aria-invalid', 'true');
});
it('rejects non-image loading evidence before dispatch', async () => {
  show(assigned);
  fireEvent.click(await screen.findByRole('button', { name: 'Arrange shipment' }));
  fireEvent.change(screen.getByLabelText('Loading photo', { exact: true }), { target: { files: [new File(['pdf'], 'loading.pdf', { type: 'application/pdf' })] } });
  expect(screen.getByText('Choose an image no larger than 10MB.')).toBeVisible();
  expect(screen.getByRole('button', { name: 'Dispatch shipment' })).toBeDisabled();
});
it('rejects oversized loading photos', async () => {
  show(assigned);
  fireEvent.click(await screen.findByRole('button', { name: 'Arrange shipment' }));
  const photo = new File(['image'], 'huge.jpg', { type: 'image/jpeg' });
  Object.defineProperty(photo, 'size', { value: 10 * 1024 * 1024 + 1 });
  fireEvent.change(screen.getByLabelText('Loading photo', { exact: true }), { target: { files: [photo] } });
  expect(screen.getByText('Choose an image no larger than 10MB.')).toBeVisible();
  expect(screen.getByRole('button', { name: 'Dispatch shipment' })).toBeDisabled();
});
it('completes legacy loading shipments with retained assignment and saved loading evidence', async () => {
  vi.mocked(deliveriesApi.shipmentPlan).mockResolvedValue({ ...assigned, status: 'in_transit', proofs: [loadingPhoto] });
  show({ ...assigned, status: 'loading', proofs: [loadingPhoto] });
  fireEvent.click(await screen.findByRole('button', { name: 'Arrange shipment' }));
  await waitFor(() => expect(screen.getByRole('button', { name: 'Dispatch shipment' })).toBeEnabled());
  expect(screen.getByLabelText('Vehicle', { exact: false })).toBeDisabled();
  expect(screen.getByLabelText('Driver', { exact: false })).toBeDisabled();
  fireEvent.click(screen.getByRole('button', { name: 'Dispatch shipment' }));
  await waitFor(() => expect(deliveriesApi.shipmentPlan).toHaveBeenCalledWith('dr-1', expect.objectContaining({ dispatch: true, vehicle_id: 'truck-1', driver_id: 'driver-1', loading_photo: undefined })));
});
it('blocks shipment submission when fleet lookup fails, then recovers without losing details', async () => {
  vi.mocked(vehiclesApi.list).mockRejectedValueOnce(new Error('offline'));
  show(assigned);
  fireEvent.click(await screen.findByRole('button', { name: 'Arrange shipment' }));
  const retry = await screen.findByRole('button', { name: 'Retry vehicles' });
  expect(screen.getByRole('button', { name: 'Save plan' })).toBeDisabled();
  fireEvent.click(retry);
  await waitFor(() => expect(screen.getByRole('button', { name: 'Save plan' })).toBeEnabled());
  expect(screen.getByLabelText('Carrier', { exact: false })).toHaveValue('Ogami fleet');
});
it('keeps loading evidence separate from receipts and confirmation eligibility', async () => {
  show({ ...assigned, status: 'delivered', proofs: [loadingPhoto] });
  expect(await screen.findByText('Loading evidence')).toBeVisible();
  expect(screen.getByRole('button', { name: /^Confirm$/ })).toBeDisabled();
  expect(screen.queryByText('1 receipt')).not.toBeInTheDocument();
  expect(screen.queryByRole('option', { name: 'Loading photo' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /Arrange shipment|Quick photo|Choose loading photo/ })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Upload customer receipt' })).not.toBeInTheDocument();
});
it('leaves customer receipt capture to the assigned driver while office reviews delivery', async () => {
  show({ ...assigned, status: 'in_transit', proofs: [loadingPhoto] });
  expect(await screen.findByRole('heading', { name: 'Customer receipt' })).toBeVisible();
  expect(screen.getAllByText('Assigned driver uploads customer receipt after arrival.').length).toBeGreaterThan(0);
  expect(screen.queryByRole('button', { name: /Upload customer receipt|Mark delivered|Dispatch shipment/ })).not.toBeInTheDocument();
  expect(screen.queryByLabelText('Customer receipt file')).not.toBeInTheDocument();
  expect(deliveriesApi.updateStatus).not.toHaveBeenCalled();
});
it('retains office receipt review and authorized confirmation after driver upload', async () => {
  show({ ...assigned, status: 'delivered', proofs: [{ ...loadingPhoto, id: 'receipt-1', proof_type: 'photo', file_name: 'customer-receipt.jpg' }] });
  expect(await screen.findByRole('heading', { name: 'Customer receipt' })).toBeVisible();
  expect(screen.getByText('customer-receipt.jpg')).toBeVisible();
  expect(screen.getByRole('button', { name: /^Confirm$/ })).toBeEnabled();
  expect(screen.queryByRole('button', { name: 'Upload customer receipt' })).not.toBeInTheDocument();
});
it('keeps shipment actions unavailable to read-only viewers', async () => {
  permissions.edit = false;
  show(delivery);
  expect(await screen.findByText('Shipment items', { exact: false })).toBeVisible();
  expect(screen.queryByRole('button', { name: /Arrange shipment|Dispatch shipment|Upload customer receipt/ })).not.toBeInTheDocument();
});
