import { describe, expect, it } from 'vitest';
import { initialView, reduceView, type ViewState } from './view';

const doc = {
  url: '/garage/vehicles/01X/documents/01D/file',
  title: 'Facture Garage du Centre',
  contentType: 'application/pdf',
  filename: 'facture.pdf',
  page: 2,
};

describe('reduceView', () => {
  it('starts on Entretien with nothing projected', () => {
    expect(initialView).toEqual({
      section: 'entretien',
      focusRegion: null,
      focusTopic: null,
      focusExplicit: false,
      projection: null,
      projectionKey: 0,
      proposal: null,
      document: null,
      focusSeq: 0,
      searches: [],
      settled: null,
      scanSeq: 0,
    });
  });

  it('switches section on a local section action without touching the projection', () => {
    const withFact = reduceView(initialView, { type: 'showFact', keys: ['oil_viscosity'] });
    const next = reduceView(withFact, { type: 'section', section: 'documents' });
    expect(next.section).toBe('documents');
    expect(next.projection).toEqual(withFact.projection);
  });

  it('maps showFact to Caractéristiques with a facts projection carrying nodes', () => {
    const nodes = [{ key: 'oil_capacity', label: 'Huile', value: '4,3 L', verified: true }];
    const next = reduceView(initialView, { type: 'showFact', keys: ['oil_capacity'], title: 'Huile moteur', nodes });
    expect(next.section).toBe('caracteristiques');
    expect(next.projection).toEqual({ kind: 'facts', keys: ['oil_capacity'], title: 'Huile moteur', nodes });
    expect(next.projectionKey).toBe(1);
  });

  it('maps focusView panels onto their section', () => {
    expect(reduceView(initialView, { type: 'focusView', panel: 'specs' }).section).toBe('caracteristiques');
    expect(reduceView(initialView, { type: 'focusView', panel: 'tasks' }).section).toBe('entretien');
    expect(reduceView(initialView, { type: 'focusView', panel: 'carnet' }).section).toBe('entretien');
    expect(reduceView(initialView, { type: 'focusView', panel: 'visits' }).section).toBe('entretien');
    expect(reduceView(initialView, { type: 'focusView', panel: 'parts' }).section).toBe('pieces');
    expect(reduceView(initialView, { type: 'focusView', panel: 'docs' }).section).toBe('documents');
  });

  it('carnet focus is the history projection; other panels keep their kind and keys', () => {
    expect(reduceView(initialView, { type: 'focusView', panel: 'carnet' }).projection).toEqual({ kind: 'history' });
    expect(reduceView(initialView, { type: 'focusView', panel: 'tasks', keys: ['oil_change'] }).projection).toEqual({
      kind: 'panel',
      panel: 'tasks',
      keys: ['oil_change'],
    });
  });

  it('rings the car region a service-type key reads off, and ignores fact-only keys', () => {
    expect(reduceView(initialView, { type: 'focusView', panel: 'tasks', keys: ['brake_pads'] }).focusRegion).toBe('frontAxle');
    expect(reduceView(initialView, { type: 'showFact', keys: ['oil_capacity'] }).focusRegion).toBeNull();
  });

  it('maps showHistory to Entretien + history', () => {
    const next = reduceView({ ...initialView, section: 'documents' }, { type: 'showHistory' });
    expect(next.section).toBe('entretien');
    expect(next.projection).toEqual({ kind: 'history' });
  });

  it('maps showParts to Pièces + a parts projection, parts absent stays null for the renderer to resolve', () => {
    const next = reduceView(initialView, { type: 'showParts', service: 'oil_change', taskId: '/garage/planned-tasks/01T' });
    expect(next.section).toBe('pieces');
    expect(next.projection).toEqual({ kind: 'parts', service: 'oil_change', taskId: '/garage/planned-tasks/01T', parts: null });
  });

  it('maps revealAssembly to a ringed region and an assembly projection on Entretien', () => {
    const next = reduceView({ ...initialView, section: 'pieces' }, { type: 'revealAssembly', region: 'hood', partKeys: ['timing_belt'] });
    expect(next.section).toBe('entretien');
    expect(next.focusRegion).toBe('hood');
    expect(next.projection).toEqual({ kind: 'assembly', region: 'hood', partKeys: ['timing_belt'] });
  });

  it('focusRegion only moves the ring', () => {
    const start: ViewState = { ...initialView, section: 'documents' };
    const next = reduceView(start, { type: 'focusRegion', region: 'rearAxle' });
    expect(next).toEqual({ ...start, focusRegion: 'rearAxle', focusTopic: null, focusExplicit: true, focusSeq: start.focusSeq + 1 });
  });

  it('showDocument opens Documents, keeps the document and projects it', () => {
    const next = reduceView(initialView, { type: 'showDocument', ...doc });
    expect(next.section).toBe('documents');
    expect(next.document).toEqual(doc);
    expect(next.projection).toEqual({ kind: 'document' });
  });

  describe('documentScan: the search log', () => {
    const hit = (page: number) => ({
      documentId: '01D',
      page,
      pageLabel: null,
      url: doc.url,
      title: doc.title,
      contentType: doc.contentType,
      filename: doc.filename,
      pagesUrl: '/garage/vehicles/01X/documents/01D/pages',
      snippet: 'Capacité en huile',
      sourceRef: null,
    });
    const scan = { type: 'documentScan' as const, vehicle: '/garage/vehicles/01X', query: 'huile', hits: [hit(4), hit(2)] };
    const refined = { ...scan, query: 'huile boite', hits: [hit(9)] };

    it('opens Documents and logs the search: no document, no projection', () => {
      const start: ViewState = { ...initialView, document: doc, projection: { kind: 'history' }, projectionKey: 5 };
      const next = reduceView(start, scan);
      expect(next.section).toBe('documents');
      expect(next.document).toBe(doc);
      expect(next.projection).toEqual({ kind: 'history' });
      expect(next.projectionKey).toBe(5);
      expect(next.searches).toEqual([{ query: 'huile', hits: scan.hits, key: next.scanSeq }]);
    });

    it('a refined query appends a block under the previous one, each a new run', () => {
      const once = reduceView(initialView, scan);
      const twice = reduceView(once, refined);
      expect(twice.searches.map((s) => s.query)).toEqual(['huile', 'huile boite']);
      expect(twice.searches[1].key).toBeGreaterThan(twice.searches[0].key);
      expect(reduceView(twice, scan).searches).toHaveLength(3);
    });

    it('a no match is logged too', () => {
      expect(reduceView(initialView, { ...scan, hits: [] }).searches).toEqual([{ query: 'huile', hits: [], key: 1 }]);
    });

    it('a new turn clears the log', () => {
      const logged = reduceView(reduceView(initialView, scan), refined);
      expect(reduceView(logged, { type: 'turn', phase: 'start' }).searches).toEqual([]);
      expect(reduceView(logged, { type: 'turn', phase: 'end' }).searches).toHaveLength(2);
    });

    it('endScan (the operator opened a document) clears the log', () => {
      const next = reduceView(reduceView(initialView, scan), { type: 'endScan' });
      expect(next.searches).toEqual([]);
      expect(next.settled).toBeNull();
    });

    it('showDocument on a logged hit settles on that row and keeps the log while it ticks', () => {
      const logged = reduceView(reduceView(initialView, scan), refined);
      const next = reduceView(logged, { type: 'showDocument', ...doc, page: 2 });
      expect(next.settled).toEqual({ key: logged.searches[0].key, hit: 1 });
      expect(next.searches).toBe(logged.searches);
      expect(next.document).toEqual({ ...doc, page: 2 });
      expect(next.projection).toEqual({ kind: 'document' });
    });

    it('the newest block wins when two searches hit the same page', () => {
      const logged = reduceView(reduceView(initialView, scan), { ...refined, hits: [hit(4)] });
      expect(reduceView(logged, { type: 'showDocument', ...doc, page: 4 }).settled).toEqual({ key: logged.searches[1].key, hit: 0 });
    });

    it('showDocument on a page the log does not hold switches at once', () => {
      const next = reduceView(reduceView(initialView, scan), { type: 'showDocument', ...doc, page: 77 });
      expect(next.searches).toEqual([]);
      expect(next.settled).toBeNull();
      expect(next.document).toEqual({ ...doc, page: 77 });
    });

    it('a search after the log settled starts a new log', () => {
      const settled = reduceView(reduceView(initialView, scan), { type: 'showDocument', ...doc, page: 4 });
      const next = reduceView(settled, refined);
      expect(next.searches.map((s) => s.query)).toEqual(['huile boite']);
      expect(next.settled).toBeNull();
    });

    it('reset (another vehicle) drops it and keeps the run counter', () => {
      const logged = reduceView(initialView, scan);
      const next = reduceView(logged, { type: 'reset' });
      expect(next.searches).toEqual([]);
      expect(next.scanSeq).toBe(logged.scanSeq);
    });
  });

  it('proposeActions fills the tray only: the section and the slot stay as they were', () => {
    const start: ViewState = { ...initialView, section: 'pieces', projection: { kind: 'history' }, projectionKey: 3 };
    const next = reduceView(start, { type: 'proposeActions', headline: 'Vidange à prévoir', actions: [{ label: 'Planifier', instruction: 'planifie' }] });
    expect(next.section).toBe('pieces');
    expect(next.proposal).toEqual({ headline: 'Vidange à prévoir', actions: [{ label: 'Planifier', instruction: 'planifie' }] });
    expect(next.projection).toEqual({ kind: 'history' });
    expect(next.projectionKey).toBe(3);
  });

  it('clearProposal drops the proposal and leaves the slot alone', () => {
    const fact = reduceView(initialView, { type: 'showFact', keys: ['engine'] });
    const proposed = reduceView(fact, { type: 'proposeActions', headline: 'h', actions: [] });
    const cleared = reduceView(proposed, { type: 'clearProposal' });
    expect(cleared.proposal).toBeNull();
    expect(cleared.projection).toEqual(fact.projection);
  });

  it('dismissProjection empties the slot and keeps the tray', () => {
    const proposed = reduceView(reduceView(initialView, { type: 'showHistory' }), { type: 'proposeActions', headline: 'h', actions: [] });
    const next = reduceView(proposed, { type: 'dismissProjection' });
    expect(next.projection).toBeNull();
    expect(next.proposal).toEqual({ headline: 'h', actions: [] });
  });

  it('bumps projectionKey on every summon, even a repeat, so the slot replays its arrival', () => {
    const once = reduceView(initialView, { type: 'showHistory' });
    const twice = reduceView(once, { type: 'showHistory' });
    expect(twice.projectionKey).toBe(2);
  });

  it('reset returns to the initial view but keeps the key monotonic', () => {
    const busy = reduceView(initialView, { type: 'showHistory' });
    const next = reduceView(busy, { type: 'reset' });
    expect(next).toEqual({ ...initialView, projectionKey: busy.projectionKey });
  });

  it('bumps focusSeq each time the ring is aimed somewhere new, so the car stage points once per aim', () => {
    const tick = reduceView(initialView, { type: 'focusRegion', region: 'hood' });
    expect(tick.focusSeq).toBe(1);
    expect(reduceView(tick, { type: 'focusRegion', region: 'hood' }).focusSeq).toBe(1);
    const moved = reduceView(tick, { type: 'focusRegion', region: 'rearAxle' });
    expect(moved.focusSeq).toBe(2);
    expect(reduceView(moved, { type: 'revealAssembly', region: 'rearAxle', partKeys: ['brake_pads'] }).focusSeq).toBe(3);
    expect(reduceView(initialView, { type: 'status', message: 'Je regarde la vidange' }).focusSeq).toBe(1);
    expect(reduceView(initialView, { type: 'showHistory' }).focusSeq).toBe(0);
  });

  it('keeps focusSeq across a reset so a remounted stage does not point again', () => {
    const aimed = reduceView(initialView, { type: 'focusRegion', region: 'hood' });
    const next = reduceView(aimed, { type: 'reset' });
    expect(next.focusRegion).toBeNull();
    expect(next.focusSeq).toBe(1);
  });

  it('reset (a vehicle change) keeps the pending proposal: the tray persists until acted on', () => {
    const proposed = reduceView(initialView, { type: 'proposeActions', headline: 'h', actions: [] });
    expect(reduceView(proposed, { type: 'reset' }).proposal).toEqual({ headline: 'h', actions: [] });
  });

  it('returns the same state for frames that carry no view change', () => {
    expect(reduceView(initialView, { type: 'status', message: 'Je regarde le garage' })).toBe(initialView);
    expect(reduceView(initialView, { type: 'turn', phase: 'start' })).toBe(initialView);
    expect(reduceView(initialView, { type: 'toast', tone: 'info', message: 'x' })).toBe(initialView);
    expect(reduceView(initialView, { type: 'selectVehicle', id: '/garage/vehicles/01X' })).toBe(initialView);
  });

  it('rings what the agent is working on: a narrated step names the part', () => {
    const next = reduceView(initialView, { type: 'status', message: 'Je cherche le kit embrayage pour le R9M', tool: 'get_service_parts' });
    expect(next).toMatchObject({ focusRegion: 'frontAxle', focusTopic: 'clutch', focusExplicit: false });
  });

  it('rings the part a proposal is about', () => {
    const next = reduceView(initialView, { type: 'proposeActions', headline: 'Kit embrayage Valeo + volant bi-masse LuK trouvés', actions: [] });
    expect(next).toMatchObject({ focusRegion: 'frontAxle', focusTopic: 'clutch' });
  });

  it('rings the service of summoned parts', () => {
    const next = reduceView(initialView, { type: 'showParts', service: 'timing_belt', taskId: '/t' });
    expect(next).toMatchObject({ focusRegion: 'hood', focusTopic: 'timing_belt' });
  });

  it('an explicit ring wins over anything derived afterwards', () => {
    const pinned = reduceView(initialView, { type: 'focusRegion', region: 'rearAxle' });
    const next = reduceView(pinned, { type: 'proposeActions', headline: 'Kit embrayage', actions: [] });
    expect(next.focusRegion).toBe('rearAxle');
    const revealed = reduceView(initialView, { type: 'revealAssembly', region: 'hood' });
    expect(reduceView(revealed, { type: 'status', message: 'plaquettes de frein arrière' }).focusRegion).toBe('hood');
  });

  it('reset forgets the explicit pin', () => {
    const pinned = reduceView(initialView, { type: 'focusRegion', region: 'rearAxle' });
    expect(reduceView(pinned, { type: 'reset' }).focusExplicit).toBe(false);
  });
});

describe('reduceView — one job per screen', () => {
  it('a proposal never moves the screen: the tray is visible on every section', () => {
    for (const section of ['documents', 'caracteristiques', 'pieces', 'entretien'] as const) {
      expect(reduceView({ ...initialView, section }, { type: 'proposeActions', headline: 'h', actions: [] }).section).toBe(section);
    }
  });
});
