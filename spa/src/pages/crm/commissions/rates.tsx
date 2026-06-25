/** Commission Rates management page. */
import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import { commissionRatesApi } from '@/api/crm/commissions';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { onFormInvalid } from '@/lib/formErrors';
import type { ApiValidationError, ListParams } from '@/types';
import type { CommissionRate } from '@/types/commissions';

const schema = z.object({
  employee_id: z.string().min(1, 'Employee ID is required'),
  rate: z.string().regex(/^0?\.\d{1,4}$|^[01]$/, 'Enter a decimal rate like 0.05'),
  effective_from: z.string().min(1, 'Effective date is required'),
});
type FormValues = z.infer<typeof schema>;

export default function CommissionRatesPage() {
  const { can } = usePermission();
  const qc = useQueryClient();
  const [params, setParams] = useState<ListParams>({ page: 1, per_page: 25 });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['commissions', 'rates', params],
    queryFn: () => commissionRatesApi.list(params),
    placeholderData: (prev) => prev,
  });

  const { register, handleSubmit, setError, reset, formState: { errors } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { employee_id: '', rate: '', effective_from: '' },
  });

  const mutation = useMutation({
    mutationFn: (values: FormValues) => commissionRatesApi.create(values),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['commissions', 'rates'] });
      toast.success('Commission rate created.');
      reset();
    },
    onError: (err: AxiosError<ApiValidationError>) => {
      if (err.response?.status === 422 && err.response.data.errors) {
        Object.entries(err.response.data.errors).forEach(([k, v]) =>
          setError(k as keyof FormValues, { type: 'server', message: v[0] }));
        toast.error(err.response?.data?.message || 'Validation failed.');
      } else {
        toast.error('Failed to create commission rate.');
      }
    },
  });

  const columns: Column<CommissionRate>[] = [
    {
      key: 'employee', header: 'Employee',
      cell: (r) => <span>{r.employee.first_name} {r.employee.last_name}</span>,
    },
    {
      key: 'rate', header: 'Rate', align: 'right',
      cell: (r) => <NumCell>{(parseFloat(r.rate) * 100).toFixed(2)}%</NumCell>,
    },
    {
      key: 'effective_from', header: 'Effective from',
      cell: (r) => <span className="font-mono">{r.effective_from}</span>,
    },
    {
      key: 'effective_until', header: 'Effective until',
      cell: (r) => <span className="font-mono">{r.effective_until ?? '—'}</span>,
    },
    {
      key: 'created_at', header: 'Created',
      cell: (r) => <span className="text-muted">{r.created_at}</span>,
    },
  ];

  return (
    <div>
      <PageHeader title="Commission Rates" backTo="/crm/commissions" backLabel="Commissions" />

      {/* Add rate form */}
      {can('crm.commissions.manage') && (
        <form onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())} className="max-w-3xl mx-auto px-5 py-6">
          <fieldset className="mb-6">
            <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">New commission rate</legend>
            <div className="grid grid-cols-3 gap-3">
              <Input label="Employee ID" {...register('employee_id')} error={errors.employee_id?.message} required placeholder="e.g. yR3kLm" />
              <Input label="Rate (decimal)" {...register('rate')} error={errors.rate?.message} required placeholder="0.05" className="font-mono" />
              <Input label="Effective from" type="date" {...register('effective_from')} error={errors.effective_from?.message} required />
            </div>
          </fieldset>
          <div className="flex items-center justify-end gap-2 pb-4 border-b border-default">
            <Button type="submit" variant="primary" disabled={mutation.isPending} loading={mutation.isPending}>
              {mutation.isPending ? 'Saving…' : 'Add rate'}
            </Button>
          </div>
        </form>
      )}

      {/* Rates table */}
      <div className="mt-4">
        {isLoading && !data && <SkeletonTable columns={5} rows={6} />}
        {isError && (
          <EmptyState icon="alert-circle" title="Failed to load rates"
            action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
        )}
        {data && data.data.length === 0 && (
          <EmptyState icon="percent" title="No commission rates" description="Add a rate above to start tracking commissions." />
        )}
        {data && data.data.length > 0 && (
          <div className="px-5 py-4">
            <DataTable
              columns={columns}
              data={data.data}
              meta={data.meta}
              onPageChange={(page) => setParams((p) => ({ ...p, page }))}
            />
          </div>
        )}
      </div>
    </div>
  );
}
