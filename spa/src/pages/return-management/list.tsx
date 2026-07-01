import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { Plus } from 'lucide-react';
import { returnManagementApi } from '@/api/returnManagement';
import { Button } from '@/components/ui/Button';
import { Chip, type ChipVariant } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDate } from '@/lib/formatDate';
import type { ReturnRequest } from '@/types/returnManagement';

const STATUS_VARIANT: Record<string, ChipVariant> = {
  draft: 'neutral',
  pending_approval: 'warning',
  approved: 'info',
  received: 'info',
  inspected: 'purple',
  completed: 'success',
  rejected: 'danger',
  cancelled: 'neutral',
};

const TYPE_VARIANT: Record<string, ChipVariant> = {
  customer_return: 'info',
  supplier_return: 'warning',
};

export default function ReturnManagementListPage() {
  const navigate = useNavigate();
  const { can } = usePermission();
  const [filters, setFilters] = useState<Record<string, unknown>>({ page: 1, per_page: 25 });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['return-requests', filters],
    queryFn: () => returnManagementApi.list(filters as Record<string, string | number | undefined>),
    placeholderData: (prev) => prev,
  });

  const columns: Column<ReturnRequest>[] = [
    {
      key: 'rma',
      header: 'RMA #',
      cell: (r) => (
        <Link to={`/return-management/${r.id}`} className="font-mono text-accent">
          {r.rma_number}
        </Link>
      ),
    },
    {
      key: 'type',
      header: 'Type',
      cell: (r) => (
        <Chip variant={TYPE_VARIANT[r.type] ?? 'neutral'}>
          {r.type_label || r.type.replace(/_/g, ' ')}
        </Chip>
      ),
    },
    {
      key: 'source',
      header: 'Source',
      cell: (r) => (
        <span className="text-secondary">
          {r.customer?.name || r.vendor?.name || r.source_label || '—'}
        </span>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      cell: (r) => (
        <Chip variant={STATUS_VARIANT[r.status] ?? 'neutral'}>
          {r.status_label || r.status.replace(/_/g, ' ')}
        </Chip>
      ),
    },
    {
      key: 'reason',
      header: 'Reason',
      className: 'max-w-[160px]',
      cell: (r) => (
        <span className="text-muted truncate block">
          {r.reason_description || r.reason_code || '—'}
        </span>
      ),
    },
    {
      key: 'items',
      header: 'Items',
      align: 'right',
      cell: (r) => <NumCell>{r.item_count}</NumCell>,
    },
    {
      key: 'date',
      header: 'Date',
      cell: (r) => <NumCell>{formatDate(r.return_date)}</NumCell>,
    },
  ];

  const filterConfig: FilterConfig[] = [
    {
      key: 'type',
      label: 'Type',
      type: 'select',
      options: [
        { value: '', label: 'All types' },
        { value: 'customer_return', label: 'Customer return' },
        { value: 'supplier_return', label: 'Supplier return' },
      ],
    },
    {
      key: 'status',
      label: 'Status',
      type: 'select',
      options: [
        { value: '', label: 'All statuses' },
        { value: 'draft', label: 'Draft' },
        { value: 'pending_approval', label: 'Pending approval' },
        { value: 'approved', label: 'Approved' },
        { value: 'received', label: 'Received' },
        { value: 'inspected', label: 'Inspected' },
        { value: 'completed', label: 'Completed' },
        { value: 'rejected', label: 'Rejected' },
        { value: 'cancelled', label: 'Cancelled' },
      ],
    },
  ];

  return (
    <div>
      <PageHeader
        title="Return Management (RMA)"
        subtitle={data ? `${data.meta.total} return requests` : undefined}
        actions={
          can('return_management.manage') ? (
            <Button
              variant="primary"
              size="sm"
              icon={<Plus size={14} />}
              onClick={() => navigate('/return-management/new')}
            >
              New RMA
            </Button>
          ) : null
        }
      />
      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(s) => setFilters((f) => ({ ...f, search: s, page: 1 }))}
        onFilter={(k, v) => setFilters((f) => ({ ...f, [k]: v, page: 1 }))}
        searchPlaceholder="Search RMA number..."
      />
      {isLoading && !data && <SkeletonTable columns={7} rows={6} />}
      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load return requests"
          action={<Button onClick={() => refetch()}>Retry</Button>}
        />
      )}
      {data && data.data.length === 0 && (
        <EmptyState
          icon="inbox"
          title="No return requests"
          action={
            can('return_management.manage') ? (
              <Button variant="primary" onClick={() => navigate('/return-management/new')}>
                New RMA
              </Button>
            ) : undefined
          }
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
