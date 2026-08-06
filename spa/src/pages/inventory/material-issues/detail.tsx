import { useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { materialIssuesApi } from '@/api/inventory/material-issues';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatDate } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
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

 if (isLoading) return <SkeletonDetail />;
 if (isError || !data) return (
 <EmptyState icon="alert-circle" title="Failed to load material issue slip"
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
 );

 return (
 <div>
 <PageHeader
 title={<span className="font-mono">{data.slip_number}</span>}
 backTo="/inventory/material-issues"
 backLabel="Material Issues"
 breadcrumbs={[
 { label: 'Warehouse', href: '/inventory/items' },
 { label: 'Material Issues', href: '/inventory/material-issues' },
 { label: data.slip_number },
 ]}
 actions={<Chip variant={statusVariant(data.status)}>{data.status_label ?? data.status}</Chip>}
 />

 <div className="px-5 pt-3 pb-4 grid grid-cols-4 gap-2">
 <StatCard label="Total value" value={formatPeso(data.total_value)} />
 <StatCard label="Issued date" value={formatDate(data.issued_date)} />
 <StatCard label="Issued by" value={data.issuer?.name ?? '—'} />
 <StatCard label="Work order" value={data.reference_text ?? '—'} />
 </div>

 <div className="px-5 pb-4 space-y-4">
 <Panel title="Line items" meta={`${data.items?.length ?? 0} lines`} noPadding>
 <table className={tableCls}>
 <thead>
 <tr className={theadTrCls}>
 <Th>Item</Th>
 <Th>Location</Th>
 <Th align="right">Qty issued</Th>
 <Th align="right">Unit cost</Th>
 <Th align="right">Total</Th>
 </tr>
 </thead>
 <tbody>
 {(data.items ?? []).map((line) => (
 <tr key={line.id} className={trCls}>
 <Td>
 <div className="font-mono">{line.item?.code ?? '—'}</div>
 <div className="text-muted">{line.item?.name ?? '—'}</div>
 </Td>
 <Td mono>{line.location?.code ?? '—'}</Td>
 <Td align="right" mono>
 {Number(line.quantity_issued).toFixed(4)} {line.item?.unit_of_measure}
 </Td>
 <Td align="right" mono>{formatPeso(line.unit_cost)}</Td>
 <Td align="right" mono className="font-medium">{formatPeso(line.total_cost)}</Td>
 </tr>
 ))}
 </tbody>
 </table>
 </Panel>

 {data.remarks && (
 <Panel title="Remarks">
 <p className="text-sm">{data.remarks}</p>
 </Panel>
 )}
 </div>
 </div>
 );
}
