import { canonicalService } from '../labels';
import type { PlannedPart } from '../types';

/** Every part mesh shipped under public/parts (copied read-only from bonneref/public/parts). */
export const PART_FILES = [
  'air-filter',
  'axial-joint',
  'battery',
  'brake-disc',
  'brake-drum',
  'brake-hose',
  'brake-shoe',
  'bulb-h7',
  'bulb-p21w',
  'bulb-w5w',
  'clutch-disc',
  'clutch-pressure-plate',
  'coolant-temperature-sensor',
  'drive-shaft',
  'engine-mount',
  'expansion-tank',
  'flywheel',
  'front-brake-pad',
  'front-lower-control-arm',
  'front-shock-absorber',
  'fuel-filter',
  'gasket-flat',
  'gasket-head',
  'gasket-manifold',
  'idler-pulley',
  'ignition-coil-pack',
  'injector',
  'jerrycan-5l',
  'lower-control-arm-bushing',
  'lower-radiator-hose',
  'oil-filter',
  'outer-tie-rod-end',
  'oxygen-sensor',
  'radiator',
  'rear-brake-pad',
  'rear-shock-absorber',
  'rear-wheel-bearing',
  'shaft-seal',
  'spark-plug',
  'starter',
  'alternator',
  'sway-bar-end-link',
  'tensioner-pulley',
  'thermostat',
  'throwout-bearing',
  'timing-belt',
  'timing-chain-kit',
  'valve-cover-gasket',
  'water-pump',
  'wiper-blade',
] as const;

export type PartFile = (typeof PART_FILES)[number];

const BY_SERVICE: Record<string, PartFile[]> = {
  clutch: ['clutch-disc', 'clutch-pressure-plate', 'flywheel'],
  timing_belt: ['timing-belt'],
  distribution: ['timing-belt'],
  oil_change: ['oil-filter'],
  oil_filter: ['oil-filter'],
  air_filter: ['air-filter'],
  fuel_filter: ['fuel-filter'],
  brake_pads: ['front-brake-pad'],
  brakes: ['front-brake-pad'],
  brake_discs: ['brake-disc'],
  spark_plug: ['spark-plug'],
  battery: ['battery'],
  water_pump: ['water-pump'],
  thermostat: ['thermostat'],
};

// A part row is read by its own words, specific phrase before generic word (a "joint de culasse" is not
// any joint, an oil filter is not oil). Nothing matched means no mesh: a placeholder beats a wrong part.
const WORDS: Array<[RegExp, PartFile]> = [
  [/bague d'?[ée]tanch[ée]it[ée]|joint spi|shaft seal|oil seal|lip seal/i, 'shaft-seal'],
  // A fluid is sold in a can, whatever circuit it is for ("liquide de frein — purge embrayage").
  [/^\s*(liquide|fluide)\b|\b(brake|coolant) fluid\b/i, 'jerrycan-5l'],
  [/joint de culasse|head gasket/i, 'gasket-head'],
  [/joint de collecteur|manifold gasket/i, 'gasket-manifold'],
  [/joint de (cache|couvre)[- ]culbuteurs?|valve cover gasket/i, 'valve-cover-gasket'],
  [/m[ée]canisme d'?embrayage|pressure plate/i, 'clutch-pressure-plate'],
  [/but[ée]e|throw-?out|release bearing/i, 'throwout-bearing'],
  [/volant (moteur|bi-?masse)|flywheel/i, 'flywheel'],
  [/(kit|disque) d'?embrayage|embrayage|clutch/i, 'clutch-disc'],
  [/filtre [àa] huile|oil filter/i, 'oil-filter'],
  [/filtre [àa] (gazole|carburant|essence)|fuel filter/i, 'fuel-filter'],
  [/filtre [àa] air|air filter|foam element|[ée]l[ée]ment filtrant/i, 'air-filter'],
  [/\b\d{1,2}W-?\d{2}\b/i, 'jerrycan-5l'],
  [/huile|\boil\b|lubrifiant|lubricant/i, 'jerrycan-5l'],
  [/cha[iî]ne de distribution|timing chain/i, 'timing-chain-kit'],
  [/courroie de distribution|kit de distribution|timing belt|cambelt/i, 'timing-belt'],
  [/galet tendeur|tensioner/i, 'tensioner-pulley'],
  [/galet (enrouleur|inverseur)|idler/i, 'idler-pulley'],
  [/pompe [àa] eau|water pump/i, 'water-pump'],
  [/thermostat/i, 'thermostat'],
  [/sonde (de )?temp[ée]rature|coolant temp/i, 'coolant-temperature-sensor'],
  [/sonde lambda|oxygen sensor|lambda/i, 'oxygen-sensor'],
  [/vase d'?expansion|expansion tank/i, 'expansion-tank'],
  [/durite|radiator hose|coolant hose/i, 'lower-radiator-hose'],
  [/radiateur|radiator/i, 'radiator'],
  [/plaquettes?[^,]*arri[èe]re|rear (brake )?pads?/i, 'rear-brake-pad'],
  [/plaquettes?|brake pads?/i, 'front-brake-pad'],
  [/disques? de frein|brake discs?|brake rotor/i, 'brake-disc'],
  [/tambour|brake drum/i, 'brake-drum'],
  [/m[âa]choires?|brake shoes?/i, 'brake-shoe'],
  [/flexible de frein|brake hose/i, 'brake-hose'],
  [/bougie|spark plug/i, 'spark-plug'],
  [/bobine|ignition coil/i, 'ignition-coil-pack'],
  [/injecteur|injector/i, 'injector'],
  [/batterie|battery/i, 'battery'],
  [/alternateur|alternator/i, 'alternator'],
  [/d[ée]marreur|starter/i, 'starter'],
  [/\bh7\b/i, 'bulb-h7'],
  [/\bp21w?\b/i, 'bulb-p21w'],
  [/\bw5w\b/i, 'bulb-w5w'],
  [/ampoule|\bbulb/i, 'bulb-h7'],
  [/rotule de direction int[ée]rieure|inner tie rod|rotule axiale|axial joint/i, 'axial-joint'],
  [/rotule de direction|tie rod end|outer tie rod/i, 'outer-tie-rod-end'],
  [/biellette|sway bar link|stabili[sz]er link/i, 'sway-bar-end-link'],
  [/amortisseur arri[èe]re|rear shock/i, 'rear-shock-absorber'],
  [/amortisseur|shock absorber/i, 'front-shock-absorber'],
  [/roulement de roue|wheel bearing|moyeu/i, 'rear-wheel-bearing'],
  [/silent-?bloc|bushing/i, 'lower-control-arm-bushing'],
  [/triangle|bras de suspension|control arm/i, 'front-lower-control-arm'],
  [/support moteur|engine mount/i, 'engine-mount'],
  [/cardan|drive ?shaft|arbre de transmission/i, 'drive-shaft'],
  [/essuie-?glace|wiper/i, 'wiper-blade'],
  [/joint|gasket/i, 'gasket-flat'],
];

const PART_BY_TYPE: Record<string, PartFile> = {
  oil_change: 'jerrycan-5l',
  oil_filter: 'oil-filter',
  air_filter: 'air-filter',
  fuel_filter: 'fuel-filter',
  thermostat: 'thermostat',
  water_pump: 'water-pump',
  coolant_temperature_sensor: 'coolant-temperature-sensor',
  temperature_sensor: 'coolant-temperature-sensor',
  engine_mount: 'engine-mount',
  spark_plug: 'spark-plug',
  ignition_coil: 'ignition-coil-pack',
  injector: 'injector',
  battery: 'battery',
  brake_pads: 'front-brake-pad',
  brake_discs: 'brake-disc',
  timing_belt: 'timing-belt',
  coolant: 'jerrycan-5l',
  brake_fluid: 'jerrycan-5l',
  clutch: 'clutch-disc',
};

export function meshesForService(type: string): PartFile[] {
  return BY_SERVICE[canonicalService(type)] ?? [];
}

export function stripNote(label: string): string {
  return label.replace(/\s*\([^)]*\)/g, '').trim();
}

export function meshForPart(part: Pick<PlannedPart, 'ref' | 'label' | 'brand'>, itemType?: string): PartFile | null {
  const words = [part.label ? stripNote(part.label) : '', part.brand].filter(Boolean).join(' ');
  const hit = WORDS.find(([match]) => match.test(words));
  if (hit) return hit[1];
  return itemType ? (PART_BY_TYPE[canonicalService(itemType)] ?? null) : null;
}

export function partUrl(file: PartFile): string {
  return `/parts/${file}.glb`;
}
