import { useState, useMemo } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { factoryApi } from '@/api/factory';
import toast from 'react-hot-toast';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { TouchConfirmSheet, useTouchSubmitLabel } from '@/components/layout/TouchShell';
import type { WorkOrder } from '@/types/production';

export default function QcQuickCheck() {
  const queryClient = useQueryClient();

  // Fetch active WOs for selection
  const { data: ordersData, isLoading: ordersLoading } = useQuery({
    queryKey: ['factory', 'active-orders'],
    queryFn: () => factoryApi.activeOrders(),
  });

  const orders = useMemo(() => (ordersData?.data ?? []) as WorkOrder[], [ordersData]);

  // Form state
  const [selectedWoId, setSelectedWoId] = useState('');
  const [sampleSize, setSampleSize] = useState('');
  const [defectsFound, setDefectsFound] = useState('');
  const [notes, setNotes] = useState('');
  const [showFailPrompt, setShowFailPrompt] = useState(false);
  const [defectDescription, setDefectDescription] = useState('');
  // Which verdict is waiting on its confirmation sheet, if any.
  const [confirming, setConfirming] = useState<'passed' | 'failed' | null>(null);

  const selectedWo = useMemo(
    () => orders.find((wo) => wo.id === selectedWoId),
    [orders, selectedWoId],
  );

  const mutation = useMutation({
    mutationFn: (result: 'passed' | 'failed') => {
      if (
        !selectedWo?.product?.id ||
        selectedWo.quantity_target == null ||
        selectedWo.quantity_target <= 0
      ) {
        throw new Error('The selected work order has no authoritative product or target quantity.');
      }
      const parsedSampleSize = parseInt(sampleSize, 10);
      if (!Number.isInteger(parsedSampleSize) || parsedSampleSize <= 0) {
        throw new Error('Sample size must be a positive integer.');
      }
      const parsedDefects = parseInt(defectsFound, 10);
      if (!Number.isInteger(parsedDefects) || parsedDefects < 0) {
        throw new Error('Defects found must be a non-negative integer.');
      }
      // A FAIL raises an NCR, and that NCR is what feeds Pareto analysis and the
      // 8D corrective-action loop — both useless keyed on an empty string. The
      // reason is therefore required, not merely prompted for: the old two-step
      // FAIL rendered the textarea and then committed regardless of whether
      // anything had been typed into it.
      if (result === 'failed' && defectDescription.trim().length === 0) {
        throw new Error('Describe the defect before recording a FAIL.');
      }

      return factoryApi.quickQcCheck({
        stage: 'in_process',
        product_id: selectedWo.product.id,
        batch_quantity: selectedWo.quantity_target,
        entity_type: 'work_order',
        entity_id: selectedWoId,
        notes: [
          result === 'failed' ? `DEFECT: ${defectDescription.trim()}` : '',
          notes ? notes : '',
          `Quick check: ${result.toUpperCase()} | Samples: ${parsedSampleSize} | Defects: ${parsedDefects}`,
        ]
          .filter(Boolean)
          .join(' | '),
      });
    },
    onSuccess: (_data, result) => {
      if (result === 'passed') {
        toast.success('QC check passed');
      } else {
        toast.error('QC check recorded as FAILED');
      }
      // Reset form
      setSampleSize('');
      setDefectsFound('');
      setNotes('');
      setDefectDescription('');
      setShowFailPrompt(false);
      setConfirming(null);
      queryClient.invalidateQueries({ queryKey: ['factory'] });
    },
    onError: (err) => {
      toast.error(
        err instanceof Error ? err.message : 'Failed to submit QC check. Please try again.',
      );
      setConfirming(null);
    },
  });

  const countsValid =
    Boolean(selectedWoId) &&
    (parseInt(sampleSize, 10) || 0) > 0 &&
    /^(0|[1-9]\d*)$/.test(defectsFound);
  const canPass = countsValid;
  // FAIL needs the same counts plus a defect reason once the reason field is up.
  const canFail = countsValid && (!showFailPrompt || defectDescription.trim().length > 0);

  const submitLabel = useTouchSubmitLabel(mutation.isPending, '', 'Recording…');

  function handlePass() {
    if (!canPass) return;
    // A false PASS ships a defect to a customer and is the costlier error on an
    // IATF line, so it gets the same second step FAIL has always had.
    setConfirming('passed');
  }

  function handleFail() {
    if (!countsValid) return;
    // First tap reveals the required defect field; only the second can confirm.
    if (!showFailPrompt) {
      setShowFailPrompt(true);
      return;
    }
    if (!canFail) return;
    setConfirming('failed');
  }

  // One line naming the work order under inspection, reused in both sheets —
  // the operator's only check that he picked the right WO used to be an 11px
  // strip he reads past.
  const woSummary = selectedWo
    ? `${selectedWo.wo_number} · ${selectedWo.product?.part_number ?? selectedWo.product?.name ?? '—'}`
    : '';

  return (
    <div className="space-y-5 touch-manipulation">
      <h1 className="text-lg font-medium">Quick QC check</h1>

      {/* Work Order selection */}
      <div className="rounded-md border border-default bg-canvas p-4 space-y-4">
        {ordersLoading ? (
          <SkeletonBlock className="h-11 rounded-md" />
        ) : (
          <Select
            id="wo_select"
            label="Work order"
            fieldSize="lg"
            value={selectedWoId}
            onChange={(e) => {
              setSelectedWoId(e.target.value);
              setShowFailPrompt(false);
            }}
          >
            <option value="">Select a work order…</option>
            {orders.map((wo) => (
              <option key={wo.id} value={wo.id}>
                {wo.wo_number} — {wo.product?.name ?? '—'}
              </option>
            ))}
          </Select>
        )}

        {selectedWo && (
          <div className="text-base text-secondary bg-subtle rounded-md p-3 space-y-1">
            <div className="font-mono tabular-nums font-medium text-primary">
              {selectedWo.wo_number}
            </div>
            <div className="font-medium">{selectedWo.product?.part_number ?? '—'}</div>
            <div className="text-sm text-muted">
              Machine: {selectedWo.machine?.name ?? '—'} &middot; Target:{' '}
              <span className="font-mono tabular-nums">{selectedWo.quantity_target}</span>
            </div>
          </div>
        )}

        <Input
          id="sample_size"
          label="Sample size"
          fieldSize="xl"
          type="number"
          inputMode="numeric"
          min="1"
          value={sampleSize}
          onChange={(e) => setSampleSize(e.target.value)}
          placeholder="Enter measured value"
          className="text-center font-mono tabular-nums"
        />

        <Input
          id="defects_found"
          label="Defects found"
          fieldSize="xl"
          type="number"
          inputMode="numeric"
          min="0"
          value={defectsFound}
          onChange={(e) => setDefectsFound(e.target.value)}
          placeholder="0"
          className="text-center font-mono tabular-nums"
        />

        <Textarea
          id="qc_notes"
          label="Notes (optional)"
          value={notes}
          onChange={(e) => setNotes(e.target.value)}
          rows={2}
          placeholder="Visual observations…"
          className="resize-none"
        />

        {/* Fail prompt for defect description */}
        {showFailPrompt && (
          <div className="rounded-md border border-danger bg-danger-bg p-3">
            <Textarea
              id="defect_desc"
              label="Describe the defect"
              value={defectDescription}
              onChange={(e) => setDefectDescription(e.target.value)}
              rows={2}
              autoFocus
              required
              error={
                defectDescription.trim().length === 0
                  ? 'A FAIL raises an NCR — it needs a reason.'
                  : undefined
              }
              placeholder="What failed? (e.g. flash on parting line, short shot, burn marks)"
              className="resize-none"
            />
          </div>
        )}

        {/* Action buttons */}
        <div className="grid grid-cols-2 gap-3 pt-2">
          <Button
            type="button"
            variant="primary"
            size="touch"
            onClick={handlePass}
            disabled={!canPass}
            loading={mutation.isPending && confirming === null}
          >
            PASS
          </Button>
          <Button
            type="button"
            variant="danger"
            size="touch"
            onClick={handleFail}
            disabled={!countsValid}
            loading={mutation.isPending && confirming === null}
          >
            FAIL
          </Button>
        </div>
      </div>

      {/*
 Both verdicts confirm. PASS is the one that had no second step at all,
 though a false PASS is what actually reaches the customer; FAIL had a
 step but committed whatever was in the reason box, including nothing.
 */}
      <TouchConfirmSheet
        isOpen={confirming === 'passed'}
        onClose={() => setConfirming(null)}
        onConfirm={() => mutation.mutate('passed')}
        title="Record a PASS?"
        confirmLabel={submitLabel || 'Yes, record PASS'}
        variant="primary"
        pending={mutation.isPending}
      >
        <p>
          This releases the batch on{' '}
          <span className="font-mono tabular-nums font-medium text-primary">{woSummary}</span>.
        </p>
        <p>
          <span className="font-mono tabular-nums font-medium text-primary">
            {sampleSize || '0'}
          </span>{' '}
          sampled,{' '}
          <span className="font-mono tabular-nums font-medium text-primary">
            {defectsFound || '0'}
          </span>{' '}
          defects found.
        </p>
        <p className="text-muted">Check the work order number above is the one you inspected.</p>
      </TouchConfirmSheet>

      <TouchConfirmSheet
        isOpen={confirming === 'failed'}
        onClose={() => setConfirming(null)}
        onConfirm={() => mutation.mutate('failed')}
        title="Record a FAIL?"
        confirmLabel={submitLabel || 'Yes, record FAIL'}
        variant="danger"
        pending={mutation.isPending}
      >
        <p>
          This raises an NCR against{' '}
          <span className="font-mono tabular-nums font-medium text-primary">{woSummary}</span> and
          holds the batch.
        </p>
        <p>
          <span className="font-mono tabular-nums font-medium text-primary">
            {sampleSize || '0'}
          </span>{' '}
          sampled,{' '}
          <span className="font-mono tabular-nums font-medium text-primary">
            {defectsFound || '0'}
          </span>{' '}
          defects found.
        </p>
        <p>
          Defect: <span className="font-medium text-primary">{defectDescription.trim()}</span>
        </p>
      </TouchConfirmSheet>
    </div>
  );
}
