/**
 * Task 16 — Create Controlled Document page.
 *
 * Form for registering a new controlled document in the quality system.
 * Fields: code, title, category, description, assignee role, review interval.
 */
import { useForm } from 'react-hook-form';
import { useMutation } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { z } from 'zod';
import { zodResolver } from '@hookform/resolvers/zod';
import toast from 'react-hot-toast';
import { onFormInvalid } from '@/lib/formErrors';
import type { AxiosError } from 'axios';
import { documentsApi } from '@/api/quality/documents';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import type { CreateDocumentData, DocumentCategory } from '@/types/quality/document';

const schema = z.object({
  code: z.string().min(1, 'Code is required').max(50),
  title: z.string().min(1, 'Title is required').max(255),
  category: z.enum(['sop', 'work_instruction', 'form', 'policy', 'specification'], {
    required_error: 'Category is required',
  }),
  description: z.string().max(2000).optional(),
  assignee_role: z.string().optional(),
  review_interval_months: z.coerce.number().int().min(1).max(120).optional().or(z.literal('')),
});

type FormValues = z.infer<typeof schema>;

const SEEDED_ROLES = [
  { value: 'system_admin', label: 'System Admin' },
  { value: 'hr_officer', label: 'HR Officer' },
  { value: 'finance_officer', label: 'Finance Officer' },
  { value: 'production_manager', label: 'Production Manager' },
  { value: 'ppc_head', label: 'PPC Head' },
  { value: 'purchasing_officer', label: 'Purchasing Officer' },
  { value: 'warehouse_staff', label: 'Warehouse Staff' },
  { value: 'qc_inspector', label: 'QC Inspector' },
  { value: 'maintenance_tech', label: 'Maintenance Tech' },
  { value: 'impex_officer', label: 'ImpEx Officer' },
  { value: 'department_head', label: 'Department Head' },
  { value: 'employee', label: 'Employee' },
  { value: 'driver', label: 'Driver' },
];

export default function DocumentCreatePage() {
  const navigate = useNavigate();

  const {
    register, handleSubmit, formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      code: '',
      title: '',
      category: 'sop',
      description: '',
      assignee_role: '',
      review_interval_months: 12,
    },
  });

  const submit = useMutation({
    mutationFn: (data: CreateDocumentData) => documentsApi.create(data),
    onSuccess: (doc) => {
      toast.success(`Document ${doc.code} created`);
      navigate('/quality/documents');
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Failed to create document');
    },
  });

  return (
    <div>
      <PageHeader
        title="New document"
        subtitle="Register a controlled document in the quality management system"
        breadcrumbs={[
          { label: 'Quality', href: '/quality' },
          { label: 'Documents', href: '/quality/documents' },
          { label: 'New' },
        ]}
      />
      <form
        onSubmit={handleSubmit((v) => {
          const payload: CreateDocumentData = {
            code: v.code,
            title: v.title,
            category: v.category as DocumentCategory,
            description: v.description || undefined,
            assignee_role: v.assignee_role || undefined,
            review_interval_months: v.review_interval_months
              ? Number(v.review_interval_months)
              : undefined,
          };
          submit.mutate(payload);
        }, onFormInvalid<FormValues>())}
        className="px-5 py-4 grid grid-cols-3 gap-4"
      >
        <div className="col-span-2 space-y-4">
          <Panel title="Document details">
            <div className="grid grid-cols-2 gap-3">
              <Input
                label="Code"
                required
                placeholder="e.g. SOP-001"
                {...register('code')}
                error={errors.code?.message}
              />
              <Input
                label="Title"
                required
                placeholder="e.g. Injection Molding SOP"
                {...register('title')}
                error={errors.title?.message}
              />
              <Select
                label="Category"
                required
                {...register('category')}
                error={errors.category?.message}
              >
                <option value="sop">SOP</option>
                <option value="work_instruction">Work Instruction</option>
                <option value="form">Form</option>
                <option value="policy">Policy</option>
                <option value="specification">Specification</option>
              </Select>
              <Select
                label="Assignee role"
                {...register('assignee_role')}
                error={errors.assignee_role?.message}
              >
                <option value="">None</option>
                {SEEDED_ROLES.map((r) => (
                  <option key={r.value} value={r.value}>{r.label}</option>
                ))}
              </Select>
              <Input
                label="Review interval (months)"
                type="number"
                min={1}
                max={120}
                placeholder="12"
                {...register('review_interval_months')}
                error={errors.review_interval_months?.message}
              />
            </div>
            <Textarea
              label="Description"
              rows={3}
              placeholder="Brief description of this document's purpose and scope..."
              {...register('description')}
              error={errors.description?.message}
            />
          </Panel>
        </div>

        <div>
          <Panel title="Preview" meta="Will be assigned on save">
            <p className="text-xs text-muted">
              After creating, you can publish revisions and upload document files from the detail page.
            </p>
          </Panel>
        </div>

        <div className="col-span-3 flex items-center justify-end gap-2 border-t border-default pt-4">
          <Button variant="secondary" type="button" onClick={() => navigate(-1)}>
            Cancel
          </Button>
          <Button variant="primary" type="submit" loading={submit.isPending}>
            Create document
          </Button>
        </div>
      </form>
    </div>
  );
}
