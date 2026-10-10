import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { expect, it, vi } from 'vitest';
import WarehouseScannerPage from './index';
import { scannerApi } from '@/api/inventory/scanner';
vi.mock('@/api/inventory/scanner', () => ({
  scannerApi: { options: vi.fn(), records: vi.fn(), resolve: vi.fn() },
}));
it('locks barcode and context during lookup and preserves them on failure', async () => {
  vi.mocked(scannerApi.options).mockResolvedValue({
    contexts: [
      { value: 'lookup', label: 'Lookup' },
      { value: 'wo_id', label: 'Work order' },
    ],
  });
  vi.mocked(scannerApi.records).mockResolvedValue([{ value: 'wo-1', label: 'WO-202610-0001' }]);
  let rejectLookup!: (error: unknown) => void;
  vi.mocked(scannerApi.resolve).mockImplementation(
    () =>
      new Promise((_, reject) => {
        rejectLookup = reject;
      }),
  );
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <MemoryRouter>
        <WarehouseScannerPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
  await screen.findByText('Work order');
  fireEvent.change(screen.getByLabelText('Workflow'), { target: { value: 'wo_id' } });
  fireEvent.focus(screen.getByRole('combobox', { name: 'Context record' }));
  fireEvent.click(await screen.findByRole('option', { name: 'WO-202610-0001' }));
  fireEvent.change(screen.getByLabelText('Barcode'), { target: { value: 'ITEM-1' } });
  await waitFor(() => expect(screen.getByRole('button', { name: 'Resolve' })).toBeEnabled());
  fireEvent.click(screen.getByRole('button', { name: 'Resolve' }));
  await waitFor(() => expect(screen.getByLabelText('Barcode')).toBeDisabled());
  expect(screen.getByLabelText('Workflow')).toBeDisabled();
  expect(screen.getByRole('combobox', { name: 'Context record' })).toBeDisabled();
  expect(scannerApi.resolve).toHaveBeenCalledWith('ITEM-1', { wo_id: 'wo-1' });
  rejectLookup({ response: { data: { message: 'Work order is unavailable.' } } });
  expect(await screen.findByText('Work order is unavailable.')).toBeVisible();
  expect(screen.getByLabelText('Barcode')).toHaveValue('ITEM-1');
  expect(screen.getByRole('combobox', { name: 'Context record' })).toHaveValue('WO-202610-0001');
  expect(screen.getByLabelText('Barcode')).toBeEnabled();
});

it('searches context numbers and clears selected record when workflow changes', async () => {
  vi.clearAllMocks();
  vi.mocked(scannerApi.options).mockResolvedValue({
    contexts: [
      { value: 'lookup', label: 'Lookup' },
      { value: 'wo_id', label: 'Work order' },
      { value: 'grn_id', label: 'Receiving' },
    ],
  });
  vi.mocked(scannerApi.records).mockResolvedValue([{ value: 'wo-1', label: 'WO-202610-0001' }]);
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <MemoryRouter>
        <WarehouseScannerPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
  await screen.findByText('Work order');
  expect(scannerApi.records).not.toHaveBeenCalled();
  fireEvent.change(screen.getByLabelText('Workflow'), { target: { value: 'wo_id' } });
  fireEvent.focus(screen.getByRole('combobox', { name: 'Context record' }));
  fireEvent.change(screen.getByRole('combobox', { name: 'Context record' }), {
    target: { value: 'WO-202610' },
  });
  await waitFor(() => expect(scannerApi.records).toHaveBeenCalledWith('wo_id', 'WO-202610'));
  fireEvent.click(await screen.findByRole('option', { name: 'WO-202610-0001' }));
  expect(screen.getByRole('combobox', { name: 'Context record' })).toHaveValue('WO-202610-0001');
  fireEvent.change(screen.getByLabelText('Workflow'), { target: { value: 'grn_id' } });
  expect(screen.getByRole('combobox', { name: 'Context record' })).toHaveValue('');
  expect(screen.queryByLabelText('Record ID')).not.toBeInTheDocument();
});
