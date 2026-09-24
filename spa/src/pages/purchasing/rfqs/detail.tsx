import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { LuX } from '@/lib/icons';
import { rfqsApi } from '@/api/purchasing/rfqs';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { invitationStatus, rfqStatus } from '@/lib/rfqStatus';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { ReasonDialog } from '@/components/ui/ReasonDialog';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatDate, formatDateTime } from '@/lib/formatDate';
import { formatPeso, formatQuantity } from '@/lib/formatNumber';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

const errMsg = (e: unknown, fallback: string) =>
  (e instanceof AxiosError ? e.response?.data?.message : undefined) ?? fallback;

const STEPS = ['draft', 'open', 'closed', 'awarded'] as const;
const STEP_LABEL: Record<(typeof STEPS)[number], string> = {
  draft: 'Draft',
  open: 'Open for quotes',
  closed: 'Closed — compare',
  awarded: 'Awarded',
};

const toIsoDatetime = (localDatetimeValue: string): string => {
  if (!localDatetimeValue) return '';
  return new Date(localDatetimeValue).toISOString();
};

export default function RfqDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();

  const [cancelOpen, setCancelOpen] = useState(false);
  const [extensionDeadline, setExtensionDeadline] = useState('');
  const [extensionReason, setExtensionReason] = useState('');
  const [requirementFile, setRequirementFile] = useState<File | null>(null);

  const query = useQuery({
    queryKey: ['purchasing', 'rfqs', id],
    queryFn: () => rfqsApi.show(id),
    enabled: !!id,
  });

  const rfq = query.data;

  const purchaseOrders = useQuery({
    queryKey: ['purchasing', 'rfqs', id, 'purchase-orders'],
    queryFn: () => rfqsApi.purchaseOrders(id),
    enabled: !!rfq && rfq.status === 'awarded',
  });

  const refreshAll = async () => {
    await qc.invalidateQueries({ queryKey: ['purchasing', 'rfqs', id] });
    await qc.invalidateQueries({ queryKey: ['purchasing', 'rfqs'] });
    if (rfq?.purchase_request?.id) {
      await qc.invalidateQueries({
        queryKey: ['purchasing', 'purchase-requests', rfq.purchase_request.id],
      });
    }
  };

  const publish = useMutation({
    mutationFn: () => rfqsApi.publish(id),
    onSuccess: async () => {
      await refreshAll();
      toast.success('RFQ published to invited suppliers.');
    },
    onError: (e) => toast.error(errMsg(e, 'Failed to publish RFQ.')),
  });

  const closeNow = useMutation({
    mutationFn: () => rfqsApi.closeNow(id),
    onSuccess: async () => {
      await refreshAll();
      toast.success('RFQ closed. Ready for comparison.');
    },
    onError: (e) => toast.error(errMsg(e, 'Failed to close RFQ.')),
  });

  const extend = useMutation({
    mutationFn: () => rfqsApi.extend(id, toIsoDatetime(extensionDeadline), extensionReason),
    onSuccess: async () => {
      await refreshAll();
      setExtensionDeadline('');
      setExtensionReason('');
      toast.success('RFQ deadline extended.');
    },
    onError: (e) => toast.error(errMsg(e, 'Failed to extend deadline.')),
  });

  const cancel = useMutation({
    mutationFn: (reason: string) => rfqsApi.cancel(id, reason),
    onSuccess: async () => {
      await refreshAll();
      setCancelOpen(false);
      toast.success('RFQ cancelled.');
    },
    onError: (e) => toast.error(errMsg(e, 'Failed to cancel RFQ.')),
  });

  const uploadRequirement = useMutation({
    mutationFn: async () => {
      if (!requirementFile) throw new Error('Select a file.');
      const form = new FormData();
      form.append('file', requirementFile);
      form.append('document_type', 'requirement_document');
      return rfqsApi.uploadDocument(id, form);
    },
    onSuccess: async () => {
      await refreshAll();
      setRequirementFile(null);
      toast.success('Requirement document uploaded.');
    },
    onError: (e) => toast.error(errMsg(e, 'Failed to upload document.')),
  });

  if (query.isLoading) return <SkeletonTable columns={5} rows={6} />;

  if (query.isError || !rfq) {
    return (
      <EmptyState
        icon="alert-circle"
        title="Failed to load RFQ"
        action={<Button onClick={() => query.refetch()}>Retry</Button>}
      />
    );
  }

  const canCompare = rfq.actions.can_compare;

  return (
    <div>
      <PageHeader
        title={<span className="font-mono">{rfq.rfq_number}</span>}
        subtitle={rfq.title}
        backTo="/purchasing/rfqs"
        backLabel="Supplier RFQs"
        actions={
          <div className="flex flex-wrap gap-2">
            <Chip variant={rfqStatus(rfq.status).variant}>{rfqStatus(rfq.status).label}</Chip>
            {rfq.actions.can_publish && (
              <Button
                size="sm"
                variant="primary"
                onClick={() => publish.mutate()}
                loading={publish.isPending}
              >
                Publish
              </Button>
            )}
            {rfq.actions.can_close_now && (
              <Button
                size="sm"
                variant="primary"
                onClick={() => closeNow.mutate()}
                loading={closeNow.isPending}
              >
                Close now
              </Button>
            )}
            {rfq.status === 'open' && !rfq.actions.can_close_now && (
              <div className="text-sm text-muted">
                Closes {formatDateTime(rfq.closes_at)} ·{' '}
                <span className="font-mono tabular-nums">
                  {rfq.responded_count}/{rfq.invited_count}
                </span>{' '}
                responded
              </div>
            )}
            {canCompare && (
              <Button
                size="sm"
                variant={rfq.actions.can_award ? 'primary' : 'secondary'}
                onClick={() => navigate(`/purchasing/rfqs/${id}/compare`)}
              >
                {rfq.actions.can_award ? 'Compare & award' : 'View comparison'}
              </Button>
            )}
            {rfq.actions.can_edit && (
              <Button
                size="sm"
                variant="secondary"
                onClick={() => navigate(`/purchasing/rfqs/${id}/edit`)}
              >
                Edit
              </Button>
            )}
            {rfq.actions.can_cancel && (
              <Button
                size="sm"
                variant="secondary"
                icon={<LuX size={14} />}
                onClick={() => setCancelOpen(true)}
              >
                Cancel
              </Button>
            )}
          </div>
        }
      />

      <ol className="px-5 pt-4 flex flex-wrap items-center gap-2 text-xs" aria-label="RFQ progress">
        {STEPS.map((step, index) => {
          const current = STEPS.indexOf(rfq.status as (typeof STEPS)[number]);
          const done = current > index;
          const active = current === index;
          return (
            <li key={step} className="flex items-center gap-2">
              {index > 0 && <span className="h-px w-6 bg-border-default" aria-hidden />}
              <span
                className={
                  active
                    ? 'rounded-full bg-accent px-3 py-1 font-medium text-accent-fg'
                    : done
                      ? 'rounded-full border border-default px-3 py-1 text-default'
                      : 'rounded-full border border-subtle px-3 py-1 text-muted'
                }
                aria-current={active ? 'step' : undefined}
              >
                {STEP_LABEL[step]}
              </span>
            </li>
          );
        })}
        {rfq.status === 'cancelled' && <Chip variant="danger">Cancelled</Chip>}
      </ol>

      <div className="px-5 py-4 grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div className="lg:col-span-2 space-y-4">
          {rfq.status === 'cancelled' && rfq.cancellation_reason && (
            <div className="rounded-md border border-danger/40 bg-danger-bg/10 px-4 py-3">
              <p className="text-sm font-medium text-danger-fg mb-1">Cancelled</p>
              <p className="text-sm text-muted">{rfq.cancellation_reason}</p>
            </div>
          )}

          <Panel title="Requirements">
            <p className="text-sm text-muted mb-3">
              {rfq.instructions || 'No additional instructions.'}
            </p>
            <div className="overflow-x-auto">
              <table className={tableCls}>
                <caption className="sr-only">RFQ requirements</caption>
                <thead>
                  <tr className={theadTrCls}>
                    <Th>Item</Th>
                    <Th>Quantity</Th>
                    <Th>Required date</Th>
                    {rfq.status === 'awarded' && <Th>Awarded</Th>}
                    {rfq.status === 'awarded' && <Th>Remaining</Th>}
                  </tr>
                </thead>
                <tbody>
                  {rfq.items.map((item) => (
                    <tr key={item.id} className={trCls}>
                      <Td>
                        <div className="text-sm">{item.description}</div>
                        {item.item && <div className="font-mono text-2xs text-muted">{item.item.code}</div>}
                        {item.specification && <div className="text-2xs text-muted">{item.specification}</div>}
                      </Td>
                      <Td className="font-mono tabular-nums">
                        {formatQuantity(item.quantity)} {item.unit || ''}
                      </Td>
                      <Td className="font-mono">
                        {item.required_delivery_date
                          ? formatDate(item.required_delivery_date)
                          : '—'}
                      </Td>
                      {rfq.status === 'awarded' && (
                        <Td className="font-mono tabular-nums">
                          {formatQuantity(item.awarded_quantity)} {item.unit || ''}
                        </Td>
                      )}
                      {rfq.status === 'awarded' && (
                        <Td className="font-mono tabular-nums">{formatQuantity(item.remaining_quantity)}</Td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {rfq.status === 'awarded' &&
              rfq.items.some((item) => Number(item.remaining_quantity) > 0) && (
                <div className="mt-3 rounded bg-info-bg/10 border border-info/20 p-3 text-sm text-info-fg">
                  {rfq.items
                    .filter((item) => Number(item.remaining_quantity) > 0)
                    .map((item) => (
                      <div key={item.id}>
                        <strong>
                          {formatQuantity(item.remaining_quantity)} {item.unit}
                        </strong>{' '}
                        of {item.description} was not awarded and is back on{' '}
                        <Link
                          to={`/purchasing/purchase-requests/${rfq.purchase_request?.id}`}
                          className="font-mono text-info hover:underline"
                        >
                          {rfq.purchase_request?.pr_number}
                        </Link>{' '}
                        — convert it by Direct PO or start a new RFQ.
                      </div>
                    ))}
                </div>
              )}
          </Panel>

          <Panel title="Supplier invitations">
            <div className="space-y-2">
              {rfq.invitations.map((invitation) => (
                <div
                  key={invitation.id}
                  className="flex justify-between items-center gap-3 border-b border-subtle py-2 last:border-0"
                >
                  <div>
                    <div className="font-medium">{invitation.vendor?.name || 'Supplier'}</div>
                    <div className="mt-1 flex flex-wrap items-center gap-1 text-sm text-muted">
                      {invitation.reach === 'portal' && <Chip variant="info">Portal</Chip>}
                      {invitation.reach === 'email' && <Chip variant="neutral">Email only</Chip>}
                      {invitation.reach === 'none' && (
                        <Chip variant="danger">No contact — enter quote manually</Chip>
                      )}
                      {invitation.last_notification_error && (
                        <Chip variant="warning">Email not delivered</Chip>
                      )}
                      {invitation.portal_notified_at && !invitation.last_notification_error && (
                        <span className="inline-block text-2xs text-muted">
                          Notified {formatDateTime(invitation.portal_notified_at)}
                        </span>
                      )}
                    </div>
                    {invitation.last_notification_error && (
                      <div className="text-2xs text-danger-fg mt-1">
                        {invitation.last_notification_error}
                      </div>
                    )}
                    {invitation.exception_reason && (
                      <div className="text-sm text-muted mt-1">
                        Exception: {invitation.exception_reason}
                      </div>
                    )}
                  </div>
                  <Chip variant={invitationStatus(invitation.status).variant}>
                    {invitationStatus(invitation.status).label}
                  </Chip>
                </div>
              ))}
            </div>
          </Panel>

          {rfq.awards.length > 0 && (
            <Panel title="Award outcome">
              <div className="overflow-x-auto">
                <table className={tableCls}>
                  <caption className="sr-only">RFQ awards</caption>
                  <thead>
                    <tr className={theadTrCls}>
                      <Th>Line</Th>
                      <Th>Supplier</Th>
                      <Th>Qty</Th>
                      <Th>Unit price</Th>
                      <Th>Total</Th>
                      <Th>PO</Th>
                    </tr>
                  </thead>
                  <tbody>
                    {rfq.awards.map((award) => (
                      <tr key={award.id} className={trCls}>
                        <Td>{award.rfq_item?.description || 'Line'}</Td>
                        <Td>{award.vendor?.name || '—'}</Td>
                        <Td className="font-mono tabular-nums">
                          {formatQuantity(award.awarded_quantity)}
                        </Td>
                        <Td className="font-mono tabular-nums">
                          {formatPeso(award.awarded_unit_price)}
                        </Td>
                        <Td className="font-mono tabular-nums">
                          {formatPeso(award.awarded_total_delivered_cost)}
                        </Td>
                        <Td>
                          {award.purchase_order ? (
                            <Link
                              to={`/purchasing/purchase-orders/${award.purchase_order.id}`}
                              className="font-mono text-link hover:underline"
                            >
                              {award.purchase_order.po_number}
                            </Link>
                          ) : (
                            '—'
                          )}
                        </Td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Panel>
          )}

          {purchaseOrders.data && purchaseOrders.data.length > 0 && (
            <Panel title="Generated purchase orders">
              <div className="space-y-2">
                {purchaseOrders.data.map((po) => (
                  <Link
                    key={po.id}
                    to={`/purchasing/purchase-orders/${po.id}`}
                    className="flex justify-between items-center border-b border-subtle py-2 text-link hover:underline last:border-0"
                  >
                    <span className="font-mono">{po.po_number}</span>
                    <span className="flex gap-3 text-default">
                      <span className="font-mono tabular-nums">{formatPeso(po.total_amount)}</span>
                      <span>{po.status_label || po.status}</span>
                    </span>
                  </Link>
                ))}
              </div>
            </Panel>
          )}
        </div>

        <div className="space-y-4">
          <Panel title="Sourcing">
            <dl className="space-y-3 text-sm">
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Source PR</dt>
                <dd>
                  <Link
                    to={`/purchasing/purchase-requests/${rfq.purchase_request?.id}`}
                    className="text-link hover:underline"
                  >
                    {rfq.purchase_request?.pr_number || '—'}
                  </Link>
                </dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Published</dt>
                <dd className="font-mono">{rfq.issued_at ? formatDateTime(rfq.issued_at) : '—'}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Deadline</dt>
                <dd className="font-mono">{formatDateTime(rfq.closes_at)}</dd>
              </div>
              {rfq.closed_at && (
                <div>
                  <dt className="text-2xs uppercase tracking-wider text-muted">Closed</dt>
                  <dd className="font-mono">{formatDateTime(rfq.closed_at)}</dd>
                </div>
              )}
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Invited</dt>
                <dd className="font-mono tabular-nums">{rfq.invited_count}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Responded</dt>
                <dd className="font-mono tabular-nums">{rfq.responded_count}</dd>
              </div>
            </dl>
          </Panel>

          {rfq.actions.can_upload_document && (
            <Panel title="Requirement document">
              <p className="text-sm text-muted mb-2">Shared with all invited suppliers.</p>
              <Input
                label="PDF or image"
                type="file"
                accept="application/pdf,.pdf,image/png,image/jpeg"
                onChange={(e) => setRequirementFile(e.target.files?.[0] ?? null)}
              />
              <Button
                className="mt-3 w-full"
                variant="secondary"
                disabled={!requirementFile}
                onClick={() => uploadRequirement.mutate()}
                loading={uploadRequirement.isPending}
              >
                Upload
              </Button>
            </Panel>
          )}

          {rfq.status === 'open' && rfq.actions.can_extend && (
            <Panel title="Extend deadline">
              <Input
                label="New deadline"
                type="datetime-local"
                value={extensionDeadline}
                onChange={(e) => setExtensionDeadline(e.target.value)}
              />
              <Input
                label="Reason"
                className="mt-2"
                value={extensionReason}
                onChange={(e) => setExtensionReason(e.target.value)}
                placeholder="Clarification required…"
              />
              <Button
                className="mt-3 w-full"
                variant="secondary"
                disabled={!extensionDeadline || extensionReason.trim().length < 5}
                onClick={() => extend.mutate()}
                loading={extend.isPending}
              >
                Extend RFQ
              </Button>
            </Panel>
          )}

          {rfq.status === 'open' && rfq.actions.can_capture_quote && (
            <Panel title="Manual quotation">
              <p className="text-sm text-muted mb-3">For suppliers without portal access.</p>
              <Button
                variant="secondary"
                className="w-full"
                onClick={() => navigate(`/purchasing/rfqs/${id}/manual-quote`)}
              >
                Capture quote
              </Button>
            </Panel>
          )}

          {rfq.documents.length > 0 && (
            <Panel title="Documents">
              <div className="space-y-2">
                {rfq.documents.map((doc) => (
                  <a
                    key={doc.id}
                    href={rfqsApi.documentDownloadUrl(doc.id)}
                    className="block text-link text-sm hover:underline truncate"
                    title={doc.original_filename}
                  >
                    {doc.original_filename}
                  </a>
                ))}
              </div>
            </Panel>
          )}
        </div>
      </div>

      <ReasonDialog
        isOpen={cancelOpen}
        onClose={() => setCancelOpen(false)}
        title="Cancel this RFQ?"
        description="Invited suppliers will no longer be able to submit or revise quotations."
        confirmLabel="Cancel RFQ"
        pending={cancel.isPending}
        onConfirm={(reason) => cancel.mutate(reason)}
      />
    </div>
  );
}
