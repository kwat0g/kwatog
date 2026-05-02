import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { financeDashboardApi } from '@/api/accounting/dashboard';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { formatPeso } from '@/lib/formatNumber';
import { formatDate } from '@/lib/formatDate';

/**
 * Sprint 4 / Task 37 — Finance dashboard section.
 * Drops into pages/dashboard/index.tsx for any user with `accounting.dashboard.view`.
 */
export function FinanceSection() {
  const navigate = useNavigate();
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['accounting', 'dashboard', 'summary'],
    queryFn: () => financeDashboardApi.summary(),
    staleTime: 30_000,
  });

  if (isLoading && !data) {
    return (
      <div className="px-5 py-4 space-y-4">
        <div className="grid grid-cols-4 gap-4">{[1, 2, 3, 4].map((i) => <SkeletonBlock key={i} className="h-20" />)}</div>
        <SkeletonBlock className="h-48" />
      </div>
    );
  }
  if (isError) {
    return <EmptyState icon="alert-circle" title="Failed to load finance summary" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />;
  }
  if (!data) return null;

  return (
    <div className="px-5 py-4 space-y-4">
      <div className="grid grid-cols-4 gap-4">
        <StatCard label="Cash Balance"   value={formatPeso(data.cash_balance)} className="cursor-pointer" />
        <button onClick={() => navigate('/accounting/invoices?status=finalized')} className="text-left">
          <StatCard label="AR Outstanding" value={formatPeso(data.ar_outstanding)} className="cursor-pointer hover:bg-elevated transition-colors" />
        </button>
        <button onClick={() => navigate('/accounting/bills?status=unpaid')} className="text-left">
          <StatCard label="AP Outstanding" value={formatPeso(data.ap_outstanding)} className="cursor-pointer hover:bg-elevated transition-colors" />
        </button>
        <button onClick={() => navigate('/accounting/income-statement')} className="text-left">
          <StatCard label="Revenue MTD"    value={formatPeso(data.revenue_mtd)}    className="cursor-pointer hover:bg-elevated transition-colors" />
        </button>
      </div>

      <div className="grid grid-cols-2 gap-4">
        <Panel title="Aging" meta="AR · AP">
          <div className="text-sm">
            <div className="font-mono tabular-nums">
              <Row label="AR — Current"  value={data.ar_aging_summary.current} />
              <Row label="AR — 1–30"     value={data.ar_aging_summary.d1_30} />
              <Row label="AR — 31–60"    value={data.ar_aging_summary.d31_60} />
              <Row label="AR — 61–90"    value={data.ar_aging_summary.d61_90} />
              <Row label="AR — 91+"      value={data.ar_aging_summary.d91_plus} danger />
              <Row label="AR Total"      value={data.ar_aging_summary.total} bold />
            </div>
            <div className="font-mono tabular-nums mt-3 pt-3 border-t border-default">
              <Row label="AP — Current"  value={data.ap_aging_summary.current} />
              <Row label="AP — 1–30"     value={data.ap_aging_summary.d1_30} />
              <Row label="AP — 31–60"    value={data.ap_aging_summary.d31_60} />
              <Row label="AP — 61–90"    value={data.ap_aging_summary.d61_90} />
              <Row label="AP — 91+"      value={data.ap_aging_summary.d91_plus} danger />
              <Row label="AP Total"      value={data.ap_aging_summary.total} bold />
            </div>
          </div>
        </Panel>

        <Panel title="Recent Journal Entries" meta="last 10">
          {data.recent_journal_entries.length === 0 ? (
            <p className="text-sm text-muted">No entries yet.</p>
          ) : (
            <ul className="text-sm divide-y divide-subtle">
              {data.recent_journal_entries.map((je) => (
                <li key={je.id} className="py-1.5">
                  <Link to={`/accounting/journal-entries/${je.id}`} className="block hover:bg-subtle px-2 -mx-2 rounded">
                    <div className="flex items-baseline justify-between">
                      <span className="font-mono text-accent">{je.entry_number}</span>
                      <span className="font-mono tabular-nums">{formatPeso(je.total_debit)}</span>
                    </div>
                    <div className="text-xs text-muted truncate">{formatDate(je.date)} · {je.description}</div>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </Panel>
      </div>

      {data.top_overdue_customers.length > 0 && (
        <Panel title="Top overdue customers">
          <table className="w-full text-sm">
            <thead className="text-2xs uppercase tracking-wider text-muted">
              <tr className="border-b border-default">
                <th className="h-7 px-2.5 text-left">Customer</th>
                <th className="h-7 px-2.5 text-right">1–30</th>
                <th className="h-7 px-2.5 text-right">31–60</th>
                <th className="h-7 px-2.5 text-right">61–90</th>
                <th className="h-7 px-2.5 text-right">91+</th>
                <th className="h-7 px-2.5 text-right">Total</th>
              </tr>
            </thead>
            <tbody>
              {data.top_overdue_customers.map((c) => (
                <tr key={c.customer_id} className="h-7 border-b border-subtle hover:bg-subtle">
                  <td className="px-2.5"><Link to={`/accounting/customers/${c.customer_id}`} className="text-accent hover:underline">{c.customer_name}</Link></td>
                  <td className="px-2.5 text-right font-mono tabular-nums">{formatPeso(c.d1_30)}</td>
                  <td className="px-2.5 text-right font-mono tabular-nums">{formatPeso(c.d31_60)}</td>
                  <td className="px-2.5 text-right font-mono tabular-nums">{formatPeso(c.d61_90)}</td>
                  <td className="px-2.5 text-right font-mono tabular-nums text-danger-fg">{formatPeso(c.d91_plus)}</td>
                  <td className="px-2.5 text-right font-mono tabular-nums font-medium">{formatPeso(c.total)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>
      )}
    </div>
  );
}

function Row({ label, value, danger, bold }: { label: string; value: string; danger?: boolean; bold?: boolean }) {
  return (
    <div className="flex justify-between py-0.5">
      <span className="text-muted">{label}</span>
      <span className={(danger ? 'text-danger-fg ' : '') + (bold ? 'font-medium ' : '')}>{formatPeso(value)}</span>
    </div>
  );
}
