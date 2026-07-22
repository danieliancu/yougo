import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { AlertModal, Badge, Button, Card, ConfirmationModal, DangerButton, Field, Input, SecondaryButton, ThemeToggle } from '@/Components/Ui';
import type { ActivityChartRow } from '@/Components/ActivityChart';
import { PricingPlansGrid, VoicePlanKey } from '@/Components/PricingPlansGrid';
import { YouGoCopilot } from '@/Components/YouGoCopilot';
import { Booking, Conversation, Location as SalonLocation, OfferedService, OnboardingChecklist, OnboardingStep, OverviewData, PageProps, Plan, Salon, Service, Staff, UsageSummary, User as AuthUser, WhatsappIntegration } from '@/types';
import { AlertTriangle, Bell, Bot, Building2, Calendar, Check, CheckCircle2, ChevronDown, ChevronLeft, ChevronRight, Clock, CreditCard, Download, ExternalLink, FileText, Globe2, LayoutDashboard, List, Lock, LogOut, MapPin, Menu, MessageCircle, MessageSquare, MoreHorizontal, Pencil, Phone, Plus, Save, Scissors, Search, Settings, Smartphone, Sparkles, Trash2, User, Users, X, XCircle } from 'lucide-react';
import { SiBigcommerce, SiShopify, SiWhatsapp, SiWordpress } from 'react-icons/si';
import { FormEvent, lazy, ReactNode, Suspense, useEffect, useMemo, useRef, useState } from 'react';
import { useT } from '@/i18n';
import { businessTaxonomy, findBusinessType, normalizeBusinessTypeSlug } from '@/data/businessTaxonomy';
import { planHasService, serviceEntitlementLabel, serviceIsLive, serviceStatusLabel, serviceByKey } from '@/lib/yougoServices';
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
  section: 'overview' | 'onboarding' | 'ai-settings' | 'conversations' | 'voice-calls' | 'whatsapp' | 'locations' | 'staff' | 'services' | 'bookings' | 'customers' | 'customer-detail' | 'widget' | 'billing' | 'settings';
  salon: Salon;
  overview: OverviewData;
  onboarding: OnboardingChecklist;
  billing: {
    summary: UsageSummary;
    plans: Plan[];
    services: OfferedService[];
    whatsapp_integration?: WhatsappIntegration | null;
    stripe: {
      subscription_status: string | null;
      stripe_customer_exists: boolean;
      stripe_subscription_exists: boolean;
      subscription_current_period_end: string | null;
      paid_plan_keys: string[];
      configured_prices: Record<string, boolean>;
      payment_warning: boolean;
    };
  };
  localization: LocalizationProps;
  crm?: CustomerCrmPayload | CustomerDetailPayload | null;
  appUrl: string;
}>;

type TranslateFn = (key: string, params?: Record<string, string | number>) => string;
type DateRange = { start: string; end: string };
type ConversationChannelFilter = 'all' | 'voice' | 'chat' | 'whatsapp';
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
type BulkServiceAction = 'duration' | 'type' | 'max_concurrent_bookings' | 'price' | 'location_ids' | 'delete';

type WhatsappSetupRequestForm = {
  business_name: string;
  contact_person: string;
  contact_email: string;
  contact_phone: string;
  requested_whatsapp_number: string;
  whatsapp_display_name: string;
  website_or_social_link: string;
  has_meta_business_account: 'yes' | 'no' | 'not_sure' | '';
  number_currently_used_on_whatsapp_app: 'yes' | 'no' | 'not_sure' | '';
  can_receive_sms_or_call: 'yes' | 'no' | '';
  preferred_meeting_type: 'video_call' | 'phone_call' | '';
  preferred_availability: string;
  notes: string;
};
type WhatsappAvailabilityPeriod = 'morning' | 'afternoon' | 'evening';
type CustomerListItem = {
  id: number;
  name?: string | null;
  phone?: string | null;
  email?: string | null;
  first_seen_at?: string | null;
  last_seen_at?: string | null;
  bookings_count: number;
  upcoming_bookings_count: number;
  cancelled_bookings_count: number;
  completed_bookings_count: number;
  conversations_count: number;
  last_booking?: {
    id: number;
    date?: string | null;
    time?: string | null;
    status?: string | null;
    service_name?: string | null;
  } | null;
};
type CustomerCrmPayload = {
  items: CustomerListItem[];
  pagination: {
    current_page: number;
    last_page: number;
    total: number;
    next_page_url?: string | null;
    prev_page_url?: string | null;
  };
  filters: { search: string };
  summary: {
    total_customers: number;
    with_phone: number;
    with_email: number;
    new_this_month: number;
  };
};
type CustomerDetailPayload = {
  customer: {
    id: number;
    name?: string | null;
    phone?: string | null;
    email?: string | null;
    first_seen_at?: string | null;
    last_seen_at?: string | null;
    updated_at?: string | null;
    notes?: string | null;
  };
  stats: {
    total_bookings: number;
    upcoming_bookings: number;
    cancelled_bookings: number;
    completed_bookings: number;
    conversations: number;
    last_interaction?: string | null;
  };
  preferences: {
    service?: string | null;
    staff?: string | null;
  };
  highlights: {
    next_upcoming_booking?: CustomerBookingSummary | null;
    last_booking?: CustomerBookingSummary | null;
  };
  bookings: Array<CustomerBookingSummary & Pick<Booking, 'client_name' | 'client_phone'>>;
  conversations: Array<Pick<Conversation, 'id' | 'booking_id' | 'channel' | 'contact_name' | 'contact_phone' | 'contact_email' | 'status' | 'intent' | 'summary' | 'last_message_at'>>;
};
type CustomerBookingSummary = Pick<Booking, 'id' | 'date' | 'time' | 'status' | 'source' | 'service' | 'location' | 'staff_member'> & {
  staff_name?: string | null;
};
const CONVERSATION_CHANNEL_FILTER_STORAGE_KEY = 'yougo.dashboard.conversations.channelFilter';

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
  id: 'activity' | 'assistantSettings' | 'administration';
  label: string;
  items: NavItem[];
};
type UsageRingTone = 'indigo' | 'emerald' | 'sky' | 'slate';
type UsageSummaryItem = {
  key: string;
  label: string;
  used: number;
  limit: number | null;
  icon: any;
  tone: UsageRingTone;
};

const topLevelNavItems: NavItem[] = [];

const navGroups: NavGroup[] = [
  {
    id: 'activity',
    label: 'navGroupActivity',
    items: [
      { id: 'overview', label: 'overview', href: '/dashboard', icon: LayoutDashboard },
      { id: 'onboarding', label: 'setup', href: '/dashboard/onboarding', icon: List },
      { id: 'bookings', label: 'bookings', href: '/dashboard/bookings', icon: Calendar },
      { id: 'conversations', label: 'conversations', href: '/dashboard/conversations', icon: MessageSquare },
    ],
  },
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
      { id: 'customers', label: 'customers', href: '/dashboard/customers', icon: Users },
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
  const { auth, salon, section, locale, overview, onboarding, billing, localization, crm } = usePage<Props>().props;
  const titleKey = section === 'locations'
    ? 'salonLocations'
    : section === 'customer-detail'
      ? 'customerDetail'
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
    customers: t('customersSubtitle'),
    'customer-detail': t('customerDetailSubtitle'),
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
    customers: t('searchCustomers'),
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
            <div className="hidden items-center gap-3 sm:flex">
              <ThemeToggle />
              <LanguageToggle locale={activeLocale} onChange={switchLanguage} />
            </div>
          </div>
        </header>
        <div className={`min-h-0 min-w-0 flex-1 overflow-x-hidden ${section === 'conversations' ? 'overflow-hidden' : section === 'bookings' || section === 'overview' ? 'overflow-y-auto p-4 sm:p-5 lg:p-8' : 'overflow-y-auto p-5 lg:p-8'}`}>
          {section === 'overview' && <Overview salon={salon} overview={overview} onboarding={onboarding} />}
          {section === 'onboarding' && <OnboardingSetup onboarding={onboarding} />}
          {section === 'ai-settings' && <AiSettings salon={salon} />}
          {section === 'conversations' && <Conversations salon={salon} query={query} overview={overview} />}
          {section === 'voice-calls' && <VoiceCalls query={query} />}
          {section === 'whatsapp' && <WhatsAppSettings salon={salon} plan={billing.summary.plan} query={query} />}
          {section === 'locations' && <Locations salon={salon} />}
          {section === 'staff' && <StaffManagement salon={salon} query={query} />}
          {section === 'services' && <Services salon={salon} query={query} onResetSearch={() => setQuery('')} />}
          {section === 'bookings' && <Bookings salon={salon} query={query} />}
          {section === 'customers' && <Customers crm={crm as CustomerCrmPayload | null | undefined} query={query} />}
          {section === 'customer-detail' && <CustomerDetail crm={crm as CustomerDetailPayload | null | undefined} salon={salon} />}
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
      <div className="flex h-20 shrink-0 items-center border-b border-white/10 px-6">
        <Brand salon={salon} />
      </div>
      <DashboardSidebarContent salon={salon} section={section} user={user} t={t} onboarding={onboarding} />
    </aside>
  );
}

function Brand({ salon, onClick }: { salon: Salon; onClick?: () => void }) {
  const planName = planDisplayName(salon.plan);

  return (
    <Link href="/" className="flex items-center gap-2.5 rounded-lg text-left transition hover:bg-white/5" onClick={onClick}>
      <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-white/10 text-white">
        <ChevronLeft className="h-4 w-4" />
      </span>
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
    </Link>
  );
}

function planDisplayName(plan?: string | null): string {
  if (! plan) return 'Free';

  const labels: Record<string, string> = {
    connect: 'Chat + WhatsApp',
    voice: 'YouGo Starter',
    enterprise: 'YouGo Pro',
    website_chat: 'Website Chat',
    chat_whatsapp: 'Chat + WhatsApp',
    voice_starter: 'YouGo Starter',
    voice_growth: 'YouGo Growth',
    voice_pro: 'YouGo Pro',
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
  const remainingSetupCount = onboarding.steps.filter((step) => !step.completed && !step.coming_soon && !step.optional).length;

  // Navigating to a page (e.g. via a link elsewhere in the app, not the sidebar itself)
  // whose group is currently collapsed left it hidden with no indication of where you
  // were — the initializer above only auto-opens the active group on the very first
  // render. Re-run on every section change so the active group is always visible.
  useEffect(() => {
    const group = navGroupForSection(section);

    if (!group) {
      return;
    }

    setOpenGroups((groups) => {
      if (groups[group.id]) {
        return groups;
      }

      const next = { ...groups, [group.id]: true };
      storeSidebarOpenGroups(next);

      return next;
    });
  }, [section]);

  return (
    <>
      <nav className="min-h-0 flex-1 space-y-2 overflow-y-auto p-4">
        <div className="space-y-1 pb-1">
          {topLevelNavItems.map((item) => {
            const Icon = item.icon;
            const active = item.id === section || (section === 'customer-detail' && item.id === 'customers');

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
                      const active = item.id === section || (section === 'customer-detail' && item.id === 'customers');
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
                      <>
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
                      </>
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
  const previewHref = assistantPreviewHref(salon.id);
  const [domainsText, setDomainsText] = useState((salon.widget_allowed_domains ?? []).join('\n'));
  const [copied, setCopied] = useState(false);
  const conversations = filterWebsiteChatConversations(salon.conversations, query);
  const stats = websiteChatStats(conversations);
  const form = useForm({
    widget_enabled: Boolean(salon.widget_enabled ?? true),
    widget_allowed_domains: salon.widget_allowed_domains ?? [],
    widget_primary_color: salon.widget_primary_color ?? '',
    widget_cta_text: salon.widget_cta_text ?? '',
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

  function downloadWordPressPlugin() {
    downloadTextFile('yougo-widget.php', `<?php
/**
 * Plugin Name: YouGo Website Chat
 * Description: Adds the YouGo AI assistant widget to your WordPress site.
 * Version: 1.0.0
 * Author: YouGo
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('wp_footer', function () {
    ?>
    ${embedCode}
    <?php
}, 99);
`);
  }

  function downloadShopifySnippet() {
    downloadTextFile('yougo-widget.liquid', `{% comment %}
  YouGo Website Chat
  Add this snippet before the closing </body> tag in theme.liquid.
{% endcomment %}
${embedCode}
`);
  }

  function downloadBigCommerceSnippet() {
    downloadTextFile('yougo-widget-bigcommerce-script-manager.html', `<!--
  YouGo Website Chat
  BigCommerce install method: add this as a custom script in Storefront > Script Manager.
  Location: Footer. Pages: Storefront pages where the assistant should appear.
-->
${embedCode}
`);
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

      <div className="grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3">
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
          <div className="flex shrink-0 flex-wrap gap-2">
            <a href={previewHref} target="_blank" rel="noreferrer" className="inline-flex h-11 items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 text-sm font-bold text-white transition hover:bg-indigo-700">
              <ExternalLink className="h-4 w-4" />
              {t('openPreview')}
            </a>
            {salon.widget_setup_completed ? (
              <span className="inline-flex h-11 items-center justify-center gap-1.5 rounded-lg bg-emerald-50 px-4 text-sm font-bold text-emerald-700">
                <Check className="h-4 w-4" /> {t('complete')}
              </span>
            ) : (
              <button
                type="button"
                onClick={() => router.post('/widget-settings/mark-complete', {}, { preserveScroll: true })}
                className="inline-flex h-11 items-center justify-center rounded-lg border px-4 text-sm font-bold app-text transition hover:bg-[var(--app-panel-soft)]"
              >
                {t('markStepComplete')}
              </button>
            )}
          </div>
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

          <div className="mt-6 rounded-lg border p-4 app-panel-soft app-border">
            <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
              <div>
                <p className="text-sm font-bold app-text">{t('widgetPluginDownloads')}</p>
                <p className="mt-1 text-xs font-medium app-text-muted">{t('widgetPluginDownloadsHelp')}</p>
              </div>
              <div className="flex flex-wrap gap-3">
                <SecondaryButton type="button" onClick={downloadWordPressPlugin}>
                  <SiWordpress className="h-4 w-4" />
                  WordPress
                </SecondaryButton>
                <SecondaryButton type="button" onClick={downloadShopifySnippet}>
                  <SiShopify className="h-4 w-4" />
                  Shopify
                </SecondaryButton>
                <SecondaryButton type="button" onClick={downloadBigCommerceSnippet}>
                  <SiBigcommerce className="h-4 w-4" />
                  BigCommerce
                </SecondaryButton>
              </div>
            </div>
          </div>

          <div className="mt-6 grid gap-4 xl:grid-cols-[1fr_1fr_1fr]">
            <label className="flex items-center justify-between gap-4 rounded-lg border p-4 app-panel app-border">
              <span>
                <span className="block text-sm font-bold app-text">{t('widgetEnabled')}</span>
                <span className="block text-xs font-medium app-text-muted">{t('widgetEnabledHelp')}</span>
              </span>
              <input type="checkbox" checked={form.data.widget_enabled} onChange={(event) => form.setData('widget_enabled', event.target.checked)} className="h-5 w-5 rounded border-slate-300 text-indigo-600" />
            </label>

            <div className="grid gap-4 md:grid-cols-3 xl:col-span-2">
              <Field label={t('widgetPrimaryColor')} error={form.errors.widget_primary_color}>
                <div className="flex gap-3">
                  <input type="color" value={form.data.widget_primary_color || '#2563eb'} onChange={(event) => form.setData('widget_primary_color', event.target.value)} className="h-11 w-16 rounded-lg border app-panel app-border" />
                  <Input value={form.data.widget_primary_color} onChange={(event) => form.setData('widget_primary_color', event.target.value)} placeholder="#2563eb" />
                </div>
              </Field>

              <Field label={t('widgetCtaText')} error={form.errors.widget_cta_text}>
                <Input value={form.data.widget_cta_text} onChange={(event) => form.setData('widget_cta_text', event.target.value.slice(0, 40))} maxLength={40} placeholder={t('widgetCtaTextPlaceholder')} />
                <p className="mt-2 text-xs font-medium app-text-muted">{t('widgetCtaTextHelp')}</p>
              </Field>

              <Field label={t('widgetPosition')} error={form.errors.widget_position}>
                <select value={form.data.widget_position} onChange={(event) => form.setData('widget_position', event.target.value)} className="h-11 w-full rounded-lg border px-3 text-sm font-medium app-panel app-text">
                  <option value="bottom-right">bottom-right</option>
                  <option value="bottom-left">bottom-left</option>
                </select>
              </Field>
            </div>

            <div className="rounded-lg border p-4 app-panel app-border">
              <p className="mb-3 text-sm font-bold app-text">{t('widgetIconPreview')}</p>
              <div className="flex min-h-28 items-end justify-end rounded-lg border p-4 app-panel-soft app-border">
                <WidgetLauncherPreview
                  color={form.data.widget_primary_color || '#2563eb'}
                  ctaText={form.data.widget_cta_text}
                  position={form.data.widget_position}
                />
              </div>
            </div>

          </div>

          <div className="mt-6 flex flex-wrap gap-3">
            <Button type="submit" disabled={form.processing}>
              <Save className="h-4 w-4" />
              {t('saveChanges')}
            </Button>
            <a href={previewHref} target="_blank" rel="noreferrer" className="inline-flex h-11 items-center justify-center gap-2 rounded-lg border px-4 text-sm font-bold transition app-panel app-text-soft hover:bg-[var(--app-panel-soft)]">
              <ExternalLink className="h-4 w-4" />
              {t('openPreview')}
            </a>
          </div>
        </Card>
      </form>
    </div>
  );
}

function WidgetLauncherPreview({ color, ctaText, position }: { color: string; ctaText?: string | null; position?: string | null }) {
  const primaryColor = isHexColor(color) ? color : '#2563eb';
  const label = ctaText?.trim();
  const reverse = position === 'bottom-left';

  return (
    <div className={`flex items-center gap-2 ${reverse ? 'flex-row-reverse' : 'flex-row'}`}>
      {label && (
        <span className="max-w-52 truncate rounded-full border border-slate-200 bg-white px-3.5 py-2.5 text-xs font-bold leading-tight text-slate-950 shadow-lg shadow-slate-900/10">
          {label}
        </span>
      )}
      <button
        type="button"
        className="relative flex h-14 w-14 items-center justify-center rounded-2xl text-white shadow-xl transition"
        style={{ backgroundColor: primaryColor, boxShadow: '0 20px 40px rgba(79, 70, 229, 0.30)' }}
        aria-label="Widget preview"
      >
        <MessageCircle className="h-6 w-6" aria-hidden />
        <span className="absolute right-2 top-2 h-2.5 w-2.5 rounded-full border-2 bg-emerald-400" style={{ borderColor: primaryColor }} aria-hidden />
      </button>
    </div>
  );
}

function downloadTextFile(filename: string, content: string) {
  const blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
  const url = window.URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  window.URL.revokeObjectURL(url);
}

function isHexColor(value: string) {
  return /^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/.test(value.trim());
}

function assistantPreviewHref(salonId: number) {
  const backPath = typeof window === 'undefined'
    ? '/dashboard/widget'
    : `${window.location.pathname}${window.location.search}`;

  return `/assistant/${salonId}?back=${encodeURIComponent(backPath)}`;
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

function filterWhatsappConversations(conversations: Conversation[], query: string) {
  const normalizedQuery = query.trim().toLowerCase();

  return conversations
    .filter((conversation) => isWhatsappConversation(conversation))
    .filter((conversation) => {
      if (!normalizedQuery) return true;

      return [
        conversation.contact_name,
        conversation.contact_phone,
        conversation.external_contact_id,
        conversation.external_sender,
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
  if (['dd month yyyy', 'd month yyyy', 'dd mmmm yyyy', 'd mmmm yyyy'].includes(normalized)) return 'dd month yyyy';

  return normalized;
}

function dateFormatExample(dateFormat: string, locale: 'ro' | 'en' = preferredLocale()) {
  if (dateFormat === 'yyyy-mm-dd') return '2026-05-27';
  if (dateFormat === 'dd/mm/yyyy') return '27/05/2026';
  if (dateFormat === 'dd month yyyy') return locale === 'en' ? '27 May 2026' : '27 Mai 2026';

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
    old_password: '',
    new_password: '',
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
    form.post('/settings', {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => form.setData((data) => ({
        ...data,
        old_password: '',
        new_password: '',
      })),
    });
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
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <DarkField label={t('fullName')} error={form.errors.name}>
              <DarkInput value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} />
            </DarkField>
            <DarkField label="Email">
              <DarkInput value={auth.user?.email ?? ''} disabled />
            </DarkField>
            <DarkField label={t('oldPassword')} error={form.errors.old_password}>
              <DarkInput type="password" value={form.data.old_password} onChange={(event) => form.setData('old_password', event.target.value)} autoComplete="current-password" />
            </DarkField>
            <DarkField label={t('newPassword')} error={form.errors.new_password}>
              <DarkInput type="password" value={form.data.new_password} onChange={(event) => form.setData('new_password', event.target.value)} autoComplete="new-password" />
            </DarkField>
          </div>
        </SettingsPanel>

        <SettingsPanel icon={Globe2} title={t('languageRegion')} subtitle={t('languageRegionSubtitle')}>
          <p className="mb-5 text-sm text-sky-500">{t('localizationHelp')}</p>
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
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
                  <option key={dateFormat} value={dateFormat}>{dateFormatExample(dateFormat, preferredLocale(form.data.display_language))}</option>
                ))}
              </DarkSelect>
            </DarkField>
            <div className="grid gap-3 sm:grid-cols-3 md:col-span-2 xl:col-span-1">
              <DarkField label={t('currency')}>
                <DarkInput value={form.data.currency} disabled />
              </DarkField>
              <DarkField label={t('phonePrefix')}>
                <DarkInput value={form.data.phone_prefix} disabled />
              </DarkField>
              <DarkField label={t('displayLanguage')} error={form.errors.display_language}>
                <DarkSelect value={form.data.display_language} onChange={(event) => form.setData('display_language', event.target.value)}>
                  <option value="ro">RO</option>
                  <option value="en">EN</option>
                </DarkSelect>
              </DarkField>
            </div>
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
            <p className="mt-2 text-xs text-sky-500">{t('logoHint')}</p>
          </div>

          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
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
          <p className="mt-2 text-sm text-sky-500">{t('notificationEmailHelp')}</p>
          <div className="mt-7 grid gap-4 lg:grid-cols-3">
            <div className="rounded-lg border border-slate-800/70 px-4 app-panel-soft">
              <ToggleRow title={t('newBookingEmailsTitle')} subtitle={t('newBookingEmailsDescription')} checked={form.data.booking_confirmations} onChange={(checked) => form.setData('booking_confirmations', checked)} disabled={!paidEmailSettingsAvailable} helper={!paidEmailSettingsAvailable ? t('availableOnPaidPlans') : undefined} />
            </div>
            <div className="rounded-lg border border-slate-800/70 px-4 app-panel-soft">
              <ToggleRow title={t('bookingStatusEmailsTitle')} subtitle={t('bookingStatusEmailsDescription')} checked={form.data.booking_status_email_notifications} onChange={(checked) => form.setData('booking_status_email_notifications', checked)} disabled={!paidEmailSettingsAvailable} helper={!paidEmailSettingsAvailable ? t('availableOnPaidPlans') : undefined} />
            </div>
            <div className="rounded-lg border border-slate-800/70 px-4 app-panel-soft">
              <ToggleRow title={t('missedCallAlerts')} subtitle={t('missedCallAlertsHelp')} checked={form.data.missed_call_alerts} onChange={(checked) => form.setData('missed_call_alerts', checked)} disabled={!missedCallAlertsAvailable} helper={!missedCallAlertsAvailable ? t('availableWithPhoneAi') : undefined} />
            </div>
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
        {helper && <p className="mt-2 text-xs font-bold text-sky-500">{helper}</p>}
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
  productStatus,
  entitlementStatus,
  activationStatus,
  activationHref,
  productTone,
  entitlementTone,
  activationTone,
}: {
  icon: any;
  title: string;
  subtitle: string;
  productStatus: string;
  entitlementStatus: string;
  activationStatus?: string;
  activationHref?: string;
  productTone: 'active' | 'planned';
  entitlementTone: 'active' | 'upgrade';
  activationTone?: 'active' | 'upgrade' | 'planned' | 'error' | 'neutral';
}) {
  const badgeClass = (tone: 'active' | 'upgrade' | 'planned' | 'error' | 'neutral') => ({
    active: 'bg-green-100 text-green-800',
    upgrade: 'bg-amber-100 text-amber-900',
    planned: 'bg-slate-700 text-slate-100',
    error: 'bg-red-100 text-red-800',
    neutral: 'bg-[var(--app-panel-soft)] app-text-soft',
  }[tone]);

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
        <span className={`rounded-md px-3 py-1 text-xs font-bold ${badgeClass(productTone)}`}>{productStatus}</span>
        <span className={`rounded-md px-3 py-1 text-xs font-bold ${badgeClass(entitlementTone)}`}>{entitlementStatus}</span>
        {activationStatus && activationHref ? (
          <Link href={activationHref} className={`rounded-md px-3 py-1 text-xs font-bold transition hover:opacity-85 ${badgeClass(activationTone ?? 'neutral')}`}>
            {activationStatus}
          </Link>
        ) : activationStatus ? (
          <span className={`rounded-md px-3 py-1 text-xs font-bold ${badgeClass(activationTone ?? 'neutral')}`}>{activationStatus}</span>
        ) : null}
      </div>
    </div>
  );
}

function PlanServicesOverview({ services, currentPlan, whatsappIntegration }: { services: OfferedService[]; currentPlan: Plan; whatsappIntegration?: WhatsappIntegration | null }) {
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
            productStatus={serviceStatusLabel(service, t)}
            entitlementStatus={serviceEntitlementLabel(service, currentPlan, t)}
            activationStatus={service.key === 'whatsapp_ai' ? whatsappBillingActivationLabel(currentPlan, whatsappIntegration, t) : undefined}
            activationHref={service.key === 'whatsapp_ai' ? '/dashboard/whatsapp' : undefined}
            productTone={service.implementation_status === 'live' ? 'active' : 'planned'}
            entitlementTone={planHasService(currentPlan, service.key) ? 'active' : 'upgrade'}
            activationTone={service.key === 'whatsapp_ai' ? whatsappBillingActivationTone(currentPlan, whatsappIntegration) : undefined}
          />
        ))}
      </div>
    </Card>
  );
}

function whatsappBillingActivationLabel(plan: Plan | undefined | null, integration: WhatsappIntegration | undefined | null, t: TranslateFn) {
  if (!planHasService(plan, 'whatsapp_ai')) return t('requiresActivation');
  if (integration?.status === 'active') return t('available');

  return t('requiresActivation');
}

function whatsappBillingActivationTone(plan: Plan | undefined | null, integration: WhatsappIntegration | undefined | null): 'active' | 'upgrade' | 'planned' | 'error' | 'neutral' {
  if (!planHasService(plan, 'whatsapp_ai')) return 'upgrade';
  if (integration?.status === 'active') return 'active';

  return 'upgrade';
}

function whatsappActivationStateLabel(plan: Plan | undefined | null, integration: WhatsappIntegration | undefined | null, t: TranslateFn) {
  return t(whatsappActivationStateKey(plan, integration));
}

function whatsappActivationStateTone(plan: Plan | undefined | null, integration: WhatsappIntegration | undefined | null): 'active' | 'upgrade' | 'planned' | 'error' | 'neutral' {
  if (!planHasService(plan, 'whatsapp_ai')) return 'upgrade';
  if (!integration) return 'upgrade';

  switch (integration.status) {
    case 'requested':
      return 'upgrade';
    case 'active':
      return 'active';
    case 'disabled':
      return 'neutral';
    case 'failed':
      return 'error';
    default:
      return 'upgrade';
  }
}

function whatsappActivationStateKey(plan: Plan | undefined | null, integration: WhatsappIntegration | undefined | null) {
  if (!planHasService(plan, 'whatsapp_ai')) return 'requiresUpgrade';
  if (!integration) return 'needsActivation';

  switch (integration.status) {
    case 'requested':
      return integration.requested_number ? 'activationRequested' : 'needsActivation';
    case 'active':
      return 'activated';
    case 'disabled':
      return 'disabled';
    case 'failed':
      return 'activationError';
    default:
      return 'needsActivation';
  }
}

function Conversations({ salon, query }: { salon: Salon; query: string; overview: OverviewData }) {
  const t = useT();
  const [selectedId, setSelectedId] = useState(salon.conversations[0]?.id ?? null);
  const [deletingId, setDeletingId] = useState<number | null>(null);
  const [channelFilter, setChannelFilter] = useState<ConversationChannelFilter>(() => initialConversationChannelFilter());
  const transcriptRef = useRef<HTMLDivElement | null>(null);
  const channelConversations = useMemo(
    () => salon.conversations.filter((conversation) => conversationMatchesChannel(conversation, channelFilter)),
    [salon.conversations, channelFilter],
  );
  const channelStats = useMemo(() => buildConversationChannelStats(channelConversations, salon.timezone), [channelConversations, salon.timezone]);
  const normalizedQuery = query.toLowerCase();
  const conversations = channelConversations.filter((conversation) => {

    const haystack = [
      conversation.contact_name,
      conversation.contact_phone,
      conversation.contact_email,
      conversation.summary,
      conversation.messages.at(-1)?.content,
    ].filter(Boolean).join(' ').toLowerCase();

    return haystack.includes(normalizedQuery);
  });
  const selected = conversations.find((conversation) => conversation.id === selectedId) ?? conversations[0] ?? null;
  const emptyTitle = channelFilter === 'voice'
    ? t('noVoiceCallsFound')
    : channelFilter === 'whatsapp'
      ? t('noWhatsappConversationsFound')
      : t('noConversations');
  const emptyHelp = channelFilter === 'all' ? t('noConversationsHelp') : t('noFilteredConversationsHelp');
  const lastMessageId = selected?.messages.at(-1)?.id ?? null;

  useEffect(() => {
    const transcript = transcriptRef.current;
    if (! transcript) return;

    transcript.scrollTop = transcript.scrollHeight;
  }, [selected?.id, lastMessageId]);

  function selectChannelFilter(filter: ConversationChannelFilter) {
    setChannelFilter(filter);

    if (typeof window !== 'undefined') {
      window.localStorage.setItem(CONVERSATION_CHANNEL_FILTER_STORAGE_KEY, filter);
    }
  }

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
      <div className="shrink-0 p-4 app-border sm:p-5 lg:p-8">
        <div className="flex items-center justify-end">
          <div className="inline-flex gap-1">
            <ConversationFilterButton active={channelFilter === 'voice'} onClick={() => selectChannelFilter('voice')} icon={Phone}>{t('phoneCalls')}</ConversationFilterButton>
            <ConversationFilterButton active={channelFilter === 'chat'} onClick={() => selectChannelFilter('chat')} icon={MessageSquare}>{t('chat')}</ConversationFilterButton>
            <ConversationFilterButton active={channelFilter === 'whatsapp'} onClick={() => selectChannelFilter('whatsapp')} icon={MessageCircle}>{t('whatsapp')}</ConversationFilterButton>
          </div>
        </div>
        <div className="mt-4 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-2 xl:grid-cols-4">
          <ChannelStat label={t('totalConversations')} value={channelStats.total} icon={MessageSquare} tone="blue" />
          <ChannelStat label={t('conversationsToday')} value={channelStats.today} icon={Clock} tone="purple" />
          <ChannelStat label={t('openConversations')} value={channelStats.open} icon={MessageCircle} tone="green" />
          <ChannelStat label={t('abandonedConversations')} value={channelStats.abandoned} icon={XCircle} tone="slate" />
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
                  const lastMessage = conversation.messages.at(-1)?.content ?? t('conversationWithoutMessages');
                  const isWhatsapp = isWhatsappConversation(conversation);
                  const whatsappNumber = conversationWhatsappNumber(conversation);
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
                          <div className="flex min-w-0 items-center justify-between gap-2">
                            <p className="truncate text-sm font-bold app-text">{conversationTitle(conversation, t)}</p>
                            {isWhatsapp && (
                              <span
                                className="inline-flex max-w-[9.5rem] shrink-0 items-center gap-1 rounded-md bg-green-100 px-1.5 py-0.5 text-[10px] font-bold text-green-800 dark:bg-green-500/15 dark:text-green-300"
                                title={whatsappNumber ?? 'WhatsApp'}
                              >
                                <SiWhatsapp className="h-3 w-3" />
                                <span className="truncate">{whatsappNumber ?? 'WhatsApp'}</span>
                              </span>
                            )}
                          </div>
                          <p className="mt-1 truncate text-xs app-text-muted">{lastMessage}</p>
                          <div className="mt-2 flex flex-wrap gap-1.5">
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
                  {isPhoneConversation(selected) ? <Phone className="h-5 w-5" /> : isWhatsappConversation(selected) ? <SiWhatsapp className="h-5 w-5" /> : <MessageSquare className="h-5 w-5" />}
                </div>
                <div className="min-w-0">
                  <h3 className="truncate text-xl font-bold app-text sm:text-2xl">{conversationChannelTitle(selected, t)}</h3>
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
              <div ref={transcriptRef} className="min-h-0 flex-1 space-y-5 overflow-y-auto pr-2">
                {selected.messages.map((message) => {
                  const participant = conversationMessageParticipant(selected, message, t);
                  const alignRight = conversationMessageAlignsRight(selected, message);
                  const bubbleClass = alignRight ? 'chat-bubble-user' : 'app-panel-soft';
                  const sendStatus = whatsappOutboundSendStatus(selected, message, t);

                  return (
                    <div key={message.id} className={`flex items-start gap-2 sm:gap-3 ${alignRight ? 'justify-end' : 'justify-start'}`}>
                      <div className={`flex max-w-full flex-col gap-1.5 sm:max-w-[78%] ${alignRight ? 'items-end' : 'items-start'}`}>
                        <div className={`flex flex-wrap gap-1.5 ${alignRight ? 'justify-end' : 'justify-start'}`}>
                          <span className={`inline-flex rounded-md px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide ${participant.badgeClass}`}>
                            {participant.label}
                          </span>
                          {sendStatus && (
                            <span title={sendStatus.title} className={`inline-flex rounded-md px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide ${sendStatus.className}`}>
                              {sendStatus.label}
                            </span>
                          )}
                        </div>
                        <div className={`w-full break-words rounded-lg px-4 py-3 text-sm leading-6 ${bubbleClass}`}>
                          <InlineMarkdown text={formatNaturalDatesInText(message.content)} />
                        </div>
                      </div>
                    </div>
                  );
                })}
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
            {isPhoneConversation(selected) && (
              <DarkPanel>
                <h3 className="mb-6 flex items-center gap-2 text-lg font-bold app-text"><Phone className="h-5 w-5" /> {t('phoneAi')}</h3>
                <Detail icon={Bot} label={t('agent')} value={`${salon.ai_assistant_name?.trim() || 'Bella'} Romania Line`} />
                <Detail icon={Phone} label={t('businessPhone')} value={salon.locations[0]?.phone || '+40 000 000 000'} />
              </DarkPanel>
            )}
            {isWhatsappConversation(selected) && (
              <DarkPanel>
                <h3 className="mb-6 flex items-center gap-2 text-lg font-bold app-text"><SiWhatsapp className="h-5 w-5" /> {t('whatsappAiCardTitle')}</h3>
                <Detail icon={MessageCircle} label={t('conversationChannel')} value="WhatsApp" />
                <Detail icon={User} label={t('contact')} value={conversationTitle(selected, t)} />
                <Detail icon={Bot} label={t('provider')} value={formatProvider(selected.provider)} />
                <Detail icon={Clock} label={t('status')} value={selected.status === 'open' ? t('intentInquiry') : selected.status} />
              </DarkPanel>
            )}
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
  return conversation.contact_name
    || cleanWhatsappAddress(conversation.contact_phone)
    || conversation.contact_email
    || cleanWhatsappAddress(conversation.external_contact_id)
    || cleanWhatsappAddress(conversation.external_sender)
    || (t ? t('visitorLabel', { id: num }) : `Visitor #${num}`);
}

function cleanWhatsappAddress(value?: string | null) {
  return value?.trim().replace(/^whatsapp:/i, '') || null;
}

function conversationWhatsappNumber(conversation: Conversation) {
  return cleanWhatsappAddress(conversation.external_contact_id)
    || cleanWhatsappAddress(conversation.contact_phone)
    || cleanWhatsappAddress(conversation.external_sender);
}

function isWhatsappConversation(conversation: Conversation) {
  return conversation.channel === 'whatsapp';
}

function isPhoneConversation(conversation: Conversation) {
  return ['voice', 'phone', 'call'].includes(conversation.channel as string);
}

function conversationChannelTitle(conversation: Conversation, t: TranslateFn) {
  if (isWhatsappConversation(conversation)) return t('whatsappConversationTitle');
  if (isPhoneConversation(conversation)) return t('phoneConversationTitle');

  return t('chatConversationTitle');
}

function conversationMessageAlignsRight(conversation: Conversation, message: Conversation['messages'][number]) {
  if (isWhatsappConversation(conversation)) {
    if (message.direction === 'inbound') return false;
    if (message.direction === 'outbound') return true;
  }

  return message.role === 'assistant' || message.direction === 'outbound';
}

function conversationMessageParticipant(conversation: Conversation, message: Conversation['messages'][number], t: TranslateFn) {
  const client = {
    label: t('clientBadge'),
    avatar: 'C',
    tone: 'client',
    badgeClass: 'bg-slate-100 text-slate-700 dark:bg-white/10 dark:text-slate-300',
  };
  const ai = {
    label: t('aiBadge'),
    avatar: 'AI',
    tone: 'ai',
    badgeClass: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-500/15 dark:text-indigo-300',
  };
  const yougo = {
    label: t('yougoBadge'),
    avatar: 'YG',
    tone: 'yougo',
    badgeClass: 'bg-green-100 text-green-800 dark:bg-green-500/15 dark:text-green-300',
  };

  if (isWhatsappConversation(conversation)) {
    if (message.direction === 'inbound') return client;
    if (message.role === 'assistant') return ai;
    if (message.direction === 'outbound') return yougo;
  }

  return message.role === 'assistant' ? ai : client;
}

function whatsappOutboundSendStatus(conversation: Conversation, message: Conversation['messages'][number], t: TranslateFn) {
  if (!isWhatsappConversation(conversation) || message.direction !== 'outbound') {
    return null;
  }

  const delivery = isRecord(message.metadata?.delivery) ? message.metadata.delivery : null;
  const status = normalizeWhatsappDeliveryStatus(
    stringValue(delivery?.status)
      || stringValue(message.metadata?.delivery_status)
      || stringValue(message.metadata?.status),
  );
  const errorCode = stringValue(delivery?.error_code) || stringValue(message.metadata?.twilio_error_code);

  if (status) {
    const failed = status === 'failed' || status === 'undelivered';
    const delivered = status === 'delivered' || status === 'read';
    const pending = status === 'queued' || status === 'accepted' || status === 'sending';

    return {
      label: whatsappDeliveryStatusLabel(status, t),
      title: failed && errorCode ? t('deliveryErrorDetails', { code: errorCode }) : undefined,
      className: failed
        ? 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300'
        : delivered
          ? 'bg-green-100 text-green-800 dark:bg-green-500/15 dark:text-green-300'
          : pending
            ? 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300'
            : 'bg-slate-100 text-slate-700 dark:bg-white/10 dark:text-slate-300',
    };
  }

  if (!message.provider_message_id) {
    return {
      label: t('sendingUnconfirmed'),
      className: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    };
  }

  return null;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function stringValue(value: unknown): string | null {
  if (typeof value === 'string') return value;
  if (typeof value === 'number') return String(value);

  return null;
}

function normalizeWhatsappDeliveryStatus(status?: string | null) {
  const normalized = status?.trim().toLowerCase();
  if (!normalized) return null;

  return ['accepted', 'queued', 'sending', 'sent', 'delivered', 'read', 'failed', 'undelivered'].includes(normalized)
    ? normalized
    : 'unknown';
}

function whatsappDeliveryStatusLabel(status: string, t: TranslateFn) {
  const labels: Record<string, string> = {
    queued: t('deliveryQueued'),
    accepted: t('deliveryAccepted'),
    sending: t('deliverySending'),
    sent: t('deliverySent'),
    delivered: t('deliveryDelivered'),
    read: t('deliveryRead'),
    failed: t('sendFailed'),
    undelivered: t('deliveryUndelivered'),
    unknown: t('deliveryUnknown'),
  };

  return labels[status] ?? t('deliveryUnknown');
}

function formatProvider(provider?: string | null) {
  if (!provider) return 'N/A';

  return provider.charAt(0).toUpperCase() + provider.slice(1);
}

function conversationMatchesChannel(conversation: Conversation, filter: 'all' | 'voice' | 'chat' | 'whatsapp') {
  const channel = conversation.channel as string;

  if (filter === 'all') return true;
  if (filter === 'chat') return channel === 'chat' || channel === 'web_widget';

  return channel === filter;
}

function buildConversationChannelStats(conversations: Conversation[], timezone?: string | null) {
  const todayKey = dateKeyInTimezone(new Date(), timezone);

  return {
    total: conversations.length,
    today: conversations.filter((conversation) => conversation.created_at && dateKeyInTimezone(new Date(conversation.created_at), timezone) === todayKey).length,
    open: conversations.filter((conversation) => conversation.status === 'open').length,
    abandoned: conversations.filter((conversation) => conversation.intent === 'abandoned').length,
  };
}

function dateKeyInTimezone(date: Date, timezone?: string | null) {
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: timezone || undefined,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(date);
  const value = (type: string) => parts.find((part) => part.type === type)?.value ?? '';

  return `${value('year')}-${value('month')}-${value('day')}`;
}

function initialConversationChannelFilter(): ConversationChannelFilter {
  if (typeof window === 'undefined') {
    return 'chat';
  }

  const stored = window.localStorage.getItem(CONVERSATION_CHANNEL_FILTER_STORAGE_KEY);

  return stored === 'voice' || stored === 'chat' || stored === 'whatsapp'
    ? stored
    : 'chat';
}

function ConversationFilterButton({ active, onClick, icon: Icon, children }: { active: boolean; onClick: () => void; icon?: any; children: React.ReactNode }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`inline-flex h-9 items-center justify-center gap-2 rounded-md border px-3 text-sm font-bold transition ${active ? 'border-indigo-600 bg-indigo-600 text-white shadow-sm' : 'app-border app-panel app-text-muted hover:bg-[var(--app-panel-soft)]'}`}
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

  return localizeStoredConversationSummary(conversation.summary, t) || t('noSummary');
}

function localizeStoredConversationSummary(summary: string | null | undefined, t: TranslateFn) {
  if (!summary) return '';

  const text = summary.trim();
  const askedPrefix = 'Clientul a intrebat despre ';
  if (text.startsWith(askedPrefix)) {
    return t('customerAskedAboutSummary', { topic: text.slice(askedPrefix.length) });
  }
  const englishAskedPrefix = 'The client asked about ';
  if (text.startsWith(englishAskedPrefix)) {
    return t('customerAskedAboutSummary', { topic: text.slice(englishAskedPrefix.length) });
  }

  const knownSummaries: Record<string, string> = {
    'Clientul a discutat cu asistentul si a creat o programare care asteapta confirmare.': t('bookingSummaryPending'),
    'The client spoke with the assistant and created a booking that is waiting for confirmation.': t('bookingSummaryPending'),
    'Conversatie in desfasurare cu asistentul virtual.': t('conversationInProgressSummary'),
    'Conversation in progress with the virtual assistant.': t('conversationInProgressSummary'),
    'Conversatie WhatsApp noua.': t('newWhatsappConversationSummary'),
    'New WhatsApp conversation.': t('newWhatsappConversationSummary'),
  };

  return knownSummaries[text] ?? text;
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
    open: 'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-400',
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
  const locale = preferredLocale() === 'en' ? 'en-GB' : 'ro-RO';

  return new Intl.DateTimeFormat(locale, {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: timezone || undefined,
  }).format(new Date(value));
}

function formatNaturalDatesInText(text: string) {
  const months = preferredLocale() === 'en'
    ? ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
    : ['ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'];

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
  const missingRequiredSteps = onboarding.steps.filter((step) => step.required && !step.completed);

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
          <div className="flex flex-col items-end gap-2">
            <Button onClick={complete} disabled={!onboarding.can_complete}>{t('markSetupComplete')}</Button>
            {!onboarding.can_complete && missingRequiredSteps.length > 0 && (
              <p className="max-w-xs text-right text-xs text-amber-700">
                {t('markSetupCompleteBlocked')}: {missingRequiredSteps.map((step) => t(step.label_key)).join(', ')}
              </p>
            )}
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
  // Recomputing this from onboarding.steps used to count optional steps (e.g.
  // "Instalează widgetul") in the denominator alongside required ones — so an
  // unfinished optional step dragged the percentage below 100% and made it look
  // like something mandatory was still outstanding, contradicting its own "Optional"
  // badge on the row below. The backend already computes the required-only figures
  // (OnboardingChecklistService::forSalon()); use those instead of re-deriving a
  // different, inconsistent number here.
  const completedCount = onboarding.completed_count;
  const totalCount = onboarding.total_required;
  const progress = onboarding.progress;

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
      <div className="grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-2 xl:grid-cols-4">
        <Stat label={t('totalBookings')} value={metrics.total_bookings} icon={Calendar} tone="green" />
        <Stat label={t('conversionRate')} value={`${metrics.conversion_rate}%`} icon={CheckCircle2} tone="amber" />
        <Stat label={t('bookingsThisWeek')} value={metrics.bookings_this_week} icon={Calendar} />
        <Stat label={t('completedBookings')} value={metrics.completed_bookings} icon={CheckCircle2} tone="slate" />
      </div>
      <UsageOverviewCard summary={overview.usage} />
      <div className="grid items-stretch gap-6 xl:grid-cols-[1.6fr_1fr]">
        <Card className="flex h-full flex-col p-4 sm:p-5">
          <div className="mb-5 flex flex-col items-start gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
            <h2 className="text-xs font-bold uppercase tracking-wide app-text-muted">{t('activityReport')}</h2>
            <div className="flex w-full flex-wrap items-center gap-2 sm:w-auto">
              {activityRange === 'month' && (
                <div className="inline-flex items-center rounded-lg border p-1 app-panel">
                  <button type="button" onClick={() => changeActivityMonth(-1)} className="flex h-11 w-11 items-center justify-center rounded-md app-text-muted hover:bg-[var(--app-panel-soft)]" aria-label={t('previousMonth')}>
                    <ChevronLeft className="h-4 w-4" />
                  </button>
                  <span className="min-w-0 px-3 text-center text-xs font-bold capitalize app-text-soft sm:min-w-36">{activityMonthLabel}</span>
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
        <Card className="h-full p-4 sm:p-5">
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
  const items: UsageSummaryItem[] = [
    {
      key: 'conversations',
      label: t('chatConversationsUsage'),
      used: summary.usage.conversations,
      limit: summary.limits.conversations,
      icon: MessageSquare,
      tone: 'indigo',
    },
    {
      key: 'ai_messages',
      label: t('aiMessagesUsage'),
      used: summary.usage.ai_messages,
      limit: summary.limits.ai_messages,
      icon: Sparkles,
      tone: 'emerald',
    },
    {
      key: 'bookings',
      label: t('aiBookingsUsage'),
      used: summary.usage.bookings,
      limit: summary.limits.bookings,
      icon: Calendar,
      tone: 'sky',
    },
  ];
  if (planHasService(summary.plan, 'whatsapp_ai')) {
    items.push({
      key: 'whatsapp_messages',
      label: t('whatsappMessagesUsage'),
      used: summary.usage.whatsapp_messages ?? 0,
      limit: summary.limits.whatsapp_messages ?? 0,
      icon: SiWhatsapp,
      tone: 'slate',
    });
  }
  if (planHasService(summary.plan, 'phone_ai')) {
    items.push({
      key: 'phone_minutes',
      label: t('phoneMinutesUsage'),
      used: summary.usage.phone_minutes ?? 0,
      limit: summary.limits.phone_minutes ?? 0,
      icon: Phone,
      tone: 'slate',
    });
  }

  return (
    <Card className={`${compact ? 'shrink-0 p-4' : 'p-5'}`}>
      <div className={`flex flex-col lg:flex-row lg:items-center lg:justify-between ${compact ? 'gap-3' : 'gap-4'}`}>
        <div>
          <p className="text-xs font-semibold uppercase tracking-wide app-text-muted">{t('usageThisMonth')}</p>
          <h2 className="mt-1 text-lg font-semibold app-text">{t('currentPlanLabel')}: {summary.plan.name}</h2>
        </div>
        {action}
      </div>
      <div className={`${compact ? 'mt-3 gap-3' : 'mt-5 gap-4'} grid md:grid-cols-2 xl:grid-cols-5`}>
        {items.map(({ key, ...item }) => <UsageRing key={key} compact={compact} {...item} />)}
      </div>
    </Card>
  );
}

function UsageRing({ label, used, limit, icon: Icon, tone, compact = false, locked = false }: { label: string; used: number; limit: number | null; icon: any; tone: UsageRingTone; compact?: boolean; locked?: boolean }) {
  const t = useT();
  const percentage = limit && limit > 0 ? Math.min(100, Math.round((used / limit) * 100)) : 0;
  const unavailable = limit === 0;
  const usageLabel = unavailable ? t('notIncludedInPlan') : t('usedOfLimit', { used: formatUsageNumber(used), limit: formatLimit(limit, t) });
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
        <svg className={`-rotate-90 ${compact ? 'h-[68px] w-[68px]' : 'h-28 w-28'}`} viewBox={`0 0 ${size} ${size}`} role="img" aria-label={`${label}: ${usageLabel}`}>
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
        <p className={`${compact ? 'text-base' : 'text-xl'} mt-1 font-bold app-text`}>
          {unavailable ? <span className="text-sm font-semibold app-text-muted">{t('notIncludedInPlan')}</span> : usageLabel}
        </p>
      </div>
    </div>
  );
}

function BillingPage({ billing, currentPlan }: { billing: Props['billing']; currentPlan: string }) {
  const t = useT();
  const canonicalCurrentPlan = canonicalPlanKey(currentPlan);
  // Annual billing is intentionally hidden until Stripe annual price IDs are configured.
  const billingCycle: 'monthly' | 'annual' = 'monthly';
  const [loadingPlan, setLoadingPlan] = useState<string | null>(null);
  const [portalLoading, setPortalLoading] = useState(false);
  const [billingError, setBillingError] = useState('');
  const [selectedVoicePlan, setSelectedVoicePlan] = useState<VoicePlanKey>(
    isVoicePlanKey(canonicalCurrentPlan) ? canonicalCurrentPlan : 'voice_starter'
  );
  const checkoutState = typeof window !== 'undefined' ? new URLSearchParams(window.location.search).get('checkout') : null;

  async function startCheckout(planKey: string) {
    if (loadingPlan) return;

    setLoadingPlan(planKey);
    setBillingError('');

    try {
      const response = await fetch('/dashboard/billing/checkout', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          ...csrfHeaders(),
        },
        body: JSON.stringify({ plan_key: planKey }),
      });
      const data = await response.json().catch(() => ({}));

      if (!response.ok || typeof data.url !== 'string') {
        throw new Error(billingErrorMessage(data, t('checkoutUnavailable')));
      }

      window.location.href = data.url;
    } catch (error) {
      setBillingError(error instanceof Error ? error.message : t('checkoutUnavailable'));
      setLoadingPlan(null);
    }
  }

  async function openPortal() {
    if (portalLoading) return;

    setPortalLoading(true);
    setBillingError('');

    try {
      const response = await fetch('/dashboard/billing/portal', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          ...csrfHeaders(),
        },
      });
      const data = await response.json().catch(() => ({}));

      if (!response.ok || typeof data.url !== 'string') {
        throw new Error(billingErrorMessage(data, t('portalUnavailable')));
      }

      window.location.href = data.url;
    } catch (error) {
      setBillingError(error instanceof Error ? error.message : t('portalUnavailable'));
      setPortalLoading(false);
    }
  }

  function renderPlanAction(plan: Plan) {
    if (!billing.stripe.paid_plan_keys.includes(plan.key)) return null;

    const current = canonicalCurrentPlan === plan.key;
    const configured = billing.stripe.configured_prices[plan.key] === true;
    const label = current ? t('currentPlan') : canonicalCurrentPlan === 'free' ? t('choosePlanButton') : t('changePlan');

    return (
      <Button
        type="button"
        className="mt-auto w-full"
        disabled={current || !configured || loadingPlan !== null}
        onClick={() => startCheckout(plan.key)}
      >
        {loadingPlan === plan.key ? t('openingCheckout') : label}
      </Button>
    );
  }

  return (
    <div className="space-y-6">
      <Card className="p-6">
        <div className="grid gap-6 xl:grid-cols-[1fr_340px]">
          <div>
            <p className="text-xs font-semibold uppercase tracking-wide app-text-muted">{t('currentPlan')}</p>
            <h2 className="mt-2 text-3xl font-semibold app-text">{billing.summary.plan.name}</h2>
            <p className="mt-1 text-sm app-text-soft">{priceLabel(billing.summary.plan, billingCycle, t)}</p>
            <p className="mt-4 max-w-3xl text-sm leading-6 app-text-muted">{t('billingStripeConnected')}</p>
            {billing.stripe.subscription_status && (
              <p className="mt-2 text-sm app-text-muted">{t('subscriptionStatus')}: <span className="font-semibold app-text">{billing.stripe.subscription_status}</span></p>
            )}
            {billing.stripe.subscription_current_period_end && (
              <p className="mt-1 text-sm app-text-muted">{t('subscriptionRenews')}: <span className="font-semibold app-text">{new Date(billing.stripe.subscription_current_period_end).toLocaleDateString()}</span></p>
            )}
          </div>
          <div className="rounded-lg border p-4 app-border app-panel-soft">
            <p className="text-sm font-semibold app-text">{t('subscriptionManagement')}</p>
            <p className="mt-2 text-xs leading-5 app-text-muted">{t('subscriptionManagementHelp')}</p>
            {(billing.stripe.stripe_customer_exists || billing.stripe.stripe_subscription_exists) && (
              <SecondaryButton className="mt-4 w-full" type="button" disabled={portalLoading} onClick={openPortal}>
                {portalLoading ? t('openingPortal') : t('manageSubscription')}
              </SecondaryButton>
            )}
          </div>
        </div>
      </Card>

      {checkoutState === 'success' && (
        <div className="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm font-semibold text-green-800 dark:border-green-500/30 dark:bg-green-500/10 dark:text-green-200">
          {t('checkoutSuccess')}
        </div>
      )}
      {checkoutState === 'cancelled' && (
        <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
          {t('checkoutCancelled')}
        </div>
      )}
      {billing.stripe.payment_warning && (
        <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200">
          {t('paymentWarning')}
        </div>
      )}
      {billingError && (
        <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200">
          {billingError}
        </div>
      )}

      <UsageSummaryPanel summary={billing.summary} />

      <PlanServicesOverview services={billing.services} currentPlan={billing.summary.plan} whatsappIntegration={billing.whatsapp_integration} />

      <PricingPlansGrid
        plans={billing.plans}
        services={billing.services}
        billingCycle={billingCycle}
        selectedVoicePlan={selectedVoicePlan}
        onSelectedVoicePlanChange={setSelectedVoicePlan}
        t={t}
        showCtas={false}
        currentPlanKey={canonicalCurrentPlan}
        renderPlanAction={renderPlanAction}
      />
    </div>
  );
}

function billingErrorMessage(data: unknown, fallback: string): string {
  if (data && typeof data === 'object') {
    const payload = data as { message?: unknown; errors?: Record<string, string[]> };
    const validationMessage = Object.values(payload.errors ?? {})[0]?.[0];
    const message = typeof payload.message === 'string' ? payload.message : '';

    return validationMessage || message || fallback;
  }

  return fallback;
}

function isVoicePlanKey(plan: string): plan is VoicePlanKey {
  return ['voice_starter', 'voice_growth', 'voice_pro'].includes(plan);
}

function priceLabel(plan: Plan, billingCycle: 'monthly' | 'annual', t: TranslateFn) {
  const monthly = monthlyPrice(plan);

  if (monthly === null) {
    return stripMonthlySuffix(plan.price_label);
  }

  const value = billingCycle === 'annual' ? monthly * 10 : monthly;

  const amount = new Intl.NumberFormat(preferredLocale() === 'en' ? 'en-GB' : 'ro-RO').format(value);

  return billingCycle === 'monthly'
    ? `${amount} RON${t('pricePerMonthSuffix')}`
    : `${amount} RON`;
}

function monthlyPrice(plan: Plan) {
  const match = plan.price_label.match(/^([\d.]+)\s+RON/);
  if (! match) return null;

  return Number(match[1].replaceAll('.', ''));
}

function stripMonthlySuffix(label: string) {
  return label
    .replace('/lună', '')
    .replace('/lunÄƒ', '')
    .replace('/lunÃ„Æ’', '')
    .replace('/month', '');
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

function formatUsageNumber(value: number): string {
  return new Intl.NumberFormat('en-GB').format(value);
}

function OverviewConversationsTable({ conversations, t }: { conversations: Conversation[]; t: TranslateFn }) {
  if (conversations.length === 0) {
    return (
      <div className="rounded-2xl border p-5 shadow-sm app-border app-panel sm:p-6">
        <div className="flex min-h-24 items-center justify-center text-sm app-text-muted">{t('noConversations')}</div>
      </div>
    );
  }

  return (
    <>
      <div className="space-y-2 md:hidden">
        {conversations.map((conversation) => (
          <Card key={conversation.id} className="p-4">
            <div className="min-w-0">
              <p className="truncate text-sm font-bold app-text">{conversationTitle(conversation, t)}</p>
              <p className="mt-1 truncate text-xs app-text-muted">
                {conversation.contact_phone || conversation.contact_email || conversation.contact_name || t('clientName')}
              </p>
            </div>
            <div className="mt-3 flex flex-wrap gap-1.5">
              <IntentPill intent={conversation.intent} compact bookingStatus={conversation.booking?.status} />
            </div>
          </Card>
        ))}
      </div>
      <div className="hidden md:block">
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
      </div>
    </>
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
    <>
      <div className="space-y-2 md:hidden">
        {bookings.map((booking) => (
          <Card key={booking.id} className="p-4">
            <div className="flex min-w-0 items-start justify-between gap-3">
              <div className="min-w-0">
                <p className="text-sm font-bold app-text">{booking.client_name}</p>
                <p className="mt-1 text-xs app-text-muted">{booking.client_phone || t('phoneMissingShort')}</p>
              </div>
              <StatusPill status={booking.status} t={t} />
            </div>
            <div className="mt-4 grid gap-3 text-sm">
              <Detail icon={Calendar} label={t('date')} value={formatBookingDay(booking.date, salon)} />
              <Detail icon={Clock} label={t('time')} value={bookingTimeRange(booking.time, booking.service?.duration)} />
              <div className="rounded-lg border p-3 app-border app-panel-soft">
                <p className="mb-2 text-xs font-bold uppercase tracking-wide app-text-muted">{t('details')}</p>
                <div className="text-sm app-text-soft">
                  <BookingDetailsCell booking={booking} salon={salon} t={t} />
                </div>
              </div>
            </div>
          </Card>
        ))}
      </div>
      <div className="hidden md:block">
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
      </div>
    </>
  );
}

function bookingStaffLabel(booking: Booking): string {
  if (booking.staff_member?.name) {
    return booking.staff_member.name;
  }

  return (booking.staff ?? []).filter(Boolean).join(' \u2022 ');
}

function Stat({ label, value, icon: Icon, tone = 'indigo' }: { label: string; value: number | string; icon: any; tone?: 'indigo' | 'amber' | 'green' | 'blue' | 'slate' | 'purple' | 'red' }) {
  const colors = {
    indigo: 'bg-indigo-50 text-indigo-700',
    amber: 'bg-amber-50 text-amber-700',
    green: 'bg-green-50 text-green-700',
    blue: 'bg-blue-50 text-blue-700',
    slate: 'bg-slate-100 text-slate-700',
    purple: 'bg-purple-50 text-purple-700',
    red: 'bg-red-50 text-red-700',
  };
  return (
    <Card className="p-3 sm:p-5">
      <div className={`mb-3 flex h-9 w-9 items-center justify-center rounded-lg sm:mb-4 sm:h-10 sm:w-10 ${colors[tone]}`}>
        <Icon className="h-4 w-4 sm:h-5 sm:w-5" />
      </div>
      <p className="text-[11px] font-bold uppercase leading-4 tracking-wide app-text-muted sm:text-xs">{label}</p>
      <p className="mt-1 text-2xl font-bold app-text sm:text-3xl">{value}</p>
    </Card>
  );
}

function AiSettings({ salon }: { salon: Salon }) {
  const t = useT();
  const selectedBusinessType = findBusinessType(normalizeBusinessTypeSlug(salon.business_type) || 'salon-beauty');
  const [customContextInput, setCustomContextInput] = useState('');
  const [advancedSettingsOpen, setAdvancedSettingsOpen] = useState(false);
  const [specialRulesOpen, setSpecialRulesOpen] = useState(false);
  const form = useForm({
    ai_assistant_name: salon.ai_assistant_name ?? 'Bella',
    ai_tone: salon.ai_tone ?? 'polite',
    ai_response_style: salon.ai_response_style ?? 'short',
    ai_language_mode: salon.ai_language_mode ?? 'auto',
    ai_greeting_message: salon.ai_greeting_message ?? '',
    ai_custom_instructions: salon.ai_custom_instructions ?? '',
    ai_business_summary: salon.ai_business_summary ?? '',
    ai_about_business: salon.ai_about_business ?? '',
    ai_policies: salon.ai_policies ?? '',
    ai_faq: salon.ai_faq ?? '',
    ai_recommendations: salon.ai_recommendations ?? '',
    ai_avoid: salon.ai_avoid ?? '',
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
          <div className="flex shrink-0 flex-wrap gap-2">
            <a href={assistantPreviewHref(salon.id)} target="_blank" rel="noreferrer" className="inline-flex h-10 items-center justify-center rounded-lg bg-indigo-600 px-4 text-sm font-medium text-white hover:bg-indigo-700">
              {t('testAssistant')}
            </a>
            {salon.ai_assistant_setup_completed ? (
              <span className="inline-flex h-10 items-center justify-center gap-1.5 rounded-lg bg-emerald-50 px-4 text-sm font-medium text-emerald-700">
                <Check className="h-4 w-4" /> {t('complete')}
              </span>
            ) : (
              <button
                type="button"
                onClick={() => router.post('/ai-settings/mark-complete', {}, { preserveScroll: true })}
                className="inline-flex h-10 items-center justify-center rounded-lg border px-4 text-sm font-medium app-text hover:bg-black/5"
              >
                {t('markStepComplete')}
              </button>
            )}
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
        <div className="mt-4">
          <Field label={t('aiGreetingMessage')} error={form.errors.ai_greeting_message}>
            <textarea
              rows={3}
              value={form.data.ai_greeting_message}
              onChange={(event) => form.setData('ai_greeting_message', event.target.value)}
              className="w-full rounded-lg border px-3 py-2 text-sm outline-none resize-none app-panel app-text"
              placeholder={t('aiGreetingMessagePlaceholder')}
            />
            <span className="block text-xs app-text-muted">{t('aiGreetingMessageHelp')}</span>
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
        <div className="grid gap-4">
          <Field label={t('aiBusinessSummary')} error={form.errors.ai_business_summary}>
            <textarea
              rows={6}
              value={form.data.ai_business_summary}
              onChange={(event) => form.setData('ai_business_summary', event.target.value)}
              className="w-full rounded-lg border px-3 py-2 text-sm outline-none resize-none app-panel app-text"
              placeholder={t('aiBusinessSummaryPlaceholder')}
            />
          </Field>
        </div>
      </Card>

      <CollapsibleSettingsPanel
        icon={Calendar}
        title={t('aiAdvancedSettings')}
        description={t('aiAdvancedSettingsHelp')}
        open={advancedSettingsOpen}
        onToggle={() => setAdvancedSettingsOpen((open) => !open)}
      >
        <div className="space-y-5">
          <div className="grid gap-4 lg:grid-cols-2">
            <Field label={t('aiAboutBusiness')} error={form.errors.ai_about_business}>
              <textarea
                rows={4}
                value={form.data.ai_about_business}
                onChange={(event) => form.setData('ai_about_business', event.target.value)}
                className="w-full rounded-lg border px-3 py-2 text-sm outline-none resize-none app-panel app-text"
                placeholder={t('aiAboutBusinessPlaceholder')}
              />
            </Field>
            <Field label={t('aiPolicies')} error={form.errors.ai_policies}>
              <textarea
                rows={4}
                value={form.data.ai_policies}
                onChange={(event) => form.setData('ai_policies', event.target.value)}
                className="w-full rounded-lg border px-3 py-2 text-sm outline-none resize-none app-panel app-text"
                placeholder={t('aiPoliciesPlaceholder')}
              />
            </Field>
            <Field label={t('aiFaq')} error={form.errors.ai_faq}>
              <textarea
                rows={4}
                value={form.data.ai_faq}
                onChange={(event) => form.setData('ai_faq', event.target.value)}
                className="w-full rounded-lg border px-3 py-2 text-sm outline-none resize-none app-panel app-text"
                placeholder={t('aiFaqPlaceholder')}
              />
            </Field>
            <Field label={t('aiRecommendations')} error={form.errors.ai_recommendations}>
              <textarea
                rows={4}
                value={form.data.ai_recommendations}
                onChange={(event) => form.setData('ai_recommendations', event.target.value)}
                className="w-full rounded-lg border px-3 py-2 text-sm outline-none resize-none app-panel app-text"
                placeholder={t('aiRecommendationsPlaceholder')}
              />
            </Field>
            <Field label={t('aiAvoid')} error={form.errors.ai_avoid}>
              <textarea
                rows={4}
                value={form.data.ai_avoid}
                onChange={(event) => form.setData('ai_avoid', event.target.value)}
                className="w-full rounded-lg border px-3 py-2 text-sm outline-none resize-none app-panel app-text"
                placeholder={t('aiAvoidPlaceholder')}
              />
            </Field>
          </div>
          <div className="border-t pt-5 app-border">
            <p className="mb-4 text-sm font-bold app-text">{t('aiBookingBehavior')}</p>
          </div>
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
      </CollapsibleSettingsPanel>

      <CollapsibleSettingsPanel
        icon={AlertTriangle}
        title={t('aiSpecialRules')}
        description={t('aiSpecialRulesHelp')}
        open={specialRulesOpen}
        onToggle={() => setSpecialRulesOpen((open) => !open)}
      >
        <Field label={t('aiSpecialRules')} error={form.errors.ai_custom_instructions}>
          <textarea
            rows={6}
            value={form.data.ai_custom_instructions}
            onChange={(event) => form.setData('ai_custom_instructions', event.target.value)}
            className="w-full rounded-lg border px-3 py-2 text-sm outline-none resize-none app-panel app-text"
            placeholder={t('aiCustomInstructionsPlaceholder')}
          />
        </Field>
      </CollapsibleSettingsPanel>

      <div className="flex justify-start">
        <Button disabled={form.processing}>
          <Save className="h-4 w-4" />
          {t('saveChanges')}
        </Button>
      </div>
    </form>
  );
}

function CollapsibleSettingsPanel({ icon: Icon, title, description, open, onToggle, children }: { icon: any; title: string; description: string; open: boolean; onToggle: () => void; children: ReactNode }) {
  return (
    <div className="rounded-lg border app-border app-panel">
      <button
        type="button"
        onClick={onToggle}
        aria-expanded={open}
        className="flex w-full items-start gap-3 px-5 py-4 text-left transition hover:bg-[var(--app-panel-soft)]"
      >
        <Icon className="mt-1 h-5 w-5 shrink-0 text-indigo-500" />
        <span className="min-w-0 flex-1">
          <span className="block text-base font-bold app-text">{title}</span>
          <span className="mt-1 block text-sm app-text-muted">{description}</span>
        </span>
        <ChevronDown className={`mt-1 h-4 w-4 shrink-0 app-text-muted transition-transform ${open ? 'rotate-180' : ''}`} />
      </button>
      <div className={`grid transition-[grid-template-rows,opacity] duration-200 ease-out ${open ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'}`}>
        <div className="min-h-0 overflow-hidden">
          <div className="border-t p-5 app-border">
            {children}
          </div>
        </div>
      </div>
    </div>
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
    // Days missing from location.hours (e.g. a location created via website import
    // that never had a schedule extracted) must stay genuinely blank here, not get
    // silently backfilled with defaultHours — that placeholder is indistinguishable
    // from real saved data once it's in the input, and saving the form would persist
    // it as if it were the location's actual schedule.
    const blankHours = Object.fromEntries(hourDays.map(([key]) => [key, '']));
    editForm.setData({
      name: location.name,
      address: location.address,
      email: location.email ?? '',
      phone: location.phone ?? '',
      hours: { ...blankHours, ...(location.hours ?? {}) },
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
                    <HoursList hours={location.hours ?? {}} />
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

function Services({ salon, query, onResetSearch }: { salon: Salon; query: string; onResetSearch: () => void }) {
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
  const [selectedServiceIds, setSelectedServiceIds] = useState<number[]>([]);
  const [bulkAction, setBulkAction] = useState<BulkServiceAction>('duration');
  const [bulkValue, setBulkValue] = useState('');
  const [bulkLocationIds, setBulkLocationIds] = useState<number[]>([]);
  const selectAllServicesRef = useRef<HTMLInputElement>(null);
  const defaultServiceLocationIds = salon.locations.length === 1 ? [salon.locations[0].id] : [];
  const defaultCurrency = defaultServiceCurrency(salon);
  const currencyOptions = serviceCurrencyOptions(localization, salon);
  const form = useForm({ name: '', type: '', price: '', currency: '', duration: 30, max_concurrent_bookings: '', location_ids: defaultServiceLocationIds, notes: '' });
  const editForm = useForm({ name: '', type: '', price: '', currency: '', duration: 30, max_concurrent_bookings: '', location_ids: [] as number[], notes: '' });
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
  const categoryServiceCounts = salon.services.reduce<Record<string, number>>((counts, service) => {
    const category = service.type?.trim();
    if (!category) return counts;

    counts[category] = (counts[category] ?? 0) + 1;
    return counts;
  }, {});
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
  const visibleServiceIds = filteredServices.map((service) => service.id);
  const visibleServiceIdSet = new Set(visibleServiceIds);
  const selectedVisibleServiceIds = selectedServiceIds.filter((id) => visibleServiceIdSet.has(id));
  const allVisibleServicesSelected = visibleServiceIds.length > 0 && selectedVisibleServiceIds.length === visibleServiceIds.length;
  const someVisibleServicesSelected = selectedVisibleServiceIds.length > 0 && !allVisibleServicesSelected;
  const hasServiceFilters = Boolean(normalizedQuery) || categoryFilter.length > 0 || branchFilter.length > 0;
  const bulkActionRequiresValue = bulkAction !== 'delete';
  const bulkApplyDisabled = selectedVisibleServiceIds.length === 0
    || (bulkAction === 'duration' && bulkValue === '')
    || (bulkAction === 'price' && bulkValue.trim() === '')
    || (bulkAction === 'location_ids' && bulkLocationIds.length === 0);

  useEffect(() => {
    setSelectedServiceIds((current) => current.filter((id) => visibleServiceIdSet.has(id)));
  }, [query, categoryFilter, branchFilter, salon.services.length]);

  useEffect(() => {
    if (salon.locations.length < 2 && branchFilter.length > 0) {
      setBranchFilter([]);
    }
  }, [salon.locations.length, branchFilter.length]);

  useEffect(() => {
    if (salon.locations.length < 2 && bulkAction === 'location_ids') {
      setBulkAction('duration');
      setBulkLocationIds([]);
    }
  }, [salon.locations.length, bulkAction]);

  useEffect(() => {
    if (selectAllServicesRef.current) {
      selectAllServicesRef.current.indeterminate = someVisibleServicesSelected;
    }
  }, [someVisibleServicesSelected, selectedVisibleServiceIds.length]);

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
        form.setData('currency', '');
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
      currency: service.currency ?? '',
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
      currency: service.currency ?? '',
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
      currency: service.currency ?? '',
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

  function toggleVisibleServices(checked: boolean) {
    setSelectedServiceIds((current) => {
      const currentSet = new Set(current);

      if (checked) {
        visibleServiceIds.forEach((id) => currentSet.add(id));
      } else {
        visibleServiceIds.forEach((id) => currentSet.delete(id));
      }

      return Array.from(currentSet);
    });
  }

  function toggleServiceSelection(serviceId: number, checked: boolean) {
    setSelectedServiceIds((current) => checked
      ? Array.from(new Set([...current, serviceId]))
      : current.filter((id) => id !== serviceId));
  }

  function clearBulkSelection() {
    setSelectedServiceIds([]);
    setBulkValue('');
    setBulkLocationIds([]);
  }

  function resetServiceSearch() {
    onResetSearch();
    setCategoryFilter([]);
    setBranchFilter([]);
    clearBulkSelection();
  }

  function bulkUpdatePayload() {
    if (bulkAction === 'type') return { type: bulkValue };
    if (bulkAction === 'duration') return { duration: Number(bulkValue) };
    if (bulkAction === 'max_concurrent_bookings') return { max_concurrent_bookings: bulkValue === '' ? null : Number(bulkValue) };
    if (bulkAction === 'price') return { price: bulkValue };
    if (bulkAction === 'location_ids') return { location_ids: bulkLocationIds };

    return {};
  }

  function applyBulkAction() {
    if (selectedVisibleServiceIds.length === 0) return;

    if (bulkAction === 'delete') {
      setConfirmation({
        title: t('bulkDeleteServices'),
        message: t('bulkDeleteServicesConfirm', { count: selectedVisibleServiceIds.length }),
        onConfirm: () => {
          router.post('/services/bulk-delete', {
            service_ids: selectedVisibleServiceIds,
          }, {
            preserveScroll: true,
            onSuccess: clearBulkSelection,
          });
        },
      });
      return;
    }

    router.post('/services/bulk-update', {
      service_ids: selectedVisibleServiceIds,
      updates: bulkUpdatePayload(),
    }, {
      preserveScroll: true,
      onSuccess: () => setBulkValue(''),
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
                <option value="">{t('businessCurrencyOption', { currency: defaultCurrency })}</option>
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
                <div className="flex min-w-0 flex-1 items-center gap-2">
                  <Input value={category} onChange={(event) => updateCategoryDraft(index, event.target.value)} placeholder={t('category')} />
                  <span className="flex h-6 min-w-6 shrink-0 items-center justify-center rounded-full bg-red-600 px-2 text-xs font-bold text-white">
                    {categoryServiceCounts[category.trim()] ?? 0}
                  </span>
                </div>
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
                  <option value="">{t('businessCurrencyOption', { currency: defaultCurrency })}</option>
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
      <div className="grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-2 xl:grid-cols-4">
        <ChannelStat label={t('services')} value={serviceStats.services} icon={Scissors} tone="blue" />
        <ChannelStat label={t('categories')} value={serviceStats.categories} icon={FileText} tone="purple" />
        <ChannelStat label={t('locations')} value={serviceStats.locations} icon={MapPin} tone="green" />
        <ChannelStat label={t('staff')} value={serviceStats.staff} icon={Users} tone="slate" />
      </div>
      <div className="flex flex-col gap-3 rounded-lg border px-4 py-3 app-border app-panel sm:flex-row sm:items-end sm:justify-between">
        <div className="min-w-0">
          <p className="text-xs font-bold uppercase tracking-wide app-text-muted">{t('bulkActions')}</p>
          <p className="mt-1 text-sm font-semibold app-text">{t('selectedServicesCount', { count: selectedVisibleServiceIds.length })}</p>
        </div>
        <div className="grid min-w-0 flex-1 gap-2 sm:max-w-3xl sm:grid-cols-[minmax(160px,220px)_minmax(160px,1fr)_auto_auto]">
          <select
            className="h-10 rounded-lg border px-3 text-sm font-semibold outline-none app-panel app-text"
            value={bulkAction}
            onChange={(event) => {
              setBulkAction(event.target.value as BulkServiceAction);
              setBulkValue('');
              setBulkLocationIds([]);
            }}
          >
            <option value="duration">{t('bulkSetDuration')}</option>
            <option value="type">{t('bulkSetCategory')}</option>
            <option value="max_concurrent_bookings">{t('bulkSetCapacity')}</option>
            <option value="price">{t('bulkSetPrice')}</option>
            {salon.locations.length > 1 && <option value="location_ids">{t('bulkSetLocations')}</option>}
            <option value="delete">{t('bulkDeleteServices')}</option>
          </select>
          {bulkActionRequiresValue ? (
            bulkAction === 'type' ? (
              <select
                className="h-10 rounded-lg border px-3 text-sm outline-none app-panel app-text"
                value={bulkValue}
                onChange={(event) => setBulkValue(event.target.value)}
              >
                <option value="">{t('noCategory')}</option>
                {(salon.service_categories ?? []).map((category) => (
                  <option key={category} value={category}>{category}</option>
                ))}
              </select>
            ) : bulkAction === 'location_ids' ? (
              <BranchPicker
                locations={salon.locations}
                selectedIds={bulkLocationIds}
                onChange={setBulkLocationIds}
                emptyLabel={t('noBranches')}
              />
            ) : (
              <Input
                type={bulkAction === 'duration' || bulkAction === 'max_concurrent_bookings' ? 'number' : 'text'}
                min={bulkAction === 'duration' ? 5 : bulkAction === 'max_concurrent_bookings' ? 1 : undefined}
                max={bulkAction === 'duration' ? 1440 : bulkAction === 'max_concurrent_bookings' ? 100 : undefined}
                value={bulkValue}
                onChange={(event) => setBulkValue(event.target.value)}
                placeholder={bulkAction === 'duration' ? t('durationMin') : bulkAction === 'max_concurrent_bookings' ? t('defaultCapacityOne') : t('pricePlaceholder')}
              />
            )
          ) : (
            <span className="hidden sm:block" />
          )}
          <Button type="button" disabled={bulkApplyDisabled} onClick={applyBulkAction}>{t('apply')}</Button>
          <SecondaryButton type="button" onClick={clearBulkSelection}>{t('clearSelection')}</SecondaryButton>
        </div>
      </div>
      {filteredServices.length === 0 ? (
        <Card className="flex min-h-52 flex-col items-center justify-center p-8 text-center">
          <Scissors className="mb-4 h-10 w-10 app-text-muted" />
          <p className="text-lg font-bold app-text">{t('servicesEmptyTitle')}</p>
          <p className="mt-2 max-w-xl text-sm app-text-muted">{hasServiceFilters ? t('servicesEmptyFilteredHelp') : t('servicesEmptyHelp')}</p>
          <div className="mt-5 flex flex-col gap-2 sm:flex-row">
            <Button onClick={() => setAdding(true)}><Plus className="h-4 w-4" /> {t('addService')}</Button>
            {hasServiceFilters && <SecondaryButton type="button" onClick={resetServiceSearch}>{t('resetSearch')}</SecondaryButton>}
          </div>
        </Card>
      ) : (
        <DashboardTable headers={[
          <input
            ref={selectAllServicesRef}
            type="checkbox"
            className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
            checked={allVisibleServicesSelected}
            onChange={(event) => toggleVisibleServices(event.target.checked)}
            aria-label={t('selectVisibleServices')}
          />,
          <CategoryFilterHeader
            key="cat-filter"
            label={t('category')}
            categories={salon.service_categories ?? []}
            counts={categoryServiceCounts}
            selected={categoryFilter}
            onChange={setCategoryFilter}
          />,
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
        ]} minWidth="1060px">
          {filteredServices.map((service, index) => (
            <tr key={service.id} className={dashboardTableRowClass(index)}>
              <>
                  <td className="w-12 px-5 py-4 align-top">
                    <input
                      type="checkbox"
                      className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                      checked={selectedServiceIds.includes(service.id)}
                      onChange={(event) => toggleServiceSelection(service.id, event.target.checked)}
                      aria-label={t('selectService', { service: service.name })}
                    />
                  </td>
                  <td className="px-5 py-4 text-sm app-text-soft">{service.type || ''}</td>
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
  const [analyzeProgress, setAnalyzeProgress] = useState(0);
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
      setAnalyzeProgress(0);
      setSaving(false);
    }
  }, [open]);

  useEffect(() => {
    if (!analyzing) return;

    setAnalyzeProgress((current) => current || 8);
    const interval = window.setInterval(() => {
      setAnalyzeProgress((current) => {
        if (current < 45) return Math.min(current + 7, 45);
        if (current < 75) return Math.min(current + 4, 75);
        return Math.min(current + 1.5, 94);
      });
    }, 700);

    return () => window.clearInterval(interval);
  }, [analyzing]);

  if (!open) return null;

  function chooseImage(file: File | null) {
    setError('');
    setWarning('');
    setCandidates([]);
    setAnalyzeProgress(0);
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
    setAnalyzeProgress(8);
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
        ? serviceImportWarningMessage(data.warning, t)
        : nextCandidates.length === 0
          ? t('noServicesDetected')
          : '';

      setCandidates(nextCandidates);
      setWarning(warningMessage);
    } catch (error) {
      setError(error instanceof Error ? error.message : t('serviceImportFailed'));
      setCandidates([]);
    } finally {
      setAnalyzeProgress(100);
      setAnalyzing(false);
      window.setTimeout(() => setAnalyzeProgress(0), 700);
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
                {analyzeProgress > 0 && (
                  <div className="rounded-lg border px-3 py-2 app-border app-panel-soft" aria-live="polite">
                    <div className="mb-2 flex items-center justify-between gap-3 text-xs font-semibold app-text-soft">
                      <span>{t('serviceImportProgressLabel')}</span>
                      <span>{Math.round(analyzeProgress)}%</span>
                    </div>
                    <div
                      className="h-2 overflow-hidden rounded-full bg-slate-200"
                      role="progressbar"
                      aria-valuemin={0}
                      aria-valuemax={100}
                      aria-valuenow={Math.round(analyzeProgress)}
                    >
                      <div
                        className="h-full rounded-full bg-indigo-600 transition-[width] duration-500 ease-out"
                        style={{ width: `${analyzeProgress}%` }}
                      />
                    </div>
                    <p className="mt-2 text-xs app-text-muted">{t('serviceImportProgressHint')}</p>
                  </div>
                )}
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
    ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : token ? { 'X-CSRF-TOKEN': token } : {}),
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
    const payload = data as { warning?: unknown; message?: unknown; details?: unknown; errors?: Record<string, string[]> };
    const validationMessage = payload.errors?.image?.[0] ?? Object.values(payload.errors ?? {})[0]?.[0];
    const warning = typeof payload.warning === 'string' ? payload.warning : '';
    const details = typeof payload.details === 'string' ? payload.details : '';
    const message = typeof payload.message === 'string' ? payload.message : '';

    if (warning === 'ai_service_busy') {
      return t('serviceImportAiBusy');
    }

    return validationMessage || details || message || t('serviceImportFailed');
  }

  return t('serviceImportFailed');
}

function jsonErrorMessage(data: unknown, fallback: string): string {
  if (data && typeof data === 'object') {
    const payload = data as { message?: unknown; errors?: Record<string, string[]> };
    const validationMessage = payload.errors ? Object.values(payload.errors)[0]?.[0] : '';
    const message = typeof payload.message === 'string' ? payload.message : '';

    return validationMessage || message || fallback;
  }

  return fallback;
}

function serviceImportWarningMessage(warning: string, t: TranslateFn): string {
  if (warning === 'truncated_response') {
    return t('serviceImportTruncatedResponse');
  }

  if (warning === 'invalid_json' || warning === 'empty_response') {
    return t('serviceImportInvalidAiResponse');
  }

  return warning;
}

function normalizeServiceName(name: string): string {
  return name.normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim().toLocaleLowerCase().replace(/\s+/g, ' ');
}

function CategoryFilterHeader({ label, categories, counts, selected, onChange }: { label: string; categories: string[]; counts: Record<string, number>; selected: string[]; onChange: (next: string[]) => void }) {
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
                <span className="min-w-0 flex-1 truncate text-left app-text">{category}</span>
                <span className="flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-red-600 px-1.5 text-[10px] font-bold leading-none text-white">
                  {counts[category] ?? 0}
                </span>
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

  if (locations.length === 1) {
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
        <div className="flex flex-col items-start gap-1 text-sm app-text-soft">
          {locations.map((location) => {
            const active = normalizedSelectedIds.includes(location.id);
            return (
              <button
                key={location.id}
                type="button"
                onClick={() => toggle(location.id)}
                className="flex w-full items-center gap-2 text-left text-sm app-text-soft transition hover:app-text"
                title={location.address}
              >
                <span className="flex h-4 w-4 shrink-0 items-center justify-center rounded border border-slate-300 bg-white dark:border-slate-600 dark:bg-white/5" aria-hidden="true">
                  {active && <Check className="h-3 w-3 text-blue-600" strokeWidth={4} />}
                </span>
                <span className="min-w-0 truncate">{location.name}</span>
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

      <div className="grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-2 xl:grid-cols-4">
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

function WhatsAppSettings({ salon, plan, query }: { salon: Salon; plan: Plan; query: string }) {
  const t = useT();
  const { auth } = usePage<Props>().props;
  const conversations = filterWhatsappConversations(salon.conversations, query);
  const stats = websiteChatStats(conversations);
  const [integration, setIntegration] = useState<WhatsappIntegration | null>(salon.whatsapp_integration ?? null);
  const [requestedNumber, setRequestedNumber] = useState(integration?.requested_number ?? salon.business_phone ?? '');
  const [localSubmittedWhatsappNumber, setLocalSubmittedWhatsappNumber] = useState(integration?.requested_number || (integration?.status === 'active' ? integration?.display_number : '') || '');
  const [setupForm, setSetupForm] = useState<WhatsappSetupRequestForm>({
    business_name: salon.name ?? '',
    contact_person: auth.user?.name ?? '',
    contact_email: auth.user?.email ?? salon.notification_email ?? '',
    contact_phone: salon.business_phone ?? '',
    requested_whatsapp_number: integration?.requested_number ?? integration?.display_number ?? salon.business_phone ?? '',
    whatsapp_display_name: salon.name ?? '',
    website_or_social_link: salon.website ?? '',
    has_meta_business_account: '',
    number_currently_used_on_whatsapp_app: '',
    can_receive_sms_or_call: '',
    preferred_meeting_type: 'video_call',
    preferred_availability: '',
    notes: '',
  });
  const [setupErrors, setSetupErrors] = useState<Record<string, string>>({});
  const [availabilityDate, setAvailabilityDate] = useState('');
  const [availabilityDates, setAvailabilityDates] = useState<string[]>([]);
  const [availabilityPeriods, setAvailabilityPeriods] = useState<WhatsappAvailabilityPeriod[]>([]);
  const [aiEnabled, setAiEnabled] = useState(Boolean(integration?.ai_enabled));
  const [testTo, setTestTo] = useState('');
  const [testMessage, setTestMessage] = useState(t('whatsappDefaultTestMessage'));
  const [busy, setBusy] = useState<'request' | 'toggle' | 'test' | 'setup' | null>(null);
  const [notice, setNotice] = useState('');
  const [setupNotice, setSetupNotice] = useState('');
  const [error, setError] = useState('');
  const hasWhatsappPlan = planHasService(plan, 'whatsapp_ai');
  const status = integration?.status ?? 'not_connected';
  const active = status === 'active';
  const activationRequested = status === 'requested';
  const submittedWhatsappNumber = integration?.requested_number || (active ? integration?.display_number : '') || localSubmittedWhatsappNumber;
  const canSubmitActivationRequest = !active && !activationRequested && !submittedWhatsappNumber;
  const showSetupOnboarding = hasWhatsappPlan && Boolean(submittedWhatsappNumber);
  const setupChecklistKeys = [
    'whatsappSetupChecklistMetaAccount',
    'whatsappSetupChecklistPhoneAccess',
    'whatsappSetupChecklistDisplayName',
    'whatsappSetupChecklistWebsiteSocial',
    'whatsappSetupChecklistCurrentApp',
    'whatsappSetupChecklistApiIntegration',
  ];
  const yesNoNotSureOptions = [
    { value: 'yes', label: t('yes') },
    { value: 'no', label: t('no') },
    { value: 'not_sure', label: t('notSure') },
  ];
  const yesNoOptions = [
    { value: 'yes', label: t('yes') },
    { value: 'no', label: t('no') },
  ];
  const meetingTypeOptions = [
    { value: 'video_call', label: t('whatsappSetupVideoCall') },
    { value: 'phone_call', label: t('whatsappSetupPhoneCall') },
  ];
  const availabilityPeriodOptions: Array<{ value: WhatsappAvailabilityPeriod; label: string }> = [
    { value: 'morning', label: t('whatsappAvailabilityMorning') },
    { value: 'afternoon', label: t('whatsappAvailabilityAfternoon') },
    { value: 'evening', label: t('whatsappAvailabilityEvening') },
  ];

  useEffect(() => {
    setIntegration(salon.whatsapp_integration ?? null);
    setAiEnabled(Boolean(salon.whatsapp_integration?.ai_enabled));
    setRequestedNumber(salon.whatsapp_integration?.requested_number ?? salon.business_phone ?? '');
    if (salon.whatsapp_integration?.requested_number || salon.whatsapp_integration?.display_number) {
      setLocalSubmittedWhatsappNumber(salon.whatsapp_integration.requested_number || salon.whatsapp_integration.display_number || '');
    }
    setSetupForm((current) => ({
      ...current,
      business_name: current.business_name || salon.name || '',
      contact_person: current.contact_person || auth.user?.name || '',
      contact_email: current.contact_email || auth.user?.email || salon.notification_email || '',
      contact_phone: current.contact_phone || salon.business_phone || '',
      requested_whatsapp_number: salon.whatsapp_integration?.requested_number || salon.whatsapp_integration?.display_number || current.requested_whatsapp_number || salon.business_phone || '',
      whatsapp_display_name: current.whatsapp_display_name || salon.name || '',
      website_or_social_link: current.website_or_social_link || salon.website || '',
    }));
  }, [salon.whatsapp_integration, salon.business_phone, salon.name, salon.notification_email, salon.website, auth.user]);

  function updateSetupForm<K extends keyof WhatsappSetupRequestForm>(key: K, value: WhatsappSetupRequestForm[K]) {
    setSetupForm((current) => ({ ...current, [key]: value }));
    setSetupErrors((current) => {
      const next = { ...current };
      delete next[key];
      return next;
    });
  }

  function preferredAvailabilityValue(dates: string[], periods: WhatsappAvailabilityPeriod[]) {
    const dateText = dates.length > 0 ? dates.join(', ') : '';
    const periodText = periods.map((period) => availabilityPeriodOptions.find((option) => option.value === period)?.label ?? period).join(', ');

    return [dateText ? `${t('whatsappAvailabilityDates')}: ${dateText}` : '', periodText ? `${t('whatsappAvailabilityPeriods')}: ${periodText}` : '']
      .filter(Boolean)
      .join(' | ');
  }

  function setPreferredAvailability(dates: string[], periods: WhatsappAvailabilityPeriod[]) {
    updateSetupForm('preferred_availability', preferredAvailabilityValue(dates, periods));
  }

  function addAvailabilityDate() {
    if (!availabilityDate || availabilityDates.includes(availabilityDate)) {
      return;
    }

    const nextDates = [...availabilityDates, availabilityDate].sort();
    setAvailabilityDates(nextDates);
    setAvailabilityDate('');
    setPreferredAvailability(nextDates, availabilityPeriods);
  }

  function removeAvailabilityDate(date: string) {
    const nextDates = availabilityDates.filter((value) => value !== date);
    setAvailabilityDates(nextDates);
    setPreferredAvailability(nextDates, availabilityPeriods);
  }

  function toggleAvailabilityPeriod(period: WhatsappAvailabilityPeriod, checked: boolean) {
    const nextPeriods = checked
      ? Array.from(new Set([...availabilityPeriods, period]))
      : availabilityPeriods.filter((value) => value !== period);

    setAvailabilityPeriods(nextPeriods);
    setPreferredAvailability(availabilityDates, nextPeriods);
  }

  async function requestActivation() {
    setBusy('request');
    setNotice('');
    setSetupNotice('');
    setError('');

    try {
      const nextRequestedNumber = requestedNumber.trim();
      if (!nextRequestedNumber) {
        throw new Error(t('whatsappActivationRequestFailed'));
      }

      setLocalSubmittedWhatsappNumber(nextRequestedNumber);
      setSetupForm((current) => ({
        ...current,
        requested_whatsapp_number: nextRequestedNumber,
      }));
      setNotice(t('whatsappActivationRequestedMessage'));
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : t('whatsappActivationRequestFailed'));
    } finally {
      setBusy(null);
    }
  }

  async function toggleAi(next: boolean) {
    setBusy('toggle');
    setNotice('');
    setSetupNotice('');
    setError('');

    try {
      const response = await fetch('/dashboard/whatsapp/toggle', {
        method: 'PATCH',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          ...csrfHeaders(),
        },
        body: JSON.stringify({ ai_enabled: next }),
      });
      const data = await response.json().catch(() => ({}));

      if (!response.ok) {
        throw new Error(jsonErrorMessage(data, t('whatsappToggleFailed')));
      }

      setIntegration(data.integration ?? integration);
      setAiEnabled(Boolean(data.integration?.ai_enabled));
    } catch (caught) {
      setAiEnabled(Boolean(integration?.ai_enabled));
      setError(caught instanceof Error ? caught.message : t('whatsappToggleFailed'));
    } finally {
      setBusy(null);
    }
  }

  async function sendTestMessage() {
    setBusy('test');
    setNotice('');
    setSetupNotice('');
    setError('');

    try {
      const response = await fetch('/dashboard/whatsapp/test-message', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          ...csrfHeaders(),
        },
        body: JSON.stringify({ to: testTo, message: testMessage }),
      });
      const data = await response.json().catch(() => ({}));

      if (!response.ok) {
        throw new Error(jsonErrorMessage(data, t('whatsappTestMessageFailed')));
      }

      setNotice(t('whatsappTestMessageSent'));
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : t('whatsappTestMessageFailed'));
    } finally {
      setBusy(null);
    }
  }

  async function submitSetupRequest() {
    setBusy('setup');
    setNotice('');
    setSetupNotice('');
    setError('');
    setSetupErrors({});
    const submitDates = availabilityDate && !availabilityDates.includes(availabilityDate)
      ? [...availabilityDates, availabilityDate].sort()
      : availabilityDates;
    const submitAvailability = preferredAvailabilityValue(submitDates, availabilityPeriods);
    const setupPayload = {
      ...setupForm,
      preferred_availability: submitAvailability,
    };

    try {
      const response = await fetch('/dashboard/whatsapp/setup-request', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          ...csrfHeaders(),
        },
        body: JSON.stringify(setupPayload),
      });
      const data = await response.json().catch(() => ({}));

      if (!response.ok) {
        const payload = data as { errors?: Record<string, string[]> };
        setSetupErrors(Object.fromEntries(Object.entries(payload.errors ?? {}).map(([field, messages]) => [field, messages[0] ?? t('whatsappSetupRequestFailed')])));
        throw new Error(jsonErrorMessage(data, t('whatsappSetupRequestFailed')));
      }

      setSetupNotice(t('whatsappSetupRequestSent'));
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : t('whatsappSetupRequestFailed'));
    } finally {
      setBusy(null);
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex justify-end">
        <SecondaryButton
          type="button"
          disabled={conversations.length === 0}
          onClick={() => exportConversationsCsv(conversations, 'whatsapp-conversations', salon.timezone)}
        >
          <Download className="h-4 w-4" />
          {t('exportCsv')}
        </SecondaryButton>
      </div>

      <div className="grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3">
        <ChannelStat icon={MessageSquare} value={stats.total} label={t('totalChat')} tone="blue" />
        <ChannelStat icon={CheckCircle2} value={stats.completed} label={t('completedChats')} tone="green" />
        <ChannelStat icon={XCircle} value={stats.abandoned} label={t('abandonedChats')} tone="slate" />
      </div>

      <Card className="p-6">
        <div className="flex flex-col gap-5 md:flex-row md:items-start md:justify-between">
          <div className="flex items-start gap-4">
            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-400/15 dark:text-emerald-300">
              <SiWhatsapp className="h-5 w-5" />
            </span>
            <div className="min-w-0">
              <h2 className="text-lg font-bold app-text">{t('whatsappSettingsTitle')}</h2>
              <p className="mt-1 max-w-2xl text-sm app-text-muted">{t('whatsappSettingsSubtitle')}</p>
            </div>
          </div>

          <WhatsappActivationBadge plan={plan} integration={integration} />
        </div>
      </Card>

      {!hasWhatsappPlan ? (
        <Card className="p-6">
          <div className="flex items-start gap-3">
            <Lock className="mt-0.5 h-5 w-5 text-amber-600" />
            <div>
              <h3 className="text-base font-bold app-text">{t('upgradeRequired')}</h3>
              <p className="mt-1 text-sm app-text-muted">{t('whatsappRequiresUpgrade')}</p>
              <Link href="/dashboard/billing" className="mt-4 inline-flex h-10 items-center justify-center rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">
                {t('billing')}
              </Link>
            </div>
          </div>
        </Card>
      ) : (
        <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
          <Card className="p-6">
            <div className="space-y-5">
              {(notice || error) && (
                <div className={`rounded-lg border px-4 py-3 text-sm ${error ? 'border-red-200 bg-red-50 text-red-700' : 'border-green-200 bg-green-50 text-green-700'}`}>
                  {error || notice}
                </div>
              )}

              <div>
                <h3 className="text-base font-bold app-text">{t('whatsappStepOneTitle')}</h3>
                <p className="mt-1 text-sm app-text-muted">{t('whatsappStepOneHelp')}</p>
              </div>

              <Field label={t('whatsappBusinessNumber')}>
                <Input
                  value={requestedNumber}
                  onChange={(event) => setRequestedNumber(event.target.value)}
                  placeholder="+407xxxxxxxx"
                  disabled={!canSubmitActivationRequest}
                />
              </Field>

              <div className="flex flex-wrap items-center gap-3">
                <Button type="button" onClick={requestActivation} disabled={busy !== null || !canSubmitActivationRequest || requestedNumber.trim() === ''}>
                  <Smartphone className="h-4 w-4" />
                  {busy === 'request' ? t('saving') : t('requestWhatsappActivation')}
                </Button>
                {!showSetupOnboarding && !active && (
                  <p className="max-w-xl text-sm app-text-muted">{t('whatsappSetupUnderReview')}</p>
                )}
              </div>

              {showSetupOnboarding && (
                <div className="space-y-5 rounded-lg border p-4 app-border app-panel-soft">
                  <div>
                    <h3 className="text-base font-bold app-text">{t('whatsappSetupNextTitle')}</h3>
                    <p className="mt-2 text-sm leading-6 app-text-muted">{t('whatsappSetupNextCopy')}</p>
                  </div>

                  <div>
                    <h4 className="text-sm font-bold app-text">{t('whatsappSetupChecklistTitle')}</h4>
                    <ul className="mt-3 space-y-2 text-sm app-text-muted">
                      {setupChecklistKeys.map((key) => (
                        <li key={key} className="flex gap-2">
                          <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />
                          <span>{t(key)}</span>
                        </li>
                      ))}
                    </ul>
                  </div>

                  <p className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm leading-6 text-emerald-900">
                    {t('whatsappSetupCallExplanation')}
                  </p>

                  <div className="space-y-4">
                    <h4 className="text-base font-bold app-text">{t('whatsappSetupRequestTitle')}</h4>
                    <div className="rounded-lg border px-4 py-3 text-sm app-border app-panel">
                      <span className="font-semibold app-text">{t('whatsappSetupRequestedNumber')}: </span>
                      <span className="app-text-muted">{setupForm.requested_whatsapp_number || submittedWhatsappNumber}</span>
                    </div>
                    <div className="grid gap-4 md:grid-cols-2">
                      <Field label={t('whatsappSetupBusinessName')} error={setupErrors.business_name}>
                        <Input value={setupForm.business_name} onChange={(event) => updateSetupForm('business_name', event.target.value)} />
                      </Field>
                      <Field label={t('whatsappSetupContactPerson')} error={setupErrors.contact_person}>
                        <Input value={setupForm.contact_person} onChange={(event) => updateSetupForm('contact_person', event.target.value)} />
                      </Field>
                      <Field label={t('whatsappSetupContactEmail')} error={setupErrors.contact_email}>
                        <Input type="email" value={setupForm.contact_email} onChange={(event) => updateSetupForm('contact_email', event.target.value)} />
                      </Field>
                      <Field label={t('whatsappSetupContactPhone')} error={setupErrors.contact_phone}>
                        <Input value={setupForm.contact_phone} onChange={(event) => updateSetupForm('contact_phone', event.target.value)} />
                      </Field>
                      <Field label={t('whatsappSetupDisplayName')} error={setupErrors.whatsapp_display_name}>
                        <Input value={setupForm.whatsapp_display_name} onChange={(event) => updateSetupForm('whatsapp_display_name', event.target.value)} />
                      </Field>
                      <Field label={t('whatsappSetupWebsiteSocial')} error={setupErrors.website_or_social_link}>
                        <Input value={setupForm.website_or_social_link} onChange={(event) => updateSetupForm('website_or_social_link', event.target.value)} />
                      </Field>
                      <Field label={t('whatsappSetupMetaAccount')} error={setupErrors.has_meta_business_account}>
                        <div className="flex flex-wrap gap-3">
                          {yesNoNotSureOptions.map((option) => (
                            <label key={option.value} className="inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm app-border app-panel">
                              <input
                                type="checkbox"
                                className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                checked={setupForm.has_meta_business_account === option.value}
                                onChange={(event) => updateSetupForm('has_meta_business_account', event.target.checked ? option.value as WhatsappSetupRequestForm['has_meta_business_account'] : '')}
                              />
                              <span className="app-text-soft">{option.label}</span>
                            </label>
                          ))}
                        </div>
                      </Field>
                      <Field label={t('whatsappSetupNumberInApp')} error={setupErrors.number_currently_used_on_whatsapp_app}>
                        <div className="flex flex-wrap gap-3">
                          {yesNoNotSureOptions.map((option) => (
                            <label key={option.value} className="inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm app-border app-panel">
                              <input
                                type="checkbox"
                                className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                checked={setupForm.number_currently_used_on_whatsapp_app === option.value}
                                onChange={(event) => updateSetupForm('number_currently_used_on_whatsapp_app', event.target.checked ? option.value as WhatsappSetupRequestForm['number_currently_used_on_whatsapp_app'] : '')}
                              />
                              <span className="app-text-soft">{option.label}</span>
                            </label>
                          ))}
                        </div>
                      </Field>
                      <Field label={t('whatsappSetupCanReceiveSmsCall')} error={setupErrors.can_receive_sms_or_call}>
                        <div className="flex flex-wrap gap-3">
                          {yesNoOptions.map((option) => (
                            <label key={option.value} className="inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm app-border app-panel">
                              <input
                                type="checkbox"
                                className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                checked={setupForm.can_receive_sms_or_call === option.value}
                                onChange={(event) => updateSetupForm('can_receive_sms_or_call', event.target.checked ? option.value as WhatsappSetupRequestForm['can_receive_sms_or_call'] : '')}
                              />
                              <span className="app-text-soft">{option.label}</span>
                            </label>
                          ))}
                        </div>
                      </Field>
                      <Field label={t('whatsappSetupMeetingType')} error={setupErrors.preferred_meeting_type}>
                        <div className="flex flex-wrap gap-3">
                          {meetingTypeOptions.map((option) => (
                            <label key={option.value} className="inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm app-border app-panel">
                              <input
                                type="checkbox"
                                className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                checked={setupForm.preferred_meeting_type === option.value}
                                onChange={(event) => updateSetupForm('preferred_meeting_type', event.target.checked ? option.value as WhatsappSetupRequestForm['preferred_meeting_type'] : '')}
                              />
                              <span className="app-text-soft">{option.label}</span>
                            </label>
                          ))}
                        </div>
                      </Field>
                    </div>
                    <div className="space-y-3">
                      <Field label={t('whatsappSetupAvailabilityDates')} error={setupErrors.preferred_availability}>
                        <div className="flex flex-col gap-3 sm:flex-row">
                          <Input type="date" value={availabilityDate} onChange={(event) => setAvailabilityDate(event.target.value)} />
                          <SecondaryButton type="button" onClick={addAvailabilityDate} disabled={!availabilityDate}>
                            <Plus className="h-4 w-4" />
                            {t('whatsappSetupAddDate')}
                          </SecondaryButton>
                        </div>
                      </Field>
                      {availabilityDates.length > 0 && (
                        <div className="flex flex-wrap gap-2">
                          {availabilityDates.map((date) => (
                            <span key={date} className="inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm app-border app-panel">
                              <span className="app-text-soft">{date}</span>
                              <button type="button" className="text-slate-500 hover:text-red-600" onClick={() => removeAvailabilityDate(date)} aria-label={t('remove')}>
                                <X className="h-3.5 w-3.5" />
                              </button>
                            </span>
                          ))}
                        </div>
                      )}
                      <Field label={t('whatsappSetupAvailabilityPeriods')}>
                        <div className="flex flex-wrap gap-3">
                          {availabilityPeriodOptions.map((option) => (
                            <label key={option.value} className="inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm app-border app-panel">
                              <input
                                type="checkbox"
                                className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                checked={availabilityPeriods.includes(option.value)}
                                onChange={(event) => toggleAvailabilityPeriod(option.value, event.target.checked)}
                              />
                              <span className="app-text-soft">{option.label}</span>
                            </label>
                          ))}
                        </div>
                      </Field>
                    </div>
                    <Field label={t('whatsappSetupNotes')} error={setupErrors.notes}>
                      <textarea className="min-h-24 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm app-panel app-text" value={setupForm.notes} onChange={(event) => updateSetupForm('notes', event.target.value)} />
                    </Field>
                    <Button type="button" onClick={submitSetupRequest} disabled={busy !== null}>
                      <Calendar className="h-4 w-4" />
                      {busy === 'setup' ? t('saving') : t('whatsappSetupSubmit')}
                    </Button>
                    {setupNotice && (
                      <p className="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
                        {setupNotice}
                      </p>
                    )}
                  </div>
                </div>
              )}

              <div className="rounded-lg border p-4 app-border app-panel-soft">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                  <div>
                    <h3 className="text-sm font-bold app-text">{t('whatsappAiEnabled')}</h3>
                    <p className="mt-1 text-sm app-text-muted">{t('whatsappAiEnabledDescription')}</p>
                  </div>
                  <label className="inline-flex cursor-pointer items-center gap-3">
                    <input
                      type="checkbox"
                      className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                      checked={aiEnabled}
                      disabled={!active || busy !== null}
                      onChange={(event) => {
                        setAiEnabled(event.target.checked);
                        void toggleAi(event.target.checked);
                      }}
                    />
                    <span className="text-sm font-semibold app-text-soft">{active ? t('enabled') : t('locked')}</span>
                  </label>
                </div>
              </div>

              {active && (
                <div className="space-y-4 rounded-lg border p-4 app-border">
                  <div>
                    <h3 className="text-sm font-bold app-text">{t('whatsappTestMessage')}</h3>
                    <p className="mt-1 text-sm app-text-muted">{t('whatsappManualActivationHelp')}</p>
                  </div>
                  <div className="grid gap-4 md:grid-cols-2">
                    <Field label={t('whatsappRecipientNumber')}>
                      <Input value={testTo} onChange={(event) => setTestTo(event.target.value)} placeholder="+407xxxxxxxx" />
                    </Field>
                    <Field label={t('whatsappTestMessageBody')}>
                      <Input value={testMessage} onChange={(event) => setTestMessage(event.target.value)} />
                    </Field>
                  </div>
                  <Button type="button" onClick={sendTestMessage} disabled={busy !== null || testTo.trim() === '' || testMessage.trim() === ''}>
                    <MessageCircle className="h-4 w-4" />
                    {busy === 'test' ? t('saving') : t('sendWhatsappTestMessage')}
                  </Button>
                </div>
              )}
            </div>
          </Card>

          <Card className="p-6">
            <h3 className="text-sm font-bold app-text">{t('details')}</h3>
            <div className="mt-4 space-y-3">
              <Detail icon={MessageCircle} label={t('status')} value={whatsappActivationStateLabel(plan, integration, t)} />
              <Detail icon={Phone} label={t('whatsappBusinessNumber')} value={integration?.display_number || integration?.requested_number || t('notConfigured')} />
            </div>
          </Card>
        </div>
      )}
    </div>
  );
}

function WhatsappActivationBadge({ plan, integration }: { plan: Plan; integration?: WhatsappIntegration | null }) {
  const t = useT();
  const tones: Record<string, 'slate' | 'amber' | 'green' | 'red'> = {
    upgrade: 'amber',
    planned: 'amber',
    active: 'green',
    neutral: 'slate',
    error: 'red',
  };
  const tone = whatsappActivationStateTone(plan, integration);

  return <Badge tone={tones[tone] ?? 'slate'}>{whatsappActivationStateLabel(plan, integration, t)}</Badge>;
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
    <Card className={compact ? 'p-3' : 'p-3 sm:p-5'}>
      <div className={`flex items-center ${compact ? 'gap-3' : 'gap-3 sm:gap-4'}`}>
        <span className={`flex shrink-0 items-center justify-center rounded-full ${compact ? 'h-8 w-8' : 'h-8 w-8 sm:h-10 sm:w-10'} ${tones[tone]}`}>
          <Icon className={compact ? 'h-4 w-4' : 'h-4 w-4 sm:h-5 sm:w-5'} />
        </span>
        <div className="min-w-0">
          <p className={`${compact ? 'text-xl' : 'text-xl sm:text-2xl'} font-bold app-text`}>{value}</p>
          <p className={`${compact ? 'text-xs' : 'text-xs leading-4 sm:text-sm'} app-text-muted`}>{label}</p>
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

function EmptyState({ title, description }: { title: string; description: string }) {
  return (
    <div className="rounded-2xl border p-6 app-border app-panel">
      <p className="text-sm font-bold app-text">{title}</p>
      <p className="mt-2 text-sm app-text-muted">{description}</p>
    </div>
  );
}

function Customers({ crm, query }: { crm?: CustomerCrmPayload | null; query: string }) {
  const t = useT();
  const serverSearch = crm?.filters.search ?? '';
  const items = crm?.items ?? [];

  useEffect(() => {
    if (query.trim() === serverSearch) return;

    const timeout = setTimeout(() => {
      router.get('/dashboard/customers', { search: query.trim() }, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
      });
    }, 350);

    return () => clearTimeout(timeout);
  }, [query, serverSearch]);

  if (!crm) {
    return <EmptyState title={t('customers')} description={t('customersEmpty')} />;
  }

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-2 xl:grid-cols-4">
        <Stat label={t('totalCustomers')} value={crm.summary.total_customers} icon={Users} tone="blue" />
        <Stat label={t('customersWithPhone')} value={crm.summary.with_phone} icon={Phone} tone="green" />
        <Stat label={t('customersWithEmail')} value={crm.summary.with_email} icon={Bell} tone="purple" />
        <Stat label={t('newCustomersThisMonth')} value={crm.summary.new_this_month} icon={Sparkles} tone="slate" />
      </div>

      {items.length === 0 ? (
        <EmptyState title={t('noCustomersFound')} description={t('customersEmpty')} />
      ) : (
        <DashboardTable
          headers={[t('customer'), t('contact'), t('bookings'), t('lastBooking'), t('lastInteraction'), '']}
          minWidth="980px"
        >
          {items.map((customer, index) => (
            <tr key={customer.id} className={dashboardTableRowClass(index)}>
              <td className="px-5 py-4">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-semibold app-text">{customer.name || t('unnamedCustomer')}</span>
                  <span className={`rounded-md px-2 py-0.5 text-[10px] font-semibold uppercase ${customer.phone || customer.email ? 'bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300'}`}>
                    {customer.phone || customer.email ? t('contactComplete') : t('incompleteContact')}
                  </span>
                </div>
                <div className="mt-1 text-xs app-text-muted">{t('firstSeen')}: {customer.first_seen_at ? formatDate(customer.first_seen_at) : 'N/A'}</div>
              </td>
              <td className="px-5 py-4 text-sm app-text-soft">
                <div className={customer.phone ? 'app-text' : 'text-amber-600'}>{customer.phone || t('phoneMissing')}</div>
                <div className={`mt-1 text-xs ${customer.email ? 'app-text-muted' : 'text-amber-600'}`}>{customer.email || t('emailMissing')}</div>
              </td>
              <td className="px-5 py-4">
                <div className="text-sm font-semibold app-text">{customer.bookings_count} {t('bookings')}</div>
                <div className="mt-1 text-xs app-text-muted">
                  {customer.upcoming_bookings_count} {t('upcoming')}, {customer.completed_bookings_count} {t('completed')}, {customer.cancelled_bookings_count} {t('cancelled')}
                </div>
              </td>
              <td className="px-5 py-4 text-sm app-text-soft">
                {customer.last_booking ? (
                  <>
                    <div className="font-semibold app-text">{customer.last_booking.date} {customer.last_booking.time}</div>
                    <div className="mt-1 text-xs app-text-muted">{customer.last_booking.service_name || t('service')} · {customer.last_booking.status}</div>
                  </>
                ) : t('noBookings')}
              </td>
              <td className="px-5 py-4 text-sm app-text-soft">{customer.last_seen_at ? formatDate(customer.last_seen_at) : 'N/A'}</td>
              <td className="px-5 py-4 text-right">
                <Link href={`/dashboard/customers/${customer.id}`} className="inline-flex h-9 items-center justify-center rounded-lg bg-indigo-600 px-3 text-sm font-semibold text-white hover:bg-indigo-700">
                  {t('viewCustomer')}
                </Link>
              </td>
            </tr>
          ))}
        </DashboardTable>
      )}

      {crm.pagination.last_page > 1 && (
        <div className="flex items-center justify-between text-sm app-text-muted">
          <span>{t('page')} {crm.pagination.current_page} / {crm.pagination.last_page}</span>
          <div className="flex gap-2">
            {crm.pagination.prev_page_url && <Link href={crm.pagination.prev_page_url} className="rounded-lg border px-3 py-2 font-semibold app-border app-panel app-text-soft">{t('previous')}</Link>}
            {crm.pagination.next_page_url && <Link href={crm.pagination.next_page_url} className="rounded-lg border px-3 py-2 font-semibold app-border app-panel app-text-soft">{t('next')}</Link>}
          </div>
        </div>
      )}
    </div>
  );
}

function CustomerDetail({ crm, salon }: { crm?: CustomerDetailPayload | null; salon: Salon }) {
  const t = useT();
  const notesForm = useForm({
    notes: crm?.customer.notes ?? '',
  });

  useEffect(() => {
    notesForm.setData('notes', crm?.customer.notes ?? '');
  }, [crm?.customer.id, crm?.customer.notes]);

  if (!crm) {
    return <EmptyState title={t('customerDetail')} description={t('customerNotFound')} />;
  }

  const hasContact = Boolean(crm.customer.phone || crm.customer.email);
  const submitNotes = (event: FormEvent) => {
    event.preventDefault();
    notesForm.patch(`/dashboard/customers/${crm.customer.id}/notes`, {
      preserveScroll: true,
    });
  };

  return (
    <div className="space-y-6">
      <Link href="/dashboard/customers" className="inline-flex items-center gap-2 text-sm font-semibold text-indigo-600 hover:text-indigo-700">
        <ChevronLeft className="h-4 w-4" />
        {t('backToCustomers')}
      </Link>

      <section className="rounded-2xl border p-5 app-border app-panel">
        <div className="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
          <div>
            <p className="text-xs font-semibold uppercase tracking-wide app-text-muted">{t('customerProfile')}</p>
            <h2 className="mt-2 text-2xl font-bold app-text">{crm.customer.name || t('unnamedCustomer')}</h2>
            <div className="mt-3 flex flex-wrap items-center gap-2 text-sm app-text-soft">
              <span className={`rounded-lg border px-3 py-1.5 app-border app-panel-soft ${crm.customer.phone ? '' : 'text-amber-600'}`}>{crm.customer.phone || t('phoneMissing')}</span>
              <span className={`rounded-lg border px-3 py-1.5 app-border app-panel-soft ${crm.customer.email ? '' : 'text-amber-600'}`}>{crm.customer.email || t('emailMissing')}</span>
              <span className={`rounded-lg px-3 py-1.5 text-xs font-semibold uppercase ${hasContact ? 'bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300'}`}>
                {hasContact ? t('contactComplete') : t('incompleteContact')}
              </span>
            </div>
          </div>
          <div className="grid gap-2 text-sm app-text-soft sm:grid-cols-2 lg:min-w-96">
            <InfoLine label={t('firstSeen')} value={crm.customer.first_seen_at ? formatDate(crm.customer.first_seen_at) : 'N/A'} />
            <InfoLine label={t('lastInteraction')} value={crm.stats.last_interaction ? formatDate(crm.stats.last_interaction) : 'N/A'} />
            <InfoLine label={t('preferredService')} value={crm.preferences.service || t('notEnoughData')} />
            <InfoLine label={t('preferredStaff')} value={crm.preferences.staff || t('notEnoughData')} />
          </div>
        </div>
      </section>

      <div className="grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-2 xl:grid-cols-5">
        <Stat label={t('totalBookings')} value={crm.stats.total_bookings} icon={Calendar} tone="blue" />
        <Stat label={t('upcomingBookings')} value={crm.stats.upcoming_bookings} icon={Clock} tone="green" />
        <Stat label={t('completedBookings')} value={crm.stats.completed_bookings} icon={CheckCircle2} tone="slate" />
        <Stat label={t('cancelledBookings')} value={crm.stats.cancelled_bookings} icon={XCircle} tone="red" />
        <Stat label={t('conversations')} value={crm.stats.conversations} icon={MessageSquare} tone="purple" />
      </div>

      <section className="rounded-2xl border p-5 app-border app-panel">
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
          <div>
            <h3 className="text-base font-bold app-text">{t('operationalHighlights')}</h3>
            <p className="mt-1 text-sm app-text-muted">{t('customerDetailSubtitle')}</p>
          </div>
          <span className="rounded-lg bg-indigo-50 px-3 py-1.5 text-xs font-semibold uppercase text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300">{t('internalOnly')}</span>
        </div>
        <div className="grid gap-3 lg:grid-cols-4">
          <CustomerBookingMini title={t('nextUpcomingBooking')} booking={crm.highlights.next_upcoming_booking} empty={t('noUpcomingBooking')} salon={salon} />
          <CustomerBookingMini title={t('lastBooking')} booking={crm.highlights.last_booking} empty={t('noBookings')} salon={salon} />
          <InfoLine label={t('preferredService')} value={crm.preferences.service || t('notEnoughData')} />
          <InfoLine label={t('preferredStaff')} value={crm.preferences.staff || t('notEnoughData')} />
        </div>
      </section>

      <section className="rounded-2xl border p-5 app-border app-panel">
        <form onSubmit={submitNotes} className="space-y-4">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h3 className="text-base font-bold app-text">{t('customerNotes')}</h3>
              <p className="mt-1 text-sm app-text-muted">{t('internalOnly')} · {crm.customer.updated_at ? `${t('notesLastUpdated')}: ${formatDate(crm.customer.updated_at)}` : t('customerNotesPlaceholder')}</p>
            </div>
            {notesForm.recentlySuccessful && <span className="rounded-lg bg-green-100 px-3 py-1.5 text-xs font-semibold text-green-700 dark:bg-green-500/15 dark:text-green-300">{t('customerNotesSaved')}</span>}
          </div>
          <textarea
            value={notesForm.data.notes}
            onChange={(event) => notesForm.setData('notes', event.target.value)}
            maxLength={5000}
            rows={5}
            className="min-h-32 w-full resize-y rounded-xl border px-4 py-3 text-sm app-border app-panel-soft app-text focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
            placeholder={t('customerNotesPlaceholder')}
          />
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div className="text-xs app-text-muted">
              {notesForm.data.notes.length}/5000
              {notesForm.errors.notes && <span className="ml-3 font-semibold text-red-500">{notesForm.errors.notes}</span>}
            </div>
            <button type="submit" disabled={notesForm.processing} className="inline-flex h-10 items-center gap-2 rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60">
              <Save className="h-4 w-4" />
              {notesForm.processing ? t('savingNotes') : t('saveNotes')}
            </button>
          </div>
        </form>
      </section>

      <section className="space-y-3">
        <h3 className="text-base font-bold app-text">{t('bookingHistory')}</h3>
        {crm.bookings.length === 0 ? (
          <EmptyState title={t('noBookings')} description={t('customerNoBookings')} />
        ) : (
          <DashboardTable headers={[t('date'), t('client'), t('service'), t('staffMember'), t('status'), t('bookingSource')]} minWidth="920px">
            {crm.bookings.map((booking, index) => (
              <tr key={booking.id} className={dashboardTableRowClass(index)}>
                <td className="px-5 py-4 text-sm font-semibold app-text">{booking.date ? formatBusinessDate(booking.date, salon) : 'N/A'} {booking.time}</td>
                <td className="px-5 py-4 text-sm app-text-soft">{booking.client_name}<div className="text-xs app-text-muted">{booking.client_phone}</div></td>
                <td className="px-5 py-4 text-sm app-text-soft">{booking.service?.name || t('service')}</td>
                <td className="px-5 py-4 text-sm app-text-soft">{booking.staff_name || booking.staff_member?.name || 'N/A'}</td>
                <td className="px-5 py-4"><BookingStatusCell booking={booking} t={t} /></td>
                <td className="px-5 py-4 text-sm app-text-soft">{booking.source || 'manual'}</td>
              </tr>
            ))}
          </DashboardTable>
        )}
      </section>

      <section className="space-y-3">
        <h3 className="text-base font-bold app-text">{t('recentConversations')}</h3>
        {crm.conversations.length === 0 ? (
          <EmptyState title={t('noConversations')} description={t('customerNoConversations')} />
        ) : (
          <div className="grid gap-3">
            {crm.conversations.map((conversation) => (
              <div key={conversation.id} className="rounded-2xl border p-4 app-border app-panel">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <div>
                    <p className="text-sm font-semibold app-text">{conversation.channel} · {conversation.intent}</p>
                    <p className="mt-1 text-xs app-text-muted">{t('channel')}: {conversation.channel} · {t('intent')}: {conversation.intent}</p>
                    <p className="mt-1 text-xs app-text-muted">{conversation.last_message_at ? formatDate(conversation.last_message_at) : 'N/A'}</p>
                  </div>
                  <span className="rounded-lg border px-2 py-1 text-xs font-semibold app-border app-panel-soft app-text-soft">{conversation.status}</span>
                </div>
                <p className="mt-3 text-sm app-text-soft">{localizeStoredConversationSummary(conversation.summary, t) || t('noSummary')}</p>
              </div>
            ))}
          </div>
        )}
      </section>
    </div>
  );
}

function CustomerBookingMini({ title, booking, empty, salon }: { title: string; booking?: CustomerBookingSummary | null; empty: string; salon: Pick<Salon, 'date_format'> }) {
  return (
    <div className="rounded-xl border p-4 app-border app-panel-soft">
      <p className="text-xs font-semibold uppercase tracking-wide app-text-muted">{title}</p>
      {booking ? (
        <>
          <p className="mt-2 text-sm font-semibold app-text">{booking.date ? formatBusinessDate(booking.date, salon) : 'N/A'} {booking.time}</p>
          <p className="mt-1 text-xs app-text-muted">{booking.service?.name || 'Service'} · {booking.status}</p>
        </>
      ) : (
        <p className="mt-2 text-sm app-text-muted">{empty}</p>
      )}
    </div>
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
      <div className="flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:justify-between">
        <button
          type="button"
          onClick={startAddBooking}
          className="inline-flex h-10 w-full items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 text-sm font-bold text-white transition hover:bg-blue-700 sm:w-auto"
        >
          <Plus className="h-4 w-4" />
          {t('newBooking')}
        </button>
        <div className="grid w-full grid-cols-3 rounded-lg border p-1 app-panel sm:inline-flex sm:w-auto">
          <button
            type="button"
            onClick={() => setView('archive')}
            className={`inline-flex h-9 items-center justify-center gap-2 rounded-md px-3 text-sm font-bold transition ${view === 'archive' ? 'bg-indigo-600 text-white shadow-sm' : 'app-text-muted hover:bg-[var(--app-panel-soft)]'}`}
          >
            <Clock className="h-4 w-4" />
            {t('archive')}
          </button>
          <button
            type="button"
            onClick={() => setView('list')}
            className={`inline-flex h-9 items-center justify-center gap-2 rounded-md px-3 text-sm font-bold transition ${view === 'list' ? 'bg-indigo-600 text-white shadow-sm' : 'app-text-muted hover:bg-[var(--app-panel-soft)]'}`}
          >
            <List className="h-4 w-4" />
            {t('listView')}
          </button>
          <button
            type="button"
            onClick={() => setView('calendar')}
            className={`inline-flex h-9 items-center justify-center gap-2 rounded-md px-3 text-sm font-bold transition ${view === 'calendar' ? 'bg-indigo-600 text-white shadow-sm' : 'app-text-muted hover:bg-[var(--app-panel-soft)]'}`}
          >
            <Calendar className="h-4 w-4" />
            {t('calendarView')}
          </button>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-2 xl:grid-cols-4">
        <BookingStat icon={Calendar} value={stats.today} label={t('today')} tone="blue" />
        <BookingStat icon={Calendar} value={stats.upcoming} label={t('upcoming')} tone="green" />
        <BookingStat icon={Calendar} value={stats.pending} label={t('pendingRequests')} tone="purple" />
        <BookingStat icon={Calendar} value={stats.cancelled} label={t('cancelled')} tone="red" />
      </div>

      {view === 'archive' && (
        <div className="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900 dark:border-amber-400/40 dark:bg-amber-500/15 dark:text-amber-100">
          {t('bookingArchiveReadOnly')}
        </div>
      )}

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
          isArchive={view === 'archive'}
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
    <Card className="p-3 sm:p-5">
      <div className="flex items-center gap-3 sm:gap-4">
        <span className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full sm:h-10 sm:w-10 ${tones[tone]}`}>
          <Icon className="h-4 w-4 sm:h-5 sm:w-5" />
        </span>
        <div className="min-w-0">
          <p className="text-xl font-bold app-text sm:text-2xl">{value}</p>
          <p className="text-xs leading-4 app-text-muted sm:text-sm">{label}</p>
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
  if (format === 'dd month yyyy') {
    const locale = preferredLocale() === 'en' ? 'en-GB' : 'ro-RO';
    return new Intl.DateTimeFormat(locale, {
      day: 'numeric',
      month: 'long',
      year: 'numeric',
    }).format(new Date(`${date}T00:00:00`));
  }

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
  isArchive,
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
  isArchive: boolean;
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
      <>
        <Card className="p-5 text-center text-sm app-text-muted md:hidden">
          {t('noBookingsFound')}
        </Card>
        <div className="hidden md:block">
          <DashboardTable headers={tableHeaders} minWidth="1040px">
            <tr>
              <td colSpan={7} className="px-5 py-8 text-center text-sm app-text-muted">
                {t('noBookingsFound')}
              </td>
            </tr>
          </DashboardTable>
        </div>
      </>
    );
  }

  let rowIndex = 0;

  return (
    <>
      <div className="space-y-4 md:hidden">
        {groups.map((group) => (
          <section key={group.date} className="space-y-2">
            <h3 className="px-1 text-xs font-bold uppercase tracking-wide app-text-muted">
              {formatBookingGroupDate(group.date, t, salon)}
            </h3>
            <div className="space-y-2">
              {group.bookings.map((booking) => (
                <MobileBookingCard
                  key={booking.id}
                  booking={booking}
                  salon={salon}
                  t={t}
                  isArchive={isArchive}
                  onConfirm={onConfirm}
                  onCancel={onCancel}
                  onEdit={onEdit}
                  onDelete={onDelete}
                />
              ))}
            </div>
          </section>
        ))}
      </div>
      <div className="hidden md:block">
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
                  <BookingStatusCell booking={booking} t={t} />
                </td>
                <td className="px-5 py-4 align-top font-semibold app-text">{booking.client_name}</td>
                <td className="whitespace-nowrap px-5 py-4 align-top app-text-soft">{booking.client_phone || t('phoneMissingShort')}</td>
                <td className="min-w-72 px-5 py-4 align-top app-text-soft">
                  <BookingDetailsCell booking={booking} salon={salon} t={t} />
                </td>
                <td className="w-14 px-5 py-4 align-top">
                  <BookingActionsMenu booking={booking} t={t} isArchive={isArchive} onConfirm={onConfirm} onCancel={onCancel} onEdit={onEdit} onDelete={onDelete} />
                </td>
              </tr>
            );
          }))}
        </DashboardTable>
      </div>
    </>
  );
}

function MobileBookingCard({
  booking,
  salon,
  t,
  isArchive,
  onConfirm,
  onCancel,
  onEdit,
  onDelete,
}: {
  booking: Salon['bookings'][number];
  salon: Salon;
  t: TranslateFn;
  isArchive: boolean;
  onConfirm: (booking: Salon['bookings'][number]) => void;
  onCancel: (booking: Salon['bookings'][number]) => void;
  onEdit: (booking: Salon['bookings'][number]) => void;
  onDelete: (booking: Salon['bookings'][number]) => void;
}) {
  return (
    <Card className="p-4">
      <div className="flex min-w-0 items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-sm font-bold app-text">{booking.client_name}</p>
          <p className="mt-1 text-xs font-semibold app-text-muted">{bookingTimeRange(booking.time, booking.service?.duration)}</p>
        </div>
        <div className="flex shrink-0 items-start gap-2">
          <BookingStatusCell booking={booking} t={t} />
          <BookingActionsMenu booking={booking} t={t} isArchive={isArchive} onConfirm={onConfirm} onCancel={onCancel} onEdit={onEdit} onDelete={onDelete} />
        </div>
      </div>
      <div className="mt-4 grid gap-3 text-sm">
        <Detail icon={Phone} label={t('phone')} value={booking.client_phone || t('phoneMissingShort')} />
        <div className="rounded-lg border p-3 app-border app-panel-soft">
          <p className="mb-2 text-xs font-bold uppercase tracking-wide app-text-muted">{t('details')}</p>
          <div className="text-sm app-text-soft">
            <BookingDetailsCell booking={booking} salon={salon} t={t} />
          </div>
        </div>
      </div>
    </Card>
  );
}

function BookingActionsMenu({
  booking,
  t,
  isArchive,
  onConfirm,
  onCancel,
  onEdit,
  onDelete,
}: {
  booking: Salon['bookings'][number];
  t: TranslateFn;
  isArchive: boolean;
  onConfirm: (booking: Salon['bookings'][number]) => void;
  onCancel: (booking: Salon['bookings'][number]) => void;
  onEdit: (booking: Salon['bookings'][number]) => void;
  onDelete: (booking: Salon['bookings'][number]) => void;
}) {
  return (
    <RowActionsMenu label={t('actions')}>
      {(close) => (
        <>
          {bookingAllowsDashboardActions(booking, isArchive) && booking.status === 'pending' && (
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
          {bookingAllowsDashboardActions(booking, isArchive) && (
            <RowActionButton onClick={() => { close(); onCancel(booking); }}>
              <XCircle className="h-4 w-4 text-red-600" />
              {t('cancelBooking')}
            </RowActionButton>
          )}
          {bookingAllowsDashboardActions(booking, isArchive) && (
            <RowActionButton onClick={() => { close(); onEdit(booking); }}>
              <Pencil className="h-4 w-4" />
              {t('editBooking')}
            </RowActionButton>
          )}
          <RowActionButton tone="danger" onClick={() => { close(); onDelete(booking); }}>
            <Trash2 className="h-4 w-4" />
            {t('deleteBooking')}
          </RowActionButton>
        </>
      )}
    </RowActionsMenu>
  );
}

function BookingStatusCell({ booking, t }: { booking: Pick<Booking, 'status'>; t: TranslateFn }) {
  return (
    <div className="space-y-1.5">
      <StatusPill status={booking.status} t={t} />
    </div>
  );
}

function bookingAllowsDashboardActions(booking: Salon['bookings'][number], isArchive: boolean) {
  return !isArchive && (booking.status === 'pending' || booking.status === 'confirmed');
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
