import { useEffect, type ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface BottomSheetProps {
  isOpen: boolean;
  onClose: () => void;
  title?: ReactNode;
  children: ReactNode;
  className?: string;
}

/**
 * Mobile bottom sheet. Slides up from the bottom edge, covers up to ~90vh.
 * Used by the self-service Apply-for-Leave and profile-update flows.
 */
export function BottomSheet({ isOpen, onClose, title, children, className }: BottomSheetProps) {
  useEffect(() => {
    if (!isOpen) return;
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    document.addEventListener('keydown', onKey);
    const original = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = original;
    };
  }, [isOpen, onClose]);

  if (!isOpen) return null;

  return (
    <div
      className="fixed inset-0 z-50 flex items-end justify-center"
      role="dialog"
      aria-modal="true"
    >
      <button
        type="button"
        aria-label="Close"
        onClick={onClose}
        className="absolute inset-0 bg-black/40"
      />
      <div
        className={cn(
          'relative w-full max-h-[90vh] bg-canvas rounded-t-lg border-t border-default shadow-menu flex flex-col',
          className,
        )}
      >
        {title && (
          <div className="px-4 py-3 border-b border-default">
            <h2 className="text-md font-medium">{title}</h2>
          </div>
        )}
        <div className="overflow-auto p-4">{children}</div>
      </div>
    </div>
  );
}
