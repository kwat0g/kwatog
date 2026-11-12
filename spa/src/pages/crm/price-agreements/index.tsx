import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { Link, useNavigate } from 'react-router-dom';
import { LuArchiveRestore, LuClock, LuPencil, LuPlus, LuTrash2 } from '@/lib/icons';
import {
  priceAgreementsApi,
  type PriceAgreementListParams,
  type PriceAgreementTerminationHistoryEntry,
} from '@/api/crm/priceAgreements';
import { ArchiveFilter } from '@/components/ui/ArchiveFilter';
import { archiveToTrashed, type ArchiveScope } from '@/lib/archiveScope';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Checkbox } from '@/components/ui/Checkbox';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar } from '@/components/ui/FilterBar';
import { Input } from '@/components/ui/Input';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { Textarea } from '@/components/ui/Textarea';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import type { PriceAgreement } from '@/types/crm';
import { formatDateTime, manilaIsoDate } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import { PricingNavigation } from './PricingNavigation';
import { sameQueryExceptPage } from '@/lib/queryKeys/pageScope';

export default function PriceAgreementsListPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { can } = usePermission();
  const canPropose = can('crm.price_agreements.propose');
  const canManage = can('crm.price_agreements.manage');
  const [filters, setFilters] = useUrlFilters<PriceAgreementListParams>({
    search: '',
    agreement: '',
    page: 1,
    per_page: 25,
  });
  const [scope, setScope] = useState<ArchiveScope>('active');
  const [confirmDelete, setConfirmDelete] = useState<PriceAgreement | null>(null);
  const [agreementToEnd, setAgreementToEnd] = useState<PriceAgreement | null>(null);
  const [agreementToCancelRequest, setAgreementToCancelRequest] = useState<PriceAgreement | null>(null);
  const [historyAgreement, setHistoryAgreement] = useState<PriceAgreement | null>(null);
  const [endDate, setEndDate] = useState('');
  const [endReason, setEndReason] = useState('');
  const [coverageGapAcknowledged, setCoverageGapAcknowledged] = useState(false);
  const listQueryKey = ['crm', 'price-agreements', filters, { trashed: archiveToTrashed(scope) }] as const;

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: listQueryKey,
    queryFn: () => priceAgreementsApi.list({ ...filters, trashed: archiveToTrashed(scope) }),
    placeholderData: (prev, previousQuery) =>
      sameQueryExceptPage(previousQuery?.queryKey, listQueryKey, 2) ? prev : undefined,
  });

  const del = useMutation({
    mutationFn: (id: string) => priceAgreementsApi.delete(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['crm', 'price-agreements'] });
      toast.success('Price agreement archived.');
      setConfirmDelete(null);
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Failed to archive price agreement.');
    },
  });

  const restore = useMutation({
    mutationFn: (id: string) => priceAgreementsApi.restore(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['crm', 'price-agreements'] });
      toast.success('Price agreement restored.');
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Failed to restore price agreement.');
    },
  });

  const requestTermination = useMutation({
    mutationFn: ({ id, last_valid_date, reason, coverage_gap_acknowledged }: {
      id: string; last_valid_date?: string; reason: string; coverage_gap_acknowledged: boolean;
    }) => priceAgreementsApi.requestTermination(id, { last_valid_date, reason, coverage_gap_acknowledged }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['crm', 'price-agreements'] });
      toast.success('Signature request sent to the customer. Price terms stay unchanged until signed.');
      setAgreementToEnd(null);
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Could not send the signature request. Review the details and try again.');
    },
  });

  const cancelTerminationRequest = useMutation({
    mutationFn: ({ id, documentId }: { id: string; documentId: string }) => priceAgreementsApi.cancelTerminationRequest(id, documentId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['crm', 'price-agreements'] });
      toast.success('Customer signature request cancelled. The price agreement is unchanged.');
      setAgreementToCancelRequest(null);
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Could not cancel the signature request. Refresh and try again.');
    },
  });

  const openEndEarly = (agreement: PriceAgreement) => {
    setEndDate(manilaIsoDate());
    setEndReason('');
    setCoverageGapAcknowledged(false);
    setAgreementToEnd(agreement);
  };

  const isScheduledTermination = Boolean(agreementToEnd && agreementToEnd.effective_from > manilaIsoDate());
  const terminationPreview = useQuery({
    queryKey: ['crm', 'price-agreement-termination-preview', agreementToEnd?.id, isScheduledTermination ? null : endDate],
    queryFn: () => priceAgreementsApi.previewTermination(agreementToEnd!.id, isScheduledTermination ? undefined : endDate),
    enabled: Boolean(agreementToEnd && (isScheduledTermination || endDate)),
    retry: false,
  });
  const terminationHistory = useQuery({
    queryKey: ['crm', 'price-agreement-termination-history', historyAgreement?.id],
    queryFn: () => priceAgreementsApi.terminationHistory(historyAgreement!.id),
    enabled: Boolean(historyAgreement),
    retry: false,
  });

  const earliestEndDate = agreementToEnd
    ? agreementToEnd.effective_from > manilaIsoDate()
      ? agreementToEnd.effective_from
      : manilaIsoDate()
    : manilaIsoDate();
  const latestEndDate = agreementToEnd ? dayBefore(agreementToEnd.effective_to) : '';
  const canSubmitEndEarly = Boolean(
    agreementToEnd
      && (isScheduledTermination || (endDate >= earliestEndDate && endDate < agreementToEnd.effective_to))
      && endReason.trim().length >= 10
      && terminationPreview.data
      && !(!terminationPreview.data.has_coverage && !coverageGapAcknowledged)
      && !terminationPreview.isError
      && !requestTermination.isPending,
  );

  const columns: Column<PriceAgreement>[] = [
    {
      key: 'product',
      header: 'Product',
      className: 'min-w-44',
      cell: (r) =>
        r.product ? (
          <div>
            <span className="font-mono">{r.product.part_number}</span> — {r.product.name}
          </div>
        ) : (
          <span className="text-muted">—</span>
        ),
    },
    {
      key: 'customer',
      header: 'Customer',
      className: 'min-w-36',
      cell: (r) => r.customer?.name ?? '—',
    },
    {
      key: 'price',
      header: 'Price',
      className: 'min-w-32',
      align: 'right',
      cell: (r) => (
        <NumCell>
          {formatPeso(r.price)}
          <span className="block text-xs text-muted">
            {r.pricing_method === 'tiered' ? 'Saved fallback price' : 'Any quantity'} · VAT excluded
          </span>
        </NumCell>
      ),
    },
    {
      key: 'quantity',
      header: 'Quantity and unit price',
      className: 'min-w-44',
      cell: (r) =>
        r.pricing_method === 'tiered' && r.tiers?.length ? (
          <div className="space-y-1 text-sm">
            <div>
              Below {r.tiers[0].min_qty}: {formatPeso(r.price)}
            </div>
            {r.tiers.map((tier) => (
              <div key={tier.min_qty} className="tabular-nums">
                {tier.min_qty}+ {r.product?.unit_of_measure}: {formatPeso(tier.unit_price)}
              </div>
            ))}
            <div className="text-xs text-muted">
              Per product, per order. Highest eligible tier applies.
            </div>
          </div>
        ) : (
          <span className="text-muted">No minimum quantity</span>
        ),
    },
    {
      key: 'review',
      header: 'Price review',
      className: 'min-w-48',
      cell: (r) => (
        <div className="max-w-64 space-y-1.5 text-xs">
          {r.pricing_warnings?.length ? (
            <>
              <Chip
                variant={
                  r.pricing_warnings.some((warning) =>
                    warning.includes('new quotes at this price are blocked'),
                  )
                    ? 'danger'
                    : 'warning'
                }
              >
                {r.pricing_warnings.some((warning) =>
                  warning.includes('new quotes at this price are blocked'),
                )
                  ? 'New quotes blocked'
                  : 'Review needed'}
              </Chip>
              <details>
                <summary className="cursor-pointer text-muted hover:text-primary focus-visible:outline-accent">
                  {r.pricing_warnings.length} {r.pricing_warnings.length === 1 ? 'issue' : 'issues'}{' '}
                  · Price details
                </summary>
                <div className="mt-2 space-y-2">
                  <div className="text-muted">
                    Base: {formatPeso(r.product?.base_selling_price, 'Not configured')}
                    <br />
                    Minimum: {formatPeso(r.product?.minimum_selling_price, 'Not configured')}
                  </div>
                  <ul className="list-disc space-y-1 pl-4 text-warning-fg">
                    {r.pricing_warnings.map((warning) => (
                      <li key={warning}>{warning}</li>
                    ))}
                  </ul>
                </div>
              </details>
            </>
          ) : (
            <>
              <span className="text-muted">No price warnings</span>
              <div className="text-muted">
                Base {formatPeso(r.product?.base_selling_price, 'Not configured')} · Min.{' '}
                {formatPeso(r.product?.minimum_selling_price, 'Not configured')}
              </div>
            </>
          )}
        </div>
      ),
    },
    {
      key: 'effective_from',
      header: 'From',
      className: 'whitespace-nowrap',
      align: 'right',
      cell: (r) => <NumCell>{r.termination_status === 'cancelled_before_start' ? 'Cancelled before start' : r.effective_from}</NumCell>,
    },
    {
      key: 'effective_to',
      header: 'To',
      className: 'whitespace-nowrap',
      align: 'right',
      cell: (r) => <NumCell>{r.termination_status === 'cancelled_before_start' ? '—' : r.effective_to}</NumCell>,
    },
    {
      key: 'status',
      header: 'Status',
      cell: (r) => (
        <div className="space-y-1.5">
          {r.deleted_at ? (
            <Chip variant="neutral">Archived</Chip>
          ) : r.termination_status === 'awaiting_customer_signature' ? (
            <div className="max-w-56 space-y-1" title={r.termination_reason ?? undefined}>
              <Chip variant="warning">Awaiting customer signature</Chip>
              {r.termination_reason && <p className="line-clamp-2 text-xs text-muted">{r.termination_reason}</p>}
            </div>
          ) : r.termination_status === 'ended_early' ? (
            <div className="max-w-56 space-y-1" title={r.termination_reason ?? undefined}>
              <Chip variant="warning">Ended early</Chip>
              {r.termination_reason && <p className="line-clamp-2 text-xs text-muted">{r.termination_reason}</p>}
            </div>
          ) : r.termination_status === 'cancelled_before_start' ? (
            <div className="max-w-56 space-y-1" title={r.termination_reason ?? undefined}>
              <Chip variant="neutral">Cancelled</Chip>
              {r.termination_reason && <p className="line-clamp-2 text-xs text-muted">{r.termination_reason}</p>}
            </div>
          ) : r.termination_status === 'request_cancelled' ? (
            <div className="max-w-56 space-y-1" title={r.termination_reason ?? undefined}>
              <Chip variant="neutral">Request cancelled</Chip>
              {r.termination_reason && <p className="line-clamp-2 text-xs text-muted">{r.termination_reason}</p>}
            </div>
          ) : r.is_currently_active ? (
            <Chip variant="success">Active</Chip>
          ) : r.effective_from > manilaIsoDate() ? (
            <Chip variant="info">Scheduled</Chip>
          ) : (
            <Chip variant="neutral">Expired</Chip>
          )}
          {(r.termination_history_count ?? 0) > 0 && (
            <Button
              type="button"
              variant="secondary"
              size="sm"
              icon={<LuClock size={13} />}
              aria-label={`View termination history for ${r.customer?.name ?? 'customer'}`}
              onClick={() => setHistoryAgreement(r)}
            >
              History
            </Button>
          )}
        </div>
      ),
    },
    ...(canManage || canPropose
      ? [
          {
            key: 'actions',
            header: '',
            align: 'right' as const,
            cell: (r: PriceAgreement) =>
              r.deleted_at ? (
                canManage ? (
                <Button
                  type="button"
                  variant="secondary"
                  size="sm"
                  icon={<LuArchiveRestore size={14} />}
                  aria-label={`Restore agreement for ${r.customer?.name ?? 'customer'}`}
                  onClick={() => restore.mutate(r.id)}
                >
                  Restore
                </Button>
                ) : null
              ) : (
                <div className="flex flex-wrap justify-end gap-2">
                  {canPropose && (
                    <Link
                      to={`/crm/price-agreements/${r.id}/edit`}
                      className="inline-flex min-h-8 items-center gap-1.5 rounded-md border border-default bg-canvas px-3 text-sm text-primary transition-colors hover:bg-elevated focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                      aria-label={`Propose replacement agreement for ${r.customer?.name ?? 'customer'}`}
                    >
                      <LuPencil size={14} />
                      Propose replacement
                    </Link>
                  )}
                  {canManage && r.termination_status === 'awaiting_customer_signature' && r.termination_document_id && (
                    <Button type="button" variant="secondary" size="sm" onClick={() => setAgreementToCancelRequest(r)}>
                      Cancel signature request
                    </Button>
                  )}
                  {canManage && !['awaiting_customer_signature', 'ended_early', 'cancelled_before_start'].includes(r.termination_status ?? '') && r.effective_to > manilaIsoDate() && (
                    <Button type="button" variant="danger" size="sm" onClick={() => openEndEarly(r)}>
                      Request termination
                    </Button>
                  )}
                  {/* Manila, not the viewer's zone: the backend expires agreements on the
                      Philippine calendar date (APP_TIMEZONE=Asia/Manila), so a browser set to
                      UTC would otherwise offer Archive a day late. */}
                  {canManage && r.effective_to < manilaIsoDate() && (
                    <Button
                      type="button"
                      variant="secondary"
                      size="sm"
                      icon={<LuTrash2 size={14} />}
                      aria-label="Archive agreement"
                      onClick={() => setConfirmDelete(r)}
                    >
                      Archive
                    </Button>
                  )}
                </div>
              ),
          },
        ]
      : []),
  ];

  return (
    <div>
      <PageHeader
        title="Price agreements"
        subtitle={
          data
            ? `${data.meta.total} ${data.meta.total === 1 ? 'agreement' : 'agreements'}`
            : undefined
        }
        actions={
          canPropose ? (
            <Button
              variant="primary"
              icon={<LuPlus size={14} />}
              onClick={() => navigate('/crm/price-agreements/create')}
            >
              Propose agreement
            </Button>
          ) : null
        }
      />
      <PricingNavigation current="agreements" />
      <FilterBar
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        searchPlaceholder="Search product or customer…"
        actions={<ArchiveFilter value={scope} onChange={setScope} />}
      />
      {isLoading && !data && <SkeletonTable columns={canManage || canPropose ? 9 : 8} rows={8} />}
      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load price agreements"
          action={
            <Button variant="secondary" onClick={() => refetch()}>
              Retry
            </Button>
          }
        />
      )}
      {data && data.data.length === 0 && (
        <EmptyState
          icon="dollar-sign"
          title={scope === 'only' ? 'No archived price agreements' : 'No price agreements yet'}
          description={
            canPropose
              ? 'Create a price agreement to set customer-specific pricing.'
              : 'Nothing here yet.'
          }
          action={
            canPropose && scope !== 'only' ? (
              <Button variant="primary" onClick={() => navigate('/crm/price-agreements/create')}>
                Propose agreement
              </Button>
            ) : undefined
          }
        />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable
            columns={columns}
            data={data.data}
            meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
            onPageSizeChange={(per_page) => setFilters((f) => ({ ...f, per_page, page: 1 }))}
          />
        </div>
      )}
      <ConfirmDialog
        isOpen={!!confirmDelete}
        onClose={() => setConfirmDelete(null)}
        onConfirm={() => {
          if (confirmDelete) del.mutate(confirmDelete.id);
        }}
        title="Archive price agreement?"
        description={
          confirmDelete ? (
            <>
              This will archive the agreement for{' '}
              <span className="font-medium text-primary">
                {confirmDelete.customer?.name ?? 'the selected customer'}
              </span>{' '}
              and it can be restored later.
            </>
          ) : null
        }
        confirmLabel="Archive"
        variant="danger"
        pending={del.isPending}
      />
      <Modal
        isOpen={!!agreementToEnd}
        onClose={() => {
          if (!requestTermination.isPending) setAgreementToEnd(null);
        }}
        closeOnOverlayClick={!requestTermination.isPending}
        title={isScheduledTermination ? 'Request scheduled agreement cancellation' : 'Request early termination'}
        size="md"
      >
        {agreementToEnd && (
          <form
            className="space-y-4 pt-4"
            onSubmit={(event) => {
              event.preventDefault();
              if (canSubmitEndEarly) {
                requestTermination.mutate({
                  id: agreementToEnd.id,
                  last_valid_date: isScheduledTermination ? undefined : endDate,
                  reason: endReason.trim(),
                  coverage_gap_acknowledged: coverageGapAcknowledged,
                });
              }
            }}
          >
            <div className="rounded-md border border-warning bg-warning-bg px-3 py-2.5 text-sm text-warning-fg">
              <p className="font-medium">{agreementToEnd.product?.part_number} · {agreementToEnd.customer?.name}</p>
              <p className="mt-1">
                {isScheduledTermination
                  ? 'This agreement has not started yet. The customer must sign its cancellation before it takes effect.'
                  : 'The price terms remain unchanged until the customer signs. Existing orders keep their saved prices.'}
              </p>
            </div>
            {!isScheduledTermination && <Input
                label="Last valid date"
                type="date"
                required
                min={earliestEndDate}
                max={latestEndDate}
                value={endDate}
                onChange={(event) => setEndDate(event.target.value)}
                helper="The agreed price stays valid through this full date, if the customer signs."
                error={endDate && (endDate < earliestEndDate || endDate >= agreementToEnd.effective_to)
                  ? 'Choose a date from today through the day before the current end date.'
                  : undefined}
              />}
            <div className="rounded-md border border-default bg-subtle px-3 py-2.5 text-sm" aria-live="polite">
              {terminationPreview.isLoading ? (
                <p className="text-muted">Checking price availability after this change…</p>
              ) : terminationPreview.isError ? (
                <p className="text-danger-fg">Could not check price availability. Refresh the agreement and try again.</p>
              ) : terminationPreview.data?.has_coverage ? (
                <p>
                  Price coverage continues on <strong>{terminationPreview.data.coverage_date}</strong> through {terminationPreview.data.coverage_source === 'agreement' ? 'another customer agreement' : 'the product base price'}.
                </p>
              ) : terminationPreview.data ? (
                <p className="text-warning-fg">
                  No agreement or valid base price covers orders on <strong>{terminationPreview.data.coverage_date}</strong>. New orders may be blocked until Sales configures a price.
                </p>
              ) : (
                <p className="text-muted">Choose a last-valid date to check whether future orders will have a price.</p>
              )}
            </div>
            {terminationPreview.data && !terminationPreview.data.has_coverage && <Checkbox
              checked={coverageGapAcknowledged}
              onChange={(event) => setCoverageGapAcknowledged(event.target.checked)}
              label="I understand orders may be blocked until another price is configured."
              className="items-start"
            />}
            <Textarea
              label="Reason"
              required
              minLength={10}
              maxLength={1000}
              rows={3}
              value={endReason}
              onChange={(event) => setEndReason(event.target.value)}
              helper="Record why the pricing term is ending."
            />
            <ModalFooter>
              <Button type="button" variant="secondary" disabled={requestTermination.isPending} onClick={() => setAgreementToEnd(null)}>
                Cancel
              </Button>
              <Button type="submit" variant="danger" loading={requestTermination.isPending} disabled={!canSubmitEndEarly}>
                Send for customer signature
              </Button>
            </ModalFooter>
          </form>
        )}
      </Modal>
      <ConfirmDialog
        isOpen={agreementToCancelRequest !== null}
        onClose={() => setAgreementToCancelRequest(null)}
        onConfirm={() => {
          if (agreementToCancelRequest?.termination_document_id) {
            cancelTerminationRequest.mutate({ id: agreementToCancelRequest.id, documentId: agreementToCancelRequest.termination_document_id });
          }
        }}
        title="Cancel customer signature request?"
        description="The customer will no longer be able to sign this document. The price agreement itself stays unchanged."
        confirmLabel="Cancel request"
        variant="danger"
        pending={cancelTerminationRequest.isPending}
      />
      <Modal
        isOpen={historyAgreement !== null}
        onClose={() => setHistoryAgreement(null)}
        title="Termination history"
        size="md"
      >
        {historyAgreement && (
          <div className="space-y-4 pt-4">
            <div className="rounded-md border border-default bg-subtle px-3 py-2.5 text-sm">
              <p className="font-medium text-primary">{historyAgreement.product?.part_number} · {historyAgreement.product?.name}</p>
              <p className="mt-0.5 text-muted">{historyAgreement.customer?.name}</p>
            </div>
            {terminationHistory.isLoading ? (
              <p className="py-4 text-sm text-muted">Loading agreement history…</p>
            ) : terminationHistory.isError ? (
              <div className="space-y-3 py-2 text-sm">
                <p className="text-danger-fg">Could not load the termination history.</p>
                <Button variant="secondary" size="sm" onClick={() => void terminationHistory.refetch()}>Try again</Button>
              </div>
            ) : terminationHistory.data?.entries.length ? (
              <ol className="space-y-0">
                {terminationHistory.data.entries.map((entry, index) => (
                  <li key={`${entry.status}-${entry.occurred_at ?? index}-${index}`} className="flex gap-3">
                    <div className="flex w-4 shrink-0 flex-col items-center">
                      <span className={`mt-1.5 h-2.5 w-2.5 rounded-full ${entry.actor_type === 'customer_portal' ? 'bg-success-bg' : 'bg-info-bg'}`} />
                      {index < terminationHistory.data!.entries.length - 1 && <span className="mt-1 w-px flex-1 bg-border-default" />}
                    </div>
                    <div className="min-w-0 flex-1 pb-4">
                      <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <Chip variant={entry.actor_type === 'customer_portal' ? 'success' : 'neutral'}>
                          {terminationHistoryLabel(entry.status)}
                        </Chip>
                        <span className="text-xs text-muted">
                          {entry.actor_type === 'customer_portal'
                            ? entry.signed_name ? `Signed by ${entry.signed_name}` : 'Customer signatory'
                            : 'CRM staff'}
                        </span>
                      </div>
                      <p className="mt-1 text-xs text-muted">{formatDateTime(entry.occurred_at)}</p>
                      {entry.last_valid_date && <p className="mt-1 text-sm">Price valid through {entry.last_valid_date}</p>}
                      {entry.reason && <p className="mt-1 whitespace-pre-wrap break-words text-sm text-secondary">{entry.reason}</p>}
                    </div>
                  </li>
                ))}
              </ol>
            ) : (
              <p className="py-4 text-sm text-muted">No termination events are recorded for this agreement.</p>
            )}
            {terminationHistory.data?.has_older_entries && (
              <p className="text-xs text-muted">Showing the 50 most recent events.</p>
            )}
            <ModalFooter>
              <Button type="button" variant="secondary" onClick={() => setHistoryAgreement(null)}>Close</Button>
            </ModalFooter>
          </div>
        )}
      </Modal>
    </div>
  );
}

function terminationHistoryLabel(status: PriceAgreementTerminationHistoryEntry['status']): string {
  switch (status) {
    case 'awaiting_customer_signature': return 'Customer signature requested';
    case 'request_cancelled': return 'Signature request cancelled';
    case 'ended_early': return 'Customer signed early termination';
    case 'cancelled_before_start': return 'Customer signed cancellation';
    default: return 'Agreement updated';
  }
}

function dayBefore(isoDate: string): string {
  const [year, month, day] = isoDate.split('-').map(Number);
  // Use noon UTC so the date remains the same Manila calendar day regardless
  // of the viewer's timezone. Midnight UTC can roll back to the prior day.
  return manilaIsoDate(new Date(Date.UTC(year, month - 1, day - 1, 12)));
}
