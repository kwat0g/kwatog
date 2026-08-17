/**
 * ADV7 — NCR Template create / edit page.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useParams } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { ncrTemplatesApi } from '@/api/quality/ncr-templates';
import { itemsApi } from '@/api/inventory/items';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { SkeletonForm } from '@/components/ui/Skeleton';
import { onFormInvalid } from '@/lib/formErrors';
import type { AxiosError } from 'axios';
import type { ApiValidationError } from '@/types';
import type { CreateNcrTemplateData } from '@/types/quality';

import { useFormSafety } from '@/hooks/useFormSafety';
import { FormDraftBanner } from '@/components/ui/FormDraftBanner';
const schema = z.object({
  name: z.string().trim().min(1, 'Template name is required.').max(200),
  source: z.string().min(1, 'Source is required.'),
  severity: z.string().min(1, 'Severity is required.'),
  product_id: z.string().optional().or(z.literal('')),
  defect_description: z.string().max(2000, 'Max 2000 characters.').optional().or(z.literal('')),
  notes: z.string().max(2000, 'Max 2000 characters.').optional().or(z.literal('')),
});
type FormValues = z.infer<typeof schema>;

export default function NcrTemplateFormPage() {
  const { id } = useParams<{ id: string }>();
  const isEdit = !!id;
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  const { data: templateOptions } = useQuery({
    queryKey: ['quality', 'ncr-templates', 'options'],
    queryFn: () => ncrTemplatesApi.options(),
  });

  // Load existing template for edit mode
  const { data: existing, isLoading: loadingExisting } = useQuery({
    queryKey: ['quality', 'ncr-templates', id],
    queryFn: () => ncrTemplatesApi.show(id!),
    enabled: isEdit,
  });

  const products = useQuery({
    queryKey: ['inventory', 'items', { per_page: 200, item_type: 'product' }],
    queryFn: () => itemsApi.list({ per_page: 200, item_type: 'product' }),
  });

    const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      name: '',
      source: '',
      severity: '',
      product_id: '',
      defect_description: '',
      notes: '',
    },
    values:
      isEdit && existing
        ? {
            name: existing.name,
            source: existing.source,
            severity: existing.severity,
            product_id: existing.product?.id ?? '',
            defect_description: existing.defect_description ?? '',
            notes: existing.notes ?? '',
          }
        : undefined,
  });
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = form;

  const createMut = useMutation({
    mutationFn: (data: FormValues) => {
      const payload: CreateNcrTemplateData = {
        name: data.name.trim(),
        source: data.source as CreateNcrTemplateData['source'],
        severity: data.severity as CreateNcrTemplateData['severity'],
        product_id: data.product_id || null,
        defect_description: data.defect_description?.trim() || undefined,
        notes: data.notes?.trim() || undefined,
      };
      return isEdit ? ncrTemplatesApi.update(id!, payload) : ncrTemplatesApi.create(payload);
    },
    onSuccess: () => {
      toast.success(isEdit ? 'Template updated' : 'Template created');
      queryClient.invalidateQueries({ queryKey: ['quality', 'ncr-templates'] });
      navigate('/quality/ncr-templates');
    },
    onError: (err: AxiosError<ApiValidationError>) => {
      if (err.response?.status === 422 && err.response.data.errors) {
        Object.entries(err.response.data.errors).forEach(([k, v]) =>
          setError(k as keyof FormValues, { type: 'server', message: v[0] }),
        );
        toast.error(err.response?.data?.message || 'Validation failed.');
      } else {
        toast.error(err.response?.data?.message ?? 'Failed to save template');
      }
    },
  });
  const safety = useFormSafety({ form, saved: createMut.isSuccess });

  if (isEdit && loadingExisting) {
    return <SkeletonForm />;
  }

  return (
    <div>
      <PageHeader
        title={isEdit ? 'Edit NCR template' : 'New NCR template'}
        backTo="/quality/ncr-templates"
        backLabel="NCR templates"
      />
      <FormDraftBanner safety={safety} />
      <form
        onSubmit={handleSubmit((d) => createMut.mutate(d), onFormInvalid<FormValues>())}
        className="px-5 py-4"
      >
        <div className="space-y-4 max-w-3xl">
          <Panel title="Template details">
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
              <Input
                label="Template name"
                required
                maxLength={200}
                {...register('name')}
                error={errors.name?.message}
                placeholder="Defect description"
              />
              <Select
                label="Source"
                required
                {...register('source')}
                error={errors.source?.message}
              >
                <option value="">— Select —</option>
                {(templateOptions?.sources ?? []).map((source) => (
                  <option key={source.value} value={source.value}>
                    {source.label}
                  </option>
                ))}
              </Select>
              <Select
                label="Severity"
                required
                {...register('severity')}
                error={errors.severity?.message}
              >
                <option value="">— Select —</option>
                {(templateOptions?.severities ?? []).map((severity) => (
                  <option key={severity.value} value={severity.value}>
                    {severity.label}
                  </option>
                ))}
              </Select>
            </div>
            <div className="mt-3">
              <Select
                label="Product (optional)"
                {...register('product_id')}
                error={errors.product_id?.message}
              >
                <option value="">— None —</option>
                {products.data?.data.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.code} — {p.name}
                  </option>
                ))}
              </Select>
            </div>
          </Panel>

          <Panel title="Defect details">
            <Textarea
              label="Defect description"
              rows={4}
              maxLength={2000}
              {...register('defect_description')}
              error={errors.defect_description?.message}
              placeholder="Describe the common defect pattern…"
            />
            <div className="mt-3">
              <Textarea
                label="Internal notes"
                rows={3}
                maxLength={2000}
                {...register('notes')}
                error={errors.notes?.message}
                placeholder="Any internal guidance for QC inspectors…"
              />
            </div>
          </Panel>

          <div className="flex items-center justify-end gap-2 pt-4 border-t border-default">
            <Button
              variant="secondary"
              type="button"
              onClick={() => navigate('/quality/ncr-templates')}
              disabled={createMut.isPending || isSubmitting}
            >
              Cancel
            </Button>
            <Button
              variant="primary"
              type="submit"
              loading={isSubmitting || createMut.isPending}
              disabled={isSubmitting || createMut.isPending}
            >
              {isEdit ? 'Update template' : 'Create template'}
            </Button>
          </div>
        </div>
      </form>
    </div>
  );
}
