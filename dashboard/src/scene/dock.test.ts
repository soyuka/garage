import { describe, it, expect } from 'vitest';
import scene from './scene.css?raw';
import rail from './rail.css?raw';
import noyau from '../noyau/noyau.css?raw';

// CHOREOGRAPHY § The pilot driver — corrected 2026-09-05: the freed-orb GEOMETRY (socket 138×158,
// scale(1), console as a column, rail clearance grown) is unconditional under both drivers. Only what
// sits UNDER the orb still keys on data-driver: the composer's collapse-to-handle / veille-pilotée
// inflate, and the PILOTE nameplate. Vitest runs in `node` (no DOM renderer), so the geometry is pinned
// by reading the stylesheets as text, the motion.test.ts way.

/** The body of the FIRST rule whose selector list is exactly `selector` (a swallowed brace shows up as extra declarations). */
function block(css: string, selector: string): string {
  const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const m = css.match(new RegExp(`(?:^|\\n)${escaped} \\{([^}]*)\\}`));
  expect(m, `rule ${selector}`).not.toBeNull();
  return m![1];
}

describe('scene.css: the freed orb is unconditional geometry, only the handle keys on data-driver', () => {
  it('grows the socket to the orb (138 × 158) unconditionally', () => {
    const socket = block(scene, '.at-scene-socket');
    expect(socket).toContain('width: 138px');
    expect(socket).toContain('height: 158px');
  });
  it('holds the orb at scale(1), absolute-centred, unconditionally', () => {
    const stage = block(scene, '.at-scene-socket .noyau-stage');
    expect(stage).toMatch(/transform: translate\(-50%, -50%\) scale\(1\)/);
  });
  it('never scales the orb down into a small socket anywhere in scene.css or noyau.css', () => {
    expect(scene).not.toContain('scale(0.36)');
    expect(noyau).not.toContain('scale(0.36)');
  });
  it('makes the console a column unconditionally — not a rule keyed on data-driver', () => {
    const consoleBlock = block(scene, '.at-scene-console');
    expect(consoleBlock).toMatch(/flex-direction:\s*column/);
    expect(scene).not.toMatch(/\.at-scene-dock\[data-driver='pilot'\]\s*\.at-scene-console\s*\{/);
    expect(scene).not.toMatch(/\.at-scene-dock\[data-driver='pilot'\]\s*\.at-scene-socket\s*\{/);
  });
  it('reads data-driver on .at-scene-dock, never on the socket', () => {
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
  it('collapses the locked handle to a 10px hairline unless focused, keyed on the dock driver', () => {
    expect(scene).toMatch(/\.at-scene-dock\[data-driver='pilot'\]:not\(\[data-quiet='true'\]\) \.at-scene-console \.at-scene-composer:not\(:focus-within\) \{\s*height: 10px;/);
  });
  it('leaves the full composer input under the in-app driver (no data-driver rule collapses it)', () => {
    const composer = block(scene, '.at-scene-console .at-scene-composer');
    expect(composer).toContain('height: 48px');
  });
  it('cuts every dock transition under data-reduced', () => {
    expect(scene).toMatch(/\.at-scene-dock\[data-reduced='true'\] \.at-scene-composer[\s\S]*?transition: none;/);
  });
});

// `transition` is a shorthand: a mount-scale swap used to force a transform step onto noyau.css's
// filament list (else the shorthand would be redeclared and reset — cutting the done filament). The
// swap is gone now that scale is unconditional, so the transform step goes with it: nothing left to
// protect the filament list from.
describe('the orb carries no dead mount-swap transition', () => {
  it('scene.css declares no transition on .noyau-stage', () => {
    const rules = scene.replace(/\/\*[\s\S]*?\*\//g, '');
    for (const m of rules.matchAll(/([^{}]*\.noyau-stage[^{]*)\{([^}]*)\}/g)) {
      expect(m[2], `transition on ${m[1].trim()}`).not.toMatch(/transition/);
    }
  });
  it('noyau.css names the filament list once and declares no transform step on it', () => {
    const stage = block(noyau, '.noyau-stage');
    expect(stage).toMatch(/--nx-fil-transitions:\s*--nx-fil-core 600ms ease, --nx-fil-edge 600ms ease, --nx-emissive 600ms ease, --nx-seam 500ms ease;/);
    expect(stage).toMatch(/transition: var\(--nx-fil-transitions\);/);
    expect(stage).not.toMatch(/transform var\(--at-dur-swap\)/);
  });
  it('has no reduced-motion override left over from dropping a transform step that no longer exists', () => {
    const reducedBlock = noyau.match(/@media \(prefers-reduced-motion: reduce\) \{[\s\S]*?\n\}/);
    expect(reducedBlock).not.toBeNull();
    expect(reducedBlock![0]).not.toMatch(/\.noyau-stage \{\s*transition:/);
    expect(noyau).not.toMatch(/\.noyau-stage\[data-reduced='true'\] \{\s*transition:/);
  });
});

// The left rail's bottom clearance is a literal sized to the freed-orb console (rail.css `max-height`).
// Corrected 2026-09-05: the orb is freed under both drivers, so the clearance is unconditional too — no
// more :has() keyed on the dock's data-driver.
describe('rail.css clears the (always) taller dock, unconditionally', () => {
  it('keeps the veil (scrim law) and the bottom mask in the .at-rail block itself', () => {
    const base = block(rail, '.at-rail');
    expect(base).toMatch(/background: linear-gradient\(/);
    expect(base).toMatch(/mask-image: linear-gradient\(/);
    expect(base).toMatch(/color: var\(--at-text\);/);
  });
  it('sets the grown clearance directly on .at-rail, no :has() keyed on the dock', () => {
    const base = block(rail, '.at-rail');
    expect(base).toMatch(/max-height: calc\(100% - 352px\);/);
    expect(rail).not.toMatch(/:has\(\.at-scene-dock\[data-driver='pilot'\]\)/);
    expect(rail).not.toContain('.at-rail[data-driver');
  });
  it('keeps the pre-existing dim fade, with no dead max-height transition left to cut under reduced motion', () => {
    const base = block(rail, '.at-rail');
    expect(base).toMatch(/transition: opacity var\(--at-dur-swap\) ease;/);
    expect(base).not.toMatch(/max-height var\(--at-dur-swap\)/);
    expect(rail).not.toMatch(/:has\(\.at-scene-dock\[data-reduced='true'\]\)/);
  });
});

// The mask-image bottom fade (above) lives on `.at-rail`, which was ALSO the scroll container
// (`overflow-y: auto`). Chrome paints a mask-image on a scrolling element wrong — every row invisible,
// only the dim section header showing (verified live, 2026-09-05). Fix: `.at-rail` keeps position/size/
// mask/veil only and stops scrolling; a new inner `.at-rail-scroll` is the sole scroll container.
describe('rail.css: the scroller is split out of the masked element (Chrome mask-on-scroller bug)', () => {
  it('.at-rail no longer scrolls — no overflow-y/auto declared on it', () => {
    const base = block(rail, '.at-rail');
    expect(base).not.toMatch(/overflow-y:\s*auto/);
    expect(base).not.toMatch(/overflow:\s*hidden auto/);
    expect(base).toMatch(/overflow:\s*hidden;/);
  });
  it('.at-rail-scroll carries the scrolling (hidden x, auto y) and is a flex child that can shrink', () => {
    const scroll = block(rail, '.at-rail-scroll');
    expect(scroll).toMatch(/overflow:\s*hidden auto;/);
    expect(scroll).toMatch(/min-height:\s*0;/);
  });
  it('moves the column padding off the masked element onto the scroller', () => {
    const base = block(rail, '.at-rail');
    const scroll = block(rail, '.at-rail-scroll');
    expect(base).not.toMatch(/padding:/);
    expect(scroll).toMatch(/padding: 20px var\(--at-space-5\) var\(--at-space-5\);/);
  });
  it('no selector left keying off `.at-rail` for the scrollbar chrome (moved to `.at-rail-scroll`)', () => {
    expect(rail).not.toContain('.at-rail::-webkit-scrollbar');
    expect(rail).not.toContain('.at-rail::-webkit-scrollbar-thumb');
    expect(rail).toContain('.at-rail-scroll::-webkit-scrollbar');
  });
});
