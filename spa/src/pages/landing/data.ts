import { IconType } from '@/lib/icons';
import {
  LuCar, LuLayers, LuHammer, LuPackageCheck, LuBoxes, LuThermometer,
  LuRuler, LuScanLine, LuFileCheck2,
} from '@/lib/icons';

export interface Capability { id: string; title: string; icon: IconType; blurb: string; points: string[]; tag: string }
export interface ProcessStep { index: string; title: string; icon: IconType; body: string }
export interface StatItem { id: string; value: number; prefix?: string; suffix?: string; decimals?: number; label: string }
export interface QualityPillar { id: string; title: string; icon: IconType; body: string }

export const CAPABILITY_ICONS: Record<string, IconType> = { automotive: LuCar, precision: LuRuler, assembly: LuBoxes, tooling: LuHammer };
export const PROCESS_ICONS: Record<string, IconType> = { boxes: LuBoxes, hammer: LuHammer, layers: LuLayers, thermometer: LuThermometer, ruler: LuRuler, 'file-check': LuFileCheck2 };
export const QUALITY_PILLAR_ICONS: Record<string, IconType> = { 'package-check': LuPackageCheck, 'scan-line': LuScanLine, ruler: LuRuler, 'file-check': LuFileCheck2 };
