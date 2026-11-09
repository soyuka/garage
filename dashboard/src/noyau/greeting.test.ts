import { describe, expect, it } from 'vitest';
import { drawGreeting, greetingForSelection, nothingDrawn, type Drawn } from './greeting';
import { fr } from '../i18n/fr';

describe('greetingForSelection', () => {
  it('welcomes the operator on the first settle with nothing selected', () => {
    expect(greetingForSelection(undefined, null, undefined, false)).toEqual({ kind: 'welcome' });
  });

  // A direct load of `#/v/<seg>` settles straight onto a car: one line has to do both jobs, because a
  // welcome followed half a second later by a vehicle line is the ticker talking over itself.
  it('welcomes the operator AND names the car when the first settle already has one open', () => {
    expect(greetingForSelection(undefined, '/vehicles/1', 'Mazda 6', false)).toEqual({
      kind: 'welcomeVehicle',
      name: 'Mazda 6',
    });
  });

  it('names the vehicle on a later change of selection', () => {
    expect(greetingForSelection(null, '/vehicles/1', 'Mazda 6', false)).toEqual({ kind: 'vehicle', name: 'Mazda 6' });
  });

  it('stays silent when the same vehicle is re-selected', () => {
    expect(greetingForSelection('/vehicles/1', '/vehicles/1', 'Mazda 6', false)).toBeNull();
  });

  it('stays silent while a turn is busy', () => {
    expect(greetingForSelection(null, '/vehicles/1', 'Mazda 6', true)).toBeNull();
  });

  // Returning to the parc says nothing: the operator is looking at every car they own, which is the
  // answer to "which one" already, and a line here would be on screen for as long as they browse.
  it('stays silent when a vehicle is deselected', () => {
    expect(greetingForSelection('/vehicles/1', null, undefined, false)).toBeNull();
  });

  // A nameless car would greet « sélectionnée, que veux-tu savoir ? » — headless, and worse aloud than
  // on screen. Silence is this app's documented degraded mode everywhere else.
  it('stays silent for a vehicle with no usable name', () => {
    expect(greetingForSelection(null, '/garage/vehicles/01J', '', false)).toBeNull();
    expect(greetingForSelection(null, '/garage/vehicles/01J', undefined, false)).toBeNull();
  });
});

/**
 * The lines are DATA (the i18n catalogs), drawn here — no model call. Reversed 2026-09-11, hours after
 * `GET /agent/greeting` shipped.
 */
describe('drawGreeting', () => {
  const first = () => 0;
  const state = (drawn: Drawn = nothingDrawn, rand: () => number = first) => ({
    drawn,
    operator: 'Soyuka',
    rand,
  });

  it('addresses the operator by name, once, and asks what to work on', () => {
    const { text } = drawGreeting({ kind: 'welcome' }, fr.greeting, state());

    expect(text).toBe('Bonjour Soyuka, sur quelle voiture travaille-t-on ?');
  });

  it('greets without a name when the token carries none', () => {
    const { text } = drawGreeting({ kind: 'welcome' }, fr.greeting, { ...state(), operator: undefined });

    expect(text).toBe('Bonjour, sur quelle voiture travaille-t-on ?');
  });

  it('names the car in the same breath as the welcome', () => {
    const { text } = drawGreeting({ kind: 'welcomeVehicle', name: 'Mazda 6' }, fr.greeting, state());

    expect(text).toContain('Soyuka');
    expect(text).toContain('Mazda 6');
  });

  it('names the car alone on a later selection — no second hello', () => {
    const { text } = drawGreeting({ kind: 'vehicle', name: 'Mazda 6' }, fr.greeting, state());

    expect(text).toContain('Mazda 6');
    expect(text).not.toContain('Bonjour');
  });

  /**
   * The point of a pool: a line already drawn is not drawn again while a fresh one is left. Audition
   * enforces "once spoken, never twice" (voice.ts `saidOnce`), and without this the ticker would draw
   * a repeat and then go SILENT on it — a welcome that stops speaking looks like a broken voice.
   */
  it('exhausts the pool before it repeats a line', () => {
    let drawn = nothingDrawn;
    const said = new Set<string>();

    for (let i = 0; i < fr.greeting.vehicle.length; i++) {
      // Always asks for index 0 of what is LEFT, so a pool that ignored `drawn` would answer the same
      // sentence every round.
      const out = drawGreeting({ kind: 'vehicle', name: 'Mazda 6' }, fr.greeting, state(drawn, first));
      expect(said.has(out.text)).toBe(false);
      said.add(out.text);
      drawn = out.drawn;
    }

    expect(said.size).toBe(fr.greeting.vehicle.length);
  });

  it('starts the pool over once every line has been drawn, never repeating the last one back-to-back', () => {
    let drawn = nothingDrawn;
    let last = '';
    for (let i = 0; i < fr.greeting.vehicle.length; i++) {
      const out = drawGreeting({ kind: 'vehicle', name: 'Mazda 6' }, fr.greeting, state(drawn, first));
      drawn = out.drawn;
      last = out.text;
    }

    const over = drawGreeting({ kind: 'vehicle', name: 'Mazda 6' }, fr.greeting, state(drawn, first));

    expect(over.text).not.toBe(last);
    expect(over.drawn.vehicle).toHaveLength(1);
  });

  it('keeps a draw from one pool out of the others', () => {
    const out = drawGreeting({ kind: 'vehicle', name: 'Mazda 6' }, fr.greeting, state());

    expect(out.drawn.vehicle).toHaveLength(1);
    expect(out.drawn.welcome).toEqual([]);
    expect(out.drawn.welcomeVehicle).toEqual([]);
  });

  it('draws with the injected randomness, not from the head of the pool', () => {
    const { text } = drawGreeting({ kind: 'welcome' }, fr.greeting, state(nothingDrawn, () => 0.99));

    expect(text).toBe(`Bonjour Soyuka, ${fr.greeting.welcome[fr.greeting.welcome.length - 1]}`);
  });
});

// Both catalogs are drawn from by the same code, so a pool that is empty — or a shape that drifted —
// in one locale is a crash or a silent greeting for those operators only.
describe('the pools themselves', () => {
  it('offers several lines in both locales', async () => {
    const { en } = await import('../i18n/en');

    for (const cat of [fr.greeting, en.greeting]) {
      expect(cat.welcome.length).toBeGreaterThanOrEqual(3);
      expect(cat.welcomeVehicle.length).toBeGreaterThanOrEqual(3);
      expect(cat.vehicle.length).toBeGreaterThanOrEqual(3);
    }
  });
});
