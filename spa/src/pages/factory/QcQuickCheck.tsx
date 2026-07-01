import { useState, useMemo } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { factoryApi } from '@/api/factory';
import toast from 'react-hot-toast';
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
      <h1 className="text-lg font-semibold">Quick QC Check</h1>

      {/* Work Order selection */}
      <div className="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4 space-y-4">
        <div>
          <label htmlFor="wo_select" className="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
            Work Order
          </label>
          {ordersLoading ? (
            <div className="h-12 rounded-lg bg-zinc-100 dark:bg-zinc-800 animate-pulse" />
          ) : (
            <select
              id="wo_select"
              value={selectedWoId}
              onChange={e => {
                setSelectedWoId(e.target.value);
                setShowFailPrompt(false);
              }}
              className="w-full rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 min-h-[44px]"
            >
              <option value="">Select a work order...</option>
              {orders.map(wo => (
                <option key={wo.id} value={wo.id}>
                  {wo.wo_number} — {wo.product?.name ?? 'Unknown'}
                </option>
              ))}
            </select>
          )}
        </div>

        {selectedWo && (
          <div className="text-xs text-zinc-500 bg-zinc-50 dark:bg-zinc-800/50 rounded p-2">
            <span className="font-medium">{selectedWo.product?.part_number}</span>
            {' '}&middot;{' '}
            Machine: {selectedWo.machine?.name ?? 'N/A'}
            {' '}&middot;{' '}
            Target: <span className="font-mono tabular-nums">{selectedWo.quantity_target}</span>
          </div>
        )}

        <div>
          <label htmlFor="sample_size" className="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
            Sample Size
          </label>
          <input
            id="sample_size"
            type="number"
            inputMode="numeric"
            min="1"
            value={sampleSize}
            onChange={e => setSampleSize(e.target.value)}
            placeholder="e.g. 5"
            className="w-full rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-4 py-4 text-xl font-mono tabular-nums text-center focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
          />
        </div>

        <div>
          <label htmlFor="defects_found" className="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
            Defects Found
          </label>
          <input
            id="defects_found"
            type="number"
            inputMode="numeric"
            min="0"
            value={defectsFound}
            onChange={e => setDefectsFound(e.target.value)}
            placeholder="0"
            className="w-full rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-4 py-4 text-xl font-mono tabular-nums text-center focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
          />
        </div>

        <div>
          <label htmlFor="qc_notes" className="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
            Notes (optional)
          </label>
          <textarea
            id="qc_notes"
            value={notes}
            onChange={e => setNotes(e.target.value)}
            rows={2}
            placeholder="Visual observations..."
            className="w-full rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 resize-none"
          />
        </div>

        {/* Fail prompt for defect description */}
        {showFailPrompt && (
          <div className="rounded-lg border-2 border-red-300 dark:border-red-700 bg-red-50 dark:bg-red-900/20 p-3">
            <label htmlFor="defect_desc" className="block text-sm font-medium text-red-700 dark:text-red-300 mb-1">
              Describe the defect
            </label>
            <textarea
              id="defect_desc"
              value={defectDescription}
              onChange={e => setDefectDescription(e.target.value)}
              rows={2}
              autoFocus
              placeholder="What failed? (e.g. flash on parting line, short shot, burn marks)"
              className="w-full rounded-lg border border-red-300 dark:border-red-700 bg-white dark:bg-zinc-800 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500 resize-none"
            />
          </div>
        )}

        {/* Action buttons */}
        <div className="grid grid-cols-2 gap-3 pt-2">
          <button
            type="button"
            onClick={handlePass}
            disabled={!canSubmit || mutation.isPending}
            className="min-h-[56px] rounded-lg bg-emerald-600 hover:bg-emerald-700 disabled:bg-zinc-300 dark:disabled:bg-zinc-700 text-white font-bold text-lg transition-colors focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2"
          >
            {mutation.isPending ? '...' : 'PASS'}
          </button>
          <button
            type="button"
            onClick={handleFail}
            disabled={!canSubmit || mutation.isPending}
            className="min-h-[56px] rounded-lg bg-red-600 hover:bg-red-700 disabled:bg-zinc-300 dark:disabled:bg-zinc-700 text-white font-bold text-lg transition-colors focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2"
          >
            {mutation.isPending ? '...' : 'FAIL'}
          </button>
        </div>
      </div>
    </div>
  );
}
