import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { Button } from '@/components/ui/Button';
import { portalGuideStorageKey, portalWalkthroughSteps, type PortalGuideSection } from './portalWalkthroughs';

type PortalRealm = 'customer' | 'supplier';

export function PortalWalkthrough({
  realm,
  userId,
  sections,
}: {
  realm: PortalRealm;
  userId: string;
  sections: PortalGuideSection[];
}) {
  const location = useLocation();
  const storageKey = useMemo(() => portalGuideStorageKey(realm, userId), [realm, userId]);
  const steps = useMemo(() => portalWalkthroughSteps(realm, sections), [realm, sections]);
  const [open, setOpen] = useState(false);
  const [stepIndex, setStepIndex] = useState(0);
  const [started, setStarted] = useState(false);
  const previousPath = useRef(location.pathname);
  const titleId = `portal-walkthrough-title-${realm}`;

  useEffect(() => {
    try {
      const saved = window.localStorage.getItem(storageKey);
      setOpen(saved !== 'completed' && saved !== 'skipped');
    } catch {
      // Storage can be disabled by the browser. The guide still works for this session.
      setOpen(true);
    }
    setStepIndex(0);
    setStarted(false);
  }, [storageKey]);

  useEffect(() => {
    const changedPath = previousPath.current !== location.pathname;
    previousPath.current = location.pathname;
    if (!changedPath || !open || !started) return;

    const matches = steps.flatMap((step, index) => step.links
      .filter((link) => link.to === location.pathname || location.pathname.startsWith(`${link.to.replace(/\/$/, '')}/`))
      .map((link) => ({ index, length: link.to.length, exact: link.to === location.pathname })));
    const match = matches.sort((left, right) => Number(right.exact) - Number(left.exact) || right.length - left.length)[0];
    const matchingIndex = match?.index ?? -1;
    if (matchingIndex > 0) setStepIndex(matchingIndex);
  }, [location.pathname, open, started, steps]);

  const finish = (status: 'completed' | 'skipped') => {
    try {
      window.localStorage.setItem(storageKey, status);
    } catch {
      // Keep dismissal functional when storage is unavailable.
    }
    setOpen(false);
    setStarted(false);
    setStepIndex(0);
  };

  const openGuide = () => {
    setStepIndex(0);
    setStarted(false);
    setOpen(true);
  };

  const currentStep = steps[stepIndex];

  return (
    <>
      <Button
        type="button"
        variant="ghost"
        size="touch"
        aria-expanded={open}
        onClick={openGuide}
      >
        Help
      </Button>
      {open && currentStep && (
        <aside
          id={`portal-walkthrough-${realm}`}
          role="dialog"
          aria-modal="false"
          aria-labelledby={titleId}
          className="fixed inset-x-3 bottom-3 z-[80] max-h-[min(70dvh,34rem)] w-auto overflow-y-auto rounded-lg border border-default bg-canvas p-4 shadow-lg sm:inset-x-auto sm:right-6 sm:bottom-6 sm:w-[min(28rem,calc(100vw-3rem))] sm:p-5"
        >
          <div className="flex items-start justify-between gap-4">
            <div className="min-w-0">
              <p className="text-xs font-medium text-muted">Step {stepIndex + 1} of {steps.length}</p>
              <h2 id={titleId} className="mt-1 text-base font-semibold text-primary">{currentStep.title}</h2>
            </div>
            <Button type="button" variant="ghost" size="touch" onClick={() => finish('skipped')}>
              Close
            </Button>
          </div>
          <p className="mt-2 max-w-prose text-sm leading-6 text-secondary">{currentStep.description}</p>
          {currentStep.links.length > 0 && (
            <ul className="mt-3 max-h-44 space-y-2 overflow-y-auto border-t border-default pt-3">
              {currentStep.links.map((link) => (
                <li key={link.to} className="flex flex-col gap-0.5 text-sm">
                  <Link to={link.to} className="w-fit text-link underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent">
                    {link.label}
                  </Link>
                  {'description' in link && <span className="text-xs leading-5 text-muted">{link.description}</span>}
                </li>
              ))}
            </ul>
          )}
          <div className="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-default pt-3">
            <Button type="button" variant="ghost" size="touch" onClick={() => finish('skipped')}>
              Skip walkthrough
            </Button>
            <div className="flex items-center gap-2">
              {stepIndex > 0 && <Button type="button" size="touch" onClick={() => { setStarted(true); setStepIndex((current) => Math.max(0, current - 1)); }}>Previous</Button>}
              <Button
                type="button"
                variant="primary"
                size="touch"
                onClick={() => {
                  setStarted(true);
                  if (stepIndex + 1 >= steps.length) finish('completed');
                  else setStepIndex((current) => current + 1);
                }}
              >
                {stepIndex + 1 >= steps.length ? 'Finish' : stepIndex === 0 ? 'Start walkthrough' : 'Next'}
              </Button>
            </div>
          </div>
        </aside>
      )}
    </>
  );
}
