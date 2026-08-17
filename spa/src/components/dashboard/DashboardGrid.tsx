import { type CSSProperties, type ReactNode } from 'react';
import { cn } from '@/lib/cn';

import { WidgetErrorBoundary } from '@/components/ui/WidgetErrorBoundary';
const WIDTH_CLASSES: Record<number, string> = {
  1: 'lg:col-span-1',
  2: 'lg:col-span-2',
  3: 'lg:col-span-3',
  4: 'lg:col-span-4',
  5: 'lg:col-span-5',
  6: 'lg:col-span-6',
  7: 'lg:col-span-7',
  8: 'lg:col-span-8',
  9: 'lg:col-span-9',
  10: 'lg:col-span-10',
  11: 'lg:col-span-11',
  12: 'lg:col-span-12',
};

export function DashboardGrid({ children, className }: { children: ReactNode; className?: string }) {
  return <div className={cn('grid grid-cols-1 gap-3 lg:grid-cols-12', className)}>{children}</div>;
}

export function DashboardGridItem({
  width,
  height,
  children,
  className,
}: {
  width: number;
  height: number;
  children: ReactNode;
  className?: string;
}) {
  const safeWidth = Math.max(1, Math.min(12, Math.round(width)));
  const safeHeight = Math.max(4, Math.min(12, Math.round(height)));
  const style = { minHeight: `${safeHeight * 24}px` } satisfies CSSProperties;

  return (
    <div className={cn('col-span-1 min-w-0', WIDTH_CLASSES[safeWidth], className)} style={style}>
      {/* One cell is one widget, so this is the seam where isolation belongs:
          a widget that throws should cost the user that widget, not the whole
          dashboard. WidgetErrorBoundary existed for exactly this and was wired
          into one widget on one of eleven dashboards. */}
      <WidgetErrorBoundary>{children}</WidgetErrorBoundary>
    </div>
  );
}

