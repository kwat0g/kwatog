/**
 * Sprint 7 — Task 60 — Open a new inspection.
 *
 * Picks product + stage + batch quantity. For outgoing batches the AQL
 * Level II 0.65 sample plan is shown live as the user types the batch
 * size. On submit, the backend seeds (sample × spec_item) measurement
 * rows and the user is redirected to the detail page to record values.
 */
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { z } from 'zod';
import { zodResolver } from '@hookform/resolvers/zod';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import { inspectionsApi } from '@/api/quality/inspections';
import { productsApi } from '@/api/crm/products';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import type { CreateInspectionData, InspectionStage, AqlPlan } from '@/types/quality';

const schema = z.object({
  stage: z.enum(['incoming', 'in_process', 'outgoing']),
  product_id: z.string().min(1, 'Product is required'),
  batch_quantity: z.coerce.number().int().min(1, 'Must be at least 1'),
  notes: z.string().max(2000).optional(),
});

type FormValues = z.infer<typeof schema>;

export default function CreateInspectionPage() {
  const navigate = useNavigate();
  const [aqlPlan, setAqlPlan] = useState<AqlPlan | null>(null);

  const {
    register, handleSubmit, watch, formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { stage: 'outgoing', product_id: '', batch_quantity: 100, notes: '' },
  });

  const stage = watch('stage');
  const batchQty = watch('batch_quantity');

  // Live preview AQL sample plan only for outgoing.
  useQuery({
    queryKey: ['quality', 'aql-preview', stage, batchQty],
    queryFn: async () => {
      if (stage !== 'outgoing' || !batchQty || batchQty < 1) {
        setAqlPlan(null);
        return null;
      }
      const plan = await inspectionsApi.aqlPreview(Number(batchQty));
      setAqlPlan(plan);
      return plan;
    },
    enabled: stage === 'outgoing' && Number(batchQty) > 0,
  });

  const products = useQuery({
    queryKey: ['crm', 'products', { is_active: true, per_page: 200 }],
    queryFn: () => productsApi.list({ is_active: true, per_page: 200 }),
  });

  const submit = useMutation({
    mutationFn: (data: CreateInspectionData) => inspectionsApi.create(data),
    onSuccess: (insp) => {
      toast.success(`Inspection ${insp.inspection_number} opened`);
      navigate(`/quality/inspections/${insp.id}`);
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Failed to open inspection');
    },
  });

  return (
    <div>
      <PageHeader title="Open inspection" subtitle="Sample plan is computed when stage is outgoing" />
      <form
        onSubmit={handleSubmit((v) =>
          submit.mutate({
            stage: v.stage as InspectionStage,
            product_id: v.product_id,
            batch_quantity: Number(v.batch_quantity),
            notes: v.notes,
          })
        )}
        className="px-5 py-4 grid grid-cols-3 gap-4"
      >
        <div className="col-span-2 space-y-4">
          <Panel title="Inspection details">
            <div className="grid grid-cols-2 gap-3">
              <Select label="Stage" required {...register('stage')} error={errors.stage?.message}>
                <option value="incoming">Incoming (GRN)</option>
                <option value="in_process">In-process (Work order)</option>
                <option value="outgoing">Outgoing (Delivery)</option>
              </Select>
              <Select label="Product" required {...register('product_id')} error={errors.product_id?.message}>
                <option value="">Select…</option>
                {products.data?.data.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.part_number} — {p.name}
                  </option>
                ))}
              </Select>
              <Input
                label="Batch quantity"
                type="number"
                min={1}
                required
                {...register('batch_quantity')}
                error={errors.batch_quantity?.message}
              />
            </div>
            <Textarea label="Notes" rows={3} {...register('notes')} error={errors.notes?.message} />
          </Panel>
        </div>

        <div>
          <Panel title="Sample plan" meta={stage === 'outgoing' ? 'AQL 0.65, Level II' : '100% inspection'}>
            {stage === 'outgoing' ? (
              aqlPlan ? (
                <dl className="space-y-2 text-sm">
                  <div className="flex justify-between">
                    <dt className="text-muted">Code letter</dt>
                    <dd className="font-mono tabular-nums">{aqlPlan.code}</dd>
                  </div>
                  <div className="flex justify-between">
                    <dt className="text-muted">Sample size</dt>
                    <dd className="font-mono tabular-nums">{aqlPlan.sample_size}</dd>
                  </div>
                  <div className="flex justify-between">
                    <dt className="text-muted">Accept (Ac)</dt>
                    <dd className="font-mono tabular-nums">{aqlPlan.accept}</dd>
                  </div>
                  <div className="flex justify-between">
                    <dt className="text-muted">Reject (Re)</dt>
                    <dd className="font-mono tabular-nums">{aqlPlan.reject}</dd>
                  </div>
                </dl>
              ) : (
                <p className="text-xs text-muted">Enter a batch quantity to preview the plan.</p>
              )
            ) : (
              <p className="text-xs text-muted">
                Incoming and in-process inspections sample 100% of the batch by default.
              </p>
            )}
          </Panel>
        </div>

        <div className="col-span-3 flex items-center justify-end gap-2 border-t border-default pt-4">
          <Button variant="secondary" type="button" onClick={() => navigate(-1)}>
            Cancel
          </Button>
          <Button variant="primary" type="submit" loading={submit.isPending}>
            Open inspection
          </Button>
        </div>
      </form>
    </div>
  );
}
