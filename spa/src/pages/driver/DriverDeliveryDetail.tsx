/**
 * One delivery, from the driver's seat.
 *
 * Same shell as every other page (PageHeader + panels), but the content is the
 * run-sheet view: where this stop is in the route, what is on the truck, and
 * the single next action the server allows. The action is named after the
 * transition and confirmed in a thumb-reachable sheet — it sits under the same
 * pixel whether it means "loading" or "delivered", and a mis-tap here records a
 * delivery that did not happen.
 */
import { useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { isAxiosError } from 'axios';
import { LuCamera, LuCheck } from '@/lib/icons';
import { driverApi } from '@/api/driver';
import type { DriverDelivery, DriverDeliveryStatus } from '@/types/driver';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonPanel } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import {
 TouchConfirmSheet,
 useTouchSubmitLabel,
} from '@/components/layout/TouchShell';
import { DeliveryAttemptPanel } from '@/pages/supply-chain/deliveries/DeliveryAttemptPanel';
import { deliveryStatusVariant } from '@/lib/statusVariants';
import { formatDate, formatDateTime } from '@/lib/formatDate';
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

function statusLabel(data: DriverDelivery): string {
 return data.status_label ?? data.status.replace(/_/g, ' ');
}

/**
 * Where each status sits on the route. `return_pending` is still "on the road",
 * so it shares the in-transit step; the stepper is hidden for the statuses that
 * do not walk this ladder (see `SHOW_STEPS`) rather than lying about them.
 */
const STEP_INDEX: Record<DriverDeliveryStatus, number> = {
 scheduled: 0,
 loading: 0,
 in_transit: 1,
 return_pending: 1,
 delivered: 2,
 confirmed: 3,
 returned: 2,
 cancelled: 0,
};

const SHOW_STEPS: DriverDeliveryStatus[] = ['scheduled', 'loading', 'in_transit', 'delivered', 'confirmed'];

function ProgressSteps({ data }: { data: DriverDelivery }) {
 const steps = [
 { label: 'Loading', at: null as string | null },
 { label: 'In transit', at: data.departed_at },
 { label: 'Delivered', at: data.delivered_at },
 { label: 'Received by customer', at: data.confirmed_at },
 ];
 const current = STEP_INDEX[data.status];

 return (
 <ol className="space-y-3">
 {steps.map((step, index) => {
 const done = index < current;
 const active = index === current;
 return (
 <li key={step.label} className="flex items-start gap-3">
 <span
 className={cn(
 'mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border',
 done && 'border-success bg-success-bg text-success-fg',
 active && 'border-accent text-accent',
 !done && !active && 'border-default',
 )}
 aria-hidden
 >
 {done ? (
 <LuCheck size={11} />
 ) : (
 <span className={cn('h-1.5 w-1.5 rounded-full', active ? 'bg-accent' : 'bg-subtle')} />
 )}
 </span>
 <div className="min-w-0 flex-1">
 <div
 className={cn(
 'text-sm leading-5',
 active && 'font-medium text-primary',
 done && 'text-secondary',
 !done && !active && 'text-muted',
 )}
 >
 {step.label}
 </div>
 {step.at && <div className="mt-0.5 text-xs text-muted">{formatDateTime(step.at)}</div>}
 </div>
 </li>
 );
 })}
 </ol>
 );
}

function DetailRow({ label, children }: { label: string; children: ReactNode }) {
 return (
 <div className="flex items-baseline justify-between gap-3">
 <dt className="shrink-0 text-muted">{label}</dt>
 <dd className="min-w-0 text-right text-primary">{children}</dd>
 </div>
 );
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
 return (
 <div>
 <PageHeader title="Delivery" subtitle="Loading delivery…" backTo="/driver" backLabel="My deliveries" />
 <div className="space-y-4 px-5 py-4">
 <SkeletonPanel />
 <SkeletonPanel />
 </div>
 </div>
 );
 }

 if (error || !data) {
 return (
 <div>
 <PageHeader title="Delivery" backTo="/driver" backLabel="My deliveries" />
 <EmptyState
 icon="alert-circle"
 title="Could not load delivery"
 description="Check your connection and try again."
 action={
 <Button variant="secondary" disabled={isFetching} onClick={() => refetch()}>
 {isFetching ? 'Retrying…' : 'Try again'}
 </Button>
 }
 />
 </div>
 );
 }

 const next = data.next_status ?? undefined;
 const label = data.next_status_label ? `Mark ${data.next_status_label}` : undefined;
 const state = statusLabel(data);
 const proofCount = data.proofs?.length ?? 0;
 const items = data.items ?? [];
 const canUploadReceipt = data.status === 'delivered' || data.status === 'confirmed';
 // The button sits in a fixed position but its meaning is server-derived, so the
 // same pixel is "Mark in transit" on one load and "Mark delivered" on the next.
 // Naming the transition in a sheet is the only thing standing between a
 // mis-tap and a delivery confirmed at the wrong gate.
 const isDeliveryCompletion = next === 'delivered';

 return (
 <div>
 <PageHeader
 title={
 <>
 {data.delivery_number}
 <Chip variant={deliveryStatusVariant[data.status]} className="ml-2 align-middle">
 {state}
 </Chip>
 </>
 }
 subtitle={data.sales_order ? `SO ${data.sales_order.so_number} · ${data.sales_order.customer?.name ?? 'No customer on file'}` : 'No sales order on file'}
 backTo="/driver"
 backLabel="My deliveries"
 crumbLabel={data.delivery_number}
 actions={
 next && label ? (
 <Button
 variant="primary"
 size="touch"
 className="w-full sm:w-auto"
 loading={transition.isPending}
 onClick={() => setConfirming(next)}
 >
 {advanceLabel || label}
 </Button>
 ) : undefined
 }
 />

 <div className="grid gap-4 px-5 py-4 lg:grid-cols-3">
 {/* Details first on a phone — a driver reads where the stop is before
 what went wrong with it. Right-hand column on a desk. */}
 <div className="space-y-4 lg:order-2 lg:col-span-1">
 <Panel title="Delivery details">
 <dl className="space-y-2.5 text-sm">
 <DetailRow label="Customer">{data.sales_order?.customer?.name ?? '—'}</DetailRow>
 <DetailRow label="Sales order">
 {data.sales_order?.so_number ? <span className="font-mono tabular-nums">{data.sales_order.so_number}</span> : '—'}
 </DetailRow>
 <DetailRow label="Vehicle">
 {data.vehicle ? (
 <span>
 {data.vehicle.plate_number}
 {data.vehicle.name ? <span className="text-muted"> · {data.vehicle.name}</span> : null}
 </span>
 ) : (
 '—'
 )}
 </DetailRow>
 <DetailRow label="Scheduled">{data.scheduled_date ? formatDate(data.scheduled_date) : 'Unscheduled'}</DetailRow>
 <DetailRow label="Status">
 <strong className="font-medium">{state}</strong>
 </DetailRow>
 <DetailRow label="Receipt photo">
 {proofCount > 0 ? `${proofCount} on file` : 'Not uploaded'}
 </DetailRow>
 </dl>
 </Panel>

 <Panel title="Progress">
 {SHOW_STEPS.includes(data.status) ? (
 <ProgressSteps data={data} />
 ) : (
 <p className="text-sm text-secondary">
 {data.status === 'return_pending'
 ? 'The truck is heading back to the depot. The office reconciles the returned quantities against your report.'
 : data.status === 'returned'
 ? 'The returned goods were received back at the depot.'
 : 'This delivery is closed. No further driver action is expected.'}
 </p>
 )}
 </Panel>
 </div>

 <div className="space-y-4 lg:order-1 lg:col-span-2">
 {/* What is actually on the truck. The attempt form asks for these
 quantities one by one, so the driver should be able to check the load
 against the manifest before the stop, not only while reporting a
 problem. It also keeps the wide column from standing empty on the
 statuses where no report is possible yet. */}
 {items.length > 0 && (
 <Panel title="Load" meta={`${items.length} ${items.length === 1 ? 'line' : 'lines'}`}>
 <ul className="divide-y divide-default">
 {items.map((line) => (
 <li key={line.id} className="flex items-baseline justify-between gap-3 py-2.5 first:pt-0 last:pb-0">
 <div className="min-w-0">
 <div className="truncate text-sm font-medium text-primary">
 {line.product?.name ?? 'Unnamed item'}
 </div>
 <div className="font-mono text-xs text-muted">{line.product?.part_number ?? '—'}</div>
 </div>
 <div className="shrink-0 font-mono tabular-nums text-sm text-primary">
 {line.quantity}
 {line.unit_of_measure ? <span className="text-muted"> {line.unit_of_measure}</span> : null}
 </div>
 </li>
 ))}
 </ul>
 </Panel>
 )}

 {canUploadReceipt && (
 <Panel
 title="Proof of delivery"
 meta={proofCount > 0 ? `${proofCount} on file` : 'Required'}
 >
 <p className="text-sm text-secondary">
 {proofCount > 0
 ? 'The signed receipt is on file. Replace it if you photographed the wrong document.'
 : 'Upload the signed receipt or delivery photo so the office can confirm the delivery and bill the customer.'}
 </p>
 <Button
 variant={proofCount > 0 ? 'secondary' : 'primary'}
 size="touch"
 className="mt-3 w-full sm:w-auto"
 icon={<LuCamera size={14} />}
 onClick={() => navigate(`/driver/${id}/photo`)}
 >
 {proofCount > 0 ? 'Replace receipt photo' : 'Capture receipt photo'}
 </Button>
 </Panel>
 )}

 <DeliveryAttemptPanel key={id} deliveryId={id} lines={items} driver
 can_report_attempt_outcome={data.can_report_attempt_outcome}
 attempt_outcome_reasons={data.attempt_outcome_reasons} attempt_outcome={data.attempt_outcome}
 report={(payload) => driverApi.reportAttempt(id, payload)} amend={(payload) => driverApi.amendAttempt(id, payload)} />
 </div>
 </div>

 <TouchConfirmSheet
 isOpen={confirming !== null}
 onClose={() => setConfirming(null)}
 onConfirm={() => confirming && transition.mutate(confirming)}
 title={label ? `${label}?` : 'Advance this delivery?'}
 confirmLabel={advanceLabel || (label ?? 'Confirm')}
 variant="primary"
 pending={transition.isPending}
 className="sm:max-w-lg sm:rounded-lg sm:border sm:border-default"
 >
 <p>
 <span className="font-mono tabular-nums font-medium text-primary">
 {data.delivery_number ?? data.sales_order?.so_number ?? 'This delivery'}
 </span>
 {data.sales_order?.customer?.name ? ` · ${data.sales_order.customer.name}` : ''}
 </p>
 <p>
 Status moves from{' '}
 <span className="font-medium text-primary">{state}</span>{' '}
 to{' '}
 <span className="font-medium text-primary">
 {data.next_status_label ?? confirming?.replace(/_/g, ' ') ?? ''}
 </span>
 .
 </p>
 {isDeliveryCompletion && (
 <p>Marking delivered records arrival at the customer site. Afterward, upload the signed receipt photo as delivery evidence.</p>
 )}
 </TouchConfirmSheet>
 </div>
 );
}
