import type { PublicLocale } from '@/Components/PublicChrome';

const localeKey = 'yougo-lang';

export function isPublicLocale(locale: unknown): locale is PublicLocale {
  return locale === 'ro' || locale === 'en';
}

export function preferredLocale(fallback: string = 'ro'): PublicLocale {
  if (typeof window === 'undefined') {
    return isPublicLocale(fallback) ? fallback : 'ro';
  }

  const stored = window.localStorage.getItem(localeKey);

  return isPublicLocale(stored) ? stored : (isPublicLocale(fallback) ? fallback : 'ro');
}

export function rememberLocale(locale: PublicLocale) {
  if (typeof window === 'undefined') return;

  window.localStorage.setItem(localeKey, locale);
  document.cookie = `${localeKey}=${locale}; path=/; max-age=31536000; SameSite=Lax`;
}

export function syncLocalePreference(locale: PublicLocale) {
  if (typeof window === 'undefined') return;

  const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
  const xsrf = document.cookie
    .split('; ')
    .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
    ?.split('=')[1];

  void fetch('/settings/language', {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      ...(token ? { 'X-CSRF-TOKEN': token } : {}),
      ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
    },
    body: JSON.stringify({ display_language: locale }),
  });
}
