import { preferredLocale } from '@/lib/localePreference';

// Shared date-format helpers so every dashboard list (Bookings, Requests, ...) renders
// dates using the business's configured date_format setting instead of a hardcoded style.

export function normalizeDateFormatForUi(dateFormat?: string | null) {
  const normalized = dateFormat?.trim().toLowerCase();

  if (!normalized) return null;
  if (normalized === 'dd.mm.yyyy.' || normalized === 'dd.mm.yyyy') return 'dd.mm.yyyy';
  if (normalized === 'dd/mm/yyyy' || normalized === 'dd-mm-yyyy') return 'dd/mm/yyyy';
  if (normalized === 'yyyy-mm-dd' || normalized === 'yyyy/mm/dd') return 'yyyy-mm-dd';
  if (['dd month yyyy', 'd month yyyy', 'dd mmmm yyyy', 'd mmmm yyyy'].includes(normalized)) return 'dd month yyyy';

  return normalized;
}

export function formatBusinessDate(date: string, salon: { date_format?: string | null }) {
  const datePart = date.slice(0, 10);
  const [year, month, day] = datePart.split('-');
  const format = normalizeDateFormatForUi(salon.date_format) ?? 'dd.mm.yyyy';

  if (format === 'yyyy-mm-dd') return `${year}-${month}-${day}`;
  if (format === 'dd/mm/yyyy') return `${day}/${month}/${year}`;
  if (format === 'dd month yyyy') {
    const locale = preferredLocale() === 'en' ? 'en-GB' : 'ro-RO';
    return new Intl.DateTimeFormat(locale, {
      day: 'numeric',
      month: 'long',
      year: 'numeric',
    }).format(new Date(`${datePart}T00:00:00`));
  }

  return `${day}.${month}.${year}`;
}

export function formatTimeOnly(value: string) {
  const locale = preferredLocale() === 'en' ? 'en-GB' : 'ro-RO';

  return new Intl.DateTimeFormat(locale, {
    timeStyle: 'short',
  }).format(new Date(value));
}

export function formatBusinessDateWithWeekday(date: string, salon: { date_format?: string | null }) {
  const datePart = date.slice(0, 10);
  const locale = preferredLocale() === 'en' ? 'en-GB' : 'ro-RO';
  const weekday = new Intl.DateTimeFormat(locale, {
    weekday: 'long',
  }).format(new Date(`${datePart}T00:00:00`));

  const label = `${weekday}, ${formatBusinessDate(date, salon)}`;

  return label.charAt(0).toUpperCase() + label.slice(1);
}

export function formatBusinessDateTime(value: string, salon: { date_format?: string | null }) {
  return `${formatBusinessDate(value, salon)}, ${formatTimeOnly(value)}`;
}
