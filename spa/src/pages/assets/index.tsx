/** Sprint 8 — Task 70. Assets list. */
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { Plus } from 'lucide-react';
import { assetsApi, type AssetListParams } from '@/api/assets';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import type { Asset, AssetStatus } from '@/types/assets';

const STATUS_CHIP: Record<AssetStatus, 'success' | 'warning' | 'neutral'> = {
  active: 'success',
  under_maintenance: 'warning',
  disposed: 'neutral',
};

export default function AssetsListPage() {
  const navigate = useNavigate();
  const { can } = usePermission();
  const [filters, setFilters] = useState<AssetListParams>({ page: 1, per_page: 25 });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['assets', filters],
    queryFn: () => assetsApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<Asset>[] = [
    {
      key: 'asset_code', header: 'Code',
      cell: (r) => <Link to={`/assets/${r.id}`} className="font-mono text-accent hover:underline">{r.asset_code}</Link>,
    },
    { key: 'name', header: 'Name', cell: (r) => <span>{r.name}</span> },
    { key: 'category', header: 'Category', cell: (r) => <Chip variant="neutral">{r.category}</Chip> },
    { key: 'cost',  header: 'Acquisition', align: 'right', cell: (r) => <NumCell>₱{r.acquisition_cost}</NumCell> },
    { key: 'accum', header: 'Acc. Dep.',   align: 'right', cell: (r) => <NumCell>₱{r.accumulated_depreciation}</NumCell> },
    { key: 'book',  header: 'Book value',  align: 'right', cell: (r) => <NumCell>₱{r.book_value}</NumCell> },
    { key: 'status', header: 'Status', cell: (r) => <Chip variant={STATUS_CHIP[r.status]}>{r.status.replace('_', ' ')}</Chip> },
  ];

  const filterConfig: FilterConfig[] = [
    {
      key: 'category', label: 'Category', type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'machine', label: 'Machine' },
        { value: 'mold', label: 'Mold' },
        { value: 'vehicle', label: 'Vehicle' },
        { value: 'equipment', label: 'Equipment' },
        { value: 'furniture', label: 'Furniture' },
        { value: 'other', label: 'Other' },
      ],
    },
    {
      key: 'status', label: 'Status', type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'active', label: 'Active' },
        { value: 'under_maintenance', label: 'Under maintenance' },
        { value: 'disposed', label: 'Disposed' },
      ],
    },
  ];

  return (
    <div>
      <PageHeader
        title="Assets"
        subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'asset' : 'assets'}` : undefined}
        actions={
          can('assets.create') ? (
            <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => navigate('/assets/create')}>
              New asset
            </Button>
          ) : undefined
        }
      />
      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search by code or name…"
      />
      {isLoading && !data && <SkeletonTable columns={7} rows={6} />}
      {isError && (
        <EmptyState icon="alert-circle" title="Failed to load assets"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
      )}
      {data && data.data.length === 0 && (
        <EmptyState icon="package" title="No assets" description="Register a fixed asset to track its depreciation and disposal."
          action={can('assets.create') ? (
            <Button variant="primary" onClick={() => navigate('/assets/create')}>New asset</Button>
          ) : undefined} />
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
