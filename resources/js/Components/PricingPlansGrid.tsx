import { Link } from '@inertiajs/react';
import { Check, MessageCircle, Phone } from 'lucide-react';
import { SiWhatsapp } from 'react-icons/si';
import { OfferedService, Plan } from '@/types';
import { servicesForPlan, serviceIsPlanned } from '@/lib/yougoServices';

export type BillingCycle = 'monthly' | 'annual';
export type PublicPlanKey = 'free' | 'website_chat' | 'chat_whatsapp';
export type VoicePlanKey = 'voice_starter' | 'voice_growth' | 'voice_pro';
type TranslateFn = (key: string, params?: Record<string, string | number>) => string;
type FeatureItem = { key: string; label: string; subtitle?: string; planned?: boolean; icon?: any };

export function PricingPlansGrid({
  plans,
  billingCycle,
  selectedVoicePlan,
  onSelectedVoicePlanChange,
  t,
  showCtas = true,
  authUser = false,
  currentPlanKey,
  services,
}: {
  plans: Plan[];
  services: OfferedService[];
  billingCycle: BillingCycle;
  selectedVoicePlan: VoicePlanKey;
  onSelectedVoicePlanChange: (plan: VoicePlanKey) => void;
  t: TranslateFn;
  showCtas?: boolean;
  authUser?: boolean;
  currentPlanKey?: string;
}) {
  const cardKeys: PublicPlanKey[] = ['free', 'website_chat', 'chat_whatsapp'];
  const voiceKeys: VoicePlanKey[] = ['voice_starter', 'voice_growth', 'voice_pro'];
  const selectedVoice = planByKey(plans, selectedVoicePlan);

  return (
    <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
      {cardKeys.map((key) => {
        const plan = planByKey(plans, key);

        return (
          <PricingCard
            key={key}
            plan={plan}
            fallbackName={t(`planName_${key}`)}
            subtitle={t(`pricingSubtitle_${key}`)}
            highlights={pricingHighlights(plan, services, t)}
            usage={usageLines(plan, t)}
            ctaLabel={pricingCtaLabel(key, t)}
            href={pricingHref(authUser)}
            billingCycle={billingCycle}
            t={t}
            showCta={showCtas}
            current={plan?.key === currentPlanKey}
          />
        );
      })}

      <article className="flex min-h-full flex-col rounded-xl border border-indigo-300 p-5 shadow-sm app-panel dark:border-indigo-500/60">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div className="flex flex-wrap items-center gap-2">
              <h3 className="text-xl font-bold app-text">{t('pricingVoiceName')}</h3>
              <span className="rounded-md bg-indigo-50 px-2 py-1 text-[11px] font-bold uppercase tracking-wide text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-200">{t('completeReceptionist')}</span>
              {selectedVoice?.key === currentPlanKey && <CurrentPlanBadge t={t} />}
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
                  onClick={() => onSelectedVoicePlanChange(key)}
                  className={`h-9 rounded-md text-xs font-bold transition ${selectedVoicePlan === key ? 'bg-indigo-600 text-white shadow-sm' : 'app-text-soft hover:bg-[var(--soft)]'}`}
                >
                  {voiceTabLabel(key, t)}
                </button>
              ))}
            </div>
            <FeatureList items={pricingHighlights(selectedVoice, services, t)} t={t} />
            {showCtas && (
              <Link href={pricingHref(authUser)} className="mt-auto inline-flex h-11 items-center justify-center rounded-lg bg-indigo-600 px-4 text-sm font-bold text-white shadow-sm hover:bg-indigo-700">
                {voiceCtaLabel(selectedVoicePlan, t)}
              </Link>
            )}
          </>
        ) : (
          <MissingPlanFallback t={t} />
        )}
      </article>
    </div>
  );
}

function PricingCard({ plan, fallbackName, subtitle, highlights, usage, ctaLabel, href, billingCycle, t, showCta, current }: { plan?: Plan; fallbackName: string; subtitle: string; highlights: string[]; usage: string[]; ctaLabel: string; href: string; billingCycle: BillingCycle; t: TranslateFn; showCta: boolean; current?: boolean }) {
  return (
    <article className="flex min-h-full flex-col rounded-xl border p-5 shadow-sm app-panel app-border">
      <div className="flex flex-wrap items-center gap-2">
        <h3 className="text-xl font-bold app-text">{plan ? planDisplayLabel(plan, t) : fallbackName}</h3>
        {current && <CurrentPlanBadge t={t} />}
      </div>
      <p className="mt-3 min-h-[3rem] whitespace-pre-line text-sm leading-6 app-text-soft">{subtitle}</p>
      {plan ? (
        <>
          <PriceBlock plan={plan} billingCycle={billingCycle} t={t} />
          <UsageList items={usage} />
          <FeatureList items={highlights} t={t} />
          {showCta && (
            <Link href={href} className="mt-auto inline-flex h-11 items-center justify-center rounded-lg bg-indigo-600 px-4 text-sm font-bold text-white shadow-sm hover:bg-indigo-700">
              {ctaLabel}
            </Link>
          )}
        </>
      ) : (
        <MissingPlanFallback t={t} />
      )}
    </article>
  );
}

function CurrentPlanBadge({ t }: { t: TranslateFn }) {
  return (
    <span className="rounded-md bg-green-50 px-2 py-1 text-[11px] font-bold uppercase tracking-wide text-green-700 dark:bg-green-500/15 dark:text-green-300">
      {t('currentPlan')}
    </span>
  );
}

function UsageList({ items }: { items: string[] }) {
  return (
    <div className="mb-5 grid gap-1 rounded-lg border px-3 py-2 text-xs font-semibold leading-5 app-border app-text-muted">
      {items.map((item) => <span key={item}>{item}</span>)}
    </div>
  );
}

function FeatureList({ items, t }: { items: FeatureItem[]; t: TranslateFn }) {
  return (
    <ul className="mt-5 mb-5 grid gap-3 text-sm font-medium app-text-soft">
      {items.map((item) => {
        const Icon = item.icon ?? Check;

        return (
          <li key={item.key} className="flex items-start gap-2">
            <Icon className="mt-0.5 h-4 w-4 shrink-0 text-green-600" />
            <span className="min-w-0">
              {item.label}
              {item.planned && <span className="ml-2 inline-flex rounded-md bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase text-slate-700 dark:bg-slate-700 dark:text-slate-100">{t('integrationImplementationPlanned')}</span>}
              {item.subtitle && <span className="mt-0.5 block text-xs leading-5 app-text-muted">{item.subtitle}</span>}
            </span>
          </li>
        );
      })}
    </ul>
  );
}

function PriceBlock({ plan, billingCycle, t }: { plan: Plan; billingCycle: BillingCycle; t: TranslateFn }) {
  const monthly = monthlyPrice(plan);

  return (
    <div className="mt-6 mb-4">
      <p className="text-3xl font-bold app-text">{priceLabel(plan, billingCycle)}</p>
      {billingCycle === 'annual' && monthly !== null && (
        <p className="mt-1 text-xs font-bold text-red-600">-{annualDiscountPercent()}% - {t('billedAnnually')}</p>
      )}
    </div>
  );
}

function VoiceUsage({ plan, t }: { plan: Plan; t: TranslateFn }) {
  const rows = [
    plan.phone_minutes_label && plan.phone_minutes_label.toLowerCase().includes('min') ? `${plan.phone_minutes_label} ${t('phoneMinutes').toLowerCase()}` : null,
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

function MissingPlanFallback({ t }: { t: TranslateFn }) {
  return <p className="mt-6 rounded-lg border px-3 py-3 text-sm app-border app-text-muted">{t('pricingPlanUnavailable')}</p>;
}

export function priceLabel(plan: Plan, billingCycle: BillingCycle) {
  const monthly = monthlyPrice(plan);

  if (billingCycle === 'monthly') {
    return plan.price_label;
  }

  if (monthly === null) {
    return plan.price_label.replace('/lună', '').replace('/lunÄƒ', '');
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

function planDisplayLabel(plan: Plan, t: TranslateFn) {
  return t(`planName_${plan.key}`) || plan.name;
}

function planByKey(plans: Plan[], key: string) {
  return plans.find((plan) => plan.key === key);
}

function pricingHref(authUser: boolean) {
  return authUser ? '/dashboard/billing' : '/register';
}

function pricingHighlights(plan: Plan | undefined, services: OfferedService[], t: TranslateFn): FeatureItem[] {
  if (! plan) return [{ key: 'missing-plan', label: t('pricingPlanUnavailable') }];

  return [
    ...planServiceItems(plan, services, t),
    { key: 'aiBookings', label: t('aiBookings') },
    { key: 'aiImportedServices', label: t('aiImportedServices') },
    { key: 'emailBookingNotifications', label: t('emailBookingNotifications') },
    { key: 'dashboardAccess', label: t('dashboardAccess') },
  ];
}

function planServiceItems(plan: Plan, services: OfferedService[], t: TranslateFn): FeatureItem[] {
  return servicesForPlan(services, plan)
    .map((service) => ({
      key: service.key,
      label: t(service.title_key),
      subtitle: t(service.subtitle_key),
      planned: serviceIsPlanned(service),
      icon: serviceIcon(service.icon),
    }));
}

function serviceIcon(icon: string) {
  if (icon === 'whatsapp') return SiWhatsapp;
  if (icon === 'phone') return Phone;

  return MessageCircle;
}

function usageLines(plan: Plan | undefined, t: TranslateFn) {
  if (! plan) return [t('pricingPlanUnavailable')];

  const parts = [
    `${formatLandingLimit(plan.monthly_conversations, t)} ${t('conversations')}`,
    `${formatLandingLimit(plan.monthly_bookings, t)} ${t('aiBookings')}`,
  ];

  if (plan.service_keys?.includes('whatsapp_ai')) {
    parts.push(`${formatLandingLimit(plan.monthly_whatsapp_messages ?? null, t)} ${t('whatsappMessages')}`);
  }

  return parts;
}

function pricingCtaLabel(key: PublicPlanKey, t: TranslateFn) {
  if (key === 'free') return t('startFree');
  if (key === 'chat_whatsapp') return t('chooseChatWhatsapp');
  return t('chooseWebsiteChat');
}

function voiceTabLabel(key: VoicePlanKey, t: TranslateFn) {
  return t(`voiceTab_${key}`);
}

function voiceCtaLabel(key: VoicePlanKey, t: TranslateFn) {
  return t(`choose_${key}`);
}

function formatLandingLimit(value: number | null, t: TranslateFn): string {
  if (value === null) return t('unlimited');

  return new Intl.NumberFormat('en-GB').format(value);
}
