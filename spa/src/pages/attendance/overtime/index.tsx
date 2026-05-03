import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { Plus } from 'lucide-react';
import toast from 'react-hot-toast';
import { overtimeApi } from '@/api/attendance/overtime';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { DataTable, NumCell, StackedCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Modal } from '@/components/ui/Modal';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDate } from '@/lib/formatDate';
import type { ListParams } from '@/types';
import type { OvertimeRequest } from '@/types/attendance';

export default function OvertimeListPage() {
  const { can } = usePermission();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const [view, setView] = useState<'list' | 'kanban'>('kanban');
  const [filters, setFilters] = useState<ListParams>({ page: 1, per_page: 100, sort: 'date', direction: 'desc' });
  const [reject, setReject] = useState<OvertimeRequest | null>(null);
  const [reason, setReason] = useState('');

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['attendance', 'overtime', filters],
    queryFn: () => overtimeApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const approveMutation = useMutation({
    mutationFn: (id: string) => overtimeApi.approve(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['attendance', 'overtime'] });
      toast.success('Overtime approved.');
    },
    onError: () => toast.error('Failed to approve.'),
  });

  const rejectMutation = useMutation({
    mutationFn: (id: string) => overtimeApi.reject(id, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['attendance', 'overtime'] });
      toast.success('Overtime rejected.');
      setReject(null);
      setReason('');
    },
    onError: () => toast.error('Failed to reject.'),
  });

  const all = data?.data ?? [];
  const grouped = {
    pending: all.filter((o) => o.status === 'pending'),
    approved: all.filter((o) => o.status === 'approved'),
    rejected: all.filter((o) => o.status === 'rejected'),
  };

  const columns: Column<OvertimeRequest>[] = [
    { key: 'date', header: 'Date', cell: (r) => <NumCell>{formatDate(r.date)}</NumCell> },
    { key: 'employee', header: 'Employee', cell: (r) => <StackedCell primary={r.employee?.full_name ?? '—'} secondary={<span className="font-mono">{r.employee?.employee_no}</span>} /> },
    { key: 'hours_requested', header: 'Hours', align: 'right', cell: (r) => <NumCell>{r.hours_requested}</NumCell> },
    { key: 'reason', header: 'Reason', cell: (r) => <span className="text-muted truncate block max-w-md" title={r.reason}>{r.reason}</span> },
    { key: 'status', header: 'Status', cell: (r) => <Chip variant={chipVariantForStatus(r.status)}>{r.status}</Chip> },
    ...(can('attendance.ot.approve') ? [{
      key: 'actions',
      header: '',
      align: 'right' as const,
      cell: (r: OvertimeRequest) => r.status !== 'pending' ? null : (
        <div className="flex items-center justify-end gap-1">
          <Button variant="primary" size="sm" onClick={(e) => { e.stopPropagation(); approveMutation.mutate(r.id); }} disabled={approveMutation.isPending}>Approve</Button>
          <Button variant="danger" size="sm" onClick={(e) => { e.stopPropagation(); setReject(r); }}>Reject</Button>
        </div>
      ),
    }] : []),
  ];

  return (
    <div>
      <PageHeader
        title="Overtime requests"
        subtitle={data ? `${data.meta.total} total · ${grouped.pending.length} pending` : undefined}
        actions={
          <>
            <Button variant="secondary" size="sm" onClick={() => setView(view === 'list' ? 'kanban' : 'list')}>
              {view === 'list' ? 'Kanban view' : 'List view'}
            </Button>
            <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => navigate('/hr/attendance/overtime/create')}>
              New OT request
            </Button>
          </>
        }
      />

      {isLoading && !data && <SkeletonTable columns={6} rows={6} />}
      {isError && <EmptyState icon="alert-circle" title="Failed to load overtime requests" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
      {data && all.length === 0 && (
        <EmptyState icon="inbox" title="No OT requests yet" description="Submit an OT request to see it here." />
      )}

      {data && all.length > 0 && view === 'list' && (
        <div className="px-5 py-4"><DataTable columns={columns} data={all} meta={data.meta} onPageChange={(page) => setFilters((f) => ({ ...f, page }))} /></div>
      )}

      {data && all.length > 0 && view === 'kanban' && (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4 px-5 py-4">
          <KanbanColumn title="Pending" variant="warning" items={grouped.pending} onApprove={(id) => approveMutation.mutate(id)} onReject={setReject} canApprove={can('attendance.ot.approve')} approving={approveMutation.isPending} />
          <KanbanColumn title="Approved" variant="success" items={grouped.approved} />
          <KanbanColumn title="Rejected" variant="danger" items={grouped.rejected} />
        </div>
      )}

      {reject && (
        <Modal isOpen onClose={() => { setReject(null); setReason(''); }} size="sm" title="Reject overtime request">
          <p className="text-sm py-2">
            Reject overtime for <span className="font-medium">{reject.employee?.full_name}</span> on {formatDate(reject.date)}?
          </p>
          <Textarea label="Reason for rejection" required value={reason} onChange={(e) => setReason(e.target.value)} rows={3} />
          <div className="flex justify-end gap-2 pt-3 mt-3 border-t border-default">
            <Button variant="secondary" onClick={() => { setReject(null); setReason(''); }}>Cancel</Button>
            <Button
              variant="danger"
              disabled={!reason.trim() || rejectMutation.isPending}
              loading={rejectMutation.isPending}
              onClick={() => reject && rejectMutation.mutate(reject.id)}
            >
              {rejectMutation.isPending ? 'Rejecting…' : 'Confirm reject'}
            </Button>
          </div>
        </Modal>
      )}
    </div>
  );
}

function KanbanColumn({
  title, variant, items, onApprove, onReject, canApprove, approving,
}: {
  title: string;
  variant: 'success' | 'warning' | 'danger';
  items: OvertimeRequest[];
  onApprove?: (id: string) => void;
  onReject?: (r: OvertimeRequest) => void;
  canApprove?: boolean;
  approving?: boolean;
}) {
  return (
    <Panel
      title={
        <span className="flex items-center gap-2">
          <span>{title}</span>
          <Chip variant={variant}>{items.length}</Chip>
        </span>
      }
      noPadding
    >
      {items.length === 0 ? (
        <p className="text-xs text-muted px-4 py-6 text-center">Nothing here.</p>
      ) : (
        <ul className="divide-y divide-subtle">
          {items.map((o) => (
            <li key={o.id} className="px-4 py-3 hover:bg-subtle">
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                  <div className="text-sm font-medium truncate">{o.employee?.full_name ?? '—'}</div>
                  <div className="text-xs text-muted font-mono">{o.employee?.employee_no} · {formatDate(o.date)}</div>
                  <div className="text-xs mt-1 line-clamp-2">{o.reason}</div>
                </div>
                <div className="text-right shrink-0">
                  <span className="font-mono tabular-nums text-sm">{o.hours_requested}h</span>
                </div>
              </div>
              {canApprove && o.status === 'pending' && (
                <div className="flex gap-1 mt-2">
                  <Button variant="primary" size="sm" disabled={approving} onClick={() => onApprove?.(o.id)}>Approve</Button>
                  <Button variant="danger" size="sm" onClick={() => onReject?.(o)}>Reject</Button>
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
    </Panel>
  );
}
