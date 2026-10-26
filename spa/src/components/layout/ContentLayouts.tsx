import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface FormGridProps {
  children: ReactNode;
  /** Maximum columns at wide sizes. Narrow screens always use one column. */
  columns?: 2 | 3 | 4;
  className?: string;
}

export function FormGrid({ children, columns = 3, className }: FormGridProps) {
  return (
    <div data-layout="form-grid" data-columns={columns} className={cn('form-grid', className)}>
      {children}
    </div>
  );
}

interface DetailLayoutProps {
  children: ReactNode;
  aside?: ReactNode;
  className?: string;
}

export function DetailLayout({ children, aside, className }: DetailLayoutProps) {
  return (
    <div data-layout="detail-layout" className={cn('detail-layout', aside && 'detail-layout--with-aside', className)}>
      <div className="detail-layout__main">{children}</div>
      {aside && <aside className="detail-layout__aside">{aside}</aside>}
    </div>
  );
}
