/** Sprint 8 — Task 74. Self-service: my attendance this month. */
import { useQuery } from '@tanstack/react-query';
import { client } from '@/api/client';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';

export default function SelfServiceDtrPage() {
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['self-service', 'dtr'],
    queryFn: () => client.get<{ data: any[]; meta: any }>('/attendances', {
      params: { per_page: 100, scope: 'self' },
    }).then(r => r.data),
  });

  if (isLoading) {
    return <div className="px-4 py-4 space-y-2">{[1, 2, 3, 4].map((i) => <SkeletonBlock key={i} className="h-12 rounded-md" />)}</div>;
  }
  if (isError) return (
    <div className="px-4 py-6">
      <EmptyState icon="alert-circle" title="Couldn't load attendance" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
    </div>
  );
  const rows: any[] = data?.data ?? [];
  if (!rows.length) return (
    <div className="px-4 py-6"><EmptyState icon="calendar" title="No attendance records this month" /></div>
  );

  return (
    <div className="px-4 py-4">
      <h1 className="text-base font-medium mb-3">Daily Time Record</h1>
      <ul className="rounded-md border border-default divide-y divide-subtle bg-canvas">
        {rows.slice(0, 31).map((r: any) => (
          <li key={r.id} className="px-3 py-2 flex justify-between items-center text-sm">
            <span className="font-mono tabular-nums">{r.date ?? '—'}</span>
            <span className="font-mono tabular-nums text-muted">
              {(r.time_in ?? '—').toString().slice(0, 16).replace('T', ' ')}
              {' – '}
              {(r.time_out ?? '—').toString().slice(0, 16).replace('T', ' ')}
            </span>
            <span className="font-mono tabular-nums">{(r.regular_hours ?? '0.00')}h</span>
          </li>
        ))}
      </ul>
    </div>
  );
}
