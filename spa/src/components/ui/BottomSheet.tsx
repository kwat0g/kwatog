import { type ReactNode } from 'react';
import { Modal } from './Modal';

interface BottomSheetProps {
  isOpen: boolean;
  onClose: () => void;
  title?: ReactNode;
  children: ReactNode;
  className?: string;
}

/**
 * Mobile bottom sheet — now a *forced* presentation of the one dialog
 * implementation rather than a second one.
 *
 * It used to be its own component: its own Esc listener, its own scroll lock,
 * its own backdrop, and **no focus trap** — so a keyboard or switch-control user
 * who opened the self-service leave sheet could tab straight into the page
 * behind it. There is no second dialog implementation worth maintaining: the
 * sheet is `Modal` with `presentation="sheet"`, which already owns the focus
 * trap, the topmost-only Esc stack, the scroll lock and the labelled dialog
 * role. The call sites do not change.
 *
 * Use this when a surface is phone-only by design (the self-service and
 * shop-floor flows); use `Modal` elsewhere and let it choose.
 */
export function BottomSheet({ isOpen, onClose, title, children, className }: BottomSheetProps) {
  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={title}
      presentation="sheet"
      className={className}
    >
      {children}
    </Modal>
  );
}
