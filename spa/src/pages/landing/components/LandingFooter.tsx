/**
 * LandingFooter — closing band for the marketing site.
 *
 * Carries the live brand/address, the section map, careers,
 * certifications, legal links, a newsletter signup, and clear employee and
 * partner portal entry points.
 */

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { LuArrowRight, LuCircleCheck } from '@/lib/icons';
import { BrandLogo } from '@/components/brand/BrandLogo';
import { landingApi } from '@/api/landing';
import { focusRingLanding } from '@/lib/focus';
import { cn } from '@/lib/cn';

/**
 * Footer links and link-styled buttons share one underline-on-hover treatment.
 * The font size stays at the call site — `cn` is plain clsx, so a size baked in
 * here could not be overridden by the one caller that wants 12px.
 */
const footerLinkCls = cn(
  'relative font-sans text-secondary transition-colors hover:text-accent',
  'after:absolute after:-bottom-0.5 after:left-0 after:h-px after:w-0 after:bg-accent',
  'after:transition-all after:duration-300 hover:after:w-full cursor-pointer',
  focusRingLanding,
);

const LEGAL_LINKS = [
  { label: 'Privacy Policy', href: '/privacy' },
  { label: 'Terms & Conditions', href: '/terms' },
  { label: 'Cookie Policy', href: '/cookies' },
];

export function LandingFooter() {
  const { data: contact } = useQuery({ queryKey: ['landing', 'contact'], queryFn: landingApi.contact, staleTime: 300_000 });
  const { data: content } = useQuery({ queryKey: ['landing', 'content'], queryFn: landingApi.content, staleTime: 300_000 });
  const navLinks = content?.section_copy?.nav_links ?? [];
  const companyLinks = content?.section_copy?.footer_company_links ?? [];
  const legalName = contact?.legal_name ?? '';
  const locationCountry = contact?.address?.split(',').at(-1)?.trim() ?? '';
  const salesEmail = contact?.sales_email ?? '';
  const phone = contact?.phone ?? '';
  const addressLines = contact?.address ? contact.address.split(', ') : [];
  const footerDesc = (content?.section_copy?.footer_description ?? '').replace('{{company}}', legalName);

  const year = new Date().getFullYear();
  const [email, setEmail] = useState('');
  const [consent, setConsent] = useState(false);
  const [newsletterStatus, setNewsletterStatus] = useState<'idle' | 'submitting' | 'success' | 'error'>('idle');

  // Section anchors (e.g. #contact) and route links only resolve as-is on the
  // landing page. On the careers pages the footer is shared, so anchors must be
  // rewritten to `/#…` and route changes done client-side (no full reload).
  const location = useLocation();
  const navigate = useNavigate();
  const isLanding = location.pathname === '/';

  const followLink = (e: React.MouseEvent, href: string) => {
    if (href.startsWith('#')) {
      if (isLanding) return;
      e.preventDefault();
      navigate('/' + href);
      return;
    }
    e.preventDefault();
    navigate(href);
  };

  const subscribe = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!email || !consent || newsletterStatus === 'submitting') return;
    setNewsletterStatus('submitting');
    try {
      await landingApi.subscribeNewsletter(email);
      setNewsletterStatus('success');
      setEmail('');
      setConsent(false);
    } catch {
      setNewsletterStatus('error');
    }
  };

  return (
    <footer className="relative border-t border-default bg-canvas px-5 py-16 sm:px-5">
      <div className="mx-auto max-w-7xl">
        <div className="grid gap-12 md:grid-cols-2 lg:grid-cols-[1.4fr_1fr_1fr_1fr_1.2fr]">
          {/* Brand + address */}
          <div data-reveal data-reveal-delay="0.00">
            <div className="flex items-center gap-3">
              <BrandLogo alt={legalName} className="h-10" />
              <div className="flex flex-col text-left">
                <span className="font-display text-base font-semibold tracking-tight text-primary">
                  {legalName}
                </span>
                <span className="font-mono text-[9px] uppercase tracking-[0.2em] text-muted">
                  {content?.quality_policy?.standard ?? ''}
                </span>
              </div>
            </div>
            <p className="mt-4 max-w-xs font-sans text-[13px] leading-relaxed text-muted">
              {footerDesc}
            </p>
            <address className="mt-5 not-italic font-mono text-[11px] leading-relaxed text-text-subtle">
              {addressLines.map((line) => (
                <span key={line} className="block">
                  {line}
                </span>
              ))}
            </address>
          </div>

          {/* Explore */}
          <nav aria-label="Footer explore" data-reveal data-reveal-delay="0.07">
            <h2 className="font-mono text-[11px] uppercase tracking-[0.2em] text-text-subtle">
              Explore
            </h2>
            <ul className="mt-4 space-y-2.5">
              {navLinks.map((link) => (
                <li key={link.href}>
                  <a
                    href={link.href.startsWith('#') && !isLanding ? '/' + link.href : link.href}
                    onClick={(e) => followLink(e, link.href)}
                    className={cn(footerLinkCls, 'text-[13px]')}
                  >
                    {link.label}
                  </a>
                </li>
              ))}
            </ul>
          </nav>

          {/* Company */}
          <nav aria-label="Footer company" data-reveal data-reveal-delay="0.14">
            <h2 className="font-mono text-[11px] uppercase tracking-[0.2em] text-text-subtle">
              Company
            </h2>
            <ul className="mt-4 space-y-2.5">
              {companyLinks.map((link) => (
                <li key={link.label}>
                  <a
                    href={link.href.startsWith('#') && !isLanding ? '/' + link.href : link.href}
                    onClick={(e) => followLink(e, link.href)}
                    className={cn(footerLinkCls, 'text-[13px]')}
                  >
                    {link.label}
                  </a>
                </li>
              ))}
            </ul>
          </nav>

          {/* Quality & Certifications */}
          <nav aria-label="Footer quality" data-reveal data-reveal-delay="0.21">
            <h2 className="font-mono text-[11px] uppercase tracking-[0.2em] text-text-subtle">
              Quality
            </h2>
            <ul className="mt-4 space-y-2.5">
              <li>
                <button
                  type="button"
                  onClick={() => {
                    landingApi
                      .downloadQualityPolicy()
                      .then((blob) => {
                        const url = window.URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = 'quality-policy.pdf';
                        document.body.appendChild(a);
                        a.click();
                        a.remove();
                        window.URL.revokeObjectURL(url);
                      })
                      .catch(() => {
                        // Error toast handled by the global axios interceptor.
                      });
                  }}
                  className={cn(footerLinkCls, 'text-[13px]')}
                >
                  Quality policy
                </button>
              </li>
            </ul>
          </nav>

          {/* Newsletter + Contact */}
          <div data-reveal data-reveal-delay="0.28">
            <h2 className="font-mono text-[11px] uppercase tracking-[0.2em] text-text-subtle">
              Molding insights
            </h2>
            <p className="mt-4 max-w-xs text-[13px] leading-relaxed text-secondary">
              {(content?.section_copy?.newsletter_description ?? '—').replace('{{company}}', contact?.legal_name ?? '—')}
            </p>
            {newsletterStatus === 'success' ? (
              <div role="status" aria-live="polite" className="mt-4 flex items-center gap-2 text-[13px] text-success">
                <LuCircleCheck size={16} />
                <span>You&apos;re subscribed.</span>
              </div>
            ) : (
              <form onSubmit={subscribe} className="mt-4 flex flex-col gap-2">
                <div className="flex items-center gap-2">
                  <label htmlFor="newsletter-email" className="sr-only">
                    Email address
                  </label>
                  <input
                    id="newsletter-email"
                    name="email"
                    type="email"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    placeholder="your@email.com"
                    autoComplete="email"
                    maxLength={150}
                    required
                    className="h-9 flex-1 rounded-md border border-default bg-surface px-3 text-[13px] text-primary outline-none transition-colors placeholder:text-text-subtle focus:border-accent"
                  />
                  <button
                    type="submit"
                    disabled={newsletterStatus === 'submitting' || !consent}
                    aria-label="Subscribe"
                    className={cn('flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-default bg-surface text-accent transition-colors hover:bg-elevated hover:border-accent/40 disabled:opacity-60 cursor-pointer', focusRingLanding)}
                  >
                    <LuArrowRight size={16} />
                  </button>
                </div>
                <label className="flex items-start gap-2 text-[11px] leading-relaxed text-text-subtle">
                  <input
                    type="checkbox"
                    checked={consent}
                    onChange={(e) => setConsent(e.target.checked)}
                    className="mt-0.5 h-3.5 w-3.5 shrink-0 rounded-sm border-default accent-accent"
                  />
                  <span>
                    I agree to the{' '}
                    <Link to="/privacy" className="text-accent underline underline-offset-2">
                      Privacy Policy
                    </Link>
                    .
                  </span>
                </label>
                {newsletterStatus === 'error' && (
                  <p role="alert" className="text-[11px] text-danger">Could not subscribe. Please try again.</p>
                )}
              </form>
            )}

            <h2 className="mt-8 font-mono text-[11px] uppercase tracking-[0.2em] text-text-subtle">
              Get in touch
            </h2>
            <ul className="mt-4 space-y-2.5">
              <li>
                <a
                  href={salesEmail ? `mailto:${salesEmail}` : undefined}
                  className={cn(footerLinkCls, 'text-[13px]')}
                >
                  {salesEmail || '—'}
                </a>
              </li>
              <li className="font-sans text-[13px] text-secondary">
                {phone ? (
                  <a href={`tel:${phone}`} className={cn(footerLinkCls, 'text-[13px]')}>
                    {phone}
                  </a>
                ) : (
                  '—'
                )}
              </li>
              <li className="pt-2">
                <Link
                  to="/sign-in"
                  className="font-mono text-[11px] uppercase tracking-[0.18em] text-text-subtle underline-offset-4 transition-colors hover:text-secondary hover:underline"
                >
                  Sign in →
                </Link>
              </li>
            </ul>
          </div>
        </div>

        {/* Legal links are rendered directly (not CMS-seeded) so they can never
            disappear from the footer regardless of landing content settings. */}
        <nav aria-label="Legal" className="mt-14 border-t border-default pt-6">
          <ul className="flex flex-wrap items-center gap-x-6 gap-y-2">
            {LEGAL_LINKS.map((link) => (
              <li key={link.href}>
                <Link
                  to={link.href}
                  className={cn(footerLinkCls, 'text-[12px]')}
                >
                  {link.label}
                </Link>
              </li>
            ))}
          </ul>
        </nav>

        <div className="mt-6 flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-center">
          <p className="font-mono text-[11px] text-text-subtle">
            © {year}{legalName ? ` ${legalName}.` : ''} {legalName ? 'All rights reserved.' : ''}
          </p>
          <p className="flex items-center gap-2.5 font-mono text-[11px] uppercase tracking-[0.16em] text-text-subtle">
            <span className="h-1 w-1 rounded-full bg-accent" />
            {locationCountry ? `Made in ${locationCountry}` : '—'}
          </p>
        </div>
      </div>
    </footer>
  );
}
