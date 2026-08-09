import { type ReactNode } from 'react';
import { DatumMark } from '@/components/brand/DatumMark';
import {
  Activity,
  AlertCircle,
  AlertTriangle,
  ArrowLeftRight,
  Bell,
  BellOff,
  Briefcase,
  Inbox,
  Search,
  SearchX,
  Users,
  UserX,
  FileQuestion,
  FileText,
  FileX,
  Cog,
  Cpu,
  GitBranch,
  Grid3x3,
  Layers,
  Lock,
  Factory,
  Monitor,
  Package,
  Percent,
  MessageSquare,
  TrendingUp,
  Wrench,
  Truck,
  Receipt,
  DollarSign,
  CheckCircle2,
  Clipboard,
  ClipboardCheck,
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

/**
 * Every icon name any page passes must exist here. `EmptyStateIcon` is derived
 * from these keys, so a typo is a type error rather than a silent fallback to
 * the inbox glyph (which is how "check-circle" empties spent months looking
 * like "nothing here" instead of "all clear").
 */
const ICONS = {
  'alert-circle': AlertCircle,
  'alert-triangle': AlertTriangle,
  inbox: Inbox,
  search: Search,
  'search-x': SearchX,
  users: Users,
  'user-x': UserX,
  'file-question': FileQuestion,
  'file-text': FileText,
  'file-x': FileX,
  lock: Lock,
  // Series X / Task X3 — context-specific icons.
  factory: Factory,
  package: Package,
  wrench: Wrench,
  truck: Truck,
  receipt: Receipt,
  'dollar-sign': DollarSign,
  clipboard: Clipboard,
  'clipboard-check': ClipboardCheck,
  'bar-chart': BarChart3,
  box: Box,
  calendar: Calendar,
  shield: Shield,
  'shopping-cart': ShoppingCart,
  beaker: Beaker,
  'clipboard-list': ClipboardList,
  // Success / status empties — "all clear" reads differently from "no data".
  'check-circle': CheckCircle2,
  'circle-check': CheckCircle2,
  activity: Activity,
  bell: Bell,
  'bell-off': BellOff,
  briefcase: Briefcase,
  cog: Cog,
  cpu: Cpu,
  'git-branch': GitBranch,
  grid: Grid3x3,
  layers: Layers,
  monitor: Monitor,
  'message-square': MessageSquare,
  percent: Percent,
  'trending-up': TrendingUp,
  'arrow-left-right': ArrowLeftRight,
  'arrow-right-left': ArrowLeftRight,
} satisfies Record<string, LucideIcon>;

export type EmptyStateIcon = keyof typeof ICONS;

interface EmptyStateProps {
  icon?: EmptyStateIcon;
  title: string;
  description?: string;
  action?: ReactNode;
  className?: string;
  /**
   * `compact` is for empties that live inside a Panel body next to other
   * panels — the full-height version pushes a 2-column row out of alignment.
   * Page-level empties stay `default`.
   */
  size?: 'default' | 'compact';
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
  size = 'default',
  searchTerm,
  itemNoun = 'results',
}: EmptyStateProps) {
  // Compute the resolved view: if searchTerm is supplied and no explicit
  // override, use the standard search-empty messaging.
  const resolvedIcon = icon ?? (searchTerm ? 'search-x' : 'inbox');
  const resolvedTitle = title || (searchTerm ? `No ${itemNoun} match "${searchTerm}"` : '');
  const resolvedDescription =
    description ??
    (searchTerm ? 'Try adjusting your search terms or clearing the filters.' : undefined);

  const Icon = ICONS[resolvedIcon] ?? Inbox;
  const compact = size === 'compact';

  return (
    <div
      className={cn(
        'flex flex-col items-center justify-center text-center',
        compact ? 'py-4 px-3' : 'py-8 px-5',
        className,
      )}
    >
      {/* Brand motif: faint DatumMark behind the icon cluster */}
      <div className={cn('relative flex items-center justify-center', compact ? 'mb-2' : 'mb-3')}>
        <DatumMark
          size={compact ? 52 : 72}
          strokeWidth={0.8}
          solidCore={false}
          className="absolute text-border-strong opacity-30 pointer-events-none"
          aria-hidden
        />
        <div
          className={cn(
            'relative rounded-full bg-elevated flex items-center justify-center text-muted',
            compact ? 'w-8 h-8' : 'w-10 h-10',
          )}
        >
          <Icon size={compact ? 16 : 20} />
        </div>
      </div>
      <h3 className={cn('font-medium text-primary mb-1', compact ? 'text-sm' : 'text-md')}>
        {resolvedTitle}
      </h3>
      {resolvedDescription && (
        <p className={cn('text-muted max-w-md', compact ? 'text-xs mb-2' : 'text-sm mb-4')}>
          {resolvedDescription}
        </p>
      )}
      {action}
    </div>
  );
}
