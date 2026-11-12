import { useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import toast from 'react-hot-toast';
import { defectEvidenceApi, type CreateInspectionDefectEvidence } from '@/api/quality/defectEvidence';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Chip } from '@/components/ui/Chip';

const categories = [
  ['surface_damage', 'Surface damage'],
  ['contamination', 'Contamination'],
  ['dimensional', 'Dimensional'],
  ['material', 'Material'],
  ['packaging', 'Packaging'],
  ['other', 'Other'],
] as const;

export function InspectionDefectEvidencePanel({
  inspectionId,
  grnItemId,
  receivedQuantity,
  canManage,
}: {
  inspectionId: string;
  grnItemId?: string | null;
  receivedQuantity?: string | null;
  canManage: boolean;
}) {
  const queryClient = useQueryClient();
  const photoRef = useRef<HTMLInputElement>(null);
  const [category, setCategory] = useState('surface_damage');
  const [otherCategory, setOtherCategory] = useState('');
  const [severity, setSeverity] = useState<CreateInspectionDefectEvidence['severity']>('minor');
  const [affectedQuantity, setAffectedQuantity] = useState('');
  const [notes, setNotes] = useState('');
  const [photo, setPhoto] = useState<File | null>(null);
  const [photoError, setPhotoError] = useState('');
  const evidenceQuery = useQuery({
    queryKey: ['quality', 'inspections', inspectionId, 'defect-evidence'],
    queryFn: () => defectEvidenceApi.list(inspectionId),
    enabled: Boolean(inspectionId),
  });
  const createEvidence = useMutation({
    mutationFn: (data: CreateInspectionDefectEvidence) => defectEvidenceApi.create(inspectionId, data),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['quality', 'inspections', inspectionId, 'defect-evidence'] });
      setCategory('surface_damage');
      setOtherCategory('');
      setSeverity('minor');
      setAffectedQuantity('');
      setNotes('');
      setPhoto(null);
      setPhotoError('');
      if (photoRef.current) photoRef.current.value = '';
      toast.success('Defect evidence added.');
    },
  });
  const serverMessage = isAxiosError<{ message?: string; errors?: Record<string, string[]> }>(createEvidence.error)
    ? createEvidence.error.response?.data?.message
      ?? Object.values(createEvidence.error.response?.data?.errors ?? {})[0]?.[0]
    : undefined;
  const canSubmit = Boolean(
    grnItemId && canManage && affectedQuantity && Number(affectedQuantity) > 0 && photo
      && (category !== 'other' || otherCategory.trim()) && !createEvidence.isPending,
  );

  return (
    <section aria-label="Defect evidence" className="min-w-0 rounded-md border border-default bg-canvas lg:col-span-2">
      <div className="border-b border-default px-4 py-3">
        <h2 className="text-sm font-medium text-primary">Defect evidence</h2>
        <p className="mt-1 text-xs text-muted">Record the affected received quantity, defect severity, and a private photo. This evidence does not change the inspection result.</p>
      </div>
      <div className="space-y-4 px-4 py-4">
        {evidenceQuery.isLoading && <p role="status" className="text-sm text-muted">Loading defect evidence…</p>}
        {evidenceQuery.isError && <div role="alert" className="flex flex-wrap items-center gap-2 text-sm text-danger-fg">
          <span>Defect evidence could not be loaded.</span>
          <Button type="button" size="sm" onClick={() => void evidenceQuery.refetch()}>Retry</Button>
        </div>}
        {(evidenceQuery.data ?? []).length > 0 && <ul className="divide-y divide-default">
          {(evidenceQuery.data ?? []).map((item) => <li key={item.id} className="grid gap-3 py-3 sm:grid-cols-[minmax(0,1fr)_12rem]">
            <div className="min-w-0 space-y-1 text-sm">
              <div className="flex flex-wrap items-center gap-2">
                <p className="font-medium text-primary">{item.category_label}</p>
                <Chip variant={item.severity === 'critical' ? 'danger' : item.severity === 'major' ? 'warning' : 'info'}>{item.severity_label}</Chip>
              </div>
              <p className="text-muted">Affected quantity: <span className="font-mono tabular-nums text-primary">{item.affected_quantity}</span>{receivedQuantity ? ` / ${receivedQuantity} received` : ''}</p>
              {item.grn_item?.item_code && <p className="text-xs text-muted">GRN item: {item.grn_item.item_code}{item.grn_item.item_name ? ` — ${item.grn_item.item_name}` : ''}</p>}
              {item.notes && <p className="whitespace-pre-line break-words text-sm">{item.notes}</p>}
              {item.created_by && <p className="text-xs text-muted">Added by {item.created_by.name}</p>}
            </div>
            <img src={item.photo_url} alt={`Defect evidence photo: ${item.category_label}`} loading="lazy" className="max-h-44 w-full rounded-md border border-default bg-subtle object-contain" />
          </li>)}
        </ul>}
        {!evidenceQuery.isLoading && !evidenceQuery.isError && (evidenceQuery.data ?? []).length === 0 && <p className="text-sm text-muted">No defect photos have been added.</p>}
        {!grnItemId && <p role="status" className="text-sm text-warning-fg">A received-item link is required before defect evidence can be attached.</p>}
        {canManage && grnItemId && <form className="space-y-3 border-t border-default pt-4" onSubmit={(event) => {
          event.preventDefault();
          if (!canSubmit || !photo) return;
          createEvidence.mutate({
            category,
            other_category: category === 'other' ? otherCategory.trim() : undefined,
            severity,
            affected_quantity: Number(affectedQuantity),
            notes: notes.trim() || undefined,
            photo,
          });
        }}>
          <div className="grid gap-3 sm:grid-cols-2">
            <Select label="Defect category" value={category} onChange={(event) => setCategory(event.target.value)} disabled={createEvidence.isPending}>
              {categories.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </Select>
            {category === 'other' && <Input label="Other defect category" required maxLength={100} value={otherCategory} onChange={(event) => setOtherCategory(event.target.value)} disabled={createEvidence.isPending} />}
            <Select label="Severity" value={severity} onChange={(event) => setSeverity(event.target.value as CreateInspectionDefectEvidence['severity'])} disabled={createEvidence.isPending}>
              <option value="minor">Minor</option>
              <option value="major">Major</option>
              <option value="critical">Critical</option>
            </Select>
            <Input label="Affected quantity" type="number" min="0.001" max={receivedQuantity ?? undefined} step="0.001" required value={affectedQuantity} onChange={(event) => setAffectedQuantity(event.target.value)} disabled={createEvidence.isPending} helper={receivedQuantity ? `Up to ${receivedQuantity} received.` : undefined} />
          </div>
          <Textarea label="Notes (optional)" value={notes} onChange={(event) => setNotes(event.target.value)} maxLength={2000} rows={2} disabled={createEvidence.isPending} />
          <div className="space-y-2">
            <Input ref={photoRef} label="Defect photo" type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png" required aria-invalid={Boolean(photoError)} onChange={(event) => {
              const selected = event.target.files?.[0];
              if (!selected) { setPhoto(null); return; }
              if (!['image/jpeg', 'image/png'].includes(selected.type) || selected.size > 10 * 1024 * 1024) {
                setPhoto(null);
                setPhotoError('Choose a JPG or PNG image no larger than 10MB.');
                event.target.value = '';
                return;
              }
              setPhoto(selected);
              setPhotoError('');
            }} disabled={createEvidence.isPending} />
            {photo && <p className="text-xs text-muted break-words">Selected: {photo.name}</p>}
            {photoError && <p role="alert" className="text-sm text-danger-fg">{photoError}</p>}
          </div>
          {createEvidence.isError && <p role="alert" className="text-sm text-danger-fg">{serverMessage ?? 'Defect evidence could not be saved. Check the fields and try again.'}</p>}
          <Button type="submit" variant="primary" loading={createEvidence.isPending} disabled={!canSubmit}>Add defect evidence</Button>
        </form>}
      </div>
    </section>
  );
}
