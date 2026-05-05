/**
 * Sprint P1 — centralized chain-step builder for Leave Requests.
 *
 * Hire-to-Retire chain (Leave subdomain):
 *   Submitted → Department head → HR → Approved → Deducted
 *
 * Deducted advances when the leave is consumed by a finalized payroll period
 * (currently inferred from `status === 'approved'` because the payroll-period
 * link is not yet exposed on the LeaveRequest resource — TODO once Sprint A3
 * ships the auto-deduction relation).
 */
import type { ChainStep } from '@/types/chain';
import type { LeaveRequest } from '@/types/leave';

export function buildLeaveChain(req: LeaveRequest): ChainStep[] {
  const status = req.status;
  const isCancelled = status === 'cancelled';
  const isRejected = status === 'rejected';

  // For cancelled/rejected the chain becomes "submitted → terminated".
  if (isRejected || isCancelled) {
    return [
      { key: 'submitted', label: 'Submitted', state: 'done', date: req.created_at?.slice(0, 10) },
      {
        key: 'dept',
        label: 'Department head',
        state: req.dept_approved_at ? 'done' : 'pending',
        date: req.dept_approved_at?.slice(0, 10),
      },
      {
        key: 'hr',
        label: 'HR',
        state: req.hr_approved_at ? 'done' : 'pending',
        date: req.hr_approved_at?.slice(0, 10),
      },
      {
        key: 'closed',
        label: isRejected ? 'Rejected' : 'Cancelled',
        state: 'done',
        date: req.updated_at?.slice(0, 10),
      },
    ];
  }

  return [
    { key: 'submitted', label: 'Submitted', state: 'done', date: req.created_at?.slice(0, 10) },
    {
      key: 'dept',
      label: 'Department head',
      state: req.dept_approved_at ? 'done' : status === 'pending_dept' ? 'active' : 'pending',
      date: req.dept_approved_at?.slice(0, 10),
    },
    {
      key: 'hr',
      label: 'HR',
      state: req.hr_approved_at ? 'done' : status === 'pending_hr' ? 'active' : 'pending',
      date: req.hr_approved_at?.slice(0, 10),
    },
    {
      key: 'approved',
      label: 'Approved',
      state: status === 'approved' ? 'done' : 'pending',
      date: status === 'approved' ? req.hr_approved_at?.slice(0, 10) : undefined,
    },
    {
      key: 'deducted',
      label: 'Deducted',
      // TODO: derive from payroll-period link once exposed (A3).
      state: 'pending',
    },
  ];
}
