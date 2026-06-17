import { type ReactNode } from 'react';
import { DatumMark } from '@/pages/landing/components/DatumMark';
import {
  AlertCircle,
  Inbox,
  Search,
  SearchX,
  Users,
  FileQuestion,
  Lock,
  Factory,
  Package,
  Wrench,
  Truck,
  Receipt,
  DollarSign,
  Clipboard,
  BarChart3,
  Box,
  Calendar,
  Shield,
  ShoppingCart,
  Beaker,
  ClipboardList,
  type LucideIcon,
} from 'lucide-react';
import { cn } from '@/lib/cn';

const ICONS: Record<string, LucideIcon> = {
  'alert-circle': AlertCircle,
  inbox: Inbox,
  search: Search,
  'search-x': SearchX,
  users: Users,
  'file-question': FileQuestion,
  lock: Lock,
  // Series X / Task X3 — context-specific icons.
  factory: Factory,
  package: Package,
  wrench: Wrench,
  truck: Truck,
  receipt: Receipt,
  'dollar-sign': DollarSign,
  clipboard: Clipboard,
  'bar-chart': BarChart3,
  box: Box,
  calendar: Calendar,
  shield: Shield,
  'shopping-cart': ShoppingCart,
  beaker: Beaker,
  'clipboard-list': ClipboardList,
};

export type EmptyStateIcon = keyof typeof ICONS;

interface EmptyStateProps {
  icon?: EmptyStateIcon;
  title: string;
  description?: string;
  action?: ReactNode;
  className?: string;
  /**
   * Series X / Task X3 — when supplied, the title/description default to the
   * search-empty variant (caller can still override). Useful for list pages
   * with active filters.
   */
  searchTerm?: string;
  /** Plural noun for the items being searched, e.g. "employees". */
  itemNoun?: string;
}

export function EmptyState({
  icon,
  title,
  description,
  action,
  className,
  searchTerm,
  itemNoun = 'results',
}: EmptyStateProps) {
  // Compute the resolved view: if searchTerm is supplied and no explicit
  // override, use the standard search-empty messaging.
  const resolvedIcon = icon ?? (searchTerm ? 'search-x' : 'inbox');
  const resolvedTitle = title || (searchTerm ? `No ${itemNoun} match "${searchTerm}"` : '');
  const resolvedDescription =
    description ?? (searchTerm ? 'Try adjusting your search terms or clearing the filters.' : undefined);

  const Icon = ICONS[resolvedIcon] ?? Inbox;

  return (
    <div className={cn('flex flex-col items-center justify-center py-12 px-6 text-center', className)}>
      {/* Brand motif: faint DatumMark behind the icon cluster */}
      <div className="relative flex items-center justify-center mb-3">
        <DatumMark
          size={72}
          strokeWidth={0.8}
          solidCore={false}
          className="absolute text-border-strong opacity-30 pointer-events-none"
          aria-hidden
        />
        <div className="relative w-10 h-10 rounded-full bg-elevated flex items-center justify-center text-muted">
          <Icon size={20} />
        </div>
      </div>
      <h3 className="text-md font-medium text-primary mb-1">{resolvedTitle}</h3>
      {resolvedDescription && (
        <p className="text-sm text-muted max-w-md mb-4">{resolvedDescription}</p>
      )}
      {action}
    </div>
  );
}
