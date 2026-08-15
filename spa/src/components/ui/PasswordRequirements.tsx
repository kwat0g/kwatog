import { LuCheck, LuX } from '@/lib/icons';
import { cn } from '@/lib/cn';
import type { PasswordPolicy } from '@/api/auth';
import { passwordRequirements } from './passwordValidation';

export function PasswordRequirements({
  password,
  policy,
  className,
}: {
  password: string;
  policy?: PasswordPolicy;
  className?: string;
}) {
  return (
    <ul className={cn('space-y-0.5 text-xs', className)} aria-live="polite">
      {passwordRequirements(password, policy).map((requirement) => (
        <li
          key={requirement.key}
          className={cn(
            'flex items-center gap-1.5 transition-colors',
            requirement.passed ? 'text-success-fg' : 'text-muted',
          )}
        >
          {requirement.passed ? <LuCheck size={12} aria-hidden /> : <LuX size={12} aria-hidden />}
          {requirement.label}
        </li>
      ))}
    </ul>
  );
}

export function PasswordMatchHint({ password, confirmation }: { password: string; confirmation: string }) {
  if (!confirmation) return null;

  const matches = password === confirmation;
  return (
    <p
      className={cn('text-xs transition-colors', matches ? 'text-success-fg' : 'text-danger-fg')}
      aria-live="polite"
    >
      {matches ? 'Passwords match.' : 'Passwords do not match.'}
    </p>
  );
}
