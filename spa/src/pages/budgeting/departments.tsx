import { useQuery } from '@tanstack/react-query';
import { useParams, Link } from 'react-router-dom';
import { budgetingApi } from '@/api/accounting/budgeting';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import { Badge } from '@/components/ui/Badge';
import { FullPageLoader } from '@/components/ui/Spinner';
import { ArrowLeft, Building2 } from 'lucide-react';
import { cn } from '@/lib/cn';
import type { Budget } from '@/types/budgeting';

const MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'] as const;

export default function DepartmentBudgetDetailPage() {
  const { id } = useParams<{ id: string }>();
  const departmentName = id ? decodeURIComponent(id) : '';

  const { data: budgets, isLoading } = useQuery<Budget[]>({
    queryKey: ['budgets', 'department', departmentName],
    queryFn: async () => {
      const res = await budgetingApi.list({ per_page: 100 });
      // Filter by department name on the client side
      return res.data.filter((b) => b.department?.name === departmentName || (!b.department && departmentName === 'Company-wide'));
    },
    enabled: !!departmentName,
  });

  if (isLoading) return <FullPageLoader />;

  const totalAllocated = budgets?.reduce((s, b) => s + b.total_allocated, 0) ?? 0;
  const totalSpent = budgets?.reduce((s, b) => s + b.total_spent, 0) ?? 0;
  const totalAvailable = budgets?.reduce((s, b) => s + b.available, 0) ?? 0;
  const utilizationPct = totalAllocated > 0 ? (totalSpent / totalAllocated * 100) : 0;

  const getBarColor = (pct: number) => {
    if (pct >= 95) return 'bg-red-500';
    if (pct >= 80) return 'bg-yellow-500';
    return 'bg-green-500';
  };

  return (
    <div className="p-6 space-y-6">
      <PageHeader
        title={`${departmentName} Budget`}
        subtitle={`FY ${new Date().getFullYear()} — Monthly Budget vs Actual Breakdown`}
        breadcrumbs={[{ label: 'Budgeting', href: '/budgeting' }, { label: `${departmentName} Budget` }]}
        actions={
          <Link to="/budgeting" className="inline-flex items-center gap-1.5 text-sm text-secondary hover:text-primary transition-colors">
            <ArrowLeft size={14} /> Back to Overview
          </Link>
        }
      />

      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <StatCard label="Total Allocated" value={`₱ ${(totalAllocated / 1_000_000).toFixed(2)}M`} />
        <StatCard label="Total Spent" value={`₱ ${(totalSpent / 1_000_000).toFixed(2)}M`} />
        <StatCard label="Available" value={`₱ ${(totalAvailable / 1_000_000).toFixed(2)}M`} />
        <StatCard label="Utilization" value={`${utilizationPct.toFixed(1)}%`} />
      </div>

      {/* Utilization bar */}
      <div className="h-3 bg-muted rounded-full overflow-hidden">
        <div
          className={cn('h-full rounded-full transition-all duration-500', getBarColor(utilizationPct))}
          style={{ width: `${Math.min(utilizationPct, 100)}%` }}
        />
      </div>

      {budgets?.map((budget) => (
        <Panel
          key={budget.id}
          title={budget.name}
          meta={
            <div className="flex items-center gap-3">
              <Badge variant={budget.status === 'active' ? 'accent' : 'neutral'}>{budget.status}</Badge>
              <span className={cn(
                'text-xs font-medium px-1.5 py-0.5 rounded',
                budget.utilization_pct >= 95 ? 'text-red-600 bg-red-50' :
                budget.utilization_pct >= 80 ? 'text-yellow-600 bg-yellow-50' : 'text-green-600 bg-green-50'
              )}>
                {budget.utilization_pct}% used
              </span>
            </div>
          }
        >
          {budget.line_items && budget.line_items.length > 0 ? (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-default text-left text-xs uppercase tracking-wider text-text-subtle">
                    <th className="py-2 pr-3 sticky left-0 bg-canvas">Account</th>
                    {MONTHS.map((m) => (
                      <th key={m} className="py-2 pr-3 text-right font-mono">{m}</th>
                    ))}
                    <th className="py-2 pr-3 text-right">Annual</th>
                    <th className="py-2 pr-3 text-right">Actual</th>
                    <th className="py-2 text-right">Variance</th>
                  </tr>
                </thead>
                <tbody>
                  {budget.line_items.map((li) => (
                    <tr key={li.id} className="border-b border-default/50 hover:bg-elevated/50 transition-colors">
                      <td className="py-2 pr-3 sticky left-0 bg-canvas">
                        <span className="font-medium">{li.account?.code}</span>
                        <span className="ml-1.5 text-text-subtle">{li.account?.name}</span>
                      </td>
                      {MONTHS.map((m) => {
                        const monthVal = li[m.toLowerCase() as keyof typeof li] as number;
                        return (
                          <td key={m} className="py-2 pr-3 text-right font-mono">
                            {monthVal > 0 ? `₱${(monthVal / 1000).toFixed(0)}K` : '-'}
                          </td>
                        );
                      })}
                      <td className="py-2 pr-3 text-right font-mono font-medium">₱{(li.annual_total / 1000).toFixed(0)}K</td>
                      <td className="py-2 pr-3 text-right font-mono">₱{(li.actual_total / 1000).toFixed(0)}K</td>
                      <td className={cn(
                        'py-2 text-right font-mono',
                        li.variance < 0 ? 'text-red-600' : 'text-green-600'
                      )}>
                        {li.variance >= 0 ? '+' : ''}{li.variance >= 0 ? '₱' : '-₱'}{(Math.abs(li.variance) / 1000).toFixed(0)}K
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <p className="text-sm text-text-subtle py-4 text-center">No line items configured.</p>
          )}
        </Panel>
      ))}

      {(!budgets || budgets.length === 0) && (
        <div className="text-center py-12 text-text-subtle">
          <Building2 size={48} className="mx-auto mb-3 opacity-40" />
          <p className="text-lg font-medium">No budgets found</p>
          <p className="text-sm mt-1">This department has no budgets configured for the current fiscal year.</p>
        </div>
      )}
    </div>
  );
}
