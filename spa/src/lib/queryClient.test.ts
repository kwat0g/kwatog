import { describe, expect, it } from 'vitest';
import { queryClient } from './queryClient';
import { isRealtimeManagedQueryKey } from './realtime/queryFreshness';

describe('shared query cache freshness', () => {
  it('treats server data as stale on remount unless a query opts into a longer class-specific lifetime', () => {
    expect(queryClient.getDefaultOptions().queries?.staleTime).toBe(0);
  });

  it('uses the central realtime map to distinguish live data from static option caches', () => {
    expect(isRealtimeManagedQueryKey(['kpi', 'scorecard', 2026, 10])).toBe(true);
    expect(isRealtimeManagedQueryKey(['accounting', 'dashboard', 'summary'])).toBe(true);
    expect(isRealtimeManagedQueryKey(['inventory', 'items', 'options'])).toBe(false);
  });
});
