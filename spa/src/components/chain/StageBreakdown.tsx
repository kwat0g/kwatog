import { type StageRow } from '@/types/chain';
import { cn } from '@/lib/cn';

interface StageBreakdownProps {
  title?: string;
  stages: StageRow[];
  className?: string;
}

const fillColor = {
  success: 'bg-success',
  info: 'bg-info',
  warning: 'bg-warning',
  danger: 'bg-danger',
  neutral: 'bg-strong',
} as const;

export function StageBreakdown({ title, stages, className }: StageBreakdownProps) {
  return (
    <div className={cn('flex flex-col gap-2.5', className)}>
      {title && <h3 className="text-md font-medium text-primary">{title}</h3>}
      {stages.map((stage) => (
        <div key={stage.label}>
          <div className="flex items-baseline justify-between mb-1">
            <span className="text-sm text-secondary">{stage.label}</span>
            <span className="text-sm font-mono tabular-nums text-primary">{stage.count}</span>
          </div>
          <div className="h-1 w-full rounded-full bg-subtle overflow-hidden">
            <div
              className={cn('h-full rounded-full transition-[width] duration-normal', fillColor[stage.color ?? 'neutral'])}
              style={{ width: `${Math.max(0, Math.min(100, stage.percent))}%` }}
            />
          </div>
        </div>
      ))}
    </div>
  );
}
