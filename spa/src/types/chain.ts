export type ChainStepState = 'done' | 'active' | 'pending';

export interface ChainStep {
  key: string;
  label: string;
  date?: string;
  state: ChainStepState;
}

export type StageColor = 'success' | 'info' | 'warning' | 'danger' | 'neutral';

export interface StageRow {
  label: string;
  count: number;
  /** 0–100; controls the fill width of the progress bar. */
  percent: number;
  color?: StageColor;
}

export type LinkedDot = 'success' | 'info' | 'warning' | 'danger' | 'neutral';

export interface LinkedItem {
  id: string;
  href?: string;
  meta?: string;
  chip?: { variant: 'success' | 'warning' | 'danger' | 'info' | 'neutral' | 'purple'; text: string };
}

export interface LinkedGroup {
  label: string;
  items: LinkedItem[];
}

import type { ReactNode } from 'react';

export interface ActivityItem {
  dot: LinkedDot;
  text: ReactNode;
  time: string;
}
