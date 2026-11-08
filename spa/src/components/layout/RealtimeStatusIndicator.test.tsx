import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { RealtimeStatusIndicator } from './RealtimeStatusIndicator';

const realtime = vi.hoisted(() => ({
  value: { state: 'live' as 'live' | 'reconnecting' | 'offline', lastUpdatedAt: null as number | null },
}));

vi.mock('@/lib/realtime/context', () => ({
  useRealtimeStatus: () => realtime.value,
}));

describe('RealtimeStatusIndicator', () => {
  beforeEach(() => {
    realtime.value = { state: 'live', lastUpdatedAt: null };
  });

  it('announces the live state accessibly', () => {
    render(<RealtimeStatusIndicator />);
    expect(screen.getByRole('status')).toHaveTextContent('Live');
  });

  it('shows reconnecting and offline states without relying on color alone', () => {
    realtime.value = { state: 'reconnecting', lastUpdatedAt: null };
    const { rerender } = render(<RealtimeStatusIndicator />);
    expect(screen.getByRole('status')).toHaveTextContent('Reconnecting');

    realtime.value = { state: 'offline', lastUpdatedAt: 1_798_000_000_000 };
    rerender(<RealtimeStatusIndicator />);
    expect(screen.getByRole('status')).toHaveTextContent('Offline');
    expect(screen.getByRole('status')).toHaveAttribute('title', expect.stringContaining('Last updated'));
  });
});
