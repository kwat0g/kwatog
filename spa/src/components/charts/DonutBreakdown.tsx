import { PieChart, Pie, Cell, ResponsiveContainer, Tooltip } from 'recharts';
import { chartColor } from '@/lib/chartPalette';
import { ChartFigure } from './ChartFigure';

interface DonutEntry {
  name: string;
  value: number;
  /**
   * Defaults to the categorical palette by slice position. Pass a colour only
   * when the slice MEANS something (present/late/absent) — a plain category
   * must not borrow a semantic hue, which is how "outgoing inspections" used
   * to render in danger red.
   */
  color?: string;
}

interface DonutBreakdownProps {
 label: string;
 data: DonutEntry[];
 height?: number;
 innerRadius?: number;
 centerLabel?: string;
 centerValue?: string;
}

export function DonutBreakdown({
 label,
 data,
 height = 180,
 innerRadius = 50,
 centerLabel,
 centerValue,
}: DonutBreakdownProps) {
 return (
 <ChartFigure label={label} columns={['Category', 'Value']} rows={data.map((entry) => [entry.name, entry.value])}>
 <div className="relative">
 <ResponsiveContainer width="100%" height={height}>
 <PieChart>
 <Pie
 data={data}
 dataKey="value"
 nameKey="name"
 cx="50%"
 cy="50%"
 innerRadius={innerRadius}
 outerRadius={innerRadius + 25}
 paddingAngle={2}
        isAnimationActive={false}
        >
          {data.map((entry, i) => (
            <Cell key={i} fill={entry.color ?? chartColor(i)} />
          ))}
 </Pie>
 <Tooltip
 contentStyle={{
 background: 'var(--bg-surface)',
 border: '1px solid var(--border-default)',
 borderRadius: 6,
 fontSize: 12,
 }}
 />
 </PieChart>
 </ResponsiveContainer>
 {centerLabel && (
 <div className="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
 <span className="text-2xl font-medium font-mono tabular-nums">{centerValue}</span>
 <span className="text-xs text-muted">{centerLabel}</span>
 </div>
 )}
 <div className="flex flex-wrap gap-3 justify-center mt-2">
 {data.map((entry, index) => (
          <div key={`${entry.name}-${index}`} className="flex items-center gap-1.5 text-xs">
            <span
              className="w-2.5 h-2.5 rounded-full shrink-0"
              style={{ backgroundColor: entry.color ?? chartColor(index) }}
            />
 <span className="text-muted">{entry.name}</span>
 <span className="font-mono tabular-nums">{entry.value}</span>
 </div>
 ))}
 </div>
 </div>
 </ChartFigure>
 );
}
