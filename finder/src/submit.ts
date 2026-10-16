import { ApiError } from './api';
import type { FinderApi } from './client';
import { carRef } from './route';
import { saveBody, vehicleShortName, type RegistrationBody } from './garage';
import type { TextRoute } from './route-input';
import type { BuildLabel, PartPhotoHints, Registration, TurnIntent, Vehicle } from './types';

export type Reading =
  | { kind: 'carte_grise'; registration: Registration }
  | { kind: 'part'; part: PartPhotoHints; images: string[] }
  | { kind: 'build_label'; buildLabel: BuildLabel }
  | { kind: 'other' };

// A turn to start once the route's vehicle is loaded.
export interface PendingAsk {
  text: string;
  intent?: TurnIntent;
  images?: string[];
  photoHints?: PartPhotoHints;
  buildLabel?: BuildLabel;
}

export type SubmitOutcome =
  | { kind: 'vehicle'; ref: string; vehicle: Vehicle; saved: boolean; ask: PendingAsk | null }
  | { kind: 'ask'; ask: PendingAsk }
  | { kind: 'stopped'; message: string; keepText: boolean };

export interface SubmitDeps {
  api: Pick<FinderApi, 'vehicle' | 'saveVehicle'>;
  read: (file: File) => Promise<Reading>;
  status: (text: string | null) => void;
}

export interface Submission {
  route: TextRoute;
  files: File[];
}

const REGISTRATION_KEYS: [string, keyof RegistrationBody][] = [
  ['E', 'vin'],
  ['B', 'firstRegistration'],
  ['D.2.1', 'cnit'],
  ['P.1', 'displacement'],
  ['P.2', 'powerKw'],
  ['P.3', 'energy'],
  ['P.6', 'fiscalPower'],
];

export function registrationBody(r: Registration): RegistrationBody {
  const body: RegistrationBody = {};
  for (const [code, key] of REGISTRATION_KEYS) {
    const value = r.fields.find((f) => f.code === code)?.value.trim();
    if (value) body[key] = value;
  }
  return body;
}

const PHOTO_INSTRUCTION = 'Photo de la pièce';

const field = (r: Registration, code: string): string | null => r.fields.find((f) => f.code === code)?.value.trim() || null;

const typedAsk = (route: TextRoute): PendingAsk | null => {
  if (route.kind === 'text') return { text: route.text };
  if (route.kind === 'reference') return { text: route.reference, intent: 'reference' };
  return null;
};

function photoAsk(readings: Reading[], route: TextRoute): PendingAsk | null {
  const typed = typedAsk(route);
  const parts = readings.filter((r): r is Extract<Reading, { kind: 'part' }> => r.kind === 'part');
  const label = readings.find((r): r is Extract<Reading, { kind: 'build_label' }> => r.kind === 'build_label');
  if (parts.length > 0) {
    return { text: typed?.text ?? PHOTO_INSTRUCTION, intent: 'photo', images: parts.flatMap((p) => p.images), photoHints: parts[0]!.part };
  }
  if (label) {
    const code = label.buildLabel.opr ?? label.buildLabel.printedCodes.join(' · ');
    if (code !== '') return { text: `Code de fabrication : ${code}`, buildLabel: label.buildLabel };
  }
  return typed;
}

// The one pipeline behind Chercher: read every attachment, then, when a carte grise is among them, look the
// plate up, save the car with its facts, and hand back the route to open and the turn to run on it.
export async function runSubmission({ route, files }: Submission, deps: SubmitDeps): Promise<SubmitOutcome> {
  const readings: Reading[] = [];
  for (const file of files) {
    deps.status(file.type === 'application/pdf' ? 'Lecture de la carte grise…' : 'Lecture de la photo…');
    readings.push(await deps.read(file));
  }

  const card = readings.find((r): r is Extract<Reading, { kind: 'carte_grise' }> => r.kind === 'carte_grise');
  if (!card) {
    const ask = photoAsk(readings, route);
    if (ask) return { kind: 'ask', ask };
    return { kind: 'stopped', message: 'Photo non reconnue. Cadrez la pièce, la carte grise ou la vignette de plus près.', keepText: true };
  }

  const reg = card.registration;
  if (!reg.plate) return { kind: 'stopped', message: 'Plaque (case A) illisible sur la carte grise. Saisissez-la.', keepText: true };

  let found: Vehicle;
  try {
    found = await deps.api.vehicle(reg.plate);
  } catch (e) {
    if (!(e instanceof ApiError) || e.kind !== 'notFound') throw e;
    const make = field(reg, 'D.1');
    const model = field(reg, 'D.3') ?? field(reg, 'D.2');
    if (reg.vin && make && model) {
      await deps.api.saveVehicle({ make, model, carId: null, plate: null, vin: reg.vin, registration: registrationBody(reg) }).catch(() => undefined);
    }
    return { kind: 'stopped', message: `Plaque ${reg.plate} introuvable dans le catalogue. Vérifiez la carte grise ou saisissez la plaque.`, keepText: true };
  }

  let saved = true;
  try {
    await deps.api.saveVehicle({ ...saveBody(found), vin: reg.vin ?? found.vin, registration: registrationBody(reg) });
  } catch {
    saved = false;
  }

  deps.status(`Véhicule : ${vehicleShortName(found) ?? found.plate ?? reg.plate}`);
  const ref = found.plate ?? (found.carId !== null ? carRef(found.carId, found.carType) : reg.plate);
  return { kind: 'vehicle', ref, vehicle: found, saved, ask: photoAsk(readings, route) };
}
