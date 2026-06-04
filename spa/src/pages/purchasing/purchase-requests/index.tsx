import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { Plus, Zap } from 'lucide-react';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { purchaseRequestsApi } from '@/api/purchasing/purchase-requests';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column, type BulkAction } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { PR_PRIORITIES, PR_STATUSES } from '@/lib/constants/statuses';
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

const errMsg = (e: unknown, fallback: string) =>
  (e instanceof AxiosError ? e.response?.data?.message : undefined) ?? fallback;

export default function PurchaseRequestsListPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { can } = usePermission();
  const [filters, setFilters] = useState<Record<string, unknown>>({ page: 1, per_page: 25 });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['purchasing', 'purchase-requests', filters],
    queryFn: ({ signal }) => purchaseRequestsApi.list(filters, signal),
    placeholderData: (prev) => prev,
  });

  const bulkApproveMut = useMutation({
    mutationFn: (ids: string[]) => purchaseRequestsApi.bulkApprove(ids),
    onSuccess: (results) => {
      qc.invalidateQueries({ queryKey: ['purchasing', 'purchase-requests'] });
      const approved = results.filter((r: { status: string }) => r.status === 'approved').length;
      const skipped = results.filter((r: { status: string }) => r.status === 'skipped').length;
      toast.success(`${approved} approved, ${skipped} skipped`);
    },
    onError: (e) => toast.error(errMsg(e, 'Failed to bulk approve.')),
  });

  const bulkActions: BulkAction<PurchaseRequest>[] = [
    {
      label: 'Approve selected',
      variant: 'primary',
      onClick: (rows) => {
        const ids = rows.map((r) => r.id);
        bulkApproveMut.mutate(ids);
      },
    },
  ];

  const columns: Column<PurchaseRequest>[] = [
    { key: 'pr', header: 'PR #', cell: (r) => (
      <div>
        <Link to={`/purchasing/purchase-requests/${r.id}`} className="font-mono text-accent">{r.pr_number}</Link>
        {r.is_auto_generated && <Chip variant="warning" className="ml-2">AUTO</Chip>}
        {r.is_urgent && <Chip variant="danger" className="ml-1"><Zap size={10} className="inline mr-0.5" />URGENT</Chip>}
      </div>
    ) },
    { key: 'date', header: 'Date', cell: (r) => <span className="font-mono">{formatDate(r.date)}</span> },
    { key: 'requester', header: 'Requester', cell: (r) => r.requester?.name ?? '—' },
    { key: 'dept', header: 'Dept', cell: (r) => r.department?.code ?? '—' },
    { key: 'priority', header: 'Priority', cell: (r) => (
      <span className="flex items-center gap-1">
        <Chip variant={priorityVariant[r.priority]}>{r.priority}</Chip>
        {r.is_urgent && <Zap size={12} className="text-danger" />}
      </span>
    ) },
    { key: 'status', header: 'Status', cell: (r) => (
      <span className="flex items-center gap-1.5">
        <Chip variant={statusVariant[r.status]}>{r.status}</Chip>
        {r.has_overdue_approval && (
          <span title="Approval pending more than 24 hours"><Chip variant="danger">overdue</Chip></span>
        )}
      </span>
    ) },
    { key: 'total', header: 'Estimated', align: 'right', cell: (r) => <NumCell>{formatPeso(r.total_estimated_amount)}</NumCell> },
  ];

  const filterConfig: FilterConfig[] = [
    { key: 'status', label: 'Status', type: 'select', options: [
      { value: '', label: 'All' },
      ...PR_STATUSES,
    ]},
    { key: 'priority', label: 'Priority', type: 'select', options: [
      { value: '', label: 'All' },
      ...PR_PRIORITIES,
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
        onSearch={(s) => setFilters(f => ({ ...f, search: s, page: 1 }))}
        onFilter={(k, v) => setFilters(f => ({ ...f, [k]: v, page: 1 }))}
        searchPlaceholder="Search PR number…" />
      {isLoading && !data && <SkeletonTable columns={7} rows={6} />}
      {isError && <EmptyState icon="alert-circle" title="Failed to load PRs" action={<Button onClick={() => refetch()}>Retry</Button>} />}
      {data && data.data.length === 0 && (
        <EmptyState icon="inbox" title="No purchase requests"
          action={can('purchasing.pr.create') ? <Button variant="primary" onClick={() => navigate('/purchasing/purchase-requests/create')}>New PR</Button> : undefined} />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable
            columns={columns}
            data={data.data}
            meta={data.meta}
            onPageChange={(page) => setFilters(f => ({ ...f, page }))}
            // ADV6 — Bulk approve via built-in select + bulkActions
            selectable={can('purchasing.pr.approve')}
            bulkActions={can('purchasing.pr.approve') ? bulkActions : undefined}
          />
        </div>
      )}
    </div>
  );
}
