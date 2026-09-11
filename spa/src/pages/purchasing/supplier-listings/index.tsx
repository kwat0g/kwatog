import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { supplierListingsApi } from '@/api/purchasing/supplier-listings';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type BulkAction, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar } from '@/components/ui/FilterBar';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { usePermission } from '@/hooks/usePermission';
import { formatPeso } from '@/lib/formatNumber';
import { formatDate } from '@/lib/formatDate';
import { reportMutationError } from '@/lib/formErrors';
import type { SupplierItemListing, SupplierListingStatus } from '@/types/purchasing';

const statusVariant: Record<SupplierListingStatus, 'warning' | 'success' | 'danger' | 'neutral'> = {
  pending: 'warning',
  approved: 'success',
  rejected: 'danger',
  superseded: 'neutral',
};

const statusLabel: Record<SupplierListingStatus, string> = {
  pending: 'Pending review',
  approved: 'Approved',
  rejected: 'Rejected',
  superseded: 'Superseded',
};

type ReviewFilters = { search: string; status: SupplierListingStatus | ''; page: number; per_page: number };

export default function SupplierListingsReviewPage() {
  const qc = useQueryClient();
  const { can } = usePermission();
  const canReview = can('purchasing.supplier_listings.review');
  const [filters, setFilters] = useUrlFilters<ReviewFilters>({ search: '', status: '', page: 1, per_page: 25 });
  const [rejecting, setRejecting] = useState<SupplierItemListing | null>(null);
  const [bulkRejectRows, setBulkRejectRows] = useState<SupplierItemListing[]>([]);
  const [reason, setReason] = useState('');

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['purchasing', 'supplier-listings', filters],
    queryFn: () => supplierListingsApi.list({ ...filters, status: filters.status || undefined }),
    placeholderData: (prev) => prev,
  });

  const invalidate = () => qc.invalidateQueries({ queryKey: ['purchasing', 'supplier-listings'] });

  const approve = useMutation({
    mutationFn: (listing: SupplierItemListing) => supplierListingsApi.approve(listing.id),
    onSuccess: (_, listing) => {
      invalidate();
      toast.success(`Approved listing for ${listing.item?.code ?? 'item'}. The offer is now live.`);
    },
    onError: (e: AxiosError<{ message?: string }>) => toast.error(e.response?.data?.message ?? 'Failed to approve.'),
  });

  const reject = useMutation({
    mutationFn: ({ listing, reason }: { listing: SupplierItemListing; reason: string }) =>
      supplierListingsApi.reject(listing.id, reason),
    onSuccess: () => {
      invalidate();
      toast.success('Listing rejected. The supplier will see your reason.');
      setRejecting(null);
      setReason('');
    },
    onError: (e: AxiosError<{ message?: string }>) => toast.error(e.response?.data?.message ?? 'Failed to reject.'),
  });

  const bulkApprove = useMutation({
    mutationFn: async (rows: SupplierItemListing[]) => ({
      results: await supplierListingsApi.bulkApprove(rows.map((r) => r.id)),
      selected: rows.length,
    }),
    // A partial batch may still have committed rows, so refetch regardless.
    onSettled: () => invalidate(),
    onSuccess: ({ results, selected }) => {
      const approved = results.filter((r) => r.status === 'approved').length;
      const skipped = results.filter((r) => r.status !== 'approved');
      if (skipped.length > 0) {
        const firstReason = skipped.find((r) => r.message)?.message;
        toast.error(
          `Approved ${approved} of ${selected}. ${skipped.length} skipped${firstReason ? `: ${firstReason}` : '.'}`,
          { duration: 6000 },
        );
        return;
      }
      toast.success(`${approved} listing${approved === 1 ? '' : 's'} approved.`);
    },
    onError: (e) => reportMutationError(e, 'Bulk approval failed. No listings were approved.'),
  });

  const bulkReject = useMutation({
    mutationFn: async ({ rows, reason }: { rows: SupplierItemListing[]; reason: string }) => ({
      results: await supplierListingsApi.bulkReject(rows.map((r) => r.id), reason),
      selected: rows.length,
    }),
    onSettled: () => invalidate(),
    onSuccess: ({ results, selected }) => {
      setBulkRejectRows([]);
      setReason('');
      const rejected = results.filter((r) => r.status === 'rejected').length;
      const skipped = results.filter((r) => r.status !== 'rejected');
      if (skipped.length > 0) {
        const firstReason = skipped.find((r) => r.message)?.message;
        toast.error(
          `Rejected ${rejected} of ${selected}. ${skipped.length} skipped${firstReason ? `: ${firstReason}` : '.'}`,
          { duration: 6000 },
        );
        return;
      }
      toast.success(`${rejected} listing${rejected === 1 ? '' : 's'} rejected.`);
    },
    onError: (e) => reportMutationError(e, 'Bulk rejection failed.'),
  });

  const pendingSelected = (rows: SupplierItemListing[]) => rows.filter((r) => r.status === 'pending');

  const bulkActions: BulkAction<SupplierItemListing>[] = [
    {
      label: 'Approve selected',
      variant: 'primary',
      onClick: (rows) => {
        const pending = pendingSelected(rows);
        if (pending.length === 0) {
          toast.error('Select at least one pending listing to approve.');
          return;
        }
        bulkApprove.mutate(pending);
      },
    },
    {
      label: 'Reject selected',
      variant: 'danger',
      onClick: (rows) => {
        const pending = pendingSelected(rows);
        if (pending.length === 0) {
          toast.error('Select at least one pending listing to reject.');
          return;
        }
        setBulkRejectRows(pending);
        setReason('');
      },
    },
  ];

  const columns: Column<SupplierItemListing>[] = [
    { key: 'item', header: 'Item', cell: (r) => (
      <div>
        <span className="font-mono">{r.item?.code ?? '—'}</span>
        <div className="text-xs text-muted">{r.item?.name}</div>
      </div>
    ) },
    { key: 'vendor', header: 'Supplier', cell: (r) => r.vendor?.name ?? '—' },
    { key: 'supplier_code', header: 'Their part no.', cell: (r) => (
      <span className="font-mono">{r.supplier_item_code ?? '—'}</span>
    ) },
    { key: 'price', header: 'Price', align: 'right', cell: (r) => (
      <div>
        <NumCell>{formatPeso(r.price)}</NumCell>
        {r.order_uom && r.base_qty_per_order_unit && (
          <div className="text-xs text-muted">per {r.order_uom} ({r.base_qty_per_order_unit} {r.item?.unit_of_measure ?? ''})</div>
        )}
      </div>
    ) },
    { key: 'lead', header: 'Lead time', align: 'right', cell: (r) => <NumCell>{r.lead_time_days}d</NumCell> },
    { key: 'valid_until', header: 'Valid until', cell: (r) => (
      <span className="font-mono tabular-nums">{r.valid_until ? formatDate(r.valid_until) : '—'}</span>
    ) },
    { key: 'status', header: 'Status', cell: (r) => (
      <Chip variant={statusVariant[r.status]}>{statusLabel[r.status]}</Chip>
    ) },
    ...(canReview ? [{
      key: 'actions',
      header: '',
      align: 'right' as const,
      cell: (r: SupplierItemListing) => r.status === 'pending' ? (
        <div className="flex gap-1.5 justify-end">
          <Button type="button" variant="primary" size="xs" onClick={() => approve.mutate(r)} disabled={approve.isPending}>
            Approve
          </Button>
          <Button type="button" variant="danger" size="xs" onClick={() => { setRejecting(r); setReason(''); }}>
            Reject
          </Button>
        </div>
      ) : null,
    }] : []),
  ];

  const rejectModalOpen = !!rejecting || bulkRejectRows.length > 0;

  const closeRejectModal = () => {
    setRejecting(null);
    setBulkRejectRows([]);
    setReason('');
  };

  return (
    <div>
      <PageHeader
        title="Supplier Listings"
        subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'listing' : 'listings'}` : undefined}
      />
      <div className="px-5 py-4">
        <FilterBar
          onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
          searchPlaceholder="Search supplier or item..."
          filters={[{
            key: 'status',
            label: 'Status',
            type: 'select',
            options: [
              { value: '', label: 'All statuses' },
              { value: 'pending', label: 'Pending review' },
              { value: 'approved', label: 'Approved' },
              { value: 'rejected', label: 'Rejected' },
              { value: 'superseded', label: 'Superseded' },
            ],
          }]}
          values={filters}
          onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        />
      </div>

      {isLoading && !data && <SkeletonTable columns={canReview ? 8 : 7} rows={6} />}
      {isError && <EmptyState icon="alert-circle" title="Failed to load listings" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
      {data && data.data.length === 0 && (
        <EmptyState
          icon="inbox"
          title={filters.status === 'pending' ? 'No pending listings' : 'No supplier listings yet'}
          description={filters.status === 'pending'
            ? 'You are all caught up. New supplier submissions will appear here for review.'
            : 'When suppliers submit item offers through the portal, they will appear here for approval.'}
        />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable
            tableKey="supplier-listings"
            columns={columns}
            data={data.data}
            meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
            onPageSizeChange={(per_page) => setFilters((f) => ({ ...f, per_page, page: 1 }))}
            selectable={canReview}
            bulkActions={canReview ? bulkActions : undefined}
          />
        </div>
      )}

      <Modal
        isOpen={rejectModalOpen}
        onClose={closeRejectModal}
        title={bulkRejectRows.length > 0 ? `Reject ${bulkRejectRows.length} listing(s)` : 'Reject listing'}
        size="sm"
      >
        {rejectModalOpen && (
          <>
            <div className="py-2 space-y-3">
              {bulkRejectRows.length > 0 ? (
                <p className="text-sm text-secondary">
                  Rejecting <span className="font-mono font-medium text-primary">{bulkRejectRows.length}</span> selected
                  listing(s). Every supplier will see the same reason.
                </p>
              ) : rejecting && (
                <p className="text-sm text-secondary">
                  Rejecting the offer for <span className="font-mono font-medium text-primary">{rejecting.item?.code}</span> from{' '}
                  <span className="font-medium text-primary">{rejecting.vendor?.name}</span>. They will see your reason.
                </p>
              )}
              <textarea
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                className="w-full h-24 px-3 py-2 rounded-md border border-default bg-canvas text-sm resize-none"
                placeholder="Reason for rejection (required)..."
              />
            </div>
            <ModalFooter>
              <Button variant="secondary" onClick={closeRejectModal} disabled={reject.isPending || bulkReject.isPending}>Cancel</Button>
              <Button
                variant="danger"
                onClick={() => {
                  if (bulkRejectRows.length > 0) {
                    bulkReject.mutate({ rows: bulkRejectRows, reason });
                  } else if (rejecting) {
                    reject.mutate({ listing: rejecting, reason });
                  }
                }}
                disabled={!reason.trim() || reject.isPending || bulkReject.isPending}
                loading={reject.isPending || bulkReject.isPending}
              >
                {bulkRejectRows.length > 0 ? 'Reject selected' : 'Reject listing'}
              </Button>
            </ModalFooter>
          </>
        )}
      </Modal>
    </div>
  );
}
