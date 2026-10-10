import { FormEvent, useRef, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { LuScanBarcode, LuSearch } from '@/lib/icons';
import { scannerApi } from '@/api/inventory/scanner';
import { PageHeader } from '@/components/layout/PageHeader';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { SearchableSelect } from '@/components/ui/SearchableSelect';

export default function WarehouseScannerPage() {
  const navigate = useNavigate();
  const inputRef = useRef<HTMLInputElement>(null);
  const [barcode, setBarcode] = useState('');
  const [workflow, setWorkflow] = useState('lookup');
  const [contextId, setContextId] = useState('');
  const [contextLabel, setContextLabel] = useState('');
  const [recordSearch, setRecordSearch] = useState('');
  const recordsQuery = useQuery({
    queryKey: ['inventory', 'scanner', 'records', workflow, recordSearch],
    queryFn: () => scannerApi.records(workflow, recordSearch),
    enabled: workflow !== 'lookup',
  });
  const optionsQuery = useQuery({
    queryKey: ['inventory', 'scanner', 'options'],
    queryFn: scannerApi.options,
    staleTime: 300_000,
  });
  const scannerOptions = optionsQuery.data;
  const scan = useMutation({
    mutationFn: (payload: { barcode: string; context: Record<string, string> }) =>
      scannerApi.resolve(payload.barcode, payload.context),
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (scan.isPending || !barcode.trim() || optionsQuery.isError || optionsQuery.isFetching)
      return;
    scan.mutate(
      { barcode: barcode.trim(), context: contextId ? { [workflow]: contextId } : {} },
      {
        onSuccess: () => {
          setBarcode('');
          inputRef.current?.focus();
        },
      },
    );
  };

  return (
    <div>
      <PageHeader
        title="Warehouse Scanner"
        subtitle="Scan item, PO, GRN, work-order, or bin barcodes for receiving, picking, issuance, and cycle counts."
      />
      <div className="min-w-0 w-full p-5 space-y-4">
        <Panel title="Scan context">
          {optionsQuery.isError && (
            <div role="alert" className="mb-3 text-sm text-danger-fg">
              Scanner options could not be loaded.{' '}
              <Button
                type="button"
                variant="secondary"
                size="sm"
                onClick={() => void optionsQuery.refetch()}
              >
                Retry scanner options
              </Button>
            </div>
          )}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
            <Select
              disabled={scan.isPending}
              label="Workflow"
              value={workflow}
              onChange={(event) => {
                setWorkflow(event.target.value);
                setContextId('');
                setContextLabel('');
                setRecordSearch('');
              }}
            >
              {(scannerOptions?.contexts ?? []).map((context) => (
                <option key={context.value} value={context.value}>
                  {context.label}
                </option>
              ))}
            </Select>
            {workflow !== 'lookup' && (
              <SearchableSelect
                key={workflow}
                disabled={scan.isPending}
                label="Context record"
                value={contextId}
                selectedLabel={contextLabel}
                options={recordsQuery.data ?? []}
                loading={recordsQuery.isFetching}
                onSearch={setRecordSearch}
                onChange={(value) => {
                  setContextId(value);
                  setContextLabel(
                    recordsQuery.data?.find((record) => record.value === value)?.label ?? '',
                  );
                }}
                helper="Search by record number. Up to 25 eligible records are shown. Leave blank for general lookup."
                placeholder="Search record number…"
              />
            )}
          </div>
          {workflow !== 'lookup' && recordsQuery.isError && (
            <div role="alert" className="mt-3 text-sm text-danger-fg">
              Context records could not be loaded. Check your workflow access or retry.
              <Button
                type="button"
                variant="secondary"
                size="sm"
                onClick={() => void recordsQuery.refetch()}
              >
                Retry records
              </Button>
            </div>
          )}
        </Panel>
        <form onSubmit={submit} className="rounded-md border border-default bg-canvas p-4">
          <label className="block text-sm font-medium mb-2" htmlFor="warehouse-barcode">
            Barcode
          </label>
          <div className="flex gap-2">
            <Input
              disabled={scan.isPending}
              id="warehouse-barcode"
              ref={inputRef}
              autoFocus
              value={barcode}
              onChange={(event) => setBarcode(event.target.value)}
              prefix={<LuScanBarcode size={16} />}
              containerClassName="flex-1"
              placeholder="Scan now…"
            />
            <Button
              type="submit"
              variant="primary"
              disabled={scan.isPending || optionsQuery.isError || optionsQuery.isFetching}
            >
              <LuSearch size={14} /> Resolve
            </Button>
          </div>
        </form>
        {scan.isError && (
          <EmptyState
            icon="alert-circle"
            title="Barcode lookup failed"
            description={
              (scan.error as { response?: { data?: { message?: string } } })?.response?.data
                ?.message ?? 'Check the barcode and context, then try Resolve again.'
            }
          />
        )}
        {scan.data && (
          <Panel title="Scan result">
            {scan.data.type === 'unknown' ? (
              <EmptyState
                icon="search"
                title="Unrecognized barcode"
                description="Check the printed code and scan again."
              />
            ) : (
              <div className="space-y-3">
                <div className="flex items-center gap-2">
                  <Chip variant="info">
                    {scan.data.type_label ?? scan.data.type.replaceAll('_', ' ')}
                  </Chip>
                  <span className="text-sm font-mono">
                    {String(
                      scan.data.entity?.code ??
                        scan.data.entity?.grn_number ??
                        scan.data.entity?.po_number ??
                        scan.data.entity?.wo_number ??
                        '',
                    )}
                  </span>
                </div>
                <dl className="grid grid-cols-2 gap-2 text-xs">
                  {Object.entries(scan.data.entity ?? {})
                    .filter(([key]) => key !== 'id')
                    .map(([key, value]) => (
                      <div key={key}>
                        <dt className="text-muted">{key.replaceAll('_', ' ')}</dt>
                        <dd>{String(value ?? '—')}</dd>
                      </div>
                    ))}
                </dl>
                <div className="flex flex-wrap gap-2">
                  {scan.data.suggested_actions.map((action) => (
                    <Button
                      key={action.action}
                      variant="secondary"
                      disabled={!action.href}
                      onClick={() => action.href && navigate(action.href)}
                    >
                      {action.label}
                    </Button>
                  ))}
                </div>
              </div>
            )}
          </Panel>
        )}
      </div>
    </div>
  );
}
