/** Sprint 8 — Task 70. Assets list. */
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate} from 'react-router-dom';
import { LuCalendarClock, LuPlus } from '@/lib/icons';
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
import { formatPeso } from '@/lib/formatNumber';
import { DepreciationRunner } from './DepreciationRunner';

import { useUrlFilters } from '@/hooks/useUrlFilters';
const STATUS_CHIP: Record<AssetStatus, 'success' | 'warning' | 'neutral'> = {
 active: 'success',
 under_maintenance: 'warning',
 disposed: 'neutral' };

export default function AssetsListPage() {
 const navigate = useNavigate();
 const { can } = usePermission();
 const [filters, setFilters] = useUrlFilters<AssetListParams>({ page: 1, per_page: 25 });
 // Depreciation folded here 2026-08-08 (was /admin/depreciation). Same gate.
 const [showDepreciation, setShowDepreciation] = useState(false);

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['assets', filters],
 queryFn: () => assetsApi.list(filters),
 placeholderData: (prev) => prev });
 const { data: assetOptions } = useQuery({
 queryKey: ['assets', 'options'],
 queryFn: assetsApi.options,
 staleTime: 5 * 60 * 1000 });

 const categoryLabel = new Map((assetOptions?.categories ?? []).map((option) => [option.value, option.label]));
 const statusLabel = new Map((assetOptions?.statuses ?? []).map((option) => [option.value, option.label]));

 const columns: Column<Asset>[] = [
 {
 key: 'asset_code', header: 'Code',
 cell: (r) => <span className="font-mono">{r.asset_code}</span> },
 { key: 'name', header: 'Name', cell: (r) => <span>{r.name}</span> },
 { key: 'category', header: 'Category', cell: (r) => <Chip variant="neutral">{categoryLabel.get(r.category) ?? r.category}</Chip> },
 { key: 'cost', header: 'Acquisition', align: 'right', cell: (r) => <NumCell>{formatPeso(r.acquisition_cost)}</NumCell> },
 { key: 'accum', header: 'Acc. Dep.', align: 'right', cell: (r) => <NumCell>{formatPeso(r.accumulated_depreciation)}</NumCell> },
 { key: 'book', header: 'Book value', align: 'right', cell: (r) => <NumCell>{formatPeso(r.book_value)}</NumCell> },
 { key: 'status', header: 'Status', cell: (r) => <Chip variant={STATUS_CHIP[r.status]}>{r.status_label ?? statusLabel.get(r.status) ?? r.status}</Chip> },
 ];

 const filterConfig: FilterConfig[] = [
 {
 key: 'category', label: 'Category', type: 'select',
 options: [
 { value: '', label: 'All' },
 ...(assetOptions?.categories ?? []),
 ] },
 {
 key: 'status', label: 'Status', type: 'select',
 options: [
 { value: '', label: 'All' },
 ...(assetOptions?.statuses ?? []),
 ] },
 ];

 return (
 <div>
 <PageHeader
 title="Assets"
 subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'asset' : 'assets'}` : undefined}
 actions={
 can('assets.depreciation.view') || can('assets.create') ? (
 <div className="flex items-center gap-2">
 {can('assets.depreciation.view') && (
 <Button variant="secondary" size="sm" icon={<LuCalendarClock size={14} />} onClick={() => setShowDepreciation(true)}>
 Run depreciation
 </Button>
 )}
 {can('assets.create') && (
 <Button variant="primary" size="sm" icon={<LuPlus size={14} />} onClick={() => navigate('/assets/create')}>
 New asset
 </Button>
 )}
 </div>
 ) : undefined
 }
 />
 <DepreciationRunner isOpen={showDepreciation} onClose={() => setShowDepreciation(false)} />
 <FilterBar
 filters={filterConfig}
 values={filters}
 onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
 onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
 searchPlaceholder="Search code or name…"
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
 <DataTable
 onRowClick={(r) => navigate(`/assets/${r.id}`)}
 columns={columns}
 data={data.data}
 meta={data.meta}
 onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
 onPageSizeChange={(per_page) => setFilters((f) => ({ ...f, per_page, page: 1 }))}
 />
 </div>
 )}
 </div>
 );
}
