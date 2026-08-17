import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { LuPencil, LuTrash2, LuArchiveRestore, LuPlus } from '@/lib/icons';
import { leaveTypesApi } from '@/api/leave';
import { ArchiveFilter } from '@/components/ui/ArchiveFilter';
import { archiveToTrashed, type ArchiveScope } from '@/lib/archiveScope';
import { DataTable } from '@/components/ui/DataTable';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Input } from '@/components/ui/Input';
import { Switch } from '@/components/ui/Switch';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { usePermission } from '@/hooks/usePermission';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import type { ListParams, ApiValidationError } from '@/types';
import type { LeaveType } from '@/types/leave';

import { QueryErrorState } from '@/components/ui/QueryErrorState';
const schema = z.object({
 name: z.string().min(1, 'Required').max(100),
 code: z.string().min(1, 'Required').max(10).regex(/^[A-Z0-9_]+$/, 'Uppercase letters, digits, or underscores'),
 default_balance: z.coerce.number().min(0, 'Must be >= 0'),
 max_carryover_days: z.coerce.number().min(0).optional().or(z.literal('')),
 is_paid: z.boolean().optional(),
 requires_document: z.boolean().optional(),
 is_convertible_on_separation: z.boolean().optional(),
 is_convertible_year_end: z.boolean().optional(),
 conversion_rate: z.coerce.number().min(0).max(9.99).optional().or(z.literal('')),
 is_active: z.boolean().optional(),
});
type FormValues = z.infer<typeof schema>;

/**
 * The optional numeric fields accept '' so the inputs can be cleared, but the
 * API takes `number | undefined`. Drop the blanks rather than posting ''.
 */
const toPayload = (d: FormValues) => ({
 ...d,
 max_carryover_days: d.max_carryover_days === '' ? undefined : d.max_carryover_days,
 conversion_rate: d.conversion_rate === '' ? undefined : d.conversion_rate,
});

/**
 * Leave-type catalog manager — rendered inside the "Manage Types" modal on the
 * Leave page (PASS 6 consolidation, 2026-08-08). Compact toolbar instead of a
 * PageHeader because it lives inside a dialog; the page file doubles as the
 * modal body. Re-added after a git reset wiped the original change.
 */
export function LeaveTypesManager() {
 const { can } = usePermission();
 const qc = useQueryClient();
 const [editTarget, setEditTarget] = useState<LeaveType | null>(null);
 const [showCreate, setShowCreate] = useState(false);
 const [filters] = useState<ListParams>({ page: 1, per_page: 50 });
 const [scope, setScope] = useState<ArchiveScope>('active');

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['leave-types', filters, { trashed: archiveToTrashed(scope) }],
 queryFn: () => leaveTypesApi.list({ trashed: archiveToTrashed(scope) }),
 placeholderData: (prev) => prev,
 });
 const items = data?.data ?? [];

 const { register, handleSubmit, reset, formState: { errors } } = useForm<FormValues>({
 resolver: zodResolver(schema),
 defaultValues: { is_paid: true, is_active: true },
 });

 const createMutation = useMutation({
 mutationFn: (d: FormValues) => leaveTypesApi.create(toPayload(d)),
 onSuccess: () => { qc.invalidateQueries({ queryKey: ['leave-types'] }); toast.success('Leave type created.'); setShowCreate(false); reset(); },
 onError: (e: AxiosError<ApiValidationError>) => {
 if (e.response?.status === 422 && e.response.data.errors) Object.entries(e.response.data.errors).forEach(([, msgs]) => toast.error(msgs[0]));
 else toast.error('Failed to create leave type.');
 },
 });

 const updateMutation = useMutation({
 mutationFn: ({ id, d }: { id: string; d: FormValues }) => leaveTypesApi.update(id, toPayload(d)),
 onSuccess: () => { qc.invalidateQueries({ queryKey: ['leave-types'] }); toast.success('Leave type updated.'); setEditTarget(null); },
 onError: () => toast.error('Failed to update leave type.'),
 });

 const deleteMutation = useMutation({
 mutationFn: (id: string) => leaveTypesApi.delete(id),
 onSuccess: () => { qc.invalidateQueries({ queryKey: ['leave-types'] }); toast.success('Leave type archived.'); },
 });

 const restoreMutation = useMutation({
 mutationFn: (id: string) => leaveTypesApi.restore(id),
 onSuccess: () => { qc.invalidateQueries({ queryKey: ['leave-types'] }); toast.success('Leave type restored.'); setScope('active'); },
 onError: () => toast.error('Failed to restore leave type.'),
 });

 const openEdit = (lt: LeaveType) => {
 setEditTarget(lt);
 reset({
 name: lt.name, code: lt.code, default_balance: Number(lt.default_balance),
 max_carryover_days: lt.max_carryover_days ? Number(lt.max_carryover_days) : '',
 is_paid: lt.is_paid, requires_document: lt.requires_document,
 is_convertible_on_separation: lt.is_convertible_on_separation, is_convertible_year_end: lt.is_convertible_year_end,
 conversion_rate: lt.conversion_rate ? Number(lt.conversion_rate) : '',
 is_active: lt.is_active,
 });
 };

 const columns = [
 { key: 'code', header: 'Code', cell: (r: LeaveType) => <span className="font-mono font-medium">{r.code}</span> },
 { key: 'name', header: 'Name', cell: (r: LeaveType) => r.name },
 { key: 'default_balance', header: 'Default', cell: (r: LeaveType) => <span className="font-mono">{r.default_balance}</span> },
 { key: 'is_paid', header: 'Paid', cell: (r: LeaveType) => r.is_paid ? <Chip variant="success">Yes</Chip> : <Chip variant="neutral">No</Chip> },
 { key: 'is_active', header: 'Active', cell: (r: LeaveType) => r.is_active ? <Chip variant="success">Active</Chip> : <Chip variant="neutral">Inactive</Chip> },
 {
 key: 'actions', header: '',
 cell: (r: LeaveType) => can('leave.types.manage') ? (
 <div className="flex gap-1">
 <Button variant="ghost" size="xs" iconOnly aria-label={`Edit ${r.name}`} icon={<LuPencil size={12} />} onClick={(e) => { e.stopPropagation(); openEdit(r); }} />
 {scope === 'only' ? (
 <Button variant="ghost" size="xs" iconOnly aria-label={`Restore ${r.name}`} icon={<LuArchiveRestore size={12} />} onClick={(e) => { e.stopPropagation(); restoreMutation.mutate(r.id); }} />
 ) : (
 <Button variant="ghost" size="xs" iconOnly aria-label={`Archive ${r.name}`} icon={<LuTrash2 size={12} />} onClick={(e) => { e.stopPropagation(); deleteMutation.mutate(r.id); }} />
 )}
 </div>
 ) : null,
 },
 ];

 return (
 <div>
 <div className="flex items-center justify-between gap-3 px-5 pt-3">
 <div className="text-sm text-muted">{items.length} types</div>
 <div className="flex items-center gap-2">
 <ArchiveFilter value={scope} onChange={setScope} />
 {can('leave.types.manage') && (
 <Button variant="primary" size="xs" icon={<LuPlus size={14} />}
 onClick={() => { reset({ is_paid: true, is_active: true }); setShowCreate(true); }}>
 Add Type
 </Button>
 )}
 </div>
 </div>
 {isLoading && <SkeletonTable columns={5} rows={6} />}
 {isError && <QueryErrorState subject="the leave types" onRetry={() => void refetch()} />}
 {!isLoading && !isError && items.length === 0 && <EmptyState icon="calendar" title="No leave types" />}
 {items.length > 0 && (
 <DataTable columns={columns} data={items} meta={data!.meta}
 onPageChange={() => {}}
 />
 )}
 {(showCreate || editTarget) && (
 <Modal isOpen onClose={() => { setShowCreate(false); setEditTarget(null); }} title={editTarget ? 'Edit leave type' : 'Add leave type'} size="lg">
 <form onSubmit={handleSubmit((d) => {
 if (editTarget) updateMutation.mutate({ id: editTarget.id, d });
 else createMutation.mutate(d);
 })} className="space-y-3 py-2">
 <div className="grid grid-cols-2 gap-3">
 <Input label="Name" required {...register('name')} error={errors.name?.message} />
 <Input label="Code" required placeholder="VL" className="font-mono uppercase" {...register('code')} error={errors.code?.message} />
 </div>
 <div className="grid grid-cols-2 gap-3">
 <Input label="Default balance (days)" type="number" min="0" step="0.5" required {...register('default_balance')} error={errors.default_balance?.message} />
 <Input label="Max carryover (days)" type="number" min="0" {...register('max_carryover_days')} error={errors.max_carryover_days?.message} />
 </div>
 <div className="grid grid-cols-2 gap-3">
 <Switch label="Is paid" {...register('is_paid')} />
 <Switch label="Requires document" {...register('requires_document')} />
 <Switch label="Convertible on separation" {...register('is_convertible_on_separation')} />
 <Switch label="Convertible year-end" {...register('is_convertible_year_end')} />
 <Switch label="Active" {...register('is_active')} />
 </div>
 <Input label="Conversion rate" type="number" step="0.01" min="0" max="9.99" {...register('conversion_rate')} error={errors.conversion_rate?.message} />
 <ModalFooter>
 <Button variant="secondary" onClick={() => { setShowCreate(false); setEditTarget(null); }}>Cancel</Button>
 <Button type="submit" variant="primary" loading={createMutation.isPending || updateMutation.isPending}>Save</Button>
 </ModalFooter>
 </form>
 </Modal>
 )}
 </div>
 );
}
