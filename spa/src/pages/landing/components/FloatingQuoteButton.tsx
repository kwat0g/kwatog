import { useEffect, useState } from 'react';
import { ArrowRight } from 'lucide-react';
import { cn } from '@/lib/cn';

export function FloatingQuoteButton() {
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    const hero = document.getElementById('top');
    if (!hero) return;

    const observer = new IntersectionObserver(
      ([entry]) => setVisible(!entry.isIntersecting),
      { threshold: 0 },
    );
    observer.observe(hero);
    return () => observer.disconnect();
  }, []);

  const scrollToContact = () => {
    const target = document.getElementById('contact');
    if (!target) return;
    const lenis = (window as unknown as { lenis?: { scrollTo: (target: HTMLElement, options?: { offset?: number }) => void } }).lenis;
    if (lenis) {
      lenis.scrollTo(target, { offset: -72 });
    } else {
      target.scrollIntoView({ behavior: 'smooth' });
    }
  };

  return (
    <button
      type="button"
      onClick={scrollToContact}
      className={cn(
        'fixed bottom-6 left-1/2 z-40 -translate-x-1/2 lg:hidden',
        'inline-flex items-center gap-2 rounded-full bg-landing-accent px-6 py-3',
        'font-sans text-sm font-semibold text-landing-accent-fg shadow-menu',
        'transition-all duration-300 hover:bg-landing-accent-hover hover:shadow-lg',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-landing-accent focus-visible:ring-offset-2 focus-visible:ring-offset-landing-canvas',
        visible ? 'translate-y-0 opacity-100' : 'translate-y-4 opacity-0 pointer-events-none',
      )}
    >
      Request a quote
      <ArrowRight size={16} />
    </button>
  );
}
