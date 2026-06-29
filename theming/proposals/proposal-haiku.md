# Design Vision: mon-parc-auto.fr — Hyperreal Garage Command

## North Star

A hyperreal, physics-forward garage command center where the car is the hero, a living agent-presence embodies the AI co-pilot, and maintenance health reads off the vehicle itself like a sci-fi cockpit—not a flat HMI, but a collaborative instrument panel floating over a tangible 3D stage.

---

## 1. The Core Metaphor: Showroom Theater, Not Control Panel

The old design was *industrial HMI*—dark, intentionally flat, signal colors on a grid. Uninspiring. The new direction inverts this: we're designing a *luxury garage theater*, somewhere between a high-end automotive configurator (think Tesla's 3D studio), a sci-fi ship command deck (Vortex), and a museum display floor (VoltDrive's glowing turntable).

The user doesn't feel like they're operating a maintenance database. They feel like they're standing in a showroom where their car is lit by gallery spotlights, and a living intelligence—embodied as **The Brain**—whispers its analysis directly into the vehicle's telemetry. It's intimate, powerful, and fundamentally *real*: no flat tiles, no abstract icons. Depth, materials, light, shadow, glass, steel.

---

## 2. The Brain: The Soul of the Product

The agent is not a chat bubble. It is **The Brain**—a 3D, faceted, luminescent orb floating in the top-right corner (or top-center in landscape), roughly the size of a large marble. Think the "Nimbi" agent icon: a crystalline, semi-translucent sphere with soft internal faceting and a warm cyan/electric-blue glow.

### Visual Language
- **Idle state**: The Brain breathes. Soft, 2-3 second pulse cycle. Very subtle rotation on its axis (Z-axis, slow). A gentle inner glow, like it's thinking at rest. No animation at all feels dead; the breathing says "it's alive and listening."
- **Listening state**: Pulses slightly faster when the user types. The glow stays constant. An inner ring of light traces a circumference—very subtle, like a signal being received.
- **Working state**: The brain *intensifies*. The glow brightens, the rotation speeds up. A pulse of light shoots through it roughly every 0.5 seconds as each tool call fires (plate reader, parts search, web lookup, write-back). The pulses are not jarring; they're almost like a heartbeat or synaptic firing. Users watch this to see the agent *think in real time*.
- **Done state**: A final, satisfying bloom—the glow expands outward like a soft halo, settles, then returns to idle breathing. No beep, no toast overlay. The Brain itself is the notification.

### Narration Surface
Below The Brain (or orbiting it) is a single line of step text, updating in real time:
- "Lecture de la plaque…"
- "Recherche sur TecDoc…"
- "Recherche de la viscosité…"
- "Mise à jour du tableau de bord…"
- "Fait."

The text is small, monospace (keeping the data-tool honesty), pale teal, and fades in/out smoothly. No spinner icon. The Brain IS the spinner.

### Key Insight
The Brain replaces the entire "debug journal" drawer and toast system. Every status update, every error, every triumph flows through it. When a tool fails, The Brain flashes red (just the outer edge, a warning corona), and the step text says "Erreur: pièce non trouvée" or similar. When a write succeeds, The Brain pulses green once. The user learns to *read* The Brain the way you read a car's instrument cluster—at a glance, you know the state of play.

---

## 3. The 3D Car Turntable: Maintenance as Annotated Reality

The vehicle hero dominates the center-right of the screen, rendered as a high-fidelity 3D model (using the existing SketchUp asset). The car sits on a **glowing turntable** — a circular platform with a thin, bright cyan ring that marks its edge. The ring is not purely decorative; it is the status disk.

### Staging & Lighting
- **Camera**: Three-quarter view, slightly elevated (as if you're standing a few feet away, eye-level with the hood). The car rotates slowly on the turntable (one full rotation every 15-20 seconds) when idle—not frantic, like a museum display.
- **Lighting**: Soft key light from the upper left (warm, subtle golden-white), a cooler fill light from the right (cyan-tinted, reinforcing the brand color). A faint rim light on the far side of the car to separate it from the background. Real depth: the car casts a soft shadow on the turntable.
- **Background**: Matte dark blue-grey (not pure black—a slight texture, like brushed metal or slate). Far enough back to feel open, not claustrophobic.

### Health Hotspots & Annotations
The car's surface itself becomes a diagnostic canvas. Overlaid on the 3D model are **hexagonal hotspot badges**, roughly 2–3 per vehicle zone (brakes, engine, cooling, wheels, bodywork, steering). Each badge:
- **Color-codes by health**: Vert (ok, 0% wear) = muted, almost invisible. Ambre (50-80% wear, service coming) = glowing amber/orange. Rouge (80%+, overdue) = bright red, pulsing.
- **Label**: A short system name in tiny white text (e.g. "FREINS", "HUILE", "PNEUS", "CT").
- **Glyph**: A tiny icon (brake disc, oil drop, tire, etc.) inside the hex.
- **Interaction**: Hovering or tapping a hotspot expands a small card overlay showing:
  - System name & current health % (e.g. "Freins — 72% santé")
  - Next service interval (km or days)
  - Days/km until due (e.g. "dû dans 1.2k km")
  - Last logged date (e.g. "dernière intervention: 15 nov 2024")

This is not a list. It is *reading maintenance off the car*.

### The Status Turntable
The circular disc the car stands on is not just pretty. Embedded in it (or around its edge) are 4–5 metric readouts in monospace font, arranged at cardinal points:
- **Top**: Current mileage (e.g. "142,300 km")
- **Right**: Fleet health avg (e.g. "73%")
- **Bottom**: Days since last service (e.g. "47 j")
- **Left**: Overdue count (e.g. "2 en retard" — flashing red if non-zero)

The disc's ring glows in proportion to fleet health: all green = solid bright cyan, mixed = gradient amber-cyan, lots of red = amber-red gradient. At a glance, the state of the car is legible without reading anything.

### Rotation & Interactivity
The turntable responds to user intent. If the user taps the "engine" hotspot, the car rotates to show the engine bay angle (if camera allows) or the rotation pauses to keep the hotspot visible. Dragging left/right on the turntable manually rotates the car. Very smooth, no snapping. You are *inspecting* your vehicle, not navigating a flat menu.

---

## 4. Material & Light Language: Breaking the Flat Code

The Control Panel design was intentionally flat—a signal-color reaction. We reject this entirely. The new palette is **layered, translucent, and luminous**.

### Surfaces
- **Primary panels** (vehicle detail, forecast, schedule): Semi-transparent frosted glass with a subtle blue undertone. Backdrop blur (CSS `backdrop-filter: blur(8px)`) to the dark background. A thin, bright cyan border (1–2px) marks the edge. No hard shadows; instead, a very soft glow at the bottom edge, as if light is trapped beneath the glass.
- **Data cards** (past maintenance, parts shopping): Matte metal (dark charcoal grey) with a faint pearlescent sheen. Slightly raised (box-shadow: 0 4px 16px rgba(0,255,255,0.1)). Text embossed very slightly into the surface.
- **Composer input**: Glowing field. Dark background, bright cyan border, and an inner shadow that glows cyan when focused. Placeholder text is a pale, ghosted cyan. When the user types, the glow intensifies. Very responsive—the input feels *alive*.

### Color Palette
Retain signal colors but make them cinematic:
- **Green (ok)**: Not flat lime. A luminous, saturated cyan-green (#00E5CC or similar). In cards, it glows faintly.
- **Amber (soon)**: A warm, rich orange (#FF9500 or similar). When a service is due-soon, the hotspot pulses gently amber.
- **Red (overdue)**: A deep, saturated red (#FF3B54 or similar). Overdue hotspots glow steadily red; modals and CTA cards flash red briefly when triggered.
- **Cyan/Electric Blue**: The brand accent, used in The Brain, turntable ring, glass borders, and highlights. It is the color of intelligence and electricity. It connects all agentic elements.
- **Background**: Not pure black. A very dark blue-grey (#0A1619 or similar), with a subtle diagonal noise texture (imperceptible unless you zoom in). This keeps the dark gamut from feeling dead.

### Depth & Shadow
- Use real shadows sparingly, but when you do, make them cyan-tinted (not neutral grey). A glowing shadow is better than a real one.
- Elevation steps: main panels hover ~16px above the background, cards hover ~8px, The Brain floats ~24px. The depth is clear without being excessive.
- No drop shadows on text. Emboss or a faint glow instead.

---

## 5. Typography & Data Integrity

This is still a dense, honest data tool. Complexity cannot be hidden. But it can be made beautiful.

### Typefaces
- **Sans serif (primary)**: Inter or similar modern, open geometric sans. Clear and fast to scan.
- **Monospace (data)**: IBM Plex Mono or similar. Preserves the technical, garage-manual aesthetic. Used for all KPIs, mileage, dates, model codes (e.g. "AB-123-CD"), viscosity codes.

### Data Presentation
- **Metrics remain dense**. The health ring, forecast table, past maintenance rail are not simplified; they are *refined*. Every field is legible.
- **Labels are French and succinct**: "Santé", "Prochainement", "En retard", "Intervention", "Pièces", etc.
- **Monospace columns preserve alignment**: mileage values line up right-aligned; viscosity codes stay fixed-width.
- **No hiding data behind dropdowns** unless it's truly secondary (like "view full invoice PDF"). Primary data always visible.

### Key Surfaces

**Forecast Panel**:
- A clean table: System | Health | Interval | Due Date | Days Remaining
- Each row color-codes by health. Overdue rows have a red left border and a faint red background wash.
- No icons cluttering the table. A small glyph (if any) at the left of the system name, inline, monospace.

**Past Maintenance Rail**:
- A horizontal scrollable rail of cards, each representing one logged visit (one maintenance record).
- Card shows: date, mileage, brief description (e.g. "Vidange + filtre"), parts count, bill thumbnail (if attached).
- Cards are clickable to expand and show full details (parts table, bill viewer, agent narration of what was done).

**Parts Shopping List**:
- For each part: ref code (monospace, copyable), description, shop-search links.
- Color-coded by status: green = in stock, amber = limited, red = not found.
- No cart metaphor; this is an honest checklist.

---

## 6. Key Screens & Moments: The UX Narrative

### Moment 1: Agent Reads a Plate
The user types: "ma plaque c'est AB-123-CD"

The Brain begins to pulse faster. Step text: "Identification du véhicule…"

The dashboard keeps the current vehicle in view, but a subtle scan-line animation plays across the plate text in the top bar (like a barcode reader). Within 1–2 seconds, The Brain glows bright (success), step text reads "Sélection: Peugeot 308 2021 — 142,300 km", and the detail panel updates smoothly (no jarring replace, a fade-in of the new content over ~300ms).

### Moment 2: Agent Finds the Right Part
The user says: "trouve le filtre à huile pour la Clio"

The Brain pulses intensely. Step text cycles through:
- "Recherche sur TecDoc…"
- "Comparaison des références…"
- "Vérification des prix…"

The parts panel on the right fills in with a suggested part (e.g. "MANN Filter HU 719/7x", ~€12.50, 3 links to shop online). The part card glows amber initially (found), then settles to a green glow (verified in stock). A subtle "ding" sound (very quiet, more felt than heard) signals completion. The CTA card below offers "Ajouter aux pièces" or "Acheter maintenant".

### Moment 3: Service Crosses into Overdue
The user logs a past maintenance record at 100,000 km (oil change) on a vehicle that now shows 142,300 km.

The agent has re-calculated the forecast. The "Huile" hotspot on the turntable shifts from amber (soon) to red (overdue). The hotspot pulses red gently. The forecast row for Oil also turns red, and a red pill badge "6,300 km en retard" appears at the top of the forecast panel.

The Brain flashes red briefly (warning), then returns to idle. Step text: "Mise à jour complète. Huile maintenant en retard."

On the next page load (or if the user clicks the "Huile" hotspot), a small tooltip or card appears: "Huile — Dernière intervention: 142,300 km. Prochain entretien recommandé: 150,000 km. Vous êtes à 142,300 km. Retard: 0 km (juste dû)." — Honest, clear, no alarm fatigue.

### Moment 4: Logging a Maintenance Visit with a Bill

The user clicks "Enregistrer un entretien" (or the agent proposes it after detecting a bill photo). A modal appears:

**Modal layout**: 3-column grid.
- **Left**: Bill image viewer (can upload PDF or JPG, shows thumbnail). Sticky at the top.
- **Center**: Form fields (date, mileage, description, work performed). Simple text/date inputs.
- **Right**: Auto-extracted parts table (if the agent has OCR'd the bill or if the user taps "Proposer les pièces"). Parts can be edited, added, removed.

As the user fills the form, The Brain pulses gently (listening, recording). On submit, The Brain blooms (full glow, fast pulse, then settles). Step text: "Enregistrement: Vidange à 142,300 km avec 3 pièces. Mise à jour du tableau de bord…"

The modal closes. The vehicle detail panel updates: the past maintenance rail scrolls to show the new card at the front (most recent). The forecast updates (if the interval is reset by the log). The turntable hotspot for "Huile" fades from red to amber (or green, depending on the interval).

---

## 7. Motion & Choreography: The Dashboard Dances

The dashboard is not static. When the agent acts, the UI rearranges itself like a responsive organism.

### Selection Flow
When the user selects a vehicle from the parc rail (left sidebar), the detail panel does not snap. Instead:
1. The old vehicle detail fades out (~150ms).
2. The turntable car rotates smoothly to a neutral angle.
3. The new vehicle model cross-fades in (~300ms), already rotating.
4. The forecast, schedule, and KPI panels refresh their data smoothly (numbers animate in, rows reorder if necessary).
5. Hotspot badges on the car pulse once (a brief glow from transparent to full color to transparent) to draw the eye.

Total time: 500–800ms. Never janky. The user feels the app *responding*, not loading.

### Agent Action Flow
When the agent completes an action (e.g., logs a part, updates a forecast):
1. The affected hotspot on the car flashes its new color (if the health state changed).
2. The relevant data panel (forecast, past maintenance, etc.) receives a subtle highlight—a brief glow on the background, like a flash of light, that fades away.
3. The turntable ring glows brighter for a moment (indicating a write).
4. The Brain sends its completion pulse.

The choreography is not intrusive. It's tactile feedback, like a confirmation vibration on a phone—you feel it, but it doesn't stop you.

### Expansion & Collapse
When the user clicks a hotspot or a card to see details, the expanded content slides up from the bottom (mobile) or unfolds as an overlay (desktop). The turntable stays visible behind a slight dim wash. Closing the overlay is smooth (slide down / fade out).

---

## 8. Usability & Honesty: Why This Isn't Just Eye Candy

The hyperreal aesthetic is intentional, not ornamental. It serves the tool:

1. **The 3D car is not decorative.** It is the fastest way to visualize which systems are healthy. A user glances at the turntable and instantly reads: "brakes red, oil amber, tires green, coolant green". This is faster than scanning a list.

2. **The Brain centralizes feedback.** No scattered toasts, no modal dialogs screaming for attention. One visual locus for status. Less noise, more signal.

3. **Materials signal interactivity.** Frosted glass says "you can read through me". A glowing input says "I am listening". The cyan glow says "I am intelligent". These are not arbitrary—they are functional metaphors.

4. **Monospace data remains dense and precise.** We do not trade honesty for beauty. Every value is where you expect it. Columns align. References are copyable. The tool is still a tool.

5. **Colors are signal, not decoration.** Green, amber, red are not arbitrary—they are internationally understood maintenance states. Making them glow does not change their meaning; it makes it *more visible* at a glance.

6. **The turntable is faster than clicking.** Instead of a dropdown menu "System: [Oil]", you tap the oil hotspot on the car. One interaction, one target. Faster, more intuitive, more delightful.

---

## 9. The Wow Moment: The Signature Experience

**When the agent identifies a vehicle by reading the plate and the 3D car model appears on the turntable for the first time.**

The user types: "ma plaque c'est AB-123-CD"

The turntable is currently empty or showing a generic car silhouette. The Brain begins its pulse.

As the identification completes, the old car *dissolves* (a brief shimmer, like heat haze), and the exact 3D model of the user's actual car *manifests* in its place—a Peugeot 308 Allure, silver, correctly rendering the real body style, wheels, trim. The car rotates smoothly as it appears, the hotspots bloom in sequence (breathing into existence), and the KPI strip and forecast panels populate with real data.

All of this happens over 800ms. It feels like magic, but it is pure technical execution: the agent fetched the VIN from the plate, looked up the exact model variant in TecDoc, and the React renderer is displaying the correct 3D asset + hydrating the UI with real data.

This moment—when the abstract becomes concrete, when the digital car is *their* car—is the emotional anchor of the product. It says: "This tool sees your car. It knows it. It is going to take care of it."

---

## 10. Summary: A New North Star for mon-parc-auto.fr

We are abandoning the flat industrial HMI. In its place: a **hyperreal garage theater**, where the car is hero, the agent is a *living presence* (The Brain), and every interaction is grounded in real materials, real light, real depth.

This is not a dashboard for fleet managers. It is a tool for car owners who care—people who want their maintenance tracked with intelligence, beauty, and honesty. The design says: "Your car matters. It is worth looking at. We are going to help you keep it running."

The Brain breathes. The turntable glows. The hotspots read truth. And the user, sitting in front of the screen, feels like they are standing in a high-end garage with an AI co-pilot whispering diagnostics in their ear.

That is the vision.
