import { useEffect, useMemo, useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { conditionReadingsApi } from '@/api/maintenance/conditionReadings';
import { machinesApi } from '@/api/mrp/machines';
import toast from 'react-hot-toast';
import { AlertTriangle, CheckCircle2, Thermometer } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import type { ConditionMetric, ConditionReadingResult, ConditionSource, MachineHealthSnapshot } from '@/types/maintenance';
import type { Machine } from '@/types/mrp';

export default function MobileConditionReading() {
  const queryClient = useQueryClient();

  // ── Machine list ───────────────────────────────────────
  const { data: machinesData, isLoading: machinesLoading } = useQuery({
    queryKey: ['mrp', 'machines', 'condition-reading'],
    queryFn: () => machinesApi.list({ per_page: 200 }),
  });

  const machines = (machinesData?.data ?? []) as Machine[];

  const { data: metricOptions } = useQuery({
    queryKey: ['maintenance', 'condition-readings', 'options'],
    queryFn: () => conditionReadingsApi.options(),
    staleTime: 5 * 60 * 1000,
  });
  const metrics = useMemo(() => (metricOptions?.metrics ?? []) as Array<{ value: ConditionMetric; label: string; unit: string }>, [metricOptions?.metrics]);
  const sources = useMemo(() => metricOptions?.sources ?? [], [metricOptions?.sources]);

  // ── Form state ─────────────────────────────────────────
  const [machineId, setMachineId] = useState('');
  const [metric, setMetric] = useState<ConditionMetric | ''>('');
  const [source, setSource] = useState<ConditionSource | ''>('');
  const [value, setValue] = useState('');
  const [notes, setNotes] = useState('');
  const [lastResult, setLastResult] = useState<ConditionReadingResult | null>(null);

  // ── Health snapshot for selected machine ───────────────
  const { data: healthData } = useQuery({
    queryKey: ['maintenance', 'health-snapshot', machineId],
    queryFn: () => conditionReadingsApi.healthSnapshot({ machine_id: machineId }),
    enabled: !!machineId,
  });

  // ── Submit mutation ────────────────────────────────────
  const mutation = useMutation({
    mutationFn: () =>
      conditionReadingsApi.record({
        machine_id: machineId,
        metric: metric as ConditionMetric,
        value: parseFloat(value),
        source: source as ConditionSource,
        notes: notes.trim() || undefined,
      }),
    onSuccess: (result) => {
      setLastResult(result);
      if (result.triggered) {
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

  const canSubmit = machineId && metric && source && parseFloat(value) >= 0 && !isNaN(parseFloat(value));
  const selectedMetricInfo = metrics.find(m => m.value === metric);
  useEffect(() => {
    if (!metric && metrics.length > 0) setMetric(metrics[0].value);
  }, [metric, metrics]);
  useEffect(() => {
    if (!sources.some((option) => option.value === source) && sources.length > 0) {
      setSource(sources[0].value as ConditionSource);
    }
  }, [source, sources]);

  return (
    <div className="space-y-4">
      <h1 className="text-lg font-medium flex items-center gap-2">
        <Thermometer className="w-5 h-5" />
        Condition reading
      </h1>

      {/* Form */}
      <form
        onSubmit={e => {
          e.preventDefault();
          if (canSubmit) mutation.mutate();
        }}
        className="rounded-md border border-default bg-canvas p-4 space-y-4"
      >
        {/* Machine selector */}
        {machinesLoading ? (
          <SkeletonBlock className="h-11 rounded-md" />
        ) : (
          <Select
            id="machine_select"
            label="Machine"
            fieldSize="lg"
            value={machineId}
            onChange={e => {
              setMachineId(e.target.value);
              setLastResult(null);
            }}
          >
            <option value="">Select a machine…</option>
            {machines.map(m => (
              <option key={m.id} value={m.id}>
                {m.machine_code} — {m.name}
              </option>
            ))}
          </Select>
        )}

        {/* Metric selector */}
        <Select
          id="metric_select"
          label="Reading type"
          fieldSize="lg"
          value={metric}
          onChange={e => setMetric(e.target.value as ConditionMetric)}
        >
          {metrics.map(m => (
            <option key={m.value} value={m.value}>
              {m.label} ({m.unit})
            </option>
          ))}
        </Select>

        <Select
          id="source_select"
          label="Source"
          fieldSize="lg"
          value={source}
          onChange={e => setSource(e.target.value as ConditionSource)}
        >
          {sources.map((option) => (
            <option key={option.value} value={option.value}>{option.label}</option>
          ))}
        </Select>

        {/* Value input */}
        <Input
          id="reading_value"
          label={`Value (${selectedMetricInfo?.unit})`}
          fieldSize="xl"
          type="number"
          inputMode="decimal"
          step="0.001"
          value={value}
          onChange={e => setValue(e.target.value)}
          placeholder="Enter reading"
          className="text-center font-mono tabular-nums"
        />

        {/* Notes */}
        <Textarea
          id="reading_notes"
          label="Notes (optional)"
          value={notes}
          onChange={e => setNotes(e.target.value)}
          rows={2}
          placeholder="Observations…"
          className="resize-none"
        />

        <Button
          type="submit"
          variant="primary"
          size="xl"
          className="w-full"
          disabled={!canSubmit}
          loading={mutation.isPending}
        >
          {mutation.isPending ? 'Recording…' : 'Record reading'}
        </Button>
      </form>

      {/* Alert banner for triggered WO */}
      {lastResult?.triggered && (
        <div className="rounded-md border border-danger bg-danger-bg p-4" role="alert">
          <div className="flex items-start gap-3">
            <AlertTriangle className="w-5 h-5 text-danger flex-shrink-0 mt-0.5" />
            <div>
              <div className="text-sm font-medium text-danger">
                Threshold breached
              </div>
              <p className="text-sm text-danger mt-1">
                {lastResult.reason}
              </p>
              {lastResult.work_order && (
                <p className="text-sm text-danger mt-1 font-mono">
                  Corrective WO created: {lastResult.work_order.mwo_number}
                </p>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Success banner for non-breach */}
      {lastResult && !lastResult.triggered && (
        <div className="rounded-md border border-success bg-success-bg p-4">
          <div className="flex items-center gap-2 text-sm text-success">
            <CheckCircle2 className="w-4 h-4" />
            Reading within normal range.
            {lastResult.reason && (
              <span className="text-xs text-success ml-1">
                ({lastResult.reason})
              </span>
            )}
          </div>
        </div>
      )}

      {/* Health snapshot for selected machine */}
      {machineId && healthData && (
        <div className="rounded-md border border-default bg-canvas p-4">
          <h2 className="text-base font-medium mb-3">Current health status</h2>
          <div className="space-y-2">
            {(healthData as MachineHealthSnapshot[]).map(snap => (
              <div
                key={snap.metric}
                className="flex items-center justify-between p-2 rounded bg-surface text-sm"
              >
                <div className="flex items-center gap-2">
                  <span
                    className={`w-2 h-2 rounded-full flex-shrink-0 ${
                      snap.status === 'critical'
                        ? 'bg-danger'
                        : snap.status === 'warning'
                          ? 'bg-warning'
                          : 'bg-success'
                    }`}
                  />
                  <span className="font-medium">{metrics.find((metricOption) => metricOption.value === snap.metric)?.label ?? snap.metric}</span>
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
