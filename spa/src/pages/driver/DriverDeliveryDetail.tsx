import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { isAxiosError } from 'axios';
import { driverApi } from '@/api/driver';
import type { DriverDeliveryStatus } from '@/types/driver';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { TouchCardSkeleton } from '@/components/layout/TouchShell';
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

 const transition = useMutation({
 mutationFn: (next: DriverDeliveryStatus) => driverApi.updateStatus(id, next),
 onSuccess: (fresh) => {
 qc.invalidateQueries({ queryKey: ['driver'] });
 toast.success(`Status: ${fresh.status_label ?? fresh.status.replace(/_/g, ' ')}`);
 },
 onError: (err) => toast.error(describeAxiosError(err, 'Could not update status.')),
 });

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
 className="min-h-[44px]"
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

 return (
 <div className="space-y-4">
 <Link
 to="/driver"
 className={cn('inline-block text-sm text-muted underline min-h-[44px] py-2 rounded', focusRing)}
 >
 ← All deliveries
 </Link>

 <div className="rounded-md border border-default p-4 bg-canvas">
 <div className="font-mono text-primary">{data.delivery_number}</div>
 <div className="mt-2 text-sm text-primary space-y-1">
 <div>
 <span className="text-muted">Customer:</span>{' '}
 {data.sales_order?.customer?.name ?? '—'}
 </div>
 <div>
 <span className="text-muted">SO:</span>{' '}
 {data.sales_order?.so_number ?? '—'}
 </div>
 <div>
 <span className="text-muted">Vehicle:</span>{' '}
 {data.vehicle?.plate_number ?? '—'}
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
 size="xl"
 className="w-full"
 loading={transition.isPending}
 onClick={() => transition.mutate(next)}
 >
 {transition.isPending ? 'Updating…' : label}
 </Button>
 )}

 {data.status === 'delivered' && (
 <Button
 variant="secondary"
 size="xl"
 className="w-full"
 onClick={() => navigate(`/driver/${id}/photo`)}
 >
 {(data.proofs?.length ?? 0) > 0 ? 'Replace receipt photo' : 'Capture receipt photo'}
 </Button>
 )}
 </div>
 );
}
