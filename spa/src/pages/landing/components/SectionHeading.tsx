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
        <span className="h-0.5 w-8 bg-landing-accent" />
        <ScrambleText
          text={eyebrow}
          trigger="view"
          className="font-mono text-[11px] uppercase tracking-[0.24em] text-landing-accent"
        />
      </div>

      <h2
        data-reveal
        data-reveal-delay="0.05"
        className="mt-5 font-display text-[clamp(2.1rem,4.8vw,3.75rem)] font-bold leading-[1.04] tracking-[-0.02em] text-landing-text"
      >
        {title}
      </h2>

      {intro ? (
        <p
          data-reveal
          data-reveal-delay="0.1"
          className={cn(
            'mt-5 font-sans text-[15px] leading-relaxed text-landing-text-secondary sm:text-base',
            centered && 'mx-auto',
          )}
        >
          {intro}
        </p>
      ) : null}
    </div>
  );
}
