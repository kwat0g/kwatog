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
import { LuDownload, LuClock, LuUser as UserIcon, LuArrowRight } from '@/lib/icons';
import { auditLogsApi, type AuditLogEntry, type AuditLogParams, type AuditDiffRow } from '@/api/admin/audit-logs';
import { downloadAuthenticatedFile } from '@/api/download';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatDate, formatDateTime } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';

const actionVariant: Record<string, 'success' | 'info' | 'danger' | 'neutral'> = {
 created: 'success',
 updated: 'info',
 deleted: 'danger',
} as const;

function ChangeSummary({ entry }: { entry: AuditLogEntry }) {
 const changes = entry.diff ?? [];

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
 {changes.slice(0, 8).map((row) => {
 return (
 <li key={row.key} className="text-xs flex items-center gap-1.5 flex-wrap">
 <span className="font-medium text-primary">{row.label}</span>
 {row.old !== undefined && (
 <span className="font-mono tabular-nums text-muted line-through">
 {formatDiffValue(row.old, row)}
 </span>
 )}
 {row.old !== undefined && row.new !== undefined && (
 <LuArrowRight size={10} className="text-muted shrink-0" />
 )}
 {row.new !== undefined && (
 <span className="font-mono tabular-nums text-primary">
 {formatDiffValue(row.new, row)}
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

function formatDiffValue(value: unknown, row: AuditDiffRow): string {
 if (row.type === 'encrypted') return '(changed; hidden)';
 if (value === null || value === undefined) return '(empty)';
 if (typeof value === 'boolean') return value ? 'Yes' : 'No';
 if (row.type === 'money') return formatPeso(String(value));
 if (row.type === 'date') return typeof value === 'string' ? formatDate(value) : String(value);
 if (row.type === 'datetime') return typeof value === 'string' ? formatDateTime(value) : String(value);
 if (row.type === 'enum') return String(value).replace(/_/g, ' ');
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
 const [searchParams, setSearchParams] = useSearchParams();
 const modelType = searchParams.get('model_type') ?? '';
 const modelId = searchParams.get('model_id') ?? '';
 const rawPage = Number(searchParams.get('page') ?? '1');
 const page = Number.isFinite(rawPage) && rawPage > 0 ? Math.floor(rawPage) : 1;

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['admin', 'audit-logs', 'entity', modelType, modelId, page],
 queryFn: () => auditLogsApi.entityTrail(modelType, modelId, { page, per_page: 25 }),
 enabled: !!modelType && !!modelId,
 placeholderData: (previous) => previous,
 });

 const goToPage = (nextPage: number) => {
 const next = new URLSearchParams(searchParams);
 next.set('page', String(nextPage));
 setSearchParams(next);
 };

 const handleExportPdf = () => {
 const url = auditLogsApi.exportPdfUrl({ model_type: modelType, model_id: modelId } as AuditLogParams);
 void downloadAuthenticatedFile(url, { openInNewTab: true, errorMessage: 'Failed to generate audit trail PDF.' });
 };

 if (!modelType || !modelId) {
 return (
 <div>
 <PageHeader
 title="Entity audit trail"
 backTo="/admin/audit-logs"
 backLabel="Audit logs"
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
 actions={
 <div className="flex items-center gap-2">
 <Button
 variant="secondary"
 size="sm"
 icon={<LuDownload size={14} />}
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
 ? 'bg-success-bg'
 : entry.action === 'deleted'
 ? 'bg-danger-bg'
 : 'bg-info-bg'
 }`}
 />
 {idx < data.data.length - 1 && (
 <div className="w-px flex-1 bg-border-default" />
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
 <LuClock size={12} />
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

 {data && data.meta.last_page > 1 && (
 <div className="flex items-center justify-between mt-3 text-xs text-muted">
 <span>
 Page <span className="font-mono tabular-nums">{data.meta.current_page}</span> of{' '}
 <span className="font-mono tabular-nums">{data.meta.last_page}</span>
 </span>
 <div className="flex gap-1">
 <Button
 variant="secondary"
 size="sm"
 disabled={data.meta.current_page <= 1}
 onClick={() => goToPage(data.meta.current_page - 1)}
 >
 Previous
 </Button>
 <Button
 variant="secondary"
 size="sm"
 disabled={data.meta.current_page >= data.meta.last_page}
 onClick={() => goToPage(data.meta.current_page + 1)}
 >
 Next
 </Button>
 </div>
 </div>
 )}
 </div>
 </div>
 );
}
