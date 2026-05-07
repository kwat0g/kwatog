import { useQuery } from '@tanstack/react-query';
import { Check } from 'lucide-react';
import { Panel, SkeletonBlock } from '@/components/ui';
import { onboardingApi } from '@/api/hr/onboarding';
import { cn } from '@/lib/cn';
import type { EmployeeOnboarding, OnboardingStep } from '@/types/hr';

interface Props {
  employeeId: string;
  /** Optional: render inside an existing panel without the wrapper. */
  bare?: boolean;
}

/**
 * U4 — Onboarding Stepper. Mounted above tabs on Employee detail page.
 * Re-derives step status from canonical data on every fetch (server-side).
 */
export function OnboardingStepper({ employeeId, bare = false }: Props) {
  const { data, isLoading, isError } = useQuery<EmployeeOnboarding>({
    queryKey: ['employee-onboarding', employeeId],
    queryFn: () => onboardingApi.show(employeeId),
  });

  const inner = (
    <div>
      {isLoading && (
        <div className="flex items-center gap-3">
          {[1, 2, 3, 4, 5, 6, 7].map((i) => (
            <SkeletonBlock key={i} className="h-3 w-20" />
          ))}
        </div>
      )}

      {isError && (
        <div className="text-xs text-danger">Failed to load onboarding status.</div>
      )}

      {data && (
        <>
          <div className="flex flex-wrap items-start gap-x-4 gap-y-3">
            {data.steps.map((step, idx) => (
              <StepNode key={step.key} step={step} isLast={idx === data.steps.length - 1} />
            ))}
          </div>
          {data.is_complete && data.completed_at && (
            <div className="mt-3 text-xs text-muted">
              Onboarding completed on{' '}
              <span className="font-mono tabular-nums text-primary">
                {new Date(data.completed_at).toLocaleDateString()}
              </span>
            </div>
          )}
        </>
      )}
    </div>
  );

  if (bare) return inner;
  return <Panel title="Onboarding">{inner}</Panel>;
}

function StepNode({ step }: { step: OnboardingStep; isLast: boolean }) {
  const done = step.completed_at !== null;
  return (
    <div className="flex items-center gap-2 min-w-0">
      <span
        className={cn(
          'inline-flex items-center justify-center w-4 h-4 rounded-full border text-[9px] font-medium',
          done
            ? 'bg-success-bg text-success-fg border-success-fg'
            : 'bg-elevated text-subtle border-default',
        )}
        aria-hidden
      >
        {done ? <Check size={10} strokeWidth={3} /> : null}
      </span>
      <div className="min-w-0">
        <div className={cn('text-xs leading-tight', done ? 'text-primary' : 'text-subtle')}>
          {step.label}
        </div>
        {done && step.completed_at && (
          <div className="text-[10px] font-mono tabular-nums text-muted leading-tight">
            {new Date(step.completed_at).toLocaleDateString()}
          </div>
        )}
      </div>
    </div>
  );
}
