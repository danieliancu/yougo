import { Link } from '@inertiajs/react';
import { ChevronDown, Lock, Menu, X } from 'lucide-react';
import { type MouseEvent, useState } from 'react';
import { ThemeToggle } from '@/Components/Ui';
import { businessTaxonomy } from '@/data/businessTaxonomy';

export type PublicLocale = 'ro' | 'en';

const languages = [
  { id: 'ro' as PublicLocale, label: 'RO' },
  { id: 'en' as PublicLocale, label: 'EN' },
];

type PublicHeaderProps = {
  authUserName?: string;
  locale: PublicLocale;
  onLanguageChange: (locale: PublicLocale) => void;
  startLabel: string;
  industriesLabel: string;
  pricingLabel: string;
  logoMode?: 'theme' | 'light';
};

export function PublicHeader({ authUserName, locale, onLanguageChange, startLabel, industriesLabel, pricingLabel, logoMode = 'theme' }: PublicHeaderProps) {
  const aboutLabel = locale === 'ro' ? 'Despre' : 'About';
  const scrollToLandingSection = (event: MouseEvent<HTMLAnchorElement>, sectionId: string) => {
    if (typeof window === 'undefined' || window.location.pathname !== '/') return;

    const target = document.getElementById(sectionId);
    if (! target) return;

    event.preventDefault();
    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    window.history.pushState(null, '', `#${sectionId}`);
  };

  return (
    <nav className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-6 py-5">
      <Link href="/" className="flex items-center">
        <PublicBrandLogo className="h-12" mode={logoMode} />
      </Link>
      <div className="hidden items-center gap-2 md:flex">
        <ThemeToggle />
        <IndustriesMenu label={industriesLabel} locale={locale} />
        <Link href="/#pricing" onClick={(event) => scrollToLandingSection(event, 'pricing')} className="flex h-10 cursor-pointer items-center rounded-lg px-3 text-sm font-bold app-text-soft hover:bg-[var(--soft)]">
          {pricingLabel}
        </Link>
        <Link href="/#faq" onClick={(event) => scrollToLandingSection(event, 'faq')} className="flex h-10 cursor-pointer items-center rounded-lg px-3 text-sm font-bold app-text-soft hover:bg-[var(--soft)]">
          {aboutLabel}
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
    </nav>
  );
}

function PublicBrandLogo({ className, mode = 'theme' }: { className: string; mode?: 'theme' | 'light' }) {
  if (mode === 'light') {
    return <img src="/images/logo-white.png" className={`${className} w-auto`} alt="YouGo" />;
  }

  return (
    <>
      <img src="/images/logo-white.png" className={`yougo-logo-light ${className} w-auto`} alt="YouGo" />
      <img src="/images/logo-dark.png" className={`yougo-logo-dark ${className} w-auto`} alt="YouGo" />
    </>
  );
}

function PublicCta({ authUserName, startLabel, className = 'flex items-center gap-2 rounded-lg bg-slate-950 px-4 py-2 text-sm font-bold text-white dark:border dark:border-white' }: { authUserName?: string; startLabel: string; className?: string }) {
  return (
    <Link href={authUserName ? '/dashboard' : '/register'} className={`${className} cursor-pointer`}>
      {authUserName && <Lock className="h-4 w-4 shrink-0" />}
      {authUserName ?? startLabel}
    </Link>
  );
}

function LandingLanguageToggle({ locale, onChange }: { locale: PublicLocale; onChange: (l: PublicLocale) => void }) {
  const [open, setOpen] = useState(false);
  const active = languages.find((l) => l.id === locale) ?? languages[0];

  return (
    <div className="relative" onMouseEnter={() => setOpen(true)} onMouseLeave={() => setOpen(false)}>
      <button
        type="button"
        className="flex h-10 cursor-pointer items-center gap-2 rounded-lg px-3 text-sm font-bold app-text-soft hover:bg-[var(--soft)]"
      >
        {active.label}
        <ChevronDown className="h-4 w-4" />
      </button>
      {open && (
        <div className="absolute left-0 top-10 z-50 w-36 rounded-lg border p-1 shadow-lg app-panel">
          {languages.map((item) => (
            <button
              key={item.id}
              type="button"
              onClick={() => { setOpen(false); onChange(item.id); }}
              className="flex w-full cursor-pointer items-center rounded-md px-3 py-2 text-left text-sm font-bold app-text-soft transition hover:bg-indigo-600 hover:!text-white"
            >
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
    <div className="relative" onMouseEnter={() => setOpen(true)} onMouseLeave={() => setOpen(false)}>
      <button
        type="button"
        className="flex h-10 cursor-pointer items-center gap-2 rounded-lg px-3 text-sm font-bold app-text-soft hover:bg-[var(--soft)]"
      >
        {label}
        <ChevronDown className="h-4 w-4" />
      </button>
      {open && (
        <div className="absolute left-0 top-10 z-50 hidden max-h-[70vh] w-72 overflow-y-auto rounded-2xl border p-3 shadow-2xl app-panel md:block">
          <div className="grid gap-1">
            {businessTaxonomy.map((group) => (
              <Link
                key={group.slug}
                href={`/industries/${group.slug}`}
                className="cursor-pointer rounded-lg px-3 py-2 text-sm font-bold app-text-soft hover:bg-indigo-600 hover:!text-white"
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
  const [languageOpen, setLanguageOpen] = useState(false);
  const [industriesOpen, setIndustriesOpen] = useState(false);
  const active = languages.find((item) => item.id === locale) ?? languages[0];
  const aboutLabel = locale === 'ro' ? 'Despre' : 'About';
  const scrollToLandingSection = (event: MouseEvent<HTMLAnchorElement>, sectionId: string) => {
    if (typeof window === 'undefined' || window.location.pathname !== '/') return;

    const target = document.getElementById(sectionId);
    if (! target) return;

    event.preventDefault();
    setOpen(false);
    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    window.history.pushState(null, '', `#${sectionId}`);
  };

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
          <button
            type="button"
            onClick={() => setLanguageOpen((value) => !value)}
            className="mb-2 flex w-full cursor-pointer items-center justify-between rounded-lg px-1 pb-2 text-left text-xs font-bold uppercase tracking-wide text-indigo-600"
            aria-expanded={languageOpen}
          >
            {active.label}
            <ChevronDown className={`h-4 w-4 transition ${languageOpen ? 'rotate-180' : ''}`} />
          </button>
          {languageOpen && (
            <div className="mb-4 grid gap-1">
              {languages.map((item) => (
                <button
                  key={item.id}
                  type="button"
                  onClick={() => { setLanguageOpen(false); onLanguageChange(item.id); }}
                  className={`flex w-full cursor-pointer items-center rounded-lg px-3 py-2 text-left text-sm font-bold transition hover:bg-indigo-600 hover:!text-white ${locale === item.id ? 'bg-indigo-600 !text-white' : 'app-text-soft'}`}
                >
                  {item.label}
                </button>
              ))}
            </div>
          )}
          <Link href="/#pricing" onClick={(event) => scrollToLandingSection(event, 'pricing')} className="mb-3 block px-1 pb-2 text-xs font-bold uppercase tracking-wide text-indigo-600">
            {pricingLabel}
          </Link>
          <Link href="/#faq" onClick={(event) => scrollToLandingSection(event, 'faq')} className="mb-3 block px-1 pb-2 text-xs font-bold uppercase tracking-wide text-indigo-600">
            {aboutLabel}
          </Link>
          <button
            type="button"
            onClick={() => setIndustriesOpen((value) => !value)}
            className="flex w-full cursor-pointer items-center justify-between rounded-lg px-1 pb-2 text-left text-xs font-bold uppercase tracking-wide text-indigo-600"
            aria-expanded={industriesOpen}
          >
            {industriesLabel}
            <ChevronDown className={`h-4 w-4 transition ${industriesOpen ? 'rotate-180' : ''}`} />
          </button>
          {industriesOpen && (
            <div className="grid gap-1">
              {businessTaxonomy.map((group) => (
                <Link
                  key={group.slug}
                  href={`/industries/${group.slug}`}
                  className="cursor-pointer rounded-lg px-3 py-2 text-sm font-bold app-text-soft hover:bg-indigo-600 hover:!text-white"
                >
                  {industryMenuLabel(group.slug, locale)}
                </Link>
              ))}
            </div>
          )}
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
            <PublicBrandLogo className="h-11" />
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
