import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';

// Topbar's siblings each pull their own data/WebSocket stack. None of them is
// under test here — the search affordances are.
vi.mock('./NotificationBell', () => ({ NotificationBell: () => null }));
vi.mock('./ProfileDropdown', () => ({ ProfileDropdown: () => null }));
vi.mock('./Breadcrumbs', () => ({ Breadcrumbs: () => null }));
vi.mock('@/components/brand/BrandLogo', () => ({ BrandLogo: () => null }));
vi.mock('@/api/landing', () => ({
  landingApi: { contact: () => Promise.resolve({ legal_name: 'Philippine Ogami Corporation' }) },
}));

import { Topbar } from './Topbar';

function renderTopbar() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <Topbar />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

/*
 * M009-F07. The only visible search trigger was `hidden sm:flex`, and the only
 * other way into the palette was a ⌘K/Ctrl+K document listener — not a gesture
 * a phone or a floor tablet has. Below 640px global search did not exist,
 * although the user manual documents it as a general feature.
 *
 * These assert PRESENCE and the responsive class that decides which trigger is
 * shown. jsdom computes no layout, so a real narrow-viewport check belongs in
 * the Chromium e2e suite; asserting a fabricated width here would test nothing.
 */
describe('Topbar search entry points', () => {
  it('offers a search trigger at both breakpoints', () => {
    renderTopbar();

    const wide = screen.getByText('Search…').closest('button');
    expect(wide).not.toBeNull();
    expect(wide?.className).toContain('hidden');
    expect(wide?.className).toContain('sm:flex');

    const narrow = screen.getByRole('button', { name: 'Search' });
    expect(narrow.className).toContain('sm:hidden');
  });

  it('opens the palette from the narrow-screen trigger', async () => {
    renderTopbar();

    fireEvent.click(screen.getByRole('button', { name: 'Search' }));

    // Lazily imported — the dialog resolves a tick later.
    expect(await screen.findByRole('dialog', { name: /global search/i })).toBeInTheDocument();
  });
});
