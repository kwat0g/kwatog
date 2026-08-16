import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AxiosError } from 'axios';
import { LuRefreshCw } from '@/lib/icons';
import toast from 'react-hot-toast';
import { stockMovementsApi } from '@/api/inventory/stock';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { formatDateTime } from '@/lib/formatDate';
import type { ListParams } from '@/types';
import type { StockMovement } from '@/types/inventory';
import { usePermission } from '@/hooks/usePermission';

import { useUrlFilters } from '@/hooks/useUrlFilters';
const chip = (t: string): 'success' | 'info' | 'warning' | 'danger' | 'neutral' => {
  if (['grn_receipt', 'production_receipt', 'adjustment_in'].includes(t)) return 'success';
  if (['material_issue', 'delivery'].includes(t)) return 'info';
  if (['adjustment_out', 'transfer', 'cycle_count'].includes(t)) return 'warning';
  if (['scrap', 'return_to_vendor'].includes(t)) return 'danger';
  return 'neutral';
};

interface StockMovementListParams extends ListParams {
  item_id?: string;
  movement_id?: string;
  movement_type?: string;
  type?: string;
  pending?: boolean | string;
  from?: string;
  to?: string;
  reference_type?: string;
}

const DEFAULT_FILTERS: StockMovementListParams = {
  page: 1, per_page: 50,
};

export function StockMovementsTab({
  initialItemId,
  initialMovementType,
  initialMovementId,
}: {
  initialItemId?: string;
  initialMovementType?: string;
  initialMovementId?: string;
}) {
 const qc = useQueryClient();
 const { can } = usePermission();
 const [filters, setFilters] = useUrlFilters<StockMovementListParams>({
 ...DEFAULT_FILTERS,
 item_id: initialItemId || undefined,
 movement_type: initialMovementType || undefined,
 movement_id: initialMovementId || undefined,
 });

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['inventory', 'movements', filters],
 queryFn: () => stockMovementsApi.list(filters),
 placeholderData: (prev) => prev,
 });
 const { data: movementOptions } = useQuery({
 queryKey: ['inventory', 'movements', 'options'],
 queryFn: stockMovementsApi.options,
 staleTime: 5 * 60 * 1000,
 });
 const labels = new Map((movementOptions?.movement_types ?? []).map((option) => [option.value, option.label]));
 const retryGl = useMutation({
 mutationFn: (movementId: string) => stockMovementsApi.retryGlHandoff(movementId),
 onSuccess: (movement) => {
 qc.invalidateQueries({ queryKey: ['inventory', 'movements'] });
 toast.success(movement.gl_handoff.status === 'generated' ? 'Journal entry posted.' : 'GL handoff still needs Accounting setup.');
 },
 onError: (error: AxiosError<{ message?: string }>) => {
 toast.error(error.response?.data?.message ?? 'The stock movement could not be posted to the General Ledger.');
 },
 });

 const glChip = (movement: StockMovement) => {
 const handoff = movement.gl_handoff;
 if (!handoff) return <span className="text-muted">—</span>;
 const variant = handoff.status === 'generated' || handoff.status === 'not_required'
 ? 'success' : handoff.status === 'manual_required' ? 'warning' : 'neutral';
 return <div className="flex items-center gap-2">
 <span title={handoff.message ?? undefined}>
 <Chip variant={variant}>{handoff.status_label ?? handoff.status.replace('_', ' ')}</Chip>
 </span>
 {can('accounting.journal.post') && handoff.status === 'manual_required' && (
 <Button
 type="button"
 variant="ghost"
 size="sm"
 icon={<LuRefreshCw size={13} className={retryGl.isPending ? 'animate-spin' : ''} />}
 disabled={retryGl.isPending}
 onClick={() => retryGl.mutate(movement.id)}
 >Retry</Button>
 )}
 </div>;
 };

 const columns: Column<StockMovement>[] = [
 { key: 'created_at', header: 'When', cell: (r) => <span className="font-mono">{formatDateTime(r.created_at)}</span> },
 { key: 'type', header: 'Type', cell: (r) => <Chip variant={chip(r.movement_type)}>{r.movement_type_label ?? labels.get(r.movement_type) ?? r.movement_type.replace(/_/g, ' ')}</Chip> },
 { key: 'item', header: 'Item', cell: (r) => (
 <div>
 <span className="font-mono">{r.item?.code}</span>
 <div className="text-xs text-muted">{r.item?.name}</div>
 </div>
 ) },
 { key: 'from', header: 'From', cell: (r) => <span className="font-mono">{r.from_location?.code ?? '—'}</span> },
 { key: 'to', header: 'To', cell: (r) => <span className="font-mono">{r.to_location?.code ?? '—'}</span> },
 { key: 'qty', header: 'Qty', align: 'right', cell: (r) => <NumCell>{Number(r.quantity).toFixed(3)}</NumCell> },
 { key: 'cost', header: 'Unit cost', align: 'right', cell: (r) => <NumCell>{Number(r.unit_cost).toFixed(4)}</NumCell> },
 { key: 'total', header: 'Total cost', align: 'right', cell: (r) => <NumCell className="font-medium">{Number(r.total_cost).toFixed(2)}</NumCell> },
 { key: 'gl', header: 'GL', cell: glChip },
 { key: 'ref', header: 'Reference', cell: (r) => r.reference_type ? <span className="text-xs">{r.reference_type} #{r.reference_id}</span> : '—' },
 ];

 const filterConfig: FilterConfig[] = [
 { key: 'movement_type', label: 'Type', type: 'select', options: [
 { value: '', label: 'All' },
 ...(movementOptions?.movement_types ?? []),
 ]},
 ];

 return (
 <div>
 <FilterBar filters={filterConfig} values={filters}
 onSearch={() => undefined}
 onFilter={(k, v) => setFilters(f => ({ ...f, [k]: v, page: 1 }))}
 searchPlaceholder="" />
 {isLoading && !data && <SkeletonTable columns={10} rows={10} />}
 {isError && <EmptyState icon="alert-circle" title="Failed to load movements" action={<Button onClick={() => refetch()}>Retry</Button>} />}
 {data && data.data.length === 0 && <EmptyState icon="inbox" title="No movements yet" />}
 {data && data.data.length > 0 && (
 <div className="px-5 py-4">
  <DataTable tableKey="stock-movements" columns={columns} data={data.data} meta={data.meta} onPageChange={(page) => setFilters(f => ({ ...f, page }))} />
 </div>
 )}
 </div>
 );
}
