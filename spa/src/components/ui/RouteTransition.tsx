// Series X / Task X5 — 150 ms fade between routed page content.
//
// Wraps the routed <Outlet /> inside AppLayout. Sidebar and topbar do NOT
// fade — only the page content. The fade is automatically disabled by the
// global `prefers-reduced-motion` rule in globals.css (transitions are
// nuked to 0.01ms).

import { useLocation } from 'react-router-dom';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface Props {
  children: ReactNode;
}

const FADE_MS = 150;

export function RouteTransition({ children }: Props) {
  const location = useLocation();
  const [phase, setPhase] = useState<'in' | 'out'>('in');
  const lastPathRef = useRef(location.pathname);

  useEffect(() => {
    if (lastPathRef.current === location.pathname) return;
    lastPathRef.current = location.pathname;
    setPhase('out');
    const id = window.setTimeout(() => setPhase('in'), 16);
    return () => window.clearTimeout(id);
  }, [location.pathname]);

  return (
    <div
      className={cn(
        'transition-opacity ease-out',
        phase === 'in' ? 'opacity-100' : 'opacity-0',
      )}
      style={{ transitionDuration: `${FADE_MS}ms` }}
    >
      {children}
    </div>
  );
}
