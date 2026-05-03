/**
 * Sprint 7 — Task 64 — NCR create page.
 *
 * Most NCRs are auto-opened from inspection failure; this page is for the
 * customer-complaint and supplier-issue paths where QC needs to file
 * manually.
 */
import { useForm } from 'react-hook-form';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { z } from 'zod';
import { zodResolver } from '@hookform/resolvers/zod';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import { ncrsApi } from '@/api/quality/ncrs';
import { productsApi } from '@/api/crm/products';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import type { CreateNcrData, NcrSeverity, NcrSource } from '@/types/quality';

const schema = z.object({
  source: z.enum(['inspection_fail', 'customer_complaint']),
  severity: z.enum(['low', 'medium', 'high', 'critical']),
  product_id: z.string().optional().or(z.literal('')),
  defect_description: z.string().min(1, 'Description is required').max(5000),
  affected_quantity: z.coerce.number().int().min(0).max(1000000).default(0),
});

type FormValues = z.infer<typeof schema>;

export default function CreateNcrPage() {
  const navigate = useNavigate();

  const products = useQuery({
    queryKey: ['crm', 'products', { is_active: true, per_page: 200 }],
    queryFn: () => productsApi.list({ is_active: true, per_page: 200 }),
  });

  const {
    register, handleSubmit, formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      source: 'customer_complaint',
      severity: 'medium',
      product_id: '',
      defect_description: '',
      affected_quantity: 0,
    },
  });

  const submit = useMutation({
    mutationFn: (data: CreateNcrData) => ncrsApi.create(data),
    onSuccess: (ncr) => {
      toast.success(`NCR ${ncr.ncr_number} opened`);
      navigate(`/quality/ncrs/${ncr.id}`);
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Failed to open NCR');
    },
  });

  return (
    <div>
      <PageHeader title="Open NCR" subtitle="Use this for customer complaints or supplier issues. Inspection failures auto-create NCRs." />
      <form
        onSubmit={handleSubmit((v) =>
          submit.mutate({
            source: v.source as NcrSource,
            severity: v.severity as NcrSeverity,
            product_id: v.product_id || null,
            defect_description: v.defect_description,
            affected_quantity: Number(v.affected_quantity),
          })
        )}
        className="px-5 py-4"
      >
        <div className="space-y-4 max-w-3xl">
          <Panel title="Classification">
            <div className="grid grid-cols-3 gap-3">
              <Select label="Source" required {...register('source')} error={errors.source?.message}>
                <option value="customer_complaint">Customer complaint</option>
                <option value="inspection_fail">Inspection fail</option>
              </Select>
              <Select label="Severity" required {...register('severity')} error={errors.severity?.message}>
                <option value="low">Low</option>
                <option value="medium">Medium</option>
                <option value="high">High</option>
                <option value="critical">Critical</option>
              </Select>
              <Input
                label="Affected quantity"
                type="number"
                min={0}
                {...register('affected_quantity')}
                error={errors.affected_quantity?.message}
              />
            </div>
          </Panel>

          <Panel title="Subject">
            <Select label="Product (optional)" {...register('product_id')} error={errors.product_id?.message}>
              <option value="">— None —</option>
              {products.data?.data.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.part_number} — {p.name}
                </option>
              ))}
            </Select>
            <Textarea
              label="Defect description"
              required
              rows={6}
              {...register('defect_description')}
              error={errors.defect_description?.message}
            />
          </Panel>

          <div className="flex items-center justify-end gap-2 pt-4 border-t border-default">
            <Button variant="secondary" type="button" onClick={() => navigate(-1)}>
              Cancel
            </Button>
            <Button variant="primary" type="submit" loading={submit.isPending}>
              Open NCR
            </Button>
          </div>
        </div>
      </form>
    </div>
  );
}
