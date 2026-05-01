import { type ChainStep } from '@/types/chain';
import { cn } from '@/lib/cn';

interface ChainHeaderProps {
  steps: ChainStep[];
  className?: string;
}

const dotClass = (state: ChainStep['state']) =>
  state === 'done'
    ? 'bg-success border-success'
    : state === 'active'
      ? 'bg-accent border-accent'
      : 'bg-canvas border-strong';

const lineClass = (left: ChainStep, right: ChainStep) =>
  left.state === 'done' ? 'bg-success' : 'bg-strong';

export function ChainHeader({ steps, className }: ChainHeaderProps) {
  if (steps.length === 0) return null;
  return (
    <div className={cn('w-full overflow-x-auto', className)}>
      <div className="flex items-start gap-0 min-w-max">
        {steps.map((step, i) => (
          <div key={step.key} className="flex items-start">
            <div className="flex flex-col items-center min-w-[88px] px-1">
              <span
                className={cn(
                  'h-[9px] w-[9px] rounded-full border block',
                  dotClass(step.state),
                )}
                aria-hidden
              />
              <div className="mt-2 text-center">
                <div
                  className={cn(
                    'text-xs',
                    step.state === 'pending' ? 'text-text-subtle' : 'text-primary',
                    step.state === 'active' && 'font-medium',
                  )}
                >
                  {step.label}
                </div>
                {step.date && (
                  <div className="text-2xs font-mono tabular-nums text-muted mt-0.5">{step.date}</div>
                )}
              </div>
            </div>
            {i < steps.length - 1 && (
              <div
                className={cn('h-[1px] mt-1 self-start mt-[4px] w-10', lineClass(step, steps[i + 1]))}
                aria-hidden
              />
            )}
          </div>
        ))}
      </div>
    </div>
  );
}
