import { describe, it, expect } from 'vitest';
import { busy, classifyStep, initialNoyau, NOTICE_CAP, reduceNoyau, type NoyauEvent, type NoyauState } from './state';

// The interface defaults to English; these tests assert the French copy, so they say so.
const R = (state: NoyauState, ev: NoyauEvent, at: number) => reduceNoyau(state, ev, at, 'fr');
const inFrench = (message: string | undefined, tool?: string) => classifyStep(message, tool, 'fr');

describe('Le Noyau frame→state machine', () => {
  it('starts idle (veille)', () => {
    expect(initialNoyau.phase).toBe('idle');
    expect(initialNoyau.pulseCount).toBe(0);
  });

  it('typing in idle → listening; stop typing → idle', () => {
    const s1 = R(initialNoyau, { type: 'typing', active: true }, 0);
    expect(s1.phase).toBe('listening');
    const s2 = R(s1, { type: 'typing', active: false }, 1);
    expect(s2.phase).toBe('idle');
  });

  /**
   * The MIC is not typing. Both used to dispatch `typing`, which is refused while a result is still on
   * the orb — so pressing the mic inside the 1.6 s done dwell showed no cue at all, and the orb then
   * settled itself to veille with the microphone still open (2026-09-11, reported live). An open mic
   * is a state the operator has a right to see, and it outranks a finished turn's lamp.
   */
  describe('listening — an open microphone takes the orb', () => {
    const workingThen = (ev: Parameters<typeof R>[1]) => R(R(initialNoyau, { type: 'turn', phase: 'start' }, 0), ev, 1);

    it('dilates from rest', () => {
      expect(R(initialNoyau, { type: 'listening', active: true }, 0).phase).toBe('listening');
    });

    it('takes the orb from a finished turn, and puts out its result lamp', () => {
      let s = R(initialNoyau, { type: 'turn', phase: 'start' }, 0);
      s = R(s, { type: 'concern', message: 'Contrôle technique dépassé.' }, 10);
      s = R(s, { type: 'turn', phase: 'end' }, 20);
      expect(s).toMatchObject({ phase: 'done', result: 'red' });

      const open = R(s, { type: 'listening', active: true }, 30);

      expect(open.phase).toBe('listening');
      expect(open.result).toBeNull(); // the red fault lamp is keyed on phase+result
    });

    // A turn genuinely in progress owns the orb; the mic is inert while busy anyway, so this is only
    // reachable from a pilot-driven turn.
    it('never interrupts a running turn', () => {
      const running = R(initialNoyau, { type: 'turn', phase: 'start' }, 0);

      expect(R(running, { type: 'listening', active: true }, 1)).toBe(running);
    });

    it('returns to veille when the mic closes', () => {
      const open = R(initialNoyau, { type: 'listening', active: true }, 0);

      expect(R(open, { type: 'listening', active: false }, 1).phase).toBe('idle');
    });

    /**
     * The mic closing and the turn starting are the SAME gesture now that a transcript submits itself
     * (`dictation.ts` `submission`). If the close lands after the turn has begun, it must not drag the
     * working orb back to veille.
     */
    it('leaves a turn that has already started alone', () => {
      expect(workingThen({ type: 'listening', active: false })).toMatchObject({ phase: 'working' });
    });
  });

  it('turn start → working, pulse count reset, result cleared', () => {
    const s = R(initialNoyau, { type: 'turn', phase: 'start' }, 0);
    expect(s.phase).toBe('working');
    expect(s.pulseCount).toBe(0);
    expect(s.result).toBeNull();
  });

  it('each status frame fires exactly ONE pulse and updates the single narration line', () => {
    let s = R(initialNoyau, { type: 'turn', phase: 'start' }, 0);
    s = R(s, { type: 'status', message: 'Lecture de la plaque…' }, 1000);
    s = R(s, { type: 'status', message: 'Recherche · TecDoc…' }, 3000);
    s = R(s, { type: 'status', message: "Viscosité d'huile…" }, 5000);
    expect(s.pulseCount).toBe(3);
    expect(s.narration).toBe("Viscosité d'huile…");
    const pulses = s.journal.filter((e) => e.kind === 'pulse');
    expect(pulses).toHaveLength(3);
    expect(pulses[0].elapsedMs).toBe(1000); // stamped relative to turn start
  });

  it('non-alarm toast updates the narration line but fires NO pulse', () => {
    let s = R(initialNoyau, { type: 'turn', phase: 'start' }, 0);
    s = R(s, { type: 'status', message: 'x' }, 100);
    const before = s.pulseCount;
    s = R(s, { type: 'toast', tone: 'ok', message: 'Vidange enregistrée' }, 200);
    expect(s.pulseCount).toBe(before);
    expect(s.narration).toBe('Vidange enregistrée');
  });

  it('alarm toast does NOT overwrite the narration line', () => {
    let s = R(initialNoyau, { type: 'turn', phase: 'start' }, 0);
    s = R(s, { type: 'status', message: 'narr' }, 100);
    s = R(s, { type: 'toast', tone: 'alarm', message: 'Vidange en retard' }, 200);
    expect(s.narration).toBe('narr');
  });

  // The old tone-keyed "posé · X" label is
  // gone. `narration` at turn-end is now either a real closing sentence already narrated during the
  // turn (a real agent turn's own `toast`) or an explicit `resultText` (a deterministic wayfinder's
  // closing line) — the fallback below only fires when a turn narrated nothing at all.
  // A turn that narrated NOTHING knows nothing, so it must not claim anything. `resultFromTone(null)`
  // is 'green', so a generic fallback sentence here would announce « Tout est à jour. » — a health
  // verdict invented by a turn that failed or did nothing. Silence is the honest outcome; the signal
  // still reaches text through the orb's aria-label and the journal's done row.
  it('turn end with nothing narrated stays SILENT rather than inventing a health verdict', () => {
    let s = R(initialNoyau, { type: 'turn', phase: 'start' }, 0);
    s = R(s, { type: 'turn', phase: 'end' }, 200);
    expect(s.phase).toBe('done');
    expect(s.result).toBe('green');
    expect(s.narration).toBeNull();
    const done = s.journal.find((e) => e.kind === 'done');
    expect(done?.signal).toBe('green'); // the signal is still recorded as text in the journal
  });

  it('turn end never overwrites what was already narrated — a real agent turn keeps its own toast, no generic label', () => {
    let s = R(initialNoyau, { type: 'turn', phase: 'start' }, 0);
    s = R(s, { type: 'toast', tone: 'ok', message: 'Vidange enregistrée' }, 100);
    s = R(s, { type: 'turn', phase: 'end' }, 200);
    expect(s.narration).toBe('Vidange enregistrée');
    const done = s.journal.find((e) => e.kind === 'done');
    expect(done?.message).toBe('Vidange enregistrée');
  });

  it('turn end honours an explicit resultText — a deterministic wayfinder\'s own closing sentence wins over both the last status and the fallback', () => {
    let s = R(initialNoyau, { type: 'turn', phase: 'start' }, 0);
    s = R(s, { type: 'status', message: 'ouverture du dossier…' }, 100);
    s = R(s, { type: 'turn', phase: 'end', resultText: 'Voici les données collectées sur la Mazda 6.' }, 200);
    expect(s.narration).toBe('Voici les données collectées sur la Mazda 6.');
  });

  it('a warn toast during the turn → done=amber', () => {
    let s = R(initialNoyau, { type: 'turn', phase: 'start' }, 0);
    s = R(s, { type: 'toast', tone: 'warn', message: 'due bientôt' }, 100);
    s = R(s, { type: 'turn', phase: 'end' }, 200);
    expect(s.result).toBe('amber');
  });

  it('an alarm toast during the turn → done=red (highest severity wins)', () => {
    let s = R(initialNoyau, { type: 'turn', phase: 'start' }, 0);
    s = R(s, { type: 'toast', tone: 'ok', message: 'ok' }, 50);
    s = R(s, { type: 'toast', tone: 'alarm', message: 'erreur' }, 100);
    s = R(s, { type: 'turn', phase: 'end' }, 200);
    expect(s.result).toBe('red');
  });

  it('settle returns to idle and clears result + worstTone', () => {
    let s = R(initialNoyau, { type: 'turn', phase: 'start' }, 0);
    s = R(s, { type: 'toast', tone: 'alarm', message: 'e' }, 50);
    s = R(s, { type: 'turn', phase: 'end' }, 100);
    s = R(s, { type: 'settle' }, 2000);
    expect(s.phase).toBe('idle');
    expect(s.result).toBeNull();
    expect(s.worstTone).toBeNull(); // a between-turn alarm (the §9 overdue push) must not linger
  });

  it('typing is ignored while working (no moment disables the busy orb)', () => {
    let s = R(initialNoyau, { type: 'turn', phase: 'start' }, 0);
    s = R(s, { type: 'typing', active: true }, 50);
    expect(s.phase).toBe('working');
  });

  it('a status without an explicit turn-start still implies working (robust to stream gaps)', () => {
    const s = R(initialNoyau, { type: 'status', message: 'x' }, 0);
    expect(s.phase).toBe('working');
    expect(s.pulseCount).toBe(1);
  });

  it('concern (the §9 overdue DOMAIN push) murmurs the line — but is HANDS-OFF (no turn)', () => {
    const s = R(initialNoyau, { type: 'concern', message: 'Vidange en retard · 142 000 km' }, 0);
    expect(s.phase).toBe('idle'); // NOT working — a domain frame is not an agent turn
    expect(s.pulseCount).toBe(0); // no working pulse
    expect(s.narration).toBe('Vidange en retard · 142 000 km'); // the concern murmurs on the aria-live line
    expect(s.journal[s.journal.length - 1]?.kind).toBe('alarm');
  });

  it('concern never interrupts an active turn into a fake idle', () => {
    let s = R(initialNoyau, { type: 'turn', phase: 'start' }, 0);
    s = R(s, { type: 'concern', message: 'm' }, 50);
    expect(s.phase).toBe('working'); // a turn in flight stays working
  });

  it('greet (the opening ticker line) murmurs the line and is HANDS-OFF (no turn, no notice)', () => {
    const s = R(initialNoyau, { type: 'greet', message: 'Bonjour, on s’occupe de quel véhicule ?' }, 0);
    expect(s.phase).toBe('idle'); // NOT working — nothing would ever settle it back down
    expect(busy(s)).toBe(false);
    expect(s.narration).toBe('Bonjour, on s’occupe de quel véhicule ?');
    expect(s.notices).toEqual([]); // unlike toast, mints no retained card
    expect(s.journal[s.journal.length - 1]?.kind).toBe('narration');
  });
});

// The typed tool-step chips (C1). These cover the FALLBACK path — a frame with no `tool` field, from a
// server predating it — where the French sentence is still keyword-matched back to a chip. The primary
// path is the tool key on the frame; see the describe below.
describe('classifyStep — narration sentence → {tool, short verb} (fallback)', () => {
  const cases: Array<[string, string, string]> = [
    ['Je consulte le catalogue de pièces…', 'get_service_parts', 'recherche pièces…'],
    ['Je calcule les entretiens à venir…', 'list_due_maintenance', 'prévision…'],
    ["Je relis l'historique…", 'get_history', 'lecture carnet…'],
    ["J'établis le programme d'entretien…", 'plan_maintenance', 'planification…'],
    ['Je lis la plaque…', 'lookup_plate', 'plaque…'],
    ['Je vérifie la préconisation Motul…', 'get_motul_oil', 'huile Motul…'],
    ['Je mets à jour le véhicule…', 'update_vehicle', 'mise à jour…'],
    ["J'enregistre l'intervention…", 'log_maintenance', 'enregistrement…'],
    ['Je consulte ton garage…', 'list_vehicles', 'garage…'],
    ['Je cherche en ligne…', 'web_search', 'recherche web…'],
  ];
  it.each(cases)('maps %s', (message, tool, label) => {
    expect(inFrench(message)).toEqual({ tool, label });
  });

  it('also classifies the mock-script variants (keyword-matched, not exact)', () => {
    expect(inFrench('Recherche des pièces · TecDoc…').tool).toBe('get_service_parts');
    expect(inFrench('Comparaison des références…').tool).toBe('get_service_parts');
    expect(inFrench('Calcul des échéances…').tool).toBe('list_due_maintenance');
    expect(inFrench("Viscosité d'huile…").tool).toBe('get_motul_oil');
  });

  it('an unmatched sentence → tool "unknown", label = the message itself (humanized fallback)', () => {
    expect(inFrench('Quelque chose de nouveau…')).toEqual({ tool: 'unknown', label: 'Quelque chose de nouveau…' });
  });
});

// The frame now carries the tool name the server already had (StepNarrator is keyed by it). The
// sentence stops being the identity and goes back to being prose — which is what lets it be written
// in any language without the chips degrading.
describe('classifyStep — the tool name on the frame wins over the prose', () => {
  it('classifies from the tool name, ignoring the sentence entirely', () => {
    expect(inFrench('n’importe quoi', 'get_service_parts')).toEqual({
      tool: 'get_service_parts',
      label: 'recherche pièces…',
    });
  });

  it('an English narration still lands the right chip when the tool name rides along', () => {
    expect(inFrench('Checking the parts catalogue…', 'get_service_parts').tool).toBe('get_service_parts');
    expect(inFrench('Reading the plate…', 'lookup_plate').tool).toBe('lookup_plate');
  });

  it('folds both backend history tools onto the one carnet chip', () => {
    expect(inFrench('', 'get_maintenance_history')).toEqual({ tool: 'get_history', label: 'lecture carnet…' });
    expect(inFrench('', 'get_vehicle_facts')).toEqual({ tool: 'get_history', label: 'lecture carnet…' });
  });

  it('an UNKNOWN tool name falls back to the prose, not to "unknown"', () => {
    // A newly added server tool: the sentence is still the best evidence we have.
    expect(inFrench('Je lis la plaque…', 'some_new_tool').tool).toBe('lookup_plate');
  });

  it('no tool name → the keyword table, exactly as before', () => {
    expect(inFrench('Je lis la plaque…').tool).toBe('lookup_plate');
  });

  it('the chip verb follows the interface language', () => {
    expect(classifyStep('', 'get_service_parts', 'fr').label).toBe('recherche pièces…');
    expect(classifyStep('', 'get_service_parts', 'en').label).toBe('parts search…');
  });

  it('an unrecognised step shows the server’s own sentence, whatever language it is in', () => {
    // No guess is better than a wrong one: the prose IS the only label available.
    expect(classifyStep('Doing something new…', 'brand_new_tool', 'en')).toEqual({
      tool: 'unknown',
      label: 'Doing something new…',
    });
  });

  it('the reducer puts the frame’s tool on the chip', () => {
    const s = R(initialNoyau, { type: 'status', message: 'Checking the parts catalogue…', tool: 'get_service_parts' }, 0);
    expect(s.steps[s.steps.length - 1].tool).toBe('get_service_parts');
  });

  // The click-time acknowledgement (`send()`, Atelier.tsx) is a synthetic chip identity, not a server
  // tool — same pattern as `get_history` folding two backend tools onto one chip.
  it('classifies the click-time acknowledgement chip, in both locales', () => {
    expect(classifyStep('', 'acknowledge', 'fr')).toEqual({ tool: 'acknowledge', label: 'reçu' });
    expect(classifyStep('', 'acknowledge', 'en')).toEqual({ tool: 'acknowledge', label: 'heard' });
  });
});

describe('Le Noyau — current-turn tool-step chips', () => {
  it('turn start resets the step list to empty', () => {
    let s = R(initialNoyau, { type: 'status', message: 'Je lis la plaque…' }, 0);
    expect(s.steps).toHaveLength(1);
    s = R(s, { type: 'turn', phase: 'start' }, 100);
    expect(s.steps).toEqual([]);
  });

  it('each status frame pushes a running step; the previous running step resolves to done', () => {
    let s = R(initialNoyau, { type: 'turn', phase: 'start' }, 0);
    s = R(s, { type: 'status', message: 'Je lis la plaque…' }, 100);
    expect(s.steps.map((x) => [x.tool, x.state])).toEqual([['lookup_plate', 'running']]);
    s = R(s, { type: 'status', message: 'Je consulte le catalogue de pièces…' }, 200);
    expect(s.steps.map((x) => [x.tool, x.state])).toEqual([
      ['lookup_plate', 'done'],
      ['get_service_parts', 'running'],
    ]);
  });

  it('step ids are stable + monotonic (usable as React keys across the turn)', () => {
    let s = R(initialNoyau, { type: 'turn', phase: 'start' }, 0);
    s = R(s, { type: 'status', message: 'Je lis la plaque…' }, 100);
    s = R(s, { type: 'status', message: 'Je cherche en ligne…' }, 200);
    const ids = s.steps.map((x) => x.id);
    expect(new Set(ids).size).toBe(ids.length);
    expect(ids[0]).toBeLessThan(ids[1]);
  });

  it('turn end (normal) resolves the last running step to done', () => {
    let s = R(initialNoyau, { type: 'turn', phase: 'start' }, 0);
    s = R(s, { type: 'status', message: 'Je calcule les entretiens à venir…' }, 100);
    s = R(s, { type: 'turn', phase: 'end' }, 200);
    expect(s.steps.map((x) => x.state)).toEqual(['done']);
  });

  it('turn end with failed → the last running step resolves to ERROR (agent-lifecycle, not a signal)', () => {
    let s = R(initialNoyau, { type: 'turn', phase: 'start' }, 0);
    s = R(s, { type: 'status', message: 'Je cherche en ligne…' }, 100);
    s = R(s, { type: 'turn', phase: 'end', failed: true }, 200);
    expect(s.steps.map((x) => x.state)).toEqual(['error']);
  });

  it('a warn/amber finding is NOT a step error — the last step still resolves to done', () => {
    let s = R(initialNoyau, { type: 'turn', phase: 'start' }, 0);
    s = R(s, { type: 'status', message: 'Je calcule les entretiens à venir…' }, 100);
    s = R(s, { type: 'toast', tone: 'warn', message: 'Vidange à prévoir' }, 150);
    s = R(s, { type: 'turn', phase: 'end' }, 200); // normal end (no failed flag): amber is a legit outcome
    expect(s.result).toBe('amber');
    expect(s.steps.map((x) => x.state)).toEqual(['done']);
  });

  it('settle clears the step list (chips have collapsed into the journal)', () => {
    let s = R(initialNoyau, { type: 'turn', phase: 'start' }, 0);
    s = R(s, { type: 'status', message: 'Je lis la plaque…' }, 100);
    s = R(s, { type: 'turn', phase: 'end' }, 200);
    expect(s.steps).toHaveLength(1);
    s = R(s, { type: 'settle' }, 2000);
    expect(s.steps).toEqual([]);
  });

  it('a toast does not open or close a tool step (steps track tool calls only)', () => {
    let s = R(initialNoyau, { type: 'turn', phase: 'start' }, 0);
    s = R(s, { type: 'status', message: 'Je lis la plaque…' }, 100);
    s = R(s, { type: 'toast', tone: 'ok', message: 'à jour' }, 150);
    expect(s.steps.map((x) => x.state)).toEqual(['running']); // still in flight; the toast is a finding
  });

  it('a concern (domain push) never touches the current turn steps', () => {
    let s = R(initialNoyau, { type: 'turn', phase: 'start' }, 0);
    s = R(s, { type: 'status', message: 'Je lis la plaque…' }, 100);
    s = R(s, { type: 'concern', message: 'Vidange en retard' }, 150);
    expect(s.steps.map((x) => [x.tool, x.state])).toEqual([['lookup_plate', 'running']]);
  });

  // Owner decision 2026-11-09: the server sends a tool step as its key alone; the ticker carries only
  // what the model chose to say.
  describe('a tool-only step (no message)', () => {
    it('records the running chip but leaves the ticker line as the model left it', () => {
      let s = R(initialNoyau, { type: 'turn', phase: 'start' }, 0);
      s = R(s, { type: 'status', message: 'Je compare les marques…' }, 50);
      s = R(s, { type: 'status', tool: 'get_service_parts' }, 100);
      expect(s.narration).toBe('Je compare les marques…');
      expect(s.steps.map((x) => [x.tool, x.label, x.state])).toEqual([
        ['unknown', 'Je compare les marques…', 'done'],
        ['get_service_parts', 'recherche pièces…', 'running'],
      ]);
    });

    it('still lifts the orb from rest, like any step', () => {
      const s = R(initialNoyau, { type: 'status', tool: 'web_search' }, 0);
      expect(s.phase).toBe('working');
      expect(s.narration).toBeNull();
      expect(s.steps.map((x) => x.tool)).toEqual(['web_search']);
    });

    it('journals the step under its chip verb, not an empty line', () => {
      const s = R(R(initialNoyau, { type: 'turn', phase: 'start' }, 0), { type: 'status', tool: 'lookup_plate' }, 10);
      expect(s.journal[s.journal.length - 1]).toMatchObject({ kind: 'pulse', message: 'plaque…' });
    });

    it('a step the chip table does not know falls back to a generic verb, never a raw tool name', () => {
      const s = R(initialNoyau, { type: 'status', tool: 'brand_new_tool' }, 0);
      expect(s.steps[0].label).not.toContain('brand_new_tool');
      expect(s.steps[0].label).not.toBe('');
    });

    it('list_part_categories is a catalogue step', () => {
      expect(inFrench(undefined, 'list_part_categories').tool).toBe('get_service_parts');
    });
  });
});

// ── The pilot driver (docs/pilot.md): a run with no declared start and no bounded length ──────────
describe('who is at the controls', () => {
  it('a status frame at rest lifts the orb and records the driver — a pilot run declares no start', () => {
    const s = R(initialNoyau, { type: 'status', message: 'Je relis le carnet…', driver: 'pilot' }, 500);
    expect(s.phase).toBe('working');
    expect(s.driver).toBe('pilot');
    expect(s.lastFrameAt).toBe(500);
  });

  it('defaults to the in-app driver when a frame does not say (the turn-bracketed path is unchanged)', () => {
    const s = R(initialNoyau, { type: 'turn', phase: 'start' }, 0);
    expect(s.driver).toBe('app');
  });

  it('goes quiet when a pilot run stops producing frames, and un-quiets on the next one', () => {
    let s = R(initialNoyau, { type: 'status', message: 'a', driver: 'pilot' }, 0);
    expect(s.quiet).toBe(false);
    s = R(s, { type: 'quiet' }, 4000);
    expect(s.quiet).toBe(true);
    expect(s.phase).toBe('working'); // still attached — the run is open, just not acting
    s = R(s, { type: 'status', message: 'b', driver: 'pilot' }, 120_000);
    expect(s.quiet).toBe(false);
    expect(s.lastFrameAt).toBe(120_000);
  });

  it('never goes quiet under the in-app driver — that turn is bounded and its watchdog owns silence', () => {
    let s = R(initialNoyau, { type: 'turn', phase: 'start' }, 0);
    const before = s;
    s = R(s, { type: 'quiet' }, 9000);
    expect(s).toBe(before); // identity: nothing to re-render
  });

  it('settling hands the controls back — no driver, no silence to read', () => {
    let s = R(initialNoyau, { type: 'status', message: 'a', driver: 'pilot' }, 0);
    s = R(s, { type: 'quiet' }, 4000);
    s = R(s, { type: 'turn', phase: 'end', driver: 'pilot' }, 5000);
    expect(s.quiet).toBe(false);
    expect(s.driver).toBe('pilot'); // the done filament still belongs to the pilot
    s = R(s, { type: 'settle' }, 6600);
    expect(s.driver).toBeNull();
    expect(s.lastFrameAt).toBeNull();
  });

  it('a toast refreshes the liveness of an open run but never opens one on its own', () => {
    const idle = R(initialNoyau, { type: 'toast', tone: 'alarm', message: 'Vidange en retard' }, 300);
    expect(idle.phase).toBe('idle');
    expect(idle.lastFrameAt).toBeNull();

    let s = R(initialNoyau, { type: 'status', message: 'a', driver: 'pilot' }, 0);
    s = R(s, { type: 'quiet' }, 4000);
    s = R(s, { type: 'toast', tone: 'ok', message: 'Référence trouvée', driver: 'pilot' }, 5000);
    expect(s.quiet).toBe(false);
    expect(s.lastFrameAt).toBe(5000);
  });

  it('the composer is locked while a driver is actually working, and unlocked once a pilot goes quiet', () => {
    expect(busy(initialNoyau)).toBe(false);
    let s = R(initialNoyau, { type: 'turn', phase: 'start' }, 0);
    expect(busy(s)).toBe(true); // an in-app turn holds the console for its bounded length
    s = R(initialNoyau, { type: 'status', message: 'a', driver: 'pilot' }, 0);
    expect(busy(s)).toBe(true);
    s = R(s, { type: 'quiet' }, 4000);
    expect(busy(s)).toBe(false); // a quiet pilot never holds the dashboard hostage
  });
});

// The retained notice stack. The narration
// line is a heartbeat — overwritten by the next status, cleared on settle — so a `toast` finding used
// to scroll past and vanish. Every toast now also raises a card that stays until the operator says "vu".
describe('Le Noyau — the retained notice stack', () => {
  it('starts with no notices', () => {
    expect(initialNoyau.notices).toEqual([]);
  });

  it('EVERY tone raises a notice, alarm included, while the existing routing is unchanged', () => {
    let s = R(initialNoyau, { type: 'turn', phase: 'start' }, 0);
    s = R(s, { type: 'toast', tone: 'ok', message: 'Vidange enregistrée' }, 100);
    expect(s.notices).toHaveLength(1);
    expect(s.notices[0]).toMatchObject({ tone: 'ok', text: 'Vidange enregistrée', at: 100 });
    expect(s.narration).toBe('Vidange enregistrée'); // heartbeat routing untouched

    s = R(s, { type: 'toast', tone: 'alarm', message: 'Vidange en retard' }, 200);
    expect(s.notices.map((n) => n.tone)).toEqual(['ok', 'alarm']);
  });

  it('the same tone+message re-arriving is ONE card (a pilot repeating itself builds no wall)', () => {
    let s = R(initialNoyau, { type: 'toast', tone: 'warn', message: 'Pas au catalogue' }, 0);
    const first = s.notices;
    s = R(s, { type: 'toast', tone: 'warn', message: 'Pas au catalogue' }, 500);
    expect(s.notices).toBe(first); // identity: no re-render churn either
  });

  it('the stack is capped at NOTICE_CAP — the oldest leaves, the journal keeps everything', () => {
    let s = initialNoyau;
    ['a', 'b', 'c', 'd'].forEach((m, i) => {
      s = R(s, { type: 'toast', tone: 'info', message: m }, i * 10);
    });
    expect(s.notices).toHaveLength(NOTICE_CAP);
    expect(s.notices.map((n) => n.text)).toEqual(['b', 'c', 'd']);
    expect(s.journal.filter((e) => e.kind === 'narration')).toHaveLength(4);
  });

  it('"vu" removes exactly that card and leaves the others up', () => {
    let s = R(initialNoyau, { type: 'toast', tone: 'alarm', message: 'Vidange en retard' }, 0);
    s = R(s, { type: 'toast', tone: 'ok', message: 'Référence trouvée' }, 10);
    s = R(s, { type: 'dismissNotice', id: s.notices[0].id }, 20);
    expect(s.notices.map((n) => n.text)).toEqual(['Référence trouvée']);
  });

  it('an unknown dismiss id is identity (no render, no crash)', () => {
    const s = R(initialNoyau, { type: 'toast', tone: 'ok', message: 'a' }, 0);
    expect(R(s, { type: 'dismissNotice', id: 'nope' }, 10)).toBe(s);
  });

  it('notices survive turn end AND settle — they are what the turn produced', () => {
    let s = R(initialNoyau, { type: 'turn', phase: 'start' }, 0);
    s = R(s, { type: 'toast', tone: 'warn', message: 'Deux véhicules — j’ai pris la Mazda' }, 100);
    s = R(s, { type: 'turn', phase: 'end' }, 200);
    s = R(s, { type: 'settle' }, 300);
    expect(s.phase).toBe('idle');
    expect(s.narration).toBeNull(); // the heartbeat is cleared…
    expect(s.notices).toHaveLength(1); // …the answer is not
  });
});
