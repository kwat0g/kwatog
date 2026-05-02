/**
 * Sprint 6 — Task 55. Output recording form.
 * Subscribes to production.wo.{id} for live cumulative updates while the
 * supervisor is filling the form.
 */
import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useFieldArray, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AxiosError } from 'axios';
import { Plus, Trash2 } from 'lucide-react';
import toast from 'react-hot-toast';
import { workOrdersApi } from '@/api/production/workOrders';
import { client } from '@/api/client';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { PageHeader } from '@/components/layout/PageHeader';
import { useEcho } from '@/hooks/useEcho';
import type { DefectType } from '@/types/production';

const schema = z.object({
  good_count:   z.string().regex(/^\d+$/, 'Use a non-negative integer'),
  reject_count: z.string().regex(/^\d+$/, 'Use a non-negative integer'),
  shift:        z.string().optional().or(z.literal('')),
  remarks:      z.string().max(500).optional().or(z.literal('')),
  defects:      z.array(z.object({
    defect_type_id: z.string().min(1, 'Defect type required'),
    count:          z.string().regex(/^\d+$/, 'Use a positive integer').refine((v) => Number(v) > 0, 'Must be > 0'),
  })),
});
type FormValues = z.infer<typeof schema>;

export default function RecordOutputPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const [liveCumulative, setLiveCumulative] = useState<{ produced: number; good: number; reject: number; scrap: string } | null>(null);

  const wo = useQuery({
    queryKey: ['production', 'work-orders', 'detail', id],
    queryFn: () => workOrdersApi.show(id!),
    enabled: !!id,
  });

  const defects = useQuery({
    queryKey: ['production', 'defect-types'],
    queryFn: () => client.get<{ data: DefectType[] }>('/production/defect-types').then((r) => r.data.data).catch(() => [] as DefectType[]),
  });

  // Subscribe to live updates so the cumulative panel reflects every recording.
  useEcho<{ total_quantity_produced: number; total_quantity_good: number; total_quantity_rejected: number; scrap_rate: string }>(
    `production.wo.${id}`,
    '.output.recorded',
    (p) => {
      setLiveCumulative({
        produced: p.total_quantity_produced,
        good:     p.total_quantity_good,
        reject:   p.total_quantity_rejected,
        scrap:    p.scrap_rate,
      });
      qc.invalidateQueries({ queryKey: ['production', 'work-orders', 'detail', id] });
    },
  );

  const { register, control, handleSubmit, setError, reset, formState: { errors, isSubmitting } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { good_count: '0', reject_count: '0', shift: '', remarks: '', defects: [] },
  });
  const { fields, append, remove } = useFieldArray({ control, name: 'defects' });

  const submit = useMutation({
    mutationFn: async (values: FormValues) => {
      // Generate a UUID-ish idempotency key.
      const key = `${id}-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
      return workOrdersApi.recordOutput(id!, {
        good_count: Number(values.good_count),
        reject_count: Number(values.reject_count),
        shift: values.shift || undefined,
        remarks: values.remarks || undefined,
        defects: values.defects.map((d) => ({ defect_type_id: d.defect_type_id, count: Number(d.count) })),
      }, key);
    },
    onSuccess: (output) => {
      toast.success(`Output ${output.batch_code ?? ''} recorded.`);
      reset({ good_count: '0', reject_count: '0', shift: '', remarks: '', defects: [] });
    },
    onError: (e: AxiosError<{ message?: string; errors?: Record<string, string[]> }>) => {
      if (e.response?.status === 422 && e.response.data.errors) {
        Object.entries(e.response.data.errors).forEach(([field, msgs]) => {
          setError(field as never, { type: 'server', message: msgs[0] });
        });
        toast.error('Please fix the errors below.');
      } else {
        toast.error(e.response?.data?.message ?? 'Failed to record output.');
      }
    },
  });

  const cumulative = liveCumulative ?? (wo.data ? {
    produced: wo.data.quantity_produced,
    good:     wo.data.quantity_good,
    reject:   wo.data.quantity_rejected,
    scrap:    wo.data.scrap_rate,
  } : { produced: 0, good: 0, reject: 0, scrap: '0' });

  return (
    <div>
      <PageHeader
        title={`Record output${wo.data ? ` — ${wo.data.wo_number}` : ''}`}
        backTo={`/production/work-orders/${id}`}
        backLabel="Work order"
      />
      <div className="px-5 py-4 grid gap-4 lg:grid-cols-3">
        <div className="lg:col-span-2">
          <form onSubmit={handleSubmit((v) => submit.mutate(v))}>
            <Panel title="New recording">
              <div className="grid grid-cols-2 gap-3">
                <Input label="Good count" required type="number" min={0} {...register('good_count')}
                  error={errors.good_count?.message} className="font-mono text-right" />
                <Input label="Reject count" required type="number" min={0} {...register('reject_count')}
                  error={errors.reject_count?.message} className="font-mono text-right" />
                <Select label="Shift" {...register('shift')}>
                  <option value="">—</option>
                  <option value="day">Day</option>
                  <option value="night">Night</option>
                  <option value="office">Office</option>
                </Select>
                <div className="col-span-2">
                  <Textarea label="Remarks" rows={2} {...register('remarks')} error={errors.remarks?.message} />
                </div>
              </div>

              <div className="mt-4 border-t border-default pt-3">
                <div className="flex items-center justify-between mb-2">
                  <div className="text-2xs uppercase tracking-wider text-muted font-medium">Defect breakdown</div>
                  <Button type="button" variant="secondary" size="sm" icon={<Plus size={14} />}
                    onClick={() => append({ defect_type_id: '', count: '1' })}>
                    Add defect
                  </Button>
                </div>
                {fields.length === 0 && <div className="text-xs text-muted">No defect breakdown — sum of defect counts must equal reject_count.</div>}
                {fields.map((f, i) => (
                  <div key={f.id} className="flex items-end gap-2 mt-2">
                    <div className="flex-1">
                      <Select label={i === 0 ? 'Defect type' : ''} {...register(`defects.${i}.defect_type_id`)} error={errors.defects?.[i]?.defect_type_id?.message}>
                        <option value="">Select…</option>
                        {(defects.data ?? []).map((d) => (
                          <option key={d.id} value={d.id}>{d.code} — {d.name}</option>
                        ))}
                      </Select>
                    </div>
                    <Input
                      label={i === 0 ? 'Count' : ''} type="number" min={1}
                      {...register(`defects.${i}.count`)} error={errors.defects?.[i]?.count?.message}
                      className="w-24 font-mono text-right"
                    />
                    <button type="button" onClick={() => remove(i)} className="p-1.5 text-text-muted hover:text-danger" aria-label="Remove">
                      <Trash2 size={14} />
                    </button>
                  </div>
                ))}
              </div>

              <div className="mt-6 flex items-center justify-end gap-2 pt-3 border-t border-default">
                <Button type="button" variant="secondary" onClick={() => navigate(`/production/work-orders/${id}`)}>Back</Button>
                <Button type="submit" variant="primary" disabled={isSubmitting || submit.isPending} loading={submit.isPending}>
                  {submit.isPending ? 'Recording…' : 'Record'}
                </Button>
              </div>
            </Panel>
          </form>
        </div>

        <div className="space-y-4">
          <Panel title="Live cumulative" meta="updated via WebSocket">
            <dl className="space-y-2 text-sm">
              <div className="flex justify-between"><dt className="text-muted">Produced</dt><dd className="font-mono tabular-nums">{cumulative.produced.toLocaleString()}</dd></div>
              <div className="flex justify-between"><dt className="text-muted">Good</dt><dd className="font-mono tabular-nums text-success-fg">{cumulative.good.toLocaleString()}</dd></div>
              <div className="flex justify-between"><dt className="text-muted">Reject</dt><dd className="font-mono tabular-nums text-warning-fg">{cumulative.reject.toLocaleString()}</dd></div>
              <div className="flex justify-between"><dt className="text-muted">Scrap rate</dt><dd className="font-mono tabular-nums">{Number(cumulative.scrap).toFixed(2)}%</dd></div>
              {wo.data && (
                <div className="flex justify-between border-t border-default pt-2 mt-2">
                  <dt className="text-muted">Target</dt>
                  <dd className="font-mono tabular-nums">{wo.data.quantity_target.toLocaleString()}</dd>
                </div>
              )}
            </dl>
          </Panel>
        </div>
      </div>
    </div>
  );
}
