import type { Config } from 'tailwindcss';

// Tokens are filled in Task 4 (design system foundation).
const config: Config = {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  darkMode: ['selector', '[data-theme="dark"]'],
  theme: { extend: {} },
  plugins: [],
};

export default config;
