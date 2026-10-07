/**
 * Time in/out quick action — rendered by DashboardShell in every role
 * dashboard's header.
 *
 * One tap runs the same GPS punch as the self-service card (shared
 * `useTimeClock`), and the label follows the employee's real state. It renders
 * nothing for accounts with no linked employee (e.g. system admin), when the
 * Attendance module is off, or until the status has loaded.
 *
 * When location access is blocked the button turns into a "Location blocked"
 * control that re-checks the permission instead of asking the browser again,
 * so tapping it cannot spam location prompts or error toasts.
 */
import { LuLogIn, LuLogOut, LuMapPin } from '@/lib/icons';
import { useTimeClock } from '@/hooks/useTimeClock';
import { Button } from '@/components/ui/Button';

export function TimeClockQuickAction() {
  const clock = useTimeClock({ requireEmployee: true });
  const data = clock.status.data;
  if (!clock.enabled || !data) return null;

  if (clock.locationBlocked) {
    return (
      <Button
        variant="secondary"
        className="min-h-11 sm:min-h-0"
        icon={<LuMapPin size={14} aria-hidden />}
        onClick={clock.recheckLocation}
        title="Location access is blocked for this site. Allow it in your browser settings, then tap to check again."
      >
        Location blocked
      </Button>
    );
  }

  const clockedIn = data.next_action === 'out';
  const label = clock.pending
    ? clock.locating
      ? 'Locating…'
      : 'Punching…'
    : clock.cooldownSeconds > 0
      ? `Try again in ${clock.cooldownSeconds}s`
      : clockedIn
        ? 'Time out'
        : 'Time in';

  return (
    <Button
      variant="primary"
      className="min-h-11 sm:min-h-0"
      icon={clockedIn ? <LuLogOut size={14} aria-hidden /> : <LuLogIn size={14} aria-hidden />}
      loading={clock.pending}
      disabled={!clock.canPunch && !clock.pending}
      onClick={clock.punch}
    >
      {label}
    </Button>
  );
}
