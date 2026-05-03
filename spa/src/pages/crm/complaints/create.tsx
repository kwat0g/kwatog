/** Sprint 7 — Task 68 — File a customer complaint. */
import { useForm } from 'react-hook-form';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { z } from 'zod';
import { zodResolver } from '@hookform/resolvers/zod';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import { complaintsApi } from '@/api/crm/complaints';
import { customersApi } from '@/api/accounting/customers';
import { productsApi } from '@/api/crm/products';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { applyServerValidationErrors } from '@/lib/formErrors';
import type { CreateComplaintData, ComplaintSeverity } from '@/types/crm';

const schema = z.object({
  customer_id: z.string().min(1, 'Customer is required'),
  product_id: z.string().optional().or(z.literal('')),
  sales_order_id: z.string().optional().or(z.literal('')),
  received_date: z.string().min(1, 'Received date is required'),
  severity: z.enum(['low', 'medium', 'high', 'critical']),
  description: z.string().min(1, 'Description is required').max(5000),
  affected_quantity: z.coerce.number().int().min(0).default(0),
});

type FormValues = z.infer<typeof schema>;

export default function CreateComplaintPage() {
  const navigate = useNavigate();

  const customers = useQuery({
    queryKey: ['accounting', 'customers', { per_page: 200 }],
    queryFn: () => customersApi.list({ per_page: 200 }),
  });
  const products = useQuery({
    queryKey: ['crm', 'products', { is_active: true, per_page: 200 }],
    queryFn: () => productsApi.list({ is_active: true, per_page: 200 }),
  });

  const {
    register, handleSubmit, setError, formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      customer_id: '',
      product_id: '',
      sales_order_id: '',
      received_date: new Date().toISOString().slice(0, 10),
      severity: 'medium',
      description: '',
      affected_quantity: 0,
    },
  });

  const submit = useMutation({
    mutationFn: (data: CreateComplaintData) => complaintsApi.create(data),
    onSuccess: (c) => {
      toast.success(`Complaint ${c.complaint_number} opened${c.ncr ? `; NCR ${c.ncr.ncr_number} auto-created` : ''}`);
      navigate(`/crm/complaints/${c.id}`);
    },
    onError: (e: AxiosError<{ message?: string; errors?: Record<string, string[]> }>) => {
      if (e.response?.data?.errors) {
        applyServerValidationErrors(setError as never, e.response.data.errors);
        toast.error('Please fix the errors below.');
      } else {
        toast.error(e.response?.data?.message ?? 'Failed to open complaint');
      }
    },
  });

  return (
    <div>
      <PageHeader title="File complaint" subtitle="An NCR will be auto-created on submit." />
      <form
        onSubmit={handleSubmit((v) =>
          submit.mutate({
            customer_id: v.customer_id,
            product_id: v.product_id || null,
            sales_order_id: v.sales_order_id || null,
            received_date: v.received_date,
            severity: v.severity as ComplaintSeverity,
            description: v.description,
            affected_quantity: Number(v.affected_quantity),
          })
        )}
        className="px-5 py-4 max-w-3xl"
      >
        <div className="space-y-4">
          <Panel title="Subject">
            <div className="grid grid-cols-2 gap-3">
              <Select label="Customer" required {...register('customer_id')} error={errors.customer_id?.message}>
                <option value="">Select…</option>
                {customers.data?.data.map((c) => (
                  <option key={c.id} value={c.id}>{c.name}</option>
                ))}
              </Select>
              <Select label="Product (optional)" {...register('product_id')} error={errors.product_id?.message}>
                <option value="">— None —</option>
                {products.data?.data.map((p) => (
                  <option key={p.id} value={p.id}>{p.part_number} — {p.name}</option>
                ))}
              </Select>
            </div>
          </Panel>

          <Panel title="Classification">
            <div className="grid grid-cols-3 gap-3">
              <Input label="Received date" type="date" required
                {...register('received_date')} error={errors.received_date?.message} />
              <Select label="Severity" required {...register('severity')} error={errors.severity?.message}>
                <option value="low">Low</option>
                <option value="medium">Medium</option>
                <option value="high">High</option>
                <option value="critical">Critical</option>
              </Select>
              <Input label="Affected quantity" type="number" min={0}
                {...register('affected_quantity')} error={errors.affected_quantity?.message} />
            </div>
          </Panel>

          <Panel title="Description">
            <Textarea label="Customer complaint" required rows={6}
              {...register('description')} error={errors.description?.message} />
          </Panel>

          <div className="flex items-center justify-end gap-2 pt-4 border-t border-default">
            <Button variant="secondary" type="button" onClick={() => navigate(-1)}>Cancel</Button>
            <Button variant="primary" type="submit" loading={submit.isPending}>
              {submit.isPending ? 'Opening...' : 'Open complaint'}
            </Button>
          </div>
        </div>
      </form>
    </div>
  );
}
