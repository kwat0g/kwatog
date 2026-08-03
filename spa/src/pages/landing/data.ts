import {
  Car, Stethoscope, Layers, Hammer, PackageCheck, Boxes, Thermometer,
  Ruler, ScanLine, FileCheck2, type LucideIcon,
} from 'lucide-react';

export interface Capability { id: string; title: string; icon: LucideIcon; blurb: string; points: string[]; tag: string }
export interface ProcessStep { index: string; title: string; icon: LucideIcon; body: string }
export interface StatItem { id: string; value: number; prefix?: string; suffix?: string; decimals?: number; label: string }
export interface QualityPillar { id: string; title: string; icon: LucideIcon; body: string }

export const CAPABILITY_ICONS: Record<string, LucideIcon> = { automotive: Car, precision: Stethoscope, assembly: Layers, tooling: Hammer };
export const PROCESS_ICONS: Record<string, LucideIcon> = { boxes: Boxes, hammer: Hammer, layers: Layers, thermometer: Thermometer, ruler: Ruler, 'file-check': FileCheck2 };
export const QUALITY_PILLAR_ICONS: Record<string, LucideIcon> = { 'package-check': PackageCheck, 'scan-line': ScanLine, ruler: Ruler, 'file-check': FileCheck2 };
