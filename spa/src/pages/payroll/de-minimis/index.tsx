import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Plus, Trash2, ArchiveRestore } from 'lucide-react';
import { client } from '@/api/client';
import { employeesApi } from '@/api/hr/employees';
import { ArchiveFilter } from '@/components/ui/ArchiveFilter';
import { archiveToTrashed, type ArchiveScope } from '@/lib/archiveScope';
import { DataTable } from '@/components/ui/DataTable';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Modal } from '@/components/ui/Modal';
import { PageHeader } from '@/components/layout/PageHeader';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { usePermission } from '@/hooks/usePermission';
import { formatPeso } from '@/lib/formatNumber';
import toast from 'react-hot-toast';
import type { ListParams } from '@/types';

interface DeMinimisRow {
  id: string;
  employee: { id: string; full_name: string } | null;
  benefit_type: string;
  benefit_type_label: string;
  amount: string;
  period_year: number;
  period_month: number;
  is_taxable_portion: boolean;
  notes: string | null;
}

const BENEFIT_TYPES = [
  { value: 'rice_subsidy', label: 'Rice Subsidy' },
  { value: 'uniform_allowance', label: 'Uniform Allowance' },
  { value: 'medical_cash_allowance', label: 'Medical Cash Allowance' },
  { value: 'laundry_allowance', label: 'Laundry Allowance' },
  { value: 'employee_achievement_award', label: 'Achievement Award' },
  { value: 'gifts', label: 'Gifts' },
  { value: 'meal_allowance_per_ot', label: 'Meal Allowance (OT)' },
];

const schema = z.object({
  employee_id: z.string().min(1, 'Required'),
  benefit_type: z.string().min(1, 'Required'),
  amount: z.string().min(1, 'Required').regex(/^\d+(\.\d{1,2})?$/, 'Invalid amount'),
  period_year: z.string().min(1, 'Required'),
  period_month: z.string().min(1, 'Required'),
});
type FormValues = z.infer<typeof schema>;

export default function DeMinimisPage() {
  const { can } = usePermission();
  const qc = useQueryClient();
const [showCreate, setShowCreate] = useState(false);
 const [scope, setScope] = useState<ArchiveScope>('active');
 const [filters, setFilters] = useState<ListParams & { period_year?: string; period_month?: string; benefit_type?: string }>({ per_page: 25 });

  const { data, isLoading, isError } = useQuery({
  queryKey: ['de-minimis', filters, { trashed: archiveToTrashed(scope) }],
  queryFn: () => client.get('/payroll/de-minimis', { params: { ...filters, trashed: archiveToTrashed(scope) } }).then((r) => r.data),
  placeholderData: (prev) => prev,
  });

  const { data: employees } = useQuery({
    queryKey: ['hr', 'employees', 'active'],
    queryFn: () => employeesApi.list({ per_page: 500, status: 'active' }),
  });

  const items: DeMinimisRow[] = data?.data ?? [];
  const meta = data?.meta;

  const { register, handleSubmit, reset, formState: { errors } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { period_year: new Date().getFullYear().toString(), period_month: (new Date().getMonth() + 1).toString() },
  });

  const createMutation = useMutation({
    mutationFn: (d: FormValues) => client.post('/payroll/de-minimis', d),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['de-minimis'] }); toast.success('Benefit recorded.'); setShowCreate(false); reset(); },
    onError: () => toast.error('Failed to record benefit.'),
  });

const deleteMutation = useMutation({
 mutationFn: (id: string) => client.delete(`/payroll/de-minimis/${id}`),
 onSuccess: () => { qc.invalidateQueries({ queryKey: ['de-minimis'] }); toast.success('Benefit archived.'); },
 });

 const restoreMutation = useMutation({
 mutationFn: (id: string) => client.patch(`/payroll/de-minimis/${id}/restore`),
 onSuccess: () => { qc.invalidateQueries({ queryKey: ['de-minimis'] }); toast.success('Benefit restored.'); setScope('active'); },
 onError: () => toast.error('Failed to restore benefit.'),
 });

  const columns = [
    { key: 'employee', header: 'Employee', cell: (r: DeMinimisRow) => r.employee?.full_name ?? '—' },
    { key: 'benefit_type', header: 'Type', cell: (r: DeMinimisRow) => r.benefit_type_label },
    { key: 'amount', header: 'Amount', cell: (r: DeMinimisRow) => <span className="font-mono">{formatPeso(r.amount)}</span> },
    { key: 'period', header: 'Period', cell: (r: DeMinimisRow) => <span className="font-mono">{r.period_year}-{String(r.period_month).padStart(2, '0')}</span> },
{
 key: 'actions', header: '',
 cell: (r: DeMinimisRow) => (
 scope === 'only' ? (
 <Button variant="ghost" size="sm" icon={<ArchiveRestore size={12} />} onClick={(e) => { e.stopPropagation(); restoreMutation.mutate(r.id); }} />
 ) : (
 <Button variant="ghost" size="sm" icon={<Trash2 size={12} />} onClick={(e) => { e.stopPropagation(); deleteMutation.mutate(r.id); }} />
 )
 ),
},
  ];

  return (
    <div>
      <PageHeader title="De Minimis Benefits" subtitle={`${data?.meta?.total ?? 0} records`}
        actions={can('payroll.adjustments.create') && (
          <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => setShowCreate(true)}>Record Benefit</Button>
        )}
      />
      <div className="flex justify-end px-5 pt-3">
        <ArchiveFilter value={scope} onChange={setScope} />
      </div>
      {isLoading && <SkeletonTable columns={4} rows={8} />}
      {isError && <EmptyState icon="alert-circle" title="Failed to load de minimis benefits" />}
      {!isLoading && !isError && items.length === 0 && <EmptyState icon="file-text" title="No de minimis benefits recorded" />}
      {items.length > 0 && (
        <DataTable columns={columns} data={items} meta={meta}
          onPageChange={(page) => setFilters((p) => ({ ...p, page }))}
        />
      )}
      {showCreate && (
        <Modal isOpen onClose={() => setShowCreate(false)} title="Record de minimis benefit">
          <form onSubmit={handleSubmit((d) => createMutation.mutate(d))} className="space-y-3 py-2">
            <Select label="Employee" required {...register('employee_id')} error={errors.employee_id?.message}>
              <option value="">— Select Employee —</option>
              {employees?.data.map((e) => (
                <option key={e.id} value={e.id}>{e.first_name} {e.last_name}</option>
              ))}
            </Select>
            <Select label="Benefit type" required {...register('benefit_type')} error={errors.benefit_type?.message}>
              <option value="">— Select —</option>
              {BENEFIT_TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
            </Select>
            <Input label="Amount (₱)" required type="number" step="0.01" min="0" {...register('amount')} error={errors.amount?.message} />
            <div className="grid grid-cols-2 gap-3">
              <Input label="Year" required type="number" {...register('period_year')} error={errors.period_year?.message} />
              <Input label="Month" required type="number" min={1} max={12} {...register('period_month')} error={errors.period_month?.message} />
            </div>
            <div className="flex justify-end gap-2 pt-3 border-t border-default">
              <Button variant="secondary" onClick={() => setShowCreate(false)} disabled={createMutation.isPending}>Cancel</Button>
              <Button type="submit" variant="primary" loading={createMutation.isPending}>Record</Button>
            </div>
          </form>
        </Modal>
      )}
    </div>
  );
}