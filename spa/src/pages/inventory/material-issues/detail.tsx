import { useParams } from 'react-router-dom';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { materialIssuesApi } from '@/api/inventory/material-issues';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { PageShell } from '@/components/layout/PageShell';
import { TableScrollRegion } from '@/components/ui/TableScrollRegion';
import { formatDate } from '@/lib/formatDate';
import { formatPeso, formatPreciseQuantity, formatQuantity, formatUnitCost } from '@/lib/formatNumber';
import type { MaterialIssueStatus } from '@/types/inventory';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

const statusVariant = (s: MaterialIssueStatus) => {
 if (s === 'issued') return 'info' as const;
 if (s === 'cancelled') return 'neutral' as const;
 return 'warning' as const;
};

export default function MaterialIssueDetailPage() {
 const { id = '' } = useParams<{ id: string }>();

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['inventory', 'material-issues', id],
 queryFn: () => materialIssuesApi.show(id),
 enabled: !!id,
 });

 if (isLoading) return <PageShell><SkeletonDetail /></PageShell>;
 if (isError || !data) return (
  <PageShell>
   <EmptyState icon="alert-circle" title="Failed to load material issue slip"
   action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
  </PageShell>
 );

 return (
 <PageShell className="space-y-4 pb-4">
 <PageHeader
 inset={false}
 title={<span className="font-mono">{data.slip_number}</span>}
 backTo="/inventory/material-issues"
 backLabel="Material Issues"
 actions={<Chip variant={statusVariant(data.status)}>{data.status_label ?? data.status}</Chip>}
 />

 <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
 <StatCard label="Total value" value={formatPeso(data.total_value)} />
 <StatCard label="Issued date" value={formatDate(data.issued_date)} />
 <StatCard label="Issued by" value={data.issuer?.name ?? '—'} />
 <StatCard label="Work order" value={data.work_order?.wo_number ?? data.reference_text ?? '—'} />
 </div>

 <div className="space-y-4">
 <Panel title="Line items" meta={`${data.items?.length ?? 0} lines`} noPadding>
 <TableScrollRegion label="Material issue line items">
 <table className={`${tableCls} min-w-max table-fixed`}>
 <thead>
 <tr className={theadTrCls}>
 <Th className="w-40">Item</Th>
 <Th align="right" className="w-44">Qty issued</Th>
 <Th align="right" className="w-40">Total</Th>
 <Th className="w-32 whitespace-nowrap">Location</Th>
 <Th align="right" className="w-40">Unit cost</Th>
 <Th className="w-48">Stock ledger</Th>
 </tr>
 </thead>
 <tbody>
 {(data.items ?? []).map((line) => (
 <tr key={line.id} className={trCls}>
 <Td>
 <div className="truncate font-mono" title={line.item?.code ?? '—'}>{line.item?.code ?? '—'}</div>
 <div className="truncate text-caption text-muted" title={line.item?.name ?? '—'}>{line.item?.name ?? '—'}</div>
 </Td>
 <Td align="right">
 <div className="tabular-nums">{formatPreciseQuantity(line.quantity_issued)} {line.item?.unit_of_measure}</div>
 {line.quantity_issued_in_uom !== null && line.issued_uom_conversion_factor !== null ? (
 <div className="text-caption text-muted leading-5">
  Entered {formatQuantity(line.quantity_issued_in_uom)} {line.issued_uom_code || line.item?.unit_of_measure}
  {' · 1 '}{line.issued_uom_code || line.item?.unit_of_measure}
  {' = '}{Number(line.issued_uom_conversion_factor).toLocaleString('en-PH', { minimumFractionDigits: 6, maximumFractionDigits: 6 })} {line.item?.unit_of_measure}
 </div>
 ) : line.issued_uom_code ? (
 <div className="text-caption text-muted leading-5">
  Source unit {line.issued_uom_code}; original quantity was not recorded.
 </div>
 ) : null}
 </Td>
 <Td align="right" className="tabular-nums font-medium">{formatPeso(line.total_cost)}</Td>
 <Td mono className="whitespace-nowrap">{line.location?.code ?? '—'}</Td>
 <Td align="right" className="tabular-nums">{formatUnitCost(line.unit_cost)}</Td>
          <Td>
           {line.stock_movement_id ? (
            <Link
             to={`/inventory/stock-levels?view=movements&movement_id=${encodeURIComponent(line.stock_movement_id)}`}
             data-touch-target
             className="text-sm text-link hover:text-link-hover hover:underline"
            >View movement / return unused</Link>
           ) : <span className="text-caption text-muted">No unique issue link</span>}
          </Td>
 </tr>
 ))}
 </tbody>
 </table>
 </TableScrollRegion>
 </Panel>

 {data.remarks && (
 <Panel title="Remarks">
 <p className="text-sm">{data.remarks}</p>
 </Panel>
 )}
 </div>
 </PageShell>
 );
}
