/**
 * Sprint P10 — OEE Report page (`/production/oee`).
 *
 * Date-range driven view that aggregates OEE across all machines (or a
 * single machine), showing:
 *   - 4 KPI cards: Overall OEE, Availability, Performance, Quality
 *   - Per-machine table embedding the existing OeeGauge for each row
 *   - Daily OEE trend (recharts LineChart) with a 75% benchmark line
 *   - Downtime breakdown by category (planned, breakdown, etc.)
 *
 * Default window is the current month. Quick presets cover today / week /
 * month / custom. The page is also reachable from the Plant Manager
 * dashboard "OEE · Today" KPI tile (P8 drill-down map).
 */
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import {
  CartesianGrid,
  Line,
  LineChart,
  ReferenceLine,
  ResponsiveContainer,
  Tooltip as RTooltip,
  XAxis,
  YAxis,
} from 'recharts';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { StatCard } from '@/components/ui/StatCard';
import { PageHeader } from '@/components/layout/PageHeader';
import { OeeGauge } from '@/components/production/OeeGauge';
import { oeeApi } from '@/api/production/oee';
import { formatDate } from '@/lib/formatDate';
import type { MachineOeeRow } from '@/types/production';

type Preset = 'today' | 'week' | 'month' | 'custom';

interface Window {
  from: string;
  to: string;
}

function todayIso(): string {
  return new Date().toISOString().slice(0, 10);
}

function isoOffset(days: number): string {
  const d = new Date();
  d.setDate(d.getDate() - days);
  return d.toISOString().slice(0, 10);
}

function startOfMonthIso(): string {
  const d = new Date();
  return new Date(Date.UTC(d.getFullYear(), d.getMonth(), 1)).toISOString().slice(0, 10);
}

function presetWindow(p: Preset): Window {
  switch (p) {
    case 'today':
      return { from: todayIso(), to: todayIso() };
    case 'week':
      return { from: isoOffset(6), to: todayIso() };
    case 'month':
    default:
      return { from: startOfMonthIso(), to: todayIso() };
  }
}

const DOWNTIME_LABELS: Record<string, string> = {
  breakdown: 'Breakdown',
  changeover: 'Changeover',
  material_shortage: 'Material shortage',
  no_order: 'No order',
  planned_maintenance: 'Planned maintenance',
};

const DOWNTIME_COLORS: Record<string, string> = {
  breakdown: 'bg-danger',
  changeover: 'bg-warning',
  material_shortage: 'bg-warning',
  no_order: 'bg-strong',
  planned_maintenance: 'bg-info',
};

function pct(v: number): string {
  return `${(v * 100).toFixed(1)}%`;
}

function fmtMinutes(min: number): string {
  if (min < 60) return `${min}m`;
  const h = Math.floor(min / 60);
  const m = min % 60;
  return m === 0 ? `${h}h` : `${h}h ${m}m`;
}

const machineStatusVariant = (status: string): 'success' | 'info' | 'warning' | 'danger' | 'neutral' => {
  switch (status) {
    case 'running':
      return 'info';
    case 'idle':
      return 'neutral';
    case 'breakdown':
      return 'danger';
    case 'maintenance':
      return 'warning';
    case 'setup':
      return 'warning';
    default:
      return 'neutral';
  }
};

export default function OeeReportPage() {
  const [preset, setPreset] = useState<Preset>('month');
  const [custom, setCustom] = useState<Window>(() => presetWindow('month'));

  const window: Window = preset === 'custom' ? custom : presetWindow(preset);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['production', 'oee', 'report', window],
    queryFn: () => oeeApi.report(window),
    placeholderData: (prev) => prev,
  });

  return (
    <div>
      <PageHeader
        title="OEE Report"
        subtitle={
          data
            ? `${formatDate(data.range.from)} → ${formatDate(data.range.to)} · ${data.machines.length} machine${data.machines.length === 1 ? '' : 's'}`
            : 'Overall Equipment Effectiveness'
        }
      />

      {/* ─── Date range presets ─── */}
      <div className="px-5 py-3 border-b border-default flex items-center gap-3 flex-wrap">
        <div className="flex items-center gap-1">
          {(['today', 'week', 'month', 'custom'] as Preset[]).map((p) => (
            <button
              key={p}
              onClick={() => setPreset(p)}
              className={
                'h-7 px-3 text-xs rounded-md border transition-colors duration-fast ' +
                (preset === p
                  ? 'bg-primary text-canvas border-primary'
                  : 'border-default hover:bg-elevated')
              }
            >
              {p === 'today' ? 'Today' : p === 'week' ? 'Last 7 days' : p === 'month' ? 'This month' : 'Custom'}
            </button>
          ))}
        </div>
        {preset === 'custom' && (
          <div className="flex items-center gap-2 text-xs">
            <input
              type="date"
              value={custom.from}
              onChange={(e) => setCustom((c) => ({ ...c, from: e.target.value }))}
              className="h-7 px-2 rounded-md border border-default bg-canvas text-sm font-mono"
            />
            <span className="text-muted">→</span>
            <input
              type="date"
              value={custom.to}
              onChange={(e) => setCustom((c) => ({ ...c, to: e.target.value }))}
              className="h-7 px-2 rounded-md border border-default bg-canvas text-sm font-mono"
            />
          </div>
        )}
      </div>

      {/* ─── Loading ─── */}
      {isLoading && !data && (
        <div className="px-5 py-4 space-y-4">
          <div className="grid grid-cols-4 gap-3">
            {[1, 2, 3, 4].map((i) => <SkeletonBlock key={i} className="h-20" />)}
          </div>
          <SkeletonBlock className="h-72" />
          <SkeletonBlock className="h-48" />
        </div>
      )}

      {/* ─── Error ─── */}
      {isError && !data && (
        <div className="px-5 py-4">
          <EmptyState
            icon="alert-circle"
            title="Failed to load OEE report"
            action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
          />
        </div>
      )}

      {/* ─── Data ─── */}
      {data && (
        <div className="px-5 py-4 space-y-4">
          {/* KPI cards */}
          <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
            <StatCard
              label="Overall OEE"
              value={pct(data.overall.oee)}
              helper={data.overall.oee >= 0.85 ? 'World-class' : data.overall.oee >= 0.6 ? 'On track' : 'Below benchmark'}
            />
            <StatCard label="Availability" value={pct(data.overall.availability)} />
            <StatCard label="Performance" value={pct(data.overall.performance)} />
            <StatCard label="Quality" value={pct(data.overall.quality)} />
          </div>

          {/* Trend + downtime */}
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
            {/* Trend chart */}
            <div className="lg:col-span-2">
              <Panel title="OEE trend" meta="benchmark 75%">
                {data.trend.length === 0 ? (
                  <p className="text-sm text-muted">Window too large — trend down-sampling not yet implemented for &gt; 92 days.</p>
                ) : (
                  <div className="h-64">
                    <ResponsiveContainer width="100%" height="100%">
                      <LineChart data={data.trend.map((t) => ({ date: t.date, oee: t.oee * 100 }))}
                        margin={{ top: 8, right: 16, bottom: 8, left: 0 }}>
                        <CartesianGrid strokeDasharray="3 3" stroke="var(--border-subtle)" />
                        <XAxis
                          dataKey="date"
                          tick={{ fontSize: 10, fill: 'var(--text-muted)' }}
                          tickFormatter={(v: string) => v.slice(5)}
                        />
                        <YAxis
                          domain={[0, 100]}
                          tick={{ fontSize: 10, fill: 'var(--text-muted)' }}
                          tickFormatter={(v: number) => `${v}%`}
                        />
                        <RTooltip
                          contentStyle={{
                            background: 'var(--bg-elevated)',
                            border: '1px solid var(--border-default)',
                            borderRadius: 6,
                            fontSize: 12,
                          }}
                          formatter={(v: number) => [`${v.toFixed(1)}%`, 'OEE']}
                        />
                        <ReferenceLine y={75} stroke="var(--success)" strokeDasharray="4 4" />
                        <Line
                          type="monotone"
                          dataKey="oee"
                          stroke="var(--accent)"
                          strokeWidth={2}
                          dot={false}
                          activeDot={{ r: 4 }}
                        />
                      </LineChart>
                    </ResponsiveContainer>
                  </div>
                )}
              </Panel>
            </div>

            {/* Downtime breakdown */}
            <Panel title="Downtime by category">
              {data.downtime_breakdown.length === 0 ? (
                <p className="text-sm text-muted">No downtime recorded in this window.</p>
              ) : (
                <ul className="space-y-2.5">
                  {(() => {
                    const total = data.downtime_breakdown.reduce((a, x) => a + x.minutes, 0);
                    return data.downtime_breakdown.map((d) => {
                      const pctOfTotal = total === 0 ? 0 : (d.minutes / total) * 100;
                      return (
                        <li key={d.category}>
                          <div className="flex items-center justify-between text-sm mb-1">
                            <span>{DOWNTIME_LABELS[d.category] ?? d.category}</span>
                            <span className="font-mono tabular-nums text-muted">{fmtMinutes(d.minutes)}</span>
                          </div>
                          <div className="h-1 bg-subtle rounded-full overflow-hidden">
                            <div
                              className={`h-full rounded-full ${DOWNTIME_COLORS[d.category] ?? 'bg-strong'}`}
                              style={{ width: `${pctOfTotal}%` }}
                              aria-hidden
                            />
                          </div>
                        </li>
                      );
                    });
                  })()}
                </ul>
              )}
            </Panel>
          </div>

          {/* Per-machine table */}
          <Panel
            title="Per machine"
            meta={`${data.machines.length} ${data.machines.length === 1 ? 'machine' : 'machines'}`}
            noPadding
          >
            {data.machines.length === 0 ? (
              <p className="px-4 py-6 text-sm text-muted">No machines configured.</p>
            ) : (
              <table className="w-full text-xs">
                <thead className="bg-subtle">
                  <tr>
                    <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">Code</th>
                    <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">Name</th>
                    <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">Status</th>
                    <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">OEE</th>
                    <th className="h-8 px-2.5 text-right text-2xs uppercase tracking-wider text-muted font-medium">Run time</th>
                    <th className="h-8 px-2.5 text-right text-2xs uppercase tracking-wider text-muted font-medium">Downtime</th>
                  </tr>
                </thead>
                <tbody>
                  {data.machines.map((m: MachineOeeRow) => (
                    <tr key={m.machine_id} className="border-t border-subtle align-top">
                      <td className="px-2.5 py-2 font-mono">
                        <Link to={`/mrp/machines/${m.machine_id}`} className="text-accent hover:underline">
                          {m.machine_code}
                        </Link>
                      </td>
                      <td className="px-2.5 py-2">
                        <div>{m.name}</div>
                        {m.tonnage != null && (
                          <div className="text-2xs text-muted font-mono tabular-nums">{m.tonnage}t</div>
                        )}
                      </td>
                      <td className="px-2.5 py-2">
                        <Chip variant={machineStatusVariant(m.status)}>{m.status}</Chip>
                      </td>
                      <td className="px-2.5 py-2 min-w-[280px]">
                        <OeeGauge result={m} compact />
                      </td>
                      <td className="px-2.5 py-2 text-right font-mono tabular-nums">
                        {fmtMinutes(m.diagnostics.run_time)}
                      </td>
                      <td className="px-2.5 py-2 text-right font-mono tabular-nums">
                        {fmtMinutes(m.diagnostics.planned_downtime + m.diagnostics.unplanned_downtime)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </Panel>
        </div>
      )}
    </div>
  );
}
