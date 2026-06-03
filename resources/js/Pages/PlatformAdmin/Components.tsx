import { Link } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, Building2, Clipboard, MessageCircle, Phone, Shield, Users } from 'lucide-react';
import { ReactNode } from 'react';

export type AdminPage = 'overview' | 'businesses' | 'business_detail' | 'whatsapp_onboarding' | 'usage' | 'issues';

const navItems = [
  { href: '/platform-admin', key: 'overview', label: 'Overview', icon: Shield },
  { href: '/platform-admin/businesses', key: 'businesses', label: 'Businesses', icon: Building2 },
  { href: '/platform-admin/whatsapp-onboarding', key: 'whatsapp_onboarding', label: 'WhatsApp Onboarding', icon: MessageCircle },
  { href: '/platform-admin/usage', key: 'usage', label: 'Usage', icon: Users },
  { href: '/platform-admin/issues', key: 'issues', label: 'Issues', icon: AlertTriangle },
];

export function PlatformAdminLayout({ page, children }: { page: AdminPage; children: ReactNode }) {
  return (
    <div className="min-h-screen bg-slate-950 text-slate-100">
      <aside className="fixed inset-y-0 left-0 hidden w-72 border-r border-slate-800 bg-slate-950 px-5 py-6 lg:block">
        <div className="mb-8">
          <p className="text-xs font-bold uppercase tracking-wide text-indigo-300">YouGo</p>
          <h1 className="mt-2 text-2xl font-black tracking-tight text-slate-50">Platform Admin</h1>
          <p className="mt-2 text-sm text-slate-400">Internal read-only operations console.</p>
        </div>
        <nav className="space-y-1">
          {navItems.map((item) => {
            const Icon = item.icon;
            const active = item.key === page || (page === 'business_detail' && item.key === 'businesses');

            return (
              <Link
                key={item.key}
                href={item.href}
                className={`flex items-center gap-3 rounded-md px-3 py-2.5 text-sm font-bold transition ${active ? 'bg-indigo-500 text-white' : 'text-slate-300 hover:bg-slate-900 hover:text-slate-50'}`}
              >
                <Icon className="h-4 w-4" />
                {item.label}
              </Link>
            );
          })}
        </nav>
        <Link href="/dashboard" className="mt-8 flex items-center gap-2 rounded-md px-3 py-2 text-sm font-bold text-slate-400 transition hover:bg-slate-900 hover:text-slate-50">
          <ArrowLeft className="h-4 w-4" />
          Back to Business Dashboard
        </Link>
      </aside>

      <main className="lg:pl-72">
        <div className="border-b border-slate-800 bg-slate-950 px-4 py-4 lg:hidden">
          <h1 className="text-xl font-black">Platform Admin</h1>
          <div className="mt-3 flex gap-2 overflow-x-auto pb-1">
            {navItems.map((item) => (
              <Link key={item.key} href={item.href} className="shrink-0 rounded-md bg-slate-900 px-3 py-2 text-xs font-bold text-slate-200">
                {item.label}
              </Link>
            ))}
          </div>
        </div>
        <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">{children}</div>
      </main>
    </div>
  );
}

export function PageHeader({ title, subtitle }: { title: string; subtitle: string }) {
  return (
    <header className="mb-7">
      <p className="text-xs font-black uppercase tracking-wide text-indigo-300">Internal</p>
      <h2 className="mt-2 text-3xl font-black tracking-tight text-slate-50">{title}</h2>
      <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-400">{subtitle}</p>
    </header>
  );
}

export function Panel({ title, children, className = '' }: { title: string; children: ReactNode; className?: string }) {
  return (
    <section className={`rounded-md border border-slate-800 bg-slate-900/55 p-5 shadow-sm shadow-slate-950/30 ${className}`}>
      <h2 className="mb-4 text-base font-black text-slate-50">{title}</h2>
      {children}
    </section>
  );
}

export function Metric({ label, value }: { label: string; value: unknown }) {
  return (
    <div className="rounded-md border border-slate-800 bg-slate-900 p-4">
      <p className="text-xs font-bold uppercase tracking-wide text-slate-500">{label}</p>
      <p className="mt-2 text-2xl font-black text-slate-50">{String(value ?? 0)}</p>
    </div>
  );
}

export function BusinessTable({ items }: { items: Record<string, any>[] }) {
  if (!items.length) return <Empty text="No businesses found." />;

  return (
    <div className="overflow-x-auto">
      <table className="min-w-full divide-y divide-slate-800 text-sm">
        <thead className="text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th className="py-3 pr-4">Business</th>
            <th className="py-3 pr-4">Owner</th>
            <th className="py-3 pr-4">Plan</th>
            <th className="py-3 pr-4">Subscription</th>
            <th className="py-3 pr-4">WhatsApp</th>
            <th className="py-3 pr-4">Website Chat</th>
            <th className="py-3 pr-4">Phone AI</th>
            <th className="py-3 pr-4">Usage</th>
            <th className="py-3 pr-4"></th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-800">
          {items.map((item) => (
            <tr key={item.id}>
              <td className="py-3 pr-4 font-bold text-slate-100">{item.name}</td>
              <td className="py-3 pr-4 text-slate-400">{item.owner_email ?? 'none'}</td>
              <td className="py-3 pr-4 text-slate-300">{item.plan ?? 'none'}</td>
              <td className="py-3 pr-4"><StatusBadge status={item.subscription_status ?? 'none'} /></td>
              <td className="py-3 pr-4"><StatusBadge status={item.whatsapp?.status ?? 'not_connected'} /></td>
              <td className="py-3 pr-4"><StatusBadge status={item.website_chat_enabled ? 'enabled' : 'disabled'} /></td>
              <td className="py-3 pr-4"><StatusBadge status="Planned" /></td>
              <td className="py-3 pr-4 text-xs text-slate-400">{formatUsageBrief(item.usage)}</td>
              <td className="py-3 pr-4 text-right">
                <Link href={`/platform-admin/businesses/${item.id}`} className="font-bold text-indigo-300 hover:text-indigo-200">View business</Link>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export function StatusBadge({ status }: { status: string }) {
  const normalized = String(status).toLowerCase();
  const classes = normalized.includes('active') || normalized === 'delivered' || normalized === 'enabled'
    ? 'bg-emerald-400/15 text-emerald-200'
    : normalized.includes('failed') || normalized.includes('disabled') || normalized.includes('undelivered') || normalized.includes('reached')
      ? 'bg-red-400/15 text-red-200'
      : normalized.includes('planned')
        ? 'bg-sky-400/15 text-sky-200'
        : normalized.includes('warning') || normalized.includes('requested') || normalized.includes('near')
          ? 'bg-amber-300/15 text-amber-100'
          : 'bg-slate-800 text-slate-300';

  return <span className={`inline-flex rounded-md px-2.5 py-1 text-xs font-black capitalize ${classes}`}>{status}</span>;
}

export function KeyValue({ data, exclude = [] }: { data: Record<string, any>; exclude?: string[] }) {
  const rows = Object.entries(data ?? {}).filter(([key, value]) => !exclude.includes(key) && typeof value !== 'object');
  if (!rows.length) return <Empty text="No data." />;

  return (
    <dl className="grid gap-3 text-sm">
      {rows.map(([key, value]) => (
        <div key={key} className="grid gap-1 sm:grid-cols-[170px_1fr]">
          <dt className="font-bold text-slate-500">{key.replaceAll('_', ' ')}</dt>
          <dd className="break-words text-slate-200">{formatValue(value)}</dd>
        </div>
      ))}
    </dl>
  );
}

export function SetupRequest({ request }: { request?: Record<string, any> | null }) {
  if (!request) return <Empty text="No setup request details." />;

  return (
    <div>
      <h3 className="mb-2 text-sm font-black text-slate-100">Setup call details</h3>
      <KeyValue data={request} />
    </div>
  );
}

export function CommandBox({ command }: { command?: string | null }) {
  if (!command) return <Empty text="No activation command available." />;

  return (
    <div className="mt-4 rounded-md border border-slate-700 bg-slate-950 p-3">
      <p className="mb-2 text-xs font-bold uppercase tracking-wide text-slate-500">Copy WhatsApp activation command</p>
      <div className="flex items-start gap-2">
        <code className="min-w-0 flex-1 break-all text-xs text-indigo-200">{command}</code>
        <button type="button" onClick={() => navigator.clipboard?.writeText(command)} className="rounded-md bg-slate-800 p-2 text-slate-200 hover:bg-slate-700" aria-label="Copy activation command">
          <Clipboard className="h-4 w-4" />
        </button>
      </div>
    </div>
  );
}

export function UsageSummary({ summary }: { summary?: Record<string, any> }) {
  if (!summary) return <Empty text="No usage data." />;
  const usage = summary.usage ?? {};
  const limits = summary.limits ?? {};
  const keys = ['conversations', 'ai_messages', 'bookings', 'whatsapp_messages', 'phone_minutes'];

  return (
    <div className="grid gap-3 md:grid-cols-2">
      {keys.map((key) => (
        <div key={key} className="rounded-md bg-slate-950 p-3">
          <div className="flex justify-between gap-3 text-sm">
            <span className="font-bold text-slate-400">{key.replaceAll('_', ' ')}</span>
            <span className="font-black text-slate-100">{usage[key] ?? 0} / {limits[key] ?? 'unlimited'}</span>
          </div>
        </div>
      ))}
      <div className="rounded-md bg-slate-950 p-3 md:col-span-2">
        <div className="flex justify-between gap-3 text-sm">
          <span className="font-bold text-slate-400">whatsapp conversation analytics</span>
          <span className="font-black text-slate-100">{summary.analytics?.whatsapp_conversations ?? usage.whatsapp_conversations ?? 0}</span>
        </div>
        <p className="mt-1 text-xs text-slate-500">Analytics only. monthly_whatsapp_messages is the billable inbound + outbound WhatsApp limit.</p>
      </div>
    </div>
  );
}

export function Warnings({ warnings }: { warnings: Record<string, any>[] }) {
  if (!warnings.length) return null;

  return (
    <div className="mt-3 flex flex-wrap gap-2">
      {warnings.map((warning) => (
        <StatusBadge key={`${warning.metric}-${warning.level}`} status={`${warning.level === 'reached' ? 'Limit reached' : 'Near limit'}: ${warning.metric}`} />
      ))}
    </div>
  );
}

export function RecentActivity({ activity }: { activity: Record<string, any[]> }) {
  return (
    <div className="grid gap-4 lg:grid-cols-3">
      {Object.entries(activity).map(([label, rows]) => (
        <div key={label} className="rounded-md bg-slate-950 p-3">
          <h3 className="mb-2 text-sm font-black capitalize text-slate-100">{label}</h3>
          {rows?.length ? rows.map((row) => (
            <p key={row.id} className="mb-2 break-words text-xs text-slate-400">{activityLine(label, row)}</p>
          )) : <Empty text="None." />}
        </div>
      ))}
    </div>
  );
}

export function IssueList({ title, items }: { title: string; items?: any[] }) {
  return (
    <Panel title={`${title} (${items?.length ?? 0})`} className="mb-4">
      {items?.length ? (
        <div className="space-y-2">
          {items.map((item, index) => (
            <div key={item.id ?? index} className="grid gap-3 rounded-md bg-slate-950 px-3 py-3 text-sm text-slate-300 lg:grid-cols-[120px_1fr_1.4fr_1.4fr_auto] lg:items-center">
              <StatusBadge status={item.severity ?? severityForIssue(title)} />
              <span className="font-bold text-slate-100">{item.business_name ?? item.name ?? item.uuid ?? `Item ${index + 1}`}</span>
              <span>{item.description ?? item.owner_email ?? item.status ?? item.exception ?? ''}</span>
              <span className="text-slate-400">{item.suggested_action ?? suggestedActionForIssue(title)}</span>
              {item.salon_id && <Link href={`/platform-admin/businesses/${item.salon_id}`} className="font-bold text-indigo-300 hover:text-indigo-200">View business</Link>}
            </div>
          ))}
        </div>
      ) : <Empty text="No issues in this queue." />}
    </Panel>
  );
}

export function Select({ label, value, onChange, options }: { label: string; value: string; onChange: (value: string) => void; options: string[] }) {
  return (
    <label>
      <span className="text-xs font-bold uppercase tracking-wide text-slate-400">{label}</span>
      <select value={value} onChange={(event) => onChange(event.target.value)} className="mt-1 w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 outline-none">
        {options.map((option) => (
          <option key={option || 'all'} value={option}>{option || 'All'}</option>
        ))}
      </select>
    </label>
  );
}

export function Pagination({ pagination }: { pagination?: Record<string, any> }) {
  if (!pagination) return null;

  return (
    <div className="mt-4 flex items-center justify-between text-sm text-slate-400">
      <span>Page {pagination.current_page} of {pagination.last_page}, {pagination.total} total</span>
      <div className="flex gap-2">
        {pagination.prev_page_url && <Link href={pagination.prev_page_url} className="rounded-md bg-slate-800 px-3 py-2 font-bold text-slate-200">Previous</Link>}
        {pagination.next_page_url && <Link href={pagination.next_page_url} className="rounded-md bg-slate-800 px-3 py-2 font-bold text-slate-200">Next</Link>}
      </div>
    </div>
  );
}

export function Empty({ text }: { text: string }) {
  return <p className="text-sm text-slate-500">{text}</p>;
}

export function PhoneAiPlannedNotice() {
  return (
    <div className="mt-4 rounded-md border border-slate-800 bg-slate-900/50 p-4 text-sm text-slate-400">
      <Phone className="mr-2 inline h-4 w-4" />
      Phone AI is planned, not active.
    </div>
  );
}

function formatUsageBrief(summary?: Record<string, any>) {
  if (!summary) return 'No usage';

  const usage = summary.usage ?? {};
  const limits = summary.limits ?? {};

  return [
    `Chat ${usage.conversations ?? 0}/${limits.conversations ?? 'unlimited'}`,
    `AI ${usage.ai_messages ?? 0}/${limits.ai_messages ?? 'unlimited'}`,
    `WA ${usage.whatsapp_messages ?? 0}/${limits.whatsapp_messages ?? 'unlimited'}`,
  ].join(' | ');
}

function formatValue(value: unknown) {
  if (value === null || value === undefined || value === '') return 'none';
  if (typeof value === 'boolean') return value ? 'yes' : 'no';

  return String(value);
}

function activityLine(label: string, row: Record<string, any>) {
  if (label === 'bookings') {
    return `#${row.id} ${row.client_name ?? 'unknown'} | ${row.date ?? ''} ${row.time ?? ''} | ${row.status ?? 'unknown'}`;
  }

  if (label === 'conversations') {
    return `#${row.id} ${row.channel ?? 'unknown'} | ${row.status ?? 'unknown'} | ${row.summary ?? row.intent ?? 'No summary'}`;
  }

  return `#${row.id} ${row.direction ?? 'message'} | ${row.provider_message_id ?? 'no provider id'}`;
}

function severityForIssue(title: string) {
  const lower = title.toLowerCase();
  if (lower.includes('failed') || lower.includes('no sender') || lower.includes('reached')) return 'error';
  if (lower.includes('disabled') || lower.includes('missing') || lower.includes('near')) return 'warning';

  return 'info';
}

function suggestedActionForIssue(title: string) {
  const lower = title.toLowerCase();
  if (lower.includes('requested')) return 'Review setup request and schedule activation.';
  if (lower.includes('ai disabled')) return 'Confirm whether AI should be enabled for this sender.';
  if (lower.includes('no sender')) return 'Configure sender manually, then run activation command.';
  if (lower.includes('failed')) return 'Inspect provider status and latest delivery metadata.';
  if (lower.includes('notification')) return 'Ask business to add notification email.';
  if (lower.includes('usage')) return 'Review plan fit before the business hits a hard limit.';

  return 'Review details.';
}
