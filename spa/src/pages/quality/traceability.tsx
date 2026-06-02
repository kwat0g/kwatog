/**
 * ADV3 — IATF 16949 traceability search.
 *
 * Single search box accepts any of:
 *   - BATCH-YYYYMM-NNNN  → traces a production batch (work order)
 *   - LOT-YYYYMM-NNNN    → traces an outgoing shipment lot
 *   - <material lot>     → traces an incoming GRN material lot
 *
 * The result is rendered as a single backward → focus → forward tree, regardless
 * of which leg the user searched on, so we have one consistent UI for all three
 * traceability queries an auditor might ask for.
 */
import { useMemo, useState } from 'react';
import { useSearchParams, Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Search, Tag, Layers, Package, Factory, ShieldCheck, Truck, Building2 } from 'lucide-react';
import { traceabilityApi, type TraceabilityResult } from '@/api/quality/traceability';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';

export default function TraceabilityPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const submitted = searchParams.get('term') ?? '';
  const [term, setTerm] = useState(submitted);

  const { data, isLoading, isError, refetch, isFetching } = useQuery({
    queryKey: ['quality', 'traceability', submitted],
    queryFn: () => traceabilityApi.search(submitted),
    enabled: submitted.length > 0,
  });

  const onSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    const trimmed = term.trim();
    if (trimmed) {
      setSearchParams({ term: trimmed }, { replace: true });
    }
  };

  return (
    <div>
      <PageHeader
        title="Traceability search"
        subtitle="Trace a production batch, shipment lot, or supplier material lot end-to-end."
        breadcrumbs={[{ label: 'Quality', href: '/quality' }, { label: 'Traceability' }]}
      />

      <div className="px-5 pt-4">
        <form onSubmit={onSubmit} className="flex items-center gap-2 max-w-2xl">
          <div className="flex-1 flex items-center h-9 rounded-md border border-default bg-canvas focus-within:ring-2 focus-within:ring-accent focus-within:border-accent">
            <Search size={14} className="ml-2 text-text-subtle" />
            <input
              autoFocus
              type="text"
              value={term}
              onChange={(e) => setTerm(e.target.value)}
              placeholder="BATCH-…, LOT-…, or supplier material lot"
              className="flex-1 h-full px-2 bg-transparent text-sm outline-none placeholder:text-text-subtle font-mono"
            />
          </div>
          <Button type="submit" variant="primary" size="sm" loading={isFetching} disabled={!term.trim()}>
            Trace
          </Button>
        </form>
      </div>

      <div className="px-5 pb-6 pt-4 space-y-4">
        {!submitted && (
          <EmptyState
            icon="search"
            title="Enter a batch, lot, or material lot number"
            description="Examples: BATCH-202601-0007 · LOT-202601-0042 · SUP-LOT-882C"
          />
        )}

        {submitted && isLoading && (
          <Panel title="Searching…">
            <div className="text-sm text-muted">Tracing {submitted}…</div>
          </Panel>
        )}

        {submitted && isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load trace"
            action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
          />
        )}

        {submitted && data && !data.found && (
          <EmptyState
            icon="search"
            title="No match found"
            description={`No batch, shipment lot, or material lot matches "${submitted}".`}
          />
        )}

        {submitted && data?.found && <TraceTree result={data} />}
      </div>
    </div>
  );
}

/** Renders the unified trace tree, branching on result.type. */
function TraceTree({ result }: { result: TraceabilityResult }) {
  const typeLabel = useMemo(() => {
    if (result.type === 'batch') return 'Production batch';
    if (result.type === 'lot') return 'Shipment lot';
    if (result.type === 'material_lot') return 'Material lot';
    return 'Result';
  }, [result.type]);

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-2 text-2xs uppercase tracking-wider text-muted font-medium">
        <span>Match</span>
        <Chip variant="info">{typeLabel}</Chip>
        <span className="font-mono text-text-primary">{result.term}</span>
      </div>

      {result.type === 'batch' && <BatchView result={result} />}
      {result.type === 'lot' && <LotView result={result} />}
      {result.type === 'material_lot' && <MaterialLotView result={result} />}
    </div>
  );
}

function BatchView({ result }: { result: TraceabilityResult }) {
  const wo = result.trace.work_order;
  const materials = result.trace.backward?.materials ?? [];
  const inspections = result.trace.forward?.inspections ?? [];
  const lots = result.trace.forward?.lots ?? [];

  if (!wo) return null;

  return (
    <div className="grid grid-cols-3 gap-4">
      <div className="col-span-1">
        <SectionCard icon={<Package size={14} />} title="Backward — materials">
          {materials.length === 0
            ? <p className="text-xs text-muted">No material lot references recorded.</p>
            : (
              <ul className="text-xs space-y-2">
                {materials.map((m, i) => (
                  <li key={i} className="border-l-2 border-subtle pl-2">
                    <div className="font-mono">{m.material_lot_number ?? '—'}</div>
                    <div className="text-muted">
                      {m.item_code ?? '—'}{m.item_name ? ` · ${m.item_name}` : ''}
                    </div>
                    {m.grn_number && <div className="text-muted">GRN {m.grn_number}</div>}
                    {m.supplier_lot_reference && (
                      <div className="text-muted">Supplier lot {m.supplier_lot_reference}</div>
                    )}
                  </li>
                ))}
              </ul>
            )}
        </SectionCard>
      </div>

      <div className="col-span-1">
        <SectionCard icon={<Factory size={14} />} title="Production batch" highlight>
          <dl className="text-xs space-y-1.5">
            <Row label="Batch" value={<span className="font-mono">{wo.batch_number ?? '—'}</span>} />
            <Row label="Work order" value={
              <Link to={`/production/work-orders/${wo.id}`} className="font-mono text-accent hover:underline">
                {wo.wo_number}
              </Link>
            } />
            <Row label="Product" value={wo.product
              ? <span><span className="font-mono">{wo.product.part_number}</span> · {wo.product.name}</span>
              : '—'} />
            <Row label="Machine" value={wo.machine ? `${wo.machine.machine_code} · ${wo.machine.name}` : '—'} />
            <Row label="Mold" value={wo.mold ? `${wo.mold.mold_code} · ${wo.mold.name}` : '—'} />
            <Row label="Status" value={<Chip variant="neutral">{wo.status}</Chip>} />
            <Row label="Good qty" value={<span className="font-mono tabular-nums">{wo.quantity_good}</span>} />
            <Row label="Rejected" value={<span className="font-mono tabular-nums">{wo.quantity_rejected}</span>} />
          </dl>
        </SectionCard>
      </div>

      <div className="col-span-1 space-y-4">
        <SectionCard icon={<ShieldCheck size={14} />} title="Forward — inspections">
          {inspections.length === 0
            ? <p className="text-xs text-muted">No inspections recorded.</p>
            : (
              <ul className="text-xs space-y-1.5">
                {inspections.map((i) => (
                  <li key={i.id}>
                    <Link to={`/quality/inspections/${i.id}`} className="font-mono text-accent hover:underline">
                      {i.inspection_number}
                    </Link>
                    <span className="text-muted ml-2">{i.stage} · {i.status}</span>
                  </li>
                ))}
              </ul>
            )}
        </SectionCard>

        <SectionCard icon={<Tag size={14} />} title="Forward — shipment lots">
          {lots.length === 0
            ? <p className="text-xs text-muted">Not yet shipped.</p>
            : (
              <ul className="text-xs space-y-2">
                {lots.map((l) => (
                  <li key={l.id} className="border-l-2 border-subtle pl-2">
                    <Link
                      to={`/quality/traceability?term=${encodeURIComponent(l.lot_number)}`}
                      className="font-mono text-accent hover:underline"
                    >
                      {l.lot_number}
                    </Link>
                    {l.delivery && (
                      <div className="text-muted">
                        <Link to={`/supply-chain/deliveries/${l.delivery.id}`} className="hover:underline">
                          {l.delivery.delivery_number}
                        </Link>
                      </div>
                    )}
                    {l.customer?.name && (
                      <div className="text-muted flex items-center gap-1">
                        <Building2 size={11} />{l.customer.name}
                      </div>
                    )}
                  </li>
                ))}
              </ul>
            )}
        </SectionCard>
      </div>
    </div>
  );
}

function LotView({ result }: { result: TraceabilityResult }) {
  const lot = result.trace.lot;
  const woRows = result.trace.backward?.work_orders ?? [];
  const delivery = result.trace.forward?.delivery ?? null;
  const customer = result.trace.forward?.customer ?? null;

  if (!lot) return null;

  return (
    <div className="grid grid-cols-3 gap-4">
      <div className="col-span-1">
        <SectionCard icon={<Layers size={14} />} title="Backward — production batches">
          {woRows.length === 0
            ? <p className="text-xs text-muted">No batches linked.</p>
            : (
              <ul className="text-xs space-y-3">
                {woRows.map((row) => (
                  <li key={row.work_order.id} className="border-l-2 border-subtle pl-2">
                    <div>
                      <Link to={`/quality/traceability?term=${encodeURIComponent(row.work_order.batch_number ?? '')}`}
                        className="font-mono text-accent hover:underline">
                        {row.work_order.batch_number ?? row.work_order.wo_number}
                      </Link>
                      <span className="text-muted ml-2">{row.work_order.quantity_good} good</span>
                    </div>
                    {row.materials.length > 0 && (
                      <div className="text-muted mt-1">
                        {row.materials.length} material lot{row.materials.length === 1 ? '' : 's'}
                      </div>
                    )}
                    {row.inspections.length > 0 && (
                      <div className="text-muted">
                        {row.inspections.length} inspection{row.inspections.length === 1 ? '' : 's'}
                      </div>
                    )}
                  </li>
                ))}
              </ul>
            )}
        </SectionCard>
      </div>

      <div className="col-span-1">
        <SectionCard icon={<Tag size={14} />} title="Shipment lot" highlight>
          <dl className="text-xs space-y-1.5">
            <Row label="Lot" value={<span className="font-mono">{lot.lot_number}</span>} />
            <Row label="Date" value={<span className="font-mono tabular-nums">{lot.lot_date ?? '—'}</span>} />
            <Row label="Quantity" value={<span className="font-mono tabular-nums">{lot.quantity}</span>} />
            <Row label="Product" value={lot.product
              ? <span><span className="font-mono">{lot.product.part_number ?? '—'}</span>{lot.product.name ? ` · ${lot.product.name}` : ''}</span>
              : '—'} />
          </dl>
        </SectionCard>
      </div>

      <div className="col-span-1 space-y-4">
        <SectionCard icon={<Truck size={14} />} title="Forward — delivery">
          {!delivery
            ? <p className="text-xs text-muted">Not yet dispatched.</p>
            : (
              <dl className="text-xs space-y-1.5">
                <Row label="Number" value={
                  <Link to={`/supply-chain/deliveries/${delivery.id}`} className="font-mono text-accent hover:underline">
                    {delivery.delivery_number}
                  </Link>
                } />
                <Row label="Status" value={<Chip variant="neutral">{delivery.status}</Chip>} />
                <Row label="Delivered" value={delivery.delivered_at?.slice(0, 16).replace('T', ' ') ?? '—'} />
                <Row label="Confirmed" value={delivery.confirmed_at?.slice(0, 16).replace('T', ' ') ?? '—'} />
              </dl>
            )}
        </SectionCard>

        <SectionCard icon={<Building2 size={14} />} title="Customer">
          {customer?.name ? <p className="text-sm">{customer.name}</p> : <p className="text-xs text-muted">—</p>}
        </SectionCard>
      </div>
    </div>
  );
}

function MaterialLotView({ result }: { result: TraceabilityResult }) {
  const ml = result.trace.material_lot;
  const grn = result.trace.backward?.grn ?? null;
  const wos = result.trace.forward?.work_orders ?? [];

  if (!ml) return null;

  return (
    <div className="grid grid-cols-3 gap-4">
      <div className="col-span-1">
        <SectionCard icon={<Building2 size={14} />} title="Backward — supplier GRN">
          {!grn
            ? <p className="text-xs text-muted">No linked GRN.</p>
            : (
              <dl className="text-xs space-y-1.5">
                <Row label="GRN" value={<span className="font-mono">{grn.grn_number}</span>} />
                <Row label="Received" value={<span className="font-mono tabular-nums">{grn.received_date ?? '—'}</span>} />
              </dl>
            )}
        </SectionCard>
      </div>

      <div className="col-span-1">
        <SectionCard icon={<Package size={14} />} title="Material lot" highlight>
          <dl className="text-xs space-y-1.5">
            <Row label="Lot" value={<span className="font-mono">{ml.material_lot_number ?? '—'}</span>} />
            <Row label="Supplier ref" value={<span className="font-mono">{ml.supplier_lot_reference ?? '—'}</span>} />
            <Row label="Item" value={ml.item_code
              ? <span><span className="font-mono">{ml.item_code}</span>{ml.item_name ? ` · ${ml.item_name}` : ''}</span>
              : '—'} />
            <Row label="Received" value={<span className="font-mono tabular-nums">{ml.quantity_received ?? '—'}</span>} />
            <Row label="Accepted" value={<span className="font-mono tabular-nums">{ml.quantity_accepted ?? '—'}</span>} />
          </dl>
        </SectionCard>
      </div>

      <div className="col-span-1">
        <SectionCard icon={<Factory size={14} />} title="Forward — consuming batches">
          {wos.length === 0
            ? <p className="text-xs text-muted">No production batches consumed this lot yet.</p>
            : (
              <ul className="text-xs space-y-2">
                {wos.map((wo) => (
                  <li key={wo.id} className="border-l-2 border-subtle pl-2">
                    <Link
                      to={`/quality/traceability?term=${encodeURIComponent(wo.batch_number ?? '')}`}
                      className="font-mono text-accent hover:underline"
                    >
                      {wo.batch_number ?? wo.wo_number}
                    </Link>
                    {wo.product && (
                      <div className="text-muted">
                        {wo.product.part_number} · {wo.product.name}
                      </div>
                    )}
                  </li>
                ))}
              </ul>
            )}
        </SectionCard>
      </div>
    </div>
  );
}

/* ── helpers ───────────────────────────────────────────────────────────── */

function SectionCard({
  icon, title, highlight, children,
}: {
  icon: React.ReactNode;
  title: string;
  highlight?: boolean;
  children: React.ReactNode;
}) {
  return (
    <Panel
      title={
        <span className={`inline-flex items-center gap-1.5 ${highlight ? 'text-accent' : ''}`}>
          {icon}
          {title}
        </span>
      }
    >
      {children}
    </Panel>
  );
}

function Row({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex justify-between gap-2">
      <dt className="text-muted shrink-0">{label}</dt>
      <dd className="text-right">{value}</dd>
    </div>
  );
}
