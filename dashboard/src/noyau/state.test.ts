import { describe, it, expect } from 'vitest';
import { classifyStep, initialNoyau, reduceNoyau, type DecisionItem } from './state';

const R = reduceNoyau;

describe('Le Noyau frame→state machine', () => {
  it('starts idle (veille)', () => {
    expect(initialNoyau.phase).toBe('idle');
    expect(initialNoyau.pulseCount).toBe(0);
    expect(initialNoyau.ember).toBe(0);
  });

  it('typing in idle → listening; stop typing → idle', () => {
    const s1 = R(initialNoyau, { type: 'typing', active: true }, 0);
    expect(s1.phase).toBe('listening');
    const s2 = R(s1, { type: 'typing', active: false }, 1);
    expect(s2.phase).toBe('idle');
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

  it('alarm toast docks an ember and does NOT overwrite the narration line', () => {
    let s = R(initialNoyau, { type: 'turn', phase: 'start' }, 0);
    s = R(s, { type: 'status', message: 'narr' }, 100);
    s = R(s, { type: 'toast', tone: 'alarm', message: 'Vidange en retard' }, 200);
    expect(s.ember).toBe(1);
    expect(s.narration).toBe('narr');
  });

  it('turn end with no warnings → done=green, and the result is announced as TEXT (not color-only)', () => {
    let s = R(initialNoyau, { type: 'turn', phase: 'start' }, 0);
    s = R(s, { type: 'status', message: 'x' }, 100);
    s = R(s, { type: 'turn', phase: 'end' }, 200);
    expect(s.phase).toBe('done');
    expect(s.result).toBe('green');
    // honesty: the signal must be legible as a mono label, not conveyed by glow alone (§8).
    expect(s.narration).toContain('posé');
    const done = s.journal.find((e) => e.kind === 'done');
    expect(done?.signal).toBe('green');
    expect(done?.message).toBe(s.narration); // the journal carries the same legible line
  });

  it('the done narration differs per signal so green/amber/red are distinguishable without color', () => {
    const end = (tone: 'ok' | 'warn' | 'alarm') => {
      let s = R(initialNoyau, { type: 'turn', phase: 'start' }, 0);
      s = R(s, { type: 'toast', tone, message: 'm' }, 50);
      return R(s, { type: 'turn', phase: 'end' }, 100).narration;
    };
    const lines = new Set([end('ok'), end('warn'), end('alarm')]);
    expect(lines.size).toBe(3); // three distinct mono labels
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

  it('settle returns to idle, clears result + worstTone, but keeps the ember', () => {
    let s = R(initialNoyau, { type: 'turn', phase: 'start' }, 0);
    s = R(s, { type: 'toast', tone: 'alarm', message: 'e' }, 50);
    s = R(s, { type: 'turn', phase: 'end' }, 100);
    s = R(s, { type: 'settle' }, 2000);
    expect(s.phase).toBe('idle');
    expect(s.result).toBeNull();
    expect(s.worstTone).toBeNull(); // a between-turn alarm (the §9 overdue push) must not linger
    expect(s.ember).toBe(1);
  });

  it('typing is ignored while working (no moment disables the busy orb)', () => {
    let s = R(initialNoyau, { type: 'turn', phase: 'start' }, 0);
    s = R(s, { type: 'typing', active: true }, 50);
    expect(s.phase).toBe('working');
  });

  it('clearEmber acknowledges docked notifications', () => {
    let s = R(initialNoyau, { type: 'toast', tone: 'alarm', message: 'e' }, 0);
    expect(s.ember).toBe(1);
    s = R(s, { type: 'clearEmber' }, 1);
    expect(s.ember).toBe(0);
  });

  it('a status without an explicit turn-start still implies working (robust to stream gaps)', () => {
    const s = R(initialNoyau, { type: 'status', message: 'x' }, 0);
    expect(s.phase).toBe('working');
    expect(s.pulseCount).toBe(1);
  });

  it('concern (the §9 overdue DOMAIN push) docks an ember + murmurs the line — but is HANDS-OFF (no turn)', () => {
    const s = R(initialNoyau, { type: 'concern', message: 'Vidange en retard · 142 000 km' }, 0);
    expect(s.phase).toBe('idle'); // NOT working — a domain frame is not an agent turn (CHOREOGRAPHY)
    expect(s.pulseCount).toBe(0); // no working pulse
    expect(s.ember).toBe(1); // the notification docks
    expect(s.narration).toBe('Vidange en retard · 142 000 km'); // the concern murmurs on the aria-live line
    expect(s.journal[s.journal.length - 1]?.kind).toBe('alarm');
  });

  it('concern never interrupts an active turn into a fake idle', () => {
    let s = R(initialNoyau, { type: 'turn', phase: 'start' }, 0);
    s = R(s, { type: 'concern', message: 'm' }, 50);
    expect(s.phase).toBe('working'); // a turn in flight stays working; the ember still docks
    expect(s.ember).toBe(1);
  });
});

// The typed tool-step chips (C1). The live wire carries NO tool name — StepNarrator pre-maps each tool
// call to a French sentence on the `status` frame — so we reverse-map that sentence back to a tool
// identity + a SHORT chip verb. See api/src/Agent/Ui/StepNarrator.php for the source sentences.
describe('classifyStep — narration sentence → {tool, short verb}', () => {
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
    expect(classifyStep(message)).toEqual({ tool, label });
  });

  it('also classifies the mock-script variants (keyword-matched, not exact)', () => {
    expect(classifyStep('Recherche des pièces · TecDoc…').tool).toBe('get_service_parts');
    expect(classifyStep('Comparaison des références…').tool).toBe('get_service_parts');
    expect(classifyStep('Calcul des échéances…').tool).toBe('list_due_maintenance');
    expect(classifyStep("Viscosité d'huile…").tool).toBe('get_motul_oil');
  });

  it('an unmatched sentence → tool "unknown", label = the message itself (humanized fallback)', () => {
    expect(classifyStep('Quelque chose de nouveau…')).toEqual({ tool: 'unknown', label: 'Quelque chose de nouveau…' });
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
});

// C2 — the ember becomes an "à décider" ambient inbox: a QUEUE of decision items (notify/question/
// review, the LangChain ambient-agent triad, human ON the loop). The ember badge counts the pending
// queue; the sources are the §9 concern push + alarm toasts (notify) and a deferred/superseded CTA
// (review). "question" is supported in the model but has no live wire source yet.
describe('Le Noyau — the à-décider ambient inbox queue', () => {
  it('starts with an empty queue', () => {
    expect(initialNoyau.queue).toEqual([]);
    expect(initialNoyau.ember).toBe(0);
  });

  it('an alarm toast enqueues a NOTIFY decision item; the ember tracks the queue length', () => {
    const s = R(initialNoyau, { type: 'toast', tone: 'alarm', message: 'Vidange en retard' }, 0);
    expect(s.queue).toHaveLength(1);
    expect(s.queue[0]).toMatchObject({ kind: 'notify', text: 'Vidange en retard', at: 0 });
    expect(s.ember).toBe(1);
  });

  it('a §9 concern enqueues a notify item carrying its service type + vehicle (→ planifier)', () => {
    const s = R(
      initialNoyau,
      { type: 'concern', message: 'Vidange en retard · 142 000 km', serviceType: 'oil_change', vehicleIri: '/garage/vehicles/1' },
      0,
    );
    expect(s.queue).toHaveLength(1);
    expect(s.queue[0]).toMatchObject({ kind: 'notify', serviceType: 'oil_change', vehicleIri: '/garage/vehicles/1' });
    expect(s.ember).toBe(1);
  });

  it('a deferred/superseded proposal enqueues a REVIEW item carrying the CTA actions', () => {
    const actions = [{ label: 'Ajouter', patchTask: { id: '/garage/planned-tasks/1', parts: [] } }];
    const s = R(initialNoyau, { type: 'defer', id: 'review:x', text: 'Filtre à air à prévoir', finding: 'détecté', actions }, 5);
    expect(s.queue).toHaveLength(1);
    expect(s.queue[0]).toMatchObject({ id: 'review:x', kind: 'review', text: 'Filtre à air à prévoir', finding: 'détecté', actions });
    expect(s.ember).toBe(1);
  });

  it('dedups by id — the same concern (same service type) arriving twice queues once', () => {
    let s = R(initialNoyau, { type: 'concern', message: 'Vidange en retard · 100 000', serviceType: 'oil_change', vehicleIri: '/v/1' }, 0);
    s = R(s, { type: 'concern', message: 'Vidange en retard · 142 000', serviceType: 'oil_change', vehicleIri: '/v/1' }, 10);
    expect(s.queue).toHaveLength(1);
    expect(s.ember).toBe(1);
  });

  it('resolve removes the item by id and the badge shrinks with the queue', () => {
    let s = R(initialNoyau, { type: 'toast', tone: 'alarm', message: 'a' }, 0);
    s = R(s, { type: 'concern', message: 'b', serviceType: 't', vehicleIri: '/v/1' }, 1);
    expect(s.queue).toHaveLength(2);
    expect(s.ember).toBe(2);
    const firstId = s.queue[0].id;
    s = R(s, { type: 'resolve', id: firstId }, 2);
    expect(s.queue.map((q) => q.id)).not.toContain(firstId);
    expect(s.queue).toHaveLength(1);
    expect(s.ember).toBe(1);
  });

  it('resolve on an unknown id is a no-op (referentially stable)', () => {
    const s0 = R(initialNoyau, { type: 'toast', tone: 'alarm', message: 'a' }, 0);
    const s1 = R(s0, { type: 'resolve', id: 'nope' }, 1);
    expect(s1).toBe(s0);
  });

  it('opening the inbox (clearEmber) clears the BADGE but keeps the pending queue', () => {
    let s = R(initialNoyau, { type: 'toast', tone: 'alarm', message: 'a' }, 0);
    s = R(s, { type: 'clearEmber' }, 1);
    expect(s.ember).toBe(0); // the badge is muted (acknowledged)
    expect(s.queue).toHaveLength(1); // the decision is still pending — clearing ≠ deleting
  });

  it('a new decision arriving after the badge was cleared re-lights the ember', () => {
    let s = R(initialNoyau, { type: 'toast', tone: 'alarm', message: 'a' }, 0);
    s = R(s, { type: 'clearEmber' }, 1);
    expect(s.ember).toBe(0);
    s = R(s, { type: 'concern', message: 'b', serviceType: 't', vehicleIri: '/v/1' }, 2);
    expect(s.queue).toHaveLength(2);
    expect(s.ember).toBe(2); // both pending decisions still await the operator
  });

  it('the pending queue outlives a turn (settle clears turn state but not decisions)', () => {
    let s = R(initialNoyau, { type: 'concern', message: 'b', serviceType: 't', vehicleIri: '/v/1' }, 0);
    s = R(s, { type: 'turn', phase: 'start' }, 1);
    s = R(s, { type: 'turn', phase: 'end' }, 2);
    s = R(s, { type: 'settle' }, 2000);
    expect(s.queue).toHaveLength(1);
  });

  it('resolving the last item leaves an empty queue and a dark ember', () => {
    let s = R(initialNoyau, { type: 'toast', tone: 'alarm', message: 'a' }, 0);
    s = R(s, { type: 'resolve', id: s.queue[0].id }, 1);
    expect(s.queue).toHaveLength(0);
    expect(s.ember).toBe(0);
  });

  it("supports a 'question' kind in the model (no live wire source feeds it yet)", () => {
    const item: DecisionItem = { id: 'q1', kind: 'question', text: 'Quel kilométrage ?', at: 0 };
    expect(item.kind).toBe('question');
  });
});
