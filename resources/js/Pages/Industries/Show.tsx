import { Head, Link, usePage } from '@inertiajs/react';
import { Bot, CalendarCheck, ClipboardList, HelpCircle, Sparkles } from 'lucide-react';
import { useState } from 'react';
import { PublicFooter, PublicHeader, PublicLocale } from '@/Components/PublicChrome';
import { BusinessType } from '@/data/businessTaxonomy';
import { translate } from '@/i18n';
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
  const [locale, setLocale] = useState<PublicLocale>(() => {
    if (typeof window === 'undefined') return 'ro';
    return (localStorage.getItem('yougo-lang') as PublicLocale) ?? 'ro';
  });
  const t = (key: string) => translate(locale, key);
  const content = industryCopy(businessType, locale);

  function switchLang(lang: PublicLocale) {
    setLocale(lang);
    localStorage.setItem('yougo-lang', lang);
  }

  return (
    <main className="min-h-screen app-bg">
      <Head title={locale === 'ro' ? content.title : seo.title}>
        <meta name="description" content={locale === 'ro' ? content.pageFocus : seo.description} />
      </Head>

      <PublicHeader
        authUserName={auth.user?.name}
        locale={locale}
        onLanguageChange={switchLang}
        startLabel={t('start')}
        industriesLabel={t('industriesNav')}
        pricingLabel={t('pricing')}
      />

      <section className="mx-auto grid max-w-6xl gap-10 px-6 py-14 lg:grid-cols-[1fr_0.9fr] lg:items-center">
        <div>
          <p className="mb-4 inline-flex rounded-md bg-indigo-50 px-3 py-1 text-xs font-bold uppercase tracking-wide text-indigo-700">{businessType.label}</p>
          <h1 className="max-w-3xl text-5xl font-bold tracking-tight app-text md:text-6xl">{content.title}</h1>
          <p className="mt-6 max-w-2xl text-lg leading-8 app-text-soft">{content.pageFocus}</p>
          <div className="mt-8 flex flex-wrap gap-3">
            <Link href="/register" className="rounded-lg bg-indigo-600 px-5 py-3 text-sm font-bold text-white shadow-sm hover:bg-indigo-700">{t('start')}</Link>
            <Link href="/" className="rounded-lg border px-5 py-3 text-sm font-bold hover:bg-[var(--soft)] app-border">{t('industryViewDemo')}</Link>
          </div>
        </div>

        <div className="rounded-2xl border p-6 shadow-xl app-panel">
          <div className="flex items-center gap-3">
            <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-indigo-600 text-white">
              <Bot className="h-6 w-6" />
            </div>
            <div>
              <p className="text-sm font-bold app-text">{t('industryCurrentMode')}</p>
              <p className="text-sm app-text-muted">{t('industryAppointmentHandling')}</p>
            </div>
          </div>
          <p className="mt-5 text-sm leading-6 app-text-soft">
            {hasFutureReservation
              ? t('industryReservationLater')
              : hasFutureLead
                ? t('industryLeadLater')
                : t('industryAppointmentNow')}
          </p>
        </div>
      </section>

      <section className="mx-auto max-w-6xl px-6 pb-20">
        <div className="grid gap-5 md:grid-cols-3">
          <InfoCard icon={HelpCircle} title={t('industryWhatClientsAsk')}>
            <ul className="space-y-3 text-sm app-text-soft">
              {content.commonQuestions.map((question) => <li key={question}>{'\u2022'} {question}</li>)}
            </ul>
          </InfoCard>
          <InfoCard icon={Sparkles} title={t('industryHowAiHelps')}>
            <p className="text-sm leading-6 app-text-soft">
              {t('industryHowAiHelpsCopy')}
            </p>
            {content.safetyCopy && <p className="mt-4 rounded-lg bg-amber-500/10 p-3 text-sm font-medium text-amber-700 dark:text-amber-300">{content.safetyCopy}</p>}
          </InfoCard>
          <InfoCard icon={ClipboardList} title={t('industryCurrentFlow')}>
            <p className="text-sm font-bold app-text-soft">{content.currentFlow}</p>
            {content.futureFlow && (
              <p className="mt-4 text-sm app-text-muted">{t('industryComingLater')}: {content.futureFlow}</p>
            )}
          </InfoCard>
        </div>

        <div className="mt-8 rounded-2xl border p-6 app-panel">
          <div className="flex items-start gap-4">
            <CalendarCheck className="mt-1 h-6 w-6 text-indigo-500" />
            <div>
              <h2 className="text-2xl font-bold app-text">{t('industryContextTitle')}</h2>
              <p className="mt-3 max-w-3xl text-sm leading-6 app-text-soft">
                {t('industryContextCopy').replace(':label', businessType.label)}
              </p>
              <div className="mt-4 flex flex-wrap gap-2">
                {businessType.industries.map((category) => (
                  <span key={category.slug} className="rounded-md bg-indigo-50 px-3 py-1 text-xs font-bold text-indigo-700">{category.label}</span>
                ))}
              </div>
            </div>
          </div>
        </div>
      </section>
      <PublicFooter t={t} />
    </main>
  );
}

function InfoCard({ icon: Icon, title, children }: { icon: any; title: string; children: React.ReactNode }) {
  return (
    <div className="rounded-2xl border p-6 app-panel">
      <Icon className="mb-4 h-6 w-6 text-indigo-500" />
      <h2 className="mb-4 text-xl font-bold app-text">{title}</h2>
      {children}
    </div>
  );
}

type LocalizedIndustryCopy = {
  title: string;
  pageFocus: string;
  commonQuestions: string[];
  currentFlow: string;
  futureFlow?: string | null;
  safetyCopy?: string | null;
};

function industryCopy(businessType: BusinessType, locale: PublicLocale): LocalizedIndustryCopy {
  if (locale !== 'ro') {
    return {
      title: `YouGo AI receptionist for ${businessType.label.toLowerCase()}`,
      pageFocus: businessType.page_focus,
      commonQuestions: businessType.common_questions,
      currentFlow: businessType.current_flow,
      futureFlow: businessType.future_flow,
      safetyCopy: businessType.safety_copy,
    };
  }

  const ro: Record<string, Omit<LocalizedIndustryCopy, 'title'>> = {
    'salon-beauty': {
      pageFocus: 'YouGo ajuta saloanele si businessurile de beauty sa raspunda la intrebari, sa explice serviciile si sa colecteze cereri de programare cand echipa este ocupata.',
      commonQuestions: ['Aveti disponibilitate astazi?', 'Cat costa acest tratament?', 'Pot sa ma programez la un anumit stilist sau tehnician?', 'Unde sunteti localizati?'],
      currentFlow: 'serviciu -> locatie -> preferinta staff -> data -> ora -> date client -> cerere de programare',
    },
    'clinic-healthcare': {
      pageFocus: 'YouGo ajuta clinicile sa gestioneze cereri de programare, intrebari despre program, locatie si informatii generale non-urgente despre servicii.',
      commonQuestions: ['Pot face o programare?', 'Ce servicii oferiti?', 'Care este programul?', 'Unde este clinica?'],
      currentFlow: 'motiv programare -> serviciu -> data/ora preferata -> date pacient -> cerere de programare',
      safetyCopy: 'AI-ul nu trebuie sa diagnosticheze, sa prescrie sau sa inlocuiasca sfatul medical de urgenta.',
    },
    'auto-service': {
      pageFocus: 'YouGo ajuta service-urile auto sa colecteze detalii utile despre masina, sa explice serviciile si sa gestioneze cereri de programare.',
      commonQuestions: ['Pot programa o inspectie?', 'Masina face un zgomot, o puteti verifica?', 'Montati anvelope?', 'Cat costa diagnoza?'],
      currentFlow: 'problema/serviciu -> detalii masina -> data/ora preferata -> date client -> cerere de programare',
    },
    'professional-services': {
      pageFocus: 'YouGo ajuta businessurile de servicii profesionale sa califice cereri, sa colecteze date de contact si sa programeze consultatii.',
      commonQuestions: ['Pot programa o consultatie?', 'Ce servicii oferiti?', 'Ma poate suna cineva?', 'Ce informatii aveti nevoie de la mine?'],
      currentFlow: 'tip cerere -> preferinta consultatie -> data/ora preferata -> date contact',
      safetyCopy: 'Pentru servicii juridice, financiare sau reglementate, AI-ul trebuie sa colecteze detalii si sa aranjeze follow-up, nu sa ofere consultanta reglementata.',
    },
    restaurant: {
      pageFocus: 'YouGo ajuta restaurantele, cafenelele si businessurile de catering sa raspunda la intrebari si sa colecteze cereri de tip rezervare.',
      commonQuestions: ['Sunteti deschisi diseara?', 'Pot rezerva o masa?', 'Aveti optiuni vegetariene?', 'Organizati evenimente private?'],
      currentFlow: 'cerere -> data/ora preferata -> numar persoane -> date contact',
      futureFlow: 'data -> ora -> numar persoane -> disponibilitate masa -> rezervare',
    },
    'hotel-accommodation': {
      pageFocus: 'YouGo ajuta businessurile de cazare sa raspunda la intrebarile oaspetilor si sa colecteze cereri de rezervare.',
      commonQuestions: ['Aveti camere disponibile?', 'Care este pretul pe noapte?', 'Micul dejun este inclus?', 'La ce ora este check-in-ul?'],
      currentFlow: 'data check-in -> numar nopti -> oaspeti -> preferinta camera -> date contact',
      futureFlow: 'date -> oaspeti -> disponibilitate camera -> rezervare',
    },
    rental: {
      pageFocus: 'YouGo ajuta businessurile de inchirieri sa colecteze cereri despre resurse, perioade de inchiriere si datele clientilor.',
      commonQuestions: ['Aveti disponibil saptamana viitoare?', 'Cat costa pe zi?', 'Pot inchiria pentru doua zile?', 'Oferiti livrare?'],
      currentFlow: 'obiect/resursa -> data start -> data final/durata -> date contact',
      futureFlow: 'resursa -> data start -> data final -> disponibilitate -> rezervare',
    },
    'real-estate': {
      pageFocus: 'YouGo ajuta agentiile imobiliare si businessurile de proprietati sa colecteze cereri, vizionari si date de contact.',
      commonQuestions: ['Mai este disponibila proprietatea?', 'Pot programa o vizionare?', 'Care este pretul?', 'Ma poate contacta cineva?'],
      currentFlow: 'proprietate/tip cerere -> vizionare sau preferinta contact -> date contact',
      futureFlow: 'cumparare/vanzare/inchiriere -> buget/proprietate/locatie -> date contact -> lead',
    },
    other: {
      pageFocus: 'YouGo poate fi configurat pentru alte businessuri bazate pe programari prin servicii, locatii, staff si instructiuni AI.',
      commonQuestions: ['Ce servicii oferiti?', 'Pot rezerva o ora?', 'Unde sunteti localizati?'],
      currentFlow: 'cerere -> serviciu -> data/ora -> date contact',
    },
  };

  const content = ro[businessType.slug] ?? ro.other;

  return {
    title: `Receptionist AI YouGo pentru ${businessType.label.toLowerCase()}`,
    ...content,
  };
}
