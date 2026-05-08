// Series X / Task X2 — unsaved-changes guard.
//
// Blocks tab close / hard refresh / external nav with the browser's native
// `beforeunload` prompt. The user sees the OS-level "Leave site? Changes you
// made may not be saved." dialog.
//
// **In-app SPA navigation is not blocked here.** `useBlocker` from
// react-router-dom requires a *data router* (createBrowserRouter), and the
// app currently uses the legacy `<BrowserRouter>` (see main.tsx). Adding
// data-router migration is out of scope for X2; the autosave-draft feature
// (`useFormDraftAutosave`) covers the common case where a user accidentally
// clicks a sidebar link mid-edit — they'll see the restore banner on
// return.
//
// Usage:
//   const { isDirty } = formState;
//   useUnsavedChangesGuard(isDirty && !mutation.isSuccess);

import { useEffect } from 'react';

export function useUnsavedChangesGuard(when: boolean): void {
  useEffect(() => {
    if (!when) return;
    const onBeforeUnload = (e: BeforeUnloadEvent) => {
      e.preventDefault();
      // Modern browsers ignore the returnValue string but require it to be
      // set for the prompt to show.
      e.returnValue = '';
    };
    window.addEventListener('beforeunload', onBeforeUnload);
    return () => window.removeEventListener('beforeunload', onBeforeUnload);
  }, [when]);
}
