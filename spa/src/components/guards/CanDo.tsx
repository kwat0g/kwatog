import type { ReactNode } from 'react';
import { usePermission } from '@/hooks/usePermission';

/**
 * Series R — Task R3.
 *
 * Component-level permission gate. Renders `children` when the current user
 * has the required permission(s); otherwise renders `fallback` (default null).
 *
 *   <CanDo permission="hr.employees.delete">
 *     <Button variant="danger">Delete</Button>
 *   </CanDo>
 *
 *   <CanDo
 *     permission="payroll.periods.finalize"
 *     fallback={<Button disabled title="No permission">Finalize</Button>}
 *   >
 *     <Button variant="primary">Finalize</Button>
 *   </CanDo>
 *
 *   <CanDo permission={['accounting.bills.view', 'accounting.invoices.view']}>
 *     <SidebarItem to="/accounting" />
 *   </CanDo>
 *
 *   <CanDo permission={['hr.employees.edit', 'hr.employees.view_sensitive']} requireAll>
 *     <SensitiveEditPanel />
 *   </CanDo>
 *
 * Backend remains the source of truth — this is UX only. The server still
 * 403s if a stale frontend lets a forbidden action through.
 */
export interface CanDoProps {
  permission: string | string[];
  /** When true, requires every permission in the array. Default: false (any). */
  requireAll?: boolean;
  /** What to show when the user lacks the permission. Default: null. */
  fallback?: ReactNode;
  children: ReactNode;
}

export function CanDo({
  permission,
  requireAll = false,
  fallback = null,
  children,
}: CanDoProps) {
  const { can, canAny, canAll } = usePermission();
  const perms = Array.isArray(permission) ? permission : [permission];

  if (perms.length === 0) {
    return <>{children}</>;
  }
  if (perms.length === 1) {
    return can(perms[0]) ? <>{children}</> : <>{fallback}</>;
  }
  const has = requireAll ? canAll(...perms) : canAny(...perms);
  return has ? <>{children}</> : <>{fallback}</>;
}
