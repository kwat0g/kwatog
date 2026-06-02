import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useParams } from 'react-router-dom';
import { Plus, Trash2 } from 'lucide-react';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { prTemplatesApi } from '@/api/purchasing/purchase-requests';
import { itemsApi } from '@/api/inventory/items';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';

const errMsg = (e: unknown, fallback: string) =>
  (e instanceof AxiosError ? e.response?.data?.message : undefined) ?? fallback;

interface LineRow {
  key: number;
  item_id: string;
  description: string;
  quantity: string;
  unit: string;
  estimated_unit_price: string;
}

let nextKey = 1;

export default function PrTemplateFormPage() {
  const { id: paramId } = useParams<{ id: string }>();
  const isEdit = paramId !== undefined && paramId !== 'create';
  const templateId = isEdit ? Number(paramId) : null;
  const navigate = useNavigate();
  const qc = useQueryClient();

  const [name, setName] = useState('');
  const [notes, setNotes] = useState('');
  const [departmentId, setDepartmentId] = useState('');
  const [lines, setLines] = useState<LineRow[]>([
    { key: nextKey++, item_id: '', description: '', quantity: '1', unit: '', estimated_unit_price: '' },
  ]);

  // Load existing template for edit mode
  const { data: template } = useQuery({
    queryKey: ['purchasing', 'pr-templates', templateId],
    queryFn: () => prTemplatesApi.show(templateId!),
    enabled: isEdit && !!templateId,
  });

  // Populate form fields when template data loads (edit mode)
  useEffect(() => {
    if (template) {
      setName(template.name);
      setNotes(template.notes ?? '');
      setDepartmentId(template.department?.id ?? '');
      setLines(template.items.map((i) => ({
        key: nextKey++,
        item_id: i.item_id ?? '',
        description: i.description,
        quantity: String(i.quantity),
        unit: i.unit ?? '',
        estimated_unit_price: i.estimated_unit_price ?? '',
      })));
    }
  }, [template]);

  const { data: itemsData } = useQuery({
    queryKey: ['inventory', 'items', 'select'],
    queryFn: () => itemsApi.list({ per_page: 500 }),
  });

  const { data: deptsData } = useQuery({
    queryKey: ['hr', 'departments', 'select'],
    queryFn: () => fetch('/api/v1/hr/departments').then((r) => r.json()),
  });

  const save = useMutation({
    mutationFn: () => {
      const payload = {
        name,
        notes: notes || undefined,
        department_id: departmentId ? Number(departmentId) : undefined,
        items: lines.map((l) => ({
          item_id: l.item_id || null,
          description: l.description,
          quantity: l.quantity,
          unit: l.unit || undefined,
          estimated_unit_price: l.estimated_unit_price || undefined,
        })),
      };
      if (isEdit && templateId) {
        return prTemplatesApi.update(templateId, payload);
      }
      return prTemplatesApi.create(payload as any);
    },
    onSuccess: (_result) => {
      qc.invalidateQueries({ queryKey: ['purchasing', 'pr-templates'] });
      toast.success(isEdit ? 'Template updated.' : 'Template created.');
      navigate('/purchasing/pr-templates');
    },
    onError: (e) => toast.error(errMsg(e, 'Failed to save template.')),
  });

  const addLine = () => setLines((prev) => [...prev, { key: nextKey++, item_id: '', description: '', quantity: '1', unit: '', estimated_unit_price: '' }]);
  const removeLine = (key: number) => setLines((prev) => prev.filter((l) => l.key !== key));
  const updateLine = (key: number, field: keyof LineRow, value: string) =>
    setLines((prev) => prev.map((l) => (l.key === key ? { ...l, [field]: value } : l)));

  const items = itemsData?.data ?? [];
  const depts = deptsData?.data ?? [];

  return (
    <div>
      <PageHeader
        title={isEdit ? 'Edit Template' : 'New PR Template'}
        backTo="/purchasing/pr-templates" backLabel="PR Templates"
        breadcrumbs={[{ label: 'Purchasing', href: '/purchasing' }, { label: 'PR Templates', href: '/purchasing/pr-templates' }, { label: isEdit ? 'Edit Template' : 'New PR Template' }]}
        actions={
          <Button variant="primary" size="sm" onClick={() => save.mutate()} loading={save.isPending}>
            {isEdit ? 'Update Template' : 'Create Template'}
          </Button>
        }
      />
      <div className="px-5 py-4 max-w-3xl space-y-4">
        <Panel title="Template details">
          <div className="space-y-3">
            <Input label="Template name" value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Monthly office supplies" required />
            <div className="grid grid-cols-2 gap-3">
              <Select label="Department" value={departmentId} onChange={(e) => setDepartmentId(e.target.value)}>
                <option value="">All departments</option>
                {depts.map((d: any) => (
                  <option key={d.id} value={d.id}>{d.name}</option>
                ))}
              </Select>
            </div>
            <Textarea label="Notes" value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Optional notes about this template…" rows={2} />
          </div>
        </Panel>

        <Panel title="Line items" noPadding>
          <div className="divide-y divide-subtle">
            {lines.map((line) => (
              <div key={line.key} className="p-3 grid grid-cols-12 gap-2 items-start">
                <div className="col-span-3">
                  <Select value={line.item_id} onChange={(e) => updateLine(line.key, 'item_id', e.target.value)}>
                    <option value="">— Select item —</option>
                    {items.map((i: any) => (
                      <option key={i.id} value={i.id}>{i.code} — {i.name}</option>
                    ))}
                  </Select>
                </div>
                <div className="col-span-4">
                  <Input placeholder="Description" value={line.description} onChange={(e) => updateLine(line.key, 'description', e.target.value)} />
                </div>
                <div className="col-span-1">
                  <Input type="number" min="0" step="0.01" placeholder="Qty" value={line.quantity} onChange={(e) => updateLine(line.key, 'quantity', e.target.value)} />
                </div>
                <div className="col-span-1">
                  <Input placeholder="Unit" value={line.unit} onChange={(e) => updateLine(line.key, 'unit', e.target.value)} />
                </div>
                <div className="col-span-2">
                  <Input type="number" min="0" step="0.01" placeholder="Est. price" value={line.estimated_unit_price} onChange={(e) => updateLine(line.key, 'estimated_unit_price', e.target.value)} />
                </div>
                <div className="col-span-1 pt-1">
                  <button onClick={() => removeLine(line.key)} className="p-1.5 rounded hover:bg-subtle text-muted hover:text-danger transition-colors" disabled={lines.length <= 1}>
                    <Trash2 size={14} />
                  </button>
                </div>
              </div>
            ))}
          </div>
          <div className="px-3 py-2 border-t border-subtle">
            <Button size="sm" variant="secondary" icon={<Plus size={14} />} onClick={addLine}>Add item</Button>
          </div>
        </Panel>
      </div>
    </div>
  );
}
