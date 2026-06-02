import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import toast from 'react-hot-toast';
import { ArrowLeft, Plus, X, Send } from 'lucide-react';
import { customerPortalApi } from '@/api/b2b/customer';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import type { DeliveryScheduleLine } from '@/types/b2b';

const MONTH_OPTIONS: string[] = [];
const now = new Date();
for (let i = 0; i < 6; i++) {
  const d = new Date(now.getFullYear(), now.getMonth() + i, 1);
  MONTH_OPTIONS.push(d.toISOString().slice(0, 7));
}

export default function DeliverySchedulesPage() {
  const queryClient = useQueryClient();
  const [showForm, setShowForm] = useState(false);
  const [month, setMonth] = useState(MONTH_OPTIONS[0] ?? '');
  const [lines, setLines] = useState<DeliveryScheduleLine[]>([
    { product_name: '', quantity: 0, notes: '' },
  ]);

  const { data: schedules, isLoading } = useQuery({
    queryKey: ['portal', 'customer', 'delivery-schedules'],
    queryFn: () => customerPortalApi.listDeliverySchedules(),
  });

  const createMut = useMutation({
    mutationFn: () => customerPortalApi.createDeliverySchedule({ month, lines }),
    onSuccess: (res) => {
      toast.success(res.message ?? 'Delivery schedule submitted.');
      setShowForm(false);
      setLines([{ product_name: '', quantity: 0, notes: '' }]);
      queryClient.invalidateQueries({ queryKey: ['portal', 'customer', 'delivery-schedules'] });
    },
    onError: () => toast.error('Failed to submit delivery schedule.'),
  });

  const addLine = () => setLines([...lines, { product_name: '', quantity: 0, notes: '' }]);
  const removeLine = (idx: number) => {
    if (lines.length <= 1) return;
    setLines(lines.filter((_, i) => i !== idx));
  };
  const updateLine = (idx: number, field: keyof DeliveryScheduleLine, value: string | number) => {
    const updated = [...lines];
    updated[idx] = { ...updated[idx], [field]: value };
    setLines(updated);
  };

  if (isLoading) return <SkeletonBlock className="h-64 rounded-lg" />;

  return (
    <div className="space-y-4 max-w-5xl">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <Link to="/portal/customer" className="text-muted hover:text-primary p-1 -ml-1">
            <ArrowLeft size={16} />
          </Link>
          <h2 className="text-sm font-semibold">Delivery Schedules</h2>
        </div>
        <Button variant="primary" size="sm" icon={showForm ? <X size={14} /> : <Plus size={14} />} onClick={() => setShowForm(!showForm)}>
          {showForm ? 'Cancel' : 'New Schedule'}
        </Button>
      </div>

      {/* Submission form */}
      {showForm && (
        <Panel title="Submit Monthly Delivery Requirements">
          <form onSubmit={(e) => { e.preventDefault(); createMut.mutate(); }} className="flex flex-col gap-4">
            <div>
              <label className="text-2xs uppercase tracking-wide text-muted mb-1 block">Month</label>
              <select value={month} onChange={(e) => setMonth(e.target.value)}
                className="w-full rounded-md border border-border bg-canvas px-3 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-accent">
                {MONTH_OPTIONS.map((m) => (
                  <option key={m} value={m}>{m}</option>
                ))}
              </select>
            </div>

            <div className="space-y-2">
              <div className="flex items-center justify-between">
                <label className="text-2xs uppercase tracking-wide text-muted">Line Items</label>
                <Button type="button" variant="ghost" size="sm" icon={<Plus size={12} />} onClick={addLine}>
                  Add Item
                </Button>
              </div>
              {lines.map((line, idx) => (
                <div key={idx} className="flex items-start gap-2 p-2 bg-surface border border-default rounded-md">
                  <div className="flex-1 space-y-1.5">
                    <input
                      type="text"
                      placeholder="Product name"
                      value={line.product_name}
                      onChange={(e) => updateLine(idx, 'product_name', e.target.value)}
                      required
                      className="w-full rounded border border-border bg-canvas px-2 py-1 text-xs focus:outline-none focus:ring-1 focus:ring-accent"
                    />
                    <div className="flex gap-2">
                      <input
                        type="number"
                        placeholder="Qty"
                        value={line.quantity || ''}
                        onChange={(e) => updateLine(idx, 'quantity', parseFloat(e.target.value) || 0)}
                        required
                        min={0.01}
                        step={0.01}
                        className="w-24 rounded border border-border bg-canvas px-2 py-1 text-xs focus:outline-none focus:ring-1 focus:ring-accent"
                      />
                      <input
                        type="text"
                        placeholder="Notes (optional)"
                        value={line.notes ?? ''}
                        onChange={(e) => updateLine(idx, 'notes', e.target.value)}
                        className="flex-1 rounded border border-border bg-canvas px-2 py-1 text-xs focus:outline-none focus:ring-1 focus:ring-accent"
                      />
                    </div>
                  </div>
                  <button
                    type="button"
                    onClick={() => removeLine(idx)}
                    disabled={lines.length <= 1}
                    className="p-1 text-muted hover:text-danger transition-colors disabled:opacity-30"
                    aria-label="Remove line"
                  >
                    <X size={14} />
                  </button>
                </div>
              ))}
            </div>

            <Button type="submit" variant="primary" size="sm" icon={<Send size={14} />} loading={createMut.isPending}>
              Submit Schedule
            </Button>
          </form>
        </Panel>
      )}

      {/* Submitted schedules list */}
      <Panel title="Submitted Schedules">
        {schedules && schedules.length > 0 ? (
          <div className="space-y-3">
            {schedules.map((s) => (
              <div key={s.id} className="border border-default rounded-md p-3 hover:bg-subtle/50 transition-colors">
                <div className="flex items-center justify-between mb-2">
                  <span className="text-xs font-semibold">{s.month}</span>
                  <span className={`inline-block px-2 py-0.5 rounded-full text-2xs font-medium uppercase ${
                    s.status === 'confirmed' ? 'bg-success/10 text-success' :
                    'bg-subtle text-muted'
                  }`}>{s.status}</span>
                </div>
                <table className="w-full text-xs">
                  <thead>
                    <tr className="border-b border-border text-muted">
                      <th className="text-left py-1 px-2 font-medium">Product</th>
                      <th className="text-right py-1 px-2 font-medium">Qty</th>
                      <th className="text-left py-1 px-2 font-medium">Notes</th>
                    </tr>
                  </thead>
                  <tbody>
                    {s.lines.map((line, li) => (
                      <tr key={li} className="border-b border-border/50">
                        <td className="py-1.5 px-2">{line.product_name}</td>
                        <td className="py-1.5 px-2 text-right font-mono">{line.quantity}</td>
                        <td className="py-1.5 px-2 text-muted">{line.notes ?? '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                <p className="text-2xs text-muted mt-1.5">
                  Submitted {new Date(s.created_at).toLocaleDateString()}
                </p>
              </div>
            ))}
          </div>
        ) : (
          <EmptyState icon="clipboard-list" title="No schedules yet" description="Submit your monthly delivery requirements above." />
        )}
      </Panel>
    </div>
  );
}
