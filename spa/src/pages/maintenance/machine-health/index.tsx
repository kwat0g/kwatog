/** ADV8 — Machine health / condition monitoring. */
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Activity, Thermometer, Gauge, Droplets, Zap } from 'lucide-react';
import { conditionReadingsApi } from '@/api/maintenance/conditionReadings';
import { machinesApi } from '@/api/mrp/machines';
import { PageHeader } from '@/components/layout/PageHeader';
import { Select } from '@/components/ui/Select';
import { Panel } from '@/components/ui/Panel';
import { Chip } from '@/components/ui/Chip';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import type { ConditionTrendPoint, ConditionMetric, MachineHealthSnapshot } from '@/types/maintenance';

const METRIC_ICONS: Record<ConditionMetric, typeof Activity> = {
  temperature: Thermometer,
  vibration: Gauge,
  pressure: Activity,
  current: Zap,
  oil_quality: Droplets,
};

const METRIC_LABELS: Record<ConditionMetric, string> = {
  temperature: 'Temperature',
  vibration: 'Vibration',
  pressure: 'Hydraulic Pressure',
  current: 'Current Draw',
  oil_quality: 'Oil Quality',
};

const METRIC_UNITS: Record<ConditionMetric, string> = {
  temperature: '°C',
  vibration: 'mm/s',
  pressure: 'bar',
  current: 'A',
  oil_quality: '%',
};

const THRESHOLDS: Record<ConditionMetric, { max: number }> = {
  temperature: { max: 85 },
  vibration: { max: 7.1 },
  pressure: { max: 12 },
  current: { max: 150 },
  oil_quality: { max: 100 },
};

function HealthGauge({ metric, snapshot }: { metric: ConditionMetric; snapshot: MachineHealthSnapshot | undefined }) {
  const Icon = METRIC_ICONS[metric];
  const value = snapshot?.value ?? 0;
  const max = THRESHOLDS[metric].max;
  const pct = Math.min((value / max) * 100, 100);
  const status = snapshot?.status ?? 'ok';

  return (
    <Panel className="p-4">
      <div className="flex items-center gap-2">
        <Icon size={16} className="text-primary" />
        <span className="text-sm font-medium">{METRIC_LABELS[metric]}</span>
        <Chip variant={status === 'critical' ? 'danger' : status === 'warning' ? 'warning' : 'success'} className="ml-auto text-2xs">
          {status}
        </Chip>
      </div>
      <div className="mt-3">
        <div className="flex items-baseline gap-1">
          <span className="text-2xl font-semibold tabular-nums">{snapshot?.value?.toFixed(2) ?? '—'}</span>
          <span className="text-sm text-muted">{METRIC_UNITS[metric]}</span>
        </div>
        <div className="mt-2 h-2 overflow-hidden rounded bg-elevated">
          <div
            className={`h-full rounded transition-all duration-500 ${
              status === 'critical' ? 'bg-danger' : status === 'warning' ? 'bg-warning' : 'bg-success'
            }`}
            style={{ width: `${pct}%` }}
          />
        </div>
        <div className="mt-1 flex justify-between text-2xs text-muted">
          <span>0</span>
          <span>Limit: {max}</span>
        </div>
      </div>
      {snapshot?.recorded_at && (
        <p className="mt-2 text-2xs text-muted">Last reading: {new Date(snapshot.recorded_at).toLocaleString()}</p>
      )}
    </Panel>
  );
}

function TrendChart({ points, metric }: { points: ConditionTrendPoint[]; metric: ConditionMetric }) {
  const max = THRESHOLDS[metric].max;
  const values = points.map((p) => p.value);
  const minVal = Math.min(...values, 0);
  const maxVal = Math.max(...values, max);
  const range = maxVal - minVal || 1;

  return (
    <div className="mt-4">
      <div className="flex h-32 items-end gap-1">
        {points.map((p, i) => {
          const h = ((p.value - minVal) / range) * 100;
          return (
            <div key={i} className="group relative flex flex-1 flex-col items-center">
              <div
                className={`w-full rounded-t transition-all ${
                  p.value > max * 0.9 ? 'bg-danger/60' : p.value > max * 0.7 ? 'bg-warning/60' : 'bg-primary/60'
                }`}
                style={{ height: `${Math.max(h, 2)}%` }}
              />
              <div className="absolute -top-6 left-1/2 hidden -translate-x-1/2 rounded bg-canvas px-2 py-0.5 text-2xs shadow border border-default group-hover:block whitespace-nowrap">
                {p.value.toFixed(2)} {METRIC_UNITS[metric]}
              </div>
            </div>
          );
        })}
      </div>
      <div className="mt-1 flex justify-between text-2xs text-muted">
        <span>{points[0]?.recorded_at ? new Date(points[0].recorded_at).toLocaleDateString() : ''}</span>
        <span>{points[points.length - 1]?.recorded_at ? new Date(points[points.length - 1].recorded_at).toLocaleDateString() : ''}</span>
      </div>
    </div>
  );
}

export default function MachineHealthPage() {
  const [selectedMachine, setSelectedMachine] = useState<string>('');

  const { data: machines } = useQuery({
    queryKey: ['machines', 'list'],
    queryFn: () => machinesApi.list({ per_page: 500 }),
  });

  const machineId = selectedMachine ? Number(selectedMachine) : undefined;

  const { data: healthSnapshot, isLoading: healthLoading } = useQuery({
    queryKey: ['machine-health', 'snapshot', machineId],
    queryFn: () => machineId ? conditionReadingsApi.healthSnapshot({ machine_id: machineId }) : Promise.resolve([]),
    enabled: !!machineId,
  });

  const { data: readings, isLoading: readingsLoading } = useQuery({
    queryKey: ['condition-readings', 'list', machineId],
    queryFn: () => machineId ? conditionReadingsApi.list({ machine_id: machineId }) : Promise.resolve(undefined),
    enabled: !!machineId,
  });

  const machineOptions = [
    { value: '', label: 'Select a machine…' },
    ...(machines?.data.map((m) => ({ value: String(m.id), label: `${m.machine_code} — ${m.name}` })) ?? []),
  ];

  const metrics: ConditionMetric[] = ['temperature', 'vibration', 'pressure', 'current', 'oil_quality'];

  return (
    <div>
      <PageHeader
        title="Machine health"
        subtitle="Condition monitoring and predictive maintenance"
        actions={
          <div className="w-64">
            <Select value={selectedMachine} onChange={(e) => setSelectedMachine(e.target.value)}>
              {machineOptions.map((o) => (
                <option key={o.value || '_empty'} value={o.value}>{o.label}</option>
              ))}
            </Select>
          </div>
        }
      />

      {!machineId && (
        <div className="px-5 py-12">
          <EmptyState
            icon="activity"
            title="Select a machine"
            description="Choose a machine from the dropdown above to view its health snapshot, condition trends, and predictive maintenance status."
          />
        </div>
      )}

      {machineId && (
        <>
          {/* Health gauges */}
          <div className="grid grid-cols-1 gap-3 px-5 py-4 sm:grid-cols-2 lg:grid-cols-5">
            {healthLoading
              ? metrics.map((m) => <div key={m} className="h-40 animate-pulse rounded-md bg-elevated" />)
              : metrics.map((metric) => (
                  <HealthGauge
                    key={metric}
                    metric={metric}
                    snapshot={healthSnapshot?.find((s) => s.metric === metric)}
                  />
                ))}
          </div>

          {/* Trend charts per metric */}
          <div className="px-5 py-4 space-y-4">
            {metrics.map((metric) => {
              const metricReadings = readings?.data.filter((r) => r.metric === metric) ?? [];
              const trendPoints: ConditionTrendPoint[] = metricReadings
                .slice(0, 30)
                .reverse()
                .map((r) => ({ recorded_at: r.recorded_at ?? r.created_at ?? '', value: Number(r.value) }));

              return (
                <Panel key={metric} className="p-4">
                  <div className="flex items-center gap-2">
                    {(() => {
                      const Icon = METRIC_ICONS[metric];
                      return <Icon size={16} className="text-primary" />;
                    })()}
                    <span className="text-sm font-medium">{METRIC_LABELS[metric]} trend</span>
                    <span className="ml-auto text-2xs text-muted">Last 30 readings</span>
                  </div>
                  {readingsLoading ? (
                    <div className="mt-4 h-32 animate-pulse rounded bg-elevated" />
                  ) : trendPoints.length > 0 ? (
                    <TrendChart points={trendPoints} metric={metric} />
                  ) : (
                    <EmptyState icon="activity" title="No data" description={`No ${metric} readings for this machine.`} className="mt-4" />
                  )}
                </Panel>
              );
            })}
          </div>

          {/* Record reading action */}
          <div className="px-5 py-4">
            <Panel className="p-4">
              <div className="flex items-center justify-between">
                <div>
                  <h3 className="text-sm font-medium">Record condition reading</h3>
                  <p className="mt-1 text-xs text-muted">Manually log a sensor reading or inspection result for this machine.</p>
                </div>
                <Button variant="primary" size="sm" icon={<Activity size={14} />}>
                  Record reading
                </Button>
              </div>
            </Panel>
          </div>
        </>
      )}
    </div>
  );
}
