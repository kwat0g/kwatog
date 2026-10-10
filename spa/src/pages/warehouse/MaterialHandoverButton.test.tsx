import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import { MaterialHandoverButton } from './MaterialHandoverButton';
import { stockMovementsApi } from '@/api/inventory/stock';

vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true }) }));
vi.mock('@/api/inventory/stock', () => ({
  stockMovementsApi: { handoverOptions: vi.fn(), handover: vi.fn() },
}));

it('records selected recipient against the existing issue quantity and preserves failed form', async () => {
  vi.mocked(stockMovementsApi.handoverOptions).mockResolvedValue({
    quantity: '3.000',
    recipients: [{ value: 'employee-1', label: 'Catherine Rivera' }],
  });
  vi.mocked(stockMovementsApi.handover).mockRejectedValue({
    response: { data: { message: 'Issue changed. Reload.' } },
  });
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <MaterialHandoverButton movementId="issue-1" />
    </QueryClientProvider>,
  );
  fireEvent.click(screen.getByRole('button', { name: 'Record handover' }));
  const select = await screen.findByRole('combobox', { name: 'Received by' });
  await waitFor(() => expect(select).toBeEnabled());
  fireEvent.focus(select);
  fireEvent.click(await screen.findByRole('option', { name: 'Catherine Rivera' }));
  fireEvent.click(screen.getByRole('button', { name: 'Confirm handover' }));
  expect(await screen.findByText('Issue changed. Reload.')).toBeVisible();
  expect(stockMovementsApi.handover).toHaveBeenCalledWith('issue-1', {
    received_by_employee_id: 'employee-1',
    expected_quantity: '3.000',
  });
  expect(select).toHaveValue('Catherine Rivera');
});
