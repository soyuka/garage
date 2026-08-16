import { describe, it, expect } from 'vitest';
import { THIN as SRC_THIN, NBSP as SRC_NBSP } from './format';
import {
  formatAmount,
  formatDate,
  formatEuros,
  formatHealthPct,
  formatHorizon,
  formatOverdue,
  formatPower,
  formatReading,
  groupThousands,
  roundHealth,
} from './format';

const THIN = '\u202f';
const NBSP = '\u00a0';

// Every French expectation below is UNCHANGED from before the locale argument existed — that is the
// point of this file now: it proves the French rendering did not move while English grew beside it.

// These two are INVISIBLE in an editor, and a tool rewriting format.ts silently flattened both to
// ASCII spaces during the i18n work — every French number and unit was subtly wrong and nothing
// looked odd in a diff. Pin the codepoints so that failure can only ever happen once.
describe('the invisible separators are the right characters', () => {
  it('THIN is U+202F and NBSP is U+00A0, not ordinary spaces', () => {
    expect(SRC_THIN.codePointAt(0)).toBe(0x202f);
    expect(SRC_NBSP.codePointAt(0)).toBe(0x00a0);
    expect(THIN.codePointAt(0)).toBe(0x202f);
    expect(NBSP.codePointAt(0)).toBe(0x00a0);
    expect(THIN).not.toBe(' ');
    expect(NBSP).not.toBe(' ');
  });
});

describe('groupThousands', () => {
  it('groups with a thin no-break space in French', () => {
    expect(groupThousands(142000, 'fr')).toBe(`142${THIN}000`);
    expect(groupThousands(1234567, 'fr')).toBe(`1${THIN}234${THIN}567`);
  });
  it('groups with a comma in English', () => {
    expect(groupThousands(142000, 'en')).toBe('142,000');
    expect(groupThousands(1234567, 'en')).toBe('1,234,567');
  });
  it('leaves sub-thousands untouched and handles zero/negatives', () => {
    expect(groupThousands(0, 'fr')).toBe('0');
    expect(groupThousands(900, 'fr')).toBe('900');
    expect(groupThousands(-3200, 'fr')).toBe(`-3${THIN}200`);
    expect(groupThousands(-3200, 'en')).toBe('-3,200');
  });
});

describe('formatReading', () => {
  it('binds the unit with a no-break space in both languages', () => {
    // NBSP is not a French rule here — it stops a value wrapping away from its unit, which is just
    // as wrong in English.
    expect(formatReading(142000, 'km', 'fr')).toBe(`142${THIN}000${NBSP}km`);
    expect(formatReading(142000, 'km', 'en')).toBe(`142,000${NBSP}km`);
  });
  it('omits the unit when absent', () => {
    expect(formatReading(142000, null, 'fr')).toBe(`142${THIN}000`);
    expect(formatReading(142000, null, 'en')).toBe('142,000');
  });
});

describe('formatDate', () => {
  it('renders the French abbreviated form', () => {
    expect(formatDate('2026-01-15', 'fr')).toBe(`15${NBSP}janv.${NBSP}2026`);
  });
  it('renders the English abbreviated form, same day-first shape', () => {
    expect(formatDate('2026-01-15', 'en')).toBe(`15${NBSP}Jan${NBSP}2026`);
    expect(formatDate('2026-07-06', 'en')).toBe(`6${NBSP}Jul${NBSP}2026`);
  });
  it('returns empty string for null/empty', () => {
    expect(formatDate(null, 'fr')).toBe('');
    expect(formatDate('', 'en')).toBe('');
  });
});

describe('roundHealth / formatHealthPct', () => {
  it('rounds to the nearest 5', () => {
    expect(roundHealth(72.3)).toBe(70);
    expect(roundHealth(73)).toBe(75);
    expect(roundHealth(100)).toBe(100);
  });
  it('formats with a no-break space before % in French, and none in English', () => {
    expect(formatHealthPct(72.3, 'fr')).toBe(`70${NBSP}%`);
    expect(formatHealthPct(72.3, 'en')).toBe('70%');
  });
});

describe('formatHorizon (no false precision §5)', () => {
  it('today', () => {
    expect(formatHorizon(0, 'fr')).toBe("aujourd'hui");
    expect(formatHorizon(0, 'en')).toBe('today');
  });
  it('a few days', () => {
    expect(formatHorizon(3, 'fr')).toBe('dans quelques jours');
    expect(formatHorizon(3, 'en')).toBe('in a few days');
  });
  it('weeks under ~6 weeks (number bound to word with a no-break space)', () => {
    expect(formatHorizon(20, 'fr')).toBe(`dans environ 3${NBSP}semaines`);
    expect(formatHorizon(20, 'en')).toBe(`in about 3${NBSP}weeks`);
  });
  it('months otherwise — 58 days reads as ~2 months, never "58 jours"', () => {
    expect(formatHorizon(58, 'fr')).toBe(`dans environ 2${NBSP}mois`);
    expect(formatHorizon(58, 'en')).toBe(`in about 2${NBSP}months`);
    expect(formatHorizon(58, 'en')).not.toContain('58');
  });
});

describe('formatEuros', () => {
  it('renders French money — comma decimal, thin-space grouping, NBSP before €', () => {
    expect(formatEuros(8900, 'fr')).toBe(`89,00${NBSP}€`);
    expect(formatEuros(1234567, 'fr')).toBe(`12${THIN}345,67${NBSP}€`);
  });
  it('renders English money — symbol first, dot decimal, comma grouping', () => {
    // Still euros: the currency is the garage's, not the reader's. Only the notation changes.
    expect(formatEuros(8900, 'en')).toBe('€89.00');
    expect(formatEuros(1234567, 'en')).toBe('€12,345.67');
  });
  it('zero-pads the centime part', () => {
    expect(formatEuros(500, 'fr')).toBe(`5,00${NBSP}€`);
    expect(formatEuros(509, 'fr')).toBe(`5,09${NBSP}€`);
    expect(formatEuros(509, 'en')).toBe('€5.09');
  });
  it('keeps the sign in front of the symbol in English', () => {
    expect(formatEuros(-509, 'en')).toBe('-€5.09');
    expect(formatEuros(-509, 'fr')).toBe(`-5,09${NBSP}€`);
  });
});

describe('formatOverdue (precise §5 exception)', () => {
  it('states the exact overdue amount with thin-space grouping', () => {
    expect(formatOverdue(3200, 'km', 'fr')).toBe(`DÉPASSÉ de 3${THIN}200${NBSP}km`);
    expect(formatOverdue(3200, 'km', 'en')).toBe(`OVERDUE by 3,200${NBSP}km`);
  });
  it('uses the absolute value (a negative remaining is overdue)', () => {
    expect(formatOverdue(-3200, 'km', 'fr')).toBe(`DÉPASSÉ de 3${THIN}200${NBSP}km`);
    expect(formatOverdue(-3200, 'km', 'en')).toBe(`OVERDUE by 3,200${NBSP}km`);
  });
});

describe('formatAmount (decimal capacity — trimmed)', () => {
  it('renders a one-decimal fluid capacity with the locale decimal mark', () => {
    expect(formatAmount(4.3, 'fr')).toBe('4,3');
    expect(formatAmount(4.3, 'en')).toBe('4.3');
  });
  it('trims trailing zeros and a whole number has no decimal part', () => {
    expect(formatAmount(1, 'fr')).toBe('1');
    expect(formatAmount(5.0, 'en')).toBe('5');
  });
  it('groups the integer part', () => {
    expect(formatAmount(1200.5, 'fr')).toBe(`1${THIN}200,5`);
    expect(formatAmount(1200.5, 'en')).toBe('1,200.5');
  });
  it('keeps up to three decimals and handles a sub-unit amount', () => {
    expect(formatAmount(0.47, 'fr')).toBe('0,47');
    expect(formatAmount(0.47, 'en')).toBe('0.47');
  });
  it('handles zero and negatives', () => {
    expect(formatAmount(0, 'fr')).toBe('0');
    expect(formatAmount(-2.5, 'fr')).toBe('-2,5');
    expect(formatAmount(-2.5, 'en')).toBe('-2.5');
  });
});

describe('formatPower (SIV carte grise)', () => {
  it('joins both readings with a middot, each unit no-break bound', () => {
    expect(formatPower(75, 102, 'fr')).toBe(`75${NBSP}kW · 102${NBSP}ch`);
  });
  it('says hp rather than ch in English', () => {
    expect(formatPower(75, 102, 'en')).toBe(`75${NBSP}kW · 102${NBSP}hp`);
  });
  it('groups large values and tolerates a single reading', () => {
    expect(formatPower(1103, null, 'fr')).toBe(`1${THIN}103${NBSP}kW`);
    expect(formatPower(null, 150, 'fr')).toBe(`150${NBSP}ch`);
    expect(formatPower(null, 150, 'en')).toBe(`150${NBSP}hp`);
  });
  it('is empty when neither is known', () => {
    expect(formatPower(null, null, 'fr')).toBe('');
    expect(formatPower(undefined, undefined, 'en')).toBe('');
  });
});
