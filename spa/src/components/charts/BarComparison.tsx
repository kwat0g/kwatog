import { BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid, Legend } from 'recharts';

interface BarDef {
  dataKey: string;
  color: string;
  label: string;
}

interface BarComparisonProps {
  data: Array<Record<string, any>>;
  bars: BarDef[];
  xKey?: string;
  height?: number;
  stacked?: boolean;
  formatValue?: (v: number) => string;
}

export function BarComparison({
  data,
  bars,
  xKey = 'label',
  height = 200,
  stacked,
  formatValue,
}: BarComparisonProps) {
  return (
    <ResponsiveContainer width="100%" height={height}>
      <BarChart data={data} margin={{ top: 4, right: 4, bottom: 0, left: 0 }}>
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
          tickFormatter={formatValue}
        />
        <Tooltip
          contentStyle={{
            background: 'var(--color-surface)',
            border: '1px solid var(--color-border)',
            borderRadius: 6,
            fontSize: 12,
          }}
          formatter={formatValue ? (v: number) => [formatValue(v), ''] : undefined}
        />
        <Legend wrapperStyle={{ fontSize: 11 }} />
        {bars.map((bar) => (
          <Bar
            key={bar.dataKey}
            dataKey={bar.dataKey}
            fill={bar.color}
            name={bar.label}
            stackId={stacked ? 'stack' : undefined}
            radius={[2, 2, 0, 0]}
          />
        ))}
      </BarChart>
    </ResponsiveContainer>
  );
}
