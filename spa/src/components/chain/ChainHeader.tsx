import { Link } from 'react-router-dom';
import { type ChainStep } from '@/types/chain';
import { cn } from '@/lib/cn';
import { focusRing } from '@/lib/focus';
import { Tooltip } from '@/components/ui/Tooltip';

interface ChainHeaderProps {
 steps: ChainStep[];
 className?: string;
}

const dotClass = (step: ChainStep) => {
 if (step.is_overdue) return 'bg-danger-bg border-danger animate-pulse ring-2 ring-danger/30';
 if (step.state === 'done') return 'bg-success-bg border-success';
 if (step.state === 'rejected') return 'bg-danger-bg border-danger';
 if (step.state === 'skipped') return 'bg-muted border-default';
 if (step.state === 'active') return 'bg-accent border-accent';
 return 'bg-canvas border-strong';
};

const lineClass = (left: ChainStep) =>
 left.state === 'done' ? 'bg-success-bg' : left.state === 'rejected' ? 'bg-danger' : 'bg-strong';

export function ChainHeader({ steps, className }: ChainHeaderProps) {
 if (steps.length === 0) return null;
 return (
 <div className={cn('w-full overflow-x-auto', className)}>
 <div className="flex items-start gap-0 min-w-max">
 {steps.map((step, i) => {
 const isInteractive = Boolean(step.href || step.onClick);

 const stepNode = (
 <div
 className={cn(
 'flex flex-col items-center min-w-[88px] px-1 py-0.5 rounded-md transition-colors duration-fast relative',
 isInteractive && 'hover:bg-subtle cursor-pointer',
 isInteractive && focusRing,
 )}
 >
 <span
 className={cn(
 'h-[9px] w-[9px] rounded-full border block transition-transform duration-fast',
 dotClass(step),
 isInteractive && 'group-hover:scale-125',
 )}
 aria-hidden
 />
 <div className="mt-2 text-center">
 <div
 className={cn(
 'text-xs',
 step.is_overdue
 ? 'text-danger-fg font-medium'
 : step.state === 'rejected'
 ? 'text-danger-fg font-medium'
 : step.state === 'skipped'
 ? 'text-muted'
 : step.state === 'pending'
 ? 'text-subtle'
 : 'text-primary',
 step.state === 'active' && 'font-medium',
 isInteractive && 'hover:underline underline-offset-2',
 )}
 >
 {step.label}
 </div>
 {step.sla_label && (
 <div className="text-[10px] leading-tight font-mono text-danger-fg bg-danger-bg px-1 rounded mt-0.5 inline-block">
 {step.sla_label}
 </div>
 )}
 {step.date && !step.sla_label && (
 <div className="text-2xs font-mono tabular-nums text-muted mt-0.5">{step.date}</div>
 )}
 </div>
 </div>
 );

 let content = stepNode;
 if (step.href) {
 content = (
 <Link to={step.href} className="group focus:outline-none">
 {stepNode}
 </Link>
 );
 } else if (step.onClick) {
 content = (
 <button
 type="button"
 onClick={() => step.onClick?.(step)}
 className="group focus:outline-none text-left"
 >
 {stepNode}
 </button>
 );
 }

 if (step.description) {
 content = <Tooltip content={step.description}>{content}</Tooltip>;
 }

 return (
 <div key={step.key} className="flex items-start">
 {content}
 {i < steps.length - 1 && (
 <div
 className={cn('h-[1px] self-start mt-[4px] w-10', lineClass(step))}
 aria-hidden
 />
 )}
 </div>
 );
 })}
 </div>
 </div>
 );
}
