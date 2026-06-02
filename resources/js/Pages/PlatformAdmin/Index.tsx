import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, Building2, Clipboard, MessageCircle, Phone, Search, Shield, Users } from 'lucide-react';
import { FormEvent, ReactNode, useState } from 'react';

type AdminPage = 'overview' | 'businesses' | 'business_detail' | 'whatsapp_onboarding' | 'usage' | 'issues';

type Props = {
  page: AdminPage;
  payload: Record<string, any>;
};

const navItems = [
  { href: '/platform-admin', key: 'overview', label: 'Overview', icon: Shield },
  { href: '/platform-admin/businesses', key: 'businesses', label: 'Businesses', icon: Building2 },
  { href: '/platform-admin/whatsapp-onboarding', key: 'whatsapp_onboarding', label: 'WhatsApp Onboarding', icon: MessageCircle },
  { href: '/platform-admin/usage', key: 'usage', label: 'Usage', icon: Users },
  { href: '/platform-admin/issues', key: 'issues', label: 'Issues', icon: AlertTriangle },
];

export default function PlatformAdminIndex({ page, payload }: Props) {
  return (
    <AdminLayout page={page}>
      <Head title="Platform Admin" />
      {page === 'overview' && <Overview payload={payload} />}
      {page === 'businesses' && <Businesses payload={payload} />}
      {page === 'business_detail' && <BusinessDetail payload={payload} />}
      {page === 'whatsapp_onboarding' && <WhatsAppOnboarding payload={payload} />}
      {page === 'usage' && <Usage payload={payload} />}
      {page === 'issues' && <Issues payload={payload} />}
    </AdminLayout>
  );
}

function AdminLayout({ page, children }: { page: AdminPage; children: ReactNode }) {
  return (
    <div className="min-h-screen bg-stone-950 text-stone-100">
      <aside className="fixed inset-y-0 left-0 hidden w-72 border-r border-stone-800 bg-stone-950 px-5 py-6 lg:block">
        <div className="mb-8">
          <p className="text-xs font-bold uppercase tracking-[0.24em] text-amber-300">YouGo</p>
          <h1 className="mt-2 text-2xl font-black tracking-tight text-stone-50">Platform Admin</h1>
          <p className="mt-2 text-sm text-stone-400">Internal read-only operations console.</p>
        </div>
        <nav className="space-y-1">
          {navItems.map((item) => {
            const Icon = item.icon;
            const active = item.key === page || (page === 'business_detail' && item.key === 'businesses');

            return (
              <Link
                key={item.key}
                href={item.href}
                className={`flex items-center gap-3 rounded-md px-3 py-2.5 text-sm font-bold transition ${active ? 'bg-amber-300 text-stone-950' : 'text-stone-300 hover:bg-stone-900 hover:text-stone-50'}`}
              >
                <Icon className="h-4 w-4" />
                {item.label}
              </Link>
            );
          })}
        </nav>
        <Link href="/dashboard" className="mt-8 flex items-center gap-2 rounded-md px-3 py-2 text-sm font-bold text-stone-400 transition hover:bg-stone-900 hover:text-stone-50">
          <ArrowLeft className="h-4 w-4" />
          Back to Business Dashboard
        </Link>
      </aside>

      <main className="lg:pl-72">
        <div className="border-b border-stone-800 bg-stone-950/95 px-4 py-4 lg:hidden">
          <h1 className="text-xl font-black">Platform Admin</h1>
          <div className="mt-3 flex gap-2 overflow-x-auto pb-1">
            {navItems.map((item) => (
              <Link key={item.key} href={item.href} className="shrink-0 rounded-md bg-stone-900 px-3 py-2 text-xs font-bold text-stone-200">
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

function Overview({ payload }: { payload: Record<string, any> }) {
  const totals = payload.totals ?? {};
  const metrics = [
    ['Businesses', totals.businesses],
    ['Active', totals.active],
    ['Free', totals.free],
    ['Paid', totals.paid],
    ['WhatsApp requested', totals.whatsapp_requested],
    ['WhatsApp active', totals.whatsapp_active],
    ['WhatsApp failed', totals.whatsapp_failed],
    ['Current-month WhatsApp messages', totals.whatsapp_messages],
    ['AI bookings', totals.ai_bookings],
    ['Website chat conversations', totals.website_chat_conversations],
    ['Phone AI', 'Planned'],
  ];

  return (
    <section>
      <PageHeader title="Overview" subtitle="Operator snapshot for businesses, WhatsApp onboarding, usage, and current operational health." />
      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {metrics.map(([label, value]) => (
          <Metric key={String(label)} label={String(label)} value={value ?? 0} />
        ))}
      </div>
      <Panel title="Recent businesses" className="mt-8">
        <BusinessTable items={payload.recent_businesses ?? []} />
      </Panel>
    </section>
  );
}

function Businesses({ payload }: { payload: Record<string, any> }) {
  const filters = payload.filters ?? {};
  const [search, setSearch] = useState(filters.search ?? '');
  const [plan, setPlan] = useState(filters.plan ?? '');
  const [subscriptionStatus, setSubscriptionStatus] = useState(filters.subscription_status ?? '');
  const [whatsappStatus, setWhatsappStatus] = useState(filters.whatsapp_status ?? '');

  function submit(event: FormEvent) {
    event.preventDefault();
    router.get('/platform-admin/businesses', {
      search,
      plan,
      subscription_status: subscriptionStatus,
      whatsapp_status: whatsappStatus,
    }, { preserveState: true });
  }

  return (
    <section>
      <PageHeader title="Businesses" subtitle="Search and filter the customer base by owner, plan, subscription, and WhatsApp status." />
      <form onSubmit={submit} className="mb-6 grid gap-3 rounded-md border border-stone-800 bg-stone-900/60 p-4 md:grid-cols-4">
        <label className="md:col-span-2">
          <span className="text-xs font-bold uppercase tracking-wide text-stone-400">Business or email</span>
          <div className="mt-1 flex items-center gap-2 rounded-md border border-stone-700 bg-stone-950 px-3">
            <Search className="h-4 w-4 text-stone-500" />
            <input value={search} onChange={(event) => setSearch(event.target.value)} className="w-full bg-transparent py-2 text-sm text-stone-100 outline-none" />
          </div>
        </label>
        <Select label="Plan" value={plan} onChange={setPlan} options={['', ...Object.keys(payload.plans ?? {})]} />
        <Select label="Subscription" value={subscriptionStatus} onChange={setSubscriptionStatus} options={['', 'active', 'trialing', 'past_due', 'cancelled', 'none']} />
        <Select label="WhatsApp status" value={whatsappStatus} onChange={setWhatsappStatus} options={['', 'requested', 'active', 'failed', 'disabled', 'not_connected']} />
        <button className="rounded-md bg-amber-300 px-4 py-2 text-sm font-black text-stone-950 md:self-end">Apply filters</button>
      </form>
      <Panel title={`Businesses (${payload.pagination?.total ?? 0})`}>
        <BusinessTable items={payload.items ?? []} />
        <Pagination pagination={payload.pagination} />
      </Panel>
    </section>
  );
}

function BusinessDetail({ payload }: { payload: Record<string, any> }) {
  const business = payload.business ?? {};
  const whatsapp = payload.whatsapp;

  return (
    <section>
      <PageHeader title={business.name ?? 'Business detail'} subtitle={business.owner_email ?? 'Business profile, billing, usage, WhatsApp technical details, and recent activity.'} />
      <div className="grid gap-6 xl:grid-cols-[1fr_380px]">
        <div className="space-y-6">
          <Panel title="Business profile">
            <KeyValue data={payload.profile ?? {}} />
          </Panel>
          <Panel title="Plan and billing">
            <KeyValue data={payload.billing ?? {}} />
          </Panel>
          <Panel title="Current usage vs limits">
            <UsageSummary summary={payload.usage} />
          </Panel>
          <Panel title="Recent activity">
            <RecentActivity activity={payload.recent_activity ?? {}} />
          </Panel>
        </div>
        <div className="space-y-6">
          <Panel title="WhatsApp integration">
            {whatsapp ? (
              <>
                <KeyValue data={whatsapp} exclude={['metadata', 'setup_request']} />
                <CommandBox command={whatsapp.activation_command} />
              </>
            ) : (
              <Empty text="No WhatsApp integration." />
            )}
          </Panel>
          <Panel title="Latest setup request">
            {whatsapp?.setup_request ? <SetupRequest request={whatsapp.setup_request} /> : <Empty text="No setup request metadata yet." />}
          </Panel>
        </div>
      </div>
    </section>
  );
}

function WhatsAppOnboarding({ payload }: { payload: Record<string, any> }) {
  const [status, setStatus] = useState(payload.status ?? 'requested');

  function changeStatus(next: string) {
    setStatus(next);
    router.get('/platform-admin/whatsapp-onboarding', { status: next }, { preserveState: true });
  }

  return (
    <section>
      <PageHeader title="WhatsApp Onboarding" subtitle="Requested integrations, setup call details, Meta/account answers, and copyable activation commands." />
      <div className="mb-5 flex flex-wrap gap-2">
        {['requested', 'active', 'failed', 'disabled', 'all'].map((option) => (
          <button key={option} type="button" onClick={() => changeStatus(option)} className={`rounded-md px-3 py-2 text-sm font-bold capitalize ${status === option ? 'bg-amber-300 text-stone-950' : 'bg-stone-900 text-stone-300 hover:bg-stone-800'}`}>
            {option}
          </button>
        ))}
      </div>
      <div className="space-y-4">
        {(payload.items ?? []).map((item: Record<string, any>) => (
          <Panel key={item.id} title={item.business_name ?? `Salon ${item.salon_id}`}>
            <div className="grid gap-5 lg:grid-cols-[1fr_1fr_340px]">
              <KeyValue data={{
                status: item.status,
                requested_number: item.requested_number,
                display_number: item.display_number,
                owner_email: item.owner_email,
                ai_enabled: item.ai_enabled,
              }} />
              <SetupRequest request={item.setup_request} />
              <div>
                <h3 className="mb-2 text-sm font-black text-stone-100">Checklist</h3>
                <ul className="space-y-2 text-sm text-stone-300">
                  <li>Verify WhatsApp Business number ownership.</li>
                  <li>Confirm Meta Business access during setup call.</li>
                  <li>Configure approved sender in Twilio or Meta manually.</li>
                  <li>Confirm inbound and outbound test messages.</li>
                </ul>
                <CommandBox command={item.activation_command} />
              </div>
            </div>
          </Panel>
        ))}
        {(payload.items ?? []).length === 0 && <Empty text="No onboarding items for this status." />}
      </div>
    </section>
  );
}

function Usage({ payload }: { payload: Record<string, any> }) {
  return (
    <section>
      <PageHeader title="Usage" subtitle={`Current-month usage vs limits (${payload.month ?? ''}). Rows over 80% are flagged as near limit.`} />
      <div className="space-y-4">
        {(payload.items ?? []).map((item: Record<string, any>) => (
          <Panel key={item.id} title={item.name}>
            <div className="grid gap-5 lg:grid-cols-[280px_1fr]">
              <KeyValue data={{ owner_email: item.owner_email, plan: item.plan, subscription_status: item.subscription_status, phone_ai: 'Planned' }} />
              <div>
                <UsageSummary summary={item.usage} />
                <Warnings warnings={item.warnings ?? []} />
              </div>
            </div>
          </Panel>
        ))}
      </div>
    </section>
  );
}

function Issues({ payload }: { payload: Record<string, any> }) {
  return (
    <section>
      <PageHeader title="Issues" subtitle="Read-only operational queues for onboarding gaps, delivery failures, missing emails, usage warnings, and failed jobs." />
      <IssueList title="WhatsApp requested" items={payload.whatsapp_requested} />
      <IssueList title="Active with AI disabled" items={payload.active_ai_disabled} />
      <IssueList title="Active with no sender" items={payload.active_missing_sender} />
      <IssueList title="Failed or undelivered WhatsApp messages" items={payload.failed_whatsapp_messages} />
      <IssueList title="Missing notification email" items={payload.missing_notification_email} />
      <IssueList title="Usage near or reached limits" items={payload.usage_near_limits} />
      <IssueList title="Failed jobs" items={payload.failed_jobs} />
      <div className="mt-4 rounded-md border border-stone-800 bg-stone-900/50 p-4 text-sm text-stone-400">
        <Phone className="mr-2 inline h-4 w-4" />
        Phone AI is planned, not active.
      </div>
    </section>
  );
}

function PageHeader({ title, subtitle }: { title: string; subtitle: string }) {
  return (
    <header className="mb-7">
      <p className="text-xs font-black uppercase tracking-[0.24em] text-amber-300">Internal</p>
      <h2 className="mt-2 text-3xl font-black tracking-tight text-stone-50">{title}</h2>
      <p className="mt-2 max-w-3xl text-sm leading-6 text-stone-400">{subtitle}</p>
    </header>
  );
}

function Panel({ title, children, className = '' }: { title: string; children: ReactNode; className?: string }) {
  return (
    <section className={`rounded-md border border-stone-800 bg-stone-900/55 p-5 shadow-sm shadow-stone-950/30 ${className}`}>
      <h2 className="mb-4 text-base font-black text-stone-50">{title}</h2>
      {children}
    </section>
  );
}

function Metric({ label, value }: { label: string; value: any }) {
  return (
    <div className="rounded-md border border-stone-800 bg-stone-900 p-4">
      <p className="text-xs font-bold uppercase tracking-wide text-stone-500">{label}</p>
      <p className="mt-2 text-2xl font-black text-stone-50">{String(value)}</p>
    </div>
  );
}

function BusinessTable({ items }: { items: Record<string, any>[] }) {
  if (!items.length) return <Empty text="No businesses found." />;

  return (
    <div className="overflow-x-auto">
      <table className="min-w-full divide-y divide-stone-800 text-sm">
        <thead className="text-left text-xs uppercase tracking-wide text-stone-500">
          <tr>
            <th className="py-3 pr-4">Business</th>
            <th className="py-3 pr-4">Owner</th>
            <th className="py-3 pr-4">Plan</th>
            <th className="py-3 pr-4">Subscription</th>
            <th className="py-3 pr-4">WhatsApp</th>
            <th className="py-3 pr-4">Phone AI</th>
            <th className="py-3 pr-4"></th>
          </tr>
        </thead>
        <tbody className="divide-y divide-stone-800">
          {items.map((item) => (
            <tr key={item.id}>
              <td className="py-3 pr-4 font-bold text-stone-100">{item.name}</td>
              <td className="py-3 pr-4 text-stone-400">{item.owner_email}</td>
              <td className="py-3 pr-4 text-stone-300">{item.plan ?? 'none'}</td>
              <td className="py-3 pr-4"><StatusBadge status={item.subscription_status ?? 'none'} /></td>
              <td className="py-3 pr-4"><StatusBadge status={item.whatsapp?.status ?? 'not_connected'} /></td>
              <td className="py-3 pr-4"><StatusBadge status="Planned" /></td>
              <td className="py-3 pr-4 text-right">
                <Link href={`/platform-admin/businesses/${item.id}`} className="font-bold text-amber-300 hover:text-amber-200">View business</Link>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function StatusBadge({ status }: { status: string }) {
  const normalized = String(status).toLowerCase();
  const classes = normalized.includes('active') || normalized === 'delivered'
    ? 'bg-emerald-400/15 text-emerald-200'
    : normalized.includes('failed') || normalized.includes('disabled') || normalized.includes('undelivered')
      ? 'bg-red-400/15 text-red-200'
      : normalized.includes('planned')
        ? 'bg-sky-400/15 text-sky-200'
        : 'bg-stone-800 text-stone-300';

  return <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-black capitalize ${classes}`}>{status}</span>;
}

function KeyValue({ data, exclude = [] }: { data: Record<string, any>; exclude?: string[] }) {
  const rows = Object.entries(data ?? {}).filter(([key, value]) => !exclude.includes(key) && typeof value !== 'object');
  if (!rows.length) return <Empty text="No data." />;

  return (
    <dl className="grid gap-3 text-sm">
      {rows.map(([key, value]) => (
        <div key={key} className="grid gap-1 sm:grid-cols-[170px_1fr]">
          <dt className="font-bold text-stone-500">{key.replaceAll('_', ' ')}</dt>
          <dd className="break-words text-stone-200">{String(value ?? 'none')}</dd>
        </div>
      ))}
    </dl>
  );
}

function SetupRequest({ request }: { request?: Record<string, any> | null }) {
  if (!request) return <Empty text="No setup request details." />;

  return (
    <div>
      <h3 className="mb-2 text-sm font-black text-stone-100">Setup call details</h3>
      <KeyValue data={request} />
    </div>
  );
}

function CommandBox({ command }: { command?: string | null }) {
  if (!command) return <Empty text="No activation command available." />;

  return (
    <div className="mt-4 rounded-md border border-stone-700 bg-stone-950 p-3">
      <p className="mb-2 text-xs font-bold uppercase tracking-wide text-stone-500">Copy WhatsApp activation command</p>
      <div className="flex items-start gap-2">
        <code className="min-w-0 flex-1 break-all text-xs text-amber-200">{command}</code>
        <button type="button" onClick={() => navigator.clipboard?.writeText(command)} className="rounded-md bg-stone-800 p-2 text-stone-200 hover:bg-stone-700" aria-label="Copy activation command">
          <Clipboard className="h-4 w-4" />
        </button>
      </div>
    </div>
  );
}

function UsageSummary({ summary }: { summary?: Record<string, any> }) {
  if (!summary) return <Empty text="No usage data." />;
  const usage = summary.usage ?? {};
  const limits = summary.limits ?? {};

  return (
    <div className="grid gap-3 md:grid-cols-2">
      {Object.keys(usage).map((key) => (
        <div key={key} className="rounded-md bg-stone-950 p-3">
          <div className="flex justify-between gap-3 text-sm">
            <span className="font-bold text-stone-400">{key.replaceAll('_', ' ')}</span>
            <span className="font-black text-stone-100">{usage[key]} / {limits[key] ?? 'unlimited'}</span>
          </div>
        </div>
      ))}
    </div>
  );
}

function Warnings({ warnings }: { warnings: Record<string, any>[] }) {
  if (!warnings.length) return null;

  return (
    <div className="mt-3 flex flex-wrap gap-2">
      {warnings.map((warning) => (
        <span key={`${warning.metric}-${warning.level}`} className={`rounded-full px-2.5 py-1 text-xs font-black ${warning.level === 'reached' ? 'bg-red-400/15 text-red-200' : 'bg-amber-300/15 text-amber-100'}`}>
          {warning.level === 'reached' ? 'Limit reached' : 'Near limit'}: {warning.metric}
        </span>
      ))}
    </div>
  );
}

function RecentActivity({ activity }: { activity: Record<string, any[]> }) {
  return (
    <div className="grid gap-4 lg:grid-cols-3">
      {Object.entries(activity).map(([label, rows]) => (
        <div key={label} className="rounded-md bg-stone-950 p-3">
          <h3 className="mb-2 text-sm font-black capitalize text-stone-100">{label}</h3>
          {rows?.length ? rows.map((row) => (
            <p key={row.id} className="mb-2 break-words text-xs text-stone-400">{JSON.stringify(row)}</p>
          )) : <Empty text="None." />}
        </div>
      ))}
    </div>
  );
}

function IssueList({ title, items }: { title: string; items?: any[] }) {
  return (
    <Panel title={`${title} (${items?.length ?? 0})`} className="mb-4">
      {items?.length ? (
        <div className="space-y-2">
          {items.map((item, index) => (
            <div key={item.id ?? index} className="flex flex-wrap items-center justify-between gap-3 rounded-md bg-stone-950 px-3 py-2 text-sm text-stone-300">
              <span className="font-bold text-stone-100">{item.business_name ?? item.name ?? item.uuid ?? `Item ${index + 1}`}</span>
              <span>{item.owner_email ?? item.status ?? item.exception ?? ''}</span>
              {item.activation_command && <CommandBox command={item.activation_command} />}
            </div>
          ))}
        </div>
      ) : <Empty text="No issues in this queue." />}
    </Panel>
  );
}

function Select({ label, value, onChange, options }: { label: string; value: string; onChange: (value: string) => void; options: string[] }) {
  return (
    <label>
      <span className="text-xs font-bold uppercase tracking-wide text-stone-400">{label}</span>
      <select value={value} onChange={(event) => onChange(event.target.value)} className="mt-1 w-full rounded-md border border-stone-700 bg-stone-950 px-3 py-2 text-sm text-stone-100 outline-none">
        {options.map((option) => (
          <option key={option || 'all'} value={option}>{option || 'All'}</option>
        ))}
      </select>
    </label>
  );
}

function Pagination({ pagination }: { pagination?: Record<string, any> }) {
  if (!pagination) return null;

  return (
    <div className="mt-4 flex items-center justify-between text-sm text-stone-400">
      <span>Page {pagination.current_page} of {pagination.last_page}, {pagination.total} total</span>
      <div className="flex gap-2">
        {pagination.prev_page_url && <Link href={pagination.prev_page_url} className="rounded-md bg-stone-800 px-3 py-2 font-bold text-stone-200">Previous</Link>}
        {pagination.next_page_url && <Link href={pagination.next_page_url} className="rounded-md bg-stone-800 px-3 py-2 font-bold text-stone-200">Next</Link>}
      </div>
    </div>
  );
}

function Empty({ text }: { text: string }) {
  return <p className="text-sm text-stone-500">{text}</p>;
}
