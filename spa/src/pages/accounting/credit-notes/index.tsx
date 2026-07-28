import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { useFieldArray, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { Plus, Trash2 } from 'lucide-react';
import { creditNotesApi, type CreditNoteListParams } from '@/api/accounting/credit-notes';
import { accountsApi } from '@/api/accounting/accounts';
import { customersApi } from '@/api/accounting/customers';
import { vendorsApi } from '@/api/accounting/vendors';
import { Button } from '@/components/ui/Button';
import { Chip, type ChipVariant } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { Modal } from '@/components/ui/Modal';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { Checkbox } from '@/components/ui/Checkbox';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatPeso } from '@/lib/formatNumber';
import { formatDate } from '@/lib/formatDate';
import { onFormInvalid } from '@/lib/formErrors';
import { numberInputProps } from '@/lib/numberInput';
import type { ApiValidationError } from '@/types';
import type { CreditNote, CreditNoteStatus } from '@/types/accounting';

const cnStatusVariant = (s: CreditNoteStatus): ChipVariant =>
  s === 'applied' ? 'success' : s === 'finalized' ? 'info' : s === 'void' ? 'danger' : 'neutral';

const lineSchema = z.object({
  account_id:  z.string().min(1, 'Account'),
  description: z.string().min(1, 'Required').max(200),
  amount:      z.coerce.number().positive('> 0'),
});
const schema = z.object({
  type:        z.enum(['customer', 'supplier']),
  date:        z.string().min(1, 'Date is required'),
  is_vatable:  z.boolean().default(true),
  customer_id: z.string().optional().or(z.literal('')),
  vendor_id:   z.string().optional().or(z.literal('')),
  reason:      z.string().max(1000).optional().or(z.literal('')),
  lines:       z.array(lineSchema).min(1, 'At least one line'),
}).refine((d) => d.type !== 'customer' || !!d.customer_id, { path: ['customer_id'], message: 'Customer required' })
  .refine((d) => d.type !== 'supplier' || !!d.vendor_id, { path: ['vendor_id'], message: 'Vendor required' });
type FormValues = z.infer<typeof schema>;

export default function CreditNotesPage() {
  const qc = useQueryClient();
  const { can } = usePermission();
  const [filters, setFilters] = useState<CreditNoteListParams>({ page: 1, per_page: 25 });
  const [createOpen, setCreateOpen] = useState(false);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['accounting', 'credit-notes', filters],
    queryFn: () => creditNotesApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<CreditNote>[] = [
    { key: 'number', header: 'CN no', cell: (r) => <Link to={`/accounting/credit-notes/${r.id}`} className="font-mono text-accent hover:underline">{r.credit_note_number ?? 'DRAFT'}</Link> },
    { key: 'type', header: 'Type', cell: (r) => r.type_label },
    { key: 'party', header: 'Party', cell: (r) => r.customer?.name ?? r.vendor?.name ?? '—' },
    { key: 'date', header: 'Date', cell: (r) => <NumCell>{formatDate(r.date)}</NumCell> },
    { key: 'total', header: 'Total', align: 'right', cell: (r) => <NumCell>{formatPeso(r.total_amount)}</NumCell> },
    { key: 'balance', header: 'Unapplied', align: 'right', cell: (r) => <NumCell className="font-medium">{formatPeso(r.balance)}</NumCell> },
    { key: 'status', header: 'Status', cell: (r) => <Chip variant={cnStatusVariant(r.status)}>{r.status_label}</Chip> },
  ];

  const filterConfig: FilterConfig[] = [
    { key: 'type', label: 'Type', type: 'select', options: [
      { value: '', label: 'All' }, { value: 'customer', label: 'Customer (AR)' }, { value: 'supplier', label: 'Supplier (AP)' },
    ] },
    { key: 'status', label: 'Status', type: 'select', options: [
      { value: '', label: 'All' }, { value: 'draft', label: 'Draft' }, { value: 'finalized', label: 'Finalized' },
      { value: 'applied', label: 'Applied' }, { value: 'void', label: 'Void' },
    ] },
  ];

  return (
    <div>
      <PageHeader
        title="Credit Notes"
        subtitle={data ? `${data.meta.total} credit notes` : undefined}
        actions={can('accounting.credit_notes.manage') ? (
          <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => setCreateOpen(true)}>New credit note</Button>
        ) : undefined}
      />

      <div className="px-5 py-4 space-y-3">
        <FilterBar
          filters={filterConfig}
          values={filters}
          onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        />
        {isLoading && !data && <SkeletonTable columns={7} rows={8} />}
        {isError && <EmptyState icon="alert-circle" title="Failed to load credit notes" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
        {data && data.data.length === 0 && <EmptyState icon="inbox" title="No credit notes yet" />}
        {data && data.data.length > 0 && (
          <DataTable
            columns={columns}
            data={data.data}
            meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
          />
        )}
      </div>

      {createOpen && <CreateCreditNoteModal onClose={() => setCreateOpen(false)} onCreated={() => { setCreateOpen(false); qc.invalidateQueries({ queryKey: ['accounting', 'credit-notes'] }); }} />}
    </div>
  );
}

function CreateCreditNoteModal({ onClose, onCreated }: { onClose: () => void; onCreated: () => void }) {
  const { register, control, handleSubmit, watch, setError, formState: { errors } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      type: 'customer', date: new Date().toISOString().slice(0, 10), is_vatable: true,
      customer_id: '', vendor_id: '', reason: '',
      lines: [{ account_id: '', description: '', amount: 0 }],
    },
  });
  const { fields, append, remove } = useFieldArray({ control, name: 'lines' });
  const type = watch('type');
  const lines = watch('lines');
  const isVatable = watch('is_vatable');

  const { data: accountsResp } = useQuery({ queryKey: ['accounting', 'accounts', 'flat-active'], queryFn: () => accountsApi.list({ per_page: 200, is_active: true }) });
  const { data: customersResp } = useQuery({ queryKey: ['accounting', 'customers', 'all'], queryFn: () => customersApi.list({ per_page: 200 }), enabled: type === 'customer' });
  const { data: vendorsResp } = useQuery({ queryKey: ['accounting', 'vendors', 'all'], queryFn: () => vendorsApi.list({ per_page: 200 }), enabled: type === 'supplier' });
  const accounts = accountsResp?.data ?? [];
  const customers = customersResp?.data ?? [];
  const vendors = vendorsResp?.data ?? [];

  const totals = useMemo(() => {
    const sub = lines.reduce((s, l) => s + (Number(l.amount) || 0), 0);
    const vat = isVatable ? sub * 0.12 : 0;
    return { sub: sub.toFixed(2), vat: vat.toFixed(2), total: (sub + vat).toFixed(2) };
  }, [lines, isVatable]);

  const mutation = useMutation({
    mutationFn: (d: FormValues) => creditNotesApi.create({
      type: d.type, date: d.date, is_vatable: d.is_vatable,
      customer_id: d.type === 'customer' ? d.customer_id || undefined : undefined,
      vendor_id:   d.type === 'supplier' ? d.vendor_id || undefined : undefined,
      reason: d.reason || undefined,
      lines: d.lines.map((l) => ({ account_id: l.account_id, description: l.description, amount: String(l.amount) })),
    }),
    onSuccess: (cn) => { toast.success(`Credit note drafted (${formatPeso(cn.total_amount)}).`); onCreated(); },
    onError: (e: AxiosError<ApiValidationError>) => {
      if (e.response?.status === 422 && e.response.data?.errors) {
        Object.entries(e.response.data.errors).forEach(([f, msgs]) => setError(f as keyof FormValues, { type: 'server', message: (msgs as string[])[0] }));
      } else toast.error(e.response?.data?.message ?? 'Failed to create credit note.');
    },
  });

  return (
    <Modal isOpen onClose={onClose} title="New credit note" size="xl">
      <form onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())} className="space-y-4">
        <div className="grid grid-cols-3 gap-3">
          <Select label="Type" required {...register('type')} error={errors.type?.message}>
            <option value="customer">Customer (AR)</option>
            <option value="supplier">Supplier (AP)</option>
          </Select>
          <Input label="Date" type="date" required {...register('date')} error={errors.date?.message} />
          <div className="flex items-end">
            <Checkbox label="VAT-able (12%)" {...register('is_vatable')} />
          </div>
          {type === 'customer' ? (
            <Select label="Customer" required containerClassName="col-span-2" {...register('customer_id')} error={errors.customer_id?.message}>
              <option value="">— Select customer —</option>
              {customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </Select>
          ) : (
            <Select label="Vendor" required containerClassName="col-span-2" {...register('vendor_id')} error={errors.vendor_id?.message}>
              <option value="">— Select vendor —</option>
              {vendors.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
            </Select>
          )}
          <Input label="Reason" containerClassName="col-span-3" {...register('reason')} error={errors.reason?.message} placeholder="Price dispute, damaged goods, over-billing…" />
        </div>

        <div className="border border-default rounded-md overflow-hidden">
          <div className="grid grid-cols-12 gap-2 h-8 px-2.5 bg-subtle text-2xs uppercase tracking-wider text-muted font-medium border-b border-default items-center">
            <div className="col-span-5">{type === 'customer' ? 'Revenue account' : 'Expense account'}</div>
            <div className="col-span-4">Description</div>
            <div className="col-span-2 text-right">Amount</div>
            <div className="col-span-1" />
          </div>
          {fields.map((field, idx) => (
            <div key={field.id} className="grid grid-cols-12 gap-2 px-2.5 py-1.5 border-b border-subtle items-start">
              <div className="col-span-5">
                <Select {...register(`lines.${idx}.account_id` as const)} error={errors.lines?.[idx]?.account_id?.message}>
                  <option value="">— Account —</option>
                  {accounts.map((a) => <option key={a.id} value={a.id}>{a.code} — {a.name}</option>)}
                </Select>
              </div>
              <div className="col-span-4"><Input {...register(`lines.${idx}.description` as const)} error={errors.lines?.[idx]?.description?.message} /></div>
              <div className="col-span-2"><Input type="number" step="0.01" min="0" className="font-mono tabular-nums text-right" {...numberInputProps()} {...register(`lines.${idx}.amount` as const)} error={errors.lines?.[idx]?.amount?.message} /></div>
              <div className="col-span-1 flex justify-end pt-1.5">
                {fields.length > 1 && <Button type="button" variant="ghost" size="sm" iconOnly icon={<Trash2 size={14} />}
                  aria-label="Remove line" onClick={() => remove(idx)} className="text-muted hover:text-danger" />}
              </div>
            </div>
          ))}
        </div>

        <div className="flex items-center justify-between">
          <Button type="button" variant="secondary" size="sm" icon={<Plus size={14} />} onClick={() => append({ account_id: '', description: '', amount: 0 })}>Add line</Button>
          <div className="text-sm font-mono tabular-nums text-right">
            <div className="text-muted">Subtotal: {formatPeso(totals.sub)}</div>
            <div className="text-muted">VAT: {formatPeso(totals.vat)}</div>
            <div className="text-base font-medium">Total: {formatPeso(totals.total)}</div>
          </div>
        </div>

        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="primary" loading={mutation.isPending}>Create draft</Button>
        </div>
      </form>
    </Modal>
  );
}
