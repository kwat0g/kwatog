import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { grnApi, type ReceiveGoodsData } from '@/api/inventory/grn';
import { purchaseOrdersApi } from '@/api/purchasing/purchase-orders';
import { warehouseApi } from '@/api/inventory/warehouse';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonForm } from '@/components/ui/Skeleton';
import { Modal } from '@/components/ui/Modal';
import { numberInputProps } from '@/lib/numberInput';
import type { ApiSuccess, ApiValidationError } from '@/types';

/* ── Local types ─────────────────────────────────────────────────────── */

interface Line {
  purchase_order_item_id: string;
  item_id: string;
  item_code: string;
  item_name: string;
  uom: string;
  ordered: string;
  remaining: string;
  location_id: string;
  quantity_received: string;
  unit_cost: string;
  condition: 'good' | 'damaged' | 'short';
  remarks: string;
}

const qcResultOptions = [
  { value: 'passed', label: 'Pass' },
  { value: 'passed_with_remarks', label: 'Pass with remarks' },
  { value: 'failed', label: 'Fail' },
] as const;

const dispositionOptions = [
  { value: 'return_to_supplier', label: 'Return to supplier' },
  { value: 'use_under_concession', label: 'Use under concession (needs approval)' },
  { value: 'partial_accept', label: 'Partial accept' },
] as const;

/* ── Component ───────────────────────────────────────────────────────── */

export default function ReceiveGoodsPage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [searchParams] = useSearchParams();
  const poId = searchParams.get('po') ?? '';

  /* Result modal state */
  const [resultData, setResultData] = useState<{
    grn_number: string;
    qc_result: string;
    stock_updated: boolean;
    grn_id: string;
  } | null>(null);

  /* ── PO fetch ────────────────────────────────────────────────────── */

  const {
    data: po,
    isLoading: poLoading,
    isError: poError,
  } = useQuery({
    queryKey: ['purchasing', 'purchase-orders', poId],
    queryFn: () => purchaseOrdersApi.show(poId),
    enabled: !!poId,
  });

  /* ── Warehouse locations ─────────────────────────────────────────── */

  const { data: warehouses } = useQuery({
    queryKey: ['inventory', 'warehouse', 'tree'],
    queryFn: () => warehouseApi.tree(),
  });

  const locations = useMemo(
    () =>
      (warehouses ?? []).flatMap((w) =>
        (w.zones ?? []).flatMap((z) =>
          (z.locations ?? []).map((l) => ({
            id: l.id,
            label: `${w.code}-${z.code}-${l.code}`,
            sub: `${w.name} / ${z.name}`,
          })),
        ),
      ),
    [warehouses],
  );

  /* ── Form state ──────────────────────────────────────────────────── */

  const [items, setItems] = useState<Line[]>([]);
  const [deliveryNote, setDeliveryNote] = useState('');
  const [supplierLotNo, setSupplierLotNo] = useState('');

  // QC section
  const [qcResult, setQcResult] = useState<'passed' | 'failed' | 'passed_with_remarks'>('passed');
  const [qcRemarks, setQcRemarks] = useState('');
  const [qcFailureReason, setQcFailureReason] = useState('');
  const [qcDisposition, setQcDisposition] = useState('return_to_supplier');

  /* Seed items from PO once loaded */
  useEffect(() => {
    if (po && po.items) {
      setItems(
        po.items
          .filter((l) => Number(l.quantity_remaining) > 0)
          .map((l) => ({
            purchase_order_item_id: String(l.id),
            item_id: l.item.id,
            item_code: l.item.code,
            item_name: l.item.name,
            uom: l.item.unit_of_measure ?? '',
            ordered: l.quantity,
            remaining: l.quantity_remaining,
            location_id: '',
            quantity_received: l.quantity_remaining,
            unit_cost: l.unit_price,
            condition: 'good' as const,
            remarks: '',
          })),
      );
    } else {
      setItems([]);
    }
  }, [po]);

  /* ── Mutation ─────────────────────────────────────────────────────── */

  const mutation = useMutation({
    mutationFn: (data: ReceiveGoodsData) => grnApi.receiveGoods(data),
    onSuccess: (result) => {
      queryClient.invalidateQueries({ queryKey: ['inventory'] });
      queryClient.invalidateQueries({ queryKey: ['grn'] });
      queryClient.invalidateQueries({ queryKey: ['purchasing'] });
      setResultData({
        grn_number: result.data.grn_number,
        qc_result: result.qc_result,
        stock_updated: result.stock_updated,
        grn_id: result.data.id,
      });
      toast.success(`GRN ${result.data.grn_number} created.`);
    },
    onError: (e: AxiosError<ApiValidationError | ApiSuccess<unknown>>) => {
      const data = e.response?.data;
      if (e.response?.status === 422 && data && 'errors' in data && data.errors) {
        toast.error('Some fields need attention. Please review.');
      } else {
        const msg = (data && 'message' in data ? data.message : undefined) ?? 'Failed to receive goods.';
        toast.error(msg);
      }
    },
  });

  /* ── Submit ──────────────────────────────────────────────────────── */

  const handleSubmit = () => {
    if (!po || !poId) return;

    const filtered = items
      .filter((it) => Number(it.quantity_received) > 0)
      .map((it) => ({
        purchase_order_item_id: it.purchase_order_item_id,
        item_id: it.item_id,
        location_id: it.location_id,
        quantity_received: it.quantity_received,
        unit_cost: it.unit_cost,
        remarks: it.remarks.trim() || undefined,
      }));

    if (filtered.length === 0) {
      toast.error('At least one line must have a received quantity > 0.');
      return;
    }

    const missingLoc = items.some((it) => Number(it.quantity_received) > 0 && !it.location_id);
    if (missingLoc) {
      toast.error('Select a warehouse location for every received line.');
      return;
    }

    const overReceived = items.some(
      (it) => Number(it.quantity_received) > Number(it.remaining),
    );
    if (overReceived) {
      toast.error('Cannot receive more than the remaining quantity.');
      return;
    }

    mutation.mutate({
      purchase_order_id: poId,
      remarks: [supplierLotNo && `Lot: ${supplierLotNo}`, deliveryNote && `DN: ${deliveryNote}`]
        .filter(Boolean)
        .join(' | ') || undefined,
      items: filtered,
      qc: {
        result: qcResult,
        remarks: qcRemarks.trim() || undefined,
        failure_reason: qcResult === 'failed' ? qcFailureReason.trim() || undefined : undefined,
        disposition: qcResult === 'failed' ? qcDisposition : undefined,
      },
    });
  };

  /* ── No PO selected ──────────────────────────────────────────────── */

  if (!poId) {
    return (
      <div>
        <PageHeader title="Receive Goods" backTo="/inventory/grn" backLabel="GRN" />
        <EmptyState
          icon="package"
          title="Select a Purchase Order"
          description="Navigate from a Purchase Order to receive goods."
        />
      </div>
    );
  }

  /* ── Loading ─────────────────────────────────────────────────────── */

  if (poLoading) {
    return (
      <div>
        <PageHeader title="Receive Goods" backTo="/inventory/grn" backLabel="GRN" />
        <SkeletonForm />
      </div>
    );
  }

  /* ── Error ───────────────────────────────────────────────────────── */

  if (poError || !po) {
    return (
      <div>
        <PageHeader title="Receive Goods" backTo="/inventory/grn" backLabel="GRN" />
        <EmptyState
          icon="alert-circle"
          title="Failed to load Purchase Order"
          description="The PO may not exist or you may not have permission to view it."
        />
      </div>
    );
  }

  /* ── Main render ─────────────────────────────────────────────────── */

  return (
    <div>
      <PageHeader
        title={
          <>
            Receive Goods &mdash; <span className="font-mono">{po.po_number}</span>
          </>
        }
        subtitle={po.vendor?.name}
        backTo="/inventory/grn"
        backLabel="GRN"
        breadcrumbs={[
          { label: 'Inventory' },
          { label: 'GRN', href: '/inventory/grn' },
          { label: 'Receive Goods' },
        ]}
      />

      <div className="max-w-4xl mx-auto px-5 py-6 space-y-6">
        {/* ── STEP 1: What did we receive? ───────────────────────────── */}
        <Panel title="Step 1 -- What did we receive?" meta={`${items.length} item(s)`} noPadding>
          {items.length === 0 ? (
            <div className="p-4 text-sm text-muted">No outstanding lines on this PO.</div>
          ) : (
            <table className="w-full text-xs">
              <thead>
                <tr className="text-2xs uppercase tracking-wider text-muted bg-subtle">
                  <th className="text-left font-medium px-2.5 py-2">Item</th>
                  <th className="text-right font-medium px-2.5 py-2">Remaining</th>
                  <th className="text-right font-medium px-2.5 py-2 w-28">Received</th>
                  <th className="text-left font-medium px-2.5 py-2 w-44">Location</th>
                  <th className="text-left font-medium px-2.5 py-2 w-24">Condition</th>
                </tr>
              </thead>
              <tbody>
                {items.map((line, i) => (
                  <tr key={line.purchase_order_item_id} className="border-t border-subtle align-top">
                    <td className="px-2.5 py-2">
                      <div className="font-mono text-xs">{line.item_code}</div>
                      <div className="text-2xs text-muted">{line.item_name}</div>
                    </td>
                    <td className="px-2.5 py-2 text-right font-mono tabular-nums">
                      {Number(line.remaining).toFixed(2)} {line.uom}
                    </td>
                    <td className="px-2.5 py-2">
                      <input
                        type="text"
                        className="w-full h-7 px-2 rounded-sm border border-default bg-canvas text-sm font-mono tabular-nums text-right"
                        value={line.quantity_received}
                        {...numberInputProps()}
                        onChange={(e) =>
                          setItems(items.map((it, k) => (k === i ? { ...it, quantity_received: e.target.value } : it)))
                        }
                      />
                    </td>
                    <td className="px-2.5 py-2">
                      <select
                        className="w-full h-7 px-1 rounded-sm border border-default text-2xs"
                        value={line.location_id}
                        onChange={(e) =>
                          setItems(items.map((it, k) => (k === i ? { ...it, location_id: e.target.value } : it)))
                        }
                      >
                        <option value="">Select location...</option>
                        {locations.map((l) => (
                          <option key={l.id} value={l.id}>
                            {l.label}
                          </option>
                        ))}
                      </select>
                    </td>
                    <td className="px-2.5 py-2">
                      <select
                        className="w-full h-7 px-1 rounded-sm border border-default text-2xs"
                        value={line.condition}
                        onChange={(e) =>
                          setItems(
                            items.map((it, k) =>
                              k === i ? { ...it, condition: e.target.value as Line['condition'] } : it,
                            ),
                          )
                        }
                      >
                        <option value="good">Good</option>
                        <option value="damaged">Damaged</option>
                        <option value="short">Short</option>
                      </select>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
          <div className="grid grid-cols-2 gap-3 px-4 py-3 border-t border-default">
            <div>
              <label className="text-xs text-muted font-medium block mb-1">Supplier Lot No.</label>
              <input
                className="w-full h-8 px-3 rounded-sm border border-default bg-canvas text-sm"
                value={supplierLotNo}
                onChange={(e) => setSupplierLotNo(e.target.value)}
                placeholder="e.g. SL-TW-0456"
              />
            </div>
            <div>
              <label className="text-xs text-muted font-medium block mb-1">Delivery Note</label>
              <input
                className="w-full h-8 px-3 rounded-sm border border-default bg-canvas text-sm"
                value={deliveryNote}
                onChange={(e) => setDeliveryNote(e.target.value)}
                placeholder="e.g. TW-DN-20260408"
              />
            </div>
          </div>
        </Panel>

        {/* ── STEP 2: QC Incoming Inspection ─────────────────────────── */}
        <Panel title="Step 2 -- QC Incoming Inspection">
          <div className="space-y-3 text-sm">
            <div className="grid grid-cols-3 gap-3">
              <div>
                <label className="text-xs text-muted font-medium block mb-1">QC Decision</label>
                <select
                  className="w-full h-8 px-3 rounded-sm border border-default bg-canvas text-sm"
                  value={qcResult}
                  onChange={(e) => setQcResult(e.target.value as typeof qcResult)}
                >
                  {qcResultOptions.map((o) => (
                    <option key={o.value} value={o.value}>
                      {o.label}
                    </option>
                  ))}
                </select>
              </div>
            </div>
            <div>
              <label className="text-xs text-muted font-medium block mb-1">QC Remarks</label>
              <textarea
                className="w-full h-20 px-3 py-2 rounded-sm border border-default bg-canvas text-sm resize-none"
                value={qcRemarks}
                onChange={(e) => setQcRemarks(e.target.value)}
                placeholder="Inspection notes..."
              />
            </div>
            {qcResult === 'failed' && (
              <div className="space-y-3 p-3 border border-danger/30 rounded-md bg-danger-bg/10">
                <div>
                  <label className="text-xs text-danger-fg font-medium block mb-1">Failure Reason</label>
                  <textarea
                    className="w-full h-16 px-3 py-2 rounded-sm border border-danger/30 bg-canvas text-sm resize-none"
                    value={qcFailureReason}
                    onChange={(e) => setQcFailureReason(e.target.value)}
                    placeholder="Describe QC failure..."
                  />
                </div>
                <div>
                  <label className="text-xs text-danger-fg font-medium block mb-1">Disposition</label>
                  <select
                    className="w-full h-8 px-3 rounded-sm border border-danger/30 bg-canvas text-sm"
                    value={qcDisposition}
                    onChange={(e) => setQcDisposition(e.target.value)}
                  >
                    {dispositionOptions.map((o) => (
                      <option key={o.value} value={o.value}>
                        {o.label}
                      </option>
                    ))}
                  </select>
                </div>
              </div>
            )}
          </div>
        </Panel>

        {/* ── STEP 3: Review & Confirm ───────────────────────────────── */}
        <Panel title="Step 3 -- Review & Confirm">
          <div className="space-y-3 text-sm">
            <div className="grid grid-cols-2 gap-4">
              <div>
                <span className="text-muted">QC Result:</span>{' '}
                <Chip variant={qcResult === 'failed' ? 'danger' : 'success'}>
                  {qcResult === 'passed' ? 'Pass' : qcResult === 'passed_with_remarks' ? 'Pass (remarks)' : 'Fail'}
                </Chip>
              </div>
              <div>
                <span className="text-muted">Stock update:</span>{' '}
                <span className="font-medium">
                  {qcResult === 'failed' ? 'No -- pending disposition' : 'Yes -- inventory will increase'}
                </span>
              </div>
            </div>
            {qcResult !== 'failed' && (
              <div className="border border-default rounded-md p-3">
                <h4 className="text-2xs uppercase tracking-wider text-muted font-medium mb-2">Stock Changes</h4>
                {items
                  .filter((it) => Number(it.quantity_received) > 0)
                  .map((line) => (
                    <div key={line.purchase_order_item_id} className="flex justify-between text-xs py-1">
                      <span>{line.item_name}</span>
                      <span className="font-mono tabular-nums text-success-fg">
                        +{Number(line.quantity_received).toFixed(2)} {line.uom}
                      </span>
                    </div>
                  ))}
              </div>
            )}
            {qcResult === 'failed' && (
              <div className="p-3 border border-danger/30 rounded-md text-sm">
                <p className="text-danger-fg font-medium">QC Failed -- NCR will be auto-created</p>
                <p className="text-muted mt-1">Stock will NOT be updated until disposition is resolved.</p>
              </div>
            )}
          </div>
        </Panel>

        {/* ── Action bar ─────────────────────────────────────────────── */}
        <div className="flex items-center justify-end gap-2 pt-4 border-t border-default">
          <Button variant="secondary" onClick={() => navigate('/inventory/grn')} disabled={mutation.isPending}>
            Cancel
          </Button>
          <Button
            variant={qcResult === 'failed' ? 'danger' : 'primary'}
            onClick={handleSubmit}
            disabled={mutation.isPending || items.length === 0}
            loading={mutation.isPending}
          >
            {mutation.isPending
              ? 'Processing...'
              : qcResult === 'failed'
                ? 'Record Failure & Create NCR'
                : 'Create GRN & Update Inventory'}
          </Button>
        </div>
      </div>

      {/* ── Result modal ───────────────────────────────────────────── */}
      <Modal
        isOpen={!!resultData}
        onClose={() => {
          setResultData(null);
          navigate('/inventory/grn');
        }}
        title="Goods Received"
        size="sm"
      >
        {resultData && (
          <div className="py-4 space-y-3">
            <div className="text-sm">
              {resultData.stock_updated ? (
                <span className="text-success-fg font-medium">
                  {resultData.grn_number} created -- inventory updated
                </span>
              ) : (
                <span className="text-warning-fg font-medium">
                  {resultData.grn_number} created -- QC {resultData.qc_result}
                </span>
              )}
            </div>
            <div className="flex justify-end gap-2 pt-3 border-t border-default">
              <Button
                variant="secondary"
                size="sm"
                onClick={() => {
                  const id = resultData.grn_id;
                  setResultData(null);
                  navigate(`/inventory/grn/${id}`);
                }}
              >
                View GRN
              </Button>
              <Button
                variant="primary"
                size="sm"
                onClick={() => {
                  setResultData(null);
                  navigate('/inventory/grn');
                }}
              >
                Done
              </Button>
            </div>
          </div>
        )}
      </Modal>
    </div>
  );
}
