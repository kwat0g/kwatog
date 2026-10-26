import { useState, type FormEvent } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import type { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { inspectionsApi } from '@/api/quality/inspections';
import { Button } from '@/components/ui/Button';
import { Checkbox } from '@/components/ui/Checkbox';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import { useUnsavedChangesGuard } from '@/hooks/useUnsavedChangesGuard';
import type { Inspection } from '@/types/quality';

export function FullCountDecisionPanel({ inspection, canManage }: { inspection: Inspection; canManage: boolean }) {
  const queryClient = useQueryClient();
  const [saved, setSaved] = useState(inspection);
  const [counted, setCounted] = useState(false);
  const [defects, setDefects] = useState('');
  const [reason, setReason] = useState('');
  const [segregated, setSegregated] = useState(false);
  const current = (inspection.result_version ?? 0) > (saved.result_version ?? 0) ? inspection : saved;
  const stale = current !== saved;
  const editable = canManage && ['draft', 'in_progress'].includes(current.status) && !stale;
  const count = /^\d+$/.test(defects) ? Number(defects) : NaN;
  const validCount = Number.isSafeInteger(count) && count >= 0 && count <= current.batch_quantity;
  const good = validCount ? current.batch_quantity - count : null;
  const ready = editable && counted && validCount && (count === 0 || reason.trim().length > 0)
    && reason.length <= 2000 && (count === 0 || segregated)
    && current.measurements?.length === 1;
  useUnsavedChangesGuard(editable && (counted || defects !== '' || reason !== '' || segregated), true);

  const submit = useMutation<Inspection, AxiosError<{ message?: string }>>({
    mutationFn: () => inspectionsApi.recordBatchResult(current.id, {
      expected_result_version: current.result_version ?? 0,
      coverage_confirmed: true,
      inspected_quantity: current.batch_quantity,
      sample_defect_count: count,
      physical_defects_confirmed: count > 0,
      ...(count > 0 ? { problem_description: reason.trim() } : {}),
      ...(count > 0 ? { defects_removed_confirmed: true } : {}),
      items: [{ id: current.measurements![0].id, result: 'passed' }],
    }),
    onSuccess: (result) => {
      setSaved(result);
      setCounted(false);
      setDefects('');
      setReason('');
      setSegregated(false);
      queryClient.setQueryData(['quality', 'inspections', current.id], result);
      for (const key of [['quality', 'inspections'], ['quality', 'ncrs'], ['inventory', 'grn']])
        queryClient.invalidateQueries({ queryKey: key });
      toast.success(result.status === 'awaiting_review' ? 'Submitted for independent QC review.' : 'Inspection submitted.');
    },
  });

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    if (ready && !submit.isPending) submit.mutate();
  }

  return <section aria-label="Full-count inspection" className="rounded-md border border-default bg-canvas">
    <div className="border-b border-default px-4 py-3">
      <h2 className="text-sm font-medium">Full-count quality check</h2>
      <p className="mt-1 text-sm text-muted">Count every received piece. Good pieces become eligible for acceptance after independent QC review.</p>
    </div>
    {editable ? <form onSubmit={onSubmit} className="space-y-5 p-4">
      <div className="grid gap-3 sm:grid-cols-2">
        <div className="rounded-md border border-default bg-elevated p-3">
          <p className="text-xs text-muted">Received from warehouse</p>
          <p className="mt-1 font-mono text-xl tabular-nums">{current.batch_quantity.toLocaleString()} {current.source_uom_code}</p>
        </div>
        <div className="rounded-md border border-default bg-elevated p-3">
          <p className="text-xs text-muted">Good pieces</p>
          <p className="mt-1 font-mono text-xl tabular-nums">{good === null ? '—' : good.toLocaleString()} {current.source_uom_code}</p>
        </div>
      </div>
      <Checkbox label={`I counted all ${current.batch_quantity.toLocaleString()} received pieces`} checked={counted} onChange={event => setCounted(event.target.checked)} />
      <Input label="Defective pieces" type="number" min={0} max={current.batch_quantity} step={1} required value={defects}
        onChange={event => { setDefects(event.target.value); setSegregated(false); }}
        error={defects !== '' && !validCount ? `Enter a whole number from 0 to ${current.batch_quantity.toLocaleString()}.` : undefined}
        helper="Enter 0 if none. Count each defective piece once." containerClassName="max-w-sm" />
      {validCount && count > 0 && <>
        <Textarea label="Defect reason" required maxLength={2000} rows={3} value={reason} onChange={event => setReason(event.target.value)} />
        <Checkbox label="I segregated the defective pieces" checked={segregated} onChange={event => setSegregated(event.target.checked)} />
        {good === 0 && <p role="status" className="text-sm text-warning-fg">All pieces defective. Entire receipt goes to NCR review.</p>}
      </>}
      {good !== null && <p role="status" className="text-sm font-medium">{good.toLocaleString()} pieces eligible for acceptance</p>}
      {submit.isError && <p role="alert" className="text-sm text-danger-fg">{submit.error.response?.data?.message ?? 'Could not save inspection. Your input is retained.'}</p>}
      <div className="flex justify-end border-t border-default pt-4"><Button type="submit" variant="primary" loading={submit.isPending} disabled={!ready || submit.isPending}>Submit inspection</Button></div>
    </form> : <div className="space-y-2 p-4 text-sm">
      {stale && <p role="alert">This inspection changed. Reload the page for the latest result.</p>}
      {current.sample_defect_count != null && <p>{current.sample_defect_count.toLocaleString()} defective · {(current.batch_quantity - current.sample_defect_count).toLocaleString()} good pieces</p>}
      {current.problem_description && <p className="whitespace-pre-wrap">{current.problem_description}</p>}
      <p className="text-muted">{current.status === 'awaiting_review' ? 'Awaiting independent QC review.' : current.status === 'cancelled' ? 'This inspection was cancelled.' : current.status === 'draft' || current.status === 'in_progress' ? 'Your account cannot edit this inspection. Refresh your session or contact an administrator.' : 'Inspection evidence is locked.'}</p>
    </div>}
  </section>;
}
