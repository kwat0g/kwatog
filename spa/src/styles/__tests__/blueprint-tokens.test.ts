import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, it, expect } from 'vitest';

const tokens = readFileSync(join(process.cwd(), 'src/styles/tokens.css'), 'utf8');

/**
 * The landing page and auth surfaces share a technical "blueprint" register —
 * faint grid paper and hairline callout rules. Those are the only landing-scoped
 * colour decisions that survive; everything else resolves through the shared
 * Atelier tokens.
 */
describe('blueprint tokens', () => {
  it('declares all three', () => {
    expect(tokens).toContain('--blueprint-grid:');
    expect(tokens).toContain('--blueprint-line:');
    expect(tokens).toContain('--blueprint-grid-size:');
  });

  it('derives grid and line from ink, never a literal', () => {
    const grid = /--blueprint-grid:\s*([^;]+);/.exec(tokens)?.[1] ?? '';
    const line = /--blueprint-line:\s*([^;]+);/.exec(tokens)?.[1] ?? '';
    expect(grid).toContain('var(--text-primary)');
    expect(line).toContain('var(--text-primary)');
    expect(grid).not.toMatch(/#[0-9a-fA-F]{6}/);
    expect(line).not.toMatch(/#[0-9a-fA-F]{6}/);
  });

  it('declares them once — a per-theme redeclaration would fight the ink they derive from', () => {
    expect(tokens.match(/--blueprint-grid:/g)).toHaveLength(1);
    expect(tokens.match(/--blueprint-line:/g)).toHaveLength(1);
  });
});
