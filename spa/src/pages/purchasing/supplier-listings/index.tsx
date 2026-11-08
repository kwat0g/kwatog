import { useState } from 'react';
import { useMutation, useQuery, useQueryClient, type QueryClient } from '@tanstack/react-query';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { supplierListingsApi, type SupplierListingBulkResult } from '@/api/purchasing/supplier-listings';
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
import { useRealtimeList } from '@/hooks/useRealtimeRecord';
import { useQueryRecordTarget } from '@/hooks/useQueryRecordTarget';
import { sameQueryExceptPage } from '@/lib/queryKeys/pageScope';
import { formatPeso } from '@/lib/formatNumber';
import { formatDate } from '@/lib/formatDate';
import { reportMutationError } from '@/lib/formErrors';
import { invalidateRealtimeChange, invalidateRealtimeChanges, type RealtimeChangeRef } from '@/lib/realtime/invalidationMap';
import type { PaginatedResponse } from '@/types';
import type { SupplierItemListing, SupplierListingStatus } from '@/types/purchasing';

const supplierListingQueryPrefix = ['purchasing', 'supplier-listings'] as const;

function reconcileSupplierListingLists(
  queryClient: QueryClient,
  updated: SupplierItemListing,
): void {
  for (const [queryKey, cached] of queryClient.getQueriesData<PaginatedResponse<SupplierItemListing>>({
    queryKey: supplierListingQueryPrefix,
  })) {
    if (!cached || !cached.data.some((listing) => listing.id === updated.id)) continue;

    const filters = queryKey[2] && typeof queryKey[2] === 'object'
      ? queryKey[2] as Partial<ReviewFilters>
      : undefined;
    const remove = !!filters?.status && filters.status !== updated.status;
    const data = remove
      ? cached.data.filter((listing) => listing.id !== updated.id)
      : cached.data.map((listing) => listing.id === updated.id ? updated : listing);

    if (!remove) {
      queryClient.setQueryData<PaginatedResponse<SupplierItemListing>>(queryKey, { ...cached, data });
      continue;
    }

    const total = Math.max(0, cached.meta.total - 1);
    const pageStart = (cached.meta.current_page - 1) * cached.meta.per_page + 1;
    queryClient.setQueryData<PaginatedResponse<SupplierItemListing>>(queryKey, {
      ...cached,
      data,
      meta: {
        ...cached.meta,
        total,
        last_page: Math.max(1, Math.ceil(total / Math.max(1, cached.meta.per_page))),
        from: total === 0 || pageStart > total ? null : pageStart,
        to: data.length === 0 ? null : Math.min(total, cached.meta.current_page * cached.meta.per_page),
      },
    });
  }
}

type SupplierListingBulkStatusUpdate = {
  id: string;
  status: Extract<SupplierListingStatus, 'approved' | 'rejected'>;
};

function successfulBulkStatusUpdates(results: SupplierListingBulkResult[]): SupplierListingBulkStatusUpdate[] {
  return results.flatMap((result) => result.status === 'approved' || result.status === 'rejected'
    ? [{ id: result.id, status: result.status }]
    : []);
}

function reconcileBulkSupplierListingStatuses(
  queryClient: QueryClient,
  updates: SupplierListingBulkStatusUpdate[],
  rejectionReason?: string,
): void {
  const statusById = new Map(updates.map((update) => [update.id, update.status]));
  if (statusById.size === 0) return;

  for (const [queryKey, cached] of queryClient.getQueriesData<PaginatedResponse<SupplierItemListing>>({
    queryKey: supplierListingQueryPrefix,
  })) {
    if (!cached) continue;

    const filters = queryKey[2] && typeof queryKey[2] === 'object'
      ? queryKey[2] as Partial<ReviewFilters>
      : undefined;
    let changed = 0;
    let removed = 0;
    const data = cached.data.flatMap((listing) => {
      const status = statusById.get(listing.id);
      if (!status) return [listing];

      changed += 1;
      if (filters?.status && filters.status !== status) {
        removed += 1;
        return [];
      }

      return [{
        ...listing,
        status,
        ...(status === 'rejected' && rejectionReason !== undefined ? { rejection_reason: rejectionReason } : {}),
        available_actions: { approve: false, reject: false },
      }];
    });
    if (changed === 0) continue;

    if (removed === 0) {
      queryClient.setQueryData<PaginatedResponse<SupplierItemListing>>(queryKey, { ...cached, data });
      continue;
    }

    const total = Math.max(0, cached.meta.total - removed);
    const pageStart = (cached.meta.current_page - 1) * cached.meta.per_page + 1;
    queryClient.setQueryData<PaginatedResponse<SupplierItemListing>>(queryKey, {
      ...cached,
      data,
      meta: {
        ...cached.meta,
        total,
        last_page: Math.max(1, Math.ceil(total / Math.max(1, cached.meta.per_page))),
        from: total === 0 || pageStart > total ? null : pageStart,
        to: data.length === 0 ? null : Math.min(total, cached.meta.current_page * cached.meta.per_page),
      },
    });
  }
}

function supplierListingChange(id: string): RealtimeChangeRef {
  return { entityType: 'supplier_listing', entityId: id };
}

function invalidateSupplierListingIds(queryClient: QueryClient, ids: string[]): void {
  const uniqueIds = [...new Set(ids)];
  if (uniqueIds.length > 0) void invalidateRealtimeChanges(queryClient, uniqueIds.map(supplierListingChange));
}

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

type ReviewFilters = { id: string; search: string; status: SupplierListingStatus | ''; page: number; per_page: number };

export default function SupplierListingsReviewPage() {
  const qc = useQueryClient();
  const { canApprove } = usePermission();
  const canReview = canApprove('purchasing.supplier_listings.review');
  const [filters, setFilters] = useUrlFilters<ReviewFilters>({ id: '', search: '', status: '', page: 1, per_page: 25 });
  const [bulkRejectIds, setBulkRejectIds] = useState<string[]>([]);
  const [reason, setReason] = useState('');

  useRealtimeList('supplier_listing', 'visible');

  const queryKey = [...supplierListingQueryPrefix, filters] as const;
  const { data, isLoading, isFetching, isError, refetch } = useQuery({
    queryKey,
    queryFn: () => supplierListingsApi.list({ ...filters, status: filters.status || undefined }),
    placeholderData: (previous, previousQuery) =>
      sameQueryExceptPage(previousQuery?.queryKey, queryKey, 2) ? previous : undefined,
  });
  const rejectActionTarget = useQueryRecordTarget(data?.data, { isFetching, isError });
  const rejecting = rejectActionTarget.target;
  const bulkRejectRows = bulkRejectIds
    .map((id) => data?.data.find((listing) => listing.id === id) ?? null)
    .filter((listing): listing is SupplierItemListing => listing !== null);
  const canConfirmBulkReject = bulkRejectIds.length > 0 &&
    !isFetching && !isError &&
    bulkRejectRows.length === bulkRejectIds.length &&
    bulkRejectRows.every((listing) => listing.available_actions?.reject === true);

  const refreshListing = (updated: SupplierItemListing) => {
    reconcileSupplierListingLists(qc, updated);
    void invalidateRealtimeChange(qc, supplierListingChange(updated.id));
  };

  const approve = useMutation({
    mutationFn: (listing: SupplierItemListing) => supplierListingsApi.approve(listing.id),
    onSuccess: (updated, listing) => {
      refreshListing(updated);
      toast.success(`Approved listing for ${listing.item?.code ?? 'item'}. The offer is now live.`);
    },
    onError: (e: AxiosError<{ message?: string }>, listing) => {
      invalidateSupplierListingIds(qc, [listing.id]);
      toast.error(e.response?.data?.message ?? 'Failed to approve.');
    },
  });

  const reject = useMutation({
    mutationFn: ({ listing, reason }: { listing: SupplierItemListing; reason: string }) =>
      supplierListingsApi.reject(listing.id, reason),
    onSuccess: (updated) => {
      refreshListing(updated);
      toast.success('Listing rejected. The supplier will see your reason.');
      rejectActionTarget.close();
      setReason('');
    },
    onError: (e: AxiosError<{ message?: string }>, variables) => {
      invalidateSupplierListingIds(qc, [variables.listing.id]);
      toast.error(e.response?.data?.message ?? 'Failed to reject.');
    },
  });

  const bulkApprove = useMutation({
    mutationFn: async (rows: SupplierItemListing[]) => ({
      results: await supplierListingsApi.bulkApprove(rows.map((r) => r.id)),
      selected: rows.length,
    }),
    // A partial batch may still have committed rows, so refetch regardless.
    onSettled: (_result, _error, rows) => invalidateSupplierListingIds(qc, rows.map((row) => row.id)),
    onSuccess: ({ results, selected }) => {
      reconcileBulkSupplierListingStatuses(qc, successfulBulkStatusUpdates(results));
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
    onSettled: (_result, _error, variables) => invalidateSupplierListingIds(qc, variables.rows.map((row) => row.id)),
    onSuccess: ({ results, selected }, variables) => {
      reconcileBulkSupplierListingStatuses(qc, successfulBulkStatusUpdates(results), variables.reason);
      setBulkRejectIds([]);
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

  const pendingSelected = (rows: SupplierItemListing[], action: 'approve' | 'reject') => rows.filter((r) =>
    r.available_actions?.[action] === true,
  );

  const bulkActions: BulkAction<SupplierItemListing>[] = [
    {
      label: 'Approve selected',
      variant: 'primary',
      disabled: isFetching || isError || bulkApprove.isPending || bulkReject.isPending,
      onClick: (rows) => {
        if (isFetching || isError) return;
        const pending = pendingSelected(rows, 'approve');
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
      disabled: isFetching || isError || bulkApprove.isPending || bulkReject.isPending,
      onClick: (rows) => {
        if (isFetching || isError) return;
        const pending = pendingSelected(rows, 'reject');
        if (pending.length === 0) {
          toast.error('Select at least one pending listing to reject.');
          return;
        }
        setBulkRejectIds(pending.map((row) => row.id));
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
    ...(canReview || data?.data.some((row) => row.available_actions?.approve || row.available_actions?.reject) ? [{
      key: 'actions',
      header: '',
      align: 'right' as const,
      cell: (r: SupplierItemListing) => {
        const canApproveRow = r.available_actions?.approve === true;
        const canRejectRow = r.available_actions?.reject === true;
        if (!canApproveRow && !canRejectRow) return null;

        return (
        <div className="flex gap-1.5 justify-end">
          {canApproveRow && <Button type="button" variant="primary" size="xs" onClick={() => {
            const current = data?.data.find((listing) => listing.id === r.id);
            if (!isFetching && !isError && current?.available_actions?.approve) approve.mutate(current);
          }} disabled={isFetching || isError || approve.isPending || reject.isPending}>
            Approve
          </Button>}
          {canRejectRow && <Button type="button" variant="danger" size="xs" onClick={() => { rejectActionTarget.open(r); setReason(''); }} disabled={isFetching || isError || approve.isPending || reject.isPending}>
            Reject
          </Button>}
        </div>
        );
      },
    }] : []),
  ];

  const rejectModalOpen = rejectActionTarget.targetId !== null || bulkRejectIds.length > 0;

  const closeRejectModal = () => {
    rejectActionTarget.close();
    setBulkRejectIds([]);
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
        title={bulkRejectIds.length > 0 ? `Reject ${bulkRejectIds.length} listing(s)` : 'Reject listing'}
        size="sm"
      >
        {rejectModalOpen && (
          <>
            <div className="py-2 space-y-3">
              {bulkRejectIds.length > 0 ? (
                isFetching ? (
                  <p role="status" className="text-sm text-muted">Checking the latest status of the selected listings…</p>
                ) : isError ? (
                  <p role="alert" className="text-sm text-danger-fg">The selected listings could not be checked. Refresh before rejecting.</p>
                ) : !canConfirmBulkReject ? (
                  <p role="alert" className="text-sm text-warning-fg">One or more selected listings are no longer available for rejection. Close this dialog and select the current pending listings.</p>
                ) : (
                  <p className="text-sm text-secondary">
                    Rejecting <span className="font-mono font-medium text-primary">{bulkRejectIds.length}</span> selected
                    listing(s). Every supplier will see the same reason.
                  </p>
                )
              ) : rejectActionTarget.isChecking ? (
                <p role="status" className="text-sm text-muted">Checking the latest listing status…</p>
              ) : rejectActionTarget.isUnverified ? (
                <p role="alert" className="text-sm text-danger-fg">The listing status could not be checked. Refresh before rejecting.</p>
              ) : !rejecting || rejecting.available_actions?.reject !== true ? (
                <p role="alert" className="text-sm text-warning-fg">This listing is no longer available for rejection. Your reason is kept.</p>
              ) : (
                <p className="text-sm text-secondary">
                  Rejecting the offer for <span className="font-mono font-medium text-primary">{rejecting.item?.code}</span> from{' '}
                  <span className="font-medium text-primary">{rejecting.vendor?.name}</span>. They will see your reason.
                </p>
              )}
              <textarea
                aria-label="Reason for rejection"
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
                  if (bulkRejectIds.length > 0 && canConfirmBulkReject) {
                    bulkReject.mutate({ rows: bulkRejectRows, reason });
                  } else if (rejecting && rejectActionTarget.isFresh && rejecting.available_actions?.reject === true) {
                    reject.mutate({ listing: rejecting, reason });
                  }
                }}
                disabled={!reason.trim() || reject.isPending || bulkReject.isPending ||
                  (bulkRejectIds.length > 0 ? !canConfirmBulkReject :
                    !rejectActionTarget.isFresh || !rejecting || rejecting.available_actions?.reject !== true)}
                loading={reject.isPending || bulkReject.isPending}
              >
                {bulkRejectIds.length > 0 ? 'Reject selected' : 'Reject listing'}
              </Button>
            </ModalFooter>
          </>
        )}
      </Modal>
    </div>
  );
}
