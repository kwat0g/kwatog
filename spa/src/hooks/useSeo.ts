import { useEffect } from 'react';

/**
 * Per-page document metadata for the public (unauthenticated) routes.
 *
 * The ERP itself is behind auth and must never be indexed — this hook is only
 * for the marketing landing and the public careers board. Social scrapers do
 * not execute JS, so the base Open Graph tags live in `index.html`; this hook
 * keeps title/description/canonical correct for crawlers that do render.
 */

interface Seo {
  title?: string;
  description?: string;
  path?: string;
  image?: string;
  type?: 'website' | 'article';
}

function upsertMeta(attr: 'name' | 'property', key: string, content: string): void {
  let el = document.head.querySelector<HTMLMetaElement>(`meta[${attr}="${key}"]`);
  if (!el) {
    el = document.createElement('meta');
    el.setAttribute(attr, key);
    document.head.appendChild(el);
  }
  el.setAttribute('content', content);
}

function upsertCanonical(href: string): void {
  let el = document.head.querySelector<HTMLLinkElement>('link[rel="canonical"]');
  if (!el) {
    el = document.createElement('link');
    el.setAttribute('rel', 'canonical');
    document.head.appendChild(el);
  }
  el.setAttribute('href', href);
}

export function useSeo({ title, description, path, image = '/ogami-icon-512.png', type = 'website' }: Seo): void {
  useEffect(() => {
    if (title) {
      document.title = title;
      upsertMeta('property', 'og:title', title);
      upsertMeta('name', 'twitter:title', title);
    }

    if (description) {
      upsertMeta('name', 'description', description);
      upsertMeta('property', 'og:description', description);
      upsertMeta('name', 'twitter:description', description);
    }

    const absoluteImage = new URL(image, window.location.origin).toString();
    upsertMeta('property', 'og:image', absoluteImage);
    upsertMeta('name', 'twitter:image', absoluteImage);
    upsertMeta('property', 'og:type', type);

    if (path) {
      const url = new URL(path, window.location.origin).toString();
      upsertMeta('property', 'og:url', url);
      upsertCanonical(url);
    }
  }, [title, description, path, image, type]);
}
