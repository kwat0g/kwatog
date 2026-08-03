import { useEffect, useState } from 'react';
import { ArrowRight } from 'lucide-react';
import { cn } from '@/lib/cn';
import { useQuery } from '@tanstack/react-query';
import { landingApi } from '@/api/landing';

interface FloatingQuoteButtonProps {
  onOpenQuote?: () => void;
}

export function FloatingQuoteButton({ onOpenQuote }: FloatingQuoteButtonProps) {
  const [visible, setVisible] = useState(false);
  const { data: content } = useQuery({ queryKey: ['landing', 'content'], queryFn: landingApi.content, staleTime: 300_000 });
  const quoteLabel = content?.section_copy?.hero_cta?.quote_label ?? 'Request Quote';

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

  const handleClick = () => {
    if (onOpenQuote) {
      onOpenQuote();
      return;
    }
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
      onClick={handleClick}
      className={cn(
        'fixed bottom-6 left-1/2 z-40 -translate-x-1/2 shadow-xl',
        'inline-flex items-center gap-2 rounded-full bg-landing-accent px-5 py-3',
        'font-sans text-sm font-medium text-landing-accent-fg',
        'transition-all duration-300 hover:bg-landing-accent-hover hover:scale-105',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-landing-accent focus-visible:ring-offset-2 focus-visible:ring-offset-landing-canvas',
        visible ? 'translate-y-0 opacity-100' : 'translate-y-4 opacity-0 pointer-events-none',
      )}
    >
      {quoteLabel}
      <ArrowRight size={16} />
    </button>
  );
}
