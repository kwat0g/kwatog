import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { Plus } from 'lucide-react';
import { purchaseRequestsApi } from '@/api/purchasing/purchase-requests';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDate } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import type { PurchaseRequest, PurchaseRequestPriority, PurchaseRequestStatus } from '@/types/purchasing';

const statusVariant: Record<PurchaseRequestStatus, 'neutral' | 'warning' | 'info' | 'success' | 'danger'> = {
  draft: 'neutral', pending: 'info', approved: 'success', rejected: 'danger',
  converted: 'neutral', cancelled: 'neutral',
};
const priorityVariant: Record<PurchaseRequestPriority, 'neutral' | 'warning' | 'danger'> = {
  normal: 'neutral', urgent: 'warning', critical: 'danger',
};

export default function PurchaseRequestsListPage() {
  const navigate = useNavigate();
  const { can } = usePermission();
  const [filters, setFilters] = useState<any>({ page: 1, per_page: 25 });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['purchasing', 'purchase-requests', filters],
    queryFn: () => purchaseRequestsApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<PurchaseRequest>[] = [
    { key: 'pr', header: 'PR #', cell: (r) => (
      <div>
        <Link to={`/purchasing/purchase-requests/${r.id}`} className="font-mono text-accent">{r.pr_number}</Link>
        {r.is_auto_generated && <Chip variant="warning" className="ml-2">AUTO</Chip>}
      </div>
    ) },
    { key: 'date', header: 'Date', cell: (r) => <span className="font-mono">{formatDate(r.date)}</span> },
    { key: 'requester', header: 'Requester', cell: (r) => r.requester?.name ?? '—' },
    { key: 'dept', header: 'Dept', cell: (r) => r.department?.code ?? '—' },
    { key: 'priority', header: 'Priority', cell: (r) => <Chip variant={priorityVariant[r.priority]}>{r.priority}</Chip> },
    { key: 'status', header: 'Status', cell: (r) => <Chip variant={statusVariant[r.status]}>{r.status}</Chip> },
    { key: 'total', header: 'Estimated', align: 'right', cell: (r) => <NumCell>{formatPeso(r.total_estimated_amount)}</NumCell> },
  ];

  const filterConfig: FilterConfig[] = [
    { key: 'status', label: 'Status', type: 'select', options: [
      { value: '', label: 'All' },
      ...['draft', 'pending', 'approved', 'rejected', 'converted', 'cancelled'].map((v) => ({ value: v, label: v }))
    ]},
    { key: 'priority', label: 'Priority', type: 'select', options: [
      { value: '', label: 'All' }, { value: 'normal', label: 'Normal' },
      { value: 'urgent', label: 'Urgent' }, { value: 'critical', label: 'Critical' },
    ]},
    { key: 'is_auto_generated', label: 'Source', type: 'select', options: [
      { value: '', label: 'All' }, { value: 'true', label: 'Auto-generated' }, { value: 'false', label: 'Manual' },
    ]},
  ];

  return (
    <div>
      <PageHeader title="Purchase requests" subtitle={data ? `${data.meta.total} requests` : undefined}
        actions={can('purchasing.pr.create') ? (
          <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => navigate('/purchasing/purchase-requests/create')}>New PR</Button>
        ) : null} />
      <FilterBar filters={filterConfig} values={filters}
        onSearch={(s) => setFilters((f: any) => ({ ...f, search: s, page: 1 }))}
        onFilter={(k, v) => setFilters((f: any) => ({ ...f, [k]: v, page: 1 }))}
        searchPlaceholder="Search PR number…" />
      {isLoading && !data && <SkeletonTable columns={7} rows={6} />}
      {isError && <EmptyState icon="alert-circle" title="Failed to load PRs" action={<Button onClick={() => refetch()}>Retry</Button>} />}
      {data && data.data.length === 0 && (
        <EmptyState icon="inbox" title="No purchase requests"
          action={can('purchasing.pr.create') ? <Button variant="primary" onClick={() => navigate('/purchasing/purchase-requests/create')}>New PR</Button> : undefined} />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable columns={columns} data={data.data} meta={data.meta} onPageChange={(page) => setFilters((f: any) => ({ ...f, page }))} />
        </div>
      )}
    </div>
  );
}
