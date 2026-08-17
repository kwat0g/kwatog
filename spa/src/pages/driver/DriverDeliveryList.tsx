import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { driverApi } from '@/api/driver';
import type { DriverDelivery } from '@/types/driver';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { TouchCardSkeleton } from '@/components/layout/TouchShell';
import { deliveryStatusVariant } from '@/lib/statusVariants';
import { focusRing } from '@/lib/focus';
import { cn } from '@/lib/cn';

export default function DriverDeliveryList() {
 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['driver', 'deliveries'],
 queryFn: () => driverApi.listDeliveries(),
 });

 const rows = (data?.data ?? []) as DriverDelivery[];

 return (
 <div className="space-y-3">
 <h1 className="text-lg font-medium text-primary">Today's deliveries</h1>

 {isLoading && <TouchCardSkeleton count={3} label="Loading deliveries" cardClassName="h-24" />}

 {isError && !isLoading && (
 <EmptyState
 icon="alert-circle"
 title="Could not load deliveries"
 description="Check your connection and try again."
 action={
 <Button variant="secondary" size="lg" className="min-h-hit" onClick={() => refetch()}>
 Try again
 </Button>
 }
 />
 )}

 {!isLoading && !isError && rows.length === 0 && (
 <EmptyState
 icon="truck"
 title="No deliveries assigned"
 description="Nothing is scheduled for you today."
 />
 )}

 {rows.map((d) => (
 <Link
 key={d.id}
 to={`/driver/${d.id}`}
 className={cn('block rounded-md border border-default bg-canvas p-4 active:bg-subtle', focusRing)}
 >
 <div className="flex items-baseline justify-between gap-2">
 <div className="font-mono tabular-nums text-sm text-primary">{d.delivery_number}</div>
 <Chip variant={deliveryStatusVariant[d.status]}>{d.status_label ?? d.status.replace(/_/g, ' ')}</Chip>
 </div>
 <div className="mt-1 text-sm text-primary">{d.sales_order?.customer?.name ?? '—'}</div>
 <div className="mt-1 text-xs text-muted">
 <span className="font-mono tabular-nums">{d.sales_order?.so_number ?? '—'}</span>
 {' · '}
 {d.vehicle?.plate_number ?? 'No vehicle'}
 </div>
 </Link>
 ))}
 </div>
 );
}
