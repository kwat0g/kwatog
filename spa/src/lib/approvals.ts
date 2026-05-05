/**
 * Sprint P3 — normalize backend approval shapes into the canonical
 * ApprovalStep[] consumed by <ApprovalTimeline>.
 *
 * Different backends represent approvals differently:
 *   • PR/PO/Loan: an `approval_records[]` array (one row per step) returned
 *     verbatim by the backend (rich shape with role_slug, approver, action,
 *     overdue flags…).
 *   • Leave: denormalized columns (`dept_approver`, `dept_approved_at`,
 *     `hr_approver`, `hr_approved_at`, plus the top-level `status`).
 *
 * `fromApprovalRecords` maps the rich shape; `fromLeaveRequest` synthesises
 * the 2-step Dept → HR view from the leave columns.
 */
import type { ApprovalAction, ApprovalStep } from '@/types/chain';
import type { LeaveRequest } from '@/types/leave';

/** Rich `approval_records[]` row shape returned by PR/PO/Loan resources. */
export interface ApprovalRecordPayload {
  step_order: number;
  role_slug: string;
  action: ApprovalAction;
  remarks: string | null;
  acted_at: string | null;
  approver?: { id: string; name: string } | null;
  is_overdue?: boolean;
  overdue_hours?: number | null;
}

/** Convert "purchasing_officer" → "Purchasing officer". */
function humanizeRoleSlug(slug: string): string {
  if (!slug) return '';
  const cleaned = slug.replace(/_/g, ' ').trim();
  return cleaned.charAt(0).toUpperCase() + cleaned.slice(1);
}

export function fromApprovalRecords(records: ApprovalRecordPayload[] | undefined): ApprovalStep[] {
  if (!records?.length) return [];
  return records.map((r) => ({
    step_order: r.step_order,
    role: humanizeRoleSlug(r.role_slug),
    approver_name: r.approver?.name ?? null,
    action: r.action,
    acted_at: r.acted_at,
    remarks: r.remarks,
    is_overdue: r.is_overdue ?? false,
    overdue_hours: r.overdue_hours ?? null,
  }));
}

/**
 * Synthesize a 2-step Dept Head → HR timeline from the leave request's
 * denormalized columns.
 */
export function fromLeaveRequest(req: LeaveRequest): ApprovalStep[] {
  const status = req.status;
  const isRejected = status === 'rejected';
  const isCancelled = status === 'cancelled';

  const deptAction: ApprovalAction = req.dept_approved_at
    ? 'approved'
    : isRejected && !req.hr_approved_at
      ? 'rejected'
      : isCancelled
        ? 'skipped'
        : status === 'pending_dept'
          ? 'pending'
          : 'pending';

  const hrAction: ApprovalAction =
    status === 'approved'
      ? 'approved'
      : isRejected && req.dept_approved_at
        ? 'rejected'
        : isCancelled
          ? 'skipped'
          : status === 'pending_hr'
            ? 'pending'
            : 'pending';

  const deptRemarks =
    deptAction === 'rejected' ? req.rejection_reason : null;
  const hrRemarks =
    hrAction === 'rejected' ? req.rejection_reason : null;

  return [
    {
      step_order: 1,
      role: 'Department head',
      approver_name: req.dept_approver?.name ?? null,
      action: deptAction,
      acted_at: req.dept_approved_at,
      remarks: deptRemarks,
    },
    {
      step_order: 2,
      role: 'HR Officer',
      approver_name: req.hr_approver?.name ?? null,
      action: hrAction,
      acted_at: req.hr_approved_at,
      remarks: hrRemarks,
    },
  ];
}
