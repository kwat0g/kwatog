import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import React from 'react';

const { listen, leave, privateChannel } = vi.hoisted(() => {
  const listen = vi.fn().mockReturnThis();
  const stopListening = vi.fn().mockReturnThis();
  const leave = vi.fn();
  const privateChannel = vi.fn(() => ({ listen, stopListening }));
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

import { useBadges } from './useBadges';

function wrapper({ children }: { children: React.ReactNode }) {
  const qc = new QueryClient();
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

describe('useBadges real-time', () => {
  beforeEach(() => vi.clearAllMocks());

  it('subscribes to the private badges channel and listens for BadgesChanged', async () => {
    renderHook(() => useBadges(), { wrapper });
    // Subscription happens once the lazily-imported Echo client resolves.
    await waitFor(() => expect(privateChannel).toHaveBeenCalledWith('badges'));
    expect(listen).toHaveBeenCalledWith('.BadgesChanged', expect.any(Function));
  });
});
