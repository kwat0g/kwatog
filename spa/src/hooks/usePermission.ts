import { useAuthStore } from '@/stores/authStore';

export function usePermission() {
  const permissions = useAuthStore((s) => s.permissions);
  const user = useAuthStore((s) => s.user);
  const isAdmin = user?.is_superuser === true;

  const can = (slug: string) => isAdmin || permissions.has(slug);
  const canAny = (...slugs: string[]) => slugs.some((s) => can(s));
  const canAll = (...slugs: string[]) => slugs.every((s) => can(s));

  return { can, canAny, canAll, isAdmin };
}
