import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { StickyActionBar } from './StickyActionBar';

/**
 * The submit/cancel row of a form.
 *
 * Exists because ~50 forms each wrote their own
 * `<div className="flex items-center justify-end gap-2 pt-4 border-t …">`, and
 * none of them was reachable on a phone: the row sits at the bottom of a form
 * that can be several screens tall, so submitting meant scrolling back down
 * past everything you had just filled in. StickyActionBar solved exactly this
 * and was wired into the two touch PWAs only.
 *
 * Sticky below `md`, ordinary flow above it.
 */
export function FormActions({
  children,
  className,
  align = 'end',
}: {
  children: ReactNode;
  className?: string;
  /** `between` puts a destructive action opposite the primary one. */
  align?: 'end' | 'between';
}) {
  return (
    <StickyActionBar
      offset="none"
      className={cn('md:pt-4 md:border-t md:border-default', className)}
    >
      <div
        className={cn(
          'flex w-full items-center gap-2',
          align === 'between' ? 'justify-between' : 'justify-end',
        )}
      >
        {children}
      </div>
    </StickyActionBar>
  );
}
