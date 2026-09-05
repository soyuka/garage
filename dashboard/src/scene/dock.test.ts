import { describe, it, expect } from 'vitest';
import scene from './scene.css?raw';
import rail from './rail.css?raw';
import noyau from '../noyau/noyau.css?raw';

// CHOREOGRAPHY § The pilot driver, rows dated 2026-09-04: the orb leaves the socket under the pilot,
// the console thins to a hairline under load and inflates in veille pilotée. Vitest runs in `node`
// (no DOM renderer), so the geometry is pinned by reading the stylesheets as text, the motion.test.ts way.

/** The body of the FIRST rule whose selector list is exactly `selector` (a swallowed brace shows up as extra declarations). */
function block(css: string, selector: string): string {
  const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const m = css.match(new RegExp(`(?:^|\\n)${escaped} \\{([^}]*)\\}`));
  expect(m, `rule ${selector}`).not.toBeNull();
  return m![1];
}

describe('scene.css keys the freed orb and the handle on the dock', () => {
  it('reads data-driver on .at-scene-dock, not on the socket', () => {
    expect(scene).toContain(".at-scene-dock[data-driver='pilot']");
    expect(scene).not.toContain('.at-scene-socket[data-driver');
  });
  it('inflates in veille pilotée via data-quiet beside data-driver', () => {
    expect(scene).toContain("[data-driver='pilot'][data-quiet='true']");
  });
  it('scopes the focus inflate to the composer, never the console (ember/inbox live in the socket)', () => {
    expect(scene).toMatch(/\.at-scene-composer:not\(:focus-within\)/);
    expect(scene).not.toMatch(/\.at-scene-console:(not\()?:?focus-within/);
    expect(scene).not.toMatch(/\.at-scene-composer:hover/);
  });
  it('gives the pilot composer its own box (scene.css `.at-scene-console .at-scene-composer { flex: 1 }` would size it)', () => {
    const rule = scene.match(/\.at-scene-dock\[data-driver='pilot'\] \.at-scene-console \.at-scene-composer \{[^}]*\}/);
    expect(rule, 'pilot composer rule').not.toBeNull();
    expect(rule![0]).toContain('flex: 0 0 auto');
    expect(rule![0]).toContain('height: 48px');
  });
  it('collapses the locked handle to a 10px hairline unless focused', () => {
    expect(scene).toMatch(/\.at-scene-dock\[data-driver='pilot'\]:not\(\[data-quiet='true'\]\) \.at-scene-console \.at-scene-composer:not\(:focus-within\) \{\s*height: 10px;/);
  });
  it('grows the socket box to the orb (138 × 158) and lets the orb reach scale(1), absolute-centred', () => {
    const socket = scene.match(/\.at-scene-dock\[data-driver='pilot'\] \.at-scene-socket \{[^}]*\}/);
    expect(socket![0]).toContain('width: 138px');
    expect(socket![0]).toContain('height: 158px');
    expect(scene).toMatch(/\.at-scene-dock\[data-driver='pilot'\] \.at-scene-socket \.noyau-stage \{[^}]*transform: translate\(-50%, -50%\) scale\(1\)/);
    expect(scene).not.toMatch(/\.at-scene-dock\[data-driver='pilot'\][^{]*\.noyau-stage \{[^}]*position:/);
  });
  it('cuts every dock transition under data-reduced', () => {
    expect(scene).toMatch(/\.at-scene-dock\[data-reduced='true'\] \.at-scene-composer[\s\S]*?transition: none;/);
  });
});

// `transition` is a shorthand: a `transition: transform …` on `.noyau-stage` from ANY more specific
// selector replaces noyau.css's registered-property list and turns the done filament into a cut. So the
// orb's transform step lives in noyau.css beside the filament steps, and scene.css never declares a
// transition on the stage — under reduced motion only the transform step is dropped (the filament's
// cross-fade is the orb's own reduced-motion rule, unchanged by this change).
describe('the orb keeps its filament cross-fade while it moves between mounts', () => {
  it('scene.css declares no transition on .noyau-stage', () => {
    const rules = scene.replace(/\/\*[\s\S]*?\*\//g, '');
    for (const m of rules.matchAll(/([^{}]*\.noyau-stage[^{]*)\{([^}]*)\}/g)) {
      expect(m[2], `transition on ${m[1].trim()}`).not.toMatch(/transition/);
    }
  });
  it('noyau.css names the filament list once and adds the transform step to it', () => {
    const stage = block(noyau, '.noyau-stage');
    expect(stage).toMatch(/--nx-fil-transitions:\s*--nx-fil-core 600ms ease, --nx-fil-edge 600ms ease, --nx-emissive 600ms ease, --nx-seam 500ms ease;/);
    expect(stage).toMatch(/transition: var\(--nx-fil-transitions\), transform var\(--at-dur-swap\) var\(--at-ease-sine\);/);
  });
  it('drops only the transform step under reduced motion', () => {
    expect(noyau).toMatch(/\.noyau-stage\[data-reduced='true'\] \{\s*transition: var\(--nx-fil-transitions\);\s*\}/);
    expect(noyau).toMatch(/@media \(prefers-reduced-motion: reduce\) \{[\s\S]*?\.noyau-stage \{\s*transition: var\(--nx-fil-transitions\);\s*\}/);
  });
});

// The left rail's bottom clearance is a literal sized to the row console (rail.css `max-height`); the
// freed orb makes the console 152px taller in veille pilotée (socket 160 + gap 8 + composer 50, against
// the 66px row), so the clearance must grow with it — keyed on the SAME dock attribute via :has(), never
// a second copy of data-driver elsewhere.
describe('rail.css clears the taller pilot dock', () => {
  it('keeps the veil (scrim law) and the bottom mask in the .at-rail block itself', () => {
    const base = block(rail, '.at-rail');
    expect(base).toMatch(/background: linear-gradient\(/);
    expect(base).toMatch(/mask-image: linear-gradient\(/);
    expect(base).toMatch(/color: var\(--at-text\);/);
    expect(base).toMatch(/transition: max-height var\(--at-dur-swap\) var\(--at-ease-sine\), opacity var\(--at-dur-swap\) ease;/);
  });
  it('grows the rail clearance under the freed orb, keyed on the dock', () => {
    expect(block(rail, ".at-scene:has(.at-scene-dock[data-driver='pilot']) .at-rail")).toMatch(/^\s*max-height: calc\(100% - 352px\);\s*$/);
    expect(rail).not.toContain('.at-rail[data-driver');
  });
  it('cuts the clearance transition under reduced motion and keeps the pre-existing dim fade', () => {
    expect(block(rail, ".at-scene:has(.at-scene-dock[data-reduced='true']) .at-rail")).toMatch(/^\s*transition: opacity var\(--at-dur-swap\) ease;\s*$/);
  });
});
