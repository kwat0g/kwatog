import { useMemo } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useParams } from 'react-router-dom';
import { useFieldArray, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { LuPlus, LuTrash2 } from '@/lib/icons';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { prTemplatesApi } from '@/api/purchasing/purchase-requests';
import { itemsApi } from '@/api/inventory/items';
import { departmentsApi } from '@/api/hr/departments';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { onFormInvalid } from '@/lib/formErrors';
import type { ApiValidationError } from '@/types';

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
});

const schema = z.object({
  name: z.string().trim().min(1, 'Template name is required.').max(200),
  notes: z.string().max(1000).optional().or(z.literal('')),
  department_id: z.string().optional().or(z.literal('')),
  items: z.array(lineSchema).min(1, 'Add at least one line.'),
});
type FormValues = z.infer<typeof schema>;

export default function PrTemplateFormPage() {
  const { id: paramId } = useParams<{ id: string }>();
  const isEdit = paramId !== undefined && paramId !== 'create';
  const templateId = isEdit ? paramId : null;
  const navigate = useNavigate();
  const qc = useQueryClient();

  const { data: template } = useQuery({
    queryKey: ['purchasing', 'pr-templates', templateId],
    queryFn: () => prTemplatesApi.show(templateId!),
    enabled: isEdit && !!templateId,
  });

  const { data: itemsData } = useQuery({
    queryKey: ['inventory', 'items', 'select'],
    queryFn: () => itemsApi.list({ per_page: 500 }),
  });

  const { data: deptsData } = useQuery({
    queryKey: ['hr', 'departments', 'select'],
    queryFn: () => departmentsApi.list({ per_page: 500 }),
  });

  const {
    register,
    handleSubmit,
    setError,
    control,
    watch,
    setValue,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      name: '',
      notes: '',
      department_id: '',
      items: [{ item_id: '', description: '', quantity: '', unit: '', estimated_unit_price: '' }],
    },
    values:
      isEdit && template
        ? {
            name: template.name,
            notes: template.notes ?? '',
            department_id: template.department?.id ?? '',
            items: template.items.map((i) => ({
              item_id: i.item_id ?? '',
              description: i.description,
              quantity: i.quantity,
              unit: i.unit ?? '',
              estimated_unit_price: i.estimated_unit_price ?? '',
            })),
          }
        : undefined,
  });

  const { fields, append, remove } = useFieldArray({ control, name: 'items' });
  const watched = watch('items');

  // Unit of measure is copied from the selected item — editable only for ad-hoc lines.
  const itemById = useMemo(
    () =>
      new Map(
        (itemsData?.data ?? []).map((it: { id: string; unit_of_measure: string }) => [it.id, it]),
      ),
    [itemsData],
  );
  const onLineItemChange = (index: number, itemId: string) => {
    setValue(`items.${index}.item_id`, itemId);
    const item = itemById.get(itemId) as { unit_of_measure?: string } | undefined;
    setValue(`items.${index}.unit`, item?.unit_of_measure ?? '');
  };

  const save = useMutation({
    mutationFn: (values: FormValues) => {
      const payload = {
        name: values.name.trim(),
        notes: values.notes?.trim() || undefined,
        department_id: values.department_id || undefined,
        items: values.items.map((l) => ({
          item_id: l.item_id || null,
          description: l.description.trim(),
          quantity: l.quantity,
          unit: l.unit || undefined,
          estimated_unit_price: l.estimated_unit_price || undefined,
        })),
      };
      if (isEdit && templateId) {
        return prTemplatesApi.update(templateId, payload);
      }
      return prTemplatesApi.create(payload as never);
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['purchasing', 'pr-templates'] });
      toast.success(isEdit ? 'Template updated.' : 'Template created.');
      navigate('/purchasing/pr-templates');
    },
    onError: (err: AxiosError<ApiValidationError>) => {
      if (err.response?.status === 422 && err.response.data.errors) {
        Object.entries(err.response.data.errors).forEach(([k, v]) =>
          setError(k as keyof FormValues, { type: 'server', message: v[0] }),
        );
        toast.error(err.response?.data?.message || 'Validation failed.');
      } else {
        toast.error(err.response?.data?.message ?? 'Failed to save template.');
      }
    },
  });

  const depts = deptsData?.data ?? [];
  const items = itemsData?.data ?? [];

  return (
    <div>
      <PageHeader
        title={isEdit ? 'Edit Template' : 'New PR Template'}
        backTo="/purchasing/pr-templates"
        backLabel="PR Templates"
        breadcrumbs={[
          { label: 'Purchasing', href: '/purchasing' },
          { label: 'PR Templates', href: '/purchasing/pr-templates' },
          { label: isEdit ? 'Edit Template' : 'New PR Template' },
        ]}
      />
      <form
        onSubmit={handleSubmit((d) => save.mutate(d), onFormInvalid<FormValues>())}
        className="px-5 py-4 max-w-3xl space-y-4"
      >
        <Panel title="Template details">
          <div className="space-y-3">
            <Input
              label="Template name"
              {...register('name')}
              error={errors.name?.message}
              placeholder="Template name"
              required
              maxLength={200}
            />
            <div className="grid grid-cols-2 gap-3">
              <Select
                label="Department"
                {...register('department_id')}
                error={errors.department_id?.message}
              >
                <option value="">All departments</option>
                {depts.map((d: { id: string; name: string }) => (
                  <option key={d.id} value={d.id}>
                    {d.name}
                  </option>
                ))}
              </Select>
            </div>
            <Textarea
              label="Notes"
              rows={2}
              maxLength={1000}
              {...register('notes')}
              error={errors.notes?.message}
              placeholder="Optional notes about this template…"
            />
          </div>
        </Panel>

        <Panel
          title="Line items"
          actions={
            <Button
              type="button"
              size="sm"
              variant="secondary"
              icon={<LuPlus size={14} />}
              onClick={() =>
                append({
                  item_id: '',
                  description: '',
                  quantity: '',
                  unit: '',
                  estimated_unit_price: '',
                })
              }
            >
              Add item
            </Button>
          }
          noPadding
        >
          {errors.items?.root && (
            <div className="text-xs text-danger-fg px-3 pt-2">{errors.items.root.message}</div>
          )}
          <div className="divide-y divide-subtle">
            {fields.map((field, i) => (
              <div key={field.id} className="p-3 grid grid-cols-12 gap-2 items-start">
                <div className="col-span-3">
                  <Select
                    value={watched[i]?.item_id ?? ''}
                    onChange={(e) => onLineItemChange(i, e.target.value)}
                    error={errors.items?.[i]?.item_id?.message}
                  >
                    <option value="">— Select item —</option>
                    {items.map((it: { id: string; code: string; name: string }) => (
                      <option key={it.id} value={it.id}>
                        {it.code} — {it.name}
                      </option>
                    ))}
                  </Select>
                </div>
                <div className="col-span-4">
                  <Input
                    placeholder="Description"
                    maxLength={200}
                    {...register(`items.${i}.description` as const)}
                    error={errors.items?.[i]?.description?.message}
                  />
                </div>
                <div className="col-span-1">
                  <Input
                    placeholder="Qty"
                    {...register(`items.${i}.quantity` as const)}
                    error={errors.items?.[i]?.quantity?.message}
                  />
                </div>
                <div className="col-span-1">
                  <Input
                    placeholder="Unit"
                    value={watched[i]?.unit ?? ''}
                    onChange={(e) => setValue(`items.${i}.unit`, e.target.value)}
                    readOnly={!!watched[i]?.item_id}
                    title={
                      watched[i]?.item_id
                        ? 'Unit is copied from the selected item'
                        : 'Editable for ad-hoc lines only'
                    }
                  />
                </div>
                <div className="col-span-2">
                  <Input
                    placeholder="Est. price"
                    {...register(`items.${i}.estimated_unit_price` as const)}
                    error={errors.items?.[i]?.estimated_unit_price?.message}
                  />
                </div>
                <div className="col-span-1 pt-1">
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    iconOnly
                    icon={<LuTrash2 size={14} />}
                    aria-label="Remove line"
                    onClick={() => remove(i)}
                    disabled={fields.length <= 1}
                    className="text-muted hover:text-danger-fg"
                  />
                </div>
              </div>
            ))}
          </div>
        </Panel>

        <div className="flex items-center justify-end gap-2 pt-2">
          <Button
            type="button"
            variant="secondary"
            onClick={() => navigate('/purchasing/pr-templates')}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            variant="primary"
            disabled={isSubmitting || save.isPending}
            loading={save.isPending}
          >
            {save.isPending
              ? isEdit
                ? 'Updating…'
                : 'Creating…'
              : isEdit
                ? 'Update Template'
                : 'Create Template'}
          </Button>
        </div>
      </form>
    </div>
  );
}
