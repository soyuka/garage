# Product

## Register

product

## Users

Individual owners and small-fleet managers ("parc auto") in France maintaining cars, motos, utilitaires and groundscare machines. They are in a task: logging a maintenance visit, checking what's due, asking the agent to find a part. French-language, mechanic-literate — they read part references and mileages verbatim.

## Product Purpose

AI-native vehicle-maintenance tracker (GMAO-leaning, not a DMS): forecast what's due from logged visits and usage, surface overdue work live, and let an embodied agent (Le Noyau) do the catalog/paperwork legwork. Success = the owner knows the health of the whole parc in one glance and never misses a service.

## Brand Personality

A lit garage at night. Warm, calm, alive. The interface is a *place* (floor, turntable, workbench, a presence that moves), not a dashboard. Cinematic but honest — every glow encodes a real value.

## Anti-references

- Neon arcade / candy-saturated configurators (CARBO): staging yes, saturation no.
- All-cyan "command center" floods: cyan is rationed to the agent's intelligence only.
- Flat SCADA/HMI panel grids (the deprecated "Control Panel" system): no return to tonal-only flatness.
- Chat-transcript UIs: no message bubbles, ever.

## Design Principles

- **Light is meaning.** Emission always encodes status (vert/ambre/rouge, cyan = the agent thinking). Decor never borrows a signal color; no accent as a large fill.
- **Telemetry reads off the machine.** Health lives on the car and the ring it stands on; the glass panel beside it carries the exact numbers.
- **No false precision.** Health rounds to 5, forecasts say `dans environ 2 mois` — except overdue, which is exact.
- **Honesty survives degradation.** Every value must exist outside its glow; the flat "plan" list is always reachable.
- **Mono is data.** Plates, km, dates, refs render in JetBrains Mono, French-formatted (thin-space `142 000 km`).
- **Desktop-only.** The garage is a workbench view, not a pocket one: the layout targets a desktop/laptop
  viewport and makes no sub-900px accommodation — no responsive collapse, no mobile nav.

## Accessibility & Inclusion

Color-blind safe: every signal pairs with a mono label; hotspot glyphs differ by shape. `prefers-reduced-motion` collapses breathing/pulses/descents to static states. WCAG contrast maintained for mono telemetry on glass. Flat list fallback for weak GPUs.
