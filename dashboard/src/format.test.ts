import { describe, it, expect } from 'vitest';
import {
  formatAmount,
  formatDate,
  formatDaysAgo,
  formatEuros,
  formatHealthPct,
  formatHorizon,
  formatOverdue,
  formatPower,
  formatReading,
  groupThousands,
  roundHealth,
} from './format';

const THIN = ' ';
const NBSP = ' ';

describe('groupThousands', () => {
  it('groups with a thin no-break space', () => {
    expect(groupThousands(142000)).toBe(`142${THIN}000`);
    expect(groupThousands(1234567)).toBe(`1${THIN}234${THIN}567`);
  });
  it('leaves sub-thousands untouched and handles zero/negatives', () => {
    expect(groupThousands(0)).toBe('0');
    expect(groupThousands(900)).toBe('900');
    expect(groupThousands(-3200)).toBe(`-3${THIN}200`);
  });
});

describe('formatReading', () => {
  it('binds the unit with a no-break space', () => {
    expect(formatReading(142000, 'km')).toBe(`142${THIN}000${NBSP}km`);
  });
  it('omits the unit when absent', () => {
    expect(formatReading(142000)).toBe(`142${THIN}000`);
  });
});

describe('formatDate', () => {
  it('renders the French abbreviated form', () => {
    expect(formatDate('2026-01-15')).toBe(`15${NBSP}janv.${NBSP}2026`);
  });
  it('returns empty string for null/empty', () => {
    expect(formatDate(null)).toBe('');
    expect(formatDate('')).toBe('');
  });
});

describe('roundHealth / formatHealthPct', () => {
  it('rounds to the nearest 5', () => {
    expect(roundHealth(72.3)).toBe(70);
    expect(roundHealth(73)).toBe(75);
    expect(roundHealth(100)).toBe(100);
  });
  it('formats with a no-break space before %', () => {
    expect(formatHealthPct(72.3)).toBe(`70${NBSP}%`);
  });
});

describe('formatHorizon (no false precision §5)', () => {
  it('a few days', () => {
    expect(formatHorizon(3)).toBe('dans quelques jours');
  });
  it('weeks under ~6 weeks (number bound to word with a no-break space)', () => {
    expect(formatHorizon(20)).toBe(`dans environ 3${NBSP}semaines`);
  });
  it('months otherwise — 58 days reads as ~2 months, never "58 jours"', () => {
    expect(formatHorizon(58)).toBe(`dans environ 2${NBSP}mois`);
    expect(formatHorizon(58)).not.toContain('58');
  });
});

describe('formatEuros (French money — comma decimal, thin-space grouping, NBSP before €)', () => {
  it('renders integer cents as grouped euros', () => {
    expect(formatEuros(8900)).toBe(`89,00${NBSP}€`);
    expect(formatEuros(1234567)).toBe(`12${THIN}345,67${NBSP}€`);
  });
  it('zero-pads the centime part', () => {
    expect(formatEuros(500)).toBe(`5,00${NBSP}€`);
    expect(formatEuros(509)).toBe(`5,09${NBSP}€`);
  });
});

describe('formatDaysAgo (cardinal "depuis l\'entretien" — past fact, precise, grouped)', () => {
  it('groups the day count and binds the "j" unit with a no-break space', () => {
    expect(formatDaysAgo(393)).toBe(`393${NBSP}j`);
    expect(formatDaysAgo(1200)).toBe(`1${THIN}200${NBSP}j`);
  });
});

describe('formatOverdue (precise §5 exception)', () => {
  it('states the exact overdue amount with thin-space grouping', () => {
    expect(formatOverdue(3200, 'km')).toBe(`DÉPASSÉ de 3${THIN}200${NBSP}km`);
  });
  it('uses the absolute value (a negative remaining is overdue)', () => {
    expect(formatOverdue(-3200, 'km')).toBe(`DÉPASSÉ de 3${THIN}200${NBSP}km`);
  });
});

describe('formatAmount (decimal capacity — French comma, thin-space groups, trimmed)', () => {
  it('renders a one-decimal fluid capacity with a comma', () => {
    expect(formatAmount(4.3)).toBe('4,3');
  });
  it('trims trailing zeros and a whole number has no decimal part', () => {
    expect(formatAmount(1)).toBe('1');
    expect(formatAmount(5.0)).toBe('5');
  });
  it('groups the integer part with a thin space', () => {
    expect(formatAmount(1200.5)).toBe(`1${THIN}200,5`);
  });
  it('keeps up to three decimals and handles a sub-unit amount', () => {
    expect(formatAmount(0.47)).toBe('0,47');
  });
  it('handles zero and negatives', () => {
    expect(formatAmount(0)).toBe('0');
    expect(formatAmount(-2.5)).toBe('-2,5');
  });
});

describe('formatPower (kW · ch — SIV carte grise)', () => {
  it('joins both readings with a middot, each unit no-break bound', () => {
    expect(formatPower(75, 102)).toBe(`75${NBSP}kW · 102${NBSP}ch`);
  });
  it('groups large values and tolerates a single reading', () => {
    expect(formatPower(1103, null)).toBe(`1${THIN}103${NBSP}kW`);
    expect(formatPower(null, 150)).toBe(`150${NBSP}ch`);
  });
  it('is empty when neither is known', () => {
    expect(formatPower(null, null)).toBe('');
    expect(formatPower(undefined, undefined)).toBe('');
  });
});
