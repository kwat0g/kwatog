import { cn } from '@/lib/cn';
import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { AlertTriangle, CalendarClock, ClipboardList } from 'lucide-react';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Th, Td, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { DashboardShell, KpiGrid, PanelRow } from '@/components/dashboard/DashboardShell';
import { financeDashboardApi } from '@/api/accounting/dashboard';
import { usePermission } from '@/hooks/usePermission';
import { formatPeso } from '@/lib/formatNumber';
import { ChainBottleneckWidget } from '@/components/dashboard/ChainBottleneckWidget';
import { ForecastPanel } from '@/components/dashboard/ForecastPanel';
import { KpiStrip } from '@/components/dashboard/KpiStrip';
import { BarComparison } from '@/components/charts';

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
 const navigate = useNavigate();
 const { can } = usePermission();

 const summary = useQuery({
 queryKey: ['dashboard', 'finance'],
 queryFn: () => financeDashboardApi.summary(),
 refetchInterval: 60_000,
 });

 type FinanceSummary = NonNullable<typeof summary.data>;

 return (
 <DashboardShell<FinanceSummary>
 title="Finance Officer Dashboard"
 subtitle="Liquidity, receivables, payables, payroll, and budget hygiene at a glance."
 query={summary}
 refreshingQueryKey={['dashboard', 'finance']}
 >
 {(data) => {
 const arAgingChartData = [
 { label: 'Current', amount: parseFloat(data.ar_aging_summary.current) },
 { label: '1-30d', amount: parseFloat(data.ar_aging_summary.d1_30) },
 { label: '31-60d', amount: parseFloat(data.ar_aging_summary.d31_60) },
 { label: '61-90d', amount: parseFloat(data.ar_aging_summary.d61_90) },
 { label: '90+d', amount: parseFloat(data.ar_aging_summary.d91_plus) },
 ];
 const apAgingChartData = [
 { label: 'Current', amount: parseFloat(data.ap_aging_summary.current) },
 { label: '1-30d', amount: parseFloat(data.ap_aging_summary.d1_30) },
 { label: '31-60d', amount: parseFloat(data.ap_aging_summary.d31_60) },
 { label: '61-90d', amount: parseFloat(data.ap_aging_summary.d61_90) },
 { label: '90+d', amount: parseFloat(data.ap_aging_summary.d91_plus) },
 ];

 return (
 <>
 {/* Row 1 — Headline KPIs. */}
 <KpiGrid count={4}>
 <StatCard label="Cash on hand" value={formatPeso(data.cash_balance)} linkTo="/accounting/coa" />
 <StatCard label="AR outstanding" value={formatPeso(data.ar_outstanding)} linkTo="/accounting/invoices" />
 <StatCard label="AP outstanding" value={formatPeso(data.ap_outstanding)} linkTo="/accounting/bills" />
 <StatCard label="Revenue (MTD)" value={formatPeso(data.revenue_mtd)} linkTo="/accounting/income-statement" />
 </KpiGrid>

 {/* KPI Scorecard strip */}
 <KpiStrip codes={['ar_aging_60d', 'budget_utilization']} />

 {/* Row 2 — AR / AP aging side by side. */}
 <PanelRow>
 <AgingPanel title="AR aging" buckets={data.ar_aging_summary} listHref="/accounting/invoices" />
 <AgingPanel title="AP aging" buckets={data.ap_aging_summary} listHref="/accounting/bills" />
 </PanelRow>

 {/* Row 3 — Payroll pipeline + unposted JEs + AP due this week. */}
 <PanelRow cols={3}>
 <PayrollPipelinePanel pipeline={data.payroll_pipeline} />
 <UnpostedJesPanel data={data.unposted_jes} />
 <ApDueThisWeekPanel data={data.ap_due_this_week} />
 </PanelRow>

 {/* Row 4 — Budget vs Actual + Recent JEs. */}
 <PanelRow>
 <BudgetVsActualPanel rows={data.budget_vs_actual_top ?? null} />
 <RecentJesPanel entries={data.recent_journal_entries} />
 </PanelRow>

 {/* Row 4.5 — Chart visualizations */}
 <PanelRow>
 <Panel title="AR Aging Distribution">
 <BarComparison
 data={arAgingChartData}
 bars={[{ dataKey: 'amount', color: 'var(--info)', label: 'Amount' }]}
 xKey="label"
 height={180}
 formatValue={(v) => formatPeso(String(v))}
 />
 </Panel>
 <Panel title="AP Aging Distribution">
 <BarComparison
 data={apAgingChartData}
 bars={[{ dataKey: 'amount', color: 'var(--warning)', label: 'Amount' }]}
 xKey="label"
 height={180}
 formatValue={(v) => formatPeso(String(v))}
 />
 </Panel>
 </PanelRow>

 {/* Row 5 — Revenue forecast */}
 <ForecastPanel
 data={data.revenue_forecast}
 isLoading={false}
 isError={false}
 title="Revenue Forecast (6 months)"
 formatValue={(v) => formatPeso(String(v))}
 unitLabel="PHP"
 />

 {/* Row 6 — Top overdue customers. */}
 {data.top_overdue_customers.length > 0 && (
 <Panel title="Top overdue customers" noPadding bodyClassName="px-1.5 pb-2">
 <div className="overflow-x-auto">
 <table className={tableCls}>
 <thead>
 <tr className={theadTrCls}>
 <Th>Customer</Th>
 <Th align="right">1–30</Th>
 <Th align="right">31–60</Th>
 <Th align="right">61–90</Th>
 <Th align="right">91+</Th>
 <Th align="right">Total</Th>
 </tr>
 </thead>
 <tbody>
 {data.top_overdue_customers.map((c) => (
 <tr key={c.customer_id} className={cn(trCls, "cursor-pointer")} onClick={() => navigate("/accounting/trial-balance")}>
 <Td>{c.customer_name}</Td>
 <Td align="right" mono>{formatPeso(c.d1_30)}</Td>
 <Td align="right" mono>{formatPeso(c.d31_60)}</Td>
 <Td align="right" mono>{formatPeso(c.d61_90)}</Td>
 <Td align="right" mono>{formatPeso(c.d91_plus)}</Td>
 <Td align="right" mono className="font-medium">{formatPeso(c.total)}</Td>
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

 {/* Financial statements — accessible here, not via sidebar. */}
 {can('accounting.statements.view') && (
 <Panel title="Financial Statements">
 <div className="flex flex-wrap gap-4">
 Trial Balance →
 Trial Balance →
 Trial Balance →
 Trial Balance →
 Trial Balance →
 </div>
 </Panel>
 )}
 </>
 );
 }}
 </DashboardShell>
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
 <table className={tableCls}>
 <caption className="sr-only">{title} buckets</caption>
 <thead className="sr-only">
 <tr>
 <Th>Bucket</Th>
 <Th align="right">Amount</Th>
 </tr>
 </thead>
 <tbody>
 {rows.map(([label, value]) => (
 <tr key={label} className={trCls}>
 <Td className="text-muted">{label}</Td>
 <Td align="right" mono>{formatPeso(value)}</Td>
 </tr>
 ))}
 <tr className={trCls}>
 <Td className="font-medium">Total</Td>
 <Td align="right" mono className="font-medium">{formatPeso(buckets.total)}</Td>
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
 <EmptyState size="compact" icon="calendar" title="No payroll runs" description="No payroll periods in the last 90 days." />
 </Panel>
 );
 }
 const stages: Array<{ label: string; value: number; tone: 'neutral' | 'warning' | 'success' | 'info' }> = [
 { label: 'Draft', value: pipeline.draft, tone: 'neutral' },
 { label: 'Processing', value: pipeline.processing, tone: 'warning' },
 { label: 'Approved', value: pipeline.approved, tone: 'info' },
 { label: 'Finalized', value: pipeline.finalized, tone: 'success' },
 { label: 'Disbursed', value: pipeline.disbursed, tone: 'success' },
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
 <div className="text-2xl font-medium font-mono tabular-nums">{data?.count ?? 0}</div>
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
 <div className="font-mono tabular-nums font-medium">{formatPeso(data?.total ?? '0')}</div>
 </div>
 {items.length === 0 ? (
 <EmptyState size="compact" icon="check-circle" title="Nothing due" description="No bills due in the next 7 days." />
 ) : (
 <ul className="text-sm divide-y divide-default">
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
 <EmptyState size="compact" icon="bar-chart" title="No budget data" description="No budget is set for the current fiscal year." />
 </Panel>
 );
 }
 return (
 <Panel
 title="Budget vs Actual (top variances)"
 actions={<Link className="text-xs text-link hover:underline" to="/budgeting/budget-vs-actual">Open →</Link>}
 >
 <table className={tableCls}>
 <thead>
 <tr className={theadTrCls}>
 <Th>Category</Th>
 <Th align="right">Budget</Th>
 <Th align="right">Actual</Th>
 <Th align="right">Util</Th>
 </tr>
 </thead>
 <tbody>
 {rows.map((r, index) => (
 <tr key={`${r.category}-${index}`} className={trCls}>
 <Td className="truncate max-w-[160px]">{r.category}</Td>
 <Td align="right" mono>{formatPeso(r.budget)}</Td>
 <Td align="right" mono>{formatPeso(r.actual)}</Td>
 <Td align="right">
 <Chip variant={utilizationTone(r.variance_pct)}>
 {r.variance_pct > 100 && (
 <AlertTriangle size={12} className="mr-1 inline" aria-hidden="true" />
 )}
 {r.variance_pct.toFixed(1)}%
 </Chip>
 </Td>
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
 <EmptyState size="compact" icon="file-text" title="No journal entries" description="Posted entries will appear here." />
 ) : (
 <ul className="text-sm divide-y divide-default">
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
