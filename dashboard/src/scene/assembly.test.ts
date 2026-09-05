import { describe, it, expect } from 'vitest';
import {
  assemblyFor,
  assemblySize,
  hasAssembly,
  assemblyDelay,
  ASSEMBLY_STAGGER,
  ASSEMBLY_STAGGER_CAP,
  ASSEMBLY_RISE,
  DISC_UPRIGHT,
  PART_MESHES,
  meshFit,
  partMeshUrl,
  riseT,
  schematicBox,
} from './assembly';
import { BODY_REGIONS } from './anchors';
import { MOTION } from '../tokens/motion';

// Read the shipped files through Vite, like i18n/hardcoded.test.ts — no @types/node in this project.
// The glob is lazy: Vite lists the keys, nothing is loaded.
const SHIPPED = new Set(Object.keys(import.meta.glob('../../public/parts/*.glb')).map((p) => p.split('/').pop()!));

// C3 — the schematic assembly's pure core. The diagram is authored, so what is testable is that it is
// COMPLETE (every region reveals something), HONEST (offsets stay inside the region, no part flying off
// into the next one) and that the callout selection behaves like the rest of the key vocabulary.

describe('assemblyFor — every region has a diagram', () => {
  it('draws something for every region the camera can be sent to', () => {
    for (const region of BODY_REGIONS) {
      expect(hasAssembly(region)).toBe(true);
      expect(assemblyFor(region, 'fr').length).toBeGreaterThan(0);
      expect(assemblySize(region)).toBe(assemblyFor(region, 'fr').length);
    }
  });

  it('keeps every part within arm’s reach of the anchor — a diagram, not a scatter', () => {
    for (const region of BODY_REGIONS) {
      for (const part of assemblyFor(region, 'fr')) {
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
      for (const part of assemblyFor(region, 'fr')) {
        expect(part.label.length).toBeGreaterThan(0);
        expect(part.key.length).toBeGreaterThan(0);
      }
    }
  });
});

describe('assemblyFor — keys call parts out, they do not filter the circuit', () => {
  it('highlights nothing when no keys are given', () => {
    expect(assemblyFor('bay', 'fr').every((p) => !p.highlighted)).toBe(true);
  });

  it('still draws the WHOLE circuit when one part is named (context is the point)', () => {
    const all = assemblyFor('bay', 'fr');
    const one = assemblyFor('bay', 'fr', ['fuel_filter']);
    expect(one).toHaveLength(all.length);
    expect(one.filter((p) => p.highlighted).map((p) => p.key)).toEqual(['fuel_filter']);
  });

  it('folds spelling variants through canonicalService, like every other key surface', () => {
    const canonical = assemblyFor('frontAxle', 'fr', ['tyres']).filter((p) => p.highlighted);
    const variant = assemblyFor('frontAxle', 'fr', ['tires']).filter((p) => p.highlighted);
    expect(canonical.length).toBeGreaterThan(0);
    expect(variant.map((p) => p.key)).toEqual(canonical.map((p) => p.key));
  });

  it('ignores a key the diagram does not know rather than throwing', () => {
    const parts = assemblyFor('hood', 'fr', ['oil_filter', 'not_a_part']);
    expect(parts.filter((p) => p.highlighted).map((p) => p.key)).toEqual(['oil_filter']);
  });

  it('returns a fresh array each call — a caller marking parts must not mutate the diagram', () => {
    const a = assemblyFor('hood', 'fr', ['oil_filter']);
    a[0].highlighted = true;
    expect(assemblyFor('hood', 'fr')[0].highlighted).toBe(false);
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

describe('PART_MESHES — generic meshes only, and only ones that ship', () => {
  it('names only files present in public/parts', () => {
    expect(SHIPPED.size).toBeGreaterThan(0);
    for (const spec of Object.values(PART_MESHES)) {
      expect(SHIPPED.has(`${spec!.file}.glb`), spec!.file).toBe(true);
    }
  });

  it('never wires a vehicle-specific or a state-specific mesh', () => {
    const files = Object.values(PART_MESHES).map((s) => s!.file as string);
    expect(files).not.toContain('engine-v6');
    expect(files).not.toContain('spark-plug-worn');
    // Refused files were removed from disk (2026-09-05) — nothing dead ships.
    expect(SHIPPED.has('engine-v6.glb')).toBe(false);
    expect(SHIPPED.has('spark-plug-worn.glb')).toBe(false);
  });

  it('maps only keys a diagram draws', () => {
    const drawn = new Set(BODY_REGIONS.flatMap((r) => assemblyFor(r, 'fr').map((p) => p.key)));
    for (const key of Object.keys(PART_MESHES)) {
      expect(drawn.has(key), key).toBe(true);
    }
  });

  it('attaches the mesh to every occurrence of a mapped key', () => {
    const frontAxle = assemblyFor('frontAxle', 'fr').find((p) => p.key === 'brake_discs')!;
    const rearAxle = assemblyFor('rearAxle', 'fr').find((p) => p.key === 'brake_discs')!;
    expect(frontAxle.mesh?.file).toBe('brake-disc');
    expect(rearAxle.mesh?.file).toBe('brake-disc');
    const hood = assemblyFor('hood', 'fr');
    expect(hood.find((p) => p.key === 'oil_filter')!.mesh?.file).toBe('oil-filter');
    expect(hood.find((p) => p.key === 'spark_plug')!.mesh?.file).toBe('spark-plug');
    expect(hood.find((p) => p.key === 'engine')!.mesh?.file).toBe('engine-block');
    const bay = assemblyFor('bay', 'fr');
    expect(bay.find((p) => p.key === 'battery')!.mesh?.file).toBe('battery');
  });

  it('leaves fuel_filter on its primitive — the oil-filter mesh is a different part', () => {
    const part = assemblyFor('bay', 'fr').find((p) => p.key === 'fuel_filter')!;
    expect(part.mesh).toBeUndefined();
    expect('mesh' in part).toBe(false);
  });

  it('the disc is yawed onto the axle', () => {
    expect(PART_MESHES.brake_discs!.rotationY).toBeCloseTo(Math.PI / 2);
    for (const [key, spec] of Object.entries(PART_MESHES)) {
      if (key === 'brake_discs') continue;
      expect(spec!.rotationY, key).toBeUndefined();
    }
  });

  it('the plug is pitched upright — it lies along z on disk, but stands in its socket', () => {
    expect(PART_MESHES.spark_plug!.rotationX).toBeCloseTo(Math.PI / 2);
    for (const [key, spec] of Object.entries(PART_MESHES)) {
      if (key === 'spark_plug') continue;
      expect(spec!.rotationX, key).toBeUndefined();
    }
  });
});

describe('partMeshUrl', () => {
  it('builds the /parts/ url for a mapped part', () => {
    expect(partMeshUrl({ mesh: { file: 'oil-filter' } })).toBe('/parts/oil-filter.glb');
  });
  it('is undefined for a part with no mesh', () => {
    expect(partMeshUrl({})).toBeUndefined();
  });
});

describe("schematicBox — the primitive's box, disc upright", () => {
  it('matches the primitive geometry per shape', () => {
    expect(schematicBox('canister', 0.135)[0]).toBeCloseTo(0.27, 6);
    expect(schematicBox('canister', 0.135)[1]).toBeCloseTo(0.324, 6);
    expect(schematicBox('canister', 0.135)[2]).toBeCloseTo(0.27, 6);

    expect(schematicBox('housing', 1.116)[0]).toBeCloseTo(1.116, 6);
    expect(schematicBox('housing', 1.116)[1]).toBeCloseTo(0.69192, 6);
    expect(schematicBox('housing', 1.116)[2]).toBeCloseTo(0.87048, 6);

    expect(schematicBox('node', 0.072)[0]).toBeCloseTo(0.144, 6);
    expect(schematicBox('node', 0.072)[1]).toBeCloseTo(0.144, 6);
    expect(schematicBox('node', 0.072)[2]).toBeCloseTo(0.144, 6);

    expect(schematicBox('line', 0.9)[0]).toBeCloseTo(0.9, 6);
    expect(schematicBox('line', 0.9)[1]).toBeCloseTo(0.012, 6);
    expect(schematicBox('line', 0.9)[2]).toBeCloseTo(0.012, 6);
  });

  it("the disc's thin axis is z — it stands on the axle", () => {
    const box = schematicBox('disc', 0.306);
    expect(box[0]).toBeCloseTo(0.612, 6);
    expect(box[1]).toBeCloseTo(0.612, 6);
    expect(box[2]).toBeCloseTo(0.04896, 6);
    expect(DISC_UPRIGHT[0]).toBeCloseTo(Math.PI / 2);
    expect(DISC_UPRIGHT[1]).toBe(0);
    expect(DISC_UPRIGHT[2]).toBe(0);
  });
});

describe('meshFit — the longest axis, matched', () => {
  it("fits the engine block's HEIGHT to the housing's height, not its length", () => {
    const f = meshFit([0.433, 0.7, 0.386], schematicBox('housing', 1.116));
    expect(f.scale).toBeCloseTo(0.69192 / 0.7, 4);
    expect(0.7 * f.scale).toBeCloseTo(0.69192);
  });

  it("fits the battery's depth to the housing's depth", () => {
    const f = meshFit([0.236, 0.182, 0.26], schematicBox('housing', 0.432));
    expect(f.scale).toBeCloseTo(0.33696 / 0.26, 4);
  });

  it("fits the plug to the sphere along its own long axis (z)", () => {
    const f = meshFit([0.022, 0.022, 0.095], schematicBox('node', 0.072));
    expect(f.scale).toBeCloseTo(0.144 / 0.095, 4);
  });

  it("fits the yawed disc's face to the upright disc", () => {
    const f = meshFit([0.334, 0.324, 0.124], schematicBox('disc', 0.306));
    expect(f.scale).toBeCloseTo(0.612 / 0.334, 4);
  });

  it("fits the filter's height to the canister's", () => {
    const f = meshFit([0.092, 0.092, 0.1], schematicBox('canister', 0.135));
    expect(f.scale).toBeCloseTo(0.27 / 0.1, 4);
  });

  it('lifts by half the scaled height so the centre lands on the primitive centre', () => {
    const f = meshFit([0.092, 0.092, 0.1], schematicBox('canister', 0.135));
    expect(f.lift).toBeCloseTo(-(0.092 * f.scale) / 2);
  });

  it('survives a degenerate extent', () => {
    const f = meshFit([0, 0, 0], [1, 1, 1]);
    expect(f.scale).toBe(1);
    expect(f.lift).toBeCloseTo(0);
  });
});

describe('riseT — one clock, one slot', () => {
  it('the rise duration is the MOTION scale', () => {
    expect(ASSEMBLY_RISE).toBe(MOTION.rise / 1000);
  });
  it('is 0 before the slot starts', () => {
    expect(riseT(0, 0.14)).toBe(0);
  });
  it('is 0.5 halfway through the rise', () => {
    expect(riseT(0.14 + ASSEMBLY_RISE / 2, 0.14)).toBeCloseTo(0.5);
  });
  it('is 1 once the rise completes', () => {
    expect(riseT(0.14 + ASSEMBLY_RISE, 0.14)).toBe(1);
  });
  it('clamps to [0,1] outside the window', () => {
    expect(riseT(10, 0)).toBe(1);
    expect(riseT(-1, 0)).toBe(0);
  });
});
