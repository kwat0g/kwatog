import { useState } from 'react';
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
import { DataTable, type Column } from '@/components/ui/DataTable';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { PageHeader } from '@/components/layout/PageHeader';
import { applyServerValidationErrors, onFormInvalid } from '@/lib/formErrors';
import { formatPeso } from '@/lib/formatNumber';
import type { PriceRequest } from '@/types/pricing';

const schema = z.object({
  product_id: z.string().min(1, 'Select product'),
  quantity: z
    .string()
    .regex(/^[1-9]\d*$/, 'Enter a whole number')
    .refine((v) => Number(v) <= 2147483647, 'Quantity too large'),
  requested_price: z
    .string()
    .optional()
    .refine(
      (v) => !v || (/^\d+(\.\d{1,2})?$/.test(v) && Number(v) > 0),
      'Use a positive price with up to two decimals',
    ),
  notes: z.string().min(1, 'Explain your request').max(1000),
});
type Values = z.infer<typeof schema>;

export default function CustomerPricingPage() {
  const qc = useQueryClient();
  const [page, setPage] = useState(1);
  const [creating, setCreating] = useState(false);
  const query = useQuery({
    queryKey: ['portal', 'pricing', page],
    queryFn: () => customerPortalApi.priceRequests(page),
    placeholderData: (p) => p,
  });
  const catalog = useQuery({
    queryKey: ['portal', 'catalog', 'pricing'],
    queryFn: () => customerPortalApi.listCatalog(),
    enabled: creating,
  });
  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { product_id: '', quantity: '', requested_price: '', notes: '' },
  });
  const submit = useMutation({
    mutationFn: (v: Values) =>
      customerPortalApi.requestPrice({
        ...v,
        quantity: Number(v.quantity),
        requested_price: v.requested_price || undefined,
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['portal', 'pricing'] });
      toast.success('Request submitted to sales.');
      form.reset();
      setCreating(false);
    },
    onError: (e) => applyServerValidationErrors(e, form.setError, 'Cannot submit pricing request.'),
  });
  const decision = useMutation({
    mutationFn: ({ row, decision }: { row: PriceRequest; decision: 'accept' | 'reject' }) =>
      customerPortalApi.respondPrice(row.id, decision, row.version),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['portal'] });
      toast.success('Decision recorded.');
    },
    onError: (e: unknown) =>
      toast.error(
        (e as { response?: { data?: { message?: string } } }).response?.data?.message ??
          'Cannot record decision.',
      ),
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
              r.status === 'accepted' ? 'success' : r.status === 'offered' ? 'info' : 'neutral'
            }
          >
            {r.status === 'pending_finance' ? 'Under review' : r.status.replaceAll('_', ' ')}
          </Chip>
          {r.decision_reason && <p className="mt-2 text-sm text-muted">{r.decision_reason}</p>}
        </div>
      ),
    },
    {
      key: 'actions',
      header: 'Your decision',
      className: 'min-w-40',
      cell: (r) =>
        r.status === 'offered' ? (
          <div className="flex gap-2">
            <Button
              variant="primary"
              disabled={decision.isPending}
              onClick={() => decision.mutate({ row: r, decision: 'accept' })}
            >
              Accept terms
            </Button>
            <Button
              variant="danger"
              disabled={decision.isPending}
              onClick={() => decision.mutate({ row: r, decision: 'reject' })}
            >
              Decline
            </Button>
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
            className="space-y-4 rounded-lg border border-default p-4"
            onSubmit={form.handleSubmit((v) => submit.mutate(v), onFormInvalid<Values>())}
          >
            <h2 className="font-semibold">Request a volume discount</h2>
            <p className="text-sm text-muted">
              Regular orders use listed prices. Sales may propose a minimum quantity and revised
              price.
            </p>
            <fieldset disabled={submit.isPending} className="grid gap-4 sm:grid-cols-2">
              <Select
                searchable
                label="Product"
                required
                {...form.register('product_id')}
                error={form.formState.errors.product_id?.message}
              >
                <option value="">Select product</option>
                {catalog.data?.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.part_number} · {p.name}
                  </option>
                ))}
              </Select>
              <Input
                label="Quantity per order"
                required
                inputMode="numeric"
                {...form.register('quantity')}
                error={form.formState.errors.quantity?.message}
              />
              <Input
                label="Requested unit price (optional)"
                inputMode="decimal"
                {...form.register('requested_price')}
                error={form.formState.errors.requested_price?.message}
                helper="VAT excluded"
              />
              <Textarea
                label="Reason / planned order volume"
                required
                {...form.register('notes')}
                error={form.formState.errors.notes?.message}
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
              disabled={submit.isPending}
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
    </div>
  );
}
