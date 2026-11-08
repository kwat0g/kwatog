import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import type { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { inspectionsApi } from '@/api/quality/inspections';
import { Button } from '@/components/ui/Button';
import { Textarea } from '@/components/ui/Textarea';
import { QuantityInspectionPanel } from './QuantityInspectionPanel';
import type { WorkOrderFinalInspection } from '@/types/quality-final';

export function SimpleWorkOrderFinalInspectionPanel({ group, canManage, canReview, onUpdated }: {
  group: WorkOrderFinalInspection; canManage: boolean; canReview: boolean;
  onUpdated?: (data: WorkOrderFinalInspection) => void;
}) {
  const [current, setCurrent] = useState(group);
  const [remarks, setRemarks] = useState('');
  const [refreshError, setRefreshError] = useState(false);
  const accept = (next: WorkOrderFinalInspection) => { setCurrent(next); onUpdated?.(next); setRefreshError(false); };
  const refresh = async () => {
    try { accept(await inspectionsApi.getWorkOrderFinalInspection(current.work_order_id)); }
    catch { setRefreshError(true); }
  };
  const waiting = current.batches.filter(batch => batch.status === 'awaiting_review');
  const hasDrafts = current.batches.some(batch => ['draft', 'in_progress'].includes(batch.status));
  const failed = waiting.some(batch => batch.proposed_result === 'failed');
  const review = useMutation({
    mutationFn: () => inspectionsApi.reviewWorkOrderFinalInspection(current.work_order_id, { manifest: current.manifest, ...(remarks.trim() ? { remarks: remarks.trim() } : {}) }),
    onSuccess: accept,
    onError: (error: AxiosError<{ message?: string }>) => {
      toast.error(error.response?.data?.message ?? 'Could not review inspection. Reload the latest result.');
      void refresh();
    },
  });
  return <div className="space-y-5">
    <p className="text-sm text-muted">Work order {current.wo_number ?? current.work_order_id} · {current.produced_quantity} {current.context?.product?.unit_of_measure ?? 'PCS'}</p>
    {current.blocker && <p role="status" className="text-sm text-warning-fg">{current.blocker}</p>}
    {current.batches.map(batch => <div key={batch.id} className="space-y-2">
      <h2 className="text-sm font-medium">{batch.inspection_number}{batch.work_order_output?.batch_code ? ` · ${batch.work_order_output.batch_code}` : ''}</h2>
      <QuantityInspectionPanel inspection={batch} canManage={canManage && !current.blocker && !refreshError} onUpdated={refresh} />
    </div>)}
    {refreshError && <p role="alert" className="text-sm text-danger-fg">The result was saved. <Button variant="secondary" size="sm" onClick={() => void refresh()}>Reload saved progress</Button></p>}
    {waiting.length > 0 && <p role="status" className="text-sm text-muted">{hasDrafts ? 'Submit the remaining batches before independent QC review.' : 'Awaiting independent QC review before release.'}</p>}
    {canReview && !hasDrafts && waiting.length > 0 && waiting.every(batch => batch.can_review) && <section aria-label="Independent QC review" className="space-y-4 rounded-md border border-default bg-canvas p-4">
      <h2 className="text-sm font-medium">Independent QC review</h2>
      <Textarea label="Review remarks" required={failed} maxLength={2000} rows={2} value={remarks} onChange={event => setRemarks(event.target.value)} />
      <Button variant="primary" loading={review.isPending} disabled={review.isPending || (failed && !remarks.trim())} onClick={() => review.mutate()}>Confirm {failed ? 'FAIL' : 'PASS'}</Button>
    </section>}
  </div>;
}
