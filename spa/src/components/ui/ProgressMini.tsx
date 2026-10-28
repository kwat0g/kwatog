import { cn } from '@/lib/cn';

export function ProgressMini({
  value,
  target,
  label,
  valueLabel,
  targetLabel,
  className,
}: {
  value: number;
  target: number;
  label: string;
  valueLabel: string;
  targetLabel: string;
  className?: string;
}) {
  const safeTarget = Number.isFinite(target) && target > 0 ? target : 0;
  const safeValue = Number.isFinite(value) && value > 0 ? value : 0;
  const ratio = safeTarget > 0 ? Math.min(1, safeValue / safeTarget) : 0;
  const met = safeTarget > 0 && safeValue >= safeTarget;

  return (
    <div
      role="meter"
      aria-label={`${label}: ${valueLabel} of ${targetLabel}${met ? ', target met' : ', below target'}`}
      aria-valuemin={0}
      aria-valuemax={safeTarget}
      aria-valuenow={Math.min(safeValue, safeTarget)}
      className={cn('space-y-1', className)}
    >
      <div className="flex items-baseline justify-between gap-2 text-caption">
        <span className="text-secondary">{label}</span>
        <span className="tabular-nums text-primary">{valueLabel} / {targetLabel}</span>
      </div>
      <div className="h-1.5 overflow-hidden bg-subtle" aria-hidden="true">
        <div
          className={cn('h-full transition-[width] duration-normal motion-reduce:transition-none', met ? 'bg-success' : 'bg-warning')}
          style={{ width: `${ratio * 100}%` }}
        />
      </div>
    </div>
  );
}
