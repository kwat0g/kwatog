import { cn } from '@/lib/cn';

interface AvatarProps {
  src?: string | null;
  name?: string;
  size?: 'sm' | 'md' | 'lg';
  className?: string;
}

const sizes = { sm: 'h-6 w-6 text-[10px]', md: 'h-7 w-7 text-xs', lg: 'h-8 w-8 text-sm' } as const;

const initials = (name?: string): string =>
  (name ?? '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((s) => s[0]?.toUpperCase() ?? '')
    .join('') || '?';

export function Avatar({ src, name, size = 'md', className }: AvatarProps) {
  return (
    <span
      title={name}
      className={cn(
        'inline-flex items-center justify-center rounded-full bg-elevated text-muted font-medium select-none',
        sizes[size],
        className,
      )}
    >
      {src ? (
        <img src={src} alt={name ?? ''} className="h-full w-full rounded-full object-cover" />
      ) : (
        initials(name)
      )}
    </span>
  );
}
