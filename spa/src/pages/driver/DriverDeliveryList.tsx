import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { driverApi } from '@/api/driver';
import type { DriverDeliveryStatus } from '@/types/driver';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { TouchCardSkeleton } from '@/components/layout/TouchShell';
import { deliveryStatusVariant } from '@/lib/statusVariants';
import { focusRing } from '@/lib/focus';
import { cn } from '@/lib/cn';
import { Select } from '@/components/ui/Select';
import { Input } from '@/components/ui/Input';

export default function DriverDeliveryList() {
 const [page, setPage] = useState(1);
 const [status, setStatus] = useState('');
 const [scheduledDate, setScheduledDate] = useState('');
 const params = {
  page,
  per_page: 25,
  ...(status ? { status } : {}),
  ...(scheduledDate ? { scheduled_date: scheduledDate } : {}),
 };
 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['driver', 'deliveries', params],
 queryFn: () => driverApi.listDeliveries(params),
 });

 const rows = data?.data ?? [];
 const currentPage = data?.meta?.current_page ?? page;
 const lastPage = data?.meta?.last_page ?? page;

 return (
 <div className="space-y-3">
 <h1 className="text-lg font-medium text-primary">Assigned deliveries</h1>
 <p className="text-xs text-muted">Future deliveries stay visible, but loading is available only on the scheduled date.</p>

 <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
 <Select
  aria-label="Filter delivery status"
  value={status}
  onChange={(e) => { setStatus(e.target.value); setPage(1); }}
  fieldSize="lg"
 >
  <option value="">All actionable statuses</option>
  {(['scheduled', 'loading', 'in_transit', 'delivered'] as DriverDeliveryStatus[]).map((value) => (
  <option key={value} value={value}>{value.replace(/_/g, ' ')}</option>
  ))}
 </Select>
 <Input
  aria-label="Filter scheduled date"
  type="date"
  fieldSize="lg"
  value={scheduledDate}
  onChange={(e) => { setScheduledDate(e.target.value); setPage(1); }}
  placeholder="Scheduled date"
 />
 </div>

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
 description="No actionable deliveries match the selected filters."
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

 {!isLoading && !isError && rows.length > 0 && lastPage > 1 && (
 <div className="flex items-center justify-between gap-3 pt-2">
 <Button
  variant="secondary"
  size="lg"
  className="min-h-hit"
  disabled={currentPage <= 1}
  onClick={() => setPage((value) => Math.max(1, value - 1))}
 >
  Previous
 </Button>
 <span className="text-xs text-muted font-mono tabular-nums">Page {currentPage} of {lastPage}</span>
 <Button
  variant="secondary"
  size="lg"
  className="min-h-hit"
  disabled={currentPage >= lastPage}
  onClick={() => setPage((value) => value + 1)}
 >
  Next
 </Button>
 </div>
 )}
 </div>
 );
}
