import { useCallback, useEffect, useMemo, useReducer, useRef, useState } from 'react';
import { FIXTURES } from '../fixtures/vehicles';
import { useNoyau } from '../noyau/useNoyau';
import { segForIri, useHashPath } from '../router';
import { localeSearch } from '../i18n/locale';
import { useReducedMotion } from '../scene/useReducedMotion';
import type { CtaAction, DocScanHit, Iri, UIAction, VehicleBundle } from '../types';
import { useT } from '../i18n/LocaleProvider';
import { offlineEdits } from './edits';
import { useCommandStatus, useTheme } from './hooks';
import { initialView, reduceView } from './view';
import { WorkshopView } from './WorkshopView';

// Public, fixture-driven harness for the Workshop (no API, no auth): the parc is the fixture
// scenarios, a couple enriched so every module has real content, and a frame injector replays the
// agent's wire vocabulary so each surface can be seen and screenshotted.

const BASE = '/dev/workshop';
const NOW = new Date('2026-06-30T10:00:00Z');

function enrich(b: VehicleBundle): VehicleBundle {
  if (b.vehicle['@id'] === '/garage/vehicles/01NOASSETMOTO') return { ...b, vehicle: { ...b.vehicle, archivedAt: '2026-05-12T09:00:00+00:00' } };
  if (b.vehicle['@id'] !== '/garage/vehicles/01OVERDUEESPACE') return b;
  const iri = b.vehicle['@id'];
  return {
    ...b,
    vehicle: {
      ...b.vehicle,
      plannedTasks: [
        ...(b.vehicle.plannedTasks ?? []),
        {
          id: '/garage/planned-tasks/01TASKCLUTCH',
          type: 'clutch',
          label: 'Kit embrayage + volant bi-masse',
          target: null,
          dueDate: null,
          parts: [
            { id: 'p1', ref: '826317', brand: 'Valeo', label: 'Kit embrayage', qty: 1, priceCents: 28900 },
            { id: 'p2', ref: '415 0180 10', brand: 'LuK', label: 'Volant bi-masse', qty: 1, priceCents: 41250 },
          ],
          status: 'open',
        },
      ],
      facts: [
        ...(b.vehicle.facts ?? []),
        { key: 'oil_capacity', value: '5,9', unit: 'L', source: 'partsapi', verified: true },
        { key: 'tyre_size', value: '225/55 R17', source: 'siv', verified: true },
      ],
    },
    forecast: {
      ...b.forecast,
      due: [
        ...b.forecast.due,
        {
          type: 'timing_belt',
          action: 'replace',
          component: 'timing_belt',
          rule: 'schedule',
          lastPerformedAt: '2021-03-02',
          dueAt: null,
          projectedDueAt: '2027-02-10',
          overdue: false,
          daysUntilDue: 225,
          usage: { last: 60000, due: 180000, remaining: 38000, unit: 'km' },
        },
        {
          type: 'inspection',
          action: 'inspect',
          component: null,
          rule: 'schedule',
          lastPerformedAt: '2025-03-14',
          dueAt: '2027-03-14',
          projectedDueAt: null,
          overdue: false,
          daysUntilDue: 257,
          usage: null,
        },
      ],
    },
    records: [
      ...b.records,
      {
        id: '/garage/maintenance-records/01RECPADS',
        items: [{ type: 'brake_pads', label: null, parts: [{ ref: 'P 68 050', brand: 'Brembo' }] }],
        performedAt: '2025-11-12',
        usageValue: 139800,
        usageUnit: 'km',
        performedBy: 'Garage du Centre',
        costCents: 31240,
        currency: 'EUR',
        source: 'user',
        attachments: [{ id: 'att1', filename: 'Facture Garage du Centre 11-2025.pdf', contentType: 'application/pdf', size: 182000, url: '/garage/maintenance-records/01RECPADS/attachments/att1' }],
      },
      {
        id: '/garage/maintenance-records/01RECBELT',
        items: [{ type: 'timing_belt', label: 'Kit distribution Gates K015603XS', parts: [] }],
        performedAt: '2021-03-02',
        usageValue: 60000,
        usageUnit: 'km',
        costCents: 64000,
        currency: 'EUR',
        source: 'user',
      },
    ],
    documents: [
      {
        id: '01DOCMR',
        kind: 'manual',
        title: 'MR 361 · Mécanique moteur R9M',
        source: 'dialogys',
        sourceRef: 'MR361',
        plannedTaskId: null,
        filename: 'mr361-moteur.pdf',
        contentType: 'application/pdf',
        size: 4_200_000,
        pageCount: 212,
        addedAt: '2026-05-02T09:00:00+00:00',
        url: `${iri}/documents/01DOCMR/file`,
        pagesUrl: `${iri}/documents/01DOCMR/pages`,
      },
    ],
  };
}

const BUNDLES: VehicleBundle[] = Object.values(FIXTURES)
  .filter((b): b is VehicleBundle => b !== null)
  .map(enrich);

const MR_HIT = (page: number, pageLabel: string): DocScanHit => ({
  documentId: '01DOCMR',
  page,
  pageLabel,
  url: '/garage/vehicles/01OVERDUEESPACE/documents/01DOCMR/file',
  title: 'MR 361 · Mécanique moteur R9M',
  contentType: 'application/pdf',
  filename: 'mr361-moteur.pdf',
  pagesUrl: '/garage/vehicles/01OVERDUEESPACE/documents/01DOCMR/pages',
});
const SHOW_DOC: UIAction = {
  type: 'showDocument',
  url: '/garage/vehicles/01OVERDUEESPACE/documents/01DOCMR/file',
  title: 'MR 361 · Mécanique moteur R9M',
  contentType: 'application/pdf',
  filename: 'mr361-moteur.pdf',
  page: 84,
  highlight: 'courroie de distribution',
};
const SCAN_FRAME: UIAction = {
  type: 'documentScan',
  vehicle: '/garage/vehicles/01OVERDUEESPACE',
  query: 'courroie de distribution',
  hits: [MR_HIT(84, '11A-3'), MR_HIT(86, '11A-5'), MR_HIT(91, '11A-10'), MR_HIT(12, '10A-4'), MR_HIT(140, '19A-2')],
};

const FRAMES: Array<{ label: string; frames: UIAction[] }> = [
  { label: 'showFact (huile)', frames: [{ type: 'showFact', keys: ['oil_viscosity', 'oil_capacity'], title: 'Huile moteur' }] },
  { label: 'focusView tasks (vidange)', frames: [{ type: 'focusView', panel: 'tasks', keys: ['oil_change'] }] },
  { label: 'showHistory', frames: [{ type: 'showHistory' }] },
  { label: 'showParts (embrayage)', frames: [{ type: 'showParts', service: 'clutch', taskId: '/garage/planned-tasks/01TASKCLUTCH' }] },
  { label: 'revealAssembly (moteur)', frames: [{ type: 'revealAssembly', region: 'hood', partKeys: ['timing_belt', 'water_pump'] }] },
  { label: 'focusRegion (train avant)', frames: [{ type: 'focusRegion', region: 'frontAxle' }] },
  { label: 'showDocument', frames: [SHOW_DOC] },
  {
    label: 'proposeActions',
    frames: [
      {
        type: 'proposeActions',
        headline: 'Kit embrayage Valeo + volant bi-masse LuK trouvés pour le R9M',
        finding: 'Le volant bi-masse se remplace avec le kit : une seule immobilisation.',
        actions: [
          { label: "Planifier l'embrayage", instruction: "planifie l'embrayage" },
          { label: 'Voir les pièces', instruction: 'montre les pièces' },
        ],
      },
    ],
  },
];

export function WorkshopFixture() {
  const path = useHashPath();
  const seg = /^\/dev\/workshop\/v\/(.+)$/.exec(path)?.[1] ?? null;
  const vehicles = useMemo(() => BUNDLES.map((b) => b.vehicle), []);
  const bundles = useMemo(() => Object.fromEntries(BUNDLES.map((b) => [b.vehicle['@id'], b])) as Record<Iri, VehicleBundle>, []);
  const selectedIri = seg ? (vehicles.find((v) => segForIri(v['@id']) === seg)?.['@id'] ?? `#missing:${seg}`) : null;
  const [theme, flipTheme] = useTheme();
  const reduced = useReducedMotion();
  const [view, dispatchView] = useReducer(reduceView, initialView);
  const { state, dispatch } = useNoyau();
  const { status } = useCommandStatus(state);
  const [open, setOpen] = useState(false);
  const t = useT();
  const edits = useMemo(() => offlineEdits(t.workshop.edit.offline), [t]);
  // `?capture=1` (before or after the hash) hides the injector so screenshots show only the product.
  const capture = [window.location.search, localeSearch('', window.location.hash)].some((q) => new URLSearchParams(q).get('capture') === '1');
  const timers = useRef<number[]>([]);

  useEffect(() => dispatchView({ type: 'reset' }), [selectedIri]);
  useEffect(() => () => timers.current.forEach((id) => window.clearTimeout(id)), []);

  const later = (ms: number, fn: () => void) => timers.current.push(window.setTimeout(fn, ms));

  const runTurn = useCallback(
    (driver: 'app' | 'pilot' = 'app') => {
      dispatch({ type: 'turn', phase: 'start', driver });
      const said = { type: 'status', message: 'Je cherche le kit embrayage pour le R9M' } as const;
      dispatch({ ...said, driver });
      dispatchView(said);
      later(400, () => dispatch({ type: 'status', tool: 'get_service_parts', driver }));
      later(2600, () => dispatch({ type: 'status', tool: 'get_maintenance_history', driver }));
      later(5200, () => dispatchView({ type: 'showParts', service: 'clutch', taskId: '/garage/planned-tasks/01TASKCLUTCH' }));
      later(6400, () => dispatch({ type: 'toast', tone: 'ok', message: 'Kit embrayage Valeo 826317 et volant LuK trouvés.', driver }));
      later(6600, () => dispatch({ type: 'turn', phase: 'end', driver }));
    },
    [dispatch],
  );

  const onSubmit = useCallback(() => runTurn('app'), [runTurn]);
  const onListening = useCallback((active: boolean) => dispatch({ type: 'listening', active }), [dispatch]);
  const onCancel = useCallback(() => {
    timers.current.forEach((id) => window.clearTimeout(id));
    timers.current = [];
    dispatch({ type: 'toast', tone: 'warn', message: 'Tour interrompu.' });
    dispatch({ type: 'turn', phase: 'end', failed: true });
  }, [dispatch]);
  const onAction = useCallback((a: CtaAction) => (a.instruction ? runTurn('app') : dispatchView({ type: 'clearProposal' })), [runTurn]);

  return (
    <>
      <WorkshopView
        vehicles={vehicles}
        bundles={bundles}
        selectedIri={selectedIri}
        loadingParc={false}
        vehicleState={selectedIri && !bundles[selectedIri] ? 'missing' : 'ready'}
        view={view}
        onView={dispatchView}
        status={status}
        phase={state.phase}
        onListening={onListening}
        onSubmit={onSubmit}
        onCancel={onCancel}
        onSelectVehicle={(iri) => (window.location.hash = `#${BASE}/v/${segForIri(iri)}`)}
        onAction={onAction}
        theme={theme}
        onTheme={flipTheme}
        reduced={reduced}
        now={NOW}
        homeHref={`#${BASE}`}
        hrefFor={(iri) => `#${BASE}/v/${segForIri(iri)}`}
        onPlan={(job) => dispatch({ type: 'toast', tone: 'info', message: `planifier ${job.type}` })}
        edits={edits}
      />
      <aside className={`w-inject${open ? ' is-open' : ''}`} hidden={capture}>
        <button type="button" className="w-button" onClick={() => setOpen((o) => !o)} aria-expanded={open}>
          Frames
        </button>
        {open && (
          <div className="w-inject-list">
            {FRAMES.map((f) => (
              <button key={f.label} type="button" className="w-button" onClick={() => f.frames.forEach((fr) => dispatchView(fr as Parameters<typeof dispatchView>[0]))}>
                {f.label}
              </button>
            ))}
            <button type="button" className="w-button" onClick={() => dispatchView(SCAN_FRAME)}>
              documentScan (courroie)
            </button>
            <button
              type="button"
              className="w-button"
              onClick={() => {
                dispatchView(SCAN_FRAME);
                later(3000, () => dispatchView(SHOW_DOC));
              }}
            >
              documentScan → showDocument (3 s)
            </button>
            <button type="button" className="w-button" onClick={() => dispatchView({ type: 'documentScan', vehicle: '/garage/vehicles/01OVERDUEESPACE', query: 'turbocompresseur', hits: [] })}>
              documentScan (aucun résultat)
            </button>
            <button type="button" className="w-button" onClick={() => runTurn('app')}>
              turn (app, 6 s)
            </button>
            <button type="button" className="w-button" onClick={() => runTurn('pilot')}>
              turn (pilot)
            </button>
            <button type="button" className="w-button" onClick={() => dispatchView({ type: 'reset' })}>
              reset view
            </button>
          </div>
        )}
      </aside>
    </>
  );
}
