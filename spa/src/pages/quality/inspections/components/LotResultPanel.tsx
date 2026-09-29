/**
 * Lot result panel — the capture surface for `lot_checklist` inspections (incoming,
 * in-process and outgoing).
 *
 * The inspector ticks the lot-level checks, taps the number of defective pieces found
 * in the AQL sample, and answers each dimension: a non-critical dimension is one tick
 * when it is within tolerance, a critical one (a CTQ) is a measured value per piece.
 * Unticking a non-critical dimension reveals its piece rows so a dimension known to be
 * out carries a reading — or an explicit NG mark — per piece instead of a blank cell.
 *
 * Every dimension must be answered before submit. The tick is not decoration: it is
 * sent as the row's `is_pass`, so a dimension left unanswered reaches `complete()` as
 * an unresolved row and is refused. An unticked dimension is likewise not a failure —
 * defects are the pieces that carry one, never a count invented from the dimension.
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
  /** An explicit NG mark: the piece failed, with no reading to show for it. */
  ng: boolean;
  dirty: boolean;
}

interface LotResultPanelProps {
  inspection: Inspection;
  isTerminal: boolean;
}

/** Above this many rejectable pieces a row of taps is slower than typing the count. */
const MAX_TAP_TARGETS = 6;

/** Server-side evidence, not local draft state: a saved reading or a saved claim is never hidden. */
const hasServerEvidence = (measurements: InspectionMeasurement[]): boolean =>
  measurements.some((m) => m.measured_value !== null || m.is_pass !== null);

export function LotResultPanel({ inspection, isTerminal }: LotResultPanelProps) {
  const qc = useQueryClient();
  const [checklistDrafts, setChecklistDrafts] = useState<Record<string, ChecklistDraft>>({});
  const [measurementDrafts, setMeasurementDrafts] = useState<Record<string, MeasurementDraft>>({});
  /**
   * Per-dimension answer, keyed by the dimension's stable identity (its parameter
   * name plus criticality — see `groupedMeasurements`).
   * `true` = ticked "within tolerance"; `false` = the inspector unticked it, which
   * reveals the piece rows; absent = not answered yet.
   *
   * The absence of an answer is its own state: a fresh panel must not read as either
   * conforming or non-conforming, and submit stays disabled until every dimension
   * carries an answer the server can store.
   */
  const [parameterTicks, setParameterTicks] = useState<Record<string, boolean | undefined>>({});
  const [sampleDefectCount, setSampleDefectCount] = useState<string>('');
  const [sampleDefectDirty, setSampleDefectDirty] = useState(false);
  const [confirmSubmit, setConfirmSubmit] = useState(false);
  // A tick reaches the server as each row's `is_pass`, so it survives a refetch; it
  // is dropped when the panel moves to another inspection.
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

  const isCriticalParameter = (measurements: InspectionMeasurement[]): boolean =>
    Boolean(measurements[0]?.is_critical);

  // Group numeric measurements by dimension, keyed by a stable identity — the
  // parameter name plus its criticality — preserving order.
  //
  // The identity carries criticality because grouping on the bare name merges two
  // spec items that share one, and the merged group is answered for by whichever
  // item came first: a tick could then stand in for a critical dimension's missing
  // reading. Keying on both keeps a CTQ from being collapsed behind a namesake.
  const groupedMeasurements = useMemo(() => {
    const map = new Map<string, InspectionMeasurement[]>();
    for (const m of numericMeasurements) {
      const key = `${m.parameter_name}::${m.is_critical ? 'critical' : 'normal'}`;
      if (!map.has(key)) {
        map.set(key, []);
      }
      map.get(key)!.push(m);
    }
    return map;
  }, [numericMeasurements]);

  /**
   * The tick state of the dimension each row belongs to, so a row asks its own
   * dimension rather than the inspector's draft state.
   */
  const rowTicks = useMemo(() => {
    const map = new Map<string, boolean | undefined>();
    for (const [key, measurements] of groupedMeasurements) {
      for (const m of measurements) map.set(m.id, parameterTicks[key]);
    }
    return map;
  }, [groupedMeasurements, parameterTicks]);

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
        // A saved failure with a reading is a failed measurement, not an NG mark.
        ng: m.is_pass === false && m.measured_value === null,
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

  /**
   * A dimension shows its piece rows when it is critical (a CTQ keeps its
   * variable-data matrix), when the inspector unticked it, or when the server
   * already holds evidence for it — a saved reading or a saved claim is never
   * hidden behind a tick.
   */
  const isParameterRevealed = (key: string, measurements: InspectionMeasurement[]): boolean => {
    if (isCriticalParameter(measurements)) return true;
    if (parameterTicks[key] === false) return true;
    return parameterTicks[key] === undefined && hasServerEvidence(measurements);
  };

  // Every dimension renders its answer cell, so the piece columns belong to the
  // table whenever there is anything to measure: a dimension collapsed to its tick
  // is a row whose tick spans them. Deriving the columns from what happened to be
  // revealed left an all-non-critical plan with no cell to put a tick in — and the
  // inspector with no way to answer a dimension at all.
  const pieceColumns = sampleIndices;

  const setWithinTolerance = (
    key: string,
    measurements: InspectionMeasurement[],
    withinTolerance: boolean,
  ) => {
    setParameterTicks((s) => ({ ...s, [key]: withinTolerance }));
    if (!withinTolerance) return;
    // Ticking speaks for the whole dimension: the payload sends the tick as every
    // row's `is_pass` with a null value, so clear the numbers and NG marks the
    // inspector can no longer see — without marking an already-empty row dirty.
    setMeasurementDrafts((s) => {
      const next = { ...s };
      for (const m of measurements) {
        const draft = next[m.id];
        if (!draft) continue;
        if (draft.measured_value.trim() === '' && !draft.ng) continue;
        next[m.id] = { ...draft, measured_value: '', ng: false, dirty: true };
      }
      return next;
    });
  };

  const markNg = (id: string, ng: boolean) => {
    setMeasurementDrafts((s) => ({
      ...s,
      // The mark and a reading are exclusive: an NG piece has no measurement to show.
      [id]: { ...s[id], measured_value: ng ? '' : s[id].measured_value, ng, dirty: true },
    }));
  };

  /**
   * The claim the panel records for one row: `true` from a ticked dimension,
   * `false` from an explicit NG mark, and null wherever a reading decides for
   * itself — a claim never overrides a value, on the client or on the server.
   * Where the inspector has not spoken and the server already holds a claim,
   * that claim is the row's answer: evidence the panel did not create still is
   * evidence, and it must survive the round trip instead of being re-asked.
   */
  const claimFor = (m: InspectionMeasurement): boolean | null => {
    const draft = measurementDrafts[m.id];
    if ((draft?.measured_value ?? '').trim() !== '') return null;
    if (draft?.ng) return false;
    const tick = rowTicks.get(m.id);
    if (tick !== undefined) return tick === true ? true : null;
    // A claim-only row echoes its stored claim; a row with a reading has no
    // claim — the reading decides, and the stored `is_pass` beside it was the
    // tolerance's verdict, not an inspector's claim to re-send.
    return m.measured_value === null ? (m.is_pass ?? null) : null;
  };

  /**
   * Answered means the server can resolve the row's `is_pass` from what is sent: a
   * reading (the tolerance decides), a tick, an NG mark, or evidence already saved
   * (a stored reading or a stored claim).
   */
  const isAnswered = (m: InspectionMeasurement): boolean => {
    if (claimFor(m) !== null) return true;
    if ((measurementDrafts[m.id]?.measured_value ?? '').trim() !== '') return true;
    return m.measured_value !== null;
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
    // The tick answers for its dimension; an NG mark answers for its piece. An
    // unanswered row claims nothing and holds the verdict at pending.
    is_pass: claimFor(m),
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
  // A tick on its own has nothing to persist yet, but it is operator input: the
  // panel is no longer "not started" once one is set.
  const isTouched = isDirty || Object.values(parameterTicks).some((tick) => tick !== undefined);

  const uncheckedCount = checklistMeasurements.filter(
    (m) => !(checklistDrafts[m.id]?.is_pass ?? true),
  ).length;

  /**
   * An unanswered row is one the server cannot resolve: every dimension must be
   * answered — ticked within tolerance, or revealed with each piece carrying a
   * reading or an NG mark. The server refuses to complete an inspection holding an
   * unresolved row (after rolling back the defect count with it), so the panel must
   * not send one, and the preview must not read as a pass over it.
   */
  const unansweredCount = numericMeasurements.filter((m) => !isAnswered(m)).length;

  // Check if sample defect count is a valid whole number
  const isValidDefectCount =
    sampleDefectCount === '' ||
    (/^\d+$/.test(sampleDefectCount) && Number(sampleDefectCount) <= inspection.sample_size);
  const isDefectCountBlank = sampleDefectCount === '';

  const canSubmit = isValidDefectCount && !isDefectCountBlank && unansweredCount === 0;

  const missingFields = [
    isDefectCountBlank
      ? 'sample defect count'
      : !isValidDefectCount
        ? `sample defect count (whole number 0–${inspection.sample_size})`
        : null,
    unansweredCount > 0
      ? `${unansweredCount} unanswered measurement${unansweredCount === 1 ? '' : 's'}`
      : null,
  ].filter(Boolean);

  const recordResult = useMutation({
    mutationFn: (complete: boolean) => {
      const payload = {
        checklist: checklistMeasurements.map((m) => ({
          id: m.id,
          is_pass: checklistDrafts[m.id]?.is_pass ?? true,
          notes: checklistDrafts[m.id]?.notes.trim() || null,
        })),
        measurements: numericMeasurements.map((m) => {
          const value = (measurementDrafts[m.id]?.measured_value ?? '').trim();
          const claim = claimFor(m);
          return {
            id: m.id,
            // A ticked dimension sends its claim and no reading; a revealed row
            // sends whichever of the two it has, never both — and a row the
            // inspector left silent echoes the evidence the server already holds.
            measured_value: claim === true ? null : value !== '' ? value : (m.measured_value === null ? null : String(m.measured_value)),
            is_pass: claim,
          };
        }),
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
              {Array.from(groupedMeasurements.entries()).map(([key, measurements]) => {
                const first = measurements[0];
                const paramName = first?.parameter_name ?? '';
                const isCritical = isCriticalParameter(measurements);
                const revealed = isParameterRevealed(key, measurements);
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
                    <tr key={key} className={trCls}>
                      <Td>{label}</Td>
                      <Td align="right" mono>
                        {first?.nominal_value ?? '—'} {first?.unit_of_measure ?? ''}
                      </Td>
                      <Td align="right" mono>
                        {numericTol}
                      </Td>
                      <Td colSpan={pieceColumns.length}>
                        {isTerminal ? (
                          <span className="text-muted">—</span>
                        ) : (
                          <Checkbox
                            id={`cb-tol-${key}`}
                            checked={parameterTicks[key] === true}
                            onChange={(e) =>
                              setWithinTolerance(key, measurements, e.target.checked)
                            }
                            label={`${paramName} within tolerance`}
                          />
                        )}
                      </Td>
                    </tr>
                  );
                }

                return (
                  <tr key={key} className={trCls}>
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
                          <Td key={`${key}-${sampleIdx}`} align="right" mono>
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
                          key={`${key}-${sampleIdx}`}
                          align="right"
                          mono
                          className={isOutOfTolerance || draft.ng ? 'text-danger-fg' : ''}
                        >
                          <div className="flex items-center justify-end gap-1">
                            <Input
                              fieldSize="sm"
                              type="number"
                              step="any"
                              disabled={isTerminal || draft.ng}
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
                                    // A reading and an NG mark are exclusive, and
                                    // the reading decides: typing one drops the mark.
                                    ng: e.target.value.trim() === '' ? s[m.id].ng : false,
                                    dirty: true,
                                  },
                                }))
                              }
                            />
                            {/* A CTQ is measured, not asserted: only a revealed
                                non-critical piece can be marked NG with no reading. */}
                            {!isCritical && (
                              <button
                                type="button"
                                disabled={isTerminal}
                                aria-pressed={draft.ng}
                                aria-label={`${paramName}, piece ${sampleIdx} NG`}
                                onClick={() => markNg(m.id, !draft.ng)}
                                className={cn(
                                  'h-7 px-1.5 rounded-md border text-2xs font-medium cursor-pointer transition-colors duration-fast',
                                  focusRing,
                                  draft.ng
                                    ? 'border-danger bg-danger text-accent-fg'
                                    : 'border-default bg-canvas text-muted hover:bg-elevated',
                                  isTerminal && 'opacity-60 cursor-not-allowed',
                                )}
                              >
                                NG
                              </button>
                            )}
                          </div>
                        </Td>
                      );
                    })}
                  </tr>
                );
              })}
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
            <Chip
              variant={
                verdict === 'pass' ? 'success' : verdict === 'pending' ? 'warning' : 'danger'
              }
            >
              {verdict === 'pass'
                ? 'Will pass'
                : verdict === 'pending'
                  ? (reason ?? 'Incomplete')
                  : reason
                    ? `Will fail — ${reason}`
                    : 'Will fail'}
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
