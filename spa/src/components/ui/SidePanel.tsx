import type { ReactNode } from 'react';
import { Modal } from './Modal';

export function SidePanel({
  isOpen,
  onClose,
  title,
  children,
  className,
}: {
  isOpen: boolean;
  onClose: () => void;
  title: ReactNode;
  children: ReactNode;
  className?: string;
}) {
  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={title}
      presentation="side"
      className={className}
    >
      {children}
    </Modal>
  );
}
