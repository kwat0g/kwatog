import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { driverApi } from '@/api/driver';
import type { DriverDelivery } from '@/types/driver';
import DriverDeliveryDetail from './DriverDeliveryDetail';
import DriverPhotoCapture from './DriverPhotoCapture';

vi.mock('@/api/driver', () => ({ driverApi: { showDelivery: vi.fn(), updateStatus: vi.fn(), uploadReceipt: vi.fn(), reportAttempt: vi.fn(), amendAttempt: vi.fn() } }));
vi.mock('react-hot-toast', () => ({ default: { success: vi.fn(), error: vi.fn() } }));

const delivery: DriverDelivery = {
  id: 'dr-1', delivery_number: 'DR-202610-0001', status: 'in_transit',
  next_status: 'delivered', next_status_label: 'Delivered', scheduled_date: '2026-10-10',
  departed_at: null, delivered_at: null, confirmed_at: null,
  sales_order: { id: 'so-1', so_number: 'SO-202610-0025', customer: { id: 'customer-1', name: 'Acme' } },
  vehicle: null, carrier: 'Ogami truck', expected_delivery_date: '2026-10-11', proofs: [], proof_count: 0,
};

function show(data: DriverDelivery, photo = false) {
  vi.mocked(driverApi.showDelivery).mockResolvedValue(data);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(<QueryClientProvider client={client}><MemoryRouter initialEntries={[`/driver/dr-1${photo ? '/photo' : ''}`]}>
    <Routes>
      <Route path="/driver/:id" element={<DriverDeliveryDetail />} />
      <Route path="/driver/:id/photo" element={<DriverPhotoCapture />} />
    </Routes>
  </MemoryRouter></QueryClientProvider>);
}

beforeEach(() => {
  vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: vi.fn().mockReturnValue('blob:receipt'), revokeObjectURL: vi.fn() }));
});
afterEach(() => { cleanup(); vi.clearAllMocks(); vi.unstubAllGlobals(); });

it.each(['scheduled', 'loading'] as const)('leaves %s preparation and dispatch to the office', async (status) => {
  show({ ...delivery, status, next_status: status === 'scheduled' ? 'loading' : 'in_transit', next_status_label: status === 'scheduled' ? 'Loading' : 'In transit' });
  expect(await screen.findByText(/The office records the loading photo and dispatches this delivery/)).toBeVisible();
  expect(screen.queryByRole('button', { name: /Mark |Record delivery|Capture receipt photo/ })).not.toBeInTheDocument();
  expect(driverApi.updateStatus).not.toHaveBeenCalled();
});

it('records delivery through photo capture while preserving the problem report', async () => {
  show({ ...delivery, can_report_attempt_outcome: true, items: [{ id: 'item-1', quantity: '3', product: { name: 'Bracket', part_number: 'BR-1' } }] });
  expect(await screen.findByRole('button', { name: 'Report delivery problem' })).toBeVisible();
  expect(screen.getByText('Ogami truck')).toBeVisible();
  expect(screen.getByText('Oct 11, 2026')).toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'Record delivery' }));
  expect(await screen.findByRole('button', { name: 'Take photo' })).toBeVisible();
  expect(screen.getByRole('button', { name: 'Record delivery' })).toBeDisabled();
  expect(driverApi.updateStatus).not.toHaveBeenCalled();
});

it('keeps loading proof separate from the receipt and offers historical receipt upload', async () => {
  show({ ...delivery, status: 'delivered', next_status: null, proof_count: undefined, proofs: [{ id: 'loading-1', proof_type: 'loading_photo', file_name: 'loading.jpg', uploaded_at: null }] });
  expect(await screen.findByRole('button', { name: 'Capture receipt photo' })).toBeVisible();
  expect(screen.getByText(/Waiting for the customer to confirm receipt/)).toBeVisible();
  expect(screen.getByText('Not uploaded')).toBeVisible();
  expect(screen.queryByRole('button', { name: /Mark |Record delivery/ })).not.toBeInTheDocument();
});

it('uploads actual evidence before recording delivery', async () => {
  vi.mocked(driverApi.uploadReceipt).mockResolvedValue({ ...delivery, status: 'delivered' });
  const view = show(delivery, true);
  expect(await screen.findByRole('button', { name: 'Record delivery' })).toBeDisabled();
  const photo = new File(['photo'], 'receipt.jpg', { type: 'image/jpeg' });
  const input = view.container.querySelector('input[capture="environment"]');
  expect(input).not.toBeNull();
  fireEvent.change(input!, { target: { files: [photo] } });
  expect(screen.getByRole('img', { name: 'Receipt preview' })).toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'Record delivery' }));
  await waitFor(() => expect(driverApi.uploadReceipt).toHaveBeenCalledWith('dr-1', photo));
  expect(await screen.findByText('Delivery details')).toBeVisible();
});

it('rejects files above 8 MB without uploading', async () => {
  const view = show(delivery, true);
  await screen.findByRole('button', { name: 'Take photo' });
  const photo = new File([new Uint8Array(8 * 1024 * 1024 + 1)], 'large.jpg', { type: 'image/jpeg' });
  fireEvent.change(view.container.querySelector('input[capture="environment"]')!, { target: { files: [photo] } });
  expect(screen.getByRole('status')).toHaveTextContent('Maximum 8 MB.');
  expect(screen.getByRole('button', { name: 'Record delivery' })).toBeDisabled();
  expect(driverApi.uploadReceipt).not.toHaveBeenCalled();
});

it('blocks receipt upload before office dispatch even on the direct capture route', async () => {
  show({ ...delivery, status: 'loading' }, true);
  expect(await screen.findByText(/The office must dispatch this delivery before you record receipt/)).toBeVisible();
  expect(screen.queryByRole('button', { name: 'Take photo' })).not.toBeInTheDocument();
  expect(driverApi.uploadReceipt).not.toHaveBeenCalled();
});

it.each(['delivered', 'confirmed'] as const)('uploads historical %s evidence without another delivery action', async (status) => {
  show({ ...delivery, status }, true);
  expect(await screen.findByRole('button', { name: 'Upload receipt photo' })).toBeDisabled();
  expect(screen.queryByRole('button', { name: 'Record delivery' })).not.toBeInTheDocument();
});

it('retains the selected photo after upload failure for retry', async () => {
  vi.mocked(driverApi.uploadReceipt).mockRejectedValue(new Error('Connection lost'));
  const view = show(delivery, true);
  await screen.findByRole('button', { name: 'Take photo' });
  const photo = new File(['photo'], 'receipt.jpg', { type: 'image/jpeg' });
  fireEvent.change(view.container.querySelector('input[capture="environment"]')!, { target: { files: [photo] } });
  fireEvent.click(screen.getByRole('button', { name: 'Record delivery' }));
  await waitFor(() => expect(screen.getByRole('button', { name: 'Record delivery' })).toBeEnabled());
  expect(screen.getByRole('img', { name: 'Receipt preview' })).toBeVisible();
  expect(driverApi.uploadReceipt).toHaveBeenCalledWith('dr-1', photo);
});
