import { useThemeStore, type ThemeMode } from '@/stores/themeStore';

export function useTheme() {
  const mode = useThemeStore((s) => s.mode);
  const resolvedTheme = useThemeStore((s) => s.resolvedTheme);
  const setMode = useThemeStore((s) => s.setMode);

  const toggle = () => {
    const next: ThemeMode =
      mode === 'system'
        ? resolvedTheme === 'dark'
          ? 'light'
          : 'dark'
        : mode === 'dark'
          ? 'light'
          : 'dark';
    setMode(next);
  };

  return { mode, resolvedTheme, setMode, toggle };
}
