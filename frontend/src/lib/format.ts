/**
 * Dates and numbers in the reader's language, through Intl (no extra
 * library). Times are shown in India time, as the school works in it.
 */
const TIME_ZONE = 'Asia/Kolkata';

export function formatDateTime(iso: string | null | undefined, language: string): string {
  if (!iso) return '';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '';
  return new Intl.DateTimeFormat(language, { dateStyle: 'medium', timeStyle: 'short', timeZone: TIME_ZONE }).format(date);
}

export function formatDate(iso: string | null | undefined, language: string): string {
  if (!iso) return '';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '';
  return new Intl.DateTimeFormat(language, { dateStyle: 'medium', timeZone: TIME_ZONE }).format(date);
}

export function formatLongDate(date: Date, language: string): string {
  return new Intl.DateTimeFormat(language, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: TIME_ZONE }).format(date);
}

export function formatNumber(value: number, language: string): string {
  return new Intl.NumberFormat(language === 'en' ? 'en-IN' : language).format(value);
}

/** File sizes like "1.2 MB". */
export function formatBytes(bytes: number, language: string): string {
  const units = ['byte', 'kilobyte', 'megabyte'] as const;
  let value = bytes;
  let unit = 0;
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit += 1;
  }
  return new Intl.NumberFormat(language, { style: 'unit', unit: units[unit], unitDisplay: 'short', maximumFractionDigits: 1 }).format(value);
}

/** Hour of the day in India time, for "Good morning". */
export function hourInIndia(now: Date = new Date()): number {
  return Number(new Intl.DateTimeFormat('en-GB', { hour: 'numeric', hourCycle: 'h23', timeZone: TIME_ZONE }).format(now));
}
