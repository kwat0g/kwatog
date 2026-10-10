import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { expect, it, vi } from 'vitest';
import type { ReturnCaseSourceOptions } from '@/types/returnCases';
import { returnCasesApi } from '@/api/returnCases';
import ReturnCaseCreatePage from './create';

vi.mock('@/api/returnCases', () => ({
  returnCasesApi: {
    findSources: vi.fn(),
    sourceOptions: vi.fn(),
    create: vi.fn(),
    upload: vi.fn(),
  },
}));
vi.mock('@/hooks/usePermission', () => ({
  usePermission: () => ({
    can: (permission: string) =>
      ['inventory.grn.create', 'return_management.view'].includes(permission),
  }),
}));

it('lets warehouse report the prefilled GRN shortage without settlement controls', async () => {
  vi.mocked(returnCasesApi.sourceOptions).mockResolvedValue({
    source: { kind: 'grn', id: 'grn-1', label: 'GRN-202610-0001' },
    type: 'supplier',
    party: { id: 'vendor-1', name: 'Asia Pacific Polymers' },
    lines: [
      {
        id: 'line-1',
        part_number: 'PKG-001',
        description: 'Poly bag',
        unit: 'pcs',
        expected_quantity: '20000.000',
        received_quantity: '18000.000',
        maximum_quantity: '20000.000',
        lot_number: null,
        can_return: true,
      },
    ],
  });
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter
        initialEntries={['/return-management/cases/new?source_kind=grn&source_id=grn-1']}
      >
        <Routes>
          <Route path="/return-management/cases/new" element={<ReturnCaseCreatePage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );

  await screen.findByText('GRN-202610-0001');
  fireEvent.click(screen.getByRole('checkbox', { name: /PKG-001/ }));
  expect(screen.getByLabelText('Expected for this shipment')).toHaveValue('20000.000');
  expect(screen.getByLabelText('Expected for this shipment')).toHaveAttribute('readonly');
  expect(screen.getByLabelText('Actually received')).toHaveValue('18000.000');
  expect(screen.getByText(/2000.000 pcs/)).toBeInTheDocument();
  expect(screen.queryByLabelText('Damaged or defective')).not.toBeInTheDocument();
  expect(screen.queryByText('Preferred outcome')).not.toBeInTheDocument();
  expect(screen.getByText(/Purchasing reviews this report/)).toBeInTheDocument();
  client.clear();
});

const customerSource: ReturnCaseSourceOptions = {
  source: { kind: 'delivery', id: 'delivery-1', label: 'DR-202610-0001' },
  type: 'customer', party: { id: 'customer-1', name: 'Toyota' },
  lines: [{ id: 'line-498', part_number: 'PC-001', description: 'Pivot Cap Cover Type A', unit: 'PCS',
    expected_quantity: '498.000', received_quantity: '498.000', maximum_quantity: '498.000',
    lot_number: 'WO-202610-0004-B01', can_return: true }],
};

async function renderCustomer() {
  vi.mocked(returnCasesApi.sourceOptions).mockResolvedValue(customerSource);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter initialEntries={['/portal/customer/problems/new?source_kind=delivery&source_id=delivery-1']}>
    <Routes><Route path="/portal/customer/problems/new" element={<ReturnCaseCreatePage />} /></Routes>
  </MemoryRouter></QueryClientProvider>);
  fireEvent.click(await screen.findByRole('checkbox', { name: /PC-001/ }));
  fireEvent.change(screen.getByLabelText(/Explain the problem/), { target: { value: 'One cover is cracked.' } });
  return client;
}

it.each([
  ['Actually received', '499', 'Received quantity cannot exceed expected quantity.'],
  ['Actually received', '-1', 'Enter a non-negative number with up to 3 decimal places.'],
  ['Actually received', '1e2', 'Enter a non-negative number with up to 3 decimal places.'],
  ['Actually received', '', 'Enter a non-negative number with up to 3 decimal places.'],
  ['Actually received', '497.5', 'Enter a whole number of pieces.'],
  ['Damaged or defective', '499', 'Damaged quantity cannot exceed received quantity.'],
  ['Damaged or defective', '0.5', 'Enter a whole number of pieces.'],
])('validates %s %s immediately and prevents submission', async (label, value, error) => {
  const create = vi.mocked(returnCasesApi.create).mockClear();
  const client = await renderCustomer();
  fireEvent.change(screen.getByLabelText(label), { target: { value } });
  expect(screen.getByText(error)).toHaveClass('text-danger-fg');
  expect(screen.getByLabelText(label)).toHaveAttribute('aria-invalid', 'true');
  expect(screen.getByRole('button', { name: 'Submit report' })).toBeDisabled();
  expect(create).not.toHaveBeenCalled();
  client.clear();
});

it('revalidates damaged count when received changes and restores valid submission', async () => {
  const client = await renderCustomer();
  fireEvent.change(screen.getByLabelText('Damaged or defective'), { target: { value: '3' } });
  expect(screen.getByRole('button', { name: 'Submit report' })).toBeEnabled();
  fireEvent.change(screen.getByLabelText('Actually received'), { target: { value: '2' } });
  expect(screen.getByText('Damaged quantity cannot exceed received quantity.')).toHaveClass('text-danger-fg');
  expect(screen.getByRole('button', { name: 'Submit report' })).toBeDisabled();
  fireEvent.change(screen.getByLabelText('Damaged or defective'), { target: { value: '1' } });
  expect(screen.queryByText('Damaged quantity cannot exceed received quantity.')).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Submit report' })).toBeEnabled();
  client.clear();
});

it('locks source quantity and lot, preserves legitimate counts and prevents double submission', async () => {
  const create = vi.mocked(returnCasesApi.create).mockClear().mockImplementation(() => new Promise(() => {}));
  const client = await renderCustomer();
  const expected = screen.getByLabelText('Expected');
  const lot = screen.getByLabelText('Lot number');
  expect(expected).toHaveAttribute('readonly');
  expect(lot).toHaveAttribute('readonly');
  fireEvent.change(expected, { target: { value: '999' } });
  fireEvent.change(lot, { target: { value: 'fake-lot' } });
  expect(expected).toHaveValue('498.000');
  expect(lot).toHaveValue('WO-202610-0004-B01');
  fireEvent.change(screen.getByLabelText('Damaged or defective'), { target: { value: '1' } });
  const submit = screen.getByRole('button', { name: 'Submit report' });
  fireEvent.click(submit);
  fireEvent.click(submit);
  await waitFor(() => expect(create).toHaveBeenCalledTimes(1));
  expect(create.mock.calls[0][1].lines).toEqual([{ source_line_id: 'line-498', received_quantity: '498.000',
    defective_quantity: '1.000', lot_number: 'WO-202610-0004-B01', serial_number: undefined, reason: undefined }]);
  client.clear();
});
