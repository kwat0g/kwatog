import { useEffect, useState, type FormEvent } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { supplierPerformanceApi } from '@/api/purchasing/supplier-performance';
import { Button } from '@/components/ui/Button';
import { Chip, type ChipVariant } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { PageHeader } from '@/components/layout/PageHeader';
import { Select } from '@/components/ui/Select';
import { Th, Td, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { type SupplierPerformanceTier, type SupplierRankingRow } from '@/types/supplierPerformance';

const MIN_YEAR = 2000;
const MAX_YEAR = 2100;

function previousPeriod(): { year: number; month: number } {
  const now = new Date();
  const previous = new Date(now.getFullYear(), now.getMonth() - 1, 1);
  return { year: previous.getFullYear(), month: previous.getMonth() + 1 };
}

function tierVariant(tier: SupplierPerformanceTier | null): ChipVariant {
  if (tier === 'A') return 'success';
  if (tier === 'B') return 'info';
  if (tier === 'C') return 'warning';
  if (tier === 'D') return 'danger';
  return 'neutral';
}

function fmtPct(value: string | null): string {
  return value === null ? '—' : `${Number(value).toFixed(1)}%`;
}

function fmtScore(value: string | null): string {
  return value === null ? '—' : Number(value).toFixed(1);
}

export default function SupplierRankingPage() {
  const defaultPeriod = previousPeriod();
  const [searchParams, setSearchParams] = useSearchParams();
  const [year, setYear] = useState(searchParams.get('period_year') ?? String(defaultPeriod.year));
  const [month, setMonth] = useState(
    searchParams.get('period_month') ?? String(defaultPeriod.month),
  );
  const [tier, setTier] = useState(searchParams.get('tier') ?? '');
  const [limit, setLimit] = useState(searchParams.get('limit') ?? '50');

  useEffect(() => {
    setYear(searchParams.get('period_year') ?? String(defaultPeriod.year));
    setMonth(searchParams.get('period_month') ?? String(defaultPeriod.month));
    setTier(searchParams.get('tier') ?? '');
    setLimit(searchParams.get('limit') ?? '50');
  }, [searchParams, defaultPeriod.month, defaultPeriod.year]);

  const queryParams = {
    period_year: Number(searchParams.get('period_year') ?? defaultPeriod.year),
    period_month: Number(searchParams.get('period_month') ?? defaultPeriod.month),
    tier: searchParams.get('tier') || undefined,
    limit: Number(searchParams.get('limit') ?? 50),
  };

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['purchasing', 'supplier-performance', 'ranking', queryParams],
    queryFn: () => supplierPerformanceApi.ranking(queryParams),
    placeholderData: (previous) => previous,
  });

  const applyFilters = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setSearchParams({
      period_year: year,
      period_month: month,
      ...(tier ? { tier } : {}),
      limit,
    });
  };

  const renderRow = (row: SupplierRankingRow) => (
    <tr
      key={`${row.vendor.id ?? 'unknown'}-${row.period_year}-${row.period_month}`}
      className={trCls}
    >
      <Td>
        {row.vendor.id ? (
          <Link
            to={`/purchasing/suppliers/${row.vendor.id}/performance`}
            className="text-link hover:underline font-medium"
          >
            {row.vendor.name ?? 'Unknown supplier'}
          </Link>
        ) : (
          (row.vendor.name ?? 'Unknown supplier')
        )}
      </Td>
      <Td mono>{`${row.period_year}-${String(row.period_month).padStart(2, '0')}`}</Td>
      <Td align="right" mono>
        {fmtScore(row.overall_score)}
      </Td>
      <Td>
        <Chip variant={tierVariant(row.tier)}>{row.tier ?? '—'}</Chip>
      </Td>
      <Td align="right" mono>
        {fmtPct(row.on_time_delivery_rate)}
      </Td>
      <Td align="right" mono>
        {fmtPct(row.quality_pass_rate)}
      </Td>
      <Td align="right" mono>
        {fmtPct(row.ncr_rate)}
      </Td>
      <Td align="right" mono>
        {row.po_count}
      </Td>
      <Td align="right" mono>
        {row.grn_count}
      </Td>
    </tr>
  );

  return (
    <div>
      <PageHeader
        title="Supplier ranking"
        subtitle={
          data
            ? `${data.meta.count} suppliers · ${data.meta.period_year}-${String(data.meta.period_month).padStart(2, '0')}`
            : undefined
        }
      />

      <form onSubmit={applyFilters} className="px-5 py-4 border-b border-default bg-surface">
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end">
          <Input
            id="supplier-ranking-year"
            label="Year"
            type="number"
            fieldSize="sm"
            min={MIN_YEAR}
            max={MAX_YEAR}
            value={year}
            onChange={(event) => setYear(event.target.value)}
            required
          />
          <Input
            id="supplier-ranking-month"
            label="Month"
            type="number"
            fieldSize="sm"
            min={1}
            max={12}
            value={month}
            onChange={(event) => setMonth(event.target.value)}
            required
          />
          <Select
            id="supplier-ranking-tier"
            label="Tier"
            fieldSize="sm"
            value={tier}
            onChange={(event) => setTier(event.target.value)}
          >
            <option value="">All tiers</option>
            {(['A', 'B', 'C', 'D'] as SupplierPerformanceTier[]).map((option) => (
              <option key={option} value={option}>{`Tier ${option}`}</option>
            ))}
          </Select>
          <Select
            id="supplier-ranking-limit"
            label="Rows"
            fieldSize="sm"
            value={limit}
            onChange={(event) => setLimit(event.target.value)}
          >
            {[25, 50, 75, 100].map((option) => (
              <option key={option} value={option}>
                {option}
              </option>
            ))}
          </Select>
          <Button type="submit" variant="primary" size="sm">
            Apply filters
          </Button>
        </div>
      </form>

      {isLoading && !data && (
        <div className="px-5 py-6 text-sm text-muted" role="status">
          Loading supplier ranking…
        </div>
      )}

      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load supplier ranking"
          description="Something went wrong while loading the selected period."
          action={
            <Button variant="secondary" onClick={() => refetch()}>
              Retry
            </Button>
          }
        />
      )}

      {data && data.data.length === 0 && (
        <EmptyState
          icon="bar-chart"
          title="No supplier snapshots"
          description="No supplier performance snapshots exist for the selected period and tier."
        />
      )}

      {data && data.data.length > 0 && (
        <div className="px-5 py-4 overflow-x-auto">
          <table className={tableCls}>
            <caption className="sr-only">Cross-vendor supplier performance ranking</caption>
            <thead>
              <tr className={theadTrCls}>
                <Th>Supplier</Th>
                <Th>Period</Th>
                <Th align="right">Score</Th>
                <Th>Tier</Th>
                <Th align="right">On-time</Th>
                <Th align="right">Quality</Th>
                <Th align="right">NCR</Th>
                <Th align="right">POs</Th>
                <Th align="right">GRNs</Th>
              </tr>
            </thead>
            <tbody>{data.data.map(renderRow)}</tbody>
          </table>
        </div>
      )}
    </div>
  );
}
