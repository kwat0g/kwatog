import { useMemo } from 'react';
import { cn } from '@/lib/cn';

export interface SpecToleranceBarProps {
  nominal?: number | null;
  min?: number | null;
  max?: number | null;
  value?: number | string | null;
  unit?: string;
  className?: string;
}

export function SpecToleranceBar({
  nominal,
  min,
  max,
  value: valProp,
  unit = '',
  className,
}: SpecToleranceBarProps) {
  const numValue = useMemo(() => {
    if (valProp === null || valProp === undefined || valProp === '') return null;
    const parsed = Number(valProp);
    return isNaN(parsed) ? null : parsed;
  }, [valProp]);

  // Can't render gauge without min & max boundary
  if (min === null || min === undefined || max === null || max === undefined || min >= max) {
    return null;
  }

  const range = max - min;
  // Give 15% margin on both sides for visual indicator
  const visualMin = min - range * 0.15;
  const visualMax = max + range * 0.15;
  const visualRange = visualMax - visualMin;

  const minPercent = Math.max(0, Math.min(100, ((min - visualMin) / visualRange) * 100));
  const maxPercent = Math.max(0, Math.min(100, ((max - visualMin) / visualRange) * 100));

  let valuePercent: number | null = null;
  let status: 'in-spec' | 'near-limit' | 'out-of-spec' | 'none' = 'none';

  if (numValue !== null) {
    const rawPct = ((numValue - visualMin) / visualRange) * 100;
    valuePercent = Math.max(0, Math.min(100, rawPct));

    const isPass = numValue >= min && numValue <= max;
    if (!isPass) {
      status = 'out-of-spec';
    } else {
      const distToEdge = Math.min(numValue - min, max - numValue);
      if (range > 0 && distToEdge / range <= 0.1) {
        status = 'near-limit';
      } else {
        status = 'in-spec';
      }
    }
  }

  const statusColor =
    status === 'out-of-spec'
      ? 'bg-danger text-danger border-danger'
      : status === 'near-limit'
        ? 'bg-warning text-warning border-warning'
        : status === 'in-spec'
          ? 'bg-success text-success border-success'
          : 'bg-subtle text-muted border-strong';

  return (
    <div className={cn('flex flex-col gap-0.5 w-32 select-none', className)}>
      <div className="relative h-2 w-full bg-subtle rounded border border-default overflow-hidden">
        {/* Valid tolerance range region */}
        <div
          className="absolute top-0 bottom-0 bg-success-bg/40 border-x border-success/30"
          style={{ left: `${minPercent}%`, width: `${maxPercent - minPercent}%` }}
        />
        {/* Nominal marker if present */}
        {nominal !== null && nominal !== undefined && nominal >= min && nominal <= max && (
          <div
            className="absolute top-0 bottom-0 w-[1px] bg-muted/60 z-10"
            style={{ left: `${((nominal - visualMin) / visualRange) * 100}%` }}
          />
        )}
        {/* Measured Value Pointer */}
        {valuePercent !== null && (
          <div
            className={cn(
              'absolute top-0 bottom-0 w-1.5 -ml-0.75 rounded-full z-20 transition-all duration-fast shadow-sm',
              statusColor.split(' ')[0],
            )}
            style={{ left: `${valuePercent}%` }}
          />
        )}
      </div>
      <div className="flex justify-between items-center text-[9px] font-mono tabular-nums text-muted px-0.5">
        <span>{min}{unit}</span>
        {numValue !== null && (
          <span
            className={cn(
              'font-medium text-[10px]',
              status === 'out-of-spec' && 'text-danger font-bold',
              status === 'near-limit' && 'text-warning font-semibold',
              status === 'in-spec' && 'text-success',
            )}
          >
            {numValue}{unit}
          </span>
        )}
        <span>{max}{unit}</span>
      </div>
    </div>
  );
}
