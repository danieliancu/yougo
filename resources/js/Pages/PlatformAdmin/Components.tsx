import { Link, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, Building2, Clipboard, MessageCircle, Phone, Search, Settings, Shield, UserCircle, Users, type LucideIcon } from 'lucide-react';
import { ReactNode } from 'react';

export type AdminPage = 'overview' | 'businesses' | 'business_detail' | 'whatsapp_onboarding' | 'usage' | 'issues' | 'settings';

const mainNavItems = [
  { href: '/platform-admin', key: 'overview', label: 'Overview', icon: Shield },
  { href: '/platform-admin/businesses', key: 'businesses', label: 'Businesses', icon: Building2 },
  { href: '/platform-admin/whatsapp-onboarding', key: 'whatsapp_onboarding', label: 'WhatsApp Onboarding', icon: MessageCircle },
  { href: '/platform-admin/usage', key: 'usage', label: 'Usage', icon: Users },
  { href: '/platform-admin/issues', key: 'issues', label: 'Issues', icon: AlertTriangle },
];

export function PlatformAdminLayout({ page, children }: { page: AdminPage; children: ReactNode }) {
  const { auth } = usePage<{ auth?: { platform_admin?: { name?: string | null; username?: string | null } | null } }>().props;
  const admin = auth?.platform_admin;

  return (
    <div className="min-h-screen bg-slate-100 text-slate-900">
      <aside className="fixed inset-y-0 left-0 hidden w-72 border-r border-slate-800 bg-slate-950 px-5 py-5 lg:flex lg:flex-col">
        <div className="mb-7 flex items-center gap-3 rounded-2xl bg-white/5 p-3">
          <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-500 text-base font-bold text-white shadow-lg shadow-indigo-950/40">YG</span>
          <span className="min-w-0">
            <span className="block text-sm font-bold text-white">YouGo Admin</span>
            <span className="block truncate text-xs font-semibold text-slate-400">Platform Admin</span>
          </span>
        </div>

        <nav className="min-h-0 flex-1 space-y-7 overflow-y-auto">
          <NavGroup label="Main">
            {mainNavItems.map((item) => {
              const Icon = item.icon;
              const active = item.key === page || (page === 'business_detail' && item.key === 'businesses');

              return (
                <Link
                  key={item.key}
                  href={item.href}
                  className={`flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-bold transition ${active ? 'bg-indigo-500 text-white shadow-lg shadow-indigo-950/40' : 'text-slate-300 hover:bg-white/10 hover:text-white'}`}
                >
                  <Icon className="h-4 w-4 shrink-0" />
                  <span className="min-w-0 flex-1 truncate">{item.label}</span>
                </Link>
              );
            })}
          </NavGroup>

          <NavGroup label="Future">
            <FutureNavItem icon={Phone} label="Phone AI" />
          </NavGroup>

          <NavGroup label="Account">
            <Link href="/platform-admin/settings" className={`flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-bold transition ${page === 'settings' ? 'bg-indigo-500 text-white shadow-lg shadow-indigo-950/40' : 'text-slate-300 hover:bg-white/10 hover:text-white'}`}>
              <Settings className="h-4 w-4 shrink-0" />
              <span className="min-w-0 flex-1 truncate">Admin Settings</span>
            </Link>
            <Link href="/platform-admin/logout" method="post" as="button" className="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-bold text-slate-300 transition hover:bg-white/10 hover:text-white">
              <Shield className="h-4 w-4 shrink-0" />
              <span className="min-w-0 flex-1 truncate">Sign out admin</span>
            </Link>
            <Link href="/" className="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-bold text-slate-300 transition hover:bg-white/10 hover:text-white">
              <ArrowLeft className="h-4 w-4 shrink-0" />
              <span className="min-w-0 flex-1 truncate">Main site</span>
            </Link>
          </NavGroup>
        </nav>
      </aside>

      <main className="lg:pl-72">
        <header className="sticky top-0 z-30 border-b border-slate-200 bg-white/90 px-4 py-3 shadow-sm shadow-slate-200/60 backdrop-blur sm:px-6 lg:px-8">
          <div className="mx-auto flex max-w-7xl items-center gap-4">
            <div className="min-w-0 flex-1">
              <div className="relative max-w-2xl">
                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input
                  type="search"
                  placeholder="Search businesses, emails, WhatsApp numbers..."
                  className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-3 text-sm font-medium text-slate-700 outline-none transition placeholder:text-slate-400 focus:border-indigo-300 focus:bg-white focus:ring-4 focus:ring-indigo-100"
                />
              </div>
            </div>
            <span className="hidden rounded-full bg-indigo-50 px-3 py-1 text-xs font-bold text-indigo-700 sm:inline-flex">Platform Admin</span>
            <div className="flex min-w-0 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 shadow-sm">
              <UserCircle className="h-5 w-5 shrink-0 text-slate-400" />
              <span className="hidden min-w-0 sm:block">
                <span className="block truncate text-xs font-bold text-slate-800">{admin?.name ?? 'Admin'}</span>
                <span className="block truncate text-[11px] font-semibold text-slate-500">{admin?.username ?? 'Internal operator'}</span>
              </span>
            </div>
          </div>
          <div className="mt-3 flex gap-2 overflow-x-auto pb-1 lg:hidden">
            {mainNavItems.map((item) => (
              <Link key={item.key} href={item.href} className="shrink-0 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold text-slate-600">
                {item.label}
              </Link>
            ))}
          </div>
        </header>
        <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">{children}</div>
      </main>
    </div>
  );
}

function NavGroup({ label, children }: { label: string; children: ReactNode }) {
  return (
    <section>
      <p className="mb-2 px-3 text-[11px] font-bold uppercase tracking-wide text-slate-500">{label}</p>
      <div className="space-y-1">{children}</div>
    </section>
  );
}

function FutureNavItem({ icon: Icon, label }: { icon: LucideIcon; label: string }) {
  return (
    <div className="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-bold text-slate-500">
      <Icon className="h-4 w-4 shrink-0" />
      <span className="min-w-0 flex-1 truncate">{label}</span>
      <span className="rounded-full bg-white/10 px-2 py-0.5 text-[10px] uppercase tracking-wide text-slate-400">Soon</span>
    </div>
  );
}

export function PageHeader({ title, subtitle }: { title: string; subtitle: string }) {
  return (
    <header className="mb-7">
      <p className="text-xs font-bold uppercase tracking-wide text-indigo-600">Internal operations</p>
      <h2 className="mt-2 text-3xl font-bold tracking-tight text-slate-950">{title}</h2>
      <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-500">{subtitle}</p>
    </header>
  );
}

export function Panel({ title, children, className = '' }: { title: string; children: ReactNode; className?: string }) {
  return (
    <section className={`rounded-2xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-200/70 ${className}`}>
      <h2 className="mb-4 text-base font-bold text-slate-950">{title}</h2>
      {children}
    </section>
  );
}

export function Metric({ label, value }: { label: string; value: unknown }) {
  return (
    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-200/80">
      <p className="text-xs font-bold uppercase tracking-wide text-slate-500">{label}</p>
      <p className="mt-3 text-3xl font-bold tracking-tight text-slate-950">{String(value ?? 0)}</p>
    </div>
  );
}

export function BusinessTable({ items }: { items: Record<string, any>[] }) {
  if (!items.length) return <Empty text="No businesses found." />;

  return (
    <div className="overflow-x-auto">
      <table className="min-w-[1120px] divide-y divide-slate-100 text-sm">
        <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th className="px-4 py-3">Business</th>
            <th className="px-4 py-3">Owner</th>
            <th className="px-4 py-3">Plan</th>
            <th className="px-4 py-3">Subscription</th>
            <th className="px-4 py-3">WhatsApp</th>
            <th className="px-4 py-3">Website Chat</th>
            <th className="px-4 py-3">Phone AI</th>
            <th className="px-4 py-3">Usage</th>
            <th className="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100 bg-white">
          {items.map((item) => (
            <tr key={item.id} className="transition hover:bg-slate-50/80">
              <td className="px-4 py-4 font-bold text-slate-950">{item.name}</td>
              <td className="px-4 py-4 text-slate-500">{item.owner_email ?? 'none'}</td>
              <td className="px-4 py-4"><StatusBadge status={item.plan ?? 'none'} /></td>
              <td className="px-4 py-4"><StatusBadge status={item.subscription_status ?? 'none'} /></td>
              <td className="px-4 py-4"><StatusBadge status={item.whatsapp?.status ?? 'not_connected'} /></td>
              <td className="px-4 py-4"><StatusBadge status={item.website_chat_enabled ? 'enabled' : 'disabled'} /></td>
              <td className="px-4 py-4"><StatusBadge status="Planned" /></td>
              <td className="px-4 py-4 text-xs font-semibold text-slate-500">{formatUsageBrief(item.usage)}</td>
              <td className="px-4 py-4 text-right">
                <Link href={`/platform-admin/businesses/${item.id}`} className="inline-flex rounded-lg bg-indigo-50 px-3 py-2 text-xs font-bold text-indigo-700 hover:bg-indigo-100">View</Link>
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
    ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200'
    : normalized.includes('failed') || normalized.includes('disabled') || normalized.includes('undelivered') || normalized.includes('reached')
      ? 'bg-red-50 text-red-700 ring-1 ring-red-200'
      : normalized.includes('planned')
        ? 'bg-sky-50 text-sky-700 ring-1 ring-sky-200'
        : normalized.includes('warning') || normalized.includes('requested') || normalized.includes('near')
          ? 'bg-amber-50 text-amber-700 ring-1 ring-amber-200'
          : 'bg-slate-100 text-slate-700 ring-1 ring-slate-200';

  return <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-bold capitalize ${classes}`}>{status}</span>;
}

export function KeyValue({ data, exclude = [] }: { data: Record<string, any>; exclude?: string[] }) {
  const rows = Object.entries(data ?? {}).filter(([key, value]) => !exclude.includes(key) && typeof value !== 'object');
  if (!rows.length) return <Empty text="No data." />;

  return (
    <dl className="grid gap-3 text-sm">
      {rows.map(([key, value]) => (
        <div key={key} className="grid gap-1 sm:grid-cols-[170px_1fr]">
          <dt className="font-bold text-slate-500">{key.replaceAll('_', ' ')}</dt>
          <dd className="break-words font-semibold text-slate-800">{formatValue(value)}</dd>
        </div>
      ))}
    </dl>
  );
}

export function SetupRequest({ request }: { request?: Record<string, any> | null }) {
  if (!request) return <Empty text="No setup request details." />;

  return (
    <div>
      <h3 className="mb-2 text-sm font-bold text-slate-900">Setup call details</h3>
      <KeyValue data={request} />
    </div>
  );
}

export function CommandBox({ command }: { command?: string | null }) {
  if (!command) return <Empty text="No activation command available." />;

  return (
    <div className="mt-4 rounded-xl border border-indigo-100 bg-indigo-50 p-3">
      <p className="mb-2 text-xs font-bold uppercase tracking-wide text-slate-500">Copy WhatsApp activation command</p>
      <div className="flex items-start gap-2">
        <code className="min-w-0 flex-1 break-all text-xs font-bold text-indigo-800">{command}</code>
        <button type="button" onClick={() => navigator.clipboard?.writeText(command)} className="rounded-lg bg-indigo-600 p-2 text-white hover:bg-indigo-700" aria-label="Copy activation command">
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
        <div key={key} className="rounded-xl border border-slate-100 bg-slate-50 p-3">
          <div className="flex justify-between gap-3 text-sm">
            <span className="font-bold text-slate-600">{key.replaceAll('_', ' ')}</span>
            <span className="font-bold text-slate-900">{usage[key] ?? 0} / {limits[key] ?? 'unlimited'}</span>
          </div>
          <UsageBar used={Number(usage[key] ?? 0)} limit={limits[key]} />
        </div>
      ))}
      <div className="rounded-xl border border-slate-100 bg-slate-50 p-3 md:col-span-2">
        <div className="flex justify-between gap-3 text-sm">
          <span className="font-bold text-slate-600">whatsapp conversation analytics</span>
          <span className="font-bold text-slate-900">{summary.analytics?.whatsapp_conversations ?? usage.whatsapp_conversations ?? 0}</span>
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
        <div key={label} className="rounded-xl border border-slate-100 bg-slate-50 p-3">
          <h3 className="mb-2 text-sm font-bold capitalize text-slate-900">{label}</h3>
          {rows?.length ? rows.map((row) => (
            <p key={row.id} className="mb-2 break-words text-xs font-semibold text-slate-500">{activityLine(label, row)}</p>
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
            <div key={item.id ?? index} className="grid gap-3 rounded-xl border border-slate-100 bg-slate-50 px-3 py-3 text-sm text-slate-600 lg:grid-cols-[120px_1fr_1.4fr_1.4fr_auto] lg:items-center">
              <StatusBadge status={item.severity ?? severityForIssue(title)} />
              <span className="font-bold text-slate-950">{item.business_name ?? item.name ?? item.uuid ?? `Item ${index + 1}`}</span>
              <span>{item.description ?? item.owner_email ?? item.status ?? item.exception ?? ''}</span>
              <span className="text-slate-400">{item.suggested_action ?? suggestedActionForIssue(title)}</span>
              {item.salon_id && <Link href={`/platform-admin/businesses/${item.salon_id}`} className="font-bold text-indigo-700 hover:text-indigo-900">View business</Link>}
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
      <span className="text-xs font-bold uppercase tracking-wide text-slate-500">{label}</span>
      <select value={value} onChange={(event) => onChange(event.target.value)} className="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 outline-none focus:border-indigo-300 focus:ring-4 focus:ring-indigo-100">
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
    <div className="mt-4 flex items-center justify-between text-sm text-slate-500">
      <span>Page {pagination.current_page} of {pagination.last_page}, {pagination.total} total</span>
      <div className="flex gap-2">
        {pagination.prev_page_url && <Link href={pagination.prev_page_url} className="rounded-lg border border-slate-200 bg-white px-3 py-2 font-bold text-slate-700">Previous</Link>}
        {pagination.next_page_url && <Link href={pagination.next_page_url} className="rounded-lg border border-slate-200 bg-white px-3 py-2 font-bold text-slate-700">Next</Link>}
      </div>
    </div>
  );
}

export function Empty({ text }: { text: string }) {
  return <p className="text-sm text-slate-500">{text}</p>;
}

export function PhoneAiPlannedNotice() {
  return (
    <div className="mt-4 rounded-2xl border border-sky-100 bg-sky-50 p-4 text-sm font-semibold text-sky-800 shadow-sm shadow-slate-200/60">
      <Phone className="mr-2 inline h-4 w-4" />
      Phone AI is planned, not active.
    </div>
  );
}

function UsageBar({ used, limit }: { used: number; limit: unknown }) {
  if (!limit || !Number.isFinite(Number(limit)) || Number(limit) <= 0) {
    return <div className="mt-2 h-2 rounded-full bg-slate-200"><div className="h-2 w-0 rounded-full bg-indigo-500" /></div>;
  }

  const percent = Math.min(100, Math.round((used / Number(limit)) * 100));
  const color = percent >= 100 ? 'bg-red-500' : percent >= 80 ? 'bg-amber-400' : 'bg-indigo-500';

  return (
    <div className="mt-2 h-2 rounded-full bg-slate-200">
      <div className={`h-2 rounded-full ${color}`} style={{ width: `${percent}%` }} />
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
