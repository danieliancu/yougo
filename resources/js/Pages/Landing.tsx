import { Head, Link, usePage } from '@inertiajs/react';
import { Check, MessageCircle, Minus, Phone, Plug, Plus, Send } from 'lucide-react';
import { SiWhatsapp } from 'react-icons/si';
import { useEffect, useState } from 'react';
import { ChatShell } from '@/Components/ChatShell';
import { PublicFooter, PublicHeader, PublicLocale } from '@/Components/PublicChrome';
import { translate } from '@/i18n';
import { PageProps, Plan } from '@/types';

export default function Landing() {
  const { auth, plans = [] } = usePage<PageProps<{ plans: Plan[] }>>().props;

  const [locale, setLocale] = useState<PublicLocale>(() => {
    if (typeof window === 'undefined') return 'ro';
    return (localStorage.getItem('yougo-lang') as PublicLocale) ?? 'ro';
  });

  function switchLang(lang: PublicLocale) {
    setLocale(lang);
    localStorage.setItem('yougo-lang', lang);
  }

  const t = (key: string, params?: Record<string, string | number>) => translate(locale, key, params);

  return (
    <main className="min-h-screen app-bg landing-page-bg">
      <Head title={t('landingTitle')} />
      <div className="landing-hero-bg min-[1600px]:border-b min-[1600px]:border-slate-200 min-[1600px]:dark:border-white/10">
        <PublicHeader
          authUserName={auth.user?.name}
          locale={locale}
          onLanguageChange={switchLang}
          startLabel={t('start')}
          industriesLabel={t('industriesNav')}
          pricingLabel={t('pricing')}
        />

        <section>
          <div className="mx-auto grid max-w-6xl gap-10 px-6 py-16 lg:grid-cols-[1.05fr_0.95fr] lg:items-center">
            <div>
              <p className="mb-4 inline-flex rounded-md bg-indigo-50 px-3 py-1 text-xs font-bold uppercase tracking-wide text-indigo-700">{t('landingTitle')}</p>
              <h1 className="max-w-3xl text-5xl font-bold tracking-tight app-text md:text-6xl">{t('landingHeadline')}</h1>
              <p className="mt-6 max-w-2xl text-lg leading-8 app-text-soft">
                {t('landingCopy')}
              </p>
              <div className="mt-8 flex flex-wrap gap-3">
                <Link href={auth.user ? '/dashboard' : '/register'} className="rounded-lg bg-indigo-600 px-5 py-3 text-sm font-bold text-white shadow-sm hover:bg-indigo-700">{t('startFree')}</Link>
                <button type="button" className="rounded-lg border border-slate-950 bg-transparent px-5 py-3 text-sm font-bold text-slate-950 hover:bg-slate-950 hover:text-white dark:border-white dark:text-white dark:hover:bg-white dark:hover:text-slate-950">{t('howItWorks')}</button>
              </div>
            </div>

            <HeroChannelCarousel t={t} />
          </div>
        </section>
      </div>

      <section className="mx-auto max-w-6xl px-6 pb-20 mt-8">
        <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
          <div className="flex flex-col items-center rounded-2xl p-8 text-center">
            <MessageCircle className="mb-5 h-14 w-14 text-indigo-500" />
            <p className="text-lg font-bold app-text">{t('feature2Title')}</p>
            <p className="mt-2 text-sm app-text-soft">{t('feature2Desc')}</p>
          </div>
          <div className="flex flex-col items-center rounded-2xl p-8 text-center">
            <SiWhatsapp className="mb-5 h-14 w-14 text-indigo-500" />
            <p className="text-lg font-bold app-text">{t('feature3Title')}</p>
            <p className="mt-2 text-sm app-text-soft">{t('feature3Desc')}</p>
          </div>
          <div className="flex flex-col items-center rounded-2xl p-8 text-center">
            <Phone className="mb-5 h-14 w-14 text-indigo-500" />
            <p className="text-lg font-bold app-text">{t('feature1Title')}</p>
            <p className="mt-2 text-sm app-text-soft">{t('feature1Desc')}</p>
          </div>
          <div className="flex flex-col items-center rounded-2xl p-8 text-center">
            <Plug className="mb-5 h-14 w-14 text-indigo-500" />
            <p className="text-lg font-bold app-text">{t('feature4Title')}</p>
            <p className="mt-2 text-sm app-text-soft">{t('feature4Desc')}</p>
          </div>
        </div>
        <p className="mt-10 text-center text-sm app-text-soft">
          {t('featuresHelpText')}{' '}
          <a href="tel:08767657556" className="font-bold text-indigo-600 hover:underline">{t('featuresHelpCta')}</a>
        </p>
      </section>
      <FaqSection t={t} />
      <div id="pricing">
        <PricingSection plans={plans} t={t} authUser={Boolean(auth.user)} locale={locale} />
      </div>
      <PublicFooter t={t} />
    </main>
  );
}

function FaqSection({ t }: { t: (key: string, params?: Record<string, string | number>) => string }) {
  const [openItem, setOpenItem] = useState<string | null>(null);
  const items = [
    ['faqQuestion1', 'faqAnswer1'],
    ['faqQuestion2', 'faqAnswer2'],
    ['faqQuestion3', 'faqAnswer3'],
    ['faqQuestion4', 'faqAnswer4'],
    ['faqQuestion5', 'faqAnswer5'],
    ['faqQuestion6', 'faqAnswer6'],
    ['faqQuestion7', 'faqAnswer7'],
    ['faqQuestion8', 'faqAnswer8'],
  ];

  return (
    <section id="faq" className="mx-auto max-w-6xl px-6 pb-24">
      <div className="mb-8 max-w-2xl">
        <p className="text-xs font-semibold uppercase tracking-wide text-indigo-600">{t('faqEyebrow')}</p>
        <h2 className="mt-2 text-3xl font-bold app-text md:text-4xl">{t('faqTitle')}</h2>
        <p className="mt-4 text-sm app-text-muted">{t('faqSubtitle')}</p>
      </div>
      <div className="grid gap-x-10 gap-y-7 lg:grid-cols-2">
        {items.map(([question, answer]) => (
          <div key={question} className="border-t pt-5 app-border">
            <button
              type="button"
              onClick={() => setOpenItem((current) => current === question ? null : question)}
              className="flex w-full items-start justify-between gap-4 text-left"
              aria-expanded={openItem === question}
            >
              <span className="text-base font-bold app-text">{t(question)}</span>
              <span className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-md border app-panel app-text-soft">
                {openItem === question ? <Minus className="h-4 w-4" /> : <Plus className="h-4 w-4" />}
              </span>
            </button>
            {openItem === question && (
              <p className="mt-3 text-sm leading-6 app-text-soft">{t(answer)}</p>
            )}
          </div>
        ))}
      </div>
    </section>
  );
}

type BillingCycle = 'monthly' | 'annual';
type PublicPlanKey = 'free' | 'website_chat' | 'chat_whatsapp';
type VoicePlanKey = 'voice_starter' | 'voice_growth' | 'voice_pro';

function PricingSection({ plans, t, authUser }: { plans: Plan[]; t: (key: string, params?: Record<string, string | number>) => string; authUser: boolean; locale: PublicLocale }) {
  const [billingCycle, setBillingCycle] = useState<BillingCycle>('monthly');
  const [selectedVoicePlan, setSelectedVoicePlan] = useState<VoicePlanKey>('voice_starter');
  const cardKeys: PublicPlanKey[] = ['free', 'website_chat', 'chat_whatsapp'];
  const voiceKeys: VoicePlanKey[] = ['voice_starter', 'voice_growth', 'voice_pro'];
  const selectedVoice = planByKey(plans, selectedVoicePlan);

  return (
    <section className="mx-auto max-w-6xl px-6 pb-24">
      <div className="mb-8 flex flex-col gap-5 md:flex-row md:items-end md:justify-between">
        <div className="max-w-2xl">
          <p className="text-xs font-semibold uppercase tracking-wide text-indigo-600">{t('pricing')}</p>
          <h2 className="mt-2 text-3xl font-bold app-text md:text-4xl">{t('choosePlan')}</h2>
          <p className="mt-4 text-sm leading-6 app-text-muted">{t('pricingChannelCopy')}</p>
          <p className="mt-2 text-sm app-text-muted">{t('paymentsComingSoon')}</p>
        </div>
        <div className="inline-flex w-fit rounded-lg border p-1 app-border app-panel">
          {(['monthly', 'annual'] as BillingCycle[]).map((cycle) => (
            <button
              key={cycle}
              type="button"
              onClick={() => setBillingCycle(cycle)}
              className={`h-9 rounded-md px-4 text-sm font-semibold transition ${billingCycle === cycle ? 'bg-indigo-600 text-white' : 'app-text-soft hover:bg-[var(--soft)]'}`}
            >
              {t(cycle)}
            </button>
          ))}
        </div>
      </div>

      <div className="mb-6 rounded-lg border px-5 py-4 app-border app-panel-soft">
        <h3 className="text-base font-bold app-text">{t('pricingChannelTitle')}</h3>
        <p className="mt-1 text-sm leading-6 app-text-soft">{t('pricingChannelBody')}</p>
      </div>

      <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
        {cardKeys.map((key) => {
          const plan = planByKey(plans, key);

          return (
            <PricingCard
              key={key}
              plan={plan}
              fallbackName={t(`planName_${key}`)}
              subtitle={t(`pricingSubtitle_${key}`)}
              highlights={pricingHighlights(key, plan, t)}
              usage={usageLines(plan, t)}
              ctaLabel={pricingCtaLabel(key, t)}
              href={pricingHref(authUser)}
              billingCycle={billingCycle}
              t={t}
            />
          );
        })}

        <article className="flex min-h-full flex-col rounded-xl border border-indigo-300 p-5 shadow-sm app-panel dark:border-indigo-500/60">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <div className="flex flex-wrap items-center gap-2">
                <h3 className="text-xl font-bold app-text">{t('pricingVoiceName')}</h3>
                <span className="rounded-md bg-indigo-50 px-2 py-1 text-[11px] font-bold uppercase tracking-wide text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-200">{t('completeReceptionist')}</span>
              </div>
              <p className="mt-3 min-h-[3rem] whitespace-pre-line text-sm leading-6 app-text-soft">{t('pricingSubtitle_voice')}</p>
            </div>
          </div>

          {selectedVoice ? (
            <>
              <PriceBlock plan={selectedVoice} billingCycle={billingCycle} t={t} />
              <VoiceUsage plan={selectedVoice} t={t} />
              <div className="mb-5 grid grid-cols-3 gap-1 rounded-lg border p-1 app-border app-panel-soft">
                {voiceKeys.map((key) => (
                  <button
                    key={key}
                    type="button"
                    onClick={() => setSelectedVoicePlan(key)}
                    className={`h-9 rounded-md text-xs font-bold transition ${selectedVoicePlan === key ? 'bg-indigo-600 text-white shadow-sm' : 'app-text-soft hover:bg-[var(--soft)]'}`}
                  >
                    {voiceTabLabel(key, t)}
                  </button>
                ))}
              </div>
              <FeatureList items={[t('phoneAi'), t('websiteChat'), t('whatsapp'), t('aiBookings'), t('emailBookingNotifications'), t('dashboardAccess')]} />
              <Link href={pricingHref(authUser)} className="mt-auto inline-flex h-11 items-center justify-center rounded-lg bg-indigo-600 px-4 text-sm font-bold text-white shadow-sm hover:bg-indigo-700">
                {voiceCtaLabel(selectedVoicePlan, t)}
              </Link>
            </>
          ) : (
            <MissingPlanFallback t={t} />
          )}
        </article>
      </div>
    </section>
  );
}

function PricingCard({ plan, fallbackName, subtitle, highlights, usage, ctaLabel, href, billingCycle, t }: { plan?: Plan; fallbackName: string; subtitle: string; highlights: string[]; usage: string[]; ctaLabel: string; href: string; billingCycle: BillingCycle; t: (key: string, params?: Record<string, string | number>) => string }) {
  return (
    <article className="flex min-h-full flex-col rounded-xl border p-5 shadow-sm app-panel app-border">
      <h3 className="text-xl font-bold app-text">{plan ? planDisplayLabel(plan, t) : fallbackName}</h3>
      <p className="mt-3 min-h-[3rem] whitespace-pre-line text-sm leading-6 app-text-soft">{subtitle}</p>
      {plan ? (
        <>
          <PriceBlock plan={plan} billingCycle={billingCycle} t={t} />
          <UsageList items={usage} />
          <FeatureList items={highlights} />
          <Link href={href} className="mt-auto inline-flex h-11 items-center justify-center rounded-lg bg-indigo-600 px-4 text-sm font-bold text-white shadow-sm hover:bg-indigo-700">
            {ctaLabel}
          </Link>
        </>
      ) : (
        <MissingPlanFallback t={t} />
      )}
    </article>
  );
}

function UsageList({ items }: { items: string[] }) {
  return (
    <div className="mb-5 grid gap-1 rounded-lg border px-3 py-2 text-xs font-semibold leading-5 app-border app-text-muted">
      {items.map((item) => <span key={item}>{item}</span>)}
    </div>
  );
}

function FeatureList({ items }: { items: string[] }) {
  return (
    <ul className="mt-5 mb-5 grid gap-3 text-sm font-medium app-text-soft">
      {items.map((item) => (
        <li key={item} className="flex gap-2">
          <Check className="mt-0.5 h-4 w-4 shrink-0 text-green-600" />
          <span>{item}</span>
        </li>
      ))}
    </ul>
  );
}

function PriceBlock({ plan, billingCycle, t }: { plan: Plan; billingCycle: BillingCycle; t: (key: string, params?: Record<string, string | number>) => string }) {
  const monthly = monthlyPrice(plan);

  return (
    <div className="mt-6 mb-4">
      <p className="text-3xl font-bold app-text">{priceLabel(plan, billingCycle, t)}</p>
      {billingCycle === 'annual' && monthly !== null && (
        <p className="mt-1 text-xs font-bold text-red-600">-{annualDiscountPercent()}% - {t('billedAnnually')}</p>
      )}
    </div>
  );
}

function VoiceUsage({ plan, t }: { plan: Plan; t: (key: string, params?: Record<string, string | number>) => string }) {
  const rows = [
    plan.phone_minutes_label && plan.phone_minutes_label !== '—' ? `${plan.phone_minutes_label} ${t('phoneMinutes').toLowerCase()}` : null,
    `${formatLandingLimit(plan.monthly_conversations, t)} ${t('conversationsIncluded')}`,
    `${formatLandingLimit(plan.monthly_bookings, t)} ${t('bookingsIncluded')}`,
    `${formatLandingLimit(plan.monthly_whatsapp_messages ?? null, t)} ${t('whatsappMessagesIncluded')}`,
  ].filter(Boolean) as string[];

  return (
    <div className="mb-5 grid gap-2 rounded-lg border px-3 py-3 text-xs font-semibold leading-5 app-border app-text-muted">
      {rows.map((row) => <span key={row}>{row}</span>)}
    </div>
  );
}

function MissingPlanFallback({ t }: { t: (key: string, params?: Record<string, string | number>) => string }) {
  return <p className="mt-6 rounded-lg border px-3 py-3 text-sm app-border app-text-muted">{t('pricingPlanUnavailable')}</p>;
}

function priceLabel(plan: Plan, billingCycle: BillingCycle, t: (key: string, params?: Record<string, string | number>) => string) {
  const monthly = monthlyPrice(plan);

  if (billingCycle === 'monthly') {
    return plan.price_label;
  }

  if (monthly === null) {
    return plan.price_label.replace('/lună', '');
  }

  const value = billingCycle === 'annual' ? monthly * 10 : monthly;
  const amount = new Intl.NumberFormat('ro-RO').format(value);

  return `${amount} RON`;
}

function monthlyPrice(plan: Plan) {
  const match = plan.price_label.match(/^([\d.]+)\s+RON/);
  if (! match) return null;

  return Number(match[1].replaceAll('.', ''));
}

function annualDiscountPercent() {
  return Math.round(((12 - 10) / 12) * 100);
}

function planDisplayLabel(plan: Plan, t: (key: string, params?: Record<string, string | number>) => string) {
  return t(`planName_${plan.key}`) || plan.name;
}

function planByKey(plans: Plan[], key: string) {
  return plans.find((plan) => plan.key === key);
}

function pricingHref(authUser: boolean) {
  return authUser ? '/dashboard/billing' : '/register';
}

function pricingHighlights(key: PublicPlanKey, plan: Plan | undefined, t: (key: string, params?: Record<string, string | number>) => string) {
  if (key === 'free') {
    return [
      t('websiteChat'),
      `${formatLandingLimit(plan?.monthly_bookings ?? 10, t)} ${t('aiBookings')}`,
      `${formatLandingLimit(plan?.monthly_conversations ?? 50, t)} ${t('chatConversations')}`,
      t('dashboardAccess'),
    ];
  }

  if (key === 'chat_whatsapp') {
    return [t('websiteChat'), t('whatsapp'), t('aiBookings'), t('emailBookingNotifications'), t('dashboardAccess')];
  }

  return [t('websiteChat'), t('aiBookings'), t('availabilityChecks'), t('emailBookingNotifications'), t('dashboardAccess')];
}

function usageLines(plan: Plan | undefined, t: (key: string, params?: Record<string, string | number>) => string) {
  if (! plan) return [t('pricingPlanUnavailable')];

  const parts = [
    `${formatLandingLimit(plan.monthly_conversations, t)} ${t('conversations')}`,
    `${formatLandingLimit(plan.monthly_bookings, t)} ${t('aiBookings')}`,
  ];

  if (plan.whatsapp_enabled) {
    parts.push(`${formatLandingLimit(plan.monthly_whatsapp_messages ?? null, t)} ${t('whatsappMessages')}`);
  }

  return parts;
}

function pricingCtaLabel(key: PublicPlanKey, t: (key: string, params?: Record<string, string | number>) => string) {
  if (key === 'free') return t('startFree');
  if (key === 'chat_whatsapp') return t('chooseChatWhatsapp');
  return t('chooseWebsiteChat');
}

function voiceTabLabel(key: VoicePlanKey, t: (key: string, params?: Record<string, string | number>) => string) {
  return t(`voiceTab_${key}`);
}

function voiceCtaLabel(key: VoicePlanKey, t: (key: string, params?: Record<string, string | number>) => string) {
  return t(`choose_${key}`);
}

function formatLandingLimit(value: number | null, t: (key: string, params?: Record<string, string | number>) => string): string {
  if (value === null) return t('unlimited');
  return new Intl.NumberFormat('en-GB').format(value);
}

function HeroChannelCarousel({ t }: { t: (key: string, params?: Record<string, string | number>) => string }) {
  const [active, setActive] = useState(0);
  const slides = [
    { id: 'chat', label: t('chatVoice') },
    { id: 'whatsapp', label: t('carouselWhatsapp') },
    { id: 'receptionist', label: t('phone') },
  ];

  useEffect(() => {
    const timer = window.setInterval(() => {
      setActive((index) => (index + 1) % slides.length);
    }, 14000);

    return () => window.clearInterval(timer);
  }, [slides.length]);

  return (
    <div className="relative">
      <div className="min-h-[540px] sm:p-4">
        <div className="flex min-h-[500px] items-center justify-center">
          {active === 0 && <ChatLivePreview t={t} />}
          {active === 1 && <WhatsAppPreview t={t} />}
          {active === 2 && <ReceptionistPreview t={t} />}
        </div>

        <div className="mt-5 flex flex-wrap items-center justify-center gap-2">
          {slides.map((slide, index) => (
            <button
              key={slide.id}
              type="button"
              onClick={() => setActive(index)}
              className={`inline-flex h-9 items-center justify-center rounded-full px-4 text-sm font-semibold transition ${
                active === index
                  ? 'bg-blue-600 text-white shadow-sm'
                  : 'border border-slate-200 bg-white text-slate-600 hover:bg-slate-50 dark:border-white/10 dark:bg-white dark:text-slate-600'
              }`}
              aria-label={slide.label}
              aria-pressed={active === index}
            >
              {slide.label}
            </button>
          ))}
        </div>
      </div>
    </div>
  );
}

function ReceptionistPreview({ t }: { t: (key: string) => string }) {
  const [clientSpeaking, setClientSpeaking] = useState(false);

  useEffect(() => {
    const timer = window.setInterval(() => {
      setClientSpeaking((speaking) => !speaking);
    }, 3000);

    return () => window.clearInterval(timer);
  }, []);

  return (
    <div className="relative h-[500px] w-full max-w-[500px] overflow-hidden rounded-3xl border border-blue-300/20 bg-gradient-to-br from-blue-500/30 via-slate-800/80 to-blue-950/90 p-8 backdrop-blur-xl">
      <div className="absolute inset-0 bg-[radial-gradient(circle_at_60%_20%,rgba(147,197,253,0.35),transparent_35%),radial-gradient(circle_at_30%_80%,rgba(30,64,175,0.35),transparent_38%)]" />
      <div className="relative flex h-full flex-col items-center justify-center rounded-2xl border border-white/10 bg-slate-950/15 backdrop-blur-md">
        <div className="mb-8 flex h-20 w-20 items-center justify-center rounded-full bg-blue-600 text-white shadow-xl shadow-blue-500/30">
          <Phone className="h-10 w-10" />
        </div>
        <div className="voice-wave mb-7" aria-hidden="true">
          <span />
          <span />
          <span />
          <span />
          <span />
        </div>
        <p className="text-center text-base font-bold text-white">
          {t(clientSpeaking ? 'carouselClientSpeaking' : 'carouselReceptionistSpeaking')}
        </p>
        <p className="mt-2 text-sm font-medium text-slate-300">00:42</p>
      </div>
    </div>
  );
}

function ChatLivePreview({ t }: { t: (key: string) => string }) {
  return (
    <ChatShell
      title={t('carouselAssistantAi')}
      statusLabel="Online"
      className="border-white/15 bg-slate-950/70 backdrop-blur-xl"
      headerClassName="border-white/10 bg-gradient-to-r from-blue-500/25 to-blue-900/25"
      bodyClassName="min-h-0 flex-1 overflow-hidden p-4"
      footerClassName="border-t border-white/10 p-4"
      action={
        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-white/10 text-blue-200">
          <MessageCircle className="h-5 w-5" />
        </div>
      }
      footer={
        <div className="flex items-center gap-2 rounded-xl bg-white/10 px-3 py-2">
          <input disabled placeholder={t('carouselWriteMessage')} className="min-w-0 flex-1 bg-transparent text-sm font-medium text-white placeholder:text-slate-400 focus:outline-none" />
          <button type="button" className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-blue-600 text-white">
            <Send className="h-4 w-4" />
          </button>
        </div>
      }
    >
        <div className="landing-chat-script space-y-4">
          <div className="landing-msg landing-msg-1 max-w-[80%] rounded-xl rounded-tl-none bg-white/10 px-3 py-2 text-sm font-medium text-slate-100">{t('carouselChatAssistant1')}</div>
          <div className="landing-msg landing-msg-2 ml-auto max-w-[80%] rounded-xl rounded-tr-none bg-blue-600/60 px-3 py-2 text-sm font-medium text-white">{t('carouselChatClient1')}</div>
          <div className="landing-msg landing-msg-3 max-w-[82%] rounded-xl rounded-tl-none bg-white/10 px-3 py-2 text-sm font-medium text-slate-100">{t('carouselChatAssistant2')}</div>
          <div className="landing-msg landing-msg-4 ml-auto max-w-[80%] rounded-xl rounded-tr-none bg-blue-600/60 px-3 py-2 text-sm font-medium text-white">{t('carouselChatClient2')}</div>
          <div className="landing-msg landing-msg-5 max-w-[82%] rounded-xl rounded-tl-none bg-white/10 px-3 py-2 text-sm font-medium text-slate-100">{t('carouselChatAssistant3')}</div>
          <div className="landing-msg landing-msg-6 ml-auto max-w-[80%] rounded-xl rounded-tr-none bg-blue-600/60 px-3 py-2 text-sm font-medium text-white">{t('carouselChatClient3')}</div>
          <div className="landing-msg landing-msg-7 max-w-[82%] rounded-xl rounded-tl-none bg-white/10 px-3 py-2 text-sm font-medium text-slate-100">{t('carouselChatAssistant4')}</div>
        </div>
    </ChatShell>
  );
}

function WhatsAppPreview({ t }: { t: (key: string) => string }) {
  return (
    <div className="flex h-[500px] w-full max-w-[500px] flex-col overflow-hidden rounded-2xl border border-white/15 bg-[#f7efe5] backdrop-blur-xl">
      <div className="flex items-center gap-3 bg-[#202c33] p-3">
        <div className="flex h-11 w-11 items-center justify-center rounded-full bg-blue-600 text-white">
          <Send className="h-5 w-5" />
        </div>
        <div>
          <h4 className="text-sm font-bold text-white">{t('carouselYourBusiness')}</h4>
          <span className="text-xs font-medium text-slate-400">Online</span>
        </div>
      </div>
      <div className="flex min-h-0 flex-1 flex-col bg-[#f7efe5] bg-cover bg-center" style={{ backgroundImage: "url('/images/backgroundWhatsaap.JPG')" }}>
        <div className="min-h-0 flex-1 overflow-hidden p-4">
          <div className="landing-chat-script space-y-3">
            <div className="landing-msg landing-msg-1 max-w-[80%] rounded-lg rounded-tl-none bg-white px-3 py-2 text-sm font-medium text-slate-900 shadow-sm">{t('carouselWhatsappClient1')}</div>
            <div className="landing-msg landing-msg-2 ml-auto max-w-[82%] rounded-lg rounded-tr-none bg-[#005c4b] px-3 py-2 text-sm font-medium text-white">{t('carouselWhatsappAssistant1')}</div>
            <div className="landing-msg landing-msg-3 max-w-[80%] rounded-lg rounded-tl-none bg-white px-3 py-2 text-sm font-medium text-slate-900 shadow-sm">{t('carouselWhatsappClient2')}</div>
            <div className="landing-msg landing-msg-4 ml-auto max-w-[82%] rounded-lg rounded-tr-none bg-[#005c4b] px-3 py-2 text-sm font-medium text-white">{t('carouselWhatsappAssistant2')}</div>
            <div className="landing-msg landing-msg-5 max-w-[80%] rounded-lg rounded-tl-none bg-white px-3 py-2 text-sm font-medium text-slate-900 shadow-sm">{t('carouselWhatsappClient3')}</div>
            <div className="landing-msg landing-msg-6 ml-auto max-w-[82%] rounded-lg rounded-tr-none bg-[#005c4b] px-3 py-2 text-sm font-medium text-white">{t('carouselWhatsappAssistant3')}</div>
            <div className="landing-msg landing-msg-7 max-w-[80%] rounded-lg rounded-tl-none bg-white px-3 py-2 text-sm font-medium text-slate-900 shadow-sm">{t('carouselWhatsappClient4')}</div>
            <div className="landing-msg landing-msg-8 ml-auto max-w-[82%] rounded-lg rounded-tr-none bg-[#005c4b] px-3 py-2 text-sm font-medium text-white">{t('carouselWhatsappAssistant4')}</div>
          </div>
        </div>
        <div className="flex items-center gap-2 bg-[#202c33] p-3">
          <input
            disabled
            placeholder={t('carouselWhatsappInput')}
            className="min-w-0 flex-1 rounded-md bg-[#2a3942] px-4 py-2 text-sm font-medium text-white placeholder:text-slate-400 focus:outline-none"
          />
          <button type="button" className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#00a884] text-white">
            <Send className="h-4 w-4" />
          </button>
        </div>
      </div>
    </div>
  );
}
