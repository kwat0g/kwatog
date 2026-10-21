import { describe, it, expect, beforeEach, vi } from 'vitest';
import { useThemeStore } from '../themeStore';

vi.mock('@/api/auth', () => ({
  authApi: { updatePreferences: vi.fn().mockResolvedValue(undefined) },
}));

const attr = () => document.documentElement.getAttribute('data-theme');
const favicon = () => document.querySelector<HTMLLinkElement>('link[rel="icon"]')?.getAttribute('href');

describe('themeStore floor override', () => {
  beforeEach(() => {
    document.head.innerHTML = '<link rel="icon" href="/brand-logo.png">';
    useThemeStore.getState().popOverride();
    useThemeStore.getState().init('light');
  });

  it('applies floor to the document when pushed', () => {
    useThemeStore.getState().pushOverride('floor');
    expect(attr()).toBe('floor');
    expect(favicon()).toBe('/favicon-dark.svg?v=20261022');
  });

  it('restores the user preference when popped', () => {
    useThemeStore.getState().pushOverride('floor');
    useThemeStore.getState().popOverride();
    expect(attr()).toBe('light');
    expect(favicon()).toBe('/favicon-light.svg?v=20261022');
  });

  it('uses the light logo when the app is in light mode', () => {
    useThemeStore.getState().init('light');
    expect(favicon()).toBe('/favicon-light.svg?v=20261022');
  });

  it('switches the favicon with the selected theme', () => {
    useThemeStore.getState().setMode('dark');
    expect(favicon()).toBe('/favicon-dark.svg?v=20261022');
  });

  it('restores a dark preference, not a hardcoded light', () => {
    useThemeStore.getState().setMode('dark');
    useThemeStore.getState().pushOverride('floor');
    expect(attr()).toBe('floor');
    useThemeStore.getState().popOverride();
    expect(attr()).toBe('dark');
  });

  it('keeps mode unchanged while overridden — floor is not a user choice', () => {
    useThemeStore.getState().setMode('dark');
    useThemeStore.getState().pushOverride('floor');
    expect(useThemeStore.getState().mode).toBe('dark');
  });

  it('ignores a preference change made while overridden, but honours it after', () => {
    useThemeStore.getState().pushOverride('floor');
    useThemeStore.getState().setMode('light');
    expect(attr()).toBe('floor');
    useThemeStore.getState().popOverride();
    expect(attr()).toBe('light');
  });

  it('is idempotent — a second push does not corrupt the saved preference', () => {
    useThemeStore.getState().setMode('dark');
    useThemeStore.getState().pushOverride('floor');
    useThemeStore.getState().pushOverride('floor');
    useThemeStore.getState().popOverride();
    expect(attr()).toBe('dark');
  });

  it('popping without a push is a no-op', () => {
    useThemeStore.getState().setMode('dark');
    useThemeStore.getState().popOverride();
    expect(attr()).toBe('dark');
  });
});
