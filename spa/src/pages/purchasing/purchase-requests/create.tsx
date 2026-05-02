import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useForm, useFieldArray } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Plus, Trash2 } from 'lucide-react';
import toast from 'react-hot-toast';
import { purchaseRequestsApi } from '@/api/purchasing/purchase-requests';
import { itemsApi } from '@/api/inventory/items';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { PageHeader } from '@/components/layout/PageHeader';
import { applyServerValidationErrors, onFormInvalid } from '@/lib/formErrors';
import { numberInputProps } from '@/lib/numberInput';

const lineSchema = z.object({
  item_id: z.string().optional().or(z.literal('')),
  description: z.string().trim().min(2, 'Description is required.').max(200),
  quantity: z.string().regex(/^\d+(\.\d{1,2})?$/, 'Up to 2 decimals.').refine(v => Number(v) > 0, 'Must be > 0.'),
  unit: z.string().max(20).optional().or(z.literal('')),
  estimated_unit_price: z.string().regex(/^(\d+(\.\d{1,2})?)?$/, 'Up to 2 decimals.').optional().or(z.literal('')),
  purpose: z.string().max(200).optional().or(z.literal('')),
});

const schema = z.object({
  priority: z.enum(['normal', 'urgent', 'critical']),
  reason: z.string().max(1000).optional().or(z.literal('')),
  items: z.array(lineSchema).min(1, 'Add at least one line.'),
});
type V = z.infer<typeof schema>;

export default function CreatePurchaseRequestPage() {
  const nav = useNavigate();
  const [confirmSubmit, setConfirmSubmit] = useState(false);
  const [pendingDraft, setPendingDraft] = useState<V | null>(null);

  const items = useQuery({
    queryKey: ['inventory', 'items', { per_page: 200, is_active: 'true' }],
    queryFn: () => itemsApi.list({ per_page: 200, is_active: 'true' }),
  });

  const { register, handleSubmit, setError, control, watch, formState: { errors, isSubmitting } } = useForm<V>({
    resolver: zodResolver(schema),
    defaultValues: {
      priority: 'normal',
      reason: '',
      items: [{ description: '', quantity: '1', unit: 'pcs', estimated_unit_price: '0', purpose: '', item_id: '' }],
    },
  });
  const { fields, append, remove } = useFieldArray({ control, name: 'items' });
  const watched = watch('items');
  const total = watched.reduce((sum, l) => sum + Number(l.quantity || 0) * Number(l.estimated_unit_price || 0), 0);

  const create = useMutation({
    mutationFn: ({ values, submit }: { values: V; submit: boolean }) => purchaseRequestsApi.create({
      reason: values.reason?.trim() || undefined,
      priority: values.priority,
      items: values.items.map((l) => ({
        item_id: l.item_id || null,
        description: l.description.trim(),
        quantity: l.quantity,
        unit: l.unit || undefined,
        estimated_unit_price: l.estimated_unit_price || undefined,
        purpose: l.purpose?.trim() || undefined,
      })),
    }).then(async (pr) => {
      if (submit) await purchaseRequestsApi.submit(pr.id);
      return pr;
    }),
    onSuccess: (pr) => {
      toast.success(`PR ${pr.pr_number} created.`);
      nav(`/purchasing/purchase-requests/${pr.id}`);
    },
    onError: (e) => {
      setConfirmSubmit(false);
      applyServerValidationErrors(e, setError, 'Failed to create PR.');
    },
  });

  const onValid = (values: V, submit: boolean) => {
    if (submit) {
      setPendingDraft(values);
      setConfirmSubmit(true);
    } else {
      create.mutate({ values, submit: false });
    }
  };

  return (
    <div>
      <PageHeader title="New purchase request" backTo="/purchasing/purchase-requests" backLabel="Purchase requests" />
      <form
        onSubmit={handleSubmit((d) => onValid(d, true), onFormInvalid<V>())}
        className="max-w-5xl mx-auto px-5 py-6 space-y-4"
      >
        <Panel title="Header">
          <div className="grid grid-cols-3 gap-3">
            <Select label="Priority" required {...register('priority')} error={errors.priority?.message}>
              <option value="normal">Normal</option>
              <option value="urgent">Urgent</option>
              <option value="critical">Critical</option>
            </Select>
            <Textarea
              label="Reason"
              rows={2}
              className="col-span-2"
              maxLength={1000}
              placeholder="What is this PR for?"
              {...register('reason')}
              error={errors.reason?.message}
            />
          </div>
        </Panel>
        <Panel
          title="Line items"
          actions={
            <Button
              type="button"
              variant="secondary"
              size="sm"
              icon={<Plus size={12} />}
              onClick={() => append({ description: '', quantity: '1', unit: 'pcs', estimated_unit_price: '0', purpose: '', item_id: '' })}
            >
              Add line
            </Button>
          }
        >
          {errors.items?.root && (
            <div className="text-xs text-danger-fg mb-2">{errors.items.root.message}</div>
          )}
          <table className="w-full text-xs">
            <thead>
              <tr className="text-2xs uppercase tracking-wider text-muted">
                <th className="text-left py-1 font-medium">Item</th>
                <th className="text-left font-medium">Description</th>
                <th className="text-right font-medium">Qty</th>
                <th className="font-medium">Unit</th>
                <th className="text-right font-medium">Est. unit price</th>
                <th className="text-right font-medium">Total</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {fields.map((f, i) => (
                <tr key={f.id} className="h-9 border-t border-subtle align-top">
                  <td className="py-1.5">
                    <select
                      className="h-7 w-32 px-1 rounded-sm border border-default text-2xs font-mono"
                      {...register(`items.${i}.item_id` as const)}
                    >
                      <option value="">— ad hoc —</option>
                      {items.data?.data.map((it) => (
                        <option key={it.id} value={it.id}>{it.code}</option>
                      ))}
                    </select>
                  </td>
                  <td>
                    <Input className="h-7" {...register(`items.${i}.description` as const)} error={errors.items?.[i]?.description?.message} />
                  </td>
                  <td className="text-right">
                    <input
                      className="h-7 w-20 px-2 rounded-sm border border-default text-right font-mono tabular-nums text-xs"
                      type="text"
                      {...numberInputProps()}
                      {...register(`items.${i}.quantity` as const)}
                    />
                    {errors.items?.[i]?.quantity && <div className="text-2xs text-danger-fg mt-0.5">{errors.items[i]?.quantity?.message}</div>}
                  </td>
                  <td>
                    <input
                      className="h-7 w-16 px-2 rounded-sm border border-default text-xs"
                      {...register(`items.${i}.unit` as const)}
                    />
                  </td>
                  <td className="text-right">
                    <input
                      className="h-7 w-24 px-2 rounded-sm border border-default text-right font-mono tabular-nums text-xs"
                      type="text"
                      {...numberInputProps()}
                      {...register(`items.${i}.estimated_unit_price` as const)}
                    />
                  </td>
                  <td className="text-right font-mono tabular-nums pt-1">
                    {(Number(watched[i]?.quantity || 0) * Number(watched[i]?.estimated_unit_price || 0)).toFixed(2)}
                  </td>
                  <td className="text-right pt-1">
                    {fields.length > 1 && (
                      <button
                        type="button"
                        onClick={() => remove(i)}
                        className="p-1 text-text-muted hover:text-danger hover:bg-elevated rounded-sm"
                        aria-label="Remove line"
                      >
                        <Trash2 size={12} />
                      </button>
                    )}
                  </td>
                </tr>
              ))}
              <tr className="border-t border-default font-medium">
                <td colSpan={5} className="text-right py-2 uppercase text-2xs tracking-wider">Estimated total</td>
                <td className="text-right font-mono tabular-nums">₱ {total.toFixed(2)}</td>
                <td />
              </tr>
            </tbody>
          </table>
        </Panel>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={() => nav('/purchasing/purchase-requests')} disabled={create.isPending}>Cancel</Button>
          <Button
            type="button"
            variant="secondary"
            disabled={create.isPending || isSubmitting}
            onClick={handleSubmit((d) => onValid(d, false), onFormInvalid<V>())}
          >
            Save draft
          </Button>
          <Button type="submit" variant="primary" loading={create.isPending} disabled={create.isPending || isSubmitting}>
            Submit for approval
          </Button>
        </div>
      </form>

      <ConfirmDialog
        isOpen={confirmSubmit}
        onClose={() => setConfirmSubmit(false)}
        onConfirm={() => { if (pendingDraft) create.mutate({ values: pendingDraft, submit: true }); }}
        title="Submit PR for approval?"
        description={
          pendingDraft ? (
            <>
              The PR will enter the approval workflow immediately. Edits are not allowed once submitted.
              {pendingDraft.priority === 'critical' && (
                <span className="block mt-1 text-warning-fg">Critical priority bypasses some approval steps and notifies VP directly.</span>
              )}
            </>
          ) : null
        }
        confirmLabel="Submit"
        variant="primary"
        pending={create.isPending}
      />
    </div>
  );
}
