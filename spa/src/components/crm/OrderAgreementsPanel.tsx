import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { zodResolver } from '@hookform/resolvers/zod';
import type { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { salesOrdersApi } from '@/api/crm/salesOrders';
import { customerPortalApi } from '@/api/b2b/customer';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { Textarea } from '@/components/ui/Textarea';
import { formatDateTime } from '@/lib/formatDate';
import { applyServerValidationErrors } from '@/lib/formErrors';
import type { OrderAgreement, OrderAgreements } from '@/types/orderAgreements';

const externalSchema = z.object({
  representative: z.string().trim().min(1, 'Representative is required.').max(200),
  acknowledged_at: z.string().min(1, 'Acknowledgment time is required.'),
  reference: z.string().trim().min(1, 'Evidence or reference is required.').max(2000),
}).refine((value) => new Date(value.acknowledged_at).getTime() <= Date.now(), { path: ['acknowledged_at'], message: 'Acknowledgment time cannot be in the future.' });
type ExternalValues = z.infer<typeof externalSchema>;
type ServerError = AxiosError<{ message?: string; errors?: Record<string, string[]> }>;

interface Props {
  orderId: string;
  realm: 'internal' | 'customer';
  readOnlyData?: OrderAgreements;
}

function saveBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.append(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

function AgreementRevision({ agreement, orderId, realm }: { agreement: OrderAgreement; orderId: string; realm: Props['realm'] }) {
  const [downloadError, setDownloadError] = useState('');
  const download = useMutation({
    mutationFn: () => realm === 'internal'
      ? salesOrdersApi.agreementFile(orderId, agreement.id)
      : customerPortalApi.agreementFile(orderId, agreement.id),
    onSuccess: (blob) => saveBlob(blob, agreement.file_name ?? 'order-terms'),
    onError: () => setDownloadError('Could not download the attachment. Try again.'),
  });
  return (
    <section aria-label={`Revision ${agreement.revision}`} className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h4 className="font-medium text-primary">Revision {agreement.revision}{agreement.is_current ? ' · Current' : ' · Previous'}</h4>
        <span className="text-caption text-muted">Published {formatDateTime(agreement.published_at)} by {agreement.published_by}</span>
      </div>
      {agreement.is_stale && <p role="status" className="text-sm text-warning-fg">These terms are stale because order details changed.</p>}
      <p className="whitespace-pre-wrap break-words rounded-md border border-subtle bg-elevated p-3 text-sm leading-6 text-primary">{agreement.terms}</p>
      <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-3">
        <Fact label="Payment terms" value={`${agreement.snapshot.payment_terms_days} days`} />
        <Fact label="Delivery terms" value={agreement.snapshot.delivery_terms ?? 'Not set'} />
        <Fact label="Incoterm" value={agreement.snapshot.incoterm ?? 'Not set'} />
        <Fact label="Subtotal" value={agreement.snapshot.subtotal} />
        <Fact label="VAT" value={agreement.snapshot.vat_amount} />
        <Fact label="Total" value={agreement.snapshot.total_amount} />
      </dl>
      <div className="space-y-2">
        <h5 className="text-sm font-medium text-primary">Captured items</h5>
        {agreement.snapshot.items.map((item, index) => <p key={`${item.product_id}-${index}`} className="text-sm text-secondary">
          {item.product_label} · <span className="font-mono tabular-nums">{item.quantity} × {item.unit_price}</span>{item.delivery_date ? ` · Delivery ${item.delivery_date}` : ''}
        </p>)}
      </div>
      {agreement.file_name && <div className="flex flex-wrap items-center gap-2">
        <span className="text-sm text-secondary">Attachment: {agreement.file_name}</span>
        <Button size="xs" variant="secondary" disabled={download.isPending} onClick={() => { setDownloadError(''); download.mutate(); }}>
          {download.isPending ? 'Downloading…' : 'Download attachment'}
        </Button>
        {downloadError && <span role="alert" className="text-caption text-danger-fg">{downloadError}</span>}
      </div>}
      {agreement.acknowledgment && <div className="rounded-md border border-default bg-subtle p-3 text-sm">
        <p className="font-medium text-primary">{agreement.acknowledgment.method === 'portal' ? 'Acknowledged through customer portal' : 'External acknowledgment recorded by staff'}</p>
        <p className="mt-1 text-secondary">{agreement.acknowledgment.representative} · {formatDateTime(agreement.acknowledgment.acknowledged_at)}</p>
        <p className="text-caption text-muted">Recorded by {agreement.acknowledgment.recorded_by} · {formatDateTime(agreement.acknowledgment.recorded_at)}</p>
        {agreement.acknowledgment.reference && <p className="mt-1 whitespace-pre-wrap text-secondary">Evidence/reference: {agreement.acknowledgment.reference}</p>}
      </div>}
    </section>
  );
}

function Fact({ label, value }: { label: string; value: string }) {
  return <div><dt className="text-caption text-muted">{label}</dt><dd className={`break-words text-primary ${['Subtotal', 'VAT', 'Total'].includes(label) ? 'font-mono tabular-nums' : ''}`}>{value}</dd></div>;
}

export function OrderAgreementsPanel({ orderId, realm, readOnlyData }: Props) {
  const queryClient = useQueryClient();
  const readOnly = readOnlyData !== undefined;
  const query = useQuery({
    queryKey: ['order-agreements', realm, orderId],
    queryFn: () => realm === 'internal' ? salesOrdersApi.agreements(orderId) : customerPortalApi.agreements(orderId),
    enabled: !readOnly && !!orderId,
  });
  const agreements = readOnlyData ?? query.data;
  const externalForm = useForm<ExternalValues>({ resolver: zodResolver(externalSchema) });
  const [checkedRevision, setCheckedRevision] = useState('');
  const [actionError, setActionError] = useState('');
  const refresh = async () => {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['order-agreements', realm, orderId] }),
      queryClient.invalidateQueries({ queryKey: realm === 'internal' ? ['crm', 'sales-orders', 'detail', orderId] : ['portal', 'customer', 'order', orderId] }),
    ]);
  };
  const external = useMutation({
    mutationFn: ({ agreementId, values }: { agreementId: string; values: ExternalValues }) => salesOrdersApi.recordAgreementAcknowledgment(orderId, agreementId, { ...values, acknowledged_at: new Date(values.acknowledged_at).toISOString() }),
    onSuccess: async () => { externalForm.reset(); setActionError(''); toast.success('External acknowledgment recorded.'); await refresh(); },
    onError: (error: ServerError) => { applyServerValidationErrors(error, externalForm.setError, 'Could not record external acknowledgment.'); setActionError(error.response?.data?.message ?? 'Could not record external acknowledgment.'); },
  });
  const acknowledge = useMutation({
    mutationFn: (agreementId: string) => customerPortalApi.acknowledgeAgreement(orderId, agreementId),
    onSuccess: async () => { setActionError(''); toast.success('Terms acknowledged.'); await refresh(); },
    onError: (error: ServerError) => setActionError(error.response?.data?.message ?? 'Could not acknowledge these terms.'),
  });

  if (!readOnly && query.isLoading) return <Panel title="Agreed terms"><p role="status" className="text-sm text-muted">Loading agreed terms…</p></Panel>;
  if (!readOnly && query.isError) return <Panel title="Agreed terms"><p role="alert" className="text-sm text-danger-fg">Agreed terms could not be loaded. <Button variant="secondary" size="xs" onClick={() => query.refetch()}>Retry</Button></p></Panel>;
  if (!agreements) return null;
  const [current, ...history] = agreements.revisions;
  const snapshot = agreements.customer_terms_snapshot;
  const canPortalAcknowledge = !readOnly && realm === 'customer' && agreements.can_acknowledge && current?.is_current && !current?.is_stale && !current?.acknowledgment;

  return <Panel title="Order terms" meta={agreements.so_number}>
    {actionError && <p role="alert" className="mb-3 text-sm text-danger-fg">{actionError}</p>}
    {snapshot && <section className="space-y-3">
      <h3 className="font-medium text-primary">Customer terms captured for this order</h3>
      <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-3">
        <Fact label="Agreement status" value={agreementStatusLabel[snapshot.agreement_status]} />
        <Fact label="Agreement period" value={snapshot.agreement_starts_on || snapshot.agreement_ends_on ? `${snapshot.agreement_starts_on ?? '—'} to ${snapshot.agreement_ends_on ?? '—'}` : 'Not specified'} />
        <Fact label="Selected payment method" value={paymentMethodLabel[agreements.payment_method ?? ''] ?? 'Not selected'} />
        <Fact label="Payment terms" value={`${snapshot.payment_terms_days} days`} />
        <Fact label="Shipping paid by" value={snapshot.shipping_payer === 'customer' ? 'Customer (fee quoted separately)' : snapshot.shipping_payer === 'company' ? 'Company (not charged to customer)' : 'Not specified'} />
        <Fact label="Delivery terms" value={snapshot.delivery_terms ?? 'Not set'} />
        <Fact label="Incoterm" value={snapshot.incoterm ?? 'Not set'} />
      </dl>
      <p className="text-xs text-muted">Freight is not included in the order total. {snapshot.shipping_payer === 'company'
        ? 'The customer is not charged for freight under these terms.'
        : snapshot.shipping_payer === 'customer'
          ? 'Sales must confirm any customer-paid charge separately.'
          : 'Payer and fee are not recorded; confirm them with the customer.'}</p>
      {snapshot.agreement_status === 'expired' && <p className="text-sm text-warning-fg">The recorded agreement had expired by this order date. Sales should review terms with the customer.</p>}
      {snapshot.agreement_status === 'not_yet_effective' && <p className="text-sm text-warning-fg">The recorded agreement had not started by this order date. Sales should review terms with the customer.</p>}
      {agreements.warranty_terms?.some((item) => item.terms) && <div className="space-y-2 border-t border-subtle pt-3">
        <h4 className="font-medium text-primary">Warranty terms by product</h4>
        {agreements.warranty_terms.filter((item) => item.terms).map((item) => <div key={item.product_id} className="rounded-md border border-default p-3">
          <p className="text-sm font-medium">{item.product_label}</p>
          <dl className="mt-2 grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-3">
            <Fact label="Duration" value={item.terms?.warranty_duration_days != null ? `${item.terms.warranty_duration_days} days` : 'Not specified'} />
            <Fact label="Report within" value={item.terms?.warranty_report_window_days != null ? `${item.terms.warranty_report_window_days} days` : 'Not specified'} />
            <Fact label="Starts from" value={item.terms?.warranty_start_basis ?? 'Not specified'} />
            <Fact label="Covered issues" value={item.terms?.warranty_covered_issues?.length ? item.terms.warranty_covered_issues.map((value) => issueLabel[value]).join(', ') : 'Not specified'} />
            <Fact label="Possible remedies" value={item.terms?.warranty_remedies?.length ? item.terms.warranty_remedies.map((value) => remedyLabel[value]).join(', ') : 'Not specified'} />
          </dl>
        </div>)}
        <p className="text-xs text-muted">These terms guide review. Quality and Returns staff investigate each report and record the decision; coverage and remedy are not decided automatically.</p>
      </div>}
    </section>}
    {!current && !snapshot && <p className="text-sm text-muted">No terms were recorded on this order. Set customer terms on the Customer profile.</p>}
    {!current && snapshot && <p className="border-t border-subtle pt-3 text-sm text-muted">No separate order-specific revision recorded.</p>}
    {current && <div className="space-y-4">
      <h3 className="border-t border-subtle pt-3 font-medium text-primary">Previous order-specific terms</h3>
      <AgreementRevision agreement={current} orderId={orderId} realm={realm} />
      {canPortalAcknowledge && <form className="space-y-3 border-t border-default pt-4" onSubmit={(event) => { event.preventDefault(); if (checkedRevision === current.id) acknowledge.mutate(current.id); }}>
        <label className="flex items-start gap-2 text-sm text-primary"><input type="checkbox" checked={checkedRevision === current.id} onChange={(event) => setCheckedRevision(event.target.checked ? current.id : '')} />I have reviewed the current terms and acknowledge this revision.</label>
        <Button type="submit" variant="primary" disabled={checkedRevision !== current.id || acknowledge.isPending}>{acknowledge.isPending ? 'Submitting…' : 'Acknowledge terms'}</Button>
      </form>}
      {realm === 'internal' && agreements.can_record_external && current.is_current && !current.is_stale && !current.acknowledgment && !readOnly && <form className="space-y-3 border-t border-default pt-4" onSubmit={externalForm.handleSubmit((values) => external.mutate({ agreementId: current.id, values }))}>
        <h4 className="font-medium text-primary">External acknowledgment recorded by staff</h4>
        <Input label="Customer representative" maxLength={200} required {...externalForm.register('representative')} error={externalForm.formState.errors.representative?.message} />
        <Input label="Acknowledgment date and time" type="datetime-local" required {...externalForm.register('acknowledged_at')} error={externalForm.formState.errors.acknowledged_at?.message} />
        <Textarea label="Evidence or reference" helper="Describe evidence that confirms the representative acknowledged these exact terms." maxLength={2000} required {...externalForm.register('reference')} error={externalForm.formState.errors.reference?.message} />
        <p className="text-caption text-muted">The representative, acknowledgment time, and evidence/reference will be visible to this order’s customer.</p>
        <Button type="submit" variant="secondary" disabled={external.isPending}>{external.isPending ? 'Recording…' : 'Record external acknowledgment'}</Button>
      </form>}
    </div>}
    {history.length > 0 && <div className="mt-4 space-y-3 border-t border-default pt-4">
      <h4 className="font-medium text-primary">Previous revisions</h4>
      {history.map((agreement) => <details key={agreement.id} className="rounded-md border border-default p-3">
        <summary className="cursor-pointer font-medium text-primary">Revision {agreement.revision} · Published {formatDateTime(agreement.published_at)}</summary>
        <div className="mt-3"><AgreementRevision agreement={agreement} orderId={orderId} realm={realm} /></div>
      </details>)}
    </div>}
  </Panel>;
}

const paymentMethodLabel: Record<string, string> = { cash: 'Cash', check: 'Check', bank_transfer: 'Bank transfer' };
const agreementStatusLabel = {
  not_recorded: 'Not recorded',
  not_yet_effective: 'Not yet effective',
  in_effect: 'In effect',
  expired: 'Expired',
} as const;
const issueLabel = { manufacturing_defect: 'Manufacturing defect', shipping_damage: 'Shipping damage', customer_caused_damage: 'Customer-caused damage' } as const;
const remedyLabel = { repair: 'Repair', replace: 'Replace', credit: 'Credit', refund: 'Refund' } as const;
