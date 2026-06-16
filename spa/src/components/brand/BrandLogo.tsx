/**
 * BrandLogo — the Ogami emblem (gear + mountain + OGAMI wordmark).
 *
 * Renders the trimmed brand mark from src/assets. The artwork is pure black on
 * transparent, so it sits naturally on the light/warm surfaces; pass
 * `invertOnDark` on surfaces that support the ERP dark theme to flip it white.
 *
 * Size with a height utility via `className` (e.g. `h-8`); width auto-scales.
 */

import logoUrl from '@/assets/brand-logo.png';
import { cn } from '@/lib/cn';

interface BrandLogoProps {
  className?: string;
  /** Flip to white under [data-theme="dark"] (app shell surfaces). */
  invertOnDark?: boolean;
  alt?: string;
}

export function BrandLogo({ className, invertOnDark = false, alt = 'Ogami' }: BrandLogoProps) {
  return (
    <img
      src={logoUrl}
      alt={alt}
      draggable={false}
      className={cn('w-auto select-none', invertOnDark && 'dark:invert', className)}
    />
  );
}
