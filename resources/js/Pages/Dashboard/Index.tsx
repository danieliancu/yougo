import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { AlertModal, Badge, Button, Card, ConfirmationModal, DangerButton, Field, Input, SecondaryButton, ThemeToggle } from '@/Components/Ui';
import type { ActivityChartRow } from '@/Components/ActivityChart';
import { PricingPlansGrid, VoicePlanKey } from '@/Components/PricingPlansGrid';
import { YouGoCopilot } from '@/Components/YouGoCopilot';
import { Booking, Conversation, Location as SalonLocation, OfferedService, OnboardingChecklist, OnboardingStep, OverviewData, PageProps, Plan, Salon, Service, Staff, UsageSummary, User as AuthUser } from '@/types';
import { AlertTriangle, Bell, Bot, Building2, Calendar, Check, CheckCircle2, ChevronDown, ChevronLeft, ChevronRight, Clock, CreditCard, Download, ExternalLink, FileText, Globe2, LayoutDashboard, List, Lock, LogOut, MapPin, Menu, MessageCircle, MessageSquare, MoreHorizontal, Pencil, Phone, Plus, QrCode, Save, Scissors, Search, Settings, Smartphone, Sparkles, Trash2, User, Users, X, XCircle } from 'lucide-react';
import { SiWhatsapp } from 'react-icons/si';
import { FormEvent, lazy, ReactNode, Suspense, useEffect, useMemo, useRef, useState } from 'react';
import { useT } from '@/i18n';
import { businessTaxonomy, findBusinessType, normalizeBusinessTypeSlug } from '@/data/businessTaxonomy';
import { integrationStatusLabel, planHasService, serviceEntitlementLabel, serviceIsLive, serviceStatusLabel, serviceByKey } from '@/lib/yougoServices';
import { preferredLocale, rememberLocale } from '@/lib/localePreference';

const ActivityChart = lazy(() => import('@/Components/ActivityChart'));

type LocalizationCountryOption = {
  code: string;
  label: string;
  currency: string;
  phone_prefix: string;
  default_timezone: string;
  default_language: string;
  date_formats: string[];
  default_date_format: string;
  time_format: string;
};
type CurrencyOption = {
  code: string;
  label: string;
};
type LocalizationProps = {
  countries: LocalizationCountryOption[];
  timezones: string[];
  date_formats: string[];
  service_currencies: CurrencyOption[];
  defaults: {
    country: string;
    currency: string;
    phone_prefix: string;
    timezone: string;
    date_format: string;
    default_language: string;
  };
};

type Props = PageProps<{
  section: 'overview' | 'onboarding' | 'ai-settings' | 'conversations' | 'voice-calls' | 'whatsapp' | 'locations' | 'staff' | 'services' | 'bookings' | 'widget' | 'billing' | 'settings';
  salon: Salon;
  overview: OverviewData;
  onboarding: OnboardingChecklist;
  billing: {
    summary: UsageSummary;
    plans: Plan[];
    services: OfferedService[];
  };
  localization: LocalizationProps;
  appUrl: string;
}>;

type TranslateFn = (key: string, params?: Record<string, string | number>) => string;
type DateRange = { start: string; end: string };
type ImportedServiceCandidate = {
  name: string;
  category: string;
  duration_minutes: number | '';
  price: string;
  description: string;
  notes: string;
  selected: boolean;
  duplicate?: boolean;
};

const SERVICE_IMPORT_MAX_IMAGE_BYTES = 8 * 1024 * 1024;
const TABLE_PILL_CLASS = 'inline-flex items-center justify-center whitespace-nowrap rounded-md font-semibold uppercase tracking-wide min-w-28 px-2 py-1 text-[10px]';

type DashboardSection = Props['section'];
type NavItem = {
  id: DashboardSection;
  label: string;
  href: string;
  icon: typeof LayoutDashboard;
};
type NavGroup = {
  id: 'assistantSettings' | 'administration';
  label: string;
  items: NavItem[];
};

const topLevelNavItems: NavItem[] = [
  { id: 'overview', label: 'overview', href: '/dashboard', icon: LayoutDashboard },
  { id: 'onboarding', label: 'setup', href: '/dashboard/onboarding', icon: List },
  { id: 'bookings', label: 'bookings', href: '/dashboard/bookings', icon: Calendar },
  { id: 'conversations', label: 'conversations', href: '/dashboard/conversations', icon: MessageSquare },
];

const navGroups: NavGroup[] = [
  {
    id: 'assistantSettings',
    label: 'navGroupAssistantSettings',
    items: [
      { id: 'ai-settings', label: 'businessBehavior', href: '/dashboard/ai-settings', icon: Sparkles },
      { id: 'widget', label: 'chatSettings', href: '/dashboard/widget', icon: MessageCircle },
      { id: 'whatsapp', label: 'whatsappSettings', href: '/dashboard/whatsapp', icon: MessageCircle },
      { id: 'voice-calls', label: 'phoneSettings', href: '/dashboard/voice-calls', icon: Phone },
    ],
  },
  {
    id: 'administration',
    label: 'navGroupAdministration',
    items: [
      { id: 'services', label: 'services', href: '/dashboard/services', icon: Scissors },
      { id: 'staff', label: 'staff', href: '/dashboard/staff', icon: Users },
      { id: 'locations', label: 'locations', href: '/dashboard/locations', icon: MapPin },
      { id: 'billing', label: 'billing', href: '/dashboard/billing', icon: CreditCard },
      { id: 'settings', label: 'settings', href: '/dashboard/settings', icon: Settings },
    ],
  },
];

const nav = [...topLevelNavItems, ...navGroups.flatMap((group) => group.items)];

function navGroupForSection(section: DashboardSection) {
  return navGroups.find((group) => group.items.some((item) => item.id === section));
}

const navGroupIds = navGroups.map((group) => group.id);
const sidebarOpenGroupsStorageKey = 'yougo-dashboard-open-groups';

const defaultOpenGroups = navGroupIds.reduce((groups, id) => ({
  ...groups,
  [id]: false,
}), {} as Record<NavGroup['id'], boolean>);

export default function DashboardIndex() {
  const t = useT();
  const { auth, salon, section, locale, overview, onboarding, billing, localization } = usePage<Props>().props;
  const titleKey = section === 'locations'
    ? 'salonLocations'
    : nav.find((item) => item.id === section)?.label ?? section;
  const title = t(titleKey);
  const headerSubtitles: Partial<Record<Props['section'], string>> = {
    overview: t('overviewSubtitle'),
    onboarding: t('onboardingPageHelper'),
    'ai-settings': t('aiSettingsSubtitle'),
    conversations: t('conversationSubtitle'),
    'voice-calls': t('voiceCallsSubtitle'),
    whatsapp: t('whatsappSubtitle'),
    locations: t('locationsSubtitle'),
    staff: t('staffSubtitle'),
    services: t('servicesSubtitle'),
    bookings: t('bookingsSubtitle'),
    widget: t('websiteChatSubtitle'),
    billing: t('billingSubtitle'),
    settings: t('settingsSubtitle'),
  };
  const headerSubtitle = headerSubtitles[section] ?? '';
  const [mobileNavOpen, setMobileNavOpen] = useState(false);
  const [query, setQuery] = useState('');
  const activeLocale = preferredLocale(locale);

  const searchSections: Partial<Record<Props['section'], string>> = {
    conversations: t('searchConversations'),
    'voice-calls': t('searchByPhoneOrTranscript'),
    whatsapp: t('searchWhatsappConversations'),
    services: t('searchServices'),
    staff: t('searchStaff'),
    bookings: t('searchBookings'),
  };

  useEffect(() => { setQuery(''); }, [section]);

  const pollingRef = useRef<ReturnType<typeof setInterval> | null>(null);
  useEffect(() => {
    if (section !== 'conversations' && section !== 'overview') return;

    function refreshDashboardData() {
      if (document.hidden) return;

      router.visit(window.location.href, {
        only: ['salon', 'overview'],
        preserveScroll: true,
        preserveState: true,
        replace: true,
      });
    }

    pollingRef.current = setInterval(refreshDashboardData, 5000);
    document.addEventListener('visibilitychange', refreshDashboardData);

    return () => {
      if (pollingRef.current) clearInterval(pollingRef.current);
      document.removeEventListener('visibilitychange', refreshDashboardData);
    };
  }, [section]);

  function switchLanguage(displayLanguage: 'ro' | 'en') {
    if (displayLanguage === activeLocale || !auth.user) return;

    rememberLocale(displayLanguage);
    router.post('/settings', {
      name: auth.user.name,
      business_name: salon.name,
      timezone: salon.timezone ?? localization.defaults.timezone,
      business_type: normalizeBusinessTypeSlug(salon.business_type) || 'salon-beauty',
      country: salon.country ?? localization.defaults.country,
      website: salon.website ?? '',
      business_phone: salon.business_phone ?? '',
      notification_email: salon.notification_email ?? '',
      missed_call_alerts: Boolean(salon.missed_call_alerts ?? true),
      booking_confirmations: Boolean(salon.booking_confirmations ?? true),
      booking_status_email_notifications: Boolean(salon.booking_status_email_notifications ?? false),
      display_language: displayLanguage,
      date_format: salon.date_format ?? localization.defaults.date_format,
    }, {
      preserveScroll: true,
    });
  }

  return (
    <div className="flex min-h-screen overflow-x-hidden app-bg lg:h-screen lg:overflow-hidden">
      <Head title={title} />
      <DashboardSidebar salon={salon} section={section} user={auth.user} t={t} onboarding={onboarding} />

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
            <DashboardSidebarContent salon={salon} section={section} user={auth.user} t={t} onboarding={onboarding} onNavigate={() => setMobileNavOpen(false)} />
          </div>
        </div>
      )}

      <main className="flex min-h-0 min-w-0 flex-1 flex-col lg:ml-72 lg:h-screen">
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
              <h1 className="truncate text-lg font-bold app-text">{title}</h1>
              {headerSubtitle && <p className="truncate text-xs font-medium app-text-muted">{headerSubtitle}</p>}
            </div>
          </div>
          <div className="flex shrink-0 items-center gap-3">
            {searchSections[section] && <HeaderSearch query={query} onChange={setQuery} placeholder={searchSections[section]!} />}
            <ThemeToggle />
            <LanguageToggle locale={activeLocale} onChange={switchLanguage} />
          </div>
        </header>
        <div className={`min-h-0 min-w-0 flex-1 overflow-x-hidden ${section === 'conversations' ? 'overflow-hidden' : 'overflow-y-auto p-5 lg:p-8'}`}>
          {section === 'overview' && <Overview salon={salon} overview={overview} onboarding={onboarding} />}
          {section === 'onboarding' && <OnboardingSetup onboarding={onboarding} />}
          {section === 'ai-settings' && <AiSettings salon={salon} />}
          {section === 'conversations' && <Conversations salon={salon} query={query} overview={overview} />}
          {section === 'voice-calls' && <VoiceCalls query={query} />}
          {section === 'whatsapp' && <WhatsAppConversations query={query} />}
          {section === 'locations' && <Locations salon={salon} />}
          {section === 'staff' && <StaffManagement salon={salon} query={query} />}
          {section === 'services' && <Services salon={salon} query={query} />}
          {section === 'bookings' && <Bookings salon={salon} query={query} />}
          {section === 'widget' && <WidgetSettings salon={salon} query={query} />}
          {section === 'billing' && <BillingPage billing={billing} currentPlan={salon.plan ?? 'free'} />}
          {section === 'settings' && <SettingsPage salon={salon} />}
        </div>
      </main>
      <YouGoCopilot
        locale={activeLocale}
        context={{
          surface: 'dashboard',
          authenticated: Boolean(auth.user),
          current_section: section,
          plan_key: salon.plan ?? 'free',
          business_name: salon.name,
        }}
      />
    </div>
  );
}

function DashboardSidebar({ salon, section, user, t, onboarding }: { salon: Salon; section: Props['section']; user: AuthUser | null; t: TranslateFn; onboarding: OnboardingChecklist }) {
  return (
    <aside className="fixed inset-y-0 left-0 z-40 hidden h-screen w-72 shrink-0 flex-col overflow-hidden app-sidebar lg:flex">
      <div className="shrink-0 border-b border-white/10 p-6">
        <Brand salon={salon} />
      </div>
      <DashboardSidebarContent salon={salon} section={section} user={user} t={t} onboarding={onboarding} />
    </aside>
  );
}

function Brand({ salon, onClick }: { salon: Salon; onClick?: () => void }) {
  const planName = planDisplayName(salon.plan);

  return (
    <Link href="/" className="flex w-fit flex-col gap-3" onClick={onClick} style={{ alignItems:"flex-start" }}>
      <img src="/images/logo-dark.png" className="h-12 w-auto shrink-0" alt="YouGo" style={{ objectFit:"contain" }} />
      <div className="flex items-center gap-2.5">
        {salon.logo_path ? (
          <img src={`/storage/${salon.logo_path}`} className="h-7 w-7 shrink-0 rounded-md object-cover" alt={salon.name} />
        ) : (
          <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-white/10 text-xs font-bold text-white">
            {salon.name.slice(0, 1).toUpperCase()}
          </span>
        )}
        <span className="min-w-0">
          <span className="block truncate text-sm font-bold text-white">{salon.name}</span>
          <span className="block truncate text-[11px] font-medium uppercase tracking-wide text-slate-400">{planName}</span>
        </span>
      </div>
    </Link>
  );
}

function planDisplayName(plan?: string | null): string {
  if (! plan) return 'Free';

  const labels: Record<string, string> = {
    connect: 'Chat + WhatsApp',
    voice: 'Voice Starter',
    enterprise: 'Voice Pro',
    website_chat: 'Website Chat',
    chat_whatsapp: 'Chat + WhatsApp',
    voice_starter: 'Voice Starter',
    voice_growth: 'Voice Growth',
    voice_pro: 'Voice Pro',
  };

  if (labels[plan]) {
    return labels[plan];
  }

  return plan
    .split(/[-_\s]+/)
    .filter(Boolean)
    .map((part) => part.slice(0, 1).toUpperCase() + part.slice(1))
    .join(' ');
}

function NavCountBadge({ count }: { count: number }) {
  return (
    <span className="ml-auto inline-flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-red-600 px-1.5 text-[10px] font-bold leading-none text-white">
      {count}
    </span>
  );
}

function DashboardSidebarContent({ salon, section, user, t, onboarding, onNavigate }: { salon: Salon; section: Props['section']; user: AuthUser | null; t: TranslateFn; onboarding: OnboardingChecklist; onNavigate?: () => void }) {
  const activeGroup = navGroupForSection(section);
  const [openGroups, setOpenGroups] = useState<Record<NavGroup['id'], boolean>>(() => {
    const stored = storedSidebarOpenGroups();
    const hasStored = Object.keys(stored).length > 0;

    return {
      ...defaultOpenGroups,
      ...stored,
      ...(!hasStored && activeGroup ? { [activeGroup.id]: true } : {}),
    };
  });
  const pendingBookingsCount = salon.bookings.filter((booking) => booking.status === 'pending').length;
  const remainingSetupCount = onboarding.steps.filter((step) => !step.completed && !step.coming_soon).length;

  return (
    <>
      <nav className="min-h-0 flex-1 space-y-2 overflow-y-auto p-4">
        <div className="space-y-1 pb-1">
          {topLevelNavItems.map((item) => {
            const Icon = item.icon;
            const active = item.id === section;

            return (
              <Link
                key={item.id}
                href={item.href}
                onClick={onNavigate}
                className={`flex items-center gap-3 rounded-lg px-4 py-3 text-sm font-bold transition ${active ? 'bg-indigo-600 text-white' : 'text-slate-400 hover:bg-white/10 hover:text-white'}`}
              >
                <Icon className="h-4 w-4 shrink-0" />
                <span className="min-w-0 flex-1 truncate">{t(item.label)}</span>
                {item.id === 'bookings' && pendingBookingsCount > 0 && <span className="railway-lights shrink-0" aria-hidden="true" />}
                {item.id === 'onboarding' && remainingSetupCount > 0 && <NavCountBadge count={remainingSetupCount} />}
                {item.id === 'bookings' && pendingBookingsCount > 0 && <NavCountBadge count={pendingBookingsCount} />}
              </Link>
            );
          })}
        </div>
        {navGroups.map((group) => {
          const open = openGroups[group.id];

          return (
            <section key={group.id} className="rounded-lg">
              <button
                type="button"
                onClick={() => setOpenGroups((groups) => {
                  const next = { ...groups, [group.id]: !groups[group.id] };
                  storeSidebarOpenGroups(next);

                  return next;
                })}
                aria-expanded={open}
                className="flex h-9 w-full items-center gap-3 rounded-lg px-3 text-left text-[11px] font-bold uppercase tracking-wide text-white transition hover:bg-white/5"
              >
                <span className="min-w-0 flex-1 truncate">{t(group.label)}</span>
                <ChevronDown className={`h-3.5 w-3.5 shrink-0 text-slate-400 transition-transform duration-200 ease-out ${open ? 'rotate-180' : ''}`} />
              </button>

              <div className={`grid transition-[grid-template-rows,opacity] duration-200 ease-out ${open ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'}`}>
                <div className="min-h-0 overflow-hidden">
                  <div className="space-y-1 pb-1 pt-1">
                    {group.items.map((item) => {
                      const Icon = item.icon;
                      const active = item.id === section;
                      const showsAccountEmail = item.id === 'settings';

                      return (
                        <Link
                          key={item.id}
                          href={item.href}
                          onClick={onNavigate}
                          className={`flex items-center gap-3 rounded-lg px-4 py-3 text-sm font-bold transition ${active ? 'bg-indigo-600 text-white' : 'text-slate-400 hover:bg-white/10 hover:text-white'}`}
                        >
                          {showsAccountEmail && <Icon className="h-4 w-4 shrink-0" />}
                          {showsAccountEmail ? (
                            <span className="min-w-0 flex-1">
                              <span className={`block truncate text-sm font-bold ${active ? 'text-white' : 'text-slate-400'}`}>{t(item.label)}</span>
                              <span className={`block truncate text-xs font-medium ${active ? 'text-indigo-100' : 'text-slate-500'}`}>{user?.email}</span>
                            </span>
                          ) : (
                            <>
                              <Icon className="h-4 w-4 shrink-0" />
                              <span className="min-w-0 flex-1 truncate">{t(item.label)}</span>
                            </>
                          )}
                          {item.id === 'bookings' && pendingBookingsCount > 0 && <span className="railway-lights shrink-0" aria-hidden="true" />}
                          {item.id === 'onboarding' && remainingSetupCount > 0 && <NavCountBadge count={remainingSetupCount} />}
                          {item.id === 'bookings' && pendingBookingsCount > 0 && <NavCountBadge count={pendingBookingsCount} />}
                        </Link>
                      );
                    })}
                    {group.id === 'administration' && (
                      <button
                        type="button"
                        onClick={() => {
                          onNavigate?.();
                          router.post('/logout');
                        }}
                        className="flex w-full items-center gap-3 rounded-lg px-4 py-3 text-sm font-bold text-red-300 transition hover:bg-red-500/10 hover:text-red-200"
                      >
                        <LogOut className="h-4 w-4 shrink-0" />
                        <span className="min-w-0 flex-1 truncate text-left">{t('logout')}</span>
                      </button>
                    )}
                  </div>
                </div>
              </div>
            </section>
          );
        })}
      </nav>
    </>
  );
}

function storedSidebarOpenGroups(): Partial<Record<NavGroup['id'], boolean>> {
  if (typeof window === 'undefined') return {};

  try {
    const stored = window.sessionStorage.getItem(sidebarOpenGroupsStorageKey);
    if (! stored) return {};

    const parsed = JSON.parse(stored) as Partial<Record<NavGroup['id'], boolean>>;

    return navGroupIds.reduce((groups, id) => ({
      ...groups,
      ...(typeof parsed[id] === 'boolean' ? { [id]: parsed[id] } : {}),
    }), {} as Partial<Record<NavGroup['id'], boolean>>);
  } catch {
    return {};
  }
}

function storeSidebarOpenGroups(groups: Record<NavGroup['id'], boolean>): void {
  if (typeof window === 'undefined') return;

  window.sessionStorage.setItem(sidebarOpenGroupsStorageKey, JSON.stringify(groups));
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
        className="flex h-10 min-w-20 items-center justify-center gap-2 rounded-lg border px-3 text-xs font-bold uppercase app-panel app-text-soft hover:bg-[var(--app-panel-soft)]"
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
          className="h-10 w-full rounded-lg border pl-10 pr-10 text-sm outline-none placeholder:text-[var(--app-text-muted)] focus:border-indigo-500 app-panel"
          value={query}
          onChange={(event) => onChange(event.target.value)}
          placeholder={placeholder}
          aria-label={placeholder}
        />
        {query.length >= 3 && (
          <button
            type="button"
            aria-label="Clear search"
            onClick={() => onChange('')}
            className="absolute right-1 top-1/2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-md app-text-muted transition hover:app-text"
          >
            <X className="h-3.5 w-3.5" />
          </button>
        )}
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
              <p className="text-sm font-bold app-text">{placeholder}</p>
              <button
                type="button"
                aria-label="Close search"
                onClick={() => setOpen(false)}
                className="flex h-11 w-11 items-center justify-center rounded-lg app-text-soft hover:bg-[var(--app-panel-soft)]"
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
                aria-label={placeholder}
              />
            </div>
          </div>
        </div>
      )}
    </>
  );
}

function WidgetSettings({ salon, query }: { salon: Salon; query: string }) {
  const t = useT();
  const { appUrl } = usePage<Props>().props;
  const embedCode = `<script async src="${appUrl}/widget/${salon.widget_key}.js"></script>`;
  const [domainsText, setDomainsText] = useState((salon.widget_allowed_domains ?? []).join('\n'));
  const [copied, setCopied] = useState(false);
  const conversations = filterWebsiteChatConversations(salon.conversations, query);
  const stats = websiteChatStats(conversations);
  const form = useForm({
    widget_enabled: Boolean(salon.widget_enabled ?? true),
    widget_allowed_domains: salon.widget_allowed_domains ?? [],
    widget_primary_color: salon.widget_primary_color ?? '',
    widget_position: salon.widget_position ?? 'bottom-right',
  });

  function submit(event: FormEvent) {
    event.preventDefault();
    form.transform((data) => ({
      ...data,
      widget_allowed_domains: domainsText
        .split(/[\n,]+/)
        .map((domain) => domain.trim())
        .filter(Boolean),
    }));
    form.put('/widget-settings', { preserveScroll: true });
  }

  async function copyEmbedCode() {
    await navigator.clipboard?.writeText(embedCode);
    setCopied(true);
    window.setTimeout(() => setCopied(false), 1600);
  }

  return (
    <div className="space-y-6">
      <div className="flex justify-end">
        <SecondaryButton
          type="button"
          disabled={conversations.length === 0}
          onClick={() => exportConversationsCsv(conversations, 'website-chat-conversations', salon.timezone)}
        >
          <Download className="h-4 w-4" />
          {t('exportCsv')}
        </SecondaryButton>
      </div>

      <div className="grid gap-4 md:grid-cols-3">
        <ChannelStat icon={MessageSquare} value={stats.total} label={t('totalChat')} tone="blue" />
        <ChannelStat icon={CheckCircle2} value={stats.completed} label={t('completedChats')} tone="green" />
        <ChannelStat icon={XCircle} value={stats.abandoned} label={t('abandonedChats')} tone="slate" />
      </div>

      <Card className="p-6">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <h2 className="text-xl font-bold app-text">{t('previewAssistantTitle')}</h2>
            <p className="mt-2 max-w-2xl text-sm app-text-muted">{t('previewAssistantHelp')}</p>
          </div>
          <a href={`/assistant/${salon.id}`} target="_blank" rel="noreferrer" className="inline-flex h-11 items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 text-sm font-bold text-white transition hover:bg-indigo-700">
            <ExternalLink className="h-4 w-4" />
            {t('openPreview')}
          </a>
        </div>
      </Card>

      <Card className="p-6">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
          <div>
            <h2 className="text-lg font-bold app-text">{t('widgetReadinessTitle')}</h2>
            <p className="mt-2 max-w-3xl text-sm leading-6 app-text-muted">{t('widgetReadinessIntro')}</p>
          </div>
          <span className="inline-flex h-9 items-center gap-2 rounded-lg border px-3 text-xs font-bold app-panel app-text-soft">
            <CheckCircle2 className="h-4 w-4 text-emerald-600" />
            MVP
          </span>
        </div>
        <div className="mt-5 grid gap-3 md:grid-cols-2">
          {['widgetReadinessPreview', 'widgetReadinessCopyCode', 'widgetReadinessDomains', 'widgetReadinessRules', 'widgetReadinessTestPage'].map((key) => (
            <div key={key} className="flex gap-3 rounded-lg border p-3 app-border app-panel-soft">
              <Check className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />
              <p className="text-sm font-medium app-text-soft">{t(key)}</p>
            </div>
          ))}
        </div>
      </Card>

      <form onSubmit={submit}>
        <Card className="p-6">
          <div className="flex flex-col gap-2">
            <h2 className="text-xl font-bold app-text">{t('widgetSettings')}</h2>
            <p className="max-w-2xl text-sm app-text-muted">{t('installWidgetHelp')}</p>
            <p className="text-sm font-medium app-text-soft">{t('widgetUsesConfiguredRules')}</p>
          </div>

          <div className="mt-6 rounded-lg border p-4 app-panel-soft app-border">
            <p className="mb-3 text-sm font-bold app-text">{t('embedCode')}</p>
            <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
              <code className="block min-w-0 overflow-x-auto whitespace-nowrap rounded-md px-3 py-2 text-xs font-bold app-panel app-text">{embedCode}</code>
              <SecondaryButton type="button" onClick={copyEmbedCode}>{copied ? t('copied') : t('copyCode')}</SecondaryButton>
            </div>
          </div>

          <div className="mt-6 grid gap-4 lg:grid-cols-2">
            <label className="flex items-center justify-between gap-4 rounded-lg border p-4 app-panel app-border">
              <span>
                <span className="block text-sm font-bold app-text">{t('widgetEnabled')}</span>
                <span className="block text-xs font-medium app-text-muted">{t('widgetEnabledHelp')}</span>
              </span>
              <input type="checkbox" checked={form.data.widget_enabled} onChange={(event) => form.setData('widget_enabled', event.target.checked)} className="h-5 w-5 rounded border-slate-300 text-indigo-600" />
            </label>

            <Field label={t('widgetPosition')} error={form.errors.widget_position}>
              <select value={form.data.widget_position} onChange={(event) => form.setData('widget_position', event.target.value)} className="h-11 w-full rounded-lg border px-3 text-sm font-medium app-panel app-text">
                <option value="bottom-right">bottom-right</option>
                <option value="bottom-left">bottom-left</option>
              </select>
            </Field>

            <Field label={t('widgetPrimaryColor')} error={form.errors.widget_primary_color}>
              <div className="flex gap-3">
                <input type="color" value={form.data.widget_primary_color || '#2563eb'} onChange={(event) => form.setData('widget_primary_color', event.target.value)} className="h-11 w-16 rounded-lg border app-panel app-border" />
                <Input value={form.data.widget_primary_color} onChange={(event) => form.setData('widget_primary_color', event.target.value)} placeholder="#2563eb" />
              </div>
            </Field>

            <Field label={t('allowedDomains')} error={form.errors.widget_allowed_domains}>
              <textarea value={domainsText} onChange={(event) => setDomainsText(event.target.value)} rows={4} placeholder="example.com&#10;www.example.ro" className="w-full rounded-lg border px-3 py-2 text-sm font-medium app-panel app-text placeholder:text-[var(--app-text-muted)]" />
              <p className="mt-2 text-xs font-medium app-text-muted">{t('allowedDomainsHelp')}</p>
            </Field>
          </div>

          <div className="mt-6 flex flex-wrap gap-3">
            <Button type="submit" disabled={form.processing}>
              <Save className="h-4 w-4" />
              {t('saveChanges')}
            </Button>
            <a href={`/assistant/${salon.id}`} target="_blank" rel="noreferrer" className="inline-flex h-11 items-center justify-center gap-2 rounded-lg border px-4 text-sm font-bold transition app-panel app-text-soft hover:bg-[var(--app-panel-soft)]">
              <ExternalLink className="h-4 w-4" />
              {t('openPreview')}
            </a>
          </div>
        </Card>
      </form>
    </div>
  );
}

function filterWebsiteChatConversations(conversations: Conversation[], query: string) {
  const normalizedQuery = query.trim().toLowerCase();

  return conversations
    .filter((conversation) => conversation.channel === 'chat' || conversation.channel === 'web_widget')
    .filter((conversation) => {
      if (!normalizedQuery) return true;

      return [
        conversation.contact_name,
        conversation.contact_phone,
        conversation.contact_email,
        conversation.summary,
        conversation.intent,
        conversation.status,
        ...conversation.messages.map((message) => message.content),
      ].filter(Boolean).some((value) => String(value).toLowerCase().includes(normalizedQuery));
    });
}

function websiteChatStats(conversations: Conversation[]) {
  return {
    total: conversations.length,
    completed: conversations.filter((conversation) => conversation.status === 'completed').length,
    abandoned: conversations.filter((conversation) => conversation.intent === 'abandoned').length,
  };
}

function normalizeLocalizationCountry(country: string | null | undefined, localization: LocalizationProps) {
  const normalized = (country || localization.defaults.country || 'RO').toUpperCase() === 'UK'
    ? 'GB'
    : (country || localization.defaults.country || 'RO').toUpperCase();

  return localization.countries.some((option) => option.code === normalized)
    ? normalized
    : localization.defaults.country;
}

function normalizeDateFormatForUi(dateFormat?: string | null) {
  const normalized = dateFormat?.trim().toLowerCase();

  if (!normalized) return null;
  if (normalized === 'dd.mm.yyyy.' || normalized === 'dd.mm.yyyy') return 'dd.mm.yyyy';
  if (normalized === 'dd/mm/yyyy' || normalized === 'dd-mm-yyyy') return 'dd/mm/yyyy';
  if (normalized === 'yyyy-mm-dd' || normalized === 'yyyy/mm/dd') return 'yyyy-mm-dd';

  return normalized;
}

function dateFormatExample(dateFormat: string) {
  if (dateFormat === 'yyyy-mm-dd') return '2026-05-27';
  if (dateFormat === 'dd/mm/yyyy') return '27/05/2026';

  return '27.05.2026';
}

function businessPhoneForInput(phone: string | null | undefined, prefix: string) {
  const value = (phone ?? '').trim();
  if (!value || !prefix) return value;

  return value.startsWith(prefix)
    ? value.slice(prefix.length).trimStart()
    : value;
}

function businessPhoneForSubmit(phone: string, prefix: string) {
  const value = phone.trim();
  if (!value) return '';
  if (!prefix || value.startsWith('+')) return value;

  return `${prefix} ${value}`;
}

function defaultServiceCurrency(salon: Pick<Salon, 'currency' | 'country'>) {
  const normalizedCountry = (salon.country || '').toUpperCase() === 'UK' ? 'GB' : (salon.country || '').toUpperCase();

  return (salon.currency || (normalizedCountry === 'GB' ? 'GBP' : 'RON')).toUpperCase();
}

function serviceCurrencyOptions(localization: LocalizationProps, salon: Pick<Salon, 'currency' | 'country'>) {
  const fallback = defaultServiceCurrency(salon);
  const options = localization.service_currencies.length > 0
    ? localization.service_currencies
    : [fallback, 'EUR', 'GBP', 'USD'].map((code) => ({ code, label: code }));

  return Array.from(new Map([
    ...options,
    { code: fallback, label: fallback },
  ].map((option) => [option.code, option])).values());
}

function SettingsPage({ salon }: { salon: Salon }) {
  const t = useT();
  const { auth, billing, localization } = usePage<Props>().props;
  const [showDeleteAccount, setShowDeleteAccount] = useState(false);
  const [logoPreviewUrl, setLogoPreviewUrl] = useState<string | null>(null);
  const initialBusinessType = normalizeBusinessTypeSlug(salon.business_type) || 'salon-beauty';
  const initialCountry = normalizeLocalizationCountry(salon.country, localization);
  const initialCountryOption = localization.countries.find((country) => country.code === initialCountry) ?? localization.countries[0];
  const currentPlanKey = canonicalPlanKey(salon.plan);
  const currentPlan = billing.plans.find((plan) => plan.key === currentPlanKey);
  const paidEmailSettingsAvailable = Boolean(currentPlan?.email_notifications_enabled);
  const phoneAiService = serviceByKey(billing.services, 'phone_ai');
  const missedCallAlertsAvailable = planHasService(currentPlan, 'phone_ai') && serviceIsLive(phoneAiService);
  const form = useForm({
    name: auth.user?.name ?? '',
    business_name: salon.name ?? '',
    timezone: salon.timezone ?? initialCountryOption?.default_timezone ?? localization.defaults.timezone,
    business_type: initialBusinessType,
    country: initialCountry,
    currency: salon.currency ?? initialCountryOption?.currency ?? localization.defaults.currency,
    phone_prefix: salon.phone_prefix ?? initialCountryOption?.phone_prefix ?? localization.defaults.phone_prefix,
    website: salon.website ?? '',
    business_phone: businessPhoneForInput(salon.business_phone, salon.phone_prefix ?? initialCountryOption?.phone_prefix ?? localization.defaults.phone_prefix),
    notification_email: salon.notification_email ?? '',
    missed_call_alerts: missedCallAlertsAvailable ? (salon.missed_call_alerts ?? true) : false,
    booking_confirmations: paidEmailSettingsAvailable ? (salon.booking_confirmations ?? true) : false,
    booking_status_email_notifications: paidEmailSettingsAvailable ? (salon.booking_status_email_notifications ?? false) : false,
    display_language: salon.display_language ?? 'ro',
    date_format: normalizeDateFormatForUi(salon.date_format) ?? initialCountryOption?.default_date_format ?? localization.defaults.date_format,
    logo: null as File | null,
  });
  const logoUrl = logoPreviewUrl ?? (salon.logo_path ? `/storage/${salon.logo_path}` : null);
  const selectedCountry = localization.countries.find((country) => country.code === form.data.country) ?? initialCountryOption;
  const dateFormatOptions = Array.from(new Set([
    ...(selectedCountry?.date_formats ?? localization.date_formats),
    form.data.date_format,
  ].filter(Boolean)));
  const timezoneOptions = Array.from(new Set([
    ...localization.timezones,
    form.data.timezone,
  ].filter(Boolean)));

  useEffect(() => {
    if (!form.data.logo) {
      setLogoPreviewUrl(null);
      return;
    }

    const nextLogoPreviewUrl = URL.createObjectURL(form.data.logo);
    setLogoPreviewUrl(nextLogoPreviewUrl);

    return () => URL.revokeObjectURL(nextLogoPreviewUrl);
  }, [form.data.logo]);

  function submit(event: FormEvent) {
    event.preventDefault();
    form
      .transform((data) => ({
        ...data,
        business_phone: businessPhoneForSubmit(data.business_phone, data.phone_prefix),
        booking_confirmations: paidEmailSettingsAvailable ? data.booking_confirmations : false,
        booking_status_email_notifications: paidEmailSettingsAvailable ? data.booking_status_email_notifications : false,
        missed_call_alerts: missedCallAlertsAvailable ? data.missed_call_alerts : false,
      }));
    form.post('/settings', { forceFormData: true, preserveScroll: true });
  }

  function updateCountry(countryCode: string) {
    const country = localization.countries.find((option) => option.code === countryCode) ?? localization.countries[0];

    if (!country) return;

    form.setData({
      ...form.data,
      country: country.code,
      currency: country.currency,
      phone_prefix: country.phone_prefix,
      timezone: country.default_timezone,
      date_format: country.default_date_format,
    });
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

        <SettingsPanel icon={Globe2} title={t('languageRegion')} subtitle={t('languageRegionSubtitle')}>
          <p className="mb-5 text-sm text-sky-300">{t('localizationHelp')}</p>
          <div className="grid gap-4 md:grid-cols-2">
            <DarkField label={t('country')} error={form.errors.country}>
              <DarkSelect value={form.data.country} onChange={(event) => updateCountry(event.target.value)}>
                {localization.countries.map((country) => (
                  <option key={country.code} value={country.code}>{country.label}</option>
                ))}
              </DarkSelect>
            </DarkField>
            <DarkField label={t('timezone')} error={form.errors.timezone}>
              <DarkSelect value={form.data.timezone} onChange={(event) => form.setData('timezone', event.target.value)}>
                {timezoneOptions.map((timezone) => (
                  <option key={timezone} value={timezone}>{timezone}</option>
                ))}
              </DarkSelect>
            </DarkField>
            <DarkField label={t('dateFormat')} error={form.errors.date_format}>
              <DarkSelect value={form.data.date_format} onChange={(event) => form.setData('date_format', event.target.value)}>
                {dateFormatOptions.map((dateFormat) => (
                  <option key={dateFormat} value={dateFormat}>{dateFormatExample(dateFormat)}</option>
                ))}
              </DarkSelect>
            </DarkField>
            <DarkField label={t('currency')}>
              <DarkInput value={form.data.currency} disabled />
            </DarkField>
            <DarkField label={t('phonePrefix')}>
              <DarkInput value={form.data.phone_prefix} disabled />
            </DarkField>
            <DarkField label={t('displayLanguage')} error={form.errors.display_language}>
              <DarkSelect value={form.data.display_language} onChange={(event) => form.setData('display_language', event.target.value)}>
                <option value="ro">RO Romana</option>
                <option value="en">EN English</option>
              </DarkSelect>
            </DarkField>
          </div>
        </SettingsPanel>

        <SettingsPanel icon={Building2} title={t('organization')} subtitle={t('organizationSubtitle')}>
          <div className="mb-6">
            <p className="mb-3 text-sm font-bold">{t('businessLogo')}</p>
            <div className="flex items-center gap-4">
              <div className="flex h-16 w-16 items-center justify-center overflow-hidden rounded-lg border border-slate-700 bg-slate-800 text-slate-400">
                {logoUrl ? (
                  <img src={logoUrl} className="h-full w-full object-cover" alt={salon.name ? `${salon.name} logo` : t('businessLogo')} />
                ) : (
                  <Building2 className="h-8 w-8" />
                )}
              </div>
              <div className="min-w-0">
                <label className="inline-flex h-10 cursor-pointer items-center rounded-lg border border-slate-700 px-4 text-sm font-bold hover:bg-slate-900 hover:text-white">
                  {t('uploadLogo')}
                  <input className="hidden" type="file" accept=".png,.jpg,.jpeg,.svg" onChange={(event) => form.setData('logo', event.target.files?.[0] ?? null)} />
                </label>
                {form.data.logo ? (
                  <p className="mt-2 max-w-72 truncate text-xs text-slate-400">{form.data.logo.name}</p>
                ) : null}
              </div>
            </div>
            {form.errors.logo ? <p className="mt-2 text-xs text-red-400">{form.errors.logo}</p> : null}
            <p className="mt-2 text-xs text-sky-300">{t('logoHint')}</p>
          </div>

          <div className="grid gap-4 md:grid-cols-2">
            <DarkField label={t('businessName')} error={form.errors.business_name}>
              <DarkInput value={form.data.business_name} onChange={(event) => form.setData('business_name', event.target.value)} />
            </DarkField>
            <DarkField label={t('businessType')} error={form.errors.business_type}>
              <DarkSelect value={form.data.business_type} onChange={(event) => form.setData('business_type', event.target.value)}>
                <option value="">{t('selectBusinessType')}</option>
                {businessTaxonomy.map((option) => (
                  <option key={option.slug} value={option.slug}>{option.label}</option>
                ))}
              </DarkSelect>
            </DarkField>
            <DarkField label={t('website')} error={form.errors.website}>
              <DarkInput value={form.data.website} onChange={(event) => form.setData('website', event.target.value)} placeholder="https://example.com" />
            </DarkField>
            <DarkField label={t('businessPhone')} error={form.errors.business_phone}>
              <div className="flex h-10 overflow-hidden rounded-lg border border-slate-300 bg-white">
                <span className="inline-flex shrink-0 items-center border-r border-slate-300 bg-slate-100 px-3 text-sm font-bold text-slate-900">
                  {form.data.phone_prefix}
                </span>
                <input
                  className="h-full min-w-0 flex-1 bg-transparent px-3 text-sm text-slate-900 outline-none placeholder:text-slate-400"
                  value={form.data.business_phone}
                  onChange={(event) => form.setData('business_phone', businessPhoneForInput(event.target.value, form.data.phone_prefix))}
                  placeholder="712 345 678"
                />
              </div>
            </DarkField>
          </div>
        </SettingsPanel>

        <SettingsPanel icon={Bell} title={t('notifications')} subtitle={t('notificationsSubtitle')}>
          <DarkField label={t('notificationEmail')} error={form.errors.notification_email}>
            <DarkInput value={form.data.notification_email} onChange={(event) => form.setData('notification_email', event.target.value)} placeholder={t('notificationEmailPlaceholder')} />
          </DarkField>
          <p className="mt-2 text-sm text-sky-300">{t('notificationEmailHelp')}</p>
          <div className="mt-7 divide-y divide-slate-800">
            <div className="py-4">
              <p className="font-medium app-text">{t('emailNotificationsTitle')}</p>
              <p className="mt-0.5 text-sm app-text-muted">{t('emailNotificationsDescription')}</p>
              <div className="mt-3 divide-y divide-slate-800/60">
                <ToggleRow title={t('newBookingEmailsTitle')} subtitle={t('newBookingEmailsDescription')} checked={form.data.booking_confirmations} onChange={(checked) => form.setData('booking_confirmations', checked)} disabled={!paidEmailSettingsAvailable} helper={!paidEmailSettingsAvailable ? t('availableOnPaidPlans') : undefined} />
                <ToggleRow title={t('bookingStatusEmailsTitle')} subtitle={t('bookingStatusEmailsDescription')} checked={form.data.booking_status_email_notifications} onChange={(checked) => form.setData('booking_status_email_notifications', checked)} disabled={!paidEmailSettingsAvailable} helper={!paidEmailSettingsAvailable ? t('availableOnPaidPlans') : undefined} />
              </div>
            </div>
            <ToggleRow title={t('missedCallAlerts')} subtitle={t('missedCallAlertsHelp')} checked={form.data.missed_call_alerts} onChange={(checked) => form.setData('missed_call_alerts', checked)} disabled={!missedCallAlertsAvailable} helper={!missedCallAlertsAvailable ? t('availableWithPhoneAi') : undefined} />
          </div>
        </SettingsPanel>

        <SettingsPanel icon={AlertTriangle} title={t('dangerZone')} subtitle={t('dangerZoneSubtitle')}>
          <div className="flex flex-col gap-4 rounded-lg border border-red-500/30 bg-red-500/5 p-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <p className="font-bold text-red-400">{t('deleteAccount')}</p>
              <p className="mt-1 text-sm app-text-muted">{t('deleteAccountHelp')}</p>
            </div>
            <button
              type="button"
              onClick={() => setShowDeleteAccount(true)}
              className="inline-flex h-10 shrink-0 items-center justify-center gap-2 rounded-lg bg-red-600 px-4 text-sm font-bold text-white hover:bg-red-700"
            >
              <Trash2 className="h-4 w-4" />
              {t('deleteAccount')}
            </button>
          </div>
        </SettingsPanel>
      </div>

      <div className="mt-6 flex justify-start">
        <button disabled={form.processing} className="inline-flex h-11 items-center gap-2 rounded-lg bg-blue-600 px-5 text-sm font-bold text-white hover:bg-blue-700 disabled:opacity-60">
          <Save className="h-4 w-4" />
          {t('saveChanges')}
        </button>
      </div>

      <ConfirmationModal
        open={showDeleteAccount}
        title={t('deleteAccountConfirmTitle')}
        message={t('deleteAccountConfirmMessage')}
        confirmLabel={t('deleteAccountConfirm')}
        cancelLabel={t('cancel')}
        onConfirm={() => router.delete('/account')}
        onCancel={() => setShowDeleteAccount(false)}
      />
    </form>
  );
}

function SettingsPanel({ icon: Icon, title, subtitle, children }: { icon: any; title: string; subtitle: string; children: React.ReactNode }) {
  return (
    <section className="rounded-lg border p-6 app-panel">
      <div className="mb-7 flex items-start gap-3">
        <Icon className="mt-1 h-5 w-5 app-text" />
        <div>
          <h3 className="text-2xl font-bold app-text">{title}</h3>
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
      <span className="mb-2 block text-sm font-bold app-text">{label}</span>
      {children}
      {error && <span className="mt-1 block text-xs font-bold text-red-400">{error}</span>}
    </label>
  );
}

function DarkInput(props: React.InputHTMLAttributes<HTMLInputElement>) {
  return <input {...props} className={`h-10 w-full rounded-lg border px-3 text-sm font-medium outline-none placeholder:text-[var(--app-text-muted)] focus:border-blue-500 disabled:opacity-60 app-panel ${props.className ?? ''}`} />;
}

function DarkSelect(props: React.SelectHTMLAttributes<HTMLSelectElement>) {
  return <select {...props} className={`h-10 w-full rounded-lg border px-3 text-sm font-medium outline-none focus:border-blue-500 app-panel ${props.className ?? ''}`} />;
}

function ToggleRow({ title, subtitle, checked, onChange, disabled = false, helper }: { title: string; subtitle: string; checked: boolean; onChange: (checked: boolean) => void; disabled?: boolean; helper?: string }) {
  return (
    <div className={`flex items-center gap-5 py-7 ${disabled ? 'opacity-55' : ''}`}>
      <button type="button" onClick={() => !disabled && onChange(!checked)} disabled={disabled} aria-disabled={disabled} className={`relative h-6 w-11 shrink-0 rounded-full transition disabled:cursor-not-allowed ${checked && !disabled ? 'bg-blue-600' : 'bg-slate-700'}`}>
        <span className={`absolute top-1 h-4 w-4 rounded-full bg-white transition ${checked ? 'left-6' : 'left-1'}`} />
      </button>
      <div>
        <p className="font-bold app-text">{title}</p>
        <p className="mt-1 text-sm app-text-muted">{subtitle}</p>
        {helper && <p className="mt-2 text-xs font-bold text-sky-300">{helper}</p>}
      </div>
    </div>
  );
}

function serviceIcon(icon: string) {
  if (icon === 'whatsapp') return SiWhatsapp;
  if (icon === 'phone') return Phone;

  return MessageCircle;
}

function IntegrationRow({
  icon: Icon,
  title,
  subtitle,
  status,
  entitlementStatus,
  implementationStatus,
  statusTone,
}: {
  icon: any;
  title: string;
  subtitle: string;
  status: string;
  entitlementStatus: string;
  implementationStatus: string;
  statusTone: 'active' | 'upgrade' | 'planned';
}) {
  const statusClass = statusTone === 'active'
    ? 'bg-green-100 text-green-800'
    : statusTone === 'upgrade'
      ? 'bg-amber-100 text-amber-900'
      : 'bg-slate-700 text-slate-100';

  return (
    <div className="flex flex-col gap-4 py-5 sm:flex-row sm:items-center sm:justify-between">
      <div className="flex items-center gap-4">
        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-100 text-slate-950">
          <Icon className="h-5 w-5" />
        </div>
        <div>
          <p className="font-bold app-text">{title}</p>
          <p className="text-sm app-text-muted">{subtitle}</p>
        </div>
      </div>
      <div className="flex shrink-0 flex-wrap gap-2 sm:justify-end">
        <span className={`rounded-md px-3 py-1 text-xs font-bold ${statusClass}`}>{status}</span>
        <span className="rounded-md bg-[var(--app-panel-soft)] px-3 py-1 text-xs font-bold app-text-soft">{entitlementStatus}</span>
        <span className="rounded-md bg-[var(--app-panel-soft)] px-3 py-1 text-xs font-bold app-text-soft">{implementationStatus}</span>
      </div>
    </div>
  );
}

function PlanServicesOverview({ services, currentPlan }: { services: OfferedService[]; currentPlan: Plan }) {
  const t = useT();

  return (
    <Card className="p-6">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <p className="text-xs font-semibold uppercase tracking-wide app-text-muted">{t('whatYourPlanIncludes')}</p>
          <h2 className="mt-2 text-2xl font-bold app-text">{t('includedServicesTitle')}</h2>
          <p className="mt-2 max-w-3xl text-sm leading-6 app-text-muted">{t('includedServicesSubtitle')}</p>
        </div>
        <span className="inline-flex w-fit rounded-md bg-[var(--app-panel-soft)] px-3 py-1 text-xs font-bold app-text-soft">
          {currentPlan.name}
        </span>
      </div>

      <div className="mt-5 divide-y divide-slate-200">
        {services.map((service) => (
          <IntegrationRow
            key={service.key}
            icon={serviceIcon(service.icon)}
            title={t(service.title_key)}
            subtitle={t(service.subtitle_key)}
            status={integrationStatusLabel(service, currentPlan, t)}
            entitlementStatus={serviceEntitlementLabel(service, currentPlan, t)}
            implementationStatus={serviceStatusLabel(service, t)}
            statusTone={service.implementation_status === 'live' && planHasService(currentPlan, service.key) ? 'active' : service.implementation_status === 'live' ? 'upgrade' : 'planned'}
          />
        ))}
      </div>
    </Card>
  );
}

function Conversations({ salon, query, overview }: { salon: Salon; query: string; overview: OverviewData }) {
  const t = useT();
  const [selectedId, setSelectedId] = useState(salon.conversations[0]?.id ?? null);
  const [deletingId, setDeletingId] = useState<number | null>(null);
  const [channelFilter, setChannelFilter] = useState<'all' | 'voice' | 'chat' | 'whatsapp'>('chat');
  const metrics = overview.metrics;
  const conversations = salon.conversations.filter((conversation) => {
    if (!conversationMatchesChannel(conversation, channelFilter)) return false;

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
      <div className="shrink-0 border-b p-3 app-border">
        <div className="mb-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <ChannelStat label={t('totalConversations')} value={metrics.total_conversations} icon={MessageSquare} tone="blue" compact />
          <ChannelStat label={t('conversationsToday')} value={metrics.conversations_today} icon={Clock} tone="purple" compact />
          <ChannelStat label={t('openConversations')} value={metrics.open_conversations} icon={MessageCircle} tone="green" compact />
          <ChannelStat label={t('abandonedConversations')} value={metrics.abandoned_conversations} icon={XCircle} tone="slate" compact />
        </div>
        <div className="flex flex-wrap gap-2">
          <ConversationFilterButton active={channelFilter === 'voice'} onClick={() => setChannelFilter('voice')} icon={Phone}>{t('phoneCalls')}</ConversationFilterButton>
          <ConversationFilterButton active={channelFilter === 'chat'} onClick={() => setChannelFilter('chat')} icon={MessageSquare}>{t('chat')}</ConversationFilterButton>
          <ConversationFilterButton active={channelFilter === 'whatsapp'} onClick={() => setChannelFilter('whatsapp')} icon={MessageCircle}>{t('whatsapp')}</ConversationFilterButton>
        </div>
      </div>
      {selected ? (
        <div className="grid min-h-0 flex-1 overflow-hidden lg:grid-cols-[320px_minmax(0,1fr)_360px]">
          <aside className="min-h-0 overflow-y-auto border-b app-border app-panel-soft lg:border-b-0 lg:border-r">
            <div className="px-4 py-3 lg:p-4">
              <p className="mb-3 text-xs font-bold uppercase tracking-wide app-text-muted">{conversations.length} {t('conversations')}</p>
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
                          <p className="truncate text-sm font-bold app-text">{conversationTitle(conversation, t)}</p>
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
                  <h3 className="truncate text-xl font-bold app-text sm:text-2xl">{selected.channel === 'voice' ? t('voiceCall') : t('chatConversation')}</h3>
                  <p className="text-sm app-text-muted">{conversationTitle(selected, t)}</p>
                </div>
              </div>
              <div className="flex flex-wrap gap-2">
                <IntentPill intent={selected.intent} bookingStatus={selected.booking?.status} />
              </div>
            </div>

            <DarkPanel className="mt-6 flex min-h-0 flex-1 flex-col">
              <div className="mb-6 flex items-center gap-2 text-lg font-bold app-text">
                <FileText className="h-5 w-5" />
                {t('transcript')}
              </div>
              <div className="min-h-0 flex-1 space-y-5 overflow-y-auto pr-2">
                {selected.messages.map((message) => (
                  <div key={message.id} className={`flex items-start gap-2 sm:gap-3 ${message.role === 'user' ? 'justify-end' : 'justify-start'}`}>
                    {message.role === 'assistant' && <Avatar label="AI" />}
                    <div className={`max-w-[calc(100%-3rem)] break-words rounded-lg px-4 py-3 text-sm leading-6 sm:max-w-[78%] ${message.role === 'assistant' ? 'app-panel-soft' : 'chat-bubble-user'}`}>
                      <InlineMarkdown text={formatNaturalDatesInText(message.content)} />
                    </div>
                    {message.role === 'user' && <Avatar label="C" muted />}
                  </div>
                ))}
              </div>
            </DarkPanel>
          </section>

          <aside className="min-h-0 space-y-4 overflow-y-auto border-t p-4 app-border app-panel-soft sm:p-5 lg:space-y-6 lg:border-l lg:border-t-0 lg:p-8">
            <DarkPanel>
              <h3 className="mb-6 text-lg font-bold app-text">{t('summary')}</h3>
              <p className="text-sm leading-6 app-text-soft">{conversationSummary(selected, t)}</p>
            </DarkPanel>
            <DarkPanel>
              <h3 className="mb-6 flex items-center gap-2 text-lg font-bold app-text"><FileText className="h-5 w-5" /> {t('details')}</h3>
              <Detail icon={Calendar} label={t('dateAndTime')} value={formatDate(selected.last_message_at || selected.created_at, salon.timezone)} />
              <Detail icon={Clock} label={t('duration')} value={formatDuration(selected.duration_seconds)} />
              <Detail icon={User} label={t('contact')} value={conversationTitle(selected, t)} />
            </DarkPanel>
            <DarkPanel>
              <h3 className="mb-6 flex items-center gap-2 text-lg font-bold app-text"><Phone className="h-5 w-5" /> {t('phoneAi')}</h3>
              <Detail icon={Bot} label={t('agent')} value={`${salon.ai_assistant_name?.trim() || 'Bella'} Romania Line`} />
              <Detail icon={Phone} label={t('businessPhone')} value={salon.locations[0]?.phone || '+40 000 000 000'} />
            </DarkPanel>
          </aside>
        </div>
      ) : (
        <div className="flex min-h-[520px] items-center justify-center p-8 text-center">
          <div>
            <MessageSquare className="mx-auto mb-4 h-10 w-10 text-slate-700" />
            <h3 className="text-xl font-bold app-text">{emptyTitle}</h3>
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

function conversationMatchesChannel(conversation: Conversation, filter: 'all' | 'voice' | 'chat' | 'whatsapp') {
  const channel = conversation.channel as string;

  if (filter === 'all') return true;
  if (filter === 'chat') return channel === 'chat' || channel === 'web_widget';

  return channel === filter;
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

function conversationSummary(conversation: Conversation, t: TranslateFn) {
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
    confirmed: 'bg-blue-100 text-blue-800 dark:bg-blue-500/15 dark:text-blue-300',
    programat: 'bg-blue-100 text-blue-800 dark:bg-blue-500/15 dark:text-blue-300',
    cancelled: 'bg-red-100 text-red-800 dark:bg-red-500/15 dark:text-red-300',
    completed: 'bg-green-100 text-green-800 dark:bg-green-500/15 dark:text-green-300',
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
      <span className={`inline-flex justify-center rounded-md font-bold uppercase tracking-wide ${compact ? 'min-w-20 px-2 py-1 text-[10px]' : 'min-w-24 px-3 py-1 text-xs'} ${tone}`}>
        {labels[intent] ?? t('intentUnknown')}
      </span>
      {intent === 'booking' && bookingStatus && (
        <span className={`inline-flex items-center gap-1.5 justify-center rounded-md font-bold uppercase tracking-wide ${compact ? 'min-w-28 px-2 py-1 text-[10px]' : 'min-w-28 px-3 py-1 text-xs'} ${statusTones[bookingStatus] ?? statusTones.completed}`}>
          {bookingStatus === 'pending' && <span className="railway-lights shrink-0" aria-hidden="true" />}
          {bookingStatus === 'completed' && <Check className="h-3 w-3 shrink-0 stroke-[3]" />}
          {bookingStatus === 'cancelled' && <X className="h-3 w-3 shrink-0 stroke-[3]" />}
          {statusLabels[bookingStatus] ?? bookingStatus}
        </span>
      )}
    </div>
  );
}

function StatusPill({ status, t, className = '' }: { status: string; t: TranslateFn; className?: string }) {
  const tones: Record<string, string> = {
    pending: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    confirmed: 'bg-blue-100 text-blue-800 dark:bg-blue-500/15 dark:text-blue-300',
    programat: 'bg-blue-100 text-blue-800 dark:bg-blue-500/15 dark:text-blue-300',
    cancelled: 'bg-red-100 text-red-800 dark:bg-red-500/15 dark:text-red-300',
    completed: 'bg-green-100 text-green-800 dark:bg-green-500/15 dark:text-green-300',
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
    <span className={`inline-flex min-w-28 items-center justify-center gap-1 whitespace-nowrap rounded-md px-2 py-0.5 text-sm font-semibold ${tones[status] ?? tones.completed} ${className}`}>
      {status === 'pending' && <span className="railway-lights shrink-0" aria-hidden="true" />}
      {status === 'completed' && <Check className="h-3 w-3 shrink-0 stroke-[3]" />}
      {status === 'cancelled' && <X className="h-3 w-3 shrink-0 stroke-[3]" />}
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

function formatNaturalDatesInText(text: string) {
  const months = [
    'ianuarie',
    'februarie',
    'martie',
    'aprilie',
    'mai',
    'iunie',
    'iulie',
    'august',
    'septembrie',
    'octombrie',
    'noiembrie',
    'decembrie',
  ];

  return text.replace(/\b(\d{2})-(\d{2})-(\d{4})\b/g, (match, first, second) => {
    const month = Number(first);
    const day = Number(second);

    if (month < 1 || month > 12 || day < 1 || day > 31) {
      return match;
    }

    return `${day} ${months[month - 1]}`;
  });
}

function formatDuration(seconds?: number | null) {
  if (!seconds) return '0:00';
  const minutes = Math.floor(seconds / 60);
  const rest = seconds % 60;
  return `${minutes}:${String(rest).padStart(2, '0')}`;
}

function csvCell(value: unknown) {
  const text = value === null || value === undefined ? '' : String(value);
  return `"${text.replace(/"/g, '""')}"`;
}

function conversationTranscript(conversation: Conversation) {
  return conversation.messages
    .map((message) => {
      const role = message.role === 'assistant' ? 'Assistant' : 'Client';
      const time = message.created_at ? ` [${formatDate(message.created_at)}]` : '';
      return `${role}${time}: ${message.content}`;
    })
    .join('\n\n');
}

function exportConversationsCsv(conversations: Conversation[], filename: string, timezone?: string | null) {
  const headers = [
    'ID',
    'Channel',
    'Status',
    'Intent',
    'Contact name',
    'Phone',
    'Email',
    'Last message at',
    'Duration',
    'Summary',
    'Booking status',
    'Transcript',
  ];
  const rows = conversations.map((conversation) => [
    conversation.id,
    conversation.channel,
    conversation.status,
    conversation.intent,
    conversation.contact_name ?? '',
    conversation.contact_phone ?? '',
    conversation.contact_email ?? '',
    formatDate(conversation.last_message_at || conversation.created_at, timezone),
    formatDuration(conversation.duration_seconds),
    conversation.summary ?? '',
    conversation.booking?.status ?? '',
    conversationTranscript(conversation),
  ]);
  const csv = [headers, ...rows].map((row) => row.map(csvCell).join(',')).join('\r\n');
  const blob = new Blob([`\uFEFF${csv}`], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');

  link.href = url;
  link.download = `${filename}-${new Date().toISOString().slice(0, 10)}.csv`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

function DarkPanel({ children, className = '' }: { children: React.ReactNode; className?: string }) {
  return <div className={`rounded-lg border p-5 shadow-sm app-panel ${className}`}>{children}</div>;
}

function Avatar({ label, muted = false }: { label: string; muted?: boolean }) {
  return <div className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-bold ${muted ? 'app-panel-soft app-text-soft' : 'bg-blue-600 text-white'}`}>{label}</div>;
}

function Detail({ icon: Icon, label, value }: { icon: any; label: string; value: string }) {
  return (
    <div className="mb-5 flex gap-3">
      <Icon className="mt-1 h-4 w-4 shrink-0 app-text-muted" />
      <div>
        <p className="text-xs app-text-muted">{label}</p>
        <p className="text-sm font-bold app-text">{value}</p>
      </div>
    </div>
  );
}

function InlineMarkdown({ text }: { text: string }) {
  return <>{text.replaceAll('**', '')}</>;
}

function buildActivityChart(conversations: Conversation[], range: 'week' | 'month', baseDate = new Date()): ActivityChartRow[] {
  const today = new Date();
  const start = range === 'week'
    ? startOfWeek(today)
    : new Date(baseDate.getFullYear(), baseDate.getMonth(), 1);
  const days = range === 'week'
    ? 7
    : new Date(baseDate.getFullYear(), baseDate.getMonth() + 1, 0).getDate();
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
      websiteChatDone: 0,
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

      row.websiteChatDone += 1;
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

function activitySeriesLabels(t: TranslateFn): Record<string, string> {
  return {
    phoneDone: t('phoneCalls'),
    chatWhatsDone: t('chatWhats'),
    websiteChatDone: t('websiteChat'),
    inProgress: t('inProgress'),
    abandoned: t('intentAbandoned'),
  };
}

function ActivityLegendItem({ color, label }: { color: string; label: string }) {
  return (
    <span className="inline-flex items-center gap-2">
      <span className="h-3 w-3 rounded-sm" style={{ backgroundColor: `var(${color})` }} />
      {label}
    </span>
  );
}

function OnboardingSetup({ onboarding }: { onboarding: OnboardingChecklist }) {
  const t = useT();
  const nextStep = onboarding.next_step;

  function complete() {
    router.post('/onboarding/complete', {}, { preserveScroll: true });
  }

  return (
    <div className="space-y-6">
      <Card className="p-6">
        <div className="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
          <div className="min-w-0 flex-1">
            <h2 className="text-2xl font-bold app-text">{t('onboardingHeading')}</h2>
            <p className="mt-2 text-sm leading-6 app-text-muted xl:whitespace-nowrap">{t('onboardingPageHelper')}</p>
          </div>
          <div className="flex flex-wrap gap-2">
            <Button onClick={complete} disabled={!onboarding.can_complete}>{t('markSetupComplete')}</Button>
          </div>
        </div>
        <OnboardingProgress onboarding={onboarding} />
        {nextStep && (
          <div className="mt-5 flex flex-col gap-3 rounded-lg border p-4 app-border app-panel-soft sm:flex-row sm:items-center sm:justify-between">
            <div>
              <p className="text-xs font-bold uppercase tracking-wide app-text-muted">{t('nextStep')}</p>
              <p className="mt-1 font-bold app-text">{t(nextStep.label_key)}</p>
            </div>
            <Link href={nextStep.href} className="inline-flex h-10 items-center justify-center rounded-lg bg-indigo-600 px-4 text-sm font-medium text-white hover:bg-indigo-700">
              {t('openThisStep')}
            </Link>
          </div>
        )}
      </Card>

      <div className="grid gap-3">
        {onboarding.steps.map((step) => (
          <OnboardingStepRow key={step.key} step={step} />
        ))}
      </div>
    </div>
  );
}

function OnboardingProgress({ onboarding }: { onboarding: OnboardingChecklist }) {
  const countableSteps = onboarding.steps.filter((step) => !step.coming_soon);
  const completedCount = countableSteps.filter((step) => step.completed).length;
  const totalCount = countableSteps.length;
  const progress = totalCount > 0 ? Math.round((completedCount / totalCount) * 100) : 100;

  return (
    <div className="mt-6">
      <div className="mb-2 flex items-center justify-between gap-3 text-sm">
        <span className="font-bold app-text">{completedCount}/{totalCount}</span>
        <span className="font-bold text-indigo-600">{progress}%</span>
      </div>
      <div className="h-2 overflow-hidden rounded-full app-panel-soft">
        <div className="h-full rounded-full bg-indigo-600 transition-all" style={{ width: `${progress}%` }} />
      </div>
    </div>
  );
}

function OnboardingStepRow({ step }: { step: OnboardingStep }) {
  const t = useT();
  const status = step.completed
    ? t('complete')
    : step.coming_soon
      ? t('comingSoon')
      : step.optional
        ? t('optional')
        : t('notComplete');
  const tone = step.completed ? 'green' : step.coming_soon ? 'slate' : step.optional ? 'slate' : 'amber';

  return (
    <Card className="p-4">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex min-w-0 items-start gap-3">
          <span className={`mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${step.completed ? 'bg-emerald-100 text-emerald-700' : 'app-panel-soft app-text-muted'}`}>
            {step.completed ? <Check className="h-4 w-4" /> : <List className="h-4 w-4" />}
          </span>
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <p className="font-bold app-text">{t(step.label_key)}</p>
              <Badge tone={tone as any}>{status}</Badge>
            </div>
            <p className="mt-1 text-sm app-text-muted">{t(step.description_key)}</p>
          </div>
        </div>
        {!step.coming_soon && (
          <Link href={step.href} className="inline-flex h-9 shrink-0 items-center justify-center rounded-lg border px-3 text-sm font-bold app-panel app-text-soft hover:bg-[var(--app-panel-soft)]">
            {t('openThisStep')}
          </Link>
        )}
      </div>
    </Card>
  );
}

function OnboardingReminder({ onboarding }: { onboarding: OnboardingChecklist }) {
  const t = useT();
  if (onboarding.completed) return null;

  return (
    <Card className="border-indigo-500/30 p-5">
      <div className="space-y-4">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
          <div className="min-w-0 flex-1">
            <p className="text-lg font-bold app-text">{t('onboardingHeading')}</p>
            <p className="mt-2 text-sm app-text-muted">
              {t('onboardingPageHelper')}
            </p>
          </div>
          <div className="flex shrink-0">
            <Link href="/dashboard/onboarding" className="inline-flex h-10 w-full items-center justify-center rounded-lg bg-indigo-600 px-4 text-sm font-medium text-white hover:bg-indigo-700 lg:w-auto">
              {t('goToSetup')}
            </Link>
          </div>
        </div>
        <OnboardingProgress onboarding={onboarding} />
      </div>
    </Card>
  );
}

function Overview({ salon, overview, onboarding }: { salon: Salon; overview: OverviewData; onboarding: OnboardingChecklist }) {
  const t = useT();
  const { locale } = usePage<Props>().props;
  const assistantName = salon.ai_assistant_name?.trim() || 'Bella';
  const [activityRange, setActivityRange] = useState<'week' | 'month'>('week');
  const [activityMonth, setActivityMonth] = useState(() => {
    const today = new Date();
    return new Date(today.getFullYear(), today.getMonth(), 1);
  });
  const metrics = overview.metrics;
  const dateLocale = locale === 'en' ? 'en-GB' : 'ro-RO';
  const activityMonthLabel = new Intl.DateTimeFormat(dateLocale, { month: 'long', year: 'numeric' }).format(activityMonth);

  const chart = useMemo(() => buildActivityChart(salon.conversations, activityRange, activityMonth), [salon.conversations, activityRange, activityMonth]);

  function changeActivityMonth(offset: number) {
    setActivityMonth((month) => new Date(month.getFullYear(), month.getMonth() + offset, 1));
  }

  return (
    <div className="space-y-6">
      <OnboardingReminder onboarding={onboarding} />
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <Stat label={t('totalBookings')} value={metrics.total_bookings} icon={Calendar} tone="green" />
        <Stat label={t('conversionRate')} value={`${metrics.conversion_rate}%`} icon={CheckCircle2} tone="amber" />
        <Stat label={t('bookingsThisWeek')} value={metrics.bookings_this_week} icon={Calendar} />
        <Stat label={t('completedBookings')} value={metrics.completed_bookings} icon={CheckCircle2} tone="slate" />
      </div>
      <UsageOverviewCard summary={overview.usage} />
      <div className="grid items-stretch gap-6 xl:grid-cols-[1.6fr_1fr]">
        <Card className="flex h-full flex-col p-5">
          <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
            <h2 className="text-xs font-bold uppercase tracking-wide app-text-muted">{t('activityReport')}</h2>
            <div className="flex flex-wrap items-center gap-2">
              {activityRange === 'month' && (
                <div className="inline-flex items-center rounded-lg border p-1 app-panel">
                  <button type="button" onClick={() => changeActivityMonth(-1)} className="flex h-11 w-11 items-center justify-center rounded-md app-text-muted hover:bg-[var(--app-panel-soft)]" aria-label={t('previousMonth')}>
                    <ChevronLeft className="h-4 w-4" />
                  </button>
                  <span className="min-w-36 px-3 text-center text-xs font-bold capitalize app-text-soft">{activityMonthLabel}</span>
                  <button type="button" onClick={() => changeActivityMonth(1)} className="flex h-11 w-11 items-center justify-center rounded-md app-text-muted hover:bg-[var(--app-panel-soft)]" aria-label={t('nextMonth')}>
                    <ChevronRight className="h-4 w-4" />
                  </button>
                </div>
              )}
              <div className="inline-flex rounded-lg border p-1 app-panel" role="group" aria-label={t('activityReport')}>
                <button
                  type="button"
                  onClick={() => setActivityRange('week')}
                  aria-pressed={activityRange === 'week'}
                  className={`h-11 rounded-md px-3 text-xs font-bold transition ${activityRange === 'week' ? 'bg-indigo-600 text-white' : 'app-text-muted hover:bg-[var(--app-panel-soft)]'}`}
                >
                  {t('week')}
                </button>
                <button
                  type="button"
                  onClick={() => setActivityRange('month')}
                  aria-pressed={activityRange === 'month'}
                  className={`h-11 rounded-md px-3 text-xs font-bold transition ${activityRange === 'month' ? 'bg-indigo-600 text-white' : 'app-text-muted hover:bg-[var(--app-panel-soft)]'}`}
                >
                  {t('month')}
                </button>
              </div>
            </div>
          </div>
          <Suspense fallback={<div className="min-h-72 flex-1 rounded-lg app-panel-soft" aria-hidden="true" />}>
            <ActivityChart data={chart} labels={activitySeriesLabels(t)} range={activityRange} title={t('activityReport')} className="min-h-72 flex-1" />
          </Suspense>
          <div className="mt-5 flex flex-wrap gap-x-5 gap-y-2 text-xs font-bold app-text-muted">
            <ActivityLegendItem color="--chart-phone" label={t('phoneCalls')} />
            <ActivityLegendItem color="--chart-whatsapp" label={t('chatWhats')} />
            <ActivityLegendItem color="--chart-website-chat" label={t('websiteChat')} />
            <ActivityLegendItem color="--chart-progress" label={t('inProgress')} />
            <ActivityLegendItem color="--chart-abandoned" label={t('intentAbandoned')} />
          </div>
        </Card>
        <Card className="h-full p-5">
          <h2 className="mb-4 text-xs font-bold uppercase tracking-wide app-accent-text">{t('assistantLive')}</h2>
          <p className="text-2xl font-bold app-text">{t('bellaOnline', { name: assistantName })}</p>
          <p className="mt-2 text-sm app-text-soft">{t('overviewAiWorkSummary')}</p>
          <div className="mt-6">
            <p className="mb-3 text-xs font-bold uppercase tracking-wide app-accent-text">{t('latestConversations')}</p>
            <OverviewConversationsTable conversations={overview.latest_conversations} t={t} />
          </div>
        </Card>
      </div>
      <section className="space-y-3">
        <h2 className="text-lg font-bold app-text">{t('latestBookings')}</h2>
        <OverviewBookingsTable bookings={overview.latest_bookings} salon={salon} t={t} />
      </section>
    </div>
  );
}

function UsageOverviewCard({ summary }: { summary: UsageSummary }) {
  const t = useT();

  return (
    <UsageSummaryPanel
      summary={summary}
      action={(
        <Link href="/dashboard/billing" className="inline-flex h-10 items-center justify-center rounded-lg border px-4 text-sm font-medium app-panel app-text-soft hover:bg-[var(--app-panel-soft)]">
          {t('viewBilling')}
        </Link>
      )}
    />
  );
}

function UsageSummaryPanel({ summary, action, compact = false }: { summary: UsageSummary; action?: ReactNode; compact?: boolean }) {
  const t = useT();
  const items = [
    {
      key: 'ai_messages',
      label: t('aiMessages'),
      used: summary.usage.ai_messages,
      limit: summary.limits.ai_messages,
      icon: Sparkles,
      tone: 'emerald' as const,
    },
    {
      key: 'bookings',
      label: t('bookings'),
      used: summary.usage.bookings,
      limit: summary.limits.bookings,
      icon: Calendar,
      tone: 'sky' as const,
    },
    {
      key: 'conversations',
      label: t('usageChatConversations'),
      used: summary.usage.conversations,
      limit: summary.limits.conversations,
      icon: MessageSquare,
      tone: 'indigo' as const,
    },
    {
      key: 'whatsapp_conversations',
      label: t('usageWhatsappConversations'),
      used: summary.usage.whatsapp_conversations ?? 0,
      limit: summary.limits.whatsapp_conversations ?? 0,
      icon: SiWhatsapp,
      tone: 'slate' as const,
      locked: !planHasService(summary.plan, 'whatsapp_ai'),
    },
    {
      key: 'phone_minutes',
      label: t('phoneMinutes'),
      used: summary.usage.phone_minutes ?? 0,
      limit: summary.limits.phone_minutes ?? 0,
      icon: Phone,
      tone: 'slate' as const,
      locked: !planHasService(summary.plan, 'phone_ai'),
    },
  ];

  return (
    <Card className={`${compact ? 'shrink-0 p-4' : 'p-5'}`}>
      <div className={`flex flex-col lg:flex-row lg:items-center lg:justify-between ${compact ? 'gap-3' : 'gap-4'}`}>
        <div>
          <p className="text-xs font-semibold uppercase tracking-wide app-text-muted">{t('usageThisMonth')}</p>
          <h2 className="mt-1 text-lg font-semibold app-text">{summary.plan.name} {t('plan')}</h2>
        </div>
        {action}
      </div>
      <div className={`${compact ? 'mt-3 gap-3' : 'mt-5 gap-4'} grid md:grid-cols-2 xl:grid-cols-5`}>
        {items.map(({ key, ...item }) => <UsageRing key={key} compact={compact} {...item} />)}
      </div>
    </Card>
  );
}

function UsageRing({ label, used, limit, icon: Icon, tone, compact = false, locked = false }: { label: string; used: number; limit: number | null; icon: any; tone: 'indigo' | 'emerald' | 'sky' | 'slate'; compact?: boolean; locked?: boolean }) {
  const t = useT();
  const percentage = limit && limit > 0 ? Math.min(100, Math.round((used / limit) * 100)) : 0;
  const size = compact ? 68 : 112;
  const center = size / 2;
  const radius = compact ? 27 : 46;
  const stroke = compact ? 6 : 9;
  const circumference = 2 * Math.PI * radius;
  const dashOffset = circumference - (percentage / 100) * circumference;
  const tones = {
    indigo: {
      stroke: 'stroke-indigo-600',
      icon: 'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-200',
      text: 'text-indigo-700 dark:text-indigo-200',
    },
    emerald: {
      stroke: 'stroke-emerald-600',
      icon: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-200',
      text: 'text-emerald-700 dark:text-emerald-200',
    },
    sky: {
      stroke: 'stroke-sky-600',
      icon: 'bg-sky-50 text-sky-700 dark:bg-sky-500/15 dark:text-sky-200',
      text: 'text-sky-700 dark:text-sky-200',
    },
    slate: {
      stroke: 'stroke-slate-400 dark:stroke-slate-500',
      icon: 'bg-slate-100 text-slate-500 dark:bg-white/10 dark:text-slate-400',
      text: 'text-slate-500 dark:text-slate-400',
    },
  }[tone];

  return (
    <div className={`${compact ? 'min-h-24 flex-row gap-3 px-3 py-3 text-left' : 'min-h-44 flex-col gap-3 px-3 py-4 text-center'} flex items-center justify-between rounded-lg border app-border app-panel-soft`}>
      <div className={`relative shrink-0 ${compact ? 'h-[68px] w-[68px]' : 'h-28 w-28'}`}>
        <svg className={`-rotate-90 ${compact ? 'h-[68px] w-[68px]' : 'h-28 w-28'}`} viewBox={`0 0 ${size} ${size}`} role="img" aria-label={`${label}: ${used} / ${formatLimit(limit, t)}`}>
          <circle cx={center} cy={center} r={radius} fill="none" strokeWidth={stroke} className="stroke-slate-200 dark:stroke-white/10" />
          <circle
            cx={center}
            cy={center}
            r={radius}
            fill="none"
            strokeWidth={stroke}
            strokeLinecap="round"
            strokeDasharray={circumference}
            strokeDashoffset={dashOffset}
            className={`${tones.stroke} transition-[stroke-dashoffset] duration-300`}
          />
        </svg>
        <div className="absolute inset-0 flex flex-col items-center justify-center">
          <span className={`flex items-center justify-center rounded-lg ${compact ? 'h-7 w-7' : 'h-9 w-9'} ${tones.icon}`}>
            <Icon className={compact ? 'h-3.5 w-3.5' : 'h-4 w-4'} />
          </span>
          <span className={`${compact ? 'mt-0.5 text-[11px]' : 'mt-1 text-sm'} font-bold ${tones.text}`}>{percentage}%</span>
        </div>
      </div>
      <div className="min-w-0">
        <p className={`${compact ? 'text-xs leading-4' : 'min-h-10 text-sm leading-5'} flex items-center gap-1.5 font-bold app-text`}>
          {locked && <Lock className="h-3.5 w-3.5 shrink-0 app-text-muted" aria-hidden="true" />}
          <span className="min-w-0">{label}</span>
        </p>
        <p className={`${compact ? 'text-lg' : 'text-xl'} mt-1 font-bold app-text`}>{used} <span className="text-sm font-semibold app-text-muted">/ {formatLimit(limit, t)}</span></p>
      </div>
    </div>
  );
}

function BillingPage({ billing, currentPlan }: { billing: { summary: UsageSummary; plans: Plan[]; services: OfferedService[] }; currentPlan: string }) {
  const t = useT();
  const canonicalCurrentPlan = canonicalPlanKey(currentPlan);
  const [selectedPlan, setSelectedPlan] = useState(canonicalCurrentPlan);
  const [billingCycle, setBillingCycle] = useState<'monthly' | 'annual'>('monthly');
  const [selectedVoicePlan, setSelectedVoicePlan] = useState<VoicePlanKey>(
    isVoicePlanKey(canonicalCurrentPlan) ? canonicalCurrentPlan : 'voice_starter'
  );

  function updatePlan(event: FormEvent) {
    event.preventDefault();
    router.put('/billing/plan', { plan: selectedPlan }, { preserveScroll: true });
  }

  return (
    <div className="space-y-6">
      <Card className="p-6">
        <div className="grid gap-6 xl:grid-cols-[1fr_340px]">
          <div>
            <p className="text-xs font-semibold uppercase tracking-wide app-text-muted">{t('currentPlan')}</p>
            <h2 className="mt-2 text-3xl font-semibold app-text">{billing.summary.plan.name}</h2>
            <p className="mt-1 text-sm app-text-soft">{priceLabel(billing.summary.plan, billingCycle)}</p>
            <p className="mt-4 max-w-3xl text-sm leading-6 app-text-muted">{t('billingNotConnected')}</p>
          </div>
          <form onSubmit={updatePlan} className="rounded-lg border p-4 app-border app-panel-soft">
            <label className="block">
              <span className="mb-2 block text-sm font-semibold app-text">{t('selectPlan')}</span>
              <select value={selectedPlan} onChange={(event) => setSelectedPlan(event.target.value)} className="h-10 w-full rounded-lg border px-3 text-sm app-panel app-text">
                {billing.plans.map((plan) => <option key={plan.key} value={plan.key}>{plan.name}</option>)}
              </select>
            </label>
            <p className="mt-3 text-xs app-text-muted">{t('localTestingPlanSelector')}</p>
            <Button className="mt-4 w-full" type="submit">{t('changePlan')}</Button>
          </form>
        </div>
      </Card>

      <UsageSummaryPanel summary={billing.summary} />

      <PlanServicesOverview services={billing.services} currentPlan={billing.summary.plan} />

      <div className="flex justify-end">
        <div className="inline-flex rounded-lg border p-1 app-border app-panel" role="group" aria-label={t('price')}>
          <button
            type="button"
            onClick={() => setBillingCycle('monthly')}
            aria-pressed={billingCycle === 'monthly'}
            className={`h-11 rounded-md px-4 text-sm font-semibold transition ${billingCycle === 'monthly' ? 'bg-indigo-600 text-white' : 'app-text-soft hover:bg-[var(--app-panel-soft)]'}`}
          >
            {t('monthly')}
          </button>
          <button
            type="button"
            onClick={() => setBillingCycle('annual')}
            aria-pressed={billingCycle === 'annual'}
            className={`h-11 rounded-md px-4 text-sm font-semibold transition ${billingCycle === 'annual' ? 'bg-indigo-600 text-white' : 'app-text-soft hover:bg-[var(--app-panel-soft)]'}`}
          >
            {t('annual')}
          </button>
        </div>
      </div>

      <PricingPlansGrid
        plans={billing.plans}
        services={billing.services}
        billingCycle={billingCycle}
        selectedVoicePlan={selectedVoicePlan}
        onSelectedVoicePlanChange={setSelectedVoicePlan}
        t={t}
        showCtas={false}
        currentPlanKey={canonicalCurrentPlan}
      />
    </div>
  );
}

function isVoicePlanKey(plan: string): plan is VoicePlanKey {
  return ['voice_starter', 'voice_growth', 'voice_pro'].includes(plan);
}

function priceLabel(plan: Plan, billingCycle: 'monthly' | 'annual') {
  const monthly = monthlyPrice(plan);

  if (monthly === null) {
    return plan.price_label.replace('/lună', '');
  }

  const value = billingCycle === 'annual' ? monthly * 10 : monthly;

  return `${new Intl.NumberFormat('ro-RO').format(value)} RON`;
}

function monthlyPrice(plan: Plan) {
  const match = plan.price_label.match(/^([\d.]+)\s+RON/);
  if (! match) return null;

  return Number(match[1].replaceAll('.', ''));
}

function annualDiscountPercent() {
  return Math.round(((12 - 10) / 12) * 100);
}

function canonicalPlanKey(key?: string | null) {
  const aliases: Record<string, string> = {
    connect: 'chat_whatsapp',
    voice: 'voice_starter',
    enterprise: 'voice_pro',
  };

  return key ? aliases[key] ?? key : 'free';
}

function formatLimit(value: number | null, t: TranslateFn): string {
  if (value === null) return t('unlimited');
  return new Intl.NumberFormat('en-GB').format(value);
}

function OverviewConversationsTable({ conversations, t }: { conversations: Conversation[]; t: TranslateFn }) {
  if (conversations.length === 0) {
    return (
      <div className="rounded-2xl border p-6 shadow-sm app-border app-panel">
        <div className="flex min-h-24 items-center justify-center text-sm app-text-muted">{t('noConversations')}</div>
      </div>
    );
  }

  return (
    <DashboardTable headers={[]} minWidth="560px">
      {conversations.map((conversation, index) => (
        <tr key={conversation.id} className={dashboardTableRowClass(index)}>
          <td className="min-w-0 px-5 py-4 align-middle">
            <p className="truncate text-sm font-semibold app-text">{conversationTitle(conversation, t)}</p>
            <p className="mt-1 truncate text-xs app-text-muted">
              {conversation.contact_phone || conversation.contact_email || conversation.contact_name || t('clientName')}
            </p>
          </td>
          <td className="w-48 px-5 py-4 align-middle">
            <div className="flex justify-end">
              <IntentPill intent={conversation.intent} compact bookingStatus={conversation.booking?.status} />
            </div>
          </td>
        </tr>
      ))}
    </DashboardTable>
  );
}

function OverviewBookingsTable({ bookings, salon, t }: { bookings: Booking[]; salon: Salon; t: TranslateFn }) {
  if (bookings.length === 0) {
    return (
      <div className="rounded-2xl border p-6 shadow-sm app-border app-panel">
        <div className="flex min-h-24 items-center justify-center text-sm app-text-muted">{t('noRecentBooking')}</div>
      </div>
    );
  }

  return (
    <DashboardTable headers={[]} minWidth="920px">
      {bookings.map((booking, index) => (
        <tr key={booking.id} className={dashboardTableRowClass(index)}>
          <td className="w-56 px-5 py-4 align-top">
            <p className="font-semibold app-text">{booking.client_name}</p>
            <p className="mt-1 text-xs app-text-muted">{booking.client_phone || t('phoneMissingShort')}</p>
          </td>
          <td className="w-56 whitespace-nowrap px-5 py-4 align-top">
            <p className="text-sm font-semibold app-text">{formatBookingDay(booking.date, salon)}</p>
            <p className="mt-1 text-xs font-bold app-text-muted">{bookingTimeRange(booking.time, booking.service?.duration)}</p>
          </td>
          <td className="px-5 py-4 align-top">
            <StatusPill status={booking.status} t={t} />
          </td>
          <td className="min-w-72 px-5 py-4 align-top app-text-soft">
            <BookingDetailsCell booking={booking} salon={salon} t={t} />
          </td>
        </tr>
      ))}
    </DashboardTable>
  );
}

function bookingStaffLabel(booking: Booking): string {
  if (booking.staff_member?.name) {
    return booking.staff_member.name;
  }

  return (booking.staff ?? []).filter(Boolean).join(' \u2022 ');
}

function Stat({ label, value, icon: Icon, tone = 'indigo' }: { label: string; value: number | string; icon: any; tone?: 'indigo' | 'amber' | 'green' | 'blue' | 'slate' }) {
  const colors = {
    indigo: 'bg-indigo-50 text-indigo-700',
    amber: 'bg-amber-50 text-amber-700',
    green: 'bg-green-50 text-green-700',
    blue: 'bg-blue-50 text-blue-700',
    slate: 'bg-slate-100 text-slate-700',
  };
  return (
    <Card className="p-5">
      <div className={`mb-4 flex h-10 w-10 items-center justify-center rounded-lg ${colors[tone]}`}>
        <Icon className="h-5 w-5" />
      </div>
      <p className="text-xs font-bold uppercase tracking-wide app-text-muted">{label}</p>
      <p className="mt-1 text-3xl font-bold app-text">{value}</p>
    </Card>
  );
}

function AiSettings({ salon }: { salon: Salon }) {
  const t = useT();
  const selectedBusinessType = findBusinessType(normalizeBusinessTypeSlug(salon.business_type) || 'salon-beauty');
  const [customContextInput, setCustomContextInput] = useState('');
  const form = useForm({
    ai_assistant_name: salon.ai_assistant_name ?? 'Bella',
    ai_tone: salon.ai_tone ?? 'polite',
    ai_response_style: salon.ai_response_style ?? 'short',
    ai_language_mode: salon.ai_language_mode ?? 'auto',
    ai_custom_instructions: salon.ai_custom_instructions ?? '',
    ai_business_summary: salon.ai_business_summary ?? '',
    ai_industry_categories: salon.ai_industry_categories ?? [],
    ai_main_focus: salon.ai_main_focus ?? '',
    ai_custom_context: salon.ai_custom_context ?? [],
    ai_booking_enabled: Boolean(salon.ai_booking_enabled ?? true),
    ai_collect_phone: Boolean(salon.ai_collect_phone ?? true),
    ai_handoff_message: salon.ai_handoff_message ?? '',
    ai_unknown_answer_policy: salon.ai_unknown_answer_policy ?? 'say_unknown',
  });

  function submit(event: FormEvent) {
    event.preventDefault();
    form.put('/ai-settings', { preserveScroll: true });
  }

  function toggleAiCategory(category: string) {
    const categories = form.data.ai_industry_categories.includes(category)
      ? form.data.ai_industry_categories.filter((item) => item !== category)
      : [...form.data.ai_industry_categories, category];

    form.setData({
      ...form.data,
      ai_industry_categories: categories,
      ai_main_focus: categories.includes(form.data.ai_main_focus) ? form.data.ai_main_focus : '',
    });
  }

  function addCustomContext() {
    const value = customContextInput.trim();
    if (!value || form.data.ai_custom_context.includes(value)) return;

    form.setData('ai_custom_context', [...form.data.ai_custom_context, value]);
    setCustomContextInput('');
  }

  function removeCustomContext(value: string) {
    form.setData('ai_custom_context', form.data.ai_custom_context.filter((item) => item !== value));
  }

  return (
    <form onSubmit={submit} className="space-y-6">
      <Card className="p-6">
        <div className="mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
          <div className="flex min-w-0 items-start gap-3">
            <Sparkles className="mt-1 h-5 w-5 shrink-0 text-indigo-500" />
            <div>
              <h2 className="text-xl font-bold app-text">{t('aiIdentityBehavior')}</h2>
              <p className="mt-1 text-sm app-text-muted">{t('aiIdentityBehaviorHelp')}</p>
            </div>
          </div>
          <div className="flex shrink-0">
            <a href={`/assistant/${salon.id}`} target="_blank" rel="noreferrer" className="inline-flex h-10 w-full items-center justify-center rounded-lg bg-indigo-600 px-4 text-sm font-medium text-white hover:bg-indigo-700 lg:w-auto">
              {t('testAssistant')}
            </a>
          </div>
        </div>
        <div className="grid gap-4 lg:grid-cols-4">
          <Field label={t('aiAssistantName')} error={form.errors.ai_assistant_name}>
            <Input value={form.data.ai_assistant_name} onChange={(event) => form.setData('ai_assistant_name', event.target.value)} />
          </Field>
          <Field label={t('aiLanguageMode')} error={form.errors.ai_language_mode}>
            <select className="h-10 w-full rounded-lg border px-3 text-sm outline-none app-panel app-text" value={form.data.ai_language_mode} onChange={(event) => form.setData('ai_language_mode', event.target.value)}>
              <option value="auto">{t('aiLanguageAuto')}</option>
              <option value="ro">{t('aiLanguageRo')}</option>
              <option value="en">{t('aiLanguageEn')}</option>
            </select>
          </Field>
          <Field label={t('aiTone')} error={form.errors.ai_tone}>
            <select className="h-10 w-full rounded-lg border px-3 text-sm outline-none app-panel app-text" value={form.data.ai_tone} onChange={(event) => form.setData('ai_tone', event.target.value)}>
              <option value="polite">{t('aiTonePolite')}</option>
              <option value="friendly">{t('aiToneFriendly')}</option>
              <option value="professional">{t('aiToneProfessional')}</option>
              <option value="warm">{t('aiToneWarm')}</option>
            </select>
          </Field>
          <Field label={t('aiResponseStyle')} error={form.errors.ai_response_style}>
            <select className="h-10 w-full rounded-lg border px-3 text-sm outline-none app-panel app-text" value={form.data.ai_response_style} onChange={(event) => form.setData('ai_response_style', event.target.value)}>
              <option value="short">{t('aiStyleShort')}</option>
              <option value="balanced">{t('aiStyleBalanced')}</option>
              <option value="detailed">{t('aiStyleDetailed')}</option>
            </select>
          </Field>
        </div>
      </Card>

      <Card className="p-6">
        <div className="mb-6 flex items-start gap-3">
          <Building2 className="mt-1 h-5 w-5 text-indigo-500" />
          <div>
            <h2 className="text-xl font-bold app-text">{t('aiBusinessContext')}</h2>
            <p className="mt-1 text-sm app-text-muted">{t('aiBusinessContextHelp')}</p>
          </div>
        </div>
        <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
          <div>
            <p className="mb-3 text-sm font-bold app-text">{t('industryCategories')}</p>
            <div className="grid gap-2 sm:grid-cols-2">
              {selectedBusinessType?.industries.map((category) => {
                const checked = form.data.ai_industry_categories.includes(category.slug);
                return (
                  <button
                    key={category.slug}
                    type="button"
                    onClick={() => toggleAiCategory(category.slug)}
                    className={`flex items-center gap-3 rounded-lg border p-3 text-left text-sm font-bold transition app-border ${checked ? 'bg-indigo-600 text-white' : 'app-panel app-text-soft hover:bg-[var(--app-panel-soft)]'}`}
                  >
                    <span className={`flex h-4 w-4 shrink-0 items-center justify-center rounded border ${checked ? 'border-white bg-white text-indigo-600' : 'border-[var(--app-border)]'}`}>
                      {checked && <Check className="h-3 w-3" />}
                    </span>
                    {category.label}
                  </button>
                );
              })}
            </div>
            {form.errors.ai_industry_categories && <p className="mt-2 text-xs font-bold text-red-500">{form.errors.ai_industry_categories}</p>}
          </div>
          <div className="space-y-5">
            <Field label={`${t('mainFocus')} (${t('optional')})`} error={form.errors.ai_main_focus}>
              <select
                className="h-10 w-full rounded-lg border px-3 text-sm outline-none app-panel app-text"
                value={form.data.ai_main_focus}
                onChange={(event) => form.setData('ai_main_focus', event.target.value)}
              >
                <option value="">{t('chooseMainFocus')}</option>
                {form.data.ai_industry_categories.map((slug) => {
                  const category = selectedBusinessType?.industries.find((item) => item.slug === slug);
                  return category ? <option key={category.slug} value={category.slug}>{category.label}</option> : null;
                })}
              </select>
            </Field>
            <div>
              <p className="mb-2 text-sm font-bold app-text">{t('customAiContext')}</p>
              <div className="flex gap-2">
                <Input
                  value={customContextInput}
                  onChange={(event) => setCustomContextInput(event.target.value)}
                  onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                      event.preventDefault();
                      addCustomContext();
                    }
                  }}
                  placeholder={t('customAiContextPlaceholder')}
                />
                <button type="button" onClick={addCustomContext} className="inline-flex h-10 shrink-0 items-center rounded-lg bg-indigo-600 px-4 text-sm font-bold text-white hover:bg-indigo-700">
                  {t('add')}
                </button>
              </div>
              <p className="mt-2 text-xs app-text-muted">{t('customAiContextHelp')}</p>
              {form.errors.ai_custom_context && <p className="mt-2 text-xs font-bold text-red-500">{form.errors.ai_custom_context}</p>}
              {form.data.ai_custom_context.length > 0 && (
                <div className="mt-3 flex flex-wrap gap-2">
                  {form.data.ai_custom_context.map((item) => (
                    <button
                      key={item}
                      type="button"
                      onClick={() => removeCustomContext(item)}
                      className="inline-flex items-center gap-2 rounded-md border px-3 py-1 text-xs font-bold app-border app-text-soft hover:bg-[var(--app-panel-soft)]"
                      title={t('remove')}
                    >
                      {item}
                      <X className="h-3 w-3" />
                    </button>
                  ))}
                </div>
              )}
            </div>
          </div>
        </div>
      </Card>

      <Card className="p-6">
        <div className="mb-6 flex items-start gap-3">
          <FileText className="mt-1 h-5 w-5 text-indigo-500" />
          <div>
            <h2 className="text-xl font-bold app-text">{t('aiKnowledge')}</h2>
            <p className="mt-1 text-sm app-text-muted">{t('aiKnowledgeHelp')}</p>
          </div>
        </div>
        <div className="grid gap-4 lg:grid-cols-2">
          <Field label={t('aiBusinessSummary')} error={form.errors.ai_business_summary}>
            <textarea
              rows={6}
              value={form.data.ai_business_summary}
              onChange={(event) => form.setData('ai_business_summary', event.target.value)}
              className="w-full rounded-lg border px-3 py-2 text-sm outline-none resize-none app-panel app-text"
              placeholder={t('aiBusinessSummaryPlaceholder')}
            />
          </Field>
          <Field label={t('aiCustomInstructions')} error={form.errors.ai_custom_instructions}>
            <textarea
              rows={6}
              value={form.data.ai_custom_instructions}
              onChange={(event) => form.setData('ai_custom_instructions', event.target.value)}
              className="w-full rounded-lg border px-3 py-2 text-sm outline-none resize-none app-panel app-text"
              placeholder={t('aiCustomInstructionsPlaceholder')}
            />
          </Field>
        </div>
      </Card>

      <Card className="p-6">
        <div className="mb-6 flex items-start gap-3">
          <Calendar className="mt-1 h-5 w-5 text-indigo-500" />
          <div>
            <h2 className="text-xl font-bold app-text">{t('aiBookingBehavior')}</h2>
            <p className="mt-1 text-sm app-text-muted">{t('aiBookingBehaviorHelp')}</p>
          </div>
        </div>
        <div className="space-y-5">
          <div className="grid gap-4 lg:grid-cols-2">
            <ToggleRow title={t('aiBookingEnabled')} subtitle={t('aiBookingEnabledHelp')} checked={form.data.ai_booking_enabled} onChange={(checked) => form.setData('ai_booking_enabled', checked)} />
            <ToggleRow title={t('aiCollectPhone')} subtitle={t('aiCollectPhoneHelp')} checked={form.data.ai_collect_phone} onChange={(checked) => form.setData('ai_collect_phone', checked)} />
          </div>
          <div className="grid gap-4 lg:grid-cols-2">
            <Field label={t('aiUnknownAnswerPolicy')} error={form.errors.ai_unknown_answer_policy}>
              <select className="h-10 w-full rounded-lg border px-3 text-sm outline-none app-panel app-text" value={form.data.ai_unknown_answer_policy} onChange={(event) => form.setData('ai_unknown_answer_policy', event.target.value)}>
                <option value="say_unknown">{t('aiUnknownSayUnknown')}</option>
                <option value="handoff">{t('aiUnknownHandoff')}</option>
              </select>
            </Field>
            <Field label={t('aiHandoffMessage')} error={form.errors.ai_handoff_message}>
              <Input value={form.data.ai_handoff_message} onChange={(event) => form.setData('ai_handoff_message', event.target.value)} placeholder={t('aiHandoffMessagePlaceholder')} />
            </Field>
          </div>
        </div>
      </Card>

      <div className="flex justify-end">
        <Button disabled={form.processing}>
          <Save className="h-4 w-4" />
          {t('saveChanges')}
        </Button>
      </div>
    </form>
  );
}

function Locations({ salon }: { salon: Salon }) {
  const t = useT();
  const [adding, setAdding] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [confirmation, setConfirmation] = useState<{ title: string; message: string; tone?: 'danger' | 'neutral'; onConfirm: () => void } | null>(null);
  const addLocationFormRef = useRef<HTMLFormElement>(null);
  const editLocationFormRef = useRef<HTMLFormElement>(null);
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
    max_concurrent_bookings: '',
  });
  const editForm = useForm({
    name: '',
    address: '',
    email: '',
    phone: '',
    hours: defaultHours,
    max_concurrent_bookings: '',
  });
  const formHourErrors = validateHours(form.data.hours);
  const editHourErrors = validateHours(editForm.data.hours);
  const formHasHourErrors = Object.keys(formHourErrors).length > 0;
  const editHasHourErrors = Object.keys(editHourErrors).length > 0;

  function submit(event: FormEvent) {
    event.preventDefault();
    if (formHasHourErrors) return;

    form.transform((data) => ({ ...data, hours: normalizeHoursRecord(data.hours) }));
    form.post('/locations', {
      preserveScroll: true,
      onSuccess: () => {
        form.reset();
        setAdding(false);
      },
      onError: (errors) => scrollToFirstFormError(addLocationFormRef.current, Object.keys(errors)),
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
      max_concurrent_bookings: location.max_concurrent_bookings ? String(location.max_concurrent_bookings) : '',
    });
  }

  function submitEdit(event: FormEvent) {
    event.preventDefault();
    if (!editingId) return;
    if (editHasHourErrors) return;

    editForm.transform((data) => ({ ...data, hours: normalizeHoursRecord(data.hours) }));
    editForm.put(`/locations/${editingId}`, {
      preserveScroll: true,
      onSuccess: () => setEditingId(null),
      onError: (errors) => scrollToFirstFormError(editLocationFormRef.current, Object.keys(errors)),
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
          <form ref={addLocationFormRef} className="grid gap-4 lg:grid-cols-4" onSubmit={submit}>
            <div data-error-key="name"><Field label="Nume" error={form.errors.name}><Input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} /></Field></div>
            <div data-error-key="address"><Field label="Adresa" error={form.errors.address}><Input value={form.data.address} onChange={(event) => form.setData('address', event.target.value)} /></Field></div>
            <div data-error-key="phone"><Field label="Telefon" error={form.errors.phone}><Input value={form.data.phone} onChange={(event) => form.setData('phone', event.target.value)} /></Field></div>
            <div data-error-key="email"><Field label="Email" error={form.errors.email}><Input value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} /></Field></div>
            <div data-error-key="max_concurrent_bookings">
              <Field label={t('maxSimultaneousBookings')} error={form.errors.max_concurrent_bookings}>
                <Input type="number" min={1} max={100} value={form.data.max_concurrent_bookings} onChange={(event) => form.setData('max_concurrent_bookings', event.target.value)} />
                <span className="block text-xs app-text-muted">{t('locationCapacityHelp')}</span>
              </Field>
            </div>
            <div className="lg:col-span-4" data-error-key="hours">
              <HoursEditor
                title={t('operatingHours')}
                hours={form.data.hours}
                onChange={(key, value) => form.setData('hours', { ...form.data.hours, [key]: value })}
                onBulkApply={(nextHours) => form.setData('hours', nextHours)}
                errors={formHourErrors}
              />
            </div>
            <div className="flex items-end gap-2 lg:col-span-4">
              <Button disabled={form.processing || formHasHourErrors}>{t('save')}</Button>
              <SecondaryButton type="button" onClick={() => setAdding(false)}>{t('cancel')}</SecondaryButton>
            </div>
          </form>
        </Card>
      )}
      <div className="grid gap-4 lg:grid-cols-2">
        {salon.locations.length === 0 ? (
          <Card className="flex min-h-52 flex-col items-center justify-center p-8 text-center lg:col-span-2">
            <MapPin className="mb-4 h-10 w-10 app-text-muted" />
            <p className="text-lg font-bold app-text">{t('locationsEmptyTitle')}</p>
            <p className="mt-2 max-w-xl text-sm app-text-muted">{t('locationsEmptyHelp')}</p>
            <Button className="mt-5" onClick={() => setAdding(true)}><Plus className="h-4 w-4" /> {t('addLocation')}</Button>
          </Card>
        ) : salon.locations.map((location) => (
          <Card key={location.id} className="p-5">
            {editingId === location.id ? (
              <form ref={editLocationFormRef} className="space-y-4" onSubmit={submitEdit}>
                <div className="grid gap-3 sm:grid-cols-2">
                  <div data-error-key="name"><Field label="Nume" error={editForm.errors.name}><Input value={editForm.data.name} onChange={(event) => editForm.setData('name', event.target.value)} /></Field></div>
                  <div data-error-key="phone"><Field label="Telefon" error={editForm.errors.phone}><Input value={editForm.data.phone} onChange={(event) => editForm.setData('phone', event.target.value)} /></Field></div>
                  <div data-error-key="address"><Field label="Adresa" error={editForm.errors.address}><Input value={editForm.data.address} onChange={(event) => editForm.setData('address', event.target.value)} /></Field></div>
                  <div data-error-key="email"><Field label="Email" error={editForm.errors.email}><Input value={editForm.data.email} onChange={(event) => editForm.setData('email', event.target.value)} /></Field></div>
                  <div data-error-key="max_concurrent_bookings">
                    <Field label={t('maxSimultaneousBookings')} error={editForm.errors.max_concurrent_bookings}>
                      <Input type="number" min={1} max={100} value={editForm.data.max_concurrent_bookings} onChange={(event) => editForm.setData('max_concurrent_bookings', event.target.value)} />
                      <span className="block text-xs app-text-muted">{t('locationCapacityHelp')}</span>
                    </Field>
                  </div>
                </div>
                <div data-error-key="hours">
                  <HoursEditor
                    title={t('operatingHours')}
                    hours={editForm.data.hours}
                    onChange={(key, value) => editForm.setData('hours', { ...editForm.data.hours, [key]: value })}
                    onBulkApply={(nextHours) => editForm.setData('hours', nextHours)}
                    errors={editHourErrors}
                  />
                </div>
                <div className="flex gap-2">
                  <Button disabled={editForm.processing || editHasHourErrors}>{t('save')}</Button>
                  <SecondaryButton type="button" onClick={() => setEditingId(null)}>{t('cancel')}</SecondaryButton>
                </div>
              </form>
            ) : (
              <>
                <div className="flex items-start justify-between gap-4">
                  <div>
                    <h3 className="text-lg font-bold app-text">{location.name}</h3>
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
                  <ContactMetaLine label={t('phone')} value={location.phone || t('phoneMissing')} />
                  <ContactMetaLine label={t('email')} value={location.email || t('emailMissing')} />
                  <ContactMetaLine label={t('defaultLabel')} value={capacityValue(location.max_concurrent_bookings, t)} />
                  <div className="rounded-lg mt-8 app-panel-soft p-4">
                    <p className="mb-2 flex items-center gap-2 font-bold app-text"><Clock className="h-4 w-4 text-indigo-600" /> {t('operatingHours')}</p>
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

type HourValidationError = 'hourInvalidFormat' | 'hourInvalidRange' | 'hourInvalidOrder';

function normalizeHourValue(value: string): { normalized: string; error?: HourValidationError } {
  const raw = value.trim();

  if (!raw) {
    return { normalized: '' };
  }

  if (/^(inchis|closed)$/i.test(raw)) {
    return { normalized: 'Inchis' };
  }

  const normalizedDash = raw.replace(/[â€“â€”]/g, '-');
  const match = normalizedDash.match(/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/);

  if (!match) {
    return { normalized: raw, error: 'hourInvalidFormat' };
  }

  const openHour = Number(match[1]);
  const openMinute = Number(match[2]);
  const closeHour = Number(match[3]);
  const closeMinute = Number(match[4]);
  const validParts = [openHour, closeHour].every((hour) => hour >= 0 && hour <= 23)
    && [openMinute, closeMinute].every((minute) => minute >= 0 && minute <= 59);

  if (!validParts) {
    return { normalized: raw, error: 'hourInvalidRange' };
  }

  const opensAt = openHour * 60 + openMinute;
  const closesAt = closeHour * 60 + closeMinute;

  if (opensAt >= closesAt) {
    return { normalized: raw, error: 'hourInvalidOrder' };
  }

  const formatted = `${String(openHour).padStart(2, '0')}:${String(openMinute).padStart(2, '0')} - ${String(closeHour).padStart(2, '0')}:${String(closeMinute).padStart(2, '0')}`;

  return { normalized: formatted };
}

function validateHours(hours: Record<string, string>): Partial<Record<string, HourValidationError>> {
  return hourDays.reduce<Partial<Record<string, HourValidationError>>>((errors, [key]) => {
    const result = normalizeHourValue(hours[key] ?? '');

    if (result.error) {
      errors[key] = result.error;
    }

    return errors;
  }, {});
}

function normalizeHoursRecord(hours: Record<string, string>): Record<string, string> {
  return hourDays.reduce<Record<string, string>>((nextHours, [key]) => {
    const result = normalizeHourValue(hours[key] ?? '');

    nextHours[key] = result.error ? (hours[key] ?? '') : result.normalized;

    return nextHours;
  }, {});
}

function HoursEditor({ title, hours, onChange, onBulkApply, errors }: { title: string; hours: Record<string, string>; onChange: (key: string, value: string) => void; onBulkApply: (hours: Record<string, string>) => void; errors: Partial<Record<string, HourValidationError>> }) {
  const t = useT();
  const weekdayKeys = hourDays.slice(0, 5).map(([key]) => key);
  const weekendKeys = hourDays.slice(5).map(([key]) => key);
  const [selectedDays, setSelectedDays] = useState<string[]>(weekdayKeys);
  const [bulkHours, setBulkHours] = useState('');
  const bulkValidation = bulkHours.trim() ? normalizeHourValue(bulkHours) : { normalized: '' };

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
    if (!bulkHours.trim() || selectedDays.length === 0 || bulkValidation.error) return;

    const nextHours = { ...hours };

    selectedDays.forEach((dayKey) => {
      nextHours[dayKey] = bulkValidation.normalized;
    });

    onBulkApply(nextHours);
    setBulkHours(bulkValidation.normalized);
  }

  function normalizeDayOnBlur(dayKey: string, value: string) {
    const result = normalizeHourValue(value);

    if (!result.error && result.normalized !== value) {
      onChange(dayKey, result.normalized);
    }
  }

  return (
    <div className="rounded-lg border p-4 app-panel-soft">
      <p className="mb-3 text-sm font-bold app-text">{title}</p>
      <div className="mb-4 space-y-3 rounded-lg border p-3 app-panel">
        <div className="flex flex-wrap gap-2">
          <button type="button" onClick={() => selectDays(weekdayKeys)} className="rounded-md border px-3 py-1 text-xs font-bold app-panel app-text-soft hover:bg-[var(--app-panel-soft)]">
            {t('weekdays')}
          </button>
          <button type="button" onClick={() => selectDays(weekendKeys)} className="rounded-md border px-3 py-1 text-xs font-bold app-panel app-text-soft hover:bg-[var(--app-panel-soft)]">
            {t('weekend')}
          </button>
          <button type="button" onClick={() => selectDays(hourDays.map(([key]) => key))} className="rounded-md border px-3 py-1 text-xs font-bold app-panel app-text-soft hover:bg-[var(--app-panel-soft)]">
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
                className={`rounded-md border px-3 py-1 text-xs font-bold transition ${active ? 'border-indigo-600 bg-indigo-600 text-white' : 'app-panel app-text-soft hover:bg-[var(--app-panel-soft)]'}`}
              >
                {t(label)}
              </button>
            );
          })}
        </div>
        <div className="flex flex-col gap-2 sm:flex-row">
          <Input
            value={bulkHours}
            onChange={(event) => setBulkHours(event.target.value)}
            onBlur={() => {
              if (!bulkValidation.error) setBulkHours(bulkValidation.normalized);
            }}
            placeholder="09:00 - 18:00 / Inchis"
            className={bulkValidation.error ? 'border-red-400 focus:border-red-500 focus:ring-red-100' : undefined}
          />
          <Button type="button" onClick={applyBulkHours} disabled={selectedDays.length === 0 || !bulkHours.trim() || Boolean(bulkValidation.error)} className="min-w-40 whitespace-nowrap">
            {t('applySchedule')}
          </Button>
        </div>
        {bulkValidation.error && (
          <p className="flex items-center gap-1 text-xs font-medium text-red-600">
            <AlertTriangle className="h-3.5 w-3.5" />
            {t(bulkValidation.error)}
          </p>
        )}
      </div>
      <div className="grid gap-3">
        {hourDays.map(([key, label]) => (
          <Field key={key} label={t(label)} error={errors[key] ? t(errors[key]) : undefined}>
            <Input
              value={hours[key] ?? ''}
              onChange={(event) => onChange(key, event.target.value)}
              onBlur={(event) => normalizeDayOnBlur(key, event.target.value)}
              placeholder="09:00 - 18:00 / Inchis"
              className={errors[key] ? 'border-red-400 focus:border-red-500 focus:ring-red-100' : undefined}
            />
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
          <span className="font-medium app-text">{hours[key] || '-'}</span>
        </div>
      ))}
    </div>
  );
}

function StaffManagement({ salon, query }: { salon: Salon; query: string }) {
  const t = useT();
  const [adding, setAdding] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [confirmation, setConfirmation] = useState<{ title: string; message: string; onConfirm: () => void } | null>(null);
  const defaultLocationIds = salon.locations.length === 1 ? [salon.locations[0].id] : [];
  const form = useForm({ name: '', role: '', email: '', phone: '', location_ids: defaultLocationIds, active: true, service_ids: [] as number[] });
  const editForm = useForm({ name: '', role: '', email: '', phone: '', location_ids: defaultLocationIds, active: true, service_ids: [] as number[] });
  const normalizedQuery = query.trim().toLocaleLowerCase();
  const filteredStaff = (salon.staff ?? []).filter((staffMember) => {
    if (!normalizedQuery) return true;
    const searchable = [staffMember.name, staffMember.role, staffMember.email, staffMember.phone, staffLocationNames(staffMember), ...(staffMember.services ?? []).map((service) => service.name)];
    return searchable.filter(Boolean).some((value) => String(value).toLocaleLowerCase().includes(normalizedQuery));
  });

  function submit(event: FormEvent) {
    event.preventDefault();
    form.post('/staff', {
      preserveScroll: true,
      onSuccess: () => {
        form.reset();
        form.setData({ name: '', role: '', email: '', phone: '', location_ids: defaultLocationIds, active: true, service_ids: [] });
        setAdding(false);
      },
    });
  }

  function startEdit(staffMember: Staff) {
    const locationIds = (staffMember.locations ?? []).map((location) => location.id);
    setEditingId(staffMember.id);
    editForm.setData({
      name: staffMember.name,
      role: staffMember.role ?? '',
      email: staffMember.email ?? '',
      phone: staffMember.phone ?? '',
      location_ids: locationIds.length > 0 ? locationIds : staffMember.location_id ? [staffMember.location_id] : defaultLocationIds,
      active: Boolean(staffMember.active ?? true),
      service_ids: (staffMember.services ?? []).map((service) => service.id),
    });
  }

  function submitEdit(event: FormEvent) {
    event.preventDefault();
    if (!editingId) return;
    editForm.put(`/staff/${editingId}`, { preserveScroll: true, onSuccess: () => setEditingId(null) });
  }

  return (
    <div className="space-y-6">
      <ConfirmationModal
        open={confirmation !== null}
        title={confirmation?.title ?? ''}
        message={confirmation?.message ?? ''}
        confirmLabel={t('delete')}
        cancelLabel={t('cancel')}
        onCancel={() => setConfirmation(null)}
        onConfirm={() => {
          if (!confirmation) return;
          confirmation.onConfirm();
          setConfirmation(null);
        }}
      />
      <Toolbar title="" subtitle="" hideText action={<Button onClick={() => setAdding(true)}><Plus className="h-4 w-4" /> {t('addStaffMember')}</Button>} />
      {adding && (
        <Card className="p-5">
          <form className="space-y-5" onSubmit={submit}>
            <StaffFormFields salon={salon} form={form} t={t} />
            <div className="flex gap-2">
              <Button type="submit" disabled={form.processing}>{t('save')}</Button>
              <SecondaryButton type="button" onClick={() => setAdding(false)}>{t('cancel')}</SecondaryButton>
            </div>
          </form>
        </Card>
      )}
      {filteredStaff.length === 0 ? (
        <Card className="flex min-h-52 flex-col items-center justify-center p-8 text-center">
          <Users className="mb-4 h-10 w-10 app-text-muted" />
          <p className="text-lg font-bold app-text">{t('noStaffMembersYet')}</p>
          <p className="mt-2 max-w-xl text-sm app-text-muted">{t('staffEmptyHelp')}</p>
          <Button className="mt-5" onClick={() => setAdding(true)}><Plus className="h-4 w-4" /> {t('addYourTeam')}</Button>
        </Card>
      ) : (
        <div className="grid gap-4 xl:grid-cols-2">
          {filteredStaff.map((staffMember) => (
            <Card key={staffMember.id} className="p-5">
              {editingId === staffMember.id ? (
                <form className="space-y-5" onSubmit={submitEdit}>
                  <StaffFormFields salon={salon} form={editForm} t={t} />
                  <div className="flex gap-2">
                    <Button type="submit" disabled={editForm.processing}>{t('save')}</Button>
                    <SecondaryButton type="button" onClick={() => setEditingId(null)}>{t('cancel')}</SecondaryButton>
                  </div>
                </form>
              ) : (
                <div className="space-y-4">
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <div className="flex flex-wrap items-center gap-2">
                        <h3 className="truncate text-lg font-bold app-text">{staffMember.name}</h3>
                        <Badge tone={staffMember.active ? 'green' : 'slate'}>{staffMember.active ? t('active') : t('inactive')}</Badge>
                      </div>
                      <p className="mt-1 text-sm app-text-muted">{staffMember.role || t('role')}</p>
                    </div>
                    <div className="flex gap-2">
                      <SecondaryButton onClick={() => startEdit(staffMember)}><Pencil className="h-4 w-4" /></SecondaryButton>
                      <DangerButton onClick={() => setConfirmation({
                        title: t('deleteStaffMember'),
                        message: t('deleteStaffMemberConfirm'),
                        onConfirm: () => router.delete(`/staff/${staffMember.id}`, { preserveScroll: true }),
                      })}><Trash2 className="h-4 w-4" /></DangerButton>
                    </div>
                  </div>
                  <div className="grid gap-3 text-sm sm:grid-cols-2">
                    <InfoLine label={t('location')} value={staffLocationNames(staffMember)} />
                    <InfoLine label={t('services')} value={(staffMember.services ?? []).length > 0 ? (staffMember.services ?? []).map((service) => service.name) : '-'} />
                    <InfoLine label={t('email')} value={staffMember.email || '-'} />
                    <InfoLine label={t('phone')} value={staffMember.phone || '-'} />
                  </div>
                </div>
              )}
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}

function StaffFormFields({ salon, form, t }: { salon: Salon; form: any; t: TranslateFn }) {
  const serviceGroups = useMemo(() => {
    const groups = new Map<string, Service[]>();
    salon.services.forEach((service) => {
      const category = service.type || t('noCategory');
      groups.set(category, [...(groups.get(category) ?? []), service]);
    });
    return Array.from(groups.entries()).map(([category, services]) => ({ category, services }));
  }, [salon.services, t]);

  useEffect(() => {
    if (salon.locations.length === 1 && (form.data.location_ids ?? []).length === 0) {
      form.setData('location_ids', [salon.locations[0].id]);
    }
  }, [salon.locations, form.data.location_ids]);

  function toggleService(serviceId: number) {
    const selected = form.data.service_ids.includes(serviceId)
      ? form.data.service_ids.filter((id: number) => id !== serviceId)
      : [...form.data.service_ids, serviceId];
    form.setData('service_ids', selected);
  }

  function toggleCategoryServices(serviceIds: number[]) {
    const selectedIds = form.data.service_ids as number[];
    const allSelected = serviceIds.every((id) => selectedIds.includes(id));
    const next = allSelected
      ? selectedIds.filter((id) => !serviceIds.includes(id))
      : [...selectedIds, ...serviceIds.filter((id) => !selectedIds.includes(id))];
    form.setData('service_ids', next);
  }

  return (
    <>
      <div className="grid gap-4 lg:grid-cols-3">
        <Field label={t('staffMemberName')} error={form.errors.name}><Input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} /></Field>
        <Field label={t('role')} error={form.errors.role}><Input value={form.data.role} onChange={(event) => form.setData('role', event.target.value)} /></Field>
        <Field label={t('location')} error={form.errors.location_ids || form.errors.location_id}>
          <StaffLocationPicker locations={salon.locations} selectedIds={form.data.location_ids ?? []} onChange={(locationIds) => form.setData('location_ids', locationIds)} emptyLabel={t('noBranches')} />
        </Field>
        <Field label={t('email')} error={form.errors.email}><Input type="email" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} /></Field>
        <Field label={t('phone')} error={form.errors.phone}><Input value={form.data.phone} onChange={(event) => form.setData('phone', event.target.value)} /></Field>
        <div className="flex items-end"><ToggleRow title={t('active')} subtitle={form.data.active ? t('active') : t('inactive')} checked={form.data.active} onChange={(checked) => form.setData('active', checked)} /></div>
      </div>
      <div>
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2"><p className="text-sm font-bold app-text">{t('services')}</p></div>
        {salon.services.length === 0 ? (
          <p className="rounded-lg border p-4 text-sm app-text-muted app-border">{t('noServices')}</p>
        ) : (
          <div className="space-y-2">
            {serviceGroups.map(({ category, services }) => {
              const serviceIds = services.map((service) => service.id);
              const allCategorySelected = serviceIds.length > 0 && serviceIds.every((id) => form.data.service_ids.includes(id));
              return (
                <details key={category} className="rounded-lg border app-border app-panel">
                  <summary className="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3">
                    <span className="font-bold app-text">{category}</span>
                    <span className="flex items-center gap-3">
                      <button type="button" onClick={(event) => { event.preventDefault(); toggleCategoryServices(serviceIds); }} className="text-xs font-bold text-indigo-600 hover:underline">
                        {allCategorySelected ? t('clearSelection') : t('selectAll')}
                      </button>
                      <ChevronDown className="h-4 w-4 app-text-muted" />
                    </span>
                  </summary>
                  <div className="divide-y border-t app-border">
                    {services.map((service) => {
                      const checked = form.data.service_ids.includes(service.id);
                      return (
                        <label key={service.id} className="flex cursor-pointer items-center gap-3 px-4 py-3 text-sm font-medium transition app-border app-text-soft hover:bg-[var(--app-panel-soft)]">
                          <input type="checkbox" checked={checked} onChange={() => toggleService(service.id)} className="h-4 w-4 rounded border-[var(--app-border)] text-indigo-600 focus:ring-indigo-500" />
                          <span className="app-text">{service.name}</span>
                        </label>
                      );
                    })}
                  </div>
                </details>
              );
            })}
          </div>
        )}
        {form.errors.service_ids && <p className="mt-2 text-xs font-bold text-red-500">{form.errors.service_ids}</p>}
      </div>
    </>
  );
}

function staffLocationNames(staffMember: Staff): string {
  const locations = staffMember.locations ?? [];
  if (locations.length > 0) return locations.map((location) => location.name).join(', ');
  return staffMember.location?.name ?? '-';
}

function StaffLocationPicker({ locations, selectedIds, onChange, emptyLabel }: { locations: SalonLocation[]; selectedIds: number[]; onChange: (ids: number[]) => void; emptyLabel: string }) {
  if (locations.length === 0) return <p className="text-sm app-text-muted">{emptyLabel}</p>;
  if (locations.length === 1) {
    return <div className="flex h-10 w-full items-center rounded-lg border px-3 text-sm font-medium app-panel app-text app-border">{locations[0].name}</div>;
  }
  return <MultiSelectDropdown options={locations.map((location) => ({ value: location.id, label: location.name }))} selected={selectedIds ?? []} onChange={(next) => onChange(next as number[])} emptyLabel={emptyLabel} />;
}

function capacityLabel(value: number | null | undefined, t: TranslateFn): string {
  return value ? t('capacityBookingsAtSameTime', { count: value }) : t('defaultCapacityOne');
}

function capacityValue(value: number | null | undefined, t: TranslateFn): string {
  return value
    ? t('capacityBookingsAtSameTime', { count: value })
    : t('defaultCapacityOne').replace(/^(Implicit|Default):\s*/, '');
}

function ContactMetaLine({ label, value }: { label: string; value: string }) {
  return (
    <p>
      <span className="font-semibold">{label}:</span> {value}
    </p>
  );
}

function InfoLine({ label, value }: { label: string; value: string | string[] }) {
  const values = Array.isArray(value) ? value : [value];

  return (
    <div className="rounded-lg border p-3 app-border">
      <p className="text-xs font-bold uppercase tracking-wide app-text-muted">{label}</p>
      <div className="mt-1 space-y-1 break-words font-medium app-text">
        {values.map((item, index) => <p key={`${item}-${index}`}>{item}</p>)}
      </div>
    </div>
  );
}

function Services({ salon, query }: { salon: Salon; query: string }) {
  const t = useT();
  const { localization } = usePage<Props>().props;
  const [adding, setAdding] = useState(false);
  const [managingCategories, setManagingCategories] = useState(false);
  const [editingServiceId, setEditingServiceId] = useState<number | null>(null);
  const [confirmation, setConfirmation] = useState<{ title: string; message: string; tone?: 'danger' | 'neutral'; confirmLabel?: string; onConfirm: () => void } | null>(null);
  const [importModalOpen, setImportModalOpen] = useState(false);
  const [importAlert, setImportAlert] = useState<{ title: string; message: string } | null>(null);
  const [categoryFilter, setCategoryFilter] = useState<string[]>([]);
  const [branchFilter, setBranchFilter] = useState<number[]>([]);
  const [categoryDrafts, setCategoryDrafts] = useState<string[]>(salon.service_categories ?? []);
  const [serviceNameError, setServiceNameError] = useState('');
  const defaultServiceLocationIds = salon.locations.length === 1 ? [salon.locations[0].id] : [];
  const defaultCurrency = defaultServiceCurrency(salon);
  const currencyOptions = serviceCurrencyOptions(localization, salon);
  const form = useForm({ name: '', type: '', price: '', currency: defaultCurrency, duration: 30, max_concurrent_bookings: '', location_ids: defaultServiceLocationIds, notes: '' });
  const editForm = useForm({ name: '', type: '', price: '', currency: defaultCurrency, duration: 30, max_concurrent_bookings: '', location_ids: [] as number[], notes: '' });
  const serviceFormCurrencyOptions = Array.from(new Map([
    ...currencyOptions,
    { code: form.data.currency, label: form.data.currency },
    { code: editForm.data.currency, label: editForm.data.currency },
  ].filter((currency) => currency.code).map((currency) => [currency.code, currency])).values());
  const serviceStats = {
    services: salon.services.length,
    categories: (salon.service_categories ?? []).filter(Boolean).length,
    locations: salon.locations.length,
    staff: (salon.staff ?? []).length,
  };
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
      service.currency,
      service.duration,
      service.notes,
      ...(service.staff_members ?? []).map((staffMember) => staffMember.name),
      ...serviceLocationNames,
    ];

    return searchable
      .filter((value) => value !== null && value !== undefined)
      .some((value) => String(value).toLocaleLowerCase().includes(normalizedQuery));
  });

  function submit(event: FormEvent) {
    event.preventDefault();

    if (!form.data.name.trim()) {
      setServiceNameError(t('serviceNameRequired'));
      return;
    }

    setServiceNameError('');
    form.transform((data) => ({
      ...data,
      location_ids: salon.locations.length === 1 ? [salon.locations[0].id] : data.location_ids,
    }));
    form.post('/services', {
      preserveScroll: true,
      onSuccess: () => {
        form.reset();
        form.setData('location_ids', defaultServiceLocationIds);
        form.setData('currency', defaultCurrency);
        setServiceNameError('');
        setAdding(false);
      },
    });
  }

  function updateServiceBranches(service: Service, locationIds: number[]) {
    if (salon.locations.length > 0 && locationIds.length === 0) return;

    router.put(`/services/${service.id}`, {
      name: service.name,
      type: service.type ?? '',
      price: String(service.price ?? ''),
      currency: service.currency ?? defaultCurrency,
      duration: service.duration,
      location_ids: locationIds,
      notes: service.notes ?? '',
    }, { preserveScroll: true });
  }

  function updateServiceCategory(service: Service, type: string) {
    router.put(`/services/${service.id}`, {
      name: service.name,
      type,
      price: String(service.price ?? ''),
      currency: service.currency ?? defaultCurrency,
      duration: service.duration,
      max_concurrent_bookings: service.max_concurrent_bookings ? String(service.max_concurrent_bookings) : '',
      location_ids: service.location_ids ?? [],
      notes: service.notes ?? '',
    }, { preserveScroll: true });
  }

  function startEditService(service: Service) {
    setEditingServiceId(service.id);
    editForm.setData({
      name: service.name,
      type: service.type ?? '',
      price: String(service.price ?? ''),
      currency: service.currency ?? defaultCurrency,
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
    const categories = Array.from(new Set(categoryDrafts.map((category) => category.trim()).filter(Boolean)));

    router.put('/services/categories', {
      categories,
    }, {
      preserveScroll: true,
      onSuccess: () => setManagingCategories(false),
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
      <AlertModal
        open={importAlert !== null}
        title={importAlert?.title ?? ''}
        message={importAlert?.message ?? ''}
        okLabel="OK"
        onClose={() => setImportAlert(null)}
      />
      <ServiceImageImportModal
        open={importModalOpen}
        salon={salon}
        onClose={() => setImportModalOpen(false)}
        onImported={(createdCount, skippedCount) => {
          setImportModalOpen(false);
          setImportAlert({
            title: skippedCount > 0 ? t('serviceImportPartialSuccess') : t('serviceImportSuccess'),
            message: skippedCount > 0
              ? t('serviceImportDuplicateSkipped', { count: skippedCount })
              : t('serviceImportCreatedCount', { count: createdCount }),
          });
          router.visit(window.location.href, {
            only: ['salon'],
            preserveScroll: true,
            preserveState: true,
            replace: true,
          });
        }}
      />
      <EditModal open={editingServiceId !== null} title={t('editService')} onClose={() => setEditingServiceId(null)}>
        <form className="space-y-5" onSubmit={submitEditService}>
          <div className="grid gap-4 xl:grid-cols-2">
            <ServiceConfiguratorField icon={FileText} label={t('category')} error={editForm.errors.type}>
              <select className="h-10 w-full rounded-lg border px-3 text-sm outline-none app-panel app-text" value={editForm.data.type} onChange={(event) => editForm.setData('type', event.target.value)}>
                <option value="">{t('noCategory')}</option>
                {Array.from(new Set([...(salon.service_categories ?? []), ...(editForm.data.type ? [editForm.data.type] : [])])).map((category) => (
                  <option key={category} value={category}>{category}</option>
                ))}
              </select>
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
          <div className="grid gap-4">
            <Field label={t('service')} error={editForm.errors.name}><Input value={editForm.data.name} onChange={(event) => editForm.setData('name', event.target.value)} /></Field>
          </div>
          <div className="grid gap-4 lg:grid-cols-4">
            <Field label={t('priceRon')} error={editForm.errors.price}><Input value={editForm.data.price} onChange={(event) => editForm.setData('price', event.target.value)} placeholder={t('pricePlaceholder')} /></Field>
            <Field label={t('currency')} error={editForm.errors.currency}>
              <select className="h-10 w-full rounded-lg border px-3 text-sm outline-none app-panel app-text" value={editForm.data.currency} onChange={(event) => editForm.setData('currency', event.target.value)}>
                {serviceFormCurrencyOptions.map((currency) => (
                  <option key={currency.code} value={currency.code}>{currency.label}</option>
                ))}
              </select>
            </Field>
            <Field label={t('durationMin')} error={editForm.errors.duration}><Input type="number" value={editForm.data.duration} onChange={(event) => editForm.setData('duration', Number(event.target.value))} /></Field>
            <Field label={t('maxSimultaneousBookingsForService')} error={editForm.errors.max_concurrent_bookings}>
              <Input type="number" min={1} max={100} value={editForm.data.max_concurrent_bookings} onChange={(event) => editForm.setData('max_concurrent_bookings', event.target.value)} />
              <span className="block text-xs app-text-muted">{t('serviceCapacityHelp')}</span>
            </Field>
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
                <DangerButton type="button" onClick={() => setConfirmation({
                  title: t('removeCategory'),
                  message: t('removeCategoryConfirm'),
                  onConfirm: () => removeCategoryDraft(index),
                })}><Trash2 className="h-4 w-4" /></DangerButton>
              </div>
            ))}
          </div>
          <div className="flex flex-wrap gap-2">
            <SecondaryButton type="button" onClick={addCategoryDraft}><Plus className="h-4 w-4" /> {t('addCategory')}</SecondaryButton>
            <Button type="button" onClick={saveCategories}>{t('save')}</Button>
            <SecondaryButton type="button" onClick={() => setManagingCategories(false)}>{t('cancel')}</SecondaryButton>
          </div>
        </div>
      </EditModal>
      <EditModal open={adding} title={t('addService')} onClose={() => setAdding(false)}>
        <form className="space-y-5" onSubmit={submit}>
          <div className="grid gap-4 xl:grid-cols-2">
            <ServiceConfiguratorField icon={FileText} label={t('category')} error={form.errors.type}>
              <select className="h-10 w-full rounded-lg border px-3 text-sm outline-none app-panel app-text" value={form.data.type} onChange={(event) => form.setData('type', event.target.value)}>
                <option value="">{t('noCategory')}</option>
                {(salon.service_categories ?? []).map((category) => (
                  <option key={category} value={category}>{category}</option>
                ))}
              </select>
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
          <div className="grid gap-4">
            <Field label={t('service')} error={serviceNameError || form.errors.name}><Input value={form.data.name} onChange={(event) => { form.setData('name', event.target.value); if (serviceNameError) setServiceNameError(''); }} /></Field>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <Field label={t('priceRon')} error={form.errors.price}><Input value={form.data.price} onChange={(event) => form.setData('price', event.target.value)} placeholder={t('pricePlaceholder')} /></Field>
              <Field label={t('currency')} error={form.errors.currency}>
                <select className="h-10 w-full rounded-lg border px-3 text-sm outline-none app-panel app-text" value={form.data.currency} onChange={(event) => form.setData('currency', event.target.value)}>
                  {serviceFormCurrencyOptions.map((currency) => (
                    <option key={currency.code} value={currency.code}>{currency.label}</option>
                  ))}
                </select>
              </Field>
              <Field label={t('durationMin')} error={form.errors.duration}><Input type="number" value={form.data.duration} onChange={(event) => form.setData('duration', Number(event.target.value))} /></Field>
              <Field label={t('maxSimultaneousBookingsForService')} error={form.errors.max_concurrent_bookings}>
                <Input type="number" min={1} max={100} value={form.data.max_concurrent_bookings} onChange={(event) => form.setData('max_concurrent_bookings', event.target.value)} />
                <span className="block text-xs app-text-muted">{t('serviceCapacityHelp')}</span>
              </Field>
            </div>
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
          <div className="grid w-full min-w-0 grid-cols-1 gap-2 sm:flex sm:flex-wrap">
            <SecondaryButton className="w-full sm:w-auto" onClick={openCategoryManager}><Plus className="h-4 w-4" /> {t('addEditCategory')}</SecondaryButton>
            <Link href="/dashboard/staff" className="inline-flex h-10 w-full min-w-0 items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-4 text-sm font-medium transition app-panel app-text-soft hover:bg-[var(--app-panel-soft)] sm:w-auto">
              <Users className="h-4 w-4" /> {t('manageStaff')}
            </Link>
            <Button className="w-full sm:w-auto" onClick={() => setAdding(true)}><Plus className="h-4 w-4" /> {t('addService')}</Button>
            <button
              type="button"
              onClick={() => setImportModalOpen(true)}
              className="ai-import-button inline-flex h-10 w-full min-w-0 items-center justify-center gap-2 rounded-lg border px-4 text-sm font-semibold transition app-panel focus:outline-none focus:ring-2 focus:ring-violet-500/30 sm:w-auto"
            >
              <Sparkles className="h-4 w-4" />
              <span>{t('importWithAi')}</span>
              <span className="ai-import-button-glow" aria-hidden="true" />
            </button>
          </div>
        }
      />
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <ChannelStat label={t('services')} value={serviceStats.services} icon={Scissors} tone="blue" />
        <ChannelStat label={t('categories')} value={serviceStats.categories} icon={FileText} tone="purple" />
        <ChannelStat label={t('locations')} value={serviceStats.locations} icon={MapPin} tone="green" />
        <ChannelStat label={t('staff')} value={serviceStats.staff} icon={Users} tone="slate" />
      </div>
      {filteredServices.length === 0 ? (
        <Card className="flex min-h-52 flex-col items-center justify-center p-8 text-center">
          <Scissors className="mb-4 h-10 w-10 app-text-muted" />
          <p className="text-lg font-bold app-text">{t('servicesEmptyTitle')}</p>
          <p className="mt-2 max-w-xl text-sm app-text-muted">{normalizedQuery || categoryFilter.length > 0 || branchFilter.length > 0 ? t('servicesEmptyFilteredHelp') : t('servicesEmptyHelp')}</p>
          <Button className="mt-5" onClick={() => setAdding(true)}><Plus className="h-4 w-4" /> {t('addService')}</Button>
        </Card>
      ) : (
        <DashboardTable headers={[
          <DashboardTableHeaderLabel>{t('service')}</DashboardTableHeaderLabel>,
          <span key="capacity-header" className="inline-flex items-center gap-1.5">
            <span className="leading-tight">
              {t('simultaneousBookingsLine1')}<br />
              {t('simultaneousBookingsLine2')}
            </span>
            <span className="group relative inline-flex">
              <span className="flex h-4 w-4 items-center justify-center rounded-full border text-[10px] font-semibold app-border app-text-muted">i</span>
              <span className="pointer-events-none absolute left-1/2 top-6 z-50 hidden w-56 -translate-x-1/2 rounded-lg border px-3 py-2 text-xs normal-case tracking-normal shadow-lg group-hover:block app-panel app-text">
                {t('simultaneousBookingsHelp')}
              </span>
            </span>
          </span>,
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
          <DashboardTableHeaderLabel>{t('duration')}</DashboardTableHeaderLabel>,
          <DashboardTableHeaderLabel>{t('priceRon')}</DashboardTableHeaderLabel>,
          <span className="sr-only">{t('actions')}</span>,
        ]} minWidth="980px">
          {filteredServices.map((service, index) => (
            <tr key={service.id} className={dashboardTableRowClass(index)}>
              <>
                  <td className="px-5 py-4 align-top">
                    <div className="flex flex-wrap items-center gap-2">
                      <p className="font-semibold app-text">{service.name}</p>
                      {!!service.notes && <ServiceNotesPill notes={service.notes} />}
                    </div>
                    {(service.staff_members ?? []).length > 0 && (
                      <div className="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm app-text-soft">
                        {(service.staff_members ?? []).map((staffMember, index) => (
                          <span key={staffMember.id} className="inline-flex items-center gap-1.5">
                            {index > 0 && <span className="app-text-muted">{'\u2022'}</span>}
                            <span>{staffMember.name}</span>
                            {!staffMember.active && (
                              <span className={`${TABLE_PILL_CLASS} border border-slate-200 app-panel app-text-muted`}>
                                {t('inactive')}
                              </span>
                            )}
                          </span>
                        ))}
                      </div>
                    )}
                  </td>
                  <td className="px-5 py-4 text-sm font-semibold app-text">{service.max_concurrent_bookings ?? 1}</td>
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
                  <td className="px-5 py-4 font-semibold text-indigo-700">{formatServicePrice(service.price, salon, service.currency)}</td>
                  <td className="px-5 py-4">
                    <RowActionsMenu label={t('actions')}>
                      {(close) => (
                        <>
                          <RowActionButton onClick={() => { close(); startEditService(service); }}>
                            <Pencil className="h-4 w-4" />
                            {t('editService')}
                          </RowActionButton>
                          <RowActionButton tone="danger" onClick={() => {
                            close();
                            setConfirmation({
                              title: t('deleteService'),
                              message: t('deleteServiceConfirm'),
                              onConfirm: () => router.delete(`/services/${service.id}`, { preserveScroll: true }),
                            });
                          }}>
                            <Trash2 className="h-4 w-4" />
                            {t('deleteService')}
                          </RowActionButton>
                        </>
                      )}
                    </RowActionsMenu>
                  </td>
              </>
            </tr>
          ))}
        </DashboardTable>
      )}
    </div>
  );
}

function ServiceImageImportModal({
  open,
  salon,
  onClose,
  onImported,
}: {
  open: boolean;
  salon: Salon;
  onClose: () => void;
  onImported: (createdCount: number, skippedCount: number) => void;
}) {
  const t = useT();
  const [image, setImage] = useState<File | null>(null);
  const [previewUrl, setPreviewUrl] = useState('');
  const [candidates, setCandidates] = useState<ImportedServiceCandidate[]>([]);
  const [analyzing, setAnalyzing] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [warning, setWarning] = useState('');
  const existingNames = useMemo(() => new Set(salon.services.map((service) => normalizeServiceName(service.name))), [salon.services]);
  const selectedCount = candidates.filter((candidate) => candidate.selected && candidate.name.trim()).length;

  useEffect(() => {
    if (!image) {
      setPreviewUrl('');
      return;
    }

    const url = URL.createObjectURL(image);
    setPreviewUrl(url);

    return () => URL.revokeObjectURL(url);
  }, [image]);

  useEffect(() => {
    if (!open) {
      setImage(null);
      setCandidates([]);
      setError('');
      setWarning('');
      setAnalyzing(false);
      setSaving(false);
    }
  }, [open]);

  if (!open) return null;

  function chooseImage(file: File | null) {
    setError('');
    setWarning('');
    setCandidates([]);
    setImage(file);
  }

  async function analyzeImage() {
    if (!image || analyzing) return;

    if (image.size > SERVICE_IMPORT_MAX_IMAGE_BYTES) {
      setError(t('serviceImportImageTooLarge'));
      setCandidates([]);
      return;
    }

    setAnalyzing(true);
    setError('');
    setWarning('');

    const body = new FormData();
    body.append('image', image);

    try {
      const response = await fetch('/dashboard/services/import-image/analyze', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          ...csrfHeaders(),
        },
        body,
      });
      const data = await serviceImportResponseData(response, t);

      if (!response.ok) {
        throw new Error(serviceImportErrorMessage(data, t));
      }

      const services = Array.isArray(data.services) ? data.services : [];
      const nextCandidates = services.map((service: Record<string, unknown>): ImportedServiceCandidate => {
        const name = String(service.name ?? '').trim();

        return {
          name,
          category: String(service.category ?? ''),
          duration_minutes: typeof service.duration_minutes === 'number' ? service.duration_minutes : '',
          price: service.price === null || service.price === undefined ? '' : String(service.price),
          description: String(service.description ?? ''),
          notes: String(service.notes ?? ''),
          selected: true,
          duplicate: existingNames.has(normalizeServiceName(name)),
        };
      }).filter((service: ImportedServiceCandidate) => service.name);

      const warningMessage = typeof data.warning === 'string' && data.warning.trim()
        ? data.warning
        : nextCandidates.length === 0
          ? t('noServicesDetected')
          : '';

      setCandidates(nextCandidates);
      setWarning(warningMessage);
    } catch (error) {
      setError(error instanceof Error ? error.message : t('serviceImportFailed'));
      setCandidates([]);
    } finally {
      setAnalyzing(false);
    }
  }

  function updateCandidate(index: number, patch: Partial<ImportedServiceCandidate>) {
    setCandidates((current) => current.map((candidate, candidateIndex) => {
      if (candidateIndex !== index) return candidate;

      const next = { ...candidate, ...patch };
      if (patch.name !== undefined) {
        next.duplicate = existingNames.has(normalizeServiceName(patch.name));
      }

      return next;
    }));
  }

  async function insertSelectedServices() {
    if (saving || selectedCount === 0) return;

    setSaving(true);
    setError('');

    try {
      const response = await fetch('/dashboard/services/import-image/store', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          ...csrfHeaders(),
        },
        body: JSON.stringify({
          services: candidates.map((candidate) => ({
            name: candidate.name.trim(),
            category: candidate.category.trim() || null,
            duration_minutes: candidate.duration_minutes === '' ? null : Number(candidate.duration_minutes),
            price: candidate.price.trim() === '' ? null : Number(candidate.price),
            description: candidate.description.trim() || null,
            notes: candidate.notes.trim() || null,
            selected: candidate.selected,
          })),
        }),
      });
      const data = await serviceImportResponseData(response, t);

      if (!response.ok) {
        throw new Error(serviceImportErrorMessage(data, t));
      }

      onImported(Number(data.created_count ?? 0), Array.isArray(data.skipped) ? data.skipped.length : 0);
    } catch (error) {
      setError(error instanceof Error ? error.message : t('serviceImportFailed'));
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm">
      <div className="max-h-[calc(100vh-2rem)] w-full max-w-5xl overflow-y-auto rounded-lg border p-5 shadow-xl app-panel" role="dialog" aria-modal="true" aria-labelledby="service-import-title">
        <div className="mb-5 flex items-start justify-between gap-4">
          <div>
            <h2 id="service-import-title" className="text-lg font-bold app-text">{t('importServicesFromImage')}</h2>
            <p className="mt-1 max-w-2xl text-sm app-text-muted">{t('importServicesFromImageSubtitle')}</p>
          </div>
          <button
            type="button"
            aria-label="Close"
            onClick={onClose}
            className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg app-text-soft hover:bg-[var(--app-panel-soft)]"
          >
            <X className="h-4 w-4" />
          </button>
        </div>

        <div className="grid gap-5 lg:grid-cols-[280px_1fr]">
          <div className="space-y-4">
            <label className="block rounded-lg border border-dashed p-4 text-sm app-border app-panel-soft">
              <span className="mb-2 block text-xs font-bold uppercase tracking-wide app-text-muted">{t('uploadServiceImage')}</span>
              <input
                type="file"
                accept="image/jpeg,image/png,image/webp"
                className="block w-full text-sm app-text-soft file:mr-3 file:rounded-lg file:border-0 file:bg-indigo-600 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-white"
                onChange={(event) => chooseImage(event.target.files?.[0] ?? null)}
              />
            </label>

            {image && (
              <div className="space-y-3 rounded-lg border p-3 app-border app-panel">
                <p className="text-xs font-bold uppercase tracking-wide app-text-muted">{t('selectedFile')}</p>
                <p className="break-all text-sm font-semibold app-text">{image.name}</p>
                {previewUrl && <img src={previewUrl} alt="" className="max-h-44 w-full rounded-lg object-cover" />}
                <Button type="button" onClick={analyzeImage} disabled={!image || analyzing} className="w-full">
                  <Sparkles className="h-4 w-4" />
                  {analyzing ? t('analyzingImage') : t('analyzeImage')}
                </Button>
              </div>
            )}
          </div>

          <div className="min-w-0 space-y-4">
            {(error || warning) && (
              <div className={`rounded-lg border px-4 py-3 text-sm ${error ? 'border-red-200 bg-red-50 text-red-700' : 'border-amber-200 bg-amber-50 text-amber-700'}`}>
                {error || warning}
              </div>
            )}

            <div>
              <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <div>
                  <h3 className="text-sm font-bold app-text">{t('extractedServices')}</h3>
                  <p className="text-xs app-text-muted">{t('serviceImportReviewHint')}</p>
                </div>
                <Badge tone="indigo">{selectedCount} {t('selected')}</Badge>
              </div>

              {candidates.length === 0 ? (
                <div className="flex min-h-48 items-center justify-center rounded-lg border p-6 text-center app-border app-panel-soft">
                  <p className="max-w-md text-sm app-text-muted">{t('noServicesDetected')}</p>
                </div>
              ) : (
                <div className="overflow-x-auto rounded-lg border app-border">
                  <table className="w-full min-w-[820px] text-left text-sm">
                    <thead className="app-panel-soft">
                      <tr>
                        <th className="w-14 px-3 py-2">{t('selected')}</th>
                        <th className="px-3 py-2">{t('service')}</th>
                        <th className="w-40 px-3 py-2">{t('category')}</th>
                        <th className="w-32 px-3 py-2">{t('durationMinutes')}</th>
                        <th className="w-32 px-3 py-2">{t('price')}</th>
                        <th className="px-3 py-2">{t('description')}</th>
                        <th className="px-3 py-2">{t('notes')}</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y app-border">
                      {candidates.map((candidate, index) => (
                        <tr key={index}>
                          <td className="px-3 py-2 align-top">
                            <input
                              type="checkbox"
                              checked={candidate.selected}
                              onChange={(event) => updateCandidate(index, { selected: event.target.checked })}
                              className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                            />
                          </td>
                          <td className="px-3 py-2 align-top">
                            <Input value={candidate.name} onChange={(event) => updateCandidate(index, { name: event.target.value })} />
                            {candidate.duplicate && <span className="mt-1 block text-xs font-semibold text-amber-600">{t('serviceImportDuplicateLabel')}</span>}
                          </td>
                          <td className="px-3 py-2 align-top">
                            <Input value={candidate.category} onChange={(event) => updateCandidate(index, { category: event.target.value })} />
                          </td>
                          <td className="px-3 py-2 align-top">
                            <Input type="number" min={1} max={1440} value={candidate.duration_minutes} onChange={(event) => updateCandidate(index, { duration_minutes: event.target.value === '' ? '' : Number(event.target.value) })} />
                          </td>
                          <td className="px-3 py-2 align-top">
                            <Input value={candidate.price} onChange={(event) => updateCandidate(index, { price: event.target.value })} />
                          </td>
                          <td className="px-3 py-2 align-top">
                            <Input value={candidate.description} onChange={(event) => updateCandidate(index, { description: event.target.value })} />
                          </td>
                          <td className="px-3 py-2 align-top">
                            <Input value={candidate.notes} onChange={(event) => updateCandidate(index, { notes: event.target.value })} />
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>

            <div className="flex flex-wrap justify-end gap-2">
              <SecondaryButton onClick={onClose}>{t('cancel')}</SecondaryButton>
              <Button type="button" onClick={insertSelectedServices} disabled={saving || selectedCount === 0}>
                {saving ? t('saving') : t('insertSelectedServices')}
              </Button>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

function csrfHeaders(): Record<string, string> {
  const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
  const xsrf = document.cookie
    .split('; ')
    .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
    ?.split('=')[1];

  return {
    'X-Requested-With': 'XMLHttpRequest',
    ...(token ? { 'X-CSRF-TOKEN': token } : {}),
    ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
  };
}

async function serviceImportResponseData(response: Response, t: TranslateFn): Promise<Record<string, unknown>> {
  if (response.status === 413) {
    throw new Error(t('serviceImportImageTooLargeServer'));
  }

  const contentType = response.headers.get('content-type') ?? '';

  if (!contentType.includes('application/json')) {
    const body = await response.text().catch(() => '');

    if (!response.ok) {
      throw new Error(serviceImportHttpErrorMessage(response, body, t));
    }

    throw new Error(t('serviceImportFailed'));
  }

  const data = await response.json().catch(() => null);

  if (data && typeof data === 'object') {
    return data as Record<string, unknown>;
  }

  if (!response.ok) {
    throw new Error(serviceImportHttpErrorMessage(response, '', t));
  }

  throw new Error(t('serviceImportFailed'));
}

function serviceImportHttpErrorMessage(response: Response, body: string, t: TranslateFn): string {
  if (response.status === 413) {
    return t('serviceImportImageTooLargeServer');
  }

  const compactBody = body.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();

  return compactBody
    ? `${t('serviceImportFailed')} (${response.status}: ${compactBody.slice(0, 120)})`
    : `${t('serviceImportFailed')} (${response.status})`;
}

function serviceImportErrorMessage(data: unknown, t: TranslateFn): string {
  if (data && typeof data === 'object') {
    const payload = data as { message?: unknown; details?: unknown; errors?: Record<string, string[]> };
    const validationMessage = payload.errors?.image?.[0] ?? Object.values(payload.errors ?? {})[0]?.[0];
    const details = typeof payload.details === 'string' ? payload.details : '';
    const message = typeof payload.message === 'string' ? payload.message : '';

    return validationMessage || details || message || t('serviceImportFailed');
  }

  return t('serviceImportFailed');
}

function normalizeServiceName(name: string): string {
  return name.normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim().toLocaleLowerCase().replace(/\s+/g, ' ');
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
        {active && <span className="flex h-4 w-4 items-center justify-center rounded-full bg-indigo-600 text-[10px] font-semibold text-white">{selected.length}</span>}
        <ChevronDown className={`h-3 w-3 transition-transform ${open ? 'rotate-180' : ''}`} />
      </button>
      {open && (
        <div className="absolute left-0 top-full z-50 mt-1 min-w-44 rounded-lg border p-1 shadow-lg app-panel normal-case tracking-normal">
          {selected.length > 0 && (
            <button
              type="button"
              onClick={() => { onChange([]); setOpen(false); }}
              className="flex w-full items-center gap-2.5 rounded-md px-3 py-2 text-xs font-medium text-indigo-600 transition hover:bg-[var(--app-panel-soft)]"
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
                className="flex w-full items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium transition hover:bg-[var(--app-panel-soft)]"
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
  const t = useT();
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
    return (
      <span className="flex flex-col gap-1">
        <span>{label.toLocaleUpperCase()}</span>
        <Link
          href="/dashboard/locations"
          className="inline-flex w-fit rounded-full bg-red-600 px-2 py-0.5 text-[10px] font-black normal-case tracking-normal text-yellow-200 shadow-sm transition hover:bg-red-700 hover:text-yellow-100 focus:outline-none focus:ring-2 focus:ring-red-500/30"
        >
          {t('addMinimumLocation')}
        </Link>
      </span>
    );
  }

  return (
    <div ref={ref} className="relative inline-block">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className={`inline-flex items-center gap-1.5 rounded-md px-1.5 py-1 transition hover:bg-white/10 ${active ? 'text-indigo-400' : ''}`}
      >
        {label.toLocaleUpperCase()}
        {active && <span className="flex h-4 w-4 items-center justify-center rounded-full bg-indigo-600 text-[10px] font-semibold text-white">{selected.length}</span>}
        <ChevronDown className={`h-3 w-3 transition-transform ${open ? 'rotate-180' : ''}`} />
      </button>
      {open && (
        <div className="absolute left-0 top-full z-50 mt-1 min-w-44 rounded-lg border p-1 shadow-lg app-panel normal-case tracking-normal">
          {selected.length > 0 && (
            <button
              type="button"
              onClick={() => { onChange([]); setOpen(false); }}
              className="flex w-full items-center gap-2.5 rounded-md px-3 py-2 text-xs font-medium text-indigo-600 transition hover:bg-[var(--app-panel-soft)]"
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
                className="flex w-full items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium transition hover:bg-[var(--app-panel-soft)]"
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

function DateFilterHeader({ label, dates, selected, onChange, t }: { label: string; dates: string[]; selected: string[]; onChange: (next: string[]) => void; t: TranslateFn }) {
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

  function toggle(date: string) {
    onChange(selected.includes(date) ? selected.filter((value) => value !== date) : [...selected, date]);
  }

  if (dates.length === 0) {
    return <span>{label.toLocaleUpperCase()}</span>;
  }

  return (
    <div ref={ref} className="relative inline-block">
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        className={`inline-flex cursor-pointer items-center gap-1.5 rounded-md px-1.5 py-1 transition hover:bg-white/10 ${active ? 'text-indigo-400' : ''}`}
      >
        {label.toLocaleUpperCase()}
        {active && <span className="flex h-4 w-4 items-center justify-center rounded-full bg-indigo-600 text-[10px] font-semibold text-white">{selected.length}</span>}
        <ChevronDown className={`h-3 w-3 transition-transform ${open ? 'rotate-180' : ''}`} />
      </button>
      {open && (
        <div className="absolute left-0 top-full z-50 mt-1 min-w-56 rounded-lg border p-1 shadow-lg app-border app-panel normal-case tracking-normal">
          {selected.length > 0 && (
            <button
              type="button"
              onClick={() => { onChange([]); setOpen(false); }}
              className="flex w-full cursor-pointer items-center gap-2.5 rounded-md px-3 py-2 text-xs font-medium text-indigo-600 transition hover:bg-[var(--app-panel-soft)]"
            >
              Reset
            </button>
          )}
          {dates.map((date) => {
            const checked = selected.includes(date);
            return (
              <button
                key={date}
                type="button"
                onClick={() => toggle(date)}
                className="flex w-full cursor-pointer items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium transition hover:bg-[var(--app-panel-soft)]"
              >
                <span className={`flex h-4 w-4 shrink-0 items-center justify-center rounded border ${checked ? 'border-indigo-600 bg-indigo-600' : 'border-[var(--app-border)]'}`}>
                  {checked && <Check className="h-2.5 w-2.5 text-white" />}
                </span>
                <span className="app-text">{formatFilterDate(date, t)}</span>
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
}

function ArchiveDateRangeHeader({ label, availableDates, range, onChange, t }: { label: string; availableDates: string[]; range: DateRange; onChange: (next: DateRange) => void; t: TranslateFn }) {
  const { locale } = usePage<{ locale?: string }>().props;
  const dateLocale = locale === 'en' ? 'en-GB' : 'ro-RO';
  const [open, setOpen] = useState(false);
  const buttonRef = useRef<HTMLButtonElement>(null);
  const [position, setPosition] = useState({ left: 0, top: 0 });
  const anchorDate = range.start || availableDates.at(-1) || toDateKey(new Date());
  const [visibleMonth, setVisibleMonth] = useState(() => monthFromDateKey(anchorDate));
  const active = Boolean(range.start || range.end);
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

  function toggleOpen() {
    const rect = buttonRef.current?.getBoundingClientRect();
    if (rect) {
      const panelWidth = 320;
      setPosition({
        left: Math.max(16, Math.min(rect.left, window.innerWidth - panelWidth - 16)),
        top: rect.bottom + 8,
      });
    }

    setOpen((value) => !value);
  }

  function selectDate(date: string) {
    if (!range.start || (range.start && range.end)) {
      onChange({ start: date, end: '' });
      return;
    }

    if (date < range.start) {
      onChange({ start: date, end: range.start });
      setOpen(false);
      return;
    }

    onChange({ start: range.start, end: date });
    setOpen(false);
  }

  function reset() {
    onChange({ start: '', end: '' });
    setOpen(false);
  }

  function isSelected(date: string) {
    return date === range.start || date === range.end;
  }

  function isInRange(date: string) {
    return Boolean(range.start && range.end && date > range.start && date < range.end);
  }

  if (availableDates.length === 0) {
    return <span>{label.toLocaleUpperCase()}</span>;
  }

  return (
    <div className="inline-block">
      <button
        ref={buttonRef}
        type="button"
        onClick={toggleOpen}
        className={`inline-flex cursor-pointer items-center gap-1.5 rounded-md px-1.5 py-1 transition hover:bg-white/10 ${active ? 'text-indigo-400' : ''}`}
      >
        {label.toLocaleUpperCase()}
        <ChevronDown className={`h-3 w-3 transition-transform ${open ? 'rotate-180' : ''}`} />
      </button>
      {open && (
        <>
        <span className="fixed inset-0 z-40" onClick={() => setOpen(false)} />
        <div className="fixed z-50 w-80 rounded-lg border p-3 shadow-lg app-border app-panel normal-case tracking-normal" style={{ left: position.left, top: position.top }}>
          <div className="mb-3 flex items-center justify-between gap-3">
            <div>
              <p className="text-sm font-bold capitalize app-text">{monthLabel}</p>
              {active && (
                <p className="mt-0.5 text-[11px] font-medium app-text-muted">
                  {range.start ? formatCompactDate(range.start, dateLocale) : ''}
                  {range.end ? ` - ${formatCompactDate(range.end, dateLocale)}` : ''}
                </p>
              )}
            </div>
            <div className="flex items-center gap-1">
              <button type="button" aria-label={t('previousMonth')} onClick={() => changeMonth(-1)} className="flex h-8 w-8 items-center justify-center rounded-md border app-panel app-text-soft hover:bg-[var(--app-panel-soft)]">
                <ChevronLeft className="h-4 w-4" />
              </button>
              <button type="button" aria-label={t('nextMonth')} onClick={() => changeMonth(1)} className="flex h-8 w-8 items-center justify-center rounded-md border app-panel app-text-soft hover:bg-[var(--app-panel-soft)]">
                <ChevronRight className="h-4 w-4" />
              </button>
            </div>
          </div>

          <div className="grid grid-cols-7 gap-1 text-center text-[10px] font-bold uppercase app-text-muted">
            {weekDays.map((day) => <div key={day} className="py-1">{day}</div>)}
          </div>
          <div className="mt-1 grid grid-cols-7 gap-1">
            {cells.map((day, index) => {
              if (!day) return <span key={`empty-${index}`} className="h-8" />;

              const dateKey = `${visibleMonth.getFullYear()}-${String(visibleMonth.getMonth() + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
              const selected = isSelected(dateKey);
              const inRange = isInRange(dateKey);

              return (
                <button
                  key={dateKey}
                  type="button"
                  onClick={() => selectDate(dateKey)}
                  className={`flex h-8 items-center justify-center rounded-md text-xs font-bold transition ${
                    selected
                      ? 'bg-indigo-600 text-white'
                      : inRange
                        ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-400/10 dark:text-indigo-200'
                        : 'app-text-soft hover:bg-[var(--app-panel-soft)]'
                  }`}
                >
                  {day}
                </button>
              );
            })}
          </div>

          {active && (
            <button
              type="button"
              onClick={reset}
              className="mt-3 flex w-full cursor-pointer items-center justify-center rounded-md px-3 py-2 text-xs font-bold text-indigo-600 transition hover:bg-[var(--app-panel-soft)]"
            >
              {t('clearSelection')}
            </button>
          )}
        </div>
        </>
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
            className={`inline-flex items-center justify-center rounded-md border font-semibold transition px-2.5 py-1 text-xs ${active ? 'border-indigo-600 bg-indigo-600 text-white' : 'app-panel app-text-soft hover:bg-[var(--app-panel-soft)]'}`}
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
    if (compact) {
      return <span className="text-sm app-text-muted">-</span>;
    }

    return <p className="text-sm app-text-muted">{emptyLabel}</p>;
  }

  if (locations.length === 1) {
    const singleLocation = locations[0];

    return (
      <div>
        {label && <p className="mb-2 text-xs font-bold uppercase tracking-wide app-text-muted">{label}</p>}
        <div className={`inline-flex items-center gap-2 text-sm app-text-soft ${compact ? '' : 'rounded-lg border px-3 py-2 app-border app-panel-soft'}`} title={singleLocation.address}>
          <span className="flex h-4 w-4 shrink-0 items-center justify-center rounded border border-slate-300 bg-white dark:border-slate-600 dark:bg-white/5" aria-hidden="true">
            <Check className="h-3 w-3 text-blue-600" strokeWidth={4} />
          </span>
          <span>{singleLocation.name}</span>
        </div>
      </div>
    );
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
        {label && <p className="mb-2 text-xs font-bold uppercase tracking-wide app-text-muted">{label}</p>}
        <div className="flex flex-wrap items-center gap-y-1 text-sm app-text-soft">
          {locations.map((location, index) => {
            const active = normalizedSelectedIds.includes(location.id);
            return (
              <span key={location.id} className="inline-flex items-center">
                {index > 0 && <span className="mx-2 app-text-muted" aria-hidden="true">{'\u2022'}</span>}
                <button
                  type="button"
                  onClick={() => toggle(location.id)}
                  className="inline-flex items-center gap-2 text-sm app-text-soft transition hover:app-text"
                  title={location.address}
                >
                  <span className="flex h-4 w-4 shrink-0 items-center justify-center rounded border border-slate-300 bg-white dark:border-slate-600 dark:bg-white/5" aria-hidden="true">
                    {active && <Check className="h-3 w-3 text-blue-600" strokeWidth={4} />}
                  </span>
                  <span>{location.name}</span>
                </button>
              </span>
            );
          })}
        </div>
      </div>
    );
  }

  const options = locations.map(l => ({ value: l.id, label: l.name }));
  return (
    <div>
      {label && <p className="mb-2 text-xs font-bold uppercase tracking-wide app-text-muted">{label}</p>}
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
        <span className="truncate font-medium">{triggerText}</span>
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
                className="flex w-full items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium transition hover:bg-[var(--app-panel-soft)]"
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
        <h2 className="text-lg font-bold app-text">{t('recentCalls')}</h2>
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
              <h2 className="text-lg font-bold app-text">{t('whatsappBot')}</h2>
              <span className="mt-1 inline-flex rounded-md bg-amber-400/20 px-2.5 py-1 text-[11px] font-bold text-amber-600 dark:text-amber-300">
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
        <h2 className="text-lg font-bold app-text">{t('whatsappConversations')}</h2>
        <div className="flex min-h-24 items-center justify-center text-sm app-text-muted">
          {t('noWhatsappConversationsFound')}
        </div>
      </Card>
    </div>
  );
}

function ChannelStat({ icon: Icon, value, label, tone, compact = false }: { icon: any; value: number; label: string; tone: 'blue' | 'green' | 'red' | 'purple' | 'slate'; compact?: boolean }) {
  const tones = {
    blue: 'bg-blue-100 text-blue-600 dark:bg-blue-400/15 dark:text-blue-300',
    green: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-400/15 dark:text-emerald-300',
    red: 'bg-red-100 text-red-600 dark:bg-red-400/15 dark:text-red-300',
    purple: 'bg-purple-100 text-purple-600 dark:bg-purple-400/15 dark:text-purple-300',
    slate: 'bg-slate-100 text-slate-700 dark:bg-white/90 dark:text-slate-700',
  };

  return (
    <Card className={compact ? 'p-3' : 'p-5'}>
      <div className={`flex items-center ${compact ? 'gap-3' : 'gap-4'}`}>
        <span className={`flex shrink-0 items-center justify-center rounded-full ${compact ? 'h-8 w-8' : 'h-10 w-10'} ${tones[tone]}`}>
          <Icon className={compact ? 'h-4 w-4' : 'h-5 w-5'} />
        </span>
        <div>
          <p className={`${compact ? 'text-xl' : 'text-2xl'} font-bold app-text`}>{value}</p>
          <p className={`${compact ? 'text-xs' : 'text-sm'} app-text-muted`}>{label}</p>
        </div>
      </div>
    </Card>
  );
}

function DashboardTable({ headers, children, minWidth = '920px' }: { headers: ReactNode[]; children: ReactNode; minWidth?: string }) {
  return (
    <div className="min-w-0 max-w-full overflow-hidden rounded-2xl border shadow-sm app-border app-panel">
      <div className="overflow-x-auto">
        <table className="w-full border-collapse text-left text-sm" style={{ minWidth }}>
          {headers.length > 0 && (
            <thead className="app-panel-soft">
              <tr className="border-b app-border">
                {headers.map((header, index) => (
                  <th key={index} className="whitespace-nowrap px-5 py-4 text-xs font-semibold uppercase tracking-wide app-text-muted">
                    {header}
                  </th>
                ))}
              </tr>
            </thead>
          )}
          <tbody>{children}</tbody>
        </table>
      </div>
    </div>
  );
}

function DashboardTableHeaderLabel({ children }: { children: ReactNode }) {
  return (
    <span>{children}</span>
  );
}

function dashboardTableRowClass(index: number) {
  return `border-b last:border-b-0 app-border transition hover:bg-indigo-500/5 ${index % 2 === 0 ? 'app-panel' : 'app-panel-soft'}`;
}

function RowActionsMenu({ label, children }: { label: string; children: (close: () => void) => ReactNode }) {
  const [open, setOpen] = useState(false);
  const [position, setPosition] = useState({ left: 0, top: 0 });
  const ref = useRef<HTMLDivElement>(null);
  const menuRef = useRef<HTMLDivElement>(null);
  const buttonRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    function onClickOutside(event: MouseEvent) {
      const target = event.target as Node;
      if (
        ref.current
        && !ref.current.contains(target)
        && menuRef.current
        && !menuRef.current.contains(target)
      ) {
        setOpen(false);
      }
    }

    document.addEventListener('mousedown', onClickOutside);
    return () => document.removeEventListener('mousedown', onClickOutside);
  }, []);

  useEffect(() => {
    if (!open) return;

    function closeMenu() {
      setOpen(false);
    }

    window.addEventListener('resize', closeMenu);
    window.addEventListener('scroll', closeMenu, true);

    return () => {
      window.removeEventListener('resize', closeMenu);
      window.removeEventListener('scroll', closeMenu, true);
    };
  }, [open]);

  function updatePosition() {
    const rect = buttonRef.current?.getBoundingClientRect();
    if (!rect) return;

    const menuWidth = 192;
    const menuHeight = 224;
    const gap = 8;
    const left = Math.min(
      Math.max(16, rect.right - menuWidth),
      window.innerWidth - menuWidth - 16,
    );
    const spaceBelow = window.innerHeight - rect.bottom;
    const top = spaceBelow < menuHeight && rect.top > menuHeight
      ? rect.top - menuHeight - gap
      : rect.bottom + gap;

    setPosition({
      left,
      top: Math.max(16, Math.min(top, window.innerHeight - menuHeight - 16)),
    });
  }

  function toggleOpen() {
    if (!open) {
      updatePosition();
    }

    setOpen((value) => !value);
  }

  return (
    <div ref={ref} className="relative flex justify-end">
      <button
        ref={buttonRef}
        type="button"
        aria-label={label}
        title={label}
        aria-expanded={open}
        aria-haspopup="menu"
        onClick={toggleOpen}
        className="inline-flex h-9 w-9 cursor-pointer items-center justify-center rounded-lg app-text-muted transition hover:bg-[var(--app-panel-soft)] hover:app-text"
      >
        <MoreHorizontal className="h-4 w-4" />
      </button>
      {open && (
        <div
          ref={menuRef}
          className="fixed z-50 w-48 rounded-lg border p-1 shadow-xl app-border app-panel"
          style={{ left: position.left, top: position.top }}
        >
          {children(() => setOpen(false))}
        </div>
      )}
    </div>
  );
}

function RowActionButton({ children, onClick, tone = 'default' }: { children: ReactNode; onClick: () => void; tone?: 'default' | 'danger' }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`flex w-full cursor-pointer items-center gap-2 rounded-md px-3 py-2 text-left text-sm font-semibold transition hover:bg-[var(--app-panel-soft)] ${tone === 'danger' ? 'text-red-600' : 'app-text-soft'}`}
    >
      {children}
    </button>
  );
}

function RowActionLink({ children, href }: { children: ReactNode; href: string }) {
  return (
    <a href={href} className="flex w-full cursor-pointer items-center gap-2 rounded-md px-3 py-2 text-left text-sm font-semibold transition app-text-soft hover:bg-[var(--app-panel-soft)]">
      {children}
    </a>
  );
}

function Bookings({ salon, query }: { salon: Salon; query: string }) {
  const t = useT();
  const [view, setView] = useState<'archive' | 'list' | 'calendar'>('list');
  const [editingBookingId, setEditingBookingId] = useState<number | null>(null);
  const [isAddingBooking, setIsAddingBooking] = useState(false);
  const [bookingCategory, setBookingCategory] = useState('');
  const [selectedBookingDates, setSelectedBookingDates] = useState<string[]>([]);
  const [archiveDateRange, setArchiveDateRange] = useState<DateRange>({ start: '', end: '' });
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
    return {
      today: salon.bookings.filter((booking) => booking.date === todayKey).length,
      upcoming: salon.bookings.filter((booking) => booking.date >= todayKey && (booking.status === 'pending' || booking.status === 'confirmed')).length,
      pending: salon.bookings.filter((booking) => booking.status === 'pending').length,
      cancelled: salon.bookings.filter((booking) => booking.status === 'cancelled' && booking.date >= todayKey).length,
    };
  }, [salon.bookings, todayKey]);
  const visibleBookingsBase = useMemo(() => (
    view === 'archive'
      ? filteredBookings.filter((booking) => booking.date < todayKey)
      : filteredBookings.filter((booking) => booking.date >= todayKey)
  ), [filteredBookings, todayKey, view]);
  const bookingDateOptions = useMemo(
    () => Array.from(new Set(visibleBookingsBase.map((booking) => booking.date))).sort(),
    [visibleBookingsBase],
  );
  useEffect(() => {
    setSelectedBookingDates((current) => current.filter((date) => bookingDateOptions.includes(date)));
  }, [bookingDateOptions]);
  const visibleBookings = useMemo(() => {
    if (view === 'archive' && (archiveDateRange.start || archiveDateRange.end)) {
      return visibleBookingsBase.filter((booking) => (
        (!archiveDateRange.start || booking.date >= archiveDateRange.start)
        && (!archiveDateRange.end || booking.date <= archiveDateRange.end)
      ));
    }

    return selectedBookingDates.length === 0
      ? visibleBookingsBase
      : visibleBookingsBase.filter((booking) => selectedBookingDates.includes(booking.date));
  }, [archiveDateRange, selectedBookingDates, visibleBookingsBase, view]);
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
                  <option value="">{t('noLocation')}</option>
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
          className="inline-flex h-10 items-center gap-2 rounded-lg bg-blue-600 px-4 text-sm font-bold text-white transition hover:bg-blue-700"
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
        <BookingStat icon={Calendar} value={stats.pending} label={t('pendingRequests')} tone="purple" />
        <BookingStat icon={Calendar} value={stats.cancelled} label={t('cancelled')} tone="red" />
      </div>

      {(view === 'list' || view === 'archive') && (
        <BookingsDayCards
          groups={groupedBookings}
          salon={salon}
          dateOptions={bookingDateOptions}
          selectedDates={selectedBookingDates}
          onDateChange={setSelectedBookingDates}
          dateHeader={view === 'archive' ? (
            <ArchiveDateRangeHeader
              label={t('date')}
              availableDates={bookingDateOptions}
              range={archiveDateRange}
              onChange={setArchiveDateRange}
              t={t}
            />
          ) : undefined}
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
          <p className="text-2xl font-bold app-text">{value}</p>
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

function formatBusinessDate(date: string, salon: Pick<Salon, 'date_format'>) {
  const [year, month, day] = date.slice(0, 10).split('-');
  const format = normalizeDateFormatForUi(salon.date_format) ?? 'dd.mm.yyyy';

  if (format === 'yyyy-mm-dd') return `${year}-${month}-${day}`;
  if (format === 'dd/mm/yyyy') return `${day}/${month}/${year}`;

  return `${day}.${month}.${year}`;
}

function formatBookingGroupDate(date: string, t: TranslateFn, salon: Salon) {
  const locale = t('date') === 'Date' ? 'en-GB' : 'ro-RO';
  const weekday = new Intl.DateTimeFormat(locale, {
    weekday: 'long',
  }).format(new Date(`${date}T00:00:00`));

  const label = `${weekday}, ${formatBusinessDate(date, salon)}`;

  return label.charAt(0).toUpperCase() + label.slice(1);
}

function formatFilterDate(date: string, t: TranslateFn) {
  const locale = t('date') === 'Date' ? 'en-GB' : 'ro-RO';
  const formatted = new Intl.DateTimeFormat(locale, {
    weekday: 'long',
    day: '2-digit',
    month: 'long',
  }).format(new Date(`${date}T00:00:00`));

  return formatted.charAt(0).toUpperCase() + formatted.slice(1);
}

function monthFromDateKey(date: string) {
  const [year, month] = date.split('-').map(Number);
  return new Date(year || new Date().getFullYear(), (month || 1) - 1, 1);
}

function formatCompactDate(date: string, locale: string) {
  return new Intl.DateTimeFormat(locale, {
    day: '2-digit',
    month: 'short',
  }).format(new Date(`${date}T00:00:00`));
}

function formatServicePrice(price: string | number | null | undefined, salon: Pick<Salon, 'country' | 'currency'>, serviceCurrency?: string | null) {
  if (price === null || price === undefined || price === '') return '';

  const priceText = String(price).trim();
  if (/\b(RON|GBP|EUR|USD)\b|£|€|\$/i.test(priceText)) {
    return priceText;
  }

  const normalizedCountry = (salon.country || '').toUpperCase() === 'UK' ? 'GB' : (salon.country || '').toUpperCase();
  const currency = (serviceCurrency || salon.currency || (normalizedCountry === 'GB' ? 'GBP' : 'RON')).toUpperCase();

  if (currency === 'GBP') return `£${priceText}`;
  if (currency === 'USD') return `$${priceText}`;

  return `${priceText} ${currency}`;
}

function bookingDetailsLine(booking: Salon['bookings'][number], salon: Salon, t: TranslateFn) {
  return [
    booking.service?.name || (booking.service_id ? `${t('service')} #${booking.service_id}` : null),
    booking.service?.type,
    booking.service?.price ? formatServicePrice(booking.service.price, salon, booking.service.currency) : null,
    booking.location?.name,
  ].filter(Boolean).join(' • ');
}

function BookingDetailsCell({ booking, salon, t }: { booking: Salon['bookings'][number]; salon: Salon; t: TranslateFn }) {
  const detail = bookingDetailsLine(booking, salon, t);
  const staffLabel = bookingStaffLabel(booking);

  if (!detail && !staffLabel) {
    return <span>-</span>;
  }

  return (
    <div className="space-y-1">
      {detail && <p>{detail}</p>}
      {staffLabel && (
        <p className="text-xs font-semibold app-text-muted">
          {t('assignedStaff')}: {staffLabel}
        </p>
      )}
    </div>
  );
}

function BookingsDayCards({
  groups,
  salon,
  dateOptions,
  selectedDates,
  onDateChange,
  dateHeader,
  t,
  onEdit,
  onConfirm,
  onCancel,
  onDelete,
}: {
  groups: ReturnType<typeof groupBookingsByDay>;
  salon: Salon;
  dateOptions: string[];
  selectedDates: string[];
  onDateChange: (next: string[]) => void;
  dateHeader?: ReactNode;
  t: TranslateFn;
  onEdit: (booking: Salon['bookings'][number]) => void;
  onConfirm: (booking: Salon['bookings'][number]) => void;
  onCancel: (booking: Salon['bookings'][number]) => void;
  onDelete: (booking: Salon['bookings'][number]) => void;
}) {
  const tableHeaders = [
    dateHeader ?? <DateFilterHeader label={t('date')} dates={dateOptions} selected={selectedDates} onChange={onDateChange} t={t} />,
    <DashboardTableHeaderLabel>{t('time')}</DashboardTableHeaderLabel>,
    <DashboardTableHeaderLabel>{t('status')}</DashboardTableHeaderLabel>,
    <DashboardTableHeaderLabel>{t('name')}</DashboardTableHeaderLabel>,
    <DashboardTableHeaderLabel>{t('phone')}</DashboardTableHeaderLabel>,
    <DashboardTableHeaderLabel>{t('details')}</DashboardTableHeaderLabel>,
    <span className="sr-only">{t('actions')}</span>,
  ];

  if (groups.length === 0) {
    return (
      <DashboardTable headers={tableHeaders} minWidth="1040px">
        <tr>
          <td colSpan={7} className="px-5 py-8 text-center text-sm app-text-muted">
            {t('noBookingsFound')}
          </td>
        </tr>
      </DashboardTable>
    );
  }

  let rowIndex = 0;

  return (
    <DashboardTable
      headers={tableHeaders}
      minWidth="1040px"
    >
      {groups.flatMap((group) => group.bookings.map((booking, bookingIndex) => {
        const currentIndex = rowIndex++;
        return (
          <tr key={booking.id} className={dashboardTableRowClass(currentIndex)}>
            <td className="w-48 px-5 py-4 align-top text-sm font-semibold app-text">
              {bookingIndex === 0 ? formatBookingGroupDate(group.date, t, salon) : ''}
            </td>
            <td className="whitespace-nowrap px-5 py-4 align-top text-sm font-semibold app-text">
              {bookingTimeRange(booking.time, booking.service?.duration)}
            </td>
            <td className="px-5 py-4 align-top">
              <StatusPill status={booking.status} t={t} />
            </td>
            <td className="px-5 py-4 align-top font-semibold app-text">{booking.client_name}</td>
            <td className="whitespace-nowrap px-5 py-4 align-top app-text-soft">{booking.client_phone || t('phoneMissingShort')}</td>
            <td className="min-w-72 px-5 py-4 align-top app-text-soft">
              <BookingDetailsCell booking={booking} salon={salon} t={t} />
            </td>
            <td className="w-14 px-5 py-4 align-top">
              <RowActionsMenu label={t('actions')}>
                {(close) => (
                  <>
                    {(booking.status === 'pending' || booking.status === 'cancelled') && (
                      <RowActionButton onClick={() => { close(); onConfirm(booking); }}>
                        <CheckCircle2 className="h-4 w-4 text-green-600" />
                        {t('confirmBooking')}
                      </RowActionButton>
                    )}
                    {booking.client_phone && (
                      <RowActionLink href={`tel:${booking.client_phone}`}>
                        <Phone className="h-4 w-4 text-green-600" />
                        {booking.client_phone}
                      </RowActionLink>
                    )}
                    {booking.status !== 'cancelled' && (
                      <RowActionButton onClick={() => { close(); onCancel(booking); }}>
                        <XCircle className="h-4 w-4 text-red-600" />
                        {t('cancelBooking')}
                      </RowActionButton>
                    )}
                    <RowActionButton onClick={() => { close(); onEdit(booking); }}>
                      <Pencil className="h-4 w-4" />
                      {t('editBooking')}
                    </RowActionButton>
                    <RowActionButton tone="danger" onClick={() => { close(); onDelete(booking); }}>
                      <Trash2 className="h-4 w-4" />
                      {t('deleteBooking')}
                    </RowActionButton>
                  </>
                )}
              </RowActionsMenu>
            </td>
          </tr>
        );
      }))}
    </DashboardTable>
  );
}

function ServiceNotesPill({ notes }: { notes: string }) {
  const [open, setOpen] = useState(false);
  const [position, setPosition] = useState({ left: 0, top: 0 });
  const buttonRef = useRef<HTMLButtonElement>(null);

  function toggleOpen() {
    const rect = buttonRef.current?.getBoundingClientRect();
    if (rect) {
      const tooltipWidth = 256;
      const left = Math.min(rect.left, window.innerWidth - tooltipWidth - 16);
      setPosition({
        left: Math.max(16, left),
        top: rect.bottom + 8,
      });
    }

    setOpen((value) => !value);
  }

  return (
    <span className="inline-flex">
      <button
        ref={buttonRef}
        type="button"
        onClick={toggleOpen}
        className={`${TABLE_PILL_CLASS} gap-1 bg-slate-200 text-slate-600 transition hover:bg-slate-300 dark:bg-white/10 dark:text-slate-300 dark:hover:bg-white/20`}
      >
        <FileText className="h-2.5 w-2.5" />
        Note
      </button>
      {open && (
        <>
          <span className="fixed inset-0 z-40" onClick={() => setOpen(false)} />
          <span
            className="fixed z-50 w-64 rounded-lg border p-3 text-sm shadow-xl app-border app-panel app-text"
            style={{ left: position.left, top: position.top }}
          >
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

function isPastBookingTime(booking: { date: string; time: string; service?: { duration?: number } | null }): boolean {
  return bookingEndDate(booking).getTime() < Date.now();
}

function bookingTimeRange(time: string, durationMinutes?: number | null) {
  if (!durationMinutes) return time;
  const [h, m] = time.split(':').map(Number);
  const totalEnd = h * 60 + m + durationMinutes;
  const endH = Math.floor(totalEnd / 60) % 24;
  const endM = totalEnd % 60;
  return `${time} - ${String(endH).padStart(2, '0')}:${String(endM).padStart(2, '0')}`;
}

function lastBookingEndTime(bookings: Salon['bookings']) {
  const last = bookings.at(-1);

  if (!last) return '';

  if (!last.service?.duration) {
    return last.time;
  }

  return bookingTimeRange(last.time, last.service.duration).split(' - ')[1] ?? last.time;
}

function formatBookingDay(date: string, salon: Salon) {
  return formatBusinessDate(date, salon);
}

function BookingsCalendar({ bookings, t }: { bookings: Salon['bookings']; t: TranslateFn }) {
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
        <h3 className="text-base font-bold capitalize app-text">{monthLabel}</h3>
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
      <div className="grid grid-cols-7 border-l border-t app-border text-xs font-bold uppercase app-text-muted">
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
              {day && <p className={`mb-2 text-xs font-bold ${isPast ? 'text-slate-400 dark:text-slate-500' : 'app-text'}`}>{day}</p>}
              <div className="space-y-1">
                {dayBookings.slice(0, 3).map((booking) => (
                  <div key={booking.id} className={`truncate rounded-md px-2 py-1 text-xs font-medium ${isPast ? 'bg-slate-200 text-slate-500 dark:bg-white/10 dark:text-slate-400' : 'bg-indigo-500/10 text-indigo-700 dark:text-indigo-200'}`}>
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

function scrollToFirstFormError(form: HTMLFormElement | null, errorKeys: string[]) {
  if (!form || errorKeys.length === 0) return;

  window.requestAnimationFrame(() => {
    const rootKeys = errorKeys.map((key) => key.split('.')[0]);
    const target = rootKeys
      .map((key) => form.querySelector<HTMLElement>(`[data-error-key="${key}"]`))
      .find(Boolean);

    if (!target) return;

    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    target.querySelector<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>('input, textarea, select')
      ?.focus({ preventScroll: true });
  });
}

function EditModal({ open, title, onClose, children }: { open: boolean; title: string; onClose: () => void; children: React.ReactNode }) {
  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm">
      <div className="max-h-[calc(100vh-2rem)] w-full max-w-5xl overflow-y-auto rounded-lg border p-5 shadow-xl app-panel">
        <div className="mb-5 flex items-center justify-between gap-4">
          <h2 className="text-lg font-bold app-text">{title}</h2>
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
    <div className="flex min-w-0 flex-wrap items-center justify-between gap-4">
      {!hideText && (
        <div className="min-w-0">
          <h2 className="text-2xl font-bold tracking-tight app-text">{title}</h2>
          <p className="text-sm app-text-muted">{subtitle}</p>
        </div>
      )}
      {action && <div className="min-w-0 max-w-full">{action}</div>}
    </div>
  );
}

function Table({ headers, children }: { headers: (string | React.ReactNode)[]; children: React.ReactNode }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[720px] text-left">
        <thead className="text-xs font-bold uppercase tracking-wide app-panel-soft app-text-muted">
          <tr>{headers.map((header, i) => <th key={i} className="px-5 py-3">{header}</th>)}</tr>
        </thead>
        <tbody>{children}</tbody>
      </table>
    </div>
  );
}
