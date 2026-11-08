export type AuthSessionRealm = 'internal' | 'customer' | 'supplier' | 'all';
export type AuthSessionStage = 'changing' | 'changed';

export interface AuthSessionSignal {
  realm: AuthSessionRealm;
  stage: AuthSessionStage;
  nonce: string;
}

const CHANNEL_NAME = 'ogami-auth-session';
const STORAGE_KEY = 'ogami:auth-session-signal';
const AUTH_CACHE_SCOPE_KEY = 'ogami:auth-cache-scope:v1';
const AUTH_CACHE_SCOPE_MAX_AGE_MS = 30 * 24 * 60 * 60 * 1000;
const LOCAL_DRAFT_PREFIXES = [
  'ogami:formdraft:',
  'ogami:delivery-trace:',
  'ogami:delivery-attempt:',
  'ogami:truck-return-receipt:',
  'ogami:delivery-recovery:',
  'ogami:inventory:material-return:',
] as const;
const SESSION_DRAFT_PREFIXES = ['ogami:pending-production:'] as const;
const SESSION_SCOPED_KEYS = new Set(['ogami.recovery.operation_id']);
const listeners = new Set<(signal: AuthSessionSignal) => void>();
const recentNonces = new Set<string>();
let channel: BroadcastChannel | null = null;
let listening = false;
let mutationTail: Promise<void> = Promise.resolve();
let authSessionDraftGeneration = 0;

function isSignal(value: unknown): value is AuthSessionSignal {
  if (typeof value !== 'object' || value === null) return false;
  const signal = value as Partial<AuthSessionSignal>;
  return (
    (signal.realm === 'internal' || signal.realm === 'customer' || signal.realm === 'supplier' || signal.realm === 'all') &&
    (signal.stage === 'changing' || signal.stage === 'changed') &&
    typeof signal.nonce === 'string' &&
    signal.nonce.length > 0 &&
    signal.nonce.length <= 100
  );
}

function dispatch(value: unknown): void {
  if (!isSignal(value) || recentNonces.has(value.nonce)) return;
  recentNonces.add(value.nonce);
  if (recentNonces.size > 32) recentNonces.delete(recentNonces.values().next().value as string);
  for (const listener of [...listeners]) {
    try {
      listener(value);
    } catch (error) {
      console.error('Auth-session cache invalidation listener failed.', error);
    }
  }
}

function installTransport(): void {
  if (listening || typeof window === 'undefined') return;
  listening = true;

  if (typeof BroadcastChannel !== 'undefined') {
    try {
      channel = new BroadcastChannel(CHANNEL_NAME);
      channel.onmessage = (event: MessageEvent<unknown>) => dispatch(event.data);
    } catch {
      channel = null;
    }
  }

  // Keep this fallback listener even when BroadcastChannel works so a failed
  // postMessage can fall back without dropping the peer invalidation.
  window.addEventListener('storage', (event: StorageEvent) => {
    if (event.key !== STORAGE_KEY || !event.newValue) return;
    try {
      dispatch(JSON.parse(event.newValue) as unknown);
    } catch {
      // Ignore malformed or unrelated same-origin storage values.
    }
  });
}

export function subscribeAuthSessionSignal(listener: (signal: AuthSessionSignal) => void): () => void {
  installTransport();
  listeners.add(listener);
  return () => listeners.delete(listener);
}

/** Broadcast only the affected guard and lifecycle stage; never identity data. */
export function publishAuthSessionSignal(realm: AuthSessionRealm, stage: AuthSessionStage): void {
  installTransport();
  const nonce = typeof crypto !== 'undefined' && 'randomUUID' in crypto
    ? crypto.randomUUID()
    : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
  const signal: AuthSessionSignal = { realm, stage, nonce };

  if (channel) {
    try {
      channel.postMessage(signal);
      return;
    } catch {
      // Fall back to a transient storage event when the channel is unavailable.
    }
  }

  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(signal));
    localStorage.removeItem(STORAGE_KEY);
  } catch {
    // Restricted browser storage disables cross-tab notifications; API auth
    // remains authoritative in each tab.
  }
}

/** Serialize cookie-backed session mutations across the SPA's auth realms. */
export function serializeAuthSessionMutation<T>(mutation: () => Promise<T>): Promise<T> {
  const result = mutationTail.then(mutation, mutation);
  mutationTail = result.then(() => undefined, () => undefined);
  return result;
}

/** Current lifecycle generation, used to prevent an old form unmount re-saving cleared data. */
export function getAuthSessionDraftGeneration(): number {
  return authSessionDraftGeneration;
}

/** Remove persisted drafts, retry payloads, and session-scoped state on auth changes. */
export function clearAuthSessionDrafts(): void {
  authSessionDraftGeneration += 1;

  try {
    Object.keys(localStorage)
      .filter((key) => LOCAL_DRAFT_PREFIXES.some((prefix) => key.startsWith(prefix)))
      .forEach((key) => localStorage.removeItem(key));
    localStorage.removeItem(AUTH_CACHE_SCOPE_KEY);
  } catch {
    // Storage can be unavailable in restricted browser contexts.
  }

  try {
    Object.keys(sessionStorage)
      .filter((key) => SESSION_DRAFT_PREFIXES.some((prefix) => key.startsWith(prefix)) || SESSION_SCOPED_KEYS.has(key))
      .forEach((key) => sessionStorage.removeItem(key));
  } catch {
    // Session storage can be unavailable in restricted browser contexts.
  }
}

/**
 * Detect an access-scope change that happened while the SPA was closed.
 * The marker contains no record data and only triggers cleanup; API policies
 * remain authoritative for every read and retry.
 */
export function reconcileAuthSessionCacheScope(userId: string, scopeKey: string): void {
  if (typeof window === 'undefined') return;

  let previous: { userId: string; scopeKey: string; writtenAt: number } | null = null;
  let missingMarker = false;
  let invalidMarker = false;
  try {
    const raw = window.localStorage.getItem(AUTH_CACHE_SCOPE_KEY);
    missingMarker = raw === null;
    if (raw !== null) {
      const parsed: unknown = JSON.parse(raw);
      if (
        typeof parsed === 'object' && parsed !== null &&
        typeof (parsed as { userId?: unknown }).userId === 'string' &&
        typeof (parsed as { scopeKey?: unknown }).scopeKey === 'string' &&
        typeof (parsed as { writtenAt?: unknown }).writtenAt === 'number' &&
        Number.isFinite((parsed as { writtenAt: number }).writtenAt) &&
        (parsed as { writtenAt: number }).writtenAt <= Date.now() &&
        Date.now() - (parsed as { writtenAt: number }).writtenAt <= AUTH_CACHE_SCOPE_MAX_AGE_MS
      ) {
        previous = parsed as { userId: string; scopeKey: string; writtenAt: number };
      } else {
        invalidMarker = true;
      }
    }
  } catch {
    invalidMarker = true;
  }

  const hasUnscopedPersistedState = missingMarker && (() => {
    try {
      return Object.keys(window.localStorage).some((key) => LOCAL_DRAFT_PREFIXES.some((prefix) => key.startsWith(prefix))) ||
        Object.keys(window.sessionStorage).some((key) => SESSION_DRAFT_PREFIXES.some((prefix) => key.startsWith(prefix)) || SESSION_SCOPED_KEYS.has(key));
    } catch {
      return false;
    }
  })();

  if (
    invalidMarker ||
    hasUnscopedPersistedState ||
    (previous !== null && (previous.userId !== userId || previous.scopeKey !== scopeKey))
  ) {
    clearAuthSessionDrafts();
  }

  try {
    window.localStorage.setItem(AUTH_CACHE_SCOPE_KEY, JSON.stringify({ userId, scopeKey, writtenAt: Date.now() }));
  } catch {
    // Storage may be blocked; in-memory QueryClient cleanup still applies.
  }
}
