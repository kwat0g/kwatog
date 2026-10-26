import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

export type PageVariant = 'fluid' | 'grid' | 'narrow';

export type PageContainerProps = {
  children: ReactNode;
  variant?: PageVariant;
  className?: string;
};

type PageShellProps = PageContainerProps & { viewport?: boolean };

function PageFrame({
  children,
  variant = 'fluid',
  viewport = false,
  className,
  layout,
}: PageShellProps & { layout: 'page-shell' | 'page-container' }) {
  return (
    <div
      data-layout={layout}
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

export function PageShell(props: PageShellProps) {
  return <PageFrame {...props} layout="page-shell" />;
}

export function PageContainer(props: PageContainerProps) {
  return <PageFrame {...props} layout="page-container" />;
}
