import { describe, it, expect } from 'vitest';
import { bodyClassFor } from './CarProxy';
import { regionAnchors, BODY_REGIONS } from './anchors';

// bodyClassFor maps a backend VehicleType to a blueprint body SHAPE. Two spellings reach it at runtime:
// the legacy fixtures (car/motorcycle) and the API enum App\Garage\VehicleType (car/moto/cv/groundscare).
// Whatever it returns must be a valid DIMS/regionAnchors key (the anchors seam reads it).

describe('bodyClassFor — VehicleType → body shape (both spellings)', () => {
  it('moto and motorcycle both read as a moto (was the live bug: moto fell through to berline)', () => {
    expect(bodyClassFor('moto')).toBe('moto');
    expect(bodyClassFor('motorcycle')).toBe('moto');
  });
  it('cv / truck / van read as utilitaire', () => {
    expect(bodyClassFor('cv')).toBe('utilitaire');
    expect(bodyClassFor('truck')).toBe('utilitaire');
    expect(bodyClassFor('van')).toBe('utilitaire');
  });
  it('groundscare / mower read as utilitaire (no dedicated mower body class — least-wrong fallback)', () => {
    expect(bodyClassFor('groundscare')).toBe('utilitaire');
    expect(bodyClassFor('mower')).toBe('utilitaire');
  });
  it('car and anything unknown read as a berline', () => {
    expect(bodyClassFor('car')).toBe('berline');
    expect(bodyClassFor('')).toBe('berline');
    expect(bodyClassFor('spaceship')).toBe('berline');
  });
  it('always returns a usable anchors key — regionAnchors yields finite pins for every input', () => {
    for (const t of ['moto', 'motorcycle', 'cv', 'truck', 'groundscare', 'car', '', 'spaceship']) {
      const a = regionAnchors(bodyClassFor(t));
      for (const r of BODY_REGIONS) {
        const [x, y, z] = a[r];
        expect(Number.isFinite(x) && Number.isFinite(y) && Number.isFinite(z), `${t}.${r}`).toBe(true);
      }
    }
  });
});
