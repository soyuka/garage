import { describe, it, expect } from 'vitest';
import * as THREE from 'three';
import { buildHoloFill } from './holoFill';
import type { GhostControl, ScanControl } from './blueprintBuild';
import { FILL_OPACITY } from './CarProxy';
import { BLUEPRINT_EDGE, OPERATOR_HEX } from './signalColors';

// The fill is a hologram, not a tinted box (CHOREOGRAPHY § "The hologram is the light", point 2): a
// ShaderMaterial with no lighting input that shares the scan and ghost uniforms with the edge patch, so
// one driver write moves both surfaces.

function controls(): { scan: ScanControl['uniforms']; ghost: GhostControl['uniforms'] } {
  return {
    scan: {
      uScanX: { value: 0 },
      uScanOn: { value: 0 },
      uScanWidth: { value: 0.5 },
      uScanAxis: { value: 0 },
      uScanColor: { value: new THREE.Color('#5fe0d9') },
    },
    ghost: { uGhost: { value: 0 } },
  };
}

describe('buildHoloFill', () => {
  it('shares the ghost and scan uniform objects with the edges (one write moves both)', () => {
    const { scan, ghost } = controls();
    const { material } = buildHoloFill('#1d3a5c', scan, ghost);
    expect(material.uniforms.uGhost).toBe(ghost.uGhost);
    expect(material.uniforms.uScanOn).toBe(scan.uScanOn);
    expect(material.uniforms.uScanX).toBe(scan.uScanX);
    expect(material.uniforms.uScanColor).toBe(scan.uScanColor);
  });

  it('is unlit and untonemapped — linear output like the podium shafts', () => {
    const { scan, ghost } = controls();
    const { material } = buildHoloFill('#1d3a5c', scan, ghost);
    expect(material.fragmentShader).toContain('uFlicker');
    expect(material.fragmentShader).toContain('uGhost');
    expect(material.fragmentShader).toContain('uScanOn');
    expect(material.fragmentShader).not.toContain('#include <tonemapping_fragment>');
    expect(material.transparent).toBe(true);
    expect(material.depthWrite).toBe(false);
  });

  it('exposes the driver uniforms at rest: time 0, no flicker', () => {
    const { scan, ghost } = controls();
    const { holo, material } = buildHoloFill('#1d3a5c', scan, ghost);
    expect(holo.uniforms.uTime.value).toBe(0);
    expect(holo.uniforms.uFlicker.value).toBe(0);
    expect(material.uniforms.uTime).toBe(holo.uniforms.uTime);
    expect(material.uniforms.uFlicker).toBe(holo.uniforms.uFlicker);
  });
});

describe('buildHoloFill — uRise, the reveal part rise driver', () => {
  it('exposes uRise as a driver uniform, at 1 — the shell never fades', () => {
    const { scan, ghost } = controls();
    const { holo, material } = buildHoloFill('#1d3a5c', scan, ghost);
    expect(material.uniforms.uRise).toBe(holo.uniforms.uRise);
    expect(holo.uniforms.uRise.value).toBe(1);
  });

  it('uRise scales the whole alpha, fresnel included', () => {
    const { scan, ghost } = controls();
    const { material } = buildHoloFill('#1d3a5c', scan, ghost);
    expect(material.fragmentShader).toMatch(/\* uRise;/);
  });

  it('keeps uFillOpacity private, at FILL_OPACITY', () => {
    const { scan, ghost } = controls();
    const { material, holo } = buildHoloFill('#1d3a5c', scan, ghost);
    expect(material.uniforms.uFillOpacity.value).toBe(FILL_OPACITY);
    expect('uFillOpacity' in holo.uniforms).toBe(false);
  });

  it('fresnel takes the blueprint hue by default and a given edge hue when asked', () => {
    const { scan, ghost } = controls();
    const { material } = buildHoloFill('#1d3a5c', scan, ghost);
    const want = new THREE.Color(BLUEPRINT_EDGE);
    const got = material.uniforms.uEdge.value as THREE.Color;
    expect(got.r).toBeCloseTo(want.r);
    expect(got.g).toBeCloseTo(want.g);
    expect(got.b).toBeCloseTo(want.b);

    const { material: highlighted } = buildHoloFill('#1d3a5c', scan, ghost, OPERATOR_HEX);
    const wantOp = new THREE.Color(OPERATOR_HEX);
    const gotOp = highlighted.uniforms.uEdge.value as THREE.Color;
    expect(gotOp.r).toBeCloseTo(wantOp.r);
    expect(gotOp.g).toBeCloseTo(wantOp.g);
    expect(gotOp.b).toBeCloseTo(wantOp.b);
  });
});
