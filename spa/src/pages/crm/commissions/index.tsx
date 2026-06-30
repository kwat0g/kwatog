/** Commission Earnings list page. */
import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Check, DollarSign } from 'lucide-react';
import toast from 'react-hot-toast';
import { commissionsApi, type CommissionEarningListParams } from '@/api/crm/commissions';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { DataTable, NumCell, type Column, type BulkAction } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import type { CommissionEarning, CommissionEarningStatus } from '@/types/commissions';

const STATUS_CHIP: Record<CommissionEarningStatus, 'warning' | 'info' | 'success'> = {
  pending: 'warning',
  approved: 'info',
  paid: 'success',
};

export default function CommissionsListPage() {
  const { can } = usePermission();
  const qc = useQueryClient();
  const [filters, setFilters] = useState<CommissionEarningListParams>({ page: 1, per_page: 25 });
  const [confirmApprove, setConfirmApprove] = useState<string | null>(null);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['commissions', 'earnings', filters],
    queryFn: () => commissionsApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const approveMutation = useMutation({
    mutationFn: (id: string) => commissionsApi.approve(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['commissions', 'earnings'] });
      toast.success('Commission approved.');
      setConfirmApprove(null);
    },
    onError: () => toast.error('Failed to approve commission.'),
  });

  const batchPaidMutation = useMutation({
    mutationFn: (ids: string[]) => commissionsApi.batchPaid(ids),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['commissions', 'earnings'] });
      toast.success('Commissions marked as paid.');
    },
    onError: () => toast.error('Failed to mark commissions as paid.'),
  });

  const columns: Column<CommissionEarning>[] = [
    {
      key: 'so_number', header: 'SO #',
      cell: (r) => (
        <Link to={`/crm/sales-orders/${r.sales_order.id}`} className="font-mono text-accent hover:underline">
          {r.sales_order.so_number}
        </Link>
      ),
    },
    {
      key: 'employee', header: 'Employee',
      cell: (r) => <span>{r.employee.first_name} {r.employee.last_name}</span>,
    },
    {
      key: 'order_total', header: 'Order Total', align: 'right',
      cell: (r) => <NumCell>₱{r.order_total}</NumCell>,
    },
    {
      key: 'commission_rate', header: 'Rate', align: 'right',
      cell: (r) => <NumCell>{(parseFloat(r.commission_rate) * 100).toFixed(2)}%</NumCell>,
    },
    {
      key: 'commission_amount', header: 'Commission', align: 'right',
      cell: (r) => <NumCell>₱{r.commission_amount}</NumCell>,
    },
    {
      key: 'status', header: 'Status',
      cell: (r) => <Chip variant={STATUS_CHIP[r.status]}>{r.status}</Chip>,
    },
    {
      key: 'actions', header: '', align: 'right',
      cell: (r) =>
        r.status === 'pending' && can('crm.commissions.manage') ? (
          <Button
            variant="secondary"
            size="sm"
            icon={<Check size={12} />}
            disabled={approveMutation.isPending}
            onClick={(e) => { e.stopPropagation(); setConfirmApprove(r.id); }}
          >
            Approve
          </Button>
        ) : null,
    },
  ];

  const bulkActions: BulkAction<CommissionEarning>[] = can('crm.commissions.manage')
    ? [
        {
          label: 'Mark as paid',
          icon: <DollarSign size={14} />,
          variant: 'primary',
          onClick: (rows) => {
            const approvedIds = rows.filter((r) => r.status === 'approved').map((r) => r.id);
            if (approvedIds.length === 0) {
              toast.error('Select at least one approved commission.');
              return;
            }
            batchPaidMutation.mutate(approvedIds);
          },
        },
      ]
    : [];

  const filterConfig: FilterConfig[] = [
    {
      key: 'status', label: 'Status', type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'pending', label: 'Pending' },
        { value: 'approved', label: 'Approved' },
        { value: 'paid', label: 'Paid' },
      ],
    },
  ];

  return (
    <div>
      <PageHeader
        title="Commission Earnings"
        subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'earning' : 'earnings'}` : undefined}
        actions={
          can('crm.commissions.manage') ? (
            <Link to="/crm/commissions/rates">
              <Button variant="secondary" size="sm">Manage rates</Button>
            </Link>
          ) : undefined
        }
      />
      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search by employee or SO number…"
      />
      {isLoading && !data && <SkeletonTable columns={7} rows={6} />}
      {isError && (
        <EmptyState icon="alert-circle" title="Failed to load commissions"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
      )}
      {data && data.data.length === 0 && (
        <EmptyState icon="dollar-sign" title="No commission earnings"
          description="Commission earnings will appear here once sales orders are processed." />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable
            columns={columns}
            data={data.data}
            meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
            selectable={can('crm.commissions.manage')}
            bulkActions={bulkActions}
          />
        </div>
      )}

      <ConfirmDialog
        isOpen={confirmApprove !== null}
        onClose={() => setConfirmApprove(null)}
        onConfirm={() => { if (confirmApprove) approveMutation.mutate(confirmApprove); }}
        title="Approve commission?"
        variant="warning"
        confirmLabel="Approve"
        pending={approveMutation.isPending}
      />
    </div>
  );
}
