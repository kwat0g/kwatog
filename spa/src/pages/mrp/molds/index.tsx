import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { moldsApi, type MoldListParams } from '@/api/mrp/molds';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import type { Mold, MoldStatus } from '@/types/mrp';

const variant: Record<MoldStatus, 'success' | 'info' | 'warning' | 'danger' | 'neutral'> = {
  available: 'success', in_use: 'info', maintenance: 'warning', retired: 'neutral',
};

export default function MoldsListPage() {
  const [filters, setFilters] = useState<MoldListParams>({ page: 1, per_page: 25 });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['mrp', 'molds', filters],
    queryFn: () => moldsApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<Mold>[] = [
    {
      key: 'code', header: 'Code',
      cell: (r) => (
        <Link to={`/mrp/molds/${r.id}`} className="font-mono text-accent hover:underline">{r.mold_code}</Link>
      ),
    },
    { key: 'name', header: 'Name', cell: (r) => r.name },
    {
      key: 'product', header: 'Product',
      cell: (r) => r.product ? <span><span className="font-mono">{r.product.part_number}</span> · {r.product.name}</span> : '—',
    },
    { key: 'cavity', header: 'Cavities', align: 'right', cell: (r) => <NumCell>{r.cavity_count}</NumCell> },
    { key: 'rate', header: 'Output / hr', align: 'right', cell: (r) => <NumCell>{r.output_rate_per_hour.toLocaleString()}</NumCell> },
    {
      key: 'shots', header: 'Shots', align: 'right',
      cell: (r) => (
        <div className="flex flex-col items-end gap-0.5 min-w-[120px]">
          <span className="font-mono tabular-nums text-xs">
            {r.current_shot_count.toLocaleString()} / {r.max_shots_before_maintenance.toLocaleString()}
          </span>
          <div className="w-full h-1 bg-elevated rounded-full overflow-hidden">
            <div
              className={`h-1 rounded-full ${
                r.shot_percentage >= 100 ? 'bg-danger' :
                r.shot_percentage >= 80 ? 'bg-warning' : 'bg-success'
              }`}
              style={{ width: `${Math.min(100, r.shot_percentage)}%` }}
              aria-hidden
            />
          </div>
        </div>
      ),
    },
    { key: 'machines', header: 'Compatible machines', align: 'right', cell: (r) => <NumCell>{r.compatible_machines_count}</NumCell> },
    { key: 'status', header: 'Status', cell: (r) => <Chip variant={variant[r.status]}>{r.status_label}</Chip> },
  ];

  const filterConfig: FilterConfig[] = [
    { key: 'status', label: 'Status', type: 'select', options: [
      { value: '', label: 'All' }, { value: 'available', label: 'Available' },
      { value: 'in_use', label: 'In use' }, { value: 'maintenance', label: 'Maintenance' },
      { value: 'retired', label: 'Retired' },
    ]},
    { key: 'nearing_limit', label: 'Nearing limit', type: 'select', options: [
      { value: '', label: 'All' }, { value: 'true', label: 'Nearing limit (≥ 80%)' },
    ]},
  ];

  return (
    <div>
      <PageHeader title="Molds"
        subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'mold' : 'molds'}` : undefined} />
      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search by code or name…"
      />
      {isLoading && !data && <SkeletonTable columns={8} rows={8} />}
      {isError && <EmptyState icon="alert-circle" title="Failed to load molds"
        action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
      {data && data.data.length === 0 && <EmptyState icon="package" title="No molds configured" />}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable columns={columns} data={data.data} meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))} />
        </div>
      )}
    </div>
  );
}
