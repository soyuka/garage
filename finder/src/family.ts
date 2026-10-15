import { foldText } from './search';
import type { Dimension, Family } from './types';

const BRAKE = /(disque de frein|etrier|plaquette)/;
const AXLE_KEY = /^(position|cote_d_assemblage)/;

function axleFrom(path: string, dimensions: readonly Dimension[]): 'front' | 'rear' {
  if (/arriere/.test(path)) return 'rear';
  if (/avant/.test(path)) return 'front';
  const placed = dimensions.find((d) => AXLE_KEY.test(d.key) && /arriere|avant/.test(foldText(d.value)));
  return placed && /arriere/.test(foldText(placed.value)) ? 'rear' : 'front';
}

const isCylindrical = (dimensions: readonly Dimension[]): boolean =>
  dimensions.some((d) => /^diametre(_exterieur|_1)?$/.test(d.key) && d.number !== null);

export function partFamily(category: { path: string; name: string }, dimensions: readonly Dimension[] = []): Family {
  const path = foldText(category.path);
  if (BRAKE.test(path)) return { kind: 'brake', axle: axleFrom(path, dimensions) };
  if (/filtre a huile/.test(path)) return { kind: 'filter', filter: 'oil' };
  if (/habitacle/.test(path)) return { kind: 'none' };
  if (/filtre a carburant/.test(path) && isCylindrical(dimensions)) return { kind: 'filter', filter: 'fuel' };
  if (/filtre a air/.test(path) && isCylindrical(dimensions)) return { kind: 'filter', filter: 'air' };
  return { kind: 'none' };
}
