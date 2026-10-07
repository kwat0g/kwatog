/**
 * Shared time in/out logic — the self-service card and the dashboard quick
 * action both run on this, so there is exactly one punch flow.
 *
 * Spam guard (why this is more than a mutation): the browser re-asks for the
 * location on every call, so a blocked location used to give an error toast per
 * tap, and an out-of-range employee could hammer the API. Three layers:
 *   1. one punch in flight at a time;
 *   2. a short cooldown after ANY failed attempt;
 *   3. while location access is denied the button stays off, and comes back by
 *      itself when the browser permission changes (or on "Check again" where the
 *      Permissions API is not available).
 * Toasts share one id so a repeat replaces the last one instead of stacking.
 */
import { useCallback, useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { punchApi } from '@/api/attendance/punch';
import { useFeature } from '@/hooks/useFeature';
import { useAuthStore } from '@/stores/authStore';
import { formatTime } from '@/lib/formatDate';
import type { GpsFix, PunchResult } from '@/types/attendance';

/** Pause after a failed attempt before the button works again. */
export const FAILURE_COOLDOWN_MS = 3000;
const TOAST_ID = 'time-clock';

/** A failure with a stable code, so the UI can say what to do next. */
export class PunchFailure extends Error {
  constructor(
    message: string,
    readonly code: string,
  ) {
    super(message);
  }
}

export interface TimeClockFailure {
  message: string;
  code: string | null;
}

const GEO_ERRORS: Record<number, [message: string, code: string]> = {
  1: [
    'Allow location access for this site in your browser settings, then try again.',
    'geo_denied',
  ],
  2: [
    'Your location could not be determined. Move to an open area and try again.',
    'geo_unavailable',
  ],
  3: ['Locating you took too long. Try again.', 'geo_timeout'],
};

/** What to do about each refusal. The message says what happened; this says what next. */
export const RECOVERY: Record<string, string> = {
  geo_denied: 'On a phone: browser menu → Site settings → Location → Allow.',
  geo_unavailable: 'GPS works best outdoors or next to a window.',
  out_of_range:
    'Move closer to the plant. If you are on site and still blocked, ask HR to record your time.',
  low_accuracy: 'Step outdoors or near a window, wait a few seconds, then try again.',
  outside_window:
    'Time in/out opens shortly before your shift. If you worked outside it, ask HR to record it.',
  no_shift: 'HR needs to assign you a shift before you can punch.',
  too_soon: 'Wait a minute, then check your punches below.',
  manual_entry: 'Contact HR to correct this day.',
  on_leave: 'Contact HR to correct this day.',
  row_removed: 'Contact HR to correct this day.',
  out_before_in: 'Contact HR to correct this day.',
};

function readFix(): Promise<GpsFix> {
  return new Promise((resolve, reject) => {
    if (!('geolocation' in navigator)) {
      reject(new PunchFailure('This device cannot provide a location.', 'geo_unavailable'));
      return;
    }
    navigator.geolocation.getCurrentPosition(
      (p) =>
        resolve({ lat: p.coords.latitude, lng: p.coords.longitude, accuracy: p.coords.accuracy }),
      (e) => {
        const [message, code] = GEO_ERRORS[e.code] ?? GEO_ERRORS[2];
        reject(new PunchFailure(message, code));
      },
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
    );
  });
}

export function describeFailure(error: unknown): TimeClockFailure {
  if (error instanceof PunchFailure) return { message: error.message, code: error.code };
  const data = (error as { response?: { data?: { message?: string; code?: string } } })?.response
    ?.data;
  if (data?.message) return { message: data.message, code: data.code ?? null };

  return {
    message: error instanceof Error ? error.message : 'Could not record your punch.',
    code: null,
  };
}

type PermissionState = 'granted' | 'denied' | 'prompt' | 'unknown';

interface Options {
  /** Dashboard use: hide for accounts with no linked employee (e.g. system admin). */
  requireEmployee?: boolean;
}

export function useTimeClock({ requireEmployee = false }: Options = {}) {
  const featureOn = useFeature('attendance');
  const hasEmployee = useAuthStore((s) => s.user?.employee != null);
  const enabled = featureOn && (!requireEmployee || hasEmployee);
  const qc = useQueryClient();

  const [failure, setFailure] = useState<TimeClockFailure | null>(null);
  const [result, setResult] = useState<PunchResult | null>(null);
  const [locating, setLocating] = useState(false);
  const [cooldownUntil, setCooldownUntil] = useState(0);
  const [now, setNow] = useState(() => Date.now());
  const [permission, setPermission] = useState<PermissionState>('unknown');
  /** Fallback for browsers without the Permissions API: a refusal blocks until "Check again". */
  const [deniedByAttempt, setDeniedByAttempt] = useState(false);

  const status = useQuery({
    queryKey: ['attendance', 'punch', 'status'],
    queryFn: punchApi.status,
    enabled,
  });

  // Follow the browser's location permission, so the button recovers on its own.
  useEffect(() => {
    if (!enabled || typeof navigator === 'undefined' || !navigator.permissions?.query) return;
    let cancelled = false;
    let watched: PermissionStatus | null = null;

    navigator.permissions
      .query({ name: 'geolocation' as PermissionName })
      .then((s) => {
        if (cancelled) return;
        watched = s;
        const sync = () => {
          setPermission(s.state as PermissionState);
          if (s.state === 'granted') setDeniedByAttempt(false);
        };
        sync();
        s.onchange = sync;
      })
      .catch(() => {});

    return () => {
      cancelled = true;
      if (watched) watched.onchange = null;
    };
  }, [enabled]);

  // Tick only while a cooldown is running.
  useEffect(() => {
    if (cooldownUntil <= Date.now()) return;
    const id = setInterval(() => {
      const t = Date.now();
      setNow(t);
      if (t >= cooldownUntil) clearInterval(id);
    }, 250);

    return () => clearInterval(id);
  }, [cooldownUntil]);

  const mutation = useMutation({
    mutationFn: async () => {
      const fix = await readFix();
      setLocating(false);

      return punchApi.punch(fix);
    },
    onMutate: () => {
      setFailure(null);
      setResult(null);
      setLocating(true);
    },
    onSuccess: (r) => {
      setResult(r);
      toast.success(`Timed ${r.direction} at ${formatTime(r.punched_at)}`, { id: TOAST_ID });
      qc.invalidateQueries({ queryKey: ['attendance'] });
      qc.invalidateQueries({ queryKey: ['self-service'] });
    },
    onError: (e) => {
      const described = describeFailure(e);
      setFailure(described);
      if (described.code === 'geo_denied') setDeniedByAttempt(true);
      const t = Date.now();
      setNow(t);
      setCooldownUntil(t + FAILURE_COOLDOWN_MS);
      const hint = described.code ? RECOVERY[described.code] : undefined;
      toast.error(hint ? `${described.message} ${hint}` : described.message, {
        id: TOAST_ID,
        duration: 6000,
      });
    },
    onSettled: () => setLocating(false),
  });

  const locationBlocked = permission === 'denied' || deniedByAttempt;
  const cooldownSeconds = Math.max(0, Math.ceil((cooldownUntil - now) / 1000));
  const canPunch = enabled && !mutation.isPending && !locationBlocked && cooldownSeconds === 0;

  const punch = useCallback(() => {
    if (!canPunch) return;
    mutation.mutate();
  }, [canPunch, mutation]);

  /** "Check again": re-read the permission, or allow one more attempt where it cannot be read. */
  const recheckLocation = useCallback(() => {
    setDeniedByAttempt(false);
    setFailure(null);
    if (typeof navigator !== 'undefined' && navigator.permissions?.query) {
      navigator.permissions
        .query({ name: 'geolocation' as PermissionName })
        .then((s) => setPermission(s.state as PermissionState))
        .catch(() => {});
    }
  }, []);

  return {
    enabled,
    status,
    punch,
    pending: mutation.isPending,
    locating,
    failure,
    result,
    locationBlocked,
    cooldownSeconds,
    canPunch,
    recheckLocation,
  };
}
