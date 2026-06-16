/**
 * LandingNav — fixed top navigation for the public marketing site.
 *
 * Transparent over the hero, then condenses to a blurred light bar on scroll.
 * Exactly one action button — "Login" → /login (internal ERP entry). Section
 * anchors live inline on desktop and in a slide-down sheet on mobile; the Login
 * button stays visible at every breakpoint.
 *
 * Accessibility: the mobile sheet is a focus-managed disclosure. The parent owns
 * the `open` state so it can mark the page content `inert` while the sheet is up;
 * here we move focus into the sheet on open, trap Tab within it, close on Escape,
 * and restore focus to the toggle on close.
 */

import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Menu, X, LogIn } from 'lucide-react';
import { cn } from '@/lib/cn';
import { DatumMark } from './DatumMark';
import { NAV_LINKS } from '../data';

interface LandingNavProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

export function LandingNav({ open, onOpenChange }: LandingNavProps) {
  const navigate = useNavigate();
  const [scrolled, setScrolled] = useState(false);
  const toggleRef = useRef<HTMLButtonElement>(null);
  const sheetRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 24);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  // Lock body scroll while the mobile sheet is open.
  useEffect(() => {
    document.body.style.overflow = open ? 'hidden' : '';
    return () => {
      document.body.style.overflow = '';
    };
  }, [open]);

  // Focus management: move focus into the sheet on open, trap Tab, Escape to
  // close, and restore focus to the toggle button on close.
  useEffect(() => {
    if (!open) return;
    const sheet = sheetRef.current;
    if (!sheet) return;
    // Capture the toggle node now; restore focus to it on cleanup.
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
          ? 'border-b border-landing-border bg-landing-canvas/80 backdrop-blur-xl'
          : 'border-b border-transparent bg-transparent',
      )}
    >
      <nav className="mx-auto flex h-16 max-w-7xl items-center justify-between px-5 sm:px-8">
        {/* Brand */}
        <a
          href="#top"
          className="group flex items-center gap-2.5 rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-landing-accent focus-visible:ring-offset-2 focus-visible:ring-offset-landing-canvas"
        >
          <DatumMark
            size={24}
            className="text-landing-accent transition-transform duration-500 group-hover:rotate-90"
          />
          <span className="font-display text-[19px] font-bold leading-none tracking-tight text-landing-text">
            OGAMI
          </span>
          <span className="hidden font-mono text-[10px] uppercase tracking-[0.22em] text-landing-muted sm:inline">
            Philippines
          </span>
        </a>

        {/* Desktop links */}
        <div className="hidden items-center gap-8 lg:flex">
          {NAV_LINKS.map((link) => (
            <a
              key={link.href}
              href={link.href}
              className="relative rounded-sm font-sans text-[13px] text-landing-text-secondary transition-colors after:absolute after:-bottom-1.5 after:left-0 after:h-px after:w-0 after:bg-landing-accent after:transition-all after:duration-300 hover:text-landing-text hover:after:w-full focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-landing-accent focus-visible:ring-offset-4 focus-visible:ring-offset-landing-canvas"
            >
              {link.label}
            </a>
          ))}
        </div>

        {/* Actions */}
        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={() => navigate('/login')}
            className={cn(
              'group inline-flex h-10 items-center gap-2 rounded-full border border-landing-accent/40 px-5',
              'font-sans text-[13px] font-medium text-landing-accent',
              'transition-all duration-300 hover:border-landing-accent hover:bg-landing-accent hover:text-landing-accent-fg',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-landing-accent focus-visible:ring-offset-2 focus-visible:ring-offset-landing-canvas',
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
            className="inline-flex h-12 w-12 items-center justify-center rounded-full border border-landing-border text-landing-text transition-colors hover:bg-landing-elevated focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-landing-accent focus-visible:ring-offset-2 focus-visible:ring-offset-landing-canvas sm:h-10 sm:w-10 lg:hidden"
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
          'overflow-hidden border-landing-border bg-landing-canvas/95 backdrop-blur-xl transition-[max-height] duration-300 lg:hidden',
          open ? 'max-h-96 border-t' : 'max-h-0',
        )}
      >
        <div className="flex flex-col gap-1 px-5 py-4">
          {NAV_LINKS.map((link) => (
            <a
              key={link.href}
              href={link.href}
              tabIndex={open ? undefined : -1}
              onClick={() => onOpenChange(false)}
              className="rounded-lg px-3 py-3 font-display text-lg text-landing-text-secondary transition-colors hover:bg-landing-elevated hover:text-landing-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-landing-accent focus-visible:ring-offset-2 focus-visible:ring-offset-landing-canvas"
            >
              {link.label}
            </a>
          ))}
        </div>
      </div>
    </header>
  );
}
