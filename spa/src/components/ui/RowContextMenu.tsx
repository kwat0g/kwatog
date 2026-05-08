// Series X / Task X4 — right-click row context menu.
//
// Positioned absolutely at the cursor coordinates the user invoked the menu.
// Closes on outside click, Esc, or any menu item activation.

import { useEffect, useRef, type ReactNode } from 'react';
import { cn } from '@/lib/cn';

export interface RowContextMenuItem {
  label: ReactNode;
  /** Optional icon (Lucide component instance). */
  icon?: ReactNode;
  /** Variant — currently only 'danger' tints the label red. */
  variant?: 'default' | 'danger';
  /** Disabled state. */
  disabled?: boolean;
  /** Activation handler. The menu closes after firing. */
  onClick: () => void;
}

interface Props {
  open: boolean;
  x: number;
  y: number;
  items: RowContextMenuItem[];
  onClose: () => void;
}

export function RowContextMenu({ open, x, y, items, onClose }: Props) {
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const onPointer = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) onClose();
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    document.addEventListener('mousedown', onPointer);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onPointer);
      document.removeEventListener('keydown', onKey);
    };
  }, [open, onClose]);

  if (!open) return null;

  // Clamp to viewport so the menu doesn't overflow off-screen.
  const w = 180;
  const h = items.length * 30 + 8;
  const left = Math.min(x, window.innerWidth - w - 8);
  const top = Math.min(y, window.innerHeight - h - 8);

  return (
    <div
      ref={ref}
      role="menu"
      style={{ position: 'fixed', left, top, minWidth: w }}
      className="z-50 bg-elevated border border-default rounded-md shadow-menu py-1"
    >
      {items.map((item, i) => (
        <button
          key={i}
          type="button"
          role="menuitem"
          disabled={item.disabled}
          onClick={() => {
            if (item.disabled) return;
            item.onClick();
            onClose();
          }}
          className={cn(
            'w-full flex items-center gap-2 px-2.5 h-7 text-xs text-left',
            item.disabled && 'opacity-40 cursor-not-allowed',
            !item.disabled && 'hover:bg-subtle',
            item.variant === 'danger' ? 'text-danger' : 'text-primary',
          )}
        >
          {item.icon && <span className="w-3.5 inline-flex items-center text-muted">{item.icon}</span>}
          <span className="flex-1">{item.label}</span>
        </button>
      ))}
    </div>
  );
}
