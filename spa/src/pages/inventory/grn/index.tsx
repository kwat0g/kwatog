import { useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { LuPlus } from '@/lib/icons';
import { grnApi } from '@/api/inventory/grn';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { PageShell } from '@/components/layout/PageShell';
import { usePermission } from '@/hooks/usePermission';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { formatDate } from '@/lib/formatDate';
import type { ListParams } from '@/types';
import { ListEmptyState } from '@/components/ui/ListEmptyState';
import type { GoodsReceiptNote, GrnStatus } from '@/types/inventory';

const variant: Record<GrnStatus, 'neutral' | 'warning' | 'success' | 'info' | 'danger'> = {
  draft: 'neutral',
  pending_qc: 'warning',
  accepted: 'success',
  partial_accepted: 'info',
  rejected: 'danger',
};

interface GrnListParams extends ListParams {
  status?: string;
  qc_handoff?: string;
  vendor_id?: string;
  purchase_order_id?: string;
  from?: string;
  to?: string;
}

const DEFAULT_FILTERS: GrnListParams = {
  page: 1, per_page: 25, status: '', qc_handoff: '',
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
  {
   key: 'grn', header: 'GRN', width: 180, minWidth: 150, maxWidth: 240,
   cell: (r) => <Link to={`/inventory/grn/${r.id}`} className="font-mono text-link hover:text-link-hover hover:underline">{r.grn_number}</Link>,
  },
  {
   key: 'po', header: 'PO', width: 170, minWidth: 140, maxWidth: 220,
   cell: (r) => r.purchase_order ? <span className="font-mono tabular-nums">{r.purchase_order.po_number}</span> : '—',
  },
  {
   key: 'vendor', header: 'Vendor', width: 280, minWidth: 180, maxWidth: 480,
   cell: (r) => <span className="block truncate" title={r.vendor?.name ?? '—'}>{r.vendor?.name ?? '—'}</span>,
  },
  {
   key: 'date', header: 'Received', width: 160, minWidth: 140, maxWidth: 200,
   cell: (r) => <span className="tabular-nums">{formatDate(r.received_date)}</span>,
  },
  {
   key: 'status', header: 'Status', width: 150, minWidth: 120, maxWidth: 190,
   cell: (r) => <Chip variant={variant[r.status]}>{r.status_label ?? statusLabels.get(r.status) ?? r.status}</Chip>,
  },
  {
   key: 'qcHandoff', header: 'QC handoff', width: 180, minWidth: 140, maxWidth: 240,
   cell: (r) => {
    const handoff = r.incoming_qc_handoff;
    if (!handoff) return '—';
    const handoffVariant = handoff.status === 'manual_required' ? 'warning' : handoff.status === 'generated' ? 'success' : 'neutral';
    return <Chip variant={handoffVariant}>{handoff.status_label ?? handoff.status}</Chip>;
   },
  },
  {
   key: 'rec', header: 'By', width: 210, minWidth: 170, maxWidth: 300,
   cell: (r) => <span className="block truncate" title={r.receiver?.name ?? '—'}>{r.receiver?.name ?? '—'}</span>,
  },
 ];

 const filterConfig: FilterConfig[] = [
 { key: 'status', label: 'Status', type: 'select', options: [
 { value: '', label: 'All' },
 ...(grnOptions?.statuses ?? []),
 ]},
 { key: 'qc_handoff', label: 'QC Handoff', type: 'select', options: [
 { value: '', label: 'All' },
 { value: 'action_required', label: 'Needs retry' },
 ]},
 ];

 return (
 <PageShell viewport className="flex flex-col gap-3">
 <PageHeader inset={false} title="Goods Receipt Notes" subtitle={data ? `${data.meta.total} records` : undefined}
 actions={can('inventory.grn.create') ? (
 <Button variant="primary" size="sm" icon={<LuPlus size={14} />} onClick={() => navigate('/inventory/grn/create')}>New GRN</Button>
 ) : null}
 />
 <FilterBar inset={false} filters={filterConfig} values={filters}
 onSearch={(s) => setFilters(f => ({ ...f, search: s, page: 1 }))}
 onFilter={(k, v) => setFilters(f => ({ ...f, [k]: v, page: 1 }))}
 searchPlaceholder="Search GRN number…" />
 {isLoading && !data && <div className="min-h-0 flex-1 overflow-auto"><SkeletonTable columns={6} rows={6} /></div>}
 {isError && <div className="min-h-0 flex-1 flex items-center justify-center"><EmptyState icon="alert-circle" title="Failed to load GRNs" action={<Button onClick={() => refetch()}>Retry</Button>} /></div>}
 {!isError && data && data.data.length === 0 && (
 <div className="min-h-0 flex-1 flex items-center justify-center"><ListEmptyState searchTerm={filters.search || (filters.status ? `status: ${filters.status.replaceAll("_", " ")}` : filters.from || filters.to ? "selected dates" : undefined)} /></div>
 )}
 {data && data.data.length > 0 && (
 <div className="min-h-0 flex-1 flex flex-col">
  <DataTable
  tableKey="grns"
  fillHeight
  onRowClick={(r) => navigate(`/inventory/grn/${r.id}`)}
 columns={columns}
 data={data.data}
 meta={data.meta}
 onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
 onPageSizeChange={(per_page) => setFilters((f) => ({ ...f, per_page, page: 1 }))}
 />
 </div>
 )}
 </PageShell>
 );
}
