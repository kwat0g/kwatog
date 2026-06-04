import { AreaChart, Area, XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid } from 'recharts';

interface AreaTrendProps {
  data: Array<Record<string, unknown>>;
  dataKey?: string;
  forecastKey?: string;
  xKey?: string;
  color?: string;
  height?: number;
  unit?: string;
  formatValue?: (v: number) => string;
}

export function AreaTrend({
  data,
  dataKey = 'value',
  forecastKey,
  xKey = 'period',
  color = 'var(--color-accent)',
  height = 200,
  unit = '',
  formatValue,
}: AreaTrendProps) {
  const fmt = formatValue ?? ((v: number) => `${v.toLocaleString()}${unit}`);

  return (
    <ResponsiveContainer width="100%" height={height}>
      <AreaChart data={data} margin={{ top: 4, right: 4, bottom: 0, left: 0 }}>
        <CartesianGrid strokeDasharray="3 3" stroke="var(--color-border)" />
        <XAxis
          dataKey={xKey}
          tick={{ fontSize: 11, fill: 'var(--color-muted)' }}
          axisLine={false}
          tickLine={false}
        />
        <YAxis
          tick={{ fontSize: 11, fill: 'var(--color-muted)' }}
          axisLine={false}
          tickLine={false}
          tickFormatter={(v) => fmt(v)}
        />
        <Tooltip
          contentStyle={{
            background: 'var(--color-surface)',
            border: '1px solid var(--color-border)',
            borderRadius: 6,
            fontSize: 12,
          }}
          formatter={(v: number) => [fmt(v), '']}
        />
        <Area
          type="monotone"
          dataKey={dataKey}
          stroke={color}
          fill={color}
          fillOpacity={0.1}
          strokeWidth={2}
        />
        {forecastKey && (
          <Area
            type="monotone"
            dataKey={forecastKey}
            stroke={color}
            fill={color}
            fillOpacity={0.05}
            strokeWidth={2}
            strokeDasharray="5 5"
          />
        )}
      </AreaChart>
    </ResponsiveContainer>
  );
}
