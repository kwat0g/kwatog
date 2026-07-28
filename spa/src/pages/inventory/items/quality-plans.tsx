import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useParams } from 'react-router-dom';
import { Plus, Trash2 } from 'lucide-react';
import toast from 'react-hot-toast';
import { itemQualityPlansApi, itemsApi } from '@/api/inventory/items';
import { vendorsApi } from '@/api/accounting/vendors';
import { PageHeader } from '@/components/layout/PageHeader';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { Checkbox } from '@/components/ui/Checkbox';
import { formatDate } from '@/lib/formatDate';
import { usePermission } from '@/hooks/usePermission';
import { cn } from '@/lib/cn';
import type { QualityPlanParameter } from '@/types/inventory';

type DraftParameter = QualityPlanParameter & {
  nominal_value: number | null;
  tolerance_min: number | null;
  tolerance_max: number | null;
};

const blankParameter = (): DraftParameter => ({
  parameter_name: '', parameter_type: 'visual', unit_of_measure: '',
  nominal_value: null, tolerance_min: null, tolerance_max: null, is_critical: false, notes: '',
});

export default function ItemQualityPlansPage() {
  const { id = '' } = useParams<{ id: string }>();
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const canManage = can('quality.specs.manage');
  const [vendorId, setVendorId] = useState('');
  const [sampling, setSampling] = useState<'aql' | 'fixed' | 'full'>('aql');
  const [fixedSize, setFixedSize] = useState('5');
  const [notes, setNotes] = useState('');
  const [parameters, setParameters] = useState<DraftParameter[]>([blankParameter()]);

  const item = useQuery({ queryKey: ['inventory', 'items', id], queryFn: () => itemsApi.show(id), enabled: !!id });
  const plans = useQuery({ queryKey: ['inventory', 'quality-plans', id], queryFn: () => itemQualityPlansApi.list(id), enabled: !!id });
  const vendors = useQuery({ queryKey: ['vendors', 'quality-plan-picker'], queryFn: () => vendorsApi.list({ per_page: 100, is_active: true }) });

  const save = useMutation({
    mutationFn: () => itemQualityPlansApi.createRevision(id, {
      vendor_id: vendorId || null,
      sampling_method: sampling,
      fixed_sample_size: sampling === 'fixed' ? Number(fixedSize) : null,
      aql_level: sampling === 'aql' ? 'general_ii' : null,
      notes: notes || null,
      parameters,
    }),
    onSuccess: () => {
      toast.success('Quality-plan revision published.');
      setNotes(''); setParameters([blankParameter()]);
      queryClient.invalidateQueries({ queryKey: ['inventory', 'quality-plans', id] });
      queryClient.invalidateQueries({ queryKey: ['inventory', 'items', id] });
    },
    onError: () => toast.error('Could not publish the quality plan.'),
  });

  const deactivate = useMutation({
    mutationFn: itemQualityPlansApi.deactivate,
    onSuccess: () => {
      toast.success('Quality plan deactivated.');
      queryClient.invalidateQueries({ queryKey: ['inventory', 'quality-plans', id] });
      queryClient.invalidateQueries({ queryKey: ['inventory', 'items', id] });
    },
  });

  const updateParameter = (index: number, patch: Partial<DraftParameter>) =>
    setParameters((current) => current.map((parameter, i) => i === index ? { ...parameter, ...patch } : parameter));

  if (item.isLoading || plans.isLoading) return <SkeletonTable columns={4} rows={6} />;
  if (!item.data) return <EmptyState icon="alert-circle" title="Item not found" />;

  return (
    <div>
      <PageHeader title={`Quality Plans · ${item.data.code}`} backTo={`/inventory/items/${id}`} backLabel="Item" subtitle="Versioned incoming inspection rules, optionally specific to a supplier." />
      <div className="p-5 grid grid-cols-1 xl:grid-cols-5 gap-4">
        {canManage && <Panel title="Publish a revision" className="xl:col-span-3">
          <div className="space-y-3">
            <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
              <Select label="Supplier scope" value={vendorId} onChange={(event) => setVendorId(event.target.value)}>
                <option value="">All suppliers (default)</option>
                {(vendors.data?.data ?? []).map((vendor) => <option key={vendor.id} value={vendor.id}>{vendor.name}</option>)}
              </Select>
              <Select label="Sampling" value={sampling} onChange={(event) => setSampling(event.target.value as typeof sampling)}>
                <option value="aql">AQL General II</option><option value="fixed">Fixed sample</option><option value="full">100% inspection</option>
              </Select>
              {sampling === 'fixed' && <Input label="Sample size" type="number" min={1} max={1000} value={fixedSize} onChange={(event) => setFixedSize(event.target.value)} />}
            </div>

            <div className="space-y-2">
              <div className="flex items-center justify-between"><h3 className="text-sm font-medium">Inspection parameters</h3><Button size="sm" variant="secondary" onClick={() => setParameters((p) => [...p, blankParameter()])}><Plus size={12} /> Add parameter</Button></div>
              {parameters.map((parameter, index) => (
                <div key={index} className="rounded-md border border-subtle p-3 grid grid-cols-2 md:grid-cols-6 gap-2">
                  <Input containerClassName="col-span-2" placeholder="Parameter name" value={parameter.parameter_name} onChange={(e) => updateParameter(index, { parameter_name: e.target.value })} />
                  <Select value={parameter.parameter_type} onChange={(e) => updateParameter(index, { parameter_type: e.target.value as DraftParameter['parameter_type'] })}>
                    <option value="visual">Visual</option><option value="dimensional">Dimensional</option><option value="functional">Functional</option>
                  </Select>
                  <Input placeholder="Unit" value={parameter.unit_of_measure ?? ''} onChange={(e) => updateParameter(index, { unit_of_measure: e.target.value })} />
                  <Input type="number" step="any" placeholder="Min" value={parameter.tolerance_min ?? ''} onChange={(e) => updateParameter(index, { tolerance_min: e.target.value === '' ? null : Number(e.target.value) })} />
                  <div className="flex gap-1"><Input type="number" step="any" placeholder="Max" value={parameter.tolerance_max ?? ''} onChange={(e) => updateParameter(index, { tolerance_max: e.target.value === '' ? null : Number(e.target.value) })} /><Button type="button" variant="ghost" size="sm" iconOnly icon={<Trash2 size={14} />} aria-label="Remove parameter" disabled={parameters.length === 1} onClick={() => setParameters((p) => p.filter((_, i) => i !== index))} className="text-muted hover:text-danger" /></div>
                  <Checkbox className="col-span-2" label="Critical characteristic" checked={parameter.is_critical} onChange={(e) => updateParameter(index, { is_critical: e.target.checked })} />
                </div>
              ))}
            </div>
            <Input label="Revision notes" value={notes} onChange={(event) => setNotes(event.target.value)} />
            <Button variant="primary" disabled={save.isPending || parameters.some((p) => !p.parameter_name.trim())} onClick={() => save.mutate()}>Publish revision</Button>
          </div>
        </Panel>}

        <Panel title="Revision history" className={cn(canManage ? 'xl:col-span-2' : 'xl:col-span-5')}>
          <div className="space-y-2">
            {(plans.data ?? []).map((plan) => (
              <div key={plan.id} className="rounded-md border border-subtle p-3">
                <div className="flex items-center gap-2"><span className="font-mono text-sm">v{plan.version}</span><Chip variant={plan.is_active ? 'success' : 'neutral'}>{plan.is_active ? 'active' : 'retired'}</Chip><Chip variant="info">{plan.sampling_method}</Chip></div>
                <p className="text-xs text-muted mt-1">{plan.vendor?.name ?? 'All suppliers'} · {plan.parameters.length} parameters · effective {formatDate(plan.effective_from)}</p>
                {plan.notes && <p className="text-xs mt-1">{plan.notes}</p>}
                {plan.is_active && <Button className="mt-2" size="sm" variant="secondary" onClick={() => deactivate.mutate(plan.id)}>Deactivate</Button>}
              </div>
            ))}
            {(plans.data ?? []).length === 0 && <p className="text-sm text-muted">No quality-plan revisions yet.</p>}
          </div>
        </Panel>
      </div>
    </div>
  );
}
