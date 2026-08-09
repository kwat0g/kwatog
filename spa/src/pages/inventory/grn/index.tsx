import { useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate} from 'react-router-dom';
import { Plus } from 'lucide-react';
import { grnApi } from '@/api/inventory/grn';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { formatDate } from '@/lib/formatDate';
import type { ListParams } from '@/types';
import type { GoodsReceiptNote, GrnStatus } from '@/types/inventory';const variant: Record<GrnStatus, 'neutral' | 'warning' | 'success' | 'info' | 'danger'> = {
 draft: 'neutral', pending_qc: 'warning', accepted: 'success', partial_accepted: 'info', rejected: 'danger' };

interface GrnListParams extends ListParams {
  status?: string;
  vendor_id?: string;
  purchase_order_id?: string;
  from?: string;
  to?: string;
}

const DEFAULT_FILTERS: GrnListParams = {
  page: 1, per_page: 25, status: 'pending_qc',
};

export default function GrnListPage() {
  const navigate = useNavigate();
  const { can } = usePermission();
  const [filters, setFilters] = useUrlFilters<GrnListParams>(DEFAULT_FILTERS);

  // Dashboard KPI links to ?status=pending but the backend status value is
  // pending_qc — normalize once at mount so the drill-down actually filters.
  useEffect(() => {
    if (filters.status === 'pending') {
      setFilters((f) => ({ ...f, status: 'pending_qc' }));
    }
  }, [filters.status, setFilters]);

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['inventory', 'grn', filters],
 queryFn: () => grnApi.list(filters),
 placeholderData: (prev) => prev });
 const { data: grnOptions } = useQuery({
 queryKey: ['inventory', 'grn', 'options'],
 queryFn: grnApi.options,
 staleTime: 5 * 60 * 1000 });
 const statusLabels = new Map((grnOptions?.statuses ?? []).map((option) => [option.value, option.label]));

 const columns: Column<GoodsReceiptNote>[] = [
 { key: 'grn', header: 'GRN', cell: (r) => <span className="font-mono">{r.grn_number}</span> },
 { key: 'po', header: 'PO', cell: (r) => r.purchase_order ? <span className="font-mono">{r.purchase_order.po_number}</span> : '—' },
 { key: 'vendor', header: 'Vendor', cell: (r) => r.vendor?.name ?? '—' },
 { key: 'date', header: 'Received', cell: (r) => <span className="font-mono">{formatDate(r.received_date)}</span> },
 { key: 'status', header: 'Status', cell: (r) => <Chip variant={variant[r.status]}>{r.status_label ?? statusLabels.get(r.status) ?? r.status}</Chip> },
 { key: 'rec', header: 'By', cell: (r) => r.receiver?.name ?? '—' },
 ];

 const filterConfig: FilterConfig[] = [
 { key: 'status', label: 'Status', type: 'select', options: [
 { value: '', label: 'All' },
 ...(grnOptions?.statuses ?? []),
 ]},
 ];

 return (
 <div>
 <PageHeader title="Goods Receipt Notes" subtitle={data ? `${data.meta.total} records` : undefined}
 actions={can('inventory.grn.create') ? (
 <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => navigate('/inventory/grn/create')}>New GRN</Button>
 ) : null}
 />
 <FilterBar filters={filterConfig} values={filters}
 onSearch={(s) => setFilters(f => ({ ...f, search: s, page: 1 }))}
 onFilter={(k, v) => setFilters(f => ({ ...f, [k]: v, page: 1 }))}
 searchPlaceholder="Search GRN number…" />
 {isLoading && !data && <SkeletonTable columns={6} rows={6} />}
 {isError && <EmptyState icon="alert-circle" title="Failed to load GRNs" action={<Button onClick={() => refetch()}>Retry</Button>} />}
 {data && data.data.length === 0 && (
 <EmptyState icon="inbox" title="No GRNs yet"
 description={can('inventory.grn.create') ? 'Create one against an approved PO.' : undefined}
 action={can('inventory.grn.create') ? <Button variant="primary" onClick={() => navigate('/inventory/grn/create')}>New GRN</Button> : undefined} />
 )}
 {data && data.data.length > 0 && (
 <div className="px-5 py-4">
  <DataTable
  tableKey="grns"
  onRowClick={(r) => navigate(`/inventory/grn/${r.id}`)}
 columns={columns}
 data={data.data}
 meta={data.meta}
 onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
 />
 </div>
 )}
 </div>
 );
}
