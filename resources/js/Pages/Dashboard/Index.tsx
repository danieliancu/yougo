import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { AlertModal, Badge, Button, Card, ConfirmationModal, DangerButton, Field, Input, SecondaryButton, ThemeToggle } from '@/Components/Ui';
import { Conversation, Location as SalonLocation, PageProps, Salon, Service, User as AuthUser } from '@/types';
import { Bell, Bot, Building2, Calendar, Check, CheckCircle2, ChevronDown, ChevronLeft, ChevronRight, Clock, Download, ExternalLink, FileText, Globe2, LayoutDashboard, List, LogOut, MapPin, Menu, MessageCircle, MessageSquare, Pencil, Phone, Plus, QrCode, Save, Scissors, Search, Settings, Smartphone, Sparkles, Trash2, User, Users, Volume2, X, XCircle } from 'lucide-react';
import { FormEvent, useEffect, useMemo, useRef, useState } from 'react';
import { useT } from '@/i18n';

type Props = PageProps<{
  section: 'overview' | 'ai-settings' | 'conversations' | 'chat-audio' | 'voice-calls' | 'whatsapp' | 'locations' | 'services' | 'bookings' | 'settings';
  salon: Salon;
}>;

const nav = [
  { id: 'overview', label: 'overview', href: '/dashboard', icon: LayoutDashboard },
  { id: 'ai-settings', label: 'aiSettings', href: '/dashboard/ai-settings', icon: Sparkles, dividerAfter: true },
  { id: 'conversations', label: 'conversations', href: '/dashboard/conversations', icon: MessageSquare },
  { id: 'chat-audio', label: 'chatAudio', href: '/dashboard/chat-audio', icon: Volume2 },
  { id: 'voice-calls', label: 'voiceCalls', href: '/dashboard/voice-calls', icon: Phone },
  { id: 'whatsapp', label: 'whatsapp', href: '/dashboard/whatsapp', icon: MessageCircle, dividerAfter: true },
  { id: 'locations', label: 'locations', href: '/dashboard/locations', icon: MapPin },
  { id: 'services', label: 'services', href: '/dashboard/services', icon: Scissors },
  { id: 'bookings', label: 'bookings', href: '/dashboard/bookings', icon: Calendar },
];

export default function DashboardIndex() {
  const t = useT();
  const { auth, salon, section, locale } = usePage<Props>().props;
  const titleKey = section === 'locations'
    ? 'salonLocations'
    : nav.find((item) => item.id === section)?.label ?? section;
  const title = t(titleKey);
  const headerSubtitles: Partial<Record<Props['section'], string>> = {
    overview: t('overviewSubtitle'),
    'ai-settings': t('aiSettingsSubtitle'),
    conversations: t('conversationSubtitle'),
    'chat-audio': t('chatAudioSubtitle'),
    'voice-calls': t('voiceCallsSubtitle'),
    whatsapp: t('whatsappSubtitle'),
    locations: t('locationsSubtitle'),
    services: t('servicesSubtitle'),
    bookings: t('bookingsSubtitle'),
    settings: t('settingsSubtitle'),
  };
  const headerSubtitle = headerSubtitles[section] ?? '';
  const [mobileNavOpen, setMobileNavOpen] = useState(false);
  const [query, setQuery] = useState('');
  const activeLocale = locale === 'en' ? 'en' : 'ro';

  const searchSections: Partial<Record<Props['section'], string>> = {
    conversations: t('searchConversations'),
    'chat-audio': t('searchByPhoneEmailOrContent'),
    'voice-calls': t('searchByPhoneOrTranscript'),
    whatsapp: t('searchWhatsappConversations'),
    services: t('searchServices'),
    bookings: t('searchBookings'),
  };

  useEffect(() => { setQuery(''); }, [section]);

  const pollingRef = useRef<ReturnType<typeof setInterval> | null>(null);
  useEffect(() => {
    if (section !== 'conversations' && section !== 'overview') return;
    pollingRef.current = setInterval(() => {
      router.visit(window.location.href, {
        only: ['salon'],
        preserveScroll: true,
        preserveState: true,
        replace: true,
      });
    }, 5000);
    return () => { if (pollingRef.current) clearInterval(pollingRef.current); };
  }, [section]);

  function switchLanguage(displayLanguage: 'ro' | 'en') {
    if (displayLanguage === activeLocale || !auth.user) return;

    router.post('/settings', {
      name: auth.user.name,
      business_name: salon.name,
      timezone: salon.timezone ?? 'Europe/London',
      industry: salon.industry ?? '',
      country: salon.country ?? '',
      website: salon.website ?? '',
      business_phone: salon.business_phone ?? '',
      notification_email: salon.notification_email ?? '',
      email_notifications: Boolean(salon.email_notifications ?? true),
      missed_call_alerts: Boolean(salon.missed_call_alerts ?? true),
      booking_confirmations: Boolean(salon.booking_confirmations ?? true),
      display_language: displayLanguage,
      date_format: salon.date_format ?? 'DD/MM/YYYY',
    }, {
      preserveScroll: true,
    });
  }

  return (
    <div className="flex min-h-screen overflow-x-hidden app-bg">
      <Head title={title} />
      <DashboardSidebar salon={salon} section={section} user={auth.user} t={t} />

      {mobileNavOpen && (
        <div className="fixed inset-0 z-50 lg:hidden">
          <button
            type="button"
            aria-label="Close navigation"
            className="absolute inset-0 bg-black/50"
            onClick={() => setMobileNavOpen(false)}
          />
          <div className="relative flex h-full w-80 max-w-[86vw] flex-col app-sidebar shadow-2xl">
            <div className="flex items-center justify-between border-b border-white/10 p-4">
              <Brand salon={salon} onClick={() => setMobileNavOpen(false)} />
              <button
                type="button"
                aria-label="Close navigation"
                className="flex h-10 w-10 items-center justify-center rounded-lg text-slate-300 hover:bg-white/10 hover:text-white"
                onClick={() => setMobileNavOpen(false)}
              >
                <X className="h-5 w-5" />
              </button>
            </div>
            <DashboardSidebarContent salon={salon} section={section} user={auth.user} t={t} onNavigate={() => setMobileNavOpen(false)} />
          </div>
        </div>
      )}

      <main className="flex min-w-0 flex-1 flex-col lg:ml-72 lg:h-screen">
        <header className={`relative z-10 shrink-0 flex items-center justify-between gap-3 border-b px-4 app-border app-shell sm:px-5 lg:px-8 ${section === 'conversations' ? 'min-h-14 py-2 sm:min-h-16 sm:py-3' : 'min-h-16 py-3'}`}>
          <div className="flex min-w-0 items-center gap-3">
            <button
              type="button"
              aria-label="Open navigation"
              className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border app-panel app-text-soft lg:hidden"
              onClick={() => setMobileNavOpen(true)}
            >
              <Menu className="h-5 w-5" />
            </button>
            <div className="min-w-0">
              <h1 className="truncate text-lg font-black app-text">{title}</h1>
              {headerSubtitle && <p className="truncate text-xs font-semibold app-text-muted">{headerSubtitle}</p>}
            </div>
          </div>
          <div className="flex shrink-0 items-center gap-3">
            {searchSections[section] && <HeaderSearch query={query} onChange={setQuery} placeholder={searchSections[section]!} />}
            <ThemeToggle />
            <LanguageToggle locale={activeLocale} onChange={switchLanguage} />
          </div>
        </header>
        <div className={`min-w-0 flex-1 overflow-x-hidden ${section === 'conversations' ? 'overflow-hidden' : 'overflow-y-auto p-5 lg:p-8'}`}>
          {section === 'overview' && <Overview salon={salon} />}
          {section === 'ai-settings' && <AiSettingsPlaceholder />}
          {section === 'conversations' && <Conversations salon={salon} query={query} />}
          {section === 'chat-audio' && <ChatAudio salon={salon} query={query} />}
          {section === 'voice-calls' && <VoiceCalls query={query} />}
          {section === 'whatsapp' && <WhatsAppConversations query={query} />}
          {section === 'locations' && <Locations salon={salon} />}
          {section === 'services' && <Services salon={salon} query={query} />}
          {section === 'bookings' && <Bookings salon={salon} query={query} />}
          {section === 'settings' && <SettingsPage salon={salon} />}
        </div>
      </main>
    </div>
  );
}

function DashboardSidebar({ salon, section, user, t }: { salon: Salon; section: Props['section']; user: AuthUser | null; t: (key: string) => string }) {
  return (
    <aside className="fixed inset-y-0 left-0 z-40 hidden h-screen w-72 shrink-0 flex-col overflow-hidden app-sidebar lg:flex">
      <div className="shrink-0 border-b border-white/10 p-6">
        <Brand salon={salon} />
      </div>
      <DashboardSidebarContent salon={salon} section={section} user={user} t={t} />
    </aside>
  );
}

function Brand({ salon, onClick }: { salon: Salon; onClick?: () => void }) {
  return (
    <Link href="/" className="flex w-fit flex-col gap-3" onClick={onClick}>
      <img src="/images/logo-dark.png" className="h-12 w-auto shrink-0" alt="YouGo" />
      <div className="flex items-center gap-2.5">
        {salon.logo_path ? (
          <img src={`/storage/${salon.logo_path}`} className="h-7 w-7 shrink-0 rounded-md object-cover" alt={salon.name} />
        ) : (
          <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-white/10 text-xs font-black text-white">
            {salon.name.slice(0, 1).toUpperCase()}
          </span>
        )}
        <span className="truncate text-sm font-bold text-white">{salon.name}</span>
      </div>
    </Link>
  );
}

function DashboardSidebarContent({ salon, section, user, t, onNavigate }: { salon: Salon; section: Props['section']; user: AuthUser | null; t: (key: string) => string; onNavigate?: () => void }) {
  const [accountOpen, setAccountOpen] = useState(false);

  return (
    <>
      <nav className="min-h-0 flex-1 space-y-2 overflow-y-auto p-4">
        {nav.map((item) => {
          const Icon = item.icon;
          const active = item.id === section;
          return (
            <div key={item.id}>
              <Link
                href={item.href}
                onClick={onNavigate}
                className={`flex items-center gap-3 rounded-lg px-4 py-3 text-sm font-bold transition ${active ? 'bg-indigo-600 text-white' : 'text-slate-400 hover:bg-white/10 hover:text-white'}`}
              >
                <Icon className="h-4 w-4" />
                {t(item.label)}
              </Link>
              {item.dividerAfter && <div className="mx-4 my-2 h-px bg-white/10" />}
            </div>
          );
        })}
      </nav>
      <div className="shrink-0 border-t border-white/10 p-4">
        <Link href={`/assistant/${salon.id}`} onClick={onNavigate} className="mb-4 flex items-center justify-center gap-2 rounded-lg bg-white/10 px-4 py-3 text-sm font-bold text-white hover:bg-white/15">
          <ExternalLink className="h-4 w-4" />
          {t('previewPublicAi')}
        </Link>

        <div className="relative">
          {accountOpen && (
            <div className="absolute bottom-full left-0 right-0 mb-2 rounded-lg border border-white/10 bg-slate-950 p-1 shadow-2xl">
              <Link
                href="/dashboard/settings"
                onClick={onNavigate}
                className={`flex items-center gap-3 rounded-md px-3 py-2 text-sm font-bold transition ${section === 'settings' ? 'bg-indigo-600 text-white' : 'text-slate-300 hover:bg-white/10 hover:text-white'}`}
              >
                <Settings className="h-4 w-4" />
                {t('settings')}
              </Link>
              <button
                type="button"
                onClick={() => router.post('/logout')}
                className="flex w-full items-center gap-3 rounded-md px-3 py-2 text-sm font-bold text-slate-300 transition hover:bg-red-500/10 hover:text-red-300"
              >
                <LogOut className="h-4 w-4" />
                {t('logout')}
              </button>
            </div>
          )}

          <button
            type="button"
            onClick={() => setAccountOpen((open) => !open)}
            className="flex w-full items-center gap-3 rounded-lg px-4 py-3 text-left hover:bg-white/10"
          >
            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white/10 text-sm font-black text-white">
              {(user?.name ?? 'U').slice(0, 1).toUpperCase()}
            </span>
            <span className="min-w-0">
              <span className="block truncate text-sm font-black text-white">{user?.name}</span>
              <span className="block truncate text-xs text-slate-400">{user?.email}</span>
            </span>
          </button>
        </div>
      </div>
    </>
  );
}

function LanguageToggle({ locale, onChange }: { locale: 'ro' | 'en'; onChange: (locale: 'ro' | 'en') => void }) {
  const [open, setOpen] = useState(false);
  const languages = [
    { id: 'ro', label: 'RO', flag: '\u{1F1F7}\u{1F1F4}', title: 'Romana' },
    { id: 'en', label: 'EN', flag: '\u{1F1EC}\u{1F1E7}', title: 'English' },
  ] as const;
  const active = languages.find((item) => item.id === locale) ?? languages[0];

  return (
    <div className="relative" aria-label="Language switcher">
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        className="flex h-10 min-w-20 items-center justify-center gap-2 rounded-lg border px-3 text-xs font-black uppercase app-panel app-text-soft hover:bg-[var(--app-panel-soft)]"
        aria-expanded={open}
      >
        <span aria-hidden="true">{active.flag}</span>
        {active.label}
      </button>

      {open && (
        <div className="absolute right-0 top-12 z-50 w-36 rounded-lg border p-1 shadow-lg app-panel">
          {languages.map((item) => (
            <button
              key={item.id}
              type="button"
              onClick={() => {
                setOpen(false);
                onChange(item.id);
              }}
              className={`flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm font-bold transition ${locale === item.id ? 'bg-indigo-600 text-white' : 'app-text-soft hover:bg-[var(--app-panel-soft)]'}`}
              aria-pressed={locale === item.id}
              title={item.title}
            >
              <span aria-hidden="true">{item.flag}</span>
              {item.label}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

function HeaderSearch({ query, onChange, placeholder }: { query: string; onChange: (query: string) => void; placeholder: string }) {
  const [open, setOpen] = useState(false);

  return (
    <>
      <div className="relative hidden w-72 xl:block">
        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 app-text-muted" />
        <input
          className="h-10 w-full rounded-lg border pl-10 pr-4 text-sm outline-none placeholder:text-[var(--app-text-muted)] focus:border-indigo-500 app-panel"
          value={query}
          onChange={(event) => onChange(event.target.value)}
          placeholder={placeholder}
        />
      </div>

      <button
        type="button"
        aria-label={placeholder}
        onClick={() => setOpen(true)}
        className="flex h-10 w-10 items-center justify-center rounded-lg border app-panel app-text-soft hover:bg-[var(--app-panel-soft)] xl:hidden"
      >
        <Search className="h-4 w-4" />
      </button>

      {open && (
        <div className="fixed inset-0 z-50 flex items-start justify-center bg-black/50 px-4 pt-24 xl:hidden">
          <div className="w-full max-w-md rounded-lg border p-4 shadow-2xl app-panel">
            <div className="mb-3 flex items-center justify-between gap-3">
              <p className="text-sm font-black app-text">{placeholder}</p>
              <button
                type="button"
                aria-label="Close search"
                onClick={() => setOpen(false)}
                className="flex h-9 w-9 items-center justify-center rounded-lg app-text-soft hover:bg-[var(--app-panel-soft)]"
              >
                <X className="h-4 w-4" />
              </button>
            </div>
            <div className="relative">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 app-text-muted" />
              <input
                autoFocus
                className="h-11 w-full rounded-lg border pl-10 pr-4 text-sm outline-none placeholder:text-[var(--app-text-muted)] focus:border-indigo-500 app-panel"
                value={query}
                onChange={(event) => onChange(event.target.value)}
                placeholder={placeholder}
              />
            </div>
          </div>
        </div>
      )}
    </>
  );
}

function SettingsPage({ salon }: { salon: Salon }) {
  const t = useT();
  const { auth } = usePage<Props>().props;
  const form = useForm({
    name: auth.user?.name ?? '',
    business_name: salon.name ?? '',
    timezone: salon.timezone ?? 'Europe/London',
    industry: salon.industry ?? '',
    country: salon.country ?? '',
    website: salon.website ?? '',
    business_phone: salon.business_phone ?? '',
    notification_email: salon.notification_email ?? '',
    email_notifications: salon.email_notifications ?? true,
    missed_call_alerts: salon.missed_call_alerts ?? true,
    booking_confirmations: salon.booking_confirmations ?? true,
    display_language: salon.display_language ?? 'ro',
    date_format: salon.date_format ?? 'DD/MM/YYYY',
    logo: null as File | null,
  });

  function submit(event: FormEvent) {
    event.preventDefault();
    form.post('/settings', { forceFormData: true, preserveScroll: true });
  }

  return (
    <form onSubmit={submit} className="-m-5 min-h-[calc(100vh-4rem)] p-5 app-bg lg:-m-8 lg:p-8">
      <div className="space-y-6">
        <SettingsPanel icon={User} title={t('profile')} subtitle={t('profileSubtitle')}>
          <div className="grid gap-4 md:grid-cols-2">
            <DarkField label={t('fullName')} error={form.errors.name}>
              <DarkInput value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} />
            </DarkField>
            <DarkField label="Email">
              <DarkInput value={auth.user?.email ?? ''} disabled />
            </DarkField>
          </div>
        </SettingsPanel>

        <SettingsPanel icon={Building2} title={t('organization')} subtitle={t('organizationSubtitle')}>
          <div className="mb-6">
            <p className="mb-3 text-sm font-black">{t('businessLogo')}</p>
            <div className="flex items-center gap-4">
              <div className="flex h-16 w-16 items-center justify-center rounded-lg bg-slate-800 text-slate-400">
                <Building2 className="h-8 w-8" />
              </div>
              <label className="inline-flex h-10 cursor-pointer items-center rounded-lg border border-slate-700 px-4 text-sm font-black hover:bg-slate-900">
                {t('uploadLogo')}
                <input className="hidden" type="file" accept=".png,.jpg,.jpeg,.svg" onChange={(event) => form.setData('logo', event.target.files?.[0] ?? null)} />
              </label>
            </div>
            <p className="mt-2 text-xs text-sky-300">{t('logoHint')}</p>
          </div>

          <div className="grid gap-4 md:grid-cols-2">
            <DarkField label={t('businessName')} error={form.errors.business_name}>
              <DarkInput value={form.data.business_name} onChange={(event) => form.setData('business_name', event.target.value)} />
            </DarkField>
            <DarkField label={t('timezone')} error={form.errors.timezone}>
              <DarkSelect value={form.data.timezone} onChange={(event) => form.setData('timezone', event.target.value)}>
                <option value="Europe/London">London (GMT/BST)</option>
                <option value="Europe/Bucharest">Bucharest (EET/EEST)</option>
                <option value="Europe/Berlin">Berlin (CET/CEST)</option>
              </DarkSelect>
            </DarkField>
            <DarkField label={t('industry')} error={form.errors.industry}>
              <DarkSelect value={form.data.industry} onChange={(event) => form.setData('industry', event.target.value)}>
                <option value="">{t('selectIndustry')}</option>
                <option value="Beauty Salon">Beauty Salon</option>
                <option value="Legal Services">Legal Services</option>
                <option value="Medical Clinic">Medical Clinic</option>
                <option value="Consulting">Consulting</option>
              </DarkSelect>
            </DarkField>
            <DarkField label={t('country')} error={form.errors.country}>
              <DarkInput maxLength={2} value={form.data.country} onChange={(event) => form.setData('country', event.target.value.toUpperCase())} placeholder="RO" />
            </DarkField>
            <DarkField label={t('website')} error={form.errors.website}>
              <DarkInput value={form.data.website} onChange={(event) => form.setData('website', event.target.value)} placeholder="https://example.com" />
            </DarkField>
            <DarkField label={t('businessPhone')} error={form.errors.business_phone}>
              <DarkInput value={form.data.business_phone} onChange={(event) => form.setData('business_phone', event.target.value)} placeholder="+40..." />
            </DarkField>
          </div>
        </SettingsPanel>

        <SettingsPanel icon={Bell} title={t('notifications')} subtitle={t('notificationsSubtitle')}>
          <DarkField label={t('notificationEmail')} error={form.errors.notification_email}>
            <DarkInput value={form.data.notification_email} onChange={(event) => form.setData('notification_email', event.target.value)} placeholder={t('notificationEmailPlaceholder')} />
          </DarkField>
          <p className="mt-2 text-sm text-sky-300">{t('notificationEmailHelp')}</p>
          <div className="mt-7 divide-y divide-slate-800">
            <ToggleRow title={t('emailNotifications')} subtitle={t('emailNotificationsHelp')} checked={form.data.email_notifications} onChange={(checked) => form.setData('email_notifications', checked)} />
            <ToggleRow title={t('missedCallAlerts')} subtitle={t('missedCallAlertsHelp')} checked={form.data.missed_call_alerts} onChange={(checked) => form.setData('missed_call_alerts', checked)} />
            <ToggleRow title={t('bookingConfirmations')} subtitle={t('bookingConfirmationsHelp')} checked={form.data.booking_confirmations} onChange={(checked) => form.setData('booking_confirmations', checked)} />
          </div>
        </SettingsPanel>

        <SettingsPanel icon={Globe2} title={t('languageRegion')} subtitle={t('languageRegionSubtitle')}>
          <div className="grid gap-4 md:grid-cols-2">
            <DarkField label={t('displayLanguage')} error={form.errors.display_language}>
              <DarkSelect value={form.data.display_language} onChange={(event) => form.setData('display_language', event.target.value)}>
                <option value="ro">RO Romana</option>
                <option value="en">EN English</option>
              </DarkSelect>
            </DarkField>
            <DarkField label={t('dateFormat')} error={form.errors.date_format}>
              <DarkInput value={form.data.date_format} onChange={(event) => form.setData('date_format', event.target.value)} />
            </DarkField>
          </div>
        </SettingsPanel>

        <SettingsPanel icon={Bot} title={t('integrations')} subtitle={t('integrationsSubtitle')}>
          <div className="divide-y divide-slate-800">
            <IntegrationRow icon={Bot} title={t('voiceAi')} subtitle={t('automatedCalls')} />
            <IntegrationRow icon={MessageCircle} title={t('chat')} subtitle={t('websiteAssistant')} />
          </div>
        </SettingsPanel>
      </div>

      <div className="mt-6 flex justify-end">
        <button disabled={form.processing} className="inline-flex h-11 items-center gap-2 rounded-lg bg-blue-600 px-5 text-sm font-black text-white hover:bg-blue-700 disabled:opacity-60">
          <Save className="h-4 w-4" />
          {t('saveChanges')}
        </button>
      </div>
    </form>
  );
}

function SettingsPanel({ icon: Icon, title, subtitle, children }: { icon: any; title: string; subtitle: string; children: React.ReactNode }) {
  return (
    <section className="rounded-lg border p-6 app-panel">
      <div className="mb-7 flex items-start gap-3">
        <Icon className="mt-1 h-5 w-5 app-text" />
        <div>
          <h3 className="text-2xl font-black app-text">{title}</h3>
          <p className="mt-1 text-sm app-text-muted">{subtitle}</p>
        </div>
      </div>
      {children}
    </section>
  );
}

function DarkField({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
  return (
    <label className="block">
      <span className="mb-2 block text-sm font-black app-text">{label}</span>
      {children}
      {error && <span className="mt-1 block text-xs font-bold text-red-400">{error}</span>}
    </label>
  );
}

function DarkInput(props: React.InputHTMLAttributes<HTMLInputElement>) {
  return <input {...props} className={`h-10 w-full rounded-lg border px-3 text-sm font-semibold outline-none placeholder:text-[var(--app-text-muted)] focus:border-blue-500 disabled:opacity-60 app-panel ${props.className ?? ''}`} />;
}

function DarkSelect(props: React.SelectHTMLAttributes<HTMLSelectElement>) {
  return <select {...props} className={`h-10 w-full rounded-lg border px-3 text-sm font-semibold outline-none focus:border-blue-500 app-panel ${props.className ?? ''}`} />;
}

function ToggleRow({ title, subtitle, checked, onChange }: { title: string; subtitle: string; checked: boolean; onChange: (checked: boolean) => void }) {
  return (
    <div className="flex items-center justify-between gap-4 py-7">
      <div>
        <p className="font-black app-text">{title}</p>
        <p className="mt-1 text-sm app-text-muted">{subtitle}</p>
      </div>
      <button type="button" onClick={() => onChange(!checked)} className={`relative h-6 w-11 rounded-full transition ${checked ? 'bg-blue-600' : 'bg-slate-700'}`}>
        <span className={`absolute top-1 h-4 w-4 rounded-full bg-white transition ${checked ? 'left-6' : 'left-1'}`} />
      </button>
    </div>
  );
}

function IntegrationRow({ icon: Icon, title, subtitle }: { icon: any; title: string; subtitle: string }) {
  const t = useT();
  return (
    <div className="flex items-center justify-between gap-4 py-5">
      <div className="flex items-center gap-4">
        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-100 text-slate-950">
          <Icon className="h-5 w-5" />
        </div>
        <div>
          <p className="font-black app-text">{title}</p>
          <p className="text-sm app-text-muted">{subtitle}</p>
        </div>
      </div>
      <span className="rounded-full bg-green-100 px-3 py-1 text-xs font-black text-green-800">{t('connected')}</span>
    </div>
  );
}

function Conversations({ salon, query }: { salon: Salon; query: string }) {
  const t = useT();
  const [selectedId, setSelectedId] = useState(salon.conversations[0]?.id ?? null);
  const [deletingId, setDeletingId] = useState<number | null>(null);
  const [channelFilter, setChannelFilter] = useState<'all' | 'voice' | 'chat' | 'whatsapp'>('all');
  const conversations = salon.conversations.filter((conversation) => {
    if (channelFilter !== 'all' && (conversation.channel as string) !== channelFilter) return false;

    const haystack = [
      conversation.contact_name,
      conversation.contact_phone,
      conversation.contact_email,
      conversation.summary,
      conversation.messages.at(-1)?.content,
    ].filter(Boolean).join(' ').toLowerCase();

    return haystack.includes(query.toLowerCase());
  });
  const selected = conversations.find((conversation) => conversation.id === selectedId) ?? conversations[0] ?? null;
  const emptyTitle = channelFilter === 'voice'
    ? t('noVoiceCallsFound')
    : channelFilter === 'whatsapp'
      ? t('noWhatsappConversationsFound')
      : t('noConversations');
  const emptyHelp = channelFilter === 'all' ? t('noConversationsHelp') : t('noFilteredConversationsHelp');

  return (
    <>
    <ConfirmationModal
      open={deletingId !== null}
      title={t('deleteConversation')}
      message={t('deleteConversationConfirm')}
      confirmLabel={t('delete')}
      cancelLabel={t('cancel')}
      onCancel={() => setDeletingId(null)}
      onConfirm={() => {
        if (!deletingId) return;
        router.delete(`/conversations/${deletingId}`, { preserveScroll: true, onSuccess: () => setDeletingId(null) });
      }}
    />
    <div className="flex h-full min-w-0 flex-col overflow-hidden app-bg">
      <div className="shrink-0 border-b p-3 app-border app-shell">
        <div className="flex flex-wrap gap-2 rounded-lg border p-4 app-panel">
          <ConversationFilterButton active={channelFilter === 'voice'} onClick={() => setChannelFilter('voice')} icon={Phone}>{t('phoneCalls')}</ConversationFilterButton>
          <ConversationFilterButton active={channelFilter === 'chat'} onClick={() => setChannelFilter('chat')} icon={MessageSquare}>{t('chat')}</ConversationFilterButton>
          <ConversationFilterButton active={channelFilter === 'whatsapp'} onClick={() => setChannelFilter('whatsapp')} icon={MessageCircle}>{t('whatsapp')}</ConversationFilterButton>
        </div>
      </div>
      {selected ? (
        <div className="grid min-h-0 flex-1 overflow-hidden lg:grid-cols-[320px_minmax(0,1fr)_360px]">
          <aside className="min-h-0 overflow-y-auto border-b app-border app-panel-soft lg:border-b-0 lg:border-r">
            <div className="px-4 py-3 lg:p-4">
              <p className="mb-3 text-xs font-black uppercase tracking-wide app-text-muted">{conversations.length} {t('conversations')}</p>
              <div className="space-y-2">
                {conversations.map((conversation) => {
                  const active = conversation.id === selected.id;
                  const lastMessage = conversation.messages.at(-1)?.content ?? 'Conversatie fara mesaje.';
                  return (
                    <div
                      key={conversation.id}
                      className={`group relative rounded-lg border p-4 transition ${active ? 'border-indigo-500 bg-indigo-600/15' : 'app-panel hover:bg-[var(--app-panel-soft)]'}`}
                    >
                      <div className="flex items-start justify-between gap-2">
                        <button
                          type="button"
                          onClick={() => setSelectedId(conversation.id)}
                          className="min-w-0 flex-1 text-left"
                        >
                          <p className="truncate text-sm font-black app-text">{conversationTitle(conversation, t)}</p>
                          <p className="mt-1 truncate text-xs app-text-muted">{lastMessage}</p>
                          <div className="mt-2">
                            <IntentPill intent={conversation.intent} compact bookingStatus={conversation.booking?.status} />
                          </div>
                        </button>
                        <button
                          type="button"
                          onClick={() => setDeletingId(conversation.id)}
                          className="shrink-0 opacity-0 group-hover:opacity-100 transition-opacity text-slate-400 hover:text-red-500 mt-0.5"
                          title={t('deleteConversation')}
                        >
                          <Trash2 className="h-3.5 w-3.5" />
                        </button>
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          </aside>

          <section className="flex min-h-0 min-w-0 flex-col overflow-y-auto p-4 sm:p-5 lg:p-8">
            <div className="mb-5 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between lg:mb-6">
              <div className="flex min-w-0 items-center gap-3 sm:gap-4">
                <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-blue-100 text-blue-700 sm:h-12 sm:w-12">
                  {selected.channel === 'voice' ? <Phone className="h-5 w-5" /> : <MessageSquare className="h-5 w-5" />}
                </div>
                <div className="min-w-0">
                  <h3 className="truncate text-xl font-black app-text sm:text-2xl">{selected.channel === 'voice' ? t('voiceCall') : t('chatConversation')}</h3>
                  <p className="text-sm app-text-muted">{conversationTitle(selected, t)}</p>
                </div>
              </div>
              <div className="flex flex-wrap gap-2">
                <IntentPill intent={selected.intent} bookingStatus={selected.booking?.status} />
              </div>
            </div>

            <DarkPanel className="mt-6 flex min-h-0 flex-1 flex-col">
              <div className="mb-6 flex items-center gap-2 text-lg font-black app-text">
                <FileText className="h-5 w-5" />
                {t('transcript')}
              </div>
              <div className="min-h-0 flex-1 space-y-5 overflow-y-auto pr-2">
                {selected.messages.map((message) => (
                  <div key={message.id} className={`flex items-start gap-2 sm:gap-3 ${message.role === 'user' ? 'justify-end' : 'justify-start'}`}>
                    {message.role === 'assistant' && <Avatar label="AI" />}
                    <div className={`max-w-[calc(100%-3rem)] break-words rounded-lg px-4 py-3 text-sm leading-6 sm:max-w-[78%] ${message.role === 'assistant' ? 'app-panel-soft' : 'chat-bubble-user'}`}>
                      <InlineMarkdown text={message.content} />
                    </div>
                    {message.role === 'user' && <Avatar label="C" muted />}
                  </div>
                ))}
              </div>
            </DarkPanel>
          </section>

          <aside className="min-h-0 space-y-4 overflow-y-auto border-t p-4 app-border app-panel-soft sm:p-5 lg:space-y-6 lg:border-l lg:border-t-0 lg:p-8">
            <DarkPanel>
              <h3 className="mb-6 text-lg font-black app-text">{t('summary')}</h3>
              <p className="text-sm leading-6 app-text-soft">{conversationSummary(selected, t)}</p>
            </DarkPanel>
            <DarkPanel>
              <h3 className="mb-6 flex items-center gap-2 text-lg font-black app-text"><FileText className="h-5 w-5" /> {t('details')}</h3>
              <Detail icon={Calendar} label={t('dateAndTime')} value={formatDate(selected.last_message_at || selected.created_at, salon.timezone)} />
              <Detail icon={Clock} label={t('duration')} value={formatDuration(selected.duration_seconds)} />
              <Detail icon={User} label={t('contact')} value={conversationTitle(selected, t)} />
            </DarkPanel>
            <DarkPanel>
              <h3 className="mb-6 flex items-center gap-2 text-lg font-black app-text"><Phone className="h-5 w-5" /> {t('voiceAgent')}</h3>
              <Detail icon={Bot} label={t('agent')} value="Bella Romania Line" />
              <Detail icon={Phone} label={t('businessPhone')} value={salon.locations[0]?.phone || '+40 000 000 000'} />
            </DarkPanel>
          </aside>
        </div>
      ) : (
        <div className="flex min-h-[520px] items-center justify-center p-8 text-center">
          <div>
            <MessageSquare className="mx-auto mb-4 h-10 w-10 text-slate-700" />
            <h3 className="text-xl font-black app-text">{emptyTitle}</h3>
            <p className="mt-2 text-sm app-text-muted">{emptyHelp}</p>
          </div>
        </div>
      )}
    </div>
    </>
  );
}

function conversationTitle(conversation: Conversation, t?: (key: string, params?: Record<string, string | number>) => string) {
  const num = conversation.visitor_number ?? conversation.id;
  return conversation.contact_name || conversation.contact_phone || conversation.contact_email || (t ? t('visitorLabel', { id: num }) : `Visitor #${num}`);
}

function ConversationFilterButton({ active, onClick, icon: Icon, children }: { active: boolean; onClick: () => void; icon?: any; children: React.ReactNode }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`inline-flex h-9 items-center justify-center gap-2 rounded-lg border px-3 text-sm font-bold transition ${active ? 'border-indigo-500 bg-indigo-600 text-white' : 'app-panel app-text-soft hover:bg-[var(--app-panel-soft)]'}`}
    >
      {Icon && <Icon className="h-4 w-4" />}
      {children}
    </button>
  );
}

function conversationSummary(conversation: Conversation, t: (key: string) => string) {
  const status = conversation.booking?.status;

  if (status === 'pending') return t('bookingSummaryPending');
  if (status === 'confirmed') return t('bookingSummaryConfirmed');
  if (status === 'cancelled') return t('bookingSummaryCancelled');
  if (status === 'completed') return t('bookingSummaryCompleted');

  return conversation.summary || t('noSummary');
}

function IntentPill({ intent, compact = false, bookingStatus }: { intent: string; compact?: boolean; bookingStatus?: string }) {
  const t = useT();
  const tones: Record<string, string> = {
    booking: 'bg-green-100 text-green-800 dark:bg-green-500/15 dark:text-green-300',
    inquiry: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    abandoned: 'bg-slate-100 text-slate-500 dark:bg-white/10 dark:text-slate-400',
  };
  const tone = tones[intent] ?? 'bg-slate-100 text-slate-700 dark:bg-white/10 dark:text-slate-300';
  const labels: Record<string, string> = {
    booking: t('intentBooking'),
    inquiry: t('intentInquiry'),
    abandoned: t('intentAbandoned'),
  };
  const statusTones: Record<string, string> = {
    pending: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    confirmed: 'bg-green-700 text-white dark:bg-green-700 dark:text-white',
    programat: 'bg-green-700 text-white dark:bg-green-700 dark:text-white',
    cancelled: 'bg-red-100 text-red-800 dark:bg-red-500/15 dark:text-red-300',
    completed: 'bg-slate-100 text-slate-700 dark:bg-white/10 dark:text-slate-300',
  };
  const statusLabels: Record<string, string> = {
    pending: t('statusPending'),
    confirmed: t('statusConfirmed'),
    programat: t('statusScheduled'),
    cancelled: t('statusCancelled'),
    completed: t('statusCompleted'),
  };

  return (
    <div className="flex items-center gap-1.5">
      <span className={`inline-flex justify-center rounded-full font-black uppercase tracking-wide ${compact ? 'min-w-20 px-2 py-1 text-[10px]' : 'min-w-24 px-3 py-1 text-xs'} ${tone}`}>
        {labels[intent] ?? t('intentUnknown')}
      </span>
      {intent === 'booking' && bookingStatus && (
        <span className={`inline-flex items-center gap-1.5 justify-center rounded-full font-black uppercase tracking-wide ${compact ? 'min-w-20 px-2 py-1 text-[10px]' : 'min-w-24 px-3 py-1 text-xs'} ${statusTones[bookingStatus] ?? statusTones.completed}`}>
          {bookingStatus === 'pending' && <span className="railway-lights shrink-0" aria-hidden="true" />}
          {statusLabels[bookingStatus] ?? bookingStatus}
        </span>
      )}
    </div>
  );
}

function StatusPill({ status, t, className = '' }: { status: string; t: (key: string) => string; className?: string }) {
  const tones: Record<string, string> = {
    pending: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    confirmed: 'bg-green-700 text-white dark:bg-green-700 dark:text-white',
    programat: 'bg-green-700 text-white dark:bg-green-700 dark:text-white',
    cancelled: 'bg-red-100 text-red-800 dark:bg-red-500/15 dark:text-red-300',
    completed: 'bg-slate-100 text-slate-700 dark:bg-white/10 dark:text-slate-300',
    open: 'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-300',
  };
  const labels: Record<string, string> = {
    pending: t('statusPending'),
    confirmed: t('statusConfirmed'),
    programat: t('statusScheduled'),
    cancelled: t('statusCancelled'),
    completed: t('statusCompleted'),
    open: t('intentInquiry'),
  };

  return (
    <span className={`inline-flex h-8 min-w-32 items-center justify-center gap-1.5 whitespace-nowrap rounded-full px-3 text-xs font-black uppercase tracking-wide ${tones[status] ?? tones.completed} ${className}`}>
      {status === 'pending' && <span className="railway-lights shrink-0" aria-hidden="true" />}
      {labels[status] ?? status}
    </span>
  );
}

function formatDate(value?: string | null, timezone?: string | null) {
  if (!value) return 'N/A';
  return new Intl.DateTimeFormat('ro-RO', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: timezone || undefined,
  }).format(new Date(value));
}

function formatDuration(seconds?: number | null) {
  if (!seconds) return '0:00';
  const minutes = Math.floor(seconds / 60);
  const rest = seconds % 60;
  return `${minutes}:${String(rest).padStart(2, '0')}`;
}

function DarkPanel({ children, className = '' }: { children: React.ReactNode; className?: string }) {
  return <div className={`rounded-lg border p-5 shadow-sm app-panel ${className}`}>{children}</div>;
}

function Avatar({ label, muted = false }: { label: string; muted?: boolean }) {
  return <div className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-black ${muted ? 'app-panel-soft app-text-soft' : 'bg-blue-600 text-white'}`}>{label}</div>;
}

function Detail({ icon: Icon, label, value }: { icon: any; label: string; value: string }) {
  return (
    <div className="mb-5 flex gap-3">
      <Icon className="mt-1 h-4 w-4 shrink-0 app-text-muted" />
      <div>
        <p className="text-xs app-text-muted">{label}</p>
        <p className="text-sm font-black app-text">{value}</p>
      </div>
    </div>
  );
}

function InlineMarkdown({ text }: { text: string }) {
  return <>{text.replaceAll('**', '')}</>;
}

function buildActivityChart(conversations: Conversation[], range: 'week' | 'month') {
  const today = new Date();
  const start = range === 'week'
    ? startOfWeek(today)
    : new Date(today.getFullYear(), today.getMonth(), 1);
  const days = range === 'week'
    ? 7
    : new Date(today.getFullYear(), today.getMonth() + 1, 0).getDate();
  const labels = range === 'week'
    ? ['Lun', 'Mar', 'Mie', 'Joi', 'Vin', 'Sam', 'Dum']
    : Array.from({ length: days }, (_, index) => String(index + 1));

  const rows = Array.from({ length: days }, (_, index) => {
    const date = new Date(start);
    date.setDate(start.getDate() + index);

    return {
      dateKey: toDateKey(date),
      label: labels[index],
      phoneDone: 0,
      chatWhatsDone: 0,
      chatAudioDone: 0,
      inProgress: 0,
      abandoned: 0,
    };
  });
  const byDate = new Map(rows.map((row) => [row.dateKey, row]));

  [...conversations]
    .sort((a, b) => getConversationDate(a).getTime() - getConversationDate(b).getTime())
    .forEach((conversation) => {
      const row = byDate.get(toDateKey(getConversationDate(conversation)));
      if (!row) return;

      if (conversation.intent === 'abandoned') {
        row.abandoned += 1;
        return;
      }

      if (conversation.status === 'open') {
        row.inProgress += 1;
        return;
      }

      if (conversation.channel === 'voice') {
        row.phoneDone += 1;
        return;
      }

      if ((conversation.channel as string) === 'whatsapp') {
        row.chatWhatsDone += 1;
        return;
      }

      row.chatAudioDone += 1;
    });

  return rows;
}

function startOfWeek(date: Date) {
  const start = new Date(date.getFullYear(), date.getMonth(), date.getDate());
  const offset = (start.getDay() + 6) % 7;
  start.setDate(start.getDate() - offset);
  return start;
}

function getConversationDate(conversation: Conversation) {
  return new Date(conversation.last_message_at || conversation.created_at || Date.now());
}

function activitySeriesLabels(t: (key: string) => string): Record<string, string> {
  return {
    phoneDone: t('phoneCalls'),
    chatWhatsDone: t('chatWhats'),
    chatAudioDone: t('chatAudio'),
    inProgress: t('inProgress'),
    abandoned: t('intentAbandoned'),
  };
}

function ActivityLegendItem({ color, label }: { color: string; label: string }) {
  return (
    <span className="inline-flex items-center gap-2">
      <span className="h-3 w-3 rounded-sm" style={{ backgroundColor: color }} />
      {label}
    </span>
  );
}

function Overview({ salon }: { salon: Salon }) {
  const t = useT();
  const [activityRange, setActivityRange] = useState<'week' | 'month'>('week');
  const stats = useMemo(() => ({
    total: salon.bookings.length,
    pending: salon.bookings.filter((item) => item.status === 'pending').length,
    confirmed: salon.bookings.filter((item) => item.status === 'confirmed').length,
    completed: salon.bookings.filter((item) => item.status === 'completed').length,
  }), [salon.bookings]);

  const chart = useMemo(() => buildActivityChart(salon.conversations, activityRange), [salon.conversations, activityRange]);

  return (
    <div className="space-y-6">
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <Stat label={t('totalBookings')} value={stats.total} icon={Calendar} />
        <Stat label={t('pendingRequests')} value={stats.pending} icon={Users} tone="amber" />
        <Stat label={t('confirmed')} value={stats.confirmed} icon={CheckCircle2} tone="green" />
        <Stat label={t('servicesActive')} value={salon.services.length} icon={Scissors} />
      </div>
      <div className="grid gap-6 xl:grid-cols-[1.6fr_1fr]">
        <Card className="p-5">
          <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
            <h2 className="text-xs font-black uppercase tracking-wide app-text-muted">{t('activityReport')}</h2>
            <div className="inline-flex rounded-lg border p-1 app-panel">
              <button
                type="button"
                onClick={() => setActivityRange('week')}
                className={`h-8 rounded-md px-3 text-xs font-black transition ${activityRange === 'week' ? 'bg-indigo-600 text-white' : 'app-text-muted hover:bg-[var(--app-panel-soft)]'}`}
              >
                {t('week')}
              </button>
              <button
                type="button"
                onClick={() => setActivityRange('month')}
                className={`h-8 rounded-md px-3 text-xs font-black transition ${activityRange === 'month' ? 'bg-indigo-600 text-white' : 'app-text-muted hover:bg-[var(--app-panel-soft)]'}`}
              >
                {t('month')}
              </button>
            </div>
          </div>
          <div className="h-72">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={chart}>
                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#e2e8f0" />
                <XAxis dataKey="label" tickLine={false} axisLine={false} interval={activityRange === 'month' ? 2 : 0} />
                <YAxis tickLine={false} axisLine={false} allowDecimals={false} />
                <Tooltip formatter={(value, name) => [value, activitySeriesLabels(t)[String(name)] ?? name]} />
                <Bar dataKey="phoneDone" stackId="activity" fill="#2563eb" radius={[4, 4, 0, 0]} />
                <Bar dataKey="chatWhatsDone" stackId="activity" fill="#16a34a" radius={[4, 4, 0, 0]} />
                <Bar dataKey="chatAudioDone" stackId="activity" fill="#7c3aed" radius={[4, 4, 0, 0]} />
                <Bar dataKey="inProgress" stackId="activity" fill="#f59e0b" radius={[4, 4, 0, 0]} />
                <Bar dataKey="abandoned" stackId="activity" fill="#94a3b8" radius={[4, 4, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>
          <div className="mt-5 flex flex-wrap gap-x-5 gap-y-2 text-xs font-bold app-text-muted">
            <ActivityLegendItem color="#2563eb" label={t('phoneCalls')} />
            <ActivityLegendItem color="#16a34a" label={t('chatWhats')} />
            <ActivityLegendItem color="#7c3aed" label={t('chatAudio')} />
            <ActivityLegendItem color="#f59e0b" label={t('inProgress')} />
            <ActivityLegendItem color="#94a3b8" label={t('intentAbandoned')} />
          </div>
        </Card>
        <Card className="p-5">
          <h2 className="mb-4 text-xs font-black uppercase tracking-wide app-accent-text">{t('assistantLive')}</h2>
          <p className="text-2xl font-black app-text">{t('bellaOnline')}</p>
          <p className="mt-2 text-sm app-text-soft">{t('mysqlPublicPage')}</p>
          <div className="mt-6 rounded-lg app-soft-tint">
            <p className="px-4 pt-4 pb-2 text-xs font-bold uppercase app-accent-text">{t('lastInteraction')}</p>
            {salon.conversations.length === 0 ? (
              <p className="px-4 pb-4 text-sm app-subtle-text">{t('noRecentBooking')}</p>
            ) : (
              <ul className="divide-y app-border">
                {[...salon.conversations]
                  .sort((a, b) => (b.last_message_at ?? b.created_at ?? '').localeCompare(a.last_message_at ?? a.created_at ?? ''))
                  .slice(0, 5)
                  .map((conv) => {
                    const name = conv.contact_name ?? `Vizitator #${conv.visitor_number ?? conv.id}`;
                    const label = conv.intent === 'booking' ? t('madeBooking', { name }) : t('justAsked', { name });
                    return (
                      <li key={conv.id} className="flex items-center justify-between gap-3 px-4 py-2.5">
                        <span className="text-sm app-subtle-text">{label}</span>
                        {conv.booking && (
                          <StatusPill status={conv.booking.status} t={t} className="min-w-0 px-2 py-0.5 text-[10px]" />
                        )}
                      </li>
                    );
                  })}
              </ul>
            )}
          </div>
        </Card>
      </div>
    </div>
  );
}

function Stat({ label, value, icon: Icon, tone = 'indigo' }: { label: string; value: number; icon: any; tone?: 'indigo' | 'amber' | 'green' }) {
  const colors = {
    indigo: 'bg-indigo-50 text-indigo-700',
    amber: 'bg-amber-50 text-amber-700',
    green: 'bg-green-50 text-green-700',
  };
  return (
    <Card className="p-5">
      <div className={`mb-4 flex h-10 w-10 items-center justify-center rounded-lg ${colors[tone]}`}>
        <Icon className="h-5 w-5" />
      </div>
      <p className="text-xs font-black uppercase tracking-wide app-text-muted">{label}</p>
      <p className="mt-1 text-3xl font-black app-text">{value}</p>
    </Card>
  );
}

function AiSettingsPlaceholder() {
  const t = useT();

  return (
    <Card className="min-h-64 p-6">
      <div className="flex min-h-52 items-center justify-center text-center">
        <div>
          <Sparkles className="mx-auto mb-4 h-10 w-10 text-indigo-500" />
          <h2 className="text-xl font-black app-text">{t('aiSettings')}</h2>
          <p className="mt-2 text-sm app-text-muted">{t('aiSettingsEmpty')}</p>
        </div>
      </div>
    </Card>
  );
}

function Locations({ salon }: { salon: Salon }) {
  const t = useT();
  const [adding, setAdding] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [confirmation, setConfirmation] = useState<{ title: string; message: string; tone?: 'danger' | 'neutral'; onConfirm: () => void } | null>(null);
  const defaultHours: Record<string, string> = {
    mon: '09:00 - 18:00',
    tue: '09:00 - 18:00',
    wed: '09:00 - 18:00',
    thu: '09:00 - 18:00',
    fri: '09:00 - 18:00',
    sat: '10:00 - 14:00',
    sun: 'Inchis',
  };
  const form = useForm({
    name: '',
    address: '',
    email: '',
    phone: '',
    hours: defaultHours,
  });
  const editForm = useForm({
    name: '',
    address: '',
    email: '',
    phone: '',
    hours: defaultHours,
  });

  function submit(event: FormEvent) {
    event.preventDefault();
    form.post('/locations', {
      preserveScroll: true,
      onSuccess: () => {
        form.reset();
        setAdding(false);
      },
    });
  }

  function startEdit(location: SalonLocation) {
    setEditingId(location.id);
    editForm.setData({
      name: location.name,
      address: location.address,
      email: location.email ?? '',
      phone: location.phone ?? '',
      hours: { ...defaultHours, ...(location.hours ?? {}) },
    });
  }

  function submitEdit(event: FormEvent) {
    event.preventDefault();
    if (!editingId) return;

    editForm.put(`/locations/${editingId}`, {
      preserveScroll: true,
      onSuccess: () => setEditingId(null),
    });
  }

  return (
    <div className="space-y-6">
      <ConfirmationModal
        open={confirmation !== null}
        title={confirmation?.title ?? ''}
        message={confirmation?.message ?? ''}
        confirmLabel={confirmation?.tone === 'neutral' ? t('confirm') : t('delete')}
        cancelLabel={t('cancel')}
        tone={confirmation?.tone ?? 'danger'}
        onCancel={() => setConfirmation(null)}
        onConfirm={() => {
          if (!confirmation) return;
          confirmation.onConfirm();
          setConfirmation(null);
        }}
      />
      <Toolbar title={t('salonLocations')} subtitle={t('locationsSubtitle')} hideText action={<Button onClick={() => setAdding(true)}><Plus className="h-4 w-4" /> {t('addLocation')}</Button>} />
      {adding && (
        <Card className="p-5">
          <form className="grid gap-4 lg:grid-cols-4" onSubmit={submit}>
            <Field label="Nume" error={form.errors.name}><Input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} /></Field>
            <Field label="Adresa" error={form.errors.address}><Input value={form.data.address} onChange={(event) => form.setData('address', event.target.value)} /></Field>
            <Field label="Telefon" error={form.errors.phone}><Input value={form.data.phone} onChange={(event) => form.setData('phone', event.target.value)} /></Field>
            <Field label="Email" error={form.errors.email}><Input value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} /></Field>
            <div className="lg:col-span-4">
              <HoursEditor
                title={t('operatingHours')}
                hours={form.data.hours}
                onChange={(key, value) => form.setData('hours', { ...form.data.hours, [key]: value })}
                onBulkApply={(nextHours) => form.setData('hours', nextHours)}
              />
            </div>
            <div className="flex items-end gap-2 lg:col-span-4">
              <Button disabled={form.processing}>{t('save')}</Button>
              <SecondaryButton type="button" onClick={() => setAdding(false)}>{t('cancel')}</SecondaryButton>
            </div>
          </form>
        </Card>
      )}
      <div className="grid gap-4 lg:grid-cols-2">
        {salon.locations.map((location) => (
          <Card key={location.id} className="p-5">
            {editingId === location.id ? (
              <form className="space-y-4" onSubmit={submitEdit}>
                <div className="grid gap-3 sm:grid-cols-2">
                  <Field label="Nume" error={editForm.errors.name}><Input value={editForm.data.name} onChange={(event) => editForm.setData('name', event.target.value)} /></Field>
                  <Field label="Telefon" error={editForm.errors.phone}><Input value={editForm.data.phone} onChange={(event) => editForm.setData('phone', event.target.value)} /></Field>
                  <Field label="Adresa" error={editForm.errors.address}><Input value={editForm.data.address} onChange={(event) => editForm.setData('address', event.target.value)} /></Field>
                  <Field label="Email" error={editForm.errors.email}><Input value={editForm.data.email} onChange={(event) => editForm.setData('email', event.target.value)} /></Field>
                </div>
                <HoursEditor
                  title={t('operatingHours')}
                  hours={editForm.data.hours}
                  onChange={(key, value) => editForm.setData('hours', { ...editForm.data.hours, [key]: value })}
                  onBulkApply={(nextHours) => editForm.setData('hours', nextHours)}
                />
                <div className="flex gap-2">
                  <Button disabled={editForm.processing}>{t('save')}</Button>
                  <SecondaryButton type="button" onClick={() => setEditingId(null)}>{t('cancel')}</SecondaryButton>
                </div>
              </form>
            ) : (
              <>
                <div className="flex items-start justify-between gap-4">
                  <div>
                    <h3 className="text-lg font-black app-text">{location.name}</h3>
                    <p className="mt-1 text-sm app-text-soft">{location.address}</p>
                  </div>
                  <div className="flex gap-2">
                    <SecondaryButton onClick={() => startEdit(location)} aria-label={t('editLocation')} title={t('editLocation')}><Pencil className="h-4 w-4" /></SecondaryButton>
                    <DangerButton onClick={() => setConfirmation({
                      title: t('deleteLocation'),
                      message: t('deleteLocationConfirm'),
                      onConfirm: () => router.delete(`/locations/${location.id}`, { preserveScroll: true }),
                    })}><Trash2 className="h-4 w-4" /></DangerButton>
                  </div>
                </div>
                <div className="mt-5 space-y-2 text-sm app-text-soft">
                  <p>{location.phone || t('phoneMissing')}</p>
                  <p>{location.email || t('emailMissing')}</p>
                  <div className="rounded-lg mt-8 app-panel-soft">
                    <p className="mb-2 flex items-center gap-2 font-black app-text"><Clock className="h-4 w-4 text-indigo-600" /> {t('operatingHours')}</p>
                    <HoursList hours={{ ...defaultHours, ...(location.hours ?? {}) }} />
                  </div>
                </div>
              </>
            )}
          </Card>
        ))}
      </div>
    </div>
  );
}

const hourDays = [
  ['mon', 'monday'],
  ['tue', 'tuesday'],
  ['wed', 'wednesday'],
  ['thu', 'thursday'],
  ['fri', 'friday'],
  ['sat', 'saturday'],
  ['sun', 'sunday'],
] as const;

function HoursEditor({ title, hours, onChange, onBulkApply }: { title: string; hours: Record<string, string>; onChange: (key: string, value: string) => void; onBulkApply: (hours: Record<string, string>) => void }) {
  const t = useT();
  const weekdayKeys = hourDays.slice(0, 5).map(([key]) => key);
  const weekendKeys = hourDays.slice(5).map(([key]) => key);
  const [selectedDays, setSelectedDays] = useState<string[]>(weekdayKeys);
  const [bulkHours, setBulkHours] = useState('');

  function toggleDay(dayKey: string) {
    setSelectedDays((current) => (
      current.includes(dayKey)
        ? current.filter((key) => key !== dayKey)
        : [...current, dayKey]
    ));
  }

  function selectDays(dayKeys: string[]) {
    setSelectedDays(dayKeys);
  }

  function applyBulkHours() {
    if (!bulkHours.trim() || selectedDays.length === 0) return;

    const nextHours = { ...hours };

    selectedDays.forEach((dayKey) => {
      nextHours[dayKey] = bulkHours.trim();
    });

    onBulkApply(nextHours);
  }

  return (
    <div className="rounded-lg border p-4 app-panel-soft">
      <p className="mb-3 text-sm font-black app-text">{title}</p>
      <div className="mb-4 space-y-3 rounded-lg border p-3 app-panel">
        <div className="flex flex-wrap gap-2">
          <button type="button" onClick={() => selectDays(weekdayKeys)} className="rounded-full border px-3 py-1 text-xs font-bold app-panel app-text-soft hover:bg-[var(--app-panel-soft)]">
            {t('weekdays')}
          </button>
          <button type="button" onClick={() => selectDays(weekendKeys)} className="rounded-full border px-3 py-1 text-xs font-bold app-panel app-text-soft hover:bg-[var(--app-panel-soft)]">
            {t('weekend')}
          </button>
          <button type="button" onClick={() => selectDays(hourDays.map(([key]) => key))} className="rounded-full border px-3 py-1 text-xs font-bold app-panel app-text-soft hover:bg-[var(--app-panel-soft)]">
            {t('allDays')}
          </button>
        </div>
        <div className="flex flex-wrap gap-2">
          {hourDays.map(([key, label]) => {
            const active = selectedDays.includes(key);

            return (
              <button
                key={key}
                type="button"
                onClick={() => toggleDay(key)}
                className={`rounded-full border px-3 py-1 text-xs font-bold transition ${active ? 'border-indigo-600 bg-indigo-600 text-white' : 'app-panel app-text-soft hover:bg-[var(--app-panel-soft)]'}`}
              >
                {t(label)}
              </button>
            );
          })}
        </div>
        <div className="flex flex-col gap-2 sm:flex-row">
          <Input value={bulkHours} onChange={(event) => setBulkHours(event.target.value)} placeholder="09:00 - 18:00 / Inchis" />
          <Button type="button" onClick={applyBulkHours} disabled={selectedDays.length === 0 || !bulkHours.trim()} className="min-w-40 whitespace-nowrap">
            {t('applySchedule')}
          </Button>
        </div>
      </div>
      <div className="grid gap-3">
        {hourDays.map(([key, label]) => (
          <Field key={key} label={t(label)}>
            <Input value={hours[key] ?? ''} onChange={(event) => onChange(key, event.target.value)} placeholder="09:00 - 18:00 / Inchis" />
          </Field>
        ))}
      </div>
    </div>
  );
}

function HoursList({ hours }: { hours: Record<string, string> }) {
  const t = useT();

  return (
    <div className="grid gap-1 text-xs">
      {hourDays.map(([key, label]) => (
        <div key={key} className="flex justify-between gap-3">
          <span className="app-text-muted">{t(label)}</span>
          <span className="font-semibold app-text">{hours[key] || '-'}</span>
        </div>
      ))}
    </div>
  );
}

function Services({ salon, query }: { salon: Salon; query: string }) {
  const t = useT();
  const [adding, setAdding] = useState(false);
  const [managingCategories, setManagingCategories] = useState(false);
  const [managingStaff, setManagingStaff] = useState(false);
  const [editingServiceId, setEditingServiceId] = useState<number | null>(null);
  const [confirmation, setConfirmation] = useState<{ title: string; message: string; tone?: 'danger' | 'neutral'; confirmLabel?: string; onConfirm: () => void } | null>(null);
  const [categoryFilter, setCategoryFilter] = useState<string[]>([]);
  const [branchFilter, setBranchFilter] = useState<number[]>([]);
  const [categoryDrafts, setCategoryDrafts] = useState<string[]>(salon.service_categories ?? []);
  const [staffDrafts, setStaffDrafts] = useState<string[]>(salon.service_staff ?? []);
  const form = useForm({ name: '', type: '', staff: [] as string[], price: '', duration: 30, location_ids: [] as number[], notes: '' });
  const editForm = useForm({ name: '', type: '', staff: [] as string[], price: '', duration: 30, location_ids: [] as number[], notes: '' });
  const normalizedQuery = query.trim().toLocaleLowerCase();
  const filteredServices = salon.services.filter((service) => {
    if (categoryFilter.length > 0 && !categoryFilter.includes(service.type ?? '')) return false;
    if (branchFilter.length > 0) {
      const ids = service.location_ids ?? [];
      if (ids.length > 0 && !ids.some((id) => branchFilter.includes(id))) return false;
    }
    if (!normalizedQuery) return true;

    const serviceLocationNames = salon.locations
      .filter((location) => (service.location_ids ?? []).includes(location.id))
      .map((location) => location.name);
    const searchable = [
      service.name,
      service.type,
      service.price,
      service.duration,
      service.notes,
      ...(service.staff ?? []),
      ...serviceLocationNames,
    ];

    return searchable
      .filter((value) => value !== null && value !== undefined)
      .some((value) => String(value).toLocaleLowerCase().includes(normalizedQuery));
  });

  function submit(event: FormEvent) {
    event.preventDefault();
    form.post('/services', {
      preserveScroll: true,
      onSuccess: () => {
        form.reset();
        form.setData('location_ids', []);
        setAdding(false);
      },
    });
  }

  function updateServiceBranches(service: Service, locationIds: number[]) {
    if (salon.locations.length > 0 && locationIds.length === 0) return;

    router.put(`/services/${service.id}`, {
      name: service.name,
      type: service.type ?? '',
      staff: service.staff ?? [],
      price: String(service.price ?? ''),
      duration: service.duration,
      location_ids: locationIds,
      notes: service.notes ?? '',
    }, { preserveScroll: true });
  }

  function updateServiceCategory(service: Service, type: string) {
    router.put(`/services/${service.id}`, {
      name: service.name,
      type,
      staff: service.staff ?? [],
      price: String(service.price ?? ''),
      duration: service.duration,
      location_ids: service.location_ids ?? [],
      notes: service.notes ?? '',
    }, { preserveScroll: true });
  }

  function startEditService(service: Service) {
    setEditingServiceId(service.id);
    editForm.setData({
      name: service.name,
      type: service.type ?? '',
      staff: service.staff ?? [],
      price: String(service.price ?? ''),
      duration: service.duration,
      location_ids: service.location_ids ?? [],
      notes: service.notes ?? '',
    });
  }

  function submitEditService(event: FormEvent) {
    event.preventDefault();
    if (!editingServiceId) return;

    editForm.put(`/services/${editingServiceId}`, {
      preserveScroll: true,
      onSuccess: () => setEditingServiceId(null),
    });
  }

  function openCategoryManager() {
    if (managingCategories) {
      setManagingCategories(false);
      return;
    }

    setManagingStaff(false);
    setCategoryDrafts((salon.service_categories ?? []).length > 0 ? [...(salon.service_categories ?? [])] : ['']);
    setManagingCategories(true);
  }

  function updateCategoryDraft(index: number, value: string) {
    setCategoryDrafts((current) => current.map((item, itemIndex) => itemIndex === index ? value : item));
  }

  function addCategoryDraft() {
    setCategoryDrafts((current) => [...current, '']);
  }

  function removeCategoryDraft(index: number) {
    setCategoryDrafts((current) => current.filter((_, itemIndex) => itemIndex !== index));
  }

  function saveCategories() {
    router.put('/services/categories', {
      categories: categoryDrafts,
    }, {
      preserveScroll: true,
      onSuccess: () => setManagingCategories(false),
    });
  }

  function openStaffManager() {
    if (managingStaff) {
      setManagingStaff(false);
      return;
    }

    setManagingCategories(false);
    setStaffDrafts((salon.service_staff ?? []).length > 0 ? [...(salon.service_staff ?? [])] : ['']);
    setManagingStaff(true);
  }

  function updateStaffDraft(index: number, value: string) {
    setStaffDrafts((current) => current.map((item, itemIndex) => itemIndex === index ? value : item));
  }

  function addStaffDraft() {
    setStaffDrafts((current) => [...current, '']);
  }

  function removeStaffDraft(index: number) {
    setStaffDrafts((current) => current.filter((_, itemIndex) => itemIndex !== index));
  }

  function saveStaff() {
    router.put('/services/staff', {
      staff: staffDrafts,
    }, {
      preserveScroll: true,
      onSuccess: () => setManagingStaff(false),
    });
  }

  return (
    <div className="space-y-6">
      <ConfirmationModal
        open={confirmation !== null}
        title={confirmation?.title ?? ''}
        message={confirmation?.message ?? ''}
        confirmLabel={confirmation?.confirmLabel ?? (confirmation?.tone === 'neutral' ? t('confirm') : t('delete'))}
        cancelLabel={t('cancel')}
        tone={confirmation?.tone ?? 'danger'}
        onCancel={() => setConfirmation(null)}
        onConfirm={() => {
          if (!confirmation) return;
          confirmation.onConfirm();
          setConfirmation(null);
        }}
      />
      <EditModal open={editingServiceId !== null} title={t('editService')} onClose={() => setEditingServiceId(null)}>
        <form className="space-y-5" onSubmit={submitEditService}>
          <div className="grid gap-4 xl:grid-cols-3">
            <ServiceConfiguratorField icon={FileText} label={t('category')} error={editForm.errors.type}>
              <select className="h-10 w-full rounded-lg border px-3 text-sm outline-none app-panel app-text" value={editForm.data.type} onChange={(event) => editForm.setData('type', event.target.value)}>
                <option value="">{t('noCategory')}</option>
                {Array.from(new Set([...(salon.service_categories ?? []), ...(editForm.data.type ? [editForm.data.type] : [])])).map((category) => (
                  <option key={category} value={category}>{category}</option>
                ))}
              </select>
            </ServiceConfiguratorField>
            <ServiceConfiguratorField icon={Users} label={t('staff')} error={editForm.errors.staff}>
              <StaffPicker
                staffOptions={Array.from(new Set([...(salon.service_staff ?? []), ...(editForm.data.staff ?? [])]))}
                selectedStaff={editForm.data.staff}
                onChange={(staff) => editForm.setData('staff', staff)}
                emptyLabel={t('noStaff')}
              />
            </ServiceConfiguratorField>
            <ServiceConfiguratorField icon={MapPin} label={t('availableBranches')} error={editForm.errors.location_ids}>
              <BranchPicker
                locations={salon.locations}
                selectedIds={editForm.data.location_ids}
                onChange={(locationIds) => editForm.setData('location_ids', locationIds)}
                emptyLabel={t('noBranches')}
              />
            </ServiceConfiguratorField>
          </div>
          <div className="grid gap-4 lg:grid-cols-3">
            <Field label={t('service')} error={editForm.errors.name}><Input value={editForm.data.name} onChange={(event) => editForm.setData('name', event.target.value)} /></Field>
            <Field label={t('priceRon')} error={editForm.errors.price}><Input value={editForm.data.price} onChange={(event) => editForm.setData('price', event.target.value)} placeholder={t('pricePlaceholder')} /></Field>
            <Field label={t('durationMin')} error={editForm.errors.duration}><Input type="number" value={editForm.data.duration} onChange={(event) => editForm.setData('duration', Number(event.target.value))} /></Field>
          </div>
          <Field label={t('serviceNotes')} error={editForm.errors.notes}>
            <textarea rows={3} value={editForm.data.notes} onChange={(event) => editForm.setData('notes', event.target.value)} className="w-full rounded-lg border px-3 py-2 text-sm outline-none resize-none app-panel app-text" placeholder={t('serviceNotesPlaceholder')} />
          </Field>
          <div className="flex gap-2">
            <Button disabled={editForm.processing}>{t('save')}</Button>
            <SecondaryButton type="button" onClick={() => setEditingServiceId(null)}>{t('cancel')}</SecondaryButton>
          </div>
        </form>
      </EditModal>
      <EditModal open={managingCategories} title={t('serviceCategories')} onClose={() => setManagingCategories(false)}>
        <div className="space-y-4">
          <p className="text-sm app-text-muted">{t('serviceCategoriesHelp')}</p>
          <div className="space-y-3">
            {categoryDrafts.map((category, index) => (
              <div key={index} className="flex gap-2">
                <Input value={category} onChange={(event) => updateCategoryDraft(index, event.target.value)} placeholder={t('category')} />
                <DangerButton onClick={() => setConfirmation({
                  title: t('removeCategory'),
                  message: t('removeCategoryConfirm'),
                  onConfirm: () => removeCategoryDraft(index),
                })}><Trash2 className="h-4 w-4" /></DangerButton>
              </div>
            ))}
          </div>
          <div className="flex flex-wrap gap-2">
            <SecondaryButton onClick={addCategoryDraft}><Plus className="h-4 w-4" /> {t('addCategory')}</SecondaryButton>
            <Button onClick={saveCategories}>{t('save')}</Button>
            <SecondaryButton onClick={() => setManagingCategories(false)}>{t('cancel')}</SecondaryButton>
          </div>
        </div>
      </EditModal>
      <EditModal open={managingStaff} title={t('serviceStaff')} onClose={() => setManagingStaff(false)}>
        <div className="space-y-4">
          <p className="text-sm app-text-muted">{t('serviceStaffHelp')}</p>
          <div className="space-y-3">
            {staffDrafts.map((staffMember, index) => (
              <div key={index} className="flex gap-2">
                <Input value={staffMember} onChange={(event) => updateStaffDraft(index, event.target.value)} placeholder={t('staff')} />
                <DangerButton onClick={() => setConfirmation({
                  title: t('removeStaffMember'),
                  message: t('removeStaffMemberConfirm'),
                  onConfirm: () => removeStaffDraft(index),
                })}><Trash2 className="h-4 w-4" /></DangerButton>
              </div>
            ))}
          </div>
          <div className="flex flex-wrap gap-2">
            <SecondaryButton onClick={addStaffDraft}><Plus className="h-4 w-4" /> {t('addStaff')}</SecondaryButton>
            <Button onClick={saveStaff}>{t('save')}</Button>
            <SecondaryButton onClick={() => setManagingStaff(false)}>{t('cancel')}</SecondaryButton>
          </div>
        </div>
      </EditModal>
      <EditModal open={adding} title={t('addService')} onClose={() => setAdding(false)}>
        <form className="space-y-5" onSubmit={submit}>
          <div className="grid gap-4 xl:grid-cols-3">
            <ServiceConfiguratorField icon={FileText} label={t('category')} error={form.errors.type}>
              <select className="h-10 w-full rounded-lg border px-3 text-sm outline-none app-panel app-text" value={form.data.type} onChange={(event) => form.setData('type', event.target.value)}>
                <option value="">{t('noCategory')}</option>
                {(salon.service_categories ?? []).map((category) => (
                  <option key={category} value={category}>{category}</option>
                ))}
              </select>
            </ServiceConfiguratorField>
            <ServiceConfiguratorField icon={Users} label={t('staff')} error={form.errors.staff}>
              <StaffPicker
                staffOptions={salon.service_staff ?? []}
                selectedStaff={form.data.staff}
                onChange={(staff) => form.setData('staff', staff)}
                emptyLabel={t('noStaff')}
              />
            </ServiceConfiguratorField>
            <ServiceConfiguratorField icon={MapPin} label={t('availableBranches')} error={form.errors.location_ids}>
              <BranchPicker
                locations={salon.locations}
                selectedIds={form.data.location_ids}
                onChange={(locationIds) => form.setData('location_ids', locationIds)}
                emptyLabel={t('noBranches')}
              />
            </ServiceConfiguratorField>
          </div>
          <div className="grid gap-4 lg:grid-cols-3">
            <Field label={t('service')} error={form.errors.name}><Input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} /></Field>
            <Field label={t('priceRon')} error={form.errors.price}><Input value={form.data.price} onChange={(event) => form.setData('price', event.target.value)} placeholder={t('pricePlaceholder')} /></Field>
            <Field label={t('durationMin')} error={form.errors.duration}><Input type="number" value={form.data.duration} onChange={(event) => form.setData('duration', Number(event.target.value))} /></Field>
          </div>
          <Field label={t('serviceNotes')} error={form.errors.notes}>
            <textarea rows={3} value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} className="w-full rounded-lg border px-3 py-2 text-sm outline-none resize-none app-panel app-text" placeholder={t('serviceNotesPlaceholder')} />
          </Field>
          <div className="flex items-end gap-2">
            <Button disabled={form.processing}>{t('save')}</Button>
            <SecondaryButton type="button" onClick={() => setAdding(false)}>{t('cancel')}</SecondaryButton>
          </div>
        </form>
      </EditModal>
      <Toolbar
        title={t('serviceCatalog')}
        subtitle={t('servicesSubtitle')}
        hideText
        action={
          <div className="flex flex-wrap gap-2">
            <SecondaryButton onClick={openCategoryManager}><Plus className="h-4 w-4" /> {t('addCategory')}</SecondaryButton>
            <SecondaryButton onClick={openStaffManager}><Plus className="h-4 w-4" /> {t('addStaff')}</SecondaryButton>
            <Button onClick={() => setAdding(true)}><Plus className="h-4 w-4" /> {t('addService')}</Button>
          </div>
        }
      />
      <Card className="overflow-hidden">
        <Table headers={[
          t('service'),
          <CategoryFilterHeader
            key="cat-filter"
            label={t('category')}
            categories={salon.service_categories ?? []}
            selected={categoryFilter}
            onChange={setCategoryFilter}
          />,
          <BranchFilterHeader
            key="branch-filter"
            label={t('branches')}
            locations={salon.locations}
            selected={branchFilter}
            onChange={setBranchFilter}
          />,
          t('duration'),
          t('priceRon'),
          '',
        ]}>
          {filteredServices.map((service) => (
            <tr key={service.id} className="border-t app-border">
              <>
                  <td className="px-5 py-4 align-top">
                    <div className="flex flex-wrap items-center gap-2">
                      <p className="font-bold app-text">{service.name}</p>
                      {!!service.notes && <ServiceNotesPill notes={service.notes} />}
                    </div>
                    {!!service.staff?.length && (
                      <p className="mt-1 text-xs font-semibold app-text-muted">
                        {service.staff.join(' • ')}
                      </p>
                    )}
                  </td>
                  <td className="px-5 py-4 text-sm app-text-soft">{service.type || ''}</td>
                  <td className="px-5 py-4">
                    <BranchPicker
                      locations={salon.locations}
                      selectedIds={service.location_ids ?? []}
                      onChange={(locationIds) => updateServiceBranches(service, locationIds)}
                      emptyLabel={t('noBranches')}
                      compact
                    />
                  </td>
                  <td className="px-5 py-4 text-sm app-text-soft">{service.duration} min</td>
                  <td className="px-5 py-4 font-black text-indigo-700">{service.price}</td>
                  <td className="px-5 py-4">
                    <div className="flex justify-end gap-2">
                      <SecondaryButton onClick={() => startEditService(service)}><Pencil className="h-4 w-4" /></SecondaryButton>
                      <DangerButton onClick={() => setConfirmation({
                        title: t('deleteService'),
                        message: t('deleteServiceConfirm'),
                        onConfirm: () => router.delete(`/services/${service.id}`, { preserveScroll: true }),
                      })}><Trash2 className="h-4 w-4" /></DangerButton>
                    </div>
                  </td>
              </>
            </tr>
          ))}
        </Table>
      </Card>
    </div>
  );
}

function CategoryFilterHeader({ label, categories, selected, onChange }: { label: string; categories: string[]; selected: string[]; onChange: (next: string[]) => void }) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const active = selected.length > 0;

  useEffect(() => {
    function onClickOutside(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    }
    document.addEventListener('mousedown', onClickOutside);
    return () => document.removeEventListener('mousedown', onClickOutside);
  }, []);

  function toggle(category: string) {
    onChange(selected.includes(category) ? selected.filter((c) => c !== category) : [...selected, category]);
  }

  if (categories.length === 0) {
    return <span>{label.toLocaleUpperCase()}</span>;
  }

  return (
    <div ref={ref} className="relative inline-block">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className={`inline-flex items-center gap-1.5 rounded-md px-1.5 py-1 transition hover:bg-white/10 ${active ? 'text-indigo-400' : ''}`}
      >
        {label.toLocaleUpperCase()}
        {active && <span className="flex h-4 w-4 items-center justify-center rounded-full bg-indigo-600 text-[10px] font-black text-white">{selected.length}</span>}
        <ChevronDown className={`h-3 w-3 transition-transform ${open ? 'rotate-180' : ''}`} />
      </button>
      {open && (
        <div className="absolute left-0 top-full z-50 mt-1 min-w-44 rounded-lg border p-1 shadow-lg app-panel normal-case tracking-normal">
          {selected.length > 0 && (
            <button
              type="button"
              onClick={() => { onChange([]); setOpen(false); }}
              className="flex w-full items-center gap-2.5 rounded-md px-3 py-2 text-xs font-semibold text-indigo-600 transition hover:bg-[var(--app-panel-soft)]"
            >
              Reset
            </button>
          )}
          {categories.map((category) => {
            const checked = selected.includes(category);
            return (
              <button
                key={category}
                type="button"
                onClick={() => toggle(category)}
                className="flex w-full items-center gap-2.5 rounded-md px-3 py-2 text-sm font-semibold transition hover:bg-[var(--app-panel-soft)]"
              >
                <span className={`flex h-4 w-4 shrink-0 items-center justify-center rounded border ${checked ? 'border-indigo-600 bg-indigo-600' : 'border-[var(--app-border)]'}`}>
                  {checked && <Check className="h-2.5 w-2.5 text-white" />}
                </span>
                <span className="app-text">{category}</span>
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
}

function BranchFilterHeader({ label, locations, selected, onChange }: { label: string; locations: SalonLocation[]; selected: number[]; onChange: (next: number[]) => void }) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const active = selected.length > 0;

  useEffect(() => {
    function onClickOutside(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    }
    document.addEventListener('mousedown', onClickOutside);
    return () => document.removeEventListener('mousedown', onClickOutside);
  }, []);

  function toggle(id: number) {
    onChange(selected.includes(id) ? selected.filter((v) => v !== id) : [...selected, id]);
  }

  if (locations.length === 0) {
    return <span>{label.toLocaleUpperCase()}</span>;
  }

  return (
    <div ref={ref} className="relative inline-block">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className={`inline-flex items-center gap-1.5 rounded-md px-1.5 py-1 transition hover:bg-white/10 ${active ? 'text-indigo-400' : ''}`}
      >
        {label.toLocaleUpperCase()}
        {active && <span className="flex h-4 w-4 items-center justify-center rounded-full bg-indigo-600 text-[10px] font-black text-white">{selected.length}</span>}
        <ChevronDown className={`h-3 w-3 transition-transform ${open ? 'rotate-180' : ''}`} />
      </button>
      {open && (
        <div className="absolute left-0 top-full z-50 mt-1 min-w-44 rounded-lg border p-1 shadow-lg app-panel normal-case tracking-normal">
          {selected.length > 0 && (
            <button
              type="button"
              onClick={() => { onChange([]); setOpen(false); }}
              className="flex w-full items-center gap-2.5 rounded-md px-3 py-2 text-xs font-semibold text-indigo-600 transition hover:bg-[var(--app-panel-soft)]"
            >
              Reset
            </button>
          )}
          {locations.map((location) => {
            const checked = selected.includes(location.id);
            return (
              <button
                key={location.id}
                type="button"
                onClick={() => toggle(location.id)}
                className="flex w-full items-center gap-2.5 rounded-md px-3 py-2 text-sm font-semibold transition hover:bg-[var(--app-panel-soft)]"
              >
                <span className={`flex h-4 w-4 shrink-0 items-center justify-center rounded border ${checked ? 'border-indigo-600 bg-indigo-600' : 'border-[var(--app-border)]'}`}>
                  {checked && <Check className="h-2.5 w-2.5 text-white" />}
                </span>
                <span className="app-text">{location.name}</span>
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
}

function CategoryPicker({ categories, selected, onChange, emptyLabel }: { categories: string[]; selected: string; onChange: (category: string) => void; emptyLabel: string }) {
  if (categories.length === 0) {
    return <p className="text-sm app-text-muted">{emptyLabel}</p>;
  }
  return (
    <div className="flex flex-wrap gap-1.5">
      {categories.map((category) => {
        const active = selected === category;
        return (
          <button
            key={category}
            type="button"
            onClick={() => onChange(active ? '' : category)}
            className={`inline-flex items-center justify-center rounded-full border font-bold transition px-2.5 py-1 text-xs ${active ? 'border-indigo-600 bg-indigo-600 text-white' : 'app-panel app-text-soft hover:bg-[var(--app-panel-soft)]'}`}
          >
            {category}
          </button>
        );
      })}
    </div>
  );
}

function BranchPicker({ locations, selectedIds, onChange, label, emptyLabel, compact = false }: { locations: SalonLocation[]; selectedIds: number[]; onChange: (ids: number[]) => void; label?: string; emptyLabel: string; compact?: boolean }) {
  if (locations.length === 0) {
    return <p className="text-sm app-text-muted">{emptyLabel}</p>;
  }

  if (compact) {
    const normalizedSelectedIds = selectedIds ?? [];
    function toggle(locationId: number) {
      const nextIds = normalizedSelectedIds.includes(locationId)
        ? normalizedSelectedIds.filter((id) => id !== locationId)
        : [...normalizedSelectedIds, locationId];
      onChange(nextIds);
    }
    return (
      <div>
        {label && <p className="mb-2 text-xs font-black uppercase tracking-wide app-text-muted">{label}</p>}
        <div className="flex flex-wrap gap-1.5">
          {locations.map((location) => {
            const active = normalizedSelectedIds.includes(location.id);
            return (
              <button
                key={location.id}
                type="button"
                onClick={() => toggle(location.id)}
                className={`inline-flex items-center justify-center rounded-full border font-bold transition min-w-24 px-2.5 py-1 text-xs ${active ? 'border-indigo-600 bg-indigo-600 text-white' : 'app-panel app-text-soft hover:bg-[var(--app-panel-soft)]'}`}
                title={location.address}
              >
                {location.name}
              </button>
            );
          })}
        </div>
      </div>
    );
  }

  const options = locations.map(l => ({ value: l.id, label: l.name }));
  return (
    <div>
      {label && <p className="mb-2 text-xs font-black uppercase tracking-wide app-text-muted">{label}</p>}
      <MultiSelectDropdown
        options={options}
        selected={selectedIds ?? []}
        onChange={next => onChange(next as number[])}
        emptyLabel={emptyLabel}
      />
    </div>
  );
}

function ServiceConfiguratorField({ icon: Icon, label, error, children }: { icon: any; label: string; error?: string; children: React.ReactNode }) {
  return (
    <div className="rounded-lg border p-4 app-panel">
      <div className="flex items-stretch gap-3">
        <span className="flex w-9 shrink-0 items-center justify-center border-r pr-3 text-indigo-600 app-border dark:text-indigo-300">
          <Icon className="h-full max-h-[4.25rem] w-full" />
        </span>
        <div className="min-w-0 flex-1 space-y-1.5">
          <p className="text-xs font-bold uppercase tracking-wide app-text-muted">{label}</p>
          {children}
          {error && <p className="text-xs font-bold text-red-500">{error}</p>}
        </div>
      </div>
    </div>
  );
}

function MultiSelectDropdown({ options, selected, onChange, emptyLabel, renderLabel }: {
  options: { value: string | number; label: string }[];
  selected: (string | number)[];
  onChange: (next: (string | number)[]) => void;
  emptyLabel: string;
  renderLabel?: (selected: (string | number)[]) => string;
}) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    function onClickOutside(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    }
    document.addEventListener('mousedown', onClickOutside);
    return () => document.removeEventListener('mousedown', onClickOutside);
  }, []);

  if (options.length === 0) {
    return <p className="text-sm app-text-muted">{emptyLabel}</p>;
  }

  const selectedLabels = options.filter(o => selected.includes(o.value)).map(o => o.label);
  const triggerText = renderLabel
    ? renderLabel(selected)
    : selectedLabels.length > 0 ? selectedLabels.join(', ') : emptyLabel;

  function toggle(value: string | number) {
    const next = selected.includes(value)
      ? selected.filter(v => v !== value)
      : [...selected, value];
    onChange(next);
  }

  return (
    <div ref={ref} className="relative">
      <button
        type="button"
        onClick={() => setOpen(v => !v)}
        className="flex h-10 w-full items-center justify-between gap-2 rounded-lg border px-3 text-sm app-panel app-text hover:bg-[var(--app-panel-soft)]"
      >
        <span className="truncate font-semibold">{triggerText}</span>
        <ChevronDown className={`h-4 w-4 shrink-0 app-text-muted transition-transform ${open ? 'rotate-180' : ''}`} />
      </button>
      {open && (
        <div className="absolute left-0 top-11 z-50 w-full min-w-48 rounded-lg border p-1 shadow-lg app-panel">
          {options.map(option => {
            const active = selected.includes(option.value);
            return (
              <button
                key={option.value}
                type="button"
                onClick={() => toggle(option.value)}
                className="flex w-full items-center gap-2.5 rounded-md px-3 py-2 text-sm font-semibold transition hover:bg-[var(--app-panel-soft)]"
              >
                <span className={`flex h-4 w-4 shrink-0 items-center justify-center rounded border ${active ? 'border-indigo-600 bg-indigo-600' : 'border-[var(--app-border)]'}`}>
                  {active && <Check className="h-2.5 w-2.5 text-white" />}
                </span>
                <span className="app-text">{option.label}</span>
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
}

function StaffPicker({ staffOptions, selectedStaff, onChange, emptyLabel }: { staffOptions: string[]; selectedStaff: string[]; onChange: (staff: string[]) => void; emptyLabel: string }) {
  const options = staffOptions.filter(Boolean).map(s => ({ value: s, label: s }));
  return (
    <MultiSelectDropdown
      options={options}
      selected={selectedStaff ?? []}
      onChange={next => onChange(next as string[])}
      emptyLabel={emptyLabel}
    />
  );
}

function ChatAudio({ salon, query }: { salon: Salon; query: string }) {
  const t = useT();
  const conversations = salon.conversations.filter((conversation) => {
    const haystack = [
      conversation.contact_name,
      conversation.contact_phone,
      conversation.contact_email,
      conversation.summary,
      conversation.messages.at(-1)?.content,
      conversation.channel,
    ].filter(Boolean).join(' ').toLowerCase();

    return haystack.includes(query.trim().toLowerCase());
  });
  const stats = {
    total: salon.conversations.length,
    chat: salon.conversations.filter((conversation) => conversation.channel === 'chat').length,
    audio: salon.conversations.filter((conversation) => conversation.channel === 'voice').length,
    completed: salon.conversations.filter((conversation) => conversation.status === 'completed').length,
  };

  return (
    <div className="space-y-6">
      <div className="flex justify-end">
        <SecondaryButton type="button">
          <Download className="h-4 w-4" />
          {t('exportCsv')}
        </SecondaryButton>
      </div>

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <ChannelStat icon={MessageSquare} value={stats.total} label={t('totalConversations')} tone="blue" />
        <ChannelStat icon={MessageSquare} value={stats.chat} label={t('chat')} tone="green" />
        <ChannelStat icon={Volume2} value={stats.audio} label={t('audio')} tone="purple" />
        <ChannelStat icon={CheckCircle2} value={stats.completed} label={t('completedChats')} tone="slate" />
      </div>

      <Card className="min-h-40 overflow-hidden">
        <div className="border-b p-5 app-border">
          <h2 className="text-lg font-black app-text">{t('recentConversations')}</h2>
        </div>
        {conversations.length === 0 ? (
          <div className="flex min-h-24 items-center justify-center p-6 text-sm app-text-muted">
            {t('noConversations')}
          </div>
        ) : (
          <div className="divide-y app-border">
            {conversations.slice(0, 12).map((conversation) => {
              const Icon = conversation.channel === 'voice' ? Phone : MessageSquare;
              const lastMessage = conversation.messages.at(-1)?.content ?? t('noSummary');

              return (
                <div key={conversation.id} className="flex items-center justify-between gap-4 p-5">
                  <div className="flex min-w-0 items-center gap-3">
                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-indigo-500/10 text-indigo-600 dark:text-indigo-300">
                      <Icon className="h-4 w-4" />
                    </span>
                    <div className="min-w-0">
                      <p className="truncate text-sm font-black app-text">{conversationTitle(conversation, t)}</p>
                      <p className="truncate text-xs app-text-muted">{lastMessage}</p>
                    </div>
                  </div>
                  <div className="hidden shrink-0 sm:block">
                    <IntentPill intent={conversation.intent} compact bookingStatus={conversation.booking?.status} />
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </Card>
    </div>
  );
}

function VoiceCalls({ query: _query }: { query: string }) {
  const t = useT();

  return (
    <div className="space-y-6">
      <div className="flex justify-end">
        <SecondaryButton type="button">
          <Download className="h-4 w-4" />
          {t('exportCsv')}
        </SecondaryButton>
      </div>

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <ChannelStat icon={Phone} value={0} label={t('totalCalls')} tone="blue" />
        <ChannelStat icon={Phone} value={0} label={t('answeredCalls')} tone="green" />
        <ChannelStat icon={Phone} value={0} label={t('missedCalls')} tone="red" />
        <ChannelStat icon={Phone} value={0} label={t('totalMinutes')} tone="purple" />
      </div>

      <Card className="min-h-40 p-6">
        <h2 className="text-lg font-black app-text">{t('recentCalls')}</h2>
        <div className="flex min-h-24 items-center justify-center text-sm app-text-muted">
          {t('noVoiceCallsFound')}
        </div>
      </Card>
    </div>
  );
}

function WhatsAppConversations({ query: _query }: { query: string }) {
  const t = useT();

  return (
    <div className="space-y-6">
      <Card className="border-emerald-500/30 p-6">
        <div className="flex flex-col gap-6 md:flex-row md:items-start md:justify-between">
          <div className="flex items-start gap-4">
            <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-amber-500/15 text-amber-500">
              <QrCode className="h-6 w-6" />
            </span>
            <div>
              <h2 className="text-lg font-black app-text">{t('whatsappBot')}</h2>
              <span className="mt-1 inline-flex rounded-full bg-amber-400/20 px-2.5 py-1 text-[11px] font-black text-amber-600 dark:text-amber-300">
                {t('disconnected')}
              </span>
              <div className="mt-4">
                <SecondaryButton type="button">
                  <Smartphone className="h-4 w-4" />
                  {t('connectWithPhoneNumber')}
                </SecondaryButton>
              </div>
            </div>
          </div>

          <Button type="button" className="bg-emerald-600 hover:bg-emerald-700">
            <QrCode className="h-4 w-4" />
            {t('connectWhatsapp')}
          </Button>
        </div>
      </Card>

      <div className="grid gap-4 md:grid-cols-3">
        <ChannelStat icon={MessageCircle} value={0} label={t('totalChats')} tone="green" />
        <ChannelStat icon={MessageCircle} value={0} label={t('activeChats')} tone="blue" />
        <ChannelStat icon={CheckCircle2} value={0} label={t('completedChats')} tone="slate" />
      </div>

      <Card className="min-h-40 p-6">
        <h2 className="text-lg font-black app-text">{t('whatsappConversations')}</h2>
        <div className="flex min-h-24 items-center justify-center text-sm app-text-muted">
          {t('noWhatsappConversationsFound')}
        </div>
      </Card>
    </div>
  );
}

function ChannelStat({ icon: Icon, value, label, tone }: { icon: any; value: number; label: string; tone: 'blue' | 'green' | 'red' | 'purple' | 'slate' }) {
  const tones = {
    blue: 'bg-blue-100 text-blue-600 dark:bg-blue-400/15 dark:text-blue-300',
    green: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-400/15 dark:text-emerald-300',
    red: 'bg-red-100 text-red-600 dark:bg-red-400/15 dark:text-red-300',
    purple: 'bg-purple-100 text-purple-600 dark:bg-purple-400/15 dark:text-purple-300',
    slate: 'bg-slate-100 text-slate-700 dark:bg-white/90 dark:text-slate-700',
  };

  return (
    <Card className="p-5">
      <div className="flex items-center gap-4">
        <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${tones[tone]}`}>
          <Icon className="h-5 w-5" />
        </span>
        <div>
          <p className="text-2xl font-black app-text">{value}</p>
          <p className="text-sm app-text-muted">{label}</p>
        </div>
      </div>
    </Card>
  );
}

function Bookings({ salon, query }: { salon: Salon; query: string }) {
  const t = useT();
  const [view, setView] = useState<'archive' | 'list' | 'calendar'>('list');
  const [editingBookingId, setEditingBookingId] = useState<number | null>(null);
  const [isAddingBooking, setIsAddingBooking] = useState(false);
  const [bookingCategory, setBookingCategory] = useState('');
  const [confirmation, setConfirmation] = useState<{ title: string; message: string; tone?: 'danger' | 'neutral'; confirmLabel?: string; onConfirm: () => void } | null>(null);
  const editForm = useForm({
    client_name: '',
    client_phone: '',
    location_id: null as number | null,
    service_id: null as number | null,
    staff: [] as string[],
    date: '',
    time: '',
    status: 'pending' as 'pending' | 'confirmed' | 'cancelled' | 'completed',
  });
  const todayKey = toDateKey(new Date());
  const filteredBookings = useMemo(() => {
    const normalized = query.trim().toLowerCase();
    if (!normalized) return salon.bookings;

    return salon.bookings.filter((booking) => [
      booking.client_name,
      booking.client_phone,
      booking.service?.name,
      booking.service?.type,
      booking.location?.name,
    ].filter(Boolean).join(' ').toLowerCase().includes(normalized));
  }, [query, salon.bookings]);
  const stats = useMemo(() => {
    const now = new Date();
    return {
      today: salon.bookings.filter((booking) => booking.date === todayKey).length,
      upcoming: salon.bookings.filter((booking) => bookingEndDate(booking) > now && (booking.status === 'pending' || booking.status === 'confirmed')).length,
      completed: salon.bookings.filter((booking) => booking.status === 'completed').length,
      cancelled: salon.bookings.filter((booking) => booking.status === 'cancelled').length,
    };
  }, [salon.bookings, todayKey]);
  const visibleBookings = useMemo(() => (
    view === 'archive'
      ? filteredBookings.filter((booking) => booking.date < todayKey)
      : filteredBookings.filter((booking) => booking.date >= todayKey)
  ), [filteredBookings, todayKey, view]);
  const groupedBookings = useMemo(() => groupBookingsByDay(visibleBookings), [visibleBookings]);
  const bookingCategoryOptions = useMemo(
    () => Array.from(new Set([
      ...(salon.service_categories ?? []),
      ...salon.services.map((service) => service.type ?? ''),
      bookingCategory,
    ].filter(Boolean))),
    [bookingCategory, salon.service_categories, salon.services],
  );
  const filteredBookingServices = useMemo(
    () => bookingCategory
      ? salon.services.filter((service) => (service.type ?? '') === bookingCategory)
      : salon.services,
    [bookingCategory, salon.services],
  );
  const selectedBookingService = useMemo(
    () => salon.services.find((service) => service.id === editForm.data.service_id) ?? null,
    [editForm.data.service_id, salon.services],
  );
  const bookingStaffOptions = useMemo(
    () => Array.from(new Set([
      ...(salon.service_staff ?? []),
      ...(selectedBookingService?.staff ?? []),
      ...(editForm.data.staff ?? []),
    ].filter(Boolean))),
    [editForm.data.staff, salon.service_staff, selectedBookingService?.staff],
  );

  function startEditBooking(booking: Salon['bookings'][number]) {
    setEditingBookingId(booking.id);
    setBookingCategory(booking.service?.type ?? '');
    editForm.setData({
      client_name: booking.client_name,
      client_phone: booking.client_phone ?? '',
      location_id: booking.location_id ?? null,
      service_id: booking.service_id ?? null,
      staff: booking.staff ?? [],
      date: booking.date,
      time: booking.time,
      status: booking.status,
    });
  }

  function startAddBooking() {
    setBookingCategory('');
    const defaultLocation = salon.locations.length === 1 ? salon.locations[0].id : null;
    editForm.setData({ client_name: '', client_phone: '', location_id: defaultLocation, service_id: null, staff: [], date: '', time: '', status: 'pending' });
    setIsAddingBooking(true);
  }

  function submitEditBooking(event: FormEvent) {
    event.preventDefault();

    if (isAddingBooking) {
      editForm.post('/bookings', {
        preserveScroll: true,
        onSuccess: () => setIsAddingBooking(false),
      });
      return;
    }

    if (!editingBookingId) return;
    editForm.put(`/bookings/${editingBookingId}`, {
      preserveScroll: true,
      onSuccess: () => setEditingBookingId(null),
    });
  }

  function updateBookingCategory(category: string) {
    setBookingCategory(category);

    const currentService = salon.services.find((service) => service.id === editForm.data.service_id);
    if (currentService && (currentService.type ?? '') !== category) {
      editForm.setData('service_id', null);
    }
  }

  function updateBookingService(serviceId: number | null) {
    editForm.setData('service_id', serviceId);

    const service = salon.services.find((item) => item.id === serviceId);
    if (service?.type) {
      setBookingCategory(service.type);
    }
  }

  return (
    <div className="space-y-6">
      <ConfirmationModal
        open={confirmation !== null}
        title={confirmation?.title ?? ''}
        message={confirmation?.message ?? ''}
        confirmLabel={confirmation?.confirmLabel ?? (confirmation?.tone === 'neutral' ? t('confirm') : t('delete'))}
        cancelLabel={t('cancel')}
        tone={confirmation?.tone ?? 'danger'}
        onCancel={() => setConfirmation(null)}
        onConfirm={() => {
          if (!confirmation) return;
          confirmation.onConfirm();
          setConfirmation(null);
        }}
      />
      <EditModal open={editingBookingId !== null || isAddingBooking} title={isAddingBooking ? t('newBooking') : t('editBooking')} onClose={() => { setEditingBookingId(null); setIsAddingBooking(false); }}>
        <form className="space-y-5" onSubmit={submitEditBooking}>
          <div className="grid gap-4 xl:grid-cols-3">
            <Field label={t('client')} error={editForm.errors.client_name}>
              <Input value={editForm.data.client_name} onChange={(event) => editForm.setData('client_name', event.target.value)} />
            </Field>
            <Field label={t('phone')} error={editForm.errors.client_phone}>
              <Input value={editForm.data.client_phone} onChange={(event) => editForm.setData('client_phone', event.target.value)} />
            </Field>
            <Field label={t('status')} error={editForm.errors.status}>
              <select className="h-10 w-full rounded-lg border px-3 text-sm outline-none app-panel app-text" value={editForm.data.status} onChange={(event) => editForm.setData('status', event.target.value as typeof editForm.data.status)}>
                <option value="pending">{t('statusPending')}</option>
                <option value="confirmed">{t('statusConfirmed')}</option>
                <option value="cancelled">{t('statusCancelled')}</option>
                <option value="completed">{t('statusCompleted')}</option>
              </select>
            </Field>
          </div>
          <div className="grid gap-4 xl:grid-cols-4">
            <Field label={t('category')} error={undefined}>
              <select className="h-10 w-full rounded-lg border px-3 text-sm outline-none app-panel app-text" value={bookingCategory} onChange={(event) => updateBookingCategory(event.target.value)}>
                <option value="">{t('category')}</option>
                {bookingCategoryOptions.map((category) => (
                  <option key={category} value={category}>{category}</option>
                ))}
              </select>
            </Field>
            <Field label={t('service')} error={editForm.errors.service_id}>
              <select className="h-10 w-full rounded-lg border px-3 text-sm outline-none app-panel app-text" value={editForm.data.service_id ?? ''} onChange={(event) => updateBookingService(event.target.value ? Number(event.target.value) : null)}>
                <option value="">{t('service')}</option>
                {filteredBookingServices.map((service) => (
                  <option key={service.id} value={service.id}>{service.name}</option>
                ))}
              </select>
            </Field>
            {salon.locations.length > 1 && (
              <Field label={t('branch')} error={editForm.errors.location_id}>
                <select className="h-10 w-full rounded-lg border px-3 text-sm outline-none app-panel app-text" value={editForm.data.location_id ?? ''} onChange={(event) => editForm.setData('location_id', event.target.value ? Number(event.target.value) : null)}>
                  <option value="">{t('branch')}</option>
                  {salon.locations.map((location) => (
                    <option key={location.id} value={location.id}>{location.name}</option>
                  ))}
                </select>
              </Field>
            )}
            <Field label={t('staff')} error={editForm.errors.staff}>
              <StaffPicker
                staffOptions={bookingStaffOptions}
                selectedStaff={editForm.data.staff}
                onChange={(staff) => editForm.setData('staff', staff)}
                emptyLabel={t('noStaff')}
              />
            </Field>
            <Field label={t('date')} error={editForm.errors.date}>
              <Input type="date" value={editForm.data.date} onChange={(event) => editForm.setData('date', event.target.value)} />
            </Field>
            <Field label={t('time')} error={editForm.errors.time}>
              <Input type="time" value={editForm.data.time} onChange={(event) => editForm.setData('time', event.target.value)} />
            </Field>
          </div>
          <div className="flex gap-2">
            <Button disabled={editForm.processing}>{t('save')}</Button>
            <SecondaryButton type="button" onClick={() => { setEditingBookingId(null); setIsAddingBooking(false); }}>{t('cancel')}</SecondaryButton>
          </div>
        </form>
      </EditModal>
      <div className="flex items-center justify-between gap-3">
        <button
          type="button"
          onClick={startAddBooking}
          className="inline-flex h-10 items-center gap-2 rounded-lg bg-blue-600 px-4 text-sm font-black text-white transition hover:bg-blue-700"
        >
          <Plus className="h-4 w-4" />
          {t('newBooking')}
        </button>
        <div className="inline-flex rounded-lg border p-1 app-panel">
          <button
            type="button"
            onClick={() => setView('archive')}
            className={`inline-flex h-9 items-center gap-2 rounded-md px-3 text-sm font-bold transition ${view === 'archive' ? 'bg-indigo-600 text-white shadow-sm' : 'app-text-muted hover:bg-[var(--app-panel-soft)]'}`}
          >
            <Clock className="h-4 w-4" />
            {t('archive')}
          </button>
          <button
            type="button"
            onClick={() => setView('list')}
            className={`inline-flex h-9 items-center gap-2 rounded-md px-3 text-sm font-bold transition ${view === 'list' ? 'bg-indigo-600 text-white shadow-sm' : 'app-text-muted hover:bg-[var(--app-panel-soft)]'}`}
          >
            <List className="h-4 w-4" />
            {t('listView')}
          </button>
          <button
            type="button"
            onClick={() => setView('calendar')}
            className={`inline-flex h-9 items-center gap-2 rounded-md px-3 text-sm font-bold transition ${view === 'calendar' ? 'bg-indigo-600 text-white shadow-sm' : 'app-text-muted hover:bg-[var(--app-panel-soft)]'}`}
          >
            <Calendar className="h-4 w-4" />
            {t('calendarView')}
          </button>
        </div>
      </div>

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <BookingStat icon={Calendar} value={stats.today} label={t('today')} tone="blue" />
        <BookingStat icon={Calendar} value={stats.upcoming} label={t('upcoming')} tone="green" />
        <BookingStat icon={Calendar} value={stats.completed} label={t('completed')} tone="purple" />
        <BookingStat icon={Calendar} value={stats.cancelled} label={t('cancelled')} tone="red" />
      </div>

      {(view === 'list' || view === 'archive') && (
        <BookingsDayCards
          groups={groupedBookings}
          t={t}
          onEdit={startEditBooking}
          onConfirm={(booking) => router.put(`/bookings/${booking.id}`, { status: 'confirmed' }, { preserveScroll: true })}
          onCancel={(booking) => router.put(`/bookings/${booking.id}`, { status: 'cancelled' }, { preserveScroll: true })}
          onDelete={(booking) => setConfirmation({
            title: t('deleteBooking'),
            message: t('deleteBookingConfirm'),
            onConfirm: () => router.delete(`/bookings/${booking.id}`, { preserveScroll: true }),
          })}
        />
      )}
      {view === 'calendar' && <Card className="overflow-hidden">
        <BookingsCalendar bookings={filteredBookings} t={t} />
      </Card>}
    </div>
  );
}

function BookingStat({ icon: Icon, value, label, tone }: { icon: any; value: number; label: string; tone: 'blue' | 'green' | 'purple' | 'red' }) {
  const tones = {
    blue: 'bg-blue-100 text-blue-600 dark:bg-blue-400/15 dark:text-blue-300',
    green: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-400/15 dark:text-emerald-300',
    purple: 'bg-purple-100 text-purple-600 dark:bg-purple-400/15 dark:text-purple-300',
    red: 'bg-red-100 text-red-600 dark:bg-red-400/15 dark:text-red-300',
  };

  return (
    <Card className="p-5">
      <div className="flex items-center gap-4">
        <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${tones[tone]}`}>
          <Icon className="h-5 w-5" />
        </span>
        <div>
          <p className="text-2xl font-black app-text">{value}</p>
          <p className="text-sm app-text-muted">{label}</p>
        </div>
      </div>
    </Card>
  );
}

function groupBookingsByDay(bookings: Salon['bookings']) {
  const groups = new Map<string, Salon['bookings']>();

  [...bookings]
    .sort((a, b) => `${a.date} ${a.time}`.localeCompare(`${b.date} ${b.time}`))
    .forEach((booking) => {
      const current = groups.get(booking.date) ?? [];
      current.push(booking);
      groups.set(booking.date, current);
    });

  return Array.from(groups.entries()).map(([date, dayBookings]) => ({
    date,
    bookings: dayBookings,
  }));
}

function BookingsDayCards({
  groups,
  t,
  onEdit,
  onConfirm,
  onCancel,
  onDelete,
}: {
  groups: ReturnType<typeof groupBookingsByDay>;
  t: (key: string) => string;
  onEdit: (booking: Salon['bookings'][number]) => void;
  onConfirm: (booking: Salon['bookings'][number]) => void;
  onCancel: (booking: Salon['bookings'][number]) => void;
  onDelete: (booking: Salon['bookings'][number]) => void;
}) {
  if (groups.length === 0) {
    return (
      <Card className="p-6">
        <div className="flex min-h-24 items-center justify-center text-sm app-text-muted">{t('noBookingsFound')}</div>
      </Card>
    );
  }

  return (
    <div className="space-y-4">
      {groups.map((group) => (
        <Card key={group.date} className="overflow-hidden">
          <div className="flex flex-wrap items-center justify-between gap-3 border-b p-5 app-border app-panel-soft">
            <div className="flex flex-wrap items-center gap-2">
              <h3 className="text-base font-black capitalize app-text">{formatBookingDay(group.date)}</h3>
              <span className="inline-flex h-6 min-w-6 items-center justify-center rounded-full bg-red-600 px-2 text-xs font-black text-white">
                {group.bookings.length}
              </span>
            </div>
            <span className="rounded-full bg-indigo-500/10 px-3 py-1 text-xs font-black text-indigo-700 dark:text-indigo-200">
              {group.bookings[0]?.time} - {group.bookings.at(-1)?.time}
            </span>
          </div>
          <div className="divide-y app-border">
            {group.bookings.map((booking) => (
              <div key={booking.id} className="grid gap-x-6 gap-y-3 p-5 lg:grid-cols-[auto_minmax(0,1fr)_auto] lg:items-center">
                <div className="flex flex-wrap items-center gap-3">
                  <span className={`inline-flex h-8 shrink-0 items-center justify-center rounded-full border px-3 text-xs font-black uppercase tracking-wide app-border ${booking.status === 'completed' ? 'bg-slate-200 text-slate-500 dark:bg-white/10 dark:text-slate-400' : 'bg-white text-slate-950'}`}>
                    {bookingTimeRange(booking.time, booking.service?.duration)}
                  </span>
                  <StatusPill status={booking.status} t={t} />
                </div>
                <div className="min-w-0">
                  <p className="font-black app-text">{booking.client_name}</p>
                  <p className="hidden">
                    {[booking.service?.name || `Serviciu #${booking.service_id}`, booking.service?.type].filter(Boolean).join(' • ')}
                  </p>
                  <p className="text-xs font-semibold app-text-muted">
                    <span>{booking.service?.type || t('general')}</span>
                    <span className="mx-1.5 app-text-muted" aria-hidden="true">•</span>
                    <span>{booking.service?.name || `Serviciu #${booking.service_id}`}</span>
                    {booking.service?.price && <>
                      <span className="mx-1.5 app-text-muted" aria-hidden="true">•</span>
                      <span>{booking.service.price} RON</span>
                    </>}
                    <span className="mx-1.5 app-text-muted" aria-hidden="true">•</span>
                    <span>{booking.location?.name || `Locatie #${booking.location_id}`}</span>
                  </p>
                  {Boolean(booking.staff?.length) && (
                    <p className="text-xs app-text-muted">{booking.staff?.join(' • ')}</p>
                  )}
                  {!!booking.service?.notes && <ServiceNotesPill notes={booking.service.notes} />}
                </div>
                <div className="flex items-center justify-start gap-2 lg:justify-end">
                  {(booking.status === 'pending' || booking.status === 'cancelled') && <SecondaryButton onClick={() => onConfirm(booking)}><CheckCircle2 className="h-4 w-4 text-green-600" /></SecondaryButton>}
                  {booking.status !== 'cancelled' && <SecondaryButton onClick={() => onCancel(booking)}><XCircle className="h-4 w-4 text-red-600" /></SecondaryButton>}
                  <SecondaryButton onClick={() => onEdit(booking)} aria-label={t('editBooking')} title={t('editBooking')}><Pencil className="h-4 w-4" /></SecondaryButton>
                  <DangerButton onClick={() => onDelete(booking)}><Trash2 className="h-4 w-4" /></DangerButton>
                </div>
              </div>
            ))}
          </div>
        </Card>
      ))}
    </div>
  );
}

function ServiceNotesPill({ notes }: { notes: string }) {
  const [open, setOpen] = useState(false);
  return (
    <span className="relative inline-flex">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className="inline-flex h-5 items-center gap-1 rounded-full bg-slate-200 px-2 text-[10px] font-black uppercase tracking-wide text-slate-600 transition hover:bg-slate-300 dark:bg-white/10 dark:text-slate-300 dark:hover:bg-white/20"
      >
        <FileText className="h-2.5 w-2.5" />
        Note
      </button>
      {open && (
        <>
          <span className="fixed inset-0 z-40" onClick={() => setOpen(false)} />
          <span className="absolute left-0 top-6 z-50 w-64 rounded-lg border p-3 text-sm shadow-xl app-border app-panel app-text">
            {notes}
          </span>
        </>
      )}
    </span>
  );
}

function bookingEndDate(booking: { date: string; time: string; service?: { duration?: number } | null }): Date {
  const datePart = booking.date.slice(0, 10); // always 'YYYY-MM-DD'
  const [rawH, rawM] = booking.time.split(':');
  const h = parseInt(rawH, 10) || 0;
  const m = parseInt(rawM, 10) || 0;
  const duration = booking.service?.duration ?? 0;
  const totalMinutes = h * 60 + m + duration;
  const [y, mo, d] = datePart.split('-').map(Number);
  const date = new Date(y, mo - 1, d, 0, totalMinutes, 0, 0); // local time, no string parsing ambiguity
  return date;
}

function bookingTimeRange(time: string, durationMinutes?: number | null) {
  if (!durationMinutes) return time;
  const [h, m] = time.split(':').map(Number);
  const totalEnd = h * 60 + m + durationMinutes;
  const endH = Math.floor(totalEnd / 60) % 24;
  const endM = totalEnd % 60;
  return `${time} - ${String(endH).padStart(2, '0')}:${String(endM).padStart(2, '0')}`;
}

function formatBookingDay(date: string) {
  return new Intl.DateTimeFormat('ro-RO', {
    weekday: 'long',
    day: '2-digit',
    month: 'long',
    year: 'numeric',
  }).format(new Date(`${date}T00:00:00`));
}

function BookingsCalendar({ bookings, t }: { bookings: Salon['bookings']; t: (key: string) => string }) {
  const { locale } = usePage<{ locale?: string }>().props;
  const dateLocale = locale === 'en' ? 'en-GB' : 'ro-RO';
  const today = new Date();
  const todayKey = toDateKey(today);
  const [visibleMonth, setVisibleMonth] = useState(() => new Date(today.getFullYear(), today.getMonth(), 1));
  const monthStart = new Date(visibleMonth.getFullYear(), visibleMonth.getMonth(), 1);
  const firstDayOffset = (monthStart.getDay() + 6) % 7;
  const daysInMonth = new Date(visibleMonth.getFullYear(), visibleMonth.getMonth() + 1, 0).getDate();
  const cells = Array.from({ length: firstDayOffset + daysInMonth }, (_, index) => index < firstDayOffset ? null : index - firstDayOffset + 1);
  const monthLabel = new Intl.DateTimeFormat(dateLocale, { month: 'long', year: 'numeric' }).format(visibleMonth);
  const weekDays = Array.from({ length: 7 }, (_, index) => (
    new Intl.DateTimeFormat(dateLocale, { weekday: 'short' }).format(new Date(2024, 0, index + 1))
  ));

  function changeMonth(offset: number) {
    setVisibleMonth((month) => new Date(month.getFullYear(), month.getMonth() + offset, 1));
  }

  return (
    <div className="p-4">
      <div className="mb-4 flex items-center justify-between">
        <h3 className="text-base font-black capitalize app-text">{monthLabel}</h3>
        <div className="flex items-center gap-2">
          <button
            type="button"
            aria-label={t('previousMonth')}
            onClick={() => changeMonth(-1)}
            className="flex h-9 w-9 items-center justify-center rounded-lg border app-panel app-text-soft hover:bg-[var(--app-panel-soft)]"
          >
            <ChevronLeft className="h-4 w-4" />
          </button>
          <button
            type="button"
            aria-label={t('nextMonth')}
            onClick={() => changeMonth(1)}
            className="flex h-9 w-9 items-center justify-center rounded-lg border app-panel app-text-soft hover:bg-[var(--app-panel-soft)]"
          >
            <ChevronRight className="h-4 w-4" />
          </button>
        </div>
      </div>
      <div className="grid grid-cols-7 border-l border-t app-border text-xs font-black uppercase app-text-muted">
        {weekDays.map((day) => (
          <div key={day} className="border-b border-r p-2 app-border">{day}</div>
        ))}
      </div>
      <div className="grid grid-cols-7 border-l app-border">
        {cells.map((day, index) => {
          const dateKey = day ? `${visibleMonth.getFullYear()}-${String(visibleMonth.getMonth() + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}` : '';
          const dayBookings = bookings.filter((booking) => booking.date === dateKey);
          const isPast = Boolean(day && dateKey < todayKey);

          return (
            <div
              key={`${day ?? 'blank'}-${index}`}
              aria-disabled={isPast || undefined}
              className={`min-h-28 border-b border-r p-2 app-border ${isPast ? 'bg-slate-100/80 text-slate-400 dark:bg-white/5 dark:text-slate-500' : ''}`}
            >
              {day && <p className={`mb-2 text-xs font-black ${isPast ? 'text-slate-400 dark:text-slate-500' : 'app-text'}`}>{day}</p>}
              <div className="space-y-1">
                {dayBookings.slice(0, 3).map((booking) => (
                  <div key={booking.id} className={`truncate rounded-md px-2 py-1 text-xs font-semibold ${isPast ? 'bg-slate-200 text-slate-500 dark:bg-white/10 dark:text-slate-400' : 'bg-indigo-500/10 text-indigo-700 dark:text-indigo-200'}`}>
                    {booking.time} {booking.client_name}
                  </div>
                ))}
                {dayBookings.length > 3 && <p className="text-xs app-text-muted">+{dayBookings.length - 3}</p>}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}

function toDateKey(date: Date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function EditModal({ open, title, onClose, children }: { open: boolean; title: string; onClose: () => void; children: React.ReactNode }) {
  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm">
      <div className="max-h-[calc(100vh-2rem)] w-full max-w-5xl overflow-y-auto rounded-lg border p-5 shadow-xl app-panel">
        <div className="mb-5 flex items-center justify-between gap-4">
          <h2 className="text-lg font-black app-text">{title}</h2>
          <button
            type="button"
            aria-label="Close"
            onClick={onClose}
            className="flex h-9 w-9 items-center justify-center rounded-lg app-text-soft hover:bg-[var(--app-panel-soft)]"
          >
            <X className="h-4 w-4" />
          </button>
        </div>
        {children}
      </div>
    </div>
  );
}

function Toolbar({ title, subtitle, action, hideText = false }: { title: string; subtitle: string; action?: React.ReactNode; hideText?: boolean }) {
  if (hideText && !action) {
    return null;
  }

  return (
    <div className="flex flex-wrap items-center justify-between gap-4">
      {!hideText && (
        <div>
          <h2 className="text-2xl font-black tracking-tight app-text">{title}</h2>
          <p className="text-sm app-text-muted">{subtitle}</p>
        </div>
      )}
      {action}
    </div>
  );
}

function Table({ headers, children }: { headers: (string | React.ReactNode)[]; children: React.ReactNode }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[720px] text-left">
        <thead className="text-xs font-black uppercase tracking-wide app-panel-soft app-text-muted">
          <tr>{headers.map((header, i) => <th key={i} className="px-5 py-3">{header}</th>)}</tr>
        </thead>
        <tbody>{children}</tbody>
      </table>
    </div>
  );
}
