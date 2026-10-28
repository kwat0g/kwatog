import { BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid, Legend } from 'recharts';
import { chartColor } from '@/lib/chartPalette';
import { ChartFigure } from './ChartFigure';

interface BarDef {
  dataKey: string;
  label: string;
}

interface BarComparisonProps {
 label: string;
 data: Array<Record<string, unknown>>;
 bars: BarDef[];
 xKey?: string;
 height?: number;
 stacked?: boolean;
 formatValue?: (v: number) => string;
}

export function BarComparison({
 label,
 data,
 bars,
 xKey = 'label',
 height = 200,
 stacked,
 formatValue,
}: BarComparisonProps) {
 const columns = [xKey, ...bars.map((bar) => bar.label)];
 const rows = data.map((entry) => [
  String(entry[xKey] ?? ''),
  ...bars.map((bar) => {
   if (entry[bar.dataKey] == null) return '—';
   const value = Number(entry[bar.dataKey]);
   return Number.isFinite(value) ? formatValue?.(value) ?? String(value) : '—';
  }),
 ]);

 return (
 <ChartFigure label={label} columns={columns} rows={rows}>
 <ResponsiveContainer width="100%" height={height}>
 <BarChart data={data} margin={{ top: 4, right: 4, bottom: 0, left: 0 }}>
 <CartesianGrid strokeDasharray="3 3" stroke="var(--border-subtle)" />
 <XAxis
 dataKey={xKey}
 tick={{ fontSize: 11, fill: 'var(--text-muted)', className: 'tabular-nums' }}
 axisLine={false}
 tickLine={false}
 />
 <YAxis
 tick={{ fontSize: 11, fill: 'var(--text-muted)', className: 'tabular-nums' }}
 axisLine={false}
 tickLine={false}
 tickFormatter={formatValue}
 />
 <Tooltip
 contentStyle={{
 background: 'var(--bg-surface)',
 border: '1px solid var(--border-default)',
 borderRadius: 6,
 fontSize: 12,
 }}
 formatter={formatValue ? (v: number) => [formatValue(v), ''] : undefined}
 />
 <Legend wrapperStyle={{ fontSize: 11, fontVariantNumeric: 'tabular-nums' }} />
 {bars.map((bar, index) => (
      <Bar
        key={bar.dataKey}
        dataKey={bar.dataKey}
        fill={chartColor(index)}
 name={bar.label}
 stackId={stacked ? 'stack' : undefined}
 radius={[2, 2, 0, 0]}
        isAnimationActive={false}
 />
 ))}
 </BarChart>
 </ResponsiveContainer>
 </ChartFigure>
 );
}
