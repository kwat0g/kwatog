/**
 * Landing-page content model.
 *
 * Single source of truth for every piece of customer-facing copy on the
 * public marketing site. Kept separate from presentation so the sections stay
 * declarative and the messaging can be tuned without touching layout/animation.
 *
 * Audience: automotive OEMs / Tier-1 buyers evaluating Philippine Ogami
 * Corporation as a precision injection-molding partner. Tone: confident,
 * Filipino-proud, quality-obsessed. No internal/ERP language.
 */

import {
  Car,
  Stethoscope,
  Layers,
  Hammer,
  PackageCheck,
  Boxes,
  Thermometer,
  Ruler,
  ScanLine,
  FileCheck2,
  type LucideIcon,
} from 'lucide-react';

export const COMPANY = {
  legalName: 'Philippine Ogami Corporation',
  shortName: 'Ogami',
  tagline: 'Precision, molded in the Philippines.',
  locationLine: 'FCIE, Dasmariñas · Cavite, Philippines',
  addressLines: [
    'First Cavite Industrial Estate (FCIE)',
    'Dasmariñas, Cavite 4114',
    'Republic of the Philippines',
  ],
  email: 'sales@ogami.com.ph',
  phone: '+63 (46) 000 0000',
} as const;

export interface NavLink {
  label: string;
  href: string;
}

export const NAV_LINKS: NavLink[] = [
  { label: 'Capabilities', href: '#capabilities' },
  { label: 'Process', href: '#process' },
  { label: 'Quality', href: '#quality' },
  { label: 'Filipino-made', href: '#filipino-made' },
  { label: 'Contact', href: '#contact' },
];

/** Automakers Ogami supplies — shown as plain wordmarks (nominative use). */
export const OEM_PARTNERS = [
  'Toyota',
  'Nissan',
  'Honda',
  'Suzuki',
  'Yamaha',
] as const;

export interface Capability {
  id: string;
  title: string;
  icon: LucideIcon;
  blurb: string;
  points: string[];
  tag: string;
}

export const CAPABILITIES: Capability[] = [
  {
    id: 'automotive',
    title: 'Automotive resin parts',
    icon: Car,
    tag: 'IATF 16949',
    blurb:
      'Safety-critical injection-molded components, produced to automotive-grade discipline and supplied tier-direct to the world’s leading OEMs.',
    points: ['Wiper bushings', 'Pivot caps', 'Relay covers'],
  },
  {
    id: 'precision',
    title: 'Medical & precision parts',
    icon: Stethoscope,
    tag: 'Tight tolerance',
    blurb:
      'Cleanroom-ready molding for devices that cannot fail — tight tolerances, full lot traceability, and material certainty on every shot.',
    points: ['Light-electric resin parts', 'Micro-tolerance molding', 'Lot traceability'],
  },
  {
    id: 'assembly',
    title: 'Assembly & sub-assembly',
    icon: Layers,
    tag: 'Value-added',
    blurb:
      'Molding, fitting, and inspection combined into one controlled flow, so finished assemblies arrive ready for your line.',
    points: ['Integrated sub-assembly', 'In-line inspection', 'Kitted delivery'],
  },
  {
    id: 'tooling',
    title: 'In-house mold design & tooling',
    icon: Hammer,
    tag: 'Built in-house',
    blurb:
      'We design, cut, and maintain our own molds — protecting your tolerances, your lead time, and your intellectual property.',
    points: ['Mold design', 'Precision fabrication', 'Preventive maintenance'],
  },
];

export interface ProcessStep {
  index: string;
  title: string;
  icon: LucideIcon;
  body: string;
}

/** The Ogami journey from resin to certified shipment (drives the pinned scroll). */
export const PROCESS_STEPS: ProcessStep[] = [
  {
    index: '01',
    title: 'Material & incoming QC',
    icon: Boxes,
    body: 'Every batch of resin is checked against its certificate of analysis and moisture spec before it is ever accepted into inventory.',
  },
  {
    index: '02',
    title: 'Mold design & tooling',
    icon: Hammer,
    body: 'Precision molds are designed and cut in-house, so the geometry your part depends on is owned and controlled end-to-end.',
  },
  {
    index: '03',
    title: 'Injection molding',
    icon: Layers,
    body: 'Controlled-process molding holds pressure, temperature, and cycle time to tight windows — repeatable shot after shot, at scale.',
  },
  {
    index: '04',
    title: 'Cooling & forming',
    icon: Thermometer,
    body: 'Managed cooling locks in dimensional stability, eliminating warp and internal stress before the part leaves the cell.',
  },
  {
    index: '05',
    title: 'Inspection — AQL 0.65',
    icon: Ruler,
    body: 'Critical dimensions are measured against spec tolerances under AQL 0.65 Level II sampling, with in-process checks between operations.',
  },
  {
    index: '06',
    title: 'Certificate & delivery',
    icon: FileCheck2,
    body: 'A Certificate of Conformance is generated from real inspection data, and parts ship with full traceability from resin to dock.',
  },
];

export interface StatItem {
  id: string;
  value: number;
  prefix?: string;
  suffix?: string;
  decimals?: number;
  label: string;
}

export const STATS: StatItem[] = [
  { id: 'employees', value: 200, suffix: '+', label: 'Skilled Filipino employees' },
  { id: 'oem', value: 5, label: 'Global OEM partners' },
  { id: 'ppm', value: 10, prefix: '≤', suffix: ' PPM', label: 'Defect rate target' },
  { id: 'otd', value: 99.8, suffix: '%', decimals: 1, label: 'On-time delivery' },
];

export interface QualityPillar {
  id: string;
  title: string;
  icon: LucideIcon;
  body: string;
}

/** IATF 16949 quality woven across the chain — framed as customer guarantees. */
export const QUALITY_PILLARS: QualityPillar[] = [
  {
    id: 'incoming',
    title: 'Incoming verification',
    icon: PackageCheck,
    body: 'Resin certificates and moisture are verified before any material is accepted — quality starts before the first shot.',
  },
  {
    id: 'in-process',
    title: 'In-process sampling',
    icon: ScanLine,
    body: 'Periodic sampling between operations catches drift early, so a problem never reaches a full production run.',
  },
  {
    id: 'outgoing',
    title: 'Outgoing AQL inspection',
    icon: Ruler,
    body: 'AQL 0.65 Level II sampling with measured critical dimensions gates every shipment against your tolerances.',
  },
  {
    id: 'coc',
    title: 'Certificate of Conformance',
    icon: FileCheck2,
    body: 'Each delivery carries a CoC built from real measurement data, with traceability from raw resin to your receiving dock.',
  },
];

export const QUALITY_METHODS = [
  'APQP',
  'PPAP',
  'MSA & SPC',
  'Traceable lot control',
  '8D corrective action',
] as const;
