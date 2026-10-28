import { AreaChart, Area, XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid, Legend } from 'recharts';
import { chartColor } from '@/lib/chartPalette';
import { ChartFigure } from './ChartFigure';

interface AreaTrendProps {
 label: string;
 data: Array<Record<string, unknown>>;
 dataKey?: string;
 forecastKey?: string;
 xKey?: string;
 height?: number;
 unit?: string;
 formatValue?: (v: number) => string;
}

export function AreaTrend({
 label,
 data,
 dataKey = 'value',
 forecastKey,
 xKey = 'period',
 height = 200,
 unit = '',
 formatValue,
}: AreaTrendProps) {
 const fmt = formatValue ?? ((v: number) => `${v.toLocaleString()}${unit}`);

 const columns = [xKey, dataKey, ...(forecastKey ? [forecastKey] : [])];
 const rows = data.map((entry) => columns.map((key) => {
  if (key === xKey) return String(entry[key] ?? '');
  if (entry[key] == null) return '—';
  const value = Number(entry[key]);
  return Number.isFinite(value) ? fmt(value) : '—';
 }));

 return (
 <ChartFigure label={label} columns={columns} rows={rows}>
 <ResponsiveContainer width="100%" height={height}>
 <AreaChart data={data} margin={{ top: 4, right: 4, bottom: 0, left: 0 }}>
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
 tickFormatter={(v) => fmt(v)}
 />
 <Tooltip
 contentStyle={{
 background: 'var(--bg-surface)',
 border: '1px solid var(--border-default)',
 borderRadius: 6,
 fontSize: 12,
 }}
 formatter={(v: number) => [fmt(v), '']}
 />
 {forecastKey && <Legend wrapperStyle={{ fontSize: 11, fontVariantNumeric: 'tabular-nums' }} />}
 <Area
 type="monotone"
 dataKey={dataKey}
 name={dataKey}
        stroke={chartColor(0)}
        fill={chartColor(0)}
        fillOpacity={0.1}
        strokeWidth={2}
        isAnimationActive={false}
 />
 {forecastKey && (
 <Area
 type="monotone"
 dataKey={forecastKey}
 name={forecastKey}
        stroke={chartColor(1)}
        fill={chartColor(1)}
 fillOpacity={0.05}
 strokeWidth={2}
 strokeDasharray="5 5"
        isAnimationActive={false}
 />
 )}
 </AreaChart>
 </ResponsiveContainer>
 </ChartFigure>
 );
}
