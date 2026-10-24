import { useI18n } from 'vue-i18n';

/** Locale aware date formatting (Intl). */
export function useFormat() {
  const { locale } = useI18n();
  const dateTime = (iso: string): string => {
    const date = new Date(iso.endsWith('Z') || /[+-]\d\d:\d\d$/.test(iso) ? iso : `${iso}Z`);
    return Number.isNaN(date.getTime())
      ? iso
      : new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
  };
  const number = (value: number): string => new Intl.NumberFormat(locale.value).format(value);
  return { dateTime, number };
}
