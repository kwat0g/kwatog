import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation } from '@tanstack/react-query';
import { Plus, Trash2 } from 'lucide-react';
import toast from 'react-hot-toast';
import { purchaseRequestsApi } from '@/api/purchasing/purchase-requests';
import { Button } from '@/components/ui/Button';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { PageHeader } from '@/components/layout/PageHeader';
import { numberInputProps } from '@/lib/numberInput';

interface Line {
  description: string; quantity: string; unit: string;
  estimated_unit_price: string; purpose: string; item_id?: string;
}

export default function CreatePurchaseRequestPage() {
  const nav = useNavigate();
  const [meta, setMeta] = useState({ priority: 'normal', reason: '' });
  const [lines, setLines] = useState<Line[]>([
    { description: '', quantity: '1', unit: 'pcs', estimated_unit_price: '0', purpose: '' },
  ]);

  const total = lines.reduce((sum, l) => sum + Number(l.quantity || 0) * Number(l.estimated_unit_price || 0), 0);

  const create = useMutation({
    mutationFn: (submitForApproval: boolean) => purchaseRequestsApi.create({
      reason: meta.reason || undefined,
      priority: meta.priority as any,
      items: lines.filter((l) => l.description && Number(l.quantity) > 0).map((l) => ({
        description: l.description, quantity: l.quantity, unit: l.unit,
        estimated_unit_price: l.estimated_unit_price, purpose: l.purpose || undefined,
        item_id: l.item_id || null,
      })),
    }).then(async (pr) => {
      if (submitForApproval) await purchaseRequestsApi.submit(pr.id);
      return pr;
    }),
    onSuccess: (pr) => { toast.success(`PR ${pr.pr_number} created.`); nav(`/purchasing/purchase-requests/${pr.id}`); },
    onError: (e: any) => toast.error(e?.response?.data?.message ?? 'Failed.'),
  });

  return (
    <div>
      <PageHeader title="New purchase request" backTo="/purchasing/purchase-requests" backLabel="Purchase requests" />
      <div className="max-w-5xl mx-auto px-5 py-6 space-y-4">
        <Panel title="Header">
          <div className="grid grid-cols-3 gap-3">
            <Select label="Priority" value={meta.priority} onChange={(e) => setMeta({ ...meta, priority: e.target.value })}>
              <option value="normal">Normal</option>
              <option value="urgent">Urgent</option>
              <option value="critical">Critical</option>
            </Select>
            <Textarea label="Reason" rows={2} className="col-span-2" value={meta.reason}
                      onChange={(e) => setMeta({ ...meta, reason: e.target.value })} />
          </div>
        </Panel>
        <Panel title="Line items" actions={
          <Button variant="secondary" size="sm" icon={<Plus size={12} />}
            onClick={() => setLines([...lines, { description: '', quantity: '1', unit: 'pcs', estimated_unit_price: '0', purpose: '' }])}>
            Add line
          </Button>
        }>
          <table className="w-full text-xs">
            <thead><tr className="text-2xs uppercase tracking-wider text-muted">
              <th className="text-left py-1">Item ID</th>
              <th className="text-left">Description</th>
              <th className="text-right">Qty</th>
              <th>Unit</th>
              <th className="text-right">Est. unit price</th>
              <th className="text-right">Total</th>
              <th></th>
            </tr></thead>
            <tbody>
              {lines.map((l, i) => (
                <tr key={i} className="h-9 border-t border-subtle">
                  <td><input className="h-7 w-24 px-2 rounded-sm border border-default font-mono text-xs"
                             value={l.item_id ?? ''} placeholder="hash"
                             onChange={(e) => setLines(lines.map((it, k) => k === i ? { ...it, item_id: e.target.value } : it))} /></td>
                  <td><input className="h-7 w-full px-2 rounded-sm border border-default text-xs"
                             value={l.description}
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
                           {...numberInputProps()} value={l.estimated_unit_price}
                           onChange={(e) => setLines(lines.map((it, k) => k === i ? { ...it, estimated_unit_price: e.target.value } : it))} />
                  </td>
                  <td className="text-right font-mono tabular-nums">
                    {(Number(l.quantity || 0) * Number(l.estimated_unit_price || 0)).toFixed(2)}
                  </td>
                  <td>
                    <button onClick={() => setLines(lines.filter((_, k) => k !== i))} className="text-text-muted hover:text-danger">
                      <Trash2 size={12} />
                    </button>
                  </td>
                </tr>
              ))}
              <tr className="border-t border-default font-medium">
                <td colSpan={5} className="text-right py-2">Estimated total</td>
                <td className="text-right font-mono tabular-nums">₱ {total.toFixed(2)}</td>
                <td></td>
              </tr>
            </tbody>
          </table>
        </Panel>
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={() => nav('/purchasing/purchase-requests')}>Cancel</Button>
          <Button variant="secondary" disabled={create.isPending} onClick={() => create.mutate(false)}>Save draft</Button>
          <Button variant="primary" disabled={create.isPending} loading={create.isPending} onClick={() => create.mutate(true)}>
            Submit for approval
          </Button>
        </div>
      </div>
    </div>
  );
}
