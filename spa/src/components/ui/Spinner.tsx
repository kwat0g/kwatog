import { cn } from '@/lib/cn';

interface SpinnerProps {
  size?: 'sm' | 'md' | 'lg';
  className?: string;
}

const sizes = { sm: 'h-3 w-3', md: 'h-4 w-4', lg: 'h-5 w-5' } as const;

export function Spinner({ size = 'md', className }: SpinnerProps) {
  return (
    <span
      role="status"
      aria-label="Loading"
      className={cn('inline-block animate-spin rounded-full border-2 border-current border-r-transparent', sizes[size], className)}
    />
  );
}

export function FullPageLoader() {
  return (
    <div className="min-h-screen w-full flex items-center justify-center bg-canvas">
      <Spinner size="lg" className="text-muted" />
    </div>
  );
}
