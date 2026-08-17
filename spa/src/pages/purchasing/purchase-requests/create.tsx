import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useForm, useFieldArray } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { LuPlus, LuTrash2 } from '@/lib/icons';
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
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { cn } from '@/lib/cn';
import type { PurchaseRequestPriority } from '@/types/purchasing';
import { formatPeso } from '@/lib/formatNumber';

import { useFormSafety } from '@/hooks/useFormSafety';
import { FormDraftBanner } from '@/components/ui/FormDraftBanner';
const lineSchema = z.object({
  item_id: z.string().optional().or(z.literal('')),
  description: z.string().trim().min(2, 'Description is required.').max(200),
  quantity: z
    .string()
    .regex(/^\d+(\.\d{1,2})?$/, 'Up to 2 decimals.')
    .refine((v) => Number(v) > 0, 'Must be > 0.'),
  unit: z.string().max(20).optional().or(z.literal('')),
  estimated_unit_price: z
    .string()
    .regex(/^(\d+(\.\d{1,2})?)?$/, 'Up to 2 decimals.')
    .optional()
    .or(z.literal('')),
  purpose: z.string().max(200).optional().or(z.literal('')),
});

const schema = z.object({
  priority: z.string().min(1, 'Priority is required.'),
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

    const form = useForm<V>({
    resolver: zodResolver(schema),
    defaultValues: {
      priority: '',
      reason: '',
      items: [
        {
          description: '',
          quantity: '',
          unit: '',
          estimated_unit_price: '',
          purpose: '',
          item_id: '',
        },
      ],
    },
  });
  const {
    register,
    handleSubmit,
    setError,
    control,
    watch,
    setValue,
    formState: { errors, isSubmitting },
  } = form;
  const { fields, append, remove } = useFieldArray({ control, name: 'items' });
  const watched = watch('items');
  const total = watched.reduce(
    (sum, l) => sum + Number(l.quantity || 0) * Number(l.estimated_unit_price || 0),
    0,
  );

  // Catalog lines inherit description / unit / est. price from the selected
  // Item record — all locked. Only ad-hoc lines (no item) are free-form.
  const itemById = useMemo(
    () => new Map((items.data?.data ?? []).map((it) => [it.id, it])),
    [items.data],
  );
  const onLineItemChange = (index: number, itemId: string) => {
    setValue(`items.${index}.item_id`, itemId);
    const item = itemById.get(itemId);
    if (item) {
      setValue(`items.${index}.description`, item.description || item.name);
      setValue(`items.${index}.unit`, item.unit_of_measure);
      setValue(`items.${index}.estimated_unit_price`, item.standard_cost);
    } else {
      setValue(`items.${index}.unit`, '');
    }
  };

  const create = useMutation({
    mutationFn: ({ values, submit }: { values: V; submit: boolean }) =>
      purchaseRequestsApi
        .create({
          reason: values.reason?.trim() || undefined,
          priority: values.priority as PurchaseRequestPriority,
          items: values.items.map((l) => ({
            item_id: l.item_id || null,
            description: l.description.trim(),
            quantity: l.quantity,
            unit: l.unit || undefined,
            estimated_unit_price: l.estimated_unit_price || undefined,
            purpose: l.purpose?.trim() || undefined,
          })),
        })
        .then(async (pr) => {
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
  const safety = useFormSafety({ form, saved: create.isSuccess });

  const onValid = (values: V, submit: boolean) => {
    if (submit) {
      setPendingDraft(values);
      setConfirmSubmit(true);
    } else {
      create.mutate({ values, submit: false });
    }
  };

  const { data: requestOptions } = useQuery({
    queryKey: ['purchasing', 'purchase-request-options'],
    queryFn: () => purchaseRequestsApi.options(),
  });
  const priorities = requestOptions?.priorities ?? [];

  return (
    <div>
      <PageHeader
        title="New purchase request"
        backTo="/purchasing/purchase-requests"
        backLabel="Purchase requests"
      />
      <FormDraftBanner safety={safety} />
      <form
        onSubmit={handleSubmit((d) => onValid(d, true), onFormInvalid<V>())}
        className="max-w-5xl mx-auto px-5 py-4 space-y-4"
      >
        <Panel title="Header">
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            <Select
              label="Priority"
              required
              {...register('priority')}
              error={errors.priority?.message}
            >
              <option value="">— Select —</option>
              {priorities.map((priority) => (
                <option key={priority.value} value={priority.value}>
                  {priority.label}
                </option>
              ))}
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
              icon={<LuPlus size={12} />}
              onClick={() =>
                append({
                  description: '',
                  quantity: '',
                  unit: '',
                  estimated_unit_price: '',
                  purpose: '',
                  item_id: '',
                })
              }
            >
              Add line
            </Button>
          }
        >
          {errors.items?.root && (
            <div className="text-xs text-danger-fg mb-2">{errors.items.root.message}</div>
          )}
          <table className={tableCls}>
            <thead>
              <tr className={theadTrCls}>
                <Th>Item</Th>
                <Th>Description</Th>
                <Th align="right">Qty</Th>
                <Th>Unit</Th>
                <Th align="right">Est. unit price</Th>
                <Th align="right">Total</Th>
                <Th />
              </tr>
            </thead>
            <tbody>
              {fields.map((f, i) => (
                <tr key={f.id} className={cn(trCls, 'align-top')}>
                  <Td>
                    <Select
                      fieldSize="sm"
                      containerClassName="w-32"
                      className="font-mono"
                      aria-label="Item"
                      value={watched[i]?.item_id ?? ''}
                      onChange={(e) => onLineItemChange(i, e.target.value)}
                    >
                      <option value="">— ad hoc —</option>
                      {items.data?.data.map((it) => (
                        <option key={it.id} value={it.id}>
                          {it.code} — {it.name}
                        </option>
                      ))}
                    </Select>
                  </Td>
                  <Td>
                    <Input
                      fieldSize="sm"
                      aria-label="Description"
                      {...register(`items.${i}.description` as const)}
                      readOnly={!!watched[i]?.item_id}
                      title={
                        watched[i]?.item_id
                          ? 'Description is copied from the selected item'
                          : 'Editable for ad-hoc lines only'
                      }
                      error={errors.items?.[i]?.description?.message}
                    />
                  </Td>
                  <Td align="right" mono>
                    <Input
                      fieldSize="sm"
                      containerClassName="w-20 inline-flex"
                      className="text-right font-mono tabular-nums"
                      aria-label="Quantity"
                      {...numberInputProps()}
                      {...register(`items.${i}.quantity` as const)}
                      error={errors.items?.[i]?.quantity?.message}
                    />
                  </Td>
                  <Td>
                    <Input
                      fieldSize="sm"
                      containerClassName="w-16"
                      aria-label="Unit"
                      value={watched[i]?.unit ?? ''}
                      onChange={(e) => setValue(`items.${i}.unit`, e.target.value)}
                      readOnly={!!watched[i]?.item_id}
                      title={
                        watched[i]?.item_id
                          ? 'Unit is copied from the selected item'
                          : 'Editable for ad-hoc lines only'
                      }
                    />
                  </Td>
                  <Td align="right" mono>
                    <Input
                      fieldSize="sm"
                      containerClassName="w-24 inline-flex"
                      className="text-right font-mono tabular-nums"
                      aria-label="Estimated unit price"
                      {...numberInputProps()}
                      {...register(`items.${i}.estimated_unit_price` as const)}
                      readOnly={!!watched[i]?.item_id}
                      title={
                        watched[i]?.item_id
                          ? 'Est. price is copied from the item standard cost'
                          : 'Editable for ad-hoc lines only'
                      }
                    />
                  </Td>
                  <Td align="right" mono>
                    {(
                      Number(watched[i]?.quantity || 0) *
                      Number(watched[i]?.estimated_unit_price || 0)
                    ).toFixed(2)}
                  </Td>
                  <Td align="right" mono>
                    {fields.length > 1 && (
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        iconOnly
                        icon={<LuTrash2 size={12} />}
                        onClick={() => remove(i)}
                        aria-label="Remove line"
                        className="text-muted hover:text-danger-fg"
                      />
                    )}
                  </Td>
                </tr>
              ))}
              <tr className={cn(trCls, 'font-medium')}>
                <Td align="right" mono className="uppercase text-2xs tracking-wider" colSpan={5}>
                  Estimated total
                </Td>
                <Td align="right" mono>
                  {formatPeso(total)}
                </Td>
                <Td />
              </tr>
            </tbody>
          </table>
        </Panel>
        <div className="flex justify-end gap-2">
          <Button
            type="button"
            variant="secondary"
            onClick={() => nav('/purchasing/purchase-requests')}
            disabled={create.isPending}
          >
            Cancel
          </Button>
          <Button
            type="button"
            variant="secondary"
            disabled={create.isPending || isSubmitting}
            onClick={handleSubmit((d) => onValid(d, false), onFormInvalid<V>())}
          >
            Save draft
          </Button>
          <Button
            type="submit"
            variant="primary"
            loading={create.isPending}
            disabled={create.isPending || isSubmitting}
          >
            Submit for approval
          </Button>
        </div>
      </form>

      <ConfirmDialog
        isOpen={confirmSubmit}
        onClose={() => setConfirmSubmit(false)}
        onConfirm={() => {
          if (pendingDraft) create.mutate({ values: pendingDraft, submit: true });
        }}
        title="Submit PR for approval?"
        description={
          pendingDraft ? (
            <>
              The PR will enter the approval workflow immediately. Edits are not allowed once
              submitted.
              {pendingDraft.priority === 'critical' && (
                <span className="block mt-1 text-warning-fg">
                  Critical priority bypasses some approval steps and notifies VP directly.
                </span>
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
