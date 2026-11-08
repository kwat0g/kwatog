import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook, waitFor } from '@testing-library/react';
import { createElement, type ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';

const { subscribeMock, getNotificationMock } = vi.hoisted(() => ({
  subscribeMock: vi.fn(),
  getNotificationMock: vi.fn(),
}));

vi.mock('./useRealtimeEvent', () => ({ useRealtimeEvent: subscribeMock }));
vi.mock('@/api/notifications', () => ({ getNotificationById: getNotificationMock }));

import { useNotificationRealtime } from './useNotificationRealtime';
import { useAuthStore } from '@/stores/authStore';

afterEach(() => {
  vi.clearAllMocks();
  useAuthStore.setState({ user: null });
});

it('uses the portal principal channel and invalidates only that portal inbox without fetching internal notice data', async () => {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const invalidate = vi.spyOn(queryClient, 'invalidateQueries').mockResolvedValue();
  const queryKey = ['portal', 'customer', 'customer-hash', 'notifications'];
  const wrapper = ({ children }: { children: ReactNode }) => createElement(QueryClientProvider, { client: queryClient }, children);

  renderHook(() => useNotificationRealtime({
    channel: 'portal.customer.user.customer-hash',
    queryKey,
    showToast: false,
  }), { wrapper });

  expect(subscribeMock).toHaveBeenCalledWith(
    'portal.customer.user.customer-hash',
    '.portal.notification.changed',
    expect.any(Function),
    expect.any(Function),
    expect.any(Function),
  );

  const onEvent = subscribeMock.mock.calls[0][2] as (payload: { action: string; id?: string; type?: string }) => void;
  await act(async () => onEvent({ action: 'created', id: 'portal-notice-1', type: 'purchase_order.published' }));

  await waitFor(() => expect(invalidate.mock.calls.some(([filters]) => filters?.predicate?.({ queryKey } as never))).toBe(true));
  expect(getNotificationMock).not.toHaveBeenCalled();
  queryClient.clear();
});

it('subscribes internal users to read/delete signals so other tabs refresh their inbox', async () => {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const invalidate = vi.spyOn(queryClient, 'invalidateQueries').mockResolvedValue();
  const queryKey = ['notifications', 'user-hash'];
  const wrapper = ({ children }: { children: ReactNode }) => createElement(QueryClientProvider, { client: queryClient }, children);
  useAuthStore.setState({ user: { id: 'user-hash' } as never });

  renderHook(() => useNotificationRealtime({ queryKey }), { wrapper });

  expect(subscribeMock).toHaveBeenNthCalledWith(
    1,
    'user.user-hash',
    '.notification.created',
    expect.any(Function),
    expect.any(Function),
    expect.any(Function),
  );
  expect(subscribeMock).toHaveBeenNthCalledWith(
    2,
    'user.user-hash',
    '.notification.changed',
    expect.any(Function),
    expect.any(Function),
    expect.any(Function),
  );

  const onChanged = subscribeMock.mock.calls[1][2] as () => void;
  await act(async () => onChanged());

  await waitFor(() => expect(invalidate.mock.calls.some(([filters]) => filters?.predicate?.({ queryKey } as never))).toBe(true));
  expect(getNotificationMock).not.toHaveBeenCalled();
  useAuthStore.setState({ user: null });
  queryClient.clear();
});
