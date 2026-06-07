import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import toast from 'react-hot-toast';
import { onFormInvalid, applyServerValidationErrors } from '@/lib/formErrors';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { priceAgreementsApi } from '@/api/crm/priceAgreements';
import { productsApi } from '@/api/crm/products';
import { crmCustomersApi } from '@/api/crm/customers';
import type { PriceAgreement, CreatePriceAgreementData } from '@/types/crm';

const schema = z.object({
  product_id:     z.string().min(1, 'Select a product'),
  customer_id:    z.string().min(1, 'Select a customer'),
  price:          z.string().regex(/^\d+(\.\d{1,2})?$/, 'Enter a valid price (e.g. 12.50)'),
  effective_from: z.string().min(1, 'Effective from date is required'),
  effective_to:   z.string().min(1, 'Expiry date is required'),
}).refine(
  (v) => !v.effective_from || !v.effective_to || v.effective_to >= v.effective_from,
  { message: 'Expiry date must be on or after the effective date', path: ['effective_to'] },
);

type FormValues = z.infer<typeof schema>;

interface Props {
  initial?: PriceAgreement;
  mode: 'create' | 'edit';
}

export function PriceAgreementForm({ initial, mode }: Props) {
  const navigate = useNavigate();
  const qc = useQueryClient();

  const { data: productsData } = useQuery({
    queryKey: ['crm', 'products', 'all'],
    queryFn: () => productsApi.list({ per_page: 200, is_active: true }),
  });

  const { data: customersData } = useQuery({
    queryKey: ['crm', 'customers', 'all'],
    queryFn: () => crmCustomersApi.list({ per_page: 200, is_active: true }),
  });

  const {
    register, handleSubmit, setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      product_id:     initial?.product?.id ?? '',
      customer_id:    initial?.customer?.id ?? '',
      price:          initial?.price ?? '',
      effective_from: initial?.effective_from ?? '',
      effective_to:   initial?.effective_to ?? '',
    },
  });

  const mutation = useMutation({
    mutationFn: (values: FormValues) => {
      const payload: CreatePriceAgreementData = values;
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

  return (
    <form
      onSubmit={handleSubmit((v) => mutation.mutate(v), onFormInvalid<FormValues>())}
      className="max-w-3xl mx-auto px-5 py-6"
    >
      <fieldset className="mb-8">
        <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">Agreement details</legend>
        <div className="grid grid-cols-2 gap-3">
          <div className="col-span-2">
            <Select
              label="Product"
              required
              {...register('product_id')}
              error={errors.product_id?.message}
            >
              <option value="">— select product —</option>
              {productsData?.data.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.part_number} — {p.name}
                </option>
              ))}
            </Select>
          </div>
          <div className="col-span-2">
            <Select
              label="Customer"
              required
              {...register('customer_id')}
              error={errors.customer_id?.message}
            >
              <option value="">— select customer —</option>
              {customersData?.data.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.code ? `${c.code} — ` : ''}{c.name}
                </option>
              ))}
            </Select>
          </div>
          <Input
            label="Agreed Price"
            required
            prefix="₱"
            {...register('price')}
            error={errors.price?.message}
            placeholder="0.00"
            className="font-mono tabular-nums"
          />
          <div />
          <Input
            label="Effective From"
            required
            type="date"
            {...register('effective_from')}
            error={errors.effective_from?.message}
          />
          <Input
            label="Effective To (Expiry)"
            required
            type="date"
            {...register('effective_to')}
            error={errors.effective_to?.message}
          />
        </div>
      </fieldset>

      <div className="flex items-center justify-end gap-2 pt-4 border-t border-default">
        <Button type="button" variant="secondary" onClick={() => navigate('/crm/price-agreements')}>
          Cancel
        </Button>
        <Button
          type="submit"
          variant="primary"
          disabled={isSubmitting || mutation.isPending}
          loading={mutation.isPending}
        >
          {mutation.isPending
            ? mode === 'create' ? 'Creating…' : 'Saving…'
            : mode === 'create' ? 'Create agreement' : 'Save changes'}
        </Button>
      </div>
    </form>
  );
}
