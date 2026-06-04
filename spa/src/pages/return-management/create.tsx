import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery } from '@tanstack/react-query';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { returnManagementApi } from '@/api/returnManagement';
import { productsApi } from '@/api/crm/products';
import { customersApi } from '@/api/accounting/customers';
import type { ReturnRequestFormData } from '@/types/returnManagement';

export default function CreateReturnRequestPage() {
  const navigate = useNavigate();
  const [form, setForm] = useState<ReturnRequestFormData>({
    type: 'customer_return',
    reason_code: '',
    reason_description: '',
    customer_notes: '',
    resolution: '',
    return_date: new Date().toISOString().slice(0, 10),
    customer_id: '',
    vendor_id: '',
    sales_order_id: '',
    invoice_id: '',
    purchase_order_id: '',
    bill_id: '',
    items: [],
  });

  const { data: productsData } = useQuery({
    queryKey: ['products'],
    queryFn: () => productsApi.list({ per_page: 500 }),
  });

  const { data: customersData } = useQuery({
    queryKey: ['customers'],
    queryFn: () => customersApi.list({ per_page: 500 }),
  });

  const products = productsData?.data ?? [];
  const customers = customersData?.data ?? [];

  const createMutation = useMutation({
    mutationFn: (data: ReturnRequestFormData) => returnManagementApi.create(data),
    onSuccess: (rma) => {
      navigate(`/return-management/${rma.id}`);
    },
  });

  const addItem = () => {
    setForm((f) => ({
      ...f,
      items: [...(f.items || []), { product_id: '', quantity: 1, unit_price: 0, reason: '', condition: '' }],
    }));
  };

  const updateItem = (idx: number, field: string, value: unknown) => {
    setForm((f) => {
      const items = [...(f.items || [])];
      items[idx] = { ...items[idx], [field]: value };
      return { ...f, items };
    });
  };

  const removeItem = (idx: number) => {
    setForm((f) => ({
      ...f,
      items: (f.items || []).filter((_, i) => i !== idx),
    }));
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    createMutation.mutate(form);
  };

  return (
    <div>
      <PageHeader
        title="New Return Request"
        subtitle="Create a customer or supplier return"
        backTo="/return-management"
        breadcrumbs={[{ label: 'Returns', href: '/return-management' }, { label: 'New Return Request' }]}
      />

      <form onSubmit={handleSubmit} className="px-4 space-y-4 max-w-3xl">
        {/* Type + Source */}
        <Panel title="Type & Source">
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="label">Type</label>
              <select
                className="input w-full"
                value={form.type}
                onChange={(e) => setForm((f) => ({ ...f, type: e.target.value as 'customer_return' | 'supplier_return' }))}
              >
                <option value="customer_return">Customer Return</option>
                <option value="supplier_return">Supplier Return</option>
              </select>
            </div>
            <div>
              <label className="label">Return Date</label>
              <input
                type="date"
                className="input w-full"
                value={form.return_date || ''}
                onChange={(e) => setForm((f) => ({ ...f, return_date: e.target.value }))}
              />
            </div>
          </div>

          {form.type === 'customer_return' ? (
            <div className="grid grid-cols-2 gap-4 mt-3">
              <div>
                <label className="label">Customer</label>
                <select
                  className="input w-full"
                  value={form.customer_id}
                  onChange={(e) => setForm((f) => ({ ...f, customer_id: e.target.value }))}
                >
                  <option value="">Select customer</option>
                  {customers.map((c: { id: string; name: string }) => (
                    <option key={c.id} value={c.id}>{c.name}</option>
                  ))}
                </select>
              </div>
            </div>
          ) : (
            <div className="grid grid-cols-2 gap-4 mt-3">
              <div>
                <label className="label">Vendor ID</label>
                <input
                  className="input w-full"
                  placeholder="Vendor hash ID"
                  value={form.vendor_id}
                  onChange={(e) => setForm((f) => ({ ...f, vendor_id: e.target.value }))}
                />
              </div>
            </div>
          )}
        </Panel>

        {/* Reason */}
        <Panel title="Reason">
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="label">Reason Code</label>
              <select
                className="input w-full"
                value={form.reason_code}
                onChange={(e) => setForm((f) => ({ ...f, reason_code: e.target.value }))}
              >
                <option value="">Select reason</option>
                <option value="defective">Defective product</option>
                <option value="damaged">Damaged in transit</option>
                <option value="wrong_item">Wrong item shipped</option>
                <option value="excess">Excess quantity</option>
                <option value="customer_change">Customer changed mind</option>
                <option value="quality_issue">Quality issue</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div>
              <label className="label">Resolution</label>
              <select
                className="input w-full"
                value={form.resolution}
                onChange={(e) => setForm((f) => ({ ...f, resolution: e.target.value }))}
              >
                <option value="">Select resolution</option>
                <option value="replace">Replace</option>
                <option value="refund">Refund</option>
                <option value="credit_note">Credit Note</option>
                <option value="scrap">Scrap</option>
                <option value="return_to_vendor">Return to Vendor</option>
              </select>
            </div>
          </div>
          <div className="mt-3">
            <label className="label">Description</label>
            <textarea
              className="input w-full h-20"
              value={form.reason_description}
              onChange={(e) => setForm((f) => ({ ...f, reason_description: e.target.value }))}
              placeholder="Describe the reason for return..."
            />
          </div>
          <div className="mt-3">
            <label className="label">Customer Notes</label>
            <textarea
              className="input w-full h-20"
              value={form.customer_notes}
              onChange={(e) => setForm((f) => ({ ...f, customer_notes: e.target.value }))}
              placeholder="Notes from the customer..."
            />
          </div>
        </Panel>

        {/* Items */}
        <Panel
          title="Items"
          actions={
            <Button variant="secondary" size="sm" onClick={addItem}>+ Add Item</Button>
          }
        >
          {(form.items || []).length === 0 ? (
            <div className="text-muted text-sm py-2">No items added yet. Click "+ Add Item" to add products being returned.</div>
          ) : (
            <div className="space-y-3">
              {(form.items || []).map((item, idx) => (
                <div key={idx} className="flex gap-2 items-start p-2 bg-elevated rounded">
                  <div className="flex-1">
                    <select
                      className="input w-full text-sm"
                      value={item.product_id}
                      onChange={(e) => updateItem(idx, 'product_id', e.target.value)}
                    >
                      <option value="">Select product</option>
                      {products.map((p: { id: string; part_number: string; name: string }) => (
                        <option key={p.id} value={p.id}>{p.part_number} — {p.name}</option>
                      ))}
                    </select>
                  </div>
                  <input
                    className="input w-20 text-sm"
                    type="number"
                    step="0.001"
                    min="0.001"
                    placeholder="Qty"
                    value={item.quantity}
                    onChange={(e) => updateItem(idx, 'quantity', parseFloat(e.target.value) || 0)}
                  />
                  <input
                    className="input w-24 text-sm"
                    type="number"
                    step="0.01"
                    min="0"
                    placeholder="Price"
                    value={item.unit_price}
                    onChange={(e) => updateItem(idx, 'unit_price', parseFloat(e.target.value) || 0)}
                  />
                  <select
                    className="input w-24 text-sm"
                    value={item.condition}
                    onChange={(e) => updateItem(idx, 'condition', e.target.value)}
                  >
                    <option value="">Condition</option>
                    <option value="new">New</option>
                    <option value="used">Used</option>
                    <option value="damaged">Damaged</option>
                    <option value="defective">Defective</option>
                    <option value="obsolete">Obsolete</option>
                  </select>
                  <input
                    className="input w-32 text-sm"
                    placeholder="Reason"
                    value={item.reason}
                    onChange={(e) => updateItem(idx, 'reason', e.target.value)}
                  />
                  <button
                    type="button"
                    onClick={() => removeItem(idx)}
                    className="text-danger hover:underline text-sm mt-1"
                  >
                    ×
                  </button>
                </div>
              ))}
            </div>
          )}
        </Panel>

        {/* Submit */}
        <div className="flex justify-end gap-2 pb-8">
          <Button variant="secondary" onClick={() => navigate('/return-management')}>Cancel</Button>
          <Button
            variant="primary"
            type="submit"
            loading={createMutation.isPending}
          >
            Create Return Request
          </Button>
        </div>
      </form>
    </div>
  );
}
