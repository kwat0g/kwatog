import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import { schedulesApi } from '@/api/maintenance/schedules';
import CreateMaintenanceSchedulePage from './create';

vi.mock('@/api/maintenance/schedules', () => ({
  schedulesApi: { options: vi.fn(), targets: vi.fn(), create: vi.fn() },
}));

afterEach(() => {
  cleanup();
  vi.useRealTimers();
  vi.resetAllMocks();
});

function renderPage() {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter><CreateMaintenanceSchedulePage /></MemoryRouter>
    </QueryClientProvider>,
  );
}

function mockOptions() {
  vi.mocked(schedulesApi.options).mockResolvedValue({
    maintainable_types: [
      { value: 'machine', label: 'Machine' },
      { value: 'mold', label: 'Mold' },
    ],
    interval_types: [],
  });
}

it('requests matching schedule targets after one 300 ms search delay', async () => {
  mockOptions();
  vi.mocked(schedulesApi.targets).mockResolvedValue([
    { id: 'machine-1', code: 'IMM-01', name: 'Press' },
  ]);
  renderPage();

  await screen.findByRole('option', { name: 'Machine' });
  const targetGroup = screen.getByRole('group', { name: 'Target' });
  fireEvent.change(within(targetGroup).getByRole('combobox', { name: 'Type' }), {
    target: { value: 'machine' },
  });
  await waitFor(() => expect(schedulesApi.targets).toHaveBeenCalledWith('machine', ''));
  const target = screen.getByRole('combobox', { name: 'Target' });
  fireEvent.focus(target);
  await screen.findByRole('option', { name: 'IMM-01 - Press' });
  vi.mocked(schedulesApi.targets).mockClear();

  vi.useFakeTimers();
  fireEvent.change(target, { target: { value: 'IMM' } });
  act(() => vi.advanceTimersByTime(299));
  expect(schedulesApi.targets).not.toHaveBeenCalledWith('machine', 'IMM');
  await act(async () => vi.advanceTimersByTime(1));
  expect(schedulesApi.targets).toHaveBeenCalledExactlyOnceWith('machine', 'IMM');
});

it('clears the selected target when its maintainable type changes', async () => {
  mockOptions();
  vi.mocked(schedulesApi.targets).mockResolvedValue([
    { id: 'machine-1', code: 'IMM-01', name: 'Press' },
  ]);
  renderPage();

  await screen.findByRole('option', { name: 'Machine' });
  const targetGroup = screen.getByRole('group', { name: 'Target' });
  const type = within(targetGroup).getByRole('combobox', { name: 'Type' });
  fireEvent.change(type, { target: { value: 'machine' } });
  await waitFor(() => expect(schedulesApi.targets).toHaveBeenCalledWith('machine', ''));
  fireEvent.focus(screen.getByRole('combobox', { name: 'Target' }));
  fireEvent.click(await screen.findByRole('option', { name: 'IMM-01 - Press' }));
  expect(screen.getByRole('combobox', { name: 'Target' })).toHaveValue('IMM-01 - Press');

  fireEvent.change(type, { target: { value: 'mold' } });
  expect(screen.getByRole('combobox', { name: 'Target' })).toHaveValue('— Select Target —');
});
