import { describe, it, expect } from 'vitest';
import scene from './scene.css?raw';
import historylog from './historylog.css?raw';
import factsdrawer from './factsdrawer.css?raw';
import atelier from '../tokens/atelier.css?raw';

// One token sizes
// both legends AND the right padding of the console dock and the nameplate header, keyed on the
// legend card's own DOM presence via :has(), no JS state.

describe('atelier.css: --at-legend-w sizes the right-docked legends', () => {
  it('declares --at-legend-w: min(460px, 40vw)', () => {
    expect(atelier).toMatch(/--at-legend-w:\s*min\(460px, 40vw\);/);
  });
});

describe('the two legend cards reference the token, not a literal', () => {
  it('historylog.css: .at-hlog width is var(--at-legend-w)', () => {
    expect(historylog).not.toMatch(/\.at-hlog\s*\{[^}]*width:\s*min\(460px/);
    expect(historylog).toMatch(/width:\s*var\(--at-legend-w\);/);
  });
  it('factsdrawer.css: .at-facts-drawer width is var(--at-legend-w)', () => {
    expect(factsdrawer).not.toMatch(/\.at-facts-drawer\s*\{[^}]*width:\s*min\(460px/);
    expect(factsdrawer).toMatch(/width:\s*var\(--at-legend-w\);/);
  });
});

describe('scene.css: the dock and the nameplate header re-centre on the narrowed stage', () => {
  it('removes the void .at-scene-dock--top modifier — the SceneView no longer emits that class', () => {
    expect(scene).not.toContain('at-scene-dock--top');
  });
  it('keys the padding on :has(.at-hlog-overlay, .at-facts-overlay), naming both the dock and the nameplate header (.at-scene-cockpit)', () => {
    expect(scene).toMatch(/\.at-scene:has\(\.at-hlog-overlay, \.at-facts-overlay\) \.at-scene-dock \{[^}]*padding-right:\s*var\(--at-legend-w\);/);
    expect(scene).toMatch(/\.at-scene:has\(\.at-hlog-overlay, \.at-facts-overlay\) \.at-scene-cockpit \{[^}]*padding-right:\s*var\(--at-legend-w\);/);
  });
  it('transitions padding-right over the swap duration, never a literal', () => {
    expect(scene).toMatch(/\.at-scene:has\(\.at-hlog-overlay, \.at-facts-overlay\) \.at-scene-dock \{[^}]*transition:[^;]*padding-right var\(--at-dur-swap\) var\(--at-ease-sine\)/);
    expect(scene).toMatch(/\.at-scene:has\(\.at-hlog-overlay, \.at-facts-overlay\) \.at-scene-cockpit \{[^}]*transition:\s*padding-right var\(--at-dur-swap\) var\(--at-ease-sine\);/);
  });
  it('cuts the transition under reduced motion and under the dock\'s data-reduced flag', () => {
    expect(scene).toMatch(/\.at-scene:has\(\.at-hlog-overlay, \.at-facts-overlay\) \.at-scene-dock\[data-reduced='true'\] \{\s*transition: none;/);
    const reducedBlock = scene.match(/@media \(prefers-reduced-motion: reduce\) \{[\s\S]*?\.at-scene:has\(\.at-hlog-overlay, \.at-facts-overlay\) \.at-scene-dock,\s*\.at-scene:has\(\.at-hlog-overlay, \.at-facts-overlay\) \.at-scene-cockpit \{\s*transition: none;\s*\}\s*\}/);
    expect(reducedBlock).not.toBeNull();
  });

  it('lets the console and the composer shrink into the padded dock (no overflow past the stage edge)', () => {
    expect(scene.match(/width: min\(680px, 86vw, 100%\)/g)?.length).toBe(3);
    expect(scene.match(/min\(620px, 88vw, 100%\)/g)?.length).toBe(3);
    expect(scene).toMatch(/\.at-scene-chip-rail-track \{[^}]*margin-inline: auto;/);
  });
});
