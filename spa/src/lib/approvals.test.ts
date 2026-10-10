import { describe, expect, it } from 'vitest';
import type { LeaveRequest } from '@/types/leave';
import { fromLeaveRequest } from './approvals';

const cancelled: LeaveRequest = {
  id: 'leave-1', leave_request_no: 'LR-202610-0001',
  employee: { id: 'employee-1', employee_no: 'DEMO-0009', full_name: 'Manuel Cruz', department: 'Production' },
  leave_type: { id: 'vl', code: 'VL', name: 'Vacation Leave' },
  start_date: '2026-10-13', end_date: '2026-10-13', days: '1.0',
  half_day_period: null, reason: null, has_document: false, status: 'cancelled',
  dept_approver: null, dept_approved_at: null, hr_approver: null, hr_approved_at: null,
  rejection_reason: null, created_at: '2026-10-10T06:00:00+08:00', updated_at: '2026-10-10T06:30:00+08:00',
};

describe('cancelled leave approval history', () => {
  it('preserves both approvals when approved leave is cancelled', () => {
    const steps = fromLeaveRequest({
      ...cancelled,
      dept_approver: { id: 'head', name: 'Roberto Santos' },
      dept_approved_at: '2026-10-10T06:28:00+08:00',
      hr_approver: { id: 'hr', name: 'Maria Santos' },
      hr_approved_at: '2026-10-10T06:29:00+08:00',
    });
    expect(steps.map(step => step.action)).toEqual(['approved', 'approved']);
    expect(steps[1]).toMatchObject({ approver_name: 'Maria Santos', acted_at: '2026-10-10T06:29:00+08:00' });
  });

  it('skips only HR when leave is cancelled after department approval', () => {
    expect(fromLeaveRequest({
      ...cancelled, dept_approved_at: '2026-10-10T06:28:00+08:00',
    }).map(step => step.action)).toEqual(['approved', 'skipped']);
  });

  it('skips both unperformed steps when pending leave is cancelled', () => {
    expect(fromLeaveRequest(cancelled).map(step => step.action)).toEqual(['skipped', 'skipped']);
  });
});
