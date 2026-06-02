import { Chip } from './Chip';
import { cn } from '@/lib/cn';

interface UserBadgeProps {
  name: string;
  role?: { name: string; slug?: string } | null;
  className?: string;
  /** When false, skip the role chip entirely. */
  showRole?: boolean;
}

/**
 * ADV4 — renders a user's display name with their role chip beside it.
 *
 * Used in approvals, activity feed, and audit logs to surface 'who did what,
 * acting as which role'. Keeps the styling consistent across the app so the
 * compliance auditor sees the same shape everywhere.
 */
export function UserBadge({ name, role, className, showRole = true }: UserBadgeProps) {
  return (
    <span className={cn('inline-flex items-center gap-1.5', className)}>
      <span className="text-primary min-w-0 truncate">{name}</span>
      {showRole && role && <Chip variant="neutral">{role.name}</Chip>}
    </span>
  );
}
