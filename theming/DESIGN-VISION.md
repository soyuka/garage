# Atelier — The Unified Design Vision for mon-parc-auto.fr

> **North star:** *A lit garage at night where a living mechanic-intelligence walks around your car for you, and the bodywork itself tells you what hurts — then shows you healing.*

This is the one sentence to repeat in every design review. Not a dashboard. Not a chat app. A **garage**: a place with a floor, a single warm light rig, a workbench, and a presence that moves through it. The car is real and three-dimensional, lit like it matters. The agent is a being, not a progress bar. Every "screen" is a camera angle on the same room.

---

## ANALYSIS — what each proposal got right, and what I inherited

The three visions agree on the spine — a living agent orb, a hyperreal 3D car on a turntable, hotspots that read health off the body, signal-locked color, mono telemetry — so the synthesis question is not *what* but *where* and *how warm*.

- **Sonnet ("The car is on a lift")** is the strongest on **craft, restraint, and honesty discipline**: the warm-tungsten garage (a garage is a *warm* place, not a neon arcade), the rule that "no accent appears as a large fill," "no false precision" (`dans environ 2 mois`, round health to 5), the always-visible truth panel beside the model, and the cleanest staging spec. Its weakness: the Brain pinned to the top-right corner reads as a *widget*, not a *presence* — it drifts away from the composer where the conversation actually happens. **Inherited: the entire material/light/honesty discipline, the warm color temperature, the "La Mise en Fosse" car-descent reveal, and the bill-as-physical-paper logging flow.**
- **Opus ("Atelier")** is the strongest on **embodiment and emotional staging**: the Brain (*Le Noyau*) anchored *over the composer* so the agent's proposals visibly *emit from where you talk to it*; the turntable rim doubling as the global health gauge (the live "forecast-health-bars" reborn as a ring you stand the car on); parts rendered as physical objects on a shelf; "the agent's attention is the camera"; and the bravest wow moment — *the car gets sick in front of you*, live. Its weakness: it risks over-rendering ("the single most expensive render in the app") before the data tool is solid. **Inherited: the name Atelier, Le Noyau's placement and cradle, the rim-as-health-gauge, parts-as-objects, camera-follows-agent choreography, the Mercure-live overdue wow, and the explicit "what we keep / what we throw out from Control Panel" contract.**
- **Haiku ("Hyperreal Garage Command")** is the strongest on **legible product mechanics**: the clearest hotspot card spec (system + health% + due + last-logged), the cardinal-point disc readouts, the three-column bill modal, and the blunt argument *why this isn't eye candy* (the 3D car is the fastest way to read which systems are healthy). Its weakness: the all-cyan "electric" brand floods the scene with a single decorative accent, which collides with the signal palette and the warm-garage mood — cyan everywhere dilutes cyan-means-intelligence. **Inherited: the hotspot card contents, the cardinal disc readouts as a secondary layer, the three-column bill modal, and the usability arguments.**

The central tension — *warm garage (sonnet) vs cyan command-center (haiku) vs graphite void with an exertion-gradient agent (opus)* — resolves decisively: **a warm graphite garage void, where cyan is rationed to the agent alone.** Cyan is not the brand color; it is the *color of the intelligence*, and it earns its meaning by being the one cool thing in a warm room.

---

## 1. The Brain — *Le Noyau*

The agent's body is **Le Noyau** ("the core"): a faceted liquid-metal sphere the size of a clementine, floating just above a small **machined-steel cradle** at the **bottom-center of the screen, directly over the composer bar**. (We take Opus's placement over Sonnet's corner: the agent must live where you speak to it, so that when it emits a proposal, your eye is already on it.) It is the Nimbi orb fused with the NeuroNest prism — a low-poly geodesic shell of dark chrome with a molten filament suspended inside, refracting through the facets. It casts a real soft shadow on the cradle and throws a faint pool of colored light onto the composer. It has weight. It is the **soul anchor**: the same object on the parc view and the vehicle view, never leaving the screen, the one element unmistakably *alive*.

**Material.** Brushed gunmetal facets under a clearcoat; between facets, seams glow with the inner light. The interior filament is volumetric — a slow-curling plasma seen *through* the glass facets, subsurface-scattering when it intensifies. This is the most expensive render in the app *after* the car — and never before the car is right.

**Motion — four states:**
- **Idle (veille).** Breathes 100%→103% over ~4s on a sine ease, rotating ~6° lazily; the filament drifts cool **cyan-teal**; one slow specular glint crosses the facets every few seconds. Asleep-but-warm, like an idling engine.
- **Listening (à l'écoute).** When the user types, the pulse quickens slightly and a thin inner ring traces the circumference — a signal being received. The glow holds steady.
- **Working (au travail).** On a turn, the sphere lifts ~12px off the cradle, the filament ignites from cyan toward **amber-gold**, rotation speeds up, seams brighten. **Every tool-call fires one discrete pulse** — a ring of light expanding off the equator and dissipating, a heartbeat you can count. A turn that scans a plate, hits TecDoc twice, and checks viscosity *visibly pulses four times*. (This cyan→amber gradient is the **only** sanctioned gradient in the system, because it encodes the agent's exertion — Opus's insight.)
- **Done (posé).** Settles onto the cradle with a tiny bounce, exhales one bright ring; the filament cools to **green if everything is healthy, amber if it surfaced something due, red on error** with one facet flaring as a fault lamp. Then back to idle.

**Narration through the Noyau.** A single line of mono text **rises out of the cradle base, directly beneath the sphere**, etched as if onto the machine: `Lecture de la plaque…` → fades up-and-out → `Recherche des pièces · TecDoc…` → `Viscosité d'huile…` → `Mise à jour live`. One line at a time, ticker-style, never stacking. The text is muted off-white, lowercase except proper nouns, no spinner glyph — *the Noyau is the spinner*. Clicking the Noyau expands the **journal drawer** (today's debug drawer, promoted to first-class): a vertical strip of past narration lines with each tool-call pulse replayed as a small glyph and an elapsed-time stamp (`+2s`, `+7s`). Notifications ("3 véhicules ont un service en retard") dock as a numbered ember on the lower-right facet; tap it and the relevant car rotates into view. The Noyau **replaces all scattered toasts and modals** — one visual locus for status (Haiku's centralization argument).

---

## 2. The 3D Car Turntable — *Le Plateau*

The vehicle detail view is dominated by **Le Plateau**: the real 3D car model (the SketchUp asset in `theming/`, exported to glTF, rendered via Three.js with PBR materials — metallic clearcoat paint, rubber-tread tyres, refracting glass) standing on a circular **turntable** filling the upper two-thirds of the screen. The hero, full stop. We take the staging of VoltDrive and the annotation grammar of ATMOS, tuned for honesty rather than gamified flash — CARBO is the cautionary tale (we take its *staging*, never its candy saturation).

**Staging & light.** A dark, faintly textured graphite void — *not* pure black, so chrome and glow carry the image. Three virtual lights: a dominant **warm tungsten key from upper-left (~3200K)**, a cool fill from the right, a thin rim from behind to cut the silhouette off the void. A soft contact shadow grounds the car. The turntable is a **brushed-metal disc** with chevron floor-markings radiating outward (the "My Garage" floor) and an inlaid light-ring at the rim. Idle: the car rotates once per ~40s, museum-slow; the user can grab and spin it, double-tap resets, and **the agent can rotate it to face the system it is working on** — *the agent's attention is the camera* (Opus). Mileage and identity float as a **frosted-glass telemetry slab** to the side (the VORTEX panels-over-the-hero pattern), never occluding the body.

**Telemetry reads OFF the model — the core promise.** Health is not a chart *beside* the car; it is *on* the car.
- **Hotspots.** Hexagonal "+" annotation pins (ATMOS) anchored in 3D to body regions: front wheels = *Freins*, wheel arches = *Pneus*, hood = *Vidange / Huile* and *Courroie de distribution*, front bay = *Batterie*, windshield badge = *Contrôle technique*. Each hex glows its system's signal color. **Vert** = service 30+ days out (dark badge, green edge). **Ambre** = due within 30 days or 10% of interval (gentle 2s opacity pulse). **Rouge** = overdue (deep red, pulsing). Dwell/tap expands a frosted card (Haiku's spec): system name, current health %, interval, days/km until due, and last-logged date — e.g. `Freins — 72% · dû dans 1 200 km · dernière intervention 15 nov. 2024` — plus a one-line action chip (`Planifier la vidange →`).
- **Body glow.** When brakes are due, the **brake discs themselves emit amber through the wheel**; overdue, they pulse red. Tyres due → a red contact-band where rubber meets the disc. Overdue engine → a faint red subsurface blush in the bay. *You see the sick part on the actual car.* This is the moment that sells the product.
- **The plate is the master identifier.** The car wears the real French plate (`AB-123-CD`, mono, EU blue band as a physical inset).
- **The rim is the global gauge.** The turntable rim is a 360° ring divided into arc-segments, one per tracked system, each lit its signal color — the live-dev "forecast-health-bars" reborn as **a ring you stand the car on**. One overdue system burns that arc red; an all-clear car glows the whole rim green. **Glance at the floor, know the car.** Four mono readouts sit at cardinal points of the disc (Haiku): top = mileage, right = fleet/vehicle health %, bottom = days since last service, left = overdue count (red when non-zero).

**Parts as objects.** A part the agent finds is not a table row — it is a **rendered physical object on a lit pedestal on a shelf below the Plateau** (the "My Garage" parts shelf: oil bottle, filter, brake disc, battery, tyre), its TecDoc reference etched in mono beneath, a shop-search link as a tag. Confirming a part slides it from the shelf onto the task card.

---

## 3. Material & Light Language — the hard break from flat HMI

Control Panel said: *no gradients, no gloss, no shadow, low radius, depth is tonal only.* Atelier keeps its **discipline** and inverts its **surface**:

- **Glass.** Telemetry panels are real frosted glass: `backdrop-filter: blur(20px)`, `rgba(10,11,14,0.78)` fill, a 0.5px top-edge highlight simulating the key light on the rim, a faint bottom inner-shadow. **Depth comes from the blurred car behind the glass, not box-shadows.** Generous radius (16–20px) on glass; tight radius (4–6px) on data chips — soft cinematic shell, crisp honest data, coexisting.
- **Metal.** Structural chrome — Noyau cradle, turntable disc, part pedestals, buttons — is brushed/machined steel with anisotropic highlights. Buttons are milled keys that depress, with an inner glow in the signal color on press.
- **Glow = signal, never decor.** A glowing edge *always* means a status. The Control Panel law "color is meaning" is upgraded to **"light is meaning."** Health rings, hotspots, brake discs — emission carries semantics. Signal values never appear as flat fills: a green KPI gets `text-shadow: 0 0 8px rgba(34,197,94,0.6)`; a health bar is a gradient with a 2px bright top edge. Everything important looks lit from inside.
- **Depth & shadow return** (banned in the old system): the Noyau shadows its cradle, the car shadows the turntable, glass casts onto the scene. Depth is physical now, not tonal-only.

Z-order: 3D stage at back (WebGL canvas) → glass panels mid-ground → composer tray at front → **Le Noyau highest of all**, casting a faint glow onto whatever sits below it.

---

## 4. Color & Mood

Cinematic, low-key, lit from within. Base is a **warm near-black graphite void** (deeper and warmer than the old `#0E1116`; neutrals lean tungsten, because a garage is a warm place — Sonnet). Mood = a high-end EV configurator at midnight (VoltDrive), not a neon arcade.

Signal meaning is preserved and made luminous (the non-negotiable inheritance from all three):
- **vert = OK** — clean emissive green (`#16A34A` base, `#4ADE80` highlight). The resting filament and healthy rim arcs.
- **ambre = bientôt** — warm amber (`#B45309` base, `#FCD34D` highlight). The "due soon" glow. The color of a hot catalytic converter.
- **rouge = maintenant / en retard** — saturated red (`#9B1C1C` base, `#FCA5A5` text), pulsing at 1Hz when overdue. Reserved *exclusively* for overdue/alarm; it earns its weight by being rare.
- **cyan = l'intelligence** — used **only** in Le Noyau (its cool resting filament), its narration line, and the glass-panel edge highlight. The one cool element in a warm scene. This is where we depart hardest from Haiku: cyan is rationed, not flooded, so "cyan = the agent thinking" stays unambiguous.

**Accent rule (Sonnet):** no accent color appears as a large fill. A health bar (≤180px) is the widest any signal color gets; everything else is a glow, a border tint, or a text color. Chrome, glass, and the void are the only neutral decor; emissive color always says something.

---

## 5. Typography & Data Display

Still a dense, honest, **French-language** maintenance tool — the data discipline survives the redesign intact.

- **Inter** for UI chrome and prose (headings gain a touch more weight and tighter tracking to feel premium against the glass).
- **JetBrains Mono** for *all* telemetry: `142 000 km`, `AB-123-CD`, `dans 18 jours`, TecDoc refs, dates, part counts. Mono on glass reads like an etched instrument.
- **Labels** keep the uppercase-tracked nameplate style (`FREINS`, `VIDANGE`, `CONTRÔLE TECHNIQUE`, `0.08em`) — they label hotspots and panel headers, tying back to the instrument heritage.
- **Data hierarchy** repeats everywhere: a 10px uppercase tracked label, a 32px Inter/mono value with its unit on the same baseline, a 9px muted context line below (`Prochaine échéance : 15 janv. 2026`).
- Numbers right-align; identifiers stay mono so a fleet scan aligns; part references render verbatim because a mechanic reads them as-is. Dates are French (`15 janv. 2026`), mileages use thin-space separators (`142 000 km`).
- **No false precision** (Sonnet): health rounds to the nearest 5; forecasts read `dans environ 2 mois`, not `dans 58 jours`. The one exception is overdue, where `DÉPASSÉ de 3 200 km` is precise — because precision matters when you are late.

---

## 6. Key Screens & Moments

**Le Parc (the rail → the lot).** The vehicle list is a **parking lot in 3/4 view**: each car a small *baked render* (not live 3D) on its own mini-turntable card, most-recently-touched nearest (driven by the `mpa.recency` localStorage rail), each card's base-ring already showing its health color so you triage the whole fleet at a glance. The Noyau floats over the lot. Selecting a car **drives it forward** into the full Plateau.

**Lecture de plaque — first contact.** User types `ma plaque c'est AB-123-CD`. The Noyau lifts and ignites; narration `Lecture de la plaque AB-123-CD…` (one pulse). The empty Plateau shows the spotlit turntable. A **ghost wireframe car** materializes, then resolves facet-by-facet into the identified vehicle as facts etch onto the glass slab (`Renault Clio IV · 1.5 dCi · 90 ch`). The rim arcs and hotspots fill from 0°. The car *builds itself from the plate.*

**Recherche de pièce — the catalog dive.** `trouve le filtre à huile pour la Clio`. The Noyau pulses per TecDoc call; narration cycles `Recherche · TecDoc…` → `Comparaison des références…`. On the shelf below, a pedestal rises empty, then the **filter object renders onto it** with its reference (`MANN W 712/75`) etching in beneath. Losing candidates dim and slide off the table. A CTA card **rises from the Noyau cradle**: *"Ajouter MANN W 712/75 à la tâche Vidange ?"* with milled-key buttons. Confirm → the filter slides off its pedestal onto the task card.

**Service passant en rouge — the overdue crossing (the wow).** Live via Mercure, no user action: the *Vidange* hotspot shifts amber→red, the **hood breathes a slow red glow**, the **rim arc beneath the car ignites red**, a red ember docks to the Noyau, and it wakes with one concerned amber pulse murmuring `Vidange en retard · 142 000 km`. In every open tab simultaneously. No alarm sound — the car *looking unwell* is enough.

**Saisie d'une facture — logging a visit.** The user drops a photographed bill onto the Plateau (or the agent proposes it). The Noyau works; a **past-maintenance service-tray slides out from under the turntable**, the new visit card sliding in: service-type pill, mileage, cost, date, extracted part references as small objects, and the **scanned bill as a slightly-curled physical paper with grain and a soft shadow** (skeuomorphic document — Sonnet's pinned-receipt). On desktop, the full editor is a three-column modal (Haiku): bill viewer left, form center, auto-extracted parts table right. On `Valider`, the treated system's hotspot exhales green, its rim arc refills, the card folds into the history rail. The scene resets to calm.

---

## 7. Motion & Choreography

The dashboard **physically rearranges** — nothing teleports.
- **Selection** drives a car forward and eases the camera; the previous car recedes into the lot (~350ms cross-fade).
- **A new fact** doesn't pop — it **etches in** with a quick light-sweep across the glass text.
- **A CTA is emitted by the Noyau**: it rises from the cradle so your eye is already on the agent when its proposal appears; decline and it sinks back.
- **Tool-call pulses are the room's metronome** — panels subtly ripple when a pulse passes (everything shares the room's light).
- **The data cascade:** when telemetry arrives via Mercure (any tab, any viewer), health bars animate old→new over 800ms with the glow trailing like settling liquid; KPI numbers count; hotspots cross-fade color in 400ms; the rim sweeps the delta arc. **Live events from other tabs animate identically to local ones — the room is shared.**
- **Choreography priority:** all panel animations yield to the Noyau's narration. While a tool-call is active, panel transitions queue, then run in sequence (50ms apart) when the Noyau settles — preventing "everything moves at once" chaos.

This maps cleanly onto the existing `UIAction` vocabulary in `docs/ui.md`: `selectVehicle` → drive-forward, `patchPanel` → etch-in + cascade, `renderSuggestions` → CTA rises from cradle, `toast` → ember docks to the Noyau. The agent mutates shared state; Atelier is the choreography layer over that state. **No chat transcript is ever rendered.**

---

## 8. Staying Usable & Honest

A beautiful garage that hides a due-date has failed.
- **The rim ring + hotspots are the at-a-glance truth**, and **the glass truth-panel beside the model is the authoritative one** (Sonnet): the 3D glows convey *urgency*; the panel carries *exact values, dates, km*, always visible, never hidden behind a tab. The car attracts attention; the panel provides truth.
- **Color is never the only signal** (Control Panel law, non-negotiable for accessibility): every glowing thing carries a mono label beside it. Glass never fully occludes the car.
- **A flat "plan" fallback view** exists: the same forecast as an honest mono list (next-due per service, days-until, overdue flags) for power users and for when the GPU is weak. The hero is the default, not the only, way in.
- **The composer is always accessible**: a fixed-z dark-steel tray; CTAs rise from behind it, never over it. The user can always type.

---

## 9. The Signature Wow — *The car gets sick in front of you*

Two beats, sequenced. The **first time** a plate is entered, *La Mise en Fosse*: under the single overhead key light on an empty turntable, the car **descends from the top of the viewport as if lowered on a garage lift** (2s, ease-in, spring-stop) and settles with one small damped bounce; the rim lights up as it touches down; the hotspots bloom in a radial burst. It happens once — but the owner remembers the descent.

The **recurring** wow, the thing people screenshot, is the overdue crossing: you are looking at your healthy Espace, rim glowing green, and a Mercure event tips the vidange past due — **the hood breathes red, the hotspot flares amber→red, the rim arc ignites, and the Noyau murmurs its concern** — all with your hands off the keyboard. No other maintenance app makes the machine *look unwell* and then offers to heal it. **That is the brand.**

---

## BUILD NOTES — what is genuinely hard, and the pragmatic fallback at each gate

1. **Real-time 3D in a web dashboard.** Three.js/Babylon with PBR is the target, but one full-fidelity hero car at a time only. **Le Parc lot cards are baked renders, never live 3D** (Opus's performance honesty). Budget the Noyau as the *second* most expensive render, gated behind the car being right. Lazy-init the WebGL context on first vehicle view; tear it down on the parc view.
2. **Asset pipeline for arbitrary makes/models.** This is the deepest risk: we will never have a hyperreal mesh for every plate the agent resolves. **Graceful degradation ladder:** (a) exact glTF model when it exists → (b) a **generic body-class proxy** (hatchback / berline / SUV / utilitaire / moto) skinned in the resolved paint color, still carrying all hotspots and the rim gauge → (c) a flat **SVG car silhouette** with the same hotspot grid as an overlay → (d) the flat mono "plan" list. The hotspots, rim, plate, and data survive *every* rung; only fidelity degrades. Hotspot anchors are defined per body-class, not per exact mesh, so they map onto the proxy.
3. **Performance / thermals.** Cap device-pixel-ratio, pause idle auto-rotation when the tab is backgrounded, throttle the Noyau filament shader on low-end GPUs, and prefer baked lighting/IBL over real-time shadows where possible. Detect WebGL support and frame budget at boot; auto-select the degradation rung.
4. **Accessibility & at-a-glance legibility.** Color-blind safe: every signal pairs with a mono label and the hotspot glyph differs by shape, never color alone. Respect `prefers-reduced-motion` — the breathing, pulses, and car-descent all collapse to instant state changes; the overdue wow becomes a static red state plus the narration line. Maintain WCAG contrast for the mono telemetry against glass (the glass fill is dark enough that this holds).
5. **The honest-data guarantee.** Ship the flat "plan" fallback *first* and keep it permanently reachable. Every visual flourish must be removable without losing a single value — if a number only exists inside a glow, that is a bug. Build the truth-panel before the turntable; the turntable is a *view onto* the data, never the source of it.
6. **Architecture fit.** Atelier is a rendering/choreography skin over the existing `UIAction` reducer and Mercure transport (`docs/ui.md`) — it changes how state is *shown*, not how it flows. No new runtime. The Noyau states are driven by agent-turn tool-call events; the rim/hotspots by domain Mercure events; both already exist in the contract.
