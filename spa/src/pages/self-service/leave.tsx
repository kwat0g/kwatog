/** Sprint 8 — Task 74. Self-service: my leave requests. */
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Plus } from 'lucide-react';
import { client } from '@/api/client';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';

const STATUS_CHIP: Record<string, 'success' | 'warning' | 'danger' | 'neutral' | 'info'> = {
  pending: 'warning', pending_dept: 'warning', pending_hr: 'info',
  approved: 'success', rejected: 'danger', cancelled: 'neutral',
};

export default function SelfServiceLeavePage() {
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['self-service', 'leave'],
    queryFn: () => client.get<{ data: any[] }>('/leaves/requests', {
      params: { per_page: 50, scope: 'self' },
    }).then(r => r.data),
  });

  return (
    <div className="px-4 py-4">
      <div className="flex items-center justify-between mb-3">
        <h1 className="text-base font-medium">My leave requests</h1>
        <Link to="/hr/leaves/create"
          className="h-8 inline-flex items-center px-3 rounded-md bg-accent text-accent-fg text-sm font-medium hover:bg-accent-hover">
          <Plus size={14} className="mr-1" />New request
        </Link>
      </div>

      {isLoading && <div className="space-y-2">{[1, 2, 3].map((i) => <SkeletonBlock key={i} className="h-14 rounded-md" />)}</div>}
      {isError && <EmptyState icon="alert-circle" title="Couldn't load leaves"
        action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
      {data && data.data.length === 0 && (
        <EmptyState icon="file-text" title="No leave requests yet" description="File one from the New Request button above." />
      )}
      {data && data.data.length > 0 && (
        <ul className="rounded-md border border-default divide-y divide-subtle bg-canvas">
          {data.data.map((r: any) => (
            <li key={r.id}>
              <Link to={`/hr/leaves/${r.id}`} className="block px-3 py-2.5 hover:bg-subtle">
                <div className="flex justify-between items-center">
                  <div>
                    <div className="text-sm font-medium font-mono">{r.leave_request_no ?? r.id}</div>
                    <div className="text-xs text-muted">{r.start_date} → {r.end_date} · {r.days} days</div>
                  </div>
                  <Chip variant={STATUS_CHIP[r.status] ?? 'neutral'}>{r.status?.replace('_', ' ')}</Chip>
                </div>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
