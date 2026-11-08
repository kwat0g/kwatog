import { useEffect, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useSearchParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { pricingRequestsApi } from '@/api/crm/pricingRequests';
import { usePermission } from '@/hooks/usePermission';
import { useRealtimeList } from '@/hooks/useRealtimeRecord';
import { Button } from '@/components/ui/Button';
import { Textarea } from '@/components/ui/Textarea';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { formatPeso } from '@/lib/formatNumber';
import type { ItemCostReview } from '@/types/pricing';
import type { PaginatedResponse } from '@/types';
import { PricingNavigation } from './PricingNavigation';

export default function CostReviewsPage() {
  const { can } = usePermission();
  const [searchParams] = useSearchParams();
  const linkedReviewId = searchParams.get('review');
  const openedReviewId = useRef<string | null>(null);
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<ItemCostReview | null>(null);
  const [notes, setNotes] = useState('');
  const [error, setError] = useState('');
  const qc = useQueryClient();
  useRealtimeList('item_cost_review', can('inventory.costs.review') ? 'visible' : undefined);
  const [reviewNeedsAcknowledgement, setReviewNeedsAcknowledgement] = useState(false);
  const query = useQuery({
    queryKey: ['cost-reviews', page, linkedReviewId],
    queryFn: () => pricingRequestsApi.costs(page, linkedReviewId ?? undefined),
    placeholderData: (previous, previousQuery) =>
      previousQuery?.queryKey[2] === linkedReviewId ? previous : undefined,
  });
  useEffect(() => {
    if (!selected || !query.data) return;
    const latest = query.data.data.find((row) => row.id === selected.id);
    if (!latest || latest.updated_at === selected.updated_at) return;
    setSelected(latest);
    setReviewNeedsAcknowledgement(true);
  }, [query.data, selected]);
  useEffect(() => {
    if (!linkedReviewId || !query.data || openedReviewId.current === linkedReviewId) return;
    const review = query.data.data.find((row) => row.id === linkedReviewId);
    if (!review) return;
    openedReviewId.current = linkedReviewId;
    setSelected(review);
    setNotes('');
    setError('');
    setReviewNeedsAcknowledgement(false);
  }, [linkedReviewId, query.data]);
  const mutation = useMutation({
    mutationFn: (decision: 'approve' | 'reject' | 'renew') => decision === 'renew'
      ? pricingRequestsApi.renewCost(selected!.id, notes)
      : pricingRequestsApi.decideCost(selected!.id, decision, notes, selected!.version),
    onSuccess: (updated, decision) => {
      qc.setQueriesData<PaginatedResponse<ItemCostReview>>(
        { queryKey: ['cost-reviews'] },
        (current) => current
          ? { ...current, data: current.data.map((row) => row.id === updated.id ? updated : row) }
          : current,
      );
      qc.invalidateQueries({ queryKey: ['cost-reviews'] });
      toast.success(
        decision === 'approve'
          ? 'Standard cost approved. Affected BOMs will refresh.'
          : decision === 'reject'
            ? 'Cost change rejected. Standard cost unchanged.'
            : 'Corrected cost review submitted for Finance approval.',
      );
      setSelected(null);
      setNotes('');
      setReviewNeedsAcknowledgement(false);
    },
    onError: (e: unknown) => {
      qc.invalidateQueries({ queryKey: ['cost-reviews'] });
      setError(
        (e as { response?: { data?: { message?: string } } }).response?.data?.message ??
          'Cannot record review. Your reason has been kept. Please try again.',
      );
    },
  });
  const columns: Column<ItemCostReview>[] = [
    {
      key: 'item',
      header: 'Item',
      className: 'min-w-48',
      cell: (r) => (
        <div className="max-w-64">
          <span className="font-mono font-medium">{r.item.code}</span>
          <div className="text-muted">{r.item.name}</div>
        </div>
      ),
    },
    {
      key: 'receipt',
      header: 'Source receipt',
      className: 'whitespace-nowrap',
      cell: (r) => <span className="font-mono">{r.receipt_number}</span>,
    },
    {
      key: 'current',
      header: 'Current standard / unit',
      align: 'right',
      className: 'min-w-40',
      cell: (r) => (
        <NumCell>
          {formatPeso(r.current_cost)}
          <span className="block text-xs text-muted">per {r.item.unit_of_measure}</span>
        </NumCell>
      ),
    },
    {
      key: 'proposed',
      header: 'Proposed standard / unit',
      align: 'right',
      className: 'min-w-40',
      cell: (r) => (
        <NumCell>
          {formatPeso(r.proposed_cost)}
          <span className="block text-xs text-muted">per {r.item.unit_of_measure}</span>
        </NumCell>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      className: 'min-w-32',
      cell: (r) => (
        <Chip variant={chipVariantForStatus(r.status)}>
          {r.status === 'pending'
            ? 'Awaiting Finance'
            : r.status === 'approved'
              ? 'Approved'
              : 'Rejected'}
        </Chip>
      ),
    },
    {
      key: 'actions',
      header: 'Action',
      align: 'right',
      cell: (r) => (
        <Button
          size="sm"
          variant={r.status === 'approved' ? 'ghost' : 'secondary'}
          onClick={() => {
            setSelected(r);
            setNotes('');
            setError('');
            setReviewNeedsAcknowledgement(false);
          }}
        >
          {(r.available_actions?.approve || r.available_actions?.reject)
            ? 'Review'
            : r.available_actions?.renew
              ? 'Renew review'
              : 'Details'}
        </Button>
      ),
    },
  ];
  const canApproveSelected = selected?.available_actions?.approve === true;
  const canRejectSelected = selected?.available_actions?.reject === true;
  const canDecide = canApproveSelected || canRejectSelected;
  const canRenew = selected?.available_actions?.renew === true;
  return (
    <div className="min-w-0 w-full">
      <PageHeader
        title="Supplier cost reviews"
        subtitle={
          query.data
            ? `${query.data.meta.total} ${query.data.meta.total === 1 ? 'review' : 'reviews'} · Standard cost changes from supplier receipts`
            : 'Standard cost changes from supplier receipts'
        }
        refreshingQueryKey={['cost-reviews']}
      />
      <PricingNavigation current="cost-reviews" />
      <div className="px-5 py-4">
        {query.isLoading && <SkeletonTable columns={6} rows={5} />}
        {query.isError && (
          <EmptyState
            icon="alert-circle"
            title="Cannot load cost reviews"
            action={<Button onClick={() => query.refetch()}>Retry</Button>}
          />
        )}
        {query.data && !query.isError && (
          <DataTable
            columns={columns}
            data={query.data.data}
            meta={query.data.meta}
            onPageChange={setPage}
            emptyState={
              <EmptyState
                icon="dollar-sign"
                title="No supplier cost reviews"
                description={
                  linkedReviewId
                    ? 'This review is unavailable or you no longer have access.'
                    : 'Supplier receipt cost changes appear here for Finance review before updating standard costs.'
                }
              />
            }
          />
        )}
      </div>
      <Modal
        isOpen={!!selected}
        onClose={() => {
          if (!mutation.isPending) setSelected(null);
        }}
        closeOnOverlayClick={!mutation.isPending}
        title={
          canRenew
            ? 'Renew supplier cost review'
            : canDecide
              ? 'Review supplier cost change'
              : 'Supplier cost review details'
        }
        size="lg"
      >
        {selected && (
          <div className="space-y-4">
            {reviewNeedsAcknowledgement && (
              <div role="status" className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-warning bg-warning-bg px-3 py-2 text-sm text-warning-fg">
                <p>This review changed while you were viewing it. The latest values are shown and your unsent reason is kept. Review the updated costs before acting.</p>
                <Button size="sm" variant="secondary" onClick={() => setReviewNeedsAcknowledgement(false)}>
                  I reviewed the updated values
                </Button>
              </div>
            )}
            <div className="flex flex-wrap items-start justify-between gap-2">
              <div className="min-w-0">
                <p className="text-sm font-medium">
                  <span className="font-mono">{selected.item.code}</span> · {selected.item.name}
                </p>
                <p className="mt-1 text-xs text-muted">
                  Receipt <span className="font-mono">{selected.receipt_number}</span> · Version{' '}
                  {selected.version}
                </p>
              </div>
              <Chip variant={chipVariantForStatus(selected.status)}>
                {selected.status === 'pending'
                  ? 'Awaiting Finance'
                  : selected.status === 'approved'
                    ? 'Approved'
                    : 'Rejected'}
              </Chip>
            </div>
            <Panel title="Standard cost comparison" meta={`Per ${selected.item.unit_of_measure}`}>
              <dl className="grid grid-cols-1 gap-4 text-sm sm:grid-cols-3">
                <div>
                  <dt className="text-xs text-muted">Standard at review creation</dt>
                  <dd className="mt-1 font-mono">{formatPeso(selected.previous_cost)}</dd>
                </div>
                <div>
                  <dt className="text-xs text-muted">Current standard</dt>
                  <dd className="mt-1 font-mono">{formatPeso(selected.current_cost)}</dd>
                </div>
                <div>
                  <dt className="text-xs text-muted">Proposed standard</dt>
                  <dd className="mt-1 font-mono font-medium">
                    {formatPeso(selected.proposed_cost)}
                  </dd>
                </div>
              </dl>
            </Panel>
            <p className="text-sm text-muted">
              Finance approval updates the standard cost and refreshes affected BOMs. Receipt
              valuation remains separate.
            </p>
            {selected.notes && (
              <Panel title="Recorded decision">
                <p className="whitespace-pre-wrap break-words text-sm text-secondary">
                  {selected.notes}
                </p>
              </Panel>
            )}
            {!!selected.history?.length && (
              <details className="rounded-md border border-default p-3">
                <summary className="cursor-pointer text-sm font-medium focus-visible:outline-accent">
                  Review history · {selected.history.length}{' '}
                  {selected.history.length === 1 ? 'entry' : 'entries'}
                </summary>
                <div className="mt-3 space-y-3">
                  {selected.history.map((entry) => (
                    <div key={entry.version} className="border-t border-default pt-3 text-sm">
                      <p className="text-xs font-medium text-muted">Version {entry.version}</p>
                      {entry.notes && (
                        <p className="mt-1 whitespace-pre-wrap break-words">{entry.notes}</p>
                      )}
                      <p className="mt-1 whitespace-pre-wrap break-words text-muted">
                        Renewal reason: {entry.renewal_reason}
                      </p>
                    </div>
                  ))}
                </div>
              </details>
            )}
            {canRenew && (
              <p className="text-sm text-warning-fg">
                Renew only after the receipt cost or current standard cost changes. The previous
                rejection stays in history.
              </p>
            )}
            {(canDecide || canRenew || (reviewNeedsAcknowledgement && Boolean(notes))) && (
              <Textarea
                label={reviewNeedsAcknowledgement && !canDecide && !canRenew
                  ? 'Your unsent reason'
                  : canRenew ? 'Corrected cost review reason' : 'Approval or rejection reason'}
                required={canDecide || canRenew}
                value={notes}
                rows={3}
                maxLength={1000}
                disabled={mutation.isPending || reviewNeedsAcknowledgement || (!canDecide && !canRenew)}
                onChange={(e) => setNotes(e.target.value)}
              />
            )}
            {error && (
              <p role="alert" className="text-sm text-danger-fg">
                {error}
              </p>
            )}
            <ModalFooter className="flex-wrap">
              <Button disabled={mutation.isPending} onClick={() => setSelected(null)}>
                {canDecide || canRenew ? 'Cancel' : 'Close'}
              </Button>
              {(canRejectSelected || canApproveSelected) && (
                <>
                  {canRejectSelected && (
                    <Button
                      variant="danger"
                      disabled={!notes.trim() || mutation.isPending || reviewNeedsAcknowledgement}
                      onClick={() => {
                        setError('');
                        mutation.mutate('reject');
                      }}
                    >
                      Reject change
                    </Button>
                  )}
                  {canApproveSelected && (
                    <Button
                      variant="primary"
                      loading={mutation.isPending && mutation.variables === 'approve'}
                      disabled={!notes.trim() || mutation.isPending || reviewNeedsAcknowledgement}
                      onClick={() => {
                        setError('');
                        mutation.mutate('approve');
                      }}
                    >
                      Approve standard cost
                    </Button>
                  )}
                </>
              )}
              {canRenew && (
                <Button
                  variant="primary"
                  loading={mutation.isPending}
                  disabled={!notes.trim() || reviewNeedsAcknowledgement}
                  onClick={() => {
                    setError('');
                    mutation.mutate('renew');
                  }}
                >
                  Submit corrected review
                </Button>
              )}
            </ModalFooter>
          </div>
        )}
      </Modal>
    </div>
  );
}
