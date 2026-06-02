/** Sprint 8 — Task 79. Audit log detail with field-level diff. */
/* Sprint P7 — diff rows now carry label+type so this page renders
 * "Monthly Salary: ₱18,000.00 → ₱20,000.00" instead of raw JSON. */
import { useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { client } from '@/api/client';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatDateTime, formatDate } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';

type DiffKind = 'added' | 'removed' | 'changed';
type DiffType = 'text' | 'money' | 'date' | 'datetime' | 'enum' | 'boolean' | 'decimal' | 'encrypted';

interface DiffRow {
  kind: DiffKind;
  key: string;
  /** Sprint P7 — human-readable field name. */
  label: string;
  /** Sprint P7 — formatting hint for old/new values. */
  type: DiffType;
  old?: unknown;
  new?: unknown;
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
  old_values: Record<string, unknown> | null;
  new_values: Record<string, unknown> | null;
  diff: DiffRow[];
}

const KIND_BG: Record<DiffKind, string> = {
  added:   'bg-success-bg text-success-fg',
  removed: 'bg-danger-bg text-danger-fg',
  changed: 'bg-info-bg text-info-fg',
};

const KIND_LABEL: Record<DiffKind, string> = {
  added:   'Added',
  removed: 'Removed',
  changed: 'Changed',
};

/** Format a single old/new value according to the field type. */
function formatValue(value: unknown, type: DiffType): string {
  if (value === null || value === undefined || value === '') return '—';
  switch (type) {
    case 'money':
      return formatPeso(String(value));
    case 'date':
      return typeof value === 'string' ? formatDate(value) : String(value);
    case 'datetime':
      return typeof value === 'string' ? formatDateTime(value) : String(value);
    case 'enum':
      return String(value).replace(/_/g, ' ');
    case 'boolean':
      return value ? 'Yes' : 'No';
    case 'decimal':
      return String(value);
    case 'encrypted':
      // Backend already redacted this; never let cleartext slip through.
      return '••••';
    default:
      if (typeof value === 'object') {
        try { return JSON.stringify(value); } catch { return String(value); }
      }
      return String(value);
  }
}

export default function AuditLogDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['audit-log', id],
    queryFn: () => client.get<{ data: AuditLogDetail }>(`/admin/audit-logs/${id}`).then((r) => r.data.data),
  });

  if (isLoading) return <SkeletonDetail />;
  if (isError || !data) return (
    <EmptyState
      icon="alert-circle"
      title="Failed to load audit entry"
      action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
    />
  );

  const actionVariant = data.action === 'created' ? 'success' : data.action === 'updated' ? 'info' : 'danger';

  return (
    <div>
      <PageHeader
        title={`Audit entry #${data.id}`}
        subtitle={`${data.model_type}${data.model_id ? ` · #${data.model_id}` : ''}`}
        backTo="/admin/audit-logs"
        backLabel="Audit logs"
        breadcrumbs={[
          { label: 'Admin', href: '/admin' },
          { label: 'Users & Roles', href: '/admin/users-roles' },
          { label: 'Audit Logs', href: '/admin/audit-logs' },
          { label: `Entry #${data.id}` },
        ]}
        actions={<Chip variant={actionVariant}>{data.action}</Chip>}
      />
      <div className="px-5 pb-6 grid grid-cols-3 gap-4">
        <div className="col-span-2">
          <Panel
            title="Field-level diff"
            meta={
              data.diff.length === 0
                ? 'no changes'
                : `${data.diff.length} ${data.diff.length === 1 ? 'change' : 'changes'}`
            }
          >
            {data.diff.length === 0 ? (
              <p className="text-sm text-muted">No field changes recorded.</p>
            ) : (
              <ul className="divide-y divide-subtle">
                {data.diff.map((row) => (
                  <li key={row.key} className="py-2.5">
                    <div className="flex items-center gap-2 mb-1">
                      <span
                        className={`inline-flex items-center px-1.5 py-0.5 rounded-sm text-xs font-medium ${KIND_BG[row.kind]}`}
                      >
                        {KIND_LABEL[row.kind]}
                      </span>
                      <span className="text-sm font-medium">{row.label}</span>
                      <span className="text-2xs uppercase tracking-wider text-muted font-mono">
                        {row.key}
                      </span>
                    </div>
                    {row.kind === 'changed' && (
                      <div className="text-sm pl-1 flex items-baseline gap-2 flex-wrap">
                        <span className="font-mono tabular-nums text-muted line-through">
                          {formatValue(row.old, row.type)}
                        </span>
                        <span className="text-muted">→</span>
                        <span className="font-mono tabular-nums text-primary font-medium">
                          {formatValue(row.new, row.type)}
                        </span>
                      </div>
                    )}
                    {row.kind === 'added' && (
                      <div className="text-sm pl-1 font-mono tabular-nums text-success-fg">
                        + {formatValue(row.new, row.type)}
                      </div>
                    )}
                    {row.kind === 'removed' && (
                      <div className="text-sm pl-1 font-mono tabular-nums text-danger-fg">
                        − {formatValue(row.old, row.type)}
                      </div>
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
              <Row label="Actor">
                {data.user ? (
                  <span>
                    <span className="block">{data.user.name}</span>
                    <span className="block text-xs text-muted">{data.user.email}</span>
                  </span>
                ) : (
                  <span className="text-muted">system</span>
                )}
              </Row>
              <Row label="IP">
                <span className="font-mono text-xs">{data.ip_address ?? '—'}</span>
              </Row>
              <Row label="User agent">
                <span className="font-mono text-xs break-all">{data.user_agent ?? '—'}</span>
              </Row>
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
