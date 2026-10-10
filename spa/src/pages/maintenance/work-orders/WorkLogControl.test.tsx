import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { WorkLogControl } from './RecoveryPanel';
import { workOrdersApi } from '@/api/maintenance/workOrders';
import type { MaintenanceWorkOrder } from '@/types/maintenance';

vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true }) }));
vi.mock('@/api/maintenance/workOrders', () => ({ workOrdersApi: { recordWork: vi.fn() } }));

afterEach(() => { cleanup(); vi.resetAllMocks(); });

it('records finding, action, and result from a modal', async () => {
  const order = { id: 'mwo-8', status: 'in_progress', available_actions: ['complete'] } as MaintenanceWorkOrder;
  vi.mocked(workOrdersApi.recordWork).mockResolvedValue({ id: 'log-1', finding: 'Guards intact', action_taken: 'Cleaned guards', result: 'passed' });
  render(<QueryClientProvider client={new QueryClient()}><WorkLogControl order={order} /></QueryClientProvider>);

  expect(screen.queryByRole('textbox', { name: 'Finding' })).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Record work' }));
  fireEvent.change(screen.getByRole('textbox', { name: 'Finding' }), { target: { value: 'Guards intact' } });
  fireEvent.change(screen.getByRole('textbox', { name: 'Action taken' }), { target: { value: 'Cleaned guards' } });
  fireEvent.change(screen.getByRole('combobox', { name: 'Result' }), { target: { value: 'passed' } });
  fireEvent.click(screen.getByRole('button', { name: 'Save work record' }));

  await waitFor(() => expect(workOrdersApi.recordWork).toHaveBeenCalledWith('mwo-8', {
    finding: 'Guards intact', action_taken: 'Cleaned guards', result: 'passed',
  }));
  await waitFor(() => expect(screen.queryByRole('textbox', { name: 'Finding' })).not.toBeInTheDocument());
});
