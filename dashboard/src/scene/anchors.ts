import type { BodyClass } from './CarProxy';
import { DIMS } from './CarProxy';
import type { MercureFrame } from '../types';

// ─────────────────────────────────────────────────────────────────────────────
// The shared geometry seam for every 3D Plateau leaf (Hotspots, BodyGlow, RimGauge).
//
// DESIGN-VISION §2 / BUILD-NOTE 2: "Hotspot anchors are defined per body-class, not per exact mesh,
// so they map onto the proxy." This module is that single source: it maps a backend service `type`
// to the body region you read it off, and gives each region a 3D anchor derived from the SAME
// CarProxy DIMS the blueprint renders from — so pin / glow / arc never drift from the car.
//
// Coordinate space = the car group's local frame (turntable origin), +x = front of the vehicle,
// +y = up, the floor at y≈0. Approximate (the proxy is a crude blueprint by design); precise
// per-mesh anchoring waits for the real .glb in Stage D.
// ─────────────────────────────────────────────────────────────────────────────

export type BodyRegion = 'hood' | 'windshield' | 'frontAxle' | 'rearAxle' | 'bay';

/** Every region key, in head-to-tail reading order. */
export const BODY_REGIONS: BodyRegion[] = ['hood', 'windshield', 'frontAxle', 'rearAxle', 'bay'];

/** A French region heading for the grouped hotspot card (the per-service rows carry the real names). */
export const REGION_LABEL: Record<BodyRegion, string> = {
  hood: 'Moteur',
  windshield: 'Contrôle',
  frontAxle: 'Freins · Pneus',
  rearAxle: 'Transmission',
  bay: 'Filtration',
};

// snake_case service type → the body region it is read off. Unmapped types default to the hood.
const TYPE_REGION: Record<string, BodyRegion> = {
  oil_change: 'hood',
  oil_filter: 'hood',
  timing_belt: 'hood',
  coolant: 'hood',
  spark_plugs: 'hood',
  spark_plug: 'hood', // backend's singular key (labels.ts) — same region as spark_plugs
  air_filter: 'bay',
  cabin_filter: 'bay',
  fuel_filter: 'bay',
  battery: 'bay',
  brake_fluid: 'frontAxle',
  brake_pads: 'frontAxle',
  brake_discs: 'frontAxle',
  tyres: 'frontAxle',
  tires: 'frontAxle', // backend spelling variant
  technical_inspection: 'windshield',
  inspection: 'windshield', // the backend's live periodic-inspection key (labels.ts) — was defaulting to hood
  chain: 'rearAxle',
};

/** Where on the car you read a given service type. Total + never throws (unmapped → hood). */
export function regionForType(type: string): BodyRegion {
  return TYPE_REGION[type] ?? 'hood';
}

/**
 * The car regions a spotlight should light for the given keys, deduped in head-to-tail order. Only
 * KNOWN service types light a region — a pure fact key (oil_capacity) maps to nothing, so a spec
 * question never glows the hood by the unmapped-→hood fallback.
 */
export function regionsForKeys(keys: string[]): BodyRegion[] {
  const set = new Set<BodyRegion>();
  for (const k of keys) {
    const region = TYPE_REGION[k];
    if (region) set.add(region);
  }
  return BODY_REGIONS.filter((r) => set.has(r));
}

/** The service types read off a region — the inverse of {@see regionForType} (for a pin → tasks focus). */
export function typesForRegion(region: BodyRegion): string[] {
  return Object.entries(TYPE_REGION)
    .filter(([, r]) => r === region)
    .map(([type]) => type);
}

/**
 * The car region an incoming agent Mercure frame acts on, or null — the trigger for the PULSE verb.
 * Only a session frame that carries service-type keys (focusView / showFact) targets a region: the first
 * KNOWN service region those keys read off ({@see regionsForKeys} already drops fact-only keys like
 * oil_capacity, so a spec question pulses nothing). Everything else — domain writes, status prose,
 * toasts, keyless focus — yields null. Best-effort by design: an unknown tool/type simply does not pulse.
 *
 * NOTE: the wire carries no structured tool name/arguments to the client (the narrator publishes prose-
 * only `status` frames), so these keys are the sole structured service-type signal available.
 */
export function regionForFrame(frame: MercureFrame): BodyRegion | null {
  if (!('type' in frame)) return null; // a DomainEvent (garage write), not a targeted intent
  const keys = frame.type === 'focusView' || frame.type === 'showFact' ? frame.keys : undefined;
  if (!keys || keys.length === 0) return null;
  return regionsForKeys(keys)[0] ?? null;
}

export type Anchor = [number, number, number];

/**
 * Per-body-class 3D anchor for each region, derived from CarProxy DIMS (cars) or the Moto layout.
 * The numbers track CarProxy's own body/cabin/wheel math so a pin sits over the part it names.
 */
export function regionAnchors(bodyClass: BodyClass): Record<BodyRegion, Anchor> {
  if (bodyClass === 'moto') {
    const r = 0.32;
    const axleX = 0.72;
    const seatY = r + 0.34; // matches Moto's frame box centre
    return {
      hood: [0.05, seatY + 0.32, 0], // the engine/tank mass
      windshield: [0.55, seatY + 0.46, 0],
      frontAxle: [axleX, r + 0.06, 0.05],
      rearAxle: [-axleX, r + 0.06, 0.05],
      bay: [0.0, seatY + 0.12, 0],
    };
  }
  const d = DIMS[bodyClass];
  const axleX = d.len / 2 - d.wheelR - 0.1; // CarProxy axle placement
  const axleZ = d.wid / 2 - 0.02;
  const bodyTop = d.wheelR + d.bodyH; // top face of the lower body box
  const cabinTop = bodyTop + d.cabinH - 0.06; // top of the greenhouse box
  return {
    hood: [d.len * 0.32, bodyTop + 0.14, 0], // front-top of the body, ahead of the cabin
    bay: [d.len * 0.16, bodyTop + 0.1, 0], // engine bay, just behind the hood line
    windshield: [d.cabinOff + d.cabinLen * 0.42, cabinTop + 0.04, 0], // front of the greenhouse
    frontAxle: [axleX, d.wheelR + 0.06, axleZ + 0.06], // near-side front wheel
    rearAxle: [-axleX, d.wheelR + 0.06, axleZ + 0.06], // near-side rear wheel
  };
}
