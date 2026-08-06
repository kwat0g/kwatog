import { cn } from '@/lib/cn';

export interface StageData {
 label: string;
 count: number;
 percent: number;
 color: 'success' | 'info' | 'warning' | 'danger' | 'neutral';
}

interface StageBreakdownProps {
 title: string;
 stages: StageData[];
 className?: string;
}

const colorMap = {
 success: 'bg-success',
 info: 'bg-info',
 warning: 'bg-warning',
 danger: 'bg-danger',
 neutral: 'bg-muted',
};

export function StageBreakdown({ title, stages, className }: StageBreakdownProps) {
 return (
 <div className={cn("bg-canvas border border-default rounded-md overflow-hidden", className)}>
 <div className="flex items-center justify-between px-4 py-3 border-b border-default">
 <h3 className="text-sm font-medium">{title}</h3>
 </div>
 <div className="p-4 space-y-[10px]">
 {stages.map((stage, idx) => (
 <div key={idx}>
 <div className="flex justify-between items-center mb-1">
 <span className="text-[11px] text-primary">{stage.label}</span>
 <span className="text-[11px] font-mono tabular-nums text-primary font-medium">{stage.count}</span>
 </div>
 <div className="w-full h-1 bg-subtle rounded-full overflow-hidden">
 <div 
 className={cn("h-full", colorMap[stage.color])} 
 style={{ width: `${Math.min(100, Math.max(0, stage.percent))}%` }} 
 />
 </div>
 </div>
 ))}
 </div>
 </div>
 );
}
