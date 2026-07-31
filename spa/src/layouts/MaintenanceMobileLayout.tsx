import { Wrench, ClipboardList, Thermometer } from 'lucide-react';
import { TouchShell, type TouchTab } from '@/components/layout/TouchShell';

/** Maintenance Tech PWA — tech-scoped, no sidebar. Chrome lives in TouchShell. */
const tabs: readonly TouchTab[] = [
  { to: '/maintenance/mobile', label: 'Work Orders', icon: ClipboardList, exact: true },
  { to: '/maintenance/mobile/condition-reading', label: 'Readings', icon: Thermometer, exact: true },
];

export default function MaintenanceMobileLayout() {
  return <TouchShell eyebrow="Maintenance" eyebrowIcon={Wrench} fallbackName="Technician" tabs={tabs} />;
}
