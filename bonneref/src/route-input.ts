import { normaliseFni, normalisePlate } from './format';

// The single finder input, routed cheapest first: local rules (plate, VIN, reference shape)
// before anything reaches the network, the agent for the rest.

export type TextRoute =
  | { kind: 'plate'; plate: string; format: 'siv' | 'fni' }
  | { kind: 'vin'; vin: string }
  | { kind: 'reference'; reference: string }
  | { kind: 'text'; text: string }
  | { kind: 'empty' };

export type FileRoute = { kind: 'image' } | { kind: 'video' } | { kind: 'pdf' } | { kind: 'unsupported'; reason: 'type' | 'size' };

const MAX_DOCUMENT_BYTES = 10 * 1024 * 1024;

const VIN = /^[A-HJ-NPR-Z0-9]{17}$/;
const PLATE_CHARS = /^[A-Za-z0-9 -]+$/;
const REFERENCE_CHARS = /^[A-Za-z0-9][A-Za-z0-9 ./-]*$/;
const DISPLACEMENT = /^\d[.,]\d$/;
// Engine and unit words: "307 1.4 hdi" or "clio 3 dci 105" carry digits like a reference but name a car.
const VEHICLE_WORDS = new Set([
  'hdi', 'bluehdi', 'tdi', 'tdci', 'dci', 'cdi', 'crdi', 'jtd', 'jtdm', 'mjet', 'tce', 'vti', 'thp', 'tsi', 'tfsi', 'fsi', 'gti', 'mzr',
  'puretech', 'ecoblue', 'ecoboost', 'turbo', 'diesel', 'essence', 'mm', 'cm', 'cv', 'ch', 'kw', 'l',
]);

const compact = (raw: string): string => raw.toUpperCase().replace(/[\s-]/g, '');

function plate(raw: string): TextRoute | null {
  if (!PLATE_CHARS.test(raw) || raw.length > 12) return null;
  const siv = normalisePlate(raw);
  if (siv) return { kind: 'plate', plate: siv, format: 'siv' };
  const fni = normaliseFni(raw);
  return fni ? { kind: 'plate', plate: fni, format: 'fni' } : null;
}

function isReference(raw: string): boolean {
  if (raw.length < 5 || raw.length > 20 || !REFERENCE_CHARS.test(raw)) return false;
  if ((raw.match(/\d/g) ?? []).length < 3) return false;
  return raw.split(/\s+/).every((token) => {
    const t = token.toLowerCase();
    if (DISPLACEMENT.test(t) || VEHICLE_WORDS.has(t)) return false;
    return !/^[a-zà-ÿ]{5,}$/i.test(t);
  });
}

export function routeText(input: string): TextRoute {
  const raw = input.trim().replace(/\s+/g, ' ');
  if (raw === '') return { kind: 'empty' };

  const p = plate(raw);
  if (p) return p;

  if (PLATE_CHARS.test(raw) && VIN.test(compact(raw))) return { kind: 'vin', vin: compact(raw) };

  if (isReference(raw)) return { kind: 'reference', reference: raw };

  return { kind: 'text', text: raw };
}

export function routeFile(file: { type: string; size: number }): FileRoute {
  if (file.type.startsWith('image/')) return { kind: 'image' };
  if (file.type.startsWith('video/')) return { kind: 'video' };
  if (file.type === 'application/pdf') return file.size > MAX_DOCUMENT_BYTES ? { kind: 'unsupported', reason: 'size' } : { kind: 'pdf' };
  return { kind: 'unsupported', reason: 'type' };
}

export interface ExtractedRef {
  plate: string | null;
  vin: string | null;
  rest: string;
}

// A plate or VIN typed among other words ("AB-123-CD plaquettes"): the first run of up to three tokens the
// single-input rules read as one, the remaining words being the part query.
export function extractVehicleRef(input: string): ExtractedRef {
  const tokens = input.trim().replace(/\s+/g, ' ').split(' ').filter(Boolean);
  for (let start = 0; start < tokens.length; start++) {
    for (let size = Math.min(3, tokens.length - start); size >= 1; size--) {
      const found = routeText(tokens.slice(start, start + size).join(' '));
      if (found.kind !== 'plate' && found.kind !== 'vin') continue;
      const rest = [...tokens.slice(0, start), ...tokens.slice(start + size)].join(' ');
      return found.kind === 'plate' ? { plate: found.plate, vin: null, rest } : { plate: null, vin: found.vin, rest };
    }
  }
  return { plate: null, vin: null, rest: tokens.join(' ') };
}
