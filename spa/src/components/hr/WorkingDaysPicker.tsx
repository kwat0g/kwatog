/**
 * Working-days picker for the employee shift assignment.
 *
 * Seven toggle chips (Monday–Sunday, ISO weekdays 1–7) plus three presets for
 * the schedules that cover nearly every plant roster. Selection is the accent;
 * everything else stays on the neutral scale. Fully keyboard operable: each chip
 * is a real button with `aria-pressed`, and the group is named by its label.
 */
import { useId } from 'react';
import { cn } from '@/lib/cn';
import { sameDays } from '@/lib/workingDays';

const DAYS = [
  { iso: 1, short: 'Mon', full: 'Monday' },
  { iso: 2, short: 'Tue', full: 'Tuesday' },
  { iso: 3, short: 'Wed', full: 'Wednesday' },
  { iso: 4, short: 'Thu', full: 'Thursday' },
  { iso: 5, short: 'Fri', full: 'Friday' },
  { iso: 6, short: 'Sat', full: 'Saturday' },
  { iso: 7, short: 'Sun', full: 'Sunday' },
] as const;

const PRESETS: Array<{ label: string; days: number[] }> = [
  { label: 'Mon–Fri', days: [1, 2, 3, 4, 5] },
  { label: 'Mon–Sat', days: [1, 2, 3, 4, 5, 6] },
  { label: 'Every day', days: [1, 2, 3, 4, 5, 6, 7] },
];

interface Props {
  value: number[];
  onChange: (days: number[]) => void;
  error?: string;
  disabled?: boolean;
}

export function WorkingDaysPicker({ value, onChange, error, disabled }: Props) {
  const labelId = useId();
  const helpId = useId();

  const toggle = (iso: number) => {
    onChange(
      value.includes(iso) ? value.filter((d) => d !== iso) : [...value, iso].sort((a, b) => a - b),
    );
  };

  return (
    <div className="flex flex-col gap-2">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span id={labelId} className="text-xs font-medium text-muted">
          Working days
        </span>
        <div className="flex items-center gap-1" role="group" aria-label="Quick schedules">
          {PRESETS.map((p) => (
            <button
              key={p.label}
              type="button"
              disabled={disabled}
              onClick={() => onChange(p.days)}
              className={cn(
                'h-6 rounded px-2 text-2xs font-medium transition-colors duration-fast',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent',
                sameDays(value, p.days)
                  ? 'bg-subtle text-primary'
                  : 'text-muted hover:bg-subtle hover:text-primary',
                'disabled:cursor-not-allowed disabled:opacity-50',
              )}
            >
              {p.label}
            </button>
          ))}
        </div>
      </div>

      <div
        role="group"
        aria-labelledby={labelId}
        aria-describedby={helpId}
        className="grid grid-cols-7 gap-1.5"
      >
        {DAYS.map((d) => {
          const on = value.includes(d.iso);

          return (
            <button
              key={d.iso}
              type="button"
              aria-pressed={on}
              aria-label={d.full}
              disabled={disabled}
              onClick={() => toggle(d.iso)}
              className={cn(
                'h-9 rounded-md border text-xs font-medium transition-colors duration-fast',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2',
                'disabled:cursor-not-allowed disabled:opacity-50',
                on
                  ? 'border-accent bg-accent text-accent-fg'
                  : 'border-default bg-canvas text-secondary hover:border-strong hover:bg-elevated',
              )}
            >
              {d.short}
            </button>
          );
        })}
      </div>

      {error ? (
        <p id={helpId} role="alert" className="text-xs text-danger-fg">
          {error}
        </p>
      ) : (
        <p id={helpId} className="text-xs text-muted">
          Time in/out on any other day needs an approved overtime request.
        </p>
      )}
    </div>
  );
}
