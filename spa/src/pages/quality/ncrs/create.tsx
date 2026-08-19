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
import { applyServerValidationErrors, onFormInvalid } from '@/lib/formErrors';
import { LuCopy } from '@/lib/icons';
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
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { PageHeader } from '@/components/layout/PageHeader';
import { focusRingInset } from '@/lib/focus';
import type { CreateNcrData, NcrTemplate, NcrSeverity, NcrSource } from '@/types/quality';
import { cn } from '@/lib/cn';

import { useFormSafety } from '@/hooks/useFormSafety';
import { FormDraftBanner } from '@/components/ui/FormDraftBanner';
const schema = z.object({
 source: z.string().min(1, 'Source is required'),
 severity: z.string().min(1, 'Severity is required'),
 product_id: z.string().optional().or(z.literal('')),
 defect_description: z.string().min(1, 'Description is required').max(5000),
 affected_quantity: z.coerce.number().int().min(0).max(1000000).default(0),
});

const SEVERITY_CHIP: Record<string, ChipVariant> = {
 low: 'neutral',
 medium: 'warning',
 high: 'danger',
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
 const ncrOptions = useQuery({
 queryKey: ['quality', 'ncrs', 'options'],
 queryFn: () => ncrsApi.options(),
 });

 const templates = useQuery({
 queryKey: ['quality', 'ncr-templates', 'active'],
 queryFn: () => ncrTemplatesApi.active(),
 });

  const form = useForm<FormValues>({
 resolver: zodResolver(schema),
 defaultValues: {
 source: '',
 severity: '',
 product_id: '',
 defect_description: '',
 affected_quantity: 0,
 },
 });
 const {
 register, handleSubmit, formState: { errors }, reset, watch, setValue, setError,
 } = form;

 useEffect(() => {
 if (!watch('source') && ncrOptions.data?.sources?.length) setValue('source', ncrOptions.data.sources[0].value);
 if (!watch('severity') && ncrOptions.data?.severities?.length) setValue('severity', ncrOptions.data.severities[0].value);
 }, [ncrOptions.data, setValue, watch]);

 // Pre-fill from template when navigated from NCR template list
 useEffect(() => {
 const tpl = (location.state as { template?: NcrTemplate } | null)?.template as NcrTemplate | undefined;
 if (tpl) {
 reset({
 source: tpl.source ?? '',
 severity: tpl.severity,
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
 source: tpl.source ?? '',
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
 // A rejected NCR used to surface only the top-level message, so a bad
 // field left the form unmarked and the user guessing which one.
 applyServerValidationErrors(e, setError, 'Failed to open NCR.');
 },
 });
 const safety = useFormSafety({ form, saved: submit.isSuccess });

 return (
 <div>
 <PageHeader title="Open NCR" subtitle="Use this for customer complaints or supplier issues. Inspection failures auto-create NCRs." />
      <FormDraftBanner safety={safety} />
 {/* Template picker button */}
 <div className="px-5 py-2 flex items-center gap-2">
 <span className="text-xs text-muted">Quick-fill from template:</span>
 <Button
 size="sm"
 variant="secondary"
 icon={<LuCopy size={12} />}
 onClick={() => setTemplatePickerOpen(true)}
 disabled={templates.isLoading}
 >
 {templates.isLoading ? 'Loading…' : 'Use template'}
 </Button>
 </div>

 <form
 onSubmit={handleSubmit((v) =>
 submit.mutate({
 source: v.source as NcrSource,
 severity: v.severity as NcrSeverity,
 product_id: v.product_id || null,
 defect_description: v.defect_description,
 affected_quantity: Number(v.affected_quantity),
 })
 , onFormInvalid<FormValues>())}
 className="px-5 py-4"
 >
 <div className="space-y-4 max-w-3xl">
 <Panel title="Classification">
 <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
 <Select label="Source" required {...register('source')} error={errors.source?.message}>
 {(ncrOptions.data?.sources ?? []).map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
 </Select>
 <Select label="Severity" required {...register('severity')} error={errors.severity?.message}>
 {(ncrOptions.data?.severities ?? []).map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
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
 {products.data?.data?.map((p) => (
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
 className={cn('w-full text-left px-3 py-2.5 rounded-md hover:bg-elevated transition-colors border border-transparent hover:border-default mb-1 cursor-pointer', focusRingInset)}
 >
 <div className="text-sm font-medium">{tpl.name}</div>
 <div className="text-xs text-muted mt-0.5 flex items-center gap-2">
 <Chip variant="neutral">{tpl.source_label ?? tpl.source.replace('_', ' ')}</Chip>
 <Chip variant={SEVERITY_CHIP[tpl.severity]}>{tpl.severity_label ?? tpl.severity}</Chip>
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

 <ModalFooter>
 <Button variant="secondary" type="button" onClick={() => navigate(-1)}>
 Cancel
 </Button>
 <Button variant="primary" type="submit" loading={submit.isPending}>
 Open NCR
 </Button>
 </ModalFooter>
 </div>
 </form>
 </div>
 );
}
