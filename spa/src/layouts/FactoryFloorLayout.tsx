import { LuClipboardList, LuCircleCheck } from '@/lib/icons';
import { TouchShell, type TouchTab } from '@/components/layout/TouchShell';

/** Factory Floor PWA — operator-scoped, no sidebar. Chrome lives in TouchShell. */
const tabs: readonly TouchTab[] = [
 { to: '/factory', label: 'Work Orders', icon: LuClipboardList, exact: true },
 { to: '/factory/qc', label: 'QC Check', icon: LuCircleCheck, exact: true },
];

export default function FactoryFloorLayout() {
 return <TouchShell eyebrow="Factory Floor" fallbackName="Operator" tabs={tabs} />;
}
