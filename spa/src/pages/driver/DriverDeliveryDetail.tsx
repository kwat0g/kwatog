import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { isAxiosError } from 'axios';
import { driverApi } from '@/api/driver';
import type { DriverDeliveryStatus } from '@/types/driver';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import {
  TouchCardSkeleton,
  TouchConfirmSheet,
  useTouchSubmitLabel,
} from '@/components/layout/TouchShell';
import { focusRing } from '@/lib/focus';
import { cn } from '@/lib/cn';

/** Extract a useful message from an axios error, preferring 422 field errors. */
function describeAxiosError(err: unknown, fallback: string): string {
  if (isAxiosError(err) && err.response) {
    if (err.response.status === 422) {
      const errors = err.response.data?.errors as Record<string, string[]> | undefined;
      if (errors) {
        const first = Object.values(errors)[0]?.[0];
        if (first) return first;
      }
      const msg = err.response.data?.message;
      if (typeof msg === 'string' && msg.length > 0) return msg;
    }
    if (err.response.status === 404) return 'Delivery not found or no longer assigned to you.';
  }
  return fallback;
}

export default function DriverDeliveryDetail() {
  const { id = '' } = useParams();
  const navigate = useNavigate();
  const qc = useQueryClient();

  const { data, isLoading, error, refetch, isFetching } = useQuery({
    queryKey: ['driver', 'delivery', id],
    queryFn: () => driverApi.showDelivery(id),
    enabled: Boolean(id),
  });

  // Which transition is waiting on its confirmation sheet, if any.
  const [confirming, setConfirming] = useState<DriverDeliveryStatus | null>(null);

  const transition = useMutation({
    mutationFn: (next: DriverDeliveryStatus) => driverApi.updateStatus(id, next),
    onSuccess: (fresh) => {
      qc.invalidateQueries({ queryKey: ['driver'] });
      toast.success(`Status: ${fresh.status_label ?? fresh.status.replace(/_/g, ' ')}`);
      setConfirming(null);
    },
    onError: (err) => {
      toast.error(describeAxiosError(err, 'Could not update status.'));
      setConfirming(null);
    },
  });

  // A driver on a delivery route is the likeliest user of all to be out of
  // signal, so the button says the commit is queued rather than looking stalled.
  const advanceLabel = useTouchSubmitLabel(transition.isPending, '', 'Updating…');

  if (isLoading) {
    return <TouchCardSkeleton count={2} label="Loading delivery" cardClassName="h-40" />;
  }

  if (error || !data) {
    return (
      <EmptyState
        icon="alert-circle"
        title="Could not load delivery"
        description="Check your connection and try again."
        action={
          <div className="flex flex-col items-center gap-3">
            <Button
              variant="secondary"
              size="lg"
              className="min-h-hit"
              disabled={isFetching}
              onClick={() => refetch()}
            >
              {isFetching ? 'Retrying…' : 'Try again'}
            </Button>
            <Link to="/driver" className="text-sm text-link hover:underline">
              All deliveries
            </Link>
          </div>
        }
      />
    );
  }

  const next = data.next_status ?? undefined;
  const label = data.next_status_label ? `Mark ${data.next_status_label}` : undefined;
  // The button sits in a fixed position but its meaning is server-derived, so the
  // same pixel is "Mark in transit" on one load and "Mark delivered" on the next.
  // Naming the transition in a sheet is the only thing standing between a
  // mis-tap and a delivery confirmed at the wrong gate.
  const isCustomerConfirm = next === 'delivered';

  return (
    <div className="space-y-4">
      <Link
        to="/driver"
        className={cn(
          'inline-block text-sm text-muted underline min-h-hit py-2 rounded',
          focusRing,
        )}
      >
        ← All deliveries
      </Link>

      <div className="rounded-md border border-default p-4 bg-canvas">
        <div className="font-mono text-primary">{data.delivery_number}</div>
        <div className="mt-2 text-sm text-primary space-y-1">
          <div>
            <span className="text-muted">Customer:</span> {data.sales_order?.customer?.name ?? '—'}
          </div>
          <div>
            <span className="text-muted">SO:</span> {data.sales_order?.so_number ?? '—'}
          </div>
          <div>
            <span className="text-muted">Vehicle:</span> {data.vehicle?.plate_number ?? '—'}
          </div>
          <div>
            <span className="text-muted">Status:</span>{' '}
            <strong>{data.status_label ?? data.status.replace(/_/g, ' ')}</strong>
          </div>
        </div>
      </div>

      {next && label && (
        <Button
          variant="primary"
          size="touch"
          className="w-full"
          loading={transition.isPending}
          onClick={() => setConfirming(next)}
        >
          {advanceLabel || label}
        </Button>
      )}

      {data.status === 'delivered' && (
        <Button
          variant="secondary"
          size="touch"
          className="w-full"
          onClick={() => navigate(`/driver/${id}/photo`)}
        >
          {(data.proofs?.length ?? 0) > 0 ? 'Replace receipt photo' : 'Capture receipt photo'}
        </Button>
      )}

      <TouchConfirmSheet
        isOpen={confirming !== null}
        onClose={() => setConfirming(null)}
        onConfirm={() => confirming && transition.mutate(confirming)}
        title={label ? `${label}?` : 'Advance this delivery?'}
        confirmLabel={advanceLabel || (label ?? 'Confirm')}
        variant={isCustomerConfirm ? 'danger' : 'primary'}
        pending={transition.isPending}
      >
        <p>
          <span className="font-mono tabular-nums font-medium text-primary">
            {data.delivery_number ?? data.sales_order?.so_number ?? 'This delivery'}
          </span>
          {data.sales_order?.customer?.name ? ` · ${data.sales_order.customer.name}` : ''}
        </p>
        <p>
          Status moves from{' '}
          <span className="font-medium text-primary">
            {data.status_label ?? data.status.replace(/_/g, ' ')}
          </span>{' '}
          to{' '}
          <span className="font-medium text-primary">
            {data.next_status_label ?? confirming?.replace(/_/g, ' ')}
          </span>
          .
        </p>
        {isCustomerConfirm && (
          <p>Marking delivered records customer receipt. Capture the receipt photo first.</p>
        )}
      </TouchConfirmSheet>
    </div>
  );
}
