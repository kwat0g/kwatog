/**
 * LandingFooter — closing band for the marketing site.
 *
 * Carries the live brand/address, the section map, careers,
 * certifications, legal links, a newsletter signup, and a single discreet
 * "Staff login" text link for internal users.
 */

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { ArrowRight, CheckCircle } from 'lucide-react';
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

export function LandingFooter() {
  const { data: contact } = useQuery({ queryKey: ['landing', 'contact'], queryFn: landingApi.contact, staleTime: 300_000 });
  const { data: content } = useQuery({ queryKey: ['landing', 'content'], queryFn: landingApi.content, staleTime: 300_000 });
  const navLinks = content?.section_copy?.nav_links?.length ? content.section_copy.nav_links : [
    { label: 'Capabilities', href: '#capabilities' },
    { label: 'Parts', href: '#parts-3d' },
    { label: 'Process', href: '#process' },
    { label: 'Quality', href: '#quality' },
    { label: 'Contact', href: '#contact' },
  ];
  const companyLinks = content?.section_copy?.footer_company_links?.length ? content.section_copy.footer_company_links : [
    { label: 'Careers', href: '/careers' },
    { label: 'Portal', href: '/portal' },
  ];
  const legalName = contact?.legal_name || 'Philippine Ogami Corporation';
  const locationCountry = contact?.address?.split(',').at(-1)?.trim() || 'Philippines';
  const salesEmail = contact?.sales_email || 'sales@ogami.ph';
  const phone = contact?.phone || '+63 (046) 402-1234';
  const addressLines = contact?.address ? contact.address.split(', ') : ['FCIE Dasmariñas', 'Cavite', 'Philippines'];
  const footerDesc = content?.section_copy?.footer_description
    ? content.section_copy.footer_description.replace('{{company}}', legalName)
    : `${legalName} — IATF 16949 certified plastic injection molding & precision tooling for Tier-1 automotive and electronics manufacturers.`;

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
                  Ogami ERP · IATF 16949
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
            <h3 className="font-mono text-[11px] uppercase tracking-[0.2em] text-text-subtle">
              Explore
            </h3>
            <ul className="mt-4 space-y-2.5">
              {navLinks.map((link) => (
                <li key={link.href}>
                  <a
                    href={link.href}
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
            <h3 className="font-mono text-[11px] uppercase tracking-[0.2em] text-text-subtle">
              Company
            </h3>
            <ul className="mt-4 space-y-2.5">
              {companyLinks.map((link) => (
                <li key={link.label}>
                  <a
                    href={link.href}
                    onClick={(e) => link.href === '#' && e.preventDefault()}
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
            <h3 className="font-mono text-[11px] uppercase tracking-[0.2em] text-text-subtle">
              Quality
            </h3>
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
            <h3 className="font-mono text-[11px] uppercase tracking-[0.2em] text-text-subtle">
              Molding insights
            </h3>
            <p className="mt-4 max-w-xs text-[13px] leading-relaxed text-secondary">
              {(content?.section_copy?.newsletter_description ?? '—').replace('{{company}}', contact?.legal_name ?? '—')}
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
                    className="h-9 flex-1 rounded-md border border-default bg-surface px-3 text-[13px] text-primary outline-none transition-colors placeholder:text-text-subtle focus:border-accent"
                  />
                  <button
                    type="submit"
                    disabled={newsletterStatus === 'submitting'}
                    aria-label="Subscribe"
                    className={cn('flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-default bg-surface text-accent transition-colors hover:bg-elevated hover:border-accent/40 disabled:opacity-60 cursor-pointer', focusRingLanding)}
                  >
                    <ArrowRight size={16} />
                  </button>
                </div>
                {newsletterStatus === 'error' && (
                  <p className="text-[11px] text-danger">Could not subscribe. Please try again.</p>
                )}
              </form>
            )}

            <h3 className="mt-8 font-mono text-[11px] uppercase tracking-[0.2em] text-text-subtle">
              Get in touch
            </h3>
            <ul className="mt-4 space-y-2.5">
              <li>
                <a
                  href={`mailto:${salesEmail}`}
                  className={cn(footerLinkCls, 'text-[13px]')}
                >
                  {salesEmail}
                </a>
              </li>
              <li className="font-sans text-[13px] text-secondary">
                {phone}
              </li>
              <li className="pt-2">
                <Link
                  to="/login"
                  className="font-mono text-[11px] uppercase tracking-[0.18em] text-text-subtle underline-offset-4 transition-colors hover:text-secondary hover:underline"
                >
                  Staff login →
                </Link>
              </li>
            </ul>
          </div>
        </div>

        <div className="mt-14 flex flex-col items-start justify-between gap-4 border-t border-default pt-6 sm:flex-row sm:items-center">
          <p className="font-mono text-[11px] text-text-subtle">
            © {year} {legalName}. All rights reserved.
          </p>
          <p className="flex items-center gap-2.5 font-mono text-[11px] uppercase tracking-[0.16em] text-text-subtle">
            <span className="h-1 w-1 rounded-full bg-accent" />
            Made in {locationCountry}
          </p>
        </div>
      </div>
    </footer>
  );
}
