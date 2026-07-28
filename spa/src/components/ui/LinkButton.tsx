import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from 'react';
import { cn } from '@/lib/cn';

/**
 * A button that reads as a link. Use it for inline actions that live inside a
 * sentence, a table cell, or a panel header — "Retry", "Clear filters", drilling
 * into a record — where a filled or outlined button would outweigh the text
 * around it. Font size is inherited from the surrounding copy on purpose, and
 * there is no fixed height, so the control sits on the same baseline as its
 * neighbours. For anything that stands on its own, use `Button`.
 */
type Tone = 'accent' | 'danger' | 'muted';

interface LinkButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  icon?: ReactNode;
  /** `danger` for destructive inline actions, `muted` for secondary toggles. */
  tone?: Tone;
}

const toneClasses: Record<Tone, string> = {
  accent: 'text-link hover:text-link-hover',
  danger: 'text-danger',
  muted: 'text-muted hover:text-primary',
};

export const LinkButton = forwardRef<HTMLButtonElement, LinkButtonProps>(
  ({ tone = 'accent', icon, type = 'button', className, children, ...rest }, ref) => (
    <button
      ref={ref}
      type={type}
      className={cn(
        'inline-flex items-center gap-1 cursor-pointer rounded transition-colors duration-fast',
        'hover:underline underline-offset-2',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-1',
        'disabled:opacity-60 disabled:cursor-not-allowed disabled:no-underline',
        toneClasses[tone],
        className,
      )}
      {...rest}
    >
      {icon}
      {children}
    </button>
  ),
);
LinkButton.displayName = 'LinkButton';
