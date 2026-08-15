import { LuWrench, LuClipboardList } from '@/lib/icons';
import { TouchShell, type TouchTab } from '@/components/layout/TouchShell';

/** Maintenance Tech PWA — tech-scoped, no sidebar. Chrome lives in TouchShell. */
const tabs: readonly TouchTab[] = [
 { to: '/maintenance/mobile', label: 'Work Orders', icon: LuClipboardList, exact: true },
];

export default function MaintenanceMobileLayout() {
 return <TouchShell eyebrow="Maintenance" eyebrowIcon={LuWrench} fallbackName="Technician" tabs={tabs} />;
}
