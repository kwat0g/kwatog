import { Outlet, NavLink } from 'react-router-dom';
import { LuCalendar, LuFileText, LuLayoutDashboard, LuReceipt, LuUser } from '@/lib/icons';
import { useFeature } from '@/hooks/useFeature';
import { usePermission } from '@/hooks/usePermission';
import { focusRingInset } from '@/lib/focus';
import { cn } from '@/lib/cn';

/**
 * M024 mobile shell. The generic ERP shell remains in place for desktop and
 * back-office routes, while self-service gets the persistent navigation
 * promised by the employee mobile contract.
 */
export default function SelfServiceLayout() {
 const attendance = useFeature('attendance');
 const leave = useFeature('leave');
 const payroll = useFeature('payroll');
 const { can } = usePermission();

 const items = [
 { to: '/self-service', label: 'Home', icon: LuLayoutDashboard, end: true },
 attendance && { to: '/self-service/dtr', label: 'DTR', icon: LuCalendar },
 leave && { to: '/self-service/leave', label: 'Leave', icon: LuFileText },
 payroll && can('payroll.view') && { to: '/self-service/payslips', label: 'Payslip', icon: LuReceipt },
 { to: '/self-service/me', label: 'Me', icon: LuUser },
 ].filter(Boolean) as Array<{
 to: string;
 label: string;
 icon: typeof LuLayoutDashboard;
 end?: boolean;
 }>;

 return (
 <>
 <div className="pb-20 md:pb-0">
 <Outlet />
 </div>
 <nav
 aria-label="Self-service navigation"
 className="fixed inset-x-0 bottom-0 z-30 border-t border-default bg-canvas safe-area-pb md:hidden"
 >
 <div className="mx-auto flex max-w-xl items-stretch">
 {items.map(({ to, label, icon: Icon, end }) => (
 <NavLink
 key={to}
 to={to}
 end={end}
 aria-label={label}
 className={({ isActive }) => cn(
 'flex min-h-[56px] flex-1 flex-col items-center justify-center gap-0.5 px-1 py-2 text-2xs font-medium transition-colors duration-fast',
 focusRingInset,
 isActive ? 'text-accent' : 'text-muted hover:text-secondary',
 )}
 >
 <Icon size={20} aria-hidden />
 <span>{label}</span>
 </NavLink>
 ))}
 </div>
 </nav>
 </>
 );
}
