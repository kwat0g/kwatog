import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Plus, Trash2 } from 'lucide-react';
import { skillsApi } from '@/api/hr/skills';
import { DataTable } from '@/components/ui/DataTable';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { Modal } from '@/components/ui/Modal';
import { Input } from '@/components/ui/Input';
import { PageHeader } from '@/components/layout/PageHeader';
import { FilterBar } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { usePermission } from '@/hooks/usePermission';
import toast from 'react-hot-toast';
import type { ListParams } from '@/types';
import type { Skill } from '@/types/hr';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import type { ApiValidationError } from '@/types';
import { AxiosError } from 'axios';

const createSchema = z.object({
 name: z.string().min(1, 'Required').max(200),
 category: z.string().max(100).optional().or(z.literal('')),
 description: z.string().max(1000).optional().or(z.literal('')),
});
type CreateForm = z.infer<typeof createSchema>;

export default function SkillsListPage() {
 const { can } = usePermission();
 const qc = useQueryClient();
 const [showCreate, setShowCreate] = useState(false);
 const [filters, setFilters] = useState<ListParams>({ page: 1, per_page: 25 });

 const { data, isLoading, isError } = useQuery({
 queryKey: ['hr', 'skills', filters],
 queryFn: () => skillsApi.list(filters),
 placeholderData: (prev) => prev,
 });

 const { register, handleSubmit, reset, formState: { errors } } = useForm<CreateForm>({
 resolver: zodResolver(createSchema),
 });

 const createMutation = useMutation({
 mutationFn: (d: CreateForm) => skillsApi.create(d),
 onSuccess: () => {
 qc.invalidateQueries({ queryKey: ['hr', 'skills'] });
 toast.success('Skill created.');
 setShowCreate(false);
 reset();
 },
 onError: (e: AxiosError<ApiValidationError>) => {
 if (e.response?.status === 422 && e.response.data.errors) {
 Object.entries(e.response.data.errors).forEach(([_field, msgs]) => toast.error(msgs[0]));
 } else toast.error('Failed to create skill.');
 },
 });

 const deactivateMutation = useMutation({
 mutationFn: (id: string) => skillsApi.deactivate(id),
 onSuccess: () => {
 qc.invalidateQueries({ queryKey: ['hr', 'skills'] });
 toast.success('Skill deactivated.');
 },
 });

 const columns = [
 { key: 'name', header: 'Name', cell: (row: Skill) => <span className="font-medium">{row.name}</span> },
 { key: 'category', header: 'Category', cell: (row: Skill) => row.category ?? '—' },
 { key: 'description', header: 'Description', cell: (row: Skill) => row.description ?? '—' },
 {
 key: 'is_active', header: 'Active',
 cell: (row: Skill) => row.is_active ? <Chip variant="success">Active</Chip> : <Chip variant="neutral">Inactive</Chip>,
 },
 {
 key: 'actions', header: '',
 cell: (row: Skill) => row.is_active && can('hr.trainings.manage') ? (
 <Button variant="ghost" size="sm" icon={<Trash2 size={12} />}
 onClick={(e) => { e.stopPropagation(); deactivateMutation.mutate(row.id); }} />
 ) : null,
 },
 ];

 return (
 <div>
 <PageHeader
 title="Skills"
 subtitle={data ? `${data.meta.total} skills` : undefined}
 actions={can('hr.trainings.manage') && (
 <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => setShowCreate(true)}>
 Add Skill
 </Button>
 )}
 />
 <FilterBar
 values={filters}
 onFilter={(key, value) => setFilters((p) => ({ ...p, [key]: value, page: 1 }))}
 onSearch={(search) => setFilters((p) => ({ ...p, search, page: 1 }))}
 searchPlaceholder="Search skills..."
 />
 {isLoading && !data && <SkeletonTable columns={4} rows={8} />}
 {isError && <EmptyState icon="alert-circle" title="Failed to load skills" />}
 {data && data.data.length === 0 && <EmptyState icon="file-text" title="No skills yet" />}
 {data && data.data.length > 0 && (
 <DataTable columns={columns} data={data.data} meta={data.meta}
 onPageChange={(page) => setFilters((p) => ({ ...p, page }))}
 />
 )}
 {showCreate && (
 <Modal isOpen onClose={() => setShowCreate(false)} title="Add skill">
 <form onSubmit={handleSubmit((d) => createMutation.mutate(d))} className="space-y-3 py-2">
 <Input label="Name" required {...register('name')} error={errors.name?.message} />
 <Input label="Category" {...register('category')} error={errors.category?.message} />
 <Input label="Description" {...register('description')} error={errors.description?.message} />
 <div className="flex justify-end gap-2 pt-3 border-t border-default">
 <Button variant="secondary" onClick={() => setShowCreate(false)} disabled={createMutation.isPending}>Cancel</Button>
 <Button type="submit" variant="primary" disabled={createMutation.isPending} loading={createMutation.isPending}>Create</Button>
 </div>
 </form>
 </Modal>
 )}
 </div>
 );
}