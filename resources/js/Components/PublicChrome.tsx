import { Link } from '@inertiajs/react';
import { ChevronDown, Lock, Menu, X } from 'lucide-react';
import { useState } from 'react';
import { ThemeToggle } from '@/Components/Ui';
import { businessTaxonomy } from '@/data/businessTaxonomy';

export type PublicLocale = 'ro' | 'en';

const languages = [
  { id: 'ro' as PublicLocale, label: 'RO', flag: '\u{1F1F7}\u{1F1F4}' },
  { id: 'en' as PublicLocale, label: 'EN', flag: '\u{1F1EC}\u{1F1E7}' },
];

type PublicHeaderProps = {
  authUserName?: string;
  locale: PublicLocale;
  onLanguageChange: (locale: PublicLocale) => void;
  startLabel: string;
  industriesLabel: string;
  pricingLabel: string;
};

export function PublicHeader({ authUserName, locale, onLanguageChange, startLabel, industriesLabel, pricingLabel }: PublicHeaderProps) {
  return (
    <nav className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-6 py-5">
      <Link href="/" className="flex items-center">
        <img src="/images/logo-white.png" className="h-12 w-auto dark:hidden" alt="YouGo" />
        <img src="/images/logo-dark.png" className="hidden h-12 w-auto dark:block" alt="YouGo" />
      </Link>
      <div className="hidden items-center gap-3 md:flex">
        <ThemeToggle />
        <IndustriesMenu label={industriesLabel} locale={locale} />
        <Link href="/#pricing" className="flex h-10 items-center rounded-lg px-3 text-sm font-bold app-text-soft hover:bg-[var(--soft)]">
          {pricingLabel}
        </Link>
        <LandingLanguageToggle locale={locale} onChange={onLanguageChange} />
        <PublicCta authUserName={authUserName} startLabel={startLabel} />
      </div>
      <div className="flex items-center gap-2 md:hidden">
        <ThemeToggle />
        <MobileLandingMenu
          locale={locale}
          onLanguageChange={onLanguageChange}
          industriesLabel={industriesLabel}
          pricingLabel={pricingLabel}
        />
      </div>
      <PublicCta
        authUserName={authUserName}
        startLabel={startLabel}
        className="flex h-10 basis-full items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 text-sm font-bold text-white sm:basis-auto md:hidden"
      />
    </nav>
  );
}

function PublicCta({ authUserName, startLabel, className = 'flex items-center gap-2 rounded-lg bg-slate-950 px-4 py-2 text-sm font-bold text-white dark:border dark:border-white' }: { authUserName?: string; startLabel: string; className?: string }) {
  return (
    <Link href={authUserName ? '/dashboard' : '/register'} className={className}>
      {authUserName && <Lock className="h-4 w-4 shrink-0" />}
      {authUserName ?? startLabel}
    </Link>
  );
}

function LandingLanguageToggle({ locale, onChange }: { locale: PublicLocale; onChange: (l: PublicLocale) => void }) {
  const [open, setOpen] = useState(false);
  const active = languages.find((l) => l.id === locale) ?? languages[0];

  return (
    <div className="relative">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className="flex h-10 min-w-20 items-center justify-center gap-2 rounded-lg border px-3 text-xs font-bold uppercase app-text-soft hover:bg-[var(--soft)]"
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
              onClick={() => { setOpen(false); onChange(item.id); }}
              className={`flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm font-bold transition ${locale === item.id ? 'bg-indigo-600 text-white' : 'app-text-soft hover:bg-[var(--soft)]'}`}
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

function industryMenuLabel(slug: string, locale: PublicLocale): string {
  if (locale !== 'ro') {
    return businessTaxonomy.find((group) => group.slug === slug)?.label ?? slug;
  }

  return {
    'salon-beauty': 'Salon / Beauty',
    'clinic-healthcare': 'Clinica / Sanatate',
    'auto-service': 'Service auto',
    'professional-services': 'Servicii profesionale',
    restaurant: 'Restaurant',
    'hotel-accommodation': 'Hotel / Cazare',
    rental: 'Inchirieri',
    'real-estate': 'Imobiliare',
    other: 'Altele',
  }[slug] ?? slug;
}

function IndustriesMenu({ label, locale }: { label: string; locale: PublicLocale }) {
  const [open, setOpen] = useState(false);

  return (
    <div className="relative">
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        onMouseEnter={() => setOpen(true)}
        className="flex h-10 items-center gap-2 rounded-lg px-3 text-sm font-bold app-text-soft hover:bg-[var(--soft)]"
      >
        {label}
        <ChevronDown className="h-4 w-4" />
      </button>
      {open && (
        <div onMouseLeave={() => setOpen(false)} className="absolute right-0 top-12 z-50 hidden max-h-[70vh] w-72 overflow-y-auto rounded-2xl border p-3 shadow-2xl app-panel md:block">
          <div className="grid gap-1">
            {businessTaxonomy.map((group) => (
              <Link
                key={group.slug}
                href={`/industries/${group.slug}`}
                className="rounded-lg px-3 py-2 text-sm font-bold app-text-soft hover:bg-[var(--soft)] hover:text-indigo-600"
              >
                {industryMenuLabel(group.slug, locale)}
              </Link>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

function MobileLandingMenu({
  locale,
  onLanguageChange,
  industriesLabel,
  pricingLabel,
}: {
  locale: PublicLocale;
  onLanguageChange: (l: PublicLocale) => void;
  industriesLabel: string;
  pricingLabel: string;
}) {
  const [open, setOpen] = useState(false);

  return (
    <div className="relative">
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        className="flex h-10 w-10 items-center justify-center rounded-lg border app-text-soft hover:bg-[var(--soft)]"
        aria-label="Menu"
        aria-expanded={open}
      >
        {open ? <X className="h-5 w-5" /> : <Menu className="h-5 w-5" />}
      </button>
      {open && (
        <div className="absolute right-0 top-12 z-50 max-h-[75vh] w-[calc(100vw-2rem)] overflow-y-auto rounded-2xl border p-4 shadow-2xl app-panel">
          <div className="mb-4 flex gap-2">
            {languages.map((item) => (
              <button
                key={item.id}
                type="button"
                onClick={() => onLanguageChange(item.id)}
                className={`flex h-10 flex-1 items-center justify-center gap-2 rounded-lg border text-xs font-bold uppercase ${locale === item.id ? 'border-indigo-600 bg-indigo-600 text-white' : 'app-text-soft hover:bg-[var(--soft)]'}`}
              >
                <span aria-hidden="true">{item.flag}</span>
                {item.label}
              </button>
            ))}
          </div>
          <Link href="/#pricing" className="mb-4 block px-1 pb-2 text-xs font-bold uppercase tracking-wide text-indigo-600">
            {pricingLabel}
          </Link>
          <p className="px-1 pb-2 text-xs font-bold uppercase tracking-wide text-indigo-600">{industriesLabel}</p>
          <div className="grid gap-1">
            {businessTaxonomy.map((group) => (
              <Link
                key={group.slug}
                href={`/industries/${group.slug}`}
                className="rounded-lg px-3 py-2 text-sm font-bold app-text-soft hover:bg-[var(--soft)]"
              >
                {industryMenuLabel(group.slug, locale)}
              </Link>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

export function PublicFooter({ t }: { t: (key: string) => string }) {
  const serviceLinks = businessTaxonomy.slice(0, 6);

  return (
    <footer className="border-t app-border">
      <div className="mx-auto grid max-w-6xl gap-8 px-6 py-10 md:grid-cols-[1.2fr_0.7fr_0.8fr_0.8fr]">
        <div>
          <Link href="/" className="inline-flex items-center">
            <img src="/images/logo-white.png" className="h-11 w-auto dark:hidden" alt="YouGo" />
            <img src="/images/logo-dark.png" className="hidden h-11 w-auto dark:block" alt="YouGo" />
          </Link>
          <p className="mt-4 max-w-md text-sm leading-6 app-text-soft">{t('footerDescription')}</p>
        </div>
        <div>
          <h3 className="text-sm font-bold app-text">{t('footerProduct')}</h3>
          <div className="mt-3 grid gap-2 text-sm font-medium app-text-soft">
            <Link href="/" className="hover:text-indigo-600">{t('footerHome')}</Link>
            <Link href="/#pricing" className="hover:text-indigo-600">{t('pricing')}</Link>
            <Link href="/register" className="hover:text-indigo-600">{t('start')}</Link>
          </div>
        </div>
        <div>
          <h3 className="text-sm font-bold app-text">{t('footerServices')}</h3>
          <div className="mt-3 grid gap-2 text-sm font-medium app-text-soft">
            {serviceLinks.map((service) => (
              <Link key={service.slug} href={`/industries/${service.slug}`} className="hover:text-indigo-600">
                {service.label}
              </Link>
            ))}
          </div>
        </div>
        <div>
          <h3 className="text-sm font-bold app-text">{t('footerStatus')}</h3>
          <p className="mt-3 text-sm leading-6 app-text-soft">{t('footerStatusCopy')}</p>
        </div>
      </div>
      <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 border-t px-6 py-4 text-xs font-medium app-border app-text-muted">
        <span>{t('footerCopyright')}</span>
        <span>{t('footerNoPayments')}</span>
      </div>
    </footer>
  );
}
