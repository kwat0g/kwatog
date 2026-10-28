import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

type PageShellProps = {
  children: ReactNode;
  variant?: 'fluid' | 'grid' | 'narrow';
  viewport?: boolean;
  className?: string;
};

export function PageShell({
  children,
  variant = 'fluid',
  viewport = false,
  className,
}: PageShellProps) {
  return (
    <div
      data-layout="page-shell"
      className={cn(
        'page-shell',
        variant === 'grid' && 'page-shell--grid',
        variant === 'narrow' && 'page-shell--narrow',
        viewport && 'page-shell--viewport',
        className,
      )}
    >
      {children}
    </div>
  );
}
