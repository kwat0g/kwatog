import { useEffect } from 'react';
import { useLocation } from 'react-router-dom';

/**
 * Moves focus to the new page's primary heading (or the main container) after
 * a route transition so screen-reader users land at the start of new content.
 *
 * Mounted once in AppLayout — fires on every pathname change.
 */
export function usePageFocus() {
 const { pathname } = useLocation();

 useEffect(() => {
 const timer = setTimeout(() => {
 const main = document.getElementById('main-content');
 if (!main) return;

 const heading = main.querySelector<HTMLElement>('h1');
 const target = heading ?? main;

 // Ensure the target is programmatically focusable
 if (!target.hasAttribute('tabindex')) {
 target.setAttribute('tabindex', '-1');
 }
 // …and that focusing it does not draw a ring. The browser's default outline
 // on a `tabindex="-1"` element put a hard black rectangle around the page
 // title on every navigation — off-brand against the warm focus ring in
 // lib/focus.ts, and misleading, since the user never tabbed here. Nothing is
 // lost: an element with a negative tabindex cannot be reached by Tab, so it
 // has no keyboard-focus state worth indicating.
 target.classList.add('outline-none');
 target.focus({ preventScroll: true });
 }, 50);

 return () => clearTimeout(timer);
 }, [pathname]);
}
