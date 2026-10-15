export type CarType = 'PC' | 'CV';

export interface Vehicle {
  plate: string;
  make: string | null;
  model: string | null;
  modelEn: string | null;
  version: string | null;
  engineCode: string | null;
  powerHp: number | null;
  firstRegistration: string | null;
  energy: string | null;
  vin: string | null;
  carId: number | null;
  carType: CarType;
}

export interface VinDecode {
  vin: string;
  valid: boolean;
  error: string | null;
  wmi: string | null;
  manufacturer: string | null;
  region: string | null;
  country: string | null;
  modelYear: number | null;
  modelYearCandidates: number[];
}

export interface Category {
  id: number;
  name: string;
  path: string;
  segments: string[];
  label: string;
  leaf: boolean;
}

export interface Dimension {
  key: string;
  label: string;
  value: string;
  number: number | null;
  unit: string | null;
  min: number | null;
  max: number | null;
  info: boolean;
}

export interface Reference {
  brand: string;
  number: string;
}

export interface Variant extends Reference {
  artId: number;
  tier: number;
}

export interface Representative extends Reference {
  artId: number;
}

export interface Cluster {
  id: string;
  dimensions: Dimension[];
  oeNumbers: Reference[];
  representative: Representative;
  variants: Variant[];
  articleCount: number;
}

export interface Divergence {
  key: string;
  label: string;
  unit: string | null;
  values: { clusterId: string; value: string }[];
}

export interface FallbackBrand {
  brand: string;
  tier: number;
  articles: { id: number; number: string }[];
}

export interface Narrowing {
  carId: number;
  categoryId: number;
  clusters: Cluster[];
  totalArticles: number;
  totalBrands: number;
  unclassifiedCount: number;
  differsOn: Divergence[];
  narrowed: boolean;
  degradedReason: string | null;
  upstreamCalls: number;
  fallbackBrands: FallbackBrand[];
}

export interface RegistrationField {
  code: string;
  label: string;
  value: string;
}

export interface Registration {
  plate: string | null;
  vin: string | null;
  fields: RegistrationField[];
  legible: boolean;
  warnings: string[];
}

export type ErrorKind = 'network' | 'notFound' | 'auth' | 'quota' | 'upstream' | 'invalid';

export type Family =
  | { kind: 'brake'; axle: 'front' | 'rear' }
  | { kind: 'filter'; filter: 'oil' | 'fuel' | 'air' }
  | { kind: 'none' };
