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
  /*
   * No negative horizontal offset, and that is a fix rather than an omission.
   * The bar used to bleed by a hardcoded `-mx-4` to reach the screen edges,
   * which silently assumed its container was padded 16px. Where the assumption
   * held (16px-padded forms) the bar stopped 4px short of the edge
   * anyway; where it did not (`/quality/ncr-templates/new`, whose form pads by
   * 20px) the bar hung 32px out of its container — an overflow no page could
   * see, only the probe (F-021). Filling the container instead means the rule
   * above the buttons lines up with the fields they submit, on every page, by
   * construction.
   */
  return (
    <div
      className={cn(
        'sticky z-20 mt-2 border-t border-default bg-canvas py-3 safe-area-pb',
        offsets[offset],
        'md:static md:border-t-0 md:bg-transparent md:py-0',
        className,
      )}
    >
      <div className="flex items-center gap-2">{children}</div>
    </div>
  );
}
