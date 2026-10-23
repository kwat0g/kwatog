/**
 * Reusable Product create/edit form. Used by create.tsx and edit.tsx wrappers.
 * Server-side validation errors land on the matching field; sane Zod schema
 * mirrors the StoreProductRequest rules.
 */
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient, useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { onFormInvalid } from '@/lib/formErrors';
import { productFormSchema, type ProductFormValues } from './schema';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Switch } from '@/components/ui/Switch';
import { Textarea } from '@/components/ui/Textarea';
import { Select } from '@/components/ui/Select';
import { accountsApi } from '@/api/accounting/accounts';
import { productsApi } from '@/api/crm/products';
import { uomsApi } from '@/api/inventory/uoms';
import type { Product, CreateProductData, UpdateProductData } from '@/types/crm';
import { usePermission } from '@/hooks/usePermission';

import { useFormSafety } from '@/hooks/useFormSafety';
import { FormDraftBanner } from '@/components/ui/FormDraftBanner';
import { FormActions } from '@/components/ui/FormActions';
type FormValues = ProductFormValues;

interface Props {
  initial?: Product;
  mode: 'create' | 'edit';
}

export function ProductForm({ initial, mode }: Props) {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { can } = usePermission();

  const { data: uoms = [] } = useQuery({
    queryKey: ['inventory', 'uoms'],
    queryFn: uomsApi.list,
    staleTime: 300_000,
  });
  const canViewRevenueAccounts = can('accounting.coa.view');
  const { data: revenueAccountsResponse } = useQuery({
    queryKey: ['accounting', 'accounts', 'revenue'],
    queryFn: () => accountsApi.list({ per_page: 200, type: 'revenue', is_active: true }),
    enabled: canViewRevenueAccounts,
    staleTime: 300_000,
  });
  const revenueAccounts = revenueAccountsResponse?.data ?? [];

  const form = useForm<FormValues>({
    resolver: zodResolver(productFormSchema),
    defaultValues: {
      part_number: initial?.part_number ?? '',
      name: initial?.name ?? '',
      description: initial?.description ?? '',
      unit_of_measure: initial?.unit_of_measure.toUpperCase() ?? '',
      standard_cost: initial?.standard_cost ?? '',
      base_selling_price: initial?.base_selling_price ?? '',
      minimum_selling_price: initial?.minimum_selling_price ?? '',
      revenue_account_id: initial?.revenue_account_id ?? '',
      is_active: initial?.is_active ?? true,
    },
  });
  const {
    register,
    handleSubmit,
    getValues,
    setValue,
    setError,
    watch,
    reset,
    formState: { errors, isSubmitting },
  } = form;

  // Re-sync defaults if initial changes (e.g. edit page after fetch).
  // `reset` rather than field-by-field `setValue`: one render instead of six,
  // and it re-baselines the defaults so a hydrated record doesn't leave the
  // form looking dirty before the user has touched it.
  useEffect(() => {
    if (initial) {
      reset({
        part_number: initial.part_number,
        name: initial.name,
        description: initial.description ?? '',
        unit_of_measure: initial.unit_of_measure.toUpperCase(),
        standard_cost: initial.standard_cost,
        base_selling_price: initial.base_selling_price ?? '',
        minimum_selling_price: initial.minimum_selling_price ?? '',
        revenue_account_id: initial.revenue_account_id ?? '',
        is_active: initial.is_active,
      });
    }
  }, [initial, reset]);

  useEffect(() => {
    if (!initial) return;

    const canonicalUom = uoms.find(
      (uom) => uom.code.toUpperCase() === initial.unit_of_measure.toUpperCase(),
    );
    if (
      canonicalUom &&
      getValues('unit_of_measure')?.toUpperCase() === canonicalUom.code.toUpperCase()
    ) {
      setValue('unit_of_measure', canonicalUom.code, { shouldDirty: false });
    }
  }, [initial, uoms, getValues, setValue]);

  const isActive = watch('is_active');
  const baseSellingPrice = watch('base_selling_price');
  const { ref: isActiveRef, ...isActiveRegister } = register('is_active');
  void isActiveRef;

  const mutation = useMutation({
    mutationFn: (values: FormValues) => {
      const payload: CreateProductData | UpdateProductData = {
        ...values,
        base_selling_price: values.base_selling_price || null,
        minimum_selling_price: values.minimum_selling_price || null,
        description: values.description?.trim() ? values.description : null,
      };
      return mode === 'create'
        ? productsApi.create(payload as CreateProductData)
        : productsApi.update(initial!.id, payload);
    },
    onSuccess: (product) => {
      qc.invalidateQueries({ queryKey: ['crm', 'products'] });
      toast.success(mode === 'create' ? 'Product created.' : 'Product updated.');
      navigate(`/crm/products/${product.id}`);
    },
    onError: (e: AxiosError<{ message?: string; errors?: Record<string, string[]> }>) => {
      if (e.response?.status === 422 && e.response.data.errors) {
        Object.entries(e.response.data.errors).forEach(([field, msgs]) => {
          setError(field as keyof FormValues, { type: 'server', message: msgs[0] });
        });
        toast.error(e.response?.data?.message || 'Validation failed.');
      } else {
        toast.error(e.response?.data?.message ?? 'Failed to save product.');
      }
    },
  });
  const safety = useFormSafety({ form, saved: mutation.isSuccess });

  return (
    <form
      onSubmit={handleSubmit((v) => mutation.mutate(v), onFormInvalid<FormValues>())}
      className="min-w-0 w-full px-5 py-4"
    >
      <FormDraftBanner safety={safety} inset={false} />
      <fieldset className="mb-8">
        <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">
          Identification
        </legend>
        <div className="grid grid-cols-2 gap-3">
          <Input
            label="Part Number"
            required
            {...register('part_number')}
            error={errors.part_number?.message}
            placeholder="Enter part number"
            className="font-mono"
          />
          <Select
            label="Unit of Measure"
            required
            {...register('unit_of_measure')}
            error={errors.unit_of_measure?.message}
          >
            <option value="">— Select UOM —</option>
            {uoms.map((u) => (
              <option key={u.id} value={u.code}>
                {u.code}
              </option>
            ))}
          </Select>
          <div className="col-span-2">
            <Input
              label="Name"
              required
              {...register('name')}
              error={errors.name?.message}
              placeholder="Enter product name"
            />
          </div>
          <div className="col-span-2">
            <Textarea
              label="Description"
              rows={3}
              {...register('description')}
              error={errors.description?.message}
              placeholder="Optional notes for production / sales reference."
            />
          </div>
        </div>
      </fieldset>

      <fieldset className="mb-8">
        <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">
          Costing
        </legend>
        <div className="grid grid-cols-2 gap-3">
          <Input
            label="Standard Cost"
            required
            prefix="₱"
            {...register('standard_cost')}
            readOnly={mode === 'edit' && !can('inventory.costs.approve')}
            error={errors.standard_cost?.message}
            placeholder="0.00"
            className="font-mono"
          />
          {canViewRevenueAccounts ? (
            <Select
              searchable
              label="Revenue Account"
              {...register('revenue_account_id')}
              error={errors.revenue_account_id?.message}
              helper="Optional override for the product's sales revenue account."
            >
              <option value="">Use configured default</option>
              {revenueAccounts.map((account) => (
                <option key={account.id} value={account.id}>
                  {account.code} · {account.name}
                </option>
              ))}
            </Select>
          ) : (
            <p className="text-xs text-muted self-end pb-1">
              Revenue account selection requires chart-of-accounts access.
            </p>
          )}
        </div>
        <p className="mt-2 text-xs text-muted">
          {mode === 'edit' &&
            !can('inventory.costs.approve') &&
            'Financial fields require Finance approval. Submit changes from the product record. '}
          Internal accounting figure. Confirm full unit cost before setting selling prices;
          customers never see this cost.
        </p>
      </fieldset>

      <fieldset className="mb-8 rounded-lg border border-default bg-canvas p-4 sm:p-5">
        <legend className="px-1 text-sm font-semibold">Selling prices</legend>
        <p className="mb-4 text-sm text-muted">
          Base price applies when no customer agreement covers the requested delivery date.
        </p>
        <div className="grid gap-4 sm:grid-cols-2">
          <Input
            label="Base selling price"
            prefix="₱"
            inputMode="decimal"
            {...register('base_selling_price')}
            readOnly={mode === 'edit' && !can('inventory.costs.approve')}
            error={errors.base_selling_price?.message}
            placeholder="Not configured"
            helper="Per unit, before VAT. Leave unset if not ready to sell."
          />
          <Input
            label="Minimum selling price"
            prefix="₱"
            inputMode="decimal"
            {...register('minimum_selling_price')}
            readOnly={mode === 'edit' && !can('inventory.costs.approve')}
            error={errors.minimum_selling_price?.message}
            placeholder="Not configured"
            helper="Blocks lower base, agreed and volume prices. No floor protection when unset."
          />
        </div>
        {!baseSellingPrice && (
          <p role="status" className="mt-3 rounded-md border border-warning/40 bg-warning-bg px-3 py-2 text-sm text-warning-fg">
            No base price. Customers need an active price agreement for their delivery date before
            they can order this product.
          </p>
        )}
        <p className="mt-3 text-xs text-muted">
          Sales manages customer portal access from the saved product record. New products start
          private, with no assigned customers.
        </p>
      </fieldset>

      <fieldset className="mb-8">
        <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">
          Status
        </legend>
        <Switch
          {...isActiveRegister}
          checked={!!isActive}
          label="Active — visible to CRM officers when creating sales orders"
        />
      </fieldset>

      <FormActions>
        <Button type="button" variant="secondary" onClick={() => navigate('/crm/products')}>
          Cancel
        </Button>
        <Button
          type="submit"
          variant="primary"
          disabled={isSubmitting || mutation.isPending}
          loading={mutation.isPending}
        >
          {mutation.isPending
            ? mode === 'create'
              ? 'Creating…'
              : 'Saving…'
            : mode === 'create'
              ? 'Create product'
              : 'Save changes'}
        </Button>
      </FormActions>
    </form>
  );
}
