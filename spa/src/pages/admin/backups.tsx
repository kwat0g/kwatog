import { useMemo, useState, type ReactNode } from 'react';
import { useMutation, useInfiniteQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import {
 LuArchive,
 LuCheck,
 LuChevronDown,
 LuCloudUpload,
 LuCopy,
 LuDatabase,
 LuFolderOpen,
 LuInfo,
 LuRefreshCw,
 LuRotateCcw,
 LuShieldAlert,
 LuShieldCheck,
 LuTriangleAlert,
} from '@/lib/icons';
import { backupsApi, type BackupOperation } from '@/api/admin/backups';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Panel } from '@/components/ui/Panel';
import { QueryErrorState } from '@/components/ui/QueryErrorState';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatDateTime, formatRelative } from '@/lib/formatDate';
import { formatInt } from '@/lib/formatNumber';

const EMPTY_BACKUPS: BackupOperation[] = [];

function formatBytes(value: number | null | undefined): string {
 if (value === null || value === undefined) return '—';
 if (value < 1024) return `${value} B`;
 if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`;
 if (value < 1024 * 1024 * 1024) return `${(value / (1024 * 1024)).toFixed(1)} MB`;
 return `${(value / (1024 * 1024 * 1024)).toFixed(1)} GB`;
}

function shortHash(value: string | null | undefined): string {
 if (!value) return 'not recorded';
 return `${value.slice(0, 12)}…`;
}

function artifactSummary(operation: BackupOperation): string {
 const artifacts = [operation.artifacts.database, operation.artifacts.files].filter(Boolean);
 if (!artifacts.length) return 'No validated artifacts';
 return artifacts.map((artifact) => formatBytes(artifact?.size)).join(' · ');
}

function statusLabel(status: BackupOperation['status']): string {
 switch (status) {
 case 'completed':
 return 'Committed';
 case 'available':
 return 'Legacy archive';
 case 'running':
 return 'Running';
 case 'queued':
 return 'Queued';
 case 'failed':
 return 'Failed';
 case 'rollback_required':
 return 'Rollback required';
 case 'rolled_back':
 return 'Rolled back';
 }
}

function statusDescription(operation: BackupOperation): string {
 switch (operation.status) {
 case 'completed':
 return operation.restorable === true
 ? 'Committed manifest; restore available'
 : 'Committed manifest; artifact unavailable or unverified';
 case 'available':
 return 'Legacy archive; no committed restore manifest';
 case 'running':
 return 'Working in the background';
 case 'queued':
 return 'Waiting to start';
 case 'failed':
 return 'No usable snapshot was published';
 case 'rollback_required':
 return 'Maintenance gate held; operator rollback required';
 case 'rolled_back':
 return 'Pre-restore snapshot was restored';
 }
}

function statusIcon(status: BackupOperation['status']): ReactNode {
 switch (status) {
 case 'completed':
 case 'available':
 return <LuCheck size={13} />;
 case 'running':
 return <LuRefreshCw size={13} className="animate-spin" />;
 case 'queued':
 return <LuInfo size={13} />;
 case 'failed':
 return <LuTriangleAlert size={13} />;
 case 'rollback_required':
 return <LuShieldAlert size={13} />;
 case 'rolled_back':
 return <LuCheck size={13} />;
 }
}

function canRestoreOperation(operation: BackupOperation): boolean {
 return Boolean(
 operation.type === 'backup'
 && operation.id
 && operation.manifest_committed === true
 && operation.restorable === true
 && operation.artifacts.database
 && operation.status === 'completed',
 );
}

function artifactAvailabilityLabel(availability: NonNullable<BackupOperation['artifacts']['database']>['availability']): string {
 switch (availability) {
 case 'local':
 return 'Local · matches manifest';
 case 'remote':
 return 'Off-site · matches manifest';
 case 'mismatch':
 return 'Does not match manifest';
 case 'missing':
 return 'Unavailable';
 }
}

async function copyArtifactName(name: string): Promise<void> {
 if (!navigator.clipboard) {
 toast.error('Clipboard is unavailable in this browser.');
 return;
 }

 try {
 await navigator.clipboard.writeText(name);
 toast.success('Artifact filename copied.');
 } catch {
 toast.error('Could not copy the artifact filename.');
 }
}

function ArtifactLine({
 artifact,
 icon,
 label,
 compact = false,
}: {
 artifact: NonNullable<BackupOperation['artifacts']['database']>;
 icon: ReactNode;
 label: string;
 compact?: boolean;
}) {
 return (
 <div className="flex items-start gap-2 min-w-0">
 <span className="mt-0.5 shrink-0 text-muted" aria-hidden>{icon}</span>
 <div className="min-w-0">
 {compact ? (
 <div className="flex items-center gap-2">
 <span className="text-sm text-primary">{label}</span>
 <span className="text-xs text-muted">{formatBytes(artifact.size)}</span>
 <span className={`text-xs ${artifact.availability === 'mismatch' ? 'text-danger-fg' : artifact.availability === 'missing' ? 'text-warning-fg' : 'text-muted'}`}>
 {artifactAvailabilityLabel(artifact.availability)}
 </span>
 </div>
 ) : (
 <>
 <div className="flex items-start gap-1.5">
 <span className="font-mono text-xs text-primary break-all" title={artifact.name}>{artifact.name}</span>
 <button
 type="button"
 className="shrink-0 mt-0.5 text-muted hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent rounded"
 aria-label={`Copy ${label} filename`}
 title="Copy filename"
 onClick={() => void copyArtifactName(artifact.name)}
 >
 <LuCopy size={12} />
 </button>
 </div>
 <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-muted mt-0.5">
 <span>{label}</span>
 <span aria-hidden>·</span>
 <span>{formatBytes(artifact.size)}</span>
 <span aria-hidden>·</span>
 <span title={artifact.sha256 ?? undefined}>SHA-256 {shortHash(artifact.sha256)}</span>
 <span aria-hidden>·</span>
 <span className={artifact.availability === 'mismatch' ? 'text-danger-fg' : artifact.availability === 'missing' ? 'text-warning-fg' : undefined}>
 {artifactAvailabilityLabel(artifact.availability)}
 </span>
 </div>
 </>
 )}
 </div>
 </div>
 );
}

function StatusBlock({ operation }: { operation: BackupOperation }) {
 const [expanded, setExpanded] = useState(false);
 const hasError = Boolean(operation.error_message);

 return (
 <div className="min-w-[150px]">
 <Chip variant={chipVariantForStatus(operation.status)} className="gap-1">
 {statusIcon(operation.status)}
 {statusLabel(operation.status)}
 </Chip>
 <div className="text-xs text-muted mt-1">{statusDescription(operation)}</div>
 {hasError && (
 <>
 <button
 type="button"
 className="inline-flex items-center gap-1 text-xs text-danger-fg hover:underline mt-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent rounded"
 onClick={() => setExpanded((value) => !value)}
 aria-expanded={expanded}
 >
 {expanded ? 'Hide failure details' : 'View failure details'}
 <LuChevronDown size={12} className={expanded ? 'rotate-180' : ''} />
 </button>
 {expanded && (
 <div className="mt-2 rounded border border-danger bg-danger-bg px-2 py-1.5 text-xs text-danger-fg break-words">
 {operation.error_message}
 </div>
 )}
 </>
 )}
 </div>
 );
}

function BackupRow({
 operation,
 onRestore,
}: {
 operation: BackupOperation;
 onRestore: (operation: BackupOperation) => void;
}) {
 const database = operation.artifacts.database;
 const files = operation.artifacts.files;

 return (
 <tr className="border-t border-default align-top hover:bg-row-hover transition-colors">
 <td className="px-4 py-3 whitespace-nowrap">
 <time dateTime={operation.created_at ?? undefined} className="text-sm text-primary">
 {formatDateTime(operation.created_at)}
 </time>
 <div className="text-xs text-muted mt-1">{formatRelative(operation.created_at)}</div>
 </td>
 <td className="px-4 py-3 min-w-[280px]">
 {database ? (
 <div className="space-y-2">
 <ArtifactLine artifact={database} icon={<LuDatabase size={14} />} label="PostgreSQL database" compact />
 {files && <ArtifactLine artifact={files} icon={<LuFolderOpen size={14} />} label="Private uploaded files" compact />}
 </div>
 ) : (
 <div className="flex items-center gap-2 text-sm text-muted">
 <LuArchive size={14} />
 <span>No database artifact</span>
 </div>
 )}
 </td>
 <td className="px-4 py-3">
 <StatusBlock operation={operation} />
 </td>
 <td className="px-4 py-3 min-w-[150px]">
 <div className="text-sm text-primary">{operation.requested_by_name ?? 'System Administrator'}</div>
 <Chip variant="neutral" className="mt-1">{operation.type === 'restore' ? 'Restore' : 'Full backup'}</Chip>
 </td>
 <td className="px-4 py-3 text-right whitespace-nowrap">
 {canRestoreOperation(operation) && (
 <Button variant="danger" size="sm" icon={<LuRotateCcw size={14} />} onClick={() => onRestore(operation)}>
 Restore
 </Button>
 )}
 </td>
 </tr>
 );
}

function BackupCard({
 operation,
 onRestore,
}: {
 operation: BackupOperation;
 onRestore: (operation: BackupOperation) => void;
}) {
 const database = operation.artifacts.database;
 const files = operation.artifacts.files;

 return (
 <article className="border-t border-default p-4 first:border-t-0">
 <div className="flex items-start justify-between gap-3">
 <div>
 <time dateTime={operation.created_at ?? undefined} className="text-sm text-primary">
 {formatDateTime(operation.created_at)}
 </time>
 <div className="text-xs text-muted mt-1">{formatRelative(operation.created_at)}</div>
 </div>
 <StatusBlock operation={operation} />
 </div>
 <div className="mt-4 space-y-2">
 {database ? <ArtifactLine artifact={database} icon={<LuDatabase size={14} />} label="PostgreSQL database" compact /> : (
 <div className="flex items-center gap-2 text-sm text-muted"><LuArchive size={14} /> No database artifact</div>
 )}
 {files && <ArtifactLine artifact={files} icon={<LuFolderOpen size={14} />} label="Private uploaded files" compact />}
 </div>
 <div className="flex items-end justify-between gap-3 mt-4 pt-3 border-t border-default">
 <div>
 <div className="text-xs text-muted">Requested by</div>
 <div className="text-sm text-primary mt-0.5">{operation.requested_by_name ?? 'System Administrator'}</div>
 <Chip variant="neutral" className="mt-1">{operation.type === 'restore' ? 'Restore' : 'Full backup'}</Chip>
 </div>
 {canRestoreOperation(operation) && (
 <Button variant="danger" size="sm" icon={<LuRotateCcw size={14} />} onClick={() => onRestore(operation)}>
 Restore
 </Button>
 )}
 </div>
 </article>
 );
}

export default function AdminBackupsPage() {
 const queryClient = useQueryClient();
 const [restoreTarget, setRestoreTarget] = useState<BackupOperation | null>(null);
 const [confirmation, setConfirmation] = useState('');

 const query = useInfiniteQuery({
 queryKey: ['admin', 'backups'],
 queryFn: ({ pageParam }) => backupsApi.index(pageParam),
 initialPageParam: null as string | null,
 getNextPageParam: (lastPage) => lastPage.next_cursor,
 // The first page carries the live posture (active operation, configuration);
 // later pages are older ledger history only.
 refetchInterval: (current) => current.state.data?.pages[0]?.active_operation ? 5_000 : 30_000,
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

 const firstPage = query.data?.pages[0];
 const backups = useMemo(
 () => query.data?.pages.flatMap((page) => page.backups) ?? EMPTY_BACKUPS,
 [query.data],
 );
 const verifiedBackups = useMemo(
 () => backups.filter((operation) => operation.type === 'backup' && operation.restorable === true),
 [backups],
 );
 const failedBackups = useMemo(() => backups.filter((operation) => ['failed', 'rollback_required'].includes(operation.status)), [backups]);
 const latestVerified = verifiedBackups[0] ?? null;
 const activeOperation = firstPage?.active_operation;
 const selectedDatabase = restoreTarget?.artifacts.database?.name ?? '';
 const expectedConfirmation = `RESTORE ${selectedDatabase}`;
 const confirmationInvalid = confirmation.length > 0 && confirmation !== expectedConfirmation;
 const canConfirm = confirmation === expectedConfirmation && !restore.isPending;

 const posture = query.isLoading
 ? { label: 'Checking posture', variant: 'neutral' as const, icon: <LuRefreshCw size={13} className="animate-spin" /> }
 : query.isError
 ? { label: 'Unavailable', variant: 'danger' as const, icon: <LuShieldAlert size={13} /> }
 : activeOperation?.status === 'rollback_required'
 ? { label: 'Rollback required', variant: 'danger' as const, icon: <LuShieldAlert size={13} /> }
 : activeOperation
 ? { label: activeOperation.type === 'restore' ? 'Restore in progress' : 'Backup in progress', variant: 'info' as const, icon: <LuRefreshCw size={13} className="animate-spin" /> }
 : latestVerified
 ? { label: 'Ready to recover', variant: 'success' as const, icon: <LuShieldCheck size={13} /> }
 : { label: 'Awaiting first snapshot', variant: 'warning' as const, icon: <LuShieldAlert size={13} /> };

 const openRestore = (operation: BackupOperation) => {
 setRestoreTarget(operation);
 setConfirmation('');
 };

 return (
 <div>
 <PageHeader
 title="Backup & Restore"
 subtitle="Validated database and private-file recovery artifacts"
 actions={
 <>
 <Button
 variant="secondary"
 icon={<LuRefreshCw size={14} className={query.isFetching ? 'animate-spin' : ''} />}
 iconOnly
 aria-label="Refresh backup history"
 title="Refresh backup history"
 onClick={() => void query.refetch()}
 loading={query.isFetching}
 />
 <Button
 variant="primary"
 icon={<LuArchive size={14} />}
 onClick={() => create.mutate()}
 loading={create.isPending}
 disabled={Boolean(activeOperation)}
 >
 {activeOperation ? 'Operation in progress' : 'Create full backup'}
 </Button>
 </>
 }
 />

 <div className="px-5 py-4 space-y-3">
 <section className="rounded-md border border-default bg-surface px-4 py-3" aria-label="Recovery posture">
 <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
 <div className="flex items-center gap-3 min-w-0">
 <span className="flex items-center justify-center w-8 h-8 rounded-full bg-success-bg text-success-fg shrink-0" aria-hidden><LuShieldCheck size={16} /></span>
 <div className="min-w-0">
 <div className="flex flex-wrap items-center gap-2 text-xs text-muted">
 <span>Recovery posture</span>
 <Chip variant={posture.variant} className="gap-1">{posture.icon}{posture.label}</Chip>
 </div>
 <div className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 mt-1">
 <span className="text-sm font-medium text-primary">{latestVerified ? 'Latest committed snapshot is restorable' : 'No restorable snapshot yet'}</span>
 <span className="text-xs text-muted">{latestVerified ? `${formatDateTime(latestVerified.completed_at ?? latestVerified.created_at)} · ${artifactSummary(latestVerified)}` : 'Create a full backup to establish a recovery point.'}</span>
 </div>
 </div>
 </div>
 <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted md:justify-end">
 <span className="inline-flex items-center gap-1.5"><LuDatabase size={13} /> Database + files</span>
 <span className="inline-flex items-center gap-1.5 text-success-fg"><LuShieldCheck size={13} /> Checksummed</span>
 <span className={`inline-flex items-center gap-1.5 ${firstPage?.configuration.offsite_configured ? 'text-success-fg' : 'text-warning-fg'}`}><LuCloudUpload size={13} /> {firstPage?.configuration.offsite_configured ? 'S3 configured' : 'S3 not configured'}</span>
 </div>
 </div>
 </section>

 <div className="flex flex-wrap items-center gap-x-5 gap-y-2 px-1 text-xs">
 <span className="inline-flex items-center gap-1.5 text-success-fg"><LuCheck size={14} /><strong className="text-primary">{firstPage ? formatInt(verifiedBackups.length) : '—'}</strong> restorable</span>
 <span className={`inline-flex items-center gap-1.5 ${failedBackups.length ? 'text-warning-fg' : 'text-muted'}`}><LuTriangleAlert size={14} /><strong className="text-primary">{firstPage ? formatInt(failedBackups.length) : '—'}</strong> need attention</span>
 <span className="text-muted">Newest first</span>
 </div>

 {activeOperation && (
 <div className={`flex items-start gap-3 px-3 py-3 rounded-md border text-sm ${activeOperation.status === 'rollback_required' ? 'border-danger bg-danger-bg text-danger-fg' : 'border-info bg-info-bg text-info-fg'}`} role={activeOperation.status === 'rollback_required' ? 'alert' : 'status'}>
 {activeOperation.status === 'rollback_required' ? <LuShieldAlert size={16} className="mt-0.5 shrink-0" aria-hidden /> : <LuRefreshCw size={16} className="mt-0.5 shrink-0 animate-spin" aria-hidden />}
 <div>
 <div className="font-medium">{activeOperation.status === 'rollback_required' ? 'Operator rollback is required' : activeOperation.type === 'restore' ? 'Restore is running' : 'Full backup is running'}</div>
 <div className="text-xs mt-0.5">{activeOperation.status === 'rollback_required' ? 'The maintenance gate remains active until the pre-restore snapshot is recovered with the rollback command.' : `This page refreshes automatically. ${activeOperation.type === 'restore' ? 'The application may enter maintenance mode while data is replaced.' : 'You can continue working while the snapshot is prepared.'}`}</div>
 </div>
 </div>
 )}

 {query.isError && <QueryErrorState subject="backup history" onRetry={() => void query.refetch()} />}
 {query.isLoading && <SkeletonTable columns={5} rows={6} />}
 {!query.isLoading && !query.isError && backups.length === 0 && (
 <Panel title="Backup history" meta="No snapshots yet">
 <EmptyState
 icon="inbox"
 title="Establish your first recovery point"
 description="Create a full backup to publish a validated database and private-file snapshot."
 action={<Button variant="primary" icon={<LuArchive size={14} />} onClick={() => create.mutate()} loading={create.isPending}>Create first backup</Button>}
 />
 </Panel>
 )}
 {backups.length > 0 && (
 <Panel
 title="Backup history"
 meta={`${formatInt(backups.length)} loaded${query.hasNextPage ? ' · more available' : ''}`}
 actions={<span className="text-xs text-muted">Newest first</span>}
 noPadding
 >
 <div className="hidden md:block overflow-x-auto">
 <table className="w-full text-sm">
 <caption className="sr-only">Backup and restore operation history</caption>
 <thead className="bg-subtle text-xs text-muted uppercase tracking-wider">
 <tr>
 <th scope="col" className="text-left px-4 py-3 font-medium">Created</th>
 <th scope="col" className="text-left px-4 py-3 font-medium">Artifacts</th>
 <th scope="col" className="text-left px-4 py-3 font-medium">Status</th>
 <th scope="col" className="text-left px-4 py-3 font-medium">Requested by</th>
 <th scope="col" className="text-right px-4 py-3 font-medium">Action</th>
 </tr>
 </thead>
 <tbody>
 {backups.map((operation) => (
 <BackupRow key={`${operation.id ?? 'legacy'}-${operation.created_at}`} operation={operation} onRestore={openRestore} />
 ))}
 </tbody>
 </table>
 </div>
 <div className="md:hidden">
 {backups.map((operation) => (
 <BackupCard key={`${operation.id ?? 'legacy'}-${operation.created_at}`} operation={operation} onRestore={openRestore} />
 ))}
 </div>
 {query.hasNextPage && (
 <div className="border-t border-default px-4 py-3 text-center">
 <Button
 variant="secondary"
 size="sm"
 onClick={() => void query.fetchNextPage()}
 loading={query.isFetchingNextPage}
 >
 Load older operations
 </Button>
 </div>
 )}
 </Panel>
 )}

 </div>

 <Modal
 isOpen={!!restoreTarget}
 onClose={() => { if (!restore.isPending) { setRestoreTarget(null); setConfirmation(''); } }}
 title="Restore committed snapshot"
 size="md"
 closeOnOverlayClick={!restore.isPending}
 >
 <div className="space-y-4">
 <div className="flex items-start gap-3 rounded-md border border-danger bg-danger-bg p-3 text-sm text-danger-fg" role="alert">
 <LuShieldAlert size={17} className="mt-0.5 shrink-0" aria-hidden />
 <div>
 <div className="font-medium">This action replaces live data</div>
 <div className="text-xs mt-1">The shared maintenance gate and recovery queue will pause while the committed snapshot is restored. A pre-restore snapshot is created first; a failed destructive step leaves the gate held for operator rollback.</div>
 </div>
 </div>
 <div className="rounded-md border border-default bg-subtle p-3 space-y-3">
 <div className="text-xs uppercase tracking-wider text-muted">Selected snapshot</div>
 {restoreTarget?.artifacts.database && <ArtifactLine artifact={restoreTarget.artifacts.database} icon={<LuDatabase size={14} />} label="PostgreSQL database" />}
 {restoreTarget?.artifacts.files && <ArtifactLine artifact={restoreTarget.artifacts.files} icon={<LuFolderOpen size={14} />} label="Private uploaded files" />}
 </div>
 <Input
 label="Confirmation phrase"
 helper={`Type ${expectedConfirmation} exactly to continue.`}
 error={confirmationInvalid ? 'The confirmation phrase does not match.' : undefined}
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
 backup_operation_id: restoreTarget.id ?? undefined,
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
