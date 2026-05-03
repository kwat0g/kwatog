import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { Plus, Printer } from 'lucide-react';
import { purchaseOrdersApi } from '@/api/purchasing/purchase-orders';
import { bulkPrint } from '@/api/print';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type BulkAction, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDate } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import type { PurchaseOrder, PurchaseOrderStatus } from '@/types/purchasing';

const variant: Record<PurchaseOrderStatus, 'neutral' | 'info' | 'warning' | 'success' | 'danger'> = {
  draft: 'neutral', pending_approval: 'info', approved: 'success', sent: 'info',
  partially_received: 'warning', received: 'success', closed: 'neutral', cancelled: 'danger',
};

export default function PurchaseOrdersListPage() {
  const navigate = useNavigate();
  const { can } = usePermission();
  const [filters, setFilters] = useState<any>({ page: 1, per_page: 25 });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['purchasing', 'purchase-orders', filters],
    queryFn: () => purchaseOrdersApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<PurchaseOrder>[] = [
    { key: 'po', header: 'PO #', cell: (r) => (
      <Link to={`/purchasing/purchase-orders/${r.id}`} className="font-mono text-accent">{r.po_number}</Link>
    ) },
    { key: 'date', header: 'Date', cell: (r) => <span className="font-mono">{formatDate(r.date)}</span> },
    { key: 'vendor', header: 'Vendor', cell: (r) => r.vendor?.name ?? '—' },
    { key: 'eta', header: 'Expected', cell: (r) => (
      <span className={'font-mono ' + (r.expected_delivery_date && new Date(r.expected_delivery_date) < new Date() && r.status !== 'received' ? 'text-danger-fg' : '')}>
        {r.expected_delivery_date ? formatDate(r.expected_delivery_date) : '—'}
      </span>
    ) },
    { key: 'total', header: 'Total', align: 'right', cell: (r) => <NumCell className="font-medium">{formatPeso(r.total_amount)}</NumCell> },
    { key: 'status', header: 'Status', cell: (r) => <Chip variant={variant[r.status]}>{r.status.replace(/_/g, ' ')}</Chip> },
    { key: 'rcv', header: 'Received', align: 'right', cell: (r) => <NumCell>{r.quantity_received_pct.toFixed(0)}%</NumCell> },
  ];

  const filterConfig: FilterConfig[] = [
    { key: 'status', label: 'Status', type: 'select', options: [
      { value: '', label: 'All' },
      ...Object.keys(variant).map((v) => ({ value: v, label: v.replace(/_/g, ' ') }))
    ]},
    { key: 'requires_vp_approval', label: 'VP threshold', type: 'select', options: [
      { value: '', label: 'All' }, { value: 'true', label: 'Yes' }, { value: 'false', label: 'No' },
    ]},
  ];

  return (
    <div>
      <PageHeader title="Purchase orders" subtitle={data ? `${data.meta.total} POs` : undefined}
        actions={can('purchasing.po.create') ? (
          <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => navigate('/purchasing/purchase-orders/create')}>New PO</Button>
        ) : null} />
      <FilterBar filters={filterConfig} values={filters}
        onSearch={(s) => setFilters((f: any) => ({ ...f, search: s, page: 1 }))}
        onFilter={(k, v) => setFilters((f: any) => ({ ...f, [k]: v, page: 1 }))}
        searchPlaceholder="Search PO number…" />
      {isLoading && !data && <SkeletonTable columns={7} rows={6} />}
      {isError && <EmptyState icon="alert-circle" title="Failed to load POs" action={<Button onClick={() => refetch()}>Retry</Button>} />}
      {data && data.data.length === 0 && (
        <EmptyState icon="inbox" title="No purchase orders"
          action={can('purchasing.po.create') ? <Button variant="primary" onClick={() => navigate('/purchasing/purchase-orders/create')}>New PO</Button> : undefined} />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable
            columns={columns}
            data={data.data}
            meta={data.meta}
            onPageChange={(page) => setFilters((f: any) => ({ ...f, page }))}
            selectable
            bulkActions={[
              {
                label: 'Print PDFs',
                icon: <Printer size={14} />,
                onClick: (rows: PurchaseOrder[]) => bulkPrint('purchase_order', rows.map((r) => r.id)),
              } as BulkAction<PurchaseOrder>,
            ]}
          />
        </div>
      )}
    </div>
  );
}
