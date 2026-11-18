import { describe, expect, it } from 'vitest';
import { topicForService, topicForText, regionOnBody } from './focusTopic';

describe('topicForText', () => {
  it('reads the clutch off French and English text, onto the front axle', () => {
    expect(topicForText('Kit embrayage Valeo + volant bi-masse LuK trouvés')).toEqual({ topic: 'clutch', region: 'frontAxle' });
    expect(topicForText('Looking up the clutch kit')).toEqual({ topic: 'clutch', region: 'frontAxle' });
  });

  it('puts the timing belt and the oil change on the hood, the air filter in the bay', () => {
    expect(topicForText('Courroie de distribution à 180 000 km')?.region).toBe('hood');
    expect(topicForText('Vidange dépassée')).toEqual({ topic: 'oil_change', region: 'hood' });
    expect(topicForText('Filtre à air à commander')).toEqual({ topic: 'air_filter', region: 'bay' });
  });

  it('reads rear brakes before brakes in general', () => {
    expect(topicForText('Plaquettes de frein arrière usées')).toEqual({ topic: 'brake_pads', region: 'rearAxle' });
    expect(topicForText('Plaquettes de frein avant')).toEqual({ topic: 'brake_pads', region: 'frontAxle' });
  });

  it('is null on text naming no part', () => {
    expect(topicForText('Je regarde le garage')).toBeNull();
    expect(topicForText('')).toBeNull();
  });
});

describe('topicForService', () => {
  it('maps a service type onto its region, with the transmission on the front axle', () => {
    expect(topicForService('clutch')).toEqual({ topic: 'clutch', region: 'frontAxle' });
    expect(topicForService('timing_belt')).toEqual({ topic: 'timing_belt', region: 'hood' });
    expect(topicForService('brake_pads')).toEqual({ topic: 'brake_pads', region: 'frontAxle' });
  });
});

describe('regionOnBody', () => {
  it('reads the clutch and gearbox off the engine case on a motorcycle', () => {
    for (const topic of ['clutch', 'gearbox_oil', 'flywheel']) {
      expect(regionOnBody('frontAxle', topic, 'moto')).toBe('hood');
    }
  });

  it('keeps the front axle for a car, and every other topic as derived', () => {
    expect(regionOnBody('frontAxle', 'clutch', 'car')).toBe('frontAxle');
    expect(regionOnBody('frontAxle', 'brake_pads', 'moto')).toBe('frontAxle');
    expect(regionOnBody('rearAxle', null, 'moto')).toBe('rearAxle');
  });
});
