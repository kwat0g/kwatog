import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import React from 'react';

const { listen, leave, privateChannel } = vi.hoisted(() => {
  const listen = vi.fn().mockReturnThis();
  const stopListening = vi.fn().mockReturnThis();
  const subscribed = vi.fn().mockReturnThis();
  const error = vi.fn().mockReturnThis();
  const leave = vi.fn();
  const privateChannel = vi.fn(() => ({ listen, stopListening, subscribed, error }));
  return { listen, leave, privateChannel };
});

// `@/lib/echo` resolves the client lazily so `laravel-echo` + `pusher-js` stay
// out of the entry chunk — mock the accessor, not a ready-made instance.
vi.mock('@/lib/echo', () => ({
  getEcho: vi.fn(() =>
    Promise.resolve({
      private: privateChannel,
      leave,
      leaveChannel: leave,
    }),
  ),
}));
vi.mock('@/api/badges', () => ({
  badgesApi: { get: vi.fn().mockResolvedValue({}) },
}));
vi.mock('@/stores/authStore', () => ({
  useAuthStore: (selector: (state: { user: { id: string } }) => unknown) =>
    selector({ user: { id: 'badge-user' } }),
}));

import { useBadges } from './useBadges';

function wrapper(qc: QueryClient) {
  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

describe('useBadges real-time', () => {
  beforeEach(() => vi.clearAllMocks());

  it('subscribes to the current user private badge channel and listens for BadgesChanged', async () => {
    const qc = new QueryClient();
    renderHook(() => useBadges(), { wrapper: wrapper(qc) });
    // Subscription happens once the lazily-imported Echo client resolves.
    await waitFor(() => expect(privateChannel).toHaveBeenCalledWith('badge-user.badge-user'));
    expect(listen).toHaveBeenCalledWith('.BadgesChanged', expect.any(Function));
    qc.clear();
  });

  it('does not schedule periodic badge polling while realtime delivery is healthy', async () => {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const { unmount } = renderHook(() => useBadges(), { wrapper: wrapper(qc) });

    await waitFor(() => expect(qc.getQueryData(['sidebar', 'badges'])).toBeDefined());

    const query = qc.getQueryCache().find({ queryKey: ['sidebar', 'badges'] });
    const observerOptions = query?.options as { refetchInterval?: number | false; staleTime?: number } | undefined;
    expect(observerOptions?.refetchInterval).toBeUndefined();
    expect(observerOptions?.staleTime).toBe(0);

    unmount();
    qc.clear();
  });
});
