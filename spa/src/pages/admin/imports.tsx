import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Upload, Play, CheckCircle2, AlertCircle, Undo2 } from 'lucide-react';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import {
  importsApi,
  IMPORT_SCHEMAS,
  type ImportDryRunResult,
  type ImportError,
  type ImportBatch,
} from '@/api/admin/imports';
import { Button } from '@/components/ui/Button';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { Chip, type ChipVariant } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatDate } from '@/lib/formatDate';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

const batchStatusVariant = (s: string): ChipVariant =>
  s === 'committed' ? 'success' : 'neutral';

export default function ImportsPage() {
  const qc = useQueryClient();
  const [entity, setEntity] = useState<string>('coa');
  const [file, setFile] = useState<File | null>(null);
  const [dryRun, setDryRun] = useState<ImportDryRunResult | null>(null);
  const [commitErrors, setCommitErrors] = useState<ImportError[] | null>(null);
  const [rollbackTarget, setRollbackTarget] = useState<ImportBatch | null>(null);

  const entitiesQ = useQuery({ queryKey: ['imports', 'entities'], queryFn: importsApi.entities });
  const batchesQ = useQuery({
    queryKey: ['imports', 'batches'],
    queryFn: importsApi.batches,
    placeholderData: (prev) => prev,
  });

  const resetResults = () => { setDryRun(null); setCommitErrors(null); };

  const dryRunMut = useMutation({
    mutationFn: () => importsApi.dryRun(entity, file!),
    onSuccess: (r) => { setDryRun(r); setCommitErrors(null); },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Validation failed.'),
  });

  const commitMut = useMutation({
    mutationFn: () => importsApi.commit(entity, file!),
    onSuccess: (r) => {
      toast.success(r.message ?? `Imported ${r.data.imported} row(s).`);
      resetResults();
      setFile(null);
      qc.invalidateQueries({ queryKey: ['imports', 'batches'] });
    },
    onError: (e: AxiosError<{ message?: string; errors?: ImportError[] }>) => {
      if (e.response?.status === 422 && e.response.data?.errors) {
        setCommitErrors(e.response.data.errors);
        toast.error('Import rejected — nothing was imported.');
      } else {
        toast.error(e.response?.data?.message ?? 'Import failed.');
      }
    },
  });

  const rollbackMut = useMutation({
    mutationFn: (id: string) => importsApi.rollback(id),
    onSuccess: (r) => {
      toast.success(r.message ?? 'Batch rolled back.');
      setRollbackTarget(null);
      qc.invalidateQueries({ queryKey: ['imports', 'batches'] });
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      setRollbackTarget(null);
      toast.error(e.response?.data?.message ?? 'Rollback failed.');
    },
  });

  const entities = entitiesQ.data ?? ['coa', 'items', 'customers', 'vendors'];
  const schema = IMPORT_SCHEMAS[entity];
  const errorRows = commitErrors ?? dryRun?.errors ?? [];
  const isClean = dryRun !== null && dryRun.errors.length === 0;

  return (
    <div>
      <PageHeader
        title="Master-Data Import"
        subtitle="Load chart of accounts, items, customers, and vendors from CSV at go-live."
        breadcrumbs={[{ label: 'Administration' }, { label: 'Import' }]}
      />

      <div className="px-5 py-4 grid grid-cols-1 lg:grid-cols-[420px_1fr] gap-5">
        {/* Upload + validate */}
        <Panel title="Upload CSV">
          <div className="space-y-3">
            <Select
              label="Entity"
              value={entity}
              onChange={(e) => { setEntity(e.target.value); setFile(null); resetResults(); }}
            >
              {entities.map((k) => <option key={k} value={k}>{k}</option>)}
            </Select>

            {schema && (
              <div className="text-2xs text-muted rounded-md border border-default bg-subtle px-3 py-2">
                <div><span className="font-medium text-primary">Required:</span> <span className="font-mono">{schema.required.join(', ')}</span></div>
                {schema.optional.length > 0 && (
                  <div className="mt-0.5"><span className="font-medium">Optional:</span> <span className="font-mono">{schema.optional.join(', ')}</span></div>
                )}
              </div>
            )}

            <div>
              <label className="block text-xs font-medium text-primary mb-1">CSV file</label>
              <input
                type="file"
                accept=".csv,text/csv"
                onChange={(e) => { setFile(e.target.files?.[0] ?? null); resetResults(); }}
                className="block w-full text-xs text-muted file:mr-3 file:py-1 file:px-3 file:rounded file:border file:border-default file:text-xs file:bg-elevated file:text-primary hover:file:bg-strong"
              />
            </div>

            <div className="flex gap-2 pt-1">
              <Button variant="secondary" size="sm" icon={<Play size={14} />}
                onClick={() => dryRunMut.mutate()}
                disabled={!file || dryRunMut.isPending} loading={dryRunMut.isPending}>
                Validate
              </Button>
              <Button variant="primary" size="sm" icon={<Upload size={14} />}
                onClick={() => commitMut.mutate()}
                disabled={!file || commitMut.isPending} loading={commitMut.isPending}>
                Import
              </Button>
            </div>

            {isClean && (
              <div className="flex items-center gap-2 px-3 py-2 rounded-md bg-success-bg text-success-fg text-xs">
                <CheckCircle2 size={14} />
                {dryRun!.valid} row(s) valid, 0 errors — ready to import.
              </div>
            )}
            {dryRun && dryRun.errors.length > 0 && !commitErrors && (
              <div className="flex items-center gap-2 px-3 py-2 rounded-md bg-warning-bg text-warning-fg text-xs">
                <AlertCircle size={14} />
                {dryRun.valid} valid, {dryRun.errors.length} error(s) — fix before importing.
              </div>
            )}
            {commitErrors && (
              <div className="flex items-center gap-2 px-3 py-2 rounded-md bg-danger-bg text-danger-fg text-xs">
                <AlertCircle size={14} />
                Import rejected — nothing was imported. Fix the {commitErrors.length} row(s) below.
              </div>
            )}
          </div>
        </Panel>

        {/* Error rows (from dry-run or a rejected commit) */}
        <div>
          {errorRows.length > 0 ? (
            <Panel title={`Row errors (${errorRows.length})`}>
              <div className="border border-default rounded-md overflow-hidden">
                <table className={tableCls}>
                  <thead>
                    <tr className={theadTrCls}>
                      <Th className="w-20">Row</Th>
                      <Th>Message</Th>
                    </tr>
                  </thead>
                  <tbody>
                    {errorRows.map((e, i) => (
                      <tr key={i} className={trCls}>
                        <Td mono>{e.row}</Td>
                        <Td className="text-danger-fg">{e.message}</Td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Panel>
          ) : (
            <Panel title="How it works">
              <ol className="text-xs text-muted space-y-1.5 list-decimal pl-4">
                <li>Pick the entity and upload its CSV.</li>
                <li><span className="text-primary font-medium">Validate</span> runs a dry-run — no data is written; any bad rows are listed here.</li>
                <li><span className="text-primary font-medium">Import</span> is all-or-nothing: if any row is invalid, nothing is imported.</li>
                <li>A committed batch can be rolled back below (unless its records are already in use).</li>
              </ol>
            </Panel>
          )}
        </div>
      </div>

      {/* Batch history */}
      <div className="px-5 pb-6">
        <Panel title="Import history">
          {batchesQ.isLoading && !batchesQ.data && <SkeletonTable columns={7} rows={5} />}
          {batchesQ.isError && (
            <EmptyState icon="alert-circle" title="Failed to load import history"
              action={<Button variant="secondary" onClick={() => batchesQ.refetch()}>Retry</Button>} />
          )}
          {batchesQ.data && batchesQ.data.length === 0 && (
            <EmptyState icon="inbox" title="No imports yet" description="Uploaded batches appear here." />
          )}
          {batchesQ.data && batchesQ.data.length > 0 && (
            <div className="border border-default rounded-md overflow-hidden">
              <table className={tableCls}>
                <thead>
                  <tr className={theadTrCls}>
                    <Th>Entity</Th>
                    <Th>File</Th>
                    <Th>Status</Th>
                    <Th align="right">Imported</Th>
                    <Th>By</Th>
                    <Th>When</Th>
                    <Th />
                  </tr>
                </thead>
                <tbody>
                  {batchesQ.data.map((b) => (
                    <tr key={b.id} className={trCls}>
                      <Td mono>{b.entity_type}</Td>
                      <Td className="text-muted truncate max-w-[180px]">{b.filename ?? '—'}</Td>
                      <Td><Chip variant={batchStatusVariant(b.status)}>{b.status}</Chip></Td>
                      <Td align="right" mono>{b.imported_rows}/{b.total_rows}</Td>
                      <Td className="text-muted">{b.created_by ?? '—'}</Td>
                      <Td mono className="text-muted text-xs">{b.created_at ? formatDate(b.created_at) : '—'}</Td>
                      <Td align="right">
                        {b.status === 'committed' && (
                          <button
                            onClick={() => setRollbackTarget(b)}
                            className="inline-flex items-center gap-1 text-xs text-muted hover:text-danger-fg"
                          >
                            <Undo2 size={13} /> Roll back
                          </button>
                        )}
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Panel>
      </div>

      <ConfirmDialog
        isOpen={rollbackTarget !== null}
        onClose={() => setRollbackTarget(null)}
        onConfirm={() => { if (rollbackTarget) rollbackMut.mutate(rollbackTarget.id); }}
        title="Roll back this import?"
        description={`This deletes the ${rollbackTarget?.imported_rows ?? ''} record(s) this batch created. It fails if any are already referenced by other data.`}
        variant="danger"
        confirmLabel="Roll back"
        pending={rollbackMut.isPending}
      />
    </div>
  );
}
