// Series X / Task X2 — unsaved-changes guard.
//
// Blocks tab close / hard refresh / external nav with the browser's native
// `beforeunload` prompt and blocks in-app history transitions with a
// confirmation dialog. The latter uses the history block seam exposed by the
// legacy BrowserRouter, so forms do not need a router migration just to stay
// safe while editing.
//
// Usage:
// const { isDirty } = formState;
// useUnsavedChangesGuard(isDirty && !mutation.isSuccess);

import { useContext, useEffect } from 'react';
import { UNSAFE_NavigationContext } from 'react-router-dom';

interface BlockableNavigator {
 block?: (listener: (transition: { retry: () => void }) => void) => () => void;
}

export function useUnsavedChangesGuard(when: boolean): void {
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
 const unblock = block?.((transition) => {
   if (window.confirm('You have unsaved changes. Leave this page?')) {
     unblock?.();
     transition.retry();
   }
 });

 return () => {
   window.removeEventListener('beforeunload', onBeforeUnload);
   unblock?.();
 };
 }, [navigator, when]);
}
