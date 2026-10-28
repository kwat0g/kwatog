import type { ReactNode } from 'react';
import { stableIndex } from '@/lib/stableIndex';
import { cn } from '@/lib/cn';

const categoryClasses = [
  'border-l-category-1',
  'border-l-category-2',
  'border-l-category-3',
  'border-l-category-4',
  'border-l-category-5',
  'border-l-category-6',
  'border-l-category-7',
  'border-l-category-8',
] as const;

export function Tag({
  categoryKey,
  children,
  className,
}: {
  categoryKey: string;
  children: ReactNode;
  className?: string;
}) {
  const category = stableIndex(categoryKey, categoryClasses.length);
  return (
    <span
      className={cn(
        'inline-flex min-h-6 items-center border border-default border-l-2 bg-canvas px-2 text-caption text-secondary',
        categoryClasses[category],
        className,
      )}
    >
      {children}
    </span>
  );
}
