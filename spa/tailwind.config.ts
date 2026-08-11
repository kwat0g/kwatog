import type { Config } from 'tailwindcss';

/**
 * All token values come from spa/src/styles/tokens.css (CSS variables).
 * Three palettes: :root (light), [data-theme="dark"], [data-theme="floor"].
 * NEVER hard-code colors or fonts in components — extend here instead.
 *
 * Every colour is wrapped in `color-mix(... calc(<alpha-value> * 100%) ...)`.
 * The tokens are hex literals behind CSS variables, and Tailwind cannot split a
 * `var()` into channels, so a plain `'var(--accent)'` makes `bg-accent/10` emit
 * *nothing at all* — the class silently disappears from the stylesheet. The
 * `<alpha-value>` placeholder plus `color-mix` gives every token working opacity
 * modifiers (`bg-accent/10`, `border-danger/30`, `bg-canvas/85`).
 */
const config: Config = {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  darkMode: ['selector', '[data-theme="dark"]'],
  theme: {
    extend: {
      colors: {
        // Canvas
        canvas: 'color-mix(in srgb, var(--bg-canvas) calc(<alpha-value> * 100%), transparent)',
        surface: 'color-mix(in srgb, var(--bg-surface) calc(<alpha-value> * 100%), transparent)',
        elevated: 'color-mix(in srgb, var(--bg-elevated) calc(<alpha-value> * 100%), transparent)',
        subtle: 'color-mix(in srgb, var(--bg-subtle) calc(<alpha-value> * 100%), transparent)',

        // Text — exposed as text-primary / text-secondary / text-muted / text-subtle
        primary: 'color-mix(in srgb, var(--text-primary) calc(<alpha-value> * 100%), transparent)',
        secondary:
          'color-mix(in srgb, var(--text-secondary) calc(<alpha-value> * 100%), transparent)',
        muted: 'color-mix(in srgb, var(--text-muted) calc(<alpha-value> * 100%), transparent)',
        'text-subtle':
          'color-mix(in srgb, var(--text-subtle) calc(<alpha-value> * 100%), transparent)',

        // Borders
        'border-subtle':
          'color-mix(in srgb, var(--border-subtle) calc(<alpha-value> * 100%), transparent)',
        'border-default':
          'color-mix(in srgb, var(--border-default) calc(<alpha-value> * 100%), transparent)',
        'border-strong':
          'color-mix(in srgb, var(--border-strong) calc(<alpha-value> * 100%), transparent)',

        // Convenience: same gray ramp exposed as bg/text utilities
        // (e.g. bg-strong, text-strong) so neutral fills don't need
        // module-specific tokens.
        strong: 'color-mix(in srgb, var(--border-strong) calc(<alpha-value> * 100%), transparent)',

        // Accent
        accent: {
          DEFAULT: 'color-mix(in srgb, var(--accent) calc(<alpha-value> * 100%), transparent)',
          hover: 'color-mix(in srgb, var(--accent-hover) calc(<alpha-value> * 100%), transparent)',
          fg: 'color-mix(in srgb, var(--accent-fg) calc(<alpha-value> * 100%), transparent)',
        },

        // Links — colored per design-system ("color is for meaning")
        link: {
          DEFAULT: 'color-mix(in srgb, var(--text-link) calc(<alpha-value> * 100%), transparent)',
          hover:
            'color-mix(in srgb, var(--text-link-hover) calc(<alpha-value> * 100%), transparent)',
        },

        // Semantic
        success: {
          DEFAULT: 'color-mix(in srgb, var(--success) calc(<alpha-value> * 100%), transparent)',
          bg: 'color-mix(in srgb, var(--success-bg) calc(<alpha-value> * 100%), transparent)',
          fg: 'color-mix(in srgb, var(--success-fg) calc(<alpha-value> * 100%), transparent)',
        },
        warning: {
          DEFAULT: 'color-mix(in srgb, var(--warning) calc(<alpha-value> * 100%), transparent)',
          bg: 'color-mix(in srgb, var(--warning-bg) calc(<alpha-value> * 100%), transparent)',
          fg: 'color-mix(in srgb, var(--warning-fg) calc(<alpha-value> * 100%), transparent)',
        },
        danger: {
          DEFAULT: 'color-mix(in srgb, var(--danger) calc(<alpha-value> * 100%), transparent)',
          bg: 'color-mix(in srgb, var(--danger-bg) calc(<alpha-value> * 100%), transparent)',
          fg: 'color-mix(in srgb, var(--danger-fg) calc(<alpha-value> * 100%), transparent)',
        },
        info: {
          DEFAULT: 'color-mix(in srgb, var(--info) calc(<alpha-value> * 100%), transparent)',
          bg: 'color-mix(in srgb, var(--info-bg) calc(<alpha-value> * 100%), transparent)',
          fg: 'color-mix(in srgb, var(--info-fg) calc(<alpha-value> * 100%), transparent)',
        },
        purple: {
          DEFAULT: 'color-mix(in srgb, var(--purple) calc(<alpha-value> * 100%), transparent)',
          bg: 'color-mix(in srgb, var(--purple-bg) calc(<alpha-value> * 100%), transparent)',
          fg: 'color-mix(in srgb, var(--purple-fg) calc(<alpha-value> * 100%), transparent)',
        },

        // Focus ring
        ring: 'color-mix(in srgb, var(--ring) calc(<alpha-value> * 100%), transparent)',

        // Blueprint line-work — landing + auth technical register. Derived
        // from ink in tokens.css, so it follows every palette for free.
        blueprint: {
          grid: 'color-mix(in srgb, var(--blueprint-grid) calc(<alpha-value> * 100%), transparent)',
          line: 'color-mix(in srgb, var(--blueprint-line) calc(<alpha-value> * 100%), transparent)',
        },
      },

      // Border-only aliases: `border-default` / `border-subtle` / `border-strong`
      // read better than `border-border-default`, and DEFAULT lets a bare
      // `border` pick up the token.
      textColor: {
        subtle: 'color-mix(in srgb, var(--text-subtle) calc(<alpha-value> * 100%), transparent)',
        'text-subtle':
          'color-mix(in srgb, var(--text-subtle) calc(<alpha-value> * 100%), transparent)',
      },

      borderColor: {
        DEFAULT:
          'color-mix(in srgb, var(--border-default) calc(<alpha-value> * 100%), transparent)',
        subtle: 'color-mix(in srgb, var(--border-subtle) calc(<alpha-value> * 100%), transparent)',
        default:
          'color-mix(in srgb, var(--border-default) calc(<alpha-value> * 100%), transparent)',
        strong: 'color-mix(in srgb, var(--border-strong) calc(<alpha-value> * 100%), transparent)',
      },

      // `divide-*` follows `border-*` so a divided list uses the same ramp.
      divideColor: {
        DEFAULT:
          'color-mix(in srgb, var(--border-default) calc(<alpha-value> * 100%), transparent)',
        subtle: 'color-mix(in srgb, var(--border-subtle) calc(<alpha-value> * 100%), transparent)',
        default:
          'color-mix(in srgb, var(--border-default) calc(<alpha-value> * 100%), transparent)',
        strong: 'color-mix(in srgb, var(--border-strong) calc(<alpha-value> * 100%), transparent)',
      },

      fontFamily: {
        sans: ['Public Sans Variable', 'system-ui', 'sans-serif'],
        mono: ['Spline Sans Mono Variable', 'SF Mono', 'Menlo', 'monospace'],
        display: ['Instrument Serif', 'Georgia', 'serif'],
      },

      fontSize: {
        '2xs': ['10px', { lineHeight: '1.4' }],
        xs: ['11px', { lineHeight: '1.4' }],
        sm: ['12px', { lineHeight: '1.4' }],
        base: ['13px', { lineHeight: '1.5' }],
        md: ['14px', { lineHeight: '1.4' }],
        lg: ['16px', { lineHeight: '1.3' }],
        xl: ['20px', { lineHeight: '1.25' }],
        '2xl': ['26px', { lineHeight: '1.15' }],
        '3xl': ['32px', { lineHeight: '1.1' }],
      },

      borderRadius: {
        sm: 'var(--radius-sm)',
        md: 'var(--radius-md)',
        DEFAULT: 'var(--radius-md)',
        lg: 'var(--radius-lg)',
        full: 'var(--radius-full)',
      },

      // Density tokens. Office palettes declare 32px / 28px; [data-theme='floor']
      // raises them to 48px / 44px, so a component reads them unconditionally
      // rather than branching on theme.
      height: {
        row: 'var(--row-height)',
      },

      minHeight: {
        hit: 'var(--hit-min)',
      },

      minWidth: {
        hit: 'var(--hit-min)',
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
          '0%': { backgroundPosition: '-200% 0' },
          '100%': { backgroundPosition: '200% 0' },
        },
        'fade-in': {
          '0%': { opacity: '0' },
          '100%': { opacity: '1' },
        },
        'slide-up': {
          '0%': { opacity: '0', transform: 'translateY(8px)' },
          '100%': { opacity: '1', transform: 'translateY(0)' },
        },
        // Sprint P3 — soft pulse on the active step of an ApprovalTimeline
        // (and reusable for any "current bottleneck" indicator). 1.5s cycle,
        // gentle on the eyes, respects prefers-reduced-motion via globals.css.
        // Pre-Atelier survivor: this hardcoded `rgba(79,70,229,.55)` — Tailwind's
        // default indigo-600 — which matches no token in any of the three
        // palettes and is exactly the blue family DESIGN-SYSTEM.md names as what
        // makes an interface read as templated. It renders on the active step of
        // every ApprovalTimeline. Now the accent (clay), via color-mix so the
        // ring follows whichever palette is mounted. The token gate missed it
        // because HEX only matches `#rrggbb` and PALETTE only matches class
        // names, never a raw `rgba()` in this config.
        'approval-pulse': {
          '0%, 100%': {
            boxShadow: '0 0 0 0 color-mix(in srgb, var(--accent) 55%, transparent)',
          },
          '50%': {
            boxShadow: '0 0 0 6px color-mix(in srgb, var(--accent) 0%, transparent)',
          },
        },
        'slide-right': {
          '0%': { opacity: '0', transform: 'translateX(-100%)' },
          '100%': { opacity: '1', transform: 'translateX(0)' },
        },
        // Landing — seamless logo marquee (track holds two copies, shifts -50%).
        marquee: {
          '0%': { transform: 'translateX(0)' },
          '100%': { transform: 'translateX(-50%)' },
        },
      },

      animation: {
        shimmer: 'shimmer 1.8s ease-in-out infinite',
        'fade-in': 'fade-in var(--duration-slow) var(--ease-default)',
        'slide-up': 'slide-up var(--duration-slow) var(--ease-default)',
        'approval-pulse': 'approval-pulse 1.5s ease-in-out infinite',
        'slide-right': 'slide-right 200ms var(--ease-default)',
        marquee: 'marquee 36s linear infinite',
      },
    },
  },
  plugins: [],
};

export default config;
