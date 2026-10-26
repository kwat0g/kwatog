/** Driver run sheet. The office dispatches; the driver records delivery with a photo. */
import type { ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate, useParams } from 'react-router-dom';
import { LuCamera, LuCheck } from '@/lib/icons';
import { driverApi } from '@/api/driver';
import type { DriverDelivery, DriverDeliveryStatus } from '@/types/driver';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonPanel } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { DeliveryAttemptPanel } from '@/pages/supply-chain/deliveries/DeliveryAttemptPanel';
import { ShipToAddress } from '@/components/supply-chain/ShipToAddress';
import { deliveryStatusVariant } from '@/lib/statusVariants';
import { formatDate, formatDateTime } from '@/lib/formatDate';
import { cn } from '@/lib/cn';

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
 { label: 'Shipment plan', at: null as string | null },
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

 const { data, isLoading, error, refetch, isFetching } = useQuery({
 queryKey: ['driver', 'delivery', id],
 queryFn: () => driverApi.showDelivery(id),
 enabled: Boolean(id),
 });

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

 const state = statusLabel(data);
 const proofCount = data.proof_count ?? data.proofs?.filter((proof) => ['photo', 'signed_dr', 'customer_po_confirmation'].includes(proof.proof_type)).length ?? 0;
 const items = data.items ?? [];
 const canRecordDelivery = data.status === 'in_transit';
 const canUploadReceipt = data.status === 'delivered' || data.status === 'confirmed';
 const awaitingDispatch = data.status === 'scheduled' || data.status === 'loading';

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
 canRecordDelivery ? (
 <Button
 variant="primary"
 size="touch"
 className="w-full sm:w-auto"
 icon={<LuCamera size={14} />}
 onClick={() => navigate(`/driver/${id}/photo`)}
 >
 Record delivery
 </Button>
 ) : undefined
 }
 />

 <div className="grid gap-4 px-5 py-4 lg:grid-cols-3">
 {/* Details first on a phone — a driver reads where the stop is before
 what went wrong with it. Right-hand column on a desk. */}
 <div className="space-y-4 lg:order-2 lg:col-span-1">
 {/* The stop, before anything about the paperwork. Tapping the address
 starts Google Maps turn-by-turn from wherever the truck is. */}
 <Panel title="Deliver to">
 <ShipToAddress
 heading={data.sales_order?.customer?.name ?? undefined}
 address={data.delivery_address}
 contactPerson={data.delivery_contact_person}
 contactPhone={data.delivery_contact_phone}
 emptyHint="No delivery address on file for this customer."
 />
 </Panel>

 <Panel title="Delivery details">
 <dl className="space-y-2.5 text-sm">
 {/* The customer now leads the "Deliver to" panel above, so it is
 not repeated here — the subtitle carries it as well. */}
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
 <DetailRow label="Planned dispatch">{data.scheduled_date ? formatDate(data.scheduled_date) : 'Unscheduled'}</DetailRow>
 <DetailRow label="Expected delivery">{data.expected_delivery_date ? formatDate(data.expected_delivery_date) : 'Not set'}</DetailRow>
 <DetailRow label="Carrier">{data.carrier ?? 'Not assigned'}</DetailRow>
 <DetailRow label="Status">
 <Chip variant={deliveryStatusVariant[data.status]}>{state}</Chip>
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
 {awaitingDispatch && (
 <Panel title="Awaiting dispatch">
 <p className="text-sm text-secondary">
 The office records the loading photo and dispatches this delivery. Check your load and wait for dispatch before leaving.
 </p>
 </Panel>
 )}
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

 {(canRecordDelivery || canUploadReceipt) && (
 <Panel
 title="Proof of delivery"
 meta={proofCount > 0 ? `${proofCount} on file` : 'Required'}
 >
 <p className="text-sm text-secondary">
 {canRecordDelivery
 ? 'After handing over the goods, choose Record delivery and upload the signed receipt or delivery photo. Saving the photo records delivery for customer confirmation.'
 : proofCount > 0
 ? 'The signed receipt is on file. Replace it if you photographed the wrong document.'
 : 'Upload the signed receipt or delivery photo for this recorded delivery.'}
 </p>
 {data.status === 'delivered' && <p className="mt-2 text-sm text-secondary">Waiting for the customer to confirm receipt.</p>}
 {canUploadReceipt && <Button
 variant={proofCount > 0 ? 'secondary' : 'primary'}
 size="touch"
 className="mt-3 w-full sm:w-auto"
 icon={<LuCamera size={14} />}
 onClick={() => navigate(`/driver/${id}/photo`)}
 >
 {proofCount > 0 ? 'Replace receipt photo' : 'Capture receipt photo'}
 </Button>}
 </Panel>
 )}

 <DeliveryAttemptPanel key={id} deliveryId={id} lines={items} driver
 can_report_attempt_outcome={data.can_report_attempt_outcome}
 attempt_outcome_reasons={data.attempt_outcome_reasons} attempt_outcome={data.attempt_outcome}
 report={(payload) => driverApi.reportAttempt(id, payload)} amend={(payload) => driverApi.amendAttempt(id, payload)} />
 </div>
 </div>

 </div>
 );
}
