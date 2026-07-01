/**
 * SPC Control Charts — list page.
 *
 * Filterable by product and status. Each row links to the chart detail page
 * where the X-bar/R or I-MR chart is rendered interactively.
 */
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { Plus } from 'lucide-react';
import { spcApi, type SpcChartListParams } from '@/api/quality/spc';
import { Button } from '@/components/ui/Button';
import { Chip, type ChipVariant } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import type { SpcControlChart, SpcChartStatus, SpcChartType } from '@/types/quality/spc';

// ─── Status chip mapping ──────────────────────────
const STATUS_CHIP: Record<SpcChartStatus, ChipVariant> = {
  active: 'success',
  monitoring: 'info',
  suspended: 'neutral',
};

// ─── Chart type display labels ────────────────────
const CHART_TYPE_LABEL: Record<SpcChartType, string> = {
  xbar_r: 'X-bar / R',
  imr: 'I-MR',
  p_chart: 'p-chart',
};

const CHART_TYPE_CHIP: Record<SpcChartType, ChipVariant> = {
  xbar_r: 'purple',
  imr: 'info',
  p_chart: 'neutral',
};

export default function SpcChartsListPage() {
  const navigate = useNavigate();
  const { can } = usePermission();
  const [filters, setFilters] = useState<SpcChartListParams>({ page: 1, per_page: 20 });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['quality', 'spc', 'charts', filters],
    queryFn: () => spcApi.listCharts(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<SpcControlChart>[] = [
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
          <span className="text-muted">--</span>
        ),
    },
    {
      key: 'parameter',
      header: 'Parameter',
      cell: (r) => (
        <Link to={`/quality/spc/${r.id}`} className="text-accent hover:underline">
          {r.spec_item?.parameter_name ?? '--'}
          {r.spec_item?.unit_of_measure ? (
            <span className="ml-1 text-muted text-2xs">({r.spec_item.unit_of_measure})</span>
          ) : null}
        </Link>
      ),
    },
    {
      key: 'chart_type',
      header: 'Chart Type',
      cell: (r) => (
        <Chip variant={CHART_TYPE_CHIP[r.chart_type]}>
          {CHART_TYPE_LABEL[r.chart_type]}
        </Chip>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      cell: (r) => (
        <Chip variant={STATUS_CHIP[r.status]}>{r.status}</Chip>
      ),
    },
    {
      key: 'subgroup_size',
      header: 'Subgroup',
      align: 'right',
      cell: (r) => <NumCell>{r.subgroup_size}</NumCell>,
    },
    {
      key: 'limits',
      header: 'UCL / CL / LCL',
      align: 'right',
      cell: (r) =>
        r.ucl && r.center_line && r.lcl ? (
          <NumCell>
            {Number(r.ucl).toFixed(3)} / {Number(r.center_line).toFixed(3)} / {Number(r.lcl).toFixed(3)}
          </NumCell>
        ) : (
          <span className="text-muted text-xs">not calculated</span>
        ),
    },
    {
      key: 'alerts',
      header: 'Alerts',
      align: 'right',
      cell: (r) => {
        const count = r.unresolved_alert_count ?? 0;
        return count > 0 ? (
          <Chip variant="danger">{count}</Chip>
        ) : (
          <NumCell className="text-muted">0</NumCell>
        );
      },
    },
  ];

  const filterConfig: FilterConfig[] = [
    {
      key: 'status',
      label: 'Status',
      type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'active', label: 'Active' },
        { value: 'monitoring', label: 'Monitoring' },
        { value: 'suspended', label: 'Suspended' },
      ],
    },
  ];

  return (
    <div>
      <PageHeader
        title="SPC Control Charts"
        subtitle={data ? `${data.meta.total} chart${data.meta.total === 1 ? '' : 's'}` : undefined}
        breadcrumbs={[{ label: 'Quality', href: '/quality' }, { label: 'SPC' }]}
        actions={
          <div className="flex items-center gap-2">
            <Button
              variant="secondary"
              size="sm"
              onClick={() => navigate('/quality/spc/capability')}
            >
              Capability Study
            </Button>
            {can('quality.spc.manage') && (
              <Button
                variant="primary"
                size="sm"
                icon={<Plus size={14} />}
                onClick={() => navigate('/quality/spc/new')}
              >
                New Chart
              </Button>
            )}
          </div>
        }
      />
      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search by product or parameter..."
      />

      {/* ─── LOADING STATE ─── */}
      {isLoading && !data && <SkeletonTable columns={7} rows={6} />}

      {/* ─── ERROR STATE ─── */}
      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load control charts"
          action={
            <Button variant="secondary" onClick={() => refetch()}>
              Retry
            </Button>
          }
        />
      )}

      {/* ─── EMPTY STATE ─── */}
      {data && data.data.length === 0 && (
        <EmptyState
          icon="bar-chart-2"
          title="No control charts yet"
          description="Create an SPC control chart to start monitoring process stability for a product dimension."
        />
      )}

      {/* ─── DATA TABLE ─── */}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable
            columns={columns}
            data={data.data}
            meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
            onRowClick={(row) => navigate(`/quality/spc/${row.id}`)}
          />
        </div>
      )}
    </div>
  );
}
