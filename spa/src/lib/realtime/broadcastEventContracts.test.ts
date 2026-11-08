import { readdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const repositoryRoot = resolve(process.cwd(), '..');

function filesUnder(directory: string, extensions: Set<string>): string[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = resolve(directory, entry.name);
    if (entry.isDirectory()) return filesUnder(path, extensions);
    return extensions.has(entry.name.slice(entry.name.lastIndexOf('.'))) ? [path] : [];
  });
}

describe('Laravel broadcast event names', () => {
  it('has every returned broadcast name represented in runtime SPA source', () => {
    const backendNames = new Set<string>();
    const unparsedMethods: string[] = [];
    for (const path of filesUnder(`${repositoryRoot}/api/app`, new Set(['.php']))) {
      const source = readFileSync(path, 'utf8');
      for (const method of source.matchAll(/function\s+broadcastAs\s*\([^)]*\)\s*:\s*string\s*\{([\s\S]*?)\n\s*\}/g)) {
        const body = method[1];
        const direct = body.match(/\breturn\s+(["'])(.*?)\1\s*;/s);
        if (direct) {
          backendNames.add(direct[2]);
          continue;
        }

        const ternary = body.match(/\breturn\s+[\s\S]*?\?\s*(["'])(.*?)\1\s*:\s*(["'])(.*?)\3\s*;/s);
        if (ternary) {
          backendNames.add(ternary[2]);
          backendNames.add(ternary[4]);
          continue;
        }

        unparsedMethods.push(path);
      }
    }
    expect(unparsedMethods).toEqual([]);

    const frontendNames = new Set<string>();
    const frontendSource = filesUnder(`${repositoryRoot}/spa/src`, new Set(['.ts', '.tsx']))
      .filter((path) => !/\.(test|spec)\.tsx?$/.test(path));
    for (const path of frontendSource) {
      const source = readFileSync(path, 'utf8');
      for (const match of source.matchAll(/(["'])(.*?)\1/g)) {
        frontendNames.add(match[2].replace(/^\./, ''));
      }
    }

    const missing = [...backendNames].filter((name) => !frontendNames.has(name)).sort();
    expect(missing).toEqual([]);
  });
});
