/** Sprint 8 — Task 70. Asset detail with depreciation history + dispose modal. */
import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import QRCode from 'qrcode';
import toast from 'react-hot-toast';
import { assetsApi } from '@/api/assets';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Panel } from '@/components/ui/Panel';
import { ReasonDialog } from '@/components/ui/ReasonDialog';
import { StatCard } from '@/components/ui/StatCard';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { LuPencil } from '@/lib/icons';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { formatPeso } from '@/lib/formatNumber';
import { localIsoDate } from '@/lib/formatDate';
import type { AssociateAssetData } from '@/types/assets';

const ASSOCIATION_LABELS: Record<AssociateAssetData['target_type'], string> = {
  machine: 'Machine',
  mold: 'Mold',
  vehicle: 'Vehicle',
};

function isOperationalCategory(value: string): value is AssociateAssetData['target_type'] {
  return value === 'machine' || value === 'mold' || value === 'vehicle';
}

export default function AssetDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { can } = usePermission();
  const [disposeOpen, setDisposeOpen] = useState(false);
  const [disposalAmount, setDisposalAmount] = useState<string>('');
  const [disposalDate, setDisposalDate] = useState(() => localIsoDate());
  const [disposalReason, setDisposalReason] = useState<string>('');
  const [qrImage, setQrImage] = useState<string | null>(null);
  const [qrError, setQrError] = useState(false);
  const [approveDisposalOpen, setApproveDisposalOpen] = useState(false);
  const [rejectDisposalOpen, setRejectDisposalOpen] = useState(false);
  const [cancelDisposalOpen, setCancelDisposalOpen] = useState(false);
  const [associationOpen, setAssociationOpen] = useState(false);
  const [clearAssociationOpen, setClearAssociationOpen] = useState(false);
  const [associationTargetId, setAssociationTargetId] = useState('');
  const [associationSearch, setAssociationSearch] = useState('');
  const [associationPage, setAssociationPage] = useState(1);
  const disposalError = !/^\d+(\.\d{1,2})?$/.test(disposalAmount)
    ? disposalAmount === ''
      ? 'Disposal proceeds is required.'
      : 'Enter a valid amount, up to 2 decimals.'
    : Number(disposalAmount) < 0
      ? 'Amount cannot be negative.'
    : undefined;
  const disposalReasonError = disposalReason.trim() === '' ? 'A disposal reason is required.' : undefined;
  const disposalDateError = disposalDate === '' ? 'Disposal date is required.' : undefined;

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['asset', id],
    queryFn: () => assetsApi.show(id),
  });

  const { data: qrData } = useQuery({
    queryKey: ['asset', id, 'qr'],
    queryFn: () => assetsApi.qr(id),
    enabled: !!id && !!data,
    staleTime: Infinity,
  });
  useEffect(() => {
    let active = true;
    setQrImage(null);
    setQrError(false);
    if (!qrData?.url) return undefined;

    QRCode.toDataURL(qrData.url, {
      errorCorrectionLevel: 'M',
      margin: 1,
      width: 320,
    })
      .then((dataUrl) => {
        if (active) setQrImage(dataUrl);
      })
      .catch(() => {
        if (active) setQrError(true);
      });

    return () => {
      active = false;
    };
  }, [qrData?.url]);
  const { data: assetOptions } = useQuery({
    queryKey: ['assets', 'options'],
    queryFn: assetsApi.options,
    staleTime: 300_000,
  });
  const statusLabel = assetOptions?.statuses?.find((option) => option.value === data?.status)?.label;
  const categoryLabel = assetOptions?.categories?.find(
    (option) => option.value === data?.category,
  )?.label;
  const associationType = data && isOperationalCategory(data.category) ? data.category : null;
  const canUpdateAsset = can('assets.update');

  const associationOptionsQuery = useQuery({
    queryKey: ['asset', id, 'association-options', associationSearch, associationPage],
    queryFn: () => assetsApi.associationOptions(id, {
      search: associationSearch.trim() || undefined,
      page: associationPage,
      per_page: 50,
    }),
    enabled: Boolean(associationOpen && associationType && canUpdateAsset && data && !data.association && !data.disposal_request && data.status !== 'disposed'),
    retry: false,
    staleTime: 30_000,
  });

  const dispose = useMutation({
    mutationFn: () => assetsApi.dispose(id, {
      disposal_amount: disposalAmount,
      disposed_date: disposalDate,
      remarks: disposalReason.trim(),
    }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['asset', id] });
      qc.invalidateQueries({ queryKey: ['assets'] });
      toast.success('Disposal request submitted for approval.');
      setDisposeOpen(false);
      setDisposalReason('');
    },
    onError: (error) => toast.error(isAxiosError(error) ? error.response?.data?.message ?? 'Failed to request asset disposal.' : 'Failed to request asset disposal.'),
  });

  const invalidateDisposal = () => {
    qc.invalidateQueries({ queryKey: ['asset', id] });
    qc.invalidateQueries({ queryKey: ['assets'] });
  };
  const disposalErrMsg = (error: unknown, fallback: string) =>
    (isAxiosError(error) ? error.response?.data?.message : undefined) ?? fallback;

  const approveDisposal = useMutation({
    mutationFn: () => assetsApi.approveDisposal(id),
    onSuccess: (asset) => {
      invalidateDisposal();
      toast.success(asset.status === 'disposed' ? 'Disposal approved. Journal entry posted.' : 'Disposal step approved.');
      setApproveDisposalOpen(false);
    },
    onError: (error) => toast.error(disposalErrMsg(error, 'Failed to approve disposal.')),
  });

  const rejectDisposal = useMutation({
    mutationFn: (reason: string) => assetsApi.rejectDisposal(id, reason),
    onSuccess: () => {
      invalidateDisposal();
      toast.success('Disposal request rejected. The asset stays active.');
      setRejectDisposalOpen(false);
    },
    onError: (error) => toast.error(disposalErrMsg(error, 'Failed to reject disposal.')),
  });

  const cancelDisposal = useMutation({
    mutationFn: () => assetsApi.cancelDisposal(id),
    onSuccess: () => {
      invalidateDisposal();
      toast.success('Disposal request cancelled.');
      setCancelDisposalOpen(false);
    },
    onError: (error) => toast.error(disposalErrMsg(error, 'Failed to cancel disposal request.')),
  });

  const updateAssociation = useMutation({
    mutationFn: (targetId: string | null) => {
      const targetType = data?.association?.type ?? associationType;
      if (!targetType) throw new Error('This asset category cannot be associated with an operational record.');
      return assetsApi.associate(id, { target_type: targetType, target_id: targetId });
    },
    onSuccess: (asset) => {
      qc.setQueryData(['asset', id], asset);
      qc.invalidateQueries({ queryKey: ['asset', id] });
      qc.invalidateQueries({ queryKey: ['asset', id, 'association-options'] });
      qc.invalidateQueries({ queryKey: ['assets'] });
      toast.success(asset.association ? 'Operational record linked.' : 'Operational record unlinked.');
      setAssociationOpen(false);
      setClearAssociationOpen(false);
      setAssociationTargetId('');
    },
    onError: (error) => toast.error(disposalErrMsg(error, 'Failed to update the operational association.')),
  });

  if (isLoading) return <SkeletonDetail />;
  if (isError || !data) {
    return (
      <EmptyState
        icon="alert-circle"
        title="Failed to load asset"
        action={
          <Button variant="secondary" onClick={() => refetch()}>
            Retry
          </Button>
        }
      />
    );
  }

  return (
    <div>
      <PageHeader
        title={data.asset_code}
        subtitle={data.name}
        backTo="/assets"
        backLabel="Assets"
        actions={
          <div className="flex gap-1.5 items-center">
            <Chip
              variant={
                data.status === 'active'
                  ? 'success'
                  : data.status === 'under_maintenance'
                    ? 'warning'
                    : 'neutral'
              }
            >
              {statusLabel ?? data.status}
            </Chip>
            {can('assets.update') && (
              <Button variant="secondary" size="xs" onClick={() => navigate(`/assets/${id}/edit`)}>
                <LuPencil className="h-3.5 w-3.5 mr-1" /> Edit
              </Button>
            )}
            {data.status !== 'disposed' && !data.disposal_request && !data.association && can('assets.dispose') && (
              <Button variant="danger" size="xs" onClick={() => setDisposeOpen(true)}>
                Dispose
              </Button>
            )}
          </div>
        }
      />

      {data.disposal_request && (
        <div className="px-5 pt-3">
          <Panel title="Disposal pending approval">
            <div className="space-y-2">
              <p className="text-sm text-secondary">
                A disposal of this asset is awaiting approval — the journal entry posts only
                after every step approves.
              </p>
              <dl className="text-sm divide-y divide-subtle">
                <Row label="Proceeds">
                  <span className="font-mono">{formatPeso(data.disposal_request.amount ?? '0')}</span>
                </Row>
                {data.disposal_request.date && <Row label="Disposal date">{data.disposal_request.date}</Row>}
                <Row label="Reason">{data.disposal_request.reason ?? '—'}</Row>
                <Row label="Requested by">{data.disposal_request.requested_by?.name ?? '—'}</Row>
              </dl>
              {data.approval_records && data.approval_records.length > 0 && (
                <ol className="space-y-1">
                  {data.approval_records.map((step) => (
                    <li key={step.step_order} className="flex items-center gap-2 text-xs">
                      <Chip variant={step.action === 'approved' ? 'success' : step.action === 'pending' ? 'info' : 'neutral'}>
                        {step.action}
                      </Chip>
                      <span className="font-mono text-muted">step {step.step_order}</span>
                      <span>{step.role_slug.replace(/_/g, ' ')}</span>
                      {step.approver && <span className="text-muted">— {step.approver.name}</span>}
                    </li>
                  ))}
                </ol>
              )}
              {(data.disposal_request.can_approve || data.disposal_request.can_cancel) && (
                <div className="flex flex-wrap gap-1.5 pt-1">
                  {data.disposal_request.can_approve && (
                    <>
                      <Button variant="secondary" size="xs" onClick={() => setRejectDisposalOpen(true)} loading={rejectDisposal.isPending}>
                        Reject
                      </Button>
                      <Button variant="primary" size="xs" onClick={() => setApproveDisposalOpen(true)} loading={approveDisposal.isPending}>
                        Approve
                      </Button>
                    </>
                  )}
                  {data.disposal_request.can_cancel && (
                    <Button variant="ghost" size="xs" onClick={() => setCancelDisposalOpen(true)} loading={cancelDisposal.isPending}>
                      Cancel request
                    </Button>
                  )}
                </div>
              )}
            </div>
          </Panel>
        </div>
      )}

      <div className="px-5 pt-3 pb-4 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-2">
        <StatCard label="Acquisition" value={formatPeso(data.acquisition_cost)} />
        <StatCard label="Accumulated dep." value={formatPeso(data.accumulated_depreciation)} />
        <StatCard label="Book value" value={formatPeso(data.book_value)} />
        <StatCard label="Monthly dep." value={formatPeso(data.monthly_depreciation)} />
      </div>

      <div className="px-5 pb-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <div className="col-span-2">
          <Panel
            title="Depreciation history"
            meta={data.depreciations?.length ? `${data.depreciations.length} months` : undefined}
          >
            {data.depreciations && data.depreciations.length > 0 ? (
              <table className={tableCls}>
                <thead>
                  <tr className={theadTrCls}>
                    <Th>Period</Th>
                    <Th align="right">Amount</Th>
                    <Th align="right">Accumulated</Th>
                  </tr>
                </thead>
                <tbody>
                  {data.depreciations.map((d) => (
                    <tr key={d.id} className={trCls}>
                      <Td mono>
                        {d.period_year}-{String(d.period_month).padStart(2, '0')}
                      </Td>
                      <Td align="right" mono>
                        {formatPeso(d.depreciation_amount)}
                      </Td>
                      <Td align="right" mono>
                        {formatPeso(d.accumulated_after)}
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : (
              <p className="text-sm text-muted">No depreciation posted yet.</p>
            )}
          </Panel>
        </div>
        <aside className="space-y-4">
          {(associationType || data.association) && (
            <Panel title="Operational association">
              {data.association ? (
                <div className="space-y-2">
                  <dl className="text-sm divide-y divide-subtle">
                    <Row label="Record type">{ASSOCIATION_LABELS[data.association.type]}</Row>
                    <Row label="Code"><span className="font-mono">{data.association.code}</span></Row>
                    <Row label="Name">{data.association.name}</Row>
                  </dl>
                  {data.association.archived && <p className="text-xs text-muted" role="status">This archived operational record remains linked until you clear the association.</p>}
                  {can('assets.dispose') && data.status !== 'disposed' && !data.disposal_request && (
                    <p className="text-xs text-muted" role="status">Clear this operational association before requesting disposal.</p>
                  )}
                  {canUpdateAsset && (
                    <Button
                      variant="secondary"
                      size="sm"
                      onClick={() => setClearAssociationOpen(true)}
                      loading={updateAssociation.isPending}
                    >
                      Clear association
                    </Button>
                  )}
                  {data.status === 'disposed' && (
                    <p className="text-xs text-muted" role="status">Disposed assets cannot be linked to new operational records. Existing links can be cleared.</p>
                  )}
                </div>
              ) : (
                <div className="space-y-2">
                  <p className="text-sm text-muted">No operational record linked.</p>
                  {data.disposal_request ? (
                    <p className="text-xs text-muted" role="status">New operational links are blocked while disposal approval is pending.</p>
                  ) : data.status === 'disposed' ? (
                    <p className="text-xs text-muted" role="status">Disposed assets cannot be associated with operational records.</p>
                  ) : canUpdateAsset && associationType ? (
                    <Button
                      variant="secondary"
                      size="sm"
                      onClick={() => {
                        setAssociationSearch('');
                        setAssociationPage(1);
                        setAssociationTargetId('');
                        setAssociationOpen(true);
                      }}
                    >
                      Associate {ASSOCIATION_LABELS[associationType].toLowerCase()}
                    </Button>
                  ) : null}
                </div>
              )}
            </Panel>
          )}

          <Panel title="Details">
            <dl className="text-sm divide-y divide-subtle">
              <Row label="Category">{data.category_label ?? categoryLabel ?? data.category}</Row>
              <Row label="Acquired">{data.acquisition_date}</Row>
              <Row label="Useful life">{data.useful_life_years} years</Row>
              <Row label="Salvage">
                <span className="font-mono">{formatPeso(data.salvage_value)}</span>
              </Row>
              <Row label="Location">{data.location ?? '—'}</Row>
              <Row label="Department">{data.department?.name ?? '—'}</Row>
              {data.disposed_date && <Row label="Disposed">{data.disposed_date}</Row>}
              {data.disposal_amount && (
                <Row label="Proceeds">
                  <span className="font-mono">{formatPeso(data.disposal_amount)}</span>
                </Row>
              )}
              {data.disposal_reason && <Row label="Disposal reason">{data.disposal_reason}</Row>}
            </dl>
          </Panel>

          {/* QR code */}
          {qrData && (
            <Panel title="QR code">
              <div className="flex flex-col items-center gap-3 py-2">
                {qrImage ? (
                  <img
                    src={qrImage}
                    alt={`QR for ${qrData.asset_code}`}
                    className="w-40 h-40 rounded border border-default"
                  />
                ) : qrData.url && !qrError ? (
                  <div className="w-40 h-40 rounded border border-default bg-elevated flex items-center justify-center text-xs text-muted">
                    Generating QR…
                  </div>
                ) : (
                  <div className="w-40 h-40 rounded border border-default bg-elevated flex items-center justify-center text-xs text-muted">
                    QR unavailable
                  </div>
                )}
                <p className="text-xs font-mono text-muted">{qrData.asset_code}</p>
                {qrImage && (
                  <a
                    href={qrImage}
                    download={`${qrData.asset_code}-qr.png`}
                    className="text-xs text-accent hover:underline"
                  >
                    Download QR
                  </a>
                )}
                {qrError && qrData.url && (
                  <a href={qrData.url} className="text-xs text-accent hover:underline" target="_blank" rel="noopener noreferrer">
                    Open asset link
                  </a>
                )}
              </div>
            </Panel>
          )}
        </aside>
      </div>

      {associationType && (
        <Modal
          isOpen={associationOpen}
          onClose={() => { if (!updateAssociation.isPending) setAssociationOpen(false); }}
          size="sm"
          title={`Associate ${ASSOCIATION_LABELS[associationType].toLowerCase()}`}
        >
          <div className="py-3 space-y-3">
            <p className="text-sm text-secondary">Choose an unassociated {ASSOCIATION_LABELS[associationType].toLowerCase()} record.</p>
            <Input
              label={`Search ${ASSOCIATION_LABELS[associationType].toLowerCase()} records`}
              value={associationSearch}
              onChange={(event) => {
                setAssociationSearch(event.target.value);
                setAssociationPage(1);
                setAssociationTargetId('');
              }}
            />
            {associationOptionsQuery.isLoading ? (
              <p className="text-sm text-muted" role="status">Loading records…</p>
            ) : associationOptionsQuery.isError ? (
              <EmptyState
                icon="alert-circle"
                title="Could not load operational records"
                action={<Button variant="secondary" onClick={() => associationOptionsQuery.refetch()}>Retry</Button>}
              />
            ) : associationOptionsQuery.data?.data.length ? (
              <>
                <Select
                  label={ASSOCIATION_LABELS[associationType]}
                  value={associationTargetId}
                  onChange={(event) => setAssociationTargetId(event.target.value)}
                >
                  <option value="">Select a record</option>
                  {associationOptionsQuery.data.data.map((target) => (
                    <option key={target.id} value={target.id}>{target.code} · {target.name}</option>
                  ))}
                </Select>
                {associationOptionsQuery.data.meta.last_page > 1 && (
                  <div className="flex items-center justify-between text-xs text-muted">
                    <Button
                      size="sm"
                      variant="ghost"
                      disabled={associationPage <= 1}
                      onClick={() => { setAssociationTargetId(''); setAssociationPage((page) => page - 1); }}
                    >Previous</Button>
                    <span>Page {associationOptionsQuery.data.meta.current_page} of {associationOptionsQuery.data.meta.last_page}</span>
                    <Button
                      size="sm"
                      variant="ghost"
                      disabled={associationPage >= associationOptionsQuery.data.meta.last_page}
                      onClick={() => { setAssociationTargetId(''); setAssociationPage((page) => page + 1); }}
                    >Next</Button>
                  </div>
                )}
              </>
            ) : (
              <p className="text-sm text-muted" role="status">No unassociated {ASSOCIATION_LABELS[associationType].toLowerCase()} records found.</p>
            )}
          </div>
          <ModalFooter>
            <Button variant="secondary" onClick={() => setAssociationOpen(false)} disabled={updateAssociation.isPending}>Cancel</Button>
            <Button
              variant="primary"
              onClick={() => updateAssociation.mutate(associationTargetId)}
              loading={updateAssociation.isPending}
              disabled={!associationTargetId || updateAssociation.isPending || !associationOptionsQuery.data?.data.length}
            >
              Save association
            </Button>
          </ModalFooter>
        </Modal>
      )}

      <ConfirmDialog
        isOpen={clearAssociationOpen}
        onClose={() => { if (!updateAssociation.isPending) setClearAssociationOpen(false); }}
        onConfirm={() => updateAssociation.mutate(null)}
        title="Clear operational association?"
        description={data.association ? `This removes the link to ${data.association.code} · ${data.association.name}. Both records remain.` : 'This removes the operational link. Both records remain.'}
        confirmLabel="Clear association"
        variant="warning"
        pending={updateAssociation.isPending}
      />

      <Modal
        isOpen={disposeOpen}
        onClose={() => setDisposeOpen(false)}
        size="sm"
        title="Dispose asset"
      >
        <div className="py-3 space-y-3">
          <p className="text-sm text-secondary">
            Submitting sends the disposal for approval. Once approved, a journal entry nets
            accumulated depreciation against the asset cost and books gain or loss against
            the proceeds.
          </p>
          <Input
            label="Disposal proceeds"
            value={disposalAmount}
            onChange={(e) => setDisposalAmount(e.target.value)}
            error={disposalError}
            prefix="₱"
            className="font-mono"
          />
          <Input
            label="Disposal date"
            type="date"
            min={data.acquisition_date.slice(0, 10)}
            max={localIsoDate()}
            value={disposalDate}
            onChange={(event) => setDisposalDate(event.target.value)}
            error={disposalDateError}
          />
          <Textarea
            label="Reason"
            value={disposalReason}
            onChange={(event) => setDisposalReason(event.target.value)}
            error={disposalReasonError}
            rows={3}
            placeholder="Why is this asset being disposed?"
            required
          />
        </div>
        <ModalFooter>
          <Button variant="secondary" onClick={() => setDisposeOpen(false)}>
            Cancel
          </Button>
          <Button
            variant="danger"
            onClick={() => dispose.mutate()}
            loading={dispose.isPending}
            disabled={!!disposalError || !!disposalDateError || !!disposalReasonError}
          >
            {dispose.isPending ? 'Submitting…' : 'Submit for approval'}
          </Button>
        </ModalFooter>
      </Modal>

      <ConfirmDialog
        isOpen={approveDisposalOpen}
        onClose={() => setApproveDisposalOpen(false)}
        onConfirm={() => approveDisposal.mutate()}
        title="Approve disposal?"
        description={
          <>
            Approving the final step posts the disposal journal entry and marks the asset
            disposed. This cannot be undone.
          </>
        }
        confirmLabel="Approve"
        variant="primary"
        pending={approveDisposal.isPending}
      />

      <ReasonDialog
        isOpen={rejectDisposalOpen}
        onClose={() => setRejectDisposalOpen(false)}
        onConfirm={(reason) => rejectDisposal.mutate(reason)}
        title="Reject disposal request"
        description="The asset stays active and no journal entry is posted."
        reasonLabel="Reason"
        reasonPlaceholder="e.g. Proceeds far below book value"
        confirmLabel="Reject"
        variant="danger"
        pending={rejectDisposal.isPending}
      />

      <ConfirmDialog
        isOpen={cancelDisposalOpen}
        onClose={() => setCancelDisposalOpen(false)}
        onConfirm={() => cancelDisposal.mutate()}
        title="Cancel disposal request?"
        description="The pending request is withdrawn. The asset stays active and no journal entry is posted."
        confirmLabel="Cancel request"
        variant="warning"
        pending={cancelDisposal.isPending}
      />
    </div>
  );
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex justify-between py-1.5">
      <span className="text-xs uppercase tracking-wider text-muted">{label}</span>
      <span>{children}</span>
    </div>
  );
}
