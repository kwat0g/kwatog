import React from 'react';
import { cn } from '@/lib/cn';

export interface ActivityItem {
 id: string | number;
 dot: 'success' | 'info' | 'warning' | 'danger' | 'neutral';
 text: React.ReactNode;
 time: string;
}

interface ActivityStreamProps {
 items: ActivityItem[];
 className?: string;
}

const dotColorMap = {
 success: 'bg-success-bg',
 info: 'bg-info-bg',
 warning: 'bg-warning-bg',
 danger: 'bg-danger-bg',
 neutral: 'bg-muted',
};

export function ActivityStream({ items, className }: ActivityStreamProps) {
 return (
 <div className={cn("space-y-0", className)}>
 {items.map((item) => (
 <div key={item.id} className="flex items-start gap-3 py-1.5">
 <div className="relative mt-1.5 shrink-0">
 <div className={cn("w-1.5 h-1.5 rounded-full", dotColorMap[item.dot])} />
 </div>
 <div>
 <div className="text-[11px] text-primary" dangerouslySetInnerHTML={typeof item.text === 'string' ? { __html: item.text } : undefined}>
 {typeof item.text !== 'string' ? item.text : undefined}
 </div>
 <div className="text-[10px] font-mono text-muted mt-0.5">{item.time}</div>
 </div>
 </div>
 ))}
 </div>
 );
}
