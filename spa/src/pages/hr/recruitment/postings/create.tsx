import { useNavigate } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AxiosError } from 'axios';
import { recruitmentApi } from '@/api/recruitment';
import { departmentsApi } from '@/api/hr/departments';
import { positionsApi } from '@/api/hr/positions';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import { Select } from '@/components/ui/Select';
import { Panel } from '@/components/ui/Panel';
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

export default function PostingCreatePage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();

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
    defaultValues: {
      employment_type: 'regular',
      slots: 1,
      show_salary: false,
    },
  });

  const mutation = useMutation({
    mutationFn: (data: FormData) => recruitmentApi.createPosting(data as any),
    onSuccess: (res: { data: { data: { id: string } } }) => {
      toast.success('Job posting created.');
      queryClient.invalidateQueries({ queryKey: ['recruitment-postings'] });
      navigate(`/hr/recruitment/postings/${res.data.data.id}`);
    },
    onError: (err: AxiosError<{ errors?: Record<string, string[]> }>) => {
      toast.error('Failed to create posting.');
      const body = err.response?.data;
      if (err.response?.status === 422 && body?.errors) {
        Object.entries(body.errors).forEach(([field, msgs]) => {
          setError(field as keyof FormData, { message: msgs[0] });
        });
      }
    },
  });

  return (
    <div>
      <PageHeader
        title="Create Job Posting"
        subtitle="Post a new open position"
        backTo="/hr/recruitment/postings"
        backLabel="Postings"
        breadcrumbs={[
          { label: 'HR', href: '/hr' },
          { label: 'Recruitment', href: '/hr/recruitment' },
          { label: 'Postings', href: '/hr/recruitment/postings' },
          { label: 'Create' },
        ]}
      />

      <div className="px-5 py-4">
        <form onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormData>())} className="max-w-2xl space-y-4">
          <Panel title="Basic Information">
            <div className="space-y-4">
              <Input label="Title" required {...register('title')} placeholder="e.g. Injection Molding Operator" error={errors.title?.message} />

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
              <Textarea label="Description" required {...register('description')} rows={5} placeholder="Job description..." error={errors.description?.message} />
              <Textarea label="Requirements" required {...register('requirements')} rows={4} placeholder="Qualifications and requirements..." error={errors.requirements?.message} />
            </div>
          </Panel>

          <Panel title="Compensation & Settings">
            <div className="space-y-4">
              <div className="grid gap-4 sm:grid-cols-3">
                <Input label="Salary Min" {...register('salary_range_min')} type="number" step="0.01" placeholder="0.00" prefix="₱" />
                <Input label="Salary Max" {...register('salary_range_max')} type="number" step="0.01" placeholder="0.00" prefix="₱" />
                <Input label="Slots" {...register('slots')} type="number" min={1} max={100} />
              </div>

              <div className="flex items-center gap-2">
                <input type="checkbox" id="show_salary" {...register('show_salary')} className="h-4 w-4 rounded border-default text-accent" />
                <label htmlFor="show_salary" className="text-sm">Show salary range on public listing</label>
              </div>

              <Input label="Application Deadline" {...register('closes_at')} type="date" />
            </div>
          </Panel>

          <div className="flex gap-3 pt-2">
            <Button type="submit" disabled={mutation.isPending} loading={mutation.isPending}>
              Create Posting
            </Button>
            <Button type="button" variant="secondary" onClick={() => navigate('/hr/recruitment/postings')}>
              Cancel
            </Button>
          </div>
        </form>
      </div>
    </div>
  );
}
