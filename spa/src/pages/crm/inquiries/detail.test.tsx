import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, expect, it, vi } from 'vitest';
import { inquiriesApi } from '@/api/crm/inquiries';
import { realtimeIdentityKey } from '@/lib/realtime/identity';
import { useAuthStore } from '@/stores/authStore';
import type { ContactInquiry } from '@/types/crm';
import InquiryDetailPage from './detail';

vi.mock('@/api/crm/inquiries', () => ({ inquiriesApi: { show: vi.fn(), updateStatus: vi.fn() } }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true }) }));
vi.mock('@/hooks/useRealtimeSignal', () => ({ useRealtimeSignal: vi.fn() }));

const inquiry: ContactInquiry = {
  id: 'inquiry-1', inquiry_no: 'CI-2026-001', full_name: 'Ana Santos', company: 'Example Co.',
  email: 'ana@example.test', phone: null, message: 'Please contact me.', status: 'new', status_label: 'New',
  version: '2026-11-02T08:00:00.000000Z', permissions: { manage: true },
  available_actions: { mark_in_progress: true, close: true },
  ip_address: null, user_agent: null, created_at: '2026-11-02T08:00:00Z', updated_at: '2026-11-02T08:00:00Z',
};

function open() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/crm/inquiries/inquiry-1']}>
        <Routes><Route path="/crm/inquiries/:id" element={<InquiryDetailPage />} /></Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
  return queryClient;
}

beforeEach(() => {
  vi.resetAllMocks();
  useAuthStore.setState({ user: null });
  vi.mocked(inquiriesApi.show).mockResolvedValue(inquiry);
});

it('renders the status and actions from the inquiry query', async () => {
  open();
  expect(await screen.findByText('New')).toBeVisible();
  expect(screen.getByRole('button', { name: 'Close' })).toBeVisible();
  expect(screen.getByRole('button', { name: 'Mark in progress' })).toBeVisible();
});

it('fails closed when the API denies an action even if the status would otherwise allow it', async () => {
  vi.mocked(inquiriesApi.show).mockResolvedValue({
    ...inquiry,
    available_actions: { mark_in_progress: false, close: false },
  });
  open();

  expect(await screen.findByText('New')).toBeVisible();
  expect(screen.queryByRole('button', { name: 'Mark in progress' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Close' })).not.toBeInTheDocument();
});

it('shows the actor the status returned by the API while the follow-up read is pending', async () => {
  const closed = {
    ...inquiry, status: 'closed' as const, status_label: 'Closed', updated_at: '2026-11-02T09:00:00Z',
    version: '2026-11-02T09:00:00.000000Z',
    available_actions: { mark_in_progress: false, close: false },
  };
  vi.mocked(inquiriesApi.show)
    .mockResolvedValueOnce(inquiry)
    .mockImplementation(() => new Promise(() => {}));
  vi.mocked(inquiriesApi.updateStatus).mockResolvedValue(closed);
  open();

  fireEvent.click(await screen.findByRole('button', { name: 'Close' }));

  await waitFor(() => expect(inquiriesApi.updateStatus).toHaveBeenCalledWith('inquiry-1', 'closed', 'new'));
  expect(await screen.findByText('Closed')).toBeVisible();
  expect(screen.queryByRole('button', { name: 'Close' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Mark in progress' })).not.toBeInTheDocument();
});

it('refetches the authorized inquiry after a stale-state conflict', async () => {
  const closed = {
    ...inquiry,
    status: 'closed' as const,
    status_label: 'Closed',
    available_actions: { mark_in_progress: false, close: false },
  };
  vi.mocked(inquiriesApi.show).mockResolvedValueOnce(inquiry).mockResolvedValueOnce(closed);
  vi.mocked(inquiriesApi.updateStatus).mockRejectedValue(Object.assign(new Error('State conflict'), {
    response: { status: 409, data: { message: 'This contact inquiry changed. Refresh and review the current status.' } },
  }));
  open();

  fireEvent.click(await screen.findByRole('button', { name: 'Close' }));

  expect(await screen.findByText('Closed')).toBeVisible();
  expect(inquiriesApi.show).toHaveBeenCalledTimes(2);
  expect(screen.queryByRole('button', { name: 'Mark in progress' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Close' })).not.toBeInTheDocument();
});

it('keeps permission-dependent inquiry data in separate identity-scoped detail entries', async () => {
  const userA = {
    id: 'user-a',
    role: { id: 'role-a', slug: 'crm-admin' },
    permissions: new Set(['crm.inquiries.view', 'crm.inquiries.manage']),
    features: new Set(['crm']),
  };
  const userB = {
    id: 'user-b',
    role: { id: 'role-b', slug: 'crm-reader' },
    permissions: new Set(['crm.inquiries.view']),
    features: new Set(['crm']),
  };
  const privileged = { ...inquiry, ip_address: '192.0.2.10', user_agent: 'Privileged browser' };
  const redacted = { ...inquiry, ip_address: null, user_agent: null, permissions: { manage: false } };
  vi.mocked(inquiriesApi.show).mockResolvedValueOnce(privileged).mockResolvedValueOnce(redacted);
  useAuthStore.setState({ user: userA as never });
  const queryClient = open();
  const keyA = ['crm', 'inquiries', inquiry.id, realtimeIdentityKey(userA)];
  const keyB = ['crm', 'inquiries', inquiry.id, realtimeIdentityKey(userB)];

  expect(await screen.findByText('192.0.2.10')).toBeVisible();
  expect(queryClient.getQueryData(keyA)).toEqual(privileged);
  act(() => useAuthStore.setState({ user: userB as never }));
  await waitFor(() => expect(inquiriesApi.show).toHaveBeenCalledTimes(2));
  await waitFor(() => expect(queryClient.getQueryData(keyB)).toEqual(redacted));
  expect(queryClient.getQueryData(keyA)).toEqual(privileged);
});
