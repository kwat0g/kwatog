import { useQuery } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { customerPortalApi } from '@/api/b2b/customer';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { formatDate } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

export default function CustomerReturnDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const query = useQuery({
    queryKey: ['portal', 'customer', 'return', id],
    queryFn: () => customerPortalApi.getReturnRequest(id),
    enabled: !!id,
  });

  if (query.isLoading) return <SkeletonDetail />;
  if (query.isError || !query.data) return <EmptyState icon="alert-circle" title="Return request unavailable" action={<Button variant="secondary" onClick={() => query.refetch()}>Retry</Button>} />;

  const request = query.data;
  return <div>
    <PageHeader
      title={<>{request.rma_number} <Chip variant={chipVariantForStatus(request.status)}>{request.status_label}</Chip></>}
      subtitle={`Submitted ${formatDate(request.created_at)}`}
      backTo="/portal/customer/returns"
      backLabel="Returns & RMAs"
    />
    <div className="px-5 py-4 space-y-4">
      <Panel title="Request details">
        <p className="text-sm whitespace-pre-wrap">{request.reason_description || request.reason_code || 'No reason provided.'}</p>
        {request.customer_notes && <p className="mt-2 text-sm text-muted whitespace-pre-wrap">{request.customer_notes}</p>}
        {request.resolution && <p className="mt-2 text-sm text-muted whitespace-pre-wrap">Resolution: {request.resolution}</p>}
        {request.source_case && <p className="mt-2 text-sm text-muted">Reported problem: <Link className="text-link hover:underline" to={`/portal/customer/problems/${request.source_case.id}`}>{request.source_case.case_number}</Link></p>}
      </Panel>
      <Panel title="Returned items" meta={String(request.items?.length ?? 0)} noPadding>
        {request.items && request.items.length > 0 ? <div className="overflow-x-auto"><table className={tableCls}>
          <thead><tr className={theadTrCls}><Th>Part #</Th><Th>Description</Th><Th align="right">Quantity</Th><Th align="right">Unit price</Th><Th>Disposition</Th></tr></thead>
          <tbody>{request.items.map((item) => <tr key={item.id} className={trCls}>
            <Td mono>{item.product?.part_number ?? '—'}</Td>
            <Td>{item.product?.name ?? 'Product'}</Td>
            <Td align="right" mono>{item.quantity}</Td>
            <Td align="right" mono>{formatPeso(item.unit_price)}</Td>
            <Td>{item.disposition?.replace(/_/g, ' ') ?? 'Under review'}</Td>
          </tr>)}</tbody>
        </table></div> : <EmptyState icon="package" title="No item details available" />}
      </Panel>
    </div>
  </div>;
}
