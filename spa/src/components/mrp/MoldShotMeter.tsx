import { AlertTriangle, CheckCircle, ShieldAlert, Wrench } from 'lucide-react';
import { Chip } from '@/components/ui/Chip';
import { Button } from '@/components/ui/Button';
import { formatInt } from '@/lib/formatNumber';

export interface MoldShotMeterProps {
  currentShots: number;
  maxShots: number;
  moldCode?: string;
  warningRatioPct?: number;
  status?: string;
  compact?: boolean;
  onTriggerPm?: () => void;
}

export function MoldShotMeter({
  currentShots,
  maxShots,
  moldCode,
  warningRatioPct = 90,
  status,
  compact = false,
  onTriggerPm,
}: MoldShotMeterProps) {
  const safeMax = Math.max(1, maxShots);
  const pct = Math.min(100, Math.max(0, (currentShots / safeMax) * 100));
  const remainingShots = Math.max(0, maxShots - currentShots);
  const isNearing = pct >= warningRatioPct && pct < 100;
  const isExceeded = pct >= 100;

  const barColor = isExceeded ? 'bg-danger-bg' : isNearing ? 'bg-warning-bg' : 'bg-success-bg';

  const statusVariant = isExceeded ? 'danger' : isNearing ? 'warning' : 'success';

  if (compact) {
    return (
      <div className="space-y-1 w-full">
        <div className="flex justify-between items-center text-xs">
          <span className="font-mono tabular-nums text-primary font-medium">
            {formatInt(currentShots)} / {formatInt(maxShots)}
          </span>
          <span
            className={`font-mono text-2xs font-medium ${
              isExceeded ? 'text-danger-fg' : isNearing ? 'text-warning-fg' : 'text-success-fg'
            }`}
          >
            {pct.toFixed(1)}%
          </span>
        </div>
        <div className="h-1.5 bg-elevated rounded-full overflow-hidden border border-default/40">
          <div
            className={`h-full rounded-full transition-[width] duration-300 ${barColor}`}
            style={{ width: `${Math.min(100, Math.max(3, pct))}%` }}
          />
        </div>
      </div>
    );
  }

  return (
    <div className="bg-canvas border border-default rounded-md p-4 space-y-3">
      <div className="flex items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          {isExceeded ? (
            <ShieldAlert className="w-5 h-5 text-danger-fg animate-pulse" />
          ) : isNearing ? (
            <AlertTriangle className="w-5 h-5 text-warning-fg" />
          ) : (
            <CheckCircle className="w-5 h-5 text-success-fg" />
          )}
          <div>
            <h4 className="text-xs font-medium uppercase tracking-wider text-primary flex items-center gap-2">
              Mold Lifespan & Shot Counter Meter
              {moldCode && <span className="font-mono text-accent">({moldCode})</span>}
            </h4>
            <p className="text-2xs text-muted">
              IATF 16949 §8.5.1.5 Tool Maintenance Tracker — Max Rated Cycles
            </p>
          </div>
        </div>

        <Chip variant={statusVariant}>
          {isExceeded ? 'Limit Exceeded' : isNearing ? 'PM Required Soon' : 'Optimal'}
        </Chip>
      </div>

      {/* Progress Track */}
      <div className="space-y-1.5">
        <div className="flex justify-between items-baseline text-xs">
          <span className="text-muted">Total Cycles Executed</span>
          <span className="font-mono text-sm font-medium text-primary tabular-nums">
            {formatInt(currentShots)}{' '}
            <span className="text-xs font-normal text-muted">/ {formatInt(maxShots)} shots</span>
          </span>
        </div>

        <div className="h-3 bg-surface rounded-full overflow-hidden border border-default/60 p-0.5 relative">
          <div
            className={`h-full rounded-full transition-[width] duration-500 ${barColor}`}
            style={{ width: `${Math.min(100, Math.max(2, pct))}%` }}
          />
          {/* 90% Warning Threshold Indicator Marker */}
          <div
            className="absolute top-0 bottom-0 w-0.5 bg-warning-bg/80 z-10"
            style={{ left: `${warningRatioPct}%` }}
            title={`PM Warning Threshold (${warningRatioPct}%)`}
          />
        </div>

        <div className="flex justify-between items-center text-2xs text-muted font-mono pt-0.5">
          <span>0 Shots</span>
          <span className="text-warning-fg font-medium">{warningRatioPct}% Threshold</span>
          <span>{formatInt(maxShots)} Max</span>
        </div>
      </div>

      {/* Metric Breakdown Cards */}
      <div className="grid grid-cols-3 gap-2 text-xs pt-1">
        <div className="bg-surface p-2 rounded-md border border-default/50">
          <span className="text-2xs text-muted block">Used Lifespan</span>
          <span className="font-mono font-medium text-primary">{pct.toFixed(1)}%</span>
        </div>
        <div className="bg-surface p-2 rounded-md border border-default/50">
          <span className="text-2xs text-muted block">Shots Remaining</span>
          <span className="font-mono font-medium text-primary tabular-nums">
            {formatInt(remainingShots)}
          </span>
        </div>
        <div className="bg-surface p-2 rounded-md border border-default/50">
          <span className="text-2xs text-muted block">Tooling Status</span>
          <span className="font-mono font-medium capitalize text-primary">
            {status ?? 'Active'}
          </span>
        </div>
      </div>

      {/* Action Prompt */}
      {(isNearing || isExceeded) && (
        <div className="flex items-center justify-between gap-2 p-2.5 rounded-md bg-warning-bg/10 border border-warning/30 text-xs">
          <div className="flex items-center gap-2">
            <Wrench className="w-4 h-4 text-warning-fg shrink-0" />
            <span className="text-warning-fg">
              {isExceeded
                ? 'Mold has reached its maximum shot limit! Cavity inspection & overhaul required.'
                : `Mold has reached ${pct.toFixed(0)}% shot limit. Schedule preventive maintenance.`}
            </span>
          </div>
          {onTriggerPm && (
            <Button variant="secondary" size="sm" onClick={onTriggerPm} className="shrink-0">
              Create PM Order
            </Button>
          )}
        </div>
      )}
    </div>
  );
}
