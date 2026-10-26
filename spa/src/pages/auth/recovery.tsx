import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AxiosError } from 'axios';
import { LuDatabase, LuFolderOpen, LuLock, LuRefreshCw, LuRotateCcw, LuShieldAlert, LuShieldCheck } from '@/lib/icons';
import type { BackupOperation } from '@/api/admin/backups';
import { recoveryApi } from '@/api/recovery';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { Input } from '@/components/ui/Input';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatDateTime, formatRelative } from '@/lib/formatDate';
import { formatInt } from '@/lib/formatNumber';

function errorMessage(error: unknown): string {
 const axiosError = error as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
 return axiosError.response?.data?.message
  ?? Object.values(axiosError.response?.data?.errors ?? {})[0]?.[0]
  ?? 'Recovery request failed. Try again.';
}

function restorable(backup: BackupOperation): boolean {
 return backup.type === 'backup'
  && backup.id !== null
  && backup.manifest_committed === true
  && backup.restorable === true
  && backup.status === 'completed'
  && backup.artifacts.database !== null;
}

export default function RecoveryPage() {
 const queryClient = useQueryClient();
 const [secret, setSecret] = useState('');
 const [token, setToken] = useState('');
 const [loginError, setLoginError] = useState('');
 const [restoreTarget, setRestoreTarget] = useState<BackupOperation | null>(null);
 const [confirmation, setConfirmation] = useState('');
 const [operationId, setOperationId] = useState(() => sessionStorage.getItem('ogami.recovery.operation_id') ?? '');
 const [actionError, setActionError] = useState('');

 const catalog = useQuery({
  queryKey: ['recovery', 'backups', token],
  queryFn: () => recoveryApi.index(token),
  enabled: Boolean(token),
  retry: false,
 });
 const operation = useQuery({
  queryKey: ['recovery', 'operation', token, operationId],
  queryFn: () => recoveryApi.operation(token, operationId),
  enabled: Boolean(token && operationId),
  retry: false,
  refetchInterval: (current) => current.state.error !== null || ['completed', 'failed', 'rollback_required', 'rolled_back'].includes(current.state.data?.status ?? '') ? false : 2000,
 });

 const signIn = useMutation({
  mutationFn: recoveryApi.login,
  onSuccess: (result) => {
   setToken(result.access_token);
   setSecret('');
   setLoginError('');
  },
  onError: (error) => setLoginError(errorMessage(error)),
 });
 const verify = useMutation({
  mutationFn: (filename: string) => recoveryApi.verify(token, filename),
  onSuccess: async () => {
   setActionError('');
   await catalog.refetch();
  },
  onError: (error) => setActionError(errorMessage(error)),
 });
 const restore = useMutation({
  mutationFn: (backup: BackupOperation) => {
   const database = backup.artifacts.database;
   if (!database || !backup.id) throw new Error('Selected backup is unavailable.');
   return recoveryApi.restore(token, {
    manifest_id: backup.id,
    database_filename: database.name,
    files_filename: backup.artifacts.files?.name,
    confirmation,
   });
  },
  onSuccess: async (result) => {
   setOperationId(result.data.id);
   sessionStorage.setItem('ogami.recovery.operation_id', result.data.id);
   setRestoreTarget(null);
   setConfirmation('');
   setActionError('');
   await queryClient.invalidateQueries({ queryKey: ['recovery'] });
  },
  onError: (error) => setActionError(errorMessage(error)),
 });
 const rollback = useMutation({
  mutationFn: () => recoveryApi.rollback(token, operationId),
  onSuccess: async () => {
   setActionError('');
   await operation.refetch();
  },
  onError: (error) => setActionError(errorMessage(error)),
 });

 useEffect(() => {
  if (operation.data?.status === 'completed') {
   void queryClient.invalidateQueries({ queryKey: ['recovery', 'backups'] });
  }
 }, [operation.data?.status, queryClient]);

 const backups = catalog.data?.backups ?? [];
 const selectedName = restoreTarget?.artifacts.database?.name ?? '';
 const expectedConfirmation = `RESTORE ${selectedName}`;
 const canRestore = Boolean(restoreTarget && confirmation === expectedConfirmation && !restore.isPending);
 const status = operation.data;

 return (
  <main className="min-h-screen bg-canvas text-primary">
   <div className="mx-auto max-w-4xl">
    <PageHeader
     title="Database recovery"
     subtitle="Restore a verified system snapshot without an ERP account"
     actions={<Link to="/sign-in" className="text-sm text-accent underline-offset-2 hover:underline">Return to sign in</Link>}
    />
    <div className="space-y-4 px-5 py-4">
     <section className="flex items-start gap-3 border-b border-default px-1 pb-4 text-sm" aria-label="Recovery access scope">
      <LuLock size={16} className="mt-0.5 shrink-0 text-accent" aria-hidden />
      <p className="text-muted">This access can list and restore backups only. It does not grant access to ERP records or settings.</p>
     </section>

     {!token ? (
      <Panel title="Recovery access" meta="Separate from ERP sign-in">
       <form className="max-w-md space-y-3" onSubmit={(event) => { event.preventDefault(); signIn.mutate(secret); }}>
        <Input
         label="Recovery secret"
         type="password"
         value={secret}
         onChange={(event) => setSecret(event.target.value)}
         autoComplete="off"
         spellCheck={false}
         required
        />
        {loginError && <p className="text-sm text-danger-fg" role="alert">{loginError}</p>}
        <Button type="submit" variant="primary" loading={signIn.isPending} disabled={!secret || signIn.isPending}>
         Open recovery
        </Button>
       </form>
      </Panel>
     ) : (
      <>
       <div className="flex flex-wrap items-center justify-between gap-3 border-b border-default px-1 pb-3">
        <div className="flex items-center gap-2 text-sm text-success-fg" role="status">
         <LuShieldCheck size={15} aria-hidden />
         Recovery access active for 30 minutes
        </div>
        <Button variant="secondary" size="sm" onClick={() => {
         queryClient.removeQueries({ queryKey: ['recovery'] });
         setToken('');
         setRestoreTarget(null);
         setActionError('');
        }}>
         Lock recovery
        </Button>
       </div>

       {actionError && <p className="text-sm text-danger-fg" role="alert">{actionError}</p>}
       {catalog.isError && <p className="text-sm text-danger-fg" role="alert">{errorMessage(catalog.error)}</p>}
       {operation.isError && <p className="text-sm text-danger-fg" role="alert">{errorMessage(operation.error)} Lock and reopen recovery to renew access.</p>}
       {catalog.isLoading && <p className="text-sm text-muted">Checking local backup archives…</p>}

       {status && (
        <section className={`flex items-start gap-3 rounded-md border p-3 text-sm ${status.status === 'rollback_required' ? 'border-danger bg-danger-bg text-danger-fg' : 'border-default bg-surface text-primary'}`} role={status.status === 'rollback_required' ? 'alert' : 'status'}>
         {status.status === 'rollback_required' ? <LuShieldAlert size={16} className="mt-0.5 shrink-0" aria-hidden /> : <LuRefreshCw size={16} className="mt-0.5 shrink-0" aria-hidden />}
         <div className="min-w-0 flex-1">
          <div className="font-medium">Restore {status.status.replaceAll('_', ' ')}</div>
          <div className="mt-1 text-xs text-muted">{status.phase ?? 'Waiting for restore worker'}{status.error_message ? ` · ${status.error_message}` : ''}</div>
         </div>
         {status.status === 'rollback_required' && status.rollback_available && (
          <Button variant="danger" size="sm" icon={<LuRotateCcw size={14} />} loading={rollback.isPending} onClick={() => rollback.mutate()}>
           Restore rollback copy
          </Button>
         )}
         {status.status === 'rollback_required' && !status.rollback_available && (
          <p className="mt-1 text-xs text-danger-fg">No rollback copy exists. Retry with another verified backup or restore through the database host.</p>
         )}
        </section>
       )}

       {catalog.isSuccess && backups.length === 0 && (
        <Panel title="System backups" meta="No local archives found">
         <p className="text-sm text-muted">No database backup files are available in the configured backup directory.</p>
        </Panel>
       )}

       {backups.length > 0 && (
        <Panel title="System backups" meta={`${formatInt(backups.length)} archive${backups.length === 1 ? '' : 's'} · newest first`} noPadding>
         <div className="divide-y divide-default">
          {backups.map((backup) => {
           const database = backup.artifacts.database;
           const files = backup.artifacts.files;
           return (
            <article key={`${backup.id ?? 'legacy'}-${backup.created_at}`} className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
             <div className="min-w-0 space-y-1">
              <div className="flex flex-wrap items-center gap-2">
               <time dateTime={backup.created_at ?? undefined} className="text-sm font-medium text-primary">{formatDateTime(backup.created_at)}</time>
               <span className="text-xs text-muted">{formatRelative(backup.created_at)}</span>
               <Chip variant={restorable(backup) ? 'success' : 'neutral'}>{restorable(backup) ? 'Ready to restore' : 'Needs verification'}</Chip>
              </div>
              {database && <div className="flex items-center gap-2 break-all text-xs text-muted"><LuDatabase size={13} aria-hidden /><span>{database.name}</span></div>}
              {files && <div className="flex items-center gap-2 break-all text-xs text-muted"><LuFolderOpen size={13} aria-hidden /><span>{files.name}</span></div>}
              {database?.sha256 && <div className="text-[11px] text-muted">SHA-256 recorded · {database.size.toLocaleString()} bytes</div>}
             </div>
             <div className="shrink-0">
              {restorable(backup) ? (
               <Button variant="danger" size="sm" icon={<LuRotateCcw size={14} />} onClick={() => { setRestoreTarget(backup); setConfirmation(''); }}>
                Restore
               </Button>
              ) : database?.availability === 'local' ? (
               <Button variant="secondary" size="sm" loading={verify.isPending} onClick={() => verify.mutate(database.name)}>
                Verify archive
               </Button>
              ) : <span className="text-xs text-muted">Unavailable</span>}
             </div>
            </article>
           );
          })}
         </div>
        </Panel>
       )}
      </>
     )}
    </div>

    <Modal
     isOpen={Boolean(restoreTarget)}
     onClose={() => { if (!restore.isPending) { setRestoreTarget(null); setConfirmation(''); } }}
     title="Restore this snapshot?"
     size="md"
     closeOnOverlayClick={!restore.isPending}
    >
       <div className="space-y-3">
       <p className="mt-2 text-sm text-danger-fg">This replaces the current database{restoreTarget?.artifacts.files ? ' and private files' : ''}. A rollback copy is made if the target database exists and is readable. If it is missing, restore has no automatic rollback. Normal work pauses during restore.</p>
       <p className="mt-3 break-all font-mono text-xs text-muted">{selectedName}</p>
        <Input
         label="Confirmation phrase"
         helper={`Type ${expectedConfirmation} exactly.`}
         value={confirmation}
         onChange={(event) => setConfirmation(event.target.value)}
         autoComplete="off"
         spellCheck={false}
        />
       </div>
       <ModalFooter>
        <Button variant="secondary" onClick={() => { setRestoreTarget(null); setConfirmation(''); }} disabled={restore.isPending}>Cancel</Button>
        <Button variant="danger" icon={<LuRotateCcw size={14} />} loading={restore.isPending} disabled={!canRestore} onClick={() => { if (restoreTarget) restore.mutate(restoreTarget); }}>
         Start restore
        </Button>
       </ModalFooter>
    </Modal>
   </div>
  </main>
 );
}
