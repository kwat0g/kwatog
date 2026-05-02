import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery } from '@tanstack/react-query';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { Trash2 } from 'lucide-react';
import { grnApi } from '@/api/inventory/grn';
import { purchaseOrdersApi } from '@/api/purchasing/purchase-orders';
import { warehouseApi } from '@/api/inventory/warehouse';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { PageHeader } from '@/components/layout/PageHeader';
import { numberInputProps } from '@/lib/numberInput';
import type { ApiValidationError, ApiSuccess } from '@/types';
import type { CreateGrnData } from '@/types/inventory';

interface Line {
  purchase_order_item_id: string;
  item_id: string;
  item_code: string;
  item_name: string;
  ordered: string;
  remaining: string;
  location_id: string;
  quantity_received: string;
  unit_cost: string;
  remarks?: string;
}

interface FormErrors {
  poId?: string;
  received_date?: string;
  itemErrors: Record<number, { quantity?: string; location?: string }>;
}

export default function CreateGrnPage() {
  const nav = useNavigate();
  const [search] = useSearchParams();
  const [poId, setPoId] = useState<string>(search.get('po_id') ?? '');
  const [items, setItems] = useState<Line[]>([]);
  const [meta, setMeta] = useState({ received_date: new Date().toISOString().slice(0, 10), remarks: '' });
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [errors, setErrors] = useState<FormErrors>({ itemErrors: {} });

  const { data: openPos } = useQuery({
    queryKey: ['purchasing', 'purchase-orders', 'open-for-grn'],
    queryFn: () => purchaseOrdersApi.list({ status: 'sent', per_page: 100 }),
  });
  const poList = openPos?.data ?? [];

  const { data: po } = useQuery({
    queryKey: ['purchasing', 'purchase-orders', poId],
    queryFn: () => purchaseOrdersApi.show(poId),
    enabled: !!poId,
  });

  const { data: warehouses } = useQuery({
    queryKey: ['inventory', 'warehouse', 'tree'],
    queryFn: () => warehouseApi.tree(),
  });
  const locations = useMemo(
    () => (warehouses ?? []).flatMap((w) =>
      (w.zones ?? []).flatMap((z) => (z.locations ?? []).map((l) => ({
        id: l.id,
        label: `${w.code}-${z.code}-${l.code}`,
        sub: `${w.name} / ${z.name}`,
      }))),
    ),
    [warehouses],
  );

  useEffect(() => {
    if (po && po.items) {
      setItems(po.items
        .filter((l) => Number(l.quantity_remaining) > 0)
        .map((l) => ({
          purchase_order_item_id: String(l.id),
          item_id: l.item.id,
          item_code: l.item.code,
          item_name: l.item.name,
          ordered: l.quantity,
          remaining: l.quantity_remaining,
          location_id: '',
          quantity_received: l.quantity_remaining,
          unit_cost: l.unit_price,
        })));
    } else {
      setItems([]);
    }
  }, [po]);

  const validate = (): boolean => {
    const e: FormErrors = { itemErrors: {} };
    if (!poId) e.poId = 'Select a PO.';
    if (!meta.received_date) e.received_date = 'Date is required.';
    const filtered = items.filter((it) => Number(it.quantity_received) > 0);
    if (filtered.length === 0) {
      toast.error('At least one line must have a received quantity > 0.');
      setErrors(e);
      return false;
    }
    items.forEach((it, idx) => {
      const errs: { quantity?: string; location?: string } = {};
      const qty = Number(it.quantity_received);
      if (qty > 0) {
        if (qty > Number(it.remaining)) errs.quantity = `Cannot receive more than remaining (${it.remaining}).`;
        if (!it.location_id) errs.location = 'Required.';
      }
      if (Object.keys(errs).length > 0) e.itemErrors[idx] = errs;
    });
    setErrors(e);
    return !e.poId && !e.received_date && Object.keys(e.itemErrors).length === 0;
  };

  const m = useMutation({
    mutationFn: (d: CreateGrnData) => grnApi.create(d),
    onSuccess: (g) => {
      toast.success(`GRN ${g.grn_number} created. Pending QC.`);
      nav(`/inventory/grn/${g.id}`);
    },
    onError: (err: AxiosError<ApiValidationError | ApiSuccess<unknown>>) => {
      setConfirmOpen(false);
      const data = err.response?.data;
      if (err.response?.status === 422 && data && 'errors' in data && data.errors) {
        toast.error('The server flagged some fields. Please review.');
      } else {
        const msg = (data && 'message' in data ? data.message : undefined) ?? 'Failed to create GRN.';
        toast.error(msg);
      }
    },
  });

  const submit = () => {
    if (!validate()) return;
    setConfirmOpen(true);
  };

  const reallyCreate = () => {
    m.mutate({
      purchase_order_id: poId,
      received_date: meta.received_date,
      remarks: meta.remarks.trim() || undefined,
      items: items
        .filter((i) => Number(i.quantity_received) > 0 && i.location_id)
        .map((i) => ({
          purchase_order_item_id: i.purchase_order_item_id,
          item_id: i.item_id,
          location_id: i.location_id,
          quantity_received: i.quantity_received,
          unit_cost: i.unit_cost,
          remarks: i.remarks?.trim() || undefined,
        })),
    });
  };

  return (
    <div>
      <PageHeader title="New GRN" backTo="/inventory/grn" backLabel="GRNs" />
      <div className="max-w-5xl mx-auto px-5 py-6 space-y-4">
        <Panel title="Reference">
          <div className="grid grid-cols-3 gap-3">
            <Select
              label="Purchase order"
              required
              value={poId}
              onChange={(e) => setPoId(e.target.value)}
              error={errors.poId}
            >
              <option value="">Select PO…</option>
              {poList.map((p) => (
                <option key={p.id} value={p.id}>{p.po_number} — {p.vendor?.name ?? '—'}</option>
              ))}
            </Select>
            <Input
              label="Received date"
              type="date"
              required
              value={meta.received_date}
              onChange={(e) => setMeta({ ...meta, received_date: e.target.value })}
              error={errors.received_date}
            />
            <Input
              label="Remarks"
              maxLength={1000}
              value={meta.remarks}
              onChange={(e) => setMeta({ ...meta, remarks: e.target.value })}
              placeholder="Optional"
            />
          </div>
        </Panel>
        {po && (
          <Panel title={`Line items — PO ${po.po_number}`}>
            {items.length === 0 ? (
              <div className="text-sm text-muted">No outstanding lines on this PO.</div>
            ) : (
              <table className="w-full text-xs">
                <thead>
                  <tr className="text-2xs uppercase tracking-wider text-muted">
                    <th className="text-left py-1 font-medium">Item</th>
                    <th className="text-right font-medium">Ordered</th>
                    <th className="text-right font-medium">Remaining</th>
                    <th className="text-right font-medium">Receive qty</th>
                    <th className="text-right font-medium">Unit cost</th>
                    <th className="text-left font-medium">Location</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {items.map((line, i) => (
                    <tr key={line.purchase_order_item_id} className="h-9 border-t border-subtle align-top">
                      <td className="pt-2">
                        <span className="font-mono">{line.item_code}</span>
                        <div className="text-2xs text-muted">{line.item_name}</div>
                      </td>
                      <td className="text-right pt-2 font-mono tabular-nums">{Number(line.ordered).toFixed(2)}</td>
                      <td className="text-right pt-2 font-mono tabular-nums">{Number(line.remaining).toFixed(2)}</td>
                      <td className="text-right">
                        <input
                          className={`h-7 w-24 px-2 rounded-sm border text-right font-mono tabular-nums ${errors.itemErrors[i]?.quantity ? 'border-danger' : 'border-default'}`}
                          type="text"
                          value={line.quantity_received}
                          {...numberInputProps()}
                          onChange={(e) => setItems(items.map((it, k) => k === i ? { ...it, quantity_received: e.target.value } : it))}
                        />
                        {errors.itemErrors[i]?.quantity && (
                          <div className="text-2xs text-danger-fg mt-0.5">{errors.itemErrors[i].quantity}</div>
                        )}
                      </td>
                      <td className="text-right">
                        <input
                          className="h-7 w-24 px-2 rounded-sm border border-default text-right font-mono tabular-nums"
                          type="text"
                          value={line.unit_cost}
                          {...numberInputProps()}
                          onChange={(e) => setItems(items.map((it, k) => k === i ? { ...it, unit_cost: e.target.value } : it))}
                        />
                      </td>
                      <td>
                        <select
                          className={`h-7 w-44 px-1 rounded-sm border text-2xs ${errors.itemErrors[i]?.location ? 'border-danger' : 'border-default'}`}
                          value={line.location_id}
                          onChange={(e) => setItems(items.map((it, k) => k === i ? { ...it, location_id: e.target.value } : it))}
                        >
                          <option value="">Select location…</option>
                          {locations.map((l) => (
                            <option key={l.id} value={l.id}>{l.label}</option>
                          ))}
                        </select>
                        {errors.itemErrors[i]?.location && (
                          <div className="text-2xs text-danger-fg mt-0.5">{errors.itemErrors[i].location}</div>
                        )}
                      </td>
                      <td className="pt-1.5 text-right">
                        <button
                          type="button"
                          onClick={() => setItems(items.filter((_, k) => k !== i))}
                          className="p-1 text-text-muted hover:text-danger hover:bg-elevated rounded-sm"
                          aria-label="Remove line"
                        >
                          <Trash2 size={12} />
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </Panel>
        )}
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={() => nav('/inventory/grn')} disabled={m.isPending}>Cancel</Button>
          <Button variant="primary" onClick={submit} disabled={!poId || items.length === 0 || m.isPending} loading={m.isPending}>
            Create GRN
          </Button>
        </div>
      </div>

      <ConfirmDialog
        isOpen={confirmOpen}
        onClose={() => setConfirmOpen(false)}
        onConfirm={reallyCreate}
        title="Create this GRN?"
        description={
          po ? (
            <>
              Recording receipt of <span className="font-mono font-medium text-primary">{items.filter(i => Number(i.quantity_received) > 0).length}</span> line(s)
              against PO <span className="font-mono font-medium text-primary">{po.po_number}</span>.
              <br />
              The GRN starts in <span className="font-medium">pending QC</span>; stock will only update once accepted.
            </>
          ) : null
        }
        confirmLabel="Create GRN"
        variant="primary"
        pending={m.isPending}
      />
    </div>
  );
}
