const LEGACY_FORM_DRAFT_STORAGE_PREFIX = 'ogami:formdraft:';
export const FORM_DRAFT_STORAGE_PREFIX = 'ogami:formdraft:v2:';
export const FORM_DRAFT_TTL_MS = 7 * 24 * 60 * 60 * 1000;
const FUTURE_TIMESTAMP_TOLERANCE_MS = 5 * 60 * 1000;
const FORM_DRAFT_PRUNE_INTERVAL_MS = 24 * 60 * 60 * 1000;

interface FormDraftScope {
  is_active: boolean;
  role?: { id?: string; slug?: string } | null;
  employee: { id: string; department_id: string | null } | null;
  permissions: string[];
  delegated_approvals: string[];
  features: string[];
}

/** Non-cryptographic fingerprint used only to isolate local draft namespaces. */
export function formDraftScopeFingerprint(user: FormDraftScope): string {
  const scope = JSON.stringify({
    role: [user.role?.id ?? null, user.role?.slug ?? null],
    employee: user.employee ? [user.employee.id, user.employee.department_id] : null,
    is_active: user.is_active,
    permissions: [...(user.permissions ?? [])].sort(),
    delegated_approvals: [...(user.delegated_approvals ?? [])].sort(),
    features: [...(user.features ?? [])].sort(),
  });

  let hash = 14695981039346656037n;
  const prime = 1099511628211n;
  const mask = 0xffffffffffffffffn;
  for (let index = 0; index < scope.length; index += 1) {
    hash ^= BigInt(scope.charCodeAt(index));
    hash = (hash * prime) & mask;
  }

  return hash.toString(36);
}

export function formDraftStorageKey(userId: string, scope: string, formKey: string): string {
  return `${FORM_DRAFT_STORAGE_PREFIX}${encodeURIComponent(userId)}:scope-${scope}:${formKey}`;
}

/** Keep only this user's current-scope drafts; legacy keys have no scope proof. */
export function clearStaleFormDraftScopes(userId: string, scope: string): void {
  if (typeof window === 'undefined') return;

  const encodedUserId = encodeURIComponent(userId);
  const currentPrefix = `${FORM_DRAFT_STORAGE_PREFIX}${encodedUserId}:scope-${scope}:`;
  const currentUserPrefix = `${FORM_DRAFT_STORAGE_PREFIX}${encodedUserId}:`;
  const legacyUserPrefix = `${LEGACY_FORM_DRAFT_STORAGE_PREFIX}${encodedUserId}:`;

  try {
    for (const key of Object.keys(window.localStorage)) {
      if (key.startsWith(legacyUserPrefix)
        || (key.startsWith(currentUserPrefix) && !key.startsWith(currentPrefix))) {
        window.localStorage.removeItem(key);
      }
    }
  } catch {
    // Storage can be unavailable in restricted browser contexts.
  }
}

/** Remove expired ordinary form drafts without touching pending-operation journals. */
export function pruneExpiredFormDrafts(now = Date.now()): void {
  if (typeof window === 'undefined') return;

  try {
    for (const key of Object.keys(window.localStorage)) {
      const isCurrentFormat = key.startsWith(FORM_DRAFT_STORAGE_PREFIX);
      const isLegacyFormat = key.startsWith(LEGACY_FORM_DRAFT_STORAGE_PREFIX) && !isCurrentFormat;
      if (!isCurrentFormat && !isLegacyFormat) continue;

      try {
        const raw = window.localStorage.getItem(key);
        if (!raw) continue;
        const value = JSON.parse(raw) as { ts?: unknown; values?: unknown } | null;

        // Other app-owned entries share this prefix for historical reasons.
        // Only delete a recognized autosave envelope; retry journals must survive.
        if (!value || typeof value !== 'object' || !Object.prototype.hasOwnProperty.call(value, 'values')) continue;

        const hasValuesObject = Boolean(value.values)
          && typeof value.values === 'object'
          && !Array.isArray(value.values);
        const timestamp = typeof value.ts === 'number' && Number.isFinite(value.ts) ? value.ts : null;
        const expired = timestamp !== null && now - timestamp > FORM_DRAFT_TTL_MS;
        const tooFarInFuture = timestamp !== null && timestamp > now + FUTURE_TIMESTAMP_TOLERANCE_MS;

        if (isLegacyFormat || !hasValuesObject || timestamp === null || expired || tooFarInFuture) {
          window.localStorage.removeItem(key);
        }
      } catch {
        // A form-specific reader can handle malformed/unknown storage later.
      }
    }
  } catch {
    // Restricted browser storage must not prevent the SPA from starting.
  }
}

/** Run the local-only expiry sweep at startup and daily for long-lived floor tabs. */
export function startFormDraftExpiryPruner(): void {
  if (typeof window === 'undefined') return;
  pruneExpiredFormDrafts();
  window.setInterval(pruneExpiredFormDrafts, FORM_DRAFT_PRUNE_INTERVAL_MS);
}
