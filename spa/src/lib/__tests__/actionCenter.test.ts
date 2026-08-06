import { describe, expect, it } from 'vitest';
import { filterActionItems } from '@/lib/actionCenter';
import type { ActionCenterItem } from '@/types/actionCenter';

const item = (overrides: Partial<ActionCenterItem>): ActionCenterItem => ({
 id: 'approval:pr:1',
 category: 'approval',
 kind: 'pr',
 title: 'Approve purchase request PR-001',
 description: 'Raw resin replenishment',
 reference: 'PR-001',
 priority: 'high',
 status_label: 'Waiting for you',
 link: '/purchasing/purchase-requests/1',
 created_at: '2026-07-28T00:00:00Z',
 due_at: null,
 age_hours: 25,
 is_overdue: true,
 owner_label: 'Maria Santos',
 task_state: 'open',
 assigned_to: null,
 snoozed_until: null,
 ...overrides,
});

describe('filterActionItems', () => {
 const items = [
 item({}),
 item({
 id: 'quality:ncr:2',
 category: 'quality',
 title: 'Resolve NCR-002',
 reference: 'NCR-002',
 owner_label: 'Juan Cruz',
 }),
 ];

 it('filters by category', () => {
 expect(filterActionItems(items, 'quality', '')).toEqual([items[1]]);
 });

 it('searches titles, references, descriptions, owners, and statuses case-insensitively', () => {
 expect(filterActionItems(items, 'all', 'maria')).toEqual([items[0]]);
 expect(filterActionItems(items, 'all', 'ncr-002')).toEqual([items[1]]);
 });
});
