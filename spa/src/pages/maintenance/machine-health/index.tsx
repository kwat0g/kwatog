/** ADV8 — Machine health / condition monitoring. */
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { LuActivity, LuThermometer, LuGauge, LuDroplets, LuZap } from '@/lib/icons';
import { conditionReadingsApi } from '@/api/maintenance/conditionReadings';
import { machinesApi } from '@/api/mrp/machines';
import { PageHeader } from '@/components/layout/PageHeader';
import { Select } from '@/components/ui/Select';
import { Panel } from '@/components/ui/Panel';
import { Chip } from '@/components/ui/Chip';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import type {
  ConditionTrendPoint,
  ConditionMetric,
  MachineHealthSnapshot,
} from '@/types/maintenance';
import { formatDate, formatDateTime } from '@/lib/formatDate';

const METRIC_ICONS: Record<ConditionMetric, typeof LuActivity> = {
  temperature: LuThermometer,
  vibration: LuGauge,
  pressure: LuActivity,
  current: LuZap,
  oil_quality: LuDroplets,
};

function HealthGauge({
  metric,
  snapshot,
  metricLabels,
  metricUnits,
}: {
  metric: ConditionMetric;
  snapshot: MachineHealthSnapshot | undefined;
  metricLabels: Map<string, string>;
  metricUnits: Map<string, string>;
}) {
  const Icon = METRIC_ICONS[metric];
  const value = snapshot?.value;
  const scale = snapshot?.max_threshold ?? Math.max(value ?? 0, snapshot?.min_threshold ?? 1) * 1.2;
  const pct = value == null ? 0 : Math.min((value / scale) * 100, 100);
  const status = snapshot?.status;

  return (
    <Panel className="p-4">
      <div className="flex items-center gap-2">
        <Icon size={16} className="text-primary" />
        <span className="text-sm font-medium">{metricLabels.get(metric) ?? metric}</span>
        <Chip
          variant={
            status === 'critical'
              ? 'danger'
              : status === 'warning'
                ? 'warning'
                : status
                  ? 'success'
                  : 'neutral'
          }
          className="ml-auto text-2xs"
        >
          {status ?? 'No reading'}
        </Chip>
      </div>
      <div className="mt-3">
        <div className="flex items-baseline gap-1">
          <span className="text-2xl font-medium tabular-nums">{value?.toFixed(2) ?? '—'}</span>
          <span className="text-sm text-muted">
            {metricUnits.get(metric) ?? snapshot?.unit ?? ''}
          </span>
        </div>
        <div className="mt-2 h-2 overflow-hidden rounded bg-elevated">
          <div
            className={`h-full rounded transition-[width] duration-500 ${
              status === 'critical'
                ? 'bg-danger-bg'
                : status === 'warning'
                  ? 'bg-warning-bg'
                  : 'bg-success-bg'
            }`}
            style={{ width: `${pct}%` }}
          />
        </div>
        <div className="mt-1 flex justify-between text-2xs text-muted">
          <span>0</span>
          <span>{snapshot?.min_threshold != null ? `Min: ${snapshot.min_threshold}` : ''}</span>
          <span>{snapshot?.max_threshold != null ? `Max: ${snapshot.max_threshold}` : ''}</span>
        </div>
      </div>
      {snapshot?.recorded_at && (
        <p className="mt-2 text-2xs text-muted">
          Last reading: {formatDateTime(snapshot.recorded_at)}
        </p>
      )}
    </Panel>
  );
}

function TrendChart({
  points,
  metric,
  snapshot,
  metricUnits,
}: {
  points: ConditionTrendPoint[];
  metric: ConditionMetric;
  snapshot?: MachineHealthSnapshot;
  metricUnits: Map<string, string>;
}) {
  const threshold = snapshot?.max_threshold ?? snapshot?.min_threshold ?? 0;
  const values = points.map((p) => p.value);
  const minVal = Math.min(...values, 0);
  const maxVal = Math.max(...values, threshold);
  const range = maxVal - minVal || 1;

  return (
    <div className="mt-4">
      <div className="flex h-32 items-end gap-1">
        {points.map((p, i) => {
          const h = ((p.value - minVal) / range) * 100;
          return (
            <div key={i} className="group relative flex flex-1 flex-col items-center">
              <div
                className={`w-full rounded-t transition-[height] ${
                  (snapshot?.max_threshold != null && p.value > snapshot.max_threshold) ||
                  (snapshot?.min_threshold != null && p.value < snapshot.min_threshold)
                    ? 'bg-danger-bg/60'
                    : 'bg-primary/60'
                }`}
                style={{ height: `${Math.max(h, 2)}%` }}
              />
              <div className="absolute -top-6 left-1/2 hidden -translate-x-1/2 rounded bg-canvas px-2 py-0.5 text-2xs border border-default group-hover:block whitespace-nowrap">
                {p.value.toFixed(2)} {metricUnits.get(metric) ?? snapshot?.unit ?? ''}
              </div>
            </div>
          );
        })}
      </div>
      <div className="mt-1 flex justify-between text-2xs text-muted">
        <span>{points[0]?.recorded_at ? formatDate(points[0].recorded_at) : ''}</span>
        <span>
          {points[points.length - 1]?.recorded_at
            ? formatDate(points[points.length - 1].recorded_at)
            : ''}
        </span>
      </div>
    </div>
  );
}

export default function MachineHealthPage() {
  const [selectedMachine, setSelectedMachine] = useState<string>('');

  const { data: machines } = useQuery({
    queryKey: ['machines', 'list'],
    queryFn: () => machinesApi.list({ per_page: 500 }),
    placeholderData: (prev) => prev,
  });
  const { data: metricOptions } = useQuery({
    queryKey: ['maintenance', 'condition-metric-options'],
    queryFn: conditionReadingsApi.options,
    staleTime: 5 * 60 * 1000,
  });

  const machineId = selectedMachine ?? undefined;

  const {
    data: healthSnapshot,
    isLoading: healthLoading,
    isError: healthError,
    refetch: healthRefetch,
  } = useQuery({
    queryKey: ['machine-health', 'snapshot', machineId],
    queryFn: () =>
      machineId
        ? conditionReadingsApi.healthSnapshot({ machine_id: machineId })
        : Promise.resolve([]),
    enabled: !!machineId,
  });

  const { data: readings, isLoading: readingsLoading } = useQuery({
    queryKey: ['condition-readings', 'list', machineId],
    queryFn: () =>
      machineId ? conditionReadingsApi.list({ machine_id: machineId }) : Promise.resolve(undefined),
    enabled: !!machineId,
  });

  const machineOptions = [
    { value: '', label: 'Select a machine…' },
    ...(machines?.data?.map((m) => ({
      value: String(m.id),
      label: `${m.machine_code} — ${m.name}`,
    })) ?? []),
  ];

  const metrics = (metricOptions?.metrics ?? []).map((metric) => metric.value as ConditionMetric);
  const metricLabels = new Map(
    (metricOptions?.metrics ?? []).map((metric) => [metric.value, metric.label]),
  );
  const metricUnits = new Map(
    (metricOptions?.metrics ?? []).map((metric) => [metric.value, metric.unit]),
  );

  return (
    <div>
      <PageHeader
        title="Machine health"
        subtitle="Condition monitoring and predictive maintenance"
        actions={
          <div className="w-64">
            <Select value={selectedMachine} onChange={(e) => setSelectedMachine(e.target.value)}>
              {machineOptions.map((o) => (
                <option key={o.value || '_empty'} value={o.value}>
                  {o.label}
                </option>
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
          {healthError && (
            <div className="px-5 py-4">
              <EmptyState
                icon="alert-circle"
                title="Failed to load health data"
                action={
                  <Button variant="secondary" onClick={() => healthRefetch()}>
                    Retry
                  </Button>
                }
              />
            </div>
          )}
          {/* Health gauges */}
          <div className="grid grid-cols-1 gap-3 px-5 py-4 sm:grid-cols-2 lg:grid-cols-5">
            {healthLoading
              ? metrics.map((m) => (
                  <div key={m} className="h-40 animate-pulse rounded-md bg-elevated" />
                ))
              : metrics.map((metric) => (
                  <HealthGauge
                    key={metric}
                    metric={metric}
                    metricLabels={metricLabels}
                    metricUnits={metricUnits}
                    snapshot={healthSnapshot?.find((s) => s.metric === metric)}
                  />
                ))}
          </div>

          {/* Trend charts per metric */}
          <div className="px-5 py-4 space-y-4">
            {metrics.map((metric) => {
              const metricReadings = readings?.data?.filter((r) => r.metric === metric) ?? [];
              const trendPoints: ConditionTrendPoint[] = metricReadings
                .slice(0, 30)
                .reverse()
                .map((r) => ({
                  recorded_at: r.recorded_at ?? r.created_at ?? '',
                  value: Number(r.value),
                }));

              return (
                <Panel key={metric} className="p-4">
                  <div className="flex items-center gap-2">
                    {(() => {
                      const Icon = METRIC_ICONS[metric];
                      return <Icon size={16} className="text-primary" />;
                    })()}
                    <span className="text-sm font-medium">
                      {metricLabels.get(metric) ?? metric} trend
                    </span>
                    <span className="ml-auto text-2xs text-muted">
                      {trendPoints.length} readings shown
                    </span>
                  </div>
                  {readingsLoading ? (
                    <div className="mt-4 h-32 animate-pulse rounded bg-elevated" />
                  ) : trendPoints.length > 0 ? (
                    <TrendChart
                      points={trendPoints}
                      metric={metric}
                      metricUnits={metricUnits}
                      snapshot={healthSnapshot?.find((s) => s.metric === metric)}
                    />
                  ) : (
                    <EmptyState
                      icon="activity"
                      title="No data"
                      description={`No ${metric} readings for this machine.`}
                      className="mt-4"
                    />
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
                  <p className="mt-1 text-xs text-muted">
                    Manually log a sensor reading or inspection result for this machine.
                  </p>
                </div>
                <Button variant="primary" size="sm" icon={<LuActivity size={14} />}>
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
