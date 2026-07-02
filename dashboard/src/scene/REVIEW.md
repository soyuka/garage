# Stage B.2 (step 2 leaves + step 3 crossing) — design-fidelity critique (the standing gate)

Six refute-by-default reviewers vs the vision (anti-reuse+contract · honesty/legibility/WCAG-on-glass ·
French/§5 · reduced-motion+a11y · composition/occlusion/z-order · completeness). Run via the
`atelier-b2-design-critique` workflow. Two reviewers independently converged on the crossing-as-fake-turn
blocker. Every blocker + confirmed major fixed before closing B.2.

## Fixed

- **[a11y BLOCKER ×2] The §9 crossing impersonated an agent turn.** It dispatched a `status` frame,
  which forces the Noyau into `working` (orb lift, scan-lock, pulse). CHOREOGRAPHY: the crossing is a
  Mercure *domain* push (no turn) — ember docks + one concern line, hands-off. Added a dedicated
  `concern` NoyauEvent (state.ts) that docks the ember + sets the narration line but leaves `phase`
  untouched (idle stays idle; an in-flight turn stays working). TDD'd (state.test.ts). Removing the
  fake working phase also fixed the reduced-motion orb-lift downstream.
- **[a11y BLOCKER] No OS `prefers-reduced-motion` bridge for the WebGL loops.** `reduced` came only
  from the dev checkbox, so an OS-reduced user got the 1Hz hood pulse + breathing rim + auto-rotate
  (a CSS @media can't reach a useFrame loop). Added `useReducedMotion()` (matchMedia + listener) OR'd
  into the effective `reduced` passed to every leaf + the Noyau + OrbitControls autoRotate.
- **[a11y MAJOR] Hotspot "Planifier" CTA was keyboard-unreachable** — the pin's own `onBlur` closed the
  card before Tab could land on the in-card button. Moved open/close to the wrapper with a
  `relatedTarget`-contains check (focus-within semantics); dropped the misleading `role="dialog"` for a
  non-modal `role="group"`.
- **[composition MAJOR] Hotspot cards were z-trapped under the glass panels.** drei `<Html>` portals
  into the canvas's stage stacking context (z0), so a card over a flank was painted under the
  truth-panel/établi (z10) and its CTA could be unclickable. Now portaled (`portal` prop) into a
  scene-level `.at-scene-hotspot-layer` (z30 — above glass, below the dock).
- **[honesty MAJOR] Cardinal readouts could fail WCAG over the lit, rotating body** (they float on the
  bare scene, no glass). Added a dark text-shadow scrim to every cardinal label/value (kept under
  reduced-motion; only the red *glow* drops there).
- **[honesty §8 MAJOR] établi part data vanished on rung d.** Price/qty/brand/fluid-spec lived only in
  PartsShelf, absent from the canvas-off floor. PartsShelf now also renders in the rung-d floor, so
  hiding the canvas loses nothing.
- **[honesty MAJOR] RimGauge was colour-only under reduced-motion** (arcs differed by hue + breathe;
  reduced kills the breathe). Added a per-signal **radial thickness step** (red widest, amber mid,
  green thin) — a grayscale-legible, motion-independent cue.
- **[contract MAJOR] `crossOverdue` emitted an impossible payload** — `nextDue.overdue:true` with a
  positive `usageRemaining`. Now mirrors the tipped negative remaining onto `nextDue`, so the
  collection summary can't disagree with the item forecast row. TDD'd.
- **[completeness MAJOR / §9] The crossing popped instead of igniting.** Added a ~600 ms opacity
  fade-in to BodyGlow halos + RimGauge arcs (instant when reduced) so the hood "breathes red" in
  rather than snapping.
- **[colour MINOR] cyan leaked onto dev chrome** (`.at-sbtn--on`, `.at-scene-sim` ▶). §4 rations cyan
  to Le Noyau only — recoloured to neutral chrome (scene/*.css now cyan-free).
- **[a11y MINOR] ▶ mock-turn button** gained an `aria-label`; **[§5 NIT]** `formatHorizon` plural
  invariant documented.

## Deferred — tracked, NOT silent (Stage C / polish)

- **Rung-c on-body overlay.** Hotspots/glow/rim ride rungs a/b (the R3F Canvas) only. On the silhouette
  rung the telemetry lives in the truth-panel; a 2D anchors-projected signal grid on the SVG is a
  follow-up. The false "carries the hotspot grid" claim in SilhouetteCar.tsx was struck. (The
  `SilhouetteCar.tsx` comment still says "truth-panel + cardinals" — stale reference, cardinals are gone;
  the panel alone carries it now.)
- **Parc amber-by-usage under-report** — the collection `NextDue` lacks the interval, so the §2
  usage-10% amber branch is uncomputable client-side (overdue/red + unknown stay faithful). Raised as
  a Stage-C backend task (server-provided collection signal) in PROVENANCE.md — no fabricated threshold.
- **Stage-C / Stage-D:** mpa.recency rail on the parc (still addedAt-desc — see `ParcLot.tsx`) +
  Noyau-over-lot on the parc; large-fleet (50-card) parc perf (virtualisation — the IntersectionObserver
  spin-gate is not a substitute at real scale); the full §7 800 ms data cascade (delta-sweep / KPI count
  — the domain-frame table's "hotspots cross-fade color (400ms)" in CHOREOGRAPHY.md is the same
  not-yet-built claim, tracked here not there).

## Resolved by the rail/pins + camera rework (was tracked here, now dead)

- ~~Cardinals are a screen HUD, not on-disc 3D~~ — the cardinal readouts component was **removed**
  outright (not just re-scoped): `CardinalReadouts.tsx`/`cardinals.css` are deleted; the odometer/health
  facts it carried live in the cockpit band (`Cockpit.tsx` → `HealthStat`) and the Entretien rail now.
- ~~Portaled hotspot pins can overlap the truth-panel~~ — moot: the échéance pins (`EcheancePins.tsx`)
  are a plain absolutely-positioned DOM layer (`.at-echpin-layer`, z6), not an R3F `<Html>` portal into
  the canvas stacking context, and the hero no longer renders the truth-panel alongside them (rung d
  only). The old `.at-scene-hotspot-layer` z30 portal is gone.
- **taskdock / `EcheancePanels.tsx` / `Checklist.tsx` / `EcheanceList.tsx`** — the right-docked échéance
  panels and the établi checklist were replaced wholesale by the split ring: `EcheancePins.tsx` (spatial
  index on the disc) + `EntretienRail.tsx` (the persistent left prose rail, renamed from `Checklist.tsx`).
  Any doc or comment still naming the old files is stale.

## Verified live (chrome-devtools, all 6 scenarios + rungs c/d + #/parc + crossing + reset)

Snapshot as of B.2; the établi shelf and the cardinal readouts named below were both later removed
(see "Resolved" above) — read this as a historical record, not the current surface.

healthy → green rim, 2 pins, établi, cardinals, panel + "Dernière intervention"; ambre → gold rim, dual
amber glow+pins, gap; overdue → red glow + thicker red rim arc + red ‼ pin + panel "DÉPASSÉ de 3 200 km";
moto → moto anchors + "TRANSMISSION" pin; unknown → neutral grey rim, no glow, `—` dashes; empty → parc
vide; rung c silhouette + DOM overlays; rung d floor + établi; #/parc fleet grid w/ base-rings; crossing
→ red ignite + thicker rim arc + Noyau ember (idle, hands-off) + "DÉPASSÉ de 360 km" → reset restores
green + clears ember. Console clean throughout (the `Context Lost` log on leaving a 3D route is expected
teardown). 117 tests green, tsc clean, anti-reuse 0, no cyan in any scene leaf.
