import { useEffect, useMemo, useRef, useState, type ChangeEvent } from 'react';
import { Link, useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { LuArrowLeft, LuCheck, LuFileText, LuSearch, LuX } from '@/lib/icons';
import { returnCasesApi } from '@/api/returnCases';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { Textarea } from '@/components/ui/Textarea';
import { usePermission } from '@/hooks/usePermission';
import type {
  CreateReturnCasePayload,
  ReturnCaseLineSubmission,
  ReturnCasePreferredResolution,
  ReturnCaseSourceKind,
  ReturnCaseSourceLineOption,
  ReturnCaseSourceResult,
} from '@/types/returnCases';
import { apiFieldErrors, apiMessage, casePath, createRequestKey, evidenceFileError, fromMilli, realmFromPath, toMilli } from './shared';

type LineDraft = {
  selected: boolean;
  expected: string;
  received: string;
  defective: string;
  lot: string;
  serial: string;
  reason: string;
};

const PREFERRED_OPTIONS: Array<{ value: ReturnCasePreferredResolution; label: string }> = [
  { value: 'redelivery', label: 'Send the missing or replacement goods' },
  { value: 'credit', label: 'Credit' },
  { value: 'advice', label: 'Help me decide' },
];

function sourceKindLabel(kind: ReturnCaseSourceKind): string {
  return kind === 'delivery' ? 'Delivery' : kind === 'grn' ? 'Goods receipt' : 'Purchase order';
}

function calcShortage(expected: string, received: string): string {
  const expectedValue = toMilli(expected);
  const receivedValue = toMilli(received);
  if (expectedValue === null || receivedValue === null || expectedValue <= receivedValue) return '0.000';
  return fromMilli(expectedValue - receivedValue);
}

function calcGood(received: string, defective: string): string {
  const receivedValue = toMilli(received);
  const defectiveValue = toMilli(defective);
  if (receivedValue === null || defectiveValue === null || defectiveValue > receivedValue) return '—';
  return fromMilli(receivedValue - defectiveValue);
}

export default function ReturnCaseCreatePage() {
  const location = useLocation();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const realm = realmFromPath(location.pathname);
  const { can } = usePermission();
  const initialKind = searchParams.get('source_kind') as ReturnCaseSourceKind | null;
  const initialId = searchParams.get('source_id');
  const initialType = realm === 'internal' ? (initialKind === 'delivery' ? 'customer' : 'supplier') : realm;

  const [type, setType] = useState<'customer' | 'supplier'>(initialType);
  const [search, setSearch] = useState('');
  const [selectedSource, setSelectedSource] = useState<ReturnCaseSourceResult | null>(() =>
    initialKind && initialId
      ? { id: initialId, kind: initialKind, label: 'Loading source…', party_name: null }
      : null,
  );
  const [drafts, setDrafts] = useState<Record<string, LineDraft>>({});
  const [description, setDescription] = useState('');
  const [preferredResolution, setPreferredResolution] = useState<ReturnCasePreferredResolution>('advice');
  const [files, setFiles] = useState<File[]>([]);
  const [clientErrors, setClientErrors] = useState<Record<string, string>>({});
  const [serverErrors, setServerErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState('');
  const requestKey = useRef(createRequestKey());

  const sourceSearch = useQuery({
    queryKey: ['return-cases', realm, 'sources', type, search],
    queryFn: () => returnCasesApi.findSources(realm, type, search.trim()),
    enabled: search.trim().length >= 2,
    staleTime: 15_000,
  });
  const sourceQuery = useQuery({
    queryKey: ['return-cases', realm, 'source-options', selectedSource?.kind, selectedSource?.id],
    queryFn: () => returnCasesApi.sourceOptions(realm, selectedSource!.kind, selectedSource!.id),
    enabled: !!selectedSource,
  });

  useEffect(() => {
    if (!sourceQuery.data || !selectedSource) return;
    setSelectedSource((current) => current && current.label === 'Loading source…'
      ? { ...current, label: sourceQuery.data.source.label }
      : current);
    setType(sourceQuery.data.type);
  }, [sourceQuery.data, selectedSource]);

  const sourceLines = useMemo(() => sourceQuery.data?.lines ?? [], [sourceQuery.data?.lines]);
  useEffect(() => {
    if (!sourceLines.length) return;
    setDrafts((current) => {
      const next = { ...current };
      for (const line of sourceLines) {
        if (!next[line.id]) {
          next[line.id] = {
            selected: false,
            expected: line.expected_quantity,
            received: line.received_quantity,
            defective: '0',
            lot: line.lot_number ?? '',
            serial: '',
            reason: '',
          };
        }
      }
      return next;
    });
  }, [sourceLines]);

  const canCreate = realm === 'customer' || (realm === 'internal' && can('return_management.manage'));
  const affectedLines = useMemo(() => sourceLines.filter((line) => drafts[line.id]?.selected), [sourceLines, drafts]);
  const selectedHasIssue = affectedLines.some((line) => {
    const draft = drafts[line.id];
    const shortage = calcShortage(draft.expected, draft.received);
    return shortage !== '0.000' || (toMilli(draft.defective) ?? 0n) > 0n;
  });

  const createMutation = useMutation({
    mutationFn: (payload: CreateReturnCasePayload) => returnCasesApi.create(realm, payload),
    onSuccess: async (record) => {
      toast.success(`Report ${record.case_number} submitted.`);
      const failedFiles: File[] = [];
      for (let index = 0; index < files.length; index += 1) {
        try {
          await returnCasesApi.upload(realm, record.id, files[index]);
        } catch {
          failedFiles.push(...files.slice(index));
          break;
        }
      }
      if (failedFiles.length > 0) {
        toast.error('The report was saved. Some evidence still needs uploading.', { id: `return-case-evidence-${record.id}` });
      }
      navigate(casePath(realm, record.id), { state: failedFiles.length ? { pendingFiles: failedFiles } : undefined });
    },
    onError: (error) => {
      setServerErrors(apiFieldErrors(error));
      setFormError(apiMessage(error, 'The report was not submitted. Your entries are still here; check the marked fields and retry.'));
    },
  });

  const patchLine = (line: ReturnCaseSourceLineOption, patch: Partial<LineDraft>) => {
    setDrafts((current) => ({ ...current, [line.id]: { ...current[line.id], ...patch } }));
    setClientErrors((current) => {
      const next = { ...current };
      delete next[`${line.id}.expected`];
      delete next[`${line.id}.received`];
      delete next[`${line.id}.defective`];
      return next;
    });
    setServerErrors({});
  };

  const addFiles = (event: ChangeEvent<HTMLInputElement>) => {
    const selected = Array.from(event.target.files ?? []).filter((file) => {
      const error = evidenceFileError(file);
      if (error) toast.error(error);
      return !error;
    });
    setFiles((current) => [...current, ...selected]);
    event.target.value = '';
  };

  const validateAndSubmit = () => {
    const errors: Record<string, string> = {};
    const prepared: ReturnCaseLineSubmission[] = [];

    if (!selectedSource || !sourceQuery.data) errors.source = 'Choose a source document first.';
    if (description.trim().length === 0) errors.description = 'Describe what happened.';
    if (description.trim().length > 2000) errors.description = 'Use 2,000 characters or fewer.';
    if (!selectedHasIssue) errors.lines = 'Select at least one quantity problem or damaged quantity.';

    for (const line of affectedLines) {
      const draft = drafts[line.id];
      const expected = toMilli(draft.expected);
      const received = toMilli(draft.received);
      const defective = toMilli(draft.defective);
      if (expected === null || expected <= 0n || expected > (toMilli(line.maximum_quantity) ?? 0n)) {
        errors[`${line.id}.expected`] = `Enter a positive quantity up to ${line.maximum_quantity} ${line.unit ?? ''}.`;
      }
      if (received === null || received > (expected ?? 0n)) {
        errors[`${line.id}.received`] = 'Received quantity must be zero or more and cannot exceed expected.';
      }
      if (defective === null || defective > (received ?? 0n)) {
        errors[`${line.id}.defective`] = 'Damaged quantity must be part of what arrived.';
      }
      if (expected === null || received === null || defective === null || errors[`${line.id}.expected`] || errors[`${line.id}.received`] || errors[`${line.id}.defective`]) {
        continue;
      }
      if (expected <= received && defective === 0n) {
        errors[`${line.id}.defective`] = 'Enter a shortage or damaged quantity for this line.';
        continue;
      }
      prepared.push({
        source_line_id: line.id,
        ...(type === 'supplier'
          ? { expected_quantity: fromMilli(expected) }
          : {}),
        received_quantity: fromMilli(received),
        defective_quantity: fromMilli(defective),
        lot_number: draft.lot.trim() || undefined,
        serial_number: draft.serial.trim() || undefined,
        reason: draft.reason.trim() || undefined,
      });
    }

    setClientErrors(errors);
    setFormError('');
    if (Object.keys(errors).length > 0 || !selectedSource) return;

    createMutation.mutate({
      source_kind: selectedSource.kind,
      source_id: selectedSource.id,
      description: description.trim(),
      preferred_resolution: preferredResolution,
      request_key: requestKey.current,
      lines: prepared,
    });
  };

  if (!canCreate) {
    return <EmptyState icon="lock" title="You cannot create problem reports" description="Ask a Return Management manager for access." />;
  }

  return (
    <div>
      <PageHeader
        title="Report a problem"
        subtitle="Record the quantities that arrived and the issue you found."
        backTo={casePath(realm)}
        backLabel="Problem reports"
        actions={<Button variant="ghost" size="sm" icon={<LuArrowLeft size={14} />} onClick={() => navigate(casePath(realm))}>Back</Button>}
      />

      <div className="px-5 py-4">
        {sourceQuery.isLoading && selectedSource && <SkeletonDetail />}
        {sourceQuery.isError && selectedSource && (
          <EmptyState
            icon="alert-circle"
            title="Could not load this source document"
            action={<Button variant="secondary" onClick={() => sourceQuery.refetch()}>Retry</Button>}
          />
        )}

        <div className="mx-auto grid max-w-6xl grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_300px]">
          <main className="space-y-4">
            {!selectedSource && (
              <Panel title="Choose the source document" meta="Start with the shipment or receipt">
                {realm === 'internal' && (
                  <div className="mb-3 flex flex-wrap gap-2" role="group" aria-label="Report type">
                    {(['customer', 'supplier'] as const).map((value) => (
                      <Button key={value} size="sm" variant={type === value ? 'primary' : 'secondary'} onClick={() => { setType(value); setSearch(''); }}>
                        {value === 'customer' ? 'Customer delivery' : 'Supplier receipt or order'}
                      </Button>
                    ))}
                  </div>
                )}
                <Input
                  label="Search by document number"
                  value={search}
                  onChange={(event) => setSearch(event.target.value)}
                  placeholder={type === 'customer' ? 'Delivery number…' : 'GRN or purchase order number…'}
                  prefix={<LuSearch size={15} />}
                  error={clientErrors.source}
                />
                {search.trim().length > 0 && search.trim().length < 2 && (
                  <p className="mt-2 text-xs text-muted">Enter at least two characters to search.</p>
                )}
                {sourceSearch.isFetching && <p className="mt-3 text-xs text-muted">Searching source documents…</p>}
                {sourceSearch.isError && <p role="alert" className="mt-3 text-xs text-danger-fg">Could not search documents. Try the number again.</p>}
                {sourceSearch.data && sourceSearch.data.length > 0 && (
                  <div className="mt-3 divide-y divide-subtle rounded-md border border-default">
                    {sourceSearch.data.map((source) => (
                      <button
                        key={`${source.kind}:${source.id}`}
                        type="button"
                        onClick={() => { setSelectedSource(source); setClientErrors({}); setServerErrors({}); }}
                        className="flex min-h-hit w-full items-center justify-between gap-3 px-3 py-2.5 text-left hover:bg-elevated focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                      >
                        <span>
                          <span className="block font-mono text-sm text-primary">{source.label}</span>
                          <span className="block text-xs text-muted">{sourceKindLabel(source.kind)}{source.party_name ? ` · ${source.party_name}` : ''}</span>
                        </span>
                        <LuCheck size={16} className="text-accent" aria-hidden />
                      </button>
                    ))}
                  </div>
                )}
                {sourceSearch.data?.length === 0 && search.trim().length >= 2 && (
                  <p className="mt-3 text-xs text-muted">No matching documents. Check the number or choose another report type.</p>
                )}
              </Panel>
            )}

            {selectedSource && sourceQuery.data && (
              <Panel
                title="Affected goods"
                meta={`${sourceQuery.data.source.label} · ${sourceQuery.data.party?.name ?? 'Party unavailable'}`}
                actions={<Button size="xs" variant="ghost" icon={<LuX size={14} />} onClick={() => { setSelectedSource(null); setDrafts({}); }}>Change source</Button>}
              >
                <p className="mb-4 text-sm text-secondary">
                  Select each affected line. Missing quantity is calculated from what was expected and what arrived; damaged goods are counted among received goods.
                </p>
                {sourceLines.length === 0 && <p className="text-sm text-muted">This source has no reportable lines.</p>}
                <div className="space-y-3">
                  {sourceLines.map((line) => {
                    const draft = drafts[line.id] ?? {
                      selected: false, expected: line.expected_quantity, received: line.received_quantity,
                      defective: '0', lot: line.lot_number ?? '', serial: '', reason: '',
                    };
                    const shortage = calcShortage(draft.expected, draft.received);
                    const good = calcGood(draft.received, draft.defective);
                    const isPoSource = sourceQuery.data.source.kind === 'purchase_order';
                    const receivedEditable = sourceQuery.data.type === 'customer';
                    const expectedEditable = sourceQuery.data.type === 'supplier';
                    const submissionIndex = affectedLines.findIndex((selected) => selected.id === line.id);
                    const serverLineError = (field: 'expected_quantity' | 'received_quantity' | 'defective_quantity') =>
                      serverErrors[`lines.${submissionIndex}.${field}`];

                    return (
                      <section key={line.id} className={`rounded-md border ${draft.selected ? 'border-accent bg-accent/5' : 'border-default'} p-3`}>
                        <label className="flex cursor-pointer items-start gap-2.5">
                          <input
                            type="checkbox"
                            checked={draft.selected}
                            onChange={(event) => patchLine(line, { selected: event.target.checked })}
                            className="mt-1 h-4 w-4 accent-[var(--accent)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                          />
                          <span className="min-w-0 flex-1">
                            <span className="flex flex-wrap items-center gap-2 text-sm font-medium text-primary">
                              {line.part_number && <span className="font-mono">{line.part_number}</span>}
                              <span>{line.description}</span>
                              {realm === 'internal' && !line.can_return && <Chip variant="neutral">Return handling needs review</Chip>}
                            </span>
                            <span className="mt-0.5 block text-xs text-muted">{line.expected_quantity} {line.unit ?? 'units'} expected · {line.received_quantity} received</span>
                          </span>
                        </label>

                        {draft.selected && (
                          <div className="mt-3 pl-6">
                            <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
                              <Input
                                label={sourceQuery.data.type === 'supplier' ? 'Expected for this shipment' : 'Expected'}
                                type="number" min="0" step="0.001" inputMode="decimal"
                                value={draft.expected}
                                disabled={!expectedEditable}
                                onChange={(event) => patchLine(line, { expected: event.target.value })}
                                suffix={line.unit ?? undefined}
                                error={clientErrors[`${line.id}.expected`] || serverLineError('expected_quantity')}
                              />
                              <Input
                                label="Actually received"
                                type="number" min="0" step="0.001" inputMode="decimal"
                                value={draft.received}
                                disabled={!receivedEditable}
                                onChange={(event) => patchLine(line, { received: event.target.value })}
                                suffix={line.unit ?? undefined}
                                error={clientErrors[`${line.id}.received`] || serverLineError('received_quantity')}
                              />
                              <Input
                                label="Damaged or defective"
                                type="number" min="0" step="0.001" inputMode="decimal"
                                value={draft.defective}
                                disabled={!receivedEditable && isPoSource}
                                onChange={(event) => patchLine(line, { defective: event.target.value })}
                                suffix={line.unit ?? undefined}
                                error={clientErrors[`${line.id}.defective`] || serverLineError('defective_quantity')}
                              />
                              <div className="grid grid-cols-2 gap-2 rounded-md bg-subtle px-3 py-2">
                                <div><span className="block text-2xs text-muted">Missing</span><span className="font-mono text-sm tabular-nums text-primary">{shortage} {line.unit}</span></div>
                                <div><span className="block text-2xs text-muted">Good</span><span className="font-mono text-sm tabular-nums text-primary">{good} {line.unit}</span></div>
                              </div>
                            </div>
                            {sourceQuery.data.type === 'supplier' && sourceQuery.data.source.kind === 'purchase_order' && (
                              <p className="mt-2 text-xs text-muted">Use the promised quantity for this shipment. Record goods that arrived against the GRN instead.</p>
                            )}
                            <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                              <Input label="Lot number" value={draft.lot} onChange={(event) => patchLine(line, { lot: event.target.value })} maxLength={120} />
                              <Input label="Serial number" value={draft.serial} onChange={(event) => patchLine(line, { serial: event.target.value })} maxLength={120} />
                              <div className="sm:col-span-2">
                                <Input label="Line details (optional)" value={draft.reason} onChange={(event) => patchLine(line, { reason: event.target.value })} maxLength={1000} />
                              </div>
                            </div>
                          </div>
                        )}
                      </section>
                    );
                  })}
                </div>
                {clientErrors.lines && <p role="alert" className="mt-3 text-xs text-danger-fg">{clientErrors.lines}</p>}
              </Panel>
            )}

            <Panel title="What happened?" meta="One report can include several affected lines">
              <Textarea
                label="Explain the problem"
                required
                maxLength={2000}
                value={description}
                onChange={(event) => { setDescription(event.target.value); setServerErrors({}); }}
                placeholder="Tell us what you found and when you noticed it."
                error={clientErrors.description || serverErrors.description}
                rows={4}
              />
              <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <label className="flex flex-col gap-1 text-xs text-muted font-medium">
                  Preferred outcome
                  <select value={preferredResolution} onChange={(event) => setPreferredResolution(event.target.value as ReturnCasePreferredResolution)} className="h-9 rounded-md border border-default bg-canvas px-2 text-sm text-primary focus:outline-none focus:ring-[3px] focus:ring-accent/20 focus:border-accent">
                    {PREFERRED_OPTIONS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                  </select>
                </label>
                <div>
                  <label className="flex min-h-9 cursor-pointer items-center gap-2 rounded-md border border-default px-3 text-sm text-primary hover:bg-elevated focus-within:ring-2 focus-within:ring-accent">
                    <LuFileText size={16} className="text-muted" />
                    <span>Add photos or documents <span className="text-muted">(optional)</span></span>
                    <input type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" multiple className="sr-only" onChange={addFiles} />
                  </label>
                  <p className="mt-1 text-xs text-muted">JPG, PNG, WebP or PDF, up to 10 MB each.</p>
                  {files.length > 0 && <ul className="mt-2 space-y-1 text-xs text-secondary">{files.map((file, index) => <li key={`${file.name}-${index}`} className="flex items-center justify-between gap-2"><span className="truncate">{file.name}</span><button type="button" className="rounded p-1 text-muted hover:text-danger-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent" aria-label={`Remove ${file.name}`} onClick={() => setFiles((current) => current.filter((_, i) => i !== index))}><LuX size={14} /></button></li>)}</ul>}
                </div>
              </div>
              <p className="mt-3 text-xs text-muted">Your preference helps the team understand what would help. Any return, replacement, or credit is reviewed by the responsible team.</p>
            </Panel>

            {formError && <div role="alert" className="rounded-md border border-danger/40 bg-danger-bg px-3 py-2 text-sm text-danger-fg">{formError}</div>}
          </main>

          <aside className="space-y-4 lg:sticky lg:top-4 lg:self-start">
            <Panel title="Report summary">
              {selectedSource && sourceQuery.data ? (
                <>
                  <div className="flex items-center justify-between gap-2"><span className="text-xs text-muted">Source</span><Chip variant="info">{sourceKindLabel(sourceQuery.data.source.kind)}</Chip></div>
                  <p className="mt-1 font-mono text-sm text-primary">{sourceQuery.data.source.label}</p>
                  <p className="text-xs text-muted">{sourceQuery.data.party?.name ?? 'Party unavailable'}</p>
                  <p className="mt-2 text-xs text-muted">Already reported this document? <Link className="text-link hover:underline" to={`${casePath(realm)}?search=${encodeURIComponent(sourceQuery.data.source.label)}`}>Check existing reports</Link></p>
                  <div className="mt-4 border-t border-subtle pt-3">
                    <p className="text-xs text-muted">Selected lines</p>
                    <p className="mt-1 font-mono text-xl tabular-nums text-primary">{affectedLines.length}</p>
                    {affectedLines.map((line) => {
                      const draft = drafts[line.id];
                      const missing = calcShortage(draft.expected, draft.received);
                      return <p key={line.id} className="mt-2 truncate text-xs text-secondary">{line.part_number ?? line.description}: {missing} missing · {draft.defective || '0'} damaged</p>;
                    })}
                  </div>
                </>
              ) : (
                <p className="text-sm text-muted">Choose a delivery, GRN, or purchase order to load its lines.</p>
              )}
              <div className="mt-4 border-t border-subtle pt-3 text-xs text-muted">
                <span className="block font-medium text-primary">What happens next</span>
                <span className="mt-1 block">The case is reviewed before any stock or credit action. You can add evidence or reply on the case page.</span>
              </div>
            </Panel>
            <div className="flex flex-col gap-2">
              <Button variant="primary" size="lg" icon={<LuCheck size={16} />} loading={createMutation.isPending} disabled={!selectedSource || !sourceQuery.data || !canCreate} onClick={validateAndSubmit}>
                Submit report
              </Button>
              <Button variant="secondary" onClick={() => navigate(casePath(realm))}>Cancel</Button>
            </div>
          </aside>
        </div>
      </div>
    </div>
  );
}
