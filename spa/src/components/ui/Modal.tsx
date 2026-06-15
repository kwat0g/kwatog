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

/**
 * Focusable element selector — matches all standard interactive elements
 * plus elements with explicit tabindex >= 0.
 */
const FOCUSABLE =
  'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Trap keyboard focus inside the modal content region so Tab/Shift+Tab
 * cycle through focusable elements rather than escaping into the page
 * behind the overlay. Returns a cleanup function.
 */
function useFocusTrap(
  containerRef: React.RefObject<HTMLDivElement | null>,
  isOpen: boolean,
): void {
  useEffect(() => {
    if (!isOpen || !containerRef.current) return;

    const container = containerRef.current;

    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key !== 'Tab') return;

      const focusableElements = container.querySelectorAll<HTMLElement>(FOCUSABLE);
      if (focusableElements.length === 0) {
        // Nothing focusable inside — prevent focus from leaving the overlay.
        e.preventDefault();
        return;
      }

      const first = focusableElements[0];
      const last = focusableElements[focusableElements.length - 1];

      if (e.shiftKey) {
        // Shift+Tab: wrap to last if we're on the first element
        if (document.activeElement === first) {
          e.preventDefault();
          last.focus();
        }
      } else {
        // Tab: wrap to first if we're on the last element
        if (document.activeElement === last) {
          e.preventDefault();
          first.focus();
        }
      }
    };

    // Focus the first focusable element inside the modal on open.
    requestAnimationFrame(() => {
      const focusableElements = container.querySelectorAll<HTMLElement>(FOCUSABLE);
      if (focusableElements.length > 0) {
        focusableElements[0].focus();
      }
    });

    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [isOpen, containerRef]);
}

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
  const titleId = useId();

  // Focus trap — keeps Tab cycling within the modal while open.
  useFocusTrap(dialogRef, isOpen);

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
      aria-labelledby={title ? titleId : undefined}
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
            <h2 id={titleId} className="text-md font-medium text-primary">{title}</h2>
          </div>
        )}
        <div className="px-4">{children}</div>
      </div>
    </div>
  );
}
