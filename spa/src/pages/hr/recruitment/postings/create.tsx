import { useState, useEffect, type KeyboardEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AxiosError } from 'axios';
import { X } from 'lucide-react';
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
  requirements: z.string().min(1, 'At least one requirement is needed'),
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
  const [reqTags, setReqTags] = useState<string[]>([]);
  const [reqInput, setReqInput] = useState('');

  const {
    register,
    handleSubmit,
    setError,
    setValue,
    watch,
    formState: { errors },
  } = useForm<FormData>({
    resolver: zodResolver(schema),
    defaultValues: {
      employment_type: 'regular',
      slots: 1,
      show_salary: false,
      requirements: '',
    },
  });

  const departmentId = watch('department_id');

  const { data: departments } = useQuery({
    queryKey: ['departments'],
    queryFn: () => departmentsApi.list().then((r) => r.data ?? []),
  });

  const { data: positionsData } = useQuery({
    queryKey: ['positions', departmentId],
    queryFn: () => positionsApi.list({ department_id: departmentId }).then((r) => r.data ?? []),
    enabled: !!departmentId,
  });
  const positions = departmentId ? (positionsData ?? []) : [];

  useEffect(() => {
    setValue('position_id', '');
  }, [departmentId, setValue]);

  useEffect(() => {
    setValue('requirements', reqTags.join('\n'));
  }, [reqTags, setValue]);

  const addTag = () => {
    const val = reqInput.trim();
    if (val && !reqTags.includes(val)) {
      setReqTags((prev) => [...prev, val]);
    }
    setReqInput('');
  };

  const removeTag = (index: number) => {
    setReqTags((prev) => prev.filter((_, i) => i !== index));
  };

  const handleReqKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      addTag();
    }
  };

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

      <form onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormData>())} className="max-w-5xl mx-auto px-5 py-6 space-y-4">
        <Panel title="Job Details">
          <div className="space-y-4">
            <Input label="Job Title" required {...register('title')} placeholder="e.g. Injection Molding Operator" error={errors.title?.message} />

            <div className="grid gap-4 grid-cols-2 lg:grid-cols-3">
              <Select label="Department" required {...register('department_id')} error={errors.department_id?.message}>
                <option value="">Select department</option>
                {departments?.map((d: any) => (
                  <option key={d.id} value={d.id}>{d.name}</option>
                ))}
              </Select>

              <Select label="Position" {...register('position_id')} disabled={!departmentId}>
                <option value="">{departmentId ? 'Select position' : 'Select department first'}</option>
                {positions.map((p: any) => (
                  <option key={p.id} value={p.id}>{p.title}</option>
                ))}
              </Select>

              <Select label="Employment Type" required {...register('employment_type')}>
                <option value="regular">Regular</option>
                <option value="probationary">Probationary</option>
                <option value="contractual">Contractual</option>
                <option value="project_based">Project-Based</option>
              </Select>
            </div>

            <div className="grid gap-4 grid-cols-2 lg:grid-cols-4">
              <Input label="Salary Min" {...register('salary_range_min')} type="number" step="0.01" placeholder="0.00" prefix="₱" />
              <Input label="Salary Max" {...register('salary_range_max')} type="number" step="0.01" placeholder="0.00" prefix="₱" />
              <Input label="Slots" {...register('slots')} type="number" min={1} max={100} />
              <Input label="Deadline" {...register('closes_at')} type="date" />
            </div>

            <div className="flex items-center gap-2">
              <input type="checkbox" id="show_salary" {...register('show_salary')} className="h-4 w-4 rounded border-default text-accent" />
              <label htmlFor="show_salary" className="text-sm">Show salary range on public listing</label>
            </div>
          </div>
        </Panel>

        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <Panel title={<>Description <span className="text-danger ml-0.5">*</span></>}>
            <Textarea required {...register('description')} rows={8} placeholder="Describe the role, responsibilities, and day-to-day work..." error={errors.description?.message} />
          </Panel>

          <Panel title={<>Requirements <span className="text-danger ml-0.5">*</span></>}>
            <div className="space-y-3">
              <input type="hidden" {...register('requirements')} />
              <div className="flex gap-2">
                <Input
                  value={reqInput}
                  onChange={(e) => setReqInput(e.target.value)}
                  onKeyDown={handleReqKeyDown}
                  placeholder="Type and press Enter to add"
                  containerClassName="flex-1"
                />
                <div className="flex items-end">
                  <Button type="button" variant="secondary" size="sm" onClick={addTag} disabled={!reqInput.trim()}>
                    Add
                  </Button>
                </div>
              </div>

              {errors.requirements && reqTags.length === 0 && (
                <span className="text-xs text-danger">{errors.requirements.message}</span>
              )}

              {reqTags.length > 0 ? (
                <ul className="space-y-1.5">
                  {reqTags.map((tag, i) => (
                    <li
                      key={i}
                      className="flex items-center justify-between rounded-md border border-default bg-elevated px-3 py-2 text-sm"
                    >
                      <span>{tag}</span>
                      <button
                        type="button"
                        onClick={() => removeTag(i)}
                        className="ml-2 text-muted hover:text-danger transition-colors shrink-0"
                      >
                        <X size={14} />
                      </button>
                    </li>
                  ))}
                </ul>
              ) : (
                !errors.requirements && (
                  <p className="text-xs text-muted">Press Enter or click Add after typing each requirement.</p>
                )
              )}
            </div>
          </Panel>
        </div>

        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="secondary" onClick={() => navigate('/hr/recruitment/postings')}>
            Cancel
          </Button>
          <Button type="submit" variant="primary" disabled={mutation.isPending} loading={mutation.isPending}>
            {mutation.isPending ? 'Creating…' : 'Create Posting'}
          </Button>
        </div>
      </form>
    </div>
  );
}
