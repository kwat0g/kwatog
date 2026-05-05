/**
 * Sprint P8 — bind list-page filter state to the URL query string.
 *
 * Usage (drop-in replacement for `useState<ListParams>`):
 *
 *   const [filters, setFilters] = useUrlFilters<ListParams>({
 *     page: 1,
 *     per_page: 20,
 *     sort: 'created_at',
 *     direction: 'desc',
 *   });
 *
 * On mount, `filters` is seeded from the URL (so dashboard drill-downs
 * arrive at a pre-filtered list). Calling `setFilters({...})` updates both
 * React state AND the URL (via `setSearchParams`), so the back button works
 * and links are shareable.
 */
import { useCallback, useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';

type Primitive = string | number | boolean | null | undefined;
type Filters = Record<string, Primitive>;

function paramsToFilters<T extends Filters>(params: URLSearchParams, defaults: T): T {
  const result: Filters = { ...defaults };
  params.forEach((rawValue, key) => {
    const defaultValue = (defaults as Filters)[key];
    if (typeof defaultValue === 'number') {
      const n = Number(rawValue);
      result[key] = Number.isFinite(n) ? n : defaultValue;
    } else if (typeof defaultValue === 'boolean') {
      result[key] = rawValue === '1' || rawValue === 'true';
    } else {
      result[key] = rawValue;
    }
  });
  return result as T;
}

function filtersToParams<T extends Filters>(filters: T, defaults: T): URLSearchParams {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(filters)) {
    if (value === undefined || value === null || value === '') continue;
    // Skip noise: don't put defaults in the URL.
    if ((defaults as Filters)[key] === value) continue;
    params.set(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value));
  }
  return params;
}

export function useUrlFilters<T extends Filters>(defaults: T): [T, (next: T | ((prev: T) => T)) => void] {
  const [searchParams, setSearchParams] = useSearchParams();

  // Seed once from URL.
  const initial = useMemo(() => paramsToFilters(searchParams, defaults), []); // eslint-disable-line react-hooks/exhaustive-deps
  const [filters, setFiltersState] = useState<T>(initial);

  // Re-sync state if the URL changes externally (back button, deep link).
  useEffect(() => {
    const next = paramsToFilters(searchParams, defaults);
    setFiltersState((prev) => {
      // Cheap structural equality on top-level keys.
      const keys = new Set([...Object.keys(prev), ...Object.keys(next)]);
      for (const k of keys) {
        if ((prev as Filters)[k] !== (next as Filters)[k]) return next;
      }
      return prev;
    });
  }, [searchParams]); // eslint-disable-line react-hooks/exhaustive-deps

  const setFilters = useCallback(
    (next: T | ((prev: T) => T)) => {
      setFiltersState((prev) => {
        const value = typeof next === 'function' ? (next as (p: T) => T)(prev) : next;
        const params = filtersToParams(value, defaults);
        setSearchParams(params, { replace: false });
        return value;
      });
    },
    [defaults, setSearchParams],
  );

  return [filters, setFilters];
}
