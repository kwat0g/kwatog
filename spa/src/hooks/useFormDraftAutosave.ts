// Series X / Task X2 — auto-save form drafts to localStorage.
//
// **Security:** sensitive fields are NEVER persisted. The default blocklist
// matches anything that looks like a government ID, banking detail, salary,
// or password (case-insensitive). Pages may extend the blocklist via the
// `blocklist` option.
//
// Usage inside a form:
//
//   const { hasDraft, draftAge, restore, discard } = useFormDraftAutosave({
//     formKey: 'hr.employees.create',
//     getValues: form.getValues,
//     setValues: (data) => form.reset(data),
//     enabled: !mutation.isSuccess,           // stop after successful submit
//     blocklist: ['sss_no', 'tin', 'bank_account_no'],
//   });
//
// Then render <DraftRestoreBanner /> when `hasDraft` is true.

import { useCallback, useEffect, useRef, useState } from 'react';

const DEFAULT_BLOCKLIST_PATTERNS: RegExp[] = [
  /password/i,
  /secret/i,
  /token/i,
  /sss/i,
  /tin/i,
  /philhealth/i,
  /pagibig|pag_ibig|pag-ibig/i,
  /bank/i,
  /salary/i,
  /daily_rate/i,
  /monthly_rate/i,
  /credit.?card|card.?number|cvv/i,
];

const STORAGE_PREFIX = 'ogami:formdraft:';
const SAVE_INTERVAL_MS = 30_000; // 30 s, per spec.

export interface UseFormDraftAutosaveOptions {
  /** Unique key for the form, e.g. `hr.employees.create`. */
  formKey: string;
  /** Returns the current form values (typically `form.getValues`). */
  getValues: () => Record<string, unknown>;
  /** Applies a draft back to the form (typically `(data) => form.reset(data)`). */
  setValues: (data: Record<string, unknown>) => void;
  /** Disable autosave (e.g. once the form has submitted successfully). */
  enabled?: boolean;
  /** Extra field names to blocklist on top of the default sensitive patterns. */
  blocklist?: string[];
}

interface DraftPayload {
  ts: number;
  values: Record<string, unknown>;
}

function stripSensitive(
  values: Record<string, unknown>,
  extra: string[] = [],
): Record<string, unknown> {
  const extraPatterns = extra.map((s) => new RegExp(`^${s}$`, 'i'));
  const allPatterns = [...DEFAULT_BLOCKLIST_PATTERNS, ...extraPatterns];
  const out: Record<string, unknown> = {};
  for (const [key, value] of Object.entries(values)) {
    if (allPatterns.some((p) => p.test(key))) continue;
    if (value && typeof value === 'object' && !Array.isArray(value)) {
      // Recurse into nested objects (e.g. address.line1).
      out[key] = stripSensitive(value as Record<string, unknown>, extra);
    } else {
      out[key] = value;
    }
  }
  return out;
}

function readDraft(formKey: string): DraftPayload | null {
  if (typeof window === 'undefined') return null;
  try {
    const raw = window.localStorage.getItem(STORAGE_PREFIX + formKey);
    if (!raw) return null;
    const parsed = JSON.parse(raw) as DraftPayload;
    if (!parsed || typeof parsed.ts !== 'number') return null;
    return parsed;
  } catch {
    return null;
  }
}

function writeDraft(formKey: string, payload: DraftPayload): void {
  if (typeof window === 'undefined') return;
  try {
    window.localStorage.setItem(STORAGE_PREFIX + formKey, JSON.stringify(payload));
  } catch {
    // Quota exceeded or storage disabled — silently ignore.
  }
}

function deleteDraft(formKey: string): void {
  if (typeof window === 'undefined') return;
  try {
    window.localStorage.removeItem(STORAGE_PREFIX + formKey);
  } catch {
    /* noop */
  }
}

export interface UseFormDraftAutosaveResult {
  /** True if a draft existed at mount time (banner visibility). */
  hasDraft: boolean;
  /** Age of the existing draft in milliseconds, useful for "5 minutes ago" copy. */
  draftAge: number | null;
  /** Restore the persisted draft into the form. */
  restore: () => void;
  /** Discard the persisted draft (also clears localStorage). */
  discard: () => void;
  /** Strip sensitive fields and persist now (mostly used in tests). */
  saveNow: () => void;
}

export function useFormDraftAutosave({
  formKey,
  getValues,
  setValues,
  enabled = true,
  blocklist = [],
}: UseFormDraftAutosaveOptions): UseFormDraftAutosaveResult {
  const initialDraftRef = useRef<DraftPayload | null>(readDraft(formKey));
  const [hasDraft, setHasDraft] = useState<boolean>(initialDraftRef.current !== null);
  const draftAge = initialDraftRef.current ? Date.now() - initialDraftRef.current.ts : null;

  const saveNow = useCallback(() => {
    if (!enabled) return;
    const values = stripSensitive(getValues(), blocklist);
    writeDraft(formKey, { ts: Date.now(), values });
  }, [enabled, formKey, getValues, blocklist]);

  const restore = useCallback(() => {
    const draft = readDraft(formKey);
    if (draft) setValues(draft.values);
    setHasDraft(false);
  }, [formKey, setValues]);

  const discard = useCallback(() => {
    deleteDraft(formKey);
    setHasDraft(false);
  }, [formKey]);

  // Periodic auto-save while enabled.
  useEffect(() => {
    if (!enabled) return;
    const id = window.setInterval(saveNow, SAVE_INTERVAL_MS);
    return () => window.clearInterval(id);
  }, [enabled, saveNow]);

  // Save on unmount (best-effort).
  useEffect(() => {
    return () => {
      if (enabled) saveNow();
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return { hasDraft, draftAge, restore, discard, saveNow };
}

export const __TEST_ONLY__ = { stripSensitive, STORAGE_PREFIX };
