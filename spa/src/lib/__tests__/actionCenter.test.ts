import { describe, expect, it } from 'vitest';
import { filterActionItems } from '@/lib/actionCenter';
import type { ActionCenterItem } from '@/types/actionCenter';

const item = (overrides: Partial<ActionCenterItem>): ActionCenterItem => ({
  id: 'alert:al1',
  category: 'alert',
  kind: 'stock_critical',
  title: 'Resin stock is critical',
  description: 'Available stock is below the configured safety level',
  reference: null,
  priority: 'critical',
  status_label: 'New alert',
  link: '/alerts',
  created_at: '2026-07-28T00:00:00Z',
  due_at: null,
  age_hours: 25,
  is_overdue: true,
  owner_label: null,
  task_state: 'open',
  assigned_to: null,
  updated_by: null,
  snoozed_until: null,
  ...overrides,
});

describe('filterActionItems', () => {
  const items = [
    item({}),
    item({
      id: 'quality:ncr:2',
      category: 'quality',
      title: 'Resolve NCR NCR-002',
      reference: 'NCR-002',
      owner_label: 'Juan Cruz',
    }),
  ];

  it('filters by category', () => {
    expect(filterActionItems(items, 'quality', '')).toEqual([items[1]]);
  });

  it('searches titles, references, descriptions, owners, and statuses case-insensitively', () => {
    expect(filterActionItems(items, 'all', 'resin')).toEqual([items[0]]);
    expect(filterActionItems(items, 'all', 'ncr-002')).toEqual([items[1]]);
  });
});
