/**
 * ADV7 — NCR Template create / edit page.
 */
import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { ncrTemplatesApi } from '@/api/quality/ncr-templates';
import { itemsApi } from '@/api/inventory/items';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { Spinner } from '@/components/ui/Spinner';
import type { AxiosError } from 'axios';
import type { CreateNcrTemplateData } from '@/types/quality';

type FormValues = {
  name: string;
  source: string;
  severity: string;
  product_id: string;
  defect_description: string;
  notes: string;
};

export default function NcrTemplateFormPage() {
  const { id } = useParams<{ id: string }>();
  const isEdit = !!id;
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  const [form, setForm] = useState<FormValues>({
    name: '',
    source: 'inspection_fail',
    severity: 'minor',
    product_id: '',
    defect_description: '',
    notes: '',
  });

  // Load existing template for edit mode
  const { data: existing, isLoading: loadingExisting } = useQuery({
    queryKey: ['quality', 'ncr-templates', id],
    queryFn: () => ncrTemplatesApi.show(id!),
    enabled: isEdit,
  });

  useEffect(() => {
    if (existing) {
      setForm({
        name: existing.name,
        source: existing.source,
        severity: existing.severity,
        product_id: existing.product?.id ?? '',
        defect_description: existing.defect_description ?? '',
        notes: existing.notes ?? '',
      });
    }
  }, [existing]);

  const products = useQuery({
    queryKey: ['inventory', 'items', { per_page: 200, item_type: 'product' }],
    queryFn: () => itemsApi.list({ per_page: 200, item_type: 'product' }),
  });

  const createMut = useMutation({
    mutationFn: (data: CreateNcrTemplateData) =>
      isEdit
        ? ncrTemplatesApi.update(id!, data)
        : ncrTemplatesApi.create(data),
    onSuccess: () => {
      toast.success(isEdit ? 'Template updated' : 'Template created');
      queryClient.invalidateQueries({ queryKey: ['quality', 'ncr-templates'] });
      navigate('/quality/ncr-templates');
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Failed to save template');
    },
  });

  const set = (key: keyof FormValues, value: string) =>
    setForm((f) => ({ ...f, [key]: value }));

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    createMut.mutate({
      name: form.name,
      source: form.source as CreateNcrTemplateData['source'],
      severity: form.severity as CreateNcrTemplateData['severity'],
      product_id: form.product_id || null,
      defect_description: form.defect_description || undefined,
      notes: form.notes || undefined,
    });
  };

  if (isEdit && loadingExisting) {
    return (
      <div className="flex items-center justify-center py-20">
        <Spinner size="lg" />
      </div>
    );
  }

  const isPending = createMut.isPending;

  return (
    <div>
      <PageHeader
        title={isEdit ? 'Edit NCR template' : 'New NCR template'}
        backTo="/quality/ncr-templates"
        backLabel="NCR templates"
        breadcrumbs={[{ label: 'Quality', href: '/quality' }, { label: 'NCR templates', href: '/quality/ncr-templates' }, { label: isEdit ? 'Edit NCR template' : 'New NCR template' }]}
      />
      <form onSubmit={handleSubmit} className="px-5 py-4">
        <div className="space-y-4 max-w-3xl">
          <Panel title="Template details">
            <div className="grid grid-cols-3 gap-3">
              <Input
                label="Template name"
                required
                value={form.name}
                onChange={(e) => set('name', e.target.value)}
                placeholder="e.g. Surface scratch — Injection"
              />
              <Select
                label="Source"
                required
                value={form.source}
                onChange={(e) => set('source', e.target.value)}
              >
                <option value="inspection_fail">Inspection fail</option>
                <option value="customer_complaint">Customer complaint</option>
              </Select>
              <Select
                label="Severity"
                required
                value={form.severity}
                onChange={(e) => set('severity', e.target.value)}
              >
                <option value="minor">Minor</option>
                <option value="major">Major</option>
                <option value="critical">Critical</option>
              </Select>
            </div>
            <div className="mt-3">
              <Select
                label="Product (optional)"
                value={form.product_id}
                onChange={(e) => set('product_id', e.target.value)}
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
              value={form.defect_description}
              onChange={(e) => set('defect_description', e.target.value)}
              rows={4}
              placeholder="Describe the common defect pattern…"
            />
            <div className="mt-3">
              <Textarea
                label="Internal notes"
                value={form.notes}
                onChange={(e) => set('notes', e.target.value)}
                rows={3}
                placeholder="Any internal guidance for QC inspectors…"
              />
            </div>
          </Panel>

          <div className="flex items-center justify-end gap-2 pt-4 border-t border-default">
            <Button
              variant="secondary"
              type="button"
              onClick={() => navigate('/quality/ncr-templates')}
              disabled={isPending}
            >
              Cancel
            </Button>
            <Button variant="primary" type="submit" loading={isPending} disabled={!form.name.trim()}>
              {isEdit ? 'Update template' : 'Create template'}
            </Button>
          </div>
        </div>
      </form>
    </div>
  );
}
