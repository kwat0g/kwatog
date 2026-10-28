import { cn } from '@/lib/cn';
import { stableIndex } from '@/lib/stableIndex';

interface AvatarProps {
 src?: string | null;
 name?: string;
 identityKey?: string;
 size?: 'sm' | 'md' | 'lg';
 className?: string;
}

const sizes = { sm: 'h-6 w-6 text-xs', md: 'h-row-compact w-row-compact text-label', lg: 'h-row w-row text-subsection' } as const;

const tintClasses = [
 'bg-avatar-1-bg text-avatar-1-fg border-avatar-1',
 'bg-avatar-2-bg text-avatar-2-fg border-avatar-2',
 'bg-avatar-3-bg text-avatar-3-fg border-avatar-3',
 'bg-avatar-4-bg text-avatar-4-fg border-avatar-4',
 'bg-avatar-5-bg text-avatar-5-fg border-avatar-5',
 'bg-avatar-6-bg text-avatar-6-fg border-avatar-6',
] as const;

const initials = (name?: string): string =>
 (name ?? '')
 .split(/\s+/)
 .filter(Boolean)
 .slice(0, 2)
 .map((s) => s[0]?.toUpperCase() ?? '')
 .join('') || '?';

export function Avatar({ src, name, identityKey, size = 'md', className }: AvatarProps) {
 const tint = stableIndex(identityKey?.trim() || name || '', tintClasses.length);
 return (
 <span
 title={name}
  role="img"
  aria-label={name || 'Unknown person'}
 className={cn(
 'inline-flex shrink-0 items-center justify-center rounded-full border font-medium select-none',
 tintClasses[tint],
 sizes[size],
 className,
 )}
 >
 {src ? (
 <img src={src} alt="" aria-hidden="true" className="h-full w-full rounded-full object-cover" />
 ) : (
 initials(name)
 )}
 </span>
 );
}
