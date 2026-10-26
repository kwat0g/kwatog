import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface ToolbarProps {
  children?: ReactNode;
  start?: ReactNode;
  end?: ReactNode;
  className?: string;
  inset?: boolean;
  layout?: string;
}

export function Toolbar({
  children,
  start,
  end,
  className,
  inset = false,
  layout = 'toolbar',
}: ToolbarProps) {
  return (
    <div
      data-layout={layout}
      className={cn('flex min-w-0 flex-wrap items-center justify-between gap-2', inset && 'px-5', className)}
    >
      {(start ?? children) && (
        <div data-toolbar-start className="flex min-w-0 flex-wrap items-center gap-2">
          {start ?? children}
        </div>
      )}
      {end && (
        <div data-toolbar-end className="ml-auto flex flex-wrap items-center gap-2">
          {end}
        </div>
      )}
    </div>
  );
}
