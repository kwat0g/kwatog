// Series X / Task X1 — central keyboard-shortcut registry.
//
// Single source of truth for the help modal. The actual key handling lives
// in `hooks/useKeyboardShortcuts.ts` — that hook reads no values from this
// file at runtime; this registry exists to drive the help dialog and the
// no-duplicates unit test. Edit both places together when you add a shortcut.
//
// Conventions:
//  - `keys` is a display token (e.g. 'g h', 'mod+s', '?'). Not parsed at
//    runtime — informational only.
//  - `hint` is the pretty rendering shown in the help modal (⌘ S, g h, Esc).
//  - `scope` controls when the shortcut is active. The shortcut-scope store
//    decides which scope is current (e.g. inside a modal, only `modal`
//    scope shortcuts fire alongside global ones; the modal stack ensures
//    Esc only closes the topmost modal).
//  - `id` is used by handler-registration consumers — see `usePageActions`.

export type ShortcutScope = 'global' | 'modal' | 'form' | 'table';

export type ShortcutGroup = 'navigation' | 'actions' | 'help' | 'table';

export interface ShortcutEntry {
  /** Stable identifier — used by handlers to register actions. */
  id: string;
  /** react-hotkeys-hook key string, e.g. 'g h' or 'mod+s'. */
  keys: string;
  /** Display label for the help modal. */
  label: string;
  /** Pretty key hint for the help modal, e.g. 'g h', '⌘ S', 'Esc'. */
  hint: string;
  /** Group in the help modal. */
  group: ShortcutGroup;
  /** Scope in which this shortcut is active. */
  scope: ShortcutScope;
  /** Optional path to navigate to (used by global navigation shortcuts). */
  navigate?: string;
}

export const SHORTCUTS: ShortcutEntry[] = [
  // Navigation — `g <letter>` go-to shortcuts.
  { id: 'nav.hr',         keys: 'g h', label: 'Go to HR / Employees',  hint: 'g h', group: 'navigation', scope: 'global', navigate: '/hr/employees' },
  { id: 'nav.payroll',    keys: 'g p', label: 'Go to Payroll',         hint: 'g p', group: 'navigation', scope: 'global', navigate: '/payroll/periods' },
  { id: 'nav.accounting', keys: 'g a', label: 'Go to Accounting',      hint: 'g a', group: 'navigation', scope: 'global', navigate: '/accounting' },
  { id: 'nav.inventory',  keys: 'g i', label: 'Go to Inventory',       hint: 'g i', group: 'navigation', scope: 'global', navigate: '/inventory/items' },
  { id: 'nav.sales',      keys: 'g s', label: 'Go to Sales Orders',    hint: 'g s', group: 'navigation', scope: 'global', navigate: '/crm/sales-orders' },
  { id: 'nav.mrp',        keys: 'g m', label: 'Go to MRP Plans',       hint: 'g m', group: 'navigation', scope: 'global', navigate: '/mrp/plans' },
  { id: 'nav.dashboard',  keys: 'g d', label: 'Go to Dashboard',       hint: 'g d', group: 'navigation', scope: 'global', navigate: '/dashboard' },

  // Actions — global commands.
  { id: 'action.search',     keys: 'mod+k',       label: 'Open command palette / search', hint: '⌘ K',     group: 'actions', scope: 'global' },
  { id: 'action.new',        keys: 'mod+shift+n', label: 'New record on current page',    hint: '⌘ ⇧ N',   group: 'actions', scope: 'global' },
  { id: 'action.export',     keys: 'mod+shift+e', label: 'Export current list',           hint: '⌘ ⇧ E',   group: 'actions', scope: 'global' },
  { id: 'action.print',      keys: 'mod+shift+p', label: 'Print current record',          hint: '⌘ ⇧ P',   group: 'actions', scope: 'global' },
  { id: 'action.save',       keys: 'mod+s',       label: 'Save current form',             hint: '⌘ S',     group: 'actions', scope: 'form' },

  // Help.
  { id: 'help.toggle',       keys: 'shift+/',     label: 'Show keyboard shortcuts',       hint: '?',       group: 'help',    scope: 'global' },

  // Modal — Esc handled by Modal component itself, listed for documentation only.
  { id: 'modal.close',       keys: 'escape',      label: 'Close modal / panel',           hint: 'Esc',     group: 'actions', scope: 'modal' },

  // Table — bound by DataTable when its row container is focused.
  { id: 'table.next',        keys: 'j',           label: 'Next row',                      hint: 'j',       group: 'table',   scope: 'table' },
  { id: 'table.prev',        keys: 'k',           label: 'Previous row',                  hint: 'k',       group: 'table',   scope: 'table' },
  { id: 'table.open',        keys: 'enter',       label: 'Open selected row',             hint: '↵',       group: 'table',   scope: 'table' },
  { id: 'table.toggle',      keys: 'space',       label: 'Toggle row selection',          hint: 'Space',   group: 'table',   scope: 'table' },
  { id: 'table.selectAll',   keys: 'mod+a',       label: 'Select all rows',               hint: '⌘ A',     group: 'table',   scope: 'table' },
];

export const NAVIGATION_SHORTCUTS = SHORTCUTS.filter((s) => s.group === 'navigation');
export const ACTION_SHORTCUTS     = SHORTCUTS.filter((s) => s.group === 'actions');
export const TABLE_SHORTCUTS      = SHORTCUTS.filter((s) => s.group === 'table');

export function shortcutById(id: string): ShortcutEntry | undefined {
  return SHORTCUTS.find((s) => s.id === id);
}

export const GROUP_LABELS: Record<ShortcutGroup, string> = {
  navigation: 'Navigation',
  actions:    'Actions',
  table:      'Table',
  help:       'Help',
};
