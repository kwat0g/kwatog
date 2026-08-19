// One call that makes a form safe to walk away from.
//
// `useUnsavedChangesGuard` and `useFormDraftAutosave` both shipped in Series X
// and both reached exactly one page (purchasing/purchase-orders/create) out of
// ~55 forms. The reason is visible in that page: wiring them takes a useCallback
// for `setValues`, a hand-written formKey, the auth store for `userId`, an
// `enabled` expression, and a banner with three props threaded through. That is
// enough friction that no other form paid it, so on every other form in the ERP
// a mis-click discards a half-finished purchase request.
//
// This collapses it to:
//
//   const safety = useFormSafety({ form, saved: create.isSuccess });
//   …
//   <FormDraftBanner safety={safety} />
//
// The draft key defaults to the route, which is unique per form and needs no
// invention. Pass `formKey` only when one route hosts two forms, or when the
// draft should be scoped to a parent record (see the PO page, whose draft is
// meaningless without the PR it was started from).

import { useCallback, useMemo } from 'react';
import { useLocation } from 'react-router-dom';
import type { FieldValues, UseFormReturn } from 'react-hook-form';
import { useAuthStore } from '@/stores/authStore';
import { useFormDraftAutosave, type UseFormDraftAutosaveResult } from './useFormDraftAutosave';
import { useUnsavedChangesGuard } from './useUnsavedChangesGuard';

export interface UseFormSafetyOptions<T extends FieldValues> {
  form: Pick<UseFormReturn<T>, 'getValues' | 'reset' | 'formState'>;
  /**
   * True once the work is committed. Stops the beforeunload prompt and the
   * autosave interval — a redirect after a successful save must not look like
   * an accident.
   */
  saved: boolean;
  /** Override the route-derived draft key. */
  formKey?: string;
  /** Extra field names never to persist, on top of the default sensitive set. */
  blocklist?: string[];
  /**
   * Set false to keep the beforeunload guard but skip the localStorage draft —
   * right for forms whose values are all sensitive, or that are meaningless
   * without a parent record selected first.
   */
  draft?: boolean;
}

export interface FormSafety {
  draftState: UseFormDraftAutosaveResult;
}

export function useFormSafety<T extends FieldValues>({
  form,
  saved,
  formKey,
  blocklist,
  draft = true,
}: UseFormSafetyOptions<T>): FormSafety {
  const { pathname } = useLocation();
  const userId = useAuthStore((s) => s.user?.id);
  const { getValues, reset, formState } = form;

  // Drafts are scoped per user by the underlying hook; scoping the key by route
  // keeps two different create forms from overwriting each other.
  const key = formKey ?? `route:${pathname}`;

  const applyDraft = useCallback(
    (data: Record<string, unknown>) => reset(data as T),
    [reset],
  );
  const readValues = useCallback(
    () => getValues() as Record<string, unknown>,
    [getValues],
  );
  // A new array literal each render would re-create the autosave callback and
  // restart its interval on every keystroke.
  const stableBlocklist = useMemo(() => blocklist ?? [], [blocklist?.join('|')]); // eslint-disable-line react-hooks/exhaustive-deps

  const draftState = useFormDraftAutosave({
    formKey: key,
    getValues: readValues,
    setValues: applyDraft,
    userId,
    enabled: draft && !saved,
    blocklist: stableBlocklist,
  });

  useUnsavedChangesGuard(formState.isDirty && !saved);

  return { draftState };
}
