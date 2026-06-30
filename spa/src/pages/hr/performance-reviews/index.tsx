/** Performance Review Cycles list page. */
import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { Plus } from 'lucide-react';
import { reviewCyclesApi, type CycleListParams } from '@/api/hr/performance-reviews';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { Modal } from '@/components/ui/Modal';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import type { ApiValidationError } from '@/types';
import type { ReviewCycle, CycleStatus } from '@/types/performance-reviews';

const STATUS_CHIP: Record<CycleStatus, 'success' | 'warning' | 'neutral'> = {
  draft: 'neutral',
  active: 'success',
  closed: 'neutral',
};

const cycleSchema = z.object({
  name: z.string().min(1, 'Name is required').max(255),
  cycle_type: z.enum(['annual', 'semi_annual', 'quarterly', 'probationary'], { required_error: 'Cycle type is required' }),
  start_date: z.string().min(1, 'Start date is required'),
  end_date: z.string().min(1, 'End date is required'),
}).refine((d) => !d.start_date || !d.end_date || new Date(d.end_date) >= new Date(d.start_date), {
  message: 'End date must be on or after start date',
  path: ['end_date'],
});
type CycleFormValues = z.infer<typeof cycleSchema>;

export default function PerformanceCyclesPage() {
  const { can } = usePermission();
  const qc = useQueryClient();
  const [filters, setFilters] = useState<CycleListParams>({ page: 1, per_page: 25 });
  const [showCreate, setShowCreate] = useState(false);
  const [confirmActivate, setConfirmActivate] = useState<string | null>(null);
  const [confirmClose, setConfirmClose] = useState<string | null>(null);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['performance-cycles', filters],
    queryFn: () => reviewCyclesApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const activateMutation = useMutation({
    mutationFn: (id: string) => reviewCyclesApi.activate(id),
    onSuccess: () => {
      setConfirmActivate(null);
      qc.invalidateQueries({ queryKey: ['performance-cycles'] });
      toast.success('Cycle activated.');
    },
    onError: () => toast.error('Failed to activate cycle.'),
  });

  const closeMutation = useMutation({
    mutationFn: (id: string) => reviewCyclesApi.close(id),
    onSuccess: () => {
      setConfirmClose(null);
      qc.invalidateQueries({ queryKey: ['performance-cycles'] });
      toast.success('Cycle closed.');
    },
    onError: () => toast.error('Failed to close cycle.'),
  });

  const columns: Column<ReviewCycle>[] = [
    { key: 'name', header: 'Name', cell: (r) => <span className="font-medium">{r.name}</span> },
    {
      key: 'cycle_type', header: 'Type',
      cell: (r) => <Chip variant="neutral">{r.cycle_type.replace('_', ' ')}</Chip>,
    },
    { key: 'status', header: 'Status', cell: (r) => <Chip variant={STATUS_CHIP[r.status]}>{r.status}</Chip> },
    { key: 'start_date', header: 'Start', cell: (r) => <span className="font-mono tabular-nums text-sm">{r.start_date}</span> },
    { key: 'end_date', header: 'End', cell: (r) => <span className="font-mono tabular-nums text-sm">{r.end_date}</span> },
    {
      key: 'actions', header: '', align: 'right',
      cell: (r) => (
        <div className="flex items-center gap-1 justify-end">
          {r.status === 'draft' && can('hr.performance.manage') && (
            <Button variant="ghost" size="sm" onClick={() => setConfirmActivate(r.id)}
              disabled={activateMutation.isPending}>
              Activate
            </Button>
          )}
          {r.status === 'active' && can('hr.performance.manage') && (
            <Button variant="ghost" size="sm" onClick={() => setConfirmClose(r.id)}
              disabled={closeMutation.isPending}>
              Close
            </Button>
          )}
        </div>
      ),
    },
  ];

  const filterConfig: FilterConfig[] = [
    {
      key: 'status', label: 'Status', type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'draft', label: 'Draft' },
        { value: 'active', label: 'Active' },
        { value: 'closed', label: 'Closed' },
      ],
    },
    {
      key: 'cycle_type', label: 'Type', type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'annual', label: 'Annual' },
        { value: 'semi_annual', label: 'Semi-annual' },
        { value: 'quarterly', label: 'Quarterly' },
        { value: 'probationary', label: 'Probationary' },
      ],
    },
  ];

  return (
    <div>
      <PageHeader
        title="Review Cycles"
        subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'cycle' : 'cycles'}` : undefined}
        actions={
          can('hr.performance.manage') ? (
            <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => setShowCreate(true)}>
              New cycle
            </Button>
          ) : undefined
        }
      />
      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search cycles..."
      />
      {isLoading && !data && <SkeletonTable columns={6} rows={5} />}
      {isError && (
        <EmptyState icon="alert-circle" title="Failed to load cycles"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
      )}
      {data && data.data.length === 0 && (
        <EmptyState icon="calendar" title="No review cycles"
          description="Create a review cycle to start assigning performance reviews."
          action={can('hr.performance.manage') ? (
            <Button variant="primary" onClick={() => setShowCreate(true)}>New cycle</Button>
          ) : undefined} />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable columns={columns} data={data.data} meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))} />
        </div>
      )}

      {/* Create Cycle Modal */}
      <CreateCycleModal
        isOpen={showCreate}
        onClose={() => setShowCreate(false)}
        onSuccess={() => {
          setShowCreate(false);
          qc.invalidateQueries({ queryKey: ['performance-cycles'] });
        }}
      />

      <ConfirmDialog
        isOpen={confirmActivate !== null}
        onClose={() => setConfirmActivate(null)}
        onConfirm={() => { if (confirmActivate) activateMutation.mutate(confirmActivate); }}
        title="Activate review cycle?"
        description="Employees will be able to submit self-assessments."
        confirmLabel="Activate"
        variant="warning"
        pending={activateMutation.isPending}
      />

      <ConfirmDialog
        isOpen={confirmClose !== null}
        onClose={() => setConfirmClose(null)}
        onConfirm={() => { if (confirmClose) closeMutation.mutate(confirmClose); }}
        title="Close review cycle?"
        description="No more reviews can be submitted after closing."
        confirmLabel="Close"
        variant="warning"
        pending={closeMutation.isPending}
      />
    </div>
  );
}

function CreateCycleModal({ isOpen, onClose, onSuccess }: { isOpen: boolean; onClose: () => void; onSuccess: () => void }) {
  const {
    register, handleSubmit, reset, setError,
    formState: { errors, isSubmitting },
  } = useForm<CycleFormValues>({
    resolver: zodResolver(cycleSchema),
    defaultValues: { name: '', cycle_type: 'annual', start_date: '', end_date: '' },
  });

  const mutation = useMutation({
    mutationFn: (d: CycleFormValues) => reviewCyclesApi.create(d),
    onSuccess: () => {
      toast.success('Review cycle created.');
      reset();
      onSuccess();
    },
    onError: (e: AxiosError<ApiValidationError>) => {
      if (e.response?.status === 422) {
        const data = e.response.data;
        if (data.errors) {
          Object.entries(data.errors).forEach(([f, msgs]) =>
            setError(f as keyof CycleFormValues, { type: 'server', message: msgs[0] }),
          );
        } else if (data.message) {
          toast.error(data.message);
        }
      } else toast.error('Failed to create cycle.');
    },
  });

  return (
    <Modal isOpen={isOpen} onClose={onClose} title="Create review cycle" size="md">
      <form onSubmit={handleSubmit((d) => mutation.mutate(d))} className="space-y-4">
        <Input label="Cycle name" required {...register('name')} error={errors.name?.message} placeholder="e.g. 2026 Annual Review" />
        <Select label="Type" required {...register('cycle_type')} error={errors.cycle_type?.message}>
          <option value="annual">Annual</option>
          <option value="semi_annual">Semi-annual</option>
          <option value="quarterly">Quarterly</option>
          <option value="probationary">Probationary</option>
        </Select>
        <div className="grid grid-cols-2 gap-3">
          <Input label="Start date" type="date" required {...register('start_date')} error={errors.start_date?.message} />
          <Input label="End date" type="date" required {...register('end_date')} error={errors.end_date?.message} />
        </div>
        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="primary" disabled={isSubmitting || mutation.isPending} loading={mutation.isPending}>
            {mutation.isPending ? 'Creating...' : 'Create cycle'}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
