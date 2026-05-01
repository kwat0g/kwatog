import type { Config } from 'tailwindcss';

/**
 * All token values come from spa/src/styles/tokens.css (CSS variables).
 * NEVER hard-code colors or fonts in components — extend here instead.
 */
const config: Config = {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  darkMode: ['selector', '[data-theme="dark"]'],
  theme: {
    extend: {
      colors: {
        // Canvas
        canvas: 'var(--bg-canvas)',
        surface: 'var(--bg-surface)',
        elevated: 'var(--bg-elevated)',
        subtle: 'var(--bg-subtle)',

        // Text — exposed as text-primary / text-secondary / text-muted / text-subtle
        primary: 'var(--text-primary)',
        secondary: 'var(--text-secondary)',
        muted: 'var(--text-muted)',
        'text-subtle': 'var(--text-subtle)',

        // Borders
        'border-subtle': 'var(--border-subtle)',
        'border-default': 'var(--border-default)',
        'border-strong': 'var(--border-strong)',

        // Accent
        accent: {
          DEFAULT: 'var(--accent)',
          hover: 'var(--accent-hover)',
          fg: 'var(--accent-fg)',
        },

        // Semantic
        success: {
          DEFAULT: 'var(--success)',
          bg: 'var(--success-bg)',
          fg: 'var(--success-fg)',
        },
        warning: {
          DEFAULT: 'var(--warning)',
          bg: 'var(--warning-bg)',
          fg: 'var(--warning-fg)',
        },
        danger: {
          DEFAULT: 'var(--danger)',
          bg: 'var(--danger-bg)',
          fg: 'var(--danger-fg)',
        },
        info: {
          DEFAULT: 'var(--info)',
          bg: 'var(--info-bg)',
          fg: 'var(--info-fg)',
        },
        purple: {
          DEFAULT: 'var(--purple)',
          bg: 'var(--purple-bg)',
          fg: 'var(--purple-fg)',
        },

        // Focus ring
        ring: 'var(--ring)',
      },

      borderColor: {
        DEFAULT: 'var(--border-default)',
        subtle: 'var(--border-subtle)',
        default: 'var(--border-default)',
        strong: 'var(--border-strong)',
      },

      fontFamily: {
        sans: ['Geist', 'system-ui', 'sans-serif'],
        mono: ['Geist Mono', 'SF Mono', 'Menlo', 'monospace'],
      },

      fontSize: {
        '2xs': ['10px', { lineHeight: '1.4' }],
        xs: ['11px', { lineHeight: '1.4' }],
        sm: ['12px', { lineHeight: '1.4' }],
        base: ['13px', { lineHeight: '1.5' }],
        md: ['14px', { lineHeight: '1.4' }],
        lg: ['16px', { lineHeight: '1.3' }],
        xl: ['18px', { lineHeight: '1.3' }],
        '2xl': ['22px', { lineHeight: '1.2' }],
      },

      borderRadius: {
        sm: 'var(--radius-sm)',
        md: 'var(--radius-md)',
        DEFAULT: 'var(--radius-md)',
        lg: 'var(--radius-lg)',
        full: 'var(--radius-full)',
      },

      transitionDuration: {
        fast: 'var(--duration-fast)',
        normal: 'var(--duration-normal)',
        slow: 'var(--duration-slow)',
      },

      transitionTimingFunction: {
        DEFAULT: 'var(--ease-default)',
      },

      boxShadow: {
        focus: 'var(--shadow-focus)',
        menu: 'var(--shadow-menu)',
      },

      keyframes: {
        shimmer: {
          '0%': { backgroundPosition: '-1000px 0' },
          '100%': { backgroundPosition: '1000px 0' },
        },
        'fade-in': {
          '0%': { opacity: '0' },
          '100%': { opacity: '1' },
        },
        'slide-up': {
          '0%': { opacity: '0', transform: 'translateY(8px)' },
          '100%': { opacity: '1', transform: 'translateY(0)' },
        },
      },

      animation: {
        shimmer: 'shimmer 1.5s linear infinite',
        'fade-in': 'fade-in var(--duration-slow) var(--ease-default)',
        'slide-up': 'slide-up var(--duration-slow) var(--ease-default)',
      },
    },
  },
  plugins: [],
};

export default config;
