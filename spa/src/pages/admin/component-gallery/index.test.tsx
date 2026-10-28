import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import ComponentGalleryPage from './index';

/**
 * The gallery is the subject of `spa/e2e/gallery.spec.ts`, so these tests guard
 * the two properties that make it a usable subject at all:
 *
 *   1. Every captured section still exists with its stable id — the spec
 *      addresses sections by `data-gallery-section`, and a section renamed
 *      without the baseline being regenerated must fail loudly here rather than
 *      leave a stale PNG silently pass.
 *   2. It stays deterministic. A screenshot baseline built on `Date.now()`,
 *      `Math.random()` or a fetching query goes red for reasons nobody edited,
 *      which is how a visual gate earns its reputation for flaking and gets
 *      switched off.
 *
 * jsdom has no layout engine, so — as with `narrow-width.test.ts` — this file
 * asserts structure and source contracts, not geometry. The geometry is what
 * the Playwright baseline measures in a real browser.
 */
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true, isAdmin: true }) }));

const SOURCE = readFileSync(
  join(process.cwd(), 'src', 'pages', 'admin', 'component-gallery', 'index.tsx'),
  'utf8',
);

/** Sections the visual gate photographs, in document order. */
const BASELINE_SECTIONS = [
  'buttons',
  'status',
  'forms',
  'feedback',
  'panels',
  'charts',
  'controls',
  'tables',
  'records',
];

afterEach(cleanup);

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/admin/component-gallery']}>
      <ComponentGalleryPage />
    </MemoryRouter>,
  );
}

describe('component gallery', () => {
  it('renders one heading per section', () => {
    renderPage();
    expect(screen.getByRole('heading', { level: 1, name: 'Component gallery' })).toBeVisible();
    for (const name of [
      'Buttons',
      'Chips & badges',
      'Form fields',
      'Feedback & empty states',
      'Panels & KPI cards',
      'Charts',
      'Selection controls',
      'Tables',
      'Record patterns',
      'Overlays',
    ]) {
      expect(screen.getByRole('heading', { level: 2, name })).toBeVisible();
    }
  });

  it('exposes exactly the baseline sections, and keeps overlays out of them', () => {
    const { container } = renderPage();
    const captured = [...container.querySelectorAll('[data-gallery-section]')].map((el) =>
      el.getAttribute('data-gallery-section'),
    );
    expect(captured).toEqual(BASELINE_SECTIONS);

    // A dialog renders into a portal, so a section screenshot would photograph
    // an empty frame — the overlay section is interactive-only by design.
    const interactive = container.querySelector('[data-gallery-interactive]');
    expect(interactive?.getAttribute('data-gallery-interactive')).toBe('overlays');
    expect(interactive?.hasAttribute('data-gallery-section')).toBe(false);
  });

  it('keeps the F-009 and F-010 preview cases in the catalogue', () => {
    // The gallery's job is to show the *states* a defect class lives in. These
    // two are the narrow-width cases the baseline exists to hold: the longest
    // money figure in a 2-up card, and six status options in one strip.
    renderPage();
    expect(screen.getAllByText('₱ 1,234,567.89').length).toBeGreaterThan(0);
    // Six options in one strip is the width that used to clip at 390px.
    const budgetStatus = screen.getByRole('radiogroup', { name: 'Budget status' });
    expect(within(budgetStatus).getAllByRole('radio')).toHaveLength(6);
    expect(SOURCE).toContain('max-w-[22rem]');
  });

  it('catalogues labelled state and compact chain cues', () => {
    const { container } = renderPage();
    expect(screen.getByText('StatusDot — labelled semantic states')).toBeVisible();
    expect(screen.getByRole('list', { name: 'Sales order chain' })).toBeVisible();
    expect(screen.getByText('FilterBar — active filters')).toBeVisible();
    const controls = screen.getByRole('region', { name: 'Selection controls' });
    expect(within(controls).getByRole('combobox', { name: 'Department' })).toBeVisible();
    expect(screen.getByRole('img', { name: 'On-time delivery: 5 points, rising from 91 to 96.' })).toBeInTheDocument();
    expect(container.querySelector('table[aria-busy="true"]')).toBeInTheDocument();
  });

  it('stays deterministic — no clock, no randomness, no fetch', () => {
    // Comments are stripped first: several of them explain *why* the page takes
    // literals, and a prose mention of `Date.now()` is not a call site.
    const code = SOURCE.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
    for (const forbidden of ['Date.now(', 'new Date(', 'Math.random(', 'useQuery(', 'useMutation(']) {
      expect(code, `${forbidden} makes the screenshot baseline flake`).not.toContain(forbidden);
    }
  });

  it('names every icon-only control', () => {
    const { container } = renderPage();
    const unnamed = [...container.querySelectorAll('button')].filter(
      (b) => !(b.textContent ?? '').trim() && !b.getAttribute('aria-label'),
    );
    expect(unnamed).toEqual([]);
  });
});
