import { describe, it, expect } from 'vitest';
import { assemblyFor, assemblySize, hasAssembly, assemblyDelay, ASSEMBLY_STAGGER, ASSEMBLY_STAGGER_CAP } from './assembly';
import { BODY_REGIONS } from './anchors';

// C3 — the schematic assembly's pure core. The diagram is authored, so what is testable is that it is
// COMPLETE (every region reveals something), HONEST (offsets stay inside the region, no part flying off
// into the next one) and that the callout selection behaves like the rest of the key vocabulary.

describe('assemblyFor — every region has a diagram', () => {
  it('draws something for every region the camera can be sent to', () => {
    for (const region of BODY_REGIONS) {
      expect(hasAssembly(region)).toBe(true);
      expect(assemblyFor(region).length).toBeGreaterThan(0);
      expect(assemblySize(region)).toBe(assemblyFor(region).length);
    }
  });

  it('keeps every part within arm’s reach of the anchor — a diagram, not a scatter', () => {
    for (const region of BODY_REGIONS) {
      for (const part of assemblyFor(region)) {
        for (const axis of part.offset) expect(Math.abs(axis)).toBeLessThanOrEqual(0.7);
        expect(part.size).toBeGreaterThan(0);
        // Generous, because the diagram is schematic and sized to READ, but still bounded: a part
        // wider than the car it sits in has stopped being a diagram of a region.
        expect(part.size).toBeLessThanOrEqual(1.6);
      }
    }
  });

  it('gives every part a label and a key — a shape with no name is decoration', () => {
    for (const region of BODY_REGIONS) {
      for (const part of assemblyFor(region)) {
        expect(part.label.length).toBeGreaterThan(0);
        expect(part.key.length).toBeGreaterThan(0);
      }
    }
  });
});

describe('assemblyFor — keys call parts out, they do not filter the circuit', () => {
  it('highlights nothing when no keys are given', () => {
    expect(assemblyFor('bay').every((p) => !p.highlighted)).toBe(true);
  });

  it('still draws the WHOLE circuit when one part is named (context is the point)', () => {
    const all = assemblyFor('bay');
    const one = assemblyFor('bay', ['fuel_filter']);
    expect(one).toHaveLength(all.length);
    expect(one.filter((p) => p.highlighted).map((p) => p.key)).toEqual(['fuel_filter']);
  });

  it('folds spelling variants through canonicalService, like every other key surface', () => {
    const canonical = assemblyFor('frontAxle', ['tyres']).filter((p) => p.highlighted);
    const variant = assemblyFor('frontAxle', ['tires']).filter((p) => p.highlighted);
    expect(canonical.length).toBeGreaterThan(0);
    expect(variant.map((p) => p.key)).toEqual(canonical.map((p) => p.key));
  });

  it('ignores a key the diagram does not know rather than throwing', () => {
    const parts = assemblyFor('hood', ['oil_filter', 'not_a_part']);
    expect(parts.filter((p) => p.highlighted).map((p) => p.key)).toEqual(['oil_filter']);
  });

  it('returns a fresh array each call — a caller marking parts must not mutate the diagram', () => {
    const a = assemblyFor('hood', ['oil_filter']);
    a[0].highlighted = true;
    expect(assemblyFor('hood')[0].highlighted).toBe(false);
  });
});

describe('assemblyDelay — the rise stagger, capped like the rail’s', () => {
  it('starts the first part immediately', () => {
    expect(assemblyDelay(0)).toBe(0);
  });

  it('steps one stagger per part', () => {
    expect(assemblyDelay(2)).toBeCloseTo(2 * ASSEMBLY_STAGGER);
  });

  it('caps, so the last part of a long diagram is never late', () => {
    expect(assemblyDelay(9)).toBe(assemblyDelay(ASSEMBLY_STAGGER_CAP));
    expect(assemblyDelay(9)).toBeCloseTo(ASSEMBLY_STAGGER_CAP * ASSEMBLY_STAGGER);
  });

  it('never delays a part longer than a fan — a stagger is not a beat sheet', () => {
    expect(assemblyDelay(ASSEMBLY_STAGGER_CAP)).toBeLessThan(0.5);
  });
});
