import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { Plus } from 'lucide-react';
import { salesOrdersApi, type SalesOrderListParams } from '@/api/crm/salesOrders';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import type { SalesOrder, SalesOrderStatus } from '@/types/crm';

const statusVariant: Record<SalesOrderStatus, 'success' | 'info' | 'warning' | 'neutral' | 'danger'> = {
  draft: 'neutral',
  confirmed: 'info',
  in_production: 'info',
  partially_delivered: 'warning',
  delivered: 'success',
  invoiced: 'success',
  cancelled: 'danger',
};

export default function SalesOrdersListPage() {
  const navigate = useNavigate();
  const { can } = usePermission();
  const canCreate = can('crm.sales_orders.create');
  const [filters, setFilters] = useState<SalesOrderListParams>({ page: 1, per_page: 25 });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['crm', 'sales-orders', filters],
    queryFn: () => salesOrdersApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<SalesOrder>[] = [
    {
      key: 'so_number', header: 'SO #',
      cell: (r) => (
        <Link to={`/crm/sales-orders/${r.id}`} className="font-mono text-accent hover:underline">{r.so_number}</Link>
      ),
    },
    { key: 'customer', header: 'Customer', cell: (r) => r.customer?.name ?? '—' },
    { key: 'date', header: 'Date', align: 'right', cell: (r) => <NumCell>{r.date}</NumCell> },
    { key: 'items', header: 'Lines', align: 'right', cell: (r) => <NumCell>{r.item_count}</NumCell> },
    {
      key: 'total', header: 'Total', align: 'right',
      cell: (r) => <NumCell>₱ {Number(r.total_amount).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</NumCell>,
    },
    {
      key: 'status', header: 'Status',
      cell: (r) => <Chip variant={statusVariant[r.status]}>{r.status_label}</Chip>,
    },
  ];

  const filterConfig: FilterConfig[] = [
    { key: 'status', label: 'Status', type: 'select', options: [
      { value: '', label: 'All' },
      { value: 'draft', label: 'Draft' },
      { value: 'confirmed', label: 'Confirmed' },
      { value: 'in_production', label: 'In Production' },
      { value: 'partially_delivered', label: 'Partially Delivered' },
      { value: 'delivered', label: 'Delivered' },
      { value: 'invoiced', label: 'Invoiced' },
      { value: 'cancelled', label: 'Cancelled' },
    ]},
  ];

  return (
    <div>
      <PageHeader
        title="Sales orders"
        subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'order' : 'orders'}` : undefined}
        actions={canCreate ? (
          <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => navigate('/crm/sales-orders/create')}>
            New sales order
          </Button>
        ) : null}
      />
      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search by SO number or customer…"
      />
      {isLoading && !data && <SkeletonTable columns={6} rows={8} />}
      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load sales orders"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      )}
      {data && data.data.length === 0 && (
        <EmptyState
          icon="file-text"
          title="No sales orders yet"
          description={canCreate ? 'Create the first sales order to start the order-to-cash chain.' : 'Nothing here yet.'}
          action={canCreate ? <Button variant="primary" onClick={() => navigate('/crm/sales-orders/create')}>New sales order</Button> : undefined}
        />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable
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
