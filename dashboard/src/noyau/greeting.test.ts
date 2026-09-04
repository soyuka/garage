import { describe, expect, it } from 'vitest';
import { greetingForSelection } from './greeting';

describe('greetingForSelection', () => {
  it('greets "no vehicle" on the first settle with none selected', () => {
    expect(greetingForSelection(undefined, null, undefined, false)).toEqual({ kind: 'none' });
  });

  it('greets the named vehicle on a change of selection', () => {
    expect(greetingForSelection(null, '/vehicles/1', 'Mazda 6', false)).toEqual({ kind: 'vehicle', name: 'Mazda 6' });
  });

  it('stays silent when the same vehicle is re-selected', () => {
    expect(greetingForSelection('/vehicles/1', '/vehicles/1', 'Mazda 6', false)).toBeNull();
  });

  it('stays silent while a turn is busy', () => {
    expect(greetingForSelection(null, '/vehicles/1', 'Mazda 6', true)).toBeNull();
  });

  it('greets "no vehicle" again after a vehicle is deselected', () => {
    expect(greetingForSelection('/vehicles/1', null, undefined, false)).toEqual({ kind: 'none' });
  });

  // A nameless car would greet « sélectionnée. Que souhaitez-vous savoir ? » — headless, and worse
  // aloud than on screen. Silence is this app's documented degraded mode everywhere else.
  it('stays silent for a vehicle with no usable name', () => {
    expect(greetingForSelection(null, '/garage/vehicles/01J', '', false)).toBeNull();
    expect(greetingForSelection(null, '/garage/vehicles/01J', undefined, false)).toBeNull();
  });
});
