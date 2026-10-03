import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { approvalDelegationsApi, type ApprovalDelegation } from '@/api/approval-delegations';
import ApprovalDelegationsPage from './delegations';

vi.mock('@/api/approval-delegations', () => ({
  approvalDelegationsApi: {
    list: vi.fn(),
    candidates: vi.fn(),
    create: vi.fn(),
    revoke: vi.fn(),
  },
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: (select: (state: unknown) => unknown) => select({
    user: { id: 'actor-1', role: { slug: 'finance_officer' } },
  }),
}));

const candidate = { id: 'delegate-1', name: 'Morgan Lee', role: 'Employee' };

const delegation: ApprovalDelegation = {
  id: 'delegation-1',
  role_slug: 'finance_officer',
  starts_at: '2026-10-03',
  ends_at: '2026-10-10',
  reason: 'Annual leave',
  is_active: true,
  role_is_current: true,
  can_revoke: true,
  created_at: null,
  updated_at: null,
  delegator: { id: 'actor-1', name: 'Avery Worker', is_active: true },
  delegate: { id: 'delegate-1', name: 'Morgan Lee', is_active: true },
};

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <ApprovalDelegationsPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('approval cover', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(approvalDelegationsApi.list).mockResolvedValue([]);
    vi.mocked(approvalDelegationsApi.candidates).mockResolvedValue([candidate]);
    vi.mocked(approvalDelegationsApi.create).mockResolvedValue(delegation);
    vi.mocked(approvalDelegationsApi.revoke).mockResolvedValue({ ...delegation, is_active: false });
  });

  it('creates a role-scoped date-window delegation after selecting an active user', async () => {
    renderPage();

    fireEvent.change(screen.getByLabelText('Search active colleagues'), { target: { value: 'Morgan' } });
    await screen.findByRole('option', { name: 'Morgan Lee — Employee' });
    fireEvent.change(screen.getByLabelText(/Delegate/), { target: { value: 'delegate-1' } });
    fireEvent.change(screen.getByLabelText(/Start date/), { target: { value: '2026-10-03' } });
    fireEvent.change(screen.getByLabelText(/End date/), { target: { value: '2026-10-10' } });
    fireEvent.change(screen.getByLabelText('Reason (optional)'), { target: { value: 'Annual leave' } });
    fireEvent.click(screen.getByRole('button', { name: 'Set up approval cover' }));

    await waitFor(() => expect(approvalDelegationsApi.create).toHaveBeenCalledWith({
      delegate_user_id: 'delegate-1',
      role_slug: 'finance_officer',
      starts_at: '2026-10-03',
      ends_at: '2026-10-10',
      reason: 'Annual leave',
    }));
  });

  it('requires confirmation before revoking a delegation', async () => {
    vi.mocked(approvalDelegationsApi.list).mockResolvedValue([delegation]);
    renderPage();

    const row = await screen.findByRole('article', { name: /Morgan Lee/i });
    fireEvent.click(within(row).getByRole('button', { name: 'Revoke' }));

    const dialog = await screen.findByRole('dialog', { name: 'Revoke approval cover?' });
    expect(within(dialog).getByText(/Morgan Lee/)).toBeInTheDocument();
    expect(approvalDelegationsApi.revoke).not.toHaveBeenCalled();

    fireEvent.click(within(dialog).getByRole('button', { name: 'Revoke cover' }));
    await waitFor(() => expect(approvalDelegationsApi.revoke).toHaveBeenCalledWith('delegation-1'));
  });

  it('explains that an inactive delegator has stopped the cover', async () => {
    vi.mocked(approvalDelegationsApi.list).mockResolvedValue([{
      ...delegation,
      can_revoke: false,
      delegator: { id: 'delegator-1', name: 'Riley Chen', is_active: false },
    }]);
    renderPage();

    const row = await screen.findByRole('article', { name: 'Approval cover from Riley Chen' });
    expect(within(row).getByText('Delegator inactive')).toBeInTheDocument();
    expect(within(row).getByText(/administrator must reactivate the delegator or revoke the cover/i)).toBeInTheDocument();
  });

  it('explains that a role change has stopped the cover', async () => {
    vi.mocked(approvalDelegationsApi.list).mockResolvedValue([
      Object.assign({}, delegation, { role_is_current: false }),
    ]);
    renderPage();

    const row = await screen.findByRole('article', { name: /Morgan Lee/i });
    expect(within(row).getByText('Role changed')).toBeInTheDocument();
    expect(within(row).getByText(/revoke this cover and set up a new one for the current role/i)).toBeInTheDocument();
  });
});
