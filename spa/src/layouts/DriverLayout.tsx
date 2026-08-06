import { Truck } from 'lucide-react';
import { TouchShell } from '@/components/layout/TouchShell';

/**
 * T2.5 — Driver PWA. One screen stack (list → detail → photo) rather than
 * parallel sections, so this shell has no tab bar. Chrome lives in TouchShell.
 */
export default function DriverLayout() {
 return <TouchShell eyebrow="Driver" eyebrowIcon={Truck} fallbackName="Driver" />;
}
