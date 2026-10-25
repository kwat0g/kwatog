import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Plus, Trash2 } from 'lucide-react';
import toast from 'react-hot-toast';
import { purchaseOrdersApi } from '@/api/purchasing/purchase-orders';
import { purchaseRequestsApi } from '@/api/purchasing/purchase-requests';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { Switch } from '@/components/ui/Switch';
import { Textarea } from '@/components/ui/Textarea';
import { PageHeader } from '@/components/layout/PageHeader';
import { numberInputProps } from '@/lib/numberInput';

interface Line {
  item_id: string; description: string; quantity: string; unit: string; unit_price: string;
}

export default function CreatePurchaseOrderPage() {
  const nav = useNavigate();
  const [search] = useSearchParams();
  const prId = search.get('pr_id');

  const [meta, setMeta] = useState({
    vendor_id: '', date: new Date().toISOString().slice(0, 10),
    expected_delivery_date: '', is_vatable: true, remarks: '',
  });
  const [lines, setLines] = useState<Line[]>([{ item_id: '', description: '', quantity: '1', unit: 'pcs', unit_price: '0' }]);

  const { data: pr } = useQuery({
    queryKey: ['purchasing', 'purchase-requests', prId],
    queryFn: () => purchaseRequestsApi.show(prId!),
    enabled: !!prId,
  });

  useEffect(() => {
    if (pr && pr.items) {
      setLines(pr.items.map((i) => ({
        item_id: i.item?.id ?? '',
        description: i.description, quantity: i.quantity, unit: i.unit ?? 'pcs',
        unit_price: i.estimated_unit_price ?? '0',
      })));
      setMeta((m) => ({ ...m, remarks: `Auto-generated from PR ${pr.pr_number}` }));
    }
  }, [pr]);

  const subtotal = lines.reduce((s, l) => s + Number(l.quantity || 0) * Number(l.unit_price || 0), 0);
  const vat = meta.is_vatable ? subtotal * 0.12 : 0;
  const total = subtotal + vat;
  const VP_THRESHOLD = 50000;
  const requiresVp = total >= VP_THRESHOLD;

  const create = useMutation({
    mutationFn: () => purchaseOrdersApi.create({
      vendor_id: meta.vendor_id,
      date: meta.date,
      expected_delivery_date: meta.expected_delivery_date || undefined,
      is_vatable: meta.is_vatable,
      remarks: meta.remarks || undefined,
      items: lines.filter((l) => l.item_id && Number(l.quantity) > 0).map((l) => ({
        item_id: l.item_id, description: l.description, quantity: l.quantity,
        unit: l.unit, unit_price: l.unit_price,
      })),
    }),
    onSuccess: (po) => { toast.success(`PO ${po.po_number} created.`); nav(`/purchasing/purchase-orders/${po.id}`); },
    onError: (e: any) => toast.error(e?.response?.data?.message ?? 'Failed.'),
  });

  return (
    <div>
      <PageHeader title="New purchase order" backTo="/purchasing/purchase-orders" backLabel="Purchase orders"
        actions={requiresVp ? <Chip>VP approval required</Chip> : null} />
      <div className="max-w-5xl mx-auto px-5 py-6 space-y-4">
        <Panel title="Header">
          <div className="grid grid-cols-3 gap-3">
            <Input label="Vendor ID (hash)" required value={meta.vendor_id}
                   onChange={(e) => setMeta({ ...meta, vendor_id: e.target.value })}
                   className="font-mono" />
            <Input label="Date" type="date" value={meta.date}
                   onChange={(e) => setMeta({ ...meta, date: e.target.value })} required />
            <Input label="Expected delivery" type="date" value={meta.expected_delivery_date}
                   onChange={(e) => setMeta({ ...meta, expected_delivery_date: e.target.value })} />
            <Switch label="VAT-able (12%)" checked={meta.is_vatable}
                    onChange={(e) => setMeta({ ...meta, is_vatable: e.target.checked })} />
            <Textarea label="Remarks" rows={2} className="col-span-2" value={meta.remarks}
                      onChange={(e) => setMeta({ ...meta, remarks: e.target.value })} />
          </div>
        </Panel>
        <Panel title="Line items" actions={
          <Button size="sm" variant="secondary" icon={<Plus size={12} />}
            onClick={() => setLines([...lines, { item_id: '', description: '', quantity: '1', unit: 'pcs', unit_price: '0' }])}>
            Add line
          </Button>
        }>
          <table className="w-full text-xs">
            <thead><tr className="text-2xs uppercase tracking-wider text-muted">
              <th className="text-left py-1">Item ID</th>
              <th>Description</th>
              <th className="text-right">Qty</th>
              <th>Unit</th>
              <th className="text-right">Unit price</th>
              <th className="text-right">Total</th>
              <th></th>
            </tr></thead>
            <tbody>
              {lines.map((l, i) => (
                <tr key={i} className="h-9 border-t border-subtle">
                  <td><input className="h-7 w-24 px-2 rounded-sm border border-default font-mono text-xs" value={l.item_id}
                    onChange={(e) => setLines(lines.map((it, k) => k === i ? { ...it, item_id: e.target.value } : it))} /></td>
                  <td><input className="h-7 w-full px-2 rounded-sm border border-default text-xs" value={l.description}
                    onChange={(e) => setLines(lines.map((it, k) => k === i ? { ...it, description: e.target.value } : it))} /></td>
                  <td className="text-right">
                    <input className="h-7 w-20 px-2 rounded-sm border border-default text-right font-mono tabular-nums" type="text"
                           {...numberInputProps()} value={l.quantity}
                           onChange={(e) => setLines(lines.map((it, k) => k === i ? { ...it, quantity: e.target.value } : it))} />
                  </td>
                  <td><input className="h-7 w-16 px-2 rounded-sm border border-default text-xs" value={l.unit}
                    onChange={(e) => setLines(lines.map((it, k) => k === i ? { ...it, unit: e.target.value } : it))} /></td>
                  <td className="text-right">
                    <input className="h-7 w-24 px-2 rounded-sm border border-default text-right font-mono tabular-nums" type="text"
                           {...numberInputProps()} value={l.unit_price}
                           onChange={(e) => setLines(lines.map((it, k) => k === i ? { ...it, unit_price: e.target.value } : it))} />
                  </td>
                  <td className="text-right font-mono tabular-nums">
                    {(Number(l.quantity || 0) * Number(l.unit_price || 0)).toFixed(2)}
                  </td>
                  <td><button onClick={() => setLines(lines.filter((_, k) => k !== i))} className="text-text-muted hover:text-danger"><Trash2 size={12} /></button></td>
                </tr>
              ))}
              <tr className="border-t border-default">
                <td colSpan={5} className="text-right py-1.5 text-muted">Subtotal</td>
                <td className="text-right font-mono tabular-nums">₱ {subtotal.toFixed(2)}</td>
                <td></td>
              </tr>
              {meta.is_vatable && (
                <tr>
                  <td colSpan={5} className="text-right py-1 text-muted">VAT (12%)</td>
                  <td className="text-right font-mono tabular-nums">₱ {vat.toFixed(2)}</td>
                  <td></td>
                </tr>
              )}
              <tr className="border-t border-default font-medium">
                <td colSpan={5} className="text-right py-2">Total</td>
                <td className="text-right font-mono tabular-nums">₱ {total.toFixed(2)}</td>
                <td></td>
              </tr>
            </tbody>
          </table>
        </Panel>
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={() => nav('/purchasing/purchase-orders')}>Cancel</Button>
          <Button variant="primary" onClick={() => create.mutate()}
                  disabled={!meta.vendor_id || lines.length === 0 || create.isPending} loading={create.isPending}>
            Create PO
          </Button>
        </div>
      </div>
    </div>
  );
}

function Chip({ children }: { children: React.ReactNode }) {
  return <span className="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-warning-bg text-warning-fg">{children}</span>;
}
