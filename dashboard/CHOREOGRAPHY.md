# Atelier choreography map — FROZEN in Stage B.0

The single binding of the **real** agent vocabulary (and Mercure domain frames) to Atelier motion.
Written in the verified `UIAction` / frame names only — never the `docs/ui.md` sketch names. Every
Stage-B/C component animates against THIS map; no agent invents a different mapping.

## Agent → UI intents (session topic)

| Real frame | Atelier motion | Owner layer |
|---|---|---|
| `selectVehicle {id}` | the car **drives forward** into Le Plateau; previous car recedes into the lot (~350ms cross-fade) | scene assembler |
| `showFact {keys, title?}` | the truth-panel **focuses** the named fact keys — a light-sweep **etch-in** across the glass text | truth-panel |
| `proposeActions {headline, finding?, actions}` | a CTA card **rises from Le Noyau's cradle** (rises from *behind* the composer, never over it); decline → sinks back. `actions[].patchTask` → deterministic PATCH, **no agent turn**; `actions[].instruction` → new turn | CTA layer |
| `toast {tone, message}` | non-alarm → narration line (Noyau heartbeat); **alarm** → ember docks to the Noyau + a real toast popup | Le Noyau |
| `status {message}` | one **Noyau pulse** (per narrated step, sparse) + the narration line etches | Le Noyau |
| `turn {phase}` | `start` → Noyau busy/working (aperture focuses, scan locks); `end` → settle → green/amber/red | Le Noyau |

## Domain frames (collection topic)

| Frame | Atelier motion | Owner layer |
|---|---|---|
| `{kind:'vehicle', event, resource}` | **the data cascade** (~800ms): health bars animate old→new with the glow trailing; KPI numbers count; hotspots cross-fade color (400ms); the rim sweeps the delta arc. Identical for local + other-tab events — the room is shared | scene assembler |
| overdue crossing (a domain `updated` that tips a service past due) | hood-glow breathes red + rim arc ignites + ember docks to the Noyau, **hands-off** — the §9 wow | scene assembler + Le Noyau |

## Priority & honesty rules (binding)

- **All panel animations YIELD to Le Noyau's narration.** While a turn is active, panel transitions
  queue, then run in sequence (~50ms apart) when the Noyau settles. No "everything moves at once."
- **Z-order:** WebGL canvas (back) → glass panels → composer tray → **Le Noyau (highest)**.
- **Nothing teleports** — selection drives, facts etch, CTAs rise, colors cross-fade.
- **Truth before spectacle:** every value a motion conveys (glow, rim arc, hotspot card) MUST also be
  in the truth-panel / flat plan list at full precision. Hide the WebGL canvas → no value lost.
- **`prefers-reduced-motion`:** drives/etches/pulses/cascade collapse to instant state changes; the
  overdue wow becomes a static red state + the narration line.
