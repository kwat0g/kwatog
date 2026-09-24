import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { supplierRfqsApi } from '@/api/purchasing/rfqs';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { invitationStatus, quoteStatus, rfqStatus } from '@/lib/rfqStatus';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatDate, formatDateTime } from '@/lib/formatDate';
import { formatPeso, formatQuantity } from '@/lib/formatNumber';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

const formatRelativeTime = (closesAt: string): string => {
  const now = new Date();
  const deadline = new Date(closesAt);
  if (deadline <= now) return 'Closed';

  const ms = deadline.getTime() - now.getTime();
  const days = Math.floor(ms / (1000 * 60 * 60 * 24));
  const hours = Math.floor((ms % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));

  if (days > 0) return `${days}d ${hours}h remaining`;
  return `${hours}h remaining`;
};

export default function SupplierRfqDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const queryClient = useQueryClient();
  const [confirmWithdraw, setConfirmWithdraw] = useState(false);
  const query = useQuery({
    queryKey: ['portal', 'supplier', 'rfqs', id],
    queryFn: () => supplierRfqsApi.show(id),
    enabled: !!id,
  });
  const withdraw = useMutation({
    mutationFn: () => supplierRfqsApi.withdraw(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['portal', 'supplier', 'rfqs'] });
      setConfirmWithdraw(false);
      toast.success('Quotation withdrawn. It is a draft again and will not be evaluated unless you submit it.');
    },
    onError: (e) =>
      toast.error(
        (e as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Could not withdraw the quotation.',
      ),
  });

  if (query.isLoading) return <SkeletonTable columns={4} rows={6} />;
  if (query.isError || !query.data)
    return (
      <EmptyState
        icon="alert-circle"
        title="RFQ invitation unavailable"
        action={<Button onClick={() => query.refetch()}>Retry</Button>}
      />
    );

  const rfq = query.data;
  const quote = rfq.quote;

  return (
    <div>
      <PageHeader
        title={
          <div className="flex items-center gap-3">
            <span className="font-mono text-lg">{rfq.rfq_number}</span>
            <Chip variant={rfqStatus(rfq.status).variant}>
              {rfqStatus(rfq.status).label}
            </Chip>
            <Chip variant={invitationStatus(rfq.invitation_status).variant}>
              {invitationStatus(rfq.invitation_status).label}
            </Chip>
          </div>
        }
        subtitle={rfq.title}
        backTo="/portal/supplier/rfqs"
        backLabel="RFQ Invitations"
      />

      <div className="px-5 py-4 max-w-5xl space-y-4">
        <Panel title="Invitation">
          <p className="text-sm text-muted mb-3">
            {rfq.instructions ||
              'Review the requirements and submit your quotation before the deadline.'}
          </p>
          <div className="text-sm">
            <div>
              Deadline:{' '}
              <span className="font-mono">{formatDateTime(rfq.closes_at)}</span>
            </div>
            <div className="text-muted">
              <span className="font-mono">{formatRelativeTime(rfq.closes_at)}</span>
            </div>
          </div>
        </Panel>

        {rfq.outcome && (
          <Panel title="Outcome">
            {rfq.outcome === 'awarded' && (
              <div>
                <p className="text-sm text-muted mb-3">
                  You were awarded one or more lines in this RFQ.
                </p>
                {rfq.awards && rfq.awards.length > 0 && (
                  <div className="overflow-x-auto mt-3">
                    <table className={tableCls}>
                      <thead>
                        <tr className={theadTrCls}>
                          <Th>Description</Th>
                          <Th align="right">Awarded qty</Th>
                          <Th align="right">Unit price</Th>
                        </tr>
                      </thead>
                      <tbody>
                        {rfq.awards.map((award) => (
                          <tr key={award.id} className={trCls}>
                            <Td>{award.rfq_item?.description ?? '—'}</Td>
                            <Td align="right" mono>
                              {award.awarded_quantity}
                            </Td>
                            <Td align="right" mono>
                              {formatPeso(award.awarded_unit_price)}
                            </Td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </div>
            )}
            {rfq.outcome === 'not_awarded' && (
              <p className="text-sm text-muted">
                Not selected this time. Competitor pricing is never disclosed.
              </p>
            )}
            {rfq.outcome === 'cancelled' && (
              <p className="text-sm text-muted">This RFQ was cancelled.</p>
            )}
          </Panel>
        )}

        {rfq.documents && rfq.documents.length > 0 && (
          <Panel title="Requirement documents">
            <div className="space-y-2">
              {rfq.documents.map((document) => (
                <a
                  key={document.id}
                  className="block text-link text-sm hover:underline"
                  href={supplierRfqsApi.downloadDocumentUrl(id, document.id)}
                >
                  {document.original_filename}
                </a>
              ))}
            </div>
          </Panel>
        )}

        <Panel title="Requirements">
          <div className="overflow-x-auto">
            <table className={tableCls}>
              <thead>
                <tr className={theadTrCls}>
                  <Th>Description</Th>
                  <Th>Item code</Th>
                  <Th align="right">Quantity</Th>
                  <Th>Required date</Th>
                </tr>
              </thead>
              <tbody>
                {rfq.items?.map((item) => (
                  <tr key={item.id} className={trCls}>
                    <Td>
                      {item.description}
                      {item.specification && <div className="text-xs text-muted">{item.specification}</div>}
                    </Td>
                    <Td mono className="text-muted">
                      {item.item?.code ?? '—'}
                    </Td>
                    <Td align="right" mono>
                      {formatQuantity(item.quantity)} {item.unit ?? ''}
                    </Td>
                    <Td mono className="text-muted">
                      {item.required_delivery_date ? formatDate(item.required_delivery_date) : '—'}
                    </Td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Panel>

        {quote && (
          <Panel title="Your quotation">
            <div className="space-y-3 text-sm">
              <div className="flex flex-wrap items-center gap-x-6 gap-y-2">
                <div>
                  <div className="text-2xs uppercase tracking-wider text-muted font-medium">
                    Status
                  </div>
                  <Chip variant={quoteStatus(quote.status).variant}>
                    {quoteStatus(quote.status).label}
                  </Chip>
                </div>
                {quote.submitted_at && (
                  <div>
                    <div className="text-2xs uppercase tracking-wider text-muted font-medium">
                      Submitted
                    </div>
                    <div className="font-mono">{formatDate(quote.submitted_at)}</div>
                  </div>
                )}
                <div>
                  <div className="text-2xs uppercase tracking-wider text-muted font-medium">
                    Total delivered cost
                  </div>
                  <div className="font-mono">{formatPeso(quote.total_delivered_cost)}</div>
                </div>
              </div>
              <div className="border-t border-subtle pt-3">
                <div className="grid sm:grid-cols-2 gap-3 text-sm">
                  <div>
                    <div className="text-2xs uppercase tracking-wider text-muted font-medium">
                      VAT treatment
                    </div>
                    <div>
                      {quote.vat_treatment === 'exclusive'
                        ? 'VAT exclusive'
                        : quote.vat_treatment === 'inclusive'
                          ? 'VAT inclusive'
                          : 'No VAT'}
                    </div>
                  </div>
                  {quote.quote_valid_until && (
                    <div>
                      <div className="text-2xs uppercase tracking-wider text-muted font-medium">
                        Valid until
                      </div>
                      <div className="font-mono">{formatDate(quote.quote_valid_until)}</div>
                    </div>
                  )}
                  {quote.payment_terms && (
                    <div>
                      <div className="text-2xs uppercase tracking-wider text-muted font-medium">
                        Payment terms
                      </div>
                      <div>{quote.payment_terms}</div>
                    </div>
                  )}
                </div>
              </div>
              {quote.documents && quote.documents.length > 0 && (
                <div className="border-t border-subtle pt-3">
                  <div className="text-2xs uppercase tracking-wider text-muted font-medium mb-2">
                    Your documents
                  </div>
                  <div className="space-y-1">
                    {quote.documents.map((document) => (
                      <a
                        key={document.id}
                        className="block text-link text-sm hover:underline"
                        href={supplierRfqsApi.downloadDocumentUrl(id, document.id)}
                      >
                        {document.original_filename}
                      </a>
                    ))}
                  </div>
                </div>
              )}
            </div>
          </Panel>
        )}

        <div className="flex flex-wrap gap-2">
          {rfq.can_quote ? (
            <>
              <Link to={`/portal/supplier/rfqs/${id}/quote`}>
                <Button variant="primary">
                  {!quote
                    ? 'Prepare quotation'
                    : quote.status === 'draft'
                      ? 'Continue draft'
                      : 'Update quotation'}
                </Button>
              </Link>
              {quote && quote.status === 'submitted' && (
                <Button variant="secondary" onClick={() => setConfirmWithdraw(true)}>
                  Withdraw quotation
                </Button>
              )}
            </>
          ) : (
            <p className="text-sm text-muted">This RFQ is no longer accepting quotations.</p>
          )}
        </div>
      </div>
      <ConfirmDialog
        isOpen={confirmWithdraw}
        onClose={() => setConfirmWithdraw(false)}
        onConfirm={() => withdraw.mutate()}
        title="Withdraw your quotation?"
        description="It goes back to draft and will not be evaluated unless you submit it again before the deadline."
        confirmLabel="Withdraw"
        pending={withdraw.isPending}
      />
    </div>
  );
}
