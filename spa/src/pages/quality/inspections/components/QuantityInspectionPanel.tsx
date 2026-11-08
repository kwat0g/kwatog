import { useCallback, useEffect, useRef, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import type { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { inspectionsApi } from '@/api/quality/inspections';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import { DraftRestoreBanner } from '@/components/ui/DraftRestoreBanner';
import { useFormDraftAutosave } from '@/hooks/useFormDraftAutosave';
import { useUnsavedChangesGuard } from '@/hooks/useUnsavedChangesGuard';
import { useAuthStore } from '@/stores/authStore';
import { inspectionQueryKeys } from '@/lib/queryKeys/inspections';
import { invalidateRealtimeChange } from '@/lib/realtime/invalidationMap';
import type { Inspection } from '@/types/quality';

export function QuantityInspectionPanel({ inspection: initial, canManage, onUpdated }: { inspection: Inspection; canManage: boolean; onUpdated?: (inspection: Inspection) => void | Promise<void> }) {
  const [saved, setSaved] = useState(initial);
  const inspection = (initial.result_version ?? 0) > (saved.result_version ?? 0) ? initial : saved;
  const qc = useQueryClient();
  const [quantity, setQuantity] = useState('');
  const [reasons, setReasons] = useState<string[]>([]);
  const piece = inspection.sampling_basis !== 'lot';
  const unit = inspection.source_uom_code || 'PCS';
  const source = inspection.startup_trial_required ? inspection.startup_trial?.required_quantity ?? inspection.counted_capture!.required_quantity : inspection.source_quantity ?? inspection.batch_quantity;
  const editable = canManage && ['draft', 'in_progress'].includes(inspection.status);
  const valid = /^\d+(?:\.\d{1,3})?$/.test(quantity) && Number(quantity) <= Number(source) && (!piece || (Number.isSafeInteger(Number(quantity)) && Number(quantity) <= 10000));
  const failed = valid && Number(quantity) > 0;
  const reasonCount = failed ? piece ? Number(quantity) : 1 : 0;
  const activeReasons = Array.from({ length: reasonCount }, (_, index) => reasons[index] ?? '');
  const ready = editable && valid && activeReasons.every(reason => reason.trim().length > 0 && reason.length <= 500);
  const dirty = quantity !== '' || reasons.some(Boolean);
  useUnsavedChangesGuard(editable && dirty, true);
  const userId = useAuthStore(state => state.user?.id);
  const values = useRef({ quantity, reasons });
  values.current = { quantity, reasons };
  const getValues = useCallback(() => values.current, []);
  const restore = useCallback((draft: Record<string, unknown>) => {
    if (typeof draft.quantity === 'string') setQuantity(draft.quantity);
    if (Array.isArray(draft.reasons)) setReasons(draft.reasons.map(value => typeof value === 'string' ? value : ''));
  }, []);
  const recovery = useFormDraftAutosave({ formKey: `quantity-inspection:${inspection.id}:${inspection.result_version ?? 0}`, userId, getValues, setValues: restore, enabled: Boolean(userId) && editable && dirty });
  useEffect(() => {
    if (!editable || !dirty) return;
    const timer = window.setTimeout(recovery.saveNow, 500);
    return () => window.clearTimeout(timer);
  }, [quantity, reasons, editable, dirty, recovery.saveNow]);
  const submit = useMutation({
    mutationFn: () => inspectionsApi.recordBatchResult(inspection.id, { expected_result_version: inspection.result_version ?? 0, quantity_result: { defective_quantity: quantity, descriptions: activeReasons.map(reason => reason.trim()) } }),
    onSuccess: result => {
      setSaved(result);
      void onUpdated?.(result);
      recovery.discard(); setQuantity(''); setReasons([]);
      qc.setQueryData(inspectionQueryKeys.detail(inspection.id), result);
      void invalidateRealtimeChange(qc, { entityType: 'inspection', entityId: inspection.id }, [['inventory', 'grn']], [inspectionQueryKeys.detail(inspection.id)]);
      toast.success('Inspection submitted');
    },
    onError: (error: AxiosError<{ message?: string }>) => {
      if (error.response?.status === 409) void invalidateRealtimeChange(qc, { entityType: 'inspection', entityId: inspection.id });
      toast.error(error.response?.data?.message ?? 'Could not submit inspection. Your entries are retained.');
    },
  });
  return <section aria-label="Quality inspection" className="rounded-md border border-default bg-canvas">
    <div className="border-b border-default px-4 py-3"><h2 className="text-sm font-medium">Quality inspection</h2><p className="mt-1 text-sm text-muted">Inspection quantity: {source} {unit}</p></div>
    {editable ? <form className="space-y-4 p-4" onSubmit={event => { event.preventDefault(); if (ready && !submit.isPending) submit.mutate(); }}>
      {recovery.hasDraft && <DraftRestoreBanner ageMs={recovery.draftAge} onRestore={recovery.restore} onDiscard={recovery.discard} />}
      <fieldset disabled={submit.isPending} className="min-w-0 space-y-4">
        <Input label={piece ? `Defective pieces (${unit})` : `Defective quantity (${unit})`} type="number" min={0} max={Number(source)} step={piece ? 1 : '0.001'} required value={quantity} onChange={event => setQuantity(event.target.value)} helper={`Enter 0 if none. Maximum ${source} ${unit}.`} error={quantity !== '' && !valid ? `Enter ${piece ? 'a whole number' : 'a quantity with up to 3 decimal places'} from 0 to ${piece ? Math.min(Number(source), 10000) : source}.` : undefined} containerClassName="max-w-sm" />
        {valid && <p role="status" className={`text-sm font-medium ${failed ? 'text-danger-fg' : 'text-success-fg'}`}>{failed ? 'FAIL' : 'PASS'}</p>}
        {activeReasons.map((reason, index) => <Textarea key={index} label={piece ? `Defective piece ${index + 1} — what is wrong?` : `What is wrong with the ${quantity} ${unit}?`} required maxLength={500} rows={2} value={reason} onChange={event => { const value = event.target.value; setReasons(old => { const next = [...old]; next[index] = value; return next; }); }} />)}
      </fieldset>
      <div className="flex justify-end border-t border-default pt-4"><Button type="submit" variant="primary" loading={submit.isPending} disabled={!ready || submit.isPending}>Submit inspection</Button></div>
    </form> : <div className="space-y-2 p-4 text-sm"><p>{inspection.quantity_result?.defective_quantity ?? inspection.sample_defect_count ?? '—'} {unit} defective · {inspection.recorded_result === 'failed' ? 'FAIL' : inspection.recorded_result === 'passed' ? 'PASS' : 'Not recorded'}</p>{inspection.problem_description && <p className="whitespace-pre-wrap">{inspection.problem_description}</p>}<p className="text-muted">{inspection.status === 'awaiting_review' ? 'Awaiting independent QC review.' : 'Inspection evidence is locked.'}</p></div>}
  </section>;
}
