import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { LuCircleAlert, LuCheck, LuInfo, LuLogIn, LuLogOut } from '@/lib/icons';
import { punchApi } from '@/api/attendance/punch';
import { useFeature } from '@/hooks/useFeature';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { formatTime } from '@/lib/formatDate';
import type { GpsFix, PunchResult } from '@/types/attendance';

/** A failure with a stable code, so the card can say what to do next. */
class PunchFailure extends Error {
  constructor(
    message: string,
    readonly code: string,
  ) {
    super(message);
  }
}

const GEO_ERRORS: Record<number, [message: string, code: string]> = {
  1: [
    'Allow location access for this site in your browser settings, then try again.',
    'geo_denied',
  ],
  2: [
    'Your location could not be determined. Move to an open area and try again.',
    'geo_unavailable',
  ],
  3: ['Locating you took too long. Try again.', 'geo_timeout'],
};

/** What to do about each refusal. The server message says what happened; this says what next. */
const RECOVERY: Record<string, string> = {
  geo_denied: 'On a phone: browser menu → Site settings → Location → Allow.',
  geo_unavailable: 'GPS works best outdoors or next to a window.',
  out_of_range:
    'Move closer to the plant. If you are on site and still blocked, ask HR to record your time.',
  low_accuracy: 'Step outdoors or near a window, wait a few seconds, then try again.',
  outside_window:
    'Time in/out opens shortly before your shift. If you worked outside it, ask HR to record it.',
  no_shift: 'HR needs to assign you a shift before you can punch.',
  too_soon: 'Wait a minute, then check your punches below.',
  manual_entry: 'Contact HR to correct this day.',
  on_leave: 'Contact HR to correct this day.',
  row_removed: 'Contact HR to correct this day.',
  out_before_in: 'Contact HR to correct this day.',
};

function readFix(): Promise<GpsFix> {
  return new Promise((resolve, reject) => {
    if (!('geolocation' in navigator)) {
      reject(new PunchFailure('This device cannot provide a location.', 'geo_unavailable'));
      return;
    }
    navigator.geolocation.getCurrentPosition(
      (p) =>
        resolve({ lat: p.coords.latitude, lng: p.coords.longitude, accuracy: p.coords.accuracy }),
      (e) => {
        const [message, code] = GEO_ERRORS[e.code] ?? GEO_ERRORS[2];
        reject(new PunchFailure(message, code));
      },
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
    );
  });
}

function describeFailure(error: unknown): { message: string; code: string | null } {
  if (error instanceof PunchFailure) return { message: error.message, code: error.code };
  const data = (error as { response?: { data?: { message?: string; code?: string } } })?.response
    ?.data;
  if (data?.message) return { message: data.message, code: data.code ?? null };

  return {
    message: error instanceof Error ? error.message : 'Could not record your punch.',
    code: null,
  };
}

export function TimeClockCard() {
  const enabled = useFeature('attendance');
  const qc = useQueryClient();
  const [failure, setFailure] = useState<{ message: string; code: string | null } | null>(null);
  const [result, setResult] = useState<PunchResult | null>(null);
  const [locating, setLocating] = useState(false);

  const { data, isLoading, isError, refetch, isFetching } = useQuery({
    queryKey: ['attendance', 'punch', 'status'],
    queryFn: punchApi.status,
    enabled,
  });

  const punch = useMutation({
    mutationFn: async () => {
      const fix = await readFix();
      setLocating(false);

      return punchApi.punch(fix);
    },
    onMutate: () => {
      setFailure(null);
      setResult(null);
      setLocating(true);
    },
    onSuccess: (r) => {
      setResult(r);
      toast.success(r.direction === 'in' ? 'Timed in' : 'Timed out');
      qc.invalidateQueries({ queryKey: ['attendance'] });
      qc.invalidateQueries({ queryKey: ['self-service'] });
    },
    onError: (e) => {
      const described = describeFailure(e);
      setFailure(described);
      toast.error(described.message);
    },
    onSettled: () => setLocating(false),
  });

  if (!enabled) return null;

  if (isLoading) {
    return (
      <Panel title="Time in / out">
        <div className="flex flex-col gap-4" aria-busy="true" aria-label="Loading your time clock">
          <SkeletonBlock className="h-10 w-2/3" />
          <SkeletonBlock className="h-14 w-full" />
          <SkeletonBlock className="h-20 w-full" />
        </div>
      </Panel>
    );
  }

  if (isError || !data) {
    return (
      <Panel title="Time in / out">
        <div className="flex flex-col items-start gap-3">
          <p className="text-sm text-secondary">We could not load your time clock.</p>
          <Button variant="secondary" size="sm" loading={isFetching} onClick={() => refetch()}>
            Try again
          </Button>
        </div>
      </Panel>
    );
  }

  const clockedIn = data.next_action === 'out';
  const lastIn = [...data.punches].reverse().find((p) => p.direction === 'in');
  const label = punch.isPending
    ? locating
      ? 'Locating…'
      : 'Punching…'
    : clockedIn
      ? 'Time out'
      : 'Time in';

  return (
    <Panel title="Time in / out">
      <div className="grid gap-x-8 gap-y-4 md:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]">
        <div className="flex flex-col gap-4">
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0">
              <p className="text-base font-medium text-primary">
                {clockedIn && lastIn ? (
                  <>
                    Clocked in since{' '}
                    <span className="font-mono tabular-nums">{formatTime(lastIn.punched_at)}</span>
                  </>
                ) : (
                  'Not clocked in'
                )}
              </p>
              <p className="mt-0.5 text-xs text-muted">
                {data.shift ? (
                  <>
                    {data.shift.name} shift ·{' '}
                    <span className="font-mono tabular-nums">
                      {formatTime(data.shift.start)}–{formatTime(data.shift.end)}
                    </span>
                  </>
                ) : (
                  'No shift assigned. Ask HR to set one.'
                )}
              </p>
            </div>
            {data.tardiness_minutes ? (
              <Chip variant="warning">Late by {data.tardiness_minutes} min</Chip>
            ) : null}
          </div>

          <Button
            variant="primary"
            size="touch"
            className="w-full min-h-14 text-lg"
            icon={
              clockedIn ? <LuLogOut size={20} aria-hidden /> : <LuLogIn size={20} aria-hidden />
            }
            loading={punch.isPending}
            onClick={() => punch.mutate()}
          >
            {label}
          </Button>

          {/* Screen readers hear the outcome; absolutely positioned, so it adds no gap. */}
          <p className="sr-only" aria-live="polite">
            {result && !failure
              ? `Timed ${result.direction} at ${formatTime(result.punched_at)}, ${Math.round(Number(result.distance_m))} metres from the plant centre.`
              : ''}
          </p>
          {result && !failure && (
            <p className="flex items-start gap-2 text-sm text-success-fg">
              <LuCheck size={16} className="mt-0.5 shrink-0" aria-hidden />
              <span>
                Timed {result.direction} at{' '}
                <span className="font-mono tabular-nums">{formatTime(result.punched_at)}</span>
                {' · '}
                <span className="font-mono tabular-nums">
                  {Math.round(Number(result.distance_m))} m
                </span>{' '}
                from the plant centre.
              </span>
            </p>
          )}
          {failure && (
            <div
              role="alert"
              className="flex items-start gap-2 rounded-md bg-danger-bg p-3 text-sm text-danger-fg"
            >
              <LuCircleAlert size={16} className="mt-0.5 shrink-0" aria-hidden />
              <div>
                <p className="font-medium">{failure.message}</p>
                {failure.code && RECOVERY[failure.code] && (
                  <p className="mt-1">{RECOVERY[failure.code]}</p>
                )}
              </div>
            </div>
          )}
        </div>

        <div className="md:pt-0.5">
          <h4 className="mb-1.5 text-xs font-medium text-muted">Today</h4>
          {data.punches.length === 0 ? (
            <p className="text-sm text-muted">No punches yet today.</p>
          ) : (
            <ol className="divide-y divide-subtle rounded-md border border-default">
              {data.punches.map((p, i) => (
                <li
                  key={`${p.punched_at}-${i}`}
                  className="flex h-9 items-center justify-between px-3 text-sm"
                >
                  <span className="inline-flex items-center gap-2 text-secondary">
                    {p.direction === 'in' ? (
                      <LuLogIn size={14} aria-hidden />
                    ) : (
                      <LuLogOut size={14} aria-hidden />
                    )}
                    {p.direction === 'in' ? 'Time in' : 'Time out'}
                  </span>
                  <span className="font-mono tabular-nums text-primary">
                    {formatTime(p.punched_at)}
                  </span>
                </li>
              ))}
            </ol>
          )}
        </div>

        <p className="flex items-start gap-1.5 text-xs text-muted md:col-span-2">
          <LuInfo size={13} className="mt-px shrink-0" aria-hidden />
          <span>
            Your location is read only when you tap, and must be within{' '}
            <span className="font-mono tabular-nums">{data.radius_m} m</span> of the plant.
          </span>
        </p>
      </div>
    </Panel>
  );
}
