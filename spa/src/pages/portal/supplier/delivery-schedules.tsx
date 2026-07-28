import { useCallback, useEffect, useState } from 'react';
import { Plus, X } from 'lucide-react';
import { supplierPortalApi } from '@/api/b2b/supplier';
import type { DeliverySchedule, PortalPoSummary } from '@/types/b2b';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { formatDate } from '@/lib/formatDate';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

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

  return (
    <div className="max-w-5xl space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-lg font-medium">Delivery schedules</h2>
          <p className="text-xs text-muted">Submit and manage your delivery plans</p>
        </div>
        <Button onClick={() => setShowForm(!showForm)}>
          {showForm ? 'Cancel' : 'New schedule'}
        </Button>
      </div>

      {/* New schedule form */}
      {showForm && (
        <Panel className="p-4 space-y-4">
          <h3 className="text-sm font-medium">New delivery schedule</h3>
          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <Select
                label="Purchase order"
                required
                value={form.purchase_order_id}
                onChange={(e) => setForm((p) => ({ ...p, purchase_order_id: e.target.value }))}
              >
                <option value="">Select PO…</option>
                {pos.map((po) => (
                  <option key={po.id} value={po.id}>{po.po_number}</option>
                ))}
              </Select>
              <Input
                label="Month"
                type="month"
                required
                value={form.month}
                onChange={(e) => setForm((p) => ({ ...p, month: e.target.value }))}
              />
            </div>

            {/* Line items */}
            <div className="space-y-2">
              <div className="flex items-center justify-between">
                <span className="text-xs font-medium text-muted">Line items</span>
                <Button type="button" variant="ghost" size="sm" icon={<Plus size={12} />} onClick={handleAddLine}>
                  Add line
                </Button>
              </div>
              {form.lines.map((line, idx) => (
                <div key={idx} className="flex gap-2 items-start">
                  <Input
                    type="text"
                    placeholder="Product name"
                    aria-label="Product name"
                    containerClassName="flex-1"
                    value={line.product_name}
                    onChange={(e) => handleLineChange(idx, 'product_name', e.target.value)}
                    required
                  />
                  <Input
                    type="number"
                    step="0.01"
                    min="0.01"
                    placeholder="Qty"
                    aria-label="Quantity"
                    className="font-mono tabular-nums"
                    containerClassName="w-24"
                    value={line.quantity || ''}
                    onChange={(e) => handleLineChange(idx, 'quantity', parseFloat(e.target.value) || 0)}
                    required
                  />
                  <Input
                    type="text"
                    placeholder="Notes"
                    aria-label="Notes"
                    containerClassName="w-32"
                    value={line.notes}
                    onChange={(e) => handleLineChange(idx, 'notes', e.target.value)}
                  />
                  {form.lines.length > 1 && (
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      iconOnly
                      icon={<X size={14} />}
                      onClick={() => handleRemoveLine(idx)}
                      aria-label="Remove line"
                      className="shrink-0 text-muted hover:text-danger"
                    />
                  )}
                </div>
              ))}
            </div>

            <div className="flex justify-end gap-2 pt-2">
              <Button type="button" variant="secondary" onClick={() => setShowForm(false)}>Cancel</Button>
              <Button type="submit" disabled={submitting}>
                {submitting ? 'Submitting…' : 'Submit schedule'}
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
                  <p className="text-sm font-medium">{s.month}</p>
                  <span className="text-2xs text-muted">
                    {s.purchase_order?.po_number ?? ''}
                  </span>
                  <Chip variant={chipVariantForStatus(s.status)}>{s.status}</Chip>
                </div>
                <p className="text-2xs text-muted">
                  {formatDate(s.created_at)}
                </p>
              </div>
              <div className="overflow-x-auto">
                <table className={tableCls}>
                  <thead>
                    <tr className={theadTrCls}>
                      <Th>Product</Th>
                      <Th align="right">Qty</Th>
                      <Th>Notes</Th>
                    </tr>
                  </thead>
                  <tbody>
                    {s.lines.map((line, idx) => (
                      <tr key={idx} className={trCls}>
                        <Td>{line.product_name}</Td>
                        <Td align="right" mono className="font-medium">{line.quantity}</Td>
                        <Td className="text-muted">{line.notes ?? '—'}</Td>
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
