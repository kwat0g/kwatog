import { useState, useMemo } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { factoryApi } from '@/api/factory';
import toast from 'react-hot-toast';
import { ArrowLeft } from 'lucide-react';
import type { WorkOrderOutput } from '@/types/production';
import { formatTime } from '@/lib/formatDate';

export default function RecordOutput() {
  const { woId } = useParams<{ woId: string }>();
  const queryClient = useQueryClient();

  // Fetch WO details
  const { data: ordersData } = useQuery({
    queryKey: ['factory', 'active-orders'],
    queryFn: () => factoryApi.activeOrders(),
  });

  const workOrder = useMemo(
    () => (ordersData?.data ?? []).find(wo => wo.id === woId),
    [ordersData, woId],
  );

  // Fetch recent outputs
  const { data: outputs } = useQuery({
    queryKey: ['factory', 'outputs', woId],
    queryFn: () => factoryApi.listOutputs(woId!),
    enabled: !!woId,
  });

  // Form state
  const [goodCount, setGoodCount] = useState('');
  const [rejectCount, setRejectCount] = useState('');
  const [remarks, setRemarks] = useState('');
  const [idempotencyKey, setIdempotencyKey] = useState(() => crypto.randomUUID());

  const mutation = useMutation({
    mutationFn: () =>
      factoryApi.recordOutput(
        woId!,
        {
          good_count: parseInt(goodCount, 10) || 0,
          reject_count: parseInt(rejectCount, 10) || 0,
          remarks: remarks.trim() || undefined,
        },
        idempotencyKey,
      ),
    onSuccess: () => {
      toast.success('Output recorded');
      setGoodCount('');
      setRejectCount('');
      setRemarks('');
      setIdempotencyKey(crypto.randomUUID()); // New key for next submission
      queryClient.invalidateQueries({ queryKey: ['factory', 'outputs', woId] });
      queryClient.invalidateQueries({ queryKey: ['factory', 'active-orders'] });
    },
    onError: () => {
      toast.error('Failed to record output. Please try again.');
    },
  });

  const canSubmit = (parseInt(goodCount, 10) || 0) > 0 || (parseInt(rejectCount, 10) || 0) > 0;

  return (
    <div className="space-y-5 touch-manipulation">
      {/* Back link */}
      <Link
        to="/factory"
        className="inline-flex items-center gap-1.5 text-sm text-secondary min-h-[44px] rounded focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2"
      >
        <ArrowLeft className="w-4 h-4" />
        Back to orders
      </Link>

      {/* WO Summary */}
      {workOrder && (
        <div className="rounded-md border border-default bg-canvas p-4">
          <div className="flex items-center justify-between">
            <span className="font-mono text-sm font-medium">{workOrder.wo_number}</span>
            <span className="text-xs px-2 py-0.5 rounded bg-success-bg text-success-fg font-medium">
              {workOrder.status.replace(/_/g, ' ')}
            </span>
          </div>
          <div className="mt-1 text-sm font-medium">{workOrder.product?.name ?? 'Unknown'}</div>
          <div className="mt-2 flex items-baseline gap-2">
            <span className="text-xs text-muted">Progress:</span>
            <span className="font-mono tabular-nums text-lg font-medium">
              {workOrder.quantity_good}
            </span>
            <span className="text-xs text-muted">/ {workOrder.quantity_target}</span>
          </div>
          <div className="mt-2 h-2 rounded-full bg-elevated overflow-hidden">
            <div
              className="h-full rounded-full bg-accent transition-all"
              style={{ width: `${Math.min(workOrder.progress_percentage, 100)}%` }}
            />
          </div>
        </div>
      )}

      {/* Record Output Form */}
      <form
        onSubmit={e => {
          e.preventDefault();
          if (canSubmit) mutation.mutate();
        }}
        className="rounded-md border border-default bg-canvas p-4 space-y-4"
      >
        <h2 className="text-base font-medium">Record Output</h2>

        <div>
          <label htmlFor="good_count" className="block text-sm font-medium text-secondary mb-1">
            Good Quantity
          </label>
          <input
            id="good_count"
            type="number"
            inputMode="numeric"
            min="0"
            value={goodCount}
            onChange={e => setGoodCount(e.target.value)}
            placeholder="0"
            className="w-full rounded-md border border-default bg-canvas px-4 py-4 text-2xl font-mono tabular-nums text-center focus:outline-none focus:ring-2 focus:ring-accent focus:border-accent"
          />
        </div>

        <div>
          <label htmlFor="reject_count" className="block text-sm font-medium text-secondary mb-1">
            Reject Quantity
          </label>
          <input
            id="reject_count"
            type="number"
            inputMode="numeric"
            min="0"
            value={rejectCount}
            onChange={e => setRejectCount(e.target.value)}
            placeholder="0"
            className="w-full rounded-md border border-default bg-canvas px-4 py-4 text-2xl font-mono tabular-nums text-center focus:outline-none focus:ring-2 focus:ring-accent focus:border-accent"
          />
        </div>

        <div>
          <label htmlFor="remarks" className="block text-sm font-medium text-secondary mb-1">
            Notes (optional)
          </label>
          <textarea
            id="remarks"
            value={remarks}
            onChange={e => setRemarks(e.target.value)}
            rows={2}
            placeholder="Any observations..."
            className="w-full rounded-md border border-default bg-canvas px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-accent focus:border-accent resize-none"
          />
        </div>

        <button
          type="submit"
          disabled={!canSubmit || mutation.isPending}
          className="w-full min-h-[52px] rounded-md bg-accent hover:bg-accent-hover disabled:bg-elevated disabled:text-muted text-accent-fg font-medium text-base transition-colors focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2"
        >
          {mutation.isPending ? 'Recording...' : 'Record Output'}
        </button>
      </form>

      {/* Recent Outputs */}
      {outputs && outputs.length > 0 && (
        <div className="space-y-2">
          <h3 className="text-sm font-medium text-muted">Recent Outputs</h3>
          {outputs.slice(0, 5).map((output: WorkOrderOutput) => (
            <div
              key={output.id}
              className="rounded-md border border-default bg-canvas p-3 flex items-center justify-between"
            >
              <div>
                <div className="flex items-center gap-3">
                  <span className="text-sm">
                    <span className="font-mono tabular-nums font-medium text-success">
                      +{output.good_count}
                    </span>
                    {output.reject_count > 0 && (
                      <span className="font-mono tabular-nums text-danger ml-2">
                        -{output.reject_count}
                      </span>
                    )}
                  </span>
                </div>
                {output.remarks && (
                  <div className="text-xs text-muted mt-0.5">{output.remarks}</div>
                )}
              </div>
              <div className="text-xs text-muted font-mono tabular-nums">
                {formatTime(output.recorded_at)}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
