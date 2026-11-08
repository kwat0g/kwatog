import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { expect, it, vi } from 'vitest';
import { trainingsApi } from '@/api/hr/trainings';
import type { Training } from '@/types/hr';
import TrainingListPage from './list';

vi.mock('@/api/hr/trainings', () => ({ trainingsApi: { list: vi.fn(), delete: vi.fn(), restore: vi.fn() } }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true }) }));
vi.mock('@/hooks/useRealtimeRecord', () => ({ useRealtimeList: vi.fn() }));

const training = {
  id: 'training-1', name: 'Forklift Safety', description: null, duration_hours: 4,
  validity_months: 12, is_certification: true, is_active: true, department: null,
  created_at: '2026-11-03T12:00:00.000Z', updated_at: '2026-11-03T12:00:00.000Z',
  deleted_at: null, version: '2026-11-03T12:00:00.000000Z',
  permissions: { view: true, manage: true }, available_actions: ['update', 'archive'],
} satisfies Training;

it('disables an open archive confirmation when the latest server actions remove archive', async () => {
  vi.mocked(trainingsApi.list).mockResolvedValue({
    data: [training], meta: { current_page: 1, from: 1, last_page: 1, per_page: 25, to: 1, total: 1 },
    links: { first: '', last: '', prev: null, next: null },
  });
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter><TrainingListPage /></MemoryRouter></QueryClientProvider>);

  fireEvent.click((await screen.findAllByRole('button', { name: 'Archive Forklift Safety' }))[0]);
  expect(screen.getByRole('button', { name: 'Archive' })).toBeEnabled();
  const query = client.getQueryCache().findAll({ queryKey: ['hr', 'trainings'] })[0];
  expect(query).toBeDefined();

  await act(async () => {
    client.setQueryData(query!.queryKey, {
      data: [{ ...training, available_actions: [] }],
      meta: { current_page: 1, from: 1, last_page: 1, per_page: 25, to: 1, total: 1 },
    });
  });

  await waitFor(() => expect(screen.getByRole('button', { name: 'Archive' })).toBeDisabled());
  fireEvent.click(screen.getByRole('button', { name: 'Archive' }));
  expect(trainingsApi.delete).not.toHaveBeenCalled();
  client.clear();
});
