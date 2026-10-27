import { Link } from 'react-router-dom';
import { type ChainStep } from '@/types/chain';
import { cn } from '@/lib/cn';
import { focusRing } from '@/lib/focus';
import { Tooltip } from '@/components/ui/Tooltip';
import { formatDate } from '@/lib/formatDate';

interface ChainHeaderProps {
  steps: ChainStep[];
  className?: string;
  showStatusMessages?: boolean;
  stretch?: boolean;
}

const dotClass = (step: ChainStep) => {
  if (step.is_overdue) return 'bg-danger-bg border-danger animate-pulse ring-2 ring-danger/30';
  if (step.state === 'done') return 'bg-success-bg border-success';
  if (step.state === 'rejected') return 'bg-danger-bg border-danger';
  if (step.state === 'skipped') return 'bg-muted border-default';
  if (step.state === 'active') return 'bg-accent border-accent';
  return 'bg-canvas border-strong';
};

// Callers pass either a display string or a raw ISO date/timestamp; an ISO
// value printed verbatim read "2026-09-23T21:23:21.000000Z" (UTC, not Manila).
const stepDate = (date: string) =>
  /^\d{4}-\d{2}-\d{2}/.test(date) ? formatDate(date, date) : date;

const lineClass = (left: ChainStep) =>
  left.state === 'done' ? 'bg-success-bg' : left.state === 'rejected' ? 'bg-danger' : 'bg-strong';

export function ChainHeader({
  steps,
  className,
  showStatusMessages = true,
  stretch = false,
}: ChainHeaderProps) {
  if (steps.length === 0) return null;
  return (
      <div
        className={cn('w-full overflow-x-auto', className)}
        role="region"
        aria-label="Document progress"
        tabIndex={0}
      >
      <div className={cn('flex items-start gap-0', stretch ? 'w-full min-w-0' : 'min-w-max')}>
        {steps.map((step, i) => {
          const isInteractive = Boolean(step.href || step.onClick);

          const stepNode = (
            <div
              className={cn(
                'flex flex-col items-center py-0.5 rounded-md transition-colors duration-fast relative',
                'min-w-20 px-1',
                isInteractive && 'hover:bg-subtle cursor-pointer',
              )}
            >
              <span
                className={cn(
                  'h-[9px] w-[9px] rounded-full border block transition-transform duration-fast',
                  dotClass(step),
                  isInteractive && 'group-hover:scale-125',
                )}
                aria-hidden
              />
              <div className="mt-2 text-center">
                <div
                  className={cn(
                    'text-xs',
                    step.is_overdue
                      ? 'text-danger-fg font-medium'
                      : step.state === 'rejected'
                        ? 'text-danger-fg font-medium'
                        : step.state === 'skipped'
                          ? 'text-muted'
                          : step.state === 'pending'
                            ? 'text-subtle'
                            : 'text-primary',
                    step.state === 'active' && 'font-medium',
                    isInteractive && 'hover:underline underline-offset-2',
                  )}
                >
                  {stretch && step.shortLabel ? (
                    <>
                      <span className="sm:hidden">{step.shortLabel}</span>
                      <span className="hidden sm:inline">{step.label}</span>
                    </>
                  ) : (
                    step.label
                  )}
                </div>
                {step.sla_label && (
                  <div className="text-[10px] leading-tight font-mono text-danger-fg bg-danger-bg px-1 rounded mt-0.5 inline-block">
                    {step.sla_label}
                  </div>
                )}
                {step.date && !step.sla_label && (
                  <div
                    className={cn(
                      'text-2xs font-mono tabular-nums text-muted mt-0.5',
                      stretch && 'hidden sm:block',
                    )}
                  >
                    {stepDate(step.date)}
                  </div>
                )}
              </div>
            </div>
          );

          let content = stepNode;
          if (step.href) {
            content = (
              <Link
                to={step.href}
                aria-label={step.label}
                data-touch-target
                className={cn('group rounded-md', focusRing)}
              >
                {stepNode}
              </Link>
            );
          } else if (step.onClick) {
            content = (
              <button
                type="button"
                onClick={() => step.onClick?.(step)}
                className={cn('group rounded-md text-left', focusRing)}
              >
                {stepNode}
              </button>
            );
          }

          if (step.description) {
            content = <Tooltip content={step.description}>{content}</Tooltip>;
          }

          return (
            <div
              key={step.key}
              className={cn('flex items-start', stretch && i < steps.length - 1 && 'flex-1')}
            >
              {content}
              {i < steps.length - 1 && (
                <div
                  className={cn(
                    'h-[1px] self-start mt-[4px]',
                    stretch ? 'flex-1' : 'w-10',
                    lineClass(step),
                  )}
                  aria-hidden
                />
              )}
            </div>
          );
        })}
      </div>
      {showStatusMessages && (
        <div className="mt-3 space-y-2" aria-live="polite">
          {steps
            .filter(
              (step) => step.description && ['active', 'rejected', 'pending'].includes(step.state),
            )
            .map((step) => (
              <p
                key={step.key}
                className={cn(
                  'rounded-md border border-default px-3 py-2 text-sm',
                  step.state === 'rejected'
                    ? 'text-danger-fg bg-danger-bg'
                    : 'text-primary bg-subtle',
                )}
              >
                {step.description}
              </p>
            ))}
        </div>
      )}
    </div>
  );
}
