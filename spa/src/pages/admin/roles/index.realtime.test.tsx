import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { expect, it, vi } from 'vitest';
import { rolesApi, type Role } from '@/api/admin/roles';
import RolesIndexPage from './index';

vi.mock('@/api/admin/roles', () => ({ rolesApi: { list: vi.fn(), delete: vi.fn(), restore: vi.fn() } }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true }) }));
vi.mock('@/components/guards/CanDo', () => ({ CanDo: ({ children }: { children: React.ReactNode }) => children }));
vi.mock('react-hot-toast', () => ({ default: { success: vi.fn(), error: vi.fn() } }));

const role = {
  id: 'role-1', name: 'Warehouse Supervisor', slug: 'warehouse_supervisor', description: null,
  is_system: false, type: 'Custom' as const, users_count: 0, permissions_count: 2,
  permissions: [], last_modified_by: null, last_modified_at: null,
  created_at: '2026-11-03T12:00:00.000Z', updated_at: '2026-11-03T12:00:00.000Z',
} satisfies Role;

it('disables an open archive confirmation when the latest role row is no longer archivable', async () => {
  vi.mocked(rolesApi.list).mockResolvedValue({
    data: [role], meta: { current_page: 1, from: 1, last_page: 1, per_page: 25, to: 1, total: 1 },
    links: { first: '', last: '', prev: null, next: null },
  });
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter><RolesIndexPage /></MemoryRouter></QueryClientProvider>);

  fireEvent.click((await screen.findAllByRole('button', { name: 'Delete Warehouse Supervisor' }))[0]);
  expect(screen.getByRole('button', { name: 'Archive' })).toBeEnabled();
  const query = client.getQueryCache().findAll({ queryKey: ['admin', 'roles'] })[0];
  expect(query).toBeDefined();

  await act(async () => {
    client.setQueryData(query!.queryKey, {
      data: [{ ...role, users_count: 1 }],
      meta: { current_page: 1, from: 1, last_page: 1, per_page: 25, to: 1, total: 1 },
    });
  });

  await waitFor(() => expect(screen.getByRole('button', { name: 'Archive' })).toBeDisabled());
  fireEvent.click(screen.getByRole('button', { name: 'Archive' }));
  expect(rolesApi.delete).not.toHaveBeenCalled();
  client.clear();
});
