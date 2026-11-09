import { regionForType, type BodyRegion } from '../scene/anchors';
import { canonicalService } from '../labels';

export interface FocusTopic {
  /** The service type the text or frame is about — what the ring's label names. */
  topic: string;
  region: BodyRegion;
}

// anchors.ts has no transmission region; the clutch and gearbox are read off the front axle.
const SERVICE_REGION: Record<string, BodyRegion> = {
  clutch: 'frontAxle',
  gearbox_oil: 'frontAxle',
  flywheel: 'frontAxle',
};

// First match wins, so the specific phrase sits above the general one (rear brakes before brakes).
const WORDS: Array<{ match: RegExp; topic: string; region: BodyRegion }> = [
  { match: /embrayage|clutch|volant (bi-?masse|moteur)|flywheel/i, topic: 'clutch', region: 'frontAxle' },
  { match: /boîte de vitesses|boite de vitesses|gearbox|transmission/i, topic: 'gearbox_oil', region: 'frontAxle' },
  { match: /courroie|distribution|timing belt|cambelt/i, topic: 'timing_belt', region: 'hood' },
  { match: /vidange|huile moteur|filtre à huile|oil change|oil filter/i, topic: 'oil_change', region: 'hood' },
  { match: /bougie|spark plug/i, topic: 'spark_plug', region: 'hood' },
  { match: /pompe à eau|water pump/i, topic: 'water_pump', region: 'hood' },
  { match: /filtre à air|air filter/i, topic: 'air_filter', region: 'bay' },
  { match: /batterie|battery/i, topic: 'battery', region: 'bay' },
  { match: /filtre à carburant|fuel filter/i, topic: 'fuel_filter', region: 'bay' },
  { match: /(plaquettes|frein|disques?)[^.]*arrière|rear (brake|pad|disc)/i, topic: 'brake_pads', region: 'rearAxle' },
  { match: /plaquettes|brake pad/i, topic: 'brake_pads', region: 'frontAxle' },
  { match: /disques? de frein|brake disc/i, topic: 'brake_discs', region: 'frontAxle' },
  { match: /liquide de frein|brake fluid/i, topic: 'brake_fluid', region: 'frontAxle' },
  { match: /pneu|tyre|tire/i, topic: 'tyres', region: 'frontAxle' },
  { match: /chaîne|chain/i, topic: 'chain', region: 'rearAxle' },
  { match: /contrôle technique|roadworthiness|inspection/i, topic: 'inspection', region: 'windshield' },
];

export function topicForText(text: string): FocusTopic | null {
  if (!text) return null;
  const hit = WORDS.find((w) => w.match.test(text));
  return hit ? { topic: hit.topic, region: hit.region } : null;
}

export function topicForService(type: string): FocusTopic {
  const topic = canonicalService(type);
  return { topic, region: SERVICE_REGION[topic] ?? regionForType(topic) };
}
