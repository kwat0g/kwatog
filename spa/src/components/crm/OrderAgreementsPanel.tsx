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

const termsSchema = z.object({ terms: z.string().trim().min(1, 'Terms are required.').max(10000, 'Terms may not exceed 10000 characters.') });
const externalSchema = z.object({
  representative: z.string().trim().min(1, 'Representative is required.').max(200),
  acknowledged_at: z.string().min(1, 'Acknowledgment time is required.'),
  reference: z.string().trim().min(1, 'Evidence or reference is required.').max(2000),
}).refine((value) => new Date(value.acknowledged_at).getTime() <= Date.now(), { path: ['acknowledged_at'], message: 'Acknowledgment time cannot be in the future.' });
type TermsValues = z.infer<typeof termsSchema>;
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
  const termsForm = useForm<TermsValues>({ resolver: zodResolver(termsSchema) });
  const externalForm = useForm<ExternalValues>({ resolver: zodResolver(externalSchema) });
  const [checkedRevision, setCheckedRevision] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [fileError, setFileError] = useState('');
  const [actionError, setActionError] = useState('');
  const refresh = async () => {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['order-agreements', realm, orderId] }),
      queryClient.invalidateQueries({ queryKey: realm === 'internal' ? ['crm', 'sales-orders', 'detail', orderId] : ['portal', 'customer', 'order', orderId] }),
    ]);
  };
  const publish = useMutation({
    mutationFn: (values: TermsValues) => {
      const form = new FormData();
      form.append('terms', values.terms);
      if (file) form.append('file', file);
      return salesOrdersApi.publishAgreement(orderId, form);
    },
    onSuccess: async () => { setFile(null); termsForm.reset(); setActionError(''); setFileError(''); toast.success('Terms published.'); await refresh(); },
    onError: (error: ServerError) => {
      applyServerValidationErrors(error, termsForm.setError, 'Could not publish terms.');
      setFileError(error.response?.data?.errors?.file?.[0] ?? '');
      setActionError(error.response?.data?.message ?? 'Could not publish terms.');
    },
  });
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
  const canPortalAcknowledge = !readOnly && realm === 'customer' && agreements.can_acknowledge && current?.is_current && !current?.is_stale && !current?.acknowledgment;

  return <Panel title="Agreed terms" meta={agreements.so_number}>
    {actionError && <p role="alert" className="mb-3 text-sm text-danger-fg">{actionError}</p>}
    {!current && <p className="text-sm text-muted">No terms recorded.</p>}
    {current && <div className="space-y-4">
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
    {realm === 'internal' && agreements.can_publish && !readOnly && <form className="mt-4 space-y-3 border-t border-default pt-4" onSubmit={termsForm.handleSubmit((values) => publish.mutate(values))}>
      <Textarea label="Terms shared with customer" maxLength={10000} required {...termsForm.register('terms')} error={termsForm.formState.errors.terms?.message} />
      <p className="text-caption text-muted">These terms, revisions, and any attachment will be visible to this order’s customer.</p>
      <label className="flex flex-col gap-1 text-label text-muted">Supporting attachment <input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp" onChange={(event) => {
        const selected = event.target.files?.[0] ?? null;
        const extension = selected?.name.split('.').pop()?.toLowerCase();
        setFileError(selected && selected.size > 10 * 1024 * 1024 ? 'Attachment must be 10 MiB or smaller.' : selected && !['pdf', 'jpg', 'jpeg', 'png', 'webp'].includes(extension ?? '') ? 'Choose a PDF, JPEG, PNG, or WebP file.' : '');
        setFile(selected);
      }} /></label>
      {fileError && <p role="alert" className="text-caption text-danger-fg">{fileError}</p>}
      <p className="text-caption text-muted">One PDF, JPEG, PNG, or WebP file, up to 10 MiB.</p>
      <Button type="submit" variant="primary" disabled={publish.isPending || !!fileError}>{publish.isPending ? 'Publishing…' : 'Publish new revision'}</Button>
    </form>}
    {history.length > 0 && <div className="mt-4 space-y-3 border-t border-default pt-4">
      <h4 className="font-medium text-primary">Previous revisions</h4>
      {history.map((agreement) => <details key={agreement.id} className="rounded-md border border-default p-3">
        <summary className="cursor-pointer font-medium text-primary">Revision {agreement.revision} · Published {formatDateTime(agreement.published_at)}</summary>
        <div className="mt-3"><AgreementRevision agreement={agreement} orderId={orderId} realm={realm} /></div>
      </details>)}
    </div>}
  </Panel>;
}
