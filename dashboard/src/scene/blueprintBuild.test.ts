import { describe, it, expect } from 'vitest';
import * as THREE from 'three';
import { buildBlueprint, EDGE_BOOST, type Blueprint } from './blueprintBuild';
import { EDGE } from './CarProxy';
import { BLUEPRINT_EDGE, OPERATOR_HEX } from './signalColors';

// blueprintBuild's pure core is testable headless: EdgesGeometry/Box3 are plain JS, and holoFill.test.ts
// already builds a ShaderMaterial under vitest. Colour comparisons ALWAYS via THREE.Color components with
// toBeCloseTo — never hex strings, never a raw hex against `.r` (three converts sRGB→linear on parse).

function boxScene(): THREE.Group {
  const g = new THREE.Group();
  g.add(new THREE.Mesh(new THREE.BoxGeometry(1, 2, 3)));
  g.position.set(3, 4, 5);
  return g;
}

function fillOf(b: Blueprint): THREE.ShaderMaterial {
  let m!: THREE.ShaderMaterial;
  b.clone.traverse((o) => {
    if ((o as THREE.Mesh).isMesh) m = (o as THREE.Mesh).material as THREE.ShaderMaterial;
  });
  return m;
}

function edgeMatOf(b: Blueprint): THREE.LineBasicMaterial {
  return (b.edges.children[0] as THREE.LineSegments).material as THREE.LineBasicMaterial;
}

describe('buildBlueprint', () => {
  it('clones, floors and centres — the source is never mutated', () => {
    const src = boxScene();
    const b = buildBlueprint(src, { edgeAngle: 30, paint: '#1d3a5c' });
    b.clone.updateMatrixWorld(true);
    const box = new THREE.Box3().setFromObject(b.clone);
    expect(box.min.y).toBeCloseTo(0);
    expect((box.min.x + box.max.x) / 2).toBeCloseTo(0);
    expect((box.min.z + box.max.z) / 2).toBeCloseTo(0);
    expect(src.position.x).toBe(3);
  });

  it('edges default to the blueprint hue, boosted 1.7 for the bloom', () => {
    const b = buildBlueprint(boxScene(), { edgeAngle: 30, paint: '#1d3a5c' });
    const want = new THREE.Color(EDGE).multiplyScalar(1.7);
    const mat = edgeMatOf(b);
    const got = mat.color;
    expect(got.r).toBeCloseTo(want.r);
    expect(got.g).toBeCloseTo(want.g);
    expect(got.b).toBeCloseTo(want.b);
    expect(mat.toneMapped).toBe(false);
  });

  it("the shell's fill fresnel is blueprint blue by default (the third-hue rule)", () => {
    const b = buildBlueprint(boxScene(), { edgeAngle: 30, paint: '#1d3a5c' });
    const want = new THREE.Color(BLUEPRINT_EDGE);
    const got = fillOf(b).uniforms.uEdge.value as THREE.Color;
    expect(got.r).toBeCloseTo(want.r);
    expect(got.g).toBeCloseTo(want.g);
    expect(got.b).toBeCloseTo(want.b);
  });

  it('an edge hue reaches both the edge lines and the fill fresnel', () => {
    const b = buildBlueprint(boxScene(), { edgeAngle: 30, paint: OPERATOR_HEX, edge: OPERATOR_HEX });
    const wantEdge = new THREE.Color(OPERATOR_HEX).multiplyScalar(1.7);
    const gotEdge = edgeMatOf(b).color;
    expect(gotEdge.r).toBeCloseTo(wantEdge.r);
    expect(gotEdge.g).toBeCloseTo(wantEdge.g);
    expect(gotEdge.b).toBeCloseTo(wantEdge.b);

    const wantFill = new THREE.Color(OPERATOR_HEX);
    const fill = fillOf(b).uniforms;
    const gotUEdge = fill.uEdge.value as THREE.Color;
    expect(gotUEdge.r).toBeCloseTo(wantFill.r);
    expect(gotUEdge.g).toBeCloseTo(wantFill.g);
    expect(gotUEdge.b).toBeCloseTo(wantFill.b);
    const gotUPaint = fill.uPaint.value as THREE.Color;
    expect(gotUPaint.r).toBeCloseTo(wantFill.r);
    expect(gotUPaint.g).toBeCloseTo(wantFill.g);
    expect(gotUPaint.b).toBeCloseTo(wantFill.b);
  });

  it('edgeBoost lowers the line boost without touching hue', () => {
    const b = buildBlueprint(boxScene(), { edgeAngle: 30, paint: '#1d3a5c', edgeBoost: 1.3 });
    const want = new THREE.Color(EDGE).multiplyScalar(1.3);
    const got = edgeMatOf(b).color;
    expect(got.r).toBeCloseTo(want.r);
    expect(got.g).toBeCloseTo(want.g);
    expect(got.b).toBeCloseTo(want.b);
  });

  it('cacheKey isolates edge geometry per key', () => {
    const b1 = buildBlueprint(boxScene(), { edgeAngle: 30, paint: '#1d3a5c', cacheKey: 't/a' });
    const b2 = buildBlueprint(boxScene(), { edgeAngle: 30, paint: '#1d3a5c', cacheKey: 't/a' });
    expect((b2.edges.children[0] as THREE.LineSegments).geometry).toBe((b1.edges.children[0] as THREE.LineSegments).geometry);
    const b3 = buildBlueprint(boxScene(), { edgeAngle: 30, paint: '#1d3a5c', cacheKey: 't/b' });
    expect((b3.edges.children[0] as THREE.LineSegments).geometry).not.toBe((b1.edges.children[0] as THREE.LineSegments).geometry);
  });

  it('exports EDGE_BOOST as the shell default', () => {
    expect(EDGE_BOOST).toBe(1.7);
  });

  it('rotationX stands the source up BEFORE the re-centre (spark-plug upright)', () => {
    const b = buildBlueprint(boxScene(), { edgeAngle: 30, paint: '#1d3a5c', rotationX: Math.PI / 2 });
    b.clone.updateMatrixWorld(true);
    const box = new THREE.Box3().setFromObject(b.clone);
    // A 1×2×3 box rotated 90° about x swaps its y and z extents.
    expect(box.max.x - box.min.x).toBeCloseTo(1);
    expect(box.max.y - box.min.y).toBeCloseTo(3);
    expect(box.max.z - box.min.z).toBeCloseTo(2);
    // Still floored + centred after the rotation.
    expect(box.min.y).toBeCloseTo(0);
    expect((box.min.x + box.max.x) / 2).toBeCloseTo(0);
    expect((box.min.z + box.max.z) / 2).toBeCloseTo(0);
  });
});
