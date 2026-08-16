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
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
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
 })
 .refine((v) => !requiresTarget(v.disposition as MrbDisposition) || !!v.target_location_id, {
 message: 'A target good location is required for rework / use-as-is.',
 path: ['target_location_id'],
 });
type ReleaseValues = z.infer<typeof releaseSchema>;

export default function MrbDetailPage() {
 const { id = '' } = useParams<{ id: string }>();
 const { can } = usePermission();
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
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
 />
 );

 const canRelease = can('inventory.mrb.manage') && data.status === 'held';

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
 </div>
 }
 />

 <div className="px-5 pt-3 pb-4 grid grid-cols-4 gap-2">
 <StatCard
 label="Quantity"
 value={
 <span className="font-mono tabular-nums">
 {data.quantity} {data.item?.unit_of_measure ?? ''}
 </span>
 }
 />
 <StatCard label="Item" value={data.item?.code ?? '—'} helper={data.item?.name ?? undefined} />
 <StatCard label="Disposition" value={data.disposition_label ?? data.disposition ?? '—'} />
 <StatCard label="Held" value={formatDate(data.held_at)} helper={data.held_by ?? undefined} />
 </div>

 <div className="px-5 pb-4 space-y-4">
 <Panel title="Movement">
 <dl className="grid grid-cols-3 gap-4 text-sm">
 <Field label="Source location" value={data.source_location?.full_code} mono />
 <Field label="Quarantine location" value={data.quarantine_location?.full_code} mono />
 <Field label="Release location" value={data.release_location?.full_code} mono />
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
 </dl>
 </Panel>

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
 Released{data.disposition ? ` — ${data.disposition_label ?? data.disposition}` : ''}
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

function Field({
 label,
 value,
 mono,
}: {
 label: string;
 value: React.ReactNode;
 mono?: boolean;
}) {
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
 const { register, handleSubmit, reset, watch, setError, formState: { errors, isSubmitting } } =
 useForm<ReleaseValues>({
 resolver: zodResolver(releaseSchema),
 defaultValues: { disposition: '', target_location_id: '', notes: '' },
 });
 const disposition = watch('disposition');
 const needsTarget = requiresTarget(disposition as MrbDisposition);

 const { data: warehouses } = useQuery({
 queryKey: ['inventory', 'warehouse', 'tree'],
 queryFn: () => warehouseApi.tree(),
 enabled: isOpen,
 });
 // Target must be a good (non-quarantine) location.
 const goodLocations = useMemo(
 () =>
 (warehouses ?? []).flatMap((w) =>
 (w.zones ?? [])
 .filter((z) => z.zone_type !== 'quarantine')
 .flatMap((z) =>
 (z.locations ?? []).map((l) => ({
 id: l.id,
 label: `${w.code}-${z.code}-${l.code}`,
 })),
 ),
 ),
 [warehouses],
 );

 const mutation = useMutation({
 mutationFn: (v: ReleaseValues) =>
 mrbApi.release(record.id, {
 disposition: v.disposition as MrbDisposition,
 target_location_id: requiresTarget(v.disposition as MrbDisposition)
 ? v.target_location_id || undefined
 : undefined,
 notes: v.notes || undefined,
 }),
 onSuccess: (rec) => {
 qc.invalidateQueries({ queryKey: ['inventory', 'mrb'] });
 toast.success(`MRB ${rec.mrb_number} released (${rec.disposition}).`);
 reset();
 onClose();
 },
 onError: (e) => applyServerValidationErrors(e, setError, 'Failed to release MRB.'),
 });

 return (
 <Modal
 isOpen={isOpen}
 onClose={() => {
 reset();
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
 Release <span className="font-mono">{record.quantity}</span> {record.item?.unit_of_measure}{' '}
 of <span className="font-mono">{record.item?.code}</span> from quarantine.
 </p>

 <Select
 label="Disposition"
 required
 {...register('disposition')}
 error={errors.disposition?.message}
 >
 {dispositions.map((d) => (
 <option key={d.value} value={d.value}>
 {d.label}
 </option>
 ))}
 </Select>

 {needsTarget && (
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
 disabled={isSubmitting || mutation.isPending}
 loading={mutation.isPending}
 >
 {mutation.isPending ? 'Releasing…' : 'Release'}
 </Button>
 </div>
 </form>
 </Modal>
 );
}
