import { describe, it, expect } from 'vitest';
import source from './Plateau.tsx?raw';

// The stage is read as TEXT, never evaluated: the r3f stack has no place in a node test, and the rules
// pinned here are about what the Canvas subtree declares, not what it renders.

describe('Plateau source', () => {
  it('has no warm key — the room is dark and the hologram is the light', () => {
    expect(source).not.toContain('directionalLight');
    expect(source).toContain('ROOM_AMBIENT');
    expect(source).toContain('AgentLightRig');
  });
  it('keeps Bloom multisampling at 0 and vignettes after it', () => {
    expect(source).toContain('multisampling={0}');
    expect(source).toContain('<Vignette');
    expect(source.indexOf('<Bloom')).toBeLessThan(source.indexOf('<Vignette'));
  });
  it('mounts the visit ring after the reveal assembly, in the same car group (§ Stations)', () => {
    expect(source).toContain('<VisitRing');
    expect(source.indexOf('<Assembly')).toBeLessThan(source.indexOf('<VisitRing'));
  });
  it('mounts the fact constellation alongside the visit ring, in the same car group (§ Stations)', () => {
    expect(source).toContain('<FactConstellation');
  });
  it('mounts the fluid volumes alongside the ring and the constellation, in the same car group (§ Stations)', () => {
    expect(source).toContain('<FluidVolumes');
  });
  it('forwards the six picking props to every station (§ "Picking — the body points back")', () => {
    expect(source).toContain('onHoverVisit');
    expect(source).toContain('onPickVisit');
    expect(source).toContain('onHoverFact');
    expect(source).toContain('onPickFact');
    expect(source).toContain('onHoverFluid');
    expect(source).toContain('onPickFluid');
  });
});
