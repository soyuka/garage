import { describe, it, expect } from 'vitest';
import { fillParamsFor } from './blueprintBuild';
import { FILL_OPACITY, PORCELAIN_BODY } from './CarProxy';

// fillParamsFor is the pure selection buildBlueprint uses to pick the real-mesh (.glb) fill under each
// stageVariant — closing the gap left at 7d0a60f where the .glb path always rendered the night-glass fill
// regardless of the flag. Reuses CarProxy's single-source constants so rung-a (the real mesh) matches the
// rung-b proxy under 'mat'. Only the SELECTION is tested here — the resulting THREE material/shader is
// out of scope (no unit-testing shaders/visuals).

describe('fillParamsFor', () => {
  it('mat → opaque porcelain, ignoring the per-make paint (matches CarProxy Box under mat)', () => {
    expect(fillParamsFor('mat', '#1d3a5c')).toEqual({ color: PORCELAIN_BODY, opacity: 1, standard: true });
  });

  it('night → the paint-tinted translucent glass fill, unchanged from before the promotion', () => {
    expect(fillParamsFor('night', '#1d3a5c')).toEqual({ color: '#1d3a5c', opacity: FILL_OPACITY, standard: false });
  });

  it('mat never leaks a per-vehicle paint color into the fill', () => {
    const a = fillParamsFor('mat', '#111111');
    const b = fillParamsFor('mat', '#eeeeee');
    expect(a).toEqual(b);
  });
});
