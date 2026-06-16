import { useEffect, useState } from 'react';
import { X } from 'lucide-react';
import { cn } from '@/lib/cn';

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
        'rounded-2xl border border-landing-border bg-landing-surface/95 p-4 shadow-menu backdrop-blur-xl',
        'motion-safe:animate-slide-up',
      )}
    >
      <div className="flex items-start gap-4">
        <p className="flex-1 text-[13px] leading-relaxed text-landing-text-secondary">
          We use cookies to understand how visitors use our site and to improve
          your experience. Read our{' '}
          <a
            href="#"
            className="text-landing-accent underline-offset-2 hover:underline"
            onClick={(e) => e.preventDefault()}
          >
            Privacy Policy
          </a>
          .
        </p>
        <button
          type="button"
          onClick={() => handleConsent('declined')}
          className="rounded-md p-1 text-landing-muted transition-colors hover:bg-landing-elevated hover:text-landing-text"
          aria-label="Decline cookies"
        >
          <X size={16} />
        </button>
      </div>
      <div className="mt-3 flex items-center gap-2">
        <button
          type="button"
          onClick={() => handleConsent('accepted')}
          className="rounded-full bg-landing-accent px-4 py-2 text-xs font-semibold text-landing-accent-fg transition-colors hover:bg-landing-accent-hover"
        >
          Accept
        </button>
        <button
          type="button"
          onClick={() => handleConsent('declined')}
          className="rounded-full border border-landing-border px-4 py-2 text-xs font-medium text-landing-text transition-colors hover:bg-landing-elevated"
        >
          Decline
        </button>
      </div>
    </div>
  );
}
