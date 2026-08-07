/**
 * SectionHeading — consistent eyebrow / title / intro block used across the
 * marketing sections. The mono eyebrow with a leading rule reads as a precise,
 * engineering-grade label; the display title carries the message.
 */

import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { ScrambleText } from './ScrambleText';

interface SectionHeadingProps {
  eyebrow: string;
  title: ReactNode;
  intro?: ReactNode;
  align?: 'left' | 'center';
  className?: string;
}

export function SectionHeading({
  eyebrow,
  title,
  intro,
  align = 'left',
  className,
}: SectionHeadingProps) {
  const centered = align === 'center';
  return (
    <div
      className={cn(
        'max-w-2xl',
        centered && 'mx-auto text-center',
        className,
      )}
    >
      <div
        data-reveal
        className={cn(
          'flex items-center gap-3',
          centered && 'justify-center',
        )}
      >
        <span className="h-0.5 w-8 bg-accent" />
        <ScrambleText
          text={eyebrow}
          trigger="view"
          className="font-mono text-[11px] uppercase tracking-[0.24em] text-accent"
        />
      </div>

      <h2
        data-reveal
        data-reveal-delay="0.05"
        className="mt-6 font-display text-[clamp(2.5rem,5vw,4rem)] font-semibold leading-[0.98] tracking-[-0.03em] text-primary"
      >
        {title}
      </h2>

      {intro ? (
        <p
          data-reveal
          data-reveal-delay="0.1"
          className={cn(
            'mt-6 font-sans text-base leading-relaxed text-secondary sm:text-lg font-light tracking-wide',
            centered && 'mx-auto',
          )}
        >
          {intro}
        </p>
      ) : null}
    </div>
  );
}
