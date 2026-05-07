/**
 * Series R — Task R3 — CanDo wrapper.
 *
 * Verifies single-permission, multi-permission (any/all), and fallback
 * behaviour by mocking the underlying authStore that usePermission reads.
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { CanDo } from './CanDo';
import { useAuthStore } from '@/stores/authStore';

function setStore(permissions: string[], roleSlug = 'employee') {
  useAuthStore.setState({
    user: {
      id: 'u1',
      name: 'Test User',
      email: 't@t.test',
      role: { id: 'r1', slug: roleSlug, name: roleSlug },
      permissions,
      features: [],
      employee: null,
      is_active: true,
      must_change_password: false,
      theme_mode: 'system',
      sidebar_collapsed: false,
    } as never,
    permissions: new Set(permissions),
    features: new Set(),
    isAuthenticated: true,
    isLoading: false,
  });
}

describe('CanDo', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
  });

  it('renders children when single permission is granted', () => {
    setStore(['hr.employees.view']);
    render(
      <CanDo permission="hr.employees.view">
        <button>Edit</button>
      </CanDo>,
    );
    expect(screen.getByText('Edit')).toBeInTheDocument();
  });

  it('renders fallback when single permission is missing', () => {
    setStore([]);
    render(
      <CanDo permission="hr.employees.view" fallback={<span>nope</span>}>
        <button>Edit</button>
      </CanDo>,
    );
    expect(screen.queryByText('Edit')).not.toBeInTheDocument();
    expect(screen.getByText('nope')).toBeInTheDocument();
  });

  it('renders children when ANY of multiple permissions is granted (default)', () => {
    setStore(['accounting.bills.view']);
    render(
      <CanDo permission={['accounting.bills.view', 'accounting.invoices.view']}>
        <span>finance</span>
      </CanDo>,
    );
    expect(screen.getByText('finance')).toBeInTheDocument();
  });

  it('hides children when requireAll=true and only some perms granted', () => {
    setStore(['hr.employees.view']); // missing edit
    render(
      <CanDo
        permission={['hr.employees.view', 'hr.employees.edit']}
        requireAll
      >
        <span>full HR</span>
      </CanDo>,
    );
    expect(screen.queryByText('full HR')).not.toBeInTheDocument();
  });

  it('treats system_admin role as having every permission', () => {
    setStore([], 'system_admin'); // empty permissions array but system_admin
    render(
      <CanDo permission="anything.anywhere">
        <span>admin sees</span>
      </CanDo>,
    );
    expect(screen.getByText('admin sees')).toBeInTheDocument();
  });
});
