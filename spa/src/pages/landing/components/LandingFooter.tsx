/**
 * LandingFooter — closing band for the marketing site.
 *
 * Carries the brand, the Cavite address, the section map, and a single discreet
 * "Staff login" text link for internal users (the prominent CTA stays in the
 * nav). Quietly proud: the datum mark and a "Made in the Philippines" line.
 */

import { Link } from 'react-router-dom';
import { BrandLogo } from '@/components/brand/BrandLogo';
import { COMPANY, NAV_LINKS } from '../data';

export function LandingFooter() {
  const year = new Date().getFullYear();

  return (
    <footer className="relative border-t border-landing-border bg-landing-canvas px-5 py-16 sm:px-8">
      <div className="mx-auto max-w-7xl">
        <div className="grid gap-12 md:grid-cols-[1.4fr_1fr_1fr]">
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
          <nav aria-label="Footer">
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

          {/* Contact */}
          <div>
            <h3 className="font-mono text-[11px] uppercase tracking-[0.2em] text-landing-subtle-text">
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
          <p className="flex items-center gap-2.5 font-mono text-[11px] uppercase tracking-[0.16em] text-landing-subtle-text">
            <span className="h-1 w-1 rounded-full bg-landing-accent" />
            Made in the Philippines
          </p>
        </div>
      </div>
    </footer>
  );
}
