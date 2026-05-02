import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { mrpPlansApi, type MrpPlanListParams } from '@/api/mrp/mrpPlans';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import type { MrpPlan, MrpPlanStatus } from '@/types/mrp';

const variant: Record<MrpPlanStatus, 'success' | 'neutral' | 'danger'> = {
  active: 'success', superseded: 'neutral', cancelled: 'danger',
};

export default function MrpPlansListPage() {
  const [filters, setFilters] = useState<MrpPlanListParams>({ page: 1, per_page: 25 });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['mrp', 'plans', filters],
    queryFn: () => mrpPlansApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<MrpPlan>[] = [
    {
      key: 'no', header: 'Plan #',
      cell: (r) => (
        <Link to={`/mrp/plans/${r.id}`} className="font-mono text-accent hover:underline">{r.mrp_plan_no}</Link>
      ),
    },
    {
      key: 'so', header: 'Sales order',
      cell: (r) => r.sales_order ? (
        <Link to={`/crm/sales-orders/${r.sales_order.id}`} className="font-mono text-accent hover:underline">
          {r.sales_order.so_number}
        </Link>
      ) : '—',
    },
    { key: 'cust', header: 'Customer', cell: (r) => r.sales_order?.customer?.name ?? '—' },
    { key: 'version', header: 'Version', align: 'right', cell: (r) => <NumCell>v{r.version}</NumCell> },
    {
      key: 'shortages', header: 'Shortages', align: 'right',
      cell: (r) => r.shortages_found > 0
        ? <NumCell className="text-warning-fg">{r.shortages_found}</NumCell>
        : <NumCell>0</NumCell>,
    },
    { key: 'pr', header: 'Auto PRs', align: 'right', cell: (r) => <NumCell>{r.auto_pr_count}</NumCell> },
    { key: 'wo', header: 'Draft WOs', align: 'right', cell: (r) => <NumCell>{r.draft_wo_count}</NumCell> },
    { key: 'status', header: 'Status', cell: (r) => <Chip variant={variant[r.status]}>{r.status}</Chip> },
    { key: 'gen', header: 'Generated', align: 'right', cell: (r) => <NumCell>{r.generated_at?.slice(0, 10)}</NumCell> },
  ];

  const filterConfig: FilterConfig[] = [
    { key: 'status', label: 'Status', type: 'select', options: [
      { value: '', label: 'All' }, { value: 'active', label: 'Active' },
      { value: 'superseded', label: 'Superseded' }, { value: 'cancelled', label: 'Cancelled' },
    ]},
  ];

  return (
    <div>
      <PageHeader title="MRP plans"
        subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'plan' : 'plans'}` : undefined} />
      <FilterBar
        filters={filterConfig} values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
      />
      {isLoading && !data && <SkeletonTable columns={9} rows={6} />}
      {isError && <EmptyState icon="alert-circle" title="Failed to load MRP plans"
        action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
      {data && data.data.length === 0 && (
        <EmptyState icon="layers" title="No MRP plans yet"
          description="Plans are generated automatically when a sales order is confirmed." />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable columns={columns} data={data.data} meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))} />
        </div>
      )}
    </div>
  );
}
