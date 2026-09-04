import { describe, expect, it } from 'vitest';

// The interface speaks fr AND en, so a user-visible string written straight into a component is a
// wording ONE of the two locales can never reach. That is not a hypothetical: the notice stack shipped
// a French `NOTE`/`FAIT` chrome and a `Fermer : …` dismiss button into the English UI, and the operator
// reported it as "messy" before anyone read the code (FUTURE.md § 16).
//
// So this test guards the CLASS, not those instances: no `aria-label`/`placeholder` may be a quoted
// literal — every one has to come from an expression, which in practice means `t.*`.
//
// Scope: the LIVE app only. `App.tsx:12-14` declares the playgrounds a "fixture-driven dev playground
// … stage-by-stage reference harness", and a fixture harness is allowed its own hardcoded copy.
//
// `title` is deliberately NOT scanned: `title=` is also an ordinary React prop (`<Section title="…">`),
// so a regex cannot tell a tooltip from a component's own API without guessing.

// Read through Vite rather than node:fs — the build's tsc compiles this file too, and there is no
// @types/node in this project.
const SOURCES = import.meta.glob('../**/*.tsx', { query: '?raw', import: 'default', eager: true }) as Record<string, string>;

/** Dev-only playground surfaces — public, fixture-driven, never part of the operator's app. */
const PLAYGROUNDS = [
  '../App.tsx',
  '../AuthPlayground.tsx',
  '../floor/FloorPlayground.tsx',
  '../noyau/NoyauPlayground.tsx',
  '../scene/AtelierScene.tsx',
  '../scene/ParcPlayground.tsx',
];

describe('every user-visible string goes through the catalog', () => {
  it('has no hardcoded aria-label or placeholder in the live app', () => {
    const offenders = Object.entries(SOURCES)
      .filter(([rel]) => !PLAYGROUNDS.includes(rel))
      .flatMap(([rel, src]) =>
        [...src.matchAll(/(aria-label|placeholder)="([^"]*)"/g)]
          .filter((m) => /\p{L}/u.test(m[2]))
          .map((m) => `${rel}: ${m[0]}`),
      );

    expect(Object.keys(SOURCES).length).toBeGreaterThan(20); // the glob actually resolved

    expect(offenders).toEqual([]);
  });
});
