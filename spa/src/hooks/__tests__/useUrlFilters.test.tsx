/**
 * useUrlFilters — two bugs this locks down.
 *
 *   • setSearchParams was called inside the setFiltersState updater, a side
 *     effect in a function React may run more than once. StrictMode does in
 *     dev, so one filter change pushed two history entries.
 *   • it pushed rather than replaced on every keystroke-debounced search,
 *     select and page step, so the back button stopped being an exit.
 */
import { describe, it, expect } from 'vitest';
import { StrictMode } from 'react';
import { act, render, screen } from '@testing-library/react';
import { MemoryRouter, useLocation, useNavigate } from 'react-router-dom';
import { useUrlFilters } from '../useUrlFilters';

interface Filters extends Record<string, unknown> {
  page: number;
  per_page: number;
  search?: string;
}

const DEFAULTS: Filters = { page: 1, per_page: 25 };

let setFiltersRef: (next: Filters | ((prev: Filters) => Filters)) => void;
let navigateRef: (delta: number) => void;

function Probe() {
  const [filters, setFilters] = useUrlFilters<Filters>(DEFAULTS);
  setFiltersRef = setFilters;
  const { search, pathname } = useLocation();
  navigateRef = useNavigate();
  return (
    <>
      <span data-testid="qs">{search}</span>
      <span data-testid="path">{pathname}</span>
      <span data-testid="page">{filters.page}</span>
      <span data-testid="term">{filters.search ?? ''}</span>
    </>
  );
}

function mount(initial = '/list', strict = false) {
  // Two entries with the list second, so `navigate(-1)` has somewhere real to
  // go. With one entry a Back is a no-op and would pass either way.
  const tree = (
    <MemoryRouter initialEntries={['/dashboard', initial]} initialIndex={1}>
      <Probe />
    </MemoryRouter>
  );
  return render(strict ? <StrictMode>{tree}</StrictMode> : tree);
}

describe('useUrlFilters', () => {
  it('seeds from the URL so a dashboard drill-down arrives pre-filtered', () => {
    mount('/list?page=3&search=bushing');
    expect(screen.getByTestId('page').textContent).toBe('3');
    expect(screen.getByTestId('term').textContent).toBe('bushing');
  });

  it('writes changes to the query string', () => {
    mount();
    act(() => setFiltersRef((f) => ({ ...f, search: 'pivot', page: 1 })));
    expect(screen.getByTestId('qs').textContent).toBe('?search=pivot');
  });

  it('keeps defaults out of the URL', () => {
    mount();
    act(() => setFiltersRef((f) => ({ ...f, page: 1, per_page: 25 })));
    expect(screen.getByTestId('qs').textContent).toBe('');
  });

  it('replaces rather than pushes, so one Back leaves the list', () => {
    mount();
    act(() => setFiltersRef((f) => ({ ...f, search: 'a' })));
    act(() => setFiltersRef((f) => ({ ...f, search: 'ab' })));
    act(() => setFiltersRef((f) => ({ ...f, page: 2 })));
    expect(screen.getByTestId('qs').textContent).toBe('?page=2&search=ab');

    // Under `push` these three updates would each be a history entry and one
    // Back would land on the previous filter state (?search=ab). Under
    // `replace` the only prior entry is the page the user came from.
    act(() => navigateRef(-1));
    expect(screen.getByTestId('path').textContent).toBe('/dashboard');
  });

  it('resolves an updater exactly once under StrictMode', () => {
    mount('/list', true);
    let calls = 0;
    act(() =>
      setFiltersRef((f) => {
        calls += 1;
        return { ...f, page: f.page + 1 };
      }),
    );
    expect(calls).toBe(1);
    expect(screen.getByTestId('page').textContent).toBe('2');
  });

  it('coerces numeric params back to numbers', () => {
    mount('/list?page=4');
    expect(screen.getByTestId('page').textContent).toBe('4');
    expect(typeof screen.getByTestId('page').textContent).toBe('string');
    act(() => setFiltersRef((f) => ({ ...f, page: f.page + 1 })));
    expect(screen.getByTestId('page').textContent).toBe('5');
  });
});
