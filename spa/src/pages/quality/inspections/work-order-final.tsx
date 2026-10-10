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

export default function WorkOrderFinalInspectionPage() {
  const [cocLoading, setCocLoading] = useState(false);
  const { id = '' } = useParams();
  const { can } = usePermission();
  const client = useQueryClient();
  const key = ['quality', 'work-order-final', id];
  const result = useQuery({
    queryKey: key,
    queryFn: () => inspectionsApi.getWorkOrderFinalInspection(id),
    enabled: Boolean(id),
    refetchOnWindowFocus: false,
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
        {result.data && (
          <WorkOrderFinalInspectionPanel
            key={id}
            group={result.data}
            canManage={can('quality.inspections.manage')}
            canReview={can('quality.inspections.review')}
            onUpdated={(data) => client.setQueryData(key, data)}
          />
        )}
      </div>
    </>
  );
}
