/**
 * Chain Tracker — a focused, cross-module journey view for the order-to-cash
 * chain. Pick a sales order and see its full live position (SO → MRP → work
 * orders → quality → fulfilment → invoice → collection) using the existing
 * chain components, plus a plant-wide "stuck documents" panel from the chain
 * bottleneck endpoint.
 *
 * Frontend-only: reuses salesOrdersApi.chain/show, chainApi.bottlenecks, and
 * the real-time chain channel. Deep-linkable via `?id=<hashid>` so the SO
 * detail page and bottleneck rows can hand off into this view.
 */

import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Search, Workflow, ArrowRight, AlertTriangle, Clock, RotateCcw } from 'lucide-react';
import { salesOrdersApi } from '@/api/crm/salesOrders';
import { chainApi } from '@/api/chain';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { Chip, type ChipVariant } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { Input } from '@/components/ui/Input';
import { ChainHeader, LinkedRecords, ActivityStream } from '@/components/chain';
import { useChainProgress } from '@/hooks/useChainProgress';
import type { SalesOrderStatus } from '@/types/crm';
import type { ChainBottleneckRow } from '@/types/chain';

const SO_STATUS_VARIANT: Record<SalesOrderStatus, ChipVariant> = {
  draft: 'neutral',
  confirmed: 'info',
  in_production: 'info',
  partially_delivered: 'warning',
  delivered: 'success',
  invoiced: 'success',
  cancelled: 'danger',
} as Record<SalesOrderStatus, ChipVariant>;

const peso = (v: string | number) =>
  '₱ ' + Number(v).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/** Map a bottleneck row to the right destination page. */
function bottleneckHref(row: ChainBottleneckRow): string {
  switch (row.entity_type) {
    case 'sales_order':
      return `/chains?id=${row.entity_id}`;
    case 'work_order':
      return `/production/work-orders/${row.entity_id}`;
    case 'purchase_order':
      return `/purchasing/purchase-orders/${row.entity_id}`;
    case 'delivery':
      return `/supply-chain/deliveries/${row.entity_id}`;
    case 'grn':
      return `/inventory/grn/${row.entity_id}`;
    default:
      return `/chains?id=${row.entity_id}`;
  }
}

export default function ChainTrackerPage() {
  const [params, setParams] = useSearchParams();
  const selectedId = params.get('id') ?? undefined;

  return (
    <div>
      <PageHeader
        title="Chain Tracker"
        subtitle="Follow a sales order end-to-end across every module — order to cash."
        breadcrumbs={[{ label: 'Chain Tracker' }]}
      />
      <div className="px-5 py-4">
        {selectedId ? (
          <ChainDetail
            id={selectedId}
            onClear={() => {
              const next = new URLSearchParams(params);
              next.delete('id');
              setParams(next, { replace: true });
            }}
          />
        ) : (
          <ChainPicker onPick={(id) => setParams({ id }, { replace: false })} />
        )}
      </div>
    </div>
  );
}

/* ── Picker + plant-wide bottlenecks ─────────────────────────────── */

function ChainPicker({ onPick }: { onPick: (id: string) => void }) {
  const [search, setSearch] = useState('');

  const results = useQuery({
    queryKey: ['chains', 'so-search', search],
    queryFn: () => salesOrdersApi.list({ search: search || undefined, per_page: 8 }),
    placeholderData: (prev) => prev,
  });

  const bottlenecks = useQuery({
    queryKey: ['chains', 'bottlenecks'],
    queryFn: () => chainApi.bottlenecks(),
  });

  const orders = results.data?.data ?? [];

  return (
    <div className="grid gap-4 lg:grid-cols-[1.3fr_1fr]">
      {/* Search / pick */}
      <Panel title="Track a sales order">
        <Input
          label="Search"
          placeholder="Search by SO number or customer…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          autoFocus
        />
        <div className="mt-3 divide-y divide-subtle border-t border-subtle">
          {results.isLoading ? (
            <div className="flex items-center gap-2 py-6 text-sm text-muted">
              <Spinner size="sm" /> Searching…
            </div>
          ) : orders.length === 0 ? (
            <div className="py-8">
              <EmptyState
                icon="search"
                title={search ? 'No matching sales orders' : 'Start typing to find an order'}
                description={search ? 'Try a different SO number or customer name.' : 'Or pick one of the stuck documents on the right.'}
              />
            </div>
          ) : (
            orders.map((so) => (
              <button
                key={so.id}
                type="button"
                onClick={() => onPick(so.id)}
                className="flex w-full items-center gap-3 py-2.5 text-left transition-colors hover:bg-subtle"
              >
                <Workflow size={15} className="shrink-0 text-muted" />
                <span className="font-mono text-sm text-primary">{so.so_number}</span>
                <span className="flex-1 truncate text-sm text-secondary">{so.customer?.name ?? '—'}</span>
                <Chip variant={SO_STATUS_VARIANT[so.status] ?? 'neutral'}>{so.status_label}</Chip>
                <span className="hidden font-mono text-xs tabular-nums text-muted sm:inline">{peso(so.total_amount)}</span>
                <ArrowRight size={14} className="shrink-0 text-text-subtle" />
              </button>
            ))
          )}
        </div>
      </Panel>

      {/* Plant-wide bottlenecks */}
      <Panel
        title="Stuck across the plant"
        meta={bottlenecks.data ? `${bottlenecks.data.total} stuck` : undefined}
      >
        {bottlenecks.isLoading ? (
          <div className="flex items-center gap-2 py-6 text-sm text-muted">
            <Spinner size="sm" /> Loading…
          </div>
        ) : bottlenecks.isError ? (
          <div className="py-6">
            <EmptyState icon="alert-circle" title="Couldn’t load bottlenecks" description="Try refreshing." action={
              <button type="button" onClick={() => bottlenecks.refetch()} className="inline-flex items-center gap-1.5 text-sm text-accent hover:underline">
                <RotateCcw size={13} /> Retry
              </button>
            } />
          </div>
        ) : !bottlenecks.data || bottlenecks.data.total === 0 ? (
          <div className="py-6">
            <EmptyState icon="shield" title="Nothing stuck" description="Every chain is moving. Nice." />
          </div>
        ) : (
          <div className="space-y-4">
            {bottlenecks.data.groups.map((g) => (
              <div key={g.key}>
                <div className="flex items-center justify-between">
                  <span className="text-xs font-medium uppercase tracking-wider text-muted">{g.label}</span>
                  <span className="font-mono text-2xs tabular-nums text-text-subtle">{g.count}</span>
                </div>
                <ul className="mt-1.5 space-y-1">
                  {g.rows.slice(0, 6).map((row) => (
                    <li key={`${row.entity_type}-${row.entity_id}`}>
                      <Link
                        to={bottleneckHref(row)}
                        className="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm transition-colors hover:bg-subtle"
                      >
                        <AlertTriangle size={13} className="shrink-0 text-warning" />
                        <span className="font-mono text-xs text-primary">{row.doc_number}</span>
                        <span className="flex-1 truncate text-xs text-secondary">{row.label}</span>
                        {row.hours_stuck != null && (
                          <span className="inline-flex items-center gap-1 font-mono text-2xs tabular-nums text-muted">
                            <Clock size={11} /> {Math.round(row.hours_stuck)}h
                          </span>
                        )}
                      </Link>
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </div>
        )}
      </Panel>
    </div>
  );
}

/* ── Selected order chain ────────────────────────────────────────── */

function ChainDetail({ id, onClear }: { id: string; onClear: () => void }) {
  const detail = useQuery({
    queryKey: ['chains', 'so', id],
    queryFn: () => salesOrdersApi.show(id),
  });
  const chain = useQuery({
    queryKey: ['chains', 'so-chain', id],
    queryFn: () => salesOrdersApi.chain(id),
  });
  useChainProgress('sales_order', id, ['chains', 'so', id]);

  if (detail.isLoading) {
    return (
      <div className="flex items-center justify-center gap-2 py-24 text-sm text-muted">
        <Spinner /> Loading chain…
      </div>
    );
  }
  if (detail.isError || !detail.data) {
    return (
      <EmptyState
        icon="alert-circle"
        title="Couldn’t load that order"
        description="It may have been removed, or you may not have access."
        action={
          <button type="button" onClick={onClear} className="inline-flex items-center gap-1.5 text-sm text-accent hover:underline">
            <ArrowRight size={13} className="rotate-180" /> Back to search
          </button>
        }
      />
    );
  }

  const so = detail.data;

  return (
    <div className="space-y-4">
      {/* Summary + change-doc */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <span className="font-mono text-lg font-medium text-primary">{so.so_number}</span>
          <Chip variant={SO_STATUS_VARIANT[so.status] ?? 'neutral'}>{so.status_label}</Chip>
        </div>
        <button
          type="button"
          onClick={onClear}
          className="inline-flex items-center gap-1.5 rounded-md border border-default px-3 py-1.5 text-sm text-secondary transition-colors hover:bg-elevated"
        >
          <Search size={13} /> Track another
        </button>
      </div>

      {/* Chain stepper */}
      <Panel title="Order-to-cash chain">
        {chain.isLoading ? (
          <div className="flex items-center gap-2 py-4 text-sm text-muted"><Spinner size="sm" /> Loading stages…</div>
        ) : chain.data ? (
          <ChainHeader steps={chain.data} className="mt-1" />
        ) : (
          <p className="py-4 text-sm text-muted">No chain data for this order.</p>
        )}
      </Panel>

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="space-y-4 lg:col-span-2">
          <Panel title="Order">
            <dl className="grid grid-cols-3 gap-x-4 gap-y-3 text-sm">
              <dt className="text-muted">Customer</dt>
              <dd className="col-span-2 font-medium">{so.customer?.name ?? '—'}</dd>
              <dt className="text-muted">Date</dt>
              <dd className="col-span-2 font-mono">{so.date}</dd>
              <dt className="text-muted">Lines</dt>
              <dd className="col-span-2 font-mono tabular-nums">{so.item_count}</dd>
              <dt className="text-muted">Total</dt>
              <dd className="col-span-2 font-mono tabular-nums font-medium text-primary">{peso(so.total_amount)}</dd>
            </dl>
            <div className="mt-4 border-t border-subtle pt-3">
              <Link to={`/crm/sales-orders/${so.id}`} className="inline-flex items-center gap-1.5 text-sm text-accent hover:underline">
                Open full sales order <ArrowRight size={13} />
              </Link>
            </div>
          </Panel>

          <Panel title="Linked records">
            <LinkedRecords
              groups={[
                ...(so.mrp_plan ? [{
                  label: 'MRP Plan',
                  items: [{
                    id: so.mrp_plan.mrp_plan_no,
                    href: `/mrp/plans/${so.mrp_plan.id}`,
                    meta: `v${so.mrp_plan.version} · ${so.mrp_plan.draft_wo_count} WOs · ${so.mrp_plan.shortages_found} shortages`,
                    chip: { variant: so.mrp_plan.status === 'active' ? 'success' as const : so.mrp_plan.status === 'cancelled' ? 'danger' as const : 'neutral' as const, text: so.mrp_plan.status },
                  }],
                }] : []),
                ...(so.work_orders && so.work_orders.length > 0 ? [{
                  label: 'Work Orders',
                  items: so.work_orders.map((wo) => ({
                    id: wo.wo_number,
                    href: `/production/work-orders/${wo.id}`,
                    meta: `${wo.product?.part_number ?? ''} · ${wo.quantity_produced.toLocaleString()} / ${wo.quantity_target.toLocaleString()}`,
                    chip: { variant: wo.status === 'completed' || wo.status === 'closed' ? 'success' as const : wo.status === 'in_progress' ? 'info' as const : wo.status === 'paused' ? 'warning' as const : wo.status === 'cancelled' ? 'danger' as const : 'neutral' as const, text: wo.status.replace('_', ' ') },
                  })),
                }] : []),
                { label: 'Quality', items: [{ id: 'Inspections', meta: 'Incoming · in-process · outgoing AQL 0.65' }] },
                { label: 'Fulfilment', items: [
                  { id: 'Deliveries', meta: 'Delivery + customer confirm' },
                  { id: 'Invoice', meta: 'Auto on delivery confirm' },
                ] },
              ]}
            />
          </Panel>
        </div>

        <div className="space-y-4">
          <Panel title="Activity">
            <ActivityStream
              items={[
                { dot: 'success' as const, text: <>Sales order <span className="font-mono">{so.so_number}</span> created.</>, time: so.created_at?.slice(0, 10) ?? '' },
                ...(so.status !== 'draft' ? [{
                  dot: 'info' as const,
                  text: <>Status: <span className="font-medium">{so.status_label}</span></>,
                  time: so.updated_at?.slice(0, 10) ?? '',
                }] : []),
              ]}
            />
          </Panel>
        </div>
      </div>
    </div>
  );
}
