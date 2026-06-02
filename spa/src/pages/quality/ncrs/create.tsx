/**
 * Sprint 7 — Task 64 — NCR create page.
 *
 * Most NCRs are auto-opened from inspection failure; this page is for the
 * customer-complaint and supplier-issue paths where QC needs to file
 * manually.
 */
import { useState, useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Link, useNavigate, useLocation } from 'react-router-dom';
import { z } from 'zod';
import { zodResolver } from '@hookform/resolvers/zod';
import toast from 'react-hot-toast';
import { onFormInvalid } from '@/lib/formErrors';
import { Copy } from 'lucide-react';
import type { AxiosError } from 'axios';
import { ncrsApi } from '@/api/quality/ncrs';
import { ncrTemplatesApi } from '@/api/quality/ncr-templates';
import { productsApi } from '@/api/crm/products';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { Chip, type ChipVariant } from '@/components/ui/Chip';
import { Modal } from '@/components/ui/Modal';
import { PageHeader } from '@/components/layout/PageHeader';
import type { CreateNcrData, NcrTemplate } from '@/types/quality';

const schema = z.object({
  source: z.enum(['inspection_fail', 'customer_complaint']),
  severity: z.enum(['minor', 'major', 'critical']),
  product_id: z.string().optional().or(z.literal('')),
  defect_description: z.string().min(1, 'Description is required').max(5000),
  affected_quantity: z.coerce.number().int().min(0).max(1000000).default(0),
});

const SEVERITY_CHIP: Record<string, ChipVariant> = {
  minor: 'neutral',
  major: 'warning',
  critical: 'danger',
};

type FormValues = z.infer<typeof schema>;

export default function CreateNcrPage() {
  const navigate = useNavigate();
  const location = useLocation();
  const [templatePickerOpen, setTemplatePickerOpen] = useState(false);

  const products = useQuery({
    queryKey: ['crm', 'products', { is_active: true, per_page: 200 }],
    queryFn: () => productsApi.list({ is_active: true, per_page: 200 }),
  });

  const templates = useQuery({
    queryKey: ['quality', 'ncr-templates', 'active'],
    queryFn: () => ncrTemplatesApi.active(),
  });

  const {
    register, handleSubmit, formState: { errors }, reset,
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      source: 'customer_complaint',
      severity: 'minor',
      product_id: '',
      defect_description: '',
      affected_quantity: 0,
    },
  });

  // Pre-fill from template when navigated from NCR template list
  useEffect(() => {
    const tpl = (location.state as any)?.template as NcrTemplate | undefined;
    if (tpl) {
      reset({
        source: (tpl.source === 'inspection_fail' || tpl.source === 'customer_complaint')
          ? tpl.source
          : 'customer_complaint',
        severity: (['minor', 'major', 'critical'].includes(tpl.severity) ? tpl.severity : 'minor') as FormValues['severity'],
        product_id: tpl.product?.id ?? '',
        defect_description: tpl.defect_description ?? '',
        affected_quantity: 0,
      });
      toast.success(`Template "${tpl.name}" applied`);
      // Clear state so a refresh doesn't re-apply
      window.history.replaceState({}, document.title);
    }
  }, [location.state, reset]);

  const applyTemplate = (tpl: NcrTemplate) => {
    reset({
      source: (tpl.source === 'inspection_fail' || tpl.source === 'customer_complaint')
        ? tpl.source
        : 'customer_complaint',
      severity: tpl.severity,
      product_id: tpl.product?.id ?? '',
      defect_description: tpl.defect_description ?? '',
      affected_quantity: 0,
    });
    setTemplatePickerOpen(false);
    toast.success(`Template "${tpl.name}" applied`);
  };

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
      {/* Template picker button */}
      <div className="px-5 py-2 flex items-center gap-2">
        <span className="text-xs text-muted">Quick-fill from template:</span>
        <Button
          size="sm"
          variant="secondary"
          icon={<Copy size={12} />}
          onClick={() => setTemplatePickerOpen(true)}
          disabled={templates.isLoading}
        >
          {templates.isLoading ? 'Loading…' : 'Use template'}
        </Button>
      </div>

      <form
        onSubmit={handleSubmit((v) =>
          submit.mutate({
            source: v.source,
            severity: v.severity,
            product_id: v.product_id || null,
            defect_description: v.defect_description,
            affected_quantity: Number(v.affected_quantity),
          })
        , onFormInvalid<FormValues>())}
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
                <option value="minor">Minor</option>
                <option value="major">Major</option>
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

          {/* ─── Template picker modal ──────────────────── */}
          <Modal
            isOpen={templatePickerOpen}
            onClose={() => setTemplatePickerOpen(false)}
            title="Choose a template"
            size="md"
          >
            <div className="py-3 max-h-80 overflow-y-auto -mx-4 px-4">
              {templates.isLoading && (
                <div className="text-sm text-muted text-center py-4">Loading templates…</div>
              )}
              {!templates.isLoading && templates.data && templates.data.length === 0 && (
                <div className="text-sm text-muted text-center py-4">
                  No active templates.{' '}
                  <Link to="/quality/ncr-templates" className="text-accent hover:underline">
                    Create one
                  </Link>
                </div>
              )}
              {templates.data?.map((tpl) => (
                <button
                  key={tpl.id}
                  type="button"
                  onClick={() => applyTemplate(tpl)}
                  className="w-full text-left px-3 py-2.5 rounded-md hover:bg-elevated transition-colors border border-transparent hover:border-default mb-1"
                >
                  <div className="text-sm font-medium">{tpl.name}</div>
                  <div className="text-xs text-muted mt-0.5 flex items-center gap-2">
                    <Chip variant="neutral">{tpl.source.replace('_', ' ')}</Chip>
                    <Chip variant={SEVERITY_CHIP[tpl.severity]}>{tpl.severity}</Chip>
                    {tpl.product && (
                      <span>
                        {tpl.product.part_number} — {tpl.product.name}
                      </span>
                    )}
                  </div>
                </button>
              ))}
            </div>
          </Modal>

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
