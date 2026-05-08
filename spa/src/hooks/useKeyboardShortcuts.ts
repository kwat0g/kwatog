// Series X / Task X1 — global keyboard shortcuts hook.
//
// Implementation note: react-hotkeys-hook 4.x does NOT support two-key
// sequence shortcuts natively (the syntax `'g>h'` parses as a single key
// literally named "g>h" and never fires). We therefore implement the
// `g <letter>` go-to navigation and the `?` help toggle with a vanilla
// keydown listener on `document`. Pure chord shortcuts (⌘ S, ⌘ ⇧ N etc.)
// also flow through the same listener for consistency.
//
// Mounts once near the root of the authenticated app (see AppLayout).
// Owns the help-modal open state so callers can render the matching
// modal.

import { useCallback, useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { usePageActionsDispatcher } from '@/contexts/PageActionsContext';

export interface KeyboardShortcutsApi {
  helpOpen: boolean;
  setHelpOpen: (open: boolean) => void;
}

const SEQUENCE_TIMEOUT_MS = 1000;

const NAV_TARGETS: Record<string, string> = {
  h: '/hr/employees',
  p: '/payroll/periods',
  a: '/accounting',
  i: '/inventory/items',
  s: '/crm/sales-orders',
  m: '/mrp/plans',
  d: '/dashboard',
};

function isTypingTarget(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) return false;
  const tag = target.tagName;
  if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true;
  if (target.isContentEditable) return true;
  return false;
}

export function useKeyboardShortcuts(): KeyboardShortcutsApi {
  const navigate = useNavigate();
  const fireAction = usePageActionsDispatcher();
  const [helpOpen, setHelpOpen] = useState(false);

  // Track the leader key (`g`) for two-key sequences. We use a ref so the
  // listener doesn't need to re-bind every render.
  const leaderActiveRef = useRef(false);
  const leaderTimeoutRef = useRef<number | null>(null);

  const clearLeader = useCallback(() => {
    leaderActiveRef.current = false;
    if (leaderTimeoutRef.current !== null) {
      window.clearTimeout(leaderTimeoutRef.current);
      leaderTimeoutRef.current = null;
    }
  }, []);

  useEffect(() => {
    const onKeyDown = (e: KeyboardEvent) => {
      const typing = isTypingTarget(e.target);
      const mod = e.metaKey || e.ctrlKey;

      // ─── Action chords (work in any context for ⌘S; others only outside inputs) ──

      // ⌘ S — save the current form. Allowed inside inputs.
      if (mod && !e.shiftKey && !e.altKey && (e.key === 's' || e.key === 'S')) {
        e.preventDefault();
        fireAction('onSave');
        clearLeader();
        return;
      }

      // ⌘ ⇧ N — new record on the current list page.
      if (mod && e.shiftKey && (e.key === 'N' || e.key === 'n')) {
        if (typing) return;
        e.preventDefault();
        fireAction('onCreate');
        clearLeader();
        return;
      }

      // ⌘ ⇧ E — export current list.
      if (mod && e.shiftKey && (e.key === 'E' || e.key === 'e')) {
        if (typing) return;
        e.preventDefault();
        fireAction('onExport');
        clearLeader();
        return;
      }

      // ⌘ ⇧ P — print current detail.
      if (mod && e.shiftKey && (e.key === 'P' || e.key === 'p')) {
        if (typing) return;
        e.preventDefault();
        fireAction('onPrint');
        clearLeader();
        return;
      }

      // From here on, all shortcuts are blocked while typing in form fields.
      if (typing) return;

      // ─── Help toggle: `?` ────────────────────────────────────────────
      if (e.key === '?' && !mod) {
        e.preventDefault();
        setHelpOpen((v) => !v);
        clearLeader();
        return;
      }

      // ─── Two-key sequence: `g <letter>` ──────────────────────────────
      // Pressing `g` starts the leader window. Pressing any nav-target letter
      // within SEQUENCE_TIMEOUT_MS triggers the navigation. Any other key
      // cancels.

      if (!mod && !e.shiftKey && !e.altKey) {
        if (leaderActiveRef.current) {
          const target = NAV_TARGETS[e.key];
          // Always cancel the leader on the next keypress, regardless of match.
          clearLeader();
          if (target) {
            e.preventDefault();
            navigate(target);
            return;
          }
          // No match — fall through (the key wasn't a nav target).
          return;
        }

        if (e.key === 'g') {
          // Don't preventDefault — the user might be holding 'g' for autorepeat
          // or the page might have an interactive element bound to 'g'. We
          // simply set the leader flag and consume the *next* key.
          leaderActiveRef.current = true;
          leaderTimeoutRef.current = window.setTimeout(() => {
            leaderActiveRef.current = false;
            leaderTimeoutRef.current = null;
          }, SEQUENCE_TIMEOUT_MS);
          return;
        }
      }
    };

    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('keydown', onKeyDown);
      clearLeader();
    };
  }, [navigate, fireAction, clearLeader]);

  return { helpOpen, setHelpOpen };
}
