/** Identify the batch for a manual inspection; record its result on the next page. */
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { z } from 'zod';
import { zodResolver } from '@hookform/resolvers/zod';
import toast from 'react-hot-toast';
import { onFormInvalid } from '@/lib/formErrors';
import type { AxiosError } from 'axios';
import { inspectionsApi } from '@/api/quality/inspections';
import { productsApi } from '@/api/crm/products';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import type { CreateInspectionData, InspectionStage } from '@/types/quality';

import { useFormSafety } from '@/hooks/useFormSafety';
import { FormDraftBanner } from '@/components/ui/FormDraftBanner';
import { FormActions } from '@/components/ui/FormActions';
const schema = z
  .object({
    stage: z.string().min(1, 'Stage is required'),
    product_id: z.string().min(1, 'Product is required'),
    batch_quantity: z.coerce.number().int().min(1, 'Must be at least 1'),
    work_order_output_id: z.string().optional(),
    notes: z.string().max(2000).optional(),
  })
  .superRefine((values, ctx) => {
    if (values.stage === 'outgoing' && !values.work_order_output_id) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['work_order_output_id'],
        message: 'Output batch is required for outgoing inspection',
      });
    }
  });

type FormValues = z.infer<typeof schema>;

export default function CreateInspectionPage() {
  const navigate = useNavigate();
  const inspectionOptions = useQuery({
    queryKey: ['quality', 'inspections', 'options'],
    queryFn: () => inspectionsApi.options(),
  });
  const stages = inspectionOptions.data?.stages ?? [];

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    // Batch size is transactional input, not a catalog default.
    defaultValues: { stage: '', product_id: '', work_order_output_id: '', notes: '' },
  });
  const {
    register,
    handleSubmit,
    watch,
    setValue,
    formState: { errors },
  } = form;

  const stage = watch('stage');
  const productId = watch('product_id');

  const workOrderOutputs = useQuery({
    queryKey: ['quality', 'inspection-output-options', productId],
    queryFn: () => inspectionsApi.workOrderOutputs(productId),
    enabled: stage === 'outgoing' && Boolean(productId),
  });
  const outputId = watch('work_order_output_id');
  const selectedOutput = workOrderOutputs.data?.find((output) => output.id === outputId);
  const selectedGoodCount = selectedOutput?.good_count;
  const samplingMethod = inspectionOptions.data?.sampling_methods.find(
    (method) => method.stage === stage,
  );

  const aqlPreview = useQuery({
    queryKey: ['quality', 'aql-preview', selectedGoodCount],
    queryFn: () => inspectionsApi.aqlPreview(selectedGoodCount ?? 0),
    enabled:
      stage === 'outgoing' &&
      samplingMethod?.value === 'aql' &&
      selectedGoodCount !== undefined &&
      selectedGoodCount > 0,
  });

  useEffect(() => {
    if (stage === 'outgoing' && selectedGoodCount) {
      setValue('batch_quantity', selectedGoodCount, { shouldValidate: true, shouldDirty: true });
    }
  }, [selectedGoodCount, setValue, stage]);

  const products = useQuery({
    queryKey: ['crm', 'products', { is_active: true, per_page: 200 }],
    queryFn: () => productsApi.options({ is_active: true, per_page: 200 }),
  });
  const lookups = [
    inspectionOptions,
    products,
    ...(stage === 'outgoing' && productId ? [workOrderOutputs] : []),
  ];
  const lookupFailed = lookups.some((query) => query.isError);
  const lookupPending = lookups.some((query) => query.isFetching);

  const submit = useMutation({
    mutationFn: (data: CreateInspectionData) => inspectionsApi.create(data),
    onSuccess: (insp) => {
      toast.success(`Inspection ${insp.inspection_number} opened`);
      navigate(`/quality/inspections/${insp.id}`);
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Failed to open inspection');
    },
  });
  const safety = useFormSafety({ form, saved: submit.isSuccess });

  return (
    <div>
      <PageHeader
        backTo="/quality/inspections"
        backLabel="Back to inspections"
        title="Open inspection"
      />
      <FormDraftBanner safety={safety} />
      {lookupFailed && (
        <div role="alert" className="px-5 py-3 text-sm text-danger-fg">
          Inspection options could not be loaded.{' '}
          <Button
            variant="secondary"
            size="sm"
            onClick={() => {
              lookups.forEach((query) => {
                if (query.isError) void query.refetch();
              });
            }}
          >
            Retry options
          </Button>
        </div>
      )}
      <form
        onSubmit={handleSubmit((v) => {
          if (lookupFailed || lookupPending) return;
          submit.mutate({
            stage: v.stage as InspectionStage,
            product_id: v.product_id,
            batch_quantity: Number(v.batch_quantity),
            work_order_output_id: v.stage === 'outgoing' ? v.work_order_output_id : undefined,
            notes: v.notes,
          });
        }, onFormInvalid<FormValues>())}
        className="px-5 py-4 grid grid-cols-1 lg:grid-cols-3 gap-4"
      >
        <div className="lg:col-span-2 space-y-4">
          <p className="text-sm text-muted">
            Inspections from receipts, production and returns already appear in your inspection
            list. Open one here only when you need a manual inspection.
          </p>
          <Panel title="Inspection details">
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <Select label="Stage" required {...register('stage')} error={errors.stage?.message}>
                <option value="">— Select —</option>
                {stages.map((stage) => (
                  <option key={stage.value} value={stage.value}>
                    {stage.label}
                  </option>
                ))}
              </Select>
              <Select
                searchable
                label="Product"
                required
                {...register('product_id')}
                error={errors.product_id?.message}
              >
                <option value="">Select…</option>
                {products.data?.data?.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.part_number} — {p.name}
                  </option>
                ))}
              </Select>
              <Input
                label="Batch quantity"
                type="number"
                min={1}
                required
                readOnly={stage === 'outgoing'}
                helper={stage === 'outgoing' ? 'Taken from the selected output batch.' : undefined}
                {...register('batch_quantity')}
                error={errors.batch_quantity?.message}
              />
              {stage === 'outgoing' && (
                <Select
                  searchable
                  label="Output batch"
                  required
                  {...register('work_order_output_id')}
                  error={errors.work_order_output_id?.message}
                  disabled={!productId || workOrderOutputs.isLoading}
                >
                  <option value="">
                    {!productId
                      ? 'Select a product first…'
                      : workOrderOutputs.isLoading
                        ? 'Loading output batches…'
                        : 'Select…'}
                  </option>
                  {workOrderOutputs.data?.map((output) => (
                    <option key={output.id} value={output.id}>
                      {output.batch_code ?? 'Unlabelled batch'} —{' '}
                      {output.work_order?.wo_number ?? 'WO'} — good {output.good_count}
                    </option>
                  ))}
                </Select>
              )}
            </div>
          </Panel>
          <Textarea
            label="Notes (optional)"
            rows={3}
            {...register('notes')}
            error={errors.notes?.message}
          />
        </div>

        <Panel title="Inspection coverage">
          {stage === 'outgoing' ? (
            samplingMethod?.value === 'aql' ? (
              <>
                <p className="text-sm">
                  The selected output batch uses the configured AQL sample and acceptance limits.
                </p>
                <details className="mt-3 rounded-md border border-default px-3 py-2">
                  <summary className="cursor-pointer text-sm font-medium">Sample plan</summary>
                  <div className="pt-3">
                    {selectedGoodCount === undefined ? (
                      <p role="status" className="text-sm text-muted">
                        Select an output batch to preview its sample plan.
                      </p>
                    ) : aqlPreview.isFetching ? (
                      <p role="status" className="text-sm text-muted">
                        Loading sample plan…
                      </p>
                    ) : aqlPreview.isError ? (
                      <div role="alert" className="text-sm text-danger-fg">
                        Sample plan could not be loaded.{' '}
                        <Button
                          type="button"
                          variant="secondary"
                          size="sm"
                          onClick={() => void aqlPreview.refetch()}
                        >
                          Retry sample plan
                        </Button>
                      </div>
                    ) : aqlPreview.data ? (
                      <dl className="space-y-2 text-sm">
                        <div className="flex justify-between">
                          <dt className="text-muted">Code letter</dt>
                          <dd className="font-mono tabular-nums">{aqlPreview.data.code}</dd>
                        </div>
                        <div className="flex justify-between">
                          <dt className="text-muted">Sample size</dt>
                          <dd className="font-mono tabular-nums">{aqlPreview.data.sample_size}</dd>
                        </div>
                        <div className="flex justify-between">
                          <dt className="text-muted">Accept (Ac)</dt>
                          <dd className="font-mono tabular-nums">{aqlPreview.data.accept}</dd>
                        </div>
                        <div className="flex justify-between">
                          <dt className="text-muted">Reject (Re)</dt>
                          <dd className="font-mono tabular-nums">{aqlPreview.data.reject}</dd>
                        </div>
                      </dl>
                    ) : null}
                  </div>
                </details>
              </>
            ) : (
              <p className="text-sm">
                Inspect all {selectedGoodCount ?? 'output'} pieces against the approved checklist.
                Every check must pass; any observed defect fails inspection.
              </p>
            )
          ) : stage === 'in_process' ? (
            <p className="text-sm">
              The saved production inspection sets its planned sample. Open the inspection created
              from the work order to follow its approved plan.
            </p>
          ) : (
            <p className="text-sm">
              Receipts and returns create their own inspections with the correct item, quantity,
              unit, and approved plan. Use those records for acceptance and recovery.
            </p>
          )}
          {stage && stage !== 'outgoing' && (
            <p role="status" className="mt-3 text-sm text-warning-fg">
              This form creates standalone product evidence. It does not authorize receipt, return,
              or stock release.
            </p>
          )}
          <Button
            type="button"
            variant="secondary"
            size="sm"
            className="mt-3"
            onClick={() => navigate(`/quality/inspections?stage=${stage}&work_queue=to_inspect`)}
          >
            Find source inspections
          </Button>
        </Panel>

        <FormActions>
          <Button variant="secondary" type="button" onClick={() => navigate(-1)}>
            Cancel
          </Button>
          <Button
            variant="primary"
            type="submit"
            disabled={lookupFailed || lookupPending}
            loading={submit.isPending}
          >
            Open inspection
          </Button>
        </FormActions>
      </form>
    </div>
  );
}
