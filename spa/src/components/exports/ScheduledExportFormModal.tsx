import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { scheduledExportsApi, exportsApi } from '@/api/exports';
import type {
  CreateScheduledExportInput,
  ExportFormat,
  ScheduledExport,
} from '@/types/exports';
import { Button } from '@/components/ui/Button';
import { Checkbox } from '@/components/ui/Checkbox';
import { Input } from '@/components/ui/Input';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { Textarea } from '@/components/ui/Textarea';
import { formatDateTime } from '@/lib/formatDate';

interface ScheduledExportFormModalProps {
  isOpen: boolean;
  onClose: () => void;
  module: string;
  filters?: Record<string, unknown>;
  initial?: ScheduledExport | null;
  onSaved?: (schedule: ScheduledExport) => void;
}

type ApiError = {
  response?: {
    data?: {
      message?: string;
      errors?: Record<string, string[]>;
    };
  };
};

function errorMessage(error: unknown): string {
  const data = (error as ApiError).response?.data;
  const firstFieldError = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;
  return firstFieldError ?? data?.message ?? 'Could not save the scheduled export.';
}

function parseRecipients(value: string): string[] {
  return Array.from(
    new Set(
      value
        .split(/[\s,;]+/u)
        .map((recipient) => recipient.trim().toLowerCase())
        .filter(Boolean),
    ),
  );
}

function cleanFilters(filters: Record<string, unknown> | undefined): Record<string, unknown> {
  return Object.fromEntries(
    Object.entries(filters ?? {}).filter(([, value]) => value !== null && value !== undefined && value !== ''),
  );
}

export function ScheduledExportFormModal({
  isOpen,
  onClose,
  module,
  filters,
  initial = null,
  onSaved,
}: ScheduledExportFormModalProps) {
  const [name, setName] = useState('');
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [format, setFormat] = useState<ExportFormat>('xlsx');
  const [frequency, setFrequency] = useState<CreateScheduledExportInput['frequency']>('daily');
  const [dayOfWeek, setDayOfWeek] = useState('1');
  const [dayOfMonth, setDayOfMonth] = useState('1');
  const [timeOfDay, setTimeOfDay] = useState('06:00');
  const [recipients, setRecipients] = useState('');
  const [saveError, setSaveError] = useState<string | null>(null);
  const [saved, setSaved] = useState<ScheduledExport | null>(null);
  const [isSaving, setIsSaving] = useState(false);

  const columnsQuery = useQueryColumns(module, isOpen);

  useEffect(() => {
    if (!isOpen) return;

    setName(initial?.name ?? '');
    setFormat(initial?.format ?? 'xlsx');
    setFrequency(initial?.frequency ?? 'daily');
    setDayOfWeek(String(initial?.day_of_week ?? 1));
    setDayOfMonth(String(initial?.day_of_month ?? 1));
    setTimeOfDay(initial?.time_of_day ?? '06:00');
    setRecipients(initial?.recipients.join('\n') ?? '');
    setSaveError(null);
    setSaved(null);
  }, [initial, isOpen]);

  useEffect(() => {
    if (!columnsQuery.data) return;

    const available = new Set(columnsQuery.data.columns.map((column) => column.key));
    const preferred = initial?.columns ?? columnsQuery.data.selected;
    const valid = preferred.filter((column) => available.has(column));
    setSelected(new Set(valid.length > 0 ? valid : columnsQuery.data.selected));
  }, [columnsQuery.data, initial]);

  const selectedCount = selected.size;
  const effectiveFilters = useMemo(() => cleanFilters(filters), [filters]);
  const nextRun = saved?.next_run_at ?? initial?.next_run_at;

  const toggle = (key: string) => {
    setSelected((current) => {
      const next = new Set(current);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
  };

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (selectedCount === 0) {
      setSaveError('Select at least one column.');
      return;
    }

    const recipientList = parseRecipients(recipients);
    if (recipientList.length === 0) {
      setSaveError('Enter at least one recipient email address.');
      return;
    }

    setSaveError(null);
    const payload: CreateScheduledExportInput = {
      name: name.trim(),
      module,
      columns: Array.from(selected),
      filters: effectiveFilters,
      format,
      frequency,
      day_of_week: frequency === 'weekly' ? Number(dayOfWeek) : null,
      day_of_month: frequency === 'monthly' ? Number(dayOfMonth) : null,
      time_of_day: timeOfDay,
      recipients: recipientList,
      is_active: true,
    };

    setIsSaving(true);
    try {
      const result = initial
        ? await scheduledExportsApi.update(initial.id, payload)
        : await scheduledExportsApi.create(payload);
      setSaved(result);
      onSaved?.(result);
      toast.success(initial ? 'Scheduled export updated.' : 'Scheduled export created.');
    } catch (error) {
      setSaveError(errorMessage(error));
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <Modal
      isOpen={isOpen}
      onClose={isSaving ? () => undefined : onClose}
      title={initial ? 'Edit scheduled export' : 'New scheduled export'}
      size="lg"
    >
      {saveError && (
        <div className="mb-4 rounded-md border border-danger/30 bg-danger-bg px-3 py-2 text-sm text-danger-fg" role="alert">
          {saveError}
        </div>
      )}

      {saved && (
        <div className="mb-4 rounded-md border border-success/30 bg-success-bg px-3 py-2 text-sm text-success-fg">
          Saved. Next run: <span className="font-mono tabular-nums">{formatDateTime(saved.next_run_at)}</span>
        </div>
      )}

      {columnsQuery.isLoading && <SkeletonBlock className="h-40" />}
      {columnsQuery.isError && (
        <div className="py-6 text-center text-sm text-danger-fg">
          Column definitions could not be loaded. This module may not be available for scheduling.
        </div>
      )}

      {columnsQuery.data && (
        <form onSubmit={submit}>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <Input label="Schedule name" required value={name} onChange={(event) => setName(event.target.value)} placeholder="Active employees — daily" />
            <Select label="Format" value={format} onChange={(event) => setFormat(event.target.value as 'csv' | 'xlsx')}>
              <option value="xlsx">Excel workbook</option>
              <option value="csv">CSV</option>
            </Select>
            <Select label="Frequency" value={frequency} onChange={(event) => setFrequency(event.target.value as 'daily' | 'weekly' | 'monthly')}>
              <option value="daily">Daily</option>
              <option value="weekly">Weekly</option>
              <option value="monthly">Monthly</option>
            </Select>
            <Input label="Time of day" type="time" required value={timeOfDay} onChange={(event) => setTimeOfDay(event.target.value)} />
            {frequency === 'weekly' && (
              <Select label="Day of week" value={dayOfWeek} onChange={(event) => setDayOfWeek(event.target.value)}>
                <option value="0">Sunday</option>
                <option value="1">Monday</option>
                <option value="2">Tuesday</option>
                <option value="3">Wednesday</option>
                <option value="4">Thursday</option>
                <option value="5">Friday</option>
                <option value="6">Saturday</option>
              </Select>
            )}
            {frequency === 'monthly' && (
              <Input label="Day of month" type="number" min={1} max={31} required value={dayOfMonth} onChange={(event) => setDayOfMonth(event.target.value)} />
            )}
          </div>

          <div className="mt-4">
            <div className="mb-1.5 text-xs font-medium text-muted">Columns</div>
            <div className="grid max-h-48 grid-cols-2 gap-1.5 overflow-y-auto rounded-md border border-default bg-canvas p-2">
              {columnsQuery.data.columns.map((column) => (
                <label key={column.key} className="flex cursor-pointer items-center gap-2 rounded-sm px-1 py-1 text-sm hover:bg-elevated">
                  <Checkbox checked={selected.has(column.key)} onChange={() => toggle(column.key)} />
                  <span>{column.label}</span>
                </label>
              ))}
            </div>
            <div className="mt-1 text-xs text-muted">{selectedCount} selected · module {module}</div>
          </div>

          <Textarea
            className="mt-4"
            label="Recipients"
            required
            value={recipients}
            onChange={(event) => setRecipients(event.target.value)}
            placeholder="hr@example.com\nfinance@example.com"
            helper="Use one address per line or separate addresses with commas."
            rows={3}
          />

          {nextRun && (
            <div className="mt-3 text-xs text-muted">
              Current next run: <span className="font-mono tabular-nums">{formatDateTime(nextRun)}</span>
            </div>
          )}

          <ModalFooter>
            <Button type="button" variant="secondary" onClick={onClose} disabled={isSaving}>Cancel</Button>
            <Button type="submit" variant="primary" disabled={isSaving || selectedCount === 0} loading={isSaving}>
              {initial ? 'Save changes' : 'Create schedule'}
            </Button>
          </ModalFooter>
        </form>
      )}
    </Modal>
  );
}

function useQueryColumns(module: string, enabled: boolean) {
  return useQuery({
    queryKey: ['exports.columns', module, 'schedule'],
    queryFn: () => exportsApi.columns(module),
    enabled,
  });
}
