import { useState, useMemo } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { factoryApi } from '@/api/factory';
import toast from 'react-hot-toast';
import { LuArrowLeft } from '@/lib/icons';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import { StickyActionBar } from '@/components/ui/StickyActionBar';
import { TouchConfirmSheet, useTouchSubmitLabel } from '@/components/layout/TouchShell';
import type { WorkOrderOutput } from '@/types/production';
import { formatTime } from '@/lib/formatDate';
import { workOrderStatusVariant } from '@/lib/statusVariants';
import { focusRing } from '@/lib/focus';
import { cn } from '@/lib/cn';

export default function RecordOutput() {
  const { woId } = useParams<{ woId: string }>();
  const queryClient = useQueryClient();

  // Fetch WO details
  const { data: ordersData } = useQuery({
    queryKey: ['factory', 'active-orders'],
    queryFn: () => factoryApi.activeOrders(),
  });

  const workOrder = useMemo(
    () => (ordersData?.data ?? []).find((wo) => wo.id === woId),
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
  const [confirming, setConfirming] = useState(false);

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

  const good = parseInt(goodCount, 10) || 0;
  const reject = parseInt(rejectCount, 10) || 0;
  const canSubmit = good > 0 || reject > 0;
  const submitLabel = useTouchSubmitLabel(mutation.isPending, 'Record output', 'Recording…');

  return (
    <div className="space-y-5 touch-manipulation">
      {/* Back link */}
      <Link
        to="/factory"
        className={cn(
          'inline-flex items-center gap-1.5 text-sm text-secondary min-h-[44px] rounded',
          focusRing,
        )}
      >
        <LuArrowLeft className="w-4 h-4" />
        Back to orders
      </Link>

      {/* WO Summary */}
      {workOrder && (
        <div className="rounded-md border border-default bg-canvas p-4">
          <div className="flex items-center justify-between">
            <span className="font-mono tabular-nums text-sm font-medium">
              {workOrder.wo_number}
            </span>
            <Chip variant={workOrderStatusVariant[workOrder.status]}>
              {workOrder.status_label ?? workOrder.status.replace(/_/g, ' ')}
            </Chip>
          </div>
          <div className="mt-1 text-sm font-medium">{workOrder.product?.name ?? '—'}</div>
          <div className="mt-2 flex items-baseline gap-2">
            <span className="text-xs text-muted">Progress:</span>
            <span className="font-mono tabular-nums text-lg font-medium">
              {workOrder.quantity_good}
            </span>
            <span className="text-xs text-muted">/ {workOrder.quantity_target}</span>
          </div>
          <div className="mt-2 h-2 rounded-full bg-elevated overflow-hidden">
            <div
              className="h-full rounded-full bg-accent transition-[width]"
              style={{ width: `${Math.min(workOrder.progress_percentage, 100)}%` }}
            />
          </div>
        </div>
      )}

      {/* Record Output Form */}
      <form
        onSubmit={(e) => {
          e.preventDefault();
          if (canSubmit) setConfirming(true);
        }}
        className="rounded-md border border-default bg-canvas p-4 space-y-4"
      >
        <h2 className="text-lg font-medium">Record output</h2>

        <Input
          id="good_count"
          label="Good quantity"
          fieldSize="xl"
          type="number"
          inputMode="numeric"
          min="0"
          value={goodCount}
          onChange={(e) => setGoodCount(e.target.value)}
          placeholder="0"
          className="text-center font-mono tabular-nums"
        />

        <Input
          id="reject_count"
          label="Reject quantity"
          fieldSize="xl"
          type="number"
          inputMode="numeric"
          min="0"
          value={rejectCount}
          onChange={(e) => setRejectCount(e.target.value)}
          placeholder="0"
          className="text-center font-mono tabular-nums"
        />

        <Textarea
          id="remarks"
          label="Notes (optional)"
          value={remarks}
          onChange={(e) => setRemarks(e.target.value)}
          rows={2}
          placeholder="Any observations…"
          className="resize-none"
        />

        <StickyActionBar>
          <Button
            type="submit"
            variant="primary"
            size="touch"
            className="w-full"
            disabled={!canSubmit}
            loading={mutation.isPending}
          >
            {submitLabel}
          </Button>
        </StickyActionBar>
      </form>

      <TouchConfirmSheet
        isOpen={confirming}
        onClose={() => setConfirming(false)}
        onConfirm={() => {
          setConfirming(false);
          mutation.mutate();
        }}
        title="Record this output?"
        confirmLabel="Record"
        variant={reject > 0 ? 'danger' : 'primary'}
        pending={mutation.isPending}
      >
        <p className="font-mono tabular-nums text-lg text-primary">
          {workOrder?.wo_number ?? 'this work order'}
        </p>
        <p>
          <span className="font-mono tabular-nums text-lg text-primary">{good}</span> good
          {reject > 0 && (
            <>
              {' · '}
              <span className="font-mono tabular-nums text-lg text-danger-fg">{reject}</span> reject
            </>
          )}
        </p>
        {reject > 0 && (
          <p className="text-sm">
            Rejects count against this order's scrap rate and cannot be undone here.
          </p>
        )}
      </TouchConfirmSheet>

      {/* Recent Outputs */}
      {outputs && outputs.length > 0 && (
        <div className="space-y-2">
          <h3 className="text-sm font-medium text-muted">Recent outputs</h3>
          {outputs.slice(0, 5).map((output: WorkOrderOutput) => (
            <div
              key={output.id}
              className="rounded-md border border-default bg-canvas p-3 flex items-center justify-between"
            >
              <div>
                <div className="flex items-center gap-3">
                  <span className="text-sm">
                    <span className="font-mono tabular-nums font-medium text-success-fg">
                      +{output.good_count}
                    </span>
                    {output.reject_count > 0 && (
                      <span className="font-mono tabular-nums text-danger-fg ml-2">
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
