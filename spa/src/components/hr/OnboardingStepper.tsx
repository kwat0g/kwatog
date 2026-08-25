import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { LuCheck } from '@/lib/icons';
import { Panel, SkeletonBlock } from '@/components/ui';
import { Button } from '@/components/ui/Button';
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
 const queryClient = useQueryClient();
 const queryKey = ['employee-onboarding', employeeId] as const;
 const { data, isLoading, isError, refetch, isFetching } = useQuery<EmployeeOnboarding>({
 queryKey,
 queryFn: () => onboardingApi.show(employeeId),
 });
 const markDepartmentTeamNotified = useMutation({
 mutationFn: () => onboardingApi.markDepartmentTeamNotified(employeeId),
 onSuccess: (next) => queryClient.setQueryData(queryKey, next),
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
 <div className="flex flex-wrap items-center gap-2 text-xs text-danger-fg">
 <span>Failed to load onboarding status.</span>
 <Button size="xs" variant="secondary" loading={isFetching} onClick={() => void refetch()}>
 Retry
 </Button>
 </div>
 )}

 {data && Array.isArray(data.steps) && (
 <>
 <div className="flex flex-wrap items-start gap-x-4 gap-y-3">
 {data.steps.map((step) => (
 <StepNode
 key={step.key}
 step={step}
 onMark={
 step.key === 'dept_team_notified' && step.completed_at === null
 ? () => markDepartmentTeamNotified.mutate()
 : undefined
 }
 isMarking={markDepartmentTeamNotified.isPending}
 />
 ))}
 </div>
 {markDepartmentTeamNotified.isError && (
 <div className="mt-3 text-xs text-danger-fg">
 Unable to record the department-team notification. Try again.
 </div>
 )}
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

function StepNode({
 step,
 onMark,
 isMarking = false,
}: {
 step: OnboardingStep;
 onMark?: () => void;
 isMarking?: boolean;
}) {
 const done = step.completed_at !== null;
 return (
 <div className="flex items-center gap-2 min-w-0">
 <span
 className={cn(
 'inline-flex items-center justify-center w-4 h-4 rounded-full border',
 done
 ? 'bg-success-bg text-success-fg border-success-fg'
 : 'bg-elevated text-subtle border-default',
 )}
 aria-hidden
 >
 {done ? <LuCheck size={10} strokeWidth={3} /> : null}
 </span>
 <div className="min-w-0">
 <div className={cn('text-xs leading-tight', done ? 'text-primary' : 'text-subtle')}>
 {step.label}
 </div>
 {done && step.completed_at && (
 <div className="text-2xs font-mono tabular-nums text-muted leading-tight">
 {new Date(step.completed_at).toLocaleDateString()}
 </div>
 )}
 {onMark && (
 <div className="mt-2 space-y-1.5">
 <div className="text-2xs text-muted">HR confirmation is required for this step.</div>
 <Button size="xs" variant="secondary" loading={isMarking} onClick={onMark}>
 Mark team notified
 </Button>
 </div>
 )}
 </div>
 </div>
 );
}
