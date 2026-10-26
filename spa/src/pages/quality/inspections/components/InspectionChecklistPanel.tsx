import { CountedBatchInspectionPanel } from './CountedBatchInspectionPanel';
import { ChecklistDecisionPanel } from './ChecklistDecisionPanel';
import { FullCountDecisionPanel } from './FullCountDecisionPanel';
import { useMemo, useState, type FormEvent } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import type { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { inspectionsApi } from '@/api/quality/inspections';
import { Button } from '@/components/ui/Button';
import { Checkbox } from '@/components/ui/Checkbox';
import { Chip } from '@/components/ui/Chip';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import type {
  Inspection,
  InspectionMeasurement,
  RecordInspectionBatchResultData,
  RecordInspectionItemResultsData,
  RecordInspectionMeasurementsData,
} from '@/types/quality';

interface Props {
  inspection: Inspection;
  canManage: boolean;
}

interface Requirement {
  key: string;
  row: InspectionMeasurement;
  readings: InspectionMeasurement[];
}

interface DraftDecision {
  result: 'passed' | 'failed';
  problem: string;
}

type PanelSubmission = RecordInspectionItemResultsData & {
  batch?: RecordInspectionBatchResultData;
  measurements?: RecordInspectionMeasurementsData['measurements'];
};

function checklistKey(row: InspectionMeasurement) {
  return (
    row.checklist_key ??
    JSON.stringify([
      row.parameter_name,
      row.parameter_type,
      row.unit_of_measure,
      row.nominal_value,
      row.tolerance_min,
      row.tolerance_max,
      row.is_critical,
    ])
  );
}

function measurementFails(row: InspectionMeasurement, measurementDraft?: string) {
  if (measurementDraft !== undefined && measurementDraft.trim() !== '') {
    const value = Number(measurementDraft);
    return (
      (row.tolerance_min != null && value < Number(row.tolerance_min)) ||
      (row.tolerance_max != null && value > Number(row.tolerance_max))
    );
  }
  if (row.is_pass === false) return true;
  if (row.measured_value == null) return false;
  const value = Number(row.measured_value);
  return (
    (row.tolerance_min != null && value < Number(row.tolerance_min)) ||
    (row.tolerance_max != null && value > Number(row.tolerance_max))
  );
}

function needsNumericReading(row: InspectionMeasurement) {
  return (row.is_critical || row.parameter_type === 'dimensional') && (row.tolerance_min != null || row.tolerance_max != null);
}

export function InspectionChecklistPanel(props: Props) {
  const trial = props.inspection.startup_trial;
  if (props.inspection.startup_trial_required && (!trial || trial.recorded_quantity !== trial.required_quantity || !trial.output_id)) {
    return <section aria-label="Inspection checklist" className="rounded-md border border-default bg-canvas p-4">
      <h2 className="text-sm font-medium">Inspection checklist</h2>
      <p role="status" className="mt-2 text-sm text-warning-fg">Waiting for Production to record {trial?.required_quantity ?? props.inspection.sample_size} trial pieces</p>
    </section>;
  }
  if (props.inspection.counted_capture) return <CountedBatchInspectionPanel key={props.inspection.id} {...props} />;
  return props.inspection.capture_method === 'checklist'
    ? props.inspection.count_only_incoming
      ? <FullCountDecisionPanel key={props.inspection.id} {...props} />
      : <ChecklistDecisionPanel key={props.inspection.id} {...props} />
    : <LegacyInspectionChecklistPanel {...props} />;
}

function LegacyInspectionChecklistPanel({ inspection, canManage }: Props) {
  const queryClient = useQueryClient();
  const [saved, setSaved] = useState<Inspection | null>(null);
  const [draft, setDraft] = useState<Record<string, DraftDecision>>({});
  const [measurementDraft, setMeasurementDraft] = useState<Record<string, string>>({});
  const [search, setSearch] = useState('');
  const [needsAttention, setNeedsAttention] = useState(false);
  const [inspectedQuantity, setInspectedQuantity] = useState(
    inspection.inspected_quantity == null ? '' : String(inspection.inspected_quantity),
  );
  const [sampleDefectCount, setSampleDefectCount] = useState(
    inspection.sample_defect_count == null ? '' : String(inspection.sample_defect_count),
  );
  const [coverageConfirmed, setCoverageConfirmed] = useState(false);
  const [defectsRemovedConfirmed, setDefectsRemovedConfirmed] = useState(
    Boolean(inspection.defects_removed_confirmed),
  );
  const [batchProblem, setBatchProblem] = useState('');
  const current = saved && saved.updated_at >= inspection.updated_at ? saved : inspection;
  const requirements = useMemo(() => {
    const groups = new Map<string, InspectionMeasurement[]>();
    for (const row of current.measurements ?? []) {
      const key = checklistKey(row);
      groups.set(key, [...(groups.get(key) ?? []), row]);
    }

    return [...groups].map(([key, readings]) => ({ key, row: readings[0], readings }));
  }, [current.measurements]);
  const editable = canManage && ['draft', 'in_progress'].includes(current.status);
  const isLotChecklist = current.inspection_mode === 'lot_checklist';
  const resultFor = (item: Requirement) => draft[item.key]?.result ?? item.row.item_result ?? null;
  const problemFor = (item: Requirement) =>
    draft[item.key]?.problem ?? item.row.item_problem_description ?? '';
  const failsEvidence = (item: Requirement) =>
    item.readings.some((row) => measurementFails(row, measurementDraft[row.id]));
  const criticalEvidenceReady = (item: Requirement) =>
    item.readings.filter(needsNumericReading).every((row) => {
      if (row.measured_value == null) return false;
      const draftValue = measurementDraft[row.id];
      return draftValue === undefined || draftValue === String(row.measured_value);
    });
  const criticalReadingReady = (item: Requirement) =>
    item.readings.filter(needsNumericReading).every((row) => {
      const value = measurementDraft[row.id] ?? (row.measured_value == null ? '' : String(row.measured_value));
      return value.trim() !== '' && Number.isFinite(Number(value));
    });
  const changedMeasurements = requirements.flatMap((item) =>
    item.readings
      .filter(needsNumericReading)
      .filter((row) => {
        const draftValue = measurementDraft[row.id];
        return draftValue !== undefined && draftValue !== String(row.measured_value ?? '');
      })
      .map((row) => ({
        id: row.id,
        measured_value: measurementDraft[row.id] === '' ? null : measurementDraft[row.id],
      })),
  );
  const failedRequirements = requirements.filter((item) => resultFor(item) === 'failed');
  const failedRequirementDetails = failedRequirements
    .map((item) => `${item.row.parameter_name}: ${problemFor(item).trim()}`)
    .join('\n');
  const criticalDefect = requirements.some((item) =>
    item.row.is_critical && (resultFor(item) === 'failed' || failsEvidence(item)),
  );
  const inspectedCount = inspectedQuantity.trim() === '' ? null : Number(inspectedQuantity);
  const defectiveCount = sampleDefectCount.trim() === '' ? null : Number(sampleDefectCount);
  const result = failedRequirements.length > 0 || criticalDefect || (defectiveCount ?? 0) > 0
    ? 'failed'
    : 'passed';
  const acceptedWithinAql =
    failedRequirements.length === 0 && !criticalDefect && defectiveCount !== null && defectiveCount <= current.accept_count;
  const removalRequired = isLotChecklist && acceptedWithinAql && defectiveCount > 0;
  const batchProblemRequired = isLotChecklist && result === 'failed' && failedRequirementDetails === '';
  const coverageReady =
    inspectedCount !== null && Number.isInteger(inspectedCount)
    && inspectedCount >= current.sample_size && inspectedCount <= (current.startup_trial_required ? current.startup_trial!.recorded_quantity : current.batch_quantity)
    && defectiveCount !== null && Number.isInteger(defectiveCount)
    && defectiveCount >= 0 && defectiveCount <= inspectedCount;
  const requirementsReady =
    requirements.length > 0 &&
    requirements.every((item) => {
      const result = resultFor(item);
      return result !== null
        && (result !== 'failed' || problemFor(item).trim() !== '')
        && (result === 'failed' || (isLotChecklist ? criticalReadingReady(item) : criticalEvidenceReady(item)));
    });
  const lotReady = !isLotChecklist || (
    coverageReady && coverageConfirmed
    && (!removalRequired || defectsRemovedConfirmed)
    && (!batchProblemRequired || batchProblem.trim() !== '')
  );
  const ready = requirementsReady && lotReady;
  const changed = requirements.filter((item) => Object.hasOwn(draft, item.key));
  const draftItems = changed.map((item) => ({
    id: item.row.id,
    result: draft[item.key].result,
    ...(draft[item.key].result === 'failed'
      ? { problem_description: draft[item.key].problem.trim() }
      : {}),
  }));
  const canSaveDraft =
    draftItems.length > 0 &&
    draftItems.every((item) => item.result !== 'failed' || Boolean(item.problem_description));
  const visibleItems = requirements.filter((item) => {
    const matchesSearch = item.row.parameter_name
      .toLocaleLowerCase()
      .includes(search.trim().toLocaleLowerCase());
    return matchesSearch && (!needsAttention || resultFor(item) !== 'passed');
  });

  const save = useMutation<
    Inspection,
    AxiosError<{ message?: string }>,
    PanelSubmission
  >({
    mutationFn: async (payload) => {
      if (!payload.batch) {
        return inspectionsApi.recordItemResults(inspection.id, {
          items: payload.items,
          submit: payload.submit,
        });
      }
      if (payload.measurements?.length) {
        await inspectionsApi.recordMeasurements(inspection.id, { measurements: payload.measurements });
      }
      await inspectionsApi.recordItemResults(inspection.id, { items: payload.items, submit: false });

      return inspectionsApi.recordBatchResult(inspection.id, payload.batch);
    },
    onSuccess: (result, payload) => {
      setSaved(result);
      setDraft({});
      queryClient.setQueryData(['quality', 'inspections', inspection.id], result);
      queryClient.invalidateQueries({ queryKey: ['quality', 'inspections'] });
      queryClient.invalidateQueries({ queryKey: ['quality', 'ncrs'] });
      queryClient.invalidateQueries({ queryKey: ['inventory', 'grn'] });
      toast.success(
        payload.submit
          ? result.status === 'awaiting_review'
            ? 'Inspection submitted for checker review.'
            : 'Inspection completed.'
          : 'Checklist draft saved.',
      );
    },
  });

  const saveMeasurements = useMutation<
    Inspection,
    AxiosError<{ message?: string }>,
    RecordInspectionMeasurementsData
  >({
    mutationFn: (payload) => inspectionsApi.recordMeasurements(inspection.id, payload),
    onSuccess: (result) => {
      setSaved(result);
      setMeasurementDraft({});
      queryClient.setQueryData(['quality', 'inspections', inspection.id], result);
      queryClient.invalidateQueries({ queryKey: ['quality', 'inspections'] });
      toast.success('Measurements saved.');
    },
  });

  function choose(item: Requirement, result: 'passed' | 'failed') {
    setDraft((currentDraft) => ({
      ...currentDraft,
      [item.key]: {
        result,
        problem: result === 'failed' ? currentDraft[item.key]?.problem ?? item.row.item_problem_description ?? '' : '',
      },
    }));
    save.reset();
  }

  function setProblem(item: Requirement, problem: string) {
    setDraft((currentDraft) => ({
      ...currentDraft,
      [item.key]: {
        result: resultFor(item) ?? 'failed',
        problem,
      },
    }));
    save.reset();
  }

  function saveDraft() {
    if (!canSaveDraft || save.isPending || saveMeasurements.isPending) return;
    save.mutate({ items: draftItems, submit: false });
  }

  function saveMeasurementDraft() {
    if (changedMeasurements.length === 0 || save.isPending || saveMeasurements.isPending) return;
    saveMeasurements.mutate({ measurements: changedMeasurements });
  }

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!ready || save.isPending || saveMeasurements.isPending) return;
    const items = requirements.map((item) => ({
      id: item.row.id,
      result: resultFor(item) as 'passed' | 'failed',
      ...(resultFor(item) === 'failed' ? { problem_description: problemFor(item).trim() } : {}),
    }));
    if (!isLotChecklist) {
      save.mutate({ items, submit: true });

      return;
    }

    save.mutate({
      items,
      submit: true,
      measurements: changedMeasurements,
      batch: {
        result,
        coverage_confirmed: true,
        inspected_quantity: inspectedCount as number,
        sample_defect_count: defectiveCount as number,
        ...(result === 'failed'
          ? { problem_description: failedRequirementDetails || batchProblem.trim() }
          : {}),
        critical_defect: criticalDefect,
        defects_removed_confirmed: defectsRemovedConfirmed,
      },
    });
  }

  function passAllRemaining() {
    setDraft((currentDraft) => {
      const next = { ...currentDraft };
      for (const item of requirements) {
        const result = currentDraft[item.key]?.result ?? item.row.item_result ?? null;
        const readingsReady = isLotChecklist ? criticalReadingReady(item) : criticalEvidenceReady(item);
        if (result === null && !failsEvidence(item) && readingsReady) {
          next[item.key] = { result: 'passed', problem: '' };
        }
      }
      return next;
    });
    save.reset();
  }

  return (
    <section aria-label="Inspection checklist" className="rounded-md border border-default bg-canvas">
      <div className="border-b border-default px-4 py-3">
        <h2 className="text-sm font-medium text-primary">Inspection checklist</h2>
        <p className="mt-1 text-sm text-muted">
          Batch quantity:{' '}
          <strong className="font-mono tabular-nums text-primary">{current.batch_quantity}</strong>
          {' · '}Planned sample:{' '}
          <strong className="font-mono tabular-nums text-primary">{current.sample_size}</strong> pieces
        </p>
      </div>

      {!editable && ['draft', 'in_progress'].includes(current.status) && !canManage && (
        <p role="status" className="px-4 pt-4 text-sm text-muted">
          You have view access. A Quality Inspector must record each requirement.
        </p>
      )}

      {editable ? (
        <form className="space-y-4 px-4 py-4" onSubmit={submit}>
          <p className="text-sm text-muted">
            {isLotChecklist
              ? 'Inspect every planned sample. Record one result per requirement, then confirm actual sample coverage and unique defective pieces.'
              : 'Inspect the planned sample. Record one result for every unique requirement. A failed requirement needs a problem description.'}
          </p>

          {isLotChecklist && (
            <section aria-label="Sample coverage" className="space-y-3 rounded-md border border-default bg-elevated p-3">
              <p className="text-sm text-muted">
                Planned sample: {current.sample_size} of {current.batch_quantity} pieces. Count each defective piece once.
              </p>
              <div className="grid gap-3 sm:grid-cols-2">
                <Input
                  type="number"
                  label="Inspected pieces"
                  min={current.sample_size}
                  max={current.startup_trial_required ? current.startup_trial!.recorded_quantity : current.batch_quantity}
                  step="1"
                  required
                  value={inspectedQuantity}
                  disabled={save.isPending || saveMeasurements.isPending}
                  onChange={(event) => setInspectedQuantity(event.target.value)}
                />
                <Input
                  type="number"
                  label="Defective pieces found"
                  min={0}
                  max={inspectedCount ?? current.batch_quantity}
                  step="1"
                  required
                  value={sampleDefectCount}
                  disabled={save.isPending || saveMeasurements.isPending}
                  onChange={(event) => setSampleDefectCount(event.target.value)}
                />
              </div>
              <Checkbox
                checked={coverageConfirmed}
                label="I confirm all required checks on the planned sample were completed"
                className="min-h-11"
                disabled={save.isPending || saveMeasurements.isPending}
                onChange={(event) => setCoverageConfirmed(event.target.checked)}
              />
              {defectiveCount !== null && defectiveCount > 0 && (
                <Checkbox
                  checked={defectsRemovedConfirmed}
                  label="Known defective pieces were removed and segregated"
                  className="min-h-11"
                  disabled={save.isPending || saveMeasurements.isPending}
                  onChange={(event) => setDefectsRemovedConfirmed(event.target.checked)}
                />
              )}
              {batchProblemRequired && (
                <Textarea
                  label="Batch problem description"
                  required
                  maxLength={2000}
                  rows={2}
                  value={batchProblem}
                  disabled={save.isPending || saveMeasurements.isPending}
                  onChange={(event) => setBatchProblem(event.target.value)}
                />
              )}
            </section>
          )}

          {(requirements.length > 12 || isLotChecklist) && (
            <div className="flex flex-wrap items-end gap-3">
              <Input
                type="search"
                aria-label="Search requirements"
                placeholder="Search requirements…"
                fieldSize="lg"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                onKeyDown={(event) => {
                  if (event.key === 'Enter') event.preventDefault();
                }}
                containerClassName="min-w-56 flex-1"
              />
              <Checkbox
                checked={needsAttention}
                label="Needs attention"
                className="min-h-11"
                onChange={(event) => setNeedsAttention(event.target.checked)}
              />
              <Button type="button" variant="primary" size="touch" onClick={passAllRemaining} disabled={save.isPending || saveMeasurements.isPending}>
                PASS all remaining
              </Button>
            </div>
          )}

          {requirements.length === 0 ? (
            <p role="status" className="text-sm text-warning-fg">
              No inspection requirements are available. Add an approved inspection specification before recording a result.
            </p>
          ) : (
            <ul className="divide-y divide-default border-y border-default">
              {visibleItems.map((item) => {
                const result = resultFor(item);
                const failEvidence = failsEvidence(item);
                const measuredRows = item.readings.filter(needsNumericReading);
                const hasCriticalMeasurementGap = !(isLotChecklist ? criticalReadingReady(item) : criticalEvidenceReady(item));
                return (
                  <li key={item.key} className="space-y-3 py-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2">
                          <span className="font-medium break-words">{item.row.parameter_name}</span>
                          {item.row.is_critical && <Chip variant="danger">Critical</Chip>}
                          <Chip variant={result === 'passed' ? 'success' : result === 'failed' ? 'danger' : 'warning'}>
                            {result === 'passed' ? 'PASS' : result === 'failed' ? 'FAIL' : 'Unanswered'}
                          </Chip>
                        </div>
                        <p className="mt-1 text-xs text-muted">
                          {item.row.parameter_type_label ?? item.row.parameter_type?.replaceAll('_', ' ')}
                          {item.row.nominal_value != null && (
                            <>
                              {' · Nominal: '}
                              <span className="font-mono tabular-nums">
                                {item.row.nominal_value} {item.row.unit_of_measure}
                              </span>
                            </>
                          )}
                        </p>
                        {(item.row.tolerance_min != null || item.row.tolerance_max != null) && (
                          <p className="mt-1 text-xs text-muted">
                            Tolerance: {item.row.tolerance_min ?? 'No lower limit'} – {item.row.tolerance_max ?? 'No upper limit'} {item.row.unit_of_measure}
                          </p>
                        )}
                      </div>
                    </div>
                    {measuredRows.length > 0 && (
                      <div className="space-y-2 rounded-md bg-elevated p-3">
                        <p className="text-xs text-muted">
                          {isLotChecklist
                            ? 'Record each sampled critical measurement; submission saves readings with the batch result.'
                            : 'Record and save each sampled critical measurement before marking this requirement PASS.'}
                        </p>
                        <div className="grid gap-3 sm:grid-cols-2">
                          {measuredRows.map((reading) => (
                            <Input
                              key={reading.id}
                              type="number"
                              step="0.0001"
                              label={`${item.row.parameter_name}${isLotChecklist ? ', piece' : ' sample'} ${reading.sample_index}${reading.unit_of_measure ? ` (${reading.unit_of_measure})` : ''}`}
                              value={measurementDraft[reading.id] ?? (reading.measured_value == null ? '' : String(reading.measured_value))}
                              disabled={save.isPending || saveMeasurements.isPending}
                              onChange={(event) => {
                                setMeasurementDraft((currentDraft) => ({ ...currentDraft, [reading.id]: event.target.value }));
                                save.reset();
                                saveMeasurements.reset();
                              }}
                            />
                          ))}
                        </div>
                      </div>
                    )}
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <div role="group" aria-label={`${item.row.parameter_name} result`} className="grid grid-cols-2 gap-2">
                        <Button
                          type="button"
                          size="touch"
                          aria-label={`PASS ${item.row.parameter_name}`}
                          aria-pressed={result === 'passed'}
                          className={result === 'passed' ? 'border-success bg-success-bg text-success-fg hover:bg-success-bg/80' : ''}
                          disabled={save.isPending || saveMeasurements.isPending || failEvidence || hasCriticalMeasurementGap}
                          onClick={() => choose(item, 'passed')}
                        >
                          PASS
                        </Button>
                        <Button
                          type="button"
                          size="touch"
                          aria-label={`FAIL ${item.row.parameter_name}`}
                          aria-pressed={result === 'failed'}
                          variant={result === 'failed' ? 'danger' : 'secondary'}
                          disabled={save.isPending || saveMeasurements.isPending}
                          onClick={() => choose(item, 'failed')}
                        >
                          FAIL
                        </Button>
                      </div>
                    </div>
                    {failEvidence && (
                      <p role="alert" className="text-sm text-danger-fg">
                        Saved measurement evidence for this requirement failed. Record the failure; PASS is blocked.
                      </p>
                    )}
                    {hasCriticalMeasurementGap && measuredRows.length > 0 && (
                      <p role="status" className="text-sm text-muted">
                        Save every critical sample reading before PASS.
                      </p>
                    )}
                    {result === 'failed' && (
                      <Textarea
                        label={`Problem found for ${item.row.parameter_name}`}
                        required
                        maxLength={500}
                        rows={2}
                        value={problemFor(item)}
                        disabled={save.isPending}
                        onChange={(event) => setProblem(item, event.target.value)}
                      />
                    )}
                  </li>
                );
              })}
            </ul>
          )}

          {requirements.length > 0 && visibleItems.length === 0 && (
            <p role="status" className="text-sm text-muted">No requirements match this view.</p>
          )}
          <div className="sticky bottom-0 z-10 -mx-4 flex flex-wrap justify-end gap-2 border-t border-default bg-canvas px-4 py-3">
            {requirements.some((item) => item.readings.some(needsNumericReading)) && (
              <Button
                type="button"
                size="touch"
                disabled={changedMeasurements.length === 0 || save.isPending || saveMeasurements.isPending}
                loading={saveMeasurements.isPending}
                onClick={saveMeasurementDraft}
              >
                Save measurements
              </Button>
            )}
            <Button type="button" size="touch" disabled={!canSaveDraft || save.isPending || saveMeasurements.isPending} loading={save.isPending && save.variables?.submit === false} onClick={saveDraft}>
              Save draft
            </Button>
            <Button variant="primary" size="touch" type="submit" disabled={!ready || save.isPending || saveMeasurements.isPending} loading={save.isPending && save.variables?.submit === true}>
              {isLotChecklist ? 'Submit result' : 'Submit inspection'}
            </Button>
          </div>
          {saveMeasurements.isError && (
            <p role="alert" className="text-sm text-danger-fg">
              {saveMeasurements.error.response?.data?.message ?? 'Could not save measurements. Try again.'}
            </p>
          )}
          {save.isError && (
            <p role="alert" className="text-sm text-danger-fg">
              {save.error.response?.data?.message ?? 'Could not save inspection. Try again.'}
            </p>
          )}
        </form>
      ) : (
        <div className="px-4 py-4">
          <Chip variant={current.status === 'passed' ? 'success' : current.status === 'failed' ? 'danger' : 'warning'}>
            {current.status === 'awaiting_review'
              ? `Awaiting checker review · proposed ${current.proposed_result ?? current.recorded_result ?? 'Unknown'}`
              : current.status === 'passed'
                ? 'Inspection passed'
                : current.status === 'failed'
                  ? current.sorting_approved_at
                    ? 'FAIL retained · sorting approved'
                    : 'Inspection failed'
                  : current.status_label ?? current.status}
          </Chip>
          <dl className="mt-4 grid grid-cols-2 gap-3 text-sm">
            <div>
              <dt className="text-xs text-muted">Inspected pieces</dt>
              <dd className="mt-1 font-mono tabular-nums">{current.inspected_quantity ?? 'Not recorded'}</dd>
            </div>
            <div>
              <dt className="text-xs text-muted">Defective pieces</dt>
              <dd className="mt-1 font-mono tabular-nums">{current.sample_defect_count ?? 'Not recorded'}</dd>
            </div>
            {current.stage === 'outgoing' && (
              <div>
                <dt className="text-xs text-muted">
                  {current.status === 'failed' || current.status === 'awaiting_review'
                    ? current.sorting_approved_at
                      ? 'Approved release quantity'
                      : 'Held batch'
                    : 'Accepted quantity'}
                </dt>
                <dd className="mt-1 font-mono tabular-nums">
                  {current.status === 'failed' || current.status === 'awaiting_review'
                    ? current.sorting_approved_at
                      ? current.accepted_quantity
                      : current.batch_quantity
                    : current.accepted_quantity}
                </dd>
              </div>
            )}
            {current.stage === 'outgoing' && current.sorting_approved_at && (
              <div>
                <dt className="text-xs text-muted">Confirmed rejected pieces</dt>
                <dd className="mt-1 font-mono tabular-nums">
                  {current.batch_quantity - current.accepted_quantity}
                </dd>
              </div>
            )}
          </dl>
          {current.status === 'failed' && (
            <p className="mt-3 text-sm text-muted">
              {current.sorting_approved_at
                ? 'Original FAIL retained. Only the approved good quantity may be released; the remainder follows the NCR disposition.'
                : 'Batch held for NCR sorting or rework, not scrap.'}
            </p>
          )}
          {current.problem_description && (
            <p className="mt-3 whitespace-pre-wrap break-words text-sm">{current.problem_description}</p>
          )}
        </div>
      )}

      {!editable && (
        <>
          <div className="border-t border-default px-4 py-3">
            <p className="text-xs text-muted">
              {requirements.length} requirements · decisions apply to each parameter across the planned sample.
            </p>
          </div>
          <ul className="divide-y divide-default">
            {requirements.map((item) => {
              const result = resultFor(item);
              return (
                <li key={item.key} className="flex flex-wrap items-start justify-between gap-3 px-4 py-3">
                  <div className="min-w-0">
                    <span className="font-medium break-words">{item.row.parameter_name}</span>
                    {item.row.is_critical && <Chip variant="danger">Critical</Chip>}
                    {result === 'failed' && item.row.item_problem_description && (
                      <p className="mt-1 whitespace-pre-wrap break-words text-sm text-muted">{item.row.item_problem_description}</p>
                    )}
                  </div>
                  <Chip variant={result === 'passed' ? 'success' : result === 'failed' ? 'danger' : 'warning'}>
                    {result === 'passed' ? 'PASS' : result === 'failed' ? 'FAIL' : 'Unanswered'}
                  </Chip>
                </li>
              );
            })}
          </ul>
          {requirements.length === 0 && (
            <p role="status" className="px-4 py-4 text-sm text-warning-fg">
              No inspection requirements are available. Add an approved inspection specification.
            </p>
          )}
        </>
      )}
    </section>
  );
}
