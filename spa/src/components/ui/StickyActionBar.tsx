import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

/**
 * Where the bar has to clear on small screens.
 *
 * `tabs` is TouchShell's geometry — its bottom tab bar occupies the last 56px,
 * so a bar at `bottom-0` would sit underneath it. AppLayout has no bottom
 * chrome, and the hardcoded `bottom-14` was the reason this component only ever
 * reached the two touch PWAs: dropped into any desktop form it floated 56px off
 * the bottom edge for no reason.
 */
type Offset = 'tabs' | 'none';

const offsets: Record<Offset, string> = {
  tabs: 'bottom-14',
  none: 'bottom-0',
};

/**
 * Keeps the primary action reachable while a long form is scrolled. Sticky on
 * small screens, back in normal document flow from `md` up, where the whole
 * form is usually visible at once.
 */
export function StickyActionBar({
  children,
  className,
  offset = 'tabs',
}: {
  children: ReactNode;
  className?: string;
  offset?: Offset;
}) {
  return (
    <div
      className={cn(
        'sticky z-20 -mx-4 mt-2 border-t border-default bg-canvas px-4 py-3 safe-area-pb',
        offsets[offset],
        'md:static md:mx-0 md:border-t-0 md:bg-transparent md:px-0 md:py-0',
        className,
      )}
    >
      <div className="flex items-center gap-2">{children}</div>
    </div>
  );
}
