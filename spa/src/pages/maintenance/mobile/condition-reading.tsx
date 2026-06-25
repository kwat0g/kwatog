import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { conditionReadingsApi } from '@/api/maintenance/conditionReadings';
import { machinesApi } from '@/api/mrp/machines';
import toast from 'react-hot-toast';
import { AlertTriangle, CheckCircle2, Thermometer } from 'lucide-react';
import type { ConditionMetric, ConditionReadingResult, MachineHealthSnapshot } from '@/types/maintenance';
import type { Machine } from '@/types/mrp';

const METRICS: { value: ConditionMetric; label: string; unit: string; placeholder: string }[] = [
  { value: 'temperature', label: 'Temperature', unit: 'celsius', placeholder: 'e.g. 55.0' },
  { value: 'vibration',   label: 'Vibration',   unit: 'mm/s',    placeholder: 'e.g. 4.2' },
  { value: 'pressure',    label: 'Pressure',    unit: 'bar',     placeholder: 'e.g. 8.0' },
  { value: 'current',     label: 'Current',     unit: 'amp',     placeholder: 'e.g. 120' },
  { value: 'oil_quality', label: 'Oil Quality',  unit: 'percent', placeholder: 'e.g. 85' },
];

export default function MobileConditionReading() {
  const queryClient = useQueryClient();

  // ── Machine list ───────────────────────────────────────
  const { data: machinesData, isLoading: machinesLoading } = useQuery({
    queryKey: ['mrp', 'machines', 'condition-reading'],
    queryFn: () => machinesApi.list({ per_page: 200 }),
  });

  const machines = (machinesData?.data ?? []) as Machine[];

  // ── Form state ─────────────────────────────────────────
  const [machineId, setMachineId] = useState('');
  const [metric, setMetric] = useState<ConditionMetric>('temperature');
  const [value, setValue] = useState('');
  const [notes, setNotes] = useState('');
  const [lastResult, setLastResult] = useState<ConditionReadingResult | null>(null);

  // ── Health snapshot for selected machine ───────────────
  const { data: healthData } = useQuery({
    queryKey: ['maintenance', 'health-snapshot', machineId],
    queryFn: () => conditionReadingsApi.healthSnapshot({ machine_id: parseInt(machineId, 10) }),
    enabled: !!machineId,
  });

  // ── Submit mutation ────────────────────────────────────
  const mutation = useMutation({
    mutationFn: () =>
      conditionReadingsApi.record({
        machine_id: parseInt(machineId, 10),
        metric,
        value: parseFloat(value),
        source: 'manual',
        notes: notes.trim() || undefined,
      }),
    onSuccess: (result) => {
      setLastResult(result as unknown as ConditionReadingResult);
      if ((result as unknown as ConditionReadingResult).triggered) {
        toast.error('Threshold breached! Corrective WO created.', { duration: 5000 });
      } else {
        toast.success('Reading recorded');
      }
      setValue('');
      setNotes('');
      queryClient.invalidateQueries({ queryKey: ['maintenance', 'health-snapshot', machineId] });
      queryClient.invalidateQueries({ queryKey: ['maintenance', 'condition-readings'] });
    },
    onError: () => toast.error('Failed to record reading.'),
  });

  const canSubmit = machineId && metric && parseFloat(value) >= 0 && !isNaN(parseFloat(value));
  const selectedMetricInfo = METRICS.find(m => m.value === metric);

  return (
    <div className="space-y-4">
      <h1 className="text-lg font-semibold flex items-center gap-2">
        <Thermometer className="w-5 h-5" />
        Condition Reading
      </h1>

      {/* Form */}
      <form
        onSubmit={e => {
          e.preventDefault();
          if (canSubmit) mutation.mutate();
        }}
        className="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4 space-y-4"
      >
        {/* Machine selector */}
        <div>
          <label htmlFor="machine_select" className="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
            Machine
          </label>
          {machinesLoading ? (
            <div className="h-12 rounded-lg bg-zinc-100 dark:bg-zinc-800 animate-pulse" />
          ) : (
            <select
              id="machine_select"
              value={machineId}
              onChange={e => {
                setMachineId(e.target.value);
                setLastResult(null);
              }}
              className="w-full rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 min-h-[44px]"
            >
              <option value="">Select a machine...</option>
              {machines.map(m => (
                <option key={m.id} value={m.id}>
                  {m.machine_code} — {m.name}
                </option>
              ))}
            </select>
          )}
        </div>

        {/* Metric selector */}
        <div>
          <label htmlFor="metric_select" className="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
            Reading Type
          </label>
          <select
            id="metric_select"
            value={metric}
            onChange={e => setMetric(e.target.value as ConditionMetric)}
            className="w-full rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 min-h-[44px]"
          >
            {METRICS.map(m => (
              <option key={m.value} value={m.value}>
                {m.label} ({m.unit})
              </option>
            ))}
          </select>
        </div>

        {/* Value input */}
        <div>
          <label htmlFor="reading_value" className="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
            Value ({selectedMetricInfo?.unit})
          </label>
          <input
            id="reading_value"
            type="number"
            inputMode="decimal"
            step="0.001"
            value={value}
            onChange={e => setValue(e.target.value)}
            placeholder={selectedMetricInfo?.placeholder}
            className="w-full rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-4 py-4 text-2xl font-mono tabular-nums text-center focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
          />
        </div>

        {/* Notes */}
        <div>
          <label htmlFor="reading_notes" className="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
            Notes (optional)
          </label>
          <textarea
            id="reading_notes"
            value={notes}
            onChange={e => setNotes(e.target.value)}
            rows={2}
            placeholder="Observations..."
            className="w-full rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 resize-none"
          />
        </div>

        <button
          type="submit"
          disabled={!canSubmit || mutation.isPending}
          className="w-full min-h-[52px] rounded-lg bg-indigo-600 hover:bg-indigo-700 disabled:bg-zinc-300 dark:disabled:bg-zinc-700 text-white font-semibold text-base transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
        >
          {mutation.isPending ? 'Recording...' : 'Record Reading'}
        </button>
      </form>

      {/* Alert banner for triggered WO */}
      {lastResult?.triggered && (
        <div className="rounded-lg border-2 border-red-300 dark:border-red-700 bg-red-50 dark:bg-red-900/20 p-4" role="alert">
          <div className="flex items-start gap-3">
            <AlertTriangle className="w-5 h-5 text-red-600 dark:text-red-400 flex-shrink-0 mt-0.5" />
            <div>
              <div className="text-sm font-semibold text-red-800 dark:text-red-200">
                Threshold Breached
              </div>
              <p className="text-sm text-red-700 dark:text-red-300 mt-1">
                {lastResult.reason}
              </p>
              {lastResult.work_order && (
                <p className="text-sm text-red-600 dark:text-red-400 mt-1 font-mono">
                  Corrective WO created: {lastResult.work_order.mwo_number}
                </p>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Success banner for non-breach */}
      {lastResult && !lastResult.triggered && (
        <div className="rounded-lg border border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-900/20 p-4">
          <div className="flex items-center gap-2 text-sm text-emerald-800 dark:text-emerald-200">
            <CheckCircle2 className="w-4 h-4" />
            Reading within normal range.
            {lastResult.reason && (
              <span className="text-xs text-emerald-600 dark:text-emerald-400 ml-1">
                ({lastResult.reason})
              </span>
            )}
          </div>
        </div>
      )}

      {/* Health snapshot for selected machine */}
      {machineId && healthData && (
        <div className="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4">
          <h2 className="text-base font-semibold mb-3">Current Health Status</h2>
          <div className="space-y-2">
            {(healthData as MachineHealthSnapshot[]).map(snap => (
              <div
                key={snap.metric}
                className="flex items-center justify-between p-2 rounded bg-zinc-50 dark:bg-zinc-800/50 text-sm"
              >
                <div className="flex items-center gap-2">
                  <span
                    className={`w-2 h-2 rounded-full flex-shrink-0 ${
                      snap.status === 'critical'
                        ? 'bg-red-500'
                        : snap.status === 'warning'
                          ? 'bg-amber-500'
                          : 'bg-emerald-500'
                    }`}
                  />
                  <span className="capitalize font-medium">{snap.metric.replace(/_/g, ' ')}</span>
                </div>
                <div className="font-mono tabular-nums text-xs">
                  {snap.value !== null ? `${snap.value} ${snap.unit}` : '—'}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
