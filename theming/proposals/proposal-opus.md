# Atelier — Design Vision

> **North star:** *mon-parc-auto.fr is a lit garage at night where a living mechanic-intelligence walks around your car for you, and the bodywork itself tells you what hurts.*

This is the one sentence to repeat in every review. Not a dashboard. Not a chat app. A **garage** — a place with a floor, a light rig, a workbench, and a presence that moves through it. The car is real and three-dimensional. The agent is a being, not a progress bar. Every "screen" is a camera angle on the same room.

We are deliberately abandoning the flat HMI ("Control Panel"). That system was honest but it read like a SCADA panel for a power plant. The product's soul is *someone competent looking after your car while you sleep* — that deserves materials, weight, and light. We keep the discipline of Control Panel (signal-locked color, mono telemetry, honest data) and pour it into a hyperreal, skeuomorphic shell. Call the new system **Atelier**.

---

## 1. The Brain — *Le Noyau*

The agent's body is **Le Noyau** ("the core"): a faceted, liquid-metal sphere about the size of a clementine, floating just above a small **machined steel cradle** in the bottom-center of the screen, directly over the composer. Think the Nimbi orb fused with the NeuroNest prism — a low-poly geodesic shell of dark chrome with a molten amber-cyan filament suspended inside it, refracting through the facets. It is not a flat icon. It casts a real soft shadow on the cradle below and throws a faint pool of colored light onto the composer bar. It has weight.

**Material.** Brushed gunmetal facets with a clearcoat sheen; between the facets, seams glow with the core's inner light. The interior filament is volumetric — a slow-curling plasma you can see *through* the glass facets, like a Newton's-cradle of light. Subsurface scatter when it intensifies. This is the single most expensive render in the app and it should look it.

**Motion — three states:**
- **Idle (veille).** The sphere breathes: it scales 100%→103% over ~4s on a sine ease, rotating maybe 6° lazily. The inner filament drifts cool teal. One slow specular glint travels across the facets every few seconds. It looks asleep-but-warm, like an idling engine.
- **Working (au travail).** On a turn, the sphere lifts ~12px off the cradle, the filament ignites from teal toward amber, rotation speeds up, and the seams brighten. **Every tool-call fires one discrete pulse** — a ring of light that expands off the sphere's equator and dissipates (a heartbeat you can count). So a turn that scans a plate, hits TecDoc twice, and checks oil viscosity visibly *pulses four times*. The breathing cadence becomes the work cadence.
- **Done (posé).** It settles back onto the cradle with a tiny bounce, exhales one bright ring, filament cools to green if everything is healthy / amber if it surfaced something due. Then back to idle.

**Step-narration through the Noyau.** Today's narration is "a pulsing dot + one line of text" — underwhelming. In Atelier, the narration is a **single line of mono text that rises out of the cradle base, directly beneath the sphere**, like a readout etched on the machine: `Lecture de la plaque…` → fades → `Recherche des pièces · TecDoc…` → fades → `Viscosité d'huile…` → `Mise à jour live`. One line at a time, each fading up-and-out as the next replaces it, so it reads like a ticker the machine is thinking aloud. The history of the turn is not lost — clicking the Noyau expands the **journal drawer** (today's debug drawer, promoted to first-class) as a vertical strip of past narration lines with their tool-call pulses replayed as small glyphs. Notifications ("3 véhicules ont un service en retard") dock to the Noyau as a small numbered ember on its lower-right facet; tap it and the relevant car rotates into view.

The Noyau is the **soul anchor**: it never leaves the screen, it is the same object on the parc view and the vehicle view, and it is the one element that is unmistakably *alive*.

---

## 2. The 3D Car Turntable — *Le Plateau*

The vehicle detail view is dominated by **Le Plateau**: the real 3D car model (the SketchUp asset in `theming/`) standing on a circular **turntable** that fills the upper two-thirds of the screen. This is the hero, full stop — borrowing the staging of VoltDrive and CARBO but tuned for honesty, not gamified flash.

**Staging & light.** A dark showroom void. The turntable is a brushed-metal disc with a thin **inlaid light-ring** at its rim and chevron floor-markings radiating outward (the "My Garage" motorcycle floor). A soft key light from front-left, a cool rim light from behind to separate the car from the void, and a contact shadow that grounds it. The car rotates slowly on idle (one revolution per ~40s); the user can grab and spin it, or the agent can rotate it to face a relevant system. Mileage and plate float as a **frosted-glass telemetry slab** to the side (the VORTEX "panels-over-the-hero" pattern), never occluding the body.

**Telemetry reads OFF the model.** This is the core promise — health is not a chart beside the car, it is *on* the car:
- **Hotspots.** Hexagonal "+" annotation pins (the ATMOS helmet pattern) pinned to body regions: front wheels = *Freins*, wheel arches = *Pneus*, hood = *Vidange / Huile* and *Courroie de distribution*, front bay = *Batterie*, a windshield badge = *Contrôle technique*. Each pin's hex glows in the signal color of that system's forecast: **vert** (ok), **ambre** (bientôt), **rouge** (maintenant/en retard).
- **Body glow.** When brakes are due, the **brake discs themselves emit an amber glow** through the wheel; overdue, they pulse red. Tyres due → a red contact-band where rubber meets the turntable. This is the moment that sells the product: you *see* the sick part on the actual car.
- **The plate is the master readout.** The car's licence plate is the real French plate (mono, the exact `AB-123-CD` format). The **turntable rim doubles as the global health gauge**: it is a 360° ring divided into arc-segments, one per tracked system, each lit its signal color — the live-dev "forecast-health-bars" reborn as a *ring you stand the car on*. One overdue system and that arc burns red; the whole rim is green when the car is healthy. Glance at the floor, know the car.

**Parts as objects.** A part the agent finds is not a table row — it is a **rendered physical object on a shelf below the Plateau** (the "My Garage" parts shelf: oil bottle, filter, brake disc, battery, tyre). Each sits on a small lit pedestal with its TecDoc reference etched mono beneath it and a shop-search link as a tag. Dropping a part onto a task animates it sliding from the shelf onto the task card.

---

## 3. Material & Light Language

The hard break from flat HMI, made concrete:

- **Glass.** Telemetry panels are **frosted glass** (real backdrop-blur, a 1px inner highlight on the top edge, a faint bottom inner-shadow) floating over the 3D scene — VORTEX command-deck panels. They have thickness.
- **Metal.** Structural chrome — the Noyau cradle, the turntable disc, part pedestals — is **brushed/machined steel** with anisotropic highlights. Buttons are milled metal keys that depress.
- **Glow as data.** Light is never decorative here; **glow = signal**. A glowing edge always means a status. Health rings, hotspots, brake discs — emission carries meaning, same discipline as Control Panel's "color is meaning," upgraded to "light is meaning."
- **Depth & shadow.** Real soft shadows return (banned in the old system). The Noyau shadows its cradle, the car shadows the turntable, panels cast onto the scene behind. Depth is now physical, not tonal-only.

Where Control Panel said *no gradients, no gloss, no shadow, low radius* — Atelier says **environment gradients (showroom void), clearcoat gloss on the hero, real depth shadows, and generous radius on glass (16–20px) while keeping data chips tight (4–6px)**. The two coexist: soft cinematic shell, crisp honest data.

---

## 4. Color & Mood

Cinematic, low-key, lit from within. Base is a **near-black graphite void** (deeper and warmer than the old `#0E1116`) so glow and chrome carry the image. Mood = a high-end EV configurator at midnight (VoltDrive), not a neon arcade (CARBO is the cautionary tale — gamified, too candy; we take its *staging*, not its saturation).

Signal meaning is preserved and made luminous:
- **vert = OK** — a clean emissive green, used as the resting filament and healthy rim arcs.
- **ambre = bientôt** — warm amber, the "service due soon" glow on hotspots.
- **rouge = maintenant / en retard** — saturated red, reserved exclusively for overdue/alarm; it earns its weight by being rare.
- A **cyan-to-amber gradient** is the Noyau's identity (cool at rest, hot at work) — the one place a gradient is sanctioned, because it encodes the agent's state of exertion.

Color stays meaning-locked: chrome, glass, and the void are the only neutral decor; emissive color always says something.

---

## 5. Typography & Data Display

Still a dense, honest, **French-language** maintenance tool. The data discipline survives the redesign intact:
- **Inter** for UI chrome and prose. Headings gain a touch more weight and tighter tracking to feel premium against the glass.
- **JetBrains Mono** for all telemetry: `142 000 km`, `AB-123-CD`, `dans 18 jours`, TecDoc refs, dates, part counts. Mono on the glass slabs reads like an etched instrument.
- **Labels** keep the uppercase-tracked nameplate style (`FREINS`, `VIDANGE`, `CONTRÔLE TECHNIQUE`) — they label hotspots and panel headers, tying back to the instrument heritage.
- Numbers right-align; identifiers stay mono so a fleet scan still aligns. The redesign must never make a due-date harder to read than it is today — eye-candy never costs legibility.

---

## 6. Key Screens & Moments

**Le Parc (the rail → the lot).** The vehicle list becomes a **parking lot seen in 3/4**: each car a small lit render on its own mini-turntable card, most-recently-touched nearest, each card's base-ring already showing its health color so you triage the whole fleet at a glance (the "Garage" app per-system cards, but 3D). The Noyau floats over the lot. Selecting a car *drives it forward* into the full Plateau.

**The agent reads a plate.** User types `ma plaque c'est AB-123-CD`. The Noyau lifts and ignites; narration: `Lecture de la plaque AB-123-CD…` (pulse). A **ghost wireframe car** materializes on an empty Plateau, then resolves facet-by-facet into the identified Renault Espace as facts populate the glass slab (`Marque · Renault`, `Code moteur · …`). The car *builds itself* from the plate. One pulse per lookup.

**The agent finds the right part.** `trouve le filtre à huile pour la Clio`. Noyau pulses on the TecDoc call. A pedestal rises from the parts shelf, empty; then the **filter object renders onto it** with its reference etching fading in beneath. A CTA card slides up from the Noyau: *"Ajouter PURFLUX LS867 à la tâche Vidange?"* with milled-key option buttons. Confirm → the filter slides off its pedestal onto the task card.

**A service crosses into overdue.** This is the emotional beat. Live via Mercure, with no user action: the *Vidange* hotspot on the hood **shifts amber→red**, the hood region flushes a slow red glow, the corresponding **turntable rim arc burns red**, and a red ember docks to the Noyau. In every open tab simultaneously. The car visibly got sicker while you watched.

**Logging a visit with a bill.** User drags a photographed bill onto the Plateau (or the agent logs it). The Noyau works; a **past-maintenance "drawer" slides out from under the turntable** like a service-tray, the new visit card sliding in with the parts it carried as small objects and the scanned bill as a thumbnailed paper with a slight curl/shadow (skeuomorphic document). The treated system's hotspot exhales green; its rim arc refills.

---

## 7. Motion & Choreography

The dashboard **physically rearranges** — nothing teleports. When the agent acts:
- **Selection** drives a car forward and rotates the camera; the previous car recedes into the lot.
- **A new fact** doesn't pop — it **etches in** on the glass slab (a quick light-sweep across the text).
- **A CTA** is *emitted by the Noyau* — it rises from the cradle, so the user's eye is already on the agent when its proposal appears. Decline and it sinks back.
- **Tool-call pulses** are the metronome of the whole scene; panels subtly ripple when a pulse passes (everything shares the room's light).
- Live Mercure events from other tabs animate identically to local ones — the room is shared; a write elsewhere ripples here.

Choreography rule: **the agent's attention is the camera.** When it works on brakes, the car turns to show the wheels and the camera eases in. The user is always looking where the agent is looking.

---

## 8. Staying Usable & Honest

A beautiful garage that hides a due-date has failed. Guardrails:
- **The rim ring + hotspots are the at-a-glance truth.** A fleet owner spins through the lot and reads health off the floor-rings without opening anything — faster than the old table, not slower.
- **Glass never fully occludes the car**, and every glowing thing has a mono label beside it (color is never the only signal — inherited from Control Panel, non-negotiable for accessibility).
- **A "plan" / flat fallback view** exists: the same forecast as an honest mono list (next-due per service, days-until, overdue flags) for power users and for when the 3D is loading or GPU is weak. The hero is the default, not the only, way in. The 3D degrades to a high-quality static render, then to the flat view — the data is always reachable.
- **Performance honesty:** one hero car at a time renders at full fidelity; lot cards are baked renders, not live 3D.

---

## 9. The Signature "Wow" — *The car gets sick in front of you*

The anchor moment, the thing people screenshot: **a service crossing into overdue, live.** You're looking at your Espace on its turntable, healthy, rim glowing green. A Mercure event arrives — the *vidange* forecast just tipped past due. Without you touching anything, the **hood breathes a slow red glow, the brake or oil hotspot flares from amber to red, the rim arc beneath the car ignites red, and the Noyau wakes with a single concerned amber pulse and murmurs `Vidange en retard · 142 000 km`.** The car, sitting in its lit garage, visibly tells you it needs you — and then the agent offers to fix it. No other maintenance app makes the machine *look unwell*. That is the brand.

---

### What Atelier keeps from Control Panel
Signal-color meaning (vert/ambre/rouge), mono telemetry, uppercase nameplate labels, "color/light is always meaning," and the flat honest list as a guaranteed fallback. **What it throws out:** the ban on depth, gloss, gradient, and warmth. The instrument survives; the cold dies.
