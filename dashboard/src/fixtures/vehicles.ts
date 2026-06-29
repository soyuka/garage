import type { VehicleBundle, VehicleSummary } from '../types';

// Typed Stage-B fixtures — NO backend, NO Mercure. Components consume the typed interfaces, never
// loose JSON. Five switchable scenarios exercise every honest-floor + look state: healthy / a service
// ambre / overdue rouge / empty parc / no-3D-asset (a body-class with no .glb → degrades to SVG/list).

export type Scenario = 'healthy' | 'ambre' | 'overdue' | 'noAsset' | 'unknown' | 'empty';

// A scenario's data is exactly the live VehicleBundle shape — fixtures and the API converge here.
export type ScenarioBundle = VehicleBundle;

export const SCENARIOS: { id: Scenario; label: string }[] = [
  { id: 'healthy', label: 'Sain (vert)' },
  { id: 'ambre', label: 'Service dû (ambre)' },
  { id: 'overdue', label: 'En retard (rouge)' },
  { id: 'noAsset', label: 'Sans modèle 3D' },
  { id: 'unknown', label: 'Non suivi (gris)' },
  { id: 'empty', label: 'Parc vide' },
];

// ── healthy — Renault Clio IV, everything current ────────────────────────────
const healthy: ScenarioBundle = {
  vehicle: {
    '@id': '/garage/vehicles/01HEALTHYCLIO',
    make: 'Renault',
    model: 'Clio IV',
    year: 2016,
    engineVariant: '1.5 dCi 90',
    engineCode: 'K9K',
    vehicleType: 'car',
    source: 'verified',
    plate: 'AB-123-CD',
    usageValue: 142000,
    usageUnit: 'km',
    factCount: 4,
    openTasks: 0,
    facts: [
      { key: 'engine', value: '1.5 dCi 90', source: 'partsapi', verified: true },
      { key: 'oil_viscosity', value: '5W-30', source: 'partsapi', verified: true },
      { key: 'oil_standard', value: 'ACEA C4', source: 'partsapi', verified: true },
      { key: 'tyre_size', value: '195/55 R16', source: 'siv', verified: true },
    ],
    health: { status: 'green', score: 92, overdue: 0, dueSoon: 0, tracked: 4, gaps: 0 },
    nextDue: {
      type: 'oil_change',
      action: 'change',
      label: 'Vidange',
      rule: 'schedule',
      overdue: false,
      dueAt: null,
      projectedDueAt: '2026-12-01',
      daysUntilDue: 155,
      usageRemaining: 6000,
      usageUnit: 'km',
    },
    plannedTasks: [],
    addedAt: '2025-11-02',
  },
  forecast: {
    vehicle: '/garage/vehicles/01HEALTHYCLIO',
    due: [
      {
        type: 'oil_change',
        action: 'change',
        component: null,
        rule: 'schedule',
        lastPerformedAt: '2025-06-01',
        dueAt: null,
        projectedDueAt: '2026-12-01',
        overdue: false,
        daysUntilDue: 155,
        usage: { last: 136000, due: 148000, remaining: 6000, unit: 'km' },
      },
      {
        type: 'technical_inspection',
        action: 'inspect',
        component: null,
        rule: 'schedule',
        lastPerformedAt: '2024-09-12',
        dueAt: '2026-09-12',
        projectedDueAt: null,
        overdue: false,
        daysUntilDue: 75,
        usage: null,
      },
    ],
    gaps: [],
  },
  records: [
    {
      id: '/garage/maintenance-records/01RECCLIO',
      items: [{ type: 'oil_change', label: 'Vidange + filtre', parts: [{ ref: 'MANN W 712/75', brand: 'MANN' }] }],
      performedAt: '2025-06-01',
      usageValue: 136000,
      usageUnit: 'km',
      costCents: 8900,
      currency: 'EUR',
      source: 'user',
    },
  ],
};

// ── ambre — Peugeot 308, vidange due soon (within 10% of interval) ───────────
const ambre: ScenarioBundle = {
  vehicle: {
    '@id': '/garage/vehicles/01AMBRE308',
    make: 'Peugeot',
    model: '308',
    year: 2019,
    engineVariant: '1.2 PureTech 130',
    engineCode: 'EB2',
    vehicleType: 'car',
    source: 'verified',
    plate: 'CD-456-EF',
    usageValue: 89200,
    usageUnit: 'km',
    factCount: 3,
    openTasks: 1,
    facts: [
      { key: 'engine', value: '1.2 PureTech 130', source: 'partsapi', verified: true },
      { key: 'oil_viscosity', value: '0W-20', source: 'partsapi', verified: true },
      { key: 'tyre_size', value: '205/55 R16', source: 'user', verified: false },
    ],
    health: { status: 'amber', score: 68, overdue: 0, dueSoon: 1, tracked: 3, gaps: 1 },
    nextDue: {
      type: 'oil_change',
      action: 'change',
      label: 'Vidange',
      rule: 'schedule',
      overdue: false,
      dueAt: null,
      projectedDueAt: '2026-08-20',
      daysUntilDue: 52,
      usageRemaining: 800,
      usageUnit: 'km',
    },
    plannedTasks: [
      { id: '/garage/planned-tasks/01TASKAMBRE', type: 'oil_change', label: 'Vidange', target: { value: 90000, unit: 'km' }, dueDate: null, parts: [], status: 'open' },
    ],
    addedAt: '2025-10-18',
  },
  forecast: {
    vehicle: '/garage/vehicles/01AMBRE308',
    due: [
      {
        type: 'oil_change',
        action: 'change',
        component: null,
        rule: 'schedule',
        lastPerformedAt: '2025-01-10',
        dueAt: null,
        projectedDueAt: '2026-08-20',
        overdue: false,
        daysUntilDue: 52,
        usage: { last: 79200, due: 90000, remaining: 800, unit: 'km' },
      },
      {
        type: 'brake_fluid',
        action: 'change',
        component: 'brake_fluid',
        rule: 'schedule',
        lastPerformedAt: '2023-05-01',
        dueAt: '2026-07-15',
        projectedDueAt: null,
        overdue: false,
        daysUntilDue: 16,
        usage: null,
      },
    ],
    gaps: ['air_filter'],
  },
  records: [],
};

// ── overdue — Renault Espace, vidange PASSED by 3 200 km (the precise-value scenario) ──
const overdue: ScenarioBundle = {
  vehicle: {
    '@id': '/garage/vehicles/01OVERDUEESPACE',
    make: 'Renault',
    model: 'Espace V',
    year: 2017,
    engineVariant: '1.6 dCi 160',
    engineCode: 'R9M',
    vehicleType: 'car',
    source: 'verified',
    plate: 'EF-789-GH',
    usageValue: 142000,
    usageUnit: 'km',
    factCount: 3,
    openTasks: 2,
    facts: [
      { key: 'engine', value: '1.6 dCi 160', source: 'partsapi', verified: true },
      { key: 'oil_viscosity', value: '5W-30', source: 'partsapi', verified: true },
      { key: 'timing_belt', value: 'chaîne', source: 'user', verified: false },
    ],
    health: { status: 'red', score: 34, overdue: 1, dueSoon: 1, tracked: 3, gaps: 0 },
    nextDue: {
      type: 'oil_change',
      action: 'change',
      label: 'Vidange',
      rule: 'schedule',
      overdue: true,
      dueAt: null,
      projectedDueAt: '2026-04-10',
      daysUntilDue: -80,
      usageRemaining: -3200,
      usageUnit: 'km',
    },
    plannedTasks: [
      { id: '/garage/planned-tasks/01TASKOVER', type: 'oil_change', label: 'Vidange', target: { value: 138800, unit: 'km' }, dueDate: null, parts: [], status: 'open' },
    ],
    addedAt: '2025-09-30',
  },
  forecast: {
    vehicle: '/garage/vehicles/01OVERDUEESPACE',
    due: [
      {
        type: 'oil_change',
        action: 'change',
        component: null,
        rule: 'schedule',
        lastPerformedAt: '2024-10-01',
        dueAt: null,
        projectedDueAt: '2026-04-10',
        overdue: true,
        daysUntilDue: -80,
        usage: { last: 128800, due: 138800, remaining: -3200, unit: 'km' },
      },
      {
        type: 'air_filter',
        action: 'replace',
        component: 'air_filter',
        rule: 'schedule',
        lastPerformedAt: '2025-02-01',
        dueAt: null,
        projectedDueAt: '2026-07-25',
        overdue: false,
        daysUntilDue: 26,
        usage: { last: 132000, due: 144000, remaining: 2000, unit: 'km' },
      },
    ],
    gaps: [],
  },
  records: [
    {
      id: '/garage/maintenance-records/01RECESPACE',
      items: [{ type: 'oil_change', label: 'Vidange', parts: [] }],
      performedAt: '2024-10-01',
      usageValue: 128800,
      usageUnit: 'km',
      costCents: 11500,
      currency: 'EUR',
      source: 'user',
    },
  ],
};

// ── noAsset — a motorcycle: full data, but its body-class has no .glb (→ SVG/list rung in B.2) ──
const noAsset: ScenarioBundle = {
  vehicle: {
    '@id': '/garage/vehicles/01NOASSETMOTO',
    make: 'Yamaha',
    model: 'MT-07',
    year: 2021,
    engineVariant: '689 cm³',
    engineCode: 'CP2',
    vehicleType: 'motorcycle',
    source: 'verified',
    plate: 'GH-012-IJ',
    usageValue: 23400,
    usageUnit: 'km',
    factCount: 2,
    openTasks: 1,
    facts: [
      { key: 'engine', value: '689 cm³ CP2', source: 'partsapi', verified: true },
      { key: 'oil_viscosity', value: '10W-40', source: 'user', verified: false },
    ],
    health: { status: 'amber', score: 74, overdue: 0, dueSoon: 1, tracked: 2, gaps: 0 },
    nextDue: {
      type: 'chain',
      action: 'inspect',
      label: 'Chaîne',
      rule: 'schedule',
      overdue: false,
      dueAt: null,
      projectedDueAt: '2026-07-30',
      daysUntilDue: 31,
      usageRemaining: 600,
      usageUnit: 'km',
    },
    plannedTasks: [
      { id: '/garage/planned-tasks/01TASKMOTO', type: 'chain', label: 'Tension de chaîne', target: { value: 24000, unit: 'km' }, dueDate: null, parts: [], status: 'open' },
    ],
    addedAt: '2025-12-01',
  },
  forecast: {
    vehicle: '/garage/vehicles/01NOASSETMOTO',
    due: [
      {
        type: 'chain',
        action: 'inspect',
        component: 'chain',
        rule: 'schedule',
        lastPerformedAt: '2025-12-01',
        dueAt: null,
        projectedDueAt: '2026-07-30',
        overdue: false,
        daysUntilDue: 31,
        usage: { last: 18000, due: 24000, remaining: 600, unit: 'km' },
      },
    ],
    gaps: [],
  },
  records: [],
};

// ── unknown — a freshly added vehicle, nothing tracked yet (must render neutral grey, NOT healthy) ──
const unknown: ScenarioBundle = {
  vehicle: {
    '@id': '/garage/vehicles/01UNKNOWNC3',
    make: 'Citroën',
    model: 'C3',
    year: 2020,
    engineVariant: '1.2 PureTech 83',
    engineCode: 'EB2',
    vehicleType: 'car',
    source: 'user',
    plate: 'IJ-345-KL',
    usageValue: 41000,
    usageUnit: 'km',
    factCount: 1,
    openTasks: 0,
    facts: [{ key: 'engine', value: '1.2 PureTech 83', source: 'user', verified: false }],
    health: { status: 'unknown', score: null, overdue: 0, dueSoon: 0, tracked: 0, gaps: 0 },
    nextDue: null,
    plannedTasks: [],
    addedAt: '2026-06-20',
  },
  forecast: { vehicle: '/garage/vehicles/01UNKNOWNC3', due: [], gaps: [] },
  records: [],
};

export const FIXTURES: Record<Scenario, ScenarioBundle | null> = {
  healthy,
  ambre,
  overdue,
  noAsset,
  unknown,
  empty: null,
};

/** The parc listing (every non-empty scenario) — for the ParcLot in B.2. */
export const PARC: VehicleSummary[] = [healthy, ambre, overdue, noAsset, unknown].map((b) => b.vehicle);
