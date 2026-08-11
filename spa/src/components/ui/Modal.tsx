import { useEffect, useId, useRef, type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { useShortcutScopeStore } from '@/stores/shortcutScopeStore';

type Size = 'sm' | 'md' | 'lg' | 'xl';

interface ModalProps {
 isOpen: boolean;
 onClose: () => void;
 title?: ReactNode;
 /** ID of a title rendered by the caller when Modal's built-in title is not used. */
 ariaLabelledBy?: string;
 ariaDescribedBy?: string;
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

 const previousElement = document.activeElement as HTMLElement | null;
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
 return () => {
 document.removeEventListener('keydown', handleKeyDown);
 previousElement?.focus?.();
 };
 }, [isOpen, containerRef]);
}

export function Modal({
 isOpen,
 onClose,
 title,
 ariaLabelledBy,
 ariaDescribedBy,
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
  className="fixed inset-0 z-50 flex items-center justify-center px-4 sm:px-6"
  role="dialog"
  aria-modal="true"
  aria-labelledby={title ? titleId : ariaLabelledBy}
  aria-describedby={ariaDescribedBy}
  onMouseDown={(e) => {
  if (closeOnOverlayClick && e.target === e.currentTarget) onClose();
  }}
  >
  <div className="fixed inset-0 bg-black/60 transition-opacity" aria-hidden="true" />
  
  <div
  ref={dialogRef}
  className={cn(
  'relative w-full bg-canvas border border-default rounded-lg animate-slide-up flex flex-col max-h-[90vh]',
  sizes[size],
  className,
  )}
  >
  {title && (
  <div className="px-5 py-4 border-b border-default bg-surface shrink-0 flex items-center justify-between rounded-t-md">
  <h2 id={titleId} className="text-base font-medium text-primary m-0">{title}</h2>
  <button onClick={onClose} type="button" className="text-muted hover:text-primary transition-colors focus:outline-none focus:ring-2 focus:ring-accent rounded-sm" aria-label="Close modal">
  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
  </button>
  </div>
  )}
        <div className="p-5 overflow-y-auto overflow-x-hidden">{children}</div>
      </div>
    </div>
  );
}

export function ModalFooter({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <div
      className={cn(
        'sticky bottom-[-20px] -mx-5 -mb-5 mt-5 px-5 py-4 border-t border-default bg-surface flex items-center justify-end gap-2 rounded-b-md z-10',
        className
      )}
    >
      {children}
    </div>
  );
}
