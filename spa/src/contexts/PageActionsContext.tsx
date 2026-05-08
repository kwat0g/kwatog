/* eslint-disable react-refresh/only-export-components */
// Series X / Task X1 — context-driven page actions.
//
// Pages register handlers for the action shortcuts (⌘ S save, ⌘ ⇧ N new,
// ⌘ ⇧ E export, ⌘ ⇧ P print). The context lives once at the AppLayout
// level; pages call `usePageActions({...})` on mount and the registration
// is automatically scoped to the page's lifecycle.
//
// If a shortcut fires and no handler is registered, it's a no-op — no errors,
// no toast.

import { createContext, useCallback, useContext, useEffect, useRef, type ReactNode } from 'react';

export interface PageActionHandlers {
  onSave?: () => void;
  onCreate?: () => void;
  onExport?: () => void;
  onPrint?: () => void;
}

interface PageActionsContextValue {
  register: (handlers: PageActionHandlers) => () => void;
  fire: (action: keyof PageActionHandlers) => void;
}

const PageActionsContext = createContext<PageActionsContextValue | null>(null);

export function PageActionsProvider({ children }: { children: ReactNode }) {
  // Stack of registered handlers; the topmost one wins. This lets a modal
  // (registered after the underlying page) take precedence for ⌘ S without
  // the page needing to know about it.
  const stackRef = useRef<PageActionHandlers[]>([]);

  const register = useCallback((handlers: PageActionHandlers) => {
    stackRef.current.push(handlers);
    return () => {
      const idx = stackRef.current.lastIndexOf(handlers);
      if (idx !== -1) stackRef.current.splice(idx, 1);
    };
  }, []);

  const fire = useCallback((action: keyof PageActionHandlers) => {
    for (let i = stackRef.current.length - 1; i >= 0; i--) {
      const handler = stackRef.current[i][action];
      if (handler) {
        handler();
        return;
      }
    }
  }, []);

  return (
    <PageActionsContext.Provider value={{ register, fire }}>
      {children}
    </PageActionsContext.Provider>
  );
}

/**
 * Register page-level action handlers for keyboard shortcuts. Re-registers on
 * every render where any handler changes — the stack tracks identity so this
 * is cheap.
 */
export function usePageActions(handlers: PageActionHandlers): void {
  const ctx = useContext(PageActionsContext);
  // We allow null context (pages outside AppLayout simply skip registration).
  useEffect(() => {
    if (!ctx) return;
    return ctx.register(handlers);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ctx, handlers.onSave, handlers.onCreate, handlers.onExport, handlers.onPrint]);
}

/**
 * Hook used by `useKeyboardShortcuts` to fire actions. Returns a no-op `fire`
 * when called outside the provider.
 */
export function usePageActionsDispatcher() {
  const ctx = useContext(PageActionsContext);
  return useCallback(
    (action: keyof PageActionHandlers) => {
      if (!ctx) return;
      ctx.fire(action);
    },
    [ctx],
  );
}
