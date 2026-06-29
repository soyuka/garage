import { describe, it, expect } from 'vitest';
import { initialNoyau, reduceNoyau } from './state';

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
