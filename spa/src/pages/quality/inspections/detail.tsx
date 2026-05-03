/**
 * Sprint 7 — Task 60 — Inspection detail / measurement-recording page.
 *
 * The page groups the seeded measurements by sample_index. The inspector
 * fills in measured_value (auto pass/fail for dimensional/functional via
 * tolerance window) or toggles is_pass (visual checks). Save patches the
 * batch; Complete finalises the inspection (passed/failed by AQL plan).
 */
import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { Check, Ban, Save } from 'lucide-react';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import { inspectionsApi } from '@/api/quality/inspections';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { ReasonDialog } from '@/components/ui/ReasonDialog';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { ChainHeader } from '@/components/chain/ChainHeader';
import { LinkedRecords } from '@/components/chain/LinkedRecords';
import { usePermission } from '@/hooks/usePermission';
import type { Inspection, InspectionMeasurement, InspectionStatus } from '@/types/quality';

const STATUS_CHIP: Record<InspectionStatus, 'success' | 'danger' | 'warning' | 'neutral' | 'info'> = {
  draft: 'neutral',
  in_progress: 'info',
  passed: 'success',
  failed: 'danger',
  cancelled: 'neutral',
};

interface RowDraft {
  id: string;
  measured_value: string; // keep as string to allow empty input
  is_pass: boolean | null;
  notes: string;
  dirty: boolean;
}

export default function InspectionDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { can } = usePermission();
  const [drafts, setDrafts] = useState<Record<string, RowDraft>>({});
  const [confirmComplete, setConfirmComplete] = useState(false);
  const [confirmCancel, setConfirmCancel] = useState(false);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['quality', 'inspections', id],
    queryFn: () => inspectionsApi.show(id),
    enabled: Boolean(id),
    placeholderData: (prev) => prev,
  });

  // Seed local drafts whenever the server payload arrives.
  useEffect(() => {
    if (!data?.measurements) return;
    setDrafts((existing) => {
      const next: Record<string, RowDraft> = {};
      for (const m of data.measurements!) {
        next[m.id] = existing[m.id]?.dirty
          ? existing[m.id]
          : {
              id: m.id,
              measured_value: m.measured_value === null ? '' : String(m.measured_value),
              is_pass: m.is_pass,
              notes: m.notes ?? '',
              dirty: false,
            };
      }
      return next;
    });
  }, [data]);

  const save = useMutation({
    mutationFn: () => {
      const dirty = Object.values(drafts).filter((d) => d.dirty);
      return inspectionsApi.recordMeasurements(id, {
        measurements: dirty.map((d) => ({
          id: d.id,
          measured_value: d.measured_value === '' ? null : Number(d.measured_value),
          is_pass: d.is_pass,
          notes: d.notes || null,
        })),
      });
    },
    onSuccess: () => {
      toast.success('Measurements saved');
      qc.invalidateQueries({ queryKey: ['quality', 'inspections', id] });
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Save failed');
    },
  });

  const complete = useMutation({
    mutationFn: () => inspectionsApi.complete(id),
    onSuccess: (insp) => {
      toast.success(`Inspection ${insp.status === 'passed' ? 'PASSED' : 'FAILED'}`);
      qc.invalidateQueries({ queryKey: ['quality', 'inspections', id] });
      setConfirmComplete(false);
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Could not complete');
    },
  });

  const cancel = useMutation({
    mutationFn: (reason: string) => inspectionsApi.cancel(id, reason),
    onSuccess: () => {
      toast.success('Inspection cancelled');
      qc.invalidateQueries({ queryKey: ['quality', 'inspections', id] });
      setConfirmCancel(false);
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Could not cancel');
    },
  });

  if (isLoading && !data) {
    return <SkeletonDetail />;
  }
  if (isError || !data) {
    return (
      <div>
        <EmptyState
          icon="alert-circle"
          title="Failed to load inspection"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      </div>
    );
  }

  // Group measurements by sample_index for a per-sample card.
  const grouped: Record<number, InspectionMeasurement[]> = {};
  for (const m of data.measurements ?? []) {
    (grouped[m.sample_index] ??= []).push(m);
  }
  const sampleIndices = Object.keys(grouped).map(Number).sort((a, b) => a - b);

  const isTerminal = ['passed', 'failed', 'cancelled'].includes(data.status);
  const dirtyCount = Object.values(drafts).filter((d) => d.dirty).length;
  const unresolvedCount = (data.measurements ?? []).filter((m) => m.is_pass === null).length;

  const updateDraft = (mId: string, patch: Partial<RowDraft>) => {
    setDrafts((s) => ({ ...s, [mId]: { ...s[mId], ...patch, dirty: true } }));
  };

  return (
    <div>
      <PageHeader
        title={
          <span>
            {data.inspection_number}
            <Chip variant={STATUS_CHIP[data.status]} className="ml-3">
              {data.status.replace('_', ' ')}
            </Chip>
          </span>
        }
        subtitle={
          data.product
            ? `${data.product.part_number} — ${data.product.name} (${data.stage.replace('_', '-')})`
            : data.stage
        }
        actions={
          <div className="flex items-center gap-2">
            {!isTerminal && can('quality.inspections.manage') && (
              <>
                <Button
                  variant="secondary"
                  size="sm"
                  icon={<Save size={14} />}
                  loading={save.isPending}
                  disabled={dirtyCount === 0}
                  onClick={() => save.mutate()}
                >
                  Save ({dirtyCount})
                </Button>
                <Button
                  variant="primary"
                  size="sm"
                  icon={<Check size={14} />}
                  disabled={unresolvedCount > 0}
                  onClick={() => setConfirmComplete(true)}
                >
                  Complete
                </Button>
                <Button
                  variant="secondary"
                  size="sm"
                  icon={<Ban size={14} />}
                  onClick={() => setConfirmCancel(true)}
                >
                  Cancel
                </Button>
              </>
            )}
          </div>
        }
      />

      {/* Sprint 7 audit fix: chain visualization for inspection (O2C step "QC Outgoing", P2P step "QC Incoming"). */}
      {data.stage === 'outgoing' && (
        <div className="px-5 py-3 border-b border-default">
          <ChainHeader
            steps={[
              { key: 'order',   label: 'Order',          state: 'done' },
              { key: 'mrp',     label: 'MRP planned',    state: 'done' },
              { key: 'wo',      label: 'In production',  state: 'done' },
              { key: 'qc',      label: 'QC outgoing',    state: data.status === 'passed' ? 'done' : data.status === 'failed' ? 'done' : 'active' },
              { key: 'deliver', label: 'Delivered',      state: 'pending' },
              { key: 'invoice', label: 'Invoiced',       state: 'pending' },
              { key: 'collect', label: 'Collected',      state: 'pending' },
            ]}
          />
        </div>
      )}
      {data.stage === 'incoming' && (
        <div className="px-5 py-3 border-b border-default">
          <ChainHeader
            steps={[
              { key: 'pr',     label: 'PR created',     state: 'done' },
              { key: 'po',     label: 'PO approved',    state: 'done' },
              { key: 'grn',    label: 'GRN received',   state: 'done' },
              { key: 'qc',     label: 'QC incoming',    state: data.status === 'passed' ? 'done' : 'active' },
              { key: 'bill',   label: 'Bill created',   state: 'pending' },
              { key: 'pay',    label: 'Payment',        state: 'pending' },
            ]}
          />
        </div>
      )}

      <div className="px-5 grid grid-cols-3 gap-4">
        <div className="col-span-2 space-y-4">
          <Panel title="Sample plan">
            <dl className="grid grid-cols-4 gap-x-4 gap-y-3 text-sm">
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Stage</dt>
                <dd className="font-mono">{data.stage}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Batch</dt>
                <dd className="font-mono tabular-nums">{data.batch_quantity}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Sample</dt>
                <dd className="font-mono tabular-nums">
                  {data.sample_size}
                  {data.aql_code ? <span className="ml-2 text-muted">[{data.aql_code}]</span> : null}
                </dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Ac / Re</dt>
                <dd className="font-mono tabular-nums">
                  {data.accept_count} / {data.reject_count}
                </dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Defects</dt>
                <dd
                  className={`font-mono tabular-nums ${
                    data.defect_count > data.accept_count ? 'text-danger' : ''
                  }`}
                >
                  {data.defect_count}
                </dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Inspector</dt>
                <dd>{data.inspector?.name ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Started</dt>
                <dd className="font-mono tabular-nums">{data.started_at?.slice(0, 16).replace('T', ' ') ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Completed</dt>
                <dd className="font-mono tabular-nums">{data.completed_at?.slice(0, 16).replace('T', ' ') ?? '—'}</dd>
              </div>
            </dl>
          </Panel>

          {sampleIndices.map((idx) => (
            <Panel
              key={idx}
              title={`Sample #${idx}`}
              meta={`${grouped[idx].length} parameter${grouped[idx].length === 1 ? '' : 's'}`}
              noPadding
            >
              <table className="w-full text-xs">
                <thead className="bg-subtle">
                  <tr>
                    <th className="px-2.5 py-2 text-left text-2xs uppercase tracking-wider text-muted font-medium">
                      Parameter
                    </th>
                    <th className="px-2.5 py-2 text-right text-2xs uppercase tracking-wider text-muted font-medium">
                      Nominal
                    </th>
                    <th className="px-2.5 py-2 text-right text-2xs uppercase tracking-wider text-muted font-medium">
                      Tolerance
                    </th>
                    <th className="px-2.5 py-2 text-right text-2xs uppercase tracking-wider text-muted font-medium">
                      Measured
                    </th>
                    <th className="px-2.5 py-2 text-center text-2xs uppercase tracking-wider text-muted font-medium">
                      Pass
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {grouped[idx].map((m) => {
                    const draft = drafts[m.id];
                    if (!draft) return null;
                    const numericTol =
                      m.tolerance_min !== null || m.tolerance_max !== null
                        ? `${m.tolerance_min ?? '−∞'} … ${m.tolerance_max ?? '+∞'}`
                        : '—';
                    return (
                      <tr key={m.id} className="border-t border-subtle">
                        <td className="px-2.5 py-2">
                          <div className="flex items-center gap-2">
                            <span>{m.parameter_name}</span>
                            {m.is_critical && <Chip variant="danger">Critical</Chip>}
                            <span className="text-2xs uppercase text-muted">{m.parameter_type}</span>
                          </div>
                        </td>
                        <td className="px-2.5 py-2 text-right font-mono tabular-nums">
                          {m.nominal_value ?? '—'} {m.unit_of_measure ?? ''}
                        </td>
                        <td className="px-2.5 py-2 text-right font-mono tabular-nums">{numericTol}</td>
                        <td className="px-2.5 py-2 text-right">
                          {m.parameter_type === 'visual' ? (
                            <span className="text-muted text-2xs">N/A</span>
                          ) : (
                            <input
                              type="number"
                              step="any"
                              disabled={isTerminal}
                              className="w-24 px-2 py-1 text-right font-mono tabular-nums border border-default rounded-md bg-canvas focus:outline-none focus:ring-2 focus:ring-accent"
                              value={draft.measured_value}
                              onChange={(e) =>
                                updateDraft(m.id, { measured_value: e.target.value })
                              }
                            />
                          )}
                        </td>
                        <td className="px-2.5 py-2 text-center">
                          {m.parameter_type === 'visual' ? (
                            <select
                              disabled={isTerminal}
                              className="px-2 py-1 text-xs border border-default rounded-md bg-canvas"
                              value={draft.is_pass === null ? '' : draft.is_pass ? 'pass' : 'fail'}
                              onChange={(e) =>
                                updateDraft(m.id, {
                                  is_pass:
                                    e.target.value === '' ? null : e.target.value === 'pass',
                                })
                              }
                            >
                              <option value="">—</option>
                              <option value="pass">Pass</option>
                              <option value="fail">Fail</option>
                            </select>
                          ) : draft.is_pass === null ? (
                            <span className="text-muted text-2xs">—</span>
                          ) : draft.is_pass ? (
                            <Chip variant="success">Pass</Chip>
                          ) : (
                            <Chip variant="danger">Fail</Chip>
                          )}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </Panel>
          ))}
        </div>

        <div className="space-y-4">
          <Panel title="Status">
            {isTerminal ? (
              <p className="text-sm">
                Inspection finalised on{' '}
                <span className="font-mono tabular-nums">
                  {data.completed_at?.slice(0, 16).replace('T', ' ')}
                </span>
                .
              </p>
            ) : unresolvedCount > 0 ? (
              <p className="text-sm text-muted">
                {unresolvedCount} measurement{unresolvedCount === 1 ? '' : 's'} still pending. Complete is disabled
                until every sampled unit has a pass/fail recorded.
              </p>
            ) : data.defect_count > data.accept_count ? (
              <p className="text-sm text-danger">
                Defects ({data.defect_count}) exceed Ac ({data.accept_count}). Completing now will mark this
                inspection as <strong>failed</strong>.
              </p>
            ) : (
              <p className="text-sm text-success">
                All measurements recorded within tolerance. Completing will mark this inspection as{' '}
                <strong>passed</strong>.
              </p>
            )}
          </Panel>

          {data.notes && (
            <Panel title="Notes">
              <p className="whitespace-pre-line text-sm">{data.notes}</p>
            </Panel>
          )}

          {/* Sprint 7 audit fix: LinkedRecords (Order-to-Cash chain) */}
          {data.product && (
            <Panel title="Linked records">
              <LinkedRecords
                groups={[
                  {
                    label: 'Product',
                    items: [{
                      id: `${data.product.part_number} — ${data.product.name}`,
                      href: `/crm/products/${data.product.id}`,
                    }],
                  },
                  ...(data.spec ? [{
                    label: 'Inspection spec',
                    items: [{
                      id: `v${data.spec.version}`,
                      href: `/quality/inspection-specs/${data.product.id}`,
                      meta: data.spec.is_active ? 'active' : 'archived',
                    }],
                  }] : []),
                ]}
              />
            </Panel>
          )}
          <Panel title="Actions">
            <Link to="/quality/inspections" className="text-xs text-accent hover:underline">
              ← Back to inspections
            </Link>
          </Panel>
        </div>
      </div>

      <ConfirmDialog
        isOpen={confirmComplete}
        title="Complete inspection?"
        description="This will finalise the inspection and lock all measurements. The system will compute pass/fail using the AQL plan."
        confirmLabel="Complete"
        onConfirm={() => complete.mutate()}
        onClose={() => setConfirmComplete(false)}
        pending={complete.isPending}
      />
      <ReasonDialog
        isOpen={confirmCancel}
        title="Cancel inspection?"
        description="Provide a brief reason. The cancellation will be appended to this inspection's notes."
        confirmLabel="Cancel inspection"
        onConfirm={(reason) => cancel.mutate(reason)}
        onClose={() => setConfirmCancel(false)}
        pending={cancel.isPending}
      />
    </div>
  );
}
