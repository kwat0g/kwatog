import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { LuPencil, LuPlus, LuTrash2 } from '@/lib/icons';
import { uomsApi, type ItemUomConversion, type UomOption } from '@/api/inventory/uoms';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { usePermission } from '@/hooks/usePermission';

const schema = z.object({
  from_uom_id: z.string().min(1, 'Choose a source unit.'),
  factor: z
    .string()
    .trim()
    .regex(/^\d{1,12}(?:\.\d{1,6})?$/, 'Use up to 12 integer and 6 decimal places.')
    .refine((value) => Number(value) > 0, 'Factor must be greater than zero.'),
});

type FormValues = z.infer<typeof schema>;

export function ItemUomConversions({ itemId, baseUnit }: { itemId: string; baseUnit: string }) {
  const qc = useQueryClient();
  const { can } = usePermission();
  const canManage = can('inventory.items.manage');
  const [editing, setEditing] = useState<ItemUomConversion | null>(null);
  const [formOpen, setFormOpen] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState<ItemUomConversion | null>(null);
  const queryKey = ['inventory', 'items', itemId, 'uom-conversions'];

  const conversions = useQuery({
    queryKey,
    queryFn: () => uomsApi.conversions(itemId),
  });
  const uoms = useQuery({
    queryKey: ['inventory', 'uoms'],
    queryFn: () => uomsApi.list(),
    enabled: canManage,
    staleTime: 300_000,
  });
  const base = uoms.data?.find((unit) => unit.code.toUpperCase() === baseUnit.toUpperCase());
  const sources = (uoms.data ?? []).filter(
    (unit) => unit.code.toUpperCase() !== baseUnit.toUpperCase(),
  );

  const save = useMutation({
    mutationFn: (data: FormValues) =>
      uomsApi.saveConversion(itemId, {
        from_uom_id: data.from_uom_id,
        to_uom_id: base!.id,
        factor: data.factor,
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey });
      toast.success(editing ? 'Conversion updated.' : 'Conversion added.');
      setEditing(null);
      setFormOpen(false);
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Failed to save conversion.');
    },
  });
  const remove = useMutation({
    mutationFn: (conversionId: string) => uomsApi.deleteConversion(itemId, conversionId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey });
      toast.success('Conversion removed.');
      setConfirmDelete(null);
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Failed to remove conversion.');
    },
  });

  return (
    <>
      <Panel
        title="Unit conversions"
        meta={conversions.data ? `${conversions.data.length} configured` : undefined}
        actions={
          canManage && (
            <Button
              size="xs"
              variant="secondary"
              icon={<LuPlus size={13} />}
              disabled={!base || uoms.isLoading || uoms.isError}
              title={!base ? `Add or restore ${baseUnit} in the unit catalog first.` : undefined}
              onClick={() => {
                setEditing(null);
                setFormOpen(true);
              }}
            >
              Add conversion
            </Button>
          )
        }
      >
        <p className="mb-3 text-xs text-muted">
          Factors record base units per one source unit. Conversions lock when a purchase, receipt,
          or issue uses them.
        </p>
        {conversions.isLoading && !conversions.data && <SkeletonTable rows={2} columns={3} />}
        {conversions.isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load conversions"
            action={
              <Button variant="secondary" onClick={() => conversions.refetch()}>
                Retry
              </Button>
            }
          />
        )}
        {canManage && !uoms.isLoading && !base && (
          <p role="alert" className="text-sm text-danger-fg">
            Base unit <span className="font-mono">{baseUnit}</span> is missing from the active
            catalog.{' '}
            <Link to="/inventory/uoms" className="underline">
              Open unit catalog
            </Link>
            .
          </p>
        )}
        {conversions.data?.length === 0 && (
          <EmptyState
            icon="inbox"
            title="No alternate units configured"
            description={
              canManage
                ? `Add a source unit that converts to ${baseUnit}.`
                : 'This item uses its base unit only.'
            }
          />
        )}
        {conversions.data && conversions.data.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-default text-left text-xs text-muted">
                  <th scope="col" className="py-2 pr-3">
                    Source unit
                  </th>
                  <th scope="col" className="py-2 pr-3 text-right">
                    Base units per source unit
                  </th>
                  <th scope="col" className="py-2 pr-3">
                    Status
                  </th>
                  {canManage && (
                    <th scope="col" className="py-2 text-right">
                      Actions
                    </th>
                  )}
                </tr>
              </thead>
              <tbody>
                {conversions.data.map((conversion) => (
                  <tr key={conversion.id} className="border-b border-subtle last:border-0">
                    <td className="py-2 pr-3 font-mono">{conversion.from_uom.code}</td>
                    <td className="py-2 pr-3 text-right font-mono tabular-nums">
                      1 {conversion.from_uom.code} = {Number(conversion.factor).toFixed(6)}{' '}
                      {conversion.to_uom.code}
                    </td>
                    <td className="py-2 pr-3">
                      {conversion.locked_by_history ? (
                        <span className="inline-flex items-center gap-2">
                          <Chip variant="neutral">In use</Chip>
                          <span className="text-xs text-muted">
                            Locked by purchase, receipt, or issue
                          </span>
                        </span>
                      ) : (
                        <Chip variant="success">Editable</Chip>
                      )}
                    </td>
                    {canManage && (
                      <td className="py-2 text-right">
                        <div className="flex justify-end gap-1">
                          <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            iconOnly
                            icon={<LuPencil size={14} />}
                            aria-label={`Edit ${conversion.from_uom.code} conversion`}
                            disabled={conversion.locked_by_history}
                            title={
                              conversion.locked_by_history
                                ? 'Locked while a purchase, receipt, or issue uses this factor.'
                                : undefined
                            }
                            onClick={() => {
                              setEditing(conversion);
                              setFormOpen(true);
                            }}
                          />
                          <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            iconOnly
                            icon={<LuTrash2 size={14} />}
                            aria-label={`Remove ${conversion.from_uom.code} conversion`}
                            disabled={conversion.locked_by_history}
                            title={
                              conversion.locked_by_history
                                ? 'Locked while a purchase, receipt, or issue uses this factor.'
                                : undefined
                            }
                            className="text-muted hover:text-danger-fg"
                            onClick={() => setConfirmDelete(conversion)}
                          />
                        </div>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Panel>

      <Modal
        isOpen={formOpen}
        onClose={() => {
          if (!save.isPending) {
            setEditing(null);
            setFormOpen(false);
          }
        }}
        title={editing ? `Edit ${editing.from_uom.code} conversion` : 'Add unit conversion'}
        size="sm"
      >
        {base && uoms.data && (
          <ConversionForm
            key={editing?.id ?? 'new'}
            units={sources}
            base={base}
            editing={editing}
            pending={save.isPending}
            onClose={() => {
              setEditing(null);
              setFormOpen(false);
            }}
            onSave={(values) => save.mutate(values)}
          />
        )}
      </Modal>

      <ConfirmDialog
        isOpen={!!confirmDelete}
        onClose={() => setConfirmDelete(null)}
        onConfirm={() => {
          if (confirmDelete) remove.mutate(confirmDelete.id);
        }}
        title="Remove conversion?"
        description={
          confirmDelete && (
            <>
              The <span className="font-mono">{confirmDelete.from_uom.code}</span> conversion will
              be removed from new receipts and issues.
            </>
          )
        }
        confirmLabel="Remove conversion"
        variant="danger"
        pending={remove.isPending}
      />
    </>
  );
}

function ConversionForm({
  units,
  base,
  editing,
  pending,
  onClose,
  onSave,
}: {
  units: UomOption[];
  base: UomOption;
  editing: ItemUomConversion | null;
  pending: boolean;
  onClose: () => void;
  onSave: (values: FormValues) => void;
}) {
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { from_uom_id: editing?.from_uom.id ?? '', factor: editing?.factor ?? '' },
  });

  return (
    <form onSubmit={handleSubmit(onSave)} className="py-3">
      <div className="space-y-3">
        <Select
          label="Source unit"
          required
          helper={`This unit converts to ${base.code}.`}
          {...register('from_uom_id')}
          error={errors.from_uom_id?.message}
        >
          <option value="">Choose a unit…</option>
          {units.map((unit) => (
            <option key={unit.id} value={unit.id}>
              {unit.code} · {unit.name}
            </option>
          ))}
        </Select>
        <Input
          label="Base units per source unit"
          required
          type="number"
          min="0.000001"
          max="999999999999.999999"
          step="0.000001"
          helper={`Example: 1 BAG = 25 ${base.code}.`}
          {...register('factor')}
          error={errors.factor?.message}
        />
        <div className="rounded-md bg-subtle px-3 py-2 text-xs text-muted">
          Target base unit: <span className="font-mono text-primary">{base.code}</span>
        </div>
      </div>
      <ModalFooter>
        <Button type="button" variant="secondary" onClick={onClose} disabled={pending}>
          Cancel
        </Button>
        <Button
          type="submit"
          variant="primary"
          loading={pending}
          disabled={pending || isSubmitting}
        >
          {editing ? 'Save changes' : 'Add conversion'}
        </Button>
      </ModalFooter>
    </form>
  );
}
