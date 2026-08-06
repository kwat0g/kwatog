import { describe, it, expect, beforeEach, vi } from 'vitest';
import { useThemeStore } from '../themeStore';

vi.mock('@/api/auth', () => ({
  authApi: { updatePreferences: vi.fn().mockResolvedValue(undefined) },
}));

const attr = () => document.documentElement.getAttribute('data-theme');

describe('themeStore floor override', () => {
  beforeEach(() => {
    useThemeStore.getState().popOverride();
    useThemeStore.getState().init('light');
  });

  it('applies floor to the document when pushed', () => {
    useThemeStore.getState().pushOverride('floor');
    expect(attr()).toBe('floor');
  });

  it('restores the user preference when popped', () => {
    useThemeStore.getState().pushOverride('floor');
    useThemeStore.getState().popOverride();
    expect(attr()).toBe('light');
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
