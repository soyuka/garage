import { formatAmount, NBSP } from '../format';
import type { Locale } from '../i18n/locale';

// Catalog units arrive spelled out ("liter", "gramme"); a workshop reads symbols.
const UNIT_SYMBOL: Record<string, string> = {
  l: 'L',
  liter: 'L',
  liters: 'L',
  litre: 'L',
  litres: 'L',
  g: 'g',
  gram: 'g',
  grams: 'g',
  gramme: 'g',
  grammes: 'g',
  kg: 'kg',
  ml: 'mL',
  cm3: 'cm³',
  'cm³': 'cm³',
  cc: 'cm³',
};

const NUMBER = /^-?\d+(?:[.,]\d+)?$/;

/** A fact value as display tags: pipe-joined alternatives split apart, quantities in locale form with a unit symbol. */
export function factValues(value: string, unit: string | null | undefined, locale: Locale, opts: { identifier?: boolean } = {}): string[] {
  const symbol = unit ? (UNIT_SYMBOL[unit.trim().toLowerCase()] ?? unit.trim()) : null;
  return value
    .split('|')
    .map((part) => part.trim())
    .filter(Boolean)
    .map((part) => {
      const shown = !opts.identifier && NUMBER.test(part) ? formatAmount(Number(part.replace(',', '.')), locale) : part;
      return symbol ? `${shown}${NBSP}${symbol}` : shown;
    });
}
