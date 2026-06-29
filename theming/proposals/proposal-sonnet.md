# Design Vision — mon-parc-auto.fr

## 1. North-Star Concept & Metaphor

**The car is on a lift. The mechanic knows everything before you open the hood.**

That one sentence drives every decision. The dashboard is a high-end independent garage with its lights on at night: dark concrete floor, a single powerful overhead fixture throwing a perfect cone of warm tungsten onto the car, everything beyond that lit only by the amber and green of status instruments. The AI agent is not a chat window pinned to a corner — it is the mechanic himself, invisible until you need him, his presence felt through the tools moving, the clipboard updating, the diagnosis appearing on the workbench screen the moment the car rolls in. The user is the owner, not the technician. He names the car. He reads the readout. He trusts the verdict.

---

## 2. The Brain — "Le Cerveau"

The agent's presence is a floating sphere, 72px diameter, anchored to the top-right corner of the screen with 20px margin from both edges. It is not a spinner. It is not a status light. It is a material object: a geodesic sphere made of brushed dark steel facets, like the Nimbi orb but heavier and more industrial — imagine a ball bearing cracked open to reveal a crystalline interior that glows from within. The geometry is low-poly but rendered with real specular highlights; each triangular face catches the scene light differently.

**Idle state.** The sphere sits still. A very slow pulse — 4-second cycle — makes the interior glow breathe from near-black to a barely-perceptible blue-white, like a pilot light. One or two facets catch a faint reflection. No rotation. The mechanic is at his desk, reading.

**Working state.** The moment the agent begins a tool call, three things happen simultaneously: the sphere begins a slow axial rotation (one revolution every 2.5 seconds), the interior glow shifts from blue-white toward warm amber-gold, and a thin ring of light materialises around the sphere's equator — like a halo of condensation on cold metal, rendered as a faint iris gradient that sweeps clockwise. Each new tool call triggers a single sharp pulse — the interior flares bright white for 80ms then settles back to amber — as if a relay fired. The mechanic hit a key on the keyboard.

**Narration.** Directly beneath the sphere, a single line of text renders in 11px monospace, left-aligned under the sphere's center: `Lecture de la plaque…` then `Interrogation TecDoc…` then `Viscosité trouvée : 5W-30`. These lines do not stack. They replace each other with a 150ms vertical slide — old line slides up and out, new line slides up and in — so the transition reads as a ticker, not a flash. The text is a muted off-white (#B8B8B0), never bold, always lowercase except proper nouns. No icon prefix, no spinner glyph. The text is the signal.

**Done state.** When the agent finishes, the halo ring dissolves, the interior glow cools from amber back to blue-white, and the rotation decelerates over 600ms — physically, like a gyroscope winding down. A final line appears: `Terminé.` It fades to 0% opacity over 3 seconds and disappears. The sphere is still again.

**Error state.** The interior glow shifts to a deep red-orange. One facet at the top-front lights up as a brighter red patch — a fault indicator on a control panel. The narration line reads: `Plaque non reconnue.` The mechanic shakes his head.

The sphere is tappable. Tapping it during a working state opens a minimal overlay drawer from the right edge — a narrow 280px panel showing the last 8 tool-call narration lines in reverse-chronological order, the most recent at top. Each line has a small timestamp (elapsed seconds since turn start: `+2s`, `+7s`) in the left margin in 9px monospace. This is the debug journal made accessible without a separate button.

---

## 3. The 3D Car Turntable — "La Scène"

The central two-thirds of the vehicle detail view is a full-bleed 3D stage. The background is not a color — it is a rendered floor: dark gray polished concrete with subtle directional grain and a shallow specular streak from a light source above-left. The car itself is lit by three virtual lights: a dominant overhead key light (warm tungsten, 3200K), a cool blue fill from the right (simulating ambient sky through a workshop skylight), and a thin rim light from behind-left to separate the silhouette from the dark background. The car is not a cartoon. It is a real render of the SketchUp model, exported to glTF and loaded via Three.js (or Babylon.js), with PBR materials: metallic paint with clearcoat, black rubber tyres with actual tread geometry, glass windscreen with proper refraction.

**The turntable plate.** The car sits on a circular disc, 20cm thick in model-space, with a machined aluminum finish — concentric rings of brushed metal like a lathe-turned piece, a 1mm chamfer at the edge. The disc has two concentric indicator rings inset just inside the rim:

- The outer ring is a continuous progress arc — like a watchface complication — that fills clockwise from 0° to 360° and represents the vehicle's overall health percentage. It is colored by the worst system status: solid green (#22C55E) when all clear, amber (#F59E0B) when any service is upcoming, red (#EF4444) when anything is overdue. The fill itself has a metallic sheen, not a flat bar.
- The inner ring contains the vehicle's immatriculation plate: the actual French registration number set in a bold sans (Barlow Condensed, weight 700) in white on a very dark recessed background, the EU blue band on the left as a real physical band, not a flat rectangle. It reads like a real plate embedded in the disc.

**Rotation behavior.** On page load, the car performs a single slow hero reveal: it starts at 15° from front-three-quarter, rotates 90° counterclockwise over 3 seconds (ease-in-out), and stops at the canonical front-three-quarter view. After that it does not auto-rotate. The user can drag to orbit freely, pinch to zoom. Double-tap resets to the canonical view.

**Hotspot annotations.** Up to 6 maintenance systems get 3D-anchored hotspot markers: brake calipers, front-left tyre, engine bay area (hood center), battery (rear deck or front frunk depending on model), air filter (under hood, marked at a specific mesh face), and timing belt (behind the grille, marked by a small arrow). Each hotspot is a small hexagonal badge — 24px, flat face — floating 8px off the car surface in model space:

- **Vert (#22C55E):** service is not due for 30+ days. Badge is dark charcoal with a green edge glow.
- **Ambre (#F59E0B):** service is coming within 30 days or within 10% of the interval. Badge is amber with a subtle pulse — opacity cycles between 85% and 100% on a 2-second beat.
- **Rouge (#EF4444):** overdue. Badge is deep red, and the corresponding mesh faces of the car glow with a faint red subsurface scattering — brake discs blush red, the engine bay gets a warm red tint in the ambient occlusion — as if that part is running hot.

Hovering a hotspot expands it into a 160px-wide frosted-glass tooltip panel (backdrop-filter blur 16px, background rgba(12,12,14,0.82), 1px border rgba(255,255,255,0.08)): system name in 12px uppercase tracked, next due date in 16px medium, remaining km/days, and a one-line action chip ("Planifier la vidange →"). No hover-to-orbit conflict: hotspot expand triggers only on dwell, not on drag-intent.

---

## 4. Material & Light Language

The flat HMI is gone. Every surface in the UI has material provenance.

**Glass.** Panels that float over the 3D stage — the identity strip at top-left, the forecast sidebar at right, the parts shopping card — are architectural glass: `backdrop-filter: blur(20px)`, background `rgba(10, 11, 14, 0.78)`, inner border `1px solid rgba(255,255,255,0.06)`, and a 0.5px top-edge highlight `rgba(255,255,255,0.18)` that simulates the overhead key light hitting the glass rim. No drop-shadows. Depth is created by the blur through the panel onto the car model behind it, not by a box-shadow.

**Metal.** Interactive elements — buttons, the composer border, the health bar tracks — use a brushed metal texture rendered in CSS: a subtle `repeating-linear-gradient` of near-identical dark grays (0.3px bands) over a base of `#1A1C20`, finished with a 1px inset shadow at the top to simulate the slight concavity of a machined channel. Active/pressed states add a soft inner glow in the signal color.

**Glow.** Signal colors never appear as flat fills. They appear as glows: a green value is `text-shadow: 0 0 8px rgba(34,197,94,0.6)` on the number, and the health bar fill is a gradient from `#16A34A` at the left to `#4ADE80` at the right with a 2px bright highlight line at the top edge. Amber and red follow the same pattern. The result is every status value looks like it is lit from inside.

**Depth order.** The Z-stack reads: 3D stage at the back (WebGL canvas), glass panels at mid-ground, the composer bar at the front as a solid dark steel tray. The Brain sphere is the highest Z, always above every panel, casting a tiny soft glow onto whatever glass panel sits below it.

---

## 5. Color & Mood

Base palette: `#09090B` (near-black, the garage floor), `#111317` (card surfaces), `#1C1F26` (slightly lifted panels), `#E8E6E0` (warm almost-white for primary text — not pure white, like a worn service manual page). The color temperature of neutrals leans warm-tungsten, not cool-blue, because a garage is a warm place.

Signal colors are cinematic, not flat-design:

- **Vert sain:** `#16A34A` base, `#4ADE80` highlight, `#052E16` for dark fills. Think a dashboard "READY" indicator in a modern car.
- **Ambre vigilance:** `#B45309` base, `#FCD34D` highlight. The exact color of a hot catalytic converter, or a CHECK ENGINE amber that is not yet critical.
- **Rouge urgent:** `#9B1C1C` base, `#FCA5A5` for text, pulsing at 1Hz when a service is overdue.
- **Bleu agent:** `#1D4ED8` base, `#93C5FD` highlight, used only for the Brain and its narration line. This is the one cool-blue element in a warm-dark scene — it reads as "intelligence", distinct from status.

Accent usage rule: no accent color appears as a large fill. A green health bar is the widest it gets — 180px. Everything else is a glow, a border tint, or a text color.

---

## 6. Typography & Data Display

The primary typeface is **Barlow** (Google Fonts, inline subset): Barlow Condensed Bold for vehicle names, large KPIs, and the plate number; Barlow Regular for body copy and labels. The secondary typeface — used exclusively for data, codes, and the Brain narration — is **JetBrains Mono** at 11–13px, weight 400. No other monospace. No serif anywhere.

**Data hierarchy.** A KPI (e.g., `142 000 km`) is set in 32px Barlow Condensed Bold, the unit suffix (`km`) in 14px Regular, immediately following on the same baseline — tight tracking, no space between number and unit. The label above the KPI is 10px Barlow Regular, uppercase, letter-spacing 0.12em, color `#6B7280`. This three-tier stack (label / number / context) repeats everywhere data appears.

**Health bars.** Each maintenance system gets a named row: 11px Barlow Regular label left, a 120px bar (the glow gradient described above) center, a percentage or remaining-km value right in 11px JetBrains Mono. Below the bar, in 9px Regular `#6B7280`: the calculated next-due date, e.g., `Prochaine échéance : 15 janv. 2026`. Overdue bars have a short red pulse on the bar's right edge — like a slow heartbeat at 60bpm — and the label is set in the red highlight color.

**Tables.** The parts shopping list and maintenance history render as tabular data with 1px separator lines in `rgba(255,255,255,0.06)` — consistent with the flat HMI lineage. Column headers in 9px uppercase tracked monospace. Part references (e.g. `WIX WL7167`) always in JetBrains Mono so they read as codes, not prose.

---

## 7. Key Screens & Moments

### Lecture de plaque — the first contact

The user types `ma plaque c'est AB-123-CD` in the composer. The Brain activates instantly: the interior glow shifts to amber, the halo ring appears, and the narration line reads `Lecture de la plaque AB-123-CD…`. The 3D stage fades to 60% opacity with a white spotlight sweeping slowly across the empty platform — as if the garage is lit and waiting. After 1–3 seconds (real agent time), the car model fades up from 0% opacity onto the plate with a 400ms ease, while the plate disc's indicator rings fill from 0° clockwise as health data populates. The narration reads `Renault Clio IV · 1.5 dCi · 90 ch`. The vehicle identity strip slides in from the top-left. The hotspot badges appear one by one, 80ms apart, with a small scale-from-zero pop. The Brain rotation decelerates and the halo ring dissolves.

### Recherche de pièce — the catalog dive

The agent is searching TecDoc for the right oil filter. The Brain pulses once per tool call. The parts card — a frosted-glass panel that slides in from the right edge of the screen, 320px wide — shows a live-updating list. Each candidate part reference appears as a row with a thin left border in the agent-blue color: `WIX WL7167` in JetBrains Mono, `Mann W 712/75` below it. As the agent narrows down, non-matching rows dim to 20% opacity and slide right 8px — as if being physically sorted off the table. The winning reference animates: its left border thickens to 3px, turns green, and the row background lifts to `rgba(22,163,74,0.08)`. The Brain shows `Filtre trouvé : MANN W 712/75`. Done.

### Service passant en rouge — the overdue crossing

A vidange crosses from amber into overdue while the dashboard is open (Mercure SSE delivers the update). The transition is not a re-render flash. The brake hotspot badge on the 3D model pulses once with a sharp amber-to-red shift, the corresponding health bar fills with the red glow gradient, and the overdue text appears: `DÉPASSÉ de 3 200 km`. The disc's outer health ring ticks to a smaller fill value and shifts to red. A toast slides in from the bottom-right: a dark tray with a red left border (4px), the icon of a wrench in red-orange, and the text `Vidange moteur dépassée · Planifier ?` with a pill button `Planifier`. No alarm sound. The visual shift is enough — the car's engine bay in the 3D model has already acquired a faint red subsurface glow.

### Saisie d'une facture — logging a visit

The user photographs a receipt and drops it on the composer. The Brain activates. A bill card slides up from the bottom of the screen, full-width, 240px tall: the scanned document rendered as a slight-tilt physical paper with a subtle paper grain texture and a faint drop shadow from behind — it looks like a real receipt pinned to a corkboard. The agent reads it (`Lecture de la facture…`, `Vidange + filtre : 142 000 km, 78 €`) and the card populates: service type tag in a green pill, mileage, cost, date, part references extracted. The user confirms with a single "Valider" button. The card folds up and disappears — physically, like paper sliding into a file — and a new entry appears in the past-maintenance rail at the left, with the bill thumbnail as a small attached icon. The car's vidange hotspot immediately switches from red to green, the disc ring refills, the health bars update. The whole scene resets to calm.

---

## 8. Motion & Choreography

The dashboard physically rearranges itself by region. Think of the screen as a garage workbench with defined zones: the 3D stage is fixed and never moves; panels slide and morph over it.

**Agent action = panel slide.** When the agent selects a vehicle, the 3D stage fades from empty to populated. When it proposes a task, the CTA card slides up from the bottom over the composer bar with a spring easing (stiffness 200, damping 20) — it does not pop or flash. When it finishes, the card either collapses back down (if dismissed) or transforms in place into a confirmed maintenance entry (if accepted) — the panel background transitions from the agent-blue tint to the dark card color over 300ms.

**The data cascade.** When new telemetry arrives via Mercure SSE — any tab, any viewer — the health bars do not jump to their new value. They animate from old to new over 800ms with a cubic-ease, the glow trailing slightly behind the bar fill so it looks like liquid settling. KPI numbers count up or down over 600ms. The 3D hotspot badges animate their color in 400ms. The disc health ring sweeps the delta arc.

**The parc rail.** The left-side vehicle rail (when visible) is a physical card stack — each card is 72px tall, slightly overlapping the one below, casting a 2px drop shadow. Switching vehicles: the selected card rises and slides right, expanding horizontally to fill the stage container; the previous car's model fades out, the new one fades in. Duration 350ms.

**The Brain's choreography priority.** All panel animations yield to the Brain's narration: while a tool-call is active, no panel transition runs — they queue. When the Brain settles, queued animations run in sequence, 50ms apart, bottom-to-top. This prevents the "everything moves at once" chaos.

---

## 9. How It Stays Usable and Honest

Skeuomorphism is a trap when it hides data. Every design decision here is reversible to legibility.

**The 3D stage is always optional.** If WebGL is unavailable or the user explicitly toggles to "vue simplifiée", the stage becomes a static car silhouette illustration with the same hotspot grid as an SVG overlay. All data remains. The glass panels persist. The Brain persists. Nothing functional depends on the 3D render.

**Health bars are the real readout.** The 3D hotspot glows are emotionally evocative but imprecise — they communicate urgency, not values. The authoritative numbers live in the glass panel at the right: the per-system health bar list with exact values, dates, and km. This panel is always visible alongside the 3D stage, never hidden behind a tab. The car model attracts attention; the panel provides truth.

**The composer is always accessible.** The bottom bar — a 56px dark-steel tray with a white-text input and a submit icon — has a fixed z-index above everything including CTA cards. CTAs slide up from behind it, never over it. The user can always type.

**French first.** Every label, status string, narration line, and CTA is in French. Dates use `15 janv. 2026`, not ISO strings. Mileages use thin-space thousands separators (`142 000 km`). Part reference codes are displayed verbatim, unchanged, because a mechanic reads them as-is.

**No false precision.** Health scores are not displayed as `87.3%`. They round to the nearest 5 and the bar reflects that. The forecast reads `dans environ 2 mois` not `dans 58 jours` — humans do not maintain cars to the day. The one exception: overdue, where `DÉPASSÉ de 3 200 km` is precise because precision matters.

---

## 10. The Signature Wow Moment — "La Mise en Fosse"

When the user first arrives with a completely new, unidentified vehicle and types its plate for the first time, the 3D stage shows an empty turntable platform under that single overhead key light. The light cone on the empty floor is visible. The Brain activates. As the agent resolves the plate and pulls the vehicle data, the car model does not fade in — it descends. It appears out of the top of the viewport, as if being lowered on a garage lift, dropping slowly (2 seconds, ease-in at the start, spring-stop at the end) until it settles on the turntable plate with a barely perceptible physical bounce — one small oscillation, dampened in 300ms. The disc rings light up simultaneously with the car touching down. The hotspot badges appear in a radial burst from the car center, one by one, 60ms apart, each with a small scale-and-fade-in.

It happens once, the first time a car is registered. Every subsequent load, the car is simply there, already on the lift. But the user remembers the descent. That is the brand moment — the car arriving in your garage, being lowered into position, waiting to be looked after.
