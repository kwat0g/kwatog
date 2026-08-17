import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useSearchParams, useNavigate } from "react-router-dom";
import { stockLevelsApi } from '@/api/inventory/stock';
import { itemsApi } from '@/api/inventory/items';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { LuArrowLeftRight, LuDownload} from '@/lib/icons';
import { StockMovementsTab } from '@/pages/inventory/movements';
import type { StockLevel } from '@/types/inventory';

import { ColumnSelectorModal } from '@/components/exports/ColumnSelectorModal';
import { usePermission } from '@/hooks/usePermission';
export default function StockLevelsPage() {
 const navigate = useNavigate();
 const [search] = useSearchParams();
 const itemFilter = search.get('item_id') ?? '';
 const typeFilter = search.get('type') ?? '';
 const movementFilter = search.get('movement_id') ?? '';
 // Scope cut 2026-08-08: Stock Movements folded into this page as a view toggle.
 // Deep links (item detail "View movements", stock-adjustment back) land on the
 // movements tab via ?view=movements; ?type=transfer filters the movement list.
 const [view, setView] = useState<'levels' | 'movements'>(search.get('view') === 'movements' ? 'movements' : 'levels');
 const toggleView = () => {
  const next = view === 'levels' ? 'movements' : 'levels';
  setView(next);
  // Keep the URL in sync so refresh/back restore the view (useUrlFilters convention).
  const params = new URLSearchParams(search.toString());
  if (next === 'movements') params.set('view', 'movements');
  else params.delete('view');
  navigate(`?${params.toString()}`, { replace: true });
 };
 const { can } = usePermission();
 const [exportOpen, setExportOpen] = useState(false);
 const [filters, setFilters] = useState<Record<string, unknown>>({ page: 1, per_page: 50, item_id: itemFilter || undefined });

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['inventory', 'stock-levels', filters],
 queryFn: () => stockLevelsApi.list(filters),
 placeholderData: (prev) => prev });
 const { data: itemOptions } = useQuery({
 queryKey: ['inventory', 'items', 'options'],
 queryFn: itemsApi.options,
 staleTime: 5 * 60 * 1000 });

 const columns: Column<StockLevel>[] = [
 { key: 'item', header: 'Item', cell: (r) => (
 <div>
 <span className="font-mono">{r.item?.code}</span>
 <div className="text-xs text-muted">{r.item?.name}</div>
 </div>
 ) },
 { key: 'loc', header: 'Location', cell: (r) => <span className="font-mono">{r.location?.full_code}</span> },
 { key: 'qty', header: 'Quantity', align: 'right', cell: (r) => <NumCell>{Number(r.quantity).toFixed(3)}</NumCell> },
 { key: 'res', header: 'Reserved', align: 'right', cell: (r) => <NumCell>{Number(r.reserved_quantity).toFixed(3)}</NumCell> },
 { key: 'avail', header: 'Available', align: 'right', cell: (r) => <NumCell>{Number(r.available).toFixed(3)}</NumCell> },
 { key: 'wac', header: 'WAC', align: 'right', cell: (r) => <NumCell>{Number(r.weighted_avg_cost).toFixed(4)}</NumCell> },
 { key: 'val', header: 'Total value', align: 'right', cell: (r) => <NumCell className="font-medium">{Number(r.total_value).toFixed(2)}</NumCell> },
 ];

 const filterConfig: FilterConfig[] = [
 { key: 'item_type', label: 'Type', type: 'select', options: [
 { value: '', label: 'All' },
 ...(itemOptions?.item_types ?? []),
 ]},
 ];

 return (
 <div>
 <PageHeader
 title={view === 'levels' ? 'Stock levels' : 'Stock movements'}
 subtitle={view === 'levels' ? (data ? `${data.meta.total} entries` : undefined) : undefined}
 actions={
 <>
 {/* inventory.valuation is an export module the backend already serves; no
 page offered it. */}
 {view === 'levels' && can('inventory.view') && (
 <Button variant="secondary" size="sm" icon={<LuDownload size={14} />}
 onClick={() => setExportOpen(true)}>
 Export
 </Button>
 )}
 <Button variant="secondary" size="sm" icon={<LuArrowLeftRight size={14} />}
 onClick={toggleView}>
 {view === 'levels' ? 'Movements' : 'Stock Levels'}
 </Button>
 </>
 }
 />
 {view === 'movements' ? (
 <StockMovementsTab initialItemId={itemFilter || undefined} initialMovementType={typeFilter || undefined} initialMovementId={movementFilter || undefined} />
 ) : (
 <>
 <FilterBar filters={filterConfig} values={filters}
 onSearch={(s) => setFilters(f => ({ ...f, search: s, page: 1 }))}
 onFilter={(k, v) => setFilters(f => ({ ...f, [k]: v, page: 1 }))}
 searchPlaceholder="Search item…" />
 {isLoading && !data && <SkeletonTable columns={7} rows={8} />}
 {isError && <EmptyState icon="alert-circle" title="Failed to load stock" action={<Button onClick={() => refetch()}>Retry</Button>} />}
 {data && data.data.length === 0 && <EmptyState icon="inbox" title="No stock found" />}
 {data && data.data.length > 0 && (
 <div className="px-5 py-4">
 <DataTable onRowClick={(r) => navigate(`/inventory/items/${r.item?.id}`)}
 columns={columns} data={data.data} meta={data.meta} onPageChange={(page) => setFilters(f => ({ ...f, page }))}
 onPageSizeChange={(per_page) => setFilters(f => ({ ...f, per_page, page: 1 }))} />
 </div>
 )}
 </>
 )}
   <ColumnSelectorModal
     isOpen={exportOpen}
     onClose={() => setExportOpen(false)}
     module="inventory.valuation"
     filters={filters as Record<string, unknown>}
   />
 </div>
 );
}
