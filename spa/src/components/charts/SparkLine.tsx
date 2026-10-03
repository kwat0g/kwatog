import { LineChart, Line } from 'recharts';

interface SparkLineProps {
 data: number[];
 color?: string;
 height?: number;
 width?: number;
}

export function SparkLine({
 data,
 color = 'var(--accent)',
 height = 24,
 width = 80,
}: SparkLineProps) {
 const chartData = data.map((v, i) => ({ i, v }));
 return (
 <LineChart data={chartData} width={width} height={height}>
 <Line type="monotone" dataKey="v" stroke={color} strokeWidth={1.5} dot={false} />
 </LineChart>
 );
}
