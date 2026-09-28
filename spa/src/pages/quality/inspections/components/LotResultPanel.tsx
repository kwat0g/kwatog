/**
 * Lot result panel — the capture surface for `lot_checklist` inspections (incoming,
 * in-process and outgoing).
 *
 * The inspector ticks the lot-level checks, taps the number of defective pieces found
 * in the AQL sample, and answers each dimension: a non-critical dimension is one tick
 * when it is within tolerance, a critical one (a CTQ) is a measured value per piece.
 * Unticking a non-critical dimension reveals its piece rows so a dimension known to be
 * out stays a recorded reading instead of a blank cell.
 *
 * The server is authoritative for the result; the chip at the bottom is a preview of
 * what `computeLotChecklistVerdict` expects the server to compute.
 */
import { useEffect, useMemo, useRef, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { LuCheck, LuSave } from '@/lib/icons';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import { inspectionsApi } from '@/api/quality/inspections';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Checkbox } from '@/components/ui/Checkbox';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { cn } from '@/lib/cn';
import { focusRing } from '@/lib/focus';
import type { Inspection, InspectionMeasurement } from '@/types/quality';
import { computeLotChecklistVerdict } from './computeLotChecklistVerdict';

interface ChecklistDraft {
  id: string;
  is_pass: boolean;
  notes: string;
  dirty: boolean;
}

interface MeasurementDraft {
  id: string;
  measured_value: string;
  dirty: boolean;
}

interface LotResultPanelProps {
  inspection: Inspection;
  isTerminal: boolean;
}

/** Above this many rejectable pieces a row of taps is slower than typing the count. */
const MAX_TAP_TARGETS = 6;

/** Server-side evidence, not local draft state: a saved reading is never hidden. */
const hasRecordedReading = (measurements: InspectionMeasurement[]): boolean =>
  measurements.some((m) => m.measured_value !== null);

export function LotResultPanel({ inspection, isTerminal }: LotResultPanelProps) {
  const qc = useQueryClient();
  const [checklistDrafts, setChecklistDrafts] = useState<Record<string, ChecklistDraft>>({});
  const [measurementDrafts, setMeasurementDrafts] = useState<Record<string, MeasurementDraft>>({});
  /**
   * Per-parameter answer for non-critical dimensions, keyed by parameter name.
   * `true` = ticked "within tolerance"; `false` = the inspector unticked it, which
   * reveals the piece rows and declares the dimension out; absent = not answered yet.
   *
   * The absence of an answer is its own state: a fresh panel must not read as either
   * conforming or non-conforming, so nothing is asserted on the inspector's behalf.
   */
  const [parameterTicks, setParameterTicks] = useState<Record<string, boolean | undefined>>({});
  const [sampleDefectCount, setSampleDefectCount] = useState<string>('');
  const [sampleDefectDirty, setSampleDefectDirty] = useState(false);
  const [confirmSubmit, setConfirmSubmit] = useState(false);
  // Ticks are local-only (the API persists no field for them), so they survive a
  // background refetch but are dropped when the panel moves to another inspection.
  const seededFor = useRef<string | null>(null);

  // Memoize filtered measurements to avoid infinite render loop
  const checklistMeasurements = useMemo(
    () =>
      (inspection.measurements ?? []).filter(
        (m) => m.evaluation_mode === 'manual' && m.sample_index === 1,
      ),
    [inspection.measurements],
  );

  const numericMeasurements = useMemo(
    () => (inspection.measurements ?? []).filter((m) => m.evaluation_mode === 'numeric'),
    [inspection.measurements],
  );

  const sampleIndices = useMemo(
    () => Array.from(new Set(numericMeasurements.map((m) => m.sample_index))).sort((a, b) => a - b),
    [numericMeasurements],
  );

  // Group numeric measurements by parameter_name using a Map to preserve order
  const groupedMeasurements = useMemo(() => {
    const map = new Map<string, InspectionMeasurement[]>();
    for (const m of numericMeasurements) {
      if (!map.has(m.parameter_name)) {
        map.set(m.parameter_name, []);
      }
      map.get(m.parameter_name)!.push(m);
    }
    return map;
  }, [numericMeasurements]);

  // Seed drafts when inspection loads
  useEffect(() => {
    const newChecklistDrafts: Record<string, ChecklistDraft> = {};
    for (const m of checklistMeasurements) {
      newChecklistDrafts[m.id] = {
        id: m.id,
        is_pass: m.is_pass === true,
        notes: m.notes ?? '',
        dirty: false,
      };
    }
    setChecklistDrafts(newChecklistDrafts);

    const newMeasurementDrafts: Record<string, MeasurementDraft> = {};
    for (const m of numericMeasurements) {
      newMeasurementDrafts[m.id] = {
        id: m.id,
        measured_value: m.measured_value === null ? '' : String(m.measured_value),
        dirty: false,
      };
    }
    setMeasurementDrafts(newMeasurementDrafts);

    setSampleDefectCount(
      inspection.sample_defect_count == null ? '' : String(inspection.sample_defect_count),
    );
    setSampleDefectDirty(false);

    if (seededFor.current !== inspection.id) {
      setParameterTicks({});
      seededFor.current = inspection.id;
    }
  }, [inspection, checklistMeasurements, numericMeasurements]);

  const isCriticalParameter = (measurements: InspectionMeasurement[]): boolean =>
    Boolean(measurements[0]?.is_critical);

  /**
   * A dimension shows its piece rows when it is critical (a CTQ keeps its
   * variable-data matrix), when the inspector unticked it, or when the server
   * already holds readings for it — saved evidence is never hidden behind a tick.
   */
  const isParameterRevealed = (
    paramName: string,
    measurements: InspectionMeasurement[],
  ): boolean => {
    if (isCriticalParameter(measurements)) return true;
    if (parameterTicks[paramName] === false) return true;
    return parameterTicks[paramName] === undefined && hasRecordedReading(measurements);
  };

  const pieceColumns = Array.from(groupedMeasurements.entries()).some(([paramName, measurements]) =>
    isParameterRevealed(paramName, measurements),
  )
    ? sampleIndices
    : [];

  const setWithinTolerance = (
    paramName: string,
    measurements: InspectionMeasurement[],
    withinTolerance: boolean,
  ) => {
    setParameterTicks((s) => ({ ...s, [paramName]: withinTolerance }));
    if (!withinTolerance) return;
    // Ticking speaks for the whole dimension and records no readings: the payload
    // sends null for every row, so clear the local numbers rather than leaving
    // values the operator cannot see.
    setMeasurementDrafts((s) => {
      const next = { ...s };
      for (const m of measurements) {
        if (next[m.id]) next[m.id] = { ...next[m.id], measured_value: '', dirty: true };
      }
      return next;
    });
  };

  // Compute verdict for preview
  const checklistForVerdictCompute = checklistMeasurements.map((m) => ({
    is_critical: m.is_critical,
    is_pass: checklistDrafts[m.id]?.is_pass ?? true,
  }));
  const numericForVerdictCompute = numericMeasurements.map((m) => ({
    is_critical: m.is_critical,
    sample_index: m.sample_index,
    measured_value: measurementDrafts[m.id]?.measured_value ?? null,
    tolerance_min: m.tolerance_min,
    tolerance_max: m.tolerance_max,
    // Only an explicit untick declares a dimension out — an unanswered one is silent.
    declared_out_of_tolerance: parameterTicks[m.parameter_name] === false,
  }));
  const sampleDefectNum = sampleDefectCount === '' ? 0 : Number(sampleDefectCount);
  const { verdict, reason } = computeLotChecklistVerdict(
    checklistForVerdictCompute,
    numericForVerdictCompute,
    sampleDefectNum,
    inspection.accept_count,
  );

  const allChecklistDirty = Object.values(checklistDrafts).some((d) => d.dirty);
  const allMeasurementDirty = Object.values(measurementDrafts).some((d) => d.dirty);
  const isDirty = allChecklistDirty || allMeasurementDirty || sampleDefectDirty;
  // A tick on its own has nothing to persist, but it is operator input: the panel
  // is no longer "not started" once one is set.
  const isTouched = isDirty || Object.values(parameterTicks).some((tick) => tick !== undefined);

  const uncheckedCount = checklistMeasurements.filter(
    (m) => !(checklistDrafts[m.id]?.is_pass ?? true),
  ).length;

  // Only a critical dimension blocks submission: its measured pieces are the
  // evidence IATF wants on a CTQ, and a blank cell there is no evidence at all.
  const hasUnfilledCritical = numericMeasurements.some(
    (m) => m.is_critical && (measurementDrafts[m.id]?.measured_value ?? '').trim() === '',
  );

  // Check if sample defect count is a valid whole number
  const isValidDefectCount =
    sampleDefectCount === '' ||
    (/^\d+$/.test(sampleDefectCount) && Number(sampleDefectCount) <= inspection.sample_size);
  const isDefectCountBlank = sampleDefectCount === '';

  const canSubmit = isValidDefectCount && !isDefectCountBlank && !hasUnfilledCritical;

  const missingFields = [
    isDefectCountBlank
      ? 'sample defect count'
      : !isValidDefectCount
        ? `sample defect count (whole number 0–${inspection.sample_size})`
        : null,
    hasUnfilledCritical ? 'critical measurements' : null,
  ].filter(Boolean);

  const recordResult = useMutation({
    mutationFn: (complete: boolean) => {
      const payload = {
        checklist: checklistMeasurements.map((m) => ({
          id: m.id,
          is_pass: checklistDrafts[m.id]?.is_pass ?? true,
          notes: checklistDrafts[m.id]?.notes.trim() || null,
        })),
        measurements: numericMeasurements.map((m) => ({
          id: m.id,
          measured_value:
            measurementDrafts[m.id]?.measured_value.trim() === ''
              ? null
              : (measurementDrafts[m.id]?.measured_value ?? null),
        })),
        // Blank stays null on a draft — 0 would claim the sample was counted clean.
        sample_defect_count: sampleDefectCount === '' ? null : Number(sampleDefectCount),
        complete,
      };
      return inspectionsApi.recordLotResult(inspection.id, payload);
    },
    onSuccess: (result, complete) => {
      if (complete) {
        toast.success(
          result.status === 'awaiting_review'
            ? 'Inspection submitted for checker review.'
            : `Inspection ${result.status === 'passed' ? 'PASSED' : 'FAILED'}`,
        );
      } else {
        toast.success('Lot result saved');
      }
      setChecklistDrafts((d) =>
        Object.entries(d).reduce((acc, [k, v]) => ({ ...acc, [k]: { ...v, dirty: false } }), {}),
      );
      setMeasurementDrafts((d) =>
        Object.entries(d).reduce((acc, [k, v]) => ({ ...acc, [k]: { ...v, dirty: false } }), {}),
      );
      setSampleDefectDirty(false);
      setConfirmSubmit(false);
      qc.invalidateQueries({ queryKey: ['quality', 'inspections', inspection.id] });
      qc.invalidateQueries({ queryKey: ['quality', 'inspections'] });
      qc.invalidateQueries({ queryKey: ['inventory', 'grn'] });
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Save failed');
    },
  });

  const saving = recordResult.isPending && recordResult.variables === false;
  const submitting = recordResult.isPending && recordResult.variables === true;

  // A row of taps beats a number pad for the hand-counted sample; past six
  // rejectable pieces the row stops being faster than typing the count.
  const tapTargets =
    Number.isFinite(inspection.reject_count) && inspection.reject_count <= MAX_TAP_TARGETS
      ? Array.from({ length: inspection.reject_count + 1 }, (_, i) => i)
      : null;

  return (
    <div className="space-y-4">
      {/* Summary strip */}
      <Panel>
        <dl className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-x-4 gap-y-3 text-sm">
          <div>
            <dt className="text-2xs uppercase tracking-wider text-muted">Lot quantity</dt>
            <dd className="font-mono tabular-nums">{inspection.batch_quantity}</dd>
          </div>
          <div>
            <dt className="text-2xs uppercase tracking-wider text-muted">Inspect</dt>
            <dd className="font-mono tabular-nums">
              {inspection.sample_size} pcs {inspection.aql_code ? `[${inspection.aql_code}]` : ''}
            </dd>
          </div>
          <div>
            <dt className="text-2xs uppercase tracking-wider text-muted">Accept ≤</dt>
            <dd className="font-mono tabular-nums">{inspection.accept_count}</dd>
          </div>
          <div>
            <dt className="text-2xs uppercase tracking-wider text-muted">Reject ≥</dt>
            <dd className="font-mono tabular-nums">{inspection.reject_count}</dd>
          </div>
        </dl>
      </Panel>

      {/* Lot checklist */}
      {checklistMeasurements.length > 0 && (
        <Panel
          title="Checklist"
          meta={`${checklistMeasurements.length} item${checklistMeasurements.length === 1 ? '' : 's'}`}
        >
          <div className="space-y-2">
            {checklistMeasurements.map((m) => {
              const draft = checklistDrafts[m.id];
              if (!draft) return null;
              return (
                <div
                  key={m.id}
                  className={cn(
                    'flex flex-col gap-2 p-3 border border-default rounded-md transition-colors',
                    isTerminal ? 'bg-subtle' : 'hover:bg-zebra-even',
                  )}
                >
                  <div className="flex items-center gap-3">
                    <Checkbox
                      id={`cb-${m.id}`}
                      disabled={isTerminal}
                      checked={draft.is_pass}
                      onChange={(e) => {
                        setChecklistDrafts((s) => ({
                          ...s,
                          [m.id]: {
                            ...s[m.id],
                            is_pass: e.target.checked,
                            dirty: true,
                          },
                        }));
                      }}
                      label={
                        <span className="flex items-center gap-2">
                          <span>{m.parameter_name}</span>
                          {m.is_critical && (
                            <Chip variant="danger" className="text-2xs py-0.5 px-1.5">
                              Critical
                            </Chip>
                          )}
                        </span>
                      }
                    />
                  </div>
                  {!draft.is_pass && (
                    <Input
                      fieldSize="sm"
                      type="text"
                      placeholder="NG reason (optional)"
                      disabled={isTerminal}
                      value={draft.notes}
                      onChange={(e) => {
                        setChecklistDrafts((s) => ({
                          ...s,
                          [m.id]: {
                            ...s[m.id],
                            notes: e.target.value,
                            dirty: true,
                          },
                        }));
                      }}
                      aria-label={`${m.parameter_name} NG reason`}
                    />
                  )}
                </div>
              );
            })}
          </div>
          {!isTerminal && (
            <Button
              variant="secondary"
              size="sm"
              onClick={() => {
                setChecklistDrafts((s) =>
                  Object.entries(s).reduce(
                    (acc, [k, v]) => ({
                      ...acc,
                      [k]: { ...v, is_pass: true, notes: '', dirty: true },
                    }),
                    {},
                  ),
                );
              }}
              className="w-full"
            >
              Tick all
            </Button>
          )}
        </Panel>
      )}

      {/* Sample check */}
      <Panel title="Sample">
        <div>
          <span className="block text-2xs uppercase tracking-wider text-muted mb-1.5">
            Defective pieces found
          </span>
          {tapTargets ? (
            <div
              role="group"
              aria-label="Defective pieces found"
              className="flex flex-wrap gap-1.5"
            >
              {tapTargets.map((count) => {
                const selected = sampleDefectCount === String(count);
                return (
                  <button
                    key={count}
                    type="button"
                    disabled={isTerminal}
                    aria-pressed={selected}
                    onClick={() => {
                      setSampleDefectCount(String(count));
                      setSampleDefectDirty(true);
                    }}
                    className={cn(
                      'h-7 min-w-[2.25rem] px-2.5 rounded-md border text-xs font-mono tabular-nums cursor-pointer transition-colors duration-fast',
                      focusRing,
                      selected
                        ? 'border-accent bg-accent text-accent-fg font-medium'
                        : 'border-default bg-canvas text-primary hover:bg-elevated',
                      isTerminal && 'opacity-60 cursor-not-allowed',
                    )}
                  >
                    {count}
                  </button>
                );
              })}
            </div>
          ) : (
            <Input
              fieldSize="sm"
              type="number"
              min="0"
              max={inspection.sample_size}
              disabled={isTerminal}
              value={sampleDefectCount}
              onChange={(e) => {
                setSampleDefectCount(e.target.value);
                setSampleDefectDirty(true);
              }}
              aria-label="Defective pieces found"
              containerClassName="max-w-32"
              className="font-mono tabular-nums"
            />
          )}
        </div>
      </Panel>

      {/* Measurements */}
      {numericMeasurements.length > 0 && (
        <Panel
          title="Measurements"
          meta={`${numericMeasurements.length} dimension${numericMeasurements.length === 1 ? '' : 's'} · ${sampleIndices.length} piece${sampleIndices.length === 1 ? '' : 's'}`}
          noPadding
        >
          <table className={tableCls}>
            <thead>
              <tr className={theadTrCls}>
                <Th>Parameter</Th>
                <Th align="right">Nominal</Th>
                <Th align="right">Tolerance</Th>
                {pieceColumns.map((idx) => (
                  <Th key={`piece-${idx}`} align="right">
                    Piece {idx}
                  </Th>
                ))}
              </tr>
            </thead>
            <tbody>
              {Array.from(groupedMeasurements.entries()).map(
                ([paramName, measurements], groupIndex) => {
                  const first = measurements[0];
                  const isCritical = isCriticalParameter(measurements);
                  const revealed = isParameterRevealed(paramName, measurements);
                  const numericTol =
                    first?.tolerance_min !== null && first?.tolerance_max !== null
                      ? `${first.tolerance_min} … ${first.tolerance_max}`
                      : '—';

                  const label = (
                    <div className="flex items-center gap-2">
                      <span>{paramName}</span>
                      {isCritical && <Chip variant="danger">Critical</Chip>}
                      <span className="text-2xs uppercase text-muted">
                        {first?.parameter_type_label ?? first?.parameter_type}
                      </span>
                    </div>
                  );

                  if (!revealed) {
                    return (
                      <tr key={paramName} className={trCls}>
                        <Td>{label}</Td>
                        <Td align="right" mono>
                          {first?.nominal_value ?? '—'} {first?.unit_of_measure ?? ''}
                        </Td>
                        <Td align="right" mono>
                          {numericTol}
                        </Td>
                        {pieceColumns.length > 0 && (
                          <Td colSpan={pieceColumns.length}>
                            {isTerminal ? (
                              <span className="text-muted">—</span>
                            ) : (
                              <Checkbox
                                id={`cb-tol-${groupIndex}`}
                                checked={parameterTicks[paramName] === true}
                                onChange={(e) =>
                                  setWithinTolerance(paramName, measurements, e.target.checked)
                                }
                                label="Within tolerance"
                              />
                            )}
                          </Td>
                        )}
                      </tr>
                    );
                  }

                  return (
                    <tr key={paramName} className={trCls}>
                      <Td>{label}</Td>
                      <Td align="right" mono>
                        {first?.nominal_value ?? '—'} {first?.unit_of_measure ?? ''}
                      </Td>
                      <Td align="right" mono>
                        {numericTol}
                      </Td>
                      {pieceColumns.map((sampleIdx) => {
                        const m = measurements.find((n) => n.sample_index === sampleIdx);
                        if (!m) {
                          return (
                            <Td key={`${paramName}-${sampleIdx}`} align="right" mono>
                              —
                            </Td>
                          );
                        }

                        const draft = measurementDrafts[m.id];
                        if (!draft) return null;

                        const draftNum =
                          draft.measured_value.trim() === '' ? null : Number(draft.measured_value);
                        const isOutOfTolerance =
                          draftNum !== null &&
                          ((m.tolerance_min !== null && draftNum < m.tolerance_min) ||
                            (m.tolerance_max !== null && draftNum > m.tolerance_max));

                        return (
                          <Td
                            key={`${paramName}-${sampleIdx}`}
                            align="right"
                            mono
                            className={isOutOfTolerance ? 'text-danger-fg' : ''}
                          >
                            <Input
                              fieldSize="sm"
                              type="number"
                              step="any"
                              disabled={isTerminal}
                              aria-label={`${paramName}, piece ${sampleIdx}${m.unit_of_measure ? ` (${m.unit_of_measure})` : ''}`}
                              containerClassName="inline-flex w-20"
                              className="text-right font-mono tabular-nums"
                              value={draft.measured_value}
                              onChange={(e) =>
                                setMeasurementDrafts((s) => ({
                                  ...s,
                                  [m.id]: {
                                    ...s[m.id],
                                    measured_value: e.target.value,
                                    dirty: true,
                                  },
                                }))
                              }
                            />
                          </Td>
                        );
                      })}
                    </tr>
                  );
                },
              )}
            </tbody>
          </table>
        </Panel>
      )}

      {/* Live verdict — neutral until the inspector records anything: a fresh
          checklist is all-unticked, which would otherwise preview as a failure. */}
      {!isTerminal && (
        <Panel>
          {!isTouched &&
          isDefectCountBlank &&
          checklistMeasurements.every((m) => m.is_pass === null) ? (
            <Chip variant="neutral">Not started</Chip>
          ) : (
            <Chip variant={verdict === 'pass' ? 'success' : 'danger'}>
              {verdict === 'pass' ? 'Will pass' : reason ? `Will fail — ${reason}` : 'Will fail'}
            </Chip>
          )}
        </Panel>
      )}

      {/* Terminal state verdict */}
      {isTerminal && (
        <Panel>
          <Chip
            variant={
              inspection.status === 'passed'
                ? 'success'
                : inspection.status === 'awaiting_review'
                  ? 'warning'
                  : 'danger'
            }
          >
            {inspection.status === 'passed'
              ? 'Passed'
              : inspection.status === 'awaiting_review'
                ? `Awaiting checker${inspection.proposed_result ? ` · proposed ${inspection.proposed_result}` : ''}`
                : inspection.status === 'failed'
                  ? 'Failed'
                  : 'Cancelled'}
          </Chip>
          <p className="text-sm text-muted mt-3">
            Recorded: <span className="font-mono">{sampleDefectCount}</span> defects ·{' '}
            {uncheckedCount} NG items
          </p>
        </Panel>
      )}

      {/* Actions */}
      {!isTerminal && (
        <Panel>
          <div className="space-y-2">
            <div className="flex gap-2">
              <Button
                variant="secondary"
                size="sm"
                icon={<LuSave size={14} />}
                loading={saving}
                disabled={recordResult.isPending || !isDirty || !isValidDefectCount}
                onClick={() => recordResult.mutate(false)}
              >
                Save draft
              </Button>
              <Button
                variant="primary"
                size="sm"
                icon={<LuCheck size={14} />}
                loading={submitting}
                disabled={recordResult.isPending || !canSubmit}
                onClick={() => {
                  if (uncheckedCount > 0) {
                    setConfirmSubmit(true);
                  } else {
                    recordResult.mutate(true);
                  }
                }}
              >
                Submit result
              </Button>
            </div>
            {!canSubmit && missingFields.length > 0 && (
              <p className="text-2xs text-muted">Missing: {missingFields.join(' · ')}</p>
            )}
          </div>
        </Panel>
      )}

      <ConfirmDialog
        isOpen={confirmSubmit}
        title="Submit with NG items?"
        description={`${uncheckedCount} checklist item${uncheckedCount === 1 ? '' : 's'} ${uncheckedCount === 1 ? 'is' : 'are'} not ticked and will be recorded as NG.`}
        confirmLabel="Submit anyway"
        onConfirm={() => recordResult.mutate(true)}
        onClose={() => setConfirmSubmit(false)}
        pending={submitting}
      />
    </div>
  );
}
