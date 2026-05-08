// Series X / Task X1 — keyboard-shortcut help modal.
//
// Triggered globally by `?` (Shift+/). Renders the SHORTCUTS registry
// grouped by category with kbd-styled hints. Press Esc or the close button
// (or `?` again) to dismiss.

import { Modal } from './Modal';
import { GROUP_LABELS, SHORTCUTS, type ShortcutEntry, type ShortcutGroup } from '@/lib/shortcuts';

interface Props {
  open: boolean;
  onClose: () => void;
}

const COLUMN_ORDER: ShortcutGroup[][] = [
  ['navigation', 'help'],
  ['actions', 'table'],
];

export function KeyboardShortcutHelp({ open, onClose }: Props) {
  const byGroup = SHORTCUTS.reduce<Record<ShortcutGroup, ShortcutEntry[]>>(
    (acc, s) => {
      acc[s.group] = acc[s.group] ?? [];
      acc[s.group].push(s);
      return acc;
    },
    { navigation: [], actions: [], help: [], table: [] },
  );

  return (
    <Modal isOpen={open} onClose={onClose} size="lg" title="Keyboard Shortcuts">
      <div className="grid grid-cols-2 gap-6 py-4">
        {COLUMN_ORDER.map((groups, idx) => (
          <div key={idx} className="flex flex-col gap-5">
            {groups.map((group) => (
              <section key={group}>
                <h3 className="text-2xs uppercase tracking-wider text-muted font-medium mb-2">
                  {GROUP_LABELS[group]}
                </h3>
                <ul className="flex flex-col gap-1.5">
                  {byGroup[group].map((s) => (
                    <li key={s.id} className="flex items-center justify-between text-sm">
                      <span className="text-secondary">{s.label}</span>
                      <kbd className="font-mono text-xs text-muted bg-elevated border border-default rounded px-1.5 py-0.5 min-w-[2rem] text-center">
                        {s.hint}
                      </kbd>
                    </li>
                  ))}
                </ul>
              </section>
            ))}
          </div>
        ))}
      </div>
      <p className="pt-3 pb-4 text-xs text-muted border-t border-default">
        Press <kbd className="font-mono text-xs bg-elevated border border-default rounded px-1">?</kbd> at any time to
        toggle this dialog. Shortcuts are disabled while typing in form fields, except <kbd className="font-mono text-xs bg-elevated border border-default rounded px-1">⌘ S</kbd>.
      </p>
    </Modal>
  );
}

export default KeyboardShortcutHelp;
