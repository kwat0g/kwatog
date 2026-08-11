/**
 * Sprint 7 — Task 64 — NCR list page.
 */
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { Plus } from 'lucide-react';
import { ncrsApi, type NcrListParams } from '@/api/quality/ncrs';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { cn } from '@/lib/cn';
import type { Ncr, NcrSeverity, NcrStatus } from '@/types/quality';

const STATUS_CHIP: Record<NcrStatus, 'success' | 'danger' | 'warning' | 'neutral' | 'info'> = {
  open: 'warning',
  in_progress: 'info',
  closed: 'success',
  cancelled: 'neutral',
};

const SEVERITY_CHIP: Record<NcrSeverity, 'success' | 'danger' | 'warning' | 'neutral' | 'info'> = {
  low: 'neutral',
  medium: 'warning',
  high: 'danger',
  critical: 'danger',
};

const DEFAULT_FILTERS: NcrListParams = {
  page: 1,
  per_page: 25,
  status: 'open',
};

export default function NcrsListPage() {
  const navigate = useNavigate();
  const { can } = usePermission();
  const [filters, setFilters] = useUrlFilters<NcrListParams>(DEFAULT_FILTERS);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['quality', 'ncrs', filters],
    queryFn: () => ncrsApi.list(filters),
    placeholderData: (prev) => prev,
  });
  const { data: ncrOptions } = useQuery({
    queryKey: ['quality', 'ncr-options'],
    queryFn: ncrsApi.options,
    staleTime: 5 * 60 * 1000,
  });
  const labels = new Map(
    [
      ...(ncrOptions?.sources ?? []),
      ...(ncrOptions?.severities ?? []),
      ...(ncrOptions?.statuses ?? []),
      ...(ncrOptions?.dispositions ?? []),
    ].map((option) => [option.value, option.label]),
  );

  const columns: Column<Ncr>[] = [
    {
      key: 'ncr_number',
      header: 'NCR',
      cell: (r) => (
        <span className="flex items-center gap-2">
          <span className="font-mono">{r.ncr_number}</span>
          {r.is_auto_generated && (
            <span title="Auto-generated from inspection failure">
              <Chip variant="info">Auto</Chip>
            </span>
          )}
        </span>
      ),
    },
    {
      key: 'product',
      header: 'Product',
      cell: (r) =>
        r.product ? (
          <span>
            <span className="font-mono">{r.product.part_number}</span>
            <span className="ml-2 text-muted">{r.product.name}</span>
          </span>
        ) : (
          <span className="text-muted">—</span>
        ),
    },
    {
      key: 'source',
      header: 'Source',
      cell: (r) => (
        <Chip variant="neutral">{r.source_label ?? labels.get(r.source) ?? r.source}</Chip>
      ),
    },
    {
      key: 'severity',
      header: 'Severity',
      cell: (r) => (
        <Chip variant={SEVERITY_CHIP[r.severity]}>
          {r.severity_label ?? labels.get(r.severity) ?? r.severity}
        </Chip>
      ),
    },
    {
      key: 'affected_quantity',
      header: 'Qty',
      align: 'right',
      cell: (r) => <NumCell>{r.affected_quantity}</NumCell>,
    },
    {
      key: 'disposition',
      header: 'Disposition',
      cell: (r) =>
        r.disposition ? (
          <Chip variant="neutral">
            {r.disposition_label ?? labels.get(r.disposition) ?? r.disposition}
          </Chip>
        ) : (
          <span className="text-muted">—</span>
        ),
    },
    {
      key: 'status',
      header: 'Status',
      cell: (r) => (
        <Chip variant={STATUS_CHIP[r.status]}>
          {r.status_label ?? labels.get(r.status) ?? r.status}
        </Chip>
      ),
    },
    {
      key: 'closed',
      header: 'Closed',
      align: 'right',
      cell: (r) => <NumCell>{r.closed_at?.slice(0, 10) ?? '—'}</NumCell>,
    },
  ];

  const filterConfig: FilterConfig[] = [
    {
      key: 'status',
      label: 'Status',
      type: 'select',
      options: [{ value: '', label: 'All' }, ...(ncrOptions?.statuses ?? [])],
    },
    {
      key: 'severity',
      label: 'Severity',
      type: 'select',
      options: [{ value: '', label: 'All' }, ...(ncrOptions?.severities ?? [])],
    },
    {
      key: 'source',
      label: 'Source',
      type: 'select',
      options: [{ value: '', label: 'All' }, ...(ncrOptions?.sources ?? [])],
    },
  ];

  return (
    <div>
      <PageHeader
        title="Non-conformance reports"
        subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'NCR' : 'NCRs'}` : undefined}
        actions={
          can('quality.ncr.manage') ? (
            <Button
              variant="primary"
              size="sm"
              icon={<Plus size={14} />}
              onClick={() => navigate('/quality/ncrs/new')}
            >
              New NCR
            </Button>
          ) : undefined
        }
      />
      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search NCR number or description…"
      />
      {isLoading && !data && <SkeletonTable columns={8} rows={6} />}
      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load NCRs"
          action={
            <Button variant="secondary" onClick={() => refetch()}>
              Retry
            </Button>
          }
        />
      )}
      {data && <EightDProgress rows={data.data} />}

      {data && data.data.length === 0 && (
        <EmptyState
          icon="alert-triangle"
          title="No NCRs"
          description="When an inspection fails or a customer complaint is filed, a non-conformance report will appear here."
        />
      )}

      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable
            tableKey="ncrs"
            onRowClick={(r) => navigate(`/quality/ncrs/${r.id}`)}
            columns={columns}
            data={data.data}
            meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
          />
        </div>
      )}
    </div>
  );
}

/**
 * Where the open NCRs are in the 8D loop, and how long they have been there.
 *
 * This replaced four StatCards counting `data.data.filter(...)` over a 25-row
 * page and presenting the result as headline KPIs — each with a `linkTo` that
 * re-filtered the ENTIRE dataset, so the figure changed after you clicked it.
 *
 * Status alone was also the wrong axis. `open` and `in_progress` say nothing
 * about whether the 8D has actually advanced: an NCR can sit `in_progress` for
 * three weeks with no root cause recorded, which on an IATF 16949 line is the
 * finding an auditor writes up. The fields that show real progress are already
 * on the row — `root_cause` and `corrective_action` are null until someone does
 * the work — so the stage is derived from those rather than from the label.
 *
 * Age is the second axis because an NCR's cost is a function of how long the
 * defect keeps shipping. Oldest-first, since that is the one you act on.
 */
function EightDProgress({ rows }: { rows: Ncr[] }) {
  const live = rows.filter((n) => n.status === 'open' || n.status === 'in_progress');
  if (live.length === 0) return null;

  const dayMs = 86_400_000;
  const ageDays = (iso: string) =>
    Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / dayMs));

  // Ordered earliest→latest; an NCR counts at the furthest stage it has reached.
  const stages = [
    {
      key: 'raised',
      label: 'Raised',
      hint: 'no containment recorded',
      match: (n: Ncr) => !n.actions?.some((a) => a.action_type === 'containment'),
    },
    {
      key: 'contained',
      label: 'Contained',
      hint: 'root cause not yet found',
      match: (n: Ncr) => !n.root_cause?.trim(),
    },
    {
      key: 'root-cause',
      label: 'Root cause',
      hint: 'corrective action not defined',
      match: (n: Ncr) => !n.corrective_action?.trim(),
    },
    {
      key: 'corrective',
      label: 'Corrective action',
      hint: 'awaiting verification and close',
      match: () => true,
    },
  ] as const;

  const bucketed = stages.map((s, i) => {
    const earlier = stages.slice(0, i);
    const items = live.filter((n) => !earlier.some((e) => e.match(n)) && s.match(n));
    const oldest = items.reduce<number>((max, n) => Math.max(max, ageDays(n.created_at)), 0);
    return { ...s, items, oldest };
  });

  const stalest = Math.max(...bucketed.map((b) => b.oldest));

  return (
    <section className="px-5 py-4 border-b border-default bg-canvas" aria-labelledby="ncr-8d">
      <div className="flex items-baseline justify-between mb-2.5">
        <h2 id="ncr-8d" className="text-sm font-medium text-primary">
          8D progress
        </h2>
        <span className="text-xs text-muted">
          {live.length} open on this page · oldest{' '}
          <span className="font-mono tabular-nums">{stalest}</span>d
        </span>
      </div>

      <ol className="grid grid-cols-2 lg:grid-cols-4 gap-3">
        {bucketed.map((b) => {
          // Severity reads off age, not off the stage: sitting at "Raised" for a
          // day is normal, sitting there for three weeks is the problem.
          const tone =
            b.items.length === 0
              ? 'text-muted'
              : b.oldest >= 14
                ? 'text-danger'
                : b.oldest >= 7
                  ? 'text-warning'
                  : 'text-secondary';
          return (
            <li
              key={b.key}
              className={cn(
                'rounded-md border border-default p-3',
                b.items.length === 0 && 'opacity-60',
              )}
            >
              <div className="text-2xs uppercase tracking-wider text-muted font-medium">
                {b.label}
              </div>
              <div className="mt-0.5 flex items-baseline gap-2">
                <span className="font-mono tabular-nums text-2xl font-medium">
                  {b.items.length}
                </span>
                {b.items.length > 0 && (
                  <span className={cn('font-mono tabular-nums text-xs', tone)}>
                    oldest {b.oldest}d
                  </span>
                )}
              </div>
              <p className="mt-1 text-xs text-muted">
                {b.items.length === 0 ? 'none here' : b.hint}
              </p>
            </li>
          );
        })}
      </ol>
    </section>
  );
}
