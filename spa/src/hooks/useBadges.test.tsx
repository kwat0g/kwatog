import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import React from 'react';

const { listen, stopListening, leave } = vi.hoisted(() => {
  const listen = vi.fn().mockReturnThis();
  const stopListening = vi.fn().mockReturnThis();
  const leave = vi.fn();
  return { listen, stopListening, leave };
});

vi.mock('@/lib/echo', () => ({
  echo: {
    private: vi.fn(() => ({ listen, stopListening })),
    leave,
    leaveChannel: leave,
  },
}));
vi.mock('@/api/badges', () => ({
  badgesApi: { get: vi.fn().mockResolvedValue({}) },
}));

import { useBadges } from './useBadges';
import { echo } from '@/lib/echo';

function wrapper({ children }: { children: React.ReactNode }) {
  const qc = new QueryClient();
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

describe('useBadges real-time', () => {
  beforeEach(() => vi.clearAllMocks());

  it('subscribes to the private badges channel and listens for BadgesChanged', () => {
    renderHook(() => useBadges(), { wrapper });
    expect(echo.private).toHaveBeenCalledWith('badges');
    expect(listen).toHaveBeenCalledWith('.BadgesChanged', expect.any(Function));
  });
});
