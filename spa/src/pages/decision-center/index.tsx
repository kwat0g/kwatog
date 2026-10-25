/** Role-scoped DSS decision workbench. */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { LuTriangleAlert, LuCircleAlert, LuInfo, LuRefreshCw } from '@/lib/icons';
import { dssApi, type DssCoverage } from '@/api/dss';
import type {
  Recommendation,
  RecommendationListParams,
  RecommendationOption,
  RecommendationSeverity,
  RecommendationStatus,
} from '@/types/dss';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatImpact, severityVariant } from './helpers';
import {
  decisionView,
  detectorLabel,
  recommendationStatusLabel,
  severityLabel,
} from './presentation';

const severityIcons = {
  critical: LuTriangleAlert,
  warning: LuCircleAlert,
  info: LuInfo,
} satisfies Record<RecommendationSeverity, typeof LuInfo>;

const severityOptions: RecommendationOption[] = [
  { value: 'critical', label: 'Critical' },
  { value: 'warning', label: 'Warning' },
  { value: 'info', label: 'Information' },
];

const statusFallback: RecommendationOption[] = [
  { value: 'open', label: 'New' },
  { value: 'in_progress', label: 'In progress' },
  { value: 'snoozed', label: 'Snoozed' },
  { value: 'accepted', label: 'Accepted' },
  { value: 'executed', label: 'Executed' },
  { value: 'dismissed', label: 'Dismissed' },
  { value: 'resolved', label: 'Resolved' },
  { value: 'expired', label: 'Expired' },
];

const FILTER_KEYS = [
  'search',
  'severity',
  'domain',
  'owner_role',
  'status',
  'sort',
  'direction',
  'page',
  'per_page',
] as const;

const isSeverity = (value: string | null): value is RecommendationSeverity =>
  value === 'critical' || value === 'warning' || value === 'info';
const isStatus = (value: string | null): value is RecommendationStatus =>
  value !== null && statusFallback.some((option) => option.value === value);
const isSort = (value: string | null): value is NonNullable<RecommendationListParams['sort']> =>
  value === 'priority_score' || value === 'created_at';
const isDirection = (value: string | null): value is 'asc' | 'desc' =>
  value === 'asc' || value === 'desc';

function positiveInteger(value: string | null, fallback: number, max = Number.MAX_SAFE_INTEGER) {
  if (!value || !/^\d+$/.test(value)) return fallback;
  const number = Number(value);
  return Number.isSafeInteger(number) && number > 0 && number <= max ? number : fallback;
}

function readFilters(params: URLSearchParams): RecommendationListParams {
  const search = params.get('search') || undefined;
  const domain = params.get('domain') || undefined;
  const owner_role = params.get('owner_role') || undefined;
  const severity = params.get('severity');
  const status = params.get('status');
  const sort = params.get('sort');
  const direction = params.get('direction');
  return {
    search,
    domain,
    owner_role,
    severity: isSeverity(severity) ? severity : undefined,
    status: isStatus(status) ? status : undefined,
    sort: isSort(sort) ? sort : undefined,
    direction: isDirection(direction) ? direction : undefined,
    page: positiveInteger(params.get('page'), 1),
    per_page: positiveInteger(params.get('per_page'), 20, 100),
  };
}

function filtersToSearch(filters: RecommendationListParams): string {
  const params = new URLSearchParams();
  for (const key of FILTER_KEYS) {
    const value = filters[key];
    if (value === undefined || value === null || value === '') continue;
    if (key === 'page' && value === 1) continue;
    if (key === 'per_page' && value === 20) continue;
    if (key === 'sort' && value === 'priority_score') continue;
    if (key === 'direction' && value === 'desc') continue;
    params.set(key, String(value));
  }
  const value = params.toString();
  return value ? `?${value}` : '';
}

function emptyCoverageDescription(coverage?: DssCoverage): string {
  if (!coverage)
    return 'Decision check health is unavailable. Refresh to check whether checks are running.';
  if (!coverage.engine_enabled) return 'Decision support is paused. Contact a DSS administrator.';
  if (coverage.available_detectors === 0) return 'No decision checks are available for your role.';

  const healthIssues = [
    coverage.failed_detectors > 0
      ? `${coverage.failed_detectors} check${coverage.failed_detectors === 1 ? '' : 's'} failed`
      : null,
    coverage.partial_detectors > 0
      ? `${coverage.partial_detectors} check${coverage.partial_detectors === 1 ? '' : 's'} returned incomplete scans`
      : null,
  ].filter((issue): issue is string => issue !== null);

  if (healthIssues.length > 0) {
    return `${healthIssues.join(' and ')} on the latest run. Review decision check health; failed checks can leave recommendations incomplete.`;
  }
  if (coverage.disabled_detectors === coverage.available_detectors) {
    return `All ${coverage.available_detectors} decision checks are disabled.`;
  }
  if (coverage.shadow_detectors === coverage.available_detectors) {
    return `${coverage.available_detectors} decision checks are running in shadow mode. They are being calibrated before recommendations reach users.`;
  }

  return `No recommendations match this view. ${coverage.advisory_detectors} checks are publishing advice; ${coverage.shadow_detectors} remain in calibration; ${coverage.disabled_detectors} are disabled.`;
}

function DetectorHealth({ checks }: { checks: DssCoverage['checks'] }) {
  if (checks.length === 0) return null;
  const failed = checks.filter((check) => check.last_run?.status === 'failed').length;
  const partial = checks.length - failed;

  return (
    <details className="rounded-md border border-warning bg-warning-bg px-3 py-2 text-warning-fg">
      <summary className="flex cursor-pointer flex-wrap items-center justify-between gap-x-3 gap-y-1 font-medium">
        <span className="inline-flex items-center gap-2">
          <LuTriangleAlert size={14} aria-hidden="true" />
          {checks.length} decision check{checks.length === 1 ? '' : 's'} need attention
        </span>
        <span className="text-xs font-normal">
          {[failed > 0 ? `${failed} failed` : null, partial > 0 ? `${partial} partial` : null]
            .filter(Boolean)
            .join(' · ')}
        </span>
      </summary>
      <ul className="mt-2 grid grid-cols-1 gap-2 border-t border-warning/30 pt-2 sm:grid-cols-2">
        {checks.map((check) => (
          <li
            key={check.id}
            className="flex min-w-0 items-center justify-between gap-2 rounded border border-default bg-canvas px-2.5 py-2"
          >
            <span className="min-w-0 text-xs text-primary">{detectorLabel(check.id)}</span>
            <Chip variant={check.last_run?.status === 'failed' ? 'danger' : 'warning'}>
              {check.last_run?.status === 'failed' ? 'Failed' : 'Partial'}
            </Chip>
          </li>
        ))}
      </ul>
    </details>
  );
}

function SummaryCard({
  label,
  value,
  severity,
}: {
  label: string;
  value?: number;
  severity?: RecommendationSeverity;
}) {
  const Icon = severity ? severityIcons[severity] : null;
  return (
    <div className="min-w-0 rounded-md border border-default bg-canvas px-4 py-3">
      <div className="flex items-center gap-2 text-xs text-secondary">
        {Icon && <Icon size={13} aria-hidden="true" />}
        <span>{label}</span>
      </div>
      <div className="mt-1 font-mono text-xl tabular-nums text-primary" aria-live="polite">
        {value === undefined ? '—' : new Intl.NumberFormat('en-PH').format(value)}
      </div>
    </div>
  );
}

function ownerLabel(recommendation: Recommendation, options: RecommendationOption[]): string {
  const labels = new Map(options.map(({ value, label }) => [value, label]));
  const values = Array.isArray(recommendation.owner_role_slugs)
    ? recommendation.owner_role_slugs.filter((value): value is string => typeof value === 'string')
    : [];
  const known = [...new Set(values.map((value) => labels.get(value)).filter(Boolean))];
  return known.length ? known.join(', ') : 'Owner not recorded';
}

function statusVariant(status: Recommendation['status']) {
  return status === 'open' || status === 'in_progress'
    ? 'info'
    : status === 'snoozed'
      ? 'warning'
      : status === 'resolved'
        ? 'success'
        : 'neutral';
}

export default function DecisionCenterPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const navigate = useNavigate();
  const [notice, setNotice] = useState('');
  const latestSearch = useRef(searchParams.toString());

  useEffect(() => {
    latestSearch.current = searchParams.toString();
  }, [searchParams]);

  const filters = useMemo(() => readFilters(new URLSearchParams(searchParams)), [searchParams]);
  const legacySelectedId = searchParams.get('id');
  const listSearch = filtersToSearch(filters);

  useEffect(() => {
    if (legacySelectedId) {
      navigate(`/decision-center/${encodeURIComponent(legacySelectedId)}${listSearch}`, {
        replace: true,
      });
    }
  }, [legacySelectedId, listSearch, navigate]);

  const updateUrl = useCallback(
    (patch: Partial<RecommendationListParams>, options: { replace?: boolean } = {}) => {
      const current = new URLSearchParams(latestSearch.current);
      const next = new URLSearchParams();
      for (const key of FILTER_KEYS) {
        const value = Object.prototype.hasOwnProperty.call(patch, key)
          ? patch[key]
          : current.get(key);
        if (value === undefined || value === null || value === '') continue;
        if (key === 'page' && value === 1) continue;
        if (key === 'per_page' && value === 20) continue;
        if (key === 'sort' && value === 'priority_score') continue;
        if (key === 'direction' && value === 'desc') continue;
        next.set(key, String(value));
      }
      latestSearch.current = next.toString();
      setSearchParams(next, { replace: options.replace });
    },
    [setSearchParams],
  );

  const updateFilter = (key: string, value: unknown) => {
    updateUrl(
      { [key]: typeof value === 'string' && value !== '' ? value : undefined, page: 1 },
      { replace: value === undefined || value === '' },
    );
  };

  const optionsQuery = useQuery({
    queryKey: ['dss', 'recommendations', 'options'],
    queryFn: dssApi.options,
    staleTime: 60_000,
  });
  const options = optionsQuery.isError ? undefined : optionsQuery.data;
  const scopedFiltersValid =
    (!filters.domain && !filters.owner_role) ||
    (!!options &&
      (!filters.domain || options.domains.some((option) => option.value === filters.domain)) &&
      (!filters.owner_role ||
        options.owner_roles.some((option) => option.value === filters.owner_role)));

  useEffect(() => {
    if (options) {
      const missingDomain =
        filters.domain && !options.domains.some((option) => option.value === filters.domain);
      const missingOwner =
        filters.owner_role &&
        !options.owner_roles.some((option) => option.value === filters.owner_role);
      if (missingDomain || missingOwner) {
        updateUrl(
          {
            domain: missingDomain ? undefined : filters.domain,
            owner_role: missingOwner ? undefined : filters.owner_role,
            page: 1,
          },
          { replace: true },
        );
        setNotice(
          'An area or team filter was cleared because it is no longer available to your role.',
        );
      }
      return;
    }

    if (optionsQuery.isError && (filters.domain || filters.owner_role)) {
      updateUrl({ domain: undefined, owner_role: undefined, page: 1 }, { replace: true });
      setNotice('Area and team filters were cleared because filter choices could not be verified.');
    }
  }, [filters.domain, filters.owner_role, options, optionsQuery.isError, updateUrl]);

  const summaryFilters = useMemo(() => {
    const params = { ...filters };
    delete params.page;
    delete params.per_page;
    return params;
  }, [filters]);

  const listQuery = useQuery({
    queryKey: ['dss', 'recommendations', filters],
    queryFn: () => dssApi.list(filters),
    enabled: scopedFiltersValid,
  });
  const summaryQuery = useQuery({
    queryKey: ['dss', 'recommendations', 'summary', summaryFilters],
    queryFn: () => dssApi.summary(summaryFilters),
    enabled: scopedFiltersValid,
  });
  const coverageQuery = useQuery({
    queryKey: ['dss', 'coverage'],
    queryFn: dssApi.coverage,
  });
  const isRefreshing =
    listQuery.isFetching ||
    summaryQuery.isFetching ||
    optionsQuery.isFetching ||
    coverageQuery.isFetching;
  const coverage = coverageQuery.data;
  const checksNeedingAttention =
    coverage?.checks.filter(
      (check) => check.last_run?.status === 'failed' || check.last_run?.status === 'partial',
    ) ?? [];

  const filterConfig: FilterConfig[] = [
    {
      key: 'status',
      label: 'Status',
      type: 'select',
      options: options?.statuses ?? statusFallback,
    },
    {
      key: 'severity',
      label: 'Severity',
      type: 'select',
      options: options?.severities ?? severityOptions,
    },
    { key: 'domain', label: 'Area', type: 'select', options: options?.domains ?? [] },
    {
      key: 'owner_role',
      label: 'Responsible team',
      type: 'select',
      options: options?.owner_roles ?? [],
    },
  ];

  const summary = summaryQuery.data;
  const selectRecommendation = (recommendation: Recommendation) =>
    navigate(`/decision-center/${recommendation.id}${listSearch}`);

  const columns: Column<Recommendation>[] = [
    {
      key: 'status',
      header: 'Status',
      className: 'w-28',
      cell: (recommendation) => (
        <Chip variant={statusVariant(recommendation.status)}>
          {recommendationStatusLabel(recommendation.status)}
        </Chip>
      ),
    },
    {
      key: 'priority_score',
      header: 'Priority',
      sortable: true,
      className: 'w-24',
      cell: (recommendation) => (
        <Chip variant={severityVariant(recommendation.severity)}>
          {severityLabel(recommendation.severity)}
        </Chip>
      ),
    },
    {
      key: 'decision',
      header: 'Decision',
      className: 'min-w-[230px]',
      cell: (recommendation) => {
        const view = decisionView(recommendation);
        const href = `/decision-center/${recommendation.id}${listSearch}`;
        return (
          <div className="min-w-0 py-1">
            <div className="flex min-w-0 flex-wrap items-center gap-2">
              <Link to={href} className="break-words text-sm font-medium text-link">
                {view.title}
              </Link>
              <Chip variant="neutral">{view.area}</Chip>
            </div>
            <p className="mt-1 max-w-2xl whitespace-normal text-xs text-secondary">
              {view.summary}
            </p>
          </div>
        );
      },
    },
    {
      key: 'impact',
      header: 'Business impact',
      className: 'min-w-[130px]',
      cell: (recommendation) => {
        const impact = formatImpact(
          recommendation.impact_estimate,
          recommendation.impact_currency ?? recommendation.impact_unit,
          recommendation.impact_is_estimate,
        );
        return impact ? (
          <span className="block">
            {recommendation.impact_metric && (
              <span className="block text-xs text-muted">{recommendation.impact_metric}</span>
            )}
            <span>{impact}</span>
          </span>
        ) : (
          <span className="text-muted">Not available</span>
        );
      },
    },
    {
      key: 'responsible_team',
      header: 'Responsible team',
      className: 'min-w-[140px]',
      cell: (recommendation) => ownerLabel(recommendation, options?.owner_roles ?? []),
    },
    {
      key: 'evidence',
      header: 'Evidence',
      className: 'min-w-[125px]',
      cell: (recommendation) => (
        <span className="text-secondary">{decisionView(recommendation).evidenceLabel}</span>
      ),
    },
  ];

  const refresh = () =>
    void Promise.all([
      listQuery.refetch(),
      summaryQuery.refetch(),
      optionsQuery.refetch(),
      coverageQuery.refetch(),
    ]);

  return (
    <div>
      <PageHeader
        title="Decision Center"
        subtitle={
          summary
            ? `${summary.total} recommendation${summary.total === 1 ? '' : 's'} in this view`
            : undefined
        }
        refreshingQueryKey={['dss']}
        actions={
          <Button
            variant="secondary"
            size="sm"
            onClick={refresh}
            disabled={isRefreshing}
            icon={<LuRefreshCw size={13} className={isRefreshing ? 'animate-spin' : undefined} />}
          >
            Refresh Decision Center
          </Button>
        }
      />

      <FilterBar
        filters={filterConfig}
        values={filters as Record<string, unknown>}
        onSearch={(search) =>
          updateUrl({ search: search || undefined, page: 1 }, { replace: true })
        }
        onFilter={updateFilter}
        searchPlaceholder="Search decisions, customers, work orders, bills, POs, NCRs, inspections, lots, or documents…"
        searchMaxLength={120}
      />

      <div className="px-5 pt-2 text-right text-2xs text-text-subtle">
        Advisory only — DSS recommends; people decide.
      </div>

      {notice && (
        <div className="px-5 pt-3" role="status">
          <div className="mx-auto flex max-w-[1600px] flex-wrap items-center justify-between gap-2 rounded-md border border-warning bg-warning-bg px-3 py-2 text-xs text-warning-fg">
            <span>{notice}</span>
            <Button variant="ghost" size="sm" onClick={() => setNotice('')}>
              Dismiss
            </Button>
          </div>
        </div>
      )}

      {optionsQuery.isError && (
        <div className="px-5 pt-3" role="status">
          <div className="mx-auto flex max-w-[1600px] flex-wrap items-center justify-between gap-2 rounded-md border border-warning bg-warning-bg px-3 py-2 text-xs text-warning-fg">
            <span>
              Area and responsible team filters are unavailable. Other filters remain usable.
            </span>
            <div className="flex items-center gap-2">
              {(filters.domain || filters.owner_role) && (
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => updateUrl({ domain: undefined, owner_role: undefined, page: 1 })}
                >
                  Clear area and team
                </Button>
              )}
              <Button variant="ghost" size="sm" onClick={() => void optionsQuery.refetch()}>
                Retry filter choices
              </Button>
            </div>
          </div>
        </div>
      )}

      {coverageQuery.isError && (
        <div className="px-5 pt-3" role="status">
          <div className="mx-auto flex max-w-[1600px] flex-wrap items-center justify-between gap-2 rounded-md border border-warning bg-warning-bg px-3 py-2 text-xs text-warning-fg">
            <span>
              Decision check health is unavailable. Recommendation visibility is unchanged.
            </span>
            <Button variant="ghost" size="sm" onClick={() => void coverageQuery.refetch()}>
              Retry health
            </Button>
          </div>
        </div>
      )}

      <section
        aria-label="Recommendation overview"
        className="mx-auto grid max-w-[1600px] grid-cols-2 gap-3 px-5 py-4 lg:grid-cols-4"
      >
        <SummaryCard label="Current view" value={summary?.total} />
        <SummaryCard label="Critical" value={summary?.by_severity.critical} severity="critical" />
        <SummaryCard label="Warning" value={summary?.by_severity.warning} severity="warning" />
        <SummaryCard label="Information" value={summary?.by_severity.info} severity="info" />
      </section>

      {checksNeedingAttention.length > 0 && !listQuery.isError && (
        <div className="mx-auto max-w-[1600px] px-5 pb-3">
          <DetectorHealth checks={checksNeedingAttention} />
        </div>
      )}

      <main className="mx-auto max-w-[1600px] px-5 pb-6">
        {(!scopedFiltersValid || listQuery.isLoading) && !listQuery.data && (
          <SkeletonTable columns={5} rows={6} />
        )}

        {listQuery.isError && (
          <section className="rounded-md border border-default bg-surface">
            <EmptyState
              icon="alert-circle"
              title="Failed to load recommendations"
              description="Recommendations could not be loaded. Try again. If the problem continues, contact your DSS administrator."
              action={
                <Button variant="secondary" onClick={() => void listQuery.refetch()}>
                  Retry
                </Button>
              }
            />
          </section>
        )}

        {listQuery.data && !listQuery.isError && listQuery.data.data.length === 0 && (
          <section className="rounded-md border border-default bg-surface">
            <EmptyState
              icon={checksNeedingAttention.length > 0 ? 'alert-circle' : 'check-circle'}
              title={
                listQuery.data.meta.total > 0
                  ? 'No recommendations on this page'
                  : filters.severity
                    ? `No ${severityLabel(filters.severity)} recommendations`
                    : 'No recommendations match this view'
              }
              description={
                listQuery.data.meta.total > 0
                  ? `${listQuery.data.meta.total} recommendations match these filters, but none appear on this page.`
                  : filters.severity
                    ? `No recommendations match ${severityLabel(filters.severity)} priority. Clear the severity filter to see all recommendations.`
                    : emptyCoverageDescription(coverage)
              }
              action={
                listQuery.data.meta.total > 0 && (filters.page ?? 1) > 1 ? (
                  <Button variant="secondary" onClick={() => updateUrl({ page: 1 })}>
                    Go to first page
                  </Button>
                ) : undefined
              }
            />
            {checksNeedingAttention.length > 0 && (
              <div className="border-t border-default px-4 py-3 sm:px-6">
                <DetectorHealth checks={checksNeedingAttention} />
              </div>
            )}
          </section>
        )}

        {listQuery.data && !listQuery.isError && listQuery.data.data.length > 0 && (
          <div className="min-w-0 rounded-md border border-default bg-canvas p-2 sm:p-3">
            <DataTable
              columns={columns}
              data={listQuery.data.data}
              meta={listQuery.data.meta}
              onPageChange={(page) => updateUrl({ page })}
              onPageSizeChange={(per_page) => updateUrl({ per_page, page: 1 })}
              onSort={(sort, direction) =>
                updateUrl({ sort: sort as RecommendationListParams['sort'], direction, page: 1 })
              }
              currentSort={filters.sort ?? 'priority_score'}
              currentDirection={filters.direction ?? 'desc'}
              onRowClick={selectRecommendation}
              tableKey="decision-center"
              emptyState={<EmptyState size="compact" title="No matching decisions" />}
            />
          </div>
        )}
      </main>
    </div>
  );
}
