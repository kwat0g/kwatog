import { useEffect, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { customerPortalApi } from '@/api/b2b/customer';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import { Select } from '@/components/ui/Select';
import { Chip } from '@/components/ui/Chip';
import { Checkbox } from '@/components/ui/Checkbox';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { PageHeader } from '@/components/layout/PageHeader';
import { applyServerValidationErrors, onFormInvalid } from '@/lib/formErrors';
import { formatPeso } from '@/lib/formatNumber';
import { invalidateRealtimeChange } from '@/lib/realtime/invalidationMap';
import type { PortalCatalogItem } from '@/types/b2b';
import type { PriceRequest } from '@/types/pricing';
import type { PaginatedResponse } from '@/types';

const schema = z.object({
  product_id: z.string().min(1, 'Select product'),
  quantity: z
    .string()
    .regex(/^[1-9]\d*$/, 'Enter a whole number')
    .refine((v) => Number(v) <= 2147483647, 'Quantity too large'),
  requested_price: z
    .string()
    .min(1, 'Enter a target unit price.')
    .regex(/^\d+(\.\d{1,2})?$/, 'Use a price with up to two decimals.')
    .refine((v) => Number(v) > 0, 'Target unit price must be greater than zero.'),
  notes: z.string().min(1, 'Explain your request').max(1000),
  request_order_limit_increase: z.boolean(),
});
type Values = z.infer<typeof schema>;

function quantityLimitError(
  product: PortalCatalogItem | undefined,
  quantity: string,
  requestHigherLimit = false,
): string | undefined {
  if (!product || !/^[1-9]\d*$/.test(quantity)) return undefined;

  const requested = Number(quantity);
  const minimum = Number(product.minimum_order_quantity ?? '1');
  if (requested < minimum) {
    return `Minimum order quantity is ${minimum} ${product.unit_of_measure}.`;
  }

  const maximum =
    product.maximum_order_quantity === null || product.maximum_order_quantity === undefined
      ? null
      : Number(product.maximum_order_quantity);
  if (maximum !== null && requested > maximum && !requestHigherLimit) {
    return `Maximum order quantity is ${maximum} ${product.unit_of_measure}. Ask Sales to review a higher per-order limit to continue.`;
  }

  return undefined;
}

function quantityExceedsMaximum(product: PortalCatalogItem | undefined, quantity: string): boolean {
  if (!product || !/^[1-9]\d*$/.test(quantity) || product.maximum_order_quantity == null) return false;
  return Number(quantity) > Number(product.maximum_order_quantity);
}

function formatOrderLimit(quantity: string): string {
  return new Intl.NumberFormat('en-PH', { maximumFractionDigits: 3 }).format(Number(quantity));
}

function quantityLimitHint(product: PortalCatalogItem | undefined): string {
  if (!product) return 'For one future order. Sales uses this volume to review your target price.';

  const minimum = formatOrderLimit(product.minimum_order_quantity ?? '1');
  if (product.maximum_order_quantity === null || product.maximum_order_quantity === undefined) {
    return `Minimum ${minimum} ${product.unit_of_measure} per order; no maximum is set. Sales confirms any qualifying volume in the offer.`;
  }

  const maximum = formatOrderLimit(product.maximum_order_quantity);
  return `Allowed per-order range: ${minimum}–${maximum} ${product.unit_of_measure}. Above the cap, request Sales review below; the final offer states qualifying quantities.`;
}

export default function CustomerPricingPage() {
  const [searchParams] = useSearchParams();
  const requestId = searchParams.get('request') ?? undefined;
  const qc = useQueryClient();
  const [page, setPage] = useState(1);
  const [creating, setCreating] = useState(false);
  const [canceling, setCanceling] = useState<PriceRequest | null>(null);
  const query = useQuery({
    queryKey: ['portal', 'pricing', page, requestId],
    queryFn: () => customerPortalApi.priceRequests(requestId ? 1 : page, requestId),
    placeholderData: (previous, previousQuery) =>
      requestId === undefined && previousQuery?.queryKey[3] === undefined ? previous : undefined,
  });
  const catalog = useQuery({
    queryKey: ['portal', 'catalog', 'pricing'],
    queryFn: () => customerPortalApi.listCatalog(),
    enabled: creating,
  });
  const openRequests = useQuery({
    queryKey: ['portal', 'pricing', 'open'],
    queryFn: () => customerPortalApi.openPriceRequests(),
    enabled: creating,
  });
  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { product_id: '', quantity: '', requested_price: '', notes: '', request_order_limit_increase: false },
  });
  const selectedProductId = form.watch('product_id');
  const limitReviewProductId = useRef(selectedProductId);
  const quantityValue = form.watch('quantity');
  const selectedProduct = catalog.data?.find((product) => product.id === selectedProductId);
  const requestHigherLimit = form.watch('request_order_limit_increase');
  const liveQuantityError = quantityLimitError(selectedProduct, quantityValue, requestHigherLimit);
  const needsLimitReview = quantityExceedsMaximum(selectedProduct, quantityValue);
  const existingOpenRequest = openRequests.data?.find((request) => request.product_id === selectedProductId);
  const exceedsAvailableStock = selectedProduct?.availability_mode === 'stock_limited'
    && selectedProduct.available_quantity != null
    && /^\d+$/.test(quantityValue)
    && Number(quantityValue) > Number(selectedProduct.available_quantity);
  useEffect(() => {
    if (limitReviewProductId.current !== selectedProductId || !needsLimitReview) {
      form.setValue('request_order_limit_increase', false);
    }
    limitReviewProductId.current = selectedProductId;
  }, [form, needsLimitReview, selectedProductId]);
  const submitRequest = (values: Values) => {
    const product = catalog.data?.find((item) => item.id === values.product_id);
    if (quantityLimitError(product, values.quantity, values.request_order_limit_increase)) {
      form.setFocus('quantity');
      return;
    }
    if (openRequests.isLoading || openRequests.isError) return;
    if (openRequests.data?.some((request) => request.product_id === values.product_id)) {
      form.setError('product_id', { type: 'manual', message: 'An open pricing request already exists for this product.' });
      return;
    }
    submit.mutate(values);
  };
  useEffect(() => {
    const productId = searchParams.get('product_id');
    const quantity = searchParams.get('quantity');
    if (!productId) return;
    setCreating(true);
    form.setValue('product_id', productId);
    form.setValue('quantity', quantity && /^\d+$/.test(quantity) && Number(quantity) > 0 ? quantity : '1');
    form.setValue('notes', 'Please confirm pricing and availability for this quantity beyond current stock.');
  }, [form, searchParams]);
  const submit = useMutation({
    mutationFn: (v: Values) =>
      customerPortalApi.requestPrice({
        ...v,
        quantity: Number(v.quantity),
        request_order_limit_increase: v.request_order_limit_increase,
      }),
    onSuccess: (created: PriceRequest) => {
      if (page === 1 && !requestId) {
        qc.setQueryData<PaginatedResponse<PriceRequest>>(['portal', 'pricing', page, requestId], (current) => current
          ? { ...current, data: [created, ...current.data.filter((row) => row.id !== created.id)] }
          : current);
      }
      void invalidateRealtimeChange(qc, { entityType: 'price_request', entityId: created.id });
      toast.success('Request submitted to sales.');
      void qc.invalidateQueries({ queryKey: ['portal', 'pricing', 'open'] });
      form.reset();
      setCreating(false);
    },
    onError: (e) => {
      void qc.invalidateQueries({ queryKey: ['portal', 'pricing', 'open'] });
      applyServerValidationErrors(e, form.setError, 'Cannot submit pricing request.');
    },
  });
  const decision = useMutation({
    mutationFn: ({ row, decision }: { row: PriceRequest; decision: 'accept' | 'reject' }) =>
      customerPortalApi.respondPrice(row.id, decision, row.version),
    onSuccess: (updated: PriceRequest) => {
      qc.setQueryData<PaginatedResponse<PriceRequest>>(['portal', 'pricing', page, requestId], (current) => current
        ? { ...current, data: current.data.map((row) => row.id === updated.id ? updated : row) }
        : current);
      qc.setQueryData(['portal', 'pricing', updated.id], updated);
      void invalidateRealtimeChange(
        qc,
        { entityType: 'price_request', entityId: updated.id },
        [],
        [['portal', 'pricing', page, requestId], ['portal', 'pricing', updated.id]],
      );
      toast.success('Decision recorded.');
      void qc.invalidateQueries({ queryKey: ['portal', 'pricing', 'open'] });
    },
    onError: (e: unknown) => {
      void qc.invalidateQueries({ queryKey: ['portal', 'pricing'], refetchType: 'active' });
      toast.error(
        (e as { response?: { data?: { message?: string } } }).response?.data?.message ??
          'Cannot record decision.',
      );
    },
  });
  const cancelRequest = useMutation({
    mutationFn: (row: PriceRequest) => customerPortalApi.cancelPriceRequest(row.id, row.version),
    onSuccess: (updated: PriceRequest) => {
      qc.setQueryData<PaginatedResponse<PriceRequest>>(['portal', 'pricing', page, requestId], (current) => current
        ? { ...current, data: current.data.map((row) => row.id === updated.id ? updated : row) }
        : current);
      qc.setQueryData(['portal', 'pricing', updated.id], updated);
      void invalidateRealtimeChange(
        qc,
        { entityType: 'price_request', entityId: updated.id },
        [],
        [['portal', 'pricing', page, requestId], ['portal', 'pricing', updated.id]],
      );
      void qc.invalidateQueries({ queryKey: ['portal', 'pricing', 'open'] });
      toast.success('Pricing request cancelled.');
      setCanceling(null);
    },
    onError: (e: unknown) => {
      void qc.invalidateQueries({ queryKey: ['portal', 'pricing'], refetchType: 'active' });
      toast.error(
        (e as { response?: { data?: { message?: string } } }).response?.data?.message ??
          'Cannot cancel this pricing request. Refresh and try again.',
      );
    },
  });
  const columns: Column<PriceRequest>[] = [
    {
      key: 'product',
      header: 'Product',
      className: 'min-w-44',
      cell: (r) => (
        <>
          <span className="font-mono">{r.product.part_number}</span>
          <div>{r.product.name}</div>
        </>
      ),
    },
    {
      key: 'request',
      header: 'Your request',
      className: 'min-w-40',
      cell: (r) => (
        <>
          <div>
            {r.quantity ? `${r.quantity} ${r.product.unit_of_measure}` : 'Company proposal'}
          </div>
          {r.requested_price && <div>{formatPeso(r.requested_price)}</div>}
          {r.order_limit_increase_request && (
            <p className="mt-1 text-xs text-warning-fg">
              Higher order limit review requested. Current limit: {formatOrderLimit(r.order_limit_increase_request.current_maximum_quantity)}; account limit remains unchanged until Sales updates it.
            </p>
          )}
          <p className="text-muted">{r.notes}</p>
        </>
      ),
    },
    {
      key: 'offer',
      header: 'Offered terms · VAT excluded',
      className: 'min-w-60',
      cell: (r) =>
        r.offer ? (
          <>
            <div>
              {r.offer.pricing_method === 'tiered' ? 'Below first tier' : 'Every quantity'}:{' '}
              {formatPeso(r.offer.price)}
            </div>
            {r.offer.tiers?.map((t) => (
              <div key={t.min_qty} className="font-mono">
                {t.min_qty}+ {r.product.unit_of_measure}: {formatPeso(t.unit_price)}
              </div>
            ))}
            <div>
              {r.offer.effective_from} to {r.offer.effective_to}
            </div>
            <p className="text-muted">{r.offer.notes}</p>
            <p className="text-xs text-muted">Quantity applies per product, per order.</p>
          </>
        ) : (
          'Sales and Finance are reviewing your request.'
        ),
    },
    {
      key: 'status',
      header: 'Status',
      cell: (r) => (
        <div>
          <Chip
            variant={
              r.status === 'accepted'
                ? 'success'
                : r.status === 'offered'
                  ? 'info'
                  : r.status === 'sales_rejected' || r.status === 'rejected'
                    ? 'danger'
                    : 'neutral'
            }
          >
            {{
              requested: 'Awaiting Sales',
              pending_finance: 'Under review',
              offered: 'Awaiting your response',
              accepted: 'Accepted',
              rejected: 'Declined by you',
              sales_rejected: 'Declined by Sales',
              customer_cancelled: 'Cancelled by you',
              withdrawn: 'Withdrawn by Sales',
              expired: 'Expired',
            }[r.status]}
          </Chip>
          {r.decision_reason && <p className="mt-2 text-sm text-muted">{r.decision_reason}</p>}
        </div>
      ),
    },
    {
      key: 'actions',
      header: 'Actions',
      className: 'min-w-40',
      cell: (r) => (r.available_actions?.can_accept || r.available_actions?.can_reject || r.available_actions?.can_cancel) ? (
          <div className="flex flex-wrap gap-2">
            {r.available_actions?.can_accept && (
              <Button variant="primary" disabled={decision.isPending} onClick={() => decision.mutate({ row: r, decision: 'accept' })}>
                Accept terms
              </Button>
            )}
            {r.available_actions?.can_reject && (
              <Button variant="danger" disabled={decision.isPending} onClick={() => decision.mutate({ row: r, decision: 'reject' })}>
                Decline
              </Button>
            )}
            {r.available_actions?.can_cancel && (
              <Button variant="secondary" disabled={cancelRequest.isPending} onClick={() => setCanceling(r)}>
                Cancel request
              </Button>
            )}
          </div>
        ) : (
          '—'
        ),
    },
  ];
  return (
    <div>
      <PageHeader
        title="Pricing agreements"
        subtitle="Request volume pricing and review offers before accepting."
        actions={
          <Button variant="primary" onClick={() => setCreating(true)}>
            Request pricing
          </Button>
        }
      />
      <div className="space-y-4 p-5">
        {creating && (
          <form
            data-portal-guide-target="form"
            className="space-y-4 rounded-lg border border-default p-4"
            noValidate
            onSubmit={form.handleSubmit(submitRequest, onFormInvalid<Values>())}
          >
            <h2 className="font-semibold">Request volume pricing</h2>
            <p className="text-sm text-muted">
              Enter the unit price you want for this planned order quantity. It is a request, not a
              confirmed discount. Sales confirms the final price and whether it requires a minimum
              quantity. For example, a discount that applies only at 500 units needs a 500-unit tier
              in the offer. This request does not place an order or reserve stock.
            </p>
            {openRequests.isError && (
              <div role="alert" className="rounded-md border border-danger/30 bg-danger/5 p-3 text-sm">
                <p>Cannot check for existing requests. Retry before starting another request.</p>
                <Button type="button" size="sm" onClick={() => openRequests.refetch()}>Retry</Button>
              </div>
            )}
            {openRequests.data && openRequests.data.length > 0 && (
              <div className="rounded-md border border-default bg-elevated p-3 text-sm">
                <p className="font-medium">Requests already under review</p>
                <ul className="mt-1 space-y-1">
                  {openRequests.data.map((request) => {
                    const product = catalog.data?.find((item) => item.id === request.product_id);
                    return (
                      <li key={request.id} className="flex flex-wrap items-center gap-x-2">
                        <span>{product ? `${product.part_number} · ${product.name}` : 'Product request'}</span>
                        <Link className="text-accent underline underline-offset-2" to={`/portal/customer/pricing?request=${encodeURIComponent(request.id)}`}>
                          View open request
                        </Link>
                      </li>
                    );
                  })}
                </ul>
              </div>
            )}
            <fieldset disabled={submit.isPending || catalog.isLoading || catalog.isError || openRequests.isLoading || openRequests.isError} className="grid gap-4 sm:grid-cols-2">
              <Select
                searchable
                label="Product"
                required
                disabled={catalog.isLoading || catalog.isError || catalog.data?.length === 0}
                loading={catalog.isLoading}
                {...form.register('product_id')}
                error={form.formState.errors.product_id?.message}
                helper={catalog.isLoading
                  ? 'Loading products from your account…'
                  : catalog.data?.length === 0
                    ? 'No products are available under your account. Contact Sales.'
                    : undefined}
              >
                <option value="">Select product</option>
                {catalog.data?.map((p) => (
                  <option key={p.id} value={p.id} disabled={openRequests.data?.some((request) => request.product_id === p.id)}>
                    {p.part_number} · {p.name}{openRequests.data?.some((request) => request.product_id === p.id) ? ' · request already open' : ''}
                  </option>
                ))}
              </Select>
              {existingOpenRequest && (
                <p role="alert" className="text-sm text-danger-fg sm:col-span-2">
                  An open request already exists for this product.{' '}
                  <Link className="underline underline-offset-2" to={`/portal/customer/pricing?request=${encodeURIComponent(existingOpenRequest.id)}`}>
                    View open request
                  </Link>
                </p>
              )}
              <Input
                label="Quantity you plan to order"
                required
                type="number"
                min={selectedProduct?.minimum_order_quantity ?? 1}
                max={requestHigherLimit ? undefined : selectedProduct?.maximum_order_quantity ?? undefined}
                step={1}
                {...form.register('quantity')}
                error={liveQuantityError ?? form.formState.errors.quantity?.message}
                helper={quantityLimitHint(selectedProduct)}
              />
              {needsLimitReview && (
                <div className="rounded-md border border-warning/40 bg-warning/5 p-3 sm:col-span-2">
                  <Checkbox
                    {...form.register('request_order_limit_increase')}
                    label={<span><strong>Ask Sales to review a higher per-order limit</strong><span className="mt-0.5 block text-xs text-muted">This does not change your current limit. Sales must approve and update your account before an order above the current maximum can be placed.</span></span>}
                  />
                </div>
              )}
              {exceedsAvailableStock && selectedProduct?.available_quantity != null && (
                <p role="status" className="rounded-md border border-default bg-elevated p-3 text-sm text-muted sm:col-span-2">
                  Only {formatOrderLimit(selectedProduct.available_quantity)} {selectedProduct.unit_of_measure} are currently available. You can still request pricing for this quantity; Sales will confirm replenishment and delivery availability.
                </p>
              )}
              <Input
                label="Target unit price at this quantity"
                required
                type="number"
                min="0.01"
                step="0.01"
                inputMode="decimal"
                {...form.register('requested_price')}
                error={form.formState.errors.requested_price?.message}
                helper="Required · VAT excluded. Sales confirms the final price and any qualifying quantity threshold."
              />
              <Textarea
                label="Reason for request"
                required
                {...form.register('notes')}
                error={form.formState.errors.notes?.message}
                helper="Explain the business need or expected order schedule."
              />
            </fieldset>
            {catalog.isError && (
              <p role="alert">
                Cannot load products.{' '}
                <Button type="button" onClick={() => catalog.refetch()}>
                  Retry
                </Button>
              </p>
            )}
            <Button
              type="submit"
              variant="primary"
              disabled={submit.isPending || catalog.isLoading || catalog.isError || catalog.data?.length === 0 || openRequests.isLoading || openRequests.isError || !!existingOpenRequest}
              loading={submit.isPending}
            >
              Submit request
            </Button>{' '}
            <Button type="button" onClick={() => setCreating(false)}>
              Cancel
            </Button>
          </form>
        )}
        {query.isLoading && <SkeletonTable columns={5} rows={5} />}
        {query.isError && (
          <EmptyState
            icon="alert-circle"
            title="Cannot load pricing agreements"
            action={<Button onClick={() => query.refetch()}>Retry</Button>}
          />
        )}
        {query.data && (
          <DataTable
            columns={columns}
            data={query.data.data}
            meta={query.data.meta}
            onPageChange={setPage}
            emptyState={
              <EmptyState
                icon="dollar-sign"
                title="No pricing requests yet"
                description="Regular orders use listed prices. Request a discount when your order volume supports one."
              />
            }
          />
        )}
      </div>
      <ConfirmDialog
        isOpen={canceling !== null}
        onClose={() => {
          if (!cancelRequest.isPending) setCanceling(null);
        }}
        onConfirm={async () => {
          if (canceling) await cancelRequest.mutateAsync(canceling);
        }}
        title="Cancel this pricing request?"
        description="Sales will stop reviewing this request. You can submit a new one later."
        confirmLabel="Cancel request"
        cancelLabel="Keep request"
        variant="danger"
        pending={cancelRequest.isPending}
      />
    </div>
  );
}
