import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { LuX } from '@/lib/icons';
import { cn } from '@/lib/cn';
import { focusRingLanding } from '@/lib/focus';

const CONSENT_KEY = 'ogami-cookie-consent';

type ConsentState = 'accepted' | 'declined' | null;

export function CookieBanner() {
  const [consent, setConsent] = useState<ConsentState>(null);
  const [mounted, setMounted] = useState(false);

  useEffect(() => {
    const saved = localStorage.getItem(CONSENT_KEY) as ConsentState | null;
    setConsent(saved);
    setMounted(true);
  }, []);

  const handleConsent = (value: ConsentState) => {
    localStorage.setItem(CONSENT_KEY, value as string);
    setConsent(value);
  };

  if (!mounted || consent) return null;

  return (
    <div
      role="dialog"
      aria-label="Cookie consent"
      className={cn(
        'fixed bottom-4 left-4 right-4 z-50 mx-auto max-w-xl',
        'rounded-md border border-default bg-surface p-4-menu shadow-menu',
        'motion-safe:animate-slide-up',
      )}
    >
      <div className="flex items-start gap-4">
        <p className="flex-1 text-[13px] leading-relaxed text-secondary">
          We use only essential cookies to run this site and remember your choice. We don&apos;t use
          advertising or analytics cookies. See our{' '}
          <Link to="/cookies" className="text-accent underline underline-offset-2">
            Cookie Policy
          </Link>
          .
        </p>
        <button
          type="button"
          onClick={() => handleConsent('declined')}
          className={cn(
            'rounded-md p-1 text-muted transition-colors hover:bg-elevated hover:text-primary cursor-pointer',
            focusRingLanding,
          )}
          aria-label="Decline cookies"
        >
          <LuX size={16} />
        </button>
      </div>
      <div className="mt-3 flex items-center gap-2">
        <button
          type="button"
          onClick={() => handleConsent('accepted')}
          className={cn(
            'rounded-full bg-accent px-4 py-2 text-xs font-medium text-accent-fg transition-colors hover:bg-accent-hover cursor-pointer',
            focusRingLanding,
          )}
        >
          Accept
        </button>
        <button
          type="button"
          onClick={() => handleConsent('declined')}
          className={cn(
            'rounded-full border border-default px-4 py-2 text-xs font-medium text-primary transition-colors hover:bg-elevated cursor-pointer',
            focusRingLanding,
          )}
        >
          Decline
        </button>
      </div>
    </div>
  );
}
