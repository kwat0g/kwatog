import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { Plus, Check, X } from 'lucide-react';
import toast from 'react-hot-toast';
import { adjustmentsApi, type AdjustmentListParams } from '@/api/payroll/adjustments';
import { Button } from '@/components/ui/Button';
import { Chip, type ChipVariant } from '@/components/ui/Chip';
import { DataTable, NumCell, StackedCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { Modal } from '@/components/ui/Modal';
import { Textarea } from '@/components/ui/Textarea';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatPeso } from '@/lib/formatNumber';
import { formatDate } from '@/lib/formatDate';
import type { PayrollAdjustment } from '@/types/payroll';

const statusVariant = (s: string | null | undefined): ChipVariant => {
  switch (s) {
    case 'approved': return 'success';
    case 'applied':  return 'success';
    case 'pending':  return 'warning';
    case 'rejected': return 'danger';
    default: return 'neutral';
  }
};

export default function PayrollAdjustmentsPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { can } = usePermission();
  const [filters, setFilters] = useState<AdjustmentListParams>({
    page: 1, per_page: 25, sort: 'created_at', direction: 'desc',
  });
  const [rejectTarget, setRejectTarget] = useState<PayrollAdjustment | null>(null);
  const [rejectRemarks, setRejectRemarks] = useState('');

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['payroll-adjustments', filters],
    queryFn: () => adjustmentsApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const approveMutation = useMutation({
    mutationFn: (id: string) => adjustmentsApi.approve(id),
    onSuccess: () => { toast.success('Adjustment approved.'); qc.invalidateQueries({ queryKey: ['payroll-adjustments'] }); },
    onError: () => toast.error('Failed to approve adjustment.'),
  });
  const rejectMutation = useMutation({
    mutationFn: ({ id, remarks }: { id: string; remarks: string }) => adjustmentsApi.reject(id, remarks),
    onSuccess: () => {
      toast.success('Adjustment rejected.');
      qc.invalidateQueries({ queryKey: ['payroll-adjustments'] });
      setRejectTarget(null);
      setRejectRemarks('');
    },
    onError: () => toast.error('Failed to reject adjustment.'),
  });

  const filterConfig: FilterConfig[] = [
    {
      key: 'status', label: 'Status', type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'pending', label: 'Pending' },
        { value: 'approved', label: 'Approved' },
        { value: 'applied', label: 'Applied' },
        { value: 'rejected', label: 'Rejected' },
      ],
    },
    {
      key: 'type', label: 'Type', type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'underpayment', label: 'Underpayment' },
        { value: 'overpayment',  label: 'Overpayment' },
      ],
    },
  ];

  const columns: Column<PayrollAdjustment>[] = [
    {
      key: 'employee',
      header: 'Employee',
      cell: (r) => r.employee
        ? <StackedCell
            primary={r.employee.full_name}
            secondary={<span className="font-mono">{r.employee.employee_no}</span>} />
        : '—',
    },
    { key: 'type', header: 'Type', cell: (r) => <Chip variant={r.type === 'underpayment' ? 'info' : 'warning'}>{r.type_label}</Chip> },
    { key: 'amount', header: 'Amount', align: 'right', cell: (r) => <NumCell className="font-medium">{formatPeso(r.amount)}</NumCell> },
    { key: 'period', header: 'Period', cell: (r) => r.period?.label ?? '—' },
    { key: 'reason', header: 'Reason', cell: (r) => <span className="text-xs text-muted">{r.reason.slice(0, 80)}{r.reason.length > 80 ? '…' : ''}</span> },
    { key: 'created_at', header: 'Submitted', cell: (r) => <NumCell>{formatDate(r.created_at)}</NumCell> },
    { key: 'status', header: 'Status', cell: (r) => <Chip variant={statusVariant(r.status)}>{r.status_label}</Chip> },
    {
      key: 'actions',
      header: 'Actions',
      cell: (r) => r.status === 'pending' && can('payroll.adjustments.create') ? (
        <div className="flex items-center gap-1">
          <Button size="sm" variant="ghost" icon={<Check size={12} />}
            onClick={() => approveMutation.mutate(r.id)}
            disabled={approveMutation.isPending}>
            Approve
          </Button>
          <Button size="sm" variant="ghost" icon={<X size={12} />}
            onClick={() => setRejectTarget(r)}>
            Reject
          </Button>
        </div>
      ) : null,
    },
  ];

  return (
    <div>
      <PageHeader
        title="Payroll Adjustments"
        subtitle={data ? `${data.meta.total} adjustments` : undefined}
        actions={can('payroll.adjustments.create') ? (
          <Button variant="primary" size="sm" icon={<Plus size={14} />}
            onClick={() => navigate('/payroll/adjustments/create')}>
            Raise adjustment
          </Button>
        ) : null}
      />

      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search adjustments…"
      />

      {isLoading && !data && <SkeletonTable columns={8} rows={6} />}
      {isError && (
        <EmptyState icon="alert-circle" title="Failed to load adjustments"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
      )}
      {data && data.data.length === 0 && (
        <EmptyState icon="inbox" title="No adjustments"
          description={can('payroll.adjustments.create')
            ? 'Adjustments are raised against finalized payroll periods.'
            : 'Nothing here yet.'}
          action={can('payroll.adjustments.create') ? (
            <Button variant="primary" onClick={() => navigate('/payroll/adjustments/create')}>Raise adjustment</Button>
          ) : undefined} />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable columns={columns} data={data.data} meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))} />
        </div>
      )}

      {/* Reject modal */}
      <Modal isOpen={!!rejectTarget} onClose={() => setRejectTarget(null)} size="sm" title="Reject adjustment">
        <div className="py-3">
          <p className="text-xs text-muted mb-2">
            Reject {rejectTarget?.employee?.full_name}'s {rejectTarget?.type_label.toLowerCase()} of{' '}
            <span className="font-mono">{formatPeso(rejectTarget?.amount ?? 0)}</span>?
          </p>
          <Textarea
            label="Reason for rejection"
            value={rejectRemarks}
            onChange={(e) => setRejectRemarks(e.target.value)}
            placeholder="Provide a reason…"
            rows={3}
          />
        </div>
        <div className="flex justify-end gap-2 pt-3 border-t border-default">
          <Button variant="secondary" onClick={() => setRejectTarget(null)} disabled={rejectMutation.isPending}>Cancel</Button>
          <Button variant="danger"
            onClick={() => rejectTarget && rejectMutation.mutate({ id: rejectTarget.id, remarks: rejectRemarks })}
            disabled={!rejectRemarks.trim() || rejectMutation.isPending}
            loading={rejectMutation.isPending}>
            Confirm reject
          </Button>
        </div>
      </Modal>
    </div>
  );
}
