/**
 * LandingFooter — closing band for the marketing site.
 *
 * Carries the brand, the Cavite address, the section map, careers,
 * certifications, legal links, a newsletter signup, and a single discreet
 * "Staff login" text link for internal users.
 */

import { useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowRight, CheckCircle } from 'lucide-react';
import { BrandLogo } from '@/components/brand/BrandLogo';
import { COMPANY, NAV_LINKS } from '../data';
import { landingApi } from '@/api/landing';

const FOOTER_LINKS = {
  company: [
    { label: 'About us', href: '#top' },
    { label: 'Careers', href: '#' },
    { label: 'News & insights', href: '#' },
  ],
  quality: [
    { label: 'IATF 16949 certificate', href: '#' },
    { label: 'Quality policy', href: '#' },
    { label: 'Certificate of Conformance', href: '#' },
  ],
  legal: [
    { label: 'Privacy policy', href: '#' },
    { label: 'Terms of service', href: '#' },
    { label: 'Cookie policy', href: '#' },
  ],
};

export function LandingFooter() {
  const year = new Date().getFullYear();
  const [email, setEmail] = useState('');
  const [newsletterStatus, setNewsletterStatus] = useState<'idle' | 'submitting' | 'success' | 'error'>('idle');

  const subscribe = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!email || newsletterStatus === 'submitting') return;
    setNewsletterStatus('submitting');
    try {
      await landingApi.subscribeNewsletter(email);
      setNewsletterStatus('success');
      setEmail('');
    } catch {
      setNewsletterStatus('error');
    }
  };

  return (
    <footer className="relative border-t border-landing-border bg-landing-canvas px-5 py-16 sm:px-8">
      <div className="mx-auto max-w-7xl">
        <div className="grid gap-12 md:grid-cols-2 lg:grid-cols-[1.4fr_1fr_1fr_1fr_1.2fr]">
          {/* Brand + address */}
          <div>
            <BrandLogo alt="Ogami" className="h-11" />
            <p className="mt-4 max-w-xs font-sans text-[13px] leading-relaxed text-landing-muted">
              {COMPANY.legalName} — precision plastic injection molding, proudly
              engineered in the Philippines.
            </p>
            <address className="mt-5 not-italic font-mono text-[11px] leading-relaxed text-landing-subtle-text">
              {COMPANY.addressLines.map((line) => (
                <span key={line} className="block">
                  {line}
                </span>
              ))}
            </address>
          </div>

          {/* Explore */}
          <nav aria-label="Footer explore">
            <h3 className="font-mono text-[11px] uppercase tracking-[0.2em] text-landing-subtle-text">
              Explore
            </h3>
            <ul className="mt-4 space-y-2.5">
              {NAV_LINKS.map((link) => (
                <li key={link.href}>
                  <a
                    href={link.href}
                    className="font-sans text-[13px] text-landing-text-secondary transition-colors hover:text-landing-accent"
                  >
                    {link.label}
                  </a>
                </li>
              ))}
            </ul>
          </nav>

          {/* Company */}
          <nav aria-label="Footer company">
            <h3 className="font-mono text-[11px] uppercase tracking-[0.2em] text-landing-subtle-text">
              Company
            </h3>
            <ul className="mt-4 space-y-2.5">
              {FOOTER_LINKS.company.map((link) => (
                <li key={link.label}>
                  <a
                    href={link.href}
                    onClick={(e) => link.href === '#' && e.preventDefault()}
                    className="font-sans text-[13px] text-landing-text-secondary transition-colors hover:text-landing-accent"
                  >
                    {link.label}
                  </a>
                </li>
              ))}
            </ul>
          </nav>

          {/* Quality & Certifications */}
          <nav aria-label="Footer quality">
            <h3 className="font-mono text-[11px] uppercase tracking-[0.2em] text-landing-subtle-text">
              Quality
            </h3>
            <ul className="mt-4 space-y-2.5">
              {FOOTER_LINKS.quality.map((link) => (
                <li key={link.label}>
                  <a
                    href={link.href}
                    onClick={(e) => link.href === '#' && e.preventDefault()}
                    className="font-sans text-[13px] text-landing-text-secondary transition-colors hover:text-landing-accent"
                  >
                    {link.label}
                  </a>
                </li>
              ))}
            </ul>
          </nav>

          {/* Newsletter + Contact */}
          <div>
            <h3 className="font-mono text-[11px] uppercase tracking-[0.2em] text-landing-subtle-text">
              Molding insights
            </h3>
            <p className="mt-4 max-w-xs text-[13px] leading-relaxed text-landing-text-secondary">
              Quality tips, DFM notes, and Ogami news — sent sparingly.
            </p>
            {newsletterStatus === 'success' ? (
              <div className="mt-4 flex items-center gap-2 text-[13px] text-success">
                <CheckCircle size={16} />
                <span>You&apos;re subscribed.</span>
              </div>
            ) : (
              <form onSubmit={subscribe} className="mt-4 flex flex-col gap-2">
                <div className="flex items-center gap-2">
                  <input
                    type="email"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    placeholder="your@email.com"
                    required
                    className="h-9 flex-1 rounded-md border border-landing-border bg-landing-surface px-3 text-[13px] text-landing-text outline-none transition-colors placeholder:text-landing-subtle-text focus:border-landing-accent"
                  />
                  <button
                    type="submit"
                    disabled={newsletterStatus === 'submitting'}
                    aria-label="Subscribe"
                    className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-landing-border bg-landing-surface text-landing-accent transition-colors hover:bg-landing-elevated hover:border-landing-accent/40 disabled:opacity-60"
                  >
                    <ArrowRight size={16} />
                  </button>
                </div>
                {newsletterStatus === 'error' && (
                  <p className="text-[11px] text-danger">Could not subscribe. Please try again.</p>
                )}
              </form>
            )}

            <h3 className="mt-8 font-mono text-[11px] uppercase tracking-[0.2em] text-landing-subtle-text">
              Get in touch
            </h3>
            <ul className="mt-4 space-y-2.5">
              <li>
                <a
                  href={`mailto:${COMPANY.email}`}
                  className="font-sans text-[13px] text-landing-text-secondary transition-colors hover:text-landing-accent"
                >
                  {COMPANY.email}
                </a>
              </li>
              <li className="font-sans text-[13px] text-landing-text-secondary">
                {COMPANY.phone}
              </li>
              <li className="pt-2">
                <Link
                  to="/login"
                  className="font-mono text-[11px] uppercase tracking-[0.18em] text-landing-subtle-text underline-offset-4 transition-colors hover:text-landing-text-secondary hover:underline"
                >
                  Staff login →
                </Link>
              </li>
            </ul>
          </div>
        </div>

        <div className="mt-14 flex flex-col items-start justify-between gap-4 border-t border-landing-border pt-6 sm:flex-row sm:items-center">
          <p className="font-mono text-[11px] text-landing-subtle-text">
            © {year} {COMPANY.legalName}. All rights reserved.
          </p>
          <div className="flex flex-wrap gap-4 sm:gap-6">
            {FOOTER_LINKS.legal.map((link) => (
              <a
                key={link.label}
                href={link.href}
                onClick={(e) => e.preventDefault()}
                className="font-sans text-[12px] text-landing-text-secondary transition-colors hover:text-landing-accent"
              >
                {link.label}
              </a>
            ))}
          </div>
          <p className="flex items-center gap-2.5 font-mono text-[11px] uppercase tracking-[0.16em] text-landing-subtle-text">
            <span className="h-1 w-1 rounded-full bg-landing-accent" />
            Made in the Philippines
          </p>
        </div>
      </div>
    </footer>
  );
}
