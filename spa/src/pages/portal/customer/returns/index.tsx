import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { customerPortalApi } from '@/api/b2b/customer';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { PageHeader } from '@/components/layout/PageHeader';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { formatDate } from '@/lib/formatDate';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

export default function CustomerReturnsPage() {
  const navigate = useNavigate();
  const query = useQuery({
    queryKey: ['portal', 'customer', 'returns'],
    queryFn: () => customerPortalApi.listReturnRequests({ per_page: 25 }),
    placeholderData: (previous) => previous,
  });

  return <div>
    <PageHeader
      title="Returns & RMAs"
      subtitle="Request a return for delivered products and follow its review status."
      actions={<Button variant="primary" size="sm" onClick={() => navigate('/portal/customer/returns/new')}>New return request</Button>}
    />
    <div className="px-5 py-4">
      {query.isLoading && !query.data && <SkeletonTable columns={4} rows={6} />}
      {query.isError && <EmptyState icon="alert-circle" title="Failed to load return requests" action={<Button variant="secondary" onClick={() => query.refetch()}>Retry</Button>} />}
      {query.data && query.data.data.length === 0 && <EmptyState
        icon="package"
        title="No return requests yet"
        description="Submit a return request for a delivered invoice, order, or delivery line."
        action={<Button variant="primary" onClick={() => navigate('/portal/customer/returns/new')}>Start a return</Button>}
      />}
      {query.data && query.data.data.length > 0 && <div className="overflow-x-auto rounded-md border border-default">
        <table className={tableCls}>
          <thead><tr className={theadTrCls}><Th>RMA</Th><Th>Status</Th><Th>Reason</Th><Th>Submitted</Th></tr></thead>
          <tbody>{query.data.data.map((request) => <tr key={request.id} className={trCls}>
            <Td mono><Link className="text-link hover:underline" to={`/portal/customer/returns/${request.id}`}>{request.rma_number}</Link></Td>
            <Td><Chip variant={chipVariantForStatus(request.status)}>{request.status_label}</Chip></Td>
            <Td>{request.reason_description ?? request.reason_code ?? '—'}</Td>
            <Td mono className="text-muted">{formatDate(request.created_at)}</Td>
          </tr>)}</tbody>
        </table>
      </div>}
    </div>
  </div>;
}
