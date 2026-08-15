/**
 * Sprint P3 — vertical approval workflow timeline.
 *
 * Renders one row per ApprovalStep with a colored dot + role + approver name
 * + action chip + ISO timestamp. The first pending step gets a subtle pulse
 * to draw attention to the current bottleneck.
 *
 * Used on every approvable record's detail page (Leave, Loan, PR, PO, …) and
 * also embedded in the printed approval form (P9).
 */
import { LuCheck, LuX, LuCircleMinus } from '@/lib/icons';
import { Chip } from '@/components/ui/Chip';
import { cn } from '@/lib/cn';
import { formatDateTime } from '@/lib/formatDate';
import type { ApprovalAction, ApprovalStep } from '@/types/chain';

interface ApprovalTimelineProps {
  steps: ApprovalStep[];
  className?: string;
  /**
   * If true and the active step has `is_overdue`, the row highlights with
   * a danger-tinted pulse. Defaults to true.
   */
  highlightOverdue?: boolean;
}

/**
 * Dot fill. The saturated tokens (--success / --danger / --accent) are the fills;
 * the pale `-bg` tints are chip surfaces meant to carry `-fg` ink, and using one
 * as a fill under white glyphs rendered the check mark at 1.21:1 in the office
 * palette. `text-accent-fg` is the paper-on-ink counterpart every palette declares.
 */
const dotClass = (action: ApprovalAction, isActive: boolean): string => {
  if (action === 'approved') return 'bg-success border-success text-accent-fg';
  if (action === 'rejected') return 'bg-danger border-danger text-accent-fg';
  if (action === 'skipped') return 'bg-elevated border-default text-muted';
  // pending
  return isActive
    ? 'bg-accent border-accent text-accent-fg'
    : 'bg-elevated border-default text-muted';
};

const chipForAction = (action: ApprovalAction) => {
  switch (action) {
    case 'approved':
      return { variant: 'success' as const, label: 'Approved' };
    case 'rejected':
      return { variant: 'danger' as const, label: 'Rejected' };
    case 'skipped':
      return { variant: 'neutral' as const, label: 'Skipped' };
    default:
      return { variant: 'warning' as const, label: 'Pending' };
  }
};

/** Find the index of the first pending step — the "active" bottleneck. */
function activeStepIndex(steps: ApprovalStep[]): number {
  return steps.findIndex((s) => s.action === 'pending');
}

export function ApprovalTimeline({
  steps,
  className,
  highlightOverdue = true,
}: ApprovalTimelineProps) {
  if (steps.length === 0) {
    return <p className={cn('text-sm text-muted', className)}>No approval workflow yet.</p>;
  }

  const activeIdx = activeStepIndex(steps);

  return (
    <ol className={cn('relative space-y-3', className)} aria-label="Approval timeline">
      {steps.map((step, i) => {
        const isActive = i === activeIdx;
        const chip = chipForAction(step.action);
        const showOverdue = !!(highlightOverdue && step.is_overdue && step.action === 'pending');
        return (
          <li key={`${step.step_order}-${step.role}`} className="flex items-start gap-3">
            <div className="relative flex flex-col items-center" aria-hidden>
              <span
                className={cn(
                  'inline-flex items-center justify-center w-5 h-5 rounded-full border shrink-0',
                  dotClass(step.action, isActive),
                  isActive && step.action === 'pending' && 'animate-approval-pulse',
                )}
              >
                {step.action === 'approved' && <LuCheck size={11} strokeWidth={3} />}
                {step.action === 'rejected' && <LuX size={11} strokeWidth={3} />}
                {step.action === 'skipped' && <LuCircleMinus size={11} />}
              </span>
              {i < steps.length - 1 && (
                <span
                  className={cn(
                    'w-px flex-1 mt-1 mb-1 min-h-[18px]',
                    // Saturated to match the dot above it. The pale --success-bg tint
                    // sat at ~1.06:1 on canvas — a connector nobody could see.
                    step.action === 'approved' ? 'bg-success' : 'bg-border-default',
                  )}
                />
              )}
            </div>
            <div className="min-w-0 flex-1 pb-1">
              <div className="flex items-center gap-2 flex-wrap">
                <span
                  className={cn(
                    'text-sm',
                    step.action !== 'pending' || isActive
                      ? 'text-primary font-medium'
                      : 'text-muted',
                  )}
                >
                  Step {step.step_order} — {step.role}
                </span>
                <Chip variant={chip.variant}>{chip.label}</Chip>
                {showOverdue && (
                  <span title={`Overdue by ${step.overdue_hours ?? '?'} hours`}>
                    <Chip variant="danger">
                      Overdue{step.overdue_hours ? ` ${step.overdue_hours}h` : ''}
                    </Chip>
                  </span>
                )}
              </div>
              {step.approver_name && (
                <div className="text-xs text-muted mt-0.5">{step.approver_name}</div>
              )}
              {step.acted_at && (
                <div className="text-xs text-muted font-mono tabular-nums mt-0.5">
                  {formatDateTime(step.acted_at)}
                </div>
              )}
              {step.remarks && (
                <div
                  className={cn(
                    'text-xs mt-1 italic',
                    step.action === 'rejected' ? 'text-danger-fg' : 'text-muted',
                  )}
                >
                  &ldquo;{step.remarks}&rdquo;
                </div>
              )}
            </div>
          </li>
        );
      })}
    </ol>
  );
}
