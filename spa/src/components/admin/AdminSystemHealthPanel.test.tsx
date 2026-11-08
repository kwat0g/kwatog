import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { SystemHealth } from '@/api/admin/settings';
import { AdminSystemHealthPanel } from './AdminSystemHealthPanel';

const health: SystemHealth = {
  generated_at: '2026-11-02T10:00:00+00:00',
  realtime: {
    delivery_status: 'degraded',
    broadcast_queue_pending: 2,
    outbox_pending: 2,
    outbox_failed: 1,
    oldest_pending_seconds: 40,
  },
  reverb_tcp_status: 'reachable',
  redis_cache: {
    status: 'available',
    used_memory_bytes: 1_048_576,
    max_memory_bytes: 2_097_152,
  },
  redis_persistent: {
    status: 'available',
    used_memory_bytes: 16_777_216,
    max_memory_bytes: 268_435_456,
  },
  cache_audit: {
    status: 'attention',
    completed_at: '2026-11-02T04:10:00+00:00',
    entries: 20,
    without_ttl: 1,
    oversized: 0,
    unknown_size: 0,
  },
};

describe('AdminSystemHealthPanel', () => {
  it('surfaces realtime backlog, Reverb reachability, both Redis memory figures and cache-audit issues', () => {
    render(<AdminSystemHealthPanel health={health} isLoading={false} isError={false} onRetry={vi.fn()} />);

    expect(screen.getByText('Real-time delivery')).toBeInTheDocument();
    expect(screen.getByText('Degraded')).toBeInTheDocument();
    expect(screen.getByText('Reverb TCP')).toBeInTheDocument();
    expect(screen.getByText('Reachable')).toBeInTheDocument();
    expect(screen.getByText('2 queue pending · 2 outbox pending · 1 failed')).toBeInTheDocument();
    expect(screen.getByText('1.00 MiB / 2.00 MiB')).toBeInTheDocument();
    expect(screen.getByText('Persistent Redis memory')).toBeInTheDocument();
    expect(screen.getByText('16.00 MiB / 256.00 MiB')).toBeInTheDocument();
    expect(screen.getByText('Attention')).toBeInTheDocument();
    expect(screen.getByText('20 entries audited')).toBeInTheDocument();
    expect(screen.getByText('1 without TTL · 0 oversized · 0 size unknown')).toBeInTheDocument();
  });

  it('shows a retry action when system telemetry is unavailable', () => {
    const onRetry = vi.fn();
    render(<AdminSystemHealthPanel health={undefined} isLoading={false} isError onRetry={onRetry} />);

    screen.getByRole('button', { name: 'Retry health check' }).click();
    expect(onRetry).toHaveBeenCalledOnce();
  });

  it('tolerates a health response from an API that predates persistent Redis telemetry', () => {
    const legacyHealth = { ...health };
    Reflect.deleteProperty(legacyHealth, 'redis_persistent');
    render(<AdminSystemHealthPanel health={legacyHealth} isLoading={false} isError={false} onRetry={vi.fn()} />);

    expect(screen.getByText('Persistent Redis memory')).toBeInTheDocument();
    expect(screen.getByText('Unknown')).toBeInTheDocument();
  });
});
