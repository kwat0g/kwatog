import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import toast from 'react-hot-toast';
import { LuPlus } from '@/lib/icons';
import { pricingRequestsApi } from '@/api/crm/pricingRequests';
import { usePermission } from '@/hooks/usePermission';
import { Button } from '@/components/ui/Button';
import { Textarea } from '@/components/ui/Textarea';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { Chip, type ChipVariant } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatPeso, formatQuantity } from '@/lib/formatNumber';
import { formatDate } from '@/lib/formatDate';
import type { PriceRequest } from '@/types/pricing';
import { PricingNavigation } from './PricingNavigation';

const statuses: Record<PriceRequest['status'], { label: string; variant: ChipVariant }> = {
  requested: { label: 'Awaiting sales proposal', variant: 'info' },
  pending_finance: { label: 'Awaiting Finance', variant: 'warning' },
  offered: { label: 'Awaiting customer', variant: 'info' },
  accepted: { label: 'Accepted', variant: 'success' },
  rejected: { label: 'Rejected by customer', variant: 'danger' },
  withdrawn: { label: 'Withdrawn', variant: 'neutral' },
  expired: { label: 'Expired', variant: 'neutral' },
};

export default function PricingRequestsPage() {
  const { can } = usePermission();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<PriceRequest | null>(null);
  const [mode, setMode] = useState<'view' | 'review' | 'withdraw'>('view');
  const [notes, setNotes] = useState('');
  const [error, setError] = useState('');
  const costReview = useQuery({
    queryKey: ['pricing-request-review', selected?.id],
    queryFn: () => pricingRequestsApi.show(selected!.id),
    enabled: !!selected && mode === 'review' && can('crm.price_agreements.approve'),
    staleTime: 0,
  });
  const query = useQuery({
    queryKey: ['pricing-requests', page],
    queryFn: () => pricingRequestsApi.list(page),
    placeholderData: (prev) => prev,
  });
  const action = useMutation({
    mutationFn: async (decision: 'approve' | 'withdraw') => {
      if (decision === 'approve')
        await pricingRequestsApi.approve(
          selected!.id,
          notes,
          costReview.data!.version,
          costReview.data!.cost_review!.signature,
        );
      else await pricingRequestsApi.withdraw(selected!.id, notes);
    },
    onSuccess: (_, decision) => {
      qc.invalidateQueries({ queryKey: ['pricing-requests'] });
      toast.success(
        decision === 'approve'
          ? 'Proposal sent for customer acceptance.'
          : 'Pricing request withdrawn.',
      );
      setSelected(null);
      setNotes('');
    },
    onError: (e: unknown) => {
      qc.invalidateQueries({ queryKey: ['pricing-request-review', selected?.id] });
      qc.invalidateQueries({ queryKey: ['pricing-requests'] });
      setError(
        (e as { response?: { data?: { message?: string } } }).response?.data?.message ??
          'Could not update pricing request. Your notes have been kept. Please try again.',
      );
    },
  });
  const open = (request: PriceRequest, nextMode: typeof mode) => {
    setSelected(request);
    setMode(nextMode);
    setNotes('');
    setError('');
  };
  const columns: Column<PriceRequest>[] = [
    {
      key: 'product',
      header: 'Product',
      className: 'min-w-48',
      cell: (r) => (
        <div className="max-w-64">
          <span className="font-mono font-medium">{r.product.part_number}</span>
          <div className="text-muted">{r.product.name}</div>
        </div>
      ),
    },
    { key: 'customer', header: 'Customer', className: 'min-w-40', cell: (r) => r.customer.name },
    {
      key: 'request',
      header: 'Customer request',
      className: 'min-w-36',
      align: 'right',
      cell: (r) => (
        <NumCell>
          {r.requested_price ? (
            formatPeso(r.requested_price)
          ) : (
            <span className="text-muted">No target price</span>
          )}
          <span className="block text-xs text-muted">
            {r.quantity
              ? `${formatQuantity(r.quantity)} ${r.product.unit_of_measure}`
              : 'Company proposal'}
          </span>
        </NumCell>
      ),
    },
    {
      key: 'offer',
      header: 'Proposed unit price',
      className: 'min-w-40',
      align: 'right',
      cell: (r) =>
        r.offer ? (
          <NumCell>
            {formatPeso(r.offer.price)}
            <span className="block text-xs text-muted">
              {r.offer.pricing_method === 'tiered'
                ? `${r.offer.tiers?.length ?? 0} volume tiers · Fallback price`
                : 'Any quantity'}
            </span>
          </NumCell>
        ) : (
          <span className="text-muted">Awaiting proposal</span>
        ),
    },
    {
      key: 'validity',
      header: 'Validity',
      className: 'min-w-36',
      cell: (r) =>
        r.offer ? (
          <div className="text-xs">
            <div>{formatDate(r.offer.effective_from)}</div>
            <div className="text-muted">to {formatDate(r.offer.effective_to)}</div>
          </div>
        ) : (
          <span className="text-muted">—</span>
        ),
    },
    {
      key: 'status',
      header: 'Status',
      className: 'min-w-36',
      cell: (r) => <Chip variant={statuses[r.status].variant}>{statuses[r.status].label}</Chip>,
    },
    {
      key: 'actions',
      header: 'Actions',
      align: 'right',
      className: 'min-w-40',
      cell: (r) => (
        <div className="flex flex-wrap justify-end gap-1.5">
          {can('crm.price_agreements.propose') &&
            ['requested', 'pending_finance', 'offered'].includes(r.status) && (
              <Button
                size="sm"
                onClick={() => navigate(`/crm/price-agreements/requests/${r.id}/offer`)}
              >
                Propose terms
              </Button>
            )}
          {r.status === 'pending_finance' && can('crm.price_agreements.approve') ? (
            <Button size="sm" onClick={() => open(r, 'review')}>
              Review
            </Button>
          ) : (
            <Button variant="ghost" size="sm" onClick={() => open(r, 'view')}>
              Details
            </Button>
          )}
          {['requested', 'pending_finance', 'offered'].includes(r.status) &&
            (can('crm.price_agreements.propose') || can('crm.price_agreements.approve')) && (
              <Button
                variant="ghost"
                size="sm"
                className="text-danger-fg"
                onClick={() => open(r, 'withdraw')}
              >
                Withdraw
              </Button>
            )}
        </div>
      ),
    },
  ];
  const reviewed = mode === 'review' ? costReview.data : selected;
  const offer = reviewed?.offer;
  const basis = costReview.data?.cost_review;
  return (
    <div className="min-w-0 w-full">
      <PageHeader
        title="Pricing requests"
        subtitle={
          query.data
            ? `${query.data.meta.total} ${query.data.meta.total === 1 ? 'request' : 'requests'} · Prices exclude VAT`
            : 'Customer pricing proposals and approvals · Prices exclude VAT'
        }
        refreshingQueryKey={['pricing-requests']}
        actions={
          can('crm.price_agreements.propose') ? (
            <Button
              variant="primary"
              icon={<LuPlus size={14} />}
              onClick={() => navigate('/crm/price-agreements/create')}
            >
              Propose agreement
            </Button>
          ) : undefined
        }
      />
      <PricingNavigation current="requests" />
      <div className="px-5 py-4">
        {query.isLoading && <SkeletonTable rows={5} columns={7} />}
        {query.isError && (
          <EmptyState
            icon="alert-circle"
            title="Cannot load pricing requests"
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
                title="No pricing requests"
                description="Customer pricing requests appear here for sales proposals and Finance review."
              />
            }
          />
        )}
      </div>
      <Modal
        isOpen={!!selected}
        onClose={() => {
          if (!action.isPending) setSelected(null);
        }}
        closeOnOverlayClick={!action.isPending}
        title={
          mode === 'withdraw'
            ? 'Withdraw pricing request'
            : mode === 'review'
              ? 'Review pricing proposal'
              : 'Pricing request details'
        }
        size="lg"
      >
        {selected && (
          <div className="space-y-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
              <div className="min-w-0">
                <div className="text-sm font-medium">
                  <span className="font-mono">{selected.product.part_number}</span> ·{' '}
                  {selected.product.name}
                </div>
                <p className="text-sm text-muted">{selected.customer.name}</p>
              </div>
              <Chip variant={statuses[selected.status].variant}>
                {statuses[selected.status].label}
              </Chip>
            </div>
            <Panel title="Customer request">
              <dl className="grid grid-cols-2 gap-3 text-sm">
                <div>
                  <dt className="text-xs text-muted">Requested quantity</dt>
                  <dd className="mt-1 font-mono">
                    {selected.quantity
                      ? `${formatQuantity(selected.quantity)} ${selected.product.unit_of_measure}`
                      : 'Company proposal'}
                  </dd>
                </div>
                <div>
                  <dt className="text-xs text-muted">Target unit price · VAT excluded</dt>
                  <dd className="mt-1 font-mono">
                    {formatPeso(selected.requested_price, 'No target price')}
                  </dd>
                </div>
              </dl>
              {selected.notes && (
                <p className="mt-3 whitespace-pre-wrap break-words text-sm text-secondary">
                  {selected.notes}
                </p>
              )}
            </Panel>
            {mode === 'review' && costReview.isFetching && (
              <p role="status" className="text-sm text-muted">
                Refreshing proposal and cost basis…
              </p>
            )}
            {mode === 'review' && costReview.isError && (
              <div role="alert" className="space-y-2 text-sm text-danger-fg">
                <p>Cannot load the current proposal and cost basis.</p>
                <Button size="sm" onClick={() => costReview.refetch()}>
                  Retry cost review
                </Button>
              </div>
            )}
            {offer && (
              <Panel title="Proposed terms" meta={`Version ${reviewed?.version} · VAT excluded`}>
                <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                  <div>
                    <dt className="text-xs text-muted">
                      {offer.pricing_method === 'tiered'
                        ? 'Fallback unit price'
                        : 'Unit price · Any quantity'}
                    </dt>
                    <dd className="mt-1 font-mono font-medium">{formatPeso(offer.price)}</dd>
                  </div>
                  <div>
                    <dt className="text-xs text-muted">Valid from / until</dt>
                    <dd className="mt-1">
                      {formatDate(offer.effective_from)} to {formatDate(offer.effective_to)}
                    </dd>
                  </div>
                </dl>
                {!!offer.tiers?.length && (
                  <div className="mt-3 overflow-x-auto">
                    <table className="w-full text-sm">
                      <thead className="border-b border-default text-xs text-muted">
                        <tr>
                          <th className="py-2 text-left font-medium">Minimum order quantity</th>
                          <th className="py-2 text-right font-medium">Unit price</th>
                        </tr>
                      </thead>
                      <tbody>
                        {offer.tiers.map((tier) => (
                          <tr key={tier.min_qty} className="border-b border-default last:border-0">
                            <td className="py-2 font-mono">
                              {formatQuantity(tier.min_qty)}+ {selected.product.unit_of_measure}
                            </td>
                            <td className="py-2 text-right font-mono">
                              {formatPeso(tier.unit_price)}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                    <p className="mt-2 text-xs text-muted">
                      Highest eligible tier applies per product, per order.
                    </p>
                  </div>
                )}
                {offer.notes && (
                  <p className="mt-3 whitespace-pre-wrap break-words text-sm text-secondary">
                    {offer.notes}
                  </p>
                )}
              </Panel>
            )}
            {mode === 'review' && basis && (
              <Panel title="Cost basis">
                <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                  <div>
                    <dt className="text-xs text-muted">Estimated unit cost</dt>
                    <dd className="mt-1 font-mono">{formatPeso(basis.cost)}</dd>
                  </div>
                  <div>
                    <dt className="text-xs text-muted">Minimum selling price</dt>
                    <dd className="mt-1 font-mono">
                      {formatPeso(basis.minimum_price, 'Not configured')}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-xs text-muted">Base selling price</dt>
                    <dd className="mt-1 font-mono">
                      {formatPeso(basis.base_price, 'Not configured')}
                    </dd>
                  </div>
                </dl>
                {basis.incomplete && (
                  <p role="status" className="mt-3 text-sm text-warning-fg">
                    Cost incomplete. Approval blocked until production costing is configured.
                  </p>
                )}
              </Panel>
            )}
            {selected.decision_reason && (
              <p className="whitespace-pre-wrap break-words text-sm text-muted">
                Decision reason: {selected.decision_reason}
              </p>
            )}
            {mode !== 'view' && (
              <>
                <p className="text-sm text-muted">
                  {mode === 'withdraw'
                    ? 'Withdraw this request from negotiation. A reason is required.'
                    : 'Approval sends these terms to the customer. Prices change only after customer acceptance.'}
                </p>
                <Textarea
                  label={mode === 'withdraw' ? 'Withdrawal reason' : 'Review notes'}
                  required
                  rows={3}
                  value={notes}
                  disabled={action.isPending}
                  onChange={(e) => setNotes(e.target.value)}
                  maxLength={1000}
                />
              </>
            )}
            {error && (
              <p role="alert" className="text-sm text-danger-fg">
                {error}
              </p>
            )}
            <ModalFooter className="flex-wrap">
              <Button disabled={action.isPending} onClick={() => setSelected(null)}>
                {mode === 'view' ? 'Close' : 'Cancel'}
              </Button>
              {mode === 'withdraw' && (
                <Button
                  variant="danger"
                  loading={action.isPending}
                  disabled={!notes.trim()}
                  onClick={() => {
                    setError('');
                    action.mutate('withdraw');
                  }}
                >
                  Withdraw request
                </Button>
              )}
              {mode === 'review' && can('crm.price_agreements.approve') && (
                <Button
                  variant="primary"
                  loading={action.isPending}
                  disabled={
                    !notes.trim() ||
                    costReview.isFetching ||
                    costReview.isError ||
                    costReview.data?.status !== 'pending_finance' ||
                    !basis ||
                    basis.incomplete
                  }
                  onClick={() => {
                    setError('');
                    action.mutate('approve');
                  }}
                >
                  Approve for customer review
                </Button>
              )}
            </ModalFooter>
          </div>
        )}
      </Modal>
    </div>
  );
}
