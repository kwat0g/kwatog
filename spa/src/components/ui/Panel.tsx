import { type ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface PanelProps {
 title?: ReactNode;
 meta?: ReactNode;
 actions?: ReactNode;
 children: ReactNode;
 className?: string;
 bodyClassName?: string;
 noPadding?: boolean;
}

export function Panel({ title, meta, actions, children, className, bodyClassName, noPadding }: PanelProps) {
 return (
 <div className={cn('bg-canvas border border-default rounded-2xl overflow-hidden shadow-sm transition-all duration-300 hover:shadow-md hover:-translate-y-0.5', className)}>
 {(title || meta || actions) && (
 <div className="flex items-center justify-between px-4 py-3 border-b border-default">
 <div className="flex items-baseline gap-2">
 {title && <h3 className="text-sm font-medium text-primary">{title}</h3>}
 {meta && <span className="text-xs text-muted">{meta}</span>}
 </div>
 {actions && <div className="flex items-center gap-1.5">{actions}</div>}
 </div>
 )}
 <div className={cn(noPadding ? '' : 'p-4', bodyClassName)}>{children}</div>
 </div>
 );
}
