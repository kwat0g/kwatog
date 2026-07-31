import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { periodsApi } from '@/api/payroll/periods';
import { Button } from '@/components/ui/Button';
import { Chip, type ChipVariant } from '@/components/ui/Chip';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { formatPeso } from '@/lib/formatNumber';
import type { PipelinePeriod } from '@/types/payroll';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { cn } from '@/lib/cn';

const statusVariant = (status: PipelinePeriod['status']): ChipVariant => {
  switch (status) {
    case 'disbursed':   return 'success';
    case 'finalized':   return 'success';
    case 'approved':    return 'info';
    case 'computed':    return 'info';
    case 'processing':  return 'info';
    case 'draft':       return 'warning';
    case 'scheduled':   return 'neutral';
    case 'not_created': return 'neutral';
    default:            return 'neutral';
  }
};

const statusIcon = (status: PipelinePeriod['status']): string => {
  switch (status) {
    case 'disbursed':   return '✅';
    case 'finalized':   return '✅';
    case 'approved':    return '✅';
    case 'computed':    return '◑';
    case 'processing':  return '⌛';
    case 'draft':       return '⚠';
    case 'scheduled':   return '○';
    case 'not_created': return '—';
    default:            return '○';
  }
};

export default function PayrollPipelinePage() {
  const navigate = useNavigate();
  const [year, setYear] = useState(new Date().getFullYear());

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['payroll-pipeline', year],
    queryFn: () => periodsApi.pipeline(year),
  });

  if (isLoading) {
    return (
      <div>
        <PageHeader title="Payroll Pipeline" backTo="/payroll/periods" backLabel="Payroll" />
        <SkeletonTable columns={6} rows={12} />
      </div>
    );
  }

  if (isError || !data) {
    return (
      <div>
        <PageHeader title="Payroll Pipeline" backTo="/payroll/periods" backLabel="Periods" />
        <EmptyState
          icon="alert-circle"
          title="Failed to load pipeline"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      </div>
    );
  }

  return (
    <div>
      <PageHeader
        title="Payroll Pipeline"
        subtitle={`${year} · ${data.periods.filter((p) => p.exists).length} of ${data.periods.length} periods created`}
        backTo="/payroll/periods"
        backLabel="Payroll"
        breadcrumbs={[
          { label: 'Payroll' },
          { label: 'Periods', href: '/payroll/periods' },
          { label: 'Pipeline' },
        ]}
        actions={
          <div className="flex items-center gap-2">
            <Button variant="secondary" size="sm" onClick={() => setYear((y) => y - 1)}>
              <ChevronLeft size={14} />
            </Button>
            <span className="font-mono font-medium tabular-nums text-sm">{year}</span>
            <Button variant="secondary" size="sm" onClick={() => setYear((y) => y + 1)}>
              <ChevronRight size={14} />
            </Button>
          </div>
        }
      />

      <div className="px-5 py-4 space-y-4">
        {/* Auto-schedule status */}
        <div className="flex items-center gap-3 text-sm">
          <div className="flex items-center gap-1.5">
            <span className="text-muted">Auto-schedule:</span>
            <Chip variant={data.auto_schedule_enabled ? 'success' : 'neutral'}>
              {data.auto_schedule_enabled ? 'ON' : 'OFF'}
            </Chip>
          </div>
          {data.next_auto_run && (
            <span className="text-muted">
              Next run: <span className="font-mono text-primary">{data.next_auto_run}</span>
            </span>
          )}
        </div>

        {/* Pipeline table */}
        <Panel noPadding>
          <table className={tableCls}>
            <thead>
              <tr className={theadTrCls}>
                <Th className="w-8" />
                <Th>Period</Th>
                <Th align="right">Employees</Th>
                <Th align="right">Gross</Th>
                <Th align="right">Net</Th>
                <Th className="w-32">Status</Th>
                <Th align="right" className="w-24">Action</Th>
              </tr>
            </thead>
            <tbody>
              {data.periods.map((period) => (
                <PipelineRow key={period.period_start} period={period} navigate={navigate} />
              ))}
            </tbody>
          </table>
        </Panel>
      </div>
    </div>
  );
}

function PipelineRow({
  period,
  navigate,
}: {
  period: PipelinePeriod;
  navigate: ReturnType<typeof useNavigate>;
}) {
  const handleRowClick = () => {
    if (period.id) navigate(`/payroll/periods/${period.id}`);
  };

  return (
    <tr
      className={cn(
        trCls,
        period.exists ? 'cursor-pointer' : 'opacity-60',
        period.status === 'draft' && 'bg-warning-bg',
      )}
      onClick={handleRowClick}
    >
      <Td align="center">{statusIcon(period.status)}</Td>
      <Td>
        <div className="font-medium">{period.label}</div>
        {period.is_auto_created && <span className="text-2xs text-muted">auto-created</span>}
      </Td>
      <Td align="right" mono>
        {period.employee_count > 0 ? period.employee_count : '—'}
      </Td>
      <Td align="right" mono>
        {Number(period.total_gross) > 0 ? formatPeso(period.total_gross) : '—'}
      </Td>
      <Td align="right" mono>
        {Number(period.total_net) > 0 ? formatPeso(period.total_net) : '—'}
      </Td>
      <Td>
        <Chip variant={statusVariant(period.status)}>{period.status_label}</Chip>
      </Td>
      <Td align="right" mono>
        {period.exists && (period.status === 'draft' || period.status === 'computed') && (
          <Button
            variant="secondary"
            size="sm"
            onClick={(e) => { e.stopPropagation(); navigate(`/payroll/periods/${period.id}`); }}
          >
            Review
          </Button>
        )}
        {period.exists && period.id && period.status !== 'draft' && period.status !== 'computed' && (
          <Button
            variant="secondary"
            size="sm"
            onClick={(e) => { e.stopPropagation(); navigate(`/payroll/periods/${period.id}`); }}
          >
            View
          </Button>
        )}
        {!period.exists && period.status === 'scheduled' && (
          <span className="text-2xs text-muted">{'—'}</span>
        )}
      </Td>
    </tr>
  );
}
