import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { budgetingApi } from '@/api/accounting/budgeting';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import { FullPageLoader } from '@/components/ui/Spinner';
import { cn } from '@/lib/cn';
import type { BudgetVsActual, BudgetVsActualRow } from '@/types/budgeting';

export default function BudgetVsActualPage() {
  const [groupBy, setGroupBy] = useState<string>('department');

  const { data, isLoading, error } = useQuery<BudgetVsActual>({
    queryKey: ['budget-vs-actual'],
    queryFn: () => budgetingApi.budgetVsActual(),
  });

  if (isLoading) return <FullPageLoader />;
  if (error) return <div className="p-6 text-red-500">Failed to load budget vs actual data.</div>;

  // Group rows
  const grouped: Record<string, { rows: BudgetVsActualRow[]; budgeted: number; actual: number }> = {};
  data?.rows.forEach((row) => {
    const key = groupBy === 'department' ? row.department : row.budget_type;
    if (!grouped[key]) grouped[key] = { rows: [], budgeted: 0, actual: 0 };
    grouped[key].rows.push(row);
    grouped[key].budgeted += row.budgeted;
    grouped[key].actual += row.actual;
  });

  const totalVariancePct = data && data.total_budgeted > 0
    ? (data.total_variance / data.total_budgeted * 100) : 0;

  const isFavorable = data && data.total_variance >= 0;

  return (
    <div className="p-6 space-y-6">
      <PageHeader
        title="Budget vs Actual"
        subtitle="P&L comparison — budgeted amounts vs actuals"
        breadcrumbs={[{ label: 'Budgeting', href: '/budgeting' }, { label: 'Budget vs Actual' }]}
        actions={
          <div className="flex items-center gap-2">
            <span className="text-xs text-text-subtle">Group by:</span>
            {['department', 'budget_type'].map((g) => (
              <button
                key={g}
                onClick={() => setGroupBy(g)}
                className={cn(
                  'px-2 py-0.5 text-xs rounded transition-colors',
                  groupBy === g ? 'bg-accent text-white' : 'bg-muted text-secondary hover:bg-muted/80'
                )}
              >
                {g === 'department' ? 'Department' : 'Budget Type'}
              </button>
            ))}
          </div>
        }
      />

      {data && (
        <>
          {/* Summary Cards */}
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <StatCard label="Total Budgeted" value={`₱ ${(data.total_budgeted / 1_000_000).toFixed(2)}M`} />
            <StatCard label="Total Actual" value={`₱ ${(data.total_actual / 1_000_000).toFixed(2)}M`} />
            <StatCard label="Total Variance" value={`${isFavorable ? '+' : ''}${totalVariancePct.toFixed(1)}%`} />
          </div>

          {/* Grouped Summary */}
          <Panel title="Summary by Group">
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-default text-left text-xs uppercase tracking-wider text-text-subtle">
                    <th className="py-2 pr-4">Group</th>
                    <th className="py-2 pr-4 text-right">Budgeted</th>
                    <th className="py-2 pr-4 text-right">Actual</th>
                    <th className="py-2 pr-4 text-right">Variance</th>
                    <th className="py-2 text-right">%</th>
                  </tr>
                </thead>
                <tbody>
                  {Object.entries(grouped).map(([key, group]) => {
                    const variance = group.budgeted - group.actual;
                    const pct = group.budgeted > 0 ? (variance / group.budgeted * 100) : 0;
                    return (
                      <tr key={key} className="border-b border-default/50 hover:bg-elevated/50 transition-colors">
                        <td className="py-2.5 pr-4 font-medium">{key}</td>
                        <td className="py-2.5 pr-4 text-right font-mono">₱{(group.budgeted / 1_000_000).toFixed(2)}M</td>
                        <td className="py-2.5 pr-4 text-right font-mono">₱{(group.actual / 1_000_000).toFixed(2)}M</td>
                        <td className={cn('py-2.5 pr-4 text-right font-mono', variance < 0 ? 'text-red-600' : 'text-green-600')}>
                          {variance >= 0 ? '+' : ''}₱{(Math.abs(variance) / 1_000_000).toFixed(2)}M
                        </td>
                        <td className="py-2.5 text-right">
                          <span className={cn(
                            'inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium',
                            pct < 0 ? 'text-red-600 bg-red-50' : 'text-green-600 bg-green-50'
                          )}>
                            {pct >= 0 ? '+' : ''}{pct.toFixed(1)}%
                          </span>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </Panel>

          {/* Detail Rows */}
          <Panel title="Line Item Detail">
            <div className="overflow-x-auto max-h-[500px] overflow-y-auto">
              <table className="w-full text-sm">
                <thead className="sticky top-0 bg-canvas">
                  <tr className="border-b border-default text-left text-xs uppercase tracking-wider text-text-subtle">
                    <th className="py-2 pr-3">Account</th>
                    <th className="py-2 pr-3">Department</th>
                    <th className="py-2 pr-3 text-right">Budgeted</th>
                    <th className="py-2 pr-3 text-right">Actual</th>
                    <th className="py-2 pr-3 text-right">Variance</th>
                    <th className="py-2 text-right">%</th>
                  </tr>
                </thead>
                <tbody>
                  {data.rows.map((row, i) => {
                    const isOver = row.variance < 0;
                    return (
                      <tr key={i} className="border-b border-default/50 hover:bg-elevated/50 transition-colors">
                        <td className="py-2 pr-3">
                          <span className="font-medium">{row.account_code}</span>
                          <span className="ml-1 text-text-subtle text-xs">{row.account_name}</span>
                        </td>
                        <td className="py-2 pr-3 text-secondary">{row.department}</td>
                        <td className="py-2 pr-3 text-right font-mono">₱{(row.budgeted / 1000).toFixed(0)}K</td>
                        <td className="py-2 pr-3 text-right font-mono">₱{(row.actual / 1000).toFixed(0)}K</td>
                        <td className={cn('py-2 pr-3 text-right font-mono', isOver ? 'text-red-600' : 'text-green-600')}>
                          {row.variance >= 0 ? '+' : ''}{row.variance >= 0 ? '₱' : '-₱'}{(Math.abs(row.variance) / 1000).toFixed(0)}K
                        </td>
                        <td className="py-2 text-right">
                          <span className={cn(
                            'inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium',
                            row.variance_pct < 0 ? 'text-red-600 bg-red-50' : 'text-green-600 bg-green-50'
                          )}>
                            {row.variance_pct >= 0 ? '+' : ''}{row.variance_pct}%
                          </span>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </Panel>
        </>
      )}
    </div>
  );
}
