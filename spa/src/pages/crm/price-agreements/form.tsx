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
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { priceAgreementsApi } from '@/api/crm/priceAgreements';
import { productsApi } from '@/api/crm/products';
import { crmCustomersApi } from '@/api/crm/customers';
import type { PriceAgreement, CreatePriceAgreementData } from '@/types/crm';

import { useFormSafety } from '@/hooks/useFormSafety';
import { FormDraftBanner } from '@/components/ui/FormDraftBanner';
import { FormActions } from '@/components/ui/FormActions';

const amount = z.string().regex(/^\d+(\.\d{1,2})?$/, 'Use a non-negative amount with up to 2 decimals');
const tierSchema = z.object({
  min_qty: z.string().regex(/^[1-9]\d*$/, 'Use a positive whole number'),
  unit_price: amount,
});

const schema = z.object({
  product_id: z.string().min(1, 'Select a product'),
  customer_id: z.string().min(1, 'Select a customer'),
  price: amount,
  effective_from: z.string().min(1, 'Effective from date is required'),
  effective_to: z.string().min(1, 'Expiry date is required'),
  pricing_method: z.enum(['flat', 'tiered']),
  tiers: z.array(tierSchema).optional(),
}).superRefine((values, ctx) => {
  const tiers = values.tiers ?? [];
  if (values.pricing_method === 'tiered' && tiers.length === 0) {
    ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['tiers'], message: 'Add at least one price tier' });
  }
  if (values.pricing_method === 'flat' && tiers.length > 0) {
    ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['tiers'], message: 'Remove tiers for flat pricing' });
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
  if (values.effective_from && values.effective_to && values.effective_to < values.effective_from) {
    ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['effective_to'], message: 'Expiry date must be on or after the effective date' });
  }
});

type FormValues = z.infer<typeof schema>;

interface Props {
  initial?: PriceAgreement;
  mode: 'create' | 'edit';
}

export function PriceAgreementForm({ initial, mode }: Props) {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const [productSearch, setProductSearch] = useState('');
  const [customerSearch, setCustomerSearch] = useState('');

  const { data: productsData } = useQuery({
    queryKey: ['crm', 'products', 'price-agreement-lookup', productSearch],
    queryFn: () => productsApi.list({ per_page: 100, is_active: true, search: productSearch || undefined }),
  });

  const { data: customersData } = useQuery({
    queryKey: ['crm', 'customers', 'price-agreement-lookup', customerSearch],
    queryFn: () => crmCustomersApi.list({ per_page: 100, is_active: true, search: customerSearch || undefined }),
  });

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    shouldUnregister: true,
    defaultValues: {
      product_id: initial?.product?.id ?? '',
      customer_id: initial?.customer?.id ?? '',
      price: initial?.price ?? '',
      effective_from: initial?.effective_from ?? '',
      effective_to: initial?.effective_to ?? '',
      pricing_method: initial?.pricing_method ?? 'flat',
      tiers: initial?.tiers?.map((tier) => ({
        min_qty: String(tier.min_qty),
        unit_price: tier.unit_price,
      })) ?? [],
    },
  });
  const {
    register, control, handleSubmit, setError, watch,
    formState: { errors, isSubmitting },
  } = form;
  const { fields, append, remove } = useFieldArray({ control, name: 'tiers' });
  const pricingMethod = watch('pricing_method');

  useEffect(() => {
    if (pricingMethod === 'flat' && fields.length > 0) {
      remove();
    }
  }, [fields.length, pricingMethod, remove]);

  const products = productsData?.data ?? [];
  const customers = customersData?.data ?? [];
  const productAlreadyListed = products.some((product) => product.id === initial?.product?.id);
  const customerAlreadyListed = customers.some((customer) => customer.id === initial?.customer?.id);

  const mutation = useMutation({
    mutationFn: (values: FormValues) => {
      const payload: CreatePriceAgreementData = {
        product_id: values.product_id,
        customer_id: values.customer_id,
        price: values.price,
        effective_from: values.effective_from,
        effective_to: values.effective_to,
        pricing_method: values.pricing_method,
        tiers: values.pricing_method === 'tiered'
          ? (values.tiers ?? []).map((tier) => ({ min_qty: Number(tier.min_qty), unit_price: tier.unit_price }))
          : null,
      };
      return mode === 'create'
        ? priceAgreementsApi.create(payload)
        : priceAgreementsApi.update(initial!.id, payload);
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['crm', 'price-agreements'] });
      toast.success(mode === 'create' ? 'Price agreement created.' : 'Price agreement updated.');
      navigate('/crm/price-agreements');
    },
    onError: (e) => {
      applyServerValidationErrors(e, setError, 'Failed to save price agreement.');
    },
  });
  const safety = useFormSafety({ form, saved: mutation.isSuccess });

  return (
    <form
      onSubmit={handleSubmit((v) => mutation.mutate(v), onFormInvalid<FormValues>())}
      className="max-w-3xl mx-auto px-5 py-4"
    >
      <FormDraftBanner safety={safety} inset={false} />
      <fieldset className="mb-8">
        <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">Agreement details</legend>
        <div className="grid grid-cols-2 gap-3">
          <div className="col-span-2">
            <Input
              label="Find a product"
              value={productSearch}
              onChange={(event) => setProductSearch(event.target.value)}
              placeholder="Search part number or name…"
              helper="Search the active catalog, then choose a result below."
            />
          </div>
          <div className="col-span-2">
            <Select label="Product" required {...register('product_id')} error={errors.product_id?.message}>
              <option value="">— select product —</option>
              {initial?.product && !productAlreadyListed && (
                <option value={initial.product.id}>
                  {initial.product.part_number} — {initial.product.name}
                </option>
              )}
              {products.map((product) => (
                <option key={product.id} value={product.id}>
                  {product.part_number} — {product.name}
                </option>
              ))}
            </Select>
          </div>
          <div className="col-span-2">
            <Input
              label="Find a customer"
              value={customerSearch}
              onChange={(event) => setCustomerSearch(event.target.value)}
              placeholder="Search customer name or contact…"
              helper="Search the active customer catalog, then choose a result below."
            />
          </div>
          <div className="col-span-2">
            <Select label="Customer" required {...register('customer_id')} error={errors.customer_id?.message}>
              <option value="">— select customer —</option>
              {initial?.customer && !customerAlreadyListed && (
                <option value={initial.customer.id}>{initial.customer.name}</option>
              )}
              {customers.map((customer) => (
                <option key={customer.id} value={customer.id}>
                  {customer.code ? `${customer.code} — ` : ''}{customer.name}
                </option>
              ))}
            </Select>
          </div>
          <Select label="Pricing method" required {...register('pricing_method')} error={errors.pricing_method?.message}>
            <option value="flat">Flat price</option>
            <option value="tiered">Volume tiers</option>
          </Select>
          <Input
            label={pricingMethod === 'tiered' ? 'Fallback price' : 'Agreed price'}
            required
            prefix="₱"
            {...register('price')}
            error={errors.price?.message}
            placeholder="0.00"
            className="font-mono tabular-nums"
          />
          <Input label="Effective From" required type="date" {...register('effective_from')} error={errors.effective_from?.message} />
          <Input label="Effective To (Expiry)" required type="date" {...register('effective_to')} error={errors.effective_to?.message} />
        </div>
      </fieldset>

      {pricingMethod === 'tiered' && (
        <fieldset className="mb-8">
          <div className="flex items-center justify-between mb-4">
            <legend className="text-xs uppercase tracking-wider text-muted font-medium">Volume tiers</legend>
            <Button
              type="button"
              variant="secondary"
              size="sm"
              icon={<LuPlus size={14} />}
              onClick={() => append({ min_qty: '', unit_price: '' })}
            >
              Add tier
            </Button>
          </div>
          <div className="space-y-3">
            {fields.map((field, index) => (
              <div key={field.id} className="grid grid-cols-[1fr_1fr_auto] gap-3 items-end">
                <Input
                  label={index === 0 ? 'Minimum quantity' : undefined}
                  type="number"
                  min={1}
                  step={1}
                  {...register(`tiers.${index}.min_qty`)}
                  error={errors.tiers?.[index]?.min_qty?.message}
                  placeholder="1"
                  className="font-mono tabular-nums"
                />
                <Input
                  label={index === 0 ? 'Unit price' : undefined}
                  prefix="₱"
                  {...register(`tiers.${index}.unit_price`)}
                  error={errors.tiers?.[index]?.unit_price?.message}
                  placeholder="0.00"
                  className="font-mono tabular-nums"
                />
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  iconOnly
                  icon={<LuTrash2 size={14} />}
                  aria-label={`Remove tier ${index + 1}`}
                  onClick={() => remove(index)}
                  className="text-muted hover:text-danger-fg"
                />
              </div>
            ))}
          </div>
          {errors.tiers?.message && <p className="mt-2 text-xs text-danger-fg">{errors.tiers.message}</p>}
          <p className="mt-2 text-xs text-muted">Enter tiers from the smallest to largest quantity. The fallback price applies below the first threshold.</p>
        </fieldset>
      )}

      <FormActions>
        <Button type="button" variant="secondary" onClick={() => navigate('/crm/price-agreements')}>
          Cancel
        </Button>
        <Button type="submit" variant="primary" disabled={isSubmitting || mutation.isPending} loading={mutation.isPending}>
          {mutation.isPending
            ? mode === 'create' ? 'Creating…' : 'Saving…'
            : mode === 'create' ? 'Create agreement' : 'Save changes'}
        </Button>
      </FormActions>
    </form>
  );
}
