import { Bell } from 'lucide-react';
import { Tooltip } from '@/components/ui/Tooltip';

/**
 * Notification dropdown shell — full implementation lands in Task 77.
 */
export function NotificationBell() {
  return (
    <Tooltip content="Notifications">
      <button
        type="button"
        aria-label="Notifications"
        className="h-7 w-7 inline-flex items-center justify-center rounded-md text-muted hover:bg-elevated hover:text-primary"
      >
        <Bell size={14} />
      </button>
    </Tooltip>
  );
}
