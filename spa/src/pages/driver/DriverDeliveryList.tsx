import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { driverApi } from '@/api/driver';
import type { DriverDelivery, DriverDeliveryStatus } from '@/types/driver';
import { Button } from '@/components/ui/Button';
import { focusRing } from '@/lib/focus';
import { cn } from '@/lib/cn';

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
      <div className="py-12 text-center text-muted">
        No deliveries assigned today.
      </div>
    );
  }

  return (
    <div className="space-y-3">
      <h1 className="text-lg font-medium text-primary">Today's Deliveries</h1>
      {rows.map(d => (
        <Link
          key={d.id}
          to={`/driver/${d.id}`}
          className={cn('block rounded-md border border-default bg-canvas p-4 hover:bg-surface', focusRing)}
        >
          <div className="flex items-baseline justify-between">
            <div className="font-mono text-sm text-primary">{d.delivery_number}</div>
            <StatusChip status={d.status} />
          </div>
          <div className="mt-1 text-sm text-primary">
            {d.sales_order?.customer?.name ?? '—'}
          </div>
          <div className="mt-1 text-xs text-muted">
            {d.sales_order?.so_number ?? '—'} · {d.vehicle?.plate_number ?? 'No vehicle'}
          </div>
        </Link>
      ))}
    </div>
  );
}

const STATUS_CLASSES: Record<DriverDeliveryStatus, string> = {
  scheduled:  'bg-muted text-secondary',
  loading:    'bg-warning-bg text-warning-fg',
  in_transit: 'bg-info-bg text-info-fg',
  delivered:  'bg-success-bg text-success-fg',
  confirmed:  'bg-success-bg text-success-fg',
  cancelled:  'bg-danger-bg text-danger-fg',
};

function StatusChip({ status }: { status: DriverDeliveryStatus }) {
  const cls = STATUS_CLASSES[status] ?? 'bg-subtle text-muted';
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
        <div key={i} className="h-20 rounded-md bg-elevated" />
      ))}
    </div>
  );
}

function ErrorRetry({ onRetry }: { onRetry: () => void }) {
  return (
    <div className="py-12 text-center" role="alert">
      <div className="text-danger mb-2">Could not load deliveries.</div>
      <Button variant="secondary" size="lg" className="min-h-[44px]" onClick={onRetry}>
        Try again
      </Button>
    </div>
  );
}
