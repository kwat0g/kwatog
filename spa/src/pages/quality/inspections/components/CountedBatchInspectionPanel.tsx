import { useCallback, useEffect, useRef, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import type { AxiosError } from 'axios';
import { inspectionsApi } from '@/api/quality/inspections';
import { Button } from '@/components/ui/Button';
import { Checkbox } from '@/components/ui/Checkbox';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import { useFormDraftAutosave } from '@/hooks/useFormDraftAutosave';
import { DraftRestoreBanner } from '@/components/ui/DraftRestoreBanner';
import { useAuthStore } from '@/stores/authStore';
import { useUnsavedChangesGuard } from '@/hooks/useUnsavedChangesGuard';
import type { Inspection, InspectionMeasurement } from '@/types/quality';

function numeric(row: InspectionMeasurement) {
  return (
    row.evaluation_mode === 'numeric' ||
    (row.parameter_type === 'dimensional' &&
      (row.tolerance_min != null || row.tolerance_max != null))
  );
}
export function CountedBatchInspectionPanel({
  inspection,
  canManage,
}: {
  inspection: Inspection;
  canManage: boolean;
}) {
  const queryClient = useQueryClient();
  const [saved, setSaved] = useState(inspection);
  const [defects, setDefects] = useState('');
  const [problem, setProblem] = useState('');
  const [otherProblem, setOtherProblem] = useState(false);
  const [confirmed, setConfirmed] = useState(false);
  const [segregated, setSegregated] = useState(false);
  const [readings, setReadings] = useState<Record<string, string>>({});
  const current =
    (inspection.result_version ?? 0) > (saved.result_version ?? 0) ? inspection : saved;
  const stale = current !== saved;
  const editable = canManage && ['draft', 'in_progress'].includes(current.status) && !stale;
  const quantity = current.counted_capture!.required_quantity;
  const lot = current.sampling_basis === 'lot';
  const sizes = (current.measurements ?? []).filter(numeric);
  const references = [
    ...new Map((current.measurements ?? []).map((row) => [row.parameter_name, row])).values(),
  ];
  const count = lot ? (defects === '1' ? 1 : 0) : /^\d+$/.test(defects) ? Number(defects) : NaN;
  const validCount = Number.isSafeInteger(count) && count >= 0 && count <= quantity;
  const requirementProblem = !current.count_only_incoming && validCount && count === 0 && otherProblem;
  const valueFor = (row: InspectionMeasurement) =>
    readings[row.id] ?? (row.measured_value == null ? '' : String(row.measured_value));
  const failedSizes = sizes.filter((row) => {
    const value = valueFor(row);
    return (
      value !== '' &&
      ((row.tolerance_min != null && Number(value) < Number(row.tolerance_min)) ||
        (row.tolerance_max != null && Number(value) > Number(row.tolerance_max)))
    );
  });
  const failed = validCount && (count > 0 || requirementProblem || failedSizes.length > 0 || current.historical_held);
  const ready =
    editable &&
    confirmed &&
    (!(current.count_only_incoming && count > 0) || segregated) &&
    validCount &&
    (!(count > 0 || requirementProblem || failedSizes.length > 0) || problem.trim().length > 0) &&
    problem.length <= 2000 &&
    sizes.every((row) => /^-?\d{1,8}(?:\.\d{1,4})?$/.test(valueFor(row))) &&
    (failedSizes.length === 0 || count >= (lot ? 1 : new Set(failedSizes.map((row) => row.sample_index)).size));
  useUnsavedChangesGuard(
    editable && (defects !== '' || problem !== '' || confirmed || segregated || Object.keys(readings).length > 0),
    true,
  );
  const userId = useAuthStore(state => state.user?.id);
  const valuesRef = useRef({ defects, problem, otherProblem, readings });
  valuesRef.current = { defects, problem, otherProblem, readings };
  const dirty = defects !== '' || problem !== '' || otherProblem || Object.keys(readings).length > 0;
  const getValues = useCallback(() => valuesRef.current, []);
  const restoreValues = useCallback((values: Record<string, unknown>) => {
    if (typeof values.defects === 'string') setDefects(values.defects);
    if (typeof values.problem === 'string') setProblem(values.problem);
    setOtherProblem(values.otherProblem === true && values.defects === '0');
    if (values.readings && typeof values.readings === 'object' && !Array.isArray(values.readings)) {
      setReadings(Object.fromEntries(Object.entries(values.readings).filter((entry): entry is [string, string] => typeof entry[1] === 'string')));
    }
    setConfirmed(false);
    setSegregated(false);
  }, []);
  const recovery = useFormDraftAutosave({ formKey: `counted-inspection:${current.id}:${current.result_version ?? 0}`,
    userId, getValues, setValues: restoreValues, enabled: Boolean(userId) && dirty && editable });
  useEffect(() => {
    if (!dirty || !userId || !editable) return;
    const timer = window.setTimeout(recovery.saveNow, 500);
    return () => window.clearTimeout(timer);
  }, [defects, problem, otherProblem, readings, dirty, userId, editable, recovery.saveNow]);
  const submit = useMutation<Inspection, AxiosError<{ message?: string }>>({
    mutationFn: () =>
      inspectionsApi.recordBatchResult(current.id, {
        expected_result_version: current.result_version ?? 0,
        coverage_confirmed: true,
        inspected_quantity: quantity,
        ...(lot ? { physical_defects_confirmed: count === 1 } : { sample_defect_count: count }),
        requirement_problem: requirementProblem,
        ...(current.count_only_incoming && count > 0 ? { defects_removed_confirmed: segregated } : {}),
        ...(failed ? { problem_description: problem.trim() || 'Production preceded required QC release.' } : {}),
        measurements: sizes.map((row) => ({ id: row.id, measured_value: valueFor(row) })),
      }),
    onSuccess: (result) => {
      recovery.discard();
      setSaved(result);
      setConfirmed(false);
      setSegregated(false);
      setDefects('');
      setProblem('');
      setReadings({});
      queryClient.setQueryData(['quality', 'inspections', current.id], result);
      for (const key of [
        ['quality', 'inspections'],
        ['quality', 'ncrs'],
        ['production', 'work-orders'],
        ['inventory', 'grn'],
      ])
        void queryClient.invalidateQueries({ queryKey: key });
    },
  });
  return (
    <section aria-label="Batch inspection" className="rounded-md border border-default bg-canvas">
      <div className="border-b border-default px-4 py-3">
        <h2 className="text-sm font-medium">Batch inspection</h2>
        <p className="mt-1 text-sm text-muted">
          {lot ? `Check the material lot (${current.source_quantity} ${current.source_uom_code ?? ''}).`
            : `Check ${quantity.toLocaleString()} pieces. Count each defective piece once.`}
        </p>
      </div>
      <div className="space-y-4 p-4">
        {editable && recovery.hasDraft && userId && <DraftRestoreBanner ageMs={recovery.draftAge} onRestore={recovery.restore} onDiscard={recovery.discard} />}
        <details open={references.length <= 3}>
          <summary className="min-h-8 cursor-pointer text-sm">
            Inspection requirements ({references.length})
          </summary>
          <ul className="mt-2 max-h-48 space-y-2 overflow-y-auto text-sm">
            {references.map((row) => (
              <li key={row.id}>
                <span className="font-medium">{row.parameter_name}</span> ·{' '}
                {row.acceptance_criterion}
              </li>
            ))}
          </ul>
        </details>
        {editable ? (
          <form
            className="space-y-4"
            onSubmit={(event) => {
              event.preventDefault();
              if (ready && !submit.isPending) submit.mutate();
            }}
          >
            <fieldset disabled={submit.isPending} className="min-w-0 space-y-4">
              {lot ? <Checkbox
                label="Material lot has defects"
                checked={count === 1}
                onChange={(event) => {
                  setDefects(event.target.checked ? '1' : '0');
                  if (event.target.checked) setOtherProblem(false);
                }}
              /> : <Input
                label="Defective pieces"
                type="number"
                min={0}
                max={quantity}
                step={1}
                required
                value={defects}
                onChange={(event) => {
                  setDefects(event.target.value);
                  if (Number(event.target.value) > 0) setOtherProblem(false);
                }}
                containerClassName="max-w-sm"
                helper="Enter 0 if none. Multiple problems on one piece count as one defective piece."
              />}
              {!current.count_only_incoming && validCount && count === 0 && (
                <div>
                  <Checkbox
                    id={`other-problem-${current.id}`}
                    aria-describedby={`other-problem-help-${current.id}`}
                    label="Other inspection requirement failed"
                    checked={otherProblem}
                    onChange={(event) => setOtherProblem(event.target.checked)}
                  />
                  <p id={`other-problem-help-${current.id}`} className="pl-5 text-xs text-muted">
                    Use when no pieces are defective but another requirement failed, such as missing required paperwork.
                  </p>
                </div>
              )}
              {(count > 0 || requirementProblem || failedSizes.length > 0 || current.historical_held) && (
                <Textarea
                  label="Describe the problem"
                  required={count > 0 || requirementProblem || failedSizes.length > 0}
                  helper={current.historical_held ? 'Production before QC release requires process recovery, even with no defective pieces.' : current.count_only_incoming ? 'Describe the defective pieces.' : 'Describe the defective pieces or failed requirement.'}
                  rows={3}
                  maxLength={2000}
                  value={problem}
                  onChange={(event) => setProblem(event.target.value)}
                />
              )}
              {sizes.length > 0 && (
                <section
                  aria-label="Required size readings"
                  className="space-y-3 border-t border-default pt-4"
                >
                  <h3 className="text-sm font-medium">Required size readings</h3>
                  <div className="grid max-h-80 gap-3 overflow-y-auto sm:grid-cols-2">
                    {sizes.map((row) => (
                      <Input
                        key={row.id}
                        label={`${row.parameter_name} · sample ${row.sample_index}`}
                        inputMode="decimal"
                        required
                        value={valueFor(row)}
                        onChange={(event) =>
                          setReadings((old) => ({ ...old, [row.id]: event.target.value }))
                        }
                        helper={`${row.tolerance_min ?? '—'} to ${row.tolerance_max ?? '—'} ${row.unit_of_measure ?? ''}`}
                        error={
                          failedSizes.includes(row)
                            ? lot ? 'Outside limits. Confirm the material lot has defects.' : 'Outside limits. Include this piece in defective quantity.'
                            : undefined
                        }
                      />
                    ))}
                  </div>
                </section>
              )}
              <Checkbox
                label={`I checked all ${quantity.toLocaleString()} ${current.sampling_basis === 'lot' ? 'lot' : 'pieces'} against the requirements`}
                checked={confirmed}
                onChange={(event) => setConfirmed(event.target.checked)}
              />
              {current.count_only_incoming && validCount && count > 0 && (
                <Checkbox
                  label={`I separated the ${count.toLocaleString()} defective pieces from good stock`}
                  checked={segregated}
                  onChange={(event) => setSegregated(event.target.checked)}
                />
              )}
              {validCount && (
                <p role="status" className={`text-sm ${failed ? 'text-warning-fg' : ''}`}>
                  {failed
                    ? current.count_only_incoming && count > 0
                      ? `Proposed FAIL · after QC review, ${quantity - count} good pieces enter stock and ${count} defective pieces go to supplier return.`
                      : 'Proposed FAIL · entire batch stays on hold until QC review.'
                    : 'Proposed PASS · independent QC review required before release.'}
                </p>
              )}
            </fieldset>
            {submit.isError && (
              <p role="alert" className="text-sm text-danger-fg">
                {submit.error.response?.data?.message ??
                  'Save failed. Input retained; refresh the result before retrying.'}
              </p>
            )}
            <div className="flex justify-end border-t border-default pt-4">
              <Button
                type="submit"
                disabled={!ready || submit.isPending}
                loading={submit.isPending}
              >
                Submit inspection
              </Button>
            </div>
          </form>
        ) : (
          <div className="space-y-2 text-sm">
            {stale && <p role="alert">Inspection changed. Reload before editing.</p>}
            {current.sample_defect_count != null && (
              <p>
                {current.inspected_quantity} {lot ? 'lot checked' : 'checked'} · {current.sample_defect_count} {lot ? 'affected lot' : 'defective'} ·{' '}
                {current.recorded_result === 'failed' ? 'FAIL' : 'PASS'}
              </p>
            )}
            {current.problem_description && (
              <p className="whitespace-pre-wrap">{current.problem_description}</p>
            )}
            <p className="text-muted">
              {current.status === 'awaiting_review'
                ? 'Awaiting independent QC review.'
                : canManage
                  ? 'Inspection evidence is locked.'
                  : 'QC records inspection results.'}
            </p>
          </div>
        )}
      </div>
    </section>
  );
}
