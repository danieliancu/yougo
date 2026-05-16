import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

export type ActivityChartRow = {
  dateKey: string;
  label: string;
  phoneDone: number;
  chatWhatsDone: number;
  websiteChatDone: number;
  inProgress: number;
  abandoned: number;
};

type Props = {
  data: ActivityChartRow[];
  labels: Record<string, string>;
  range: 'week' | 'month';
  title: string;
};

const series = [
  { key: 'phoneDone', color: 'var(--chart-phone)' },
  { key: 'chatWhatsDone', color: 'var(--chart-whatsapp)' },
  { key: 'websiteChatDone', color: 'var(--chart-website-chat)' },
  { key: 'inProgress', color: 'var(--chart-progress)' },
  { key: 'abandoned', color: 'var(--chart-abandoned)' },
] as const;

export default function ActivityChart({ data, labels, range, title }: Props) {
  return (
    <div className="h-72" role="img" aria-label={title}>
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={data}>
          <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--app-border)" />
          <XAxis dataKey="label" tickLine={false} axisLine={false} interval={range === 'month' ? 2 : 0} />
          <YAxis tickLine={false} axisLine={false} allowDecimals={false} />
          <Tooltip formatter={(value, name) => [value, labels[String(name)] ?? name]} />
          {series.map((item) => (
            <Bar key={item.key} dataKey={item.key} stackId="activity" fill={item.color} radius={[4, 4, 0, 0]} />
          ))}
        </BarChart>
      </ResponsiveContainer>
      <table className="sr-only">
        <caption>{title}</caption>
        <thead>
          <tr>
            <th scope="col">Date</th>
            {series.map((item) => <th key={item.key} scope="col">{labels[item.key]}</th>)}
          </tr>
        </thead>
        <tbody>
          {data.map((row) => (
            <tr key={row.dateKey}>
              <th scope="row">{row.label}</th>
              {series.map((item) => <td key={item.key}>{row[item.key]}</td>)}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
