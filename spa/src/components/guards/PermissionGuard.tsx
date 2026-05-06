import { type ReactNode } from 'react';
import { usePermission } from '@/hooks/usePermission';
import ForbiddenPage from '@/pages/error/Forbidden';

interface PermissionGuardProps {
  permission: string;
  children: ReactNode;
}

/**
 * Route-level guard. Renders the proper /403 page when the user is
 * authenticated but lacks the permission, instead of the previous inline
 * EmptyState that looked like a list-empty rather than a denial.
 */
export function PermissionGuard({ permission, children }: PermissionGuardProps) {
  const { can } = usePermission();

  if (!can(permission)) {
    return <ForbiddenPage permission={permission} />;
  }

  return <>{children}</>;
}
