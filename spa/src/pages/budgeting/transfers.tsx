import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { budgetingApi } from '@/api/accounting/budgeting';
import { usePermission } from '@/hooks/usePermission';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { Chip } from '@/components/ui/Chip';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { Button } from '@/components/ui/Button';
import { DataTablePagination } from '@/components/ui/DataTablePagination';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { QueryErrorState } from '@/components/ui/QueryErrorState';
import { formatPeso } from '@/lib/formatNumber';
import { formatDate } from '@/lib/formatDate';
import { LuPlus, LuCircleCheck, LuCircleX } from '@/lib/icons';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { reportMutationError } from '@/lib/formErrors';
import toast from 'react-hot-toast';

const STATUS_VARIANT: Record<string, 'success' | 'warning' | 'neutral' | 'danger'> = {
  approved: 'success',
  pending: 'warning',
  rejected: 'neutral',
};

export default function BudgetTransferListPage() {
  const { can } = usePermission();
  const canManage = can('budgeting.manage');
  const canApprove = can('budgeting.approve');
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [rejectId, setRejectId] = useState<string | null>(null);

  const listQuery = useQuery({
    queryKey: ['budget-transfers', status, page, perPage],
    queryFn: () => budgetingApi.transfers({
      status: status || undefined,
      page,
      per_page: perPage,
    }),
  });
  const { data, isLoading, isError } = listQuery;

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['budget-transfers'] });
  };

  const approveMutation = useMutation({
    mutationFn: (id: string) => budgetingApi.approveTransfer(id),
    onSuccess: () => {
      invalidate();
      toast.success('Transfer approved and applied.');
    },
    onError: (error) => reportMutationError(error, 'Could not approve the transfer.'),
  });

  const rejectMutation = useMutation({
    mutationFn: (id: string) => budgetingApi.rejectTransfer(id),
    onSuccess: () => {
      invalidate();
      toast.success('Transfer rejected; nothing moved.');
      setRejectId(null);
    },
    onError: (error) => reportMutationError(error, 'Could not reject the transfer.'),
  });

  return (
    <div className="p-5 space-y-6">
      <PageHeader
        title="Budget Transfers"
        subtitle="Line-to-line virement inside one fiscal year — requested by Finance, applied on VP approval"
        backTo="/budgeting"
        backLabel="Budgeting"
        actions={
          <div className="flex items-center gap-2">
            {canManage && (
              <Button variant="primary" size="sm" icon={<LuPlus size={14} />} onClick={() => navigate('/budgeting/transfers/create')}>
                Request Transfer
              </Button>
            )}
          </div>
        }
      />

      <Panel
        title="Transfers"
        meta={
          <SegmentedControl
            size="sm"
            label="Transfer status"
            value={status}
            onChange={(value) => { setStatus(value); setPage(1); }}
            options={[
              { value: '', label: 'All' },
              { value: 'pending', label: 'Pending' },
              { value: 'approved', label: 'Approved' },
              { value: 'rejected', label: 'Rejected' },
            ]}
          />
        }
      >
        {isLoading ? (
          <SkeletonTable />
        ) : isError ? (
          <QueryErrorState subject="budget transfers" onRetry={() => void listQuery.refetch()} />
        ) : data && data.data.length > 0 ? (
          <>
            <div className="overflow-x-auto">
              <table className={tableCls}>
                <thead>
                  <tr className={theadTrCls}>
                    <Th>Number</Th>
                    <Th>From → To</Th>
                    <Th>Month</Th>
                    <Th align="right">Amount</Th>
                    <Th>Reason</Th>
                    <Th align="center">Status</Th>
                    <Th align="right">Actions</Th>
                  </tr>
                </thead>
                <tbody>
                  {data.data.map((transfer) => (
                    <tr key={transfer.id} className={trCls}>
                      <Td>
                        <span className="font-medium font-mono">{transfer.transfer_number}</span>
                        <span className="ml-2 text-xs text-muted">{formatDate(transfer.created_at)}</span>
                      </Td>
                      <Td>
                        <span className="font-medium">{transfer.from_line?.account_code}</span>
                        <span className="text-muted"> → </span>
                        <span className="font-medium">{transfer.to_line?.account_code}</span>
                        {(transfer.from_line?.department || transfer.to_line?.department) && (
                          <span className="ml-2 text-xs text-muted">
                            {transfer.from_line?.department ?? 'Company-wide'} → {transfer.to_line?.department ?? 'Company-wide'}
                          </span>
                        )}
                      </Td>
                      <Td className="uppercase">{transfer.month}</Td>
                      <Td align="right" mono>{formatPeso(transfer.amount)}</Td>
                      <Td><span className="text-sm text-secondary">{transfer.reason}</span></Td>
                      <Td align="center"><Chip variant={STATUS_VARIANT[transfer.status] ?? 'neutral'}>{transfer.status_label ?? transfer.status}</Chip></Td>
                      <Td align="right">
                        {transfer.status === 'pending' && canApprove ? (
                          <div className="flex justify-end gap-1.5">
                            <Button size="xs" variant="primary" onClick={() => approveMutation.mutate(transfer.id)} loading={approveMutation.isPending}>
                              <LuCircleCheck size={13} /> Approve
                            </Button>
                            <Button size="xs" variant="secondary" onClick={() => setRejectId(transfer.id)}>
                              <LuCircleX size={13} /> Reject
                            </Button>
                          </div>
                        ) : (
                          <span className="text-xs text-muted">
                            {transfer.approved_by ? `by ${transfer.approved_by.name}` : '—'}
                          </span>
                        )}
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <DataTablePagination
              meta={data.meta}
              perPage={perPage}
              onPageChange={setPage}
              onPageSizeChange={setPerPage}
            />
          </>
        ) : (
          <p className="text-sm text-muted py-4 text-center">
            No transfers found. {canManage && <Link to="/budgeting/transfers/create" className="text-link hover:underline">Request the first one</Link>}.
          </p>
        )}
      </Panel>

      <ConfirmDialog
        isOpen={rejectId !== null}
        onClose={() => setRejectId(null)}
        onConfirm={() => { if (rejectId) rejectMutation.mutate(rejectId); }}
        title="Reject this transfer?"
        description="Nothing moves. The maker can request again with corrected lines."
        variant="warning"
        confirmLabel="Reject"
        pending={rejectMutation.isPending}
      />
    </div>
  );
}
