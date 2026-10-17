import {
  createContext,
  useContext,
  useEffect,
  useId,
  useRef,
  type ReactNode,
} from 'react';
import { cn } from '@/lib/cn';
import { useShortcutScopeStore } from '@/stores/shortcutScopeStore';
import { useIsPhone } from '@/hooks/useMediaQuery';

type Size = 'sm' | 'md' | 'lg' | 'xl';

/**
 * How the dialog is presented.
 *
 * - `dialog` — centred card with a backdrop. The desktop presentation.
 * - `sheet` — rises from the bottom edge, full width, top corners only. The
 *   phone action surface: reachable with a thumb, no 8px gutters wasted, and
 *   the footer sits against the bottom safe area.
 * - `auto` (default) — `sheet` below Tailwind's `sm`, `dialog` at and above it.
 *
 * The rule lives HERE, not in the 214 call sites, for the same reason F-009 and
 * F-010 were fixed inside `StatCard` and `SegmentedControl`: the defect is a
 * property of the primitive. Every dialog in the app is a `Modal`, so every
 * dialog gets a phone presentation without a page being edited — and a page
 * that genuinely wants a centred card on a phone can say
 * `presentation="dialog"` instead of working around a global.
 *
 * `BottomSheet` is now a thin wrapper that forces `sheet`, so there is one
 * dialog implementation: one focus trap, one scroll lock, one Esc stack.
 */
type Presentation = 'auto' | 'dialog' | 'sheet';

interface ModalProps {
  isOpen: boolean;
  onClose: () => void;
  title?: ReactNode;
  /** ID of a title rendered by the caller when Modal's built-in title is not used. */
  ariaLabelledBy?: string;
  ariaDescribedBy?: string;
  size?: Size;
  closeOnOverlayClick?: boolean;
  presentation?: Presentation;
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
 * Read by `ModalFooter` so its sticky offsets match whichever body padding is
 * in force, and so its own controls can take the 44px touch floor only in the
 * sheet presentation. A footer cannot infer this from the viewport — an
 * explicitly forced `presentation="dialog"` on a phone must keep dialog-sized
 * actions.
 */
const PresentationContext = createContext<{ sheet: boolean }>({ sheet: false });

/**
 * Focusable element selector — matches all standard interactive elements
 * plus elements with explicit tabindex >= 0.
 */
const FOCUSABLE =
  'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

function getFocusableElements(container: HTMLElement): HTMLElement[] {
  return Array.from(container.querySelectorAll<HTMLElement>(FOCUSABLE)).filter(
    (element) => !element.closest('[hidden], [aria-hidden="true"], [inert]'),
  );
}

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

      const focusableElements = getFocusableElements(container);
      if (focusableElements.length === 0) {
        // Nothing focusable inside — prevent focus from leaving the overlay.
        e.preventDefault();
        return;
      }

      const first = focusableElements[0];
      const last = focusableElements[focusableElements.length - 1];

      const activeInside = document.activeElement instanceof HTMLElement && container.contains(document.activeElement);

      if (e.shiftKey) {
        // Shift+Tab: wrap to last if we're on the first element
        if (!activeInside || document.activeElement === first) {
          e.preventDefault();
          last.focus();
        }
      } else {
        // Tab: wrap to first if we're on the last element
        if (!activeInside || document.activeElement === last) {
          e.preventDefault();
          first.focus();
        }
      }
    };

    // Focus the first focusable element inside the modal on open.
    const frame = requestAnimationFrame(() => {
      const focusableElements = getFocusableElements(container);
      if (focusableElements.length > 0) {
        focusableElements[0].focus();
      } else {
        container.focus();
      }
    });

    document.addEventListener('keydown', handleKeyDown);
    return () => {
      cancelAnimationFrame(frame);
      document.removeEventListener('keydown', handleKeyDown);
      if (previousElement && document.contains(previousElement)) previousElement.focus();
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
  presentation = 'auto',
  children,
  className,
}: ModalProps) {
  const dialogRef = useRef<HTMLDivElement>(null);
  const titleId = useId();

  const isPhone = useIsPhone();
  const sheet = presentation === 'sheet' || (presentation === 'auto' && isPhone);

  // Focus trap — keeps Tab cycling within the modal.
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

  /* A sheet always shows its header, even without a title: the close affordance
   * must exist on the surface that covers the screen edge to edge. On a phone
   * there is no visible backdrop area to tap, so a titleless sheet with no
   * button would be uncloseable except by Esc — a key a phone does not have. */
  const hasHeader = Boolean(title) || sheet;
  // A dialog with no name is unusable to a screen reader; axe flags it as
  // `aria-dialog-name`. Titled dialogs are the norm, so this only covers the
  // legacy untitled call sites.
  const hasName = hasHeader || Boolean(ariaLabelledBy);

  return (
    <div
      className={cn(
        'fixed inset-0 z-50 flex justify-center',
        sheet ? 'items-end' : 'items-center px-4 sm:px-6',
      )}
      role="dialog"
      aria-modal="true"
      aria-labelledby={hasHeader ? titleId : ariaLabelledBy}
      aria-describedby={ariaDescribedBy}
      aria-label={hasName ? undefined : 'Dialog'}
    >
      <div
        className="fixed inset-0 bg-black/60 transition-opacity"
        aria-hidden="true"
        onMouseDown={() => {
          if (closeOnOverlayClick) onClose();
        }}
      />

      <div
        ref={dialogRef}
        tabIndex={-1}
        data-presentation={sheet ? 'sheet' : 'dialog'}
        className={cn(
          'relative w-full bg-canvas border border-default flex flex-col max-h-[90vh] animate-slide-up',
          sheet
            ? // Edge to edge, top corners only, and clear of the iOS home
              // indicator — a footer button flush against the bottom edge is a
              // button the thumb cannot press.
              'rounded-t-lg rounded-b-none border-x-0 border-b-0 pb-[env(safe-area-inset-bottom)]'
            : ['rounded-lg', sizes[size]],
          className,
        )}
      >
        <PresentationContext.Provider value={{ sheet }}>
          {hasHeader && (
            <div
              className={cn(
                'border-b border-default bg-surface shrink-0 flex items-center justify-between',
                sheet ? 'px-4 py-2.5' : 'px-5 py-4 rounded-t-md',
              )}
            >
              {title ? (
                <h2 id={titleId} className="text-base font-medium text-primary m-0">{title}</h2>
              ) : (
                <h2 id={titleId} className="sr-only">Dialog</h2>
              )}
              <button
                onClick={onClose}
                type="button"
                className="min-h-11 min-w-11 inline-flex items-center justify-center text-muted hover:text-primary transition-colors focus:outline-none focus:ring-2 focus:ring-accent rounded-sm"
                aria-label="Close modal"
              >
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
              </button>
            </div>
          )}
          <div className={cn('overflow-y-auto overflow-x-hidden', sheet ? 'p-4' : 'p-5')}>
            {children}
          </div>
        </PresentationContext.Provider>
      </div>
    </div>
  );
}

export function ModalFooter({ children, className }: { children: ReactNode; className?: string }) {
  const { sheet } = useContext(PresentationContext);

  return (
    <div
      className={cn(
        'border-t border-default bg-surface flex items-center justify-end gap-2 z-10',
        sheet
          ? // Thumb-first footer: sticky to the bottom of the sheet, padded for
            // the home indicator, and every shared action gets the 44px floor
            // (`min-h-11`). The buttons stay side by side — stacking them would
            // change every dialog's hierarchy for a phone-only gain.
            'sticky bottom-0 -mx-4 -mb-4 mt-4 px-4 pt-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))] [&>button]:min-h-11 [&>a]:min-h-11'
          : 'sticky bottom-[-20px] -mx-5 -mb-5 mt-5 px-5 py-4 rounded-b-md',
        className,
      )}
    >
      {children}
    </div>
  );
}
