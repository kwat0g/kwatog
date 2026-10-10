import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import type { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { inspectionsApi } from '@/api/quality/inspections';
import { Button } from '@/components/ui/Button';
import { Checkbox } from '@/components/ui/Checkbox';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Input } from '@/components/ui/Input';
import { useUnsavedChangesGuard } from '@/hooks/useUnsavedChangesGuard';
import { useFormDraftAutosave } from '@/hooks/useFormDraftAutosave';
import { useAuthStore } from '@/stores/authStore';
import { DraftRestoreBanner } from '@/components/ui/DraftRestoreBanner';
import { Textarea } from '@/components/ui/Textarea';
import type { ChecklistSubmission, Inspection, InspectionMeasurement } from '@/types/quality';
import { inspectionMethods } from '@/lib/inspectionMethods';

type Decision = { result: 'passed' | 'failed'; problem: string };
const sizePattern = /^-?\d{1,8}(?:\.\d{1,4})?$/;
const needsSize = (row: InspectionMeasurement) =>
  row.parameter_type === 'dimensional' && (row.tolerance_min != null || row.tolerance_max != null);

/** Attribute evidence stays separate from the historical measurement renderer. */
export function ChecklistDecisionPanel({
  inspection,
  canManage,
}: {
  inspection: Inspection;
  canManage: boolean;
}) {
  const queryClient = useQueryClient();
  const [base, setBase] = useState(inspection);
  const [draft, setDraft] = useState<Record<string, Decision>>({});
  const [readings, setReadings] = useState<Record<string, string>>({});
  const [coverage, setCoverage] = useState(false);
  const [moreCoverage, setMoreCoverage] = useState(false);
  const [quantity, setQuantity] = useState(String(inspection.sample_size));
  const [physical, setPhysical] = useState<boolean | null>(null);
  const [defects, setDefects] = useState('');
  const [defectsRemovedConfirmed, setDefectsRemovedConfirmed] = useState(false);
  const [batchDefect, setBatchDefect] = useState(false);
  const [batchProblem, setBatchProblem] = useState('');
  const [search, setSearch] = useState('');
  const [attention, setAttention] = useState(false);
  const [lastSaved, setLastSaved] = useState(false);
  const [bulkOpen, setBulkOpen] = useState(false);
  const [bulkIds, setBulkIds] = useState<string[]>([]);
  const stale = (inspection.result_version ?? 0) > (base.result_version ?? 0);
  const userId = useAuthStore((state) => state.user?.id);
  const localValues = {
    draft,
    readings,
    moreCoverage,
    quantity,
    physical,
    defects,
    batchDefect,
    batchProblem,
  };
  const localRef = useRef(localValues);
  localRef.current = localValues;
  const dirty =
    Object.keys(draft).length > 0 ||
    Object.keys(readings).length > 0 ||
    coverage ||
    moreCoverage ||
    physical !== null ||
    defects !== '' ||
    defectsRemovedConfirmed ||
    batchDefect ||
    batchProblem !== '';
  useUnsavedChangesGuard(dirty && ['draft', 'in_progress'].includes(base.status), true);
  const restoreLocal = useCallback((values: Record<string, unknown>) => {
    // Drafts originate on this page, but storage remains an untrusted boundary.
    if (values.draft && typeof values.draft === 'object' && !Array.isArray(values.draft)) {
      const restored: Record<string, Decision> = {};
      for (const [id, decision] of Object.entries(values.draft)) {
        if (
          decision &&
          typeof decision === 'object' &&
          ['passed', 'failed'].includes(decision.result) &&
          typeof decision.problem === 'string'
        )
          restored[id] = decision;
      }
      setDraft(restored);
    }
    if (values.readings && typeof values.readings === 'object' && !Array.isArray(values.readings))
      setReadings(
        Object.fromEntries(
          Object.entries(values.readings).filter(([, value]) => typeof value === 'string'),
        ),
      );
    setMoreCoverage(values.moreCoverage === true);
    if (typeof values.quantity === 'string') setQuantity(values.quantity);
    setPhysical(typeof values.physical === 'boolean' ? values.physical : null);
    if (typeof values.defects === 'string') setDefects(values.defects);
    setDefectsRemovedConfirmed(false);
    setBatchDefect(values.batchDefect === true);
    if (typeof values.batchProblem === 'string') setBatchProblem(values.batchProblem);
    setCoverage(false);
  }, []);
  const readLocal = useCallback(() => localRef.current, []);
  const recovery = useFormDraftAutosave({
    formKey: `quality-inspection:${inspection.id}:version:${base.result_version ?? 0}`,
    userId,
    getValues: readLocal,
    setValues: restoreLocal,
    enabled: Boolean(userId) && dirty && !stale && ['draft', 'in_progress'].includes(base.status),
  });
  useEffect(() => {
    if (!dirty || !userId) return;
    const timer = window.setTimeout(recovery.saveNow, 500);
    return () => window.clearTimeout(timer);
  }, [
    draft,
    readings,
    moreCoverage,
    quantity,
    physical,
    defects,
    defectsRemovedConfirmed,
    batchDefect,
    batchProblem,
    dirty,
    userId,
    recovery.saveNow,
  ]);
  const current = stale ? inspection : base;
  const rows = current.measurements ?? [];
  const showEntry = canManage && ['draft', 'in_progress'].includes(current.status);
  const editable = showEntry && !stale;
  const lot = current.sampling_basis === 'lot';
  const fullCoverage = !lot && current.sample_size === current.batch_quantity;
  const coverageLabel = fullCoverage
    ? 'I confirm every piece was inspected against all required checks'
    : 'I confirm all required checks on the planned sample were completed';
  const readingFor = (row: InspectionMeasurement) =>
    readings[row.id] ?? (row.measured_value == null ? '' : String(row.measured_value));
  const sizePasses = (row: InspectionMeasurement, value = readingFor(row)) =>
    sizePattern.test(value) &&
    (row.tolerance_min == null || Number(value) >= Number(row.tolerance_min)) &&
    (row.tolerance_max == null || Number(value) <= Number(row.tolerance_max));
  const resultFor = (id: string) => {
    const row = rows.find((candidate) => candidate.id === id);
    if (row && needsSize(row)) {
      if (!sizePattern.test(readingFor(row))) return null;
      if (!sizePasses(row)) return 'failed';
      return draft[id]?.result ?? row.item_result ?? 'passed';
    }
    return draft[id]?.result ?? row?.item_result ?? null;
  };
  const problemFor = (id: string) =>
    draft[id]?.problem ?? rows.find((row) => row.id === id)?.item_problem_description ?? '';
  const failed = rows.filter((row) => resultFor(row.id) === 'failed');
  const unanswered = rows.filter((row) => resultFor(row.id) === null);
  const bulkEligible = unanswered.filter((row) => !needsSize(row));
  const invalidSizes = rows.filter((row) => needsSize(row) && !sizePattern.test(readingFor(row)));
  const outOfLimit = rows.some(
    (row) => needsSize(row) && sizePattern.test(readingFor(row)) && !sizePasses(row),
  );
  const needsPhysical = failed.length > 0 || batchDefect;
  const actualQuantity = current.startup_trial_required ? current.startup_trial!.recorded_quantity : moreCoverage ? Number(quantity) : current.sample_size;
  const physicalConfirmed = outOfLimit || physical;
  const count = lot ? (physicalConfirmed ? 1 : 0) : physicalConfirmed ? Number(defects) : 0;
  const removalRequired = !lot && physicalConfirmed === true && count > 0
    && count <= current.accept_count && failed.length === 0;
  const blockers = [
    ...(rows.length === 0 ? ['No approved checklist is available.'] : []),
    ...(invalidSizes.length
      ? [
          `Enter actual size for ${invalidSizes[0].parameter_name}${invalidSizes[0].unit_of_measure ? ` in ${invalidSizes[0].unit_of_measure}` : ''}.`,
        ]
      : []),
    ...(unanswered.length ? [`${unanswered.length} checks unanswered.`] : []),
    ...(failed.some((row) => !problemFor(row.id).trim()) ? ['Describe each failed check.'] : []),
    ...(!coverage
      ? [
          fullCoverage
            ? 'Confirm every piece was inspected.'
            : 'Confirm the planned sample was inspected.',
        ]
      : []),
    ...(needsPhysical && physicalConfirmed === null
      ? ['Confirm whether physical defects were found.']
      : []),
    ...(outOfLimit && physicalConfirmed !== true
      ? ['An out-of-limit size is a physical defect; confirm affected pieces or the lot.']
      : []),
    ...(needsPhysical &&
    physicalConfirmed &&
    (!Number.isInteger(count) || count < 1 || count > actualQuantity)
      ? ['Enter a valid unique defective-piece count.']
      : []),
    ...(batchDefect && (!batchProblem.trim() || physicalConfirmed !== true)
      ? ['Describe and confirm the physical batch defect.']
      : []),
    ...(removalRequired && !defectsRemovedConfirmed
      ? ['Segregate known defective pieces before accepting the batch.']
      : []),
    ...(!Number.isInteger(actualQuantity) ||
    actualQuantity < current.sample_size ||
    actualQuantity > current.batch_quantity
      ? ['Coverage must include the planned sample and stay within the batch.']
      : []),
  ];
  const visible = rows.filter(
    (row) =>
      `${row.parameter_name} ${row.acceptance_criterion ?? ''}`
        .toLocaleLowerCase()
        .includes(search.trim().toLocaleLowerCase()) &&
      (!attention || resultFor(row.id) !== 'passed'),
  );
  const save = useMutation<
    Inspection,
    AxiosError<{ message?: string }>,
    { submit: boolean; payload: ChecklistSubmission }
  >({
    mutationFn: ({ submit, payload }) =>
      submit
        ? inspectionsApi.recordBatchResult(current.id, payload)
        : inspectionsApi.recordItemResults(current.id, {
            items: payload.items,
            submit: false,
            expected_result_version: payload.expected_result_version,
          }),
    onSuccess: (saved, variables) => {
      recovery.discard();
      setLastSaved(!variables.submit);
      setBase(saved);
      setDraft({});
      setReadings({});
      setCoverage(false);
      if (variables.submit) {
        setPhysical(null);
        setDefects('');
        setDefectsRemovedConfirmed(false);
        setBatchDefect(false);
        setBatchProblem('');
        setMoreCoverage(false);
      }
      queryClient.setQueryData(['quality', 'inspections', current.id], saved);
      queryClient.invalidateQueries({ queryKey: ['quality', 'inspections', current.id, 'chain'] });
      for (const key of [
        ['quality', 'inspections'],
        ['quality', 'ncrs'],
        ['inventory', 'grn'],
      ]) {
        queryClient.invalidateQueries({ queryKey: key });
      }
      toast.success(
        variables.submit
          ? saved.status === 'awaiting_review'
            ? 'Submitted for independent review.'
            : 'Inspection completed.'
          : 'Checklist draft saved.',
      );
    },
    onError: () => {
      toast.error('Could not save inspection. Your input is retained.');
      queryClient.invalidateQueries({ queryKey: ['quality', 'inspections', current.id] });
    },
  });
  function payload(): ChecklistSubmission {
    return {
      expected_result_version: current.result_version ?? 0,
      coverage_confirmed: true,
      items: rows
        .filter((row) => resultFor(row.id) !== null)
        .map((row) => ({
          id: row.id,
          result: resultFor(row.id) as 'passed' | 'failed',
          ...(needsSize(row) ? { measured_value: readingFor(row) } : {}),
          ...(resultFor(row.id) === 'failed'
            ? { problem_description: problemFor(row.id).trim() }
            : {}),
        })),
      ...((moreCoverage || current.startup_trial_required) ? { inspected_quantity: actualQuantity } : {}),
      ...(needsPhysical
        ? {
            physical_defects_confirmed: physicalConfirmed ?? false,
            ...(!lot && physicalConfirmed ? { sample_defect_count: count } : {}),
          }
        : {}),
      ...(removalRequired ? { defects_removed_confirmed: defectsRemovedConfirmed } : {}),
      ...(batchDefect ? { problem_description: batchProblem.trim() } : {}),
    };
  }
  function submit(event: FormEvent) {
    event.preventDefault();
    if (editable && blockers.length === 0 && !save.isPending)
      save.mutate({ submit: true, payload: payload() });
  }
  const completedCount = rows.length - unanswered.length;
  const firstAttention = rows.find(
    (row) =>
      resultFor(row.id) === null || (resultFor(row.id) === 'failed' && !problemFor(row.id).trim()),
  );
  function jumpToCheck() {
    setSearch('');
    setAttention(false);
    window.setTimeout(() => {
      const element = document.getElementById(`inspection-check-${firstAttention?.id}`);
      element?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      element?.querySelector<HTMLElement>('input,textarea,button')?.focus({ preventScroll: true });
    }, 0);
  }
  return (
    <section
      aria-label="Inspection checklist"
      className="min-w-0 rounded-md border border-default bg-canvas"
    >
      <div className="border-b border-default px-4 py-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 className="text-sm font-medium">Inspection checklist</h2>
          <span role="status" className="text-sm text-muted">
            {completedCount} of {rows.length} checks completed
            {failed.length > 0 ? ` · ${failed.length} failed` : ''}
          </span>
        </div>
        <p className="mt-1 text-sm text-muted">
          Source:{' '}
          <span className="font-mono tabular-nums">
            {current.source_quantity} {current.source_uom_code}
          </span>
      {' · '}
      {lot
            ? 'Coverage'
            : fullCoverage
              ? 'Required full coverage'
              : 'Planned sample coverage'}:{' '}
          <span className="font-mono tabular-nums">{current.sample_size}</span>{' '}
          {lot ? 'lot' : 'pieces'}
        </p>
        {!lot && (
          <p className="mt-1 text-sm text-muted">
            {current.aql_code ? `AQL ${current.aql_code}` : 'Approved sample'}: accept up to{' '}
            {current.accept_count} defective {current.accept_count === 1 ? 'piece' : 'pieces'};
            {' '}reject at {current.reject_count} or more.
          </p>
        )}
        <p className="mt-1 text-sm text-muted">
          {fullCoverage
            ? 'Check every piece; record one PASS or FAIL per requirement.'
            : lot
              ? 'Check the material lot using its approved plan. Source quantities retain their material unit.'
              : 'This is sampled acceptance; a PASS does not certify every piece was checked.'}
          {!current.entity_hash_id ? ' Standalone evidence does not authorize stock release.' : ''}
        </p>
      </div>
      <form onSubmit={submit} className="space-y-4 p-4">
        {editable && recovery.hasDraft && userId && (
          <DraftRestoreBanner
            ageMs={recovery.draftAge}
            onRestore={recovery.restore}
            onDiscard={recovery.discard}
          />
        )}
        {editable && (
          <p className="text-xs text-muted">
            {dirty
              ? 'Unsaved changes. Recovery draft stays on this device; coverage must be confirmed again.'
              : lastSaved
                ? 'Checklist draft saved.'
                : 'Decisions remain unanswered until you inspect.'}
          </p>
        )}
        {stale && (
          <div role="alert" className="space-y-2 text-warning-fg">
            <p>
              This inspection changed. Reload its saved version before continuing. Local changes
              will be discarded.
            </p>
            <Button
              type="button"
              variant="secondary"
              onClick={() => {
                recovery.discard();
                setBase(inspection);
                setDraft({});
                setReadings({});
                setCoverage(false);
                setMoreCoverage(false);
                setQuantity(String(inspection.sample_size));
                setPhysical(null);
                setDefects('');
                setDefectsRemovedConfirmed(false);
                setBatchDefect(false);
                setBatchProblem('');
                save.reset();
              }}
            >
              Reload server version
            </Button>
          </div>
        )}
        {rows.length > 6 && (
          <div className="flex flex-wrap items-end gap-3">
            <Input
              label="Find check"
              type="search"
              value={search}
              onKeyDown={(event) => {
                if (event.key === 'Enter') event.preventDefault();
              }}
              onChange={(event) => setSearch(event.target.value)}
              containerClassName="min-w-0 flex-1"
            />
            <Checkbox
              label="Needs attention"
              checked={attention}
              onChange={(event) => setAttention(event.target.checked)}
            />
          </div>
        )}
        {rows.length === 0 && (
          <p role="status" className="text-warning-fg">
            No approved checklist is available. Ask Quality to publish the acceptance criteria.
          </p>
        )}
        <ul className="divide-y divide-default">
          {visible.map((row) => (
            <li
              key={row.id}
              id={`inspection-check-${row.id}`}
              className="space-y-2 py-3 scroll-mt-24"
            >
              <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium break-words">{row.parameter_name}</span>
                    {row.is_critical && <Chip variant="danger">Critical</Chip>}
                  </div>
                  <p className="mt-1 text-xs text-muted">
                    How to check:{' '}
                    {inspectionMethods.find((method) => method.value === (row.parameter_type ?? ''))
                      ?.label ?? row.parameter_type_label}
                  </p>
                  <p className="mt-1 whitespace-pre-line break-words text-sm">
                    <span className="font-medium">PASS requirement: </span>
                    <span>{row.acceptance_criterion}</span>
                  </p>
                  {row.notes && row.notes !== row.acceptance_criterion && (
                    <p className="mt-1 whitespace-pre-line break-words text-sm text-muted">
                      {row.notes}
                    </p>
                  )}
                  <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted">
                    {row.nominal_value != null && (
                      <span>
                        Target: {row.nominal_value} {row.unit_of_measure}
                      </span>
                    )}
                    {row.tolerance_min != null && (
                      <span>
                        Lowest allowed: {row.tolerance_min} {row.unit_of_measure}
                      </span>
                    )}
                    {row.tolerance_max != null && (
                      <span>
                        Highest allowed: {row.tolerance_max} {row.unit_of_measure}
                      </span>
                    )}
                  </div>
                </div>
                <div className="flex shrink-0 gap-2 sm:self-center">
                  {!showEntry ? (
                    <Chip
                      variant={
                        resultFor(row.id) === 'failed'
                          ? 'danger'
                          : resultFor(row.id) === 'passed'
                            ? 'success'
                            : 'neutral'
                      }
                    >
                      {resultFor(row.id) === 'failed'
                        ? 'FAIL'
                        : resultFor(row.id) === 'passed'
                          ? 'PASS'
                          : 'Not recorded'}
                    </Chip>
                  ) : (
                    <>
                      {(['passed', 'failed'] as const).map((result) => (
                        <Button
                          key={result}
                          type="button"
                          size="touch"
                          variant={
                            resultFor(row.id) === result
                              ? result === 'failed'
                                ? 'danger'
                                : 'primary'
                              : 'secondary'
                          }
                          aria-pressed={resultFor(row.id) === result}
                          aria-label={`${result === 'passed' ? 'PASS' : 'FAIL'} ${row.parameter_name}`}
                          disabled={
                            !editable ||
                            save.isPending ||
                            (needsSize(row) &&
                              (!sizePattern.test(readingFor(row)) ||
                                (result === 'passed' && !sizePasses(row))))
                          }
                          onClick={() => {
                            setDraft((old) => ({
                              ...old,
                              [row.id]: { result, problem: problemFor(row.id) },
                            }));
                            setDefectsRemovedConfirmed(false);
                            save.reset();
                          }}
                        >
                          {result === 'passed' ? 'PASS' : 'FAIL'}
                        </Button>
                      ))}
                    </>
                  )}
                </div>
              </div>
              {needsSize(row) && !showEntry && (
                <p className="text-sm">
                  Actual size:{' '}
                  <span className="font-mono tabular-nums">
                    {readingFor(row) || 'Not recorded'} {row.unit_of_measure}
                  </span>
                </p>
              )}
              {needsSize(row) && showEntry && (
                <Input
                  label={`Actual size for ${row.parameter_name}`}
                  inputMode="decimal"
                  value={readingFor(row)}
                  disabled={!editable || save.isPending}
                  containerClassName="max-w-sm"
                  error={
                    readingFor(row) && !sizePattern.test(readingFor(row))
                      ? 'Enter a decimal with up to 4 places.'
                      : undefined
                  }
                  helper={`Enter an actual reading${row.unit_of_measure ? ` in ${row.unit_of_measure}` : ''}. The saved limits determine PASS or FAIL; this reading does not replace batch coverage.`}
                  onChange={(event) => {
                    const value = event.target.value;
                    setReadings((previous) => ({ ...previous, [row.id]: value }));
                    if (sizePattern.test(value)) {
                      const passes = sizePasses(row, value);
                      setDraft((previous) => ({
                        ...previous,
                        [row.id]: {
                          result: passes ? 'passed' : 'failed',
                          problem: problemFor(row.id),
                        },
                      }));
                      if (!passes) setPhysical(true);
                    }
                    setDefectsRemovedConfirmed(false);
                    save.reset();
                  }}
                />
              )}
              {resultFor(row.id) === 'failed' && !showEntry && (
                <p className="whitespace-pre-line break-words text-sm">
                  <span className="font-medium">Problem: </span>
                  {problemFor(row.id) || 'Not recorded'}
                </p>
              )}
              {resultFor(row.id) === 'failed' && showEntry && (
                <>
                  <div
                    className="flex flex-wrap gap-2"
                    aria-label={`Suggested problems for ${row.parameter_name}`}
                  >
                    {(needsSize(row)
                      ? ['Size out of limit']
                      : row.parameter_type == null
                        ? ['Missing document', 'Incorrect document']
                        : ['Crack', 'Contamination', 'Incorrect label']
                    ).map((problem) => (
                      <Button
                        key={problem}
                        type="button"
                        variant="secondary"
                        size="sm"
                        disabled={!editable || save.isPending}
                        onClick={() => {
                          setDraft((old) => ({ ...old, [row.id]: { result: 'failed', problem } }));
                          save.reset();
                        }}
                      >
                        {problem}
                      </Button>
                    ))}
                  </div>
                  <Textarea
                    label={`Problem for ${row.parameter_name}`}
                    required
                    maxLength={500}
                    rows={2}
                    value={problemFor(row.id)}
                    disabled={!editable || save.isPending}
                    onChange={(event) => {
                      setDraft((old) => ({
                        ...old,
                        [row.id]: { result: 'failed', problem: event.target.value },
                      }));
                      save.reset();
                    }}
                  />
                </>
              )}
            </li>
          ))}
        </ul>
        {editable && (
          <>
            {bulkEligible.length > 1 && (
              <Button
                type="button"
                variant="secondary"
                disabled={save.isPending}
                onClick={() => {
                  setBulkIds(bulkEligible.map((row) => row.id));
                  setBulkOpen(true);
                }}
              >
                PASS remaining checks ({bulkEligible.length})
              </Button>
            )}
              <Checkbox
                label="Physical batch defect outside these checks"
                checked={batchDefect}
                disabled={save.isPending}
                onChange={(event) => {
                  setBatchDefect(event.target.checked);
                  setPhysical(event.target.checked ? true : null);
                  setDefectsRemovedConfirmed(false);
                }}
            />
            {needsPhysical && (
              <div className="space-y-3 border-t border-default pt-4">
                <p className="text-sm font-medium">
                  {lot ? 'Any physically defective material in this lot?' : 'Any defective pieces?'}
                </p>
                {outOfLimit ? (
                  <p className="text-sm text-danger-fg">
                    Actual size is out of limit. Confirm the affected {lot ? 'lot' : 'pieces'}{' '}
                    below.
                  </p>
                ) : (
                  <div className="flex gap-2">
                    {[true, false].map((value) => (
                      <Button
                        key={String(value)}
                        type="button"
                        size="touch"
                        variant={physical === value ? 'primary' : 'secondary'}
                        aria-pressed={physical === value}
                        disabled={save.isPending}
                        onClick={() => {
                          setPhysical(value);
                          setDefectsRemovedConfirmed(false);
                        }}
                      >
                        {value ? 'Yes' : 'No'}
                      </Button>
                    ))}
                  </div>
                )}
                {physicalConfirmed && !lot && (
                  <Input
                    label="Unique defective pieces"
                    type="number"
                    min={1}
                    max={actualQuantity}
                    step={1}
                    value={defects}
                    required
                    disabled={save.isPending}
                    onChange={(event) => {
                      setDefects(event.target.value);
                      setDefectsRemovedConfirmed(false);
                    }}
                    helper="Count each piece once, even when several checks fail."
                  />
                )}
                {batchDefect && (
                  <Textarea
                    label="Batch problem description"
                    maxLength={2000}
                    rows={2}
                    required
                    disabled={save.isPending}
                    value={batchProblem}
                    onChange={(event) => setBatchProblem(event.target.value)}
                  />
                )}
                {removalRequired && (
                  <Checkbox
                    label="I confirm the defective pieces were segregated before accepting this batch"
                    checked={defectsRemovedConfirmed}
                    disabled={save.isPending}
                    onChange={(event) => setDefectsRemovedConfirmed(event.target.checked)}
                  />
                )}
                <p className="text-sm text-muted">
                  Failed stock stays held until the linked disposition is approved.
                </p>
              </div>
            )}
            <div className="space-y-3 border-t border-default pt-4">
              <Checkbox
                label={coverageLabel}
                className="min-h-11"
                checked={coverage}
                disabled={save.isPending}
                onChange={(event) => setCoverage(event.target.checked)}
              />
              {!lot && !fullCoverage && !current.startup_trial_required && (
                <Checkbox
                  label="Inspected more than the planned sample"
                  checked={moreCoverage}
                  disabled={save.isPending}
                  onChange={(event) => {
                    setMoreCoverage(event.target.checked);
                    setDefectsRemovedConfirmed(false);
                  }}
                />
              )}
              {moreCoverage && !current.startup_trial_required && (
                <Input
                  label="Actual inspected pieces"
                  type="number"
                  min={current.sample_size}
                  max={current.batch_quantity}
                  step={1}
                  disabled={save.isPending}
                  value={quantity}
                  onChange={(event) => {
                    setQuantity(event.target.value);
                    setDefectsRemovedConfirmed(false);
                  }}
                />
              )}
            </div>
            {blockers.length > 0 && (
              <p role="status" className="text-sm text-warning-fg">
                {blockers[0]}{' '}
                {firstAttention && (
                  <Button type="button" size="sm" variant="secondary" onClick={jumpToCheck}>
                    Go to check
                  </Button>
                )}
              </p>
            )}
            {save.isError && (
              <p role="alert" className="text-sm text-danger-fg">
                {save.error.response?.data?.message ??
                  'Could not confirm submission. Reload the record to check its saved status before retrying.'}
              </p>
            )}
            <div className="sticky bottom-0 z-10 -mx-4 -mb-4 flex flex-wrap justify-end gap-2 border-t border-default bg-canvas px-4 py-3">
              <Button
                type="button"
                variant="secondary"
                disabled={
                  save.isPending ||
                  (Object.keys(draft).length === 0 && Object.keys(readings).length === 0) ||
                  failed.some((row) => !problemFor(row.id).trim()) ||
                  invalidSizes.some((row) => Object.hasOwn(readings, row.id))
                }
                onClick={() => save.mutate({ submit: false, payload: payload() })}
              >
                Save draft
              </Button>
              <Button
                type="submit"
                loading={save.isPending}
                disabled={save.isPending || blockers.length > 0}
              >
                Submit inspection
              </Button>
            </div>
          </>
        )}
      </form>
      {!showEntry && (
        <div className="border-t border-default px-4 py-3 text-sm text-muted">
          Inspection evidence is locked.{' '}
          {current.status === 'awaiting_review'
            ? 'Independent QC review is next.'
            : current.status === 'failed'
              ? 'Follow the linked NCR before stock release.'
              : current.status === 'passed'
                ? 'Check linked records for downstream acceptance and release.'
                : current.status === 'cancelled'
                  ? 'This inspection was cancelled.'
                  : 'Your account cannot edit this inspection. Refresh your session or contact an administrator.'}
        </div>
      )}
      <ConfirmDialog
        isOpen={bulkOpen}
        onClose={() => setBulkOpen(false)}
        title={`Confirm ${bulkIds.length} checks passed?`}
        description="Confirm you performed every remaining check against its acceptance criterion. Existing failed checks stay unchanged."
        confirmLabel="Checks performed; mark PASS"
        onConfirm={() => {
          setDraft((old) => {
            const next = { ...old };
            for (const id of bulkIds)
              if (resultFor(id) === null) next[id] = { result: 'passed', problem: '' };
            return next;
          });
          setBulkOpen(false);
        }}
      />
    </section>
  );
}
