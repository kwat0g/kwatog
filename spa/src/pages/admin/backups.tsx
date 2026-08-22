import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { LuArchive, LuCloudUpload, LuDatabase, LuRotateCcw, LuShieldCheck } from '@/lib/icons';
import { backupsApi, type BackupOperation } from '@/api/admin/backups';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Panel } from '@/components/ui/Panel';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatDateTime } from '@/lib/formatDate';
import { formatInt } from '@/lib/formatNumber';

function formatBytes(value: number | null | undefined): string {
 if (!value) return '—';
 if (value < 1024) return `${value} B`;
 if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`;
 if (value < 1024 * 1024 * 1024) return `${(value / (1024 * 1024)).toFixed(1)} MB`;
 return `${(value / (1024 * 1024 * 1024)).toFixed(1)} GB`;
}

function artifactSummary(operation: BackupOperation): string {
 const database = operation.artifacts.database;
 const files = operation.artifacts.files;
 if (!database) return 'No database artifact';
 return files
 ? `${formatBytes(database.size)} database · ${formatBytes(files.size)} private files`
 : `${formatBytes(database.size)} database`;
}

export default function AdminBackupsPage() {
 const queryClient = useQueryClient();
 const [restoreTarget, setRestoreTarget] = useState<BackupOperation | null>(null);
 const [confirmation, setConfirmation] = useState('');

 const query = useQuery({
 queryKey: ['admin', 'backups'],
 queryFn: backupsApi.index,
 refetchInterval: (current) => current.state.data?.active_operation ? 5_000 : 30_000,
 });

 const create = useMutation({
 mutationFn: backupsApi.create,
 onSuccess: (result) => {
 toast.success(result.message);
 void queryClient.invalidateQueries({ queryKey: ['admin', 'backups'] });
 },
 onError: () => toast.error('Could not queue the backup.'),
 });

 const restore = useMutation({
 mutationFn: backupsApi.restore,
 onSuccess: (result) => {
 toast.success(result.message);
 setRestoreTarget(null);
 setConfirmation('');
 void queryClient.invalidateQueries({ queryKey: ['admin', 'backups'] });
 },
 onError: () => toast.error('Could not queue the restore.'),
 });

 const backups = query.data?.backups ?? [];
 const selectedDatabase = restoreTarget?.artifacts.database?.name ?? '';
 const expectedConfirmation = `RESTORE ${selectedDatabase}`;
 const canConfirm = confirmation === expectedConfirmation && !restore.isPending;

 return (
 <div>
 <PageHeader
 title="Backup & Restore"
 subtitle="Validated database and private-file recovery artifacts"
 actions={
 <Button
 variant="primary"
 icon={<LuArchive size={14} />}
 onClick={() => create.mutate()}
 loading={create.isPending}
 disabled={!!query.data?.active_operation}
 >
 Create full backup
 </Button>
 }
 />

 <div className="px-5 py-4 space-y-4">
 <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
 <Panel title="Coverage" meta="Included in each full backup">
 <div className="flex items-center gap-2 text-sm text-primary"><LuDatabase size={16} /> PostgreSQL database</div>
 <div className="flex items-center gap-2 text-sm text-primary mt-2"><LuArchive size={16} /> Private uploaded files</div>
 </Panel>
 <Panel title="Integrity" meta="Validated before publishing">
 <div className="flex items-center gap-2 text-sm text-primary"><LuShieldCheck size={16} /> Gzip, archive, and SHA-256 checks</div>
 <p className="text-xs text-muted mt-2">Restore creates a fresh rollback backup first.</p>
 </Panel>
 <Panel title="Off-site storage" meta="Disaster-recovery readiness">
 <div className="flex items-center gap-2 text-sm text-primary">
 <LuCloudUpload size={16} />
 {query.data?.configuration.offsite_configured ? 'S3 replication configured' : 'S3 replication not configured'}
 </div>
 <p className="text-xs text-muted mt-2">
 {query.data?.configuration.offsite_configured
 ? 'Backups can be recovered after VPS loss.'
 : 'Configure BACKUP_S3_BUCKET before relying on this as disaster recovery.'}
 </p>
 </Panel>
 </div>

 {query.data?.active_operation && (
 <div className="px-3 py-2 rounded-md border border-info bg-info-bg text-sm text-info-fg">
 {query.data.active_operation.type === 'restore' ? 'A restore is running. The application may be in maintenance mode.' : 'A full backup is running.'}
 </div>
 )}

 {query.isLoading && <SkeletonTable columns={5} rows={6} />}
 {query.isError && (
 <EmptyState icon="alert-circle" title="Failed to load backup history" action={<Button variant="secondary" onClick={() => void query.refetch()}>Retry</Button>} />
 )}
 {!query.isLoading && !query.isError && backups.length === 0 && (
 <EmptyState icon="inbox" title="No backups recorded yet" description="Create a full backup to publish a validated database and private-file snapshot." />
 )}
 {backups.length > 0 && (
 <Panel title="Backup history" meta={`${formatInt(backups.length)} shown`} noPadding>
 <div className="overflow-x-auto">
 <table className="w-full text-sm">
 <thead className="bg-subtle text-xs text-muted uppercase tracking-wider">
 <tr>
 <th className="text-left px-4 py-3 font-medium">Created</th>
 <th className="text-left px-4 py-3 font-medium">Artifacts</th>
 <th className="text-left px-4 py-3 font-medium">Status</th>
 <th className="text-left px-4 py-3 font-medium">Requested by</th>
 <th className="text-right px-4 py-3 font-medium">Action</th>
 </tr>
 </thead>
 <tbody>
 {backups.map((operation) => {
 const database = operation.artifacts.database;
 const canRestore = !!database && ['completed', 'available'].includes(operation.status) && operation.type === 'backup';
 return (
 <tr key={`${operation.id ?? 'legacy'}-${database?.name ?? operation.created_at}`} className="border-t border-default align-top">
 <td className="px-4 py-3 whitespace-nowrap">{formatDateTime(operation.created_at)}</td>
 <td className="px-4 py-3">
 <div className="font-mono text-xs text-primary break-all">{database?.name ?? '—'}</div>
 <div className="text-xs text-muted mt-1">{artifactSummary(operation)}</div>
 {operation.artifacts.files && <div className="font-mono text-[11px] text-muted mt-1 break-all">{operation.artifacts.files.name}</div>}
 </td>
 <td className="px-4 py-3"><Chip variant={chipVariantForStatus(operation.status)}>{operation.status}</Chip>{operation.error_message && <div className="text-xs text-danger-fg mt-1 max-w-xs">{operation.error_message}</div>}</td>
 <td className="px-4 py-3 text-muted">{operation.requested_by_name ?? '—'}</td>
 <td className="px-4 py-3 text-right">
 {canRestore && (
 <Button variant="danger" size="sm" icon={<LuRotateCcw size={14} />} onClick={() => { setRestoreTarget(operation); setConfirmation(''); }}>
 Restore
 </Button>
 )}
 </td>
 </tr>
 );
 })}
 </tbody>
 </table>
 </div>
 </Panel>
 )}
 </div>

 <Modal isOpen={!!restoreTarget} onClose={() => { if (!restore.isPending) { setRestoreTarget(null); setConfirmation(''); } }} title="Restore backup" size="md" closeOnOverlayClick={!restore.isPending}>
 <div className="space-y-4">
 <div className="rounded-md border border-danger bg-danger-bg p-3 text-sm text-danger-fg">
 This is destructive. The application will enter maintenance mode, create a new rollback backup, replace the database, and restore private files if this snapshot contains them.
 </div>
 <div className="space-y-1 text-sm text-primary">
 <div>Database: <span className="font-mono text-xs break-all">{selectedDatabase}</span></div>
 {restoreTarget?.artifacts.files && <div>Files: <span className="font-mono text-xs break-all">{restoreTarget.artifacts.files.name}</span></div>}
 </div>
 <Input
 label={`Type ${expectedConfirmation} to continue`}
 value={confirmation}
 onChange={(event) => setConfirmation(event.target.value)}
 autoComplete="off"
 spellCheck={false}
 disabled={restore.isPending}
 />
 </div>
 <ModalFooter>
 <Button variant="secondary" onClick={() => { setRestoreTarget(null); setConfirmation(''); }} disabled={restore.isPending}>Cancel</Button>
 <Button
 variant="danger"
 icon={<LuRotateCcw size={14} />}
 loading={restore.isPending}
 disabled={!canConfirm}
 onClick={() => {
 if (!restoreTarget?.artifacts.database) return;
 restore.mutate({
 database_filename: restoreTarget.artifacts.database.name,
 files_filename: restoreTarget.artifacts.files?.name,
 confirmation,
 });
 }}
 >
 Start restore
 </Button>
 </ModalFooter>
 </Modal>
 </div>
 );
}
