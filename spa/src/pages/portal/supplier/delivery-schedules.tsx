import { useCallback, useEffect, useState } from 'react';
import { supplierPortalApi } from '@/api/b2b/supplier';
import type { DeliverySchedule, PortalPoSummary } from '@/types/b2b';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';

interface ScheduleForm {
  purchase_order_id: string;
  month: string;
  lines: Array<{ product_name: string; quantity: number; notes: string }>;
}

export default function SupplierDeliverySchedulesPage() {
  const [schedules, setSchedules] = useState<DeliverySchedule[]>([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [pos, setPos] = useState<PortalPoSummary[]>([]);

  const [form, setForm] = useState<ScheduleForm>({
    purchase_order_id: '',
    month: new Date().toISOString().slice(0, 7),
    lines: [{ product_name: '', quantity: 0, notes: '' }],
  });

  const fetchSchedules = useCallback(async () => {
    setLoading(true);
    try {
      const data = await supplierPortalApi.listDeliverySchedules();
      setSchedules(data);
    } finally {
      setLoading(false);
    }
  }, []);

  const fetchPos = useCallback(async () => {
    try {
      const data = await supplierPortalApi.listPos({ status: 'sent' });
      setPos(data);
    } catch { /* ignore */ }
  }, []);

  useEffect(() => {
    fetchSchedules();
    fetchPos();
  }, [fetchSchedules, fetchPos]);

  const handleAddLine = () => {
    setForm((prev) => ({
      ...prev,
      lines: [...prev.lines, { product_name: '', quantity: 0, notes: '' }],
    }));
  };

  const handleRemoveLine = (idx: number) => {
    setForm((prev) => ({
      ...prev,
      lines: prev.lines.filter((_, i) => i !== idx),
    }));
  };

  const handleLineChange = (idx: number, field: string, value: string | number) => {
    setForm((prev) => ({
      ...prev,
      lines: prev.lines.map((line, i) =>
        i === idx ? { ...line, [field]: value } : line
      ),
    }));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (submitting) return;
    setSubmitting(true);
    try {
      await supplierPortalApi.createDeliverySchedule(form);
      setShowForm(false);
      setForm({
        purchase_order_id: '',
        month: new Date().toISOString().slice(0, 7),
        lines: [{ product_name: '', quantity: 0, notes: '' }],
      });
      await fetchSchedules();
    } finally {
      setSubmitting(false);
    }
  };

  const statusColors: Record<string, string> = {
    submitted: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
    confirmed: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300',
    rejected: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
  };

  return (
    <div className="max-w-5xl space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-lg font-semibold">Delivery Schedules</h2>
          <p className="text-xs text-muted">Submit and manage your delivery plans</p>
        </div>
        <Button onClick={() => setShowForm(!showForm)}>
          {showForm ? 'Cancel' : 'New Schedule'}
        </Button>
      </div>

      {/* New schedule form */}
      {showForm && (
        <Panel className="p-4 space-y-4">
          <h3 className="text-sm font-semibold">New Delivery Schedule</h3>
          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div className="space-y-1">
                <label className="text-xs font-medium text-muted">Purchase Order</label>
                <select
                  value={form.purchase_order_id}
                  onChange={(e) => setForm((p) => ({ ...p, purchase_order_id: e.target.value }))}
                  className="w-full rounded-md border border-border bg-canvas px-3 py-2 text-xs focus:outline-none focus:ring-1 focus:ring-accent"
                  required
                >
                  <option value="">Select PO...</option>
                  {pos.map((po) => (
                    <option key={po.id} value={po.id}>{po.po_number}</option>
                  ))}
                </select>
              </div>
              <div className="space-y-1">
                <label className="text-xs font-medium text-muted">Month</label>
                <input
                  type="month"
                  value={form.month}
                  onChange={(e) => setForm((p) => ({ ...p, month: e.target.value }))}
                  className="w-full rounded-md border border-border bg-canvas px-3 py-2 text-xs focus:outline-none focus:ring-1 focus:ring-accent"
                  required
                />
              </div>
            </div>

            {/* Line items */}
            <div className="space-y-2">
              <div className="flex items-center justify-between">
                <label className="text-xs font-medium text-muted">Line Items</label>
                <button
                  type="button"
                  onClick={handleAddLine}
                  className="text-2xs text-accent hover:underline"
                >
                  + Add line
                </button>
              </div>
              {form.lines.map((line, idx) => (
                <div key={idx} className="flex gap-2 items-start">
                  <input
                    type="text"
                    placeholder="Product name"
                    value={line.product_name}
                    onChange={(e) => handleLineChange(idx, 'product_name', e.target.value)}
                    className="flex-1 rounded-md border border-border bg-canvas px-3 py-2 text-xs focus:outline-none focus:ring-1 focus:ring-accent"
                    required
                  />
                  <input
                    type="number"
                    step="0.01"
                    min="0.01"
                    placeholder="Qty"
                    value={line.quantity || ''}
                    onChange={(e) => handleLineChange(idx, 'quantity', parseFloat(e.target.value) || 0)}
                    className="w-24 rounded-md border border-border bg-canvas px-3 py-2 text-xs focus:outline-none focus:ring-1 focus:ring-accent"
                    required
                  />
                  <input
                    type="text"
                    placeholder="Notes"
                    value={line.notes}
                    onChange={(e) => handleLineChange(idx, 'notes', e.target.value)}
                    className="w-32 rounded-md border border-border bg-canvas px-3 py-2 text-xs focus:outline-none focus:ring-1 focus:ring-accent"
                  />
                  {form.lines.length > 1 && (
                    <button
                      type="button"
                      onClick={() => handleRemoveLine(idx)}
                      className="text-xs text-danger hover:underline shrink-0 mt-2"
                    >
                      Remove
                    </button>
                  )}
                </div>
              ))}
            </div>

            <div className="flex justify-end gap-2 pt-2">
              <Button type="button" variant="secondary" onClick={() => setShowForm(false)}>Cancel</Button>
              <Button type="submit" disabled={submitting}>
                {submitting ? 'Submitting...' : 'Submit Schedule'}
              </Button>
            </div>
          </form>
        </Panel>
      )}

      {/* Schedules list */}
      {loading ? (
        <div className="space-y-3">
          {Array.from({ length: 3 }).map((_, i) => <SkeletonBlock key={i} className="h-20" />)}
        </div>
      ) : schedules.length === 0 ? (
        <EmptyState icon="clipboard-list" title="No delivery schedules yet" description="Submit your first delivery schedule using the button above." />
      ) : (
        <div className="space-y-3">
          {schedules.map((s) => (
            <Panel key={s.id} className="p-4 space-y-2">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-3">
                  <p className="text-sm font-semibold">{s.month}</p>
                  <span className="text-2xs text-muted">
                    {s.purchase_order?.po_number ?? ''}
                  </span>
                  <span className={`inline-block px-2 py-0.5 rounded text-2xs font-medium ${
                    statusColors[s.status] ?? 'bg-gray-100 text-gray-700'
                  }`}>
                    {s.status}
                  </span>
                </div>
                <p className="text-2xs text-muted">
                  {new Date(s.created_at).toLocaleDateString()}
                </p>
              </div>
              <div className="overflow-x-auto">
                <table className="w-full text-xs">
                  <thead>
                    <tr className="border-b border-border text-muted">
                      <th className="text-left px-2 py-1 font-medium">Product</th>
                      <th className="text-right px-2 py-1 font-medium">Qty</th>
                      <th className="text-left px-2 py-1 font-medium">Notes</th>
                    </tr>
                  </thead>
                  <tbody>
                    {s.lines.map((line, idx) => (
                      <tr key={idx} className="border-b border-border/30">
                        <td className="px-2 py-1.5">{line.product_name}</td>
                        <td className="px-2 py-1.5 text-right font-medium">{line.quantity}</td>
                        <td className="px-2 py-1.5 text-muted">{line.notes ?? '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Panel>
          ))}
        </div>
      )}
    </div>
  );
}
