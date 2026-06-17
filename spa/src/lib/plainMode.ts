/**
 * plainMode — TEMPORARY filming aid (safe to delete).
 *
 * Visiting any URL with `?plain=1` strips ALL stylesheets so the app renders as
 * raw, unstyled HTML (default fonts, blue links, stacked blocks). Loading the
 * same URL without the param shows the full design. Lets a "refresh reveals the
 * design" video be shot by just changing the address bar — no code edits.
 *
 * It removes the <style>/<link rel="stylesheet"> tags Vite injects, and keeps a
 * MutationObserver running so any styles injected later (HMR, lazy chunks, the
 * self-hosted display font) are stripped too.
 *
 * To remove this feature entirely: delete this file and its call in main.tsx.
 */

export function applyPlainMode(): void {
  if (typeof window === 'undefined') return;
  if (!new URLSearchParams(window.location.search).has('plain')) return;

  const strip = () => {
    document
      .querySelectorAll('style, link[rel="stylesheet"]')
      .forEach((el) => el.remove());
    // Disable any constructed/adopted stylesheets too (belt and suspenders).
    Array.from(document.styleSheets).forEach((sheet) => {
      try {
        sheet.disabled = true;
      } catch {
        /* cross-origin sheet — ignore */
      }
    });
  };

  strip();

  // Vite (and lazy route chunks) inject <style> tags after this runs, so keep
  // pruning them as they appear.
  const observer = new MutationObserver(strip);
  observer.observe(document.documentElement, { childList: true, subtree: true });

  // A visible marker so it's obvious the page is in plain mode while filming.
  document.documentElement.setAttribute('data-plain', 'true');
}
