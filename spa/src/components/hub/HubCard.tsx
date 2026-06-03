import { type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { ArrowRight, type LucideIcon } from 'lucide-react';
import { cn } from '@/lib/cn';

interface HubCardProps {
  title: string;
  icon?: LucideIcon;
  viewAllHref?: string;
  viewAllLabel?: string;
  children: ReactNode;
  className?: string;
}

export function HubCard({ title, icon: Icon, viewAllHref, viewAllLabel, children, className }: HubCardProps) {
  return (
    <div className={cn('bg-canvas border border-default rounded-md overflow-hidden', className)}>
      <div className="flex items-center justify-between px-4 py-3 border-b border-default">
        <div className="flex items-center gap-2">
          {Icon && <Icon size={16} className="text-muted" />}
          <h3 className="text-sm font-medium text-primary">{title}</h3>
        </div>
        {viewAllHref && (
          <Link to={viewAllHref} className="text-xs text-accent hover:underline inline-flex items-center gap-1">
            {viewAllLabel || 'View all'} <ArrowRight size={12} />
          </Link>
        )}
      </div>
      <div className="p-4">{children}</div>
    </div>
  );
}
