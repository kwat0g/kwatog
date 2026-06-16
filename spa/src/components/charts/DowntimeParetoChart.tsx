import {
  ComposedChart,
  Bar,
  Line,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
} from 'recharts';

export interface ParetoEntry {
  category: string;
  minutes: number;
  cumulative_pct: number;
}

interface Props {
  data: ParetoEntry[];
  height?: number;
  valueLabel?: string;
}

function formatMinutes(m: number): string {
  if (m >= 60) return `${Math.floor(m / 60)}h ${m % 60}m`;
  return `${m}m`;
}

export function DowntimeParetoChart({ data, height = 260, valueLabel }: Props) {
  if (data.length === 0) return null;

  const label = (cat: string) =>
    cat.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

  return (
    <ResponsiveContainer width="100%" height={height}>
      <ComposedChart data={data} margin={{ top: 8, right: 40, left: 0, bottom: 40 }}>
        <CartesianGrid strokeDasharray="3 3" stroke="var(--border-subtle, #e5e7eb)" vertical={false} />
        <XAxis
          dataKey="category"
          tick={{ fontSize: 11, fill: 'var(--text-muted, #6b7280)' }}
          tickFormatter={label}
          angle={-30}
          textAnchor="end"
          interval={0}
          height={56}
        />
        <YAxis
          yAxisId="left"
          tick={{ fontSize: 11, fill: 'var(--text-muted, #6b7280)' }}
          tickFormatter={valueLabel ? String : formatMinutes}
          width={56}
        />
        <YAxis
          yAxisId="right"
          orientation="right"
          unit="%"
          domain={[0, 100]}
          tick={{ fontSize: 11, fill: 'var(--text-muted, #6b7280)' }}
          width={40}
        />
        <Tooltip
          contentStyle={{
            background: 'var(--bg-elevated, #fff)',
            border: '1px solid var(--border-default, #e5e7eb)',
            borderRadius: 6,
            fontSize: 12,
          }}
          formatter={(value: number, name: string) =>
            name === 'Downtime' ? [formatMinutes(value), 'Downtime'] : [`${value.toFixed(1)}%`, 'Cumulative']
          }
          labelFormatter={label}
        />
        <Bar
          yAxisId="left"
          dataKey="minutes"
          name="Downtime"
          fill="var(--color-danger, #ef4444)"
          radius={[3, 3, 0, 0]}
          maxBarSize={48}
        />
        <Line
          yAxisId="right"
          type="monotone"
          dataKey="cumulative_pct"
          name="Cumulative %"
          stroke="var(--color-warning, #f59e0b)"
          dot={{ r: 3 }}
          strokeWidth={2}
          activeDot={{ r: 4 }}
        />
      </ComposedChart>
    </ResponsiveContainer>
  );
}

/** Derive sorted Pareto data from category_breakdown array */
// eslint-disable-next-line react-refresh/only-export-components
export function buildParetoData(
  breakdown: Array<{ category: string; minutes: number }>,
): ParetoEntry[] {
  const sorted = [...breakdown].sort((a, b) => b.minutes - a.minutes);
  const total = sorted.reduce((s, r) => s + r.minutes, 0);
  let cum = 0;
  return sorted.map((r) => {
    cum += r.minutes;
    return {
      category: r.category,
      minutes: r.minutes,
      cumulative_pct: total > 0 ? (cum / total) * 100 : 0,
    };
  });
}
