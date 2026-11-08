import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const realtime = vi.hoisted(() => ({ registrations: [] as unknown[][] }));

vi.mock('./useRealtimeEvent', () => ({
  useRealtimeEvent: (...args: unknown[]) => {
    realtime.registrations.push(args);
  },
}));

vi.mock('@/api/badges', () => ({
  badgesApi: { get: vi.fn().mockResolvedValue({}) },
}));

import { useNotificationRealtime } from './useNotificationRealtime';
import { useBadges } from './useBadges';
import { usePermissionSync } from './usePermissionSync';
import { useCacheGroupRealtime } from './useRealtimeSignal';
import { useAuthStore } from '@/stores/authStore';

function withQueryClient(client: QueryClient) {
  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
}

describe('user-facing realtime subscription catch-up', () => {
  beforeEach(() => {
    realtime.registrations.length = 0;
    useAuthStore.setState({
      user: {
        id: 'viewer-1',
        role: { id: 'role-1', slug: 'finance_officer' },
        permissions: ['notifications.view'],
        features: [],
      } as unknown as NonNullable<ReturnType<typeof useAuthStore.getState>['user']>,
    });
  });

  it('refetches badge data after the private badge channel subscription is acknowledged', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const invalidate = vi.spyOn(client, 'invalidateQueries').mockResolvedValue();

    const { unmount } = renderHook(() => useBadges(), { wrapper: withQueryClient(client) });
    const onSubscribed = realtime.registrations[0]?.[3] as (() => void) | undefined;
    expect(onSubscribed).toBeTypeOf('function');

    act(() => onSubscribed?.());
    await waitFor(() => expect(invalidate.mock.calls.some(([filters]) => filters?.predicate?.({
      queryKey: ['sidebar', 'badges'],
    } as never))).toBe(true));

    unmount();
    client.clear();
  });

  it('refetches notifications after the user channel subscription is acknowledged', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const invalidate = vi.spyOn(client, 'invalidateQueries').mockResolvedValue();

    const { unmount } = renderHook(() => useNotificationRealtime(), { wrapper: withQueryClient(client) });
    const onSubscribed = realtime.registrations[0]?.[3] as (() => void) | undefined;
    expect(realtime.registrations[0]?.[0]).toBe('user.viewer-1');
    expect(realtime.registrations[0]?.[1]).toBe('.notification.created');
    expect(onSubscribed).toBeTypeOf('function');

    act(() => onSubscribed?.());
    await waitFor(() => expect(invalidate.mock.calls.some(([filters]) => filters?.predicate?.({
      queryKey: ['notifications', 'viewer-1'],
    } as never))).toBe(true));

    unmount();
    client.clear();
    useAuthStore.setState({ user: null });
  });

  it('refreshes the server-provided permission set when its private channel is ready or fails', () => {
    const refresh = vi.fn().mockResolvedValue(undefined);
    useAuthStore.setState({ refresh } as unknown as Partial<ReturnType<typeof useAuthStore.getState>>);

    const { unmount } = renderHook(() => usePermissionSync());
    const onSubscribed = realtime.registrations[0]?.[3] as (() => void) | undefined;
    const access = realtime.registrations.find(([channel, event]) => (
      channel === 'user.access.viewer-1' && event === '.user.access.changed'
    ));
    const onError = access?.[4] as ((error: unknown) => void) | undefined;
    expect(onSubscribed).toBeTypeOf('function');
    expect(onError).toBeTypeOf('function');

    act(() => onSubscribed?.());
    act(() => onError?.(new Error('channel authorization failed')));
    expect(refresh).toHaveBeenCalledTimes(2);
    unmount();
    useAuthStore.setState({ user: null });
  });

  it('refreshes the server-provided permission set after the role channel is acknowledged', () => {
    const refresh = vi.fn().mockResolvedValue(undefined);
    useAuthStore.setState({ refresh } as unknown as Partial<ReturnType<typeof useAuthStore.getState>>);

    const { unmount } = renderHook(() => usePermissionSync());
    const rolePermissions = realtime.registrations.find(([channel, event]) => (
      channel === 'role.role-1' && event === '.role.permissions.changed'
    ));
    const onSubscribed = rolePermissions?.[3] as (() => void) | undefined;

    expect(onSubscribed).toBeTypeOf('function');
    act(() => onSubscribed?.());
    expect(refresh).toHaveBeenCalledOnce();
    unmount();
    useAuthStore.setState({ user: null });
  });

  it('catches up display preferences when the private preferences channel is ready or fails', () => {
    const refresh = vi.fn().mockResolvedValue(undefined);
    useAuthStore.setState({ refresh } as unknown as Partial<ReturnType<typeof useAuthStore.getState>>);

    const { unmount } = renderHook(() => usePermissionSync());
    const preferences = realtime.registrations.find(([channel, event]) => (
      channel === 'user.preferences.viewer-1' && event === '.user.preferences.changed'
    ));
    const onSubscribed = preferences?.[3] as (() => void) | undefined;
    const onError = preferences?.[4] as ((error: unknown) => void) | undefined;

    expect(onSubscribed).toBeTypeOf('function');
    expect(onError).toBeTypeOf('function');
    act(() => onSubscribed?.());
    act(() => onError?.(new Error('channel authorization failed')));
    expect(refresh).toHaveBeenCalledTimes(2);
    unmount();
    useAuthStore.setState({ user: null });
  });

  it('catches up cache-backed queries when the cache-group channel is acknowledged', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const invalidate = vi.spyOn(client, 'invalidateQueries').mockResolvedValue();

    const { unmount } = renderHook(() => useCacheGroupRealtime('dashboards', true), {
      wrapper: withQueryClient(client),
    });
    const registration = realtime.registrations.find(([channel, event]) => (
      channel === 'cache-group.dashboards' && event === '.cache.group.invalidated'
    ));
    const onSubscribed = registration?.[3] as (() => void) | undefined;

    expect(onSubscribed).toBeTypeOf('function');
    act(() => onSubscribed?.());
    await waitFor(() => expect(invalidate).toHaveBeenCalled());
    unmount();
    client.clear();
  });
});
