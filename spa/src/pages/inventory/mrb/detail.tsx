import { useMemo, useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { mrbApi } from '@/api/inventory/mrb';
import { warehouseApi } from '@/api/inventory/warehouse';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Input } from '@/components/ui/Input';
import { Checkbox } from '@/components/ui/Checkbox';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { useAuthStore } from '@/stores/authStore';
import { formatDate, formatDateTime } from '@/lib/formatDate';
import { applyServerValidationErrors, onFormInvalid } from '@/lib/formErrors';
import type { ChipVariant } from '@/components/ui/Chip';
import type { MrbRecord, MrbDisposition, MrbStatus } from '@/types/inventory';

const mrbStatusVariant = (s: MrbStatus): ChipVariant => {
  if (s === 'held') return 'warning';
  if (s === 'released') return 'success';
  if (s === 'scrapped') return 'danger';
  return 'info'; // returned
};

// Rework / use-as-is send stock back into good inventory, so a target good
// location is required by the backend; scrap / return do not use one.
const requiresTarget = (d: MrbDisposition | '') => d === 'rework' || d === 'use_as_is';

const releaseSchema = z
  .object({
    disposition: z.string().min(1, 'Disposition is required.'),
    target_location_id: z.string().optional().or(z.literal('')),
    notes: z.string().max(1000).optional().or(z.literal('')),
    evidence_reference: z.string().trim().max(500).optional().or(z.literal('')),
    concession_approval_reference: z.string().trim().max(500).optional().or(z.literal('')),
    recovery_passed: z.boolean().optional(),
  })
  .refine((v) => !requiresTarget(v.disposition as MrbDisposition) || !!v.target_location_id, {
    message: 'A target good location is required for rework / use-as-is.',
    path: ['target_location_id'],
  })
  .refine(
    (v) =>
      !requiresTarget(v.disposition as MrbDisposition) || Boolean(v.evidence_reference?.trim()),
    {
      message: 'Completed reinspection evidence is required before releasing good inventory.',
      path: ['evidence_reference'],
    },
  )
  .refine((v) => !requiresTarget(v.disposition as MrbDisposition) || v.recovery_passed === true, {
    message: 'Confirm completed reinspection of all held material passed.',
    path: ['recovery_passed'],
  });
type ReleaseValues = z.infer<typeof releaseSchema>;

export default function MrbDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const { can } = usePermission();
  const currentUserId = useAuthStore((state) => state.user?.id);
  const [releaseOpen, setReleaseOpen] = useState(false);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['inventory', 'mrb', id],
    queryFn: () => mrbApi.show(id),
    enabled: !!id,
  });
  const { data: mrbOptions } = useQuery({
    queryKey: ['inventory', 'mrb', 'options'],
    queryFn: () => mrbApi.options(),
    staleTime: 300_000,
  });

  if (isLoading) return <SkeletonDetail />;
  if (isError || !data)
    return (
      <EmptyState
        icon="alert-circle"
        title="Failed to load MRB record"
        action={
          <Button variant="secondary" onClick={() => refetch()}>
            Retry
          </Button>
        }
      />
    );

  const canManage = can('inventory.mrb.manage');
  const isHoldCreator = data.held_by_id === currentUserId;
  const canRelease = canManage && data.status === 'held' && data.actions?.can_release === true;
  const releaseBlocked = canManage && data.status === 'held' && data.actions?.can_release === false;

  return (
    <div>
      <PageHeader
        title={<span className="font-mono">{data.mrb_number}</span>}
        backTo="/inventory/mrb"
        backLabel="MRB / Quarantine"
        actions={
          <div className="flex items-center gap-2">
            <Chip variant={mrbStatusVariant(data.status)}>{data.status_label}</Chip>
            {canRelease && (
              <Button variant="primary" onClick={() => setReleaseOpen(true)}>
                Release
              </Button>
            )}
            {releaseBlocked && (
              <p role="status" className="text-xs text-muted">
                {isHoldCreator
                  ? 'Another authorized user must release a hold you created.'
                  : 'This MRB can no longer be released by your account.'}
              </p>
            )}
          </div>
        }
      />

      <div className="px-5 pt-3 pb-4 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-2">
        <StatCard
          label="Quantity"
          value={
            <span className="font-mono tabular-nums">
              {data.quantity} {data.item?.unit_of_measure ?? ''}
            </span>
          }
        />
        <StatCard
          label="Item"
          value={data.item?.code ?? '—'}
          helper={data.item?.name ?? undefined}
        />
        <StatCard
          label="Disposition"
          value={
            data.disposition_label ??
            data.ncr?.disposition_label ??
            (data.return_request?.disposition === 'rework'
              ? 'Rework'
              : data.return_request
                ? 'Restock (use as is)'
                : undefined) ??
            data.disposition ??
            data.ncr?.disposition ??
            '—'
          }
          helper={
            !data.disposition && data.ncr?.disposition
              ? 'MRB decision — awaiting release'
              : undefined
          }
        />
        <StatCard
          label="Held"
          value={formatDate(data.held_at)}
          helper={data.held_by ?? undefined}
        />
      </div>

      <div className="px-5 pb-4 space-y-4">
        <Panel title="Movement">
          <dl className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 text-sm">
            <Field label="Source location" value={data.source_location?.full_code} mono />
            <Field label="Quarantine location" value={data.quarantine_location?.full_code} mono />
            <Field label="Release location" value={data.release_location?.full_code} mono />
            <Field
              label="Linked customer return"
              value={
                data.return_request ? (
                  <Link
                    to={`/return-management/${data.return_request.id}`}
                    className="font-mono text-accent hover:underline"
                  >
                    {data.return_request.rma_number}
                  </Link>
                ) : (
                  '—'
                )
              }
            />
            <Field label="Hold movement" value={data.hold_movement_id} mono />
            <Field label="Release movement" value={data.release_movement_id} mono />
            <Field
              label="Linked NCR"
              value={
                data.ncr ? (
                  <Link to={`/quality/ncrs/${data.ncr.id}`} className="font-mono text-accent">
                    {data.ncr.ncr_number}
                  </Link>
                ) : (
                  '—'
                )
              }
            />
            <Field
              label="NCR status"
              value={data.ncr ? <Chip variant={chipVariantForStatus(data.ncr.status)}>{data.ncr.status_label ?? data.ncr.status}</Chip> : '—'}
            />
            <Field
              label="Linked inspection"
              value={
                data.inspection ? (
                  <Link
                    to={`/quality/inspections/${data.inspection.id}`}
                    className="font-mono text-accent"
                  >
                    {data.inspection.inspection_number ?? data.inspection.id}
                  </Link>
                ) : (
                  '—'
                )
              }
            />
            <Field
              label="Inspection status"
              value={data.inspection ? <Chip variant={chipVariantForStatus(data.inspection.status)}>{data.inspection.status_label ?? data.inspection.status}</Chip> : '—'}
            />
          </dl>
        </Panel>

        {data.concession_approval_reference && (
          <Panel title="Concession approval">
            <p className="text-sm break-words">{data.concession_approval_reference}</p>
            <p className="mt-1 text-xs text-muted">
              Released by {data.released_by} · {formatDateTime(data.released_at)}
            </p>
          </Panel>
        )}
        <Panel title="Timeline">
          <ol className="space-y-3 text-sm">
            <li className="flex items-start gap-3">
              <span className="mt-1 h-2 w-2 rounded-full bg-warning-fg shrink-0" />
              <div>
                <div className="font-medium text-primary">Held</div>
                <div className="text-muted">
                  {data.held_by ?? '—'} · {formatDateTime(data.held_at)}
                </div>
              </div>
            </li>
            {data.released_at ? (
              <li className="flex items-start gap-3">
                <span className="mt-1 h-2 w-2 rounded-full bg-success-fg shrink-0" />
                <div>
                  <div className="font-medium text-primary">
                    Released
                    {data.disposition ? ` — ${data.disposition_label ?? data.disposition}` : ''}
                  </div>
                  <div className="text-muted">
                    {data.released_by ?? '—'} · {formatDateTime(data.released_at)}
                  </div>
                </div>
              </li>
            ) : (
              <li className="flex items-start gap-3">
                <span className="mt-1 h-2 w-2 rounded-full bg-elevated shrink-0" />
                <div className="text-muted">Awaiting release</div>
              </li>
            )}
          </ol>
        </Panel>

        {data.notes && (
          <Panel title="Notes">
            <p className="text-sm whitespace-pre-wrap">{data.notes}</p>
          </Panel>
        )}
      </div>

      {can('inventory.mrb.manage') && (
        <ReleaseModal
          record={data}
          dispositions={mrbOptions?.dispositions ?? []}
          isOpen={releaseOpen}
          onClose={() => setReleaseOpen(false)}
        />
      )}
    </div>
  );
}

function Field({ label, value, mono }: { label: string; value: React.ReactNode; mono?: boolean }) {
  const empty = value === null || value === undefined || value === '';
  return (
    <div>
      <dt className="text-2xs uppercase tracking-wider text-muted font-medium">{label}</dt>
      <dd className={mono ? 'font-mono' : ''}>{empty ? '—' : value}</dd>
    </div>
  );
}

function ReleaseModal({
  record,
  dispositions,
  isOpen,
  onClose,
}: {
  record: MrbRecord;
  dispositions: Array<{ value: string; label: string }>;
  isOpen: boolean;
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const customerReturn = Boolean(record.return_request);
  const canReleaseGoodStock = record.actions?.can_release_good_stock !== false;
  // Start from the MRB decision recorded on the NCR. It stays editable: a
  // rework that fails still has to be scrapped or returned from here.
  const decided =
    record.ncr?.disposition ??
    (customerReturn
      ? record.return_request?.disposition === 'rework'
        ? 'rework'
        : 'use_as_is'
      : '');
  const releaseOptions = dispositions.filter(
    (option) =>
      !(customerReturn && option.value === 'return_to_supplier') &&
      (canReleaseGoodStock || !['rework', 'use_as_is'].includes(option.value)),
  );
  // Released stock usually goes back to the bin it was held from.
  const initial = {
    disposition: !canReleaseGoodStock && requiresTarget(decided as MrbDisposition) ? '' : decided,
    target_location_id: record.source_location?.id ?? '',
    notes: '',
    evidence_reference: '',
    concession_approval_reference: '',
    recovery_passed: false,
  };
  const {
    register,
    handleSubmit,
    reset,
    watch,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<ReleaseValues>({
    resolver: zodResolver(
      releaseSchema.refine(
        (v) =>
          v.disposition !== 'use_as_is' ||
          !record.requires_concession_approval ||
          (Boolean(v.concession_approval_reference?.trim()) &&
            v.concession_approval_reference?.trim() !== v.evidence_reference?.trim()),
        {
          message: 'Provide separate authorized concession approval or customer sign-off.',
          path: ['concession_approval_reference'],
        },
      ),
    ),
    defaultValues: initial,
  });
  const disposition = watch('disposition');
  const needsTarget = requiresTarget(disposition as MrbDisposition);
  const needsConcession = disposition === 'use_as_is' && record.requires_concession_approval;

  const { data: warehouses } = useQuery({
    queryKey: ['inventory', 'warehouse', 'tree'],
    queryFn: () => warehouseApi.tree(),
    enabled: isOpen,
  });
  const quarantineWarehouseId = record.quarantine_location?.warehouse_id;
  // Target must be active, in an active warehouse, and in the same warehouse
  // as the held stock. The API enforces the same constraints.
  const goodLocations = useMemo(
    () =>
      (warehouses ?? []).flatMap((w) =>
        (w.zones ?? [])
          .filter((z) => z.zone_type !== 'quarantine' && z.zone_type !== 'scrap')
          .flatMap((z) =>
            (w.is_active && (!quarantineWarehouseId || w.id === quarantineWarehouseId)
              ? (z.locations ?? [])
              : []
            )
              .filter((l) => l.is_active)
              .map((l) => ({
                id: l.id,
                label: `${w.code}-${z.code}-${l.code}`,
              })),
          ),
      ),
    [warehouses, quarantineWarehouseId],
  );

  const mutation = useMutation({
    mutationFn: (v: ReleaseValues) =>
      mrbApi.release(record.id, {
        disposition: v.disposition as MrbDisposition,
        target_location_id: requiresTarget(v.disposition as MrbDisposition)
          ? v.target_location_id || undefined
          : undefined,
        notes: v.notes || undefined,
        ...(requiresTarget(v.disposition as MrbDisposition)
          ? {
              evidence_reference: v.evidence_reference?.trim(),
              concession_approval_reference:
                v.disposition === 'use_as_is'
                  ? v.concession_approval_reference?.trim() || undefined
                  : undefined,
              inspected_quantity: String(record.quantity),
              recovery_passed: v.recovery_passed === true,
            }
          : {}),
      }),
    onSuccess: (rec) => {
      qc.invalidateQueries({ queryKey: ['inventory', 'mrb'] });
      qc.invalidateQueries({ queryKey: ['return-request'] });
      toast.success(`MRB ${rec.mrb_number} released (${rec.disposition}).`);
      reset(initial);
      onClose();
    },
    onError: (e) => applyServerValidationErrors(e, setError, 'Failed to release MRB.'),
  });

  return (
    <Modal
      isOpen={isOpen}
      onClose={() => {
        reset(initial);
        onClose();
      }}
      title={<span className="font-mono">{record.mrb_number}</span>}
      size="md"
    >
      <form
        onSubmit={handleSubmit((v) => mutation.mutate(v), onFormInvalid<ReleaseValues>())}
        className="py-4 space-y-3"
      >
        <p className="text-sm text-muted">
          Release <span className="font-mono">{record.quantity}</span>{' '}
          {record.item?.unit_of_measure} of <span className="font-mono">{record.item?.code}</span>{' '}
          from quarantine.
        </p>

        <Select
          label="Disposition"
          required
          helper={
            decided && record.ncr
              ? `MRB decision on ${record.ncr.ncr_number}: ${dispositions.find((d) => d.value === decided)?.label ?? decided}.`
              : customerReturn
                ? `RMA ${record.return_request?.rma_number}: ${record.return_request?.disposition ?? 'restock'}.`
                : undefined
          }
          {...register('disposition')}
          error={errors.disposition?.message}
        >
          <option value="">Select disposition…</option>
          {releaseOptions.map((d) => (
            <option key={d.value} value={d.value}>
              {d.label}
            </option>
          ))}
        </Select>

        {customerReturn && !canReleaseGoodStock && (
          <p role="status" className="text-sm text-warning-fg">
            You recorded this return inspection. Another independent reviewer must release good
            stock; only scrap is available to your account.
          </p>
        )}

        {/* Mounted once the tree has loaded, so the default bin has an option to select. */}
        {needsTarget && warehouses && (
          <Select
            label="Target good location"
            required
            helper="Rework / use-as-is returns stock to good inventory — pick a non-quarantine location."
            {...register('target_location_id')}
            error={errors.target_location_id?.message}
          >
            <option value="">Select location…</option>
            {goodLocations.map((l) => (
              <option key={l.id} value={l.id}>
                {l.label}
              </option>
            ))}
          </Select>
        )}

        {needsTarget && (
          <div className="space-y-3 border-t border-default pt-3">
            <p className="text-sm text-muted">
              Completed reinspection must cover the full held quantity:{' '}
              <span className="font-mono">
                {record.quantity} {record.item?.unit_of_measure}
              </span>
              .{' '}
              {needsConcession
                ? 'Every held piece must be reviewed against the approved concession before release.'
                : 'All held material must pass before it returns to good inventory.'}
            </p>
            <Input
              label="Completed reinspection evidence reference"
              required
              maxLength={500}
              helper="Reference the completed reinspection document and verified material label."
              {...register('evidence_reference')}
              error={errors.evidence_reference?.message}
            />
            {needsConcession && (
              <Input
                label="Concession approval / customer sign-off reference"
                required
                maxLength={500}
                helper="Reference authorized acceptance of the known deviation, separate from reinspection."
                {...register('concession_approval_reference')}
                error={errors.concession_approval_reference?.message}
              />
            )}
            <Checkbox
              id={`mrb-recovery-${record.id}`}
              className="min-h-11"
              label={
                needsConcession
                  ? 'Completed reinspection confirms all held material meets the approved concession.'
                  : 'Completed reinspection confirms all held material passes every requirement.'
              }
              {...register('recovery_passed')}
            />
            {errors.recovery_passed && (
              <p role="alert" className="text-xs text-danger-fg">
                {errors.recovery_passed.message}
              </p>
            )}
          </div>
        )}

        <Textarea
          label="Notes"
          rows={2}
          maxLength={1000}
          placeholder="Optional"
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
            disabled={
              isSubmitting ||
              mutation.isPending ||
              (needsTarget &&
                (!watch('evidence_reference')?.trim() || !watch('recovery_passed'))) ||
              (needsConcession && !watch('concession_approval_reference')?.trim())
            }
            loading={mutation.isPending}
          >
            {mutation.isPending ? 'Releasing…' : 'Release'}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
