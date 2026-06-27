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
import { Panel } from '@/components/ui/Panel';
import { SkeletonForm } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { onFormInvalid } from '@/lib/formErrors';
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

  if (isLoading) return <SkeletonForm />;

  return (
    <div>
      <PageHeader
        title="Edit Job Posting"
        subtitle={<span className="font-mono">{posting?.posting_number ?? ''}</span>}
        backTo={`/hr/recruitment/postings/${id}`}
        backLabel="Posting"
        breadcrumbs={[
          { label: 'HR', href: '/hr' },
          { label: 'Recruitment', href: '/hr/recruitment' },
          { label: 'Postings', href: '/hr/recruitment/postings' },
          { label: posting?.title ?? 'Edit' },
        ]}
      />

      <form onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormData>())} className="max-w-2xl mx-auto px-5 py-6 space-y-4">
          <Panel title="Basic Information">
            <div className="space-y-4">
              <Input label="Title" required {...register('title')} error={errors.title?.message} />

              <div className="grid gap-4 sm:grid-cols-2">
                <Select label="Department" required {...register('department_id')} error={errors.department_id?.message}>
                  <option value="">Select department</option>
                  {departments?.map((d: any) => (
                    <option key={d.id} value={d.id}>{d.name}</option>
                  ))}
                </Select>
                <Select label="Position" {...register('position_id')}>
                  <option value="">None</option>
                  {positions?.map((p: any) => (
                    <option key={p.id} value={p.id}>{p.title}</option>
                  ))}
                </Select>
              </div>

              <Select label="Employment Type" required {...register('employment_type')}>
                <option value="regular">Regular</option>
                <option value="probationary">Probationary</option>
                <option value="contractual">Contractual</option>
                <option value="project_based">Project-Based</option>
              </Select>
            </div>
          </Panel>

          <Panel title="Job Details">
            <div className="space-y-4">
              <Textarea label="Description" required {...register('description')} rows={5} error={errors.description?.message} />
              <Textarea label="Requirements" required {...register('requirements')} rows={4} error={errors.requirements?.message} />
            </div>
          </Panel>

          <Panel title="Compensation & Settings">
            <div className="space-y-4">
              <div className="grid gap-4 sm:grid-cols-3">
                <Input label="Salary Min" {...register('salary_range_min')} type="number" step="0.01" prefix="₱" />
                <Input label="Salary Max" {...register('salary_range_max')} type="number" step="0.01" prefix="₱" />
                <Input label="Slots" {...register('slots')} type="number" min={1} max={100} />
              </div>

              <div className="flex items-center gap-2">
                <input type="checkbox" id="show_salary" {...register('show_salary')} className="h-4 w-4 rounded border-default text-accent" />
                <label htmlFor="show_salary" className="text-sm">Show salary range on public listing</label>
              </div>

              <Input label="Application Deadline" {...register('closes_at')} type="date" />
            </div>
          </Panel>

          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="secondary" onClick={() => navigate(`/hr/recruitment/postings/${id}`)}>
              Cancel
            </Button>
            <Button type="submit" variant="primary" disabled={mutation.isPending} loading={mutation.isPending}>
              {mutation.isPending ? 'Saving…' : 'Save Changes'}
            </Button>
          </div>
        </form>
    </div>
  );
}
