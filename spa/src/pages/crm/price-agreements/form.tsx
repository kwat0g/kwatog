import { useEffect, useState } from 'react';
import { useFieldArray, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import toast from 'react-hot-toast';
import { LuPlus, LuTrash2 } from '@/lib/icons';
import { onFormInvalid, applyServerValidationErrors } from '@/lib/formErrors';
import { Button } from '@/components/ui/Button';
import { Textarea } from '@/components/ui/Textarea';
import { pricingRequestsApi } from '@/api/crm/pricingRequests';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { productsApi } from '@/api/crm/products';
import { crmCustomersApi } from '@/api/crm/customers';
import type { PriceAgreement, CreatePriceAgreementData } from '@/types/crm';

import { useFormSafety } from '@/hooks/useFormSafety';
import { FormDraftBanner } from '@/components/ui/FormDraftBanner';
import { formatPeso } from '@/lib/formatNumber';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

const amount = z
  .string()
  .regex(/^\d+(\.\d{1,2})?$/, 'Use an amount with up to 2 decimals')
  .refine((value) => Number(value) > 0, 'Selling price must be positive');
const tierSchema = z.object({
  min_qty: z.string().regex(/^[1-9]\d*$/, 'Use a positive whole number'),
  unit_price: amount,
});

const schema = z
  .object({
    product_id: z.string().min(1, 'Select a product'),
    customer_id: z.string().min(1, 'Select a customer'),
    price: amount,
    effective_from: z.string().min(1, 'Effective from date is required'),
    effective_to: z.string().min(1, 'Expiry date is required'),
    pricing_method: z.enum(['flat', 'tiered']),
    tiers: z.array(tierSchema).optional(),
    notes: z.string().min(1, 'Explain proposed terms').max(1000),
  })
  .superRefine((values, ctx) => {
    const tiers = values.tiers ?? [];
    if (values.pricing_method === 'tiered' && tiers.length === 0) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['tiers'],
        message: 'Add at least one price tier',
      });
    }
    if (values.pricing_method === 'flat' && tiers.length > 0) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['tiers'],
        message: 'Remove tiers for flat pricing',
      });
    }
    let previous = 0;
    tiers.forEach((tier, index) => {
      const minQty = Number(tier.min_qty);
      if (minQty <= previous) {
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          path: ['tiers', index, 'min_qty'],
          message: 'Minimum quantities must be strictly ascending and unique',
        });
      }
      previous = minQty;
    });
    if (
      values.effective_from &&
      values.effective_to &&
      values.effective_to < values.effective_from
    ) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['effective_to'],
        message: 'Expiry date must be on or after the effective date',
      });
    }
  });

type FormValues = z.infer<typeof schema>;

interface Props {
  initial?: Partial<PriceAgreement>;
  negotiationId?: string;
  mode: 'create' | 'edit';
}

export function PriceAgreementForm({ initial, negotiationId }: Props) {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const [productSearch, setProductSearch] = useState('');
  const [customerSearch, setCustomerSearch] = useState('');

  const {
    data: productsData,
    isLoading: productsLoading,
    isError: productsError,
    refetch: retryProducts,
  } = useQuery({
    queryKey: ['crm', 'products', 'price-agreement-lookup', productSearch],
    queryFn: () =>
      productsApi.list({ per_page: 100, is_active: true, search: productSearch || undefined }),
  });

  const {
    data: customersData,
    isLoading: customersLoading,
    isError: customersError,
    refetch: retryCustomers,
  } = useQuery({
    queryKey: ['crm', 'customers', 'price-agreement-lookup', customerSearch],
    queryFn: () =>
      crmCustomersApi.list({ per_page: 100, is_active: true, search: customerSearch || undefined }),
  });

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    shouldUnregister: true,
    defaultValues: {
      product_id: initial?.product?.id ?? '',
      customer_id: initial?.customer?.id ?? '',
      price: initial?.price ?? '',
      notes: '',
      effective_from: initial?.effective_from ?? '',
      effective_to: initial?.effective_to ?? '',
      pricing_method: initial?.pricing_method ?? 'flat',
      tiers:
        initial?.tiers?.map((tier) => ({
          min_qty: String(tier.min_qty),
          unit_price: tier.unit_price,
        })) ?? [],
    },
  });
  const {
    register,
    control,
    handleSubmit,
    setError,
    watch,
    formState: { errors, isSubmitting },
  } = form;
  const { fields, append, remove } = useFieldArray({ control, name: 'tiers' });
  const pricingMethod = watch('pricing_method');
  const productId = watch('product_id');
  const customerId = watch('customer_id');
  const price = watch('price');
  const effectiveFrom = watch('effective_from');
  const effectiveTo = watch('effective_to');
  const tiers = watch('tiers') ?? [];
  const { data: selectedProduct, isError: selectedProductError } = useQuery({
    queryKey: ['crm', 'products', 'detail', productId],
    queryFn: () => productsApi.show(productId),
    enabled: !!productId,
  });

  useEffect(() => {
    if (pricingMethod === 'flat' && fields.length > 0) {
      remove();
    }
  }, [fields.length, pricingMethod, remove]);

  const products = productsData?.data ?? [];
  const customers = customersData?.data ?? [];
  const productAlreadyListed = products.some((product) => product.id === productId);
  const { data: selectedCustomerDetails } = useQuery({
    queryKey: ['crm', 'customers', 'detail', customerId],
    queryFn: () => crmCustomersApi.show(customerId),
    enabled: !!customerId,
  });
  const selectedCustomer =
    selectedCustomerDetails ??
    customers.find((customer) => customer.id === customerId) ??
    (initial?.customer?.id === customerId ? initial.customer : null);

  const mutation = useMutation({
    mutationFn: (values: FormValues) => {
      const payload: CreatePriceAgreementData = {
        product_id: values.product_id,
        customer_id: values.customer_id,
        price: values.price,
        effective_from: values.effective_from,
        effective_to: values.effective_to,
        pricing_method: values.pricing_method,
        tiers:
          values.pricing_method === 'tiered'
            ? (values.tiers ?? []).map((tier) => ({
                min_qty: Number(tier.min_qty),
                unit_price: tier.unit_price,
              }))
            : null,
      };
      return negotiationId
        ? pricingRequestsApi.offer(negotiationId, {
            ...payload,
            notes: values.notes,
            pricing_method: values.pricing_method,
            tiers: payload.tiers ?? null,
          })
        : pricingRequestsApi.create({
            ...payload,
            notes: values.notes,
            pricing_method: values.pricing_method,
            tiers: payload.tiers ?? null,
          });
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['crm', 'price-agreements'] });
      qc.invalidateQueries({ queryKey: ['pricing-requests'] });
      toast.success(
        'Proposal submitted for Finance review. Customer terms stay unchanged until accepted.',
      );
      navigate('/crm/price-agreements');
    },
    onError: (e) => {
      applyServerValidationErrors(e, setError, 'Failed to save price agreement.');
    },
  });
  const safety = useFormSafety({ form, saved: mutation.isSuccess });

  return (
    <form
      onSubmit={handleSubmit((v) => {
        const floor = selectedProduct?.minimum_selling_price;
        if (floor && Number(v.price) < Number(floor)) {
          setError('price', { message: `Price must be at least ${formatPeso(floor)}.` });
          return;
        }
        const lowTier = v.tiers?.findIndex(
          (tier) => floor && Number(tier.unit_price) < Number(floor),
        );
        if (lowTier != null && lowTier >= 0) {
          setError(`tiers.${lowTier}.unit_price`, {
            message: `Price must be at least ${formatPeso(floor!)}.`,
          });
          return;
        }
        mutation.mutate(v);
      }, onFormInvalid<FormValues>())}
      className="min-w-0 w-full space-y-5 px-4 py-5 sm:px-5 lg:py-6 [color-scheme:light] dark:[color-scheme:dark]"
    >
      <FormDraftBanner safety={safety} inset={false} />
      <p className="text-sm text-muted">
        Finance reviews this proposal before the customer can accept it. Existing agreement prices
        remain unchanged.
      </p>
      <Textarea
        label="Proposal reason"
        required
        {...register('notes')}
        error={errors.notes?.message}
        helper="Explain the price and quantity terms to the customer."
      />
      <fieldset
        disabled={mutation.isPending}
        className="grid min-w-0 items-start gap-5 lg:grid-cols-[minmax(0,1fr)_300px]"
      >
        <div className="min-w-0 space-y-5">
          <section
            aria-labelledby="agreement-parties-heading"
            className="rounded-lg border border-default bg-canvas p-4 sm:p-5"
          >
            <h2 id="agreement-parties-heading" className="text-base font-semibold">
              Product and customer
            </h2>
            <p className="mt-1 text-sm text-muted">
              Set a customer-specific price for one product. Standard products can use their base
              price without an agreement.
            </p>
            <div className="mt-5 grid min-w-0 gap-5 sm:grid-cols-2">
              <div className="min-w-0 space-y-3">
                <Select
                  searchable
                  onSearch={(value) => setProductSearch(value)}
                  title="Part number or product name"
                  fieldSize="lg"
                  label="Product"
                  disabled={!!negotiationId}
                  required
                  {...register('product_id')}
                  value={productId}
                  error={errors.product_id?.message}
                >
                  <option value="">
                    {productsLoading ? 'Loading products…' : 'Select product'}
                  </option>
                  {productId && !productAlreadyListed && (
                    <option value={productId}>
                      {selectedProduct
                        ? `${selectedProduct.part_number} · ${selectedProduct.name}`
                        : (initial?.product?.name ?? 'Selected product')}
                    </option>
                  )}

                  {products.map((product) => (
                    <option key={product.id} value={product.id}>
                      {product.part_number} · {product.name}
                    </option>
                  ))}
                </Select>
                {productsError && (
                  <p role="alert" className="text-sm text-danger-fg">
                    Cannot load products.{' '}
                    <Button type="button" size="sm" onClick={() => retryProducts()}>
                      Retry
                    </Button>
                  </p>
                )}
                {!productsLoading && !productsError && productSearch && !products.length && (
                  <p role="status" className="text-xs text-muted">
                    No matching products.
                  </p>
                )}
              </div>
              <div className="min-w-0 space-y-3">
                <Select
                  searchable
                  onSearch={(value) => setCustomerSearch(value)}
                  title="Customer name or code"
                  fieldSize="lg"
                  label="Customer"
                  disabled={!!negotiationId}
                  required
                  {...register('customer_id')}
                  value={customerId}
                  error={errors.customer_id?.message}
                >
                  <option value="">
                    {customersLoading ? 'Loading customers…' : 'Select customer'}
                  </option>
                  {customerId && !customers.some((customer) => customer.id === customerId) && (
                    <option value={customerId}>
                      {selectedCustomer?.name ?? 'Selected customer'}
                    </option>
                  )}
                  {customers.map((customer) => (
                    <option key={customer.id} value={customer.id}>
                      {customer.code ? `${customer.code} · ` : ''}
                      {customer.name}
                    </option>
                  ))}
                </Select>
                {customersError && (
                  <p role="alert" className="text-sm text-danger-fg">
                    Cannot load customers.{' '}
                    <Button type="button" size="sm" onClick={() => retryCustomers()}>
                      Retry
                    </Button>
                  </p>
                )}
                {!customersLoading && !customersError && customerSearch && !customers.length && (
                  <p role="status" className="text-xs text-muted">
                    No matching customers.
                  </p>
                )}
              </div>
            </div>
          </section>
          <section
            aria-labelledby="agreement-pricing-heading"
            className="rounded-lg border border-default bg-canvas p-4 sm:p-5"
          >
            <h2 id="agreement-pricing-heading" className="text-base font-semibold">
              Price and validity
            </h2>
            <p className="mt-1 text-sm text-muted">
              Prices exclude VAT. Validity follows the order’s requested delivery date.
            </p>
            {selectedProduct && (
              <dl className="mt-4 grid grid-cols-2 gap-4 rounded-md bg-surface p-3 text-sm">
                <div>
                  <dt className="text-muted">Base selling price</dt>
                  <dd className="mt-1 font-mono font-medium tabular-nums">
                    {selectedProduct.base_selling_price
                      ? formatPeso(selectedProduct.base_selling_price)
                      : 'Not configured'}
                  </dd>
                </div>
                <div>
                  <dt className="text-muted">Minimum selling price</dt>
                  <dd className="mt-1 font-mono font-medium tabular-nums">
                    {selectedProduct.minimum_selling_price
                      ? formatPeso(selectedProduct.minimum_selling_price)
                      : 'Not configured'}
                  </dd>
                </div>
              </dl>
            )}
            {selectedProductError && (
              <p role="alert" className="mt-3 text-sm text-danger-fg">
                Unable to load price limits. Server checks still apply when saving.
              </p>
            )}
            {selectedProduct && !selectedProduct.minimum_selling_price && (
              <p className="mt-2 text-xs text-muted">
                No minimum configured. Confirm profitability before creating this agreement.
              </p>
            )}
            <div className="mt-5 grid min-w-0 items-start gap-4 sm:grid-cols-2">
              <Select
                fieldSize="lg"
                label="Pricing method"
                required
                {...register('pricing_method')}
                error={errors.pricing_method?.message}
              >
                <option value="flat">Flat price — same price at every quantity</option>
                <option value="tiered">Volume tiers — price changes with quantity</option>
              </Select>
              <Input
                fieldSize="lg"
                label={pricingMethod === 'tiered' ? 'Fallback price' : 'Agreed price'}
                required
                prefix="₱"
                inputMode="decimal"
                {...register('price')}
                error={errors.price?.message}
                placeholder="0.00"
                className="font-mono tabular-nums"
                helper={
                  pricingMethod === 'tiered'
                    ? 'Applies below the first quantity threshold.'
                    : `Per ${selectedProduct?.unit_of_measure || 'unit'}, before VAT.`
                }
              />
              <Input
                fieldSize="lg"
                label="Effective from"
                required
                type="date"
                {...register('effective_from')}
                error={errors.effective_from?.message}
              />
              <Input
                fieldSize="lg"
                label="Effective to"
                required
                type="date"
                min={effectiveFrom || undefined}
                {...register('effective_to')}
                error={errors.effective_to?.message}
                helper="Both dates are included."
              />
            </div>
          </section>
          {pricingMethod === 'tiered' && (
            <section
              aria-labelledby="volume-tiers-heading"
              className="overflow-hidden rounded-lg border border-default bg-canvas"
            >
              <div className="flex items-center justify-between gap-3 p-4 sm:px-5">
                <div>
                  <h2 id="volume-tiers-heading" className="text-base font-semibold">
                    Volume tiers
                  </h2>
                  <p className="mt-1 text-xs text-muted">
                    Lowest to highest quantity. Every tier must meet the minimum price.
                  </p>
                </div>
                <Button
                  type="button"
                  className="min-h-11 shrink-0"
                  icon={<LuPlus size={16} />}
                  onClick={() => append({ min_qty: '', unit_price: '' })}
                >
                  Add tier
                </Button>
              </div>
              <div
                role="region"
                aria-label="Volume tier prices"
                tabIndex={0}
                className="max-h-72 overflow-auto"
              >
                <table className={`${tableCls} min-w-[380px]`} aria-label="Volume tiers">
                  <thead className="sticky top-0 bg-surface">
                    <tr className={theadTrCls}>
                      <Th>Minimum quantity</Th>
                      <Th>Unit price</Th>
                      <Th>
                        <span className="sr-only">Remove</span>
                      </Th>
                    </tr>
                  </thead>
                  <tbody>
                    {fields.map((field, index) => (
                      <tr key={field.id} className={trCls}>
                        <Td>
                          <Input
                            fieldSize="lg"
                            aria-label={`Minimum quantity for tier ${index + 1}`}
                            type="number"
                            min={1}
                            step={1}
                            {...register(`tiers.${index}.min_qty`)}
                            error={errors.tiers?.[index]?.min_qty?.message}
                            placeholder="100"
                            className="font-mono tabular-nums"
                          />
                        </Td>
                        <Td>
                          <Input
                            fieldSize="lg"
                            aria-label={`Unit price for tier ${index + 1}`}
                            prefix="₱"
                            inputMode="decimal"
                            {...register(`tiers.${index}.unit_price`)}
                            error={errors.tiers?.[index]?.unit_price?.message}
                            placeholder="0.00"
                            className="font-mono tabular-nums"
                          />
                        </Td>
                        <Td>
                          <Button
                            type="button"
                            variant="ghost"
                            iconOnly
                            icon={<LuTrash2 size={16} />}
                            aria-label={`Remove tier ${index + 1}`}
                            onClick={() => remove(index)}
                            className="min-h-11 min-w-11 text-muted hover:text-danger-fg"
                          />
                        </Td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {!fields.length && (
                <p className="px-4 py-5 text-sm text-muted sm:px-5">
                  Add the first quantity threshold and its unit price.
                </p>
              )}
              {errors.tiers?.message && (
                <p role="alert" className="px-4 pb-3 text-xs text-danger-fg">
                  {errors.tiers.message}
                </p>
              )}
            </section>
          )}
        </div>
        <aside
          aria-labelledby="agreement-summary-heading"
          className="min-w-0 rounded-lg border border-default bg-surface p-4 sm:p-5 lg:sticky lg:top-16"
        >
          <h2 id="agreement-summary-heading" className="text-base font-semibold">
            Agreement summary
          </h2>
          <dl className="mt-5 space-y-4 text-sm">
            <div>
              <dt className="text-muted">Customer</dt>
              <dd className="mt-1 font-medium break-words">
                {selectedCustomer?.name ?? 'Select a customer'}
              </dd>
            </div>
            <div>
              <dt className="text-muted">Product</dt>
              <dd className="mt-1 font-medium break-words">
                {selectedProduct?.name ?? 'Select a product'}
              </dd>
              {selectedProduct && (
                <p className="mt-1 font-mono text-xs text-muted">{selectedProduct.part_number}</p>
              )}
            </div>
            <div className="border-t border-default pt-4">
              <dt className="text-muted">
                {pricingMethod === 'tiered' ? 'Fallback unit price' : 'Agreed unit price'}
              </dt>
              <dd className="mt-1 font-mono text-2xl font-semibold tabular-nums">
                {price && Number.isFinite(Number(price)) ? formatPeso(price) : '—'}
              </dd>
              <p className="mt-1 text-xs text-muted">
                VAT excluded
                {pricingMethod === 'tiered'
                  ? ` · ${tiers.length} volume tier${tiers.length === 1 ? '' : 's'}`
                  : ''}
              </p>
            </div>
            <div>
              <dt className="text-muted">Valid delivery dates</dt>
              <dd className="mt-1 font-mono text-xs">
                {effectiveFrom || 'Start date'} to {effectiveTo || 'End date'}
              </dd>
            </div>
          </dl>
          <p className="mt-5 border-t border-default pt-4 text-xs leading-relaxed text-muted">
            This agreement overrides the base price during its validity. Creating an agreement also
            assigns this product to the customer. After expiry, the base price applies if
            configured.
          </p>
          <div className="mt-5 flex flex-col gap-2">
            <Button
              type="submit"
              variant="primary"
              className="min-h-11 w-full"
              disabled={isSubmitting || mutation.isPending}
              loading={mutation.isPending}
            >
              {mutation.isPending ? 'Submitting…' : 'Submit for Finance review'}
            </Button>
            <Button
              type="button"
              variant="ghost"
              className="min-h-11 w-full"
              onClick={() => navigate('/crm/price-agreements')}
            >
              Cancel
            </Button>
          </div>
        </aside>
      </fieldset>
    </form>
  );
}
