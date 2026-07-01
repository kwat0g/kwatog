/**
 * SPC Control Chart detail page.
 *
 * Renders an interactive X-bar/R (or I-MR) control chart using Recharts.
 * Shows UCL/LCL/CL as ReferenceLine, zone shading for 1-sigma / 2-sigma /
 * 3-sigma bands, and data points color-coded by violation status.
 * Below the main chart: Range chart with its own limits.
 * Sidebar: alert list + chart metadata.
 */
import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useParams } from 'react-router-dom';
import { RefreshCw } from 'lucide-react';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import {
  LineChart,
  Line,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip as RechartsTooltip,
  ReferenceLine,
  ReferenceArea,
  ResponsiveContainer,
  Scatter,
  ScatterChart,
  ZAxis,
} from 'recharts';
import { spcApi } from '@/api/quality/spc';
import { Button } from '@/components/ui/Button';
import { Chip, type ChipVariant } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import type { SpcControlChart, SpcDataPoint, SpcAlert, SpcChartStatus } from '@/types/quality/spc';

const STATUS_CHIP: Record<SpcChartStatus, ChipVariant> = {
  active: 'success',
  monitoring: 'info',
  suspended: 'neutral',
};

const RULE_LABELS: Record<string, string> = {
  rule_1_beyond_3sigma: 'Rule 1: Point beyond 3-sigma',
  rule_2_two_of_three_beyond_2sigma: 'Rule 2: 2 of 3 beyond 2-sigma',
  rule_3_four_of_five_beyond_1sigma: 'Rule 3: 4 of 5 beyond 1-sigma',
  rule_4_eight_same_side: 'Rule 4: 8 consecutive on same side',
};

// ─── Chart colours (CSS variables would be ideal but Recharts needs hex) ──
const COLORS = {
  cl: '#6366f1', // indigo — center line
  ucl: '#ef4444', // red — upper control limit
  lcl: '#ef4444', // red — lower control limit
  zone_a: 'rgba(239, 68, 68, 0.06)', // 2-sigma to 3-sigma
  zone_b: 'rgba(245, 158, 11, 0.06)', // 1-sigma to 2-sigma
  zone_c: 'rgba(34, 197, 94, 0.06)', // 0 to 1-sigma
  point_normal: '#6366f1',
  point_violation: '#ef4444',
  grid: 'var(--border-default)',
};

interface ChartPoint {
  subgroup: number;
  value: number;
  range: number;
  hasAlert: boolean;
  alerts: string[];
  sampleValues: number[] | null;
}

function parsePoints(dataPoints: SpcDataPoint[]): ChartPoint[] {
  return dataPoints
    .filter((dp) => dp.subgroup_mean !== null || dp.individual_value !== null)
    .map((dp) => ({
      subgroup: dp.subgroup_number,
      value: Number(dp.subgroup_mean ?? dp.individual_value ?? 0),
      range: Number(dp.subgroup_range ?? dp.moving_range ?? 0),
      hasAlert: (dp.alerts?.length ?? 0) > 0,
      alerts: dp.alerts ?? [],
      sampleValues: dp.sample_values,
    }))
    .sort((a, b) => a.subgroup - b.subgroup);
}

function CustomTooltip({ active, payload }: { active?: boolean; payload?: Array<{ payload: ChartPoint }> }) {
  if (!active || !payload?.[0]) return null;
  const pt = payload[0].payload;
  return (
    <div className="bg-canvas border border-default rounded-md shadow-lg p-3 text-xs max-w-xs">
      <div className="font-medium mb-1">Subgroup #{pt.subgroup}</div>
      <div className="font-mono tabular-nums">
        Mean: {pt.value.toFixed(4)}
      </div>
      <div className="font-mono tabular-nums">
        Range: {pt.range.toFixed(4)}
      </div>
      {pt.sampleValues && (
        <div className="text-muted mt-1">
          Samples: [{pt.sampleValues.map((v) => v.toFixed(3)).join(', ')}]
        </div>
      )}
      {pt.alerts.length > 0 && (
        <div className="mt-1.5 space-y-0.5">
          {pt.alerts.map((a, i) => (
            <div key={i} className="text-danger">
              {RULE_LABELS[a] ?? a}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

function RangeTooltip({ active, payload }: { active?: boolean; payload?: Array<{ payload: ChartPoint }> }) {
  if (!active || !payload?.[0]) return null;
  const pt = payload[0].payload;
  return (
    <div className="bg-canvas border border-default rounded-md shadow-lg p-3 text-xs">
      <div className="font-medium mb-1">Subgroup #{pt.subgroup}</div>
      <div className="font-mono tabular-nums">Range: {pt.range.toFixed(4)}</div>
    </div>
  );
}

// ─── Control Chart Component ──────────────────────
function XBarChart({
  points,
  ucl,
  lcl,
  cl,
  title,
  dataKey,
  height = 300,
  renderTooltip,
}: {
  points: ChartPoint[];
  ucl: number | null;
  lcl: number | null;
  cl: number | null;
  title: string;
  dataKey: 'value' | 'range';
  height?: number;
  renderTooltip?: React.ComponentType<{ active?: boolean; payload?: Array<{ payload: ChartPoint }> }>;
}) {
  // Calculate zone boundaries (1-sigma, 2-sigma from CL)
  const oneSigma = cl !== null && ucl !== null ? (ucl - cl) / 3 : null;

  return (
    <Panel title={title}>
      <ResponsiveContainer width="100%" height={height}>
        <LineChart data={points} margin={{ top: 10, right: 20, bottom: 5, left: 10 }}>
          <CartesianGrid strokeDasharray="3 3" stroke="var(--border-default)" opacity={0.5} />
          <XAxis
            dataKey="subgroup"
            tick={{ fontSize: 11, fill: 'var(--text-muted)' }}
            label={{ value: 'Subgroup', position: 'insideBottom', offset: -3, fontSize: 11, fill: 'var(--text-muted)' }}
          />
          <YAxis
            tick={{ fontSize: 11, fill: 'var(--text-muted)' }}
            domain={['auto', 'auto']}
            tickFormatter={(v: number) => v.toFixed(2)}
          />
          {renderTooltip && <RechartsTooltip content={renderTooltip} />}

          {/* Zone shading (3 zones above and below CL) */}
          {cl !== null && oneSigma !== null && (
            <>
              {/* Zone C: CL +/- 1 sigma (green) */}
              <ReferenceArea
                y1={cl - oneSigma}
                y2={cl + oneSigma}
                fill={COLORS.zone_c}
                fillOpacity={1}
              />
              {/* Zone B: 1-sigma to 2-sigma (amber) */}
              <ReferenceArea
                y1={cl + oneSigma}
                y2={cl + 2 * oneSigma}
                fill={COLORS.zone_b}
                fillOpacity={1}
              />
              <ReferenceArea
                y1={cl - 2 * oneSigma}
                y2={cl - oneSigma}
                fill={COLORS.zone_b}
                fillOpacity={1}
              />
              {/* Zone A: 2-sigma to 3-sigma (red) */}
              <ReferenceArea
                y1={cl + 2 * oneSigma}
                y2={cl + 3 * oneSigma}
                fill={COLORS.zone_a}
                fillOpacity={1}
              />
              <ReferenceArea
                y1={cl - 3 * oneSigma}
                y2={cl - 2 * oneSigma}
                fill={COLORS.zone_a}
                fillOpacity={1}
              />
            </>
          )}

          {/* Control limit reference lines */}
          {ucl !== null && (
            <ReferenceLine
              y={ucl}
              stroke={COLORS.ucl}
              strokeDasharray="6 3"
              label={{ value: 'UCL', position: 'right', fontSize: 10, fill: COLORS.ucl }}
            />
          )}
          {lcl !== null && (
            <ReferenceLine
              y={lcl}
              stroke={COLORS.lcl}
              strokeDasharray="6 3"
              label={{ value: 'LCL', position: 'right', fontSize: 10, fill: COLORS.lcl }}
            />
          )}
          {cl !== null && (
            <ReferenceLine
              y={cl}
              stroke={COLORS.cl}
              strokeDasharray="4 2"
              label={{ value: 'CL', position: 'right', fontSize: 10, fill: COLORS.cl }}
            />
          )}

          {/* Data line */}
          <Line
            type="linear"
            dataKey={dataKey}
            stroke={COLORS.point_normal}
            strokeWidth={1.5}
            dot={(props: { cx: number; cy: number; payload: ChartPoint; index: number }) => {
              const { cx, cy, payload: pt } = props;
              return (
                <circle
                  key={`dot-${pt.subgroup}`}
                  cx={cx}
                  cy={cy}
                  r={pt.hasAlert ? 5 : 3}
                  fill={pt.hasAlert ? COLORS.point_violation : COLORS.point_normal}
                  stroke={pt.hasAlert ? COLORS.point_violation : 'none'}
                  strokeWidth={pt.hasAlert ? 2 : 0}
                />
              );
            }}
            activeDot={{ r: 5, fill: COLORS.point_normal }}
          />
        </LineChart>
      </ResponsiveContainer>
    </Panel>
  );
}

// ─── Alert Item ───────────────────────────────────
function AlertItem({
  alert,
  onAcknowledge,
  isPending,
  canManage,
}: {
  alert: SpcAlert;
  onAcknowledge: (id: string, notes: string) => void;
  isPending: boolean;
  canManage: boolean;
}) {
  const [notes, setNotes] = useState('');
  return (
    <div className="border-b border-subtle py-3 last:border-0">
      <div className="flex items-start justify-between gap-2">
        <div>
          <div className="text-xs font-medium">
            {RULE_LABELS[alert.rule_code] ?? alert.rule_code}
          </div>
          {alert.data_point && (
            <div className="text-2xs text-muted mt-0.5">
              Subgroup #{alert.data_point.subgroup_number}
              {alert.data_point.subgroup_mean && (
                <span className="font-mono tabular-nums ml-1">
                  (mean: {Number(alert.data_point.subgroup_mean).toFixed(4)})
                </span>
              )}
            </div>
          )}
          <div className="text-2xs text-muted mt-0.5">
            {alert.created_at.slice(0, 16).replace('T', ' ')}
          </div>
        </div>
        {!alert.resolved_at && <Chip variant="danger">Open</Chip>}
        {alert.resolved_at && <Chip variant="success">Resolved</Chip>}
      </div>
      {!alert.resolved_at && canManage && (
        <div className="mt-2 flex items-center gap-2">
          <input
            type="text"
            placeholder="Notes (optional)"
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            className="flex-1 px-2 py-1 text-xs border border-default rounded-md bg-canvas focus:outline-none focus:ring-2 focus:ring-accent"
          />
          <Button
            variant="secondary"
            size="sm"
            disabled={isPending}
            loading={isPending}
            onClick={() => onAcknowledge(alert.id, notes)}
          >
            Acknowledge
          </Button>
        </div>
      )}
    </div>
  );
}

// ─── Main Page ────────────────────────────────────
export default function SpcChartDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const qc = useQueryClient();
  const { can } = usePermission();

  const { data: chart, isLoading, isError, refetch } = useQuery({
    queryKey: ['quality', 'spc', 'charts', id],
    queryFn: () => spcApi.showChart(id),
    enabled: Boolean(id),
  });

  const { data: alertsData } = useQuery({
    queryKey: ['quality', 'spc', 'alerts', id],
    queryFn: () => spcApi.listAlerts({ chart_id: id, per_page: 50 }),
    enabled: Boolean(id),
  });

  const recalculate = useMutation({
    mutationFn: () => spcApi.recalculate(id),
    onSuccess: () => {
      toast.success('Control limits recalculated');
      qc.invalidateQueries({ queryKey: ['quality', 'spc', 'charts', id] });
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Recalculation failed');
    },
  });

  const acknowledge = useMutation({
    mutationFn: ({ alertId, notes }: { alertId: string; notes: string }) =>
      spcApi.acknowledgeAlert(alertId, { notes: notes || undefined }),
    onSuccess: () => {
      toast.success('Alert acknowledged');
      qc.invalidateQueries({ queryKey: ['quality', 'spc', 'alerts', id] });
      qc.invalidateQueries({ queryKey: ['quality', 'spc', 'charts', id] });
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Could not acknowledge alert');
    },
  });

  const points = useMemo(() => parsePoints(chart?.data_points ?? []), [chart?.data_points]);

  if (isLoading) return <SkeletonDetail />;
  if (isError || !chart) {
    return (
      <EmptyState
        icon="alert-circle"
        title="Failed to load control chart"
        action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
      />
    );
  }

  const ucl = chart.ucl !== null ? Number(chart.ucl) : null;
  const lcl = chart.lcl !== null ? Number(chart.lcl) : null;
  const cl = chart.center_line !== null ? Number(chart.center_line) : null;
  const uclR = chart.ucl_range !== null ? Number(chart.ucl_range) : null;
  const lclR = chart.lcl_range !== null ? Number(chart.lcl_range) : null;
  const clR = chart.center_range !== null ? Number(chart.center_range) : null;

  const chartTypeLabel = chart.chart_type === 'xbar_r' ? 'X-bar' : chart.chart_type === 'imr' ? 'Individual' : 'p';
  const rangeLabel = chart.chart_type === 'imr' ? 'Moving Range' : 'Range';

  const alerts = alertsData?.data ?? [];

  return (
    <div>
      <PageHeader
        title={
          <span>
            {chart.spec_item?.parameter_name ?? 'Control Chart'}
            <Chip variant={STATUS_CHIP[chart.status]} className="ml-3">
              {chart.status}
            </Chip>
          </span>
        }
        subtitle={
          chart.product
            ? `${chart.product.part_number} -- ${chart.product.name}`
            : undefined
        }
        breadcrumbs={[
          { label: 'Quality', href: '/quality' },
          { label: 'SPC', href: '/quality/spc' },
          { label: chart.spec_item?.parameter_name ?? 'Chart' },
        ]}
        actions={
          can('quality.spc.manage') ? (
            <Button
              variant="secondary"
              size="sm"
              icon={<RefreshCw size={14} />}
              loading={recalculate.isPending}
              onClick={() => recalculate.mutate()}
            >
              Recalculate Limits
            </Button>
          ) : undefined
        }
      />

      <div className="px-5 py-4 grid grid-cols-3 gap-4">
        {/* ─── Charts (left 2 cols) ─── */}
        <div className="col-span-2 space-y-4">
          {/* Metadata panel */}
          <Panel title="Chart info">
            <dl className="grid grid-cols-4 gap-x-4 gap-y-3 text-sm">
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Type</dt>
                <dd>
                  <Chip variant="purple">
                    {chart.chart_type === 'xbar_r' ? 'X-bar / R' : chart.chart_type === 'imr' ? 'I-MR' : 'p-chart'}
                  </Chip>
                </dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Subgroup size</dt>
                <dd className="font-mono tabular-nums">{chart.subgroup_size}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Limits locked</dt>
                <dd>{chart.limits_locked ? 'Yes' : 'No'}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Sample count</dt>
                <dd className="font-mono tabular-nums">{chart.limits_sample_count ?? '--'}</dd>
              </div>
              {chart.spec_item?.tolerance_min !== null && chart.spec_item?.tolerance_max !== null && (
                <>
                  <div>
                    <dt className="text-2xs uppercase tracking-wider text-muted">Nominal</dt>
                    <dd className="font-mono tabular-nums">
                      {chart.spec_item?.nominal_value ?? '--'} {chart.spec_item?.unit_of_measure ?? ''}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-2xs uppercase tracking-wider text-muted">Tolerance</dt>
                    <dd className="font-mono tabular-nums">
                      {chart.spec_item?.tolerance_min} ... {chart.spec_item?.tolerance_max}
                    </dd>
                  </div>
                </>
              )}
            </dl>
          </Panel>

          {/* X-bar / Individual chart */}
          {points.length > 0 ? (
            <>
              <XBarChart
                points={points}
                ucl={ucl}
                lcl={lcl}
                cl={cl}
                title={`${chartTypeLabel} Chart`}
                dataKey="value"
                height={320}
                renderTooltip={CustomTooltip}
              />
              <XBarChart
                points={points}
                ucl={uclR}
                lcl={lclR}
                cl={clR}
                title={`${rangeLabel} Chart`}
                dataKey="range"
                height={220}
                renderTooltip={RangeTooltip}
              />
            </>
          ) : (
            <Panel title={`${chartTypeLabel} Chart`}>
              <EmptyState
                icon="bar-chart-2"
                title="No data points yet"
                description="Data points are recorded automatically from inspections. Run inspections to populate the chart."
              />
            </Panel>
          )}
        </div>

        {/* ─── Sidebar (right col) ─── */}
        <div className="space-y-4">
          <Panel title="Control limits">
            <dl className="space-y-2 text-sm">
              <div className="flex justify-between">
                <dt className="text-muted">UCL</dt>
                <dd className="font-mono tabular-nums">{ucl !== null ? ucl.toFixed(4) : '--'}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-muted">CL</dt>
                <dd className="font-mono tabular-nums font-medium">{cl !== null ? cl.toFixed(4) : '--'}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-muted">LCL</dt>
                <dd className="font-mono tabular-nums">{lcl !== null ? lcl.toFixed(4) : '--'}</dd>
              </div>
              <div className="border-t border-subtle my-2" />
              <div className="flex justify-between">
                <dt className="text-muted">UCL (R)</dt>
                <dd className="font-mono tabular-nums">{uclR !== null ? uclR.toFixed(4) : '--'}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-muted">CL (R)</dt>
                <dd className="font-mono tabular-nums">{clR !== null ? clR.toFixed(4) : '--'}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-muted">LCL (R)</dt>
                <dd className="font-mono tabular-nums">{lclR !== null ? lclR.toFixed(4) : '--'}</dd>
              </div>
            </dl>
          </Panel>

          <Panel
            title="Alerts"
            meta={`${alerts.length} unresolved`}
          >
            {alerts.length === 0 ? (
              <p className="text-sm text-muted">No unresolved alerts.</p>
            ) : (
              <div className="max-h-80 overflow-y-auto">
                {alerts.map((alert) => (
                  <AlertItem
                    key={alert.id}
                    alert={alert}
                    canManage={can('quality.spc.manage')}
                    isPending={acknowledge.isPending}
                    onAcknowledge={(alertId, notes) =>
                      acknowledge.mutate({ alertId, notes })
                    }
                  />
                ))}
              </div>
            )}
          </Panel>
        </div>
      </div>
    </div>
  );
}
