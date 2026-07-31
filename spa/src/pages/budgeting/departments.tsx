import { useQuery } from '@tanstack/react-query';
import { useParams, Link } from 'react-router-dom';
import { budgetingApi } from '@/api/accounting/budgeting';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { ArrowLeft, Building2 } from 'lucide-react';
import { cn } from '@/lib/cn';
import type { Budget } from '@/types/budgeting';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

const MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'] as const;

export default function DepartmentBudgetDetailPage() {
  const { id } = useParams<{ id: string }>();
  const departmentName = id ? decodeURIComponent(id) : '';

  const { data: budgets, isLoading, isError, refetch } = useQuery<Budget[]>({
    queryKey: ['budgets', 'department', departmentName],
    queryFn: async () => {
      const res = await budgetingApi.list({ per_page: 100 });
      // Filter by department name on the client side
      return res.data.filter((b) => b.department?.name === departmentName || (!b.department && departmentName === 'Company-wide'));
    },
    enabled: !!departmentName,
  });

  if (isLoading) return <SkeletonDetail />;
  if (isError) return <EmptyState icon="alert-circle" title="Failed to load budgets" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />;

  const totalAllocated = budgets?.reduce((s, b) => s + b.total_allocated, 0) ?? 0;
  const totalSpent = budgets?.reduce((s, b) => s + b.total_spent, 0) ?? 0;
  const totalAvailable = budgets?.reduce((s, b) => s + b.available, 0) ?? 0;
  const utilizationPct = totalAllocated > 0 ? (totalSpent / totalAllocated * 100) : 0;

  const getBarColor = (pct: number) => {
    if (pct >= 95) return 'bg-danger';
    if (pct >= 80) return 'bg-warning';
    return 'bg-success';
  };

  return (
    <div className="p-5 space-y-6">
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
      <div className="h-3 bg-subtle rounded-full overflow-hidden">
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
              <Chip variant={chipVariantForStatus(budget.status)}>{budget.status}</Chip>
              <span className={cn(
                'text-xs font-medium px-1.5 py-0.5 rounded',
                budget.utilization_pct >= 95 ? 'text-danger-fg bg-danger-bg' :
                budget.utilization_pct >= 80 ? 'text-warning-fg bg-warning-bg' : 'text-success-fg bg-success-bg'
              )}>
                {budget.utilization_pct}% used
              </span>
            </div>
          }
        >
          {budget.line_items && budget.line_items.length > 0 ? (
            <div className="overflow-x-auto">
              <table className={tableCls}>
                <thead>
                  <tr className={theadTrCls}>
                    <Th className="sticky left-0 bg-canvas">Account</Th>
                    {MONTHS.map((m) => (
                      <Th align="right" className="font-mono" key={m}>{m}</Th>
                    ))}
                    <Th align="right">Annual</Th>
                    <Th align="right">Actual</Th>
                    <Th align="right">Variance</Th>
                  </tr>
                </thead>
                <tbody>
                  {budget.line_items.map((li) => (
                    <tr key={li.id} className={trCls}>
                      <Td className="sticky left-0 bg-canvas">
                        <span className="font-medium">{li.account?.code}</span>
                        <span className="ml-1.5 text-muted">{li.account?.name}</span>
                      </Td>
                      {MONTHS.map((m) => {
                        const monthVal = li[m.toLowerCase() as keyof typeof li] as number;
                        return (
                          <Td align="right" mono key={m}>
                            {monthVal > 0 ? `₱${(monthVal / 1000).toFixed(0)}K` : '-'}
                          </Td>
                        );
                      })}
                      <Td align="right" mono className="font-medium">₱{(li.annual_total / 1000).toFixed(0)}K</Td>
                      <Td align="right" mono>₱{(li.actual_total / 1000).toFixed(0)}K</Td>
                      <Td align="right" mono className={cn(
 li.variance < 0 ? 'text-danger-fg' : 'text-success-fg'
 )}>
                        {li.variance >= 0 ? '+' : ''}{li.variance >= 0 ? '₱' : '-₱'}{(Math.abs(li.variance) / 1000).toFixed(0)}K
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <p className="text-sm text-muted py-4 text-center">No line items configured.</p>
          )}
        </Panel>
      ))}

      {(!budgets || budgets.length === 0) && (
        <div className="text-center py-12 text-muted">
          <Building2 size={48} className="mx-auto mb-3 opacity-40" />
          <p className="text-lg font-medium">No budgets found</p>
          <p className="text-sm mt-1">This department has no budgets configured for the current fiscal year.</p>
        </div>
      )}
    </div>
  );
}
