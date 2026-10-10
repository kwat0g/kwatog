// Series X / Task X2 — unsaved-changes guard.
//
// Blocks tab close / hard refresh / external nav with the browser's native
// `beforeunload` prompt and blocks in-app history transitions with a
// confirmation dialog. Legacy history.block is preserved; forms can opt in
// to protection at the current BrowserRouter navigation seam.
//
// Usage:
// const { isDirty } = formState;
// useUnsavedChangesGuard(isDirty && !mutation.isSuccess);

import { useContext, useEffect } from 'react';
import { UNSAFE_NavigationContext, type Navigator } from 'react-router-dom';

interface BlockableNavigator {
  block?: (listener: (transition: { retry: () => void }) => void) => () => void;
}

export function useUnsavedChangesGuard(when: boolean, protectNavigation = false): void {
  const { navigator } = useContext(UNSAFE_NavigationContext);

  useEffect(() => {
    if (!when) return;
    const onBeforeUnload = (e: BeforeUnloadEvent) => {
      e.preventDefault();
      // Modern browsers ignore the returnValue string but require it to be
      // set for the prompt to show.
      e.returnValue = '';
    };
    window.addEventListener('beforeunload', onBeforeUnload);
    const block = (navigator as unknown as BlockableNavigator).block;
    let unblock: (() => void) | undefined;
    if (block) {
      unblock = block((transition) => {
        if (window.confirm('You have unsaved changes. Leave this page?')) {
          unblock?.();
          transition.retry();
        }
      });
    } else if (protectNavigation) {
      // BrowserRouter's current navigator has no history.block. Keep the same
      // guard at its navigation seam instead of silently losing protection.
      const guarded = navigator as Navigator;
      const originals = { push: guarded.push, replace: guarded.replace, go: guarded.go };
      let lastIndex = window.history.state?.idx;
      let approvedPop = false;
      const confirm = () => window.confirm('You have unsaved changes. Leave this page?');
      guarded.push = (...args) => {
        if (confirm()) {
          originals.push(...args);
          lastIndex = window.history.state?.idx;
        }
      };
      guarded.replace = (...args) => {
        if (confirm()) {
          originals.replace(...args);
          lastIndex = window.history.state?.idx;
        }
      };
      guarded.go = (...args) => {
        if (confirm()) {
          approvedPop = true;
          originals.go(...args);
        }
      };
      const onPop = (event: PopStateEvent) => {
        const nextIndex = event.state?.idx;
        if (
          typeof lastIndex !== 'number' ||
          typeof nextIndex !== 'number' ||
          nextIndex === lastIndex
        )
          return;
        if (approvedPop || confirm()) {
          approvedPop = false;
          lastIndex = nextIndex;
          return;
        }
        event.stopImmediatePropagation();
        window.history.go(lastIndex - nextIndex);
      };
      window.addEventListener('popstate', onPop, true);
      unblock = () => {
        Object.assign(guarded, originals);
        window.removeEventListener('popstate', onPop, true);
      };
    }

    return () => {
      window.removeEventListener('beforeunload', onBeforeUnload);
      unblock?.();
    };
  }, [navigator, when, protectNavigation]);
}
