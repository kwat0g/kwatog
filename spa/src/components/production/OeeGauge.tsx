/**
 * Sprint 6 — Task 57. OEE gauge — four flat horizontal bars
 * (availability / performance / quality / oee). No SVG dial — design
 * system mandates flat surfaces with 0.5px borders.
 */
import type { OeeResult } from '@/types/production';

interface Props {
  result: Pick<OeeResult, 'availability' | 'performance' | 'quality' | 'oee'>;
  displayPolicy?: { world_class_ratio: number; on_track_ratio: number };
  compact?: boolean;
}

const colorFor = (v: number, policy?: Props['displayPolicy']) =>
  policy ? (v >= policy.world_class_ratio ? 'bg-success' : v >= policy.on_track_ratio ? 'bg-warning' : 'bg-danger') : 'bg-elevated';
const textColorFor = (v: number, policy?: Props['displayPolicy']) =>
  policy ? (v >= policy.world_class_ratio ? 'text-success-fg' : v >= policy.on_track_ratio ? 'text-warning-fg' : 'text-danger-fg') : 'text-muted';

function Row({ label, value, policy, weight = 'normal' }: { label: string; value: number; policy?: Props['displayPolicy']; weight?: 'normal' | 'medium' }) {
  const pct = Math.round(value * 1000) / 10; // 1 decimal
  return (
    <div className="grid grid-cols-[80px_1fr_60px] items-center gap-2">
      <span className={`text-2xs uppercase tracking-wider text-muted ${weight === 'medium' ? 'font-medium text-primary' : ''}`}>{label}</span>
      <div className="h-1.5 bg-elevated rounded-full overflow-hidden">
        <div className={`h-1.5 rounded-full ${colorFor(value, policy)}`} style={{ width: `${Math.min(100, pct)}%` }} aria-hidden />
      </div>
      <span className={`text-xs font-mono tabular-nums text-right ${textColorFor(value, policy)} ${weight === 'medium' ? 'font-medium' : ''}`}>
        {pct.toFixed(1)}%
      </span>
    </div>
  );
}

export function OeeGauge({ result, displayPolicy, compact = false }: Props) {
  return (
    <div className={`space-y-${compact ? '1.5' : '2'}`}>
      <Row label="Availability" value={result.availability} policy={displayPolicy} />
      <Row label="Performance" value={result.performance} policy={displayPolicy} />
      <Row label="Quality" value={result.quality} policy={displayPolicy} />
      <div className="border-t border-default pt-2">
        <Row label="OEE" value={result.oee} policy={displayPolicy} weight="medium" />
      </div>
    </div>
  );
}
