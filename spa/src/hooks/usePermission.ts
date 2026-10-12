import { useAuthStore } from '@/stores/authStore';

export function usePermission() {
 const permissions = useAuthStore((s) => s.permissions);
 const user = useAuthStore((s) => s.user);
 const isAdmin = user?.role.slug === 'system_admin';

 const can = (slug: string) => isAdmin || permissions.has(slug);
 const canApprove = (slug: string) => can(slug) || user?.delegated_approvals?.includes(slug) === true;
 const canAny = (...slugs: string[]) => slugs.some((s) => can(s));
 const canAll = (...slugs: string[]) => slugs.every((s) => can(s));

 return { can, canApprove, canAny, canAll, isAdmin };
}
