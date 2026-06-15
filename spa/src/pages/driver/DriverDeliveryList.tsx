import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { driverApi } from '@/api/driver';
import type { DriverDelivery, DriverDeliveryStatus } from '@/types/driver';

export default function DriverDeliveryList() {
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['driver', 'deliveries'],
    queryFn: () => driverApi.listDeliveries(),
  });

  if (isLoading) return <Skeleton />;
  if (error) return <ErrorRetry onRetry={refetch} />;

  const rows = (data?.data ?? []) as DriverDelivery[];
  if (rows.length === 0) {
    return (
      <div className="py-12 text-center text-zinc-500">
        No deliveries assigned today.
      </div>
    );
  }

  return (
    <div className="space-y-3">
      <h1 className="text-lg font-semibold">Today's Deliveries</h1>
      {rows.map(d => (
        <Link
          key={d.id}
          to={`/driver/${d.id}`}
          className="block rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
        >
          <div className="flex items-baseline justify-between">
            <div className="font-mono text-sm">{d.delivery_number}</div>
            <StatusChip status={d.status} />
          </div>
          <div className="mt-1 text-sm">
            {d.sales_order?.customer?.name ?? '—'}
          </div>
          <div className="mt-1 text-xs text-zinc-500">
            {d.sales_order?.so_number ?? '—'} · {d.vehicle?.plate_number ?? 'No vehicle'}
          </div>
        </Link>
      ))}
    </div>
  );
}

const STATUS_CLASSES: Record<DriverDeliveryStatus, string> = {
  scheduled:  'bg-zinc-200 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200',
  loading:    'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
  in_transit: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
  delivered:  'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
  confirmed:  'bg-emerald-200 text-emerald-900 dark:bg-emerald-800/60 dark:text-emerald-100',
  cancelled:  'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
};

function StatusChip({ status }: { status: DriverDeliveryStatus }) {
  const cls = STATUS_CLASSES[status] ?? 'bg-zinc-200 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200';
  return (
    <span className={`text-xs px-2 py-0.5 rounded ${cls}`}>
      {status.replace(/_/g, ' ')}
    </span>
  );
}

function Skeleton() {
  return (
    <div
      role="status"
      aria-live="polite"
      aria-busy="true"
      className="space-y-3 animate-pulse"
    >
      <span className="sr-only">Loading deliveries…</span>
      {[0, 1, 2].map(i => (
        <div key={i} className="h-20 rounded-lg bg-zinc-100 dark:bg-zinc-800" />
      ))}
    </div>
  );
}

function ErrorRetry({ onRetry }: { onRetry: () => void }) {
  return (
    <div className="py-12 text-center" role="alert">
      <div className="text-red-600 mb-2">Could not load deliveries.</div>
      <button
        type="button"
        onClick={onRetry}
        className="text-sm underline min-h-[44px] px-3 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 rounded"
      >
        Try again
      </button>
    </div>
  );
}
