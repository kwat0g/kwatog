import { useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { isAxiosError } from 'axios';
import { stockMovementsApi } from '@/api/inventory/stock';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { Button } from '@/components/ui/Button';
import { Checkbox } from '@/components/ui/Checkbox';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar } from '@/components/ui/FilterBar';
import { SearchableSelect } from '@/components/ui/SearchableSelect';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { PageShell } from '@/components/layout/PageShell';
import { formatInt, formatQuantity } from '@/lib/formatNumber';
import { usePermission } from '@/hooks/usePermission';
import { productionDateTime as formatDateTime } from '@/components/production/scheduleDates';
import type { MaterialHandoverTask, MaterialHandoverTaskDetail } from '@/types/inventory';

const statusLabels = {
  pending: 'Pending handover',
  handed_over: 'Resolved',
  needs_review: 'Needs review',
};

function HandoverDetails({
  selector,
  search = '',
  onLoaded,
}: {
  selector:
    | { work_order_id: string; material_issue_id?: never }
    | { material_issue_id: string; work_order_id?: never }
    | { maintenance_work_order_id: string };
  search?: string;
  onLoaded?: (detail: MaterialHandoverTaskDetail) => void;
}) {
  const { can } = usePermission();
  const client = useQueryClient();
  const initialized = useRef(false);
  const [selected, setSelected] = useState<string[]>([]);
  const [recipient, setRecipient] = useState('');
  const detail = useQuery({
    queryKey: ['inventory', 'material-handovers', 'detail', selector],
    queryFn: () => stockMovementsApi.handoverSources(selector),
    staleTime: 0,
  });
  useEffect(() => {
    if (!detail.data) return;
    const eligible = detail.data.sources
      .filter(
        (source) => source.eligible && !source.handover && Number(source.remaining_quantity) > 0,
      )
      .map((source) => source.movement_id);
    if (!initialized.current) {
      setSelected(eligible.length <= 100 ? eligible : []);
      const defaultId = detail.data.default_recipient_id;
      if (defaultId && detail.data.recipients.some((option) => option.value === defaultId))
        setRecipient(defaultId);
      initialized.current = true;
    } else {
      setSelected((previous) => previous.filter((id) => eligible.includes(id)));
      setRecipient((previous) =>
        detail.data!.recipients.some((option) => option.value === previous) ? previous : '',
      );
    }
    onLoaded?.(detail.data);
  }, [detail.data, onLoaded]);
  const record = useMutation({
    mutationFn: () => stockMovementsApi.handoverGroup({ received_by_employee_id: recipient,
      sources: detail.data!.sources.filter((source) => selected.includes(source.movement_id))
        .map((source) => ({ movement_id: source.movement_id, expected_quantity: source.remaining_quantity })),
    }),
    onSuccess: async () => {
      toast.success('Material handover recorded.');
      await Promise.all([
        client.invalidateQueries({ queryKey: ['inventory', 'material-handovers'] }),
        client.invalidateQueries({ queryKey: ['inventory', 'movements'] }),
        client.invalidateQueries({ queryKey: ['inventory', 'picking'] }),
        client.invalidateQueries({ queryKey: ['production', 'work-orders'] }),
        client.invalidateQueries({ queryKey: ['my-work-orders'] }),
        client.invalidateQueries({ queryKey: ['maintenance', 'work-order'] }),
        client.invalidateQueries({ queryKey: ['maintenance', 'work-orders'] }),
        client.invalidateQueries({ queryKey: ['inventory', 'stock-levels'] }),
      ]);
    },
  });
  if (detail.isLoading)
    return (
      <p role="status" className="p-4 text-sm text-muted">
        Loading handover details…
      </p>
    );
  if (detail.isError)
    return (
      <div role="alert" className="p-4 text-sm text-danger-fg">
        Handover details could not be loaded.{' '}
        <Button size="sm" onClick={() => void detail.refetch()}>
          Retry details
        </Button>
      </div>
    );
  const data = detail.data;
  if (!data) return null;
  const editable = can('inventory.issue.create') && data.status === 'pending';
  const unavailable = detail.isFetching || record.isPending || record.isError;
  const eligibleCount = data.sources.filter(
    (source) => source.eligible && !source.handover && Number(source.remaining_quantity) > 0,
  ).length;
  return (
    <section
      className="p-3 sm:p-4 space-y-3 min-w-0"
      aria-label={`Handover details for ${data.label}`}
    >
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-sm font-medium">{data.label} · Issued materials</h2>
        <span className="text-xs text-muted">
          {formatInt(data.pending_lines)} pending · {formatInt(data.handed_over_lines)} handed over ·{' '}
          {formatInt(
            data.sources.filter(
              (source) => Number(source.remaining_quantity) <= 0 && !source.handover,
            ).length,
          )}{' '}
          fully returned
        </span>
      </div>
      <p className="text-xs text-muted">
        Review requested parts or issued locations, lots and remaining quantities. Select only materials
        physically received.
      </p>
      {data.status === 'needs_review' && (
        <p role="status" className="text-sm text-warning-fg">
          Some issued materials need review. Receipt recording is unavailable.
        </p>
      )}
      {editable && eligibleCount > 100 && (
        <p role="status" className="text-sm text-warning-fg">
          Select a batch of up to 100 lines. No lines were selected automatically.
        </p>
      )}
      <div className="divide-y divide-default border-y border-default">
        {data.sources.map((source) => {
          const eligible =
            source.eligible && !source.handover && Number(source.remaining_quantity) > 0;
          const matched =
            !!search.trim() &&
            `${source.item.code} ${source.item.name}`
              .toLocaleLowerCase()
              .includes(search.trim().toLocaleLowerCase());
          const unit = source.item.unit_of_measure;
          return (
            <div key={source.movement_id} className="py-3 space-y-2">
              <div className="flex items-start justify-between gap-3">
                <div className="flex items-start gap-2 min-w-0">
                  {editable && eligible && (
                    <Checkbox
                      aria-label={`Select ${source.item.code} ${source.movement_id}`}
                      checked={selected.includes(source.movement_id)}
                      disabled={
                        unavailable ||
                        (!selected.includes(source.movement_id) && selected.length >= 100)
                      }
                      onChange={(event) =>
                        setSelected((previous) =>
                          event.target.checked
                            ? [...previous, source.movement_id]
                            : previous.filter((id) => id !== source.movement_id),
                        )
                      }
                    />
                  )}
                  <div className="min-w-0">
                    <span className="font-medium break-words">{source.item.code}</span>
                    <p className="text-sm text-secondary break-words">{source.item.name}</p>
                    {matched && <Chip variant="info">Matches search</Chip>}
                  </div>
                </div>
                <Chip
                  variant={
                    Number(source.remaining_quantity) <= 0
                      ? 'neutral'
                      : source.handover
                        ? 'success'
                        : 'warning'
                  }
                >
                  {Number(source.remaining_quantity) <= 0
                    ? 'Fully returned'
                    : source.handover
                      ? 'Handed over'
                      : data.status === 'needs_review'
                        ? 'Historical handover missing'
                        : 'Pending handover'}
                </Chip>
              </div>
              <dl className="grid grid-cols-2 sm:grid-cols-5 gap-2 text-xs">
                <div>
                  <dt className="text-muted">Issued from</dt>
                  <dd className="break-words">{source.location?.code ?? 'Location unavailable'}</dd>
                </div>
                <div>
                  <dt className="text-muted">Lot</dt>
                  <dd className="break-all">{source.lot_number ?? 'Not recorded'}</dd>
                </div>
                <div>
                  <dt className="text-muted">Issued</dt>
                  <dd className="tabular-nums">
                    {formatQuantity(source.issued_quantity)} {unit}
                  </dd>
                </div>
                <div>
                  <dt className="text-muted">Returned</dt>
                  <dd className="tabular-nums">
                    {formatQuantity(source.returned_quantity)} {unit}
                  </dd>
                </div>
                <div>
                  <dt className="text-muted">Remaining</dt>
                  <dd className="tabular-nums font-medium">
                    {formatQuantity(source.remaining_quantity)} {unit}
                  </dd>
                </div>
              </dl>
              {source.handover && (
                <p className="text-xs text-muted">
                  Received by {source.handover.recipient_name} ·{' '}
                  {formatDateTime(source.handover.handed_over_at)} · Quantity{' '}
                  {formatQuantity(source.handover.quantity)} {unit} · Recorded by{' '}
                  {source.handover.recorded_by ?? 'Warehouse'}
                </p>
              )}
            </div>
          );
        })}
      </div>
      {editable && (
        <div className="space-y-3">
          <div className="max-w-sm">
            <SearchableSelect
              label="Received by"
              value={recipient}
              onChange={setRecipient}
              options={data.recipients}
              disabled={unavailable}
              placeholder="Choose Production worker…"
            />
          </div>
          {!data.recipients.length && (
            <p role="alert" className="text-sm text-warning-fg">
              No active assigned recipient. Ask PPC to review the WO crew.
            </p>
          )}
          {record.isError && (
            <div role="alert" className="space-y-2 text-sm text-danger-fg">
              <p>
                {isAxiosError(record.error) &&
                typeof record.error.response?.data?.message === 'string'
                  ? record.error.response.data.message
                  : 'Handover could not be saved. Reload details, then try again.'}
              </p>
              <Button
                size="sm"
                variant="secondary"
                disabled={detail.isFetching}
                onClick={async () => {
                  await detail.refetch();
                  record.reset();
                }}
              >
                Reload handover details
              </Button>
            </div>
          )}
          <div className="flex flex-wrap items-center justify-between gap-3">
            <p className="text-sm">Confirm the selected materials were received by this worker.</p>
            <Button
              variant="primary"
              loading={record.isPending}
              disabled={
                unavailable ||
                !selected.length ||
                selected.length > 100 ||
                !data.recipients.some((option) => option.value === recipient)
              }
              onClick={() => record.mutate()}
            >
              Confirm handover ({formatInt(selected.length)} lines)
            </Button>
          </div>
        </div>
      )}
      {!can('inventory.issue.create') && data.status === 'pending' && (
        <p className="text-sm text-muted">Ask Warehouse to confirm material handover.</p>
      )}
    </section>
  );
}

export default function PickingListPage() {
  const { can } = usePermission();
  const [params] = useSearchParams();
  const maintenanceId = params.get('maintenance_work_order_id');
  const workOrderId = params.get('work_order_id');
  const slipId = workOrderId ? null : params.get('material_issue_id');
  const directLookup = Boolean(maintenanceId || workOrderId || slipId);
  const [filters, setFilters] = useState({
    status: maintenanceId || workOrderId || slipId ? '' : 'pending',
    search: '',
    page: 1,
    per_page: 25,
  });
  const [resolvedWorkOrderId, setResolvedWorkOrderId] = useState<string | null>(null);
  const effectiveWorkOrderId = workOrderId ?? resolvedWorkOrderId;
  const [reviewed, setReviewed] = useState<Record<string, MaterialHandoverTask>>({});
  const tasks = useQuery({
    queryKey: ['inventory', 'material-handovers', 'list', filters, effectiveWorkOrderId, maintenanceId],
    queryFn: () =>
      stockMovementsApi.handoverTasks({
        ...filters,
        status: filters.status as MaterialHandoverTask['status'] | '',
        ...(maintenanceId ? { maintenance_work_order_id: maintenanceId } : effectiveWorkOrderId ? { work_order_id: effectiveWorkOrderId } : {}),
      }),
    enabled: can('inventory.picking.view'),
  });
  const updateReviewed = (detail: MaterialHandoverTaskDetail) =>
    setReviewed((previous) =>
      previous[detail.id]?.status === detail.status &&
      previous[detail.id]?.pending_lines === detail.pending_lines
        ? previous
        : { ...previous, [detail.id]: detail },
    );
  const changeFilters = (next: typeof filters) => {
    setReviewed({});
    setFilters(next);
  };
  const columns: Column<MaterialHandoverTask>[] = [
    {
      key: 'label',
      header: 'WO / Slip',
      width: 340,
      minWidth: 280,
      maxWidth: 420,
      pinned: 'left',
      cell: (row) => (
        <span className="block truncate font-medium text-link" title={row.label}>
          {row.label}
        </span>
      ),
    },
    {
      key: 'lines',
      header: 'Material lines',
      align: 'right',
      width: 100,
      minWidth: 96,
      maxWidth: 130,
      cell: (row) => formatInt(row.total_lines),
    },
    {
      key: 'status',
      header: 'Handover status',
      width: 200,
      minWidth: 180,
      maxWidth: 240,
      cell: (row) => (
        <Chip variant={row.status === 'handed_over' ? 'success' : 'warning'}>
          {row.status === 'handed_over' && row.handed_over_lines === 0
            ? 'Fully returned'
            : statusLabels[row.status]}
          {row.status === 'pending' ? ` · ${formatInt(row.pending_lines)} lines` : ''}
        </Chip>
      ),
    },
    {
      key: 'latest',
      header: 'Latest issue',
      width: 190,
      minWidth: 180,
      maxWidth: 220,
      cell: (row) => (
        <span className="whitespace-nowrap tabular-nums">
          {row.latest_issue_at ? formatDateTime(row.latest_issue_at) : '—'}
        </span>
      ),
    },
    {
      key: 'review',
      header: 'Review',
      width: 150,
      minWidth: 140,
      maxWidth: 180,
      cell: () => <span className="whitespace-nowrap text-link">Review handover</span>,
    },
  ];
  const rows = (tasks.data?.data ?? []).map((row) => reviewed[row.id] ?? row);
  const completedReviews = Object.values(reviewed).filter(
    (row) => !rows.some((task) => task.id === row.id),
  );
  return (
    <PageShell viewport={!directLookup}>
      <PageHeader
        title="Material Handover"
        inset={false}
        subtitle={
          tasks.data
            ? `${tasks.data.meta.total} ${filters.status === 'pending' ? 'pending handover tasks' : 'handover tasks'}`
            : 'Confirm materials received by Production or Maintenance'
        }
      />
      <FilterBar
        inset={false}
        filters={[
          {
            key: 'status',
            label: 'Status',
            type: 'select',
            options: [
              { value: 'pending', label: 'Pending handover' },
              { value: 'handed_over', label: 'Resolved' },
              { value: 'needs_review', label: 'Needs review' },
              { value: '', label: 'All' },
            ],
          },
        ]}
        values={filters}
        onFilter={(_key, value) =>
          changeFilters({ ...filters, status: String(value ?? ''), page: 1 })
        }
        searchPlaceholder="Search WO, slip, material code or name…"
        onSearch={(search) => changeFilters({ ...filters, search, page: 1 })}
      />
      <div className={directLookup ? 'py-4 space-y-4 min-w-0' : 'pt-4 min-h-0 flex-1 flex flex-col'}>
        {(maintenanceId || workOrderId || slipId) && (
          <div className="border border-default rounded-md">
            <HandoverDetails
              key={maintenanceId ?? workOrderId ?? slipId!}
              selector={
                maintenanceId ? { maintenance_work_order_id: maintenanceId } : workOrderId ? { work_order_id: workOrderId } : { material_issue_id: slipId! }
              }
              onLoaded={(detail) => {
                if (slipId && detail.work_order_id) setResolvedWorkOrderId(detail.work_order_id);
              }}
            />
          </div>
        )}
        {tasks.isLoading && <SkeletonTable rows={5} columns={5} />}
        {tasks.isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load material handovers"
            action={<Button onClick={() => void tasks.refetch()}>Retry</Button>}
          />
        )}
        {tasks.data && (
          <DataTable
            tableKey="material-handovers"
            columns={columns}
            fixedColumnLayout
            fillHeight={!directLookup}
            data={rows}
            meta={tasks.data.meta}
            expandOnRowClick
            renderExpanded={(row) => (
              <HandoverDetails
                selector={
                  row.maintenance_work_order_id
                    ? { maintenance_work_order_id: row.maintenance_work_order_id }
                    : row.work_order_id
                    ? { work_order_id: row.work_order_id }
                    : { material_issue_id: row.material_issue_id! }
                }
                search={filters.search}
                onLoaded={updateReviewed}
              />
            )}
            onPageChange={(page) => changeFilters({ ...filters, page })}
            onPageSizeChange={(per_page) => changeFilters({ ...filters, per_page, page: 1 })}
            emptyState={
              <EmptyState
                icon="inbox"
                title={
                  filters.status === 'pending' && !filters.search
                    ? 'No pending material handovers'
                    : 'No matching material handovers'
                }
                description={
                  filters.status === 'pending' && !filters.search
                    ? 'Requested or issued materials awaiting receipt will appear here.'
                    : 'Review the status filter or search terms.'
                }
              />
            }
          />
        )}
        {completedReviews.map((row) => (
          <div key={row.id} className="border border-default rounded-md">
            <HandoverDetails
              selector={
                row.maintenance_work_order_id
                    ? { maintenance_work_order_id: row.maintenance_work_order_id }
                    : row.work_order_id
                  ? { work_order_id: row.work_order_id }
                  : { material_issue_id: row.material_issue_id! }
              }
              search={filters.search}
            />
          </div>
        ))}
      </div>
    </PageShell>
  );
}
