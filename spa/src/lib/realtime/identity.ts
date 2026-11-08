type RealtimeIdentityUser = {
  id: string;
  is_active?: boolean;
  role?: { id?: string; slug?: string } | null;
  employee?: { id?: string; department_id?: string | null } | null;
  permissions?: unknown;
  delegated_approvals?: unknown;
  features?: unknown;
} | null;

function sortedValues(value: unknown): string[] {
  if (typeof value === 'string') return value ? [value] : [];
  if (value === null || value === undefined || typeof value !== 'object') return [];

  try {
    const iterator = (value as { [Symbol.iterator]?: unknown })[Symbol.iterator];
    if (typeof iterator !== 'function') return [];
    return Array.from(value as Iterable<unknown>, (item) => String(item)).sort();
  } catch {
    return [];
  }
}

/** Build a stable key even while legacy/test identities are partially hydrated. */
export function realtimeIdentityKey(user: RealtimeIdentityUser): string {
  if (!user) return 'guest';
  return [
    user.id,
    user.is_active === false ? 'inactive' : 'active',
    user.role?.id ?? '',
    user.role?.slug ?? '',
    user.employee?.id ?? '',
    user.employee?.department_id ?? '',
    sortedValues(user.permissions).join(','),
    sortedValues(user.delegated_approvals).join(','),
    sortedValues(user.features).join(','),
  ].join(':');
}
