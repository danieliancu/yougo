import { Head, Link, usePage } from '@inertiajs/react';
import { Bot, CalendarCheck, ChevronDown, ClipboardList, HelpCircle, Sparkles } from 'lucide-react';
import { useState } from 'react';
import { ThemeToggle } from '@/Components/Ui';
import { businessTaxonomy, BusinessType } from '@/data/businessTaxonomy';
import { PageProps } from '@/types';

type Props = PageProps<{
  businessType: BusinessType;
  seo: {
    title: string;
    description: string;
  };
}>;

export default function IndustryShow() {
  const { auth, businessType, seo } = usePage<Props>().props;
  const hasFutureReservation = businessType.future_mode === 'reservation';
  const hasFutureLead = businessType.future_mode === 'lead';

  return (
    <main className="min-h-screen app-bg">
      <Head title={seo.title}>
        <meta name="description" content={seo.description} />
      </Head>

      <nav className="mx-auto flex max-w-6xl items-center justify-between px-6 py-5">
        <Link href="/" className="flex items-center">
          <img src="/images/logo-white.png" className="h-12 w-auto dark:hidden" alt="YouGo" />
          <img src="/images/logo-dark.png" className="hidden h-12 w-auto dark:block" alt="YouGo" />
        </Link>
        <IndustriesMenu />
        <div className="flex items-center gap-3">
          <ThemeToggle />
          {auth.user ? (
            <Link href="/dashboard" className="rounded-lg bg-slate-950 px-4 py-2 text-sm font-bold text-white dark:border dark:border-white">{auth.user.name}</Link>
          ) : (
            <Link href="/register" className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-bold text-white">Get started</Link>
          )}
        </div>
      </nav>

      <section className="mx-auto grid max-w-6xl gap-10 px-6 py-14 lg:grid-cols-[1fr_0.9fr] lg:items-center">
        <div>
          <p className="mb-4 inline-flex rounded-full bg-indigo-50 px-3 py-1 text-xs font-black uppercase tracking-wide text-indigo-700">{businessType.label}</p>
          <h1 className="max-w-3xl text-5xl font-black tracking-tight app-text md:text-6xl">YouGo AI receptionist for {businessType.label.toLowerCase()}</h1>
          <p className="mt-6 max-w-2xl text-lg leading-8 app-text-soft">{businessType.page_focus}</p>
          <div className="mt-8 flex flex-wrap gap-3">
            <Link href="/register" className="rounded-lg bg-indigo-600 px-5 py-3 text-sm font-black text-white shadow-sm hover:bg-indigo-700">Get started</Link>
            <Link href="/" className="rounded-lg border px-5 py-3 text-sm font-black hover:bg-[var(--soft)] app-border">View demo</Link>
          </div>
        </div>

        <div className="rounded-2xl border p-6 shadow-xl app-panel">
          <div className="flex items-center gap-3">
            <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-indigo-600 text-white">
              <Bot className="h-6 w-6" />
            </div>
            <div>
              <p className="text-sm font-black app-text">Current mode</p>
              <p className="text-sm app-text-muted">Appointment and enquiry handling</p>
            </div>
          </div>
          <p className="mt-5 text-sm leading-6 app-text-soft">
            {hasFutureReservation
              ? 'YouGo can currently handle enquiries and appointment-style requests. Full reservation availability and resource booking will be added later.'
              : hasFutureLead
                ? 'YouGo can currently collect enquiries and viewing or consultation requests. Full lead pipeline mode will be added later.'
                : 'YouGo can help answer questions and collect appointment requests now.'}
          </p>
        </div>
      </section>

      <section className="mx-auto max-w-6xl px-6 pb-20">
        <div className="grid gap-5 md:grid-cols-3">
          <InfoCard icon={HelpCircle} title="What clients ask">
            <ul className="space-y-3 text-sm app-text-soft">
              {businessType.common_questions.map((question) => <li key={question}>• {question}</li>)}
            </ul>
          </InfoCard>
          <InfoCard icon={Sparkles} title="How the AI helps">
            <p className="text-sm leading-6 app-text-soft">
              It answers configured questions, explains your services, collects complete request details and keeps the conversation aligned with your dashboard settings.
            </p>
            {businessType.safety_copy && <p className="mt-4 rounded-lg bg-amber-500/10 p-3 text-sm font-semibold text-amber-700 dark:text-amber-300">{businessType.safety_copy}</p>}
          </InfoCard>
          <InfoCard icon={ClipboardList} title="Current flow">
            <p className="text-sm font-bold app-text-soft">{businessType.current_flow}</p>
            {businessType.future_flow && (
              <p className="mt-4 text-sm app-text-muted">Coming later: {businessType.future_flow}</p>
            )}
          </InfoCard>
        </div>

        <div className="mt-8 rounded-2xl border p-6 app-panel">
          <div className="flex items-start gap-4">
            <CalendarCheck className="mt-1 h-6 w-6 text-indigo-500" />
            <div>
              <h2 className="text-2xl font-black app-text">Business type, AI context and mode</h2>
              <p className="mt-3 max-w-3xl text-sm leading-6 app-text-soft">
                {businessType.label} is the main public category. Detailed categories are optional AI context inside AI Settings; they help the assistant understand focus areas, but services configured in the dashboard remain the source of truth.
              </p>
              <div className="mt-4 flex flex-wrap gap-2">
                {businessType.industries.map((category) => (
                  <span key={category.slug} className="rounded-full bg-indigo-50 px-3 py-1 text-xs font-black text-indigo-700">{category.label}</span>
                ))}
              </div>
            </div>
          </div>
        </div>
      </section>
    </main>
  );
}

function InfoCard({ icon: Icon, title, children }: { icon: any; title: string; children: React.ReactNode }) {
  return (
    <div className="rounded-2xl border p-6 app-panel">
      <Icon className="mb-4 h-6 w-6 text-indigo-500" />
      <h2 className="mb-4 text-xl font-black app-text">{title}</h2>
      {children}
    </div>
  );
}

function IndustriesMenu() {
  const [open, setOpen] = useState(false);

  return (
    <div className="relative">
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        className="flex h-10 items-center gap-2 rounded-lg px-3 text-sm font-black app-text-soft hover:bg-[var(--soft)]"
      >
        Industries
        <ChevronDown className="h-4 w-4" />
      </button>
      {open && (
        <div className="absolute left-1/2 top-12 z-50 max-h-[70vh] w-[calc(100vw-2rem)] -translate-x-1/2 overflow-y-auto rounded-2xl border p-5 shadow-2xl app-panel md:w-[780px]">
          <div className="grid gap-3 md:grid-cols-3">
            {businessTaxonomy.map((group) => (
              <Link key={group.slug} href={`/industries/${group.slug}`} className="block rounded-lg px-3 py-2 text-sm font-bold app-text-soft hover:bg-[var(--soft)]">
                {group.label}
              </Link>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
