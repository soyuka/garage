import { describe, it, expect } from 'vitest';
import { resolveMesh } from './vehicleMesh';

// The pure mesh resolver: a vehicle → which default blueprint glb to load + its edge angle. Make/model
// specifics win over the generic per-VehicleType fallback. Both the legacy fixture spelling
// (car/motorcycle) and the API enum (car/moto/cv/groundscare) reach this at runtime, so both resolve.

const v = (vehicleType: string, make = '', model = '') => ({ vehicleType, make, model });

describe('resolveMesh — make/model specifics win', () => {
  it('any Espace → the make/model-specific Espace mesh', () => {
    expect(resolveMesh(v('car', 'Renault', 'Espace V')).url).toBe('/renault-espace-iv-web.glb');
    expect(resolveMesh(v('car', 'Renault', 'Grand Espace')).url).toBe('/renault-espace-iv-web.glb');
  });
  it('Mazda 6 in its spellings → the Mazda6 mesh', () => {
    expect(resolveMesh(v('car', 'Mazda', '6')).url).toBe('/mazda6-gh-web.glb');
    expect(resolveMesh(v('car', 'Mazda', 'Mazda 6')).url).toBe('/mazda6-gh-web.glb');
    expect(resolveMesh(v('car', 'Mazda', 'Mazda6')).url).toBe('/mazda6-gh-web.glb');
  });
  it('a make/model match wins even when the vehicleType would route elsewhere', () => {
    expect(resolveMesh(v('cv', 'Renault', 'Espace V')).url).toBe('/renault-espace-iv-web.glb');
  });
});

describe('resolveMesh — VehicleType fallback (both spellings)', () => {
  it('car / empty / unknown → the car default (Mazda6, NOT car-default)', () => {
    expect(resolveMesh(v('car')).url).toBe('/mazda6-gh-web.glb');
    expect(resolveMesh(v('')).url).toBe('/mazda6-gh-web.glb');
    expect(resolveMesh(v('spaceship')).url).toBe('/mazda6-gh-web.glb');
  });
  it('moto and motorcycle → the moto default', () => {
    expect(resolveMesh(v('moto')).url).toBe('/moto-default-web.glb');
    expect(resolveMesh(v('motorcycle')).url).toBe('/moto-default-web.glb');
  });
  it('cv, truck, van → the cv default', () => {
    expect(resolveMesh(v('cv')).url).toBe('/cv-default-web.glb');
    expect(resolveMesh(v('truck')).url).toBe('/cv-default-web.glb');
    expect(resolveMesh(v('van')).url).toBe('/cv-default-web.glb');
  });
  it('groundscare / mower → the groundscare default', () => {
    expect(resolveMesh(v('groundscare')).url).toBe('/groundscare-default-web.glb');
    expect(resolveMesh(v('mower')).url).toBe('/groundscare-default-web.glb');
  });
});

describe('resolveMesh — edge angle + invariants', () => {
  it('moto edges out at 45°, everything else at 30°', () => {
    expect(resolveMesh(v('moto')).edgeAngle).toBe(45);
    expect(resolveMesh(v('motorcycle')).edgeAngle).toBe(45);
    expect(resolveMesh(v('car')).edgeAngle).toBe(30);
    expect(resolveMesh(v('cv')).edgeAngle).toBe(30);
    expect(resolveMesh(v('car', 'Renault', 'Espace V')).edgeAngle).toBe(30);
  });
  it('every url is a same-origin absolute .glb path', () => {
    for (const t of ['car', 'moto', 'cv', 'groundscare', 'spaceship', '']) {
      const { url } = resolveMesh(v(t));
      expect(url.startsWith('/')).toBe(true);
      expect(url.endsWith('.glb')).toBe(true);
    }
  });
  it('edgeAngle is always finite; rotationY, when set, is a multiple of a right angle', () => {
    for (const t of ['car', 'moto', 'cv', 'groundscare']) {
      const spec = resolveMesh(v(t));
      expect(Number.isFinite(spec.edgeAngle)).toBe(true);
      if (spec.rotationY !== undefined) {
        const k = spec.rotationY / (Math.PI / 2);
        expect(Number.isInteger(Math.round(k * 1e6) / 1e6)).toBe(true);
      }
    }
  });
});

describe('resolveMesh — thumbUrl (low-poly parc-grid variant)', () => {
  it('derives -thumb.glb from the -web.glb url, same-origin absolute', () => {
    expect(resolveMesh(v('car', 'Renault', 'Espace V')).thumbUrl).toBe('/renault-espace-iv-thumb.glb');
    expect(resolveMesh(v('car', 'Mazda', '6')).thumbUrl).toBe('/mazda6-gh-thumb.glb');
    expect(resolveMesh(v('moto')).thumbUrl).toBe('/moto-default-thumb.glb');
    expect(resolveMesh(v('cv')).thumbUrl).toBe('/cv-default-thumb.glb');
    expect(resolveMesh(v('groundscare')).thumbUrl).toBe('/groundscare-default-thumb.glb');
    expect(resolveMesh(v('car')).thumbUrl).toBe('/mazda6-gh-thumb.glb');
  });
  it('thumbUrl honours make/model specificity exactly like url', () => {
    const espaceTypedCv = resolveMesh(v('cv', 'Renault', 'Espace V'));
    expect(espaceTypedCv.thumbUrl).toBe('/renault-espace-iv-thumb.glb');
  });
  it('every thumbUrl is a same-origin absolute .glb (falls back to url if no -web suffix)', () => {
    for (const t of ['car', 'moto', 'cv', 'groundscare', 'spaceship', '']) {
      const { url, thumbUrl } = resolveMesh(v(t));
      expect(thumbUrl.startsWith('/')).toBe(true);
      expect(thumbUrl.endsWith('.glb')).toBe(true);
      expect(thumbUrl === url || thumbUrl === url.replace(/-web\.glb$/, '-thumb.glb')).toBe(true);
    }
  });
});
