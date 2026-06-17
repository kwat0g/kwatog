/**
 * Catalogue of revolved parts for the 3D showcase.
 *
 * Each part is a stack of axisymmetric SECTIONS — every section is a small
 * half-profile (X = radius ≥ 0, Y = height) revolved around the Y axis. Stacking
 * them assembled (explode = 0) reproduces the whole part; giving each a signed
 * `explode` offset lets the viewer pull them apart into a clean engineering
 * exploded view along the axis.
 *
 * Profiles are authored in the same neutral unit scale the hero canvas uses
 * (radius ≲ 1.5, total height ≈ 2.5) so the shared camera framing just works.
 */

import { Vector2 } from 'three';

export interface PartSection {
  /** Half-profile, revolved around Y. A closed loop → a solid ring/disc; an open
   *  arc → a shell (e.g. a dome). */
  profile: Vector2[];
  /** Signed offset (in scene units) applied along Y when the part is exploded. */
  explode: number;
  /** Optional label shown next to the section in the exploded view. */
  label?: string;
}

export interface PartDef {
  id: string;
  name: string;
  material: string;
  tolerance: string;
  application: string;
  /** Bore note shown in the spec panel. */
  feature: string;
  sections: PartSection[];
  /** EdgesGeometry crease threshold — lower shows more feature lines. */
  edgeAngle: number;
}

/** A rectangular cross-section revolved into a ring / tube / disc (inner = 0). */
function ring(inner: number, outer: number, y0: number, y1: number): Vector2[] {
  return [
    new Vector2(inner, y0),
    new Vector2(outer, y0),
    new Vector2(outer, y1),
    new Vector2(inner, y1),
    new Vector2(inner, y0),
  ];
}

/** A quarter-elliptical dome shell from rim (rimR, baseY) to apex (0, baseY+h). */
function dome(rimR: number, baseY: number, h: number, steps = 12): Vector2[] {
  const pts: Vector2[] = [];
  for (let i = 0; i <= steps; i += 1) {
    const a = (i / steps) * (Math.PI / 2);
    pts.push(new Vector2(rimR * Math.cos(a), baseY + h * Math.sin(a)));
  }
  return pts;
}

export const PARTS: PartDef[] = [
  {
    id: 'wiper-bushing',
    name: 'Wiper bushing',
    material: 'POM resin',
    tolerance: '±0.02 mm',
    application: 'Steering & wiper linkages',
    feature: 'Ø 12.0 bore',
    edgeAngle: 16,
    sections: [
      { profile: ring(0.45, 1.45, -1.5, -1.2), explode: -1.5, label: 'Flange' },
      { profile: ring(0.45, 0.82, -1.2, 0.9), explode: 0, label: 'Body' },
      { profile: ring(0.45, 1.02, 0.9, 1.18), explode: 1.5, label: 'Collar' },
    ],
  },
  {
    id: 'pivot-cap',
    name: 'Pivot cap',
    material: 'PA66 resin',
    tolerance: '±0.03 mm',
    application: 'Hood & engine covers',
    feature: 'Domed shell',
    edgeAngle: 22,
    sections: [
      { profile: ring(0.9, 1.12, -1.4, 0.4), explode: -1.5, label: 'Skirt' },
      { profile: ring(0.2, 1.12, 0.4, 0.62), explode: 0, label: 'Shoulder' },
      { profile: dome(1.0, 0.62, 0.62), explode: 1.5, label: 'Dome' },
    ],
  },
  {
    id: 'filler-cap',
    name: 'Oil filler cap',
    material: 'PA66 resin',
    tolerance: '±0.05 mm',
    application: 'Fuel & fluid systems',
    feature: 'Sealed deck',
    edgeAngle: 22,
    sections: [
      { profile: ring(0.25, 1.5, -1.6, -1.35), explode: -1.5, label: 'Seal flange' },
      { profile: ring(0.25, 1.2, -1.35, 0.6), explode: 0, label: 'Grip skirt' },
      { profile: ring(0.25, 1.25, 0.6, 0.86), explode: 1.5, label: 'Top deck' },
    ],
  },
  {
    id: 'spacer-collar',
    name: 'Spacer collar',
    material: 'POM resin',
    tolerance: '±0.02 mm',
    application: 'Bearing & shaft assemblies',
    feature: 'Ø 10.0 bore',
    edgeAngle: 16,
    sections: [
      { profile: ring(0.5, 1.3, -1.0, -0.7), explode: -1.4, label: 'Lower flange' },
      { profile: ring(0.5, 0.95, -0.7, 0.7), explode: 0, label: 'Race' },
      { profile: ring(0.5, 1.3, 0.7, 1.0), explode: 1.4, label: 'Upper flange' },
    ],
  },
];
