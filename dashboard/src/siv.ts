import { formatDate, formatPower, formatReading, groupThousands } from './format';
import type { SpecNode } from './focus';

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

// French energy (carte grise J.3-ish) + gearbox codes, with a raw fallback so an unmapped code still reads.
const ENERGY_LABEL: Record<string, string> = {
  ES: 'Essence',
  GO: 'Diesel',
  EL: 'Électrique',
  EH: 'Hybride',
  EE: 'Hybride rechargeable',
  GP: 'GPL',
  GN: 'GNV',
  FE: 'Superéthanol',
};
const GEARBOX_LABEL: Record<string, string> = { M: 'Manuelle', A: 'Automatique' };

export function carteGriseNodes(v: SivVehicle): SpecNode[] {
  const nodes: SpecNode[] = [];
  const push = (key: string, label: string, value: string) => {
    if (value) nodes.push({ key, label, value, verified: true });
  };

  if (v.energy) push('energy', 'Énergie', ENERGY_LABEL[v.energy] ?? v.energy);
  push('power', 'Puissance', formatPower(v.powerKw, v.powerHp));
  if (v.engineCapacityCcm != null) push('displacement', 'Cylindrée', formatReading(v.engineCapacityCcm, 'cm³'));
  if (v.cylinders != null) push('cylinders', 'Cylindres', groupThousands(v.cylinders));
  if (v.gearbox) push('gearbox', 'Boîte', GEARBOX_LABEL[v.gearbox] ?? v.gearbox);
  if (v.seats != null) push('seats', 'Places', formatReading(v.seats, 'places'));
  if (v.weightKg != null) push('weight', 'Poids', formatReading(v.weightKg, 'kg'));
  if (v.firstRegistration) push('first_registration', 'Mise en circulation', formatDate(v.firstRegistration) || v.firstRegistration);
  if (v.fiscalPower != null) push('fiscal_power', 'Puissance fiscale', formatReading(v.fiscalPower, 'CV'));

  return nodes;
}
