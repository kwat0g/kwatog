import { IconType } from '@/lib/icons';
import { type ReactNode } from 'react';
import { DatumMark } from '@/components/brand/DatumMark';
import {
  LuActivity,
  LuCircleAlert,
  LuTriangleAlert,
  LuArrowLeftRight,
  LuBell,
  LuBellOff,
  LuBriefcase,
  LuInbox,
  LuSearch,
  LuSearchX,
  LuUsers,
  LuUserX,
  LuFileQuestion,
  LuFileText,
  LuFileX,
  LuCog,
  LuCpu,
  LuGitBranch,
  LuGrid3X3,
  LuLayers,
  LuLock,
  LuFactory,
  LuMonitor,
  LuPackage,
  LuPercent,
  LuMessageSquare,
  LuTrendingUp,
  LuWrench,
  LuTruck,
  LuReceipt,
  LuDollarSign,
  LuCircleCheck,
  LuClipboard,
  LuClipboardCheck,
  LuChartColumnIncreasing,
  LuBox,
  LuCalendar,
  LuShield,
  LuShoppingCart,
  LuBeaker,
  LuClipboardList,

} from '@/lib/icons';
import { cn } from '@/lib/cn';

/**
 * Every icon name any page passes must exist here. `EmptyStateIcon` is derived
 * from these keys, so a typo is a type error rather than a silent fallback to
 * the inbox glyph (which is how "check-circle" empties spent months looking
 * like "nothing here" instead of "all clear").
 */
const ICONS = {
  'alert-circle': LuCircleAlert,
  'alert-triangle': LuTriangleAlert,
  inbox: LuInbox,
  search: LuSearch,
  'search-x': LuSearchX,
  users: LuUsers,
  'user-x': LuUserX,
  'file-question': LuFileQuestion,
  'file-text': LuFileText,
  'file-x': LuFileX,
  lock: LuLock,
  // Series X / Task X3 — context-specific icons.
  factory: LuFactory,
  package: LuPackage,
  wrench: LuWrench,
  truck: LuTruck,
  receipt: LuReceipt,
  'dollar-sign': LuDollarSign,
  clipboard: LuClipboard,
  'clipboard-check': LuClipboardCheck,
  'bar-chart': LuChartColumnIncreasing,
  box: LuBox,
  calendar: LuCalendar,
  shield: LuShield,
  'shopping-cart': LuShoppingCart,
  beaker: LuBeaker,
  'clipboard-list': LuClipboardList,
  // Success / status empties — "all clear" reads differently from "no data".
  'check-circle': LuCircleCheck,
  'circle-check': LuCircleCheck,
  activity: LuActivity,
  bell: LuBell,
  'bell-off': LuBellOff,
  briefcase: LuBriefcase,
  cog: LuCog,
  cpu: LuCpu,
  'git-branch': LuGitBranch,
  grid: LuGrid3X3,
  layers: LuLayers,
  monitor: LuMonitor,
  'message-square': LuMessageSquare,
  percent: LuPercent,
  'trending-up': LuTrendingUp,
  'arrow-left-right': LuArrowLeftRight,
  'arrow-right-left': LuArrowLeftRight,
} satisfies Record<string, IconType>;

export type EmptyStateIcon = keyof typeof ICONS;

interface EmptyStateProps {
  icon?: EmptyStateIcon;
  title: string;
  /**
   * ReactNode, not string — a denial or error state often needs to set one
   * token inline (a permission slug, a document number) in monospace so the
   * user can quote it accurately.
   */
  description?: ReactNode;
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

  const Icon = ICONS[resolvedIcon] ?? LuInbox;
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
