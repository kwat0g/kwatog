import { cn } from '@/lib/cn';

export interface PasswordStrengthProps {
 password: string;
 className?: string;
 minimumLength?: number;
}

function scorePassword(password: string, minimumLength: number): { score: number; label: string; color: string } {
 let score = 0;
 if (password.length >= minimumLength) score += 1;
 if (password.length >= Math.max(minimumLength + 4, 12)) score += 1;
 if (/[A-Z]/.test(password)) score += 1;
 if (/[a-z]/.test(password)) score += 1;
 if (/[0-9]/.test(password)) score += 1;
 if (/[^A-Za-z0-9]/.test(password)) score += 1;

 if (password.length === 0) return { score: 0, label: '', color: 'bg-transparent' };
 if (score <= 2) return { score, label: 'Weak', color: 'bg-danger-bg' };
 if (score <= 4) return { score, label: 'Fair', color: 'bg-warning-bg' };
 return { score, label: 'Strong', color: 'bg-success-bg' };
}

export function PasswordStrength({ password, className, minimumLength = 8 }: PasswordStrengthProps) {
 const { score, label, color } = scorePassword(password, minimumLength);
 const segments = 5;

 if (!password) return null;

 return (
 <div className={cn('space-y-1.5', className)}>
 <div className="flex gap-1">
 {Array.from({ length: segments }).map((_, i) => (
 <div
 key={i}
 className={cn(
 'h-1 flex-1 rounded-full bg-elevated transition-colors duration-fast',
 i < score && color,
 )}
 />
 ))}
 </div>
 <div className="flex justify-between text-2xs">
 <span className="text-muted">Password strength</span>
 <span className={cn('font-medium', color.replace('bg-', 'text-'))}>{label}</span>
 </div>
 </div>
 );
}
