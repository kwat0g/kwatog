/** Sprint 8 — Task 79. Audit log detail with field-level diff. */
import { useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { client } from '@/api/client';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatDateTime } from '@/lib/formatDate';

interface DiffRow {
  kind: 'added' | 'removed' | 'changed';
  key: string;
  old?: any;
  new?: any;
}
interface AuditLogDetail {
  id: number;
  action: 'created' | 'updated' | 'deleted';
  model_type: string;
  model_id: number | null;
  user: { id: string; name: string; email: string } | null;
  ip_address: string | null;
  user_agent: string | null;
  created_at: string;
  old_values: Record<string, any> | null;
  new_values: Record<string, any> | null;
  diff: DiffRow[];
}

const KIND_BG: Record<DiffRow['kind'], string> = {
  added:   'bg-success-bg text-success-fg',
  removed: 'bg-danger-bg text-danger-fg',
  changed: 'bg-warning-bg text-warning-fg',
};

export default function AuditLogDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['audit-log', id],
    queryFn: () => client.get<{ data: AuditLogDetail }>(`/admin/audit-logs/${id}`).then(r => r.data.data),
  });

  if (isLoading) return <SkeletonDetail />;
  if (isError || !data) return (
    <EmptyState icon="alert-circle" title="Failed to load audit entry"
      action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
  );

  return (
    <div>
      <PageHeader
        title={`Audit entry #${data.id}`}
        subtitle={`${data.model_type}${data.model_id ? ` · #${data.model_id}` : ''}`}
        backTo="/admin/audit-logs"
        backLabel="Audit logs"
        actions={<Chip variant={data.action === 'created' ? 'success' : data.action === 'updated' ? 'info' : 'danger'}>{data.action}</Chip>}
      />
      <div className="px-5 pb-6 grid grid-cols-3 gap-4">
        <div className="col-span-2">
          <Panel title="Field-level diff" meta={data.diff.length === 0 ? 'no changes' : `${data.diff.length} ${data.diff.length === 1 ? 'change' : 'changes'}`}>
            {data.diff.length === 0 ? (
              <p className="text-sm text-muted">No field changes recorded.</p>
            ) : (
              <ul className="divide-y divide-subtle">
                {data.diff.map((row) => (
                  <li key={row.key} className="py-2">
                    <div className="flex items-center gap-2 mb-1">
                      <span className={`inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium ${KIND_BG[row.kind]}`}>
                        {row.kind}
                      </span>
                      <span className="font-mono text-sm">{row.key}</span>
                    </div>
                    {row.kind === 'changed' && (
                      <div className="grid grid-cols-2 gap-2 text-sm pl-1">
                        <pre className="font-mono text-xs bg-subtle rounded px-2 py-1 overflow-x-auto whitespace-pre-wrap">{stringify(row.old)}</pre>
                        <pre className="font-mono text-xs bg-elevated rounded px-2 py-1 overflow-x-auto whitespace-pre-wrap">{stringify(row.new)}</pre>
                      </div>
                    )}
                    {row.kind === 'added' && (
                      <pre className="font-mono text-xs bg-success-bg text-success-fg rounded px-2 py-1 overflow-x-auto whitespace-pre-wrap">{stringify(row.new)}</pre>
                    )}
                    {row.kind === 'removed' && (
                      <pre className="font-mono text-xs bg-danger-bg text-danger-fg rounded px-2 py-1 overflow-x-auto whitespace-pre-wrap">{stringify(row.old)}</pre>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </Panel>
        </div>
        <aside className="space-y-3">
          <Panel title="Context">
            <dl className="text-sm divide-y divide-subtle">
              <Row label="When">{formatDateTime(data.created_at)}</Row>
              <Row label="Actor">{data.user ? <span><span className="block">{data.user.name}</span><span className="block text-xs text-muted">{data.user.email}</span></span> : <span className="text-muted">system</span>}</Row>
              <Row label="IP"><span className="font-mono text-xs">{data.ip_address ?? '—'}</span></Row>
              <Row label="User agent"><span className="font-mono text-xs break-all">{data.user_agent ?? '—'}</span></Row>
            </dl>
          </Panel>
        </aside>
      </div>
    </div>
  );
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex justify-between gap-4 py-1.5">
      <span className="text-xs uppercase tracking-wider text-muted shrink-0">{label}</span>
      <span className="text-right">{children}</span>
    </div>
  );
}

function stringify(v: any): string {
  if (v === null || v === undefined) return '—';
  if (typeof v === 'string') return v;
  try { return JSON.stringify(v, null, 2); } catch { return String(v); }
}
