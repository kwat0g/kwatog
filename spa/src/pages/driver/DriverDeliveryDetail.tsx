import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { isAxiosError } from 'axios';
import { driverApi } from '@/api/driver';
import type { DriverDeliveryStatus } from '@/types/driver';

const NEXT: Partial<Record<DriverDeliveryStatus, DriverDeliveryStatus>> = {
  scheduled: 'loading',
  loading: 'in_transit',
  in_transit: 'delivered',
};

const ACTION_LABEL: Partial<Record<DriverDeliveryStatus, string>> = {
  scheduled: 'Mark Loading',
  loading: 'Mark In Transit',
  in_transit: 'Mark Delivered',
};

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

  const transition = useMutation({
    mutationFn: (next: DriverDeliveryStatus) => driverApi.updateStatus(id, next),
    onSuccess: (fresh) => {
      qc.invalidateQueries({ queryKey: ['driver'] });
      toast.success(`Status: ${fresh.status.replace(/_/g, ' ')}`);
    },
    onError: (err) => toast.error(describeAxiosError(err, 'Could not update status.')),
  });

  if (isLoading) {
    return (
      <div
        role="status"
        aria-live="polite"
        aria-busy="true"
        className="py-12 text-center text-zinc-500"
      >
        <span className="sr-only">Loading delivery…</span>
        Loading…
      </div>
    );
  }

  if (error || !data) {
    return (
      <div className="py-12 text-center" role="alert">
        <div className="text-red-600 mb-2">Could not load delivery.</div>
        <button
          type="button"
          onClick={() => refetch()}
          disabled={isFetching}
          className="text-sm underline disabled:opacity-60 min-h-[44px] px-3 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 rounded"
        >
          {isFetching ? 'Retrying…' : 'Try again'}
        </button>
        <div className="mt-4">
          <Link to="/driver" className="text-sm text-zinc-500 underline">All deliveries</Link>
        </div>
      </div>
    );
  }

  const next = NEXT[data.status];
  const label = ACTION_LABEL[data.status];

  return (
    <div className="space-y-4">
      <Link
        to="/driver"
        className="inline-block text-sm text-zinc-500 underline min-h-[44px] py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 rounded"
      >
        ← All deliveries
      </Link>

      <div className="rounded-lg border border-zinc-200 dark:border-zinc-800 p-4 bg-white dark:bg-zinc-900">
        <div className="font-mono">{data.delivery_number}</div>
        <div className="mt-2 text-sm space-y-1">
          <div>
            <span className="text-zinc-500">Customer:</span>{' '}
            {data.sales_order?.customer?.name ?? '—'}
          </div>
          <div>
            <span className="text-zinc-500">SO:</span>{' '}
            {data.sales_order?.so_number ?? '—'}
          </div>
          <div>
            <span className="text-zinc-500">Vehicle:</span>{' '}
            {data.vehicle?.plate_number ?? '—'}
          </div>
          <div>
            <span className="text-zinc-500">Status:</span>{' '}
            <strong>{data.status.replace(/_/g, ' ')}</strong>
          </div>
        </div>
      </div>

      {next && label && (
        <button
          type="button"
          disabled={transition.isPending}
          onClick={() => transition.mutate(next)}
          className="w-full rounded-lg bg-indigo-600 text-white py-3 font-medium disabled:opacity-60 min-h-[44px] focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
        >
          {transition.isPending ? 'Updating…' : label}
        </button>
      )}

      {data.status === 'delivered' && (
        <button
          type="button"
          onClick={() => navigate(`/driver/${id}/photo`)}
          className="w-full rounded-lg border border-zinc-300 dark:border-zinc-700 py-3 font-medium min-h-[44px] focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
        >
          {(data.proofs?.length ?? 0) > 0 ? 'Replace Receipt Photo' : 'Capture Receipt Photo'}
        </button>
      )}
    </div>
  );
}
