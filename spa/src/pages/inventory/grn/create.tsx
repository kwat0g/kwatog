import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { Trash2 } from 'lucide-react';
import { grnApi } from '@/api/inventory/grn';
import { purchaseOrdersApi } from '@/api/purchasing/purchase-orders';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { numberInputProps } from '@/lib/numberInput';
import type { CreateGrnData } from '@/types/inventory';

export default function CreateGrnPage() {
  const nav = useNavigate();
  const [search] = useSearchParams();
  const [poId, setPoId] = useState<string>(search.get('po_id') ?? '');
  const [poList, setPoList] = useState<{ id: string; po_number: string }[]>([]);
  const [items, setItems] = useState<Array<{
    purchase_order_item_id: number; item_id: string; item_code: string; item_name: string;
    location_id: string; quantity_received: string; unit_cost: string; remarks?: string;
  }>>([]);
  const [meta, setMeta] = useState({ received_date: new Date().toISOString().slice(0, 10), remarks: '' });

  // Load list of open POs.
  const { data: openPos } = useQuery({
    queryKey: ['purchasing', 'purchase-orders', 'open'],
    queryFn: () => purchaseOrdersApi.list({ status: 'sent' }),
  });
  useEffect(() => {
    if (openPos) setPoList(openPos.data.map((p) => ({ id: p.id, po_number: p.po_number })));
  }, [openPos]);

  const { data: po } = useQuery({
    queryKey: ['purchasing', 'purchase-orders', poId],
    queryFn: () => purchaseOrdersApi.show(poId),
    enabled: !!poId,
  });

  useEffect(() => {
    if (po && po.items) {
      setItems(po.items.map((l) => ({
        purchase_order_item_id: l.id,
        item_id: l.item.id, item_code: l.item.code, item_name: l.item.name,
        location_id: '',
        quantity_received: l.quantity_remaining,
        unit_cost: l.unit_price,
      })));
    }
  }, [po]);

  const m = useMutation({
    mutationFn: (d: CreateGrnData) => grnApi.create(d),
    onSuccess: (g) => { toast.success(`GRN ${g.grn_number} created. Pending QC.`); nav(`/inventory/grn/${g.id}`); },
    onError: (e: any) => toast.error(e?.response?.data?.message ?? 'Failed.'),
  });

  const submit = () => {
    if (!poId || items.length === 0) return;
    m.mutate({
      purchase_order_id: poId,
      received_date: meta.received_date,
      remarks: meta.remarks || undefined,
      items: items.filter((i) => Number(i.quantity_received) > 0 && i.location_id).map((i) => ({
        purchase_order_item_id: i.purchase_order_item_id,
        item_id: i.item_id,
        location_id: i.location_id,
        quantity_received: i.quantity_received,
        unit_cost: i.unit_cost,
        remarks: i.remarks,
      })),
    });
  };

  return (
    <div>
      <PageHeader title="New GRN" backTo="/inventory/grn" backLabel="GRNs" />
      <div className="max-w-5xl mx-auto px-5 py-6 space-y-4">
        <Panel title="Reference">
          <div className="grid grid-cols-3 gap-3">
            <div>
              <label className="text-xs text-muted font-medium block mb-1">Purchase order</label>
              <select className="h-8 w-full px-3 rounded-md border border-default bg-canvas text-sm font-mono"
                      value={poId} onChange={(e) => setPoId(e.target.value)}>
                <option value="">Select PO…</option>
                {poList.map((p) => <option key={p.id} value={p.id}>{p.po_number}</option>)}
              </select>
            </div>
            <Input label="Received date" type="date" value={meta.received_date}
                   onChange={(e) => setMeta({ ...meta, received_date: e.target.value })} required />
            <Input label="Remarks" value={meta.remarks}
                   onChange={(e) => setMeta({ ...meta, remarks: e.target.value })} />
          </div>
        </Panel>
        {po && (
          <Panel title={`Line items - PO ${po.po_number}`}
            >
            {items.length === 0
              ? <div className="text-sm text-muted">No outstanding lines on this PO.</div>
              : (
                <table className="w-full text-xs">
                  <thead><tr className="text-2xs uppercase tracking-wider text-muted">
                    <th className="text-left py-1">Item</th>
                    <th className="text-right">Receive qty</th>
                    <th className="text-right">Unit cost</th>
                    <th className="text-left">Location ID</th>
                    <th></th>
                  </tr></thead>
                  <tbody>
                    {items.map((line, i) => (
                      <tr key={line.purchase_order_item_id} className="h-9 border-t border-subtle">
                        <td>
                          <span className="font-mono">{line.item_code}</span>
                          <div className="text-2xs text-muted">{line.item_name}</div>
                        </td>
                        <td className="text-right">
                          <input className="h-7 w-24 px-2 rounded-sm border border-default text-right font-mono tabular-nums"
                                 type="text" value={line.quantity_received}
                                 {...numberInputProps()}
                                 onChange={(e) => setItems(items.map((it, k) => k === i ? { ...it, quantity_received: e.target.value } : it))} />
                        </td>
                        <td className="text-right">
                          <input className="h-7 w-24 px-2 rounded-sm border border-default text-right font-mono tabular-nums"
                                 type="text" value={line.unit_cost}
                                 {...numberInputProps()}
                                 onChange={(e) => setItems(items.map((it, k) => k === i ? { ...it, unit_cost: e.target.value } : it))} />
                        </td>
                        <td>
                          <input className="h-7 w-32 px-2 rounded-sm border border-default font-mono"
                                 type="text" placeholder="Location ID" value={line.location_id}
                                 onChange={(e) => setItems(items.map((it, k) => k === i ? { ...it, location_id: e.target.value } : it))} />
                        </td>
                        <td>
                          <button onClick={() => setItems(items.filter((_, k) => k !== i))} className="text-text-muted hover:text-danger">
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
          <Button variant="secondary" onClick={() => nav('/inventory/grn')}>Cancel</Button>
          <Button variant="primary" onClick={submit} disabled={!poId || items.length === 0 || m.isPending} loading={m.isPending}>
            Create GRN
          </Button>
        </div>
      </div>
    </div>
  );
}
