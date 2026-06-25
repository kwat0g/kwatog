/**
 * Entity-scoped audit trail page.
 *
 * IATF 16949 compliance — "show me all changes to PO-202604-0015".
 * Renders a chronological timeline of every audit row for a single record.
 *
 * Query params: model_type (basename, e.g. "PurchaseOrder"), model_id (hashid).
 */
import { useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Download, FileText, Clock, User as UserIcon, ArrowRight } from 'lucide-react';
import { auditLogsApi, type AuditLogEntry } from '@/api/admin/audit-logs';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatDateTime } from '@/lib/formatDate';

const actionVariant = {
  created: 'success',
  updated: 'info',
  deleted: 'danger',
} as const;

function ChangeSummary({ entry }: { entry: AuditLogEntry }) {
  const old = entry.old_values ?? {};
  const nw = entry.new_values ?? {};
  const keys = [...new Set([...Object.keys(old), ...Object.keys(nw)])];
  const changes = keys.filter((k) => old[k] !== nw[k]);

  if (entry.action === 'created') {
    return <span className="text-xs text-muted">Record created</span>;
  }
  if (entry.action === 'deleted') {
    return <span className="text-xs text-muted">Record deleted</span>;
  }
  if (changes.length === 0) {
    return <span className="text-xs text-muted">No field changes</span>;
  }

  return (
    <ul className="space-y-1 mt-1">
      {changes.slice(0, 8).map((key) => {
        const label = key.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
        return (
          <li key={key} className="text-xs flex items-center gap-1.5 flex-wrap">
            <span className="font-medium text-foreground">{label}</span>
            {old[key] !== undefined && (
              <span className="font-mono tabular-nums text-muted line-through">
                {formatFieldValue(old[key])}
              </span>
            )}
            {old[key] !== undefined && nw[key] !== undefined && (
              <ArrowRight size={10} className="text-muted shrink-0" />
            )}
            {nw[key] !== undefined && (
              <span className="font-mono tabular-nums text-foreground">
                {formatFieldValue(nw[key])}
              </span>
            )}
          </li>
        );
      })}
      {changes.length > 8 && (
        <li className="text-xs text-muted">...and {changes.length - 8} more</li>
      )}
    </ul>
  );
}

function formatFieldValue(value: unknown): string {
  if (value === null || value === undefined) return '(empty)';
  if (typeof value === 'boolean') return value ? 'Yes' : 'No';
  if (typeof value === 'object') {
    try {
      return JSON.stringify(value);
    } catch {
      return String(value);
    }
  }
  return String(value);
}

export default function EntityAuditTrailPage() {
  const [searchParams] = useSearchParams();
  const modelType = searchParams.get('model_type') ?? '';
  const modelId = searchParams.get('model_id') ?? '';

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['admin', 'audit-logs', 'entity', modelType, modelId],
    queryFn: () => auditLogsApi.entityTrail(modelType, modelId),
    enabled: !!modelType && !!modelId,
  });

  const handleExportPdf = () => {
    const url = auditLogsApi.exportPdfUrl({ model_type: modelType });
    window.open(url, '_blank');
  };

  if (!modelType || !modelId) {
    return (
      <div>
        <PageHeader
          title="Entity audit trail"
          backTo="/admin/audit-logs"
          backLabel="Audit logs"
          breadcrumbs={[
            { label: 'Admin', href: '/admin' },
            { label: 'Audit Logs', href: '/admin/audit-logs' },
            { label: 'Entity Trail' },
          ]}
        />
        <div className="px-5 py-4">
          <EmptyState
            icon="file-question"
            title="Missing parameters"
            description="Provide model_type and model_id query parameters to view an entity's audit trail."
          />
        </div>
      </div>
    );
  }

  return (
    <div>
      <PageHeader
        title={`${modelType} audit trail`}
        subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'entry' : 'entries'}` : undefined}
        backTo="/admin/audit-logs"
        backLabel="Audit logs"
        breadcrumbs={[
          { label: 'Admin', href: '/admin' },
          { label: 'Audit Logs', href: '/admin/audit-logs' },
          { label: `${modelType} Trail` },
        ]}
        actions={
          <div className="flex items-center gap-2">
            <Button
              variant="secondary"
              size="sm"
              icon={<Download size={14} />}
              onClick={handleExportPdf}
            >
              Export PDF
            </Button>
          </div>
        }
      />

      <div className="px-5 py-4">
        {isLoading && <SkeletonDetail />}

        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load entity audit trail"
            action={
              <Button variant="secondary" onClick={() => refetch()}>
                Retry
              </Button>
            }
          />
        )}

        {data && data.data.length === 0 && (
          <EmptyState
            icon="file-question"
            title="No audit entries"
            description={`No recorded changes for ${modelType} #${modelId}.`}
          />
        )}

        {data && data.data.length > 0 && (
          <div className="space-y-0">
            {data.data.map((entry, idx) => (
              <div key={entry.id} className="relative flex gap-4">
                {/* Timeline connector */}
                <div className="flex flex-col items-center shrink-0 w-6">
                  <div
                    className={`w-2.5 h-2.5 rounded-full mt-1.5 shrink-0 ${
                      entry.action === 'created'
                        ? 'bg-success'
                        : entry.action === 'deleted'
                          ? 'bg-danger'
                          : 'bg-info'
                    }`}
                  />
                  {idx < data.data.length - 1 && (
                    <div className="w-px flex-1 bg-border" />
                  )}
                </div>

                {/* Entry content */}
                <Panel className="flex-1 mb-3">
                  <div className="flex items-start justify-between gap-3 mb-2">
                    <div className="flex items-center gap-2 flex-wrap">
                      <Chip variant={actionVariant[entry.action] ?? 'neutral'}>
                        {entry.action}
                      </Chip>
                      <span className="text-xs text-muted flex items-center gap-1">
                        <Clock size={12} />
                        {formatDateTime(entry.created_at)}
                      </span>
                    </div>
                    {entry.user && (
                      <span className="text-xs text-muted flex items-center gap-1 shrink-0">
                        <UserIcon size={12} />
                        {entry.user.name}
                        {entry.user.role && (
                          <span className="text-2xs opacity-60">({entry.user.role.name})</span>
                        )}
                      </span>
                    )}
                    {!entry.user && (
                      <span className="text-xs text-muted">System</span>
                    )}
                  </div>

                  <ChangeSummary entry={entry} />

                  {entry.ip_address && (
                    <div className="mt-2 text-2xs text-muted font-mono">
                      IP: {entry.ip_address}
                    </div>
                  )}
                </Panel>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
