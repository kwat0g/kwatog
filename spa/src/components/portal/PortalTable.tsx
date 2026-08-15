import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

/**
 * Keeps dense ERP tables readable on narrow screens without forcing the whole
 * portal shell to scroll sideways. The table keeps its office density; only
 * the data surface becomes horizontally scrollable when it needs the room.
 */
export function PortalTable({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <div className={cn('w-full overflow-x-auto overscroll-x-contain', '[&_table]:min-w-[600px]', className)}>
      {children}
    </div>
  );
}
