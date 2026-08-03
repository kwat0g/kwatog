import { FormEvent, useRef, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { ScanBarcode, Search } from 'lucide-react';
import { scannerApi } from '@/api/inventory/scanner';
import { PageHeader } from '@/components/layout/PageHeader';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';

export default function WarehouseScannerPage() {
  const navigate = useNavigate();
  const inputRef = useRef<HTMLInputElement>(null);
  const [barcode, setBarcode] = useState('');
  const [workflow, setWorkflow] = useState('lookup');
  const [contextId, setContextId] = useState('');
  const { data: scannerOptions } = useQuery({
    queryKey: ['inventory', 'scanner', 'options'],
    queryFn: scannerApi.options,
    staleTime: 300_000,
  });
  const scan = useMutation({ mutationFn: () => scannerApi.resolve(barcode, contextId ? { [workflow]: contextId } : {}) });

  const submit = (event: FormEvent) => {
    event.preventDefault(); if (!barcode.trim()) return;
    scan.mutate(undefined, { onSuccess: () => { setBarcode(''); inputRef.current?.focus(); } });
  };

  return <div>
    <PageHeader title="Warehouse Scanner" subtitle="Scan item, PO, GRN, work-order, or bin barcodes for receiving, picking, issuance, and cycle counts." />
    <div className="p-5 max-w-4xl mx-auto space-y-4">
      <Panel title="Scan context">
        <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
          <Select label="Workflow" value={workflow} onChange={(event) => { setWorkflow(event.target.value); setContextId(''); }}>
            {(scannerOptions?.contexts ?? []).map((context) => (
              <option key={context.value} value={context.value}>{context.label}</option>
            ))}
          </Select>
          {workflow !== 'lookup' && <Input label="Record ID" value={contextId} onChange={(event) => setContextId(event.target.value)} placeholder="Scan or enter the active record ID" />}
        </div>
      </Panel>
      <form onSubmit={submit} className="rounded-md border border-default bg-canvas p-4">
        <label className="block text-sm font-medium mb-2" htmlFor="warehouse-barcode">Barcode</label>
        <div className="flex gap-2"><Input id="warehouse-barcode" ref={inputRef} autoFocus value={barcode} onChange={(event) => setBarcode(event.target.value)} prefix={<ScanBarcode size={16} />} containerClassName="flex-1" placeholder="Scan now…" /><Button type="submit" variant="primary" disabled={scan.isPending}><Search size={14} /> Resolve</Button></div>
      </form>
      {scan.isError && <EmptyState icon="alert-circle" title="Barcode lookup failed" />}
      {scan.data && <Panel title="Scan result">
        {scan.data.type === 'unknown' ? <EmptyState icon="search" title="Unrecognized barcode" description="Check the printed code and scan again." /> : <div className="space-y-3"><div className="flex items-center gap-2"><Chip variant="info">{scan.data.type_label ?? scan.data.type.replaceAll('_', ' ')}</Chip><span className="text-sm font-mono">{String(scan.data.entity?.code ?? scan.data.entity?.grn_number ?? scan.data.entity?.po_number ?? scan.data.entity?.wo_number ?? '')}</span></div><dl className="grid grid-cols-2 gap-2 text-xs">{Object.entries(scan.data.entity ?? {}).filter(([key]) => key !== 'id').map(([key, value]) => <div key={key}><dt className="text-muted">{key.replaceAll('_', ' ')}</dt><dd>{String(value ?? '—')}</dd></div>)}</dl><div className="flex flex-wrap gap-2">{scan.data.suggested_actions.map((action) => <Button key={action.action} variant="secondary" disabled={!action.href} onClick={() => action.href && navigate(action.href)}>{action.label}</Button>)}</div></div>}
      </Panel>}
    </div>
  </div>;
}
