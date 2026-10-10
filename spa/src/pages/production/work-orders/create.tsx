import { useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AxiosError } from 'axios';
import { PageHeader } from '@/components/layout/PageHeader';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Button } from '@/components/ui/Button';
import { workOrdersApi } from '@/api/production/workOrders';
import { FormOptionsStatus } from './FormOptionsStatus';
import { useUnsavedChangesGuard } from '@/hooks/useUnsavedChangesGuard';
import {
  offsetScheduleDate,
  scheduleDate,
  scheduleDateKey,
} from '@/components/production/scheduleDates';

export default function CreateWorkOrderPage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const key = useRef(crypto.randomUUID());
  const submitted = useRef<Parameters<typeof workOrdersApi.create>[0] | null>(null);
  const [product, setProduct] = useState('');
  const [quantity, setQuantity] = useState('');
  const [deadline, setDeadline] = useState('');
  const [automaticTarget] = useState(() =>
    offsetScheduleDate(scheduleDateKey(new Date().toISOString(), 'Asia/Manila'), 14),
  );
  const [saved, setSaved] = useState<string | null>(null);
  const options = useQuery({
    queryKey: ['production', 'work-orders', 'form-options'],
    queryFn: workOrdersApi.formOptions,
  });
  const quantityError =
    quantity &&
    (!/^[1-9]\d*$/.test(quantity) ||
      !Number.isSafeInteger(Number(quantity)) ||
      Number(quantity) > 2147483647)
      ? 'Enter a positive whole-piece quantity.'
      : undefined;
  const target = deadline || automaticTarget;
  const targetEnd = `${target}T23:59:59+08:00`;
  const deadlineError =
    !/^\d{4}-\d{2}-\d{2}$/.test(target) ||
    !Number.isFinite(new Date(targetEnd).getTime()) ||
    new Date(targetEnd).getTime() <= Date.now()
      ? 'Choose today or a future date in Manila.'
      : undefined;
  useUnsavedChangesGuard(!!(product || quantity || deadline) && !saved);
  const selectedProduct = options.data?.products.find((item) => item.id === product);
  const setupBlocked = selectedProduct?.stock_production?.eligible === false;
  const unavailable =
    options.data?.products.filter((item) => item.stock_production?.eligible === false) ?? [];
  const valid =
    !!selectedProduct && !setupBlocked && !!quantity && !quantityError && !deadlineError;
  const create = useMutation({
    mutationFn: () => {
      submitted.current ??= {
        production_purpose: 'stock',
        request_key: key.current,
        product_id: product,
        quantity_target: Number(quantity),
        planned_start: new Date().toISOString(),
        planned_end: targetEnd,
        minimum_workers: 1,
        maximum_workers: 1,
      };
      return workOrdersApi.create(submitted.current);
    },
    onError: (failure) => {
      if (failure instanceof AxiosError && failure.response?.status === 422) {
        submitted.current = null;
        key.current = crypto.randomUUID();
        void queryClient.invalidateQueries({
          queryKey: ['production', 'work-orders', 'form-options'],
        });
      }
    },
    onSuccess: (workOrder) => {
      setSaved(workOrder.id);
      void queryClient.invalidateQueries({ queryKey: ['production', 'work-orders'] });
      navigate(`/production/work-orders/${workOrder.id}`);
    },
  });
  const error =
    create.error instanceof AxiosError
      ? create.error.response?.data?.message
      : create.error?.message;
  return (
    <div>
      <PageHeader
        title="Produce for stock"
        backTo="/production/work-orders"
        backLabel="Work orders"
        subtitle="Plan new pieces for inventory. Production and QC approval remain required."
      />
      <form
        className="mx-auto max-w-3xl space-y-6 px-5 py-8"
        onSubmit={(event) => {
          event.preventDefault();
          if (valid && !create.isPending && !saved) create.mutate();
        }}
      >
        <FormOptionsStatus
          isLoading={options.isLoading}
          isError={options.isError}
          isFetching={options.isFetching}
          onRetry={() => {
            void options.refetch();
          }}
        />
        <fieldset
          disabled={create.isPending || !!saved || !!submitted.current}
          className="space-y-6"
        >
          <legend className="mb-3 font-medium text-primary">What to produce</legend>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <Select
              label="Product"
              fieldSize="lg"
              containerClassName="sm:col-span-2"
              required
              value={product}
              onChange={(event) => setProduct(event.target.value)}
            >
              <option value="">Select product…</option>
              {options.data?.products.map((item) => (
                <option
                  key={item.id}
                  value={item.id}
                  disabled={item.stock_production?.eligible === false}
                >
                  {item.part_number} · {item.name}
                  {item.stock_production?.eligible === false
                    ? ` · ${item.stock_production.blockers[0]}`
                    : ''}
                </option>
              ))}
            </Select>
            <Input
              label="Quantity"
              fieldSize="lg"
              required
              type="number"
              min="1"
              max="2147483647"
              step="1"
              suffix="pcs"
              value={quantity}
              onChange={(event) => setQuantity(event.target.value)}
              error={quantityError}
              helper="Whole pieces only."
            />
          </div>
          <div className="space-y-3 border-y border-default py-4">
            {!deadlineError && (
              <p className="text-sm text-muted">
                {deadline ? 'Target completion' : '14-day planning window · Target completion'}:{' '}
                <span className="font-medium text-primary">
                  {scheduleDate(targetEnd, 'Asia/Manila')}
                </span>{' '}
                (Manila).
              </p>
            )}
            <details>
              <summary className="w-fit cursor-pointer rounded text-sm font-medium text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent">
                Planning options
              </summary>
              <div className="mt-4 max-w-sm">
                <Input
                  label="Target completion date (optional)"
                  fieldSize="lg"
                  type="date"
                  min={scheduleDateKey(new Date().toISOString(), 'Asia/Manila')}
                  value={deadline}
                  onChange={(event) => setDeadline(event.target.value)}
                  error={deadlineError}
                  helper="Leave blank to use the 14-day planning window. Times use Manila."
                />
              </div>
            </details>
          </div>
        </fieldset>
        {unavailable.length > 0 && (
          <details className="text-sm text-danger-fg">
            <summary>Products needing setup ({unavailable.length})</summary>
            <ul className="mt-2 space-y-1">
              {unavailable.map((item) => (
                <li key={item.id}>
                  {item.part_number}: {item.stock_production?.blockers.join(' ')}
                </li>
              ))}
            </ul>
          </details>
        )}
        {setupBlocked && (
          <p role="alert" className="text-danger-fg">
            {selectedProduct.stock_production?.blockers.join(' ')}
          </p>
        )}
        <p className="text-sm text-muted">
          Plan production saves your work order. On its record page, use Confirm to review the
          machine, mold, workers and schedule.
        </p>
        {error && (
          <p role="alert" className="text-danger-fg">
            {error}
          </p>
        )}
        <div className="flex justify-end gap-2">
          <Button
            type="button"
            variant="secondary"
            disabled={create.isPending}
            onClick={() => navigate('/production/work-orders')}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            variant="primary"
            disabled={!valid || create.isPending || !!saved}
            loading={create.isPending}
          >
            Plan production
          </Button>
        </div>
      </form>
    </div>
  );
}
