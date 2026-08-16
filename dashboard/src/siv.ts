import { formatDate, formatPower, formatReading, groupThousands } from './format';
import type { SpecNode } from './focus';
import type { Locale } from './i18n/locale';
import { messages, type Messages } from './i18n/messages';

// "Fiche technique" (§5.1 Vehicle SIV plate lookup ★) — the response→carte-grise-node mapper. Projects
// the rich registration identity (power, displacement, gearbox, weight…) through the specs focus
// mechanism. Deterministic + pure: the chip GETs /vehicles/{plate}. SIV catalog-sourced → verified (✓).

/** The SIV lookup shape (server-only — a subset of the full §5.1 fields, the ones the carte grise shows). */
export interface SivVehicle {
  energy?: string | null;
  powerKw?: number | null;
  powerHp?: number | null;
  engineCapacityCcm?: number | null;
  cylinders?: number | null;
  gearbox?: string | null;
  seats?: number | null;
  weightKg?: number | null;
  firstRegistration?: string | null;
  fiscalPower?: number | null;
}

// Carte grise J.3-ish energy + gearbox codes. The CODES are the French registration system's and
// never change; only what they are read as does. Raw fallback so an unmapped code still reads.
const ENERGY_KEY = {
  ES: 'essence',
  GO: 'diesel',
  EL: 'electric',
  EH: 'hybrid',
  EE: 'pluginHybrid',
  GP: 'lpg',
  GN: 'cng',
  FE: 'e85',
} as const satisfies Record<string, keyof Messages['siv']['energy']>;

const GEARBOX_KEY = { M: 'manual', A: 'automatic' } as const satisfies Record<string, keyof Messages['siv']['gearbox']>;

function isEnergyCode(code: string): code is keyof typeof ENERGY_KEY {
  return code in ENERGY_KEY;
}

function isGearboxCode(code: string): code is keyof typeof GEARBOX_KEY {
  return code in GEARBOX_KEY;
}

export function carteGriseNodes(v: SivVehicle, locale: Locale): SpecNode[] {
  const t = messages(locale);
  const nodes: SpecNode[] = [];
  const push = (key: string, label: string, value: string) => {
    if (value) nodes.push({ key, label, value, verified: true });
  };

  if (v.energy) push('energy', t.siv.field.energy, isEnergyCode(v.energy) ? t.siv.energy[ENERGY_KEY[v.energy]] : v.energy);
  push('power', t.siv.field.power, formatPower(v.powerKw, v.powerHp, locale));
  if (v.engineCapacityCcm != null) push('displacement', t.siv.field.displacement, formatReading(v.engineCapacityCcm, 'cm³', locale));
  if (v.cylinders != null) push('cylinders', t.siv.field.cylinders, groupThousands(v.cylinders, locale));
  if (v.gearbox) push('gearbox', t.siv.field.gearbox, isGearboxCode(v.gearbox) ? t.siv.gearbox[GEARBOX_KEY[v.gearbox]] : v.gearbox);
  if (v.seats != null) push('seats', t.siv.field.seats, formatReading(v.seats, t.siv.unit.seats, locale));
  if (v.weightKg != null) push('weight', t.siv.field.weight, formatReading(v.weightKg, 'kg', locale));
  if (v.firstRegistration) push('first_registration', t.siv.field.firstRegistered, formatDate(v.firstRegistration, locale) || v.firstRegistration);
  if (v.fiscalPower != null) push('fiscal_power', t.siv.field.taxHorsepower, formatReading(v.fiscalPower, t.siv.unit.taxHorsepower, locale));

  return nodes;
}
