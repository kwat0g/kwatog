import { ClipboardList, CheckCircle2 } from 'lucide-react';
import { TouchShell, type TouchTab } from '@/components/layout/TouchShell';

/** Factory Floor PWA — operator-scoped, no sidebar. Chrome lives in TouchShell. */
const tabs: readonly TouchTab[] = [
  { to: '/factory', label: 'Work Orders', icon: ClipboardList, exact: true },
  { to: '/factory/qc', label: 'QC Check', icon: CheckCircle2, exact: true },
];

export default function FactoryFloorLayout() {
  return <TouchShell eyebrow="Factory Floor" fallbackName="Operator" tabs={tabs} />;
}
