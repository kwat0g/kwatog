import { usesQuantityForm } from './components/quantityCapture';
import { SimpleWorkOrderFinalInspectionPanel } from './components/SimpleWorkOrderFinalInspectionPanel';
import { useState } from 'react';
import type { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { useParams } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { inspectionsApi } from '@/api/quality/inspections';
import { PageHeader } from '@/components/layout/PageHeader';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { LuFileDown } from '@/lib/icons';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { usePermission } from '@/hooks/usePermission';
import { WorkOrderFinalInspectionPanel } from './components/WorkOrderFinalInspectionPanel';
import { useRealtimeList } from '@/hooks/useRealtimeRecord';
import { ChainHeader, LinkedRecords } from '@/components/chain';
import { formatDateTime } from '@/lib/formatDate';

export default function WorkOrderFinalInspectionPage() {
  const [cocLoading, setCocLoading] = useState(false);
  const { id = '' } = useParams();
  useRealtimeList('inspection', 'visible');
  const { can } = usePermission();
  const client = useQueryClient();
  const key = ['quality', 'work-order-final', id];
  const result = useQuery({
    queryKey: key,
    queryFn: () => inspectionsApi.getWorkOrderFinalInspection(id),
    enabled: Boolean(id),
    refetchOnWindowFocus: false,
  });
  const chain = useQuery({
    queryKey: ['quality', 'inspections', 'chain', result.data?.batches[0]?.id],
    queryFn: () => inspectionsApi.chain(result.data!.batches[0].id),
    enabled: Boolean(result.data?.batches[0]?.id),
    staleTime: 30_000,
  });
  const inspection = result.data?.batches.find(
    (row) => row.id === (result.data?.evidence_page?.inspection_id ?? result.data?.manifest[0]?.inspection_id),
  ) ?? result.data?.batches[0];
  const status = inspection?.status;
  const released = result.data?.state === 'released';
  const label =
    released && status === 'failed'
      ? 'Quality released'
      : status === 'passed'
      ? 'Passed'
      : status === 'failed'
        ? 'Failed'
        : status === 'awaiting_review'
          ? 'Awaiting review'
          : status === 'cancelled'
            ? 'Cancelled'
            : status === 'in_progress'
              ? 'In progress'
              : 'Draft';
  const statusVariant =
    released || status === 'passed'
      ? 'success'
      : status === 'failed'
        ? 'danger'
        : status === 'awaiting_review'
          ? 'warning'
          : status === 'draft' || status === 'cancelled'
            ? 'neutral'
            : 'info';
  const product = result.data?.context?.product ?? inspection?.product;

  const FinalPanel = result.data?.batches.every(usesQuantityForm) ? SimpleWorkOrderFinalInspectionPanel : WorkOrderFinalInspectionPanel;

  return (
    <>
      <PageHeader
        backTo="/quality/inspections"
        backLabel="Back to inspections"
        crumbLabel={inspection?.inspection_number ?? 'Final inspection'}
        title={
          <span>
            {inspection?.inspection_number ?? 'Final inspection'}
            {inspection && (
              <Chip
                className="ml-3"
                variant={statusVariant}
              >
                {label}
              </Chip>
            )}
          </span>
        }
        subtitle={
          product
            ? `${product.part_number} — ${product.name}`
            : 'Finished production quality inspection'
        }
        actions={
          inspection?.status === 'passed' && inspection.stage === 'outgoing' ? (
            inspection.coc_unavailable_reason ? (
              <p role="status" className="max-w-md text-xs text-warning-fg">
                Certificate unavailable: {inspection.coc_unavailable_reason}
              </p>
            ) : (
              <Button
                variant="secondary"
                size="sm"
                icon={<LuFileDown size={14} />}
                loading={cocLoading}
                onClick={async () => {
                  setCocLoading(true);
                  try {
                    await inspectionsApi.generateCoC(inspection.id);
                  } catch (error) {
                    const certificateError = error as AxiosError<{ message?: string }>;
                    toast.error(
                      certificateError.response?.data?.message ??
                        'Could not generate CoC — try again.',
                    );
                  } finally {
                    setCocLoading(false);
                  }
                }}
              >
                Certificate of Conformance
              </Button>
            )
          ) : undefined
        }
      />
      <div className="space-y-5 bg-surface px-4 py-5 sm:px-5">
        {released && status === 'failed' && (
          <p className="text-sm text-muted">Original batch result: Failed</p>
        )}
        {result.isLoading && (
          <div role="status" aria-label="Loading inspection">
            <SkeletonDetail />
          </div>
        )}
        {result.isError && (
          <div role="alert" className="rounded-md border border-default bg-canvas p-4">
            <p className="mb-2 text-sm text-danger-fg">Inspection could not be loaded.</p>
            <Button
              variant="secondary"
              loading={result.isFetching}
              onClick={() => void result.refetch()}
            >
              Retry
            </Button>
          </div>
        )}
        {result.data && (() => {
          const group = result.data;
          const context = group.context;
          const unit = context?.product?.unit_of_measure ?? 'PCS';
          const outstanding = group.batches.filter(batch => ['draft', 'in_progress'].includes(batch.status)).length;
          const reviewCount = group.batches.filter(batch => batch.status === 'awaiting_review').length;
          const currentBatch = group.batches[0];
          const nextAction = group.blocker ?? (outstanding
            ? 'QC: record the defective quantity for each batch, then submit inspection.'
            : reviewCount
              ? `Independent QC reviewer: review the proposed ${(currentBatch?.proposed_result ?? 'result').toUpperCase()}.`
              : group.state === 'released' ? 'Inspection released. Check the linked work order for the next production or warehouse action.' : 'Inspection results recorded.');
          return <>
            {chain.data && chain.data.length > 0 && <section aria-label="Record flow" className="rounded-md border border-default bg-canvas px-4 py-3">
              <h2 className="mb-3 text-sm font-medium text-primary">Record flow</h2>
              <ChainHeader steps={chain.data} />
            </section>}
            <p role="status" className="rounded-md border border-default bg-canvas px-4 py-3 text-sm"><span className="font-medium">Next action: </span>{nextAction}</p>
            <div className="grid gap-5 lg:grid-cols-3 lg:items-start">
              <section aria-label="Inspection details" className="min-w-0 rounded-md border border-default bg-canvas lg:col-span-2">
                <div className="border-b border-default px-4 py-3"><h2 className="text-sm font-medium text-primary">Inspection details</h2></div>
                <dl className="grid grid-cols-2 gap-x-5 gap-y-4 px-4 py-4 sm:grid-cols-3">
                  <div><dt className="text-xs text-muted">Produced quantity</dt><dd className="mt-1 font-mono text-sm tabular-nums">{group.produced_quantity} {unit}</dd></div>
                  <div><dt className="text-xs text-muted">Defective quantity</dt><dd className="mt-1 font-mono text-sm tabular-nums">{group.defective_quantity} {unit}</dd></div>
                  <div><dt className="text-xs text-muted">Accepted quantity</dt><dd className="mt-1 font-mono text-sm tabular-nums">{group.accepted_quantity} {unit}</dd></div>
                  <div><dt className="text-xs text-muted">Inspection batches</dt><dd className="mt-1 text-sm">{group.batches.length}</dd></div>
                  <div><dt className="text-xs text-muted">Inspection status</dt><dd className="mt-1 text-sm capitalize">{group.state.replaceAll('_', ' ')}</dd></div>
                  <div><dt className="text-xs text-muted">Decision rule</dt><dd className="mt-1 text-sm">0 defects = PASS; any defects = FAIL</dd></div>
                </dl>
              </section>
              <aside aria-label="Inspection context" className="min-w-0 space-y-5 lg:row-span-3">
                <section aria-label="Linked records" className="min-w-0 rounded-md border border-default bg-canvas">
                  <h2 className="border-b border-default px-4 py-3 text-sm font-medium text-primary">Linked records</h2>
                  <div className="px-4 py-4"><LinkedRecords className="grid grid-cols-1 gap-4 [&_a]:break-words [&_span]:break-words" groups={[
                    { label: 'Work order', items: [{ id: group.wo_number ?? group.work_order_id, href: `/production/work-orders/${group.work_order_id}` }] },
                    ...(context?.product ? [{ label: 'Product', items: [{ id: `${context.product.part_number} — ${context.product.name}` }] }] : []),
                    ...(context?.batch_number ? [{ label: 'Production batch', items: [{ id: context.batch_number }] }] : []),
                    ...(context?.machine ? [{ label: 'Machine', items: [{ id: `${context.machine.code} — ${context.machine.name}` }] }] : []),
                    ...(context?.mold ? [{ label: 'Mold', items: [{ id: `${context.mold.code} — ${context.mold.name}` }] }] : []),
                    ...(context?.sales_order ? [{ label: 'Sales order', items: [{ id: context.sales_order.so_number }] }] : []),
                  ]} /></div>
                </section>
                <section aria-label="Inspection history" className="min-w-0 rounded-md border border-default bg-canvas">
                  <h2 className="border-b border-default px-4 py-3 text-sm font-medium text-primary">Inspection history</h2>
                  <dl className="grid grid-cols-2 gap-x-5 gap-y-4 px-4 py-4 sm:grid-cols-3 lg:grid-cols-2">
                    <div><dt className="text-xs text-muted">Inspection created by</dt><dd className="mt-1 text-sm font-medium">{currentBatch?.inspector?.name ?? '—'}</dd></div>
                    <div><dt className="text-xs text-muted">Results recorded by</dt><dd className="mt-1 text-sm font-medium">{currentBatch?.result_authors?.map(author => author.name).join(', ') || 'Not recorded'}</dd></div>
                    <div><dt className="text-xs text-muted">Created</dt><dd className="mt-1 font-mono text-sm tabular-nums">{currentBatch?.created_at ? formatDateTime(currentBatch.created_at) : '—'}</dd></div>
                    <div><dt className="text-xs text-muted">Started</dt><dd className="mt-1 font-mono text-sm tabular-nums">{currentBatch?.started_at ? formatDateTime(currentBatch.started_at) : '—'}</dd></div>
                    <div><dt className="text-xs text-muted">Completed</dt><dd className="mt-1 font-mono text-sm tabular-nums">{currentBatch?.completed_at ? formatDateTime(currentBatch.completed_at) : '—'}</dd></div>
                  </dl>
                  {(currentBatch?.notes || currentBatch?.review_remarks || currentBatch?.status === 'awaiting_review') && <div className="space-y-3 border-t border-default px-4 py-3 text-sm">
                    {currentBatch.status === 'awaiting_review' && <p className="text-warning-fg">Awaiting checker review · proposed {(currentBatch.proposed_result ?? 'result').toUpperCase()}</p>}
                    {currentBatch.notes && <p className="whitespace-pre-line break-words"><span className="text-muted">Notes: </span>{currentBatch.notes}</p>}
                    {currentBatch.review_remarks && <p className="whitespace-pre-line break-words"><span className="text-muted">Checker remarks: </span>{currentBatch.review_remarks}</p>}
                  </div>}
                </section>
              </aside>
              <div className="min-w-0 lg:col-span-2">
                <FinalPanel key={id} group={group} canManage={can('quality.inspections.manage')} canReview={can('quality.inspections.review')} onUpdated={(data) => client.setQueryData(key, data)} />
              </div>
            </div>
          </>;
        })()}
      </div>
    </>
  );
}
