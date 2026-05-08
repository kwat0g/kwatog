// Series X / Task X1 — modal scope stack for keyboard shortcuts.
//
// The store maintains a stack of currently-open "modals". The Modal component
// pushes itself on mount and pops on unmount. Esc handlers (in Modal) and
// scope-aware shortcut hooks read `modalDepth` to decide whether to fire.
//
// We deliberately keep this minimal — no persistence, no localStorage. Pure
// in-memory.

import { create } from 'zustand';

interface ShortcutScopeState {
  modalStack: string[];
  pushModal: (id: string) => void;
  popModal: (id: string) => void;
  /** Convenience: depth of the modal stack. */
  modalDepth: () => number;
  /** Returns true if the given id is the topmost modal (and thus owns Esc). */
  isTopmost: (id: string) => boolean;
}

export const useShortcutScopeStore = create<ShortcutScopeState>((set, get) => ({
  modalStack: [],
  pushModal: (id) => set((s) => ({ modalStack: [...s.modalStack, id] })),
  popModal: (id) =>
    set((s) => {
      const idx = s.modalStack.lastIndexOf(id);
      if (idx === -1) return s;
      const next = [...s.modalStack];
      next.splice(idx, 1);
      return { modalStack: next };
    }),
  modalDepth: () => get().modalStack.length,
  isTopmost: (id) => {
    const stack = get().modalStack;
    return stack.length > 0 && stack[stack.length - 1] === id;
  },
}));
