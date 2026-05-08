// Series X / Task X1 — global keyboard shortcuts hook.
//
// Mounts every registered global navigation/action shortcut once at the top
// of the authenticated app (see AppLayout). Reads handlers from the
// PageActionsContext so per-page commands (Save, New, Export, Print) work
// without each page wiring its own keybinding.
//
// Forms register Save via `usePageActions({ onSave: () => handleSubmit(onSubmit)() })`.
// List pages register Create/Export via `usePageActions({ onCreate, onExport })`.
// Detail pages register Print similarly.
//
// ⌘K / Ctrl+K stays owned by Topbar (it always has — see Topbar's vanilla
// keydown listener and the CommandPalette it renders). We list the shortcut
// in the registry for the help modal but don't bind it again here.

import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useHotkeys } from 'react-hotkeys-hook';
import { shortcutById } from '@/lib/shortcuts';
import { usePageActionsDispatcher } from '@/contexts/PageActionsContext';

export interface KeyboardShortcutsApi {
  helpOpen: boolean;
  setHelpOpen: (open: boolean) => void;
}

// Pre-extract the keys at module load so the order of useHotkeys calls is
// stable across renders.
const KEYS = {
  navHr:        shortcutById('nav.hr')!.keys,
  navPayroll:   shortcutById('nav.payroll')!.keys,
  navAccounting:shortcutById('nav.accounting')!.keys,
  navInventory: shortcutById('nav.inventory')!.keys,
  navSales:     shortcutById('nav.sales')!.keys,
  navMrp:       shortcutById('nav.mrp')!.keys,
  navDashboard: shortcutById('nav.dashboard')!.keys,
  save:         shortcutById('action.save')!.keys,
  newRecord:    shortcutById('action.new')!.keys,
  exportList:   shortcutById('action.export')!.keys,
  print:        shortcutById('action.print')!.keys,
  help:         shortcutById('help.toggle')!.keys,
};

/**
 * Mount once near the root of the authenticated app. Owns the help-modal
 * open state.
 */
export function useKeyboardShortcuts(): KeyboardShortcutsApi {
  const navigate = useNavigate();
  const fireAction = usePageActionsDispatcher();
  const [helpOpen, setHelpOpen] = useState(false);

  // Default react-hotkeys-hook behavior already disables shortcuts inside
  // form inputs (input/textarea/select/contenteditable). We do NOT override
  // that — typing "g" inside a search box must not navigate to HR.

  // Navigation: g <letter> two-key sequences.
  useHotkeys(KEYS.navHr,         () => navigate('/hr/employees'),     { preventDefault: true }, [navigate]);
  useHotkeys(KEYS.navPayroll,    () => navigate('/payroll/periods'),  { preventDefault: true }, [navigate]);
  useHotkeys(KEYS.navAccounting, () => navigate('/accounting'),       { preventDefault: true }, [navigate]);
  useHotkeys(KEYS.navInventory,  () => navigate('/inventory/items'),  { preventDefault: true }, [navigate]);
  useHotkeys(KEYS.navSales,      () => navigate('/crm/sales-orders'), { preventDefault: true }, [navigate]);
  useHotkeys(KEYS.navMrp,        () => navigate('/mrp/plans'),        { preventDefault: true }, [navigate]);
  useHotkeys(KEYS.navDashboard,  () => navigate('/dashboard'),        { preventDefault: true }, [navigate]);

  // Help — toggle on `?` (shift+/).
  useHotkeys(KEYS.help, () => setHelpOpen((v) => !v), { preventDefault: true });

  // Page actions. ⌘S is enabled inside form inputs (you should be able to
  // save without leaving the active field).
  useHotkeys(
    KEYS.save,
    () => fireAction('onSave'),
    { enableOnFormTags: ['input', 'textarea', 'select'], preventDefault: true },
    [fireAction],
  );
  useHotkeys(KEYS.newRecord,  () => fireAction('onCreate'), { preventDefault: true }, [fireAction]);
  useHotkeys(KEYS.exportList, () => fireAction('onExport'), { preventDefault: true }, [fireAction]);
  useHotkeys(KEYS.print,      () => fireAction('onPrint'),  { preventDefault: true }, [fireAction]);

  return { helpOpen, setHelpOpen };
}
