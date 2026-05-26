import { PublicLocale } from '@/Components/PublicChrome';
import { YouGoCopilot } from '@/Components/YouGoCopilot';

export function PublicYouGoChat({ locale, authenticated = false }: { locale: PublicLocale; authenticated?: boolean }) {
  return <YouGoCopilot locale={locale} context={{ surface: 'public', authenticated }} />;
}
