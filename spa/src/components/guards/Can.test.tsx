/**
 * WS-C — Frontend RBAC primitives: <Can> declarative guard.
 *
 * Locks the contract for the codemod target that will replace the inline
 * `can('xxx') && <Button…/>` pattern across the SPA.
 */
import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Can } from './Can';
import { useAuthStore } from '@/stores/authStore';
import type { AuthUser } from '@/api/auth';

const renderWithUser = (perms: string[], roleSlug = 'finance_officer') => {
  const fakeUser: AuthUser = {
    id: 'U1',
    name: 'T',
    email: 't@x.test',
    is_active: true,
    must_change_password: false,
    theme_mode: 'light',
    sidebar_collapsed: false,
    role: { id: 'R1', name: 'Finance Officer', slug: roleSlug },
    permissions: perms,
    features: ['hr', 'accounting'],
  } as unknown as AuthUser;

  useAuthStore.setState({
    user: fakeUser,
    permissions: new Set(perms),
    features: new Set(['hr', 'accounting']),
    isAuthenticated: true,
    isLoading: false,
  });
};

describe('<Can>', () => {
  beforeEach(() => {
    useAuthStore.setState({
      user: null,
      permissions: new Set(),
      features: new Set(),
      isAuthenticated: false,
      isLoading: false,
    });
  });

  it('renders children when the user holds the permission', () => {
    renderWithUser(['accounting.invoices.create']);
    render(<Can permission="accounting.invoices.create">create-invoice</Can>);
    expect(screen.getByText('create-invoice')).toBeInTheDocument();
  });

  it('renders nothing when the user lacks the permission', () => {
    renderWithUser(['accounting.invoices.view']);
    render(<Can permission="accounting.invoices.create">create-invoice</Can>);
    expect(screen.queryByText('create-invoice')).not.toBeInTheDocument();
  });

  it('always renders for system_admin regardless of slug', () => {
    renderWithUser([], 'system_admin');
    render(<Can permission="anything.deeply.nested">visible</Can>);
    expect(screen.getByText('visible')).toBeInTheDocument();
  });

  it('renders the fallback prop when permission is missing', () => {
    renderWithUser([]);
    render(
      <Can permission="x.y" fallback={<span>nope</span>}>
        gone
      </Can>,
    );
    expect(screen.getByText('nope')).toBeInTheDocument();
    expect(screen.queryByText('gone')).not.toBeInTheDocument();
  });
});

describe('<Can.Any>', () => {
  beforeEach(() => {
    useAuthStore.setState({
      user: null,
      permissions: new Set(),
      features: new Set(),
      isAuthenticated: false,
      isLoading: false,
    });
  });

  it('renders children when ANY of the listed permissions is held', () => {
    renderWithUser(['leave.approve_dept']);
    render(
      <Can.Any permissions={['leave.approve_hr', 'leave.approve_dept']}>
        approve
      </Can.Any>,
    );
    expect(screen.getByText('approve')).toBeInTheDocument();
  });

  it('renders nothing when NONE are held', () => {
    renderWithUser(['hr.employees.view']);
    render(
      <Can.Any permissions={['leave.approve_hr', 'leave.approve_dept']}>
        approve
      </Can.Any>,
    );
    expect(screen.queryByText('approve')).not.toBeInTheDocument();
  });
});

describe('<Can.All>', () => {
  beforeEach(() => {
    useAuthStore.setState({
      user: null,
      permissions: new Set(),
      features: new Set(),
      isAuthenticated: false,
      isLoading: false,
    });
  });

  it('renders children only when ALL listed permissions are held', () => {
    renderWithUser(['payroll.periods.compute', 'payroll.periods.approve']);
    render(
      <Can.All permissions={['payroll.periods.compute', 'payroll.periods.approve']}>
        finalize
      </Can.All>,
    );
    expect(screen.getByText('finalize')).toBeInTheDocument();
  });

  it('renders nothing when one is missing', () => {
    renderWithUser(['payroll.periods.compute']);
    render(
      <Can.All permissions={['payroll.periods.compute', 'payroll.periods.approve']}>
        finalize
      </Can.All>,
    );
    expect(screen.queryByText('finalize')).not.toBeInTheDocument();
  });
});
