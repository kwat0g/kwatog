/**
 * WS-C — Frontend RBAC primitives: declarative permission gates.
 *
 *   <Can permission="x.y">…</Can>
 *   <Can permission="x.y" fallback={<Locked />}>…</Can>
 *   <Can.Any permissions={['a','b']}>…</Can.Any>
 *   <Can.All permissions={['a','b']}>…</Can.All>
 *
 * Replaces the inline `usePermission().can('x') && <Btn/>` pattern across
 * 179 sites. system_admin bypass is preserved through usePermission().
 *
 * Note: this primitive lives **alongside** usePermission(); the existing
 * hook is not deleted in this slice so the codemod can be incremental.
 */
import { type ReactNode } from 'react';
import { usePermission } from '@/hooks/usePermission';

interface CanProps {
  permission: string;
  fallback?: ReactNode;
  children: ReactNode;
}

interface CanAnyProps {
  permissions: string[];
  fallback?: ReactNode;
  children: ReactNode;
}

interface CanAllProps {
  permissions: string[];
  fallback?: ReactNode;
  children: ReactNode;
}

function CanRoot({ permission, fallback = null, children }: CanProps) {
  const { can } = usePermission();
  return can(permission) ? <>{children}</> : <>{fallback}</>;
}

function CanAny({ permissions, fallback = null, children }: CanAnyProps) {
  const { canAny } = usePermission();
  return canAny(...permissions) ? <>{children}</> : <>{fallback}</>;
}

function CanAll({ permissions, fallback = null, children }: CanAllProps) {
  const { canAll } = usePermission();
  return canAll(...permissions) ? <>{children}</> : <>{fallback}</>;
}

type CanComponent = typeof CanRoot & {
  Any: typeof CanAny;
  All: typeof CanAll;
};

export const Can = CanRoot as CanComponent;
Can.Any = CanAny;
Can.All = CanAll;
