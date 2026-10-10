import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import { workOrdersApi } from '@/api/maintenance/workOrders';
import CreateMaintenanceWorkOrderPage from './create';

vi.mock('@/api/maintenance/workOrders', () => ({
  workOrdersApi: { options: vi.fn(), targets: vi.fn(), create: vi.fn() },
}));

afterEach(() => {
  cleanup();
  vi.useRealTimers();
  vi.resetAllMocks();
});

it('requests matching maintenance targets after one 300 ms search delay', async () => {
  vi.mocked(workOrdersApi.options).mockResolvedValue({
    maintainable_types: [{ value: 'machine', label: 'Machine' }],
    types: [], priorities: [], statuses: [], default_type: '', default_priority: '',
  });
  vi.mocked(workOrdersApi.targets).mockResolvedValue({
    data: [{ id: 'machine-1', code: 'IMM-01', name: 'Press' }],
  });
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter><CreateMaintenanceWorkOrderPage /></MemoryRouter>
    </QueryClientProvider>,
  );

  await screen.findByRole('option', { name: 'Machine' });
  const targetGroup = screen.getByRole('group', { name: 'Target' });
  fireEvent.change(within(targetGroup).getByRole('combobox', { name: 'Type' }), {
    target: { value: 'machine' },
  });
  await waitFor(() => expect(workOrdersApi.targets).toHaveBeenCalledWith('machine', ''));
  const target = screen.getByRole('combobox', { name: 'Target' });
  fireEvent.focus(target);
  await screen.findByRole('option', { name: 'IMM-01 - Press' });
  vi.mocked(workOrdersApi.targets).mockClear();

  vi.useFakeTimers();
  fireEvent.change(target, { target: { value: 'IMM' } });
  act(() => vi.advanceTimersByTime(299));
  expect(workOrdersApi.targets).not.toHaveBeenCalledWith('machine', 'IMM');
  await act(async () => vi.advanceTimersByTime(1));
  expect(workOrdersApi.targets).toHaveBeenCalledExactlyOnceWith('machine', 'IMM');
});
