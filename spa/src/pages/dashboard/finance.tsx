import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { AlertTriangle, CalendarClock, ClipboardList } from 'lucide-react';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { Button } from '@/components/ui/Button';
import { financeDashboardApi } from '@/api/accounting/dashboard';
import { usePermission } from '@/hooks/usePermission';
import { formatPeso } from '@/lib/formatNumber';
import { ChainBottleneckWidget } from '@/components/dashboard/ChainBottleneckWidget';
import { ForecastPanel } from '@/components/dashboard/ForecastPanel';

/**
 * Task D5 — Finance Officer dashboard.
 *
 * Mounted at `/dashboard/finance` (the legacy `/dashboard/accounting` URL is
 * permanently redirected here by the route table). Built directly against
 * `financeDashboardApi.summary()` rather than going through the generic
 * `<RoleDashboard>` engine so we can present the rich, opinionated layout
 * Finance asks for: liquidity KPIs ▸ AR/AP aging ▸ AP due this week ▸
 * payroll pipeline + unposted JEs ▸ budget-vs-actual ▸ recent JEs.
 */
export default function FinanceDashboardPage() {
  const { can } = usePermission();

  const summary = useQuery({
    queryKey: ['dashboard', 'finance'],
    queryFn: () => financeDashboardApi.summary(),
    refetchInterval: 60_000,
  });

  return (
    <div>
      <PageHeader
        title="Finance Officer Dashboard"
        subtitle="Liquidity, receivables, payables, payroll, and budget hygiene at a glance."
      />

      <div className="px-5 py-4 space-y-4">
        {summary.isLoading && !summary.data && <SkeletonDetail />}

        {summary.isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load finance dashboard"
            description="We couldn't reach the finance summary endpoint."
            action={
              <Button variant="secondary" onClick={() => summary.refetch()}>
                Retry
              </Button>
            }
          />
        )}

        {summary.data && (
          <>
            {/* Row 1 — Headline KPIs. */}
            <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
              <StatCard
                label="Cash on hand"
                value={formatPeso(summary.data.cash_balance)}
                linkTo="/accounting/coa"
              />
              <StatCard
                label="AR outstanding"
                value={formatPeso(summary.data.ar_outstanding)}
                linkTo="/accounting/invoices"
              />
              <StatCard
                label="AP outstanding"
                value={formatPeso(summary.data.ap_outstanding)}
                linkTo="/accounting/bills"
              />
              <StatCard
                label="Revenue (MTD)"
                value={formatPeso(summary.data.revenue_mtd)}
                linkTo="/accounting/income-statement"
              />
            </div>

            {/* Row 2 — AR / AP aging side by side. */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
              <AgingPanel title="AR aging" buckets={summary.data.ar_aging_summary} listHref="/accounting/invoices" />
              <AgingPanel title="AP aging" buckets={summary.data.ap_aging_summary} listHref="/accounting/bills" />
            </div>

            {/* Row 3 — Payroll pipeline + unposted JEs + AP due this week. */}
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-3">
              <PayrollPipelinePanel pipeline={summary.data.payroll_pipeline} />
              <UnpostedJesPanel data={summary.data.unposted_jes} />
              <ApDueThisWeekPanel data={summary.data.ap_due_this_week} />
            </div>

            {/* Row 4 — Budget vs Actual + Recent JEs. */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
              <BudgetVsActualPanel rows={summary.data.budget_vs_actual_top ?? null} />
              <RecentJesPanel entries={summary.data.recent_journal_entries} />
            </div>

            {/* Row 5 — Revenue forecast */}
            <ForecastPanel
              data={summary.data.revenue_forecast}
              isLoading={summary.isLoading}
              isError={summary.isError}
              title="Revenue Forecast (6 months)"
              formatValue={(v) => formatPeso(String(v))}
              unitLabel="PHP"
            />

            {/* Row 6 — Top overdue customers. */}
            {summary.data.top_overdue_customers.length > 0 && (
              <Panel title="Top overdue customers">
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="text-left text-muted border-b border-border">
                        <th className="py-2 px-2 font-medium">Customer</th>
                        <th className="py-2 px-2 font-medium text-right">1–30</th>
                        <th className="py-2 px-2 font-medium text-right">31–60</th>
                        <th className="py-2 px-2 font-medium text-right">61–90</th>
                        <th className="py-2 px-2 font-medium text-right">91+</th>
                        <th className="py-2 px-2 font-medium text-right">Total</th>
                      </tr>
                    </thead>
                    <tbody>
                      {summary.data.top_overdue_customers.map((c) => (
                        <tr key={c.customer_id} className="border-b border-border last:border-0">
                          <td className="py-2 px-2">{c.customer_name}</td>
                          <td className="py-2 px-2 text-right font-mono tabular-nums">{formatPeso(c.d1_30)}</td>
                          <td className="py-2 px-2 text-right font-mono tabular-nums">{formatPeso(c.d31_60)}</td>
                          <td className="py-2 px-2 text-right font-mono tabular-nums">{formatPeso(c.d61_90)}</td>
                          <td className="py-2 px-2 text-right font-mono tabular-nums">{formatPeso(c.d91_plus)}</td>
                          <td className="py-2 px-2 text-right font-mono tabular-nums font-semibold">{formatPeso(c.total)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </Panel>
            )}

            {/* Series C / Task C5 — Finance bottleneck widget (kept from old page). */}
            {can('dashboard.view_bottlenecks') && (
              <ChainBottleneckWidget audience="finance_officer" hideWhenEmpty />
            )}
          </>
        )}
      </div>
    </div>
  );
}

/* ── Sub-panels ─────────────────────────────────────────────────────────── */

function AgingPanel({
  title, buckets, listHref,
}: {
  title: string;
  buckets: { current: string; d1_30: string; d31_60: string; d61_90: string; d91_plus: string; total: string };
  listHref: string;
}) {
  const rows: Array<[string, string]> = [
    ['Current', buckets.current],
    ['1–30 days', buckets.d1_30],
    ['31–60 days', buckets.d31_60],
    ['61–90 days', buckets.d61_90],
    ['91+ days', buckets.d91_plus],
  ];
  return (
    <Panel
      title={title}
      actions={<Link className="text-xs text-link hover:underline" to={listHref}>Open list →</Link>}
    >
      <table className="w-full text-sm">
        <tbody>
          {rows.map(([label, value]) => (
            <tr key={label} className="border-b border-border last:border-0">
              <td className="py-1.5 text-muted">{label}</td>
              <td className="py-1.5 text-right font-mono tabular-nums">{formatPeso(value)}</td>
            </tr>
          ))}
          <tr>
            <td className="pt-2 font-medium">Total</td>
            <td className="pt-2 text-right font-mono tabular-nums font-semibold">{formatPeso(buckets.total)}</td>
          </tr>
        </tbody>
      </table>
    </Panel>
  );
}

function PayrollPipelinePanel({
  pipeline,
}: {
  pipeline: NonNullable<import('@/types/accounting').FinanceDashboardSummary['payroll_pipeline']> | undefined;
}) {
  if (!pipeline || pipeline.total === 0) {
    return (
      <Panel title="Payroll pipeline (last 90 days)">
        <p className="text-sm text-muted">No payroll periods in the last 90 days.</p>
      </Panel>
    );
  }
  const stages: Array<{ label: string; value: number; tone: 'neutral' | 'warning' | 'success' | 'info' }> = [
    { label: 'Draft',      value: pipeline.draft,      tone: 'neutral' },
    { label: 'Processing', value: pipeline.processing, tone: 'warning' },
    { label: 'Approved',   value: pipeline.approved,   tone: 'info' },
    { label: 'Finalized',  value: pipeline.finalized,  tone: 'success' },
    { label: 'Disbursed',  value: pipeline.disbursed,  tone: 'success' },
  ];
  return (
    <Panel
      title="Payroll pipeline (last 90 days)"
      actions={<Link className="text-xs text-link hover:underline" to="/payroll/periods">Open →</Link>}
    >
      <div className="space-y-2">
        {stages.map((s) => (
          <div key={s.label} className="flex items-center justify-between text-sm">
            <span className="flex items-center gap-2">
              <Chip variant={s.tone}>{s.label}</Chip>
            </span>
            <span className="font-mono tabular-nums">{s.value}</span>
          </div>
        ))}
      </div>
    </Panel>
  );
}

function UnpostedJesPanel({
  data,
}: {
  data: { count: number; oldest_date: string | null } | undefined;
}) {
  return (
    <Panel
      title="Unposted JEs"
      actions={<Link className="text-xs text-link hover:underline" to="/accounting/journal-entries?status=draft">Open →</Link>}
    >
      <div className="flex items-start gap-3">
        <div className="shrink-0 mt-0.5 text-muted"><ClipboardList size={20} /></div>
        <div>
          <div className="text-2xl font-semibold font-mono tabular-nums">{data?.count ?? 0}</div>
          {(data?.count ?? 0) === 0 ? (
            <p className="text-xs text-muted mt-1">All journal entries are posted.</p>
          ) : (
            <p className="text-xs text-muted mt-1">
              Oldest draft: <span className="font-mono">{data?.oldest_date ?? '—'}</span>
            </p>
          )}
        </div>
      </div>
    </Panel>
  );
}

function ApDueThisWeekPanel({
  data,
}: {
  data: NonNullable<import('@/types/accounting').FinanceDashboardSummary['ap_due_this_week']> | undefined;
}) {
  const items = data?.items ?? [];
  return (
    <Panel
      title="AP due this week"
      actions={<Link className="text-xs text-link hover:underline" to="/accounting/bills">Open bills →</Link>}
    >
      <div className="flex items-baseline justify-between mb-2">
        <div className="flex items-center gap-2">
          <CalendarClock size={16} className="text-muted" />
          <span className="text-sm text-muted">{data?.count ?? 0} bills</span>
        </div>
        <div className="font-mono tabular-nums font-semibold">{formatPeso(data?.total ?? '0')}</div>
      </div>
      {items.length === 0 ? (
        <p className="text-sm text-muted">No bills due in the next 7 days.</p>
      ) : (
        <ul className="text-sm divide-y divide-border">
          {items.map((it) => (
            <li key={it.id} className="py-1.5 flex items-center justify-between gap-2">
              <Link to={`/accounting/bills/${it.id}`} className="truncate hover:underline">
                <span className="font-mono text-xs text-muted">{it.bill_number}</span>{' '}
                {it.vendor_name}
              </Link>
              <span className="shrink-0 flex items-center gap-2">
                <span className="text-xs text-muted">{it.due_date}</span>
                <span className="font-mono tabular-nums">{formatPeso(it.balance)}</span>
              </span>
            </li>
          ))}
        </ul>
      )}
    </Panel>
  );
}

function BudgetVsActualPanel({
  rows,
}: {
  rows: Array<{ category: string; budget: string; actual: string; variance: string; variance_pct: number }> | null;
}) {
  if (rows === null || rows.length === 0) {
    return (
      <Panel title="Budget vs Actual (top variances)">
        <p className="text-sm text-muted">No budget data for the current fiscal year.</p>
      </Panel>
    );
  }
  return (
    <Panel
      title="Budget vs Actual (top variances)"
      actions={<Link className="text-xs text-link hover:underline" to="/budgeting/budget-vs-actual">Open →</Link>}
    >
      <table className="w-full text-sm">
        <thead>
          <tr className="text-left text-muted border-b border-border">
            <th className="py-1.5 font-medium">Category</th>
            <th className="py-1.5 font-medium text-right">Budget</th>
            <th className="py-1.5 font-medium text-right">Actual</th>
            <th className="py-1.5 font-medium text-right">Util</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.category} className="border-b border-border last:border-0">
              <td className="py-1.5 truncate max-w-[160px]">{r.category}</td>
              <td className="py-1.5 text-right font-mono tabular-nums">{formatPeso(r.budget)}</td>
              <td className="py-1.5 text-right font-mono tabular-nums">{formatPeso(r.actual)}</td>
              <td className="py-1.5 text-right">
                <Chip variant={utilizationTone(r.variance_pct)}>
                  {r.variance_pct > 100 && (
                    <AlertTriangle size={12} className="mr-1 inline" aria-hidden="true" />
                  )}
                  {r.variance_pct.toFixed(1)}%
                </Chip>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </Panel>
  );
}

function RecentJesPanel({
  entries,
}: {
  entries: Array<{ id: string; entry_number: string; date: string; description: string; total_debit: string; reference: string | null }>;
}) {
  return (
    <Panel
      title="Recent journal entries"
      actions={<Link className="text-xs text-link hover:underline" to="/accounting/journal-entries">Open →</Link>}
    >
      {entries.length === 0 ? (
        <p className="text-sm text-muted">No journal entries yet.</p>
      ) : (
        <ul className="text-sm divide-y divide-border">
          {entries.slice(0, 6).map((je) => (
            <li key={je.id} className="py-1.5 flex items-center justify-between gap-2">
              <Link to={`/accounting/journal-entries/${je.id}`} className="truncate hover:underline">
                <span className="font-mono text-xs text-muted">{je.entry_number}</span>{' '}
                {je.description}
              </Link>
              <span className="shrink-0 flex items-center gap-2">
                <span className="text-xs text-muted">{je.date}</span>
                <span className="font-mono tabular-nums">{formatPeso(je.total_debit)}</span>
              </span>
            </li>
          ))}
        </ul>
      )}
    </Panel>
  );
}

/* ── Helpers ────────────────────────────────────────────────────────────── */

function utilizationTone(pct: number): 'success' | 'warning' | 'danger' | 'neutral' {
  if (!Number.isFinite(pct) || pct <= 0) return 'neutral';
  if (pct > 100) return 'danger';
  if (pct >= 80) return 'warning';
  return 'success';
}
