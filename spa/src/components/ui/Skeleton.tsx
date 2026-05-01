import { cn } from '@/lib/cn';

interface BlockProps {
  className?: string;
}

export function SkeletonBlock({ className }: BlockProps) {
  return <div className={cn('rounded bg-elevated animate-pulse', className)} />;
}

interface SkeletonTableProps {
  columns?: number;
  rows?: number;
}

export function SkeletonTable({ columns = 6, rows = 10 }: SkeletonTableProps) {
  return (
    <div className="border border-default rounded-md overflow-hidden">
      <div className="h-8 border-b border-default bg-subtle flex items-center px-2.5 gap-4">
        {Array.from({ length: columns }).map((_, i) => (
          <SkeletonBlock key={i} className="h-2.5 w-16" />
        ))}
      </div>
      {Array.from({ length: rows }).map((_, i) => (
        <div key={i} className="h-8 border-b border-subtle flex items-center px-2.5 gap-4">
          {Array.from({ length: columns }).map((_, j) => (
            <SkeletonBlock
              key={j}
              className="h-2.5"
              // Stable widths per column so the skeleton doesn't flicker on re-render.
              style={{ width: `${40 + ((i * 7 + j * 11) % 60)}px` }}
            />
          ))}
        </div>
      ))}
    </div>
  );
}

export function SkeletonDetail() {
  return (
    <div className="px-5 py-4">
      <SkeletonBlock className="h-5 w-48 mb-4" />
      <div className="grid grid-cols-4 gap-2 mb-6">
        {[1, 2, 3, 4].map((i) => (
          <SkeletonBlock key={i} className="h-16" />
        ))}
      </div>
      <SkeletonTable columns={5} rows={4} />
    </div>
  );
}

export function SkeletonForm() {
  return (
    <div className="max-w-3xl mx-auto px-5 py-6">
      {[1, 2, 3].map((section) => (
        <div key={section} className="mb-8">
          <SkeletonBlock className="h-3 w-32 mb-4" />
          <div className="grid grid-cols-2 gap-3">
            {[1, 2, 3, 4].map((i) => (
              <SkeletonBlock key={i} className="h-8" />
            ))}
          </div>
        </div>
      ))}
    </div>
  );
}
