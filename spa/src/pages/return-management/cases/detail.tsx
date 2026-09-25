import { useEffect, useState, type ChangeEvent, type FormEvent } from 'react';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import type { Dispatch, ReactNode, SetStateAction } from 'react';
import { LuArrowLeft, LuCalendarClock, LuCheck, LuDownload, LuFileText, LuMessageSquare, LuRefreshCw, LuSend, LuX } from '@/lib/icons';
import { returnCasesApi } from '@/api/returnCases';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { Textarea } from '@/components/ui/Textarea';
import { formatDate, formatDateTime } from '@/lib/formatDate';
import { usePermission } from '@/hooks/usePermission';
import type { ReturnCaseActionPayload, ReturnCasePreferredResolution, ReturnCaseRecord, ReturnCaseResolution, ReturnCaseResolutionOptions } from '@/types/returnCases';
import { apiMessage, casePath, caseStatusVariant, evidenceFileError, realmFromPath } from './shared';

type ActionPanel = 'resolve_trace' | 'request_info' | 'agree' | 'reject' | 'withdraw' | 'reopen' | 'link_resolution' | 'create_return' | 'create_credit' | 'create_replacement' | null;

const RESOLUTIONS: Array<{ value: ReturnCaseResolution; label: string }> = [
  { value: 'return_goods', label: 'Return goods' },
  { value: 'redelivery', label: 'Redelivery' },
  { value: 'credit', label: 'Credit' },
  { value: 'no_action', label: 'No action' },
];
const PREFERENCE_LABEL: Record<ReturnCasePreferredResolution, string> = { redelivery: 'Redelivery', credit: 'Credit', advice: 'Help me decide' };
const NEXT_ACTION: Record<string, string> = {
  submitted: 'Ogami reviews the quantities and reported problem.',
  under_review: 'The assigned team is checking the report.',
  information_needed: 'Reply with the requested details or evidence.',
  action_agreed: 'The agreed return, redelivery, or credit needs to be carried out.',
  in_progress: 'The team is checking that the agreed work is complete.',
  resolved: 'The case is closed. See linked records for its outcome.',
  rejected: 'Read the reason and reply if you want the team to review it again.',
  withdrawn: 'This report has been withdrawn.',
};

function activeCase(status: string): boolean {
  return ['submitted', 'under_review', 'information_needed', 'action_agreed', 'in_progress'].includes(status);
}

function Fact({ label, value }: { label: string; value: string }) {
  return <div><span className="block text-xs text-muted">{label}</span><span className="mt-1 block capitalize text-secondary">{value}</span></div>;
}

function FactRow({ label, value }: { label: string; value: ReactNode }) {
  return <><dt className="text-xs text-muted">{label}</dt><dd className="text-right text-secondary">{value}</dd></>;
}

function Qty({ value, unit }: { value: string; unit: string | null }) {
  return <td className="px-2 py-2.5 text-right font-mono tabular-nums">{value}{unit ? ` ${unit}` : ''}</td>;
}

function LinkedRow({ label, number, status, trailing, href }: { label: string; number: string; status: string; trailing?: string; href?: string }) {
  return <div className="flex items-start justify-between gap-3 border-b border-subtle pb-2 last:border-0 last:pb-0">
    <div className="min-w-0"><p className="text-xs text-muted">{label}</p>{href ? <Link to={href} className="mt-0.5 block truncate font-mono text-sm text-accent hover:underline">{number}</Link> : <p className="mt-0.5 truncate font-mono text-sm text-primary">{number}</p>}</div>
    <div className="shrink-0 text-right"><Chip variant={caseStatusVariant(status)}>{status.replace(/_/g, ' ')}</Chip>{trailing && <p className="mt-1 font-mono text-xs tabular-nums text-secondary">{trailing}</p>}</div>
  </div>;
}

function sizeLabel(size: number): string {
  if (size < 1024) return `${size} B`;
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`;
  return `${(size / 1024 / 1024).toFixed(1)} MB`;
}

function ActionEditor({
  panel, record, message, setMessage, resolution, setResolution, expectedDate, setExpectedDate,
  verified, setVerified, links, setLinks, options, optionsLoading, optionsError, retryOptions,
  error, busy, onClose, onSubmit,
}: {
  panel: Exclude<ActionPanel, null>;
  record: ReturnCaseRecord;
  message: string;
  setMessage: (value: string) => void;
  resolution: ReturnCaseResolution;
  setResolution: (value: ReturnCaseResolution) => void;
  expectedDate: string;
  setExpectedDate: (value: string) => void;
  verified: Record<string, { missing: string; defective: string }>;
  setVerified: Dispatch<SetStateAction<Record<string, { missing: string; defective: string }>>>;
  links: Record<string, string | string[]>;
  setLinks: Dispatch<SetStateAction<Record<string, string | string[]>>>;
  options: ReturnCaseResolutionOptions | undefined;
  optionsLoading: boolean;
  optionsError: boolean;
  retryOptions: () => void;
  error: string;
  busy: boolean;
  onClose: () => void;
  onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}) {
  const title: Record<Exclude<ActionPanel, null>, string> = {
    resolve_trace: 'Close shipment tracking', request_info: 'Request information', agree: 'Agree an action', reject: 'Reject this report',
    withdraw: 'Withdraw this report', reopen: 'Ask for another review',
    link_resolution: 'Link completed work', create_return: 'Create return request', create_credit: 'Create credit note',
    create_replacement: 'Approve no-charge replacement',
  };

  return <Panel title={title[panel]} actions={<Button size="xs" variant="ghost" icon={<LuX size={14} />} onClick={onClose}>Close</Button>}>
    <form onSubmit={onSubmit} className="space-y-3">
      {['resolve_trace', 'request_info', 'reject', 'agree', 'withdraw', 'reopen'].includes(panel) && <Textarea
        label={panel === 'request_info' ? 'What information is needed?' : panel === 'reject' ? 'Reason for rejection' : panel === 'withdraw' ? 'Why is this report no longer needed?' : panel === 'reopen' ? 'What should the team reconsider?' : 'Agreed action and next step'}
        required maxLength={2000} value={message} onChange={(event) => setMessage(event.target.value)} rows={3}
      />}
      {panel === 'agree' && <>
        <label className="flex flex-col gap-1 text-xs font-medium text-muted">Resolution
          <select value={resolution} onChange={(event) => setResolution(event.target.value as ReturnCaseResolution)} className="h-9 rounded-md border border-default bg-canvas px-2 text-sm text-primary focus:outline-none focus:ring-[3px] focus:ring-accent/20 focus:border-accent">
            {RESOLUTIONS.map((item) => <option value={item.value} key={item.value}>{item.label}</option>)}
          </select>
        </label>
        {resolution === 'return_goods' && <p className="text-xs text-muted">Return goods applies to defective items only. If quantities are missing, choose redelivery or credit for the case; a physical return can still be arranged for its defective goods.</p>}
        {resolution === 'redelivery' && record.type === 'customer' && <p className="text-xs text-muted">Replacement quantities must fit two decimal places per line. Keep the reported quantity exact and choose another resolution if it cannot be represented.</p>}
        <Input label="Expected completion date (optional)" type="date" value={expectedDate} onChange={(event) => setExpectedDate(event.target.value)} />
        <div className="overflow-x-auto rounded-md border border-default">
          <table className="w-full min-w-[560px] text-left text-xs">
            <thead className="bg-subtle text-muted"><tr><th className="px-3 py-2 font-medium">Line</th><th className="px-3 py-2 text-right font-medium">Verified missing</th><th className="px-3 py-2 text-right font-medium">Verified damaged</th></tr></thead>
            <tbody className="divide-y divide-subtle">{(record.lines ?? []).map((line) => <tr key={line.id}>
              <td className="px-3 py-2 text-primary">{line.product_label ?? line.item_label ?? line.description}</td>
              <td className="px-3 py-2"><Input aria-label={`Verified missing for ${line.description}`} type="number" min="0" step="0.001" value={verified[line.id]?.missing ?? line.missing_quantity} onChange={(event) => setVerified((current) => ({ ...current, [line.id]: { missing: event.target.value, defective: current[line.id]?.defective ?? line.defective_quantity } }))} /></td>
              <td className="px-3 py-2"><Input aria-label={`Verified damaged for ${line.description}`} type="number" min="0" step="0.001" value={verified[line.id]?.defective ?? line.defective_quantity} onChange={(event) => setVerified((current) => ({ ...current, [line.id]: { missing: current[line.id]?.missing ?? line.missing_quantity, defective: event.target.value } }))} /></td>
            </tr>)}</tbody>
          </table>
        </div>
        <p className="text-xs text-muted">Verified quantities record the investigation. They do not create stock or financial movements.</p>
      </>}
      {panel === 'link_resolution' && <ResolutionLinkFields
        options={options} loading={optionsLoading} failed={optionsError} retry={retryOptions} values={links}
        onChange={(key, value) => setLinks((current) => ({ ...current, [key]: value }))}
      />}
      {panel === 'create_return' && <p className="text-sm text-secondary">Create the physical return workflow for the agreed goods. The return request remains the authority for receipt and disposition.</p>}
      {panel === 'create_credit' && <p className="text-sm text-secondary">Create a credit note through Accounting. Posting and application remain subject to Finance controls.</p>}
      {panel === 'create_replacement' && <p className="text-sm text-secondary">Approve a zero-price replacement sales order for the verified quantities. It follows the normal confirmation, quality, and dispatch controls and cannot be edited commercially.</p>}
      {error && <p role="alert" className="text-xs text-danger-fg">{error}</p>}
      <div className="flex justify-end gap-2 border-t border-subtle pt-3">
        <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
        <Button type="submit" variant={panel === 'reject' ? 'danger' : 'primary'} loading={busy}>
          {panel === 'resolve_trace' ? 'Close shipment tracking' : panel === 'request_info' ? 'Request information' : panel === 'agree' ? 'Record agreement' : panel === 'reject' ? 'Reject report' : panel === 'withdraw' ? 'Withdraw report' : panel === 'reopen' ? 'Request review' : panel === 'link_resolution' ? 'Save links' : panel === 'create_return' ? 'Create return request' : panel === 'create_replacement' ? 'Approve no-charge replacement' : 'Create credit note'}
        </Button>
      </div>
    </form>
  </Panel>;
}

function ResolutionLinkFields({
  options, loading, failed, retry, values, onChange,
}: {
  options: ReturnCaseResolutionOptions | undefined;
  loading: boolean;
  failed: boolean;
  retry: () => void;
  values: Record<string, string | string[]>;
  onChange: (key: string, value: string | string[]) => void;
}) {
  if (loading) return <p className="text-sm text-muted">Loading records linked to this case…</p>;
  if (failed || !options) return <EmptyState icon="alert-circle" title="Could not load linked records" action={<Button size="xs" onClick={retry}>Retry</Button>} />;
  const fields: Array<{ key: string; label: string; options: Array<{ id: string; label: string }> }> = [
    { key: 'credit_note_id', label: 'Credit note', options: options.credit_notes },
    { key: 'replacement_sales_order_id', label: 'Replacement sales order', options: options.sales_orders },
    { key: 'replacement_purchase_order_id', label: 'Replacement purchase order', options: options.purchase_orders },
    { key: 'replacement_delivery_id', label: 'Replacement delivery', options: options.deliveries },
  ];
  return <div className="space-y-3">
    <p className="text-sm text-secondary">Choose existing records that prove the agreed physical or financial work is complete.</p>
    {fields.filter((field) => field.key !== 'resolution_goods_receipt_note_id').map((field) => <label key={field.key} className="flex flex-col gap-1 text-xs font-medium text-muted">
      {field.label}
      <select value={typeof values[field.key] === 'string' ? values[field.key] : ''} onChange={(event) => onChange(field.key, event.target.value)} className="h-9 rounded-md border border-default bg-canvas px-2 text-sm text-primary focus:outline-none focus:ring-[3px] focus:ring-accent/20 focus:border-accent">
        <option value="">No selection</option>{field.options.map((option) => <option key={option.id} value={option.id}>{option.label}</option>)}
      </select>
      {field.options.length === 0 && <span className="font-normal text-muted">No eligible records found.</span>}
    </label>)}
    {options.goods_receipts.length > 0 && <fieldset className="space-y-2">
      <legend className="text-xs font-medium text-muted">Redelivery goods receipts (select all that apply)</legend>
      <div className="space-y-1 rounded-md border border-default p-2">
        {options.goods_receipts.map((option) => {
          const selected = Array.isArray(values.resolution_goods_receipt_note_ids) && values.resolution_goods_receipt_note_ids.includes(option.id);
          return <label key={option.id} className="flex min-h-9 cursor-pointer items-center gap-2 rounded px-1 text-sm text-primary hover:bg-elevated">
            <input type="checkbox" checked={selected} onChange={(event) => {
              const current = Array.isArray(values.resolution_goods_receipt_note_ids) ? values.resolution_goods_receipt_note_ids : [];
              onChange('resolution_goods_receipt_note_ids', event.target.checked ? [...current, option.id] : current.filter((id) => id !== option.id));
            }} />
            {option.label}
          </label>;
        })}
      </div>
      <span className="text-xs font-normal text-muted">Accepted amounts accumulate until the case is complete.</span>
    </fieldset>}
  </div>;
}

function actionSuccess(action: string): string {
  const messages: Record<string, string> = {
    start_review: 'Review started.', request_info: 'Information requested.', agree: 'Agreed action recorded.',
    reject: 'Report rejected with a reason.', create_return: 'Return request created.',
    create_credit: 'Credit note created for Finance review.', link_resolution: 'Completed work linked.',
    create_replacement: 'No-charge replacement approved.',
    resolve: 'Case resolved.', reopen: 'Review reopened.', reply: 'Reply added to the case.',
    acknowledge: 'Agreed action acknowledged.', withdraw: 'Report withdrawn.',
  };
  return messages[action] ?? 'Case updated.';
}

export default function ReturnCaseDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const location = useLocation();
  const navigate = useNavigate();
  const realm = realmFromPath(location.pathname);
  const { can } = usePermission();
  const qc = useQueryClient();
  const [panel, setPanel] = useState<ActionPanel>(null);
  const [message, setMessage] = useState('');
  const [resolution, setResolution] = useState<ReturnCaseResolution>('redelivery');
  const [expectedDate, setExpectedDate] = useState('');
  const [verified, setVerified] = useState<Record<string, { missing: string; defective: string }>>({});
  const [links, setLinks] = useState<Record<string, string | string[]>>({});
  const [selectedFiles, setSelectedFiles] = useState<File[]>([]);
  const pendingFromRoute = (location.state as { pendingFiles?: File[] } | null)?.pendingFiles ?? [];
  const [pendingFiles, setPendingFiles] = useState<File[]>(pendingFromRoute);
  const [actionError, setActionError] = useState('');

  const { data: record, isLoading, isError, refetch } = useQuery({
    queryKey: ['return-cases', realm, id], queryFn: () => returnCasesApi.show(realm, id), enabled: !!id,
  });
  const optionsQuery = useQuery({
    queryKey: ['return-cases', realm, id, 'resolution-options'],
    queryFn: () => returnCasesApi.resolutionOptions(realm, id),
    enabled: realm === 'internal' && panel === 'link_resolution',
  });
  const canManage = realm === 'internal' && can('return_management.manage');
  const canCredit = realm === 'internal' && can('accounting.credit_notes.manage');
  const canApprove = realm === 'internal' && can('return_management.approve');
  const canReview = canManage || canApprove;
  const internalLink = (path: string): string | undefined => realm === 'internal' ? path : undefined;
  const canReply = (realm !== 'internal' || canReview) && !!record && !['resolved', 'withdrawn'].includes(record.status);

  useEffect(() => {
    if (!record?.lines) return;
    setVerified((current) => {
      const next = { ...current };
      for (const line of record.lines ?? []) {
        next[line.id] ??= {
          missing: line.verified_missing_quantity ?? line.missing_quantity,
          defective: line.verified_defective_quantity ?? line.defective_quantity,
        };
      }
      return next;
    });
  }, [record?.lines]);

  const act = useMutation({
    mutationFn: (payload: ReturnCaseActionPayload) => returnCasesApi.act(realm, id, payload),
    onSuccess: (updated, payload) => {
      toast.success(actionSuccess(payload.action));
      setPanel(null); setMessage(''); setExpectedDate(''); setLinks({}); setActionError('');
      qc.setQueryData(['return-cases', realm, id], updated);
      qc.invalidateQueries({ queryKey: ['return-cases', realm] });
    },
    onError: (error) => setActionError(apiMessage(error, 'The action was not saved. Your message is still here; check the case status and retry.')),
  });
  const upload = useMutation({
    mutationFn: async (batch: File[]) => {
      const failed: File[] = [];
      for (let i = 0; i < batch.length; i += 1) {
        try { await returnCasesApi.upload(realm, id, batch[i]); }
        catch { failed.push(...batch.slice(i)); break; }
      }
      return failed;
    },
    onSuccess: (failed) => {
      setSelectedFiles([]); setPendingFiles(failed); setActionError('');
      qc.invalidateQueries({ queryKey: ['return-cases', realm, id] });
      if (failed.length) toast.error('Some files were not uploaded. Retry them below.', { id: `return-case-evidence-${id}` });
      else toast.success('Evidence uploaded.', { id: `return-case-evidence-${id}` });
    },
    onError: () => setActionError('Files could not be uploaded. They are still selected so you can retry.'),
  });

  const openPanel = (value: ActionPanel) => { setPanel(value); setActionError(''); };
  const sendAction = (action: ReturnCaseActionPayload['action'], fields: Partial<ReturnCaseActionPayload> = {}) => {
    setActionError(''); act.mutate({ action, ...fields });
  };
  const submitReply = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!message.trim()) { setActionError('Write a reply before sending.'); return; }
    sendAction('reply', { message: message.trim() });
  };
  const submitPanel = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (panel === 'resolve_trace' || panel === 'request_info' || panel === 'reject' || panel === 'agree' || panel === 'withdraw' || panel === 'reopen') {
      if (!message.trim()) {
        setActionError('Add an explanation before sending.');
        return;
      }
      if (panel === 'agree') {
        sendAction('agree', {
          resolution, message: message.trim(), expected_date: expectedDate || undefined,
          lines: (record?.lines ?? []).map((line) => ({
            id: line.id,
            verified_missing_quantity: verified[line.id]?.missing ?? line.missing_quantity,
            verified_defective_quantity: verified[line.id]?.defective ?? line.defective_quantity,
          })),
        });
      } else sendAction(panel, { message: message.trim() });
      return;
    }
    if (panel === 'link_resolution') {
      const linkValue = (key: string): string | undefined => typeof links[key] === 'string' ? links[key] as string || undefined : undefined;
      const fields = {
        credit_note_id: linkValue('credit_note_id'),
        replacement_delivery_id: linkValue('replacement_delivery_id'),
        resolution_goods_receipt_note_id: typeof links.resolution_goods_receipt_note_id === 'string' ? links.resolution_goods_receipt_note_id || undefined : undefined,
        resolution_goods_receipt_note_ids: Array.isArray(links.resolution_goods_receipt_note_ids) && links.resolution_goods_receipt_note_ids.length > 0 ? links.resolution_goods_receipt_note_ids : undefined,
        replacement_sales_order_id: linkValue('replacement_sales_order_id'),
        replacement_purchase_order_id: linkValue('replacement_purchase_order_id'),
      };
      if (!Object.values(fields).some(Boolean)) { setActionError('Choose a linked record to save.'); return; }
      sendAction('link_resolution', fields); return;
    }
    if (panel === 'create_return' || panel === 'create_credit' || panel === 'create_replacement') sendAction(panel);
  };
  const chooseFiles = (event: ChangeEvent<HTMLInputElement>) => {
    const selected = Array.from(event.target.files ?? []).filter((file) => {
      const error = evidenceFileError(file);
      if (error) toast.error(error);
      return !error;
    });
    setSelectedFiles((current) => [...current, ...selected]);
    event.target.value = '';
  };
  const downloadAttachment = async (attachmentId: string, fileName: string) => {
    try {
      const blob = await returnCasesApi.download(realm, id, attachmentId);
      const url = URL.createObjectURL(blob); const anchor = document.createElement('a');
      anchor.href = url; anchor.download = fileName; anchor.click();
      window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
    } catch { toast.error(`Could not download ${fileName}.`); }
  };

  if (isLoading) return <SkeletonDetail />;
  if (isError || !record) return <EmptyState icon="alert-circle" title="Could not load this problem report" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />;

  const isTrace = record.intake_kind === 'delivery_trace';
  const hasLinkedWork = !!(record.return_request || record.credit_note || record.replacement_order || record.replacement_delivery || record.resolution_goods_receipt_note || (record.resolution_receipts?.length ?? 0) > 0);
  const canReviseAgreement = !isTrace && (record.can_revise_agreement ?? !hasLinkedWork);
  const workAgreed = ['action_agreed', 'in_progress'].includes(record.status);
  const hasPortalActions = realm !== 'internal' && ((realm === 'supplier' && workAgreed) || record.status === 'rejected' || (realm === 'customer' && activeCase(record.status) && (isTrace || (record.can_revise_agreement ?? !hasLinkedWork))));
  const hasDefects = record.lines?.some((line) => Number(line.verified_defective_quantity ?? '0') > 0);
  const canPrepareReturn = workAgreed && hasDefects && !record.credit_note
    && (!record.return_request || ['cancelled', 'rejected'].includes(record.return_request.status))
    && ['return_goods', 'redelivery', 'credit'].includes(record.resolution ?? '');
  const internalActions = (canReview || canCredit) ? (
    <>
      {canReview && isTrace && activeCase(record.status) && <Button size="sm" variant="ghost" onClick={() => openPanel('reject')}>Reject report</Button>}
      {canReview && record.status === 'submitted' && <Button size="sm" variant="primary" icon={<LuCheck size={14} />} onClick={() => sendAction('start_review')}>Start review</Button>}
      {canReview && activeCase(record.status) && <Button size="sm" variant="secondary" onClick={() => openPanel('request_info')}>Request information</Button>}
      {canReview && canReviseAgreement && ['submitted', 'under_review', 'information_needed', 'action_agreed', 'in_progress'].includes(record.status) && <>
        <Button size="sm" variant="primary" onClick={() => openPanel('agree')}>Agree action</Button>
        <Button size="sm" variant="ghost" onClick={() => openPanel('reject')}>Reject</Button>
      </>}
      {canReview && canPrepareReturn && <Button size="sm" variant="primary" onClick={() => openPanel('create_return')}>Create return request</Button>}
      {workAgreed && record.resolution === 'credit' && canCredit && (!record.credit_note || record.credit_note.status === 'void') && <Button size="sm" variant="primary" onClick={() => openPanel('create_credit')}>Create credit note</Button>}
      {workAgreed && record.type === 'customer' && record.resolution === 'redelivery' && canApprove && !record.replacement_order && <Button size="sm" variant="primary" onClick={() => openPanel('create_replacement')}>Approve no-charge replacement</Button>}
      {canReview && workAgreed && <>
        <Button size="sm" variant="secondary" onClick={() => openPanel('link_resolution')}>Link completed work</Button>
        <Button size="sm" variant="primary" icon={<LuCheck size={14} />} onClick={() => sendAction('resolve')}>Resolve case</Button>
      </>}
      {canReview && record.status === 'rejected' && <Button size="sm" variant="secondary" icon={<LuRefreshCw size={14} />} onClick={() => openPanel('reopen')}>Reopen review</Button>}
    </>
  ) : null;

  return (
    <div>
      <PageHeader
        title={<span className="flex flex-wrap items-center gap-2">{record.case_number}<Chip variant={caseStatusVariant(record.status)}>{record.status_label}</Chip></span>}
        subtitle={record.source ? `${record.source.label} · ${record.party?.name ?? 'Party'}` : record.party?.name}
        backTo={casePath(realm)} backLabel="Problem reports"
        actions={<div className="flex flex-wrap items-center gap-2">
          <Button variant="ghost" size="sm" icon={<LuArrowLeft size={14} />} onClick={() => navigate(casePath(realm))}>Back</Button>
          {realm === 'internal' && <Button variant="secondary" size="sm" onClick={() => navigate('/return-management')}>Open RMAs</Button>}
          {record.can_resolve_trace && (realm === 'customer' || canReview) && <Button size="sm" variant="primary" onClick={() => openPanel('resolve_trace')}>{realm === 'customer' ? 'Shipment has arrived' : 'Close shipment tracking'}</Button>}
          {internalActions}
        </div>}
      />

      <div className="px-5 py-4">
        {actionError && !panel && <p role="alert" className="mx-auto mb-4 max-w-7xl rounded-md border border-danger-fg/30 bg-danger-bg px-4 py-3 text-sm text-danger-fg">{actionError}</p>}
        <div className="mx-auto grid max-w-7xl grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_340px]">
          <main className="space-y-4">
            <Panel title="Reported problem" meta={record.created_at ? `Submitted ${formatDateTime(record.created_at)}` : undefined}>
              <p className="max-w-[72ch] whitespace-pre-wrap text-sm leading-6 text-primary">{record.description}</p>
              {!isTrace && <div className="mt-4 grid grid-cols-2 gap-3 border-t border-subtle pt-3 text-sm sm:grid-cols-3">
                <Fact label="Preferred outcome" value={PREFERENCE_LABEL[record.preferred_resolution]} />
                <Fact label="Agreed action" value={record.resolution?.replace(/_/g, ' ') ?? 'Under review'} />
                <Fact label="Expected date" value={record.expected_date ? formatDate(record.expected_date) : 'Not set'} />
              </div>}
              {realm === 'internal' && record.resolution_notes && <div className="mt-4 rounded-md bg-subtle p-3"><span className="text-xs font-medium text-muted">Internal resolution notes</span><p className="mt-1 whitespace-pre-wrap text-sm text-secondary">{record.resolution_notes}</p></div>}
            </Panel>

            {!isTrace && <Panel title="Quantities" meta="Reported and verified amounts are shown separately">
              <div className="space-y-4 2xl:hidden">
                {(record.lines ?? []).map((line) => <section key={line.id} className="border-b border-subtle pb-4 last:border-0 last:pb-0">
                  <p className="text-sm font-medium text-primary">{line.product_label ?? line.item_label ?? line.description}</p>
                  {(line.lot_number || line.serial_number) && <p className="mt-1 text-xs text-muted">{[line.lot_number && `Lot ${line.lot_number}`, line.serial_number && `Serial ${line.serial_number}`].filter(Boolean).join(' · ')}</p>}
                  <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-4">
                    {[
                      ['Expected', line.expected_quantity], ['Received', line.received_quantity],
                      ['Missing', line.missing_quantity], ['Damaged', line.defective_quantity],
                      ['Verified missing', line.verified_missing_quantity], ['Verified damaged', line.verified_defective_quantity],
                      ...(realm !== 'customer' ? [['Redelivered', line.redelivered_quantity], ['Remaining redelivery', line.remaining_redelivery_quantity]] : []),
                      ['Actually returned', line.returned_quantity],
                    ].map(([label, quantity]) => <div key={label}>
                      <dt className="text-xs text-muted">{label}</dt>
                      <dd className="mt-1 break-words font-mono text-sm tabular-nums text-primary">{quantity == null ? (label === 'Actually returned' ? '—' : 'Pending') : `${quantity}${line.unit ? ` ${line.unit}` : ''}`}</dd>
                    </div>)}
                  </dl>
                </section>)}
                {(record.lines ?? []).length === 0 && <p className="text-sm text-muted">No affected lines were attached.</p>}
              </div>
              <div className="hidden overflow-x-auto 2xl:block">
                <table className="w-full min-w-[860px] text-left text-xs">
                  <thead className="text-muted"><tr className="border-b border-default">
                    <th className="py-2 pr-3 font-medium">Goods</th><th className="px-2 py-2 text-right font-medium">Expected</th>
                    <th className="px-2 py-2 text-right font-medium">Received</th><th className="px-2 py-2 text-right font-medium">Missing</th>
                    <th className="px-2 py-2 text-right font-medium">Damaged</th><th className="px-2 py-2 text-right font-medium">Verified missing</th>
                    <th className="px-2 py-2 text-right font-medium">Verified damaged</th>{realm !== 'customer' && <><th className="px-2 py-2 text-right font-medium">Redelivered</th><th className="px-2 py-2 text-right font-medium">Remaining redelivery</th></>}<th className="py-2 pl-2 text-right font-medium">Actually returned</th>
                  </tr></thead>
                  <tbody className="divide-y divide-subtle">
                    {(record.lines ?? []).map((line) => <tr key={line.id}>
                      <td className="py-2.5 pr-3"><span className="block font-medium text-primary">{line.product_label ?? line.item_label ?? line.description}</span>
                        {(line.product_label || line.item_label) && <span className="block text-muted">{line.description}</span>}
                        {(line.lot_number || line.serial_number) && <span className="block text-muted">{[line.lot_number && `Lot ${line.lot_number}`, line.serial_number && `Serial ${line.serial_number}`].filter(Boolean).join(' · ')}</span>}
                      </td>
                      <Qty value={line.expected_quantity} unit={line.unit} /><Qty value={line.received_quantity} unit={line.unit} />
                      <Qty value={line.missing_quantity} unit={line.unit} /><Qty value={line.defective_quantity} unit={line.unit} />
                      <Qty value={line.verified_missing_quantity ?? 'Pending'} unit={line.verified_missing_quantity ? line.unit : null} />
                      <Qty value={line.verified_defective_quantity ?? 'Pending'} unit={line.verified_defective_quantity ? line.unit : null} />
                      {realm !== 'customer' && <><Qty value={line.redelivered_quantity ?? '—'} unit={line.redelivered_quantity ? line.unit : null} /><Qty value={line.remaining_redelivery_quantity ?? '—'} unit={line.remaining_redelivery_quantity ? line.unit : null} /></>}
                      <Qty value={line.returned_quantity ?? '—'} unit={line.returned_quantity ? line.unit : null} />
                    </tr>)}
                    {(record.lines ?? []).length === 0 && <tr><td colSpan={8} className="py-5 text-center text-muted">No affected lines were attached.</td></tr>}
                  </tbody>
                </table>
              </div>
            </Panel>}

            <Panel title="Case timeline" meta={`${record.events?.length ?? 0} updates`}>
              {record.events?.length ? <ol className="divide-y divide-subtle">{record.events.map((event) => <li key={event.id} className="grid grid-cols-[20px_minmax(0,1fr)] gap-3 py-3 first:pt-0 last:pb-0">
                <span className={`mt-1 flex h-5 w-5 items-center justify-center rounded-full ${event.is_public ? 'bg-accent/10 text-accent' : 'bg-subtle text-muted'}`} aria-hidden><LuMessageSquare size={12} /></span>
                <div><div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1"><span className="text-sm font-medium capitalize text-primary">{event.action.replace(/[._]/g, ' ')}</span><time className="font-mono text-2xs tabular-nums text-muted">{event.created_at ? formatDateTime(event.created_at) : '—'}</time></div>
                  {event.message && <p className="mt-1 whitespace-pre-wrap text-sm leading-5 text-secondary">{event.message}</p>}
                  <p className="mt-1 text-xs text-muted">{event.actor_name}{event.actor_type !== 'internal' ? ` · ${event.actor_type}` : ''}</p>
                </div>
              </li>)}</ol> : <p className="text-sm text-muted">Updates and replies will appear here.</p>}
            </Panel>

            {canReply && !panel && <Panel title="Reply to the case" meta="Your message is added to the timeline">
              <form onSubmit={submitReply} className="space-y-3">
                <Textarea label="Message" required maxLength={2000} value={message} onChange={(event) => setMessage(event.target.value)} placeholder="Add details or answer a question from the team." rows={3} />
                <div className="flex justify-end"><Button type="submit" variant="primary" size="sm" icon={<LuSend size={14} />} loading={act.isPending}>Send reply</Button></div>
              </form>
            </Panel>}

            {panel && <ActionEditor
              panel={panel} record={record} message={message} setMessage={setMessage} resolution={resolution} setResolution={setResolution}
              expectedDate={expectedDate} setExpectedDate={setExpectedDate} verified={verified} setVerified={setVerified}
              links={links} setLinks={setLinks} options={optionsQuery.data} optionsLoading={optionsQuery.isLoading}
              optionsError={optionsQuery.isError} retryOptions={() => optionsQuery.refetch()} error={actionError}
              busy={act.isPending} onClose={() => openPanel(null)} onSubmit={submitPanel}
            />}
          </main>

          <aside className="space-y-4 xl:sticky xl:top-4 xl:self-start">
            <Panel title="Next step">
              <p className="text-sm text-primary">{isTrace && activeCase(record.status) ? 'Customer Service is checking the shipment with Dispatch. Follow updates here. Once it arrives, confirm arrival and report any item problem from the delivery.' : NEXT_ACTION[record.status] ?? 'The assigned team will update this case.'}</p>
              {isTrace && record.source?.kind === 'delivery' && (realm === 'customer' || can('supply_chain.view') || can('supply_chain.deliveries.view')) && <Link className="mt-3 inline-block text-sm text-link underline" to={realm === 'customer' ? `/portal/customer/deliveries/${record.source.id}` : `/supply-chain/deliveries/${record.source.id}`}>Open delivery {record.source.label}</Link>}
              <dl className="mt-4 grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 border-t border-subtle pt-3 text-sm">
                <FactRow label="Owner" value={record.owner?.name ?? (realm === 'internal' ? 'Unassigned' : 'Ogami team')} />
                <FactRow label="Source" value={record.source?.label ?? '—'} />
                <FactRow label="Party" value={record.party?.name ?? '—'} />
                {record.expected_date && <FactRow label="Expected by" value={<span className="flex items-center justify-end gap-1"><LuCalendarClock size={13} />{formatDate(record.expected_date)}</span>} />}
              </dl>
            </Panel>
            {!isTrace && <Panel title="Linked work">
              <div className="space-y-3 text-sm">
                {record.return_request && <LinkedRow label="Physical return" number={record.return_request.rma_number} status={record.return_request.status} href={realm === 'customer' ? `/portal/customer/returns/${record.return_request.id}` : internalLink(`/return-management/${record.return_request.id}`)} />}
                {record.credit_note && <LinkedRow label={record.credit_note.status === 'draft' ? 'Credit being reviewed' : 'Credit issued'} number={record.credit_note.credit_note_number ?? 'Credit note'} status={record.credit_note.status} trailing={record.credit_note.total_amount} href={internalLink(`/accounting/credit-notes/${record.credit_note.id}`)} />}
                {record.return_credit_note && record.return_credit_note.id !== record.credit_note?.id && <LinkedRow label="Physical return credit" number={record.return_credit_note.credit_note_number ?? 'Credit note'} status={record.return_credit_note.status} trailing={record.return_credit_note.total_amount} href={internalLink(`/accounting/credit-notes/${record.return_credit_note.id}`)} />}
                {!record.credit_note && record.resolution === 'credit' && <p className="text-secondary">Credit is being reviewed. No credit note has been issued yet.</p>}
                {record.replacement_order && <LinkedRow label="Replacement order" number={record.replacement_order.number} status={record.replacement_order.status ?? '—'} href={internalLink(record.replacement_order.type === 'purchase_order' ? `/purchasing/purchase-orders/${record.replacement_order.id}` : `/crm/sales-orders/${record.replacement_order.id}`)} />}
                {record.replacement_delivery && <LinkedRow label="Replacement delivery" number={record.replacement_delivery.delivery_number ?? 'Delivery'} status={record.replacement_delivery.status ?? '—'} href={internalLink(`/supply-chain/deliveries/${record.replacement_delivery.id}`)} />}
                {record.resolution_goods_receipt_note && !(record.resolution_receipts?.length) && <LinkedRow label="Replacement receipt" number={record.resolution_goods_receipt_note.grn_number ?? 'Goods receipt'} status={record.resolution_goods_receipt_note.status ?? '—'} href={internalLink(`/inventory/grn/${record.resolution_goods_receipt_note.id}`)} />}
                {record.resolution_receipts?.map((receipt) => <LinkedRow key={receipt.id} label="Redelivery receipt" number={receipt.grn_number} status={receipt.status} href={internalLink(`/inventory/grn/${receipt.id}`)} />)}
                {!record.return_request && !record.credit_note && !record.replacement_order && !record.replacement_delivery && !record.resolution_goods_receipt_note && !(record.resolution_receipts?.length) && <p className="text-sm text-muted">No operational work is linked yet.</p>}
                {canReview && workAgreed && <Button variant="secondary" size="sm" className="w-full" onClick={() => openPanel('link_resolution')}>Link completed work</Button>}
              </div>
            </Panel>}
            <Panel title="Evidence" meta="Shared with everyone on this case">
              <div className="space-y-2">
                {(record.attachments ?? []).map((attachment) => <div key={attachment.id} className="flex items-center justify-between gap-2 rounded-md border border-default px-2.5 py-2">
                  <div className="min-w-0"><p className="truncate text-xs font-medium text-primary">{attachment.file_name}</p><p className="text-2xs text-muted">{sizeLabel(attachment.size)}</p></div>
                  <Button iconOnly aria-label={`Download ${attachment.file_name}`} size="sm" variant="ghost" onClick={() => downloadAttachment(attachment.id, attachment.file_name)}><LuDownload size={15} /></Button>
                </div>)}
                {(!record.attachments || record.attachments.length === 0) && <p className="text-xs text-muted">No evidence attached yet.</p>}
                {!['resolved', 'withdrawn'].includes(record.status) && (realm !== 'internal' || canReview || canCredit) && <>
                  {pendingFiles.length > 0 && <div className="rounded-md border border-warning/40 bg-warning-bg p-2.5"><p className="text-xs font-medium text-warning-fg">{pendingFiles.length} file{pendingFiles.length === 1 ? '' : 's'} still need uploading</p>{pendingFiles.map((file, index) => <div key={`${file.name}-${index}`} className="mt-1 flex items-center gap-2 text-xs text-secondary"><span className="min-w-0 flex-1 truncate">{file.name}</span><button type="button" aria-label={`Remove pending ${file.name}`} className="rounded p-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent" onClick={() => setPendingFiles((current) => current.filter((_, i) => i !== index))}><LuX size={13} /></button></div>)}<Button size="xs" variant="secondary" className="mt-2" loading={upload.isPending} onClick={() => upload.mutate(pendingFiles)}>Retry upload</Button></div>}
                  <label className="flex min-h-9 cursor-pointer items-center justify-center gap-2 rounded-md border border-dashed border-default px-3 text-xs text-secondary hover:bg-elevated focus-within:ring-2 focus-within:ring-accent"><LuFileText size={15} /><span>Add evidence</span><input type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" multiple className="sr-only" onChange={chooseFiles} /></label>
                  <p className="text-xs text-muted">JPG, PNG, WebP or PDF, up to 10 MB each.</p>
                  {selectedFiles.length > 0 && <div className="space-y-1">{selectedFiles.map((file, index) => <div key={`${file.name}-${index}`} className="flex items-center justify-between gap-2 text-xs text-secondary"><span className="truncate">{file.name}</span><button aria-label={`Remove ${file.name}`} type="button" className="rounded p-1 text-muted hover:text-danger-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent" onClick={() => setSelectedFiles((current) => current.filter((_, i) => i !== index))}><LuX size={13} /></button></div>)}<Button size="xs" variant="primary" loading={upload.isPending} onClick={() => upload.mutate(selectedFiles)}>Upload evidence</Button></div>}
                </>}
              </div>
            </Panel>
            {hasPortalActions && <Panel title="Report actions">
              <div className="flex flex-col gap-2">
                {realm === 'supplier' && workAgreed && <Button variant="primary" icon={<LuCheck size={14} />} onClick={() => sendAction('acknowledge', { message: 'We acknowledge the agreed action and expected completion date shown on this case.' })}>Acknowledge agreed action</Button>}
                {record.status === 'rejected' && <Button variant="secondary" icon={<LuRefreshCw size={14} /> } onClick={() => openPanel('reopen')}>Ask for another review</Button>}
                {realm === 'customer' && activeCase(record.status) && (isTrace || (record.can_revise_agreement ?? !hasLinkedWork)) && <Button variant="ghost" onClick={() => openPanel('withdraw')}>Withdraw report</Button>}
              </div>
            </Panel>}
          </aside>
        </div>
      </div>
    </div>
  );
}
