import { cn } from '@/lib/cn';
import type { ChainStepState } from '@/types/chain';

export interface StageDotStep {
  key: string;
  label: string;
  state: ChainStepState;
}

const stateLabels: Record<ChainStepState, string> = {
  done: 'Complete',
  active: 'Current',
  pending: 'Not started',
  rejected: 'Rejected',
  skipped: 'Skipped',
};

const stateClasses: Record<ChainStepState, string> = {
  done: 'bg-success',
  active: 'bg-accent ring-2 ring-accent/20',
  pending: 'border border-default bg-canvas',
  rejected: 'rounded-sm bg-danger',
  skipped: 'h-0.5 w-2 rounded-none bg-strong',
};

/** Compact chain cue. Each stage name and state remains available to assistive technology. */
export function StageDots({
  steps,
  label = 'Process stages',
  className,
}: {
  steps: StageDotStep[];
  label?: string;
  className?: string;
}) {
  const summaryStep = steps.find((step) => step.state === 'active' || step.state === 'rejected')
    ?? steps.find((step) => step.state === 'pending');
  const summaryLabel = summaryStep?.state === 'active'
    ? summaryStep.label
    : summaryStep?.state === 'rejected'
      ? `${summaryStep.label} · Rejected`
      : summaryStep?.state === 'pending'
        ? `Next: ${summaryStep.label}`
        : steps.length > 0 && steps.every((step) => step.state === 'done' || step.state === 'skipped')
          ? 'Complete'
          : undefined;

  return (
    <div className={cn('inline-flex items-center gap-2', className)}>
      <ol aria-label={label} className="inline-flex items-center gap-1.5">
        {steps.map((step) => (
          <li key={step.key} aria-current={step.state === 'active' ? 'step' : undefined}>
            <span className="sr-only">
              {step.label}: {stateLabels[step.state]}
            </span>
            <span aria-hidden="true" className={cn('block h-2 w-2 rounded-full', stateClasses[step.state])} />
          </li>
        ))}
      </ol>
      {summaryLabel && <span className="text-caption text-secondary">{summaryLabel}</span>}
    </div>
  );
}
