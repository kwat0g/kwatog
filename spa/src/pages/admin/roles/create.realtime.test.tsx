import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { rolesApi, type Role } from '@/api/admin/roles';
import CreateRolePage from './create';

vi.mock('@/api/admin/roles', () => ({
  rolesApi: { create: vi.fn(), clone: vi.fn(), list: vi.fn() },
}));
vi.mock('@/hooks/useFormSafety', () => ({
  useFormSafety: () => ({
    allowNavigationAfterSave: vi.fn(),
    draftState: { hasDraft: false, draftAge: 0, restore: vi.fn(), discard: vi.fn() },
  }),
}));
vi.mock('@/components/ui/FormDraftBanner', () => ({ FormDraftBanner: () => null }));
vi.mock('react-hot-toast', () => ({ default: { success: vi.fn(), error: vi.fn() } }));

const createdRole = {
  id: 'role-new',
  name: 'Shift Supervisor',
  slug: 'shift_supervisor',
  description: null,
  is_system: false,
  type: 'Custom',
  users_count: 0,
  permissions_count: 0,
  last_modified_by: 'Admin',
  last_modified_at: '2026-11-03T12:00:00.000Z',
  created_at: '2026-11-03T12:00:00.000Z',
  updated_at: '2026-11-03T12:00:00.000Z',
} satisfies Role;

describe('Role create freshness', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(rolesApi.create).mockResolvedValue(createdRole);
  });

  it('seeds the role editor detail cache from the create response before navigation', async () => {
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/admin/roles/create']}>
          <Routes>
            <Route path="/admin/roles/create" element={<CreateRolePage />} />
            <Route path="/admin/roles/:id/permissions" element={<span>Role permission editor</span>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    fireEvent.change(screen.getByRole('textbox', { name: 'Role name' }), {
      target: { value: createdRole.name },
    });
    fireEvent.change(screen.getByRole('textbox', { name: 'Slug' }), {
      target: { value: createdRole.slug },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Create role' }));

    await waitFor(() => expect(rolesApi.create).toHaveBeenCalledOnce());
    expect(await screen.findByText('Role permission editor')).toBeVisible();
    await waitFor(() => expect(queryClient.getQueryData(['admin', 'role', createdRole.id]))
      .toEqual(createdRole));
    queryClient.clear();
  });
});
