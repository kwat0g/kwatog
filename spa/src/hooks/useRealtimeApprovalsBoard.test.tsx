import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook, waitFor } from '@testing-library/react';
import type { PropsWithChildren } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAuthStore } from '@/stores/authStore';

const realtime = vi.hoisted(() => ({ registration: undefined as unknown[] | undefined }));

vi.mock('./useRealtimeEvent', () => ({
  useRealtimeEvent: (...args: unknown[]) => {
    realtime.registration = args;
  },
}));

import { useRealtimeApprovalsBoard } from './useRealtimeApprovalsBoard';

describe('useRealtimeApprovalsBoard', () => {
  beforeEach(() => {
    realtime.registration = undefined;
    useAuthStore.setState({
      user: {
        id: 'vp-viewer',
        role: { id: 'role-vp', slug: 'vice_president' },
        permissions: ['approvals.board.view'],
        features: [],
      } as unknown as NonNullable<ReturnType<typeof useAuthStore.getState>['user']>,
    });
  });

  it('subscribes to the scoped approval signal and invalidates board queries on change', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    queryClient.setQueryData(['approvals', 'board', 'all', 100, 50], { summary: { my_action: 1 } });
    const wrapper = ({ children }: PropsWithChildren) => (
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
    );
    const { unmount } = renderHook(() => useRealtimeApprovalsBoard(), { wrapper });

    expect(realtime.registration?.slice(0, 2)).toEqual(['badge-user.vp-viewer', '.BadgesChanged']);
    const onChanged = realtime.registration?.[2] as ((payload: unknown) => void) | undefined;
    expect(onChanged).toBeTypeOf('function');

    act(() => onChanged?.({ refresh: true }));
    await waitFor(() => {
      expect(queryClient.getQueryState(['approvals', 'board', 'all', 100, 50])?.isInvalidated).toBe(true);
    });

    unmount();
    queryClient.clear();
    useAuthStore.setState({ user: null });
  });
});
