import { normalisePlate } from './format';
import type {
  Applicability,
  CarType,
  Category,
  Cluster,
  Dimension,
  Divergence,
  ErrorKind,
  FallbackBrand,
  Narrowing,
  PhotoIntake,
  ProductGroup,
  Reference,
  Registration,
  RegistrationField,
  Variant,
  Vehicle,
  VinDecode,
} from './types';

type Json = Record<string, unknown>;

const isObject = (v: unknown): v is Json => typeof v === 'object' && v !== null && !Array.isArray(v);
const obj = (v: unknown): Json => (isObject(v) ? v : {});
const list = (v: unknown): unknown[] => (Array.isArray(v) ? v : []);
const str = (v: unknown): string | null => (typeof v === 'string' && v.trim() !== '' ? v : null);
const num = (v: unknown): number | null => (typeof v === 'number' && Number.isFinite(v) ? v : null);
const int = (v: unknown, fallback = 0): number => num(v) ?? fallback;

const ERROR_MESSAGES: Record<ErrorKind, string> = {
  network: 'Serveur injoignable. Vérifiez la connexion et réessayez.',
  notFound: 'Introuvable dans le catalogue.',
  auth: 'Connexion requise.',
  quota: 'Quota du catalogue épuisé pour aujourd’hui. Réessayez demain.',
  upstream: 'Catalogue injoignable. Réessayez dans un instant.',
  invalid: 'Demande refusée par le serveur. Vérifiez la saisie.',
};

export class ApiError extends Error {
  constructor(public readonly kind: ErrorKind, message?: string) {
    super(message ?? ERROR_MESSAGES[kind]);
  }
}

export function classifyStatus(status: number): ErrorKind {
  if (status === 401 || status === 403) return 'auth';
  if (status === 404) return 'notFound';
  if (status === 429) return 'quota';
  if (status >= 500) return 'upstream';
  return 'invalid';
}

export function mapVehicle(raw: unknown): Vehicle {
  const j = obj(raw);
  const plate = str(j.plate);
  return {
    plate: plate === null ? null : (normalisePlate(plate) ?? plate),
    make: str(j.make),
    model: str(j.model),
    modelEn: str(j.modelEn),
    version: str(j.version),
    engineCode: str(j.engineCode),
    powerHp: num(j.powerHp),
    firstRegistration: str(j.firstRegistration),
    energy: str(j.energy),
    vin: str(j.vin),
    carId: num(j.carId),
    carType: carType(j.carType),
  };
}

const carType = (v: unknown): CarType => (v === 'CV' ? 'CV' : 'PC');

export function mapVin(raw: unknown): VinDecode {
  const j = obj(raw);
  return {
    vin: str(j.vin) ?? '',
    valid: j.valid === true,
    error: str(j.error),
    wmi: str(j.wmi),
    manufacturer: str(j.manufacturer),
    region: str(j.region),
    country: str(j.country),
    modelYear: num(j.modelYear),
    modelYearCandidates: list(j.modelYearCodeCandidates).filter((y): y is number => typeof y === 'number'),
  };
}

const members = (raw: unknown): unknown[] => {
  const j = obj(raw);
  return list(j['hydra:member'] ?? j.member);
};

export function mapCategories(raw: unknown): Category[] {
  return members(raw).flatMap((item) => {
    const j = obj(item);
    const id = num(j.id);
    const path = str(j.path);
    if (id === null || path === null) return [];
    const segments = path.split(' > ').map((s) => s.trim());
    return [{ id, name: str(j.name) ?? '', path, segments, label: segments.at(-1) ?? path, leaf: j.leaf === true }];
  });
}

const KNOWN_UNITS = ['mm', 'bar', 'cm', 'kg', 'nm', 'kw', 'v', 'w', 'a', 'l', 'ml', '°c', '°'];
const LENGTH_KEY = /^(diametre|hauteur|longueur|largeur|epaisseur|cote|entraxe)/;

function splitLabel(key: string, label: string): { label: string; unit: string | null } {
  const bracket = label.indexOf('[');
  if (bracket === -1) return { label: label.trim().replace(/'/g, '’'), unit: null };
  const clean = label.slice(0, bracket).trim().replace(/'/g, '’');
  const inside = label.slice(bracket + 1).replace(/\]$/, '').trim().toLowerCase();
  const closed = label.trimEnd().endsWith(']');
  let unit: string | null = null;
  if (closed && inside !== '') unit = inside;
  else if (inside !== '') unit = KNOWN_UNITS.find((u) => u.startsWith(inside)) ?? null;
  if (unit === null && LENGTH_KEY.test(key)) unit = 'mm';
  return { label: clean, unit };
}

function mapDimension(key: string, raw: unknown): Dimension | null {
  const j = obj(raw);
  const value = str(j.value);
  if (value === null) return null;
  const { label, unit } = splitLabel(key, str(j.label) ?? key);
  const number = /^-?\d+(\.\d+)?$/.test(value) ? Number(value) : null;
  return {
    key,
    label,
    value,
    number,
    unit: number === null ? null : unit,
    min: number === null ? null : (num(j.min) ?? number),
    max: number === null ? null : (num(j.max) ?? number),
    info: j.info === true,
  };
}

const mapReference = (raw: unknown): Reference | null => {
  const j = obj(raw);
  const brand = str(j.brand);
  const number = str(j.number);
  return brand && number ? { brand, number } : null;
};

function mapCluster(raw: unknown): Cluster | null {
  const j = obj(raw);
  const id = str(j.id);
  const rep = obj(j.representative);
  const repRef = mapReference(rep);
  if (id === null || repRef === null) return null;
  const dimensions = Object.entries(obj(j.fingerprint)).flatMap(([key, v]) => mapDimension(key, v) ?? []);
  const variants = list(j.variants).flatMap((v): Variant[] => {
    const ref = mapReference(v);
    const artId = num(obj(v).artId);
    return ref && artId !== null ? [{ artId, ...ref, tier: int(obj(v).tier, 3) }] : [];
  });
  return {
    id,
    dimensions,
    oeNumbers: list(j.oeNumbers).flatMap((r) => mapReference(r) ?? []),
    representative: { artId: int(rep.artId), ...repRef },
    variants,
    articleCount: int(j.articleCount),
    applicability: list(j.applicability).flatMap((a): Applicability[] => {
      const name = str(obj(a).name);
      const values = list(obj(a).values).flatMap((v) => (typeof v === 'string' && v.trim() !== '' ? [v.trim()] : []));
      return name && name.trim() !== '' && values.length > 0 ? [{ name: name.trim(), values }] : [];
    }),
  };
}

function mapDivergence(raw: unknown): Divergence | null {
  const j = obj(raw);
  const key = str(j.key);
  if (key === null) return null;
  const { label, unit } = splitLabel(key, str(j.label) ?? key);
  const values = list(j.values).flatMap((v) => {
    const clusterId = str(obj(v).clusterId);
    const value = str(obj(v).value);
    return clusterId && value ? [{ clusterId, value }] : [];
  });
  const numeric = values.every((v) => /^-?\d+(\.\d+)?$/.test(v.value));
  return { key, label, unit: numeric ? unit : null, values };
}

const mapFallbackBrand = (raw: unknown): FallbackBrand | null => {
  const j = obj(raw);
  const brand = str(j.brand);
  if (brand === null) return null;
  const articles = list(j.articles).flatMap((a) => {
    const id = num(obj(a).id);
    const number = str(obj(a).number);
    return id !== null && number ? [{ id, number }] : [];
  });
  return { brand, tier: int(j.tier, 3), articles };
};

const mapProductGroup = (raw: unknown): ProductGroup | null => {
  const j = obj(raw);
  const id = num(j.id);
  const name = str(j.name);
  return id !== null && Number.isInteger(id) && id > 0 && name ? { id, name } : null;
};

export function mapNarrowing(raw: unknown): Narrowing {
  const j = obj(raw);
  return {
    carId: int(j.carId),
    categoryId: int(j.categoryId),
    productGroup: mapProductGroup(j.productGroup),
    productGroups: list(j.productGroups).flatMap((g) => {
      const group = mapProductGroup(g);
      return group ? [{ ...group, articleCount: int(obj(g).articleCount) }] : [];
    }),
    groupRequired: j.groupRequired === true,
    clusters: list(j.clusters).flatMap((c) => mapCluster(c) ?? []),
    totalArticles: int(j.totalArticles),
    totalBrands: int(j.totalBrands),
    unclassifiedCount: int(j.unclassifiedCount),
    differsOn: list(j.differsOn).flatMap((d) => mapDivergence(d) ?? []),
    narrowed: j.narrowed !== false,
    degradedReason: str(j.degradedReason),
    upstreamCalls: int(j.upstreamCalls),
    fallbackBrands: list(j.fallbackBrands).flatMap((b) => mapFallbackBrand(b) ?? []),
  };
}

const REGISTRATION_LABELS: [string, string][] = [
  ['A', 'Immatriculation'],
  ['B', 'Première immatriculation'],
  ['D.1', 'Marque'],
  ['D.2', 'Type, variante, version'],
  ['D.2.1', 'Code national (CNIT)'],
  ['D.3', 'Dénomination commerciale'],
  ['E', 'Numéro VIN'],
  ['J.1', 'Genre national'],
  ['K', 'Numéro de réception'],
  ['P.1', 'Cylindrée (cm³)'],
  ['P.2', 'Puissance nette (kW)'],
  ['P.3', 'Énergie'],
  ['P.6', 'Puissance fiscale (CV)'],
];

export function mapRegistration(raw: unknown): Registration {
  const j = obj(raw);
  const fields: RegistrationField[] = REGISTRATION_LABELS.flatMap(([code, label]) => {
    const value = str(j[code]);
    return value === null ? [] : [{ code, label, value: value.trim() }];
  });
  const plate = str(j.A);
  const vin = str(j.E);
  return {
    plate: plate === null ? null : normalisePlate(plate),
    vin: vin === null ? null : vin.toUpperCase().replace(/\s/g, ''),
    fields,
    legible: j.legible === true,
    warnings: list(j.warnings).filter((w): w is string => typeof w === 'string'),
  };
}

export function mapPhotoIntake(raw: unknown): PhotoIntake {
  const j = obj(raw);
  if (j.kind === 'carte_grise' && isObject(j.registration)) return { kind: 'carte_grise', registration: mapRegistration(j.registration) };
  if (j.kind === 'part' && isObject(j.part)) {
    const p = j.part;
    return {
      kind: 'part',
      part: {
        familyGuess: str(p.familyGuess),
        printedReferences: list(p.printedReferences).filter((r): r is string => typeof r === 'string' && r.trim() !== ''),
        brandGuess: str(p.brandGuess),
      },
    };
  }
  if (j.kind === 'build_label' && isObject(j.buildLabel)) {
    const b = j.buildLabel;
    const opr = str(b.opr);
    return {
      kind: 'build_label',
      buildLabel: {
        opr: opr === null || opr.trim() === '' ? null : opr.trim(),
        printedCodes: list(b.printedCodes).flatMap((c) => (typeof c === 'string' && c.trim() !== '' ? [c.trim()] : [])),
      },
    };
  }
  return { kind: 'other' };
}
