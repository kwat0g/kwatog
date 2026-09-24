import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { rfqsApi } from '@/api/purchasing/rfqs';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatDate } from '@/lib/formatDate';
import { formatPeso, formatQuantity } from '@/lib/formatNumber';
import type { SupplierQuote } from '@/types/purchasing';

const errMsg = (e: unknown, fallback: string) =>
  (e instanceof AxiosError ? e.response?.data?.message : undefined) ?? fallback;

const VAT_LABEL: Record<SupplierQuote['vat_treatment'], string> = {
  exclusive: 'VAT exclusive',
  inclusive: 'VAT inclusive',
  none: 'No VAT',
};

const DOC_LABEL: Record<string, string> = {
  quotation_pdf: 'Quotation',
  certificate_of_analysis: 'CoA',
  resin_datasheet: 'Datasheet',
  safety_document: 'Safety',
};

/**
 * Sealed-bid comparison, available once the RFQ closes. One winner per line:
 * the lowest delivered cost (freight and VAT shared by goods value, expired
 * quotes excluded) is preselected, and the buyer can change or skip any line.
 */
export default function RfqComparisonPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const [reason, setReason] = useState('');
  const [picked, setPicked] = useState<Record<string, string>>({}); // RFQ line → quote line ('' = do not award)
  const [seeded, setSeeded] = useState(false);

  const query = useQuery({
    queryKey: ['purchasing', 'rfqs', id, 'comparison'],
    queryFn: () => rfqsApi.comparison(id),
    enabled: !!id,
  });
  const rfq = query.data;
  const quotes = useMemo(() => rfq?.quotes ?? [], [rfq?.quotes]);
  const canAward = !!rfq?.actions.can_award;
  // Ogami recovers input VAT, so suppliers are ranked on cost excluding it.
  const exVat = rfq?.ranking_basis === 'ex_vat';

  // Preselect the recommended quote per line, once.
  useEffect(() => {
    if (seeded || !rfq) return;
    const initial: Record<string, string> = {};
    for (const quote of rfq.quotes) {
      for (const line of quote.items) {
        if (line.is_recommended) initial[line.request_for_quote_item_id] = line.id;
      }
    }
    setPicked(initial);
    setSeeded(true);
  }, [seeded, rfq]);

  const award = useMutation({
    mutationFn: () =>
      rfqsApi.award(id, {
        award_reason: reason.trim(),
        lines: Object.entries(picked)
          .filter(([, quoteLineId]) => quoteLineId)
          .map(([rfqLineId, quoteLineId]) => ({
            request_for_quote_item_id: rfqLineId,
            supplier_quote_item_id: quoteLineId,
          })),
      }),
    onSuccess: async (result) => {
      await qc.invalidateQueries({ queryKey: ['purchasing', 'rfqs'] });
      if (rfq?.purchase_request?.id) {
        await qc.invalidateQueries({
          queryKey: ['purchasing', 'purchase-requests', rfq.purchase_request.id],
        });
      }
      toast.success(`Awarded. ${result.purchase_orders.length} draft purchase order(s) created.`);
      navigate(`/purchasing/rfqs/${id}`);
    },
    onError: (e) => toast.error(errMsg(e, 'The award could not be saved.')),
  });

  if (query.isLoading) return <SkeletonTable columns={5} rows={6} />;
  if (query.isError || !rfq) {
    return (
      <EmptyState
        icon="alert-circle"
        title="Comparison unavailable"
        description={errMsg(query.error, 'Quotations can be compared once the RFQ closes.')}
        action={<Button onClick={() => navigate(`/purchasing/rfqs/${id}`)}>Back to RFQ</Button>}
      />
    );
  }
  if (quotes.length === 0) {
    return (
      <EmptyState
        icon="inbox"
        title="No submitted quotations"
        action={<Button onClick={() => navigate(`/purchasing/rfqs/${id}`)}>Back to RFQ</Button>}
      />
    );
  }

  const pickedCount = Object.values(picked).filter(Boolean).length;
  const responses = (lineId: string) =>
    quotes.filter((quote) =>
      quote.items.some(
        (line) => line.request_for_quote_item_id === lineId && line.response_status === 'quoted',
      ),
    ).length;
  const singleResponse = rfq.items.some((item) => picked[item.id] && responses(item.id) === 1);

  return (
    <div>
      <PageHeader
        title="Compare & award"
        subtitle={<span className="font-mono">{rfq.rfq_number}</span>}
        backTo={`/purchasing/rfqs/${id}`}
        backLabel="RFQ"
      />

      <div className="px-5 py-4 space-y-4">
        {rfq.status === 'awarded' && (
          <div className="rounded-md border border-default bg-subtle px-4 py-3 text-sm">
            This RFQ has been awarded. The comparison is shown for reference.
          </div>
        )}

        {exVat && (
          <p className="text-xs text-muted">
            Suppliers are ranked on cost excluding VAT, because Ogami recovers the VAT it pays as input VAT. Totals
            below include VAT as quoted.
          </p>
        )}

        <Panel title="Suppliers" meta={`${quotes.length} submitted`}>
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            {quotes.map((quote) => (
              <div key={quote.id} className="rounded-md border border-default p-3 text-sm">
                <div className="flex items-start justify-between gap-2">
                  <span className="font-medium">{quote.vendor?.name ?? 'Supplier'}</span>
                  {quote.is_expired && <Chip variant="danger">Expired</Chip>}
                </div>
                <div className="mt-1 font-mono tabular-nums text-base">
                  {formatPeso(quote.total_delivered_cost)}
                </div>
                <dl className="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                  <dt className="text-muted">VAT</dt>
                  <dd>{VAT_LABEL[quote.vat_treatment]}</dd>
                  <dt className="text-muted">Freight</dt>
                  <dd className="font-mono tabular-nums">{formatPeso(quote.freight_amount)}</dd>
                  <dt className="text-muted">Valid until</dt>
                  <dd className="font-mono">
                    {quote.quote_valid_until ? formatDate(quote.quote_valid_until) : '—'}
                  </dd>
                  <dt className="text-muted">Payment terms</dt>
                  <dd>{quote.payment_terms || '—'}</dd>
                  <dt className="text-muted">Quality history</dt>
                  <dd>
                    {quote.supplier_performance ? (
                      <span className="font-mono tabular-nums">
                        {quote.supplier_performance.quality_pass_rate ?? '—'}% pass ·{' '}
                        {quote.supplier_performance.ncr_rate ?? '—'}% NCR ·{' '}
                        {quote.supplier_performance.on_time_delivery_rate ?? '—'}% on time
                      </span>
                    ) : (
                      <span className="text-muted">No history</span>
                    )}
                  </dd>
                </dl>
                {quote.documents.length > 0 && (
                  <div className="mt-2 flex flex-wrap gap-2 text-xs">
                    {quote.documents.map((doc) => (
                      <a
                        key={doc.id}
                        href={rfqsApi.documentDownloadUrl(doc.id)}
                        className="text-link hover:underline"
                      >
                        {DOC_LABEL[doc.document_type] ?? doc.original_filename}
                      </a>
                    ))}
                  </div>
                )}
                {quote.captured_manually && (
                  <div className="mt-2 text-xs text-muted">Entered by purchasing</div>
                )}
              </div>
            ))}
          </div>
        </Panel>

        {rfq.items.map((item) => (
          <Panel
            key={item.id}
            title={item.description}
            meta={`${formatQuantity(item.quantity)} ${item.unit ?? ''}${item.required_delivery_date ? ` · needed by ${formatDate(item.required_delivery_date)}` : ''}${item.specification ? ` · ${item.specification}` : ''}`}
          >
            <div className="overflow-x-auto">
              <table className="w-full min-w-[640px] text-sm">
                <caption className="sr-only">Quotations for {item.description}</caption>
                <thead>
                  <tr className="border-b border-default text-left text-2xs uppercase tracking-wider text-muted">
                    {canAward && <th scope="col" className="py-2 w-8" />}
                    <th scope="col" className="py-2">
                      Supplier
                    </th>
                    <th scope="col" className="py-2 text-right">
                      Unit price
                    </th>
                    <th scope="col" className="py-2 text-right">
                      Offered
                    </th>
                    <th scope="col" className="py-2 text-right">
                      {exVat ? 'Cost / unit ex-VAT' : 'Delivered / unit'}
                    </th>
                    <th scope="col" className="py-2 text-right">
                      Delivered total
                    </th>
                    <th scope="col" className="py-2 pl-4">
                      Delivery
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {quotes.map((quote) => {
                    const line = quote.items.find(
                      (row) => row.request_for_quote_item_id === item.id,
                    );
                    const quoted = line?.response_status === 'quoted';
                    const radioName = `line-${item.id}`;
                    return (
                      <tr key={quote.id} className="border-b border-subtle align-top">
                        {canAward && (
                          <td className="py-2">
                            <input
                              type="radio"
                              name={radioName}
                              aria-label={`Award ${item.description} to ${quote.vendor?.name ?? 'supplier'}`}
                              disabled={!quoted || quote.is_expired}
                              checked={!!line && picked[item.id] === line.id}
                              onChange={() =>
                                line && setPicked((cur) => ({ ...cur, [item.id]: line.id }))
                              }
                            />
                          </td>
                        )}
                        <td className="py-2">
                          <div className="flex flex-wrap items-center gap-1">
                            <span>{quote.vendor?.name ?? 'Supplier'}</span>
                            {line?.is_recommended && (
                              <Chip variant="success">Lowest cost</Chip>
                            )}
                          </div>
                        </td>
                        {quoted && line ? (
                          <>
                            <td className="py-2 text-right font-mono tabular-nums">
                              {formatPeso(line.unit_price ?? '0')}
                            </td>
                            <td className="py-2 text-right font-mono tabular-nums">
                              {formatQuantity(line.offered_quantity ?? '0')}
                            </td>
                            <td className="py-2 text-right font-mono tabular-nums">
                              {(exVat ? line.unit_net_cost : line.unit_delivered_cost)
                                ? formatPeso((exVat ? line.unit_net_cost : line.unit_delivered_cost) ?? '0')
                                : '—'}
                            </td>
                            <td className="py-2 text-right font-mono tabular-nums">
                              {line.allocated_delivered_cost
                                ? formatPeso(line.allocated_delivered_cost)
                                : '—'}
                            </td>
                            <td className="py-2 pl-4">
                              <div className="flex flex-wrap items-center gap-1">
                                <span className="font-mono text-xs">
                                  {line.proposed_delivery_date
                                    ? formatDate(line.proposed_delivery_date)
                                    : line.lead_time_days !== null
                                      ? `${line.lead_time_days} d lead time`
                                      : '—'}
                                </span>
                                {line.meets_required_date === true && (
                                  <Chip variant="success">On time</Chip>
                                )}
                                {line.meets_required_date === false && (
                                  <Chip variant="warning">Late</Chip>
                                )}
                              </div>
                            </td>
                          </>
                        ) : (
                          <td colSpan={5} className="py-2 text-muted">
                            No quote
                          </td>
                        )}
                      </tr>
                    );
                  })}
                  {canAward && (
                    <tr>
                      <td className="py-2">
                        <input
                          type="radio"
                          name={`line-${item.id}`}
                          aria-label={`Do not award ${item.description}`}
                          checked={!picked[item.id]}
                          onChange={() => setPicked((cur) => ({ ...cur, [item.id]: '' }))}
                        />
                      </td>
                      <td colSpan={6} className="py-2 text-muted">
                        Don&apos;t award — the quantity goes back to the purchase request
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </Panel>
        ))}

        {canAward && (
          <Panel title="Award">
            <div className="space-y-3">
              <label className="block text-sm">
                <span className="block text-2xs uppercase tracking-wider text-muted mb-1">
                  Reason for this award
                </span>
                <textarea
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                  className="w-full min-h-20 border border-default rounded-md bg-canvas p-3 text-sm"
                  placeholder="Lowest delivered cost with an acceptable lead time and quality history."
                />
              </label>
              {singleResponse && (
                <p className="text-xs text-warning-fg">
                  Only one supplier quoted at least one of the chosen lines — say why the price is
                  acceptable.
                </p>
              )}
              <div className="flex flex-wrap items-center justify-between gap-3">
                <span className="text-sm text-muted">
                  <span className="font-mono tabular-nums">{pickedCount}</span> of{' '}
                  <span className="font-mono tabular-nums">{rfq.items.length}</span> lines chosen.
                  One draft PO is created per winning supplier; lines left unawarded return to{' '}
                  {rfq.purchase_request ? (
                    <Link
                      className="text-link font-mono"
                      to={`/purchasing/purchase-requests/${rfq.purchase_request.id}`}
                    >
                      {rfq.purchase_request.pr_number}
                    </Link>
                  ) : (
                    'the purchase request'
                  )}
                  .
                </span>
                <Button
                  variant="primary"
                  disabled={pickedCount === 0 || !reason.trim() || award.isPending}
                  loading={award.isPending}
                  onClick={() => award.mutate()}
                >
                  {award.isPending ? 'Awarding…' : 'Award & create draft POs'}
                </Button>
              </div>
            </div>
          </Panel>
        )}
      </div>
    </div>
  );
}
