import { useEffect, useRef } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import type { AuthSessionRealm } from '@/lib/authSessionSync';
import { clearAuthSessionDrafts, subscribeAuthSessionSignal } from '@/lib/authSessionSync';
import { clearPwaCaches } from '@/sw-register';

/** Refresh the active portal guard after a same-origin session mutation. */
export function usePortalSessionSync(
  realm: Exclude<AuthSessionRealm, 'internal' | 'all'>,
  onChanging: () => void,
  onChanged: () => void,
): void {
  const queryClient = useQueryClient();
  const onChangingRef = useRef(onChanging);
  const onChangedRef = useRef(onChanged);

  useEffect(() => {
    onChangingRef.current = onChanging;
  }, [onChanging]);

  useEffect(() => {
    onChangedRef.current = onChanged;
  }, [onChanged]);

  useEffect(() => subscribeAuthSessionSignal((signal) => {
    if (signal.realm !== 'all' && signal.realm !== realm) return;
    // A matching or unknown session switch may reuse unscoped query keys.
    queryClient.clear();
    if (signal.stage === 'changing') {
      clearAuthSessionDrafts();
      void clearPwaCaches();
      onChangingRef.current();
      return;
    }

    // A resolved realm may differ from the login page's hint, so some guards
    // receive only `changed`. Always drop local identity before revalidation.
    clearAuthSessionDrafts();
    void clearPwaCaches();
    onChangingRef.current();
    onChangedRef.current();
  }), [queryClient, realm]);
}
