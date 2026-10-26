import { describe, expect, it } from 'vitest';
import {
  addedLinesByFile,
  inspectAddedLines,
  inspectPageShell,
} from './check-layout-discipline.mjs';

const routeSources = new Set(['spa/src/pages/example/index.tsx']);

describe('layout discipline audit', () => {
  it('checks only added diff lines', () => {
    const files = addedLinesByFile([
      '+++ b/spa/src/components/ui/Example.tsx',
      '-className="p-[13px]"',
      '+className="p-4"',
    ].join('\n'));

    expect(files.get('spa/src/components/ui/Example.tsx')).toEqual(['className="p-4"']);
  });

  it('allows token utilities and rejects new arbitrary sizes and raw declarations', () => {
    expect(inspectAddedLines('spa/src/components/ui/Example.tsx', ['className="p-4 text-body"'])).toEqual([]);
    expect(inspectAddedLines('spa/src/components/ui/Example.tsx', ['className="gap-[13px] text-[11px]"'])).toHaveLength(2);
    expect(inspectAddedLines('spa/src/styles/example.css', ['  padding-inline: 18px;'])).toHaveLength(1);
    expect(inspectAddedLines('spa/src/styles/example.css', ['@media (min-width: 1280px) {'])).toEqual([]);
    expect(inspectAddedLines('spa/src/styles/example.css', ['@media (min-width: 1281px) {'])).toHaveLength(1);
  });

  it('requires changed route pages to use the shared shell or explain an exception', () => {
    expect(inspectPageShell('spa/src/pages/example/index.tsx', 'return <div />;', routeSources)).toContain('layout-exception');
    expect(inspectPageShell('spa/src/pages/example/index.tsx', 'return <PageShell />;', routeSources)).toBeNull();
    expect(inspectPageShell('spa/src/pages/other/index.tsx', 'return <div />;', routeSources)).toBeNull();
    expect(inspectAddedLines('spa/src/pages/example/index.tsx', ['<div className="mx-auto max-w-6xl">'], routeSources)
      .some((violation) => violation.includes('centered max-width page wrapper'))).toBe(true);
  });
});
