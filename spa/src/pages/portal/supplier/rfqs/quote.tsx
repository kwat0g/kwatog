import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { supplierRfqsApi, type SupplierQuoteDraftData } from '@/api/purchasing/rfqs';
import { supplierPortalApi } from '@/api/b2b/supplier';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { toCentavos, fromCentavos } from '@/lib/money';
import { formatPeso } from '@/lib/formatNumber';
import type { SupplierQuote, RfqStatus } from '@/types/purchasing';

type LineValue = {
  status: 'quoted' | 'no_quote';
  quantity: string;
  price: string;
  lead: string;
  delivery: string;
};

/**
 * How the supplier declares VAT on their quotation. Mirrors the three
 * derivation branches the server enforces — the form no longer posts a
 * free-text VAT amount, because a mistyped one either misstates the tax or
 * bounces at submit.
 */
type VatTreatment = 'exclusive' | 'inclusive' | 'none';

const emptyLine = (): LineValue => ({
  status: 'no_quote',
  quantity: '',
  price: '',
  lead: '',
  delivery: '',
});

function canSubmit(status: RfqStatus, closesAt: string): boolean {
  return status === 'open' && new Date(closesAt).getTime() > Date.now();
}

type ParsedDecimal = { digits: bigint; scale: number; sign: bigint };

function parseDecimal(value: string): ParsedDecimal | null {
  const raw = value.trim().replace(/^\./, '0.').replace(/^-\./, '-0.');
  const match = /^([+-]?)(\d+)(?:\.(\d*))?$/.exec(raw);
  if (!match) return null;
  const fraction = match[3] ?? '';
  return {
    digits: BigInt(`${match[2]}${fraction}` || '0'),
    scale: fraction.length,
    sign: match[1] === '-' ? -1n : 1n,
  };
}

function roundHalfUp(numerator: bigint, denominator: bigint): bigint {
  if (numerator < 0n) return -roundHalfUp(-numerator, denominator);
  return (numerator * 2n + denominator) / (denominator * 2n);
}

/** Multiply decimal quantity and price, returning rounded whole centavos. */
function lineTotalCentavos(quantity: string, unitPrice: string): bigint {
  const left = parseDecimal(quantity);
  const right = parseDecimal(unitPrice);
  if (!left || !right) return 0n;

  const scale = left.scale + right.scale;
  const product = left.digits * right.digits * left.sign * right.sign;
  if (scale <= 2) return product * 10n ** BigInt(2 - scale);

  return roundHalfUp(product, 10n ** BigInt(scale - 2));
}

/** Apply the configured VAT rate to centavos without converting to a float. */
function vatCentavos(baseCentavos: number, rate: string, inclusive: boolean): bigint {
  const parsed = parseDecimal(rate);
  if (!parsed || parsed.sign < 0n) return 0n;

  const scale = 10n ** BigInt(parsed.scale);
  const denominator = inclusive ? scale + parsed.digits : scale;
  return roundHalfUp(BigInt(baseCentavos) * parsed.digits, denominator);
}

function formatPercent(rate: string): string | null {
  const parsed = parseDecimal(rate);
  if (!parsed) return null;

  const digits = (parsed.digits * 100n).toString();
  if (parsed.scale <= 2) return `${digits}${'0'.repeat(2 - parsed.scale)}`;

  const padded = digits.padStart(parsed.scale - 1, '0');
  const split = padded.length - (parsed.scale - 2);
  const fraction = padded.slice(split).replace(/0+$/, '');
  return fraction ? `${padded.slice(0, split)}.${fraction}` : padded.slice(0, split);
}

export default function SupplierRfqQuotePage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const query = useQuery({
    queryKey: ['portal', 'supplier', 'rfqs', id],
    queryFn: () => supplierRfqsApi.show(id),
    enabled: !!id,
  });
  // Same cache key the portal layout warms, so this is usually no refetch.
  const policies = useQuery({
    queryKey: ['portal', 'supplier', 'business-policies'],
    queryFn: () => supplierPortalApi.businessPolicies(),
    staleTime: 300_000,
  });
  const [values, setValues] = useState<Record<string, LineValue>>({});
  const [treatment, setTreatment] = useState<VatTreatment>('exclusive');
  const [freight, setFreight] = useState('0.00');
  const [other, setOther] = useState('0.00');
  const [terms, setTerms] = useState('');
  const [validUntil, setValidUntil] = useState('');
  const [notes, setNotes] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [qualityFiles, setQualityFiles] = useState<Record<string, File | null>>({});
  const [withdrawReason, setWithdrawReason] = useState('');
  const [initializedQuoteId, setInitializedQuoteId] = useState<string | null>(null);

  const rfq = query.data;
  const currentQuote = rfq?.quotes?.find(
    (quote) => quote.is_current && ['draft', 'submitted'].includes(quote.status),
  );
  const vatRate = policies.data?.vat_rate ?? null;
  const vatPercent = vatRate != null ? formatPercent(vatRate) : null;

  useEffect(() => {
    if (!rfq || initializedQuoteId === (currentQuote?.id ?? 'new')) return;
    const next: Record<string, LineValue> = {};
    rfq.items?.forEach((item) => {
      const line = currentQuote?.items.find((entry) => entry.rfq_item?.id === item.id);
      next[item.id] = line
        ? {
            status: line.response_status,
            quantity: line.offered_quantity ?? '',
            price: line.unit_price ?? '',
            lead: line.lead_time_days?.toString() ?? '',
            delivery: line.proposed_delivery_date ?? '',
          }
        : emptyLine();
    });
    setValues(next);
    // Restore the saved treatment: inclusive flag wins, then any stored amount.
    setTreatment(
      currentQuote
        ? currentQuote.vat_inclusive
          ? 'inclusive'
          : toCentavos(currentQuote.vat_amount ?? '0') > 0
            ? 'exclusive'
            : 'none'
        : 'exclusive',
    );
    setFreight(currentQuote?.freight_amount ?? '0.00');
    setOther(currentQuote?.other_charges ?? '0.00');
    setTerms(currentQuote?.payment_terms ?? '');
    setValidUntil(currentQuote?.quote_valid_until ?? '');
    setNotes(currentQuote?.notes ?? '');
    setInitializedQuoteId(currentQuote?.id ?? 'new');
  }, [currentQuote, initializedQuoteId, rfq]);

  const quotedLineIds = useMemo(
    () =>
      new Set(
        Object.entries(values)
          .filter(([, value]) => value.status === 'quoted')
          .map(([key]) => key),
      ),
    [values],
  );

  const itemsTotal = useMemo(() => {
    const total = (rfq?.items ?? [])
      .filter((item) => quotedLineIds.has(item.id))
      .reduce((sum, item) => {
        const value = values[item.id] ?? emptyLine();
        return sum + lineTotalCentavos(value.quantity, value.price);
      }, 0n);
    return fromCentavos(Number(total));
  }, [rfq?.items, values, quotedLineIds]);

  const vatDisplay = useMemo(() => {
    if (treatment === 'none') return '0.00';
    if (vatRate == null || toCentavos(itemsTotal) === 0) return null;
    const baseCentavos = toCentavos(itemsTotal) + toCentavos(freight) + toCentavos(other);
    return fromCentavos(Number(vatCentavos(baseCentavos, vatRate, treatment === 'inclusive')));
  }, [treatment, itemsTotal, freight, other, vatRate]);

  const totalDelivered = useMemo(
    () =>
      fromCentavos(
        // Inclusive prices already hold the VAT; only exclusive VAT is added on top.
        toCentavos(itemsTotal) +
          toCentavos(treatment === 'exclusive' ? (vatDisplay ?? '0.00') : '0.00') +
          toCentavos(freight) +
          toCentavos(other),
      ),
    [itemsTotal, vatDisplay, treatment, freight, other],
  );

  const payload = (): SupplierQuoteDraftData => ({
    // The server derives VAT from the lines + this flag; only the explicit
    // no-VAT declaration posts an amount (zero).
    vat_inclusive: treatment === 'inclusive',
    ...(treatment === 'none' ? { vat_amount: '0.00' } : {}),
    freight_amount: freight,
    other_charges: other,
    quote_valid_until: validUntil || undefined,
    payment_terms: terms || undefined,
    notes: notes || undefined,
    items: (rfq?.items ?? []).map((item) => {
      const value = values[item.id] ?? emptyLine();
      return {
        request_for_quote_item_id: item.id,
        response_status: value.status,
        ...(value.status === 'quoted'
          ? {
              offered_quantity: value.quantity,
              unit_price: value.price,
              lead_time_days: value.lead ? Number(value.lead) : undefined,
              proposed_delivery_date: value.delivery || undefined,
            }
          : {}),
      };
    }),
  });

  const saveOrUpdate = async (): Promise<SupplierQuote> => {
    if (currentQuote) return supplierRfqsApi.updateQuote(id, currentQuote.id, payload());
    return supplierRfqsApi.saveQuote(id, payload());
  };

  const saveDraft = useMutation({
    mutationFn: saveOrUpdate,
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['portal', 'supplier', 'rfqs', id] });
      toast.success('Draft quotation saved.');
    },
    onError: () => toast.error('Could not save the quotation draft.'),
  });

  const submit = useMutation({
    mutationFn: async () => {
      const quote = await saveOrUpdate();
      if (!file && !quote.quotation_original_filename)
        throw new Error('formal quotation PDF required');
      if (file) {
        const form = new FormData();
        form.append('file', file);
        form.append('document_type', 'quotation_pdf');
        await supplierRfqsApi.uploadDocument(id, quote.id, form);
      }
      for (const [documentType, qualityFile] of Object.entries(qualityFiles)) {
        if (!qualityFile) continue;
        const form = new FormData();
        form.append('file', qualityFile);
        form.append('document_type', documentType);
        await supplierRfqsApi.uploadDocument(id, quote.id, form);
      }
      return supplierRfqsApi.submit(id, quote.id);
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['portal', 'supplier', 'rfqs', id] });
      toast.success('Quotation submitted securely.');
      navigate(`/portal/supplier/rfqs/${id}`);
    },
    onError: () =>
      toast.error('Could not submit quotation. Check each line, deadline, and PDF attachment.'),
  });

  const withdraw = useMutation({
    mutationFn: () => supplierRfqsApi.withdraw(id, currentQuote?.id ?? '', withdrawReason),
    onSuccess: () => {
      toast.success('Quotation withdrawn.');
      navigate(`/portal/supplier/rfqs/${id}`);
    },
    onError: () => toast.error('Could not withdraw this quotation.'),
  });

  if (query.isLoading) return <SkeletonTable columns={4} rows={6} />;
  if (query.isError || !rfq)
    return (
      <EmptyState
        icon="alert-circle"
        title="RFQ unavailable"
        action={<Button onClick={() => query.refetch()}>Retry</Button>}
      />
    );
  if (!canSubmit(rfq.status, rfq.closes_at))
    return (
      <EmptyState
        icon="lock"
        title="This RFQ is closed"
        description="The server deadline has passed or the sourcing event is no longer accepting submissions."
        action={
          <Button variant="secondary" onClick={() => navigate(`/portal/supplier/rfqs/${id}`)}>
            Back to RFQ
          </Button>
        }
      />
    );

  const vatHelper =
    treatment === 'inclusive'
      ? `Computed at ${vatPercent ?? 'the prevailing rate'} of your prices, extracted from the gross (prices already include it).`
      : treatment === 'none'
        ? 'Your quotation is declared without VAT. Attach your formal quotation PDF stating the VAT treatment.'
        : `Computed at ${vatPercent ?? 'the prevailing rate'} on top of your unit prices and charges.`;

  return (
    <div>
      <PageHeader
        title="Prepare quotation"
        subtitle={rfq.rfq_number}
        backTo={`/portal/supplier/rfqs/${id}`}
        backLabel="RFQ invitation"
      />
      <div className="px-5 py-4 max-w-4xl space-y-4">
        <Panel title="Line response">
          <p className="text-sm text-muted mb-3">
            Quote each requested line. Leave a line as &ldquo;No quote&rdquo; if you cannot supply
            it — that line only is excluded, the rest of your quotation stands.
          </p>
          <div className="space-y-3">
            {rfq.items?.map((item) => {
              const value = values[item.id] ?? emptyLine();
              const lineTotal =
                value.status === 'quoted' && value.quantity && value.price
                  ? fromCentavos(Number(lineTotalCentavos(value.quantity, value.price)))
                  : null;
              return (
                <div key={item.id} className="rounded border border-default p-3">
                  <div className="flex justify-between gap-3">
                    <div>
                      <div className="font-medium">{item.description}</div>
                      <div className="font-mono text-xs text-muted">
                        Requested {item.quantity} {item.unit ?? ''}
                      </div>
                    </div>
                    <label className="text-sm">
                      <input
                        type="checkbox"
                        checked={value.status === 'no_quote'}
                        onChange={(event) =>
                          setValues((current) => ({
                            ...current,
                            [item.id]: {
                              ...value,
                              status: event.target.checked ? 'no_quote' : 'quoted',
                            },
                          }))
                        }
                      />{' '}
                      No quote
                    </label>
                  </div>
                  {value.status === 'quoted' && (
                    <div className="grid sm:grid-cols-3 gap-2 mt-3">
                      <Input
                        label={`Offered quantity (${item.unit ?? 'units'})`}
                        inputMode="decimal"
                        value={value.quantity}
                        onChange={(event) =>
                          setValues((current) => ({
                            ...current,
                            [item.id]: { ...value, quantity: event.target.value },
                          }))
                        }
                      />
                      <Input
                        label={`Unit price (₱ per ${item.unit ?? 'unit'})`}
                        inputMode="decimal"
                        prefix="₱"
                        value={value.price}
                        onChange={(event) =>
                          setValues((current) => ({
                            ...current,
                            [item.id]: { ...value, price: event.target.value },
                          }))
                        }
                      />
                      <Input
                        label="Lead time days"
                        inputMode="numeric"
                        helper="Days from PO to delivery"
                        value={value.lead}
                        onChange={(event) =>
                          setValues((current) => ({
                            ...current,
                            [item.id]: { ...value, lead: event.target.value },
                          }))
                        }
                      />
                      <Input
                        label="Proposed delivery date"
                        type="date"
                        value={value.delivery}
                        onChange={(event) =>
                          setValues((current) => ({
                            ...current,
                            [item.id]: { ...value, delivery: event.target.value },
                          }))
                        }
                      />
                      {lineTotal !== null && (
                        <div className="sm:col-span-2 flex items-end justify-end">
                          <span className="text-sm text-muted">
                            Line total{' '}
                            <span className="font-mono tabular-nums text-ink">
                              {formatPeso(lineTotal)}
                            </span>
                          </span>
                        </div>
                      )}
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        </Panel>

        <Panel title="Prices and VAT">
          <p className="text-sm text-muted mb-3">
            State whether your unit prices include VAT. The VAT amount is computed from your quoted
            lines — it cannot be entered manually.
          </p>
          <fieldset className="flex flex-wrap items-center gap-x-5 gap-y-2">
            {(
              [
                ['exclusive', 'VAT-exclusive'],
                ['inclusive', 'VAT-inclusive'],
                ['none', 'No VAT'],
              ] as Array<[VatTreatment, string]>
            ).map(([optionValue, label]) => (
              <label key={optionValue} className="flex items-center gap-1.5 text-sm cursor-pointer">
                <input
                  type="radio"
                  name="vat_treatment"
                  checked={treatment === optionValue}
                  onChange={() => setTreatment(optionValue)}
                />
                {label}
              </label>
            ))}
          </fieldset>
          <p className="text-xs text-muted mt-1">
            {treatment === 'exclusive'
              ? `VAT at ${vatPercent ?? 'the prevailing rate'} will be added on top of the quoted amounts.`
              : treatment === 'inclusive'
                ? 'The quoted amounts already include VAT; the VAT component is extracted from them.'
                : 'Not VAT-registered, or a VAT-exempt sale. State this in your formal quotation.'}
          </p>
          <div className="grid sm:grid-cols-2 gap-3 mt-3">
            <Input
              label="VAT (computed)"
              prefix="₱"
              readOnly
              value={vatDisplay ?? '—'}
              helper={vatHelper}
              containerClassName="[&_input]:font-mono"
            />
            <Input
              label="Delivery / freight charges"
              inputMode="decimal"
              prefix="₱"
              value={freight}
              onChange={(event) => setFreight(event.target.value)}
              helper="Charge to deliver to the Ogami plant (FCIE Dasmariñas). Enter 0.00 if included in your prices."
            />
            <Input
              label="Other charges"
              inputMode="decimal"
              prefix="₱"
              value={other}
              onChange={(event) => setOther(event.target.value)}
              helper="Packing, testing, or other billable extras. 0.00 if none."
            />
          </div>
        </Panel>

        <Panel title="Quotation summary">
          <dl className="space-y-1 text-sm">
            <div className="flex justify-between">
              <dt className="text-muted">Items total</dt>
              <dd className="font-mono tabular-nums">{formatPeso(itemsTotal)}</dd>
            </div>
            <div className="flex justify-between">
              <dt className="text-muted">VAT</dt>
              <dd className="font-mono tabular-nums">{formatPeso(vatDisplay)}</dd>
            </div>
            <div className="flex justify-between">
              <dt className="text-muted">Delivery / freight</dt>
              <dd className="font-mono tabular-nums">{formatPeso(freight)}</dd>
            </div>
            <div className="flex justify-between">
              <dt className="text-muted">Other charges</dt>
              <dd className="font-mono tabular-nums">{formatPeso(other)}</dd>
            </div>
            <div className="flex justify-between border-t border-default pt-2 mt-2 font-medium">
              <dt>Total delivered cost</dt>
              <dd className="font-mono tabular-nums">{formatPeso(totalDelivered)}</dd>
            </div>
          </dl>
        </Panel>

        <Panel title="Terms and documents">
          <div className="grid sm:grid-cols-2 gap-3">
            <Input
              label="Quote valid until"
              type="date"
              value={validUntil}
              onChange={(event) => setValidUntil(event.target.value)}
              helper="How long this offer stands"
            />
            <Input
              label="Payment terms"
              value={terms}
              onChange={(event) => setTerms(event.target.value)}
              helper="e.g. 30 days, 50% advance"
            />
            <Input
              label="Formal quotation PDF"
              type="file"
              accept="application/pdf,.pdf"
              onChange={(event) => setFile(event.target.files?.[0] ?? null)}
              helper="Required before submission — your signed offer"
            />
          </div>
          <label className="block text-sm mt-3">
            Notes
            <textarea
              className="mt-1 w-full min-h-20 border border-default rounded-md bg-canvas p-3"
              value={notes}
              onChange={(event) => setNotes(event.target.value)}
            />
          </label>
        </Panel>

        {currentQuote?.documents && currentQuote.documents.length > 0 && (
          <Panel title="Your uploaded documents">
            <ul className="space-y-2 text-sm">
              {currentQuote.documents.map((document) => (
                <li key={document.id}>
                  <a
                    className="text-link hover:underline"
                    href={supplierRfqsApi.downloadDocumentUrl(id, document.id)}
                  >
                    {document.original_filename}
                  </a>
                  <span className="ml-2 text-xs text-muted">
                    {document.document_type.replace(/_/g, ' ')}
                  </span>
                </li>
              ))}
            </ul>
          </Panel>
        )}

        <Panel title="Quality evidence">
          <p className="text-sm text-muted mb-3">
            Attach any available resin datasheet, certificate of analysis, safety, or compliance
            evidence. Files remain private to this RFQ.
          </p>
          <div className="grid sm:grid-cols-2 gap-3">
            {[
              ['resin_datasheet', 'Resin datasheet'],
              ['certificate_of_analysis', 'Certificate of analysis'],
              ['safety_document', 'Safety document'],
              ['compliance_document', 'Compliance document'],
            ].map(([type, label]) => (
              <Input
                key={type}
                label={label}
                type="file"
                accept="application/pdf,.pdf,image/png,image/jpeg"
                onChange={(event) =>
                  setQualityFiles((current) => ({
                    ...current,
                    [type]: event.target.files?.[0] ?? null,
                  }))
                }
              />
            ))}
          </div>
        </Panel>

        <div className="flex flex-wrap justify-between gap-2">
          <Button variant="secondary" onClick={() => navigate(`/portal/supplier/rfqs/${id}`)}>
            Cancel
          </Button>
          <div className="flex flex-wrap gap-2">
            <Button
              variant="secondary"
              onClick={() => saveDraft.mutate()}
              loading={saveDraft.isPending}
            >
              Save draft
            </Button>
            <Button variant="primary" onClick={() => submit.mutate()} loading={submit.isPending}>
              Submit sealed quotation
            </Button>
          </div>
        </div>
        {currentQuote?.status === 'submitted' && (
          <Panel title="Withdraw submitted quotation">
            <Input
              label="Withdrawal reason"
              value={withdrawReason}
              onChange={(event) => setWithdrawReason(event.target.value)}
            />
            <Button
              className="mt-3"
              variant="secondary"
              disabled={!withdrawReason.trim()}
              onClick={() => withdraw.mutate()}
              loading={withdraw.isPending}
            >
              Withdraw quotation
            </Button>
          </Panel>
        )}
      </div>
    </div>
  );
}
