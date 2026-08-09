import { cn } from '@/lib/cn';

export interface ChainStep {
 key: string;
 label: string;
 date?: string;
 state: 'done' | 'active' | 'pending';
}

interface ChainHeaderProps {
 steps: ChainStep[];
 className?: string;
}

export function ChainHeader({ steps, className }: ChainHeaderProps) {
 return (
 <div className={cn("flex w-full items-start", className)}>
 {steps.map((step, idx) => {
 const isLast = idx === steps.length - 1;
 
 let dotClass = 'bg-muted ring-1 ring-muted ring-offset-1 ring-offset-canvas';
 let lineClass = 'bg-border-default';
 let labelClass = 'text-subtle';

 if (step.state === 'done') {
 dotClass = 'bg-success-bg ring-1 ring-success ring-offset-1 ring-offset-canvas';
 lineClass = 'bg-success-bg';
 labelClass = 'text-primary font-medium';
 } else if (step.state === 'active') {
 dotClass = 'bg-accent ring-1 ring-accent ring-offset-1 ring-offset-canvas';
 lineClass = 'bg-border-default';
 labelClass = 'text-primary font-medium';
 }

 return (
 <div key={step.key} className={cn("relative flex flex-col", isLast ? "" : "flex-1")}>
 <div className="flex items-center w-full mb-2">
 <div className={cn("w-[9px] h-[9px] rounded-full shrink-0 relative z-10", dotClass)} />
 {!isLast && (
 <div className={cn("h-[1px] w-full mx-2 relative z-0", lineClass)} />
 )}
 </div>
 
 <div className={cn("text-[11px] leading-tight absolute top-5 whitespace-nowrap", labelClass, isLast ? "right-0 text-right" : "left-0")}>
 {step.label}
 {step.date && (
 <div className="text-[10px] font-mono text-muted mt-0.5">
 {step.date}
 </div>
 )}
 </div>
 </div>
 );
 })}
 </div>
 );
}
