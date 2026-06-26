import { useParams, useNavigate } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { AxiosError } from 'axios';
import { recruitmentApi } from '@/api/recruitment';
import { departmentsApi } from '@/api/hr/departments';
import { positionsApi } from '@/api/hr/positions';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import { Select } from '@/components/ui/Select';
import { PageHeader } from '@/components/layout/PageHeader';
import { SkeletonTable } from '@/components/ui/Skeleton';
import toast from 'react-hot-toast';

const schema = z.object({
  title: z.string().min(1, 'Title is required').max(200),
  department_id: z.string().min(1, 'Department is required'),
  position_id: z.string().optional(),
  description: z.string().min(1, 'Description is required'),
  requirements: z.string().min(1, 'Requirements are required'),
  employment_type: z.enum(['regular', 'probationary', 'contractual', 'project_based']),
  salary_range_min: z.string().optional(),
  salary_range_max: z.string().optional(),
  show_salary: z.boolean().optional(),
  slots: z.coerce.number().int().min(1).max(100).optional(),
  closes_at: z.string().optional(),
});

type FormData = z.infer<typeof schema>;

export default function PostingEditPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  const { data: posting, isLoading } = useQuery({
    queryKey: ['recruitment-posting', id],
    queryFn: () => recruitmentApi.showPosting(id!).then((r) => r.data.data),
    enabled: !!id,
  });

  const { data: departments } = useQuery({
    queryKey: ['departments'],
    queryFn: () => departmentsApi.list().then((r) => r.data ?? []),
  });

  const { data: positions } = useQuery({
    queryKey: ['positions'],
    queryFn: () => positionsApi.list().then((r) => r.data ?? []),
  });

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<FormData>({
    resolver: zodResolver(schema),
    values: posting
      ? {
          title: posting.title,
          department_id: posting.department?.id ?? '',
          position_id: posting.position?.id ?? '',
          description: posting.description,
          requirements: posting.requirements,
          employment_type: posting.employment_type,
          salary_range_min: posting.salary_range_min ?? '',
          salary_range_max: posting.salary_range_max ?? '',
          show_salary: posting.show_salary,
          slots: posting.slots,
          closes_at: posting.closes_at ? posting.closes_at.split('T')[0] : '',
        }
      : undefined,
  });

  const mutation = useMutation({
    mutationFn: (data: FormData) => recruitmentApi.updatePosting(id!, data as any),
    onSuccess: () => {
      toast.success('Posting updated.');
      queryClient.invalidateQueries({ queryKey: ['recruitment-posting', id] });
      queryClient.invalidateQueries({ queryKey: ['recruitment-postings'] });
      navigate(`/hr/recruitment/postings/${id}`);
    },
    onError: (err: AxiosError<{ errors?: Record<string, string[]> }>) => {
      toast.error('Failed to update posting.');
      const body = err.response?.data;
      if (err.response?.status === 422 && body?.errors) {
        Object.entries(body.errors).forEach(([field, msgs]) => {
          setError(field as keyof FormData, { message: msgs[0] });
        });
      }
    },
  });

  if (isLoading) return <SkeletonTable rows={5} columns={3} />;

  return (
    <div>
      <PageHeader title="Edit Job Posting" subtitle={posting?.posting_number ?? ''} />

      <form onSubmit={handleSubmit((d) => mutation.mutate(d))} className="mt-6 max-w-2xl space-y-4">
        <div>
          <label className="mb-1 block text-sm font-medium">Title *</label>
          <Input {...register('title')} />
          {errors.title && <p className="mt-1 text-xs text-danger">{errors.title.message}</p>}
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <label className="mb-1 block text-sm font-medium">Department *</label>
            <Select {...register('department_id')}>
              <option value="">Select department</option>
              {departments?.map((d: any) => (
                <option key={d.id} value={d.id}>{d.name}</option>
              ))}
            </Select>
            {errors.department_id && <p className="mt-1 text-xs text-danger">{errors.department_id.message}</p>}
          </div>
          <div>
            <label className="mb-1 block text-sm font-medium">Position (optional)</label>
            <Select {...register('position_id')}>
              <option value="">None</option>
              {positions?.map((p: any) => (
                <option key={p.id} value={p.id}>{p.title}</option>
              ))}
            </Select>
          </div>
        </div>

        <div>
          <label className="mb-1 block text-sm font-medium">Employment Type *</label>
          <Select {...register('employment_type')}>
            <option value="regular">Regular</option>
            <option value="probationary">Probationary</option>
            <option value="contractual">Contractual</option>
            <option value="project_based">Project-Based</option>
          </Select>
        </div>

        <div>
          <label className="mb-1 block text-sm font-medium">Description *</label>
          <Textarea {...register('description')} rows={5} />
          {errors.description && <p className="mt-1 text-xs text-danger">{errors.description.message}</p>}
        </div>

        <div>
          <label className="mb-1 block text-sm font-medium">Requirements *</label>
          <Textarea {...register('requirements')} rows={4} />
          {errors.requirements && <p className="mt-1 text-xs text-danger">{errors.requirements.message}</p>}
        </div>

        <div className="grid gap-4 sm:grid-cols-3">
          <div>
            <label className="mb-1 block text-sm font-medium">Salary Min</label>
            <Input {...register('salary_range_min')} type="number" step="0.01" />
          </div>
          <div>
            <label className="mb-1 block text-sm font-medium">Salary Max</label>
            <Input {...register('salary_range_max')} type="number" step="0.01" />
          </div>
          <div>
            <label className="mb-1 block text-sm font-medium">Slots</label>
            <Input {...register('slots')} type="number" min={1} max={100} />
          </div>
        </div>

        <div className="flex items-center gap-2">
          <input type="checkbox" id="show_salary" {...register('show_salary')} className="rounded" />
          <label htmlFor="show_salary" className="text-sm">Show salary range on public listing</label>
        </div>

        <div>
          <label className="mb-1 block text-sm font-medium">Application Deadline</label>
          <Input {...register('closes_at')} type="date" />
        </div>

        <div className="flex gap-3 pt-4">
          <Button type="submit" disabled={mutation.isPending}>
            {mutation.isPending ? 'Saving...' : 'Save Changes'}
          </Button>
          <Button type="button" variant="secondary" onClick={() => navigate(`/hr/recruitment/postings/${id}`)}>
            Cancel
          </Button>
        </div>
      </form>
    </div>
  );
}
