import { LuCircleAlert, LuCheck, LuInfo, LuLogIn, LuLogOut, LuMapPin } from '@/lib/icons';
import { RECOVERY, useTimeClock } from '@/hooks/useTimeClock';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { formatTime } from '@/lib/formatDate';

export function TimeClockCard() {
  const clock = useTimeClock();
  const { enabled, failure, result, locating, locationBlocked, cooldownSeconds } = clock;
  const { data, isLoading, isError, refetch, isFetching } = clock.status;

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
  const label = clock.pending
    ? locating
      ? 'Locating…'
      : 'Punching…'
    : locationBlocked
      ? 'Location is blocked'
      : cooldownSeconds > 0
        ? `Try again in ${cooldownSeconds}s`
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
              {clockedIn && data.earliest_out && new Date(data.earliest_out) > new Date() ? (
                <p className="mt-0.5 text-xs text-muted">
                  You can time out from{' '}
                  <span className="font-mono tabular-nums">{formatTime(data.earliest_out)}</span>.
                </p>
              ) : null}
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
            loading={clock.pending}
            disabled={!clock.canPunch && !clock.pending}
            onClick={clock.punch}
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
          {locationBlocked && !failure && (
            <div
              role="status"
              className="flex items-start gap-2 rounded-md bg-warning-bg p-3 text-sm text-warning-fg"
            >
              <LuMapPin size={16} className="mt-0.5 shrink-0" aria-hidden />
              <div>
                <p className="font-medium">Location access is blocked for this site.</p>
                <p className="mt-1">{RECOVERY.geo_denied}</p>
              </div>
            </div>
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
          {locationBlocked && (
            <Button
              variant="secondary"
              size="sm"
              className="self-start"
              onClick={clock.recheckLocation}
            >
              Check again
            </Button>
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
