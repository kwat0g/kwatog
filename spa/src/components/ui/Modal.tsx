import { useEffect, useId, useRef, type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { useShortcutScopeStore } from '@/stores/shortcutScopeStore';

type Size = 'sm' | 'md' | 'lg' | 'xl';

interface ModalProps {
  isOpen: boolean;
  onClose: () => void;
  title?: ReactNode;
  size?: Size;
  closeOnOverlayClick?: boolean;
  children: ReactNode;
  className?: string;
}

const sizes: Record<Size, string> = {
  sm: 'max-w-sm',
  md: 'max-w-lg',
  lg: 'max-w-2xl',
  xl: 'max-w-4xl',
};

export function Modal({
  isOpen,
  onClose,
  title,
  size = 'md',
  closeOnOverlayClick = true,
  children,
  className,
}: ModalProps) {
  const dialogRef = useRef<HTMLDivElement>(null);
  // Series X / Task X1 — push this modal onto the global scope stack so Esc
  // only closes the topmost modal, and so other shortcut hooks can read
  // modal depth.
  const stackId = useId();
  const pushModal = useShortcutScopeStore((s) => s.pushModal);
  const popModal = useShortcutScopeStore((s) => s.popModal);
  const isTopmost = useShortcutScopeStore((s) => s.isTopmost);

  useEffect(() => {
    if (!isOpen) return;
    pushModal(stackId);
    return () => popModal(stackId);
  }, [isOpen, stackId, pushModal, popModal]);

  // Close on ESC — but only if this modal is the topmost in the stack.
  useEffect(() => {
    if (!isOpen) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && isTopmost(stackId)) {
        e.stopPropagation();
        onClose();
      }
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [isOpen, onClose, isTopmost, stackId]);

  // Lock body scroll while open.
  useEffect(() => {
    if (!isOpen) return;
    const original = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.body.style.overflow = original;
    };
  }, [isOpen]);

  if (!isOpen) return null;

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center px-4"
      role="dialog"
      aria-modal="true"
      onMouseDown={(e) => {
        if (closeOnOverlayClick && e.target === e.currentTarget) onClose();
      }}
    >
      <div className="absolute inset-0 bg-black/40 animate-fade-in" />
      <div
        ref={dialogRef}
        className={cn(
          'relative w-full bg-canvas border border-default rounded-lg shadow-menu animate-slide-up',
          sizes[size],
          className,
        )}
      >
        {title && (
          <div className="px-4 py-3 border-b border-default">
            <h2 className="text-md font-medium text-primary">{title}</h2>
          </div>
        )}
        <div className="px-4">{children}</div>
      </div>
    </div>
  );
}
