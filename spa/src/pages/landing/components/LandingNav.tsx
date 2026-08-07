/**
 * LandingNav — fixed top navigation for the public marketing site.
 *
 * Transparent over the hero, then condenses to a blurred light bar on scroll.
 * Exactly one action button — "Login" → /login (internal ERP entry). Section
 * anchors live inline on desktop and in a slide-down sheet on mobile; the Login
 * button stays visible at every breakpoint.
 *
 * When rendered on a non-landing page (e.g. /careers), the logo and section
 * anchors navigate back to / with the anchor, and a "Careers" link appears
 * in the desktop nav and mobile sheet.
 *
 * Accessibility: the mobile sheet is a focus-managed disclosure. The parent owns
 * the `open` state so it can mark the page content `inert` while the sheet is up;
 * here we move focus into the sheet on open, trap Tab within it, close on Escape,
 * and restore focus to the toggle on close.
 */

import { useEffect, useMemo, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate, useLocation } from 'react-router-dom';
import { Menu, X, LogIn } from 'lucide-react';
import { cn } from '@/lib/cn';
import { BrandLogo } from '@/components/brand/BrandLogo';
import { useMagnetic } from '../hooks/useMagnetic';
import { landingApi } from '@/api/landing';

export interface LandingNavProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onOpenQuote?: () => void;
}

const DEFAULT_NAV_LINKS = [
  { label: 'Capabilities', href: '#capabilities' },
  { label: '3D Parts', href: '#parts-3d' },
  { label: 'Process', href: '#process' },
  { label: 'Quality', href: '#quality' },
  { label: 'Contact', href: '#contact' },
];

export function LandingNav({ open, onOpenChange }: LandingNavProps) {
  const navigate = useNavigate();
  const location = useLocation();
  const isLanding = location.pathname === '/';
  const { data: contact } = useQuery({ queryKey: ['landing', 'contact'], queryFn: landingApi.contact, staleTime: 300_000 });
  const { data: content } = useQuery({ queryKey: ['landing', 'content'], queryFn: landingApi.content, staleTime: 300_000 });
  const navLinks = useMemo(
    () => (content?.section_copy?.nav_links?.length ? content.section_copy.nav_links : DEFAULT_NAV_LINKS),
    [content?.section_copy?.nav_links],
  );
  const legalName = contact?.legal_name || 'Philippine Ogami Corporation';
  const locationCountry = contact?.address?.split(',').at(-1)?.trim() || 'Philippines';
  const [scrolled, setScrolled] = useState(false);
  const [activeHref, setActiveHref] = useState<string>('');
  const toggleRef = useRef<HTMLButtonElement>(null);
  const sheetRef = useRef<HTMLDivElement>(null);
  const loginRef = useMagnetic<HTMLButtonElement>({ strength: 0.28, duration: 0.5 });

  const handleAnchorClick = (href: string) => {
    if (isLanding) return;
    navigate('/' + href);
  };

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 24);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  // Active-section tracking via a single IntersectionObserver.
  useEffect(() => {
    if (!isLanding) return;
    const sectionIds = navLinks.map((l) => l.href.slice(1));
    const elements = sectionIds
      .map((id) => document.getElementById(id))
      .filter((el): el is HTMLElement => el !== null);

    if (elements.length === 0) return;

    const states = new Map<string, IntersectionObserverEntry>();
    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((e) => states.set(e.target.id, e));
        const visible = [...states.values()]
          .filter((e) => e.isIntersecting)
          .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
        setActiveHref(visible.length > 0 ? '#' + visible[0].target.id : '');
      },
      { rootMargin: '-20% 0px -60% 0px', threshold: 0 },
    );

    elements.forEach((el) => observer.observe(el));
    return () => observer.disconnect();
  }, [isLanding, navLinks]);

  // Lock body scroll while the mobile sheet is open.
  useEffect(() => {
    document.body.style.overflow = open ? 'hidden' : '';
    return () => {
      document.body.style.overflow = '';
    };
  }, [open]);

  // Focus management
  useEffect(() => {
    if (!open) return;
    const sheet = sheetRef.current;
    if (!sheet) return;
    const toggle = toggleRef.current;

    const focusable = () =>
      Array.from(
        sheet.querySelectorAll<HTMLElement>(
          'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])',
        ),
      );

    focusable()[0]?.focus();

    function onKeyDown(e: KeyboardEvent) {
      if (e.key === 'Escape') {
        e.preventDefault();
        onOpenChange(false);
        return;
      }
      if (e.key !== 'Tab') return;
      const items = focusable();
      if (items.length === 0) return;
      const first = items[0];
      const last = items[items.length - 1];
      const active = document.activeElement as HTMLElement | null;
      if (e.shiftKey && active === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && active === last) {
        e.preventDefault();
        first.focus();
      }
    }

    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('keydown', onKeyDown);
      toggle?.focus();
    };
  }, [open, onOpenChange]);

  return (
    <header
      className={cn(
        'fixed inset-x-0 top-0 z-50 transition-colors duration-300',
        scrolled || open
          ? 'border-b border-default bg-canvas/80 backdrop-blur-xl'
          : 'border-b border-transparent bg-transparent',
      )}
    >
      <nav className={cn(
        "mx-auto flex h-16 w-full items-center justify-between transition-all duration-500 ease-out",
        scrolled ? "max-w-full px-4 sm:px-8 lg:px-12" : "max-w-[1440px] px-5 sm:px-5"
      )}>
        {/* Brand */}
        <a
          href={isLanding ? '#top' : '/'}
          onClick={(e) => {
            if (!isLanding) {
              e.preventDefault();
              navigate('/');
            }
          }}
          className="group flex shrink-0 items-center gap-3 rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-canvas"
        >
          <BrandLogo alt={legalName} className="h-9 shrink-0 transition-transform duration-500 group-hover:scale-105" />
          <div className="hidden flex-col text-left sm:flex">
            <span className="font-display text-sm font-semibold tracking-tight text-primary leading-tight whitespace-nowrap">
              {legalName}
            </span>
            <span className="font-mono text-[9px] uppercase tracking-[0.2em] text-muted whitespace-nowrap">
              Ogami ERP · {locationCountry}
            </span>
          </div>
        </a>

        {/* Desktop links */}
        <div className="hidden items-center gap-5 lg:flex">
          {navLinks.map((link) => {
            const isActive = isLanding && activeHref === link.href;
            return (
              <a
                key={link.href}
                href={isLanding ? link.href : '/' + link.href}
                onClick={(e) => {
                  if (!isLanding) {
                    e.preventDefault();
                    handleAnchorClick(link.href);
                  }
                }}
                aria-current={isActive ? 'location' : undefined}
                className={cn(
                  'relative rounded-sm font-sans text-[13px] transition-colors',
                  'after:absolute after:-bottom-1.5 after:left-0 after:h-px after:bg-accent after:transition-all after:duration-300',
                  'focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-accent focus-visible:ring-offset-4 focus-visible:ring-offset-canvas',
                  isActive
                    ? 'text-primary after:w-full'
                    : 'text-secondary after:w-0 hover:text-primary hover:after:w-full',
                )}
              >
                {link.label}
              </a>
            );
          })}
        </div>

        {/* Actions */}
        <div className="flex items-center gap-2">
          <button
            ref={loginRef}
            type="button"
            onClick={() => navigate('/login')}
            className={cn(
              'group inline-flex h-10 items-center gap-2 rounded-full border border-accent/40 px-5',
              'font-sans text-[13px] font-medium text-accent',
              'transition-colors duration-300 hover:border-accent hover:bg-accent hover:text-accent-fg',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-canvas',
            )}
          >
            <LogIn size={15} className="transition-transform duration-300 group-hover:translate-x-0.5" />
            Login
          </button>

          {/* Mobile menu toggle — 48px target on mobile */}
          <button
            ref={toggleRef}
            type="button"
            aria-label={open ? 'Close menu' : 'Open menu'}
            aria-expanded={open}
            aria-controls="landing-mobile-menu"
            onClick={() => onOpenChange(!open)}
            className="inline-flex h-12 w-12 items-center justify-center rounded-full border border-default text-primary transition-colors hover:bg-elevated focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-canvas sm:h-10 sm:w-10 lg:hidden"
          >
            {open ? <X size={20} /> : <Menu size={20} />}
          </button>
        </div>
      </nav>

      {/* Mobile sheet */}
      <div
        id="landing-mobile-menu"
        ref={sheetRef}
        className={cn(
          'overflow-hidden border-default bg-canvas/95 backdrop-blur-xl transition-[max-height] duration-300 lg:hidden',
          open ? 'max-h-96 border-t' : 'max-h-0',
        )}
      >
        <div className="flex flex-col gap-1 px-5 py-4">
          {navLinks.map((link) => (
            <a
              key={link.href}
              href={isLanding ? link.href : '/' + link.href}
              tabIndex={open ? undefined : -1}
              onClick={(e) => {
                onOpenChange(false);
                if (!isLanding) {
                  e.preventDefault();
                  handleAnchorClick(link.href);
                }
              }}
              className="rounded-md px-3 py-3 font-display text-lg text-secondary transition-colors hover:bg-elevated hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-canvas"
            >
              {link.label}
            </a>
          ))}
        </div>
      </div>
    </header>
  );
}
