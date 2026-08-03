import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate} from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { ShieldAlert } from 'lucide-react';
import { mrbApi } from '@/api/inventory/mrb';
import { itemsApi } from '@/api/inventory/items';
import { warehouseApi } from '@/api/inventory/warehouse';
import { ncrsApi } from '@/api/quality/ncrs';
import { Button } from '@/components/ui/Button';
import { Chip, type ChipVariant } from '@/components/ui/Chip';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Modal } from '@/components/ui/Modal';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDate } from '@/lib/formatDate';
import { applyServerValidationErrors, onFormInvalid } from '@/lib/formErrors';
import { numberInputProps } from '@/lib/numberInput';
import type { MrbRecord, MrbStatus } from '@/types/inventory';

const mrbStatusVariant = (s: MrbStatus): ChipVariant => {
  if (s === 'held')     return 'warning';
  if (s === 'released') return 'success';
  if (s === 'scrapped') return 'danger';
  return 'info'; // returned
};

const holdSchema = z.object({
  item_id: z.string().min(1, 'Item is required.'),
  source_location_id: z.string().min(1, 'Source location is required.'),
  quantity: z
    .string()
    .regex(/^\d+(\.\d{1,4})?$/, 'Up to 4 decimals.')
    .refine((v) => Number(v) > 0, 'Must be greater than zero.'),
  quarantine_location_id: z.string().optional().or(z.literal('')),
  ncr_id: z.string().optional().or(z.literal('')),
  notes: z.string().max(1000).optional().or(z.literal('')) });
type HoldValues = z.infer<typeof holdSchema>;

export default function MrbListPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { can } = usePermission();
  const canManage = can('inventory.mrb.manage');

  const [statusFilter, setStatusFilter] = useState('');
  const [page, setPage] = useState(1);
  const [holdOpen, setHoldOpen] = useState(false);

  const filters = useMemo(
    () => ({ page, per_page: 25, ...(statusFilter ? { status: statusFilter } : {}) }),
    [page, statusFilter],
  );

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['inventory', 'mrb', filters],
    queryFn: () => mrbApi.list(filters),
    placeholderData: (prev) => prev });
  const { data: options } = useQuery({
    queryKey: ['inventory', 'mrb', 'options'],
    queryFn: () => mrbApi.options(),
    staleTime: 5 * 60 * 1000 });
  const statusFilters = [{ value: '', label: 'All statuses' }, ...(options?.statuses ?? [])];

  const columns: Column<MrbRecord>[] = [
    {
      key: 'mrb',
      header: 'MRB #',
      cell: (r) => (
        <span className="font-mono">
          {r.mrb_number}
        </span>
      ) },
    {
      key: 'item',
      header: 'Item',
      cell: (r) => (
        <div>
          <div className="font-mono">{r.item?.code ?? '—'}</div>
          <div className="text-muted">{r.item?.name ?? '—'}</div>
        </div>
      ) },
    {
      key: 'qty',
      header: 'Qty',
      align: 'right',
      cell: (r) => (
        <span className="font-mono tabular-nums">
          {r.quantity} {r.item?.unit_of_measure ?? ''}
        </span>
      ) },
    {
      key: 'status',
      header: 'Status',
      cell: (r) => <Chip variant={mrbStatusVariant(r.status)}>{r.status_label}</Chip> },
    {
      key: 'location',
      header: 'Location',
      cell: (r) => (
        <span className="font-mono text-xs">
          {r.source_location?.full_code ?? '—'} → {r.quarantine_location?.full_code ?? '—'}
        </span>
      ) },
    {
      key: 'held',
      header: 'Held',
      cell: (r) => (
        <div>
          <div>{r.held_by ?? '—'}</div>
          <div className="text-muted font-mono">{formatDate(r.held_at)}</div>
        </div>
      ) },
    {
      key: 'ncr',
      header: 'NCR',
      cell: (r) =>
        r.ncr ? (
          <span className="font-mono">
            {r.ncr.ncr_number}
          </span>
        ) : (
          <span className="text-muted">—</span>
        ) },
  ];

  return (
    <div>
      <PageHeader
        title="MRB / Quarantine"
        subtitle={data ? `${data.meta.total} records` : undefined}
        actions={
          canManage ? (
            <Button variant="primary" icon={<ShieldAlert size={14} />} onClick={() => setHoldOpen(true)}>
              Raise hold
            </Button>
          ) : undefined
        }
      />

      <div className="px-5 pt-3">
        <Select
          className="max-w-xs"
          value={statusFilter}
          onChange={(e) => {
            setStatusFilter(e.target.value);
            setPage(1);
          }}
        >
          {statusFilters.map((s) => (
            <option key={s.value} value={s.value}>
              {s.label}
            </option>
          ))}
        </Select>
      </div>

      {isLoading && !data && (
        <div className="px-5 py-4">
          <SkeletonTable rows={6} columns={7} />
        </div>
      )}
      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load MRB records"
          action={<Button onClick={() => refetch()}>Retry</Button>}
        />
      )}
      {data && data.data.length === 0 && (
        <EmptyState
          icon="inbox"
          title="No MRB records"
          description="Place nonconforming stock on hold to move it into a quarantine location."
          action={
            canManage ? (
              <Button variant="primary" onClick={() => setHoldOpen(true)}>
                Raise hold
              </Button>
            ) : undefined
          }
        />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable onRowClick={(r) => navigate(`/inventory/mrb/${r.id}`)}
            columns={columns} data={data.data} meta={data.meta} onPageChange={setPage} />
        </div>
      )}

      {canManage && (
        <HoldModal
          isOpen={holdOpen}
          onClose={() => setHoldOpen(false)}
          onSuccess={() => {
            qc.invalidateQueries({ queryKey: ['inventory', 'mrb'] });
            setHoldOpen(false);
          }}
        />
      )}
    </div>
  );
}

function HoldModal({
  isOpen,
  onClose,
  onSuccess }: {
  isOpen: boolean;
  onClose: () => void;
  onSuccess: () => void;
}) {
  const { register, handleSubmit, reset, setError, formState: { errors, isSubmitting } } =
    useForm<HoldValues>({
      resolver: zodResolver(holdSchema),
      defaultValues: {
        item_id: '',
        source_location_id: '',
        quantity: '',
        quarantine_location_id: '',
        ncr_id: '',
        notes: '' } });

  const { data: itemsResp } = useQuery({
    queryKey: ['inventory', 'items', 'for-mrb'],
    queryFn: () => itemsApi.list({ per_page: 500, is_active: 'true' }),
    enabled: isOpen });
  const itemOpts = itemsResp?.data ?? [];

  const { data: warehouses } = useQuery({
    queryKey: ['inventory', 'warehouse', 'tree'],
    queryFn: () => warehouseApi.tree(),
    enabled: isOpen });

  // All locations (source can be any location holding the stock).
  const allLocations = useMemo(
    () =>
      (warehouses ?? []).flatMap((w) =>
        (w.zones ?? []).flatMap((z) =>
          (z.locations ?? []).map((l) => ({
            id: l.id,
            label: `${w.code}-${z.code}-${l.code}`,
            zoneType: z.zone_type })),
        ),
      ),
    [warehouses],
  );
  // Only quarantine-zone locations are valid quarantine targets.
  const quarantineLocations = allLocations.filter((l) => l.zoneType === 'quarantine');

  const { data: ncrsResp } = useQuery({
    queryKey: ['quality', 'ncrs', 'for-mrb'],
    queryFn: () => ncrsApi.list({ per_page: 100 }),
    enabled: isOpen });
  const ncrOpts = ncrsResp?.data ?? [];

  const mutation = useMutation({
    mutationFn: (v: HoldValues) =>
      mrbApi.hold({
        item_id: v.item_id,
        quantity: v.quantity,
        source_location_id: v.source_location_id,
        quarantine_location_id: v.quarantine_location_id || undefined,
        ncr_id: v.ncr_id || undefined,
        notes: v.notes || undefined }),
    onSuccess: (rec) => {
      toast.success(`MRB ${rec.mrb_number} raised — stock moved to quarantine.`);
      reset();
      onSuccess();
    },
    onError: (e) => applyServerValidationErrors(e, setError, 'Failed to raise MRB hold.') });

  return (
    <Modal
      isOpen={isOpen}
      onClose={() => {
        reset();
        onClose();
      }}
      title="Raise MRB hold"
      size="lg"
    >
      <form
        onSubmit={handleSubmit((v) => mutation.mutate(v), onFormInvalid<HoldValues>())}
        className="py-4 space-y-3"
      >
        <Select label="Item" required {...register('item_id')} error={errors.item_id?.message}>
          <option value="">Select item…</option>
          {itemOpts.map((it) => (
            <option key={it.id} value={it.id}>
              {it.code} — {it.name}
            </option>
          ))}
        </Select>

        <div className="grid grid-cols-2 gap-3">
          <Select
            label="Source location"
            required
            {...register('source_location_id')}
            error={errors.source_location_id?.message}
          >
            <option value="">Select location…</option>
            {allLocations.map((l) => (
              <option key={l.id} value={l.id}>
                {l.label}
              </option>
            ))}
          </Select>
          <Input
            label="Quantity"
            required
            placeholder="0"
            className="font-mono tabular-nums text-right"
            {...numberInputProps()}
            {...register('quantity')}
            error={errors.quantity?.message}
          />
        </div>

        <Select
          label="Quarantine location"
          helper="Leave blank to auto-select the warehouse's quarantine location."
          {...register('quarantine_location_id')}
          error={errors.quarantine_location_id?.message}
        >
          <option value="">Auto-select quarantine location</option>
          {quarantineLocations.map((l) => (
            <option key={l.id} value={l.id}>
              {l.label}
            </option>
          ))}
        </Select>

        <Select
          label="Linked NCR (optional)"
          {...register('ncr_id')}
          error={errors.ncr_id?.message}
        >
          <option value="">— None —</option>
          {ncrOpts.map((n) => (
            <option key={n.id} value={n.id}>
              {n.ncr_number}
            </option>
          ))}
        </Select>

        <Textarea
          label="Notes"
          rows={2}
          maxLength={1000}
          placeholder="Optional — reason for the hold"
          {...register('notes')}
          error={errors.notes?.message}
        />

        <div className="flex justify-end gap-2 pt-2">
          <Button
            type="button"
            variant="secondary"
            onClick={() => {
              reset();
              onClose();
            }}
            disabled={mutation.isPending}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            variant="primary"
            disabled={isSubmitting || mutation.isPending}
            loading={mutation.isPending}
          >
            {mutation.isPending ? 'Placing hold…' : 'Raise hold'}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
