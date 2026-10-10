import { z } from 'zod';
import type { AxiosError } from 'axios';
import { Textarea } from '@/components/ui/Textarea';
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useParams } from 'react-router-dom';
import { LuPlus, LuTrash2 } from '@/lib/icons';
import toast from 'react-hot-toast';
import { itemQualityPlansApi, itemsApi } from '@/api/inventory/items';
import { vendorsApi } from '@/api/accounting/vendors';
import { PageHeader } from '@/components/layout/PageHeader';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { Checkbox } from '@/components/ui/Checkbox';
import { formatDate } from '@/lib/formatDate';
import { reportMutationError } from '@/lib/formErrors';
import { useUnsavedChangesGuard } from '@/hooks/useUnsavedChangesGuard';
import { usePermission } from '@/hooks/usePermission';
import { cn } from '@/lib/cn';
import type { QualityPlanParameter } from '@/types/inventory';

import { QueryErrorState } from '@/components/ui/QueryErrorState';
type DraftParameter = QualityPlanParameter & { key: string; acceptance_criterion: string };
const blankParameter = (): DraftParameter => ({ key: crypto.randomUUID(), parameter_name: '', parameter_type: null, acceptance_criterion: '', is_critical: false });
const checksSchema = z.array(z.object({ parameter_name: z.string().trim().min(1).max(150), acceptance_criterion: z.string().trim().min(1).max(500), is_critical: z.boolean().optional() })).min(1).max(100);

export default function ItemQualityPlansPage() {
 const { id = '' } = useParams<{ id: string }>();
 const queryClient = useQueryClient();
 const { can } = usePermission();
 const canManage = can('quality.specs.manage');
 const [vendorId, setVendorId] = useState('');
 const [sampling, setSampling] = useState<string>('');
 const [fixedSize, setFixedSize] = useState('');
 const [notes, setNotes] = useState('');
 const [parameters, setParameters] = useState<DraftParameter[]>([blankParameter()]);

 const item = useQuery({ queryKey: ['inventory', 'items', id], queryFn: () => itemsApi.show(id), enabled: !!id });
 const plans = useQuery({ queryKey: ['inventory', 'quality-plans', id], queryFn: () => itemQualityPlansApi.list(id), enabled: !!id });
 const vendors = useQuery({ queryKey: ['vendors', 'quality-plan-picker'], queryFn: () => vendorsApi.list({ per_page: 100, is_active: true }) });
 const planOptions = useQuery({ queryKey: ['inventory', 'quality-plans', 'options'], queryFn: () => itemQualityPlansApi.options() });
 const samplingOptions = planOptions.data?.sampling_methods ?? [];
 const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
 const captureEnabled = planOptions.data?.checklist_capture_enabled === true;
 const normalizedNames = parameters.map(p => p.parameter_name.trim().replace(/\s+/g, ' ').toLocaleLowerCase());
 const valid = checksSchema.safeParse(parameters).success && new Set(normalizedNames).size === parameters.length && !!sampling && (sampling !== 'fixed' || (Number.isInteger(Number(fixedSize)) && Number(fixedSize) >= 1 && Number(fixedSize) <= 1000));

 const save = useMutation({
 mutationFn: () => itemQualityPlansApi.createRevision(id, {
 capture_method: 'checklist',
 vendor_id: vendorId || null,
 sampling_method: sampling as Parameters<typeof itemQualityPlansApi.createRevision>[1]['sampling_method'],
 fixed_sample_size: sampling === 'fixed' ? Number(fixedSize) : null,
 aql_level: sampling === 'aql' ? (planOptions.data?.default_aql_level || null) : null,
 notes: notes || null,
 parameters: parameters.map(p => ({ parameter_name: p.parameter_name.trim(), acceptance_criterion: p.acceptance_criterion.trim(), parameter_type: p.parameter_type, is_critical: p.is_critical })),
 }),
 onSuccess: () => {
 toast.success('Quality-plan revision published.');
 setNotes(''); setParameters([blankParameter()]); setFieldErrors({});
 queryClient.invalidateQueries({ queryKey: ['inventory', 'quality-plans', id] });
 queryClient.invalidateQueries({ queryKey: ['inventory', 'items', id] });
 },
 onError: (error: AxiosError<{ errors?: Record<string, string[]> }>) => { setFieldErrors(error.response?.data?.errors ?? {}); reportMutationError(error, 'Could not publish the quality-plan revision.'); },
 });

 const deactivate = useMutation({
 mutationFn: itemQualityPlansApi.deactivate,
 onError: (error: AxiosError) => reportMutationError(error, 'Could not deactivate the quality plan.'),
 onSuccess: () => {
 toast.success('Quality plan deactivated.');
 queryClient.invalidateQueries({ queryKey: ['inventory', 'quality-plans', id] });
 queryClient.invalidateQueries({ queryKey: ['inventory', 'items', id] });
 },
 });

 useUnsavedChangesGuard(!!notes || parameters.some(check => !!check.parameter_name || !!check.acceptance_criterion || check.is_critical));

 const updateParameter = (index: number, patch: Partial<DraftParameter>) =>
 setParameters((current) => current.map((parameter, i) => i === index ? { ...parameter, ...patch } : parameter));

 if (item.isLoading || plans.isLoading) return <SkeletonTable columns={4} rows={6} />;
 // A 500 on the item fetch used to render "Item not found".
 if (item.isError) return <QueryErrorState subject="this item" onRetry={() => void item.refetch()} />;
 if (!item.data) return <EmptyState icon="alert-circle" title="Item not found" />;

 return (
 <div className="min-w-0">
 <PageHeader title={`Quality Plans · ${item.data.code}`} backTo={`/inventory/items/${id}`} backLabel="Item" subtitle="Versioned incoming inspection rules, optionally specific to a supplier." />
 <div className="p-4 sm:p-5 grid min-w-0 grid-cols-1 xl:grid-cols-5 gap-4">
 {canManage && <Panel title="Publish a revision" className="xl:col-span-3">
 <form className="space-y-3" onSubmit={event => { event.preventDefault(); if (valid && captureEnabled && !save.isPending && !vendors.isError && !planOptions.isError && !vendors.isLoading && !planOptions.isLoading) save.mutate(); }}>
 <fieldset disabled={save.isPending} className="space-y-3">
 {!captureEnabled && <p role="alert" className="text-warning-fg">PASS/FAIL publication is paused until the compatible workflow is enabled.</p>}
 {planOptions.isError && <QueryErrorState subject="quality-plan options" onRetry={() => void planOptions.refetch()} />}
 {vendors.isError && <QueryErrorState subject="suppliers" onRetry={() => void vendors.refetch()} />}
 <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
 <Select label="Supplier scope" value={vendorId} onChange={(event) => setVendorId(event.target.value)}>
 <option value="">All suppliers (default)</option>
 {(vendors.data?.data ?? []).map((vendor) => <option key={vendor.id} value={vendor.id}>{vendor.name}</option>)}
 </Select>
 <Select label="Sampling" value={sampling} onChange={(event) => setSampling(event.target.value as typeof sampling)}>
 <option value="">— Select —</option>
 {samplingOptions.map((method) => <option key={method.value} value={method.value}>{method.label}</option>)}
 </Select>
 {sampling === 'fixed' && <Input label="Sample size" type="number" min={1} max={1000} value={fixedSize} onChange={(event) => setFixedSize(event.target.value)} />}
 </div>

 <div className="space-y-2">
 <div className="flex items-center justify-between"><h3 className="text-sm font-medium">Inspection checklist</h3><Button type="button" size="sm" variant="secondary" disabled={parameters.length >= 100 || save.isPending} onClick={() => setParameters((p) => [...p, blankParameter()])}><LuPlus size={12} /> Add check</Button></div>
 {parameters.map((parameter, index) => (
 <div key={parameter.key} className="grid min-w-0 gap-3 border-b border-default py-3 md:grid-cols-[minmax(0,1fr)_minmax(0,2fr)_auto]">
 <Input label={`Check ${index + 1}`} maxLength={150} required value={parameter.parameter_name} error={fieldErrors[`parameters.${index}.parameter_name`]?.[0]} onChange={e => updateParameter(index, { parameter_name: e.target.value })} />
 <Textarea label={`Acceptance criterion / reference ${index + 1}`} maxLength={500} rows={2} required value={parameter.acceptance_criterion} error={fieldErrors[`parameters.${index}.acceptance_criterion`]?.[0]} onChange={e => updateParameter(index, { acceptance_criterion: e.target.value })} />
 <div className="flex items-center gap-2"><Checkbox label="Critical" checked={parameter.is_critical} onChange={e => updateParameter(index, { is_critical: e.target.checked })} />
 <Button type="button" variant="ghost" size="sm" iconOnly icon={<LuTrash2 size={14} />} aria-label={`Remove check ${index + 1}`} disabled={parameters.length === 1} onClick={() => setParameters(p => p.filter((_, i) => i !== index))} className="text-danger-fg" /></div>
 </div>
 ))}
 </div>
 <Input label="Revision notes" value={notes} onChange={(event) => setNotes(event.target.value)} />
 <div className="flex justify-end border-t border-default pt-3"><Button type="submit" variant="primary" loading={save.isPending} disabled={save.isPending || !valid || !captureEnabled || vendors.isError || planOptions.isError || vendors.isLoading || planOptions.isLoading}>Publish revision</Button></div>
 </fieldset>
 </form>
 </Panel>}

 <Panel title="Revision history" className={cn(canManage ? 'xl:col-span-2' : 'xl:col-span-5')}>
 <div className="space-y-2">
 {plans.isError && <QueryErrorState subject="quality-plan history" onRetry={() => void plans.refetch()} />}
 {(plans.data ?? []).map((plan) => (
 <div key={plan.id} className="rounded-md border border-subtle p-3">
 <div className="flex items-center gap-2"><span className="font-mono text-sm">v{plan.version}</span><Chip variant={plan.is_active ? 'success' : 'neutral'}>{plan.is_active ? 'active' : 'retired'}</Chip><Chip variant="info">{plan.sampling_method}</Chip></div>
 <p className="text-xs text-muted mt-1">{plan.vendor?.name ?? 'All suppliers'} · {plan.parameters.length} checks · effective {formatDate(plan.effective_from)}</p>
 <ul className="mt-2 space-y-1 break-words">{plan.parameters.map((check, index) => <li key={index} className="text-sm"><span className="font-medium">{check.parameter_name}</span>{check.acceptance_criterion && <span className="text-muted"> · {check.acceptance_criterion}</span>}{plan.capture_method !== 'checklist' && <span className="block text-xs text-muted">Historical measurements · Nominal: {check.nominal_value ?? '—'} · Min: {check.tolerance_min ?? '—'} · Max: {check.tolerance_max ?? '—'} {check.unit_of_measure ?? ''}</span>}</li>)}</ul>
 {plan.notes && <p className="text-xs mt-1">{plan.notes}</p>}
 {canManage && plan.is_active && <Button className="mt-2" size="sm" variant="secondary" onClick={() => deactivate.mutate(plan.id)}>Deactivate</Button>}
 </div>
 ))}
 {!plans.isError && (plans.data ?? []).length === 0 && <p className="text-sm text-muted">No quality-plan revisions yet.</p>}
 </div>
 </Panel>
 </div>
 </div>
 );
}
