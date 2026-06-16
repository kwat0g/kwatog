import { Award, TrendingDown, Clock } from 'lucide-react';

const TRUST_CARDS = [
  {
    id: 'toyota',
    icon: Award,
    stat: '±0.02 mm',
    label: 'Wiper bushing',
    context: 'In production for the Toyota supply chain.',
  },
  {
    id: 'yamaha',
    icon: TrendingDown,
    stat: '0 PPM',
    label: 'Relay cover',
    context: 'Zero defects reported over 12 months.',
  },
  {
    id: 'otd',
    icon: Clock,
    stat: '99.8%',
    label: 'On-time delivery',
    context: 'Tracked across all active OEM programs.',
  },
];

export function TrustSection() {
  return (
    <section className="relative bg-landing-canvas px-5 py-16 sm:px-8 sm:py-20">
      <div className="mx-auto max-w-7xl">
        <div className="grid gap-4 sm:grid-cols-3">
          {TRUST_CARDS.map((card, i) => {
            const Icon = card.icon;
            return (
              <div
                key={card.id}
                data-reveal
                data-reveal-delay={(i * 0.08).toFixed(2)}
                className="rounded-2xl border border-landing-border bg-landing-surface p-6 transition-colors duration-500 hover:border-landing-accent/40"
              >
                <div className="flex items-center gap-3">
                  <div className="flex h-10 w-10 items-center justify-center rounded-xl border border-landing-border text-landing-accent">
                    <Icon size={18} strokeWidth={1.6} />
                  </div>
                  <span className="font-display text-2xl font-bold tracking-tight text-landing-text">
                    {card.stat}
                  </span>
                </div>
                <p className="mt-4 font-sans text-[14px] font-medium text-landing-text">
                  {card.label}
                </p>
                <p className="mt-1 text-[13px] text-landing-text-secondary">{card.context}</p>
              </div>
            );
          })}
        </div>
      </div>
    </section>
  );
}
