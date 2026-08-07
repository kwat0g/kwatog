#!/usr/bin/env node
/**
 * Rasterise the brand marks in brand/ into the PNGs the PWA manifests ask for.
 *
 * Why this exists: the icons in public/ were committed as 1x1 pixel
 * placeholders, and factory-manifest.webmanifest pointed at two files that were
 * never committed at all. Both PWAs had been installing with broken icons.
 * Hand-committing binaries is how that happens twice, so the rasters are
 * generated from brand/*.svg instead and this script is the only way they get
 * made.
 *
 * Requires ImageMagick (`convert`), which is what the project already has
 * available. Not wired into CI: it needs a binary CI does not install, and the
 * outputs are committed. Run it by hand when a mark changes.
 *
 *   node scripts/build-brand-assets.mjs
 */
import { execFileSync } from 'node:child_process';
import { mkdirSync, existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');

/**
 * Each entry: one source SVG rendered at N sizes.
 * `sizes` are the exact pixel dimensions the manifests reference — keep them
 * in sync with public/*.webmanifest or the manifest 404s again.
 */
const TARGETS = [
  { src: 'brand/mark.svg', out: 'public/ogami-icon', sizes: [192, 512] },
  { src: 'brand/mark-driver.svg', out: 'public/driver-icon', sizes: [192, 512] },
  { src: 'brand/mark.svg', out: 'public/apple-touch-icon', sizes: [180] },
];

function render(src, dest, size) {
  execFileSync(
    'convert',
    [
      '-background', 'none',
      `${resolve(root, src)}`,
      '-resize', `${size}x${size}`,
      // PWA icons are composited onto an OS-chosen background; flattening onto
      // the mark's own bleed colour keeps edges clean at small sizes.
      '-strip',
      resolve(root, dest),
    ],
    { stdio: 'inherit' },
  );
}

let made = 0;
for (const { src, out, sizes } of TARGETS) {
  if (!existsSync(resolve(root, src))) {
    console.error(`✗ missing source: ${src}`);
    process.exit(1);
  }
  for (const size of sizes) {
    const dest = sizes.length === 1 ? `${out}.png` : `${out}-${size}.png`;
    mkdirSync(dirname(resolve(root, dest)), { recursive: true });
    render(src, dest, size);
    console.log(`✓ ${dest} (${size}x${size})`);
    made++;
  }
}
console.log(`\n${made} raster${made === 1 ? '' : 's'} written from ${TARGETS.length} source${TARGETS.length === 1 ? '' : 's'}.`);
