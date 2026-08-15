import { describe, expect, it } from 'vitest';
import { buildPrChainSteps } from './detail';
import type { PurchaseRequest } from '@/types/purchasing';

const makePr = (approval_records: PurchaseRequest['approval_records'], status: PurchaseRequest['status'] = 'pending') => ({
 date: '2026-08-13',
 status,
 submitted_at: '2026-08-13T08:00:00Z',
 approved_at: null,
 approval_records,
} as PurchaseRequest);

describe('purchase request approval chain', () => {
 it('renders rejected and subsequent steps as terminal instead of pending or active', () => {
  const steps = buildPrChainSteps(makePr([
   { step_order: 1, role_slug: 'department_head', action: 'approved', acted_at: '2026-08-13T09:00:00Z', remarks: 'Within budget' },
   { step_order: 2, role_slug: 'finance_officer', action: 'rejected', acted_at: '2026-08-13T10:00:00Z', remarks: 'Budget code is missing' },
   { step_order: 3, role_slug: 'system_admin', action: 'pending', acted_at: null, remarks: null },
  ]));

  expect(steps.find((step) => step.key === 'step-1')?.state).toBe('done');
  expect(steps.find((step) => step.key === 'step-2')).toMatchObject({
   state: 'rejected',
   date: 'Aug 13, 2026',
   description: expect.stringContaining('Budget code is missing'),
  });
  expect(steps.find((step) => step.key === 'step-3')).toMatchObject({
   state: 'skipped',
   description: expect.stringContaining('earlier rejection'),
  });
 });

 it('preserves explicit skipped and current pending behavior before rejection', () => {
  const steps = buildPrChainSteps(makePr([
   { step_order: 1, role_slug: 'department_head', action: 'skipped', acted_at: '2026-08-13T09:00:00Z', remarks: 'Auto-resolved' },
   { step_order: 2, role_slug: 'finance_officer', action: 'pending', acted_at: null, remarks: null },
  ]));

  expect(steps.find((step) => step.key === 'step-1')).toMatchObject({
   state: 'skipped',
   description: expect.stringContaining('Auto-resolved'),
  });
  expect(steps.find((step) => step.key === 'step-2')?.state).toBe('active');
 });
});
