import { useState, type ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { LandingNav } from '@/pages/landing/components/LandingNav';
import { LandingFooter } from '@/pages/landing/components/LandingFooter';
import { landingApi } from '@/api/landing';
import { useSeo } from '@/hooks/useSeo';

const LAST_UPDATED = '17 September 2026';

interface LegalShellProps {
  title: string;
  path: string;
  children: ReactNode;
}

export function LegalShell({ title, path, children }: LegalShellProps) {
  const [menuOpen, setMenuOpen] = useState(false);
  const { data: contact } = useQuery({
    queryKey: ['landing', 'contact'],
    queryFn: landingApi.contact,
    staleTime: 300_000,
  });
  const legalName = contact?.legal_name ?? 'Ogami';
  useSeo({ title: `${title} — ${legalName}`, path });

  return (
    <div className="min-h-screen bg-canvas">
      <LandingNav open={menuOpen} onOpenChange={setMenuOpen} />
      <main className="mx-auto max-w-3xl px-5 pb-24 pt-32">
        <h1 className="font-display text-3xl tracking-tight text-primary sm:text-4xl">{title}</h1>
        <p className="mt-3 font-mono text-[11px] uppercase tracking-[0.2em] text-text-subtle">
          Last updated {LAST_UPDATED}
        </p>
        <div
          className="mt-10 space-y-4 text-sm leading-relaxed text-secondary [&_a]:text-accent [&_a]:underline [&_a]:underline-offset-2 [&_h2]:mt-10 [&_h2]:font-display [&_h2]:text-xl [&_h2]:text-primary [&_h3]:mt-6 [&_h3]:font-medium [&_h3]:text-primary [&_li]:mt-1 [&_ul]:list-disc [&_ul]:space-y-1 [&_ul]:pl-5"
        >
          {children}
        </div>
      </main>
      <LandingFooter />
    </div>
  );
}
