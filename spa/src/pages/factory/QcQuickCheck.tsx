import { useState, useMemo } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { factoryApi } from '@/api/factory';
import toast from 'react-hot-toast';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { SkeletonBlock } from '@/components/ui/Skeleton';
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
  const [defectsFound, setDefectsFound] = useState('0');
  const [notes, setNotes] = useState('');
  const [showFailPrompt, setShowFailPrompt] = useState(false);
  const [defectDescription, setDefectDescription] = useState('');

  const selectedWo = useMemo(
    () => orders.find(wo => wo.id === selectedWoId),
    [orders, selectedWoId],
  );

  const mutation = useMutation({
    mutationFn: (result: 'passed' | 'failed') => {
      const parsedSampleSize = parseInt(sampleSize, 10) || 1;
      const parsedDefects = parseInt(defectsFound, 10) || 0;

      return factoryApi.quickQcCheck({
        stage: 'in_process',
        product_id: selectedWo?.product?.id ?? '',
        batch_quantity: selectedWo?.quantity_target ?? 0,
        entity_type: 'work_order',
        entity_id: selectedWoId,
        notes: [
          result === 'failed' && defectDescription ? `DEFECT: ${defectDescription}` : '',
          notes ? notes : '',
          `Quick check: ${result.toUpperCase()} | Samples: ${parsedSampleSize} | Defects: ${parsedDefects}`,
        ].filter(Boolean).join(' | '),
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
      setDefectsFound('0');
      setNotes('');
      setDefectDescription('');
      setShowFailPrompt(false);
      queryClient.invalidateQueries({ queryKey: ['factory'] });
    },
    onError: () => {
      toast.error('Failed to submit QC check. Please try again.');
    },
  });

  const canSubmit = selectedWoId && (parseInt(sampleSize, 10) || 0) > 0;

  function handlePass() {
    if (!canSubmit) return;
    mutation.mutate('passed');
  }

  function handleFail() {
    if (!canSubmit) return;
    if (!showFailPrompt) {
      setShowFailPrompt(true);
      return;
    }
    mutation.mutate('failed');
  }

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
            onChange={e => {
              setSelectedWoId(e.target.value);
              setShowFailPrompt(false);
            }}
          >
            <option value="">Select a work order…</option>
            {orders.map(wo => (
              <option key={wo.id} value={wo.id}>
                {wo.wo_number} — {wo.product?.name ?? 'Unknown'}
              </option>
            ))}
          </Select>
        )}

        {selectedWo && (
          <div className="text-xs text-muted bg-subtle rounded-md p-2">
            <span className="font-medium text-secondary">{selectedWo.product?.part_number}</span>
            {' '}&middot;{' '}
            Machine: {selectedWo.machine?.name ?? 'N/A'}
            {' '}&middot;{' '}
            Target: <span className="font-mono tabular-nums">{selectedWo.quantity_target}</span>
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
          onChange={e => setSampleSize(e.target.value)}
          placeholder="e.g. 5"
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
          onChange={e => setDefectsFound(e.target.value)}
          placeholder="0"
          className="text-center font-mono tabular-nums"
        />

        <Textarea
          id="qc_notes"
          label="Notes (optional)"
          value={notes}
          onChange={e => setNotes(e.target.value)}
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
              onChange={e => setDefectDescription(e.target.value)}
              rows={2}
              autoFocus
              placeholder="What failed? (e.g. flash on parting line, short shot, burn marks)"
              className="resize-none"
            />
          </div>
        )}

        {/* Action buttons */}
        <div className="grid grid-cols-2 gap-3 pt-2">
          <Button
            type="button"
            variant="success"
            size="xl"
            onClick={handlePass}
            disabled={!canSubmit}
            loading={mutation.isPending}
          >
            PASS
          </Button>
          <Button
            type="button"
            variant="danger"
            size="xl"
            onClick={handleFail}
            disabled={!canSubmit}
            loading={mutation.isPending}
          >
            FAIL
          </Button>
        </div>
      </div>
    </div>
  );
}
