import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { Button } from '@/components/ui/Button';
import {
  portalGuideStorageKey,
  portalPageGuideForPath,
  portalWalkthroughSteps,
  type PortalGuideSection,
  type PortalGuideStep,
  type PortalGuideTarget,
} from './portalWalkthroughs';
import './PortalWalkthrough.css';

type PortalRealm = 'customer' | 'supplier';
type GuideMode = 'overview' | 'page';

const fallbackPageSteps: PortalGuideStep[] = [
  {
    title: 'Explore this page',
    description: 'Use the page controls and portal navigation to review your account information and complete the available tasks.',
    links: [],
  },
];

const targetSelectors: Record<PortalGuideTarget, string> = {
  'page-header': '[data-layout="page-header"]',
  'page-actions': '[data-layout="page-actions"]',
  filters: '[data-portal-guide-target="filters"], [data-layout="filter-bar"]',
  records: '[data-portal-guide-target="records"], [data-layout="data-table"], table, ul',
  form: '[data-portal-guide-target="form"]',
  section: '[data-portal-guide-target="section"], [data-layout="section"]',
  navigation: '[data-portal-guide-target="navigation"]',
};

function resolveGuideTarget(target: PortalGuideTarget, targetIndex = 0): HTMLElement | null {
  const scope = target === 'navigation'
    ? document
    : document.getElementById('portal-main-content') ?? document;
  const explicitRecordTargets = target === 'records'
    ? scope.querySelectorAll<HTMLElement>('[data-portal-guide-target="records"]')
    : null;
  const candidates = explicitRecordTargets?.length
    ? explicitRecordTargets
    : scope.querySelectorAll<HTMLElement>(targetSelectors[target]);
  const element = candidates.item(targetIndex);
  if (!element) return null;

  const rect = element.getBoundingClientRect();
  const style = window.getComputedStyle(element);
  if (style.display === 'none' || style.visibility === 'hidden' || rect.width === 0 || rect.height === 0) {
    return null;
  }

  // The portal navigation is a drawer on narrow screens. A closed drawer can
  // remain mounted, so only spotlight it when it is actually on screen.
  if (target === 'navigation' && (rect.right <= 0 || rect.left >= window.innerWidth)) return null;
  return element;
}

function bringTargetIntoView(element: HTMLElement, guidePanel: HTMLElement | null): void {
  const rect = element.getBoundingClientRect();
  const panelTop = guidePanel?.getBoundingClientRect().top ?? window.innerHeight;
  const safelyVisible = rect.top >= 64 && rect.bottom <= panelTop - 20;
  if (safelyVisible) return;

  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  element.scrollIntoView({
    behavior: reducedMotion ? 'auto' : 'smooth',
    block: 'center',
    inline: 'nearest',
  });
}

function missingTargetMessage(target: PortalGuideTarget): string {
  if (target === 'form') {
    return 'This form is not visible yet. It may still be loading, or the page may need an action to open it. Return to this step when it appears.';
  }
  if (target === 'navigation') {
    return 'Open the portal menu to view navigation on this screen size.';
  }
  return 'This area may still be loading or may not be available for this record. Continue the guide and return here when it appears.';
}

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
  const overviewSteps = useMemo(() => portalWalkthroughSteps(realm, sections), [realm, sections]);
  const pageGuide = portalPageGuideForPath(realm, location.pathname);
  const [open, setOpen] = useState(false);
  const [mode, setMode] = useState<GuideMode>('overview');
  const [stepIndex, setStepIndex] = useState(0);
  const [targetAvailable, setTargetAvailable] = useState(false);
  const previousPath = useRef(location.pathname);
  const helpButtonRef = useRef<HTMLButtonElement>(null);
  const headingRef = useRef<HTMLHeadingElement>(null);
  const guidePanelRef = useRef<HTMLElement>(null);
  const wasOpen = useRef(false);
  const titleId = `portal-walkthrough-title-${realm}`;
  const steps = mode === 'overview' ? overviewSteps : pageGuide?.steps ?? fallbackPageSteps;
  const activeIndex = Math.min(stepIndex, steps.length - 1);
  const currentStep = steps[activeIndex];

  useEffect(() => {
    try {
      const saved = window.localStorage.getItem(storageKey);
      setOpen(saved !== 'completed' && saved !== 'skipped');
    } catch {
      // Storage can be disabled by the browser. The guide still works for this session.
      setOpen(true);
    }
    setMode('overview');
    setStepIndex(0);
  }, [storageKey]);

  useLayoutEffect(() => {
    const changedPath = previousPath.current !== location.pathname;
    previousPath.current = location.pathname;
    if (!changedPath || !open) return;

    setMode('page');
    setStepIndex(0);
  }, [location.pathname, open]);

  useEffect(() => {
    if (!open || mode !== 'page' || !currentStep?.target) {
      setTargetAvailable(false);
      return;
    }

    let activeTarget: HTMLElement | null = null;
    const updateTarget = () => {
      const nextTarget = resolveGuideTarget(currentStep.target!, currentStep.targetIndex ?? 0);
      if (activeTarget !== nextTarget) {
        activeTarget?.removeAttribute('data-portal-guide-active');
        activeTarget = nextTarget;
        activeTarget?.setAttribute('data-portal-guide-active', 'true');
        if (activeTarget && currentStep.target !== 'navigation') {
          bringTargetIntoView(activeTarget, guidePanelRef.current);
        }
      }
      setTargetAvailable(Boolean(activeTarget));
    };

    updateTarget();
    const observer = new MutationObserver(updateTarget);
    const observerOptions: MutationObserverInit = { childList: true, subtree: true };
    if (currentStep.target === 'navigation') {
      observerOptions.attributes = true;
      observerOptions.attributeFilter = ['class', 'style', 'hidden', 'aria-hidden'];
    }
    observer.observe(document.body, observerOptions);

    return () => {
      observer.disconnect();
      activeTarget?.removeAttribute('data-portal-guide-active');
    };
  }, [currentStep?.target, currentStep?.targetIndex, location.pathname, mode, open]);

  useEffect(() => {
    if (open && !wasOpen.current) headingRef.current?.focus({ preventScroll: true });
    wasOpen.current = open;
  }, [open]);

  const finish = useCallback((status: 'completed' | 'skipped') => {
    try {
      window.localStorage.setItem(storageKey, status);
    } catch {
      // Keep dismissal functional when storage is unavailable.
    }
    setOpen(false);
    setMode('overview');
    setStepIndex(0);
    helpButtonRef.current?.focus();
  }, [storageKey]);

  useEffect(() => {
    if (!open) return;
    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key !== 'Escape' || !guidePanelRef.current?.contains(document.activeElement)) return;
      event.preventDefault();
      finish('skipped');
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [finish, open]);

  const openPageGuide = () => {
    setStepIndex(0);
    setMode('page');
    setOpen(true);
  };

  const goToStep = (nextIndex: number) => {
    setStepIndex(Math.max(0, Math.min(nextIndex, steps.length - 1)));
  };

  const lastStep = activeIndex + 1 >= steps.length;
  const contextLabel = mode === 'overview'
    ? 'Portal overview'
    : pageGuide?.title ?? 'Portal page guide';

  return (
    <>
      <Button
        ref={helpButtonRef}
        type="button"
        variant="ghost"
        size="touch"
        aria-expanded={open}
        aria-controls={open ? `portal-walkthrough-${realm}` : undefined}
        onClick={openPageGuide}
      >
        Help
      </Button>
      {open && currentStep && (
        <aside
          ref={guidePanelRef}
          id={`portal-walkthrough-${realm}`}
          role="dialog"
          aria-modal="false"
          aria-labelledby={titleId}
          className="portal-walkthrough fixed inset-x-3 bottom-3 z-[80] max-h-[min(70dvh,34rem)] w-auto overflow-y-auto rounded-lg border border-default bg-canvas p-4 sm:inset-x-auto sm:right-6 sm:bottom-6 sm:w-[min(30rem,calc(100vw-3rem))] sm:p-5"
        >
          <div className="flex items-start justify-between gap-4">
            <div className="min-w-0">
              <p className="text-xs font-medium text-muted">
                {contextLabel} <span aria-hidden="true">·</span> Step {activeIndex + 1} of {steps.length}
              </p>
              <h2 ref={headingRef} id={titleId} tabIndex={-1} className="mt-1 text-base font-semibold text-primary focus:outline-none">
                {currentStep.title}
              </h2>
            </div>
            <Button type="button" variant="ghost" size="touch" onClick={() => finish('skipped')}>
              Close
            </Button>
          </div>

          <div
            key={`${mode}:${location.pathname}:${activeIndex}`}
            className="portal-walkthrough__step"
            aria-live="polite"
            aria-atomic="true"
          >
            {mode === 'page' && activeIndex === 0 && pageGuide?.description && (
              <p className="mt-2 text-xs leading-5 text-muted">{pageGuide.description}</p>
            )}
            <p className="mt-2 max-w-prose text-sm leading-6 text-secondary">{currentStep.description}</p>
            {mode === 'page' && currentStep.target && !targetAvailable && (
              <p role="status" className="mt-2 rounded-md bg-subtle px-3 py-2 text-xs leading-5 text-muted">
                {missingTargetMessage(currentStep.target)}
              </p>
            )}
            {currentStep.links.length > 0 && (
              <ul className="mt-3 max-h-44 space-y-2 overflow-y-auto border-t border-default pt-3">
                {currentStep.links.map((link) => (
                  <li key={link.to} className="flex flex-col gap-0.5 text-sm">
                    <Link
                      to={link.to}
                      onClick={() => {
                        setMode('page');
                        setStepIndex(0);
                      }}
                      className="w-fit text-link underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    >
                      {link.label}
                    </Link>
                    {'description' in link && <span className="text-xs leading-5 text-muted">{link.description}</span>}
                  </li>
                ))}
              </ul>
            )}
          </div>

          <div className="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-default pt-3">
            {mode === 'overview' ? (
              <Button type="button" variant="ghost" size="touch" onClick={() => finish('skipped')}>
                Skip walkthrough
              </Button>
            ) : (
              <span className="text-xs text-muted">Use the page while this guide is open.</span>
            )}
            <div className="ml-auto flex items-center gap-2">
              {activeIndex > 0 && (
                <Button type="button" size="touch" onClick={() => goToStep(activeIndex - 1)}>
                  Previous
                </Button>
              )}
              <Button
                type="button"
                variant="primary"
                size="touch"
                onClick={() => {
                  if (lastStep) finish('completed');
                  else goToStep(activeIndex + 1);
                }}
              >
                {mode === 'overview' && activeIndex === 0 ? 'Start walkthrough' : lastStep ? 'Finish' : 'Next'}
              </Button>
            </div>
          </div>
        </aside>
      )}
    </>
  );
}
