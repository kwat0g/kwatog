import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { Plus } from 'lucide-react';
import { currencyApi } from '@/api/accounting/currency';
import { businessPoliciesApi } from '@/api/businessPolicies';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDate } from '@/lib/formatDate';
import { onFormInvalid } from '@/lib/formErrors';
import type { ApiValidationError } from '@/types';
import type { FxRate } from '@/types/accounting';

const schema = z.object({
 currency_code: z.string().trim().length(3, '3-letter ISO code').transform((s) => s.toUpperCase()),
 rate_date: z.string().min(1, 'Date is required'),
 rate_to_functional: z.coerce.number({ invalid_type_error: 'Number' }).positive('> 0'),
 source: z.string().max(40).optional().or(z.literal('')),
});
type FormValues = z.input<typeof schema>;

export default function FxRatesPage() {
 const { can } = usePermission();
 const [addOpen, setAddOpen] = useState(false);
 const [page, setPage] = useState(1);
 const { data: policies } = useQuery({ queryKey: ['business-policies'], queryFn: businessPoliciesApi.get });
 const functionalCurrency = policies?.functional_currency_code ?? '—';
 const reportingCurrency = policies?.reporting_currency_code ?? '—';

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['accounting', 'fx-rates', page],
 queryFn: () => currencyApi.listRates({ per_page: 50, page }),
 placeholderData: (prev) => prev,
 });

 const columns: Column<FxRate>[] = [
 { key: 'currency_code', header: 'Currency', cell: (r) => <span className="font-mono">{r.currency_code}</span> },
 { key: 'rate_date', header: 'Date', cell: (r) => <NumCell>{formatDate(r.rate_date)}</NumCell> },
 { key: 'rate_to_functional', header: `Rate (${functionalCurrency} per 1 unit)`, align: 'right', cell: (r) => <NumCell className="font-mono tabular-nums">{Number(r.rate_to_functional).toFixed(8)}</NumCell> },
 { key: 'source', header: 'Source', cell: (r) => r.source ?? '—' },
 ];

 return (
 <div>
 <PageHeader
 title="FX Rates"
 subtitle={`${functionalCurrency} per 1 unit of the foreign currency (default reporting currency: ${reportingCurrency})`}
 backTo="/accounting/journal-entries"
 backLabel="Accounting"
 actions={can('accounting.currency.manage') ? (
 <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => setAddOpen(true)}>Add rate</Button>
 ) : undefined}
 />

 {isLoading && !data && <SkeletonTable columns={4} rows={8} />}
 {isError && <EmptyState icon="alert-circle" title="Failed to load FX rates" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
 {data && data.data.length === 0 && (
 <EmptyState icon="inbox" title="No FX rates yet"
 description={can('accounting.currency.manage') ? `Add a daily rate so parent-pack statements can translate to ${reportingCurrency}.` : 'Nothing here yet.'}
 action={can('accounting.currency.manage') ? <Button variant="primary" onClick={() => setAddOpen(true)}>Add rate</Button> : undefined} />
 )}
 {data && data.data.length > 0 && (
 <div className="px-5 py-4">
 <DataTable
 columns={columns}
 data={data.data}
 meta={data.meta}
 onPageChange={(p) => setPage(p)}
 />
 </div>
 )}

 {addOpen && <AddRateModal onClose={() => setAddOpen(false)} functionalCurrency={functionalCurrency} reportingCurrency={reportingCurrency} />}
 </div>
 );
}

function AddRateModal({ onClose, functionalCurrency, reportingCurrency }: { onClose: () => void; functionalCurrency: string; reportingCurrency: string }) {
 const qc = useQueryClient();
 const { register, handleSubmit, setError, formState: { errors, isSubmitting } } = useForm<FormValues>({
 resolver: zodResolver(schema),
 defaultValues: { currency_code: reportingCurrency === '—' ? '' : reportingCurrency, rate_date: new Date().toISOString().slice(0, 10), source: '' },
 });

 const mutation = useMutation({
 mutationFn: (v: FormValues) => currencyApi.storeRate({
 currency_code: String(v.currency_code).toUpperCase(),
 rate_date: v.rate_date,
 rate_to_functional: String(v.rate_to_functional),
 source: v.source || undefined,
 }),
 onSuccess: () => {
 qc.invalidateQueries({ queryKey: ['accounting', 'fx-rates'] });
 toast.success('FX rate saved.');
 onClose();
 },
 onError: (e: AxiosError<ApiValidationError>) => {
 if (e.response?.status === 422 && e.response.data?.errors) {
 Object.entries(e.response.data.errors).forEach(([f, msgs]) =>
 setError(f as keyof FormValues, { type: 'server', message: msgs[0] }));
 } else {
 toast.error(e.response?.data?.message ?? 'Failed to save rate.');
 }
 },
 });

 return (
 <Modal isOpen onClose={onClose} title="Add FX rate" size="sm">
 <form onSubmit={handleSubmit((v) => mutation.mutate(v), onFormInvalid<FormValues>())} className="space-y-3">
 <Input label="Currency (ISO 4217)" required maxLength={3} className="font-mono uppercase" {...register('currency_code')} error={errors.currency_code?.message} />
 <Input label="Rate date" type="date" required {...register('rate_date')} error={errors.rate_date?.message} />
 <Input label={`Rate — ${functionalCurrency} per 1 unit`} type="number" step="0.00000001" min="0" placeholder="0.00000000"
 className="font-mono tabular-nums" {...register('rate_to_functional')} error={errors.rate_to_functional?.message} />
 <Input label="Source" placeholder="Enter rate source" {...register('source')} error={errors.source?.message} />
 <p className="text-2xs text-muted">Re-entering a currency + date overwrites that day’s rate.</p>
 <div className="flex justify-end gap-2 pt-1">
 <Button type="button" variant="secondary" onClick={onClose} disabled={isSubmitting}>Cancel</Button>
 <Button type="submit" variant="primary" loading={mutation.isPending} disabled={mutation.isPending}>Save rate</Button>
 </div>
 </form>
 </Modal>
 );
}
