import { describe, expect, it } from 'vitest';
import { partLabel } from './PastMaintenanceRail';

/** Normalise the various Unicode spaces fr-FR currency formatting injects, so assertions stay legible. */
const norm = (s: string) => s.replace(/\s+/g, ' ');

describe('partLabel', () => {
  it('shows brand + ref, no qty/price when unset', () => {
    expect(partLabel({ ref: 'W712/52', brand: 'MANN' })).toBe('MANN W712/52');
  });

  it('appends the quantity only when > 1', () => {
    expect(partLabel({ ref: '5W30', brand: 'Motul', qty: 1 })).toBe('Motul 5W30');
    expect(partLabel({ ref: '5W30', brand: 'Motul', qty: 5 })).toBe('Motul 5W30 ×5');
  });

  it('renders the unit price from priceCents as euros', () => {
    expect(norm(partLabel({ ref: '5W30', brand: 'Motul', qty: 5, priceCents: 1250 }))).toBe('Motul 5W30 ×5 — 12,50 €');
  });

  it('honours the visit currency', () => {
    expect(norm(partLabel({ ref: 'BRK-1', priceCents: 999 }, 'USD'))).toContain('$');
  });

  it('treats priceCents 0 as a real price, not absent', () => {
    expect(norm(partLabel({ ref: 'FREEBIE', priceCents: 0 }))).toBe('FREEBIE — 0,00 €');
  });
});
