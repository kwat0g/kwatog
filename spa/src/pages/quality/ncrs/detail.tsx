/**
 * Sprint 7 — Task 64 — NCR detail page.
 *
 * Shows the NCR root row, lets QC append actions (containment / corrective
 * / preventive), set a final disposition, and close the NCR. Replacement
 * work orders auto-created on close are surfaced in the LinkedRecords panel.
 */
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { Check, Ban, Plus } from 'lucide-react';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import { ncrsApi } from '@/api/quality/ncrs';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { ReasonDialog } from '@/components/ui/ReasonDialog';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { PageHeader } from '@/components/layout/PageHeader';
import { LinkedRecords } from '@/components/chain/LinkedRecords';
import { ActivityStream } from '@/components/chain/ActivityStream';
import { ChainHeader } from '@/components/chain';
import type { ChainStep } from '@/types/chain';
import { usePermission } from '@/hooks/usePermission';
import type {
  NcrActionType,
  NcrDisposition,
  NcrSeverity,
  NcrStatus,
} from '@/types/quality';

const STATUS_CHIP: Record<NcrStatus, 'success' | 'danger' | 'warning' | 'neutral' | 'info'> = {
  open: 'warning',
  in_progress: 'info',
  closed: 'success',
  cancelled: 'neutral',
};

const SEVERITY_CHIP: Record<NcrSeverity, 'success' | 'danger' | 'warning' | 'neutral' | 'info'> = {
  low: 'neutral',
  medium: 'info',
  high: 'warning',
  critical: 'danger',
};

export default function NcrDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { can } = usePermission();
  const [actionType, setActionType] = useState<NcrActionType>('containment');
  const [actionDesc, setActionDesc] = useState('');
  const [disposition, setDisposition] = useState<NcrDisposition | ''>('');
  const [rootCause, setRootCause] = useState('');
  const [correctiveAction, setCorrectiveAction] = useState('');
  const [confirmClose, setConfirmClose] = useState(false);
  const [confirmCancel, setConfirmCancel] = useState(false);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['quality', 'ncrs', id],
    queryFn: () => ncrsApi.show(id),
    enabled: Boolean(id),
    placeholderData: (prev) => prev,
  });

  const addAction = useMutation({
    mutationFn: () =>
      ncrsApi.addAction(id, { action_type: actionType, description: actionDesc }),
    onSuccess: () => {
      toast.success('Action added');
      setActionDesc('');
      qc.invalidateQueries({ queryKey: ['quality', 'ncrs', id] });
    },
    onError: (e: AxiosError<{ message?: string }>) => toast.error(e.response?.data?.message ?? 'Failed'),
  });

  const setDispositionMut = useMutation({
    mutationFn: () =>
      ncrsApi.setDisposition(id, {
        disposition: disposition as NcrDisposition,
        root_cause: rootCause || undefined,
        corrective_action: correctiveAction || undefined,
      }),
    onSuccess: () => {
      toast.success('Disposition saved');
      qc.invalidateQueries({ queryKey: ['quality', 'ncrs', id] });
    },
    onError: (e: AxiosError<{ message?: string }>) => toast.error(e.response?.data?.message ?? 'Failed'),
  });

  const close = useMutation({
    mutationFn: () => ncrsApi.close(id),
    onSuccess: (ncr) => {
      toast.success(
        ncr.replacement_work_order
          ? `NCR closed; replacement WO ${ncr.replacement_work_order.wo_number} created`
          : 'NCR closed'
      );
      qc.invalidateQueries({ queryKey: ['quality', 'ncrs', id] });
      setConfirmClose(false);
    },
    onError: (e: AxiosError<{ message?: string }>) => toast.error(e.response?.data?.message ?? 'Could not close'),
  });

  const cancel = useMutation({
    mutationFn: (reason: string) => ncrsApi.cancel(id, reason),
    onSuccess: () => {
      toast.success('NCR cancelled');
      qc.invalidateQueries({ queryKey: ['quality', 'ncrs', id] });
      setConfirmCancel(false);
    },
    onError: (e: AxiosError<{ message?: string }>) => toast.error(e.response?.data?.message ?? 'Could not cancel'),
  });

  if (isLoading && !data) return <SkeletonDetail />;
  if (isError || !data) {
    return (
      <EmptyState
        icon="alert-circle"
        title="Failed to load NCR"
        action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
      />
    );
  }

  const isTerminal = ['closed', 'cancelled'].includes(data.status);

  const ncrChain: ChainStep[] = [
    { key: 'opened',      label: 'NCR Opened',  state: 'done', date: data.created_at?.slice(0, 10) },
    { key: 'disposition', label: 'Disposition', state: data.disposition ? 'done' : data.status === 'cancelled' ? 'pending' : 'active' },
    { key: 'actions',     label: 'Action Taken',state: (data.actions?.length ?? 0) > 0 ? 'done' : 'pending' },
    { key: 'closed',      label: 'Closed',      state: data.status === 'closed' ? 'done' : 'pending', date: data.closed_at?.slice(0, 10) },
  ];

  // Build LinkedRecords groups
  const linkedGroups: import('@/types/chain').LinkedGroup[] = [];
  if (data.product) {
    linkedGroups.push({
      label: 'Product',
      items: [{
        id: `${data.product.part_number} — ${data.product.name}`,
        href: `/crm/products/${data.product.id}`,
      }],
    });
  }
  if (data.inspection) {
    linkedGroups.push({
      label: 'Inspection',
      items: [{
        id: data.inspection.inspection_number,
        href: `/quality/inspections/${data.inspection.id}`,
        meta: `${data.inspection.stage} · ${data.inspection.status}`,
      }],
    });
  }
  if (data.replacement_work_order) {
    linkedGroups.push({
      label: 'Replacement WO',
      items: [{
        id: data.replacement_work_order.wo_number,
        href: `/production/work-orders/${data.replacement_work_order.id}`,
        meta: `${data.replacement_work_order.quantity_target} pcs · ${data.replacement_work_order.status}`,
      }],
    });
  }

  const ACTION_DOT: Record<NcrActionType, 'success' | 'warning' | 'info'> = {
    containment: 'warning',
    corrective: 'info',
    preventive: 'success',
  };
  const activityItems: import('@/types/chain').ActivityItem[] = (data.actions ?? []).map((a) => ({
    dot: ACTION_DOT[a.action_type],
    text: (
      <span>
        <span className="font-medium uppercase text-2xs tracking-wider text-muted">{a.action_type}</span>
        <span className="ml-2">{a.description}</span>
        <span className="ml-2 text-muted">— {a.performer?.name ?? 'system'}</span>
      </span>
    ),
    time: a.performed_at?.slice(0, 16).replace('T', ' ') ?? '',
  }));

  return (
    <div>
      <PageHeader
        title={
          <span>
            {data.ncr_number}
            <Chip variant={STATUS_CHIP[data.status]} className="ml-3">{data.status.replace('_', ' ')}</Chip>
            <Chip variant={SEVERITY_CHIP[data.severity]} className="ml-2">{data.severity}</Chip>
          </span>
        }
        subtitle={data.product ? `${data.product.part_number} — ${data.product.name}` : data.source.replace('_', ' ')}
        actions={
          <div className="flex items-center gap-2">
            {!isTerminal && data.disposition && can('quality.ncr.manage') && (
              <Button variant="primary" size="sm" icon={<Check size={14} />} onClick={() => setConfirmClose(true)}>
                Close NCR
              </Button>
            )}
            {!isTerminal && can('quality.ncr.manage') && (
              <Button variant="secondary" size="sm" icon={<Ban size={14} />} onClick={() => setConfirmCancel(true)}>
                Cancel
              </Button>
            )}
          </div>
        }
      />

      <div className="px-5 pt-4">
        <Panel title="Quality flow">
          <ChainHeader steps={ncrChain} />
        </Panel>
      </div>

      <div className="px-5 grid grid-cols-3 gap-4">
        <div className="col-span-2 space-y-4">
          <Panel title="Details">
            <dl className="grid grid-cols-3 gap-x-4 gap-y-3 text-sm">
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Source</dt>
                <dd>{data.source.replace('_', ' ')}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Affected qty</dt>
                <dd className="font-mono tabular-nums">{data.affected_quantity}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Disposition</dt>
                <dd>{data.disposition?.replace('_', ' ') ?? '—'}</dd>
              </div>
              <div className="col-span-3">
                <dt className="text-2xs uppercase tracking-wider text-muted">Defect description</dt>
                <dd className="whitespace-pre-line">{data.defect_description}</dd>
              </div>
              {data.root_cause && (
                <div className="col-span-3">
                  <dt className="text-2xs uppercase tracking-wider text-muted">Root cause</dt>
                  <dd className="whitespace-pre-line">{data.root_cause}</dd>
                </div>
              )}
              {data.corrective_action && (
                <div className="col-span-3">
                  <dt className="text-2xs uppercase tracking-wider text-muted">Corrective action</dt>
                  <dd className="whitespace-pre-line">{data.corrective_action}</dd>
                </div>
              )}
            </dl>
          </Panel>

          {!isTerminal && can('quality.ncr.manage') && (
            <Panel title="Set disposition">
              <div className="grid grid-cols-3 gap-3">
                <Select
                  label="Disposition"
                  value={disposition}
                  onChange={(e) => setDisposition(e.target.value as NcrDisposition | '')}
                >
                  <option value="">— Select —</option>
                  <option value="scrap">Scrap (auto-WO if outgoing)</option>
                  <option value="rework">Rework</option>
                  <option value="use_as_is">Use as-is</option>
                  <option value="return_to_supplier">Return to supplier</option>
                </Select>
              </div>
              <Textarea
                label="Root cause"
                rows={3}
                value={rootCause}
                onChange={(e) => setRootCause(e.target.value)}
              />
              <Textarea
                label="Corrective action"
                rows={3}
                value={correctiveAction}
                onChange={(e) => setCorrectiveAction(e.target.value)}
              />
              <div className="flex justify-end mt-3">
                <Button
                  variant="secondary"
                  size="sm"
                  disabled={!disposition}
                  loading={setDispositionMut.isPending}
                  onClick={() => setDispositionMut.mutate()}
                >
                  Save disposition
                </Button>
              </div>
            </Panel>
          )}

          {!isTerminal && can('quality.ncr.manage') && (
            <Panel title="Add action">
              <div className="grid grid-cols-3 gap-3">
                <Select
                  label="Type"
                  value={actionType}
                  onChange={(e) => setActionType(e.target.value as NcrActionType)}
                >
                  <option value="containment">Containment</option>
                  <option value="corrective">Corrective</option>
                  <option value="preventive">Preventive</option>
                </Select>
              </div>
              <Textarea
                label="Description"
                rows={3}
                value={actionDesc}
                onChange={(e) => setActionDesc(e.target.value)}
              />
              <div className="flex justify-end mt-3">
                <Button
                  variant="secondary"
                  size="sm"
                  icon={<Plus size={14} />}
                  disabled={!actionDesc}
                  loading={addAction.isPending}
                  onClick={() => addAction.mutate()}
                >
                  Add action
                </Button>
              </div>
            </Panel>
          )}
        </div>

        <div className="space-y-4">
          {linkedGroups.length > 0 && (
            <Panel title="Linked records">
              <LinkedRecords groups={linkedGroups} />
            </Panel>
          )}
          {activityItems.length > 0 && (
            <Panel title="Activity">
              <ActivityStream items={activityItems} />
            </Panel>
          )}
          <Panel title="Navigation">
            <Link to="/quality/ncrs" className="text-xs text-accent hover:underline">
              ← Back to NCRs
            </Link>
          </Panel>
        </div>
      </div>

      <ConfirmDialog
        isOpen={confirmClose}
        title="Close NCR?"
        description={
          data.disposition === 'scrap'
            ? 'On scrap from outgoing inspection, a replacement work order will be auto-created.'
            : data.disposition === 'return_to_supplier'
            ? 'Purchasing will be notified to coordinate the supplier return.'
            : 'This will lock the NCR. Actions can no longer be added after closure.'
        }
        confirmLabel="Close NCR"
        onConfirm={() => close.mutate()}
        onClose={() => setConfirmClose(false)}
        pending={close.isPending}
      />
      <ReasonDialog
        isOpen={confirmCancel}
        title="Cancel NCR?"
        description="The reason will be appended to the corrective action notes."
        confirmLabel="Cancel NCR"
        onConfirm={(reason) => cancel.mutate(reason)}
        onClose={() => setConfirmCancel(false)}
        pending={cancel.isPending}
      />
    </div>
  );
}
