import { reportMutationError } from '@/lib/formErrors';
import { useState } from 'react';
import { Link, useParams, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { LuPencil, LuTrash2, LuArchiveRestore, LuRefreshCw } from '@/lib/icons';
import toast from 'react-hot-toast';
import { bomsApi } from '@/api/mrp/boms';
import { formatPeso } from '@/lib/formatNumber';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { formatDateTime } from '@/lib/formatDate';

export default function BomDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { can } = usePermission();
  const [deleting, setDeleting] = useState(false);
  const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
  const [restoring, setRestoring] = useState(false);

  const recalculate = useMutation({
    mutationFn: () => bomsApi.recalculate(id!),
    onSuccess: (bom) => {
      qc.setQueryData(['mrp', 'boms', 'detail', id], bom);
      qc.invalidateQueries({ queryKey: ['mrp', 'boms'] });
      toast.success('BOM cost recalculated.');
    },
    onError: (error) => reportMutationError(error, 'Failed to recalculate BOM cost.'),
  });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['mrp', 'boms', 'detail', id],
    queryFn: () => bomsApi.show(id!),
    enabled: !!id,
  });

  const handleDelete = async () => {
    setDeleting(true);
    try {
      await bomsApi.delete(id!);
      qc.invalidateQueries({ queryKey: ['mrp', 'boms'] });
      toast.success('BOM archived.');
      navigate('/mrp/boms');
    } catch {
      toast.error('Failed to archive BOM.');
      setDeleting(false);
    }
  };

  const handleRestore = async () => {
    setRestoring(true);
    try {
      await bomsApi.restore(id!);
      qc.invalidateQueries({ queryKey: ['mrp', 'boms'] });
      toast.success('BOM restored.');
    } catch {
      toast.error('Failed to restore BOM.');
    } finally {
      setRestoring(false);
    }
  };

  if (isLoading)
    return (
      <div>
        <PageHeader title="BOM" backTo="/mrp/boms" backLabel="BOMs" />
        <SkeletonDetail />
      </div>
    );
  if (isError || !data)
    return (
      <div>
        <PageHeader title="BOM" backTo="/mrp/boms" backLabel="BOMs" />
        <EmptyState
          icon="alert-circle"
          title="Failed to load BOM"
          action={
            <Button variant="secondary" onClick={() => refetch()}>
              Retry
            </Button>
          }
        />
      </div>
    );
  const completeness = data.cost_completeness ?? (data.costed_at ? 'incomplete' : 'not_costed');
  const completenessLabel = {
    not_costed: 'Not costed',
    materials_only: 'Materials only',
    incomplete: 'Incomplete estimate',
    manufacturing_estimate: 'Manufacturing estimate',
  }[completeness];
  const readiness = data.product?.finished_good_readiness;
  const margin = data.estimated_margin;

  return (
    <div>
      <PageHeader
        title={
          <div className="flex flex-wrap items-center gap-3">
            <span className="font-mono">{data.product?.part_number ?? '—'}</span>
            <span>{data.product?.name}</span>
            <Chip variant={data.is_active ? 'success' : 'neutral'}>
              v{data.version}
              {data.is_active ? ' · active' : ' · archived'}
            </Chip>
          </div>
        }
        backTo="/mrp/boms"
        backLabel="BOMs"
        actions={
          can('mrp.boms.manage') ? (
            <div className="flex flex-wrap gap-2">
              {data.is_active && !data.deleted_at && (
                <Button
                  variant="secondary"
                  size="sm"
                  onClick={() => recalculate.mutate()}
                  loading={recalculate.isPending}
                >
                  <LuRefreshCw
                    className={`h-3.5 w-3.5 mr-1 ${recalculate.isPending ? 'animate-spin' : ''}`}
                  />{' '}
                  Recalculate cost
                </Button>
              )}
              <Button
                variant="secondary"
                size="sm"
                onClick={() => navigate(`/mrp/boms/${id}/edit`)}
              >
                <LuPencil className="h-3.5 w-3.5 mr-1" /> Edit
              </Button>
              {data.deleted_at && (
                <Button variant="secondary" size="sm" onClick={handleRestore} loading={restoring}>
                  <LuArchiveRestore className="h-3.5 w-3.5 mr-1" /> Restore
                </Button>
              )}
              {!data.deleted_at && !data.is_active && (
                <Button
                  variant="danger"
                  size="sm"
                  onClick={() => setShowDeleteConfirm(true)}
                  loading={deleting}
                >
                  <LuTrash2 className="h-3.5 w-3.5 mr-1" /> Archive
                </Button>
              )}
            </div>
          ) : null
        }
      />
      <ConfirmDialog
        isOpen={showDeleteConfirm}
        onClose={() => setShowDeleteConfirm(false)}
        onConfirm={handleDelete}
        title="Archive this BOM?"
        description="It will be archived and can be restored later."
        variant="danger"
        confirmLabel="Archive"
        pending={deleting}
      />

      <div className="px-5 py-4 space-y-4">
        <Panel
          title="Production readiness"
          meta={data.is_active ? 'Current BOM definition' : 'Historical version'}
        >
          <div className="flex flex-wrap items-center gap-3">
            <Chip variant={completeness === 'manufacturing_estimate' ? 'neutral' : 'warning'}>
              {completenessLabel}
            </Chip>
            <Chip variant={readiness?.ready ? 'success' : 'warning'}>
              {readiness?.ready ? 'Inventory mapping ready' : 'Inventory setup required'}
            </Chip>
            {can('crm.products.view') && data.product && (
              <Link
                to={`/crm/products/${data.product.id}`}
                className="text-sm text-accent hover:underline"
              >
                View product
              </Link>
            )}
            {can('inventory.view') && readiness?.item_id && (
              <Link
                to={`/inventory/items/${readiness.item_id}`}
                className="text-sm text-accent hover:underline"
              >
                View finished-goods item
              </Link>
            )}
          </div>
          {!readiness?.ready && (
            <p className="mt-3 text-sm text-warning-fg">
              {readiness?.reason ?? 'Inventory mapping has not been checked.'}
            </p>
          )}
          {!data.is_active && (
            <p className="mt-3 text-sm text-muted">
              This cost snapshot is preserved. Create a new version to change the definition.
            </p>
          )}
        </Panel>
        <div className="grid min-w-0 gap-4 xl:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
          <Panel
            title="Unit cost estimate"
            meta={
              data.costed_at
                ? `Costed ${formatDateTime(data.costed_at)}`
                : 'Recalculate cost to establish an estimate'
            }
          >
            <dl className="divide-y divide-border text-sm">
              {(
                [
                  ['Materials', data.material_cost],
                  ['Labor', data.labor_cost],
                  ['Machine', data.machine_cost],
                  ['Overhead', data.overhead_cost],
                ] as const
              ).map(([label, value]) => (
                <div key={label} className="flex items-center justify-between gap-4 py-2.5">
                  <dt className="text-muted">{label}</dt>
                  <dd className="font-mono tabular-nums">
                    {data.costed_at ? formatPeso(value) : 'Not costed'}
                  </dd>
                </div>
              ))}
              <div className="flex items-center justify-between gap-4 py-3">
                <dt className="font-medium">Total per {data.product?.unit_of_measure ?? 'unit'}</dt>
                <dd className="text-lg font-mono font-medium tabular-nums">
                  {data.costed_at ? formatPeso(data.total_cost) : 'Not costed'}
                </dd>
              </div>
            </dl>
            <p className="mt-3 text-xs text-muted">
              Setup costs allocated across {data.cost_batch_size ?? '1.000'} units. Component costs
              use base units and include waste.
            </p>
            {data.cost_warnings?.length > 0 && (
              <ul className="mt-4 space-y-2 text-sm text-warning-fg">
                {data.cost_warnings.map((warning, index) => (
                  <li key={`${warning.type}-${warning.item_code}-${index}`}>{warning.message}</li>
                ))}
              </ul>
            )}
          </Panel>
          <Panel
            title={margin?.label ?? 'Estimated gross margin'}
            meta="Base selling price · excluding VAT"
          >
            <dl className="divide-y divide-border text-sm">
              <div className="flex justify-between gap-4 py-2.5">
                <dt className="text-muted">Base selling price</dt>
                <dd className="font-mono tabular-nums">
                  {formatPeso(margin?.selling_price, 'Not configured')}
                </dd>
              </div>
              <div className="flex justify-between gap-4 py-2.5">
                <dt className="text-muted">Estimated contribution / unit</dt>
                <dd className="font-mono tabular-nums">
                  {formatPeso(margin?.profit_per_unit, 'Unavailable')}
                </dd>
              </div>
              <div className="flex justify-between gap-4 py-2.5">
                <dt className="text-muted">
                  {completeness === 'materials_only' ? 'Material contribution %' : 'Gross margin'}
                </dt>
                <dd className="font-mono tabular-nums">
                  {margin?.margin_percent != null ? `${margin.margin_percent}%` : 'Unavailable'}
                </dd>
              </div>
              <div className="flex justify-between gap-4 py-2.5">
                <dt className="text-muted">Markup on estimated cost</dt>
                <dd className="font-mono tabular-nums">
                  {margin?.markup_percent != null ? `${margin.markup_percent}%` : 'Unavailable'}
                </dd>
              </div>
            </dl>
            <p className="mt-4 text-xs text-muted">
              {completeness === 'materials_only'
                ? 'Material contribution excludes labor, machine and overhead. Complete routing costs before relying on gross margin.'
                : 'Estimated costs are not actual profit. Complete cost setup and reconcile actual production costs.'}{' '}
              VAT is not revenue.
            </p>
            {margin?.profit_per_unit == null && (
              <p className="mt-3 text-sm text-warning-fg">
                Configure a positive base price and resolve missing cost data to calculate this
                estimate.
              </p>
            )}
            {data.product?.standard_cost && (
              <p className="mt-3 text-xs text-muted">
                Production inventory valuation uses product standard cost:{' '}
                {formatPeso(data.product.standard_cost)} per unit, frozen on each new work order.
              </p>
            )}
            {data.product?.minimum_selling_price &&
              Number(data.product.minimum_selling_price) <
                Math.max(Number(data.total_cost ?? 0), Number(data.product.standard_cost ?? 0)) && (
                <p className="mt-3 text-sm text-warning-fg">
                  Minimum selling price is below a configured unit cost. Finance should review the
                  threshold.
                </p>
              )}
          </Panel>
        </div>
        <Panel
          title="Materials"
          meta={`${data.item_count} ${data.item_count === 1 ? 'line' : 'lines'}`}
          noPadding
        >
          <div className="overflow-x-auto">
            <table className={`${tableCls} min-w-[860px]`}>
              <thead>
                <tr className={theadTrCls}>
                  <Th className="w-12">#</Th>
                  <Th>Item</Th>
                  <Th align="right">Qty / unit</Th>
                  <Th>UOM</Th>
                  <Th align="right">Waste %</Th>
                  <Th align="right">Effective</Th>
                  <Th align="right">Base quantity</Th>
                  <Th align="right">Cost / base unit</Th>
                  <Th align="right">Extended</Th>
                  <Th>Source</Th>
                </tr>
              </thead>
              <tbody>
                {data.items?.map((m, i) => (
                  <tr key={m.id} className={trCls}>
                    <Td mono className="text-muted">
                      {(i + 1).toString().padStart(2, '0')}
                    </Td>
                    <Td>
                      <div className="font-mono">
                        {can('inventory.view') && m.item ? (
                          <Link
                            to={`/inventory/items/${m.item.id}`}
                            className="text-accent hover:underline"
                          >
                            {m.item.code}
                          </Link>
                        ) : (
                          m.item?.code
                        )}
                      </div>
                      <div className="text-xs text-muted">{m.item?.name}</div>
                    </Td>
                    <Td align="right" mono>
                      {Number(m.quantity_per_unit).toFixed(4)}
                    </Td>
                    <Td>{m.unit}</Td>
                    <Td align="right" mono>
                      {Number(m.waste_factor).toFixed(2)}
                    </Td>
                    <Td align="right" mono className="font-medium">
                      {Number(m.effective_quantity).toFixed(4)}
                    </Td>
                    <Td align="right" mono>
                      {m.cost_quantity === null || !data.costed_at
                        ? '—'
                        : `${Number(m.cost_quantity).toFixed(6)} ${m.item?.unit_of_measure ?? ''}`}
                    </Td>
                    <Td align="right" mono>
                      {m.unit_cost === null || !data.costed_at ? '—' : formatPeso(m.unit_cost)}
                    </Td>
                    <Td align="right" mono className="font-medium">
                      {m.extended_cost === null || !data.costed_at ? '—' : formatPeso(m.extended_cost)}
                    </Td>
                    <Td>
                      {m.cost_source === 'bom_rollup' ? (
                        <Chip variant="info">Subassembly roll-up</Chip>
                      ) : m.cost_source === 'standard_cost' ? (
                        <Chip variant="neutral">Standard cost</Chip>
                      ) : (
                        '—'
                      )}
                    </Td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Panel>
      </div>
    </div>
  );
}
