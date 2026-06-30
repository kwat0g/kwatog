/** Asset Transfers list page. */
import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { Plus } from 'lucide-react';
import toast from 'react-hot-toast';
import { assetTransfersApi, type AssetTransferListParams } from '@/api/assets';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import type { AssetTransfer, AssetTransferStatus } from '@/types/assets';

const STATUS_CHIP: Record<AssetTransferStatus, 'warning' | 'info' | 'danger' | 'success'> = {
  pending: 'warning',
  approved: 'info',
  rejected: 'danger',
  completed: 'success',
};

export default function AssetTransfersListPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { can } = usePermission();
  const [filters, setFilters] = useState<AssetTransferListParams>({ page: 1, per_page: 25 });
  const [confirmApprove, setConfirmApprove] = useState<string | null>(null);
  const [confirmReject, setConfirmReject] = useState<string | null>(null);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['asset-transfers', filters],
    queryFn: () => assetTransfersApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const approveMutation = useMutation({
    mutationFn: (id: string) => assetTransfersApi.approve(id),
    onSuccess: (transfer) => {
      qc.invalidateQueries({ queryKey: ['asset-transfers'] });
      toast.success(`Transfer ${transfer.transfer_number} approved.`);
      setConfirmApprove(null);
    },
    onError: () => toast.error('Failed to approve transfer.'),
  });

  const rejectMutation = useMutation({
    mutationFn: (id: string) => assetTransfersApi.reject(id),
    onSuccess: (transfer) => {
      qc.invalidateQueries({ queryKey: ['asset-transfers'] });
      toast.success(`Transfer ${transfer.transfer_number} rejected.`);
      setConfirmReject(null);
    },
    onError: () => toast.error('Failed to reject transfer.'),
  });

  const columns: Column<AssetTransfer>[] = [
    {
      key: 'transfer_number', header: 'Transfer #',
      cell: (r) => <span className="font-mono text-accent">{r.transfer_number}</span>,
    },
    { key: 'asset_code', header: 'Asset Code', cell: (r) => <span className="font-mono">{r.asset.asset_code}</span> },
    { key: 'asset_name', header: 'Asset Name', cell: (r) => <span>{r.asset.name}</span> },
    { key: 'from_department', header: 'From', cell: (r) => <span>{r.from_department.name}</span> },
    { key: 'to_department', header: 'To', cell: (r) => <span>{r.to_department.name}</span> },
    {
      key: 'status', header: 'Status',
      cell: (r) => <Chip variant={STATUS_CHIP[r.status]}>{r.status}</Chip>,
    },
    { key: 'transfer_date', header: 'Date', cell: (r) => <span>{r.transfer_date}</span> },
    ...(can('assets.transfer.approve') ? [{
      key: 'actions' as const, header: '',
      cell: (r: AssetTransfer) => r.status === 'pending' ? (
        <div className="flex items-center gap-1">
          <Button variant="primary" size="sm"
            disabled={approveMutation.isPending || rejectMutation.isPending}
            onClick={(e: React.MouseEvent) => { e.stopPropagation(); setConfirmApprove(r.id); }}>
            Approve
          </Button>
          <Button variant="danger" size="sm"
            disabled={approveMutation.isPending || rejectMutation.isPending}
            onClick={(e: React.MouseEvent) => { e.stopPropagation(); setConfirmReject(r.id); }}>
            Reject
          </Button>
        </div>
      ) : null,
    }] : []),
  ];

  const filterConfig: FilterConfig[] = [
    {
      key: 'status', label: 'Status', type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'pending', label: 'Pending' },
        { value: 'approved', label: 'Approved' },
        { value: 'rejected', label: 'Rejected' },
        { value: 'completed', label: 'Completed' },
      ],
    },
  ];

  return (
    <div>
      <PageHeader
        title="Asset Transfers"
        subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'transfer' : 'transfers'}` : undefined}
        actions={
          can('assets.transfer') ? (
            <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => navigate('/assets/transfers/create')}>
              New transfer
            </Button>
          ) : undefined
        }
      />
      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search by transfer # or asset..."
      />
      {isLoading && !data && <SkeletonTable columns={7} rows={6} />}
      {isError && (
        <EmptyState icon="alert-circle" title="Failed to load transfers"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
      )}
      {data && data.data.length === 0 && (
        <EmptyState icon="arrow-right-left" title="No transfers" description="Create a transfer to move an asset between departments."
          action={can('assets.transfer') ? (
            <Button variant="primary" onClick={() => navigate('/assets/transfers/create')}>New transfer</Button>
          ) : undefined} />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable columns={columns} data={data.data} meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))} />
        </div>
      )}

      <ConfirmDialog
        isOpen={confirmApprove !== null}
        onClose={() => setConfirmApprove(null)}
        onConfirm={() => { if (confirmApprove) approveMutation.mutate(confirmApprove); }}
        title="Approve asset transfer?"
        variant="warning"
        confirmLabel="Approve"
        pending={approveMutation.isPending}
      />
      <ConfirmDialog
        isOpen={confirmReject !== null}
        onClose={() => setConfirmReject(null)}
        onConfirm={() => { if (confirmReject) rejectMutation.mutate(confirmReject); }}
        title="Reject asset transfer?"
        variant="danger"
        confirmLabel="Reject"
        pending={rejectMutation.isPending}
      />
    </div>
  );
}
