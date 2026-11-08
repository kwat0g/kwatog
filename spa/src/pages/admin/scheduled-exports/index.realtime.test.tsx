import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { expect, it, vi } from 'vitest';
import { scheduledExportsApi } from '@/api/exports';
import type { ScheduledExport } from '@/types/exports';
import ScheduledExportsPage from './index';

vi.mock('@/api/exports', () => ({ scheduledExportsApi: { list: vi.fn(), destroy: vi.fn(), restore: vi.fn(), update: vi.fn() } }));
vi.mock('@/hooks/useRealtimeRecord', () => ({ useRealtimeList: vi.fn() }));
vi.mock('@/lib/realtime/invalidationMap', () => ({ invalidateRealtimeChange: vi.fn(async () => undefined) }));
vi.mock('@/lib/undoToast', () => ({ showUndoToast: vi.fn() }));
vi.mock('@/components/exports/ScheduledExportFormModal', () => ({ ScheduledExportFormModal: () => null }));

const schedule = {
  id: 'export-1', name: 'Weekly employee report', module: 'hr.employees', columns: ['name'],
  filters: {}, format: 'csv' as const, frequency: 'weekly' as const, day_of_week: 1,
  day_of_month: null, time_of_day: '08:00', recipients: ['hr@example.test'],
  last_run_at: null, last_attempt_at: null, last_error: null, processing: false,
  next_run_at: '2026-11-09T08:00:00.000Z', is_active: true, owner: null,
  available_actions: { update: true, archive: true, restore: false },
  created_at: '2026-11-03T12:00:00.000Z',
} satisfies ScheduledExport;

it('disables an open archive confirmation when the current list no longer contains its schedule', async () => {
  vi.mocked(scheduledExportsApi.list).mockResolvedValue({
    data: [schedule], meta: { current_page: 1, from: 1, last_page: 1, per_page: 25, to: 1, total: 1 },
    links: { first: '', last: '', prev: null, next: null },
  });
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter><ScheduledExportsPage /></MemoryRouter></QueryClientProvider>);

  fireEvent.click((await screen.findAllByRole('button', { name: 'Archive' }))[0]);
  const dialogArchive = () => screen.getAllByRole('button', { name: 'Archive' }).at(-1)!;
  expect(dialogArchive()).toBeEnabled();
  const query = client.getQueryCache().findAll({ queryKey: ['scheduled-exports'] })[0];
  expect(query).toBeDefined();

  await act(async () => {
    client.setQueryData(query!.queryKey, {
      data: [], meta: { current_page: 1, from: null, last_page: 1, per_page: 25, to: null, total: 0 },
    });
  });

  await waitFor(() => expect(dialogArchive()).toBeDisabled());
  fireEvent.click(dialogArchive());
  expect(scheduledExportsApi.destroy).not.toHaveBeenCalled();
  client.clear();
});

it('disables an open archive confirmation when the current server action is removed', async () => {
  vi.mocked(scheduledExportsApi.list).mockResolvedValue({
    data: [schedule], meta: { current_page: 1, from: 1, last_page: 1, per_page: 25, to: 1, total: 1 },
    links: { first: '', last: '', prev: null, next: null },
  });
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter><ScheduledExportsPage /></MemoryRouter></QueryClientProvider>);

  fireEvent.click((await screen.findAllByRole('button', { name: 'Archive' }))[0]);
  const dialogArchive = () => screen.getAllByRole('button', { name: 'Archive' }).at(-1)!;
  const query = client.getQueryCache().findAll({ queryKey: ['scheduled-exports'] })[0];
  expect(query).toBeDefined();

  await act(async () => {
    client.setQueryData(query!.queryKey, {
      data: [{ ...schedule, available_actions: { update: false, archive: false, restore: true } }],
      meta: { current_page: 1, from: 1, last_page: 1, per_page: 25, to: 1, total: 1 },
    });
  });

  await waitFor(() => expect(dialogArchive()).toBeDisabled());
  fireEvent.click(dialogArchive());
  expect(scheduledExportsApi.destroy).not.toHaveBeenCalled();
  client.clear();
});

it('removes an archived schedule and updates the actor count immediately after success', async () => {
  vi.mocked(scheduledExportsApi.list).mockResolvedValue({
    data: [schedule], meta: { current_page: 1, from: 1, last_page: 1, per_page: 25, to: 1, total: 1 },
    links: { first: '', last: '', prev: null, next: null },
  });
  vi.mocked(scheduledExportsApi.destroy).mockResolvedValue({} as never);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter><ScheduledExportsPage /></MemoryRouter></QueryClientProvider>);

  fireEvent.click((await screen.findAllByRole('button', { name: 'Archive' }))[0]);
  fireEvent.click(screen.getAllByRole('button', { name: 'Archive' }).at(-1)!);

  await waitFor(() => expect(screen.getByText('0 schedules')).toBeInTheDocument());
  expect(screen.getByText('No scheduled exports yet')).toBeInTheDocument();
  expect(scheduledExportsApi.destroy).toHaveBeenCalledWith(schedule.id);
  client.clear();
});
