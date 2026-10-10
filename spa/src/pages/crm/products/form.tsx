/**
 * Reusable Product create/edit form. Used by create.tsx and edit.tsx wrappers.
 * Server-side validation errors land on the matching field; sane Zod schema
 * mirrors the StoreProductRequest rules.
 */
import { useEffect, useState } from 'react';
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
import { crmCustomersApi } from '@/api/crm/customers';
import { productsApi } from '@/api/crm/products';
import { uomsApi } from '@/api/inventory/uoms';
import type { Product, CreateProductData, UpdateProductData } from '@/types/crm';
import { usePermission } from '@/hooks/usePermission';
import { useDebounce } from '@/hooks/useDebounce';

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
  const [customerSearch, setCustomerSearch] = useState('');
  const debouncedCustomerSearch = useDebounce(customerSearch, 300);
  const {
    data: customers,
    isError: customersError,
    refetch: retryCustomers,
  } = useQuery({
    queryKey: ['crm', 'customers', 'product-access', debouncedCustomerSearch],
    queryFn: () =>
      crmCustomersApi.list({
        per_page: 100,
        is_active: true,
        search: debouncedCustomerSearch || undefined,
      }),
    enabled: can('accounting.customers.view'),
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
      portal_visibility: initial?.portal_visibility ?? 'restricted',
      portal_customer_ids: initial?.portal_customers?.map((customer) => customer.id) ?? [],
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
        portal_visibility: initial.portal_visibility ?? 'restricted',
        portal_customer_ids: initial.portal_customers?.map((customer) => customer.id) ?? [],
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

  const selectedCustomers = watch('portal_customer_ids') ?? [];
  const accessCustomers = [
    ...(initial?.portal_customers ?? []),
    ...(customers?.data ?? []).filter(
      (customer) => !initial?.portal_customers?.some((existing) => existing.id === customer.id),
    ),
  ];
  const isActive = watch('is_active');
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
        <legend className="px-1 text-sm font-semibold">Selling prices and customer access</legend>
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
        <Select
          label="Customer portal visibility"
          containerClassName="mt-4"
          {...register('portal_visibility')}
          error={errors.portal_visibility?.message}
        >
          <option value="restricted">Private — assigned customers only</option>
          <option value="standard">Standard — all active customers</option>
        </Select>
        <p className="mt-2 text-xs text-muted">
          An active product needs a base price or applicable agreement before it can be ordered.
          Assignments stay available if an agreement expires.
        </p>
        <div className="mt-4 border-t border-default pt-4">
          <p className="mb-2 text-sm font-medium">
            Assigned customers ({selectedCustomers.length})
          </p>
          {can('accounting.customers.view') ? (
            <>
              <Input
                aria-label="Find customers to assign"
                value={customerSearch}
                onChange={(event) => setCustomerSearch(event.target.value)}
                placeholder="Search customers"
              />
              {customersError && (
                <p role="alert" className="mt-2 text-sm text-danger-fg">
                  Cannot load customers.{' '}
                  <Button type="button" size="sm" onClick={() => retryCustomers()}>
                    Retry
                  </Button>
                </p>
              )}
              <div className="mt-2 max-h-52 overflow-y-auto rounded-md border border-default divide-y divide-default">
                {accessCustomers.map((customer) => (
                  <label
                    key={customer.id}
                    className="flex min-h-11 cursor-pointer items-center gap-3 px-3 py-2 text-sm hover:bg-surface"
                  >
                    <input
                      type="checkbox"
                      checked={selectedCustomers.includes(customer.id)}
                      onChange={(event) =>
                        setValue(
                          'portal_customer_ids',
                          event.target.checked
                            ? [...selectedCustomers, customer.id]
                            : selectedCustomers.filter((id) => id !== customer.id),
                          { shouldDirty: true },
                        )
                      }
                    />
                    {customer.name}
                  </label>
                ))}
                {!accessCustomers.length && (
                  <p className="p-3 text-sm text-muted">No customers found.</p>
                )}
              </div>
            </>
          ) : (
            <p className="text-sm text-muted">
              Customer access requires permission to view customers. Existing assignments are
              preserved.
            </p>
          )}
          {errors.portal_customer_ids?.message && (
            <p role="alert" className="mt-2 text-xs text-danger-fg">
              {errors.portal_customer_ids.message}
            </p>
          )}
        </div>
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
