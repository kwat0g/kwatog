import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

/**
 * Keeps the primary form action available while a long touch form is being
 * reviewed. The bar sits above TouchShell's bottom tabs on small screens and
 * returns to normal document flow on desktop.
 */
export function StickyActionBar({ children, className }: { children: ReactNode; className?: string }) {
 return (
 <div
 className={cn(
 'sticky bottom-14 z-20 -mx-4 mt-2 border-t border-default bg-canvas px-4 py-3 safe-area-pb',
 'md:static md:mx-0 md:border-t-0 md:bg-transparent md:px-0 md:py-0',
 className,
 )}
 >
 <div className="flex items-center gap-2">{children}</div>
 </div>
 );
}
