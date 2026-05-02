/**
 * Sprint 6 — Task 57. OEE gauge — four flat horizontal bars
 * (availability / performance / quality / oee). No SVG dial — design
 * system mandates flat surfaces with 0.5px borders.
 */
import type { OeeResult } from '@/types/production';

interface Props {
  result: Pick<OeeResult, 'availability' | 'performance' | 'quality' | 'oee'>;
  compact?: boolean;
}

const colorFor = (v: number) => v >= 0.85 ? 'bg-success' : v >= 0.70 ? 'bg-warning' : 'bg-danger';
const textColorFor = (v: number) => v >= 0.85 ? 'text-success-fg' : v >= 0.70 ? 'text-warning-fg' : 'text-danger-fg';

function Row({ label, value, weight = 'normal' }: { label: string; value: number; weight?: 'normal' | 'medium' }) {
  const pct = Math.round(value * 1000) / 10; // 1 decimal
  return (
    <div className="grid grid-cols-[80px_1fr_60px] items-center gap-2">
      <span className={`text-2xs uppercase tracking-wider text-muted ${weight === 'medium' ? 'font-medium text-primary' : ''}`}>{label}</span>
      <div className="h-1.5 bg-elevated rounded-full overflow-hidden">
        <div className={`h-1.5 rounded-full ${colorFor(value)}`} style={{ width: `${Math.min(100, pct)}%` }} aria-hidden />
      </div>
      <span className={`text-xs font-mono tabular-nums text-right ${textColorFor(value)} ${weight === 'medium' ? 'font-medium' : ''}`}>
        {pct.toFixed(1)}%
      </span>
    </div>
  );
}

export function OeeGauge({ result, compact = false }: Props) {
  return (
    <div className={`space-y-${compact ? '1.5' : '2'}`}>
      <Row label="Availability" value={result.availability} />
      <Row label="Performance" value={result.performance} />
      <Row label="Quality" value={result.quality} />
      <div className="border-t border-default pt-2">
        <Row label="OEE" value={result.oee} weight="medium" />
      </div>
    </div>
  );
}
