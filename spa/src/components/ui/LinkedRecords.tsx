import { cn } from '@/lib/cn';
import type { ChipVariant } from '@/components/ui/Chip';
import { Chip } from './Chip';

export interface LinkedRecordItem {
 id: string;
 meta?: string;
 chip?: { variant: ChipVariant; text: string };
 onClick?: () => void;
}

export interface LinkedRecordGroup {
 label: string;
 items: LinkedRecordItem[];
}

interface LinkedRecordsProps {
 groups: LinkedRecordGroup[];
 className?: string;
}

export function LinkedRecords({ groups, className }: LinkedRecordsProps) {
 return (
 <div className={cn("bg-surface h-full border-l border-default p-4", className)}>
 <div className="space-y-3">
 {groups.map((group, idx) => (
 <div key={idx}>
 <div className="text-[10px] uppercase tracking-wider text-muted font-medium mb-2">
 {group.label}
 </div>
 <div className="space-y-2">
 {group.items.map((item, itemIdx) => (
 <div key={itemIdx} className="flex flex-col">
 <div className="flex items-center gap-2">
 <button 
 onClick={item.onClick}
 className="text-[12px] font-mono text-primary hover:underline"
 >
 {item.id}
 </button>
 {item.chip && (
 <Chip variant={item.chip.variant}>{item.chip.text}</Chip>
 )}
 </div>
 {item.meta && (
 <div className="text-[11px] text-muted mt-0.5">{item.meta}</div>
 )}
 </div>
 ))}
 </div>
 </div>
 ))}
 </div>
 </div>
 );
}
