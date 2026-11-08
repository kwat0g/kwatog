import { describe, expect, it } from 'vitest';
import { realtimeSignalInvalidationMap } from './invalidationMap';

describe('audit log realtime invalidation', () => {
  it('refreshes all audit log list and entity trail queries', () => {
    const map = realtimeSignalInvalidationMap as Record<string, readonly unknown[]>;

    expect(map.admin_audit_logs_changed).toEqual([['admin', 'audit-logs']]);
  });
});
