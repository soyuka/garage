# Design inspiration — how the Atelier should show what it knows

Written 2026-08-15, from a prompt-corpus audit of the live tool surface (29 MCP tools) against the
render surfaces in `dashboard/`. Read `dashboard/CHOREOGRAPHY.md` first — that file is the binding
map. This one is the *why*, and the brief for the surfaces that don't exist yet.

The trigger: a rehearsal of the pilot beats showed the transport is fine and the data is rich, yet
the screen still reads as a list of things rather than an instrument. The cause turned out not to be
motion or latency. It is what follows.

---

## 1. The finding that reframed everything

We built a corpus of ~28 prompts a garage owner actually says, mapped each to the tools that answer
it and the visual that carries it. The ✅ rows were the three rehearsed beats. The ❌ rows all shared
one shape:

**The dashboard grew surfaces the wire vocabulary cannot address.**

- `CarnetProjection.tsx` exists and renders history + spend. `focus_view`'s `panel` enum was
  `["tasks","specs"]`. Only the "Carnet · dépenses" wayfinder chip (`dashboard/src/Atelier.tsx`, the
  `buildQuickActions` block) could raise it.
- `Focus.nodes?: SpecNode[]` (`dashboard/src/focus.ts`) is documented as "a fetched surface projected
  through the same specs mechanism", `SpecConstellation.tsx` renders it, the chips use it — and no
  tool could emit it.
- `list_due_maintenance` forecasts the **whole garage** and always could. There is no parc-level
  surface to put that on at all.

So the agent would look something up, get real data back, and have nowhere to put it. It fell back to
a `toast` — prose — which is exactly what `stage/CLAUDE.md` forbids: *"Never answer a question in
prose that could have been a panel."* The protocol banned the failure the contract forced.

Sharpest case: ask « elle prend combien d'huile ? ». The agent calls `get_fill_volumes`, gets the
answer, calls `show_fact(['oil_capacity'])` — **empty panel**, because that key is not a stored fact.
The panel was emptiest exactly when the agent had done the most work.

The wire holes are being closed (see `FUTURE.md` § Render vocabulary). The design holes are below.

---

## 2. Ambient vs summoned — the split we already have and keep violating

The Iron Man HUD teardown at [scifiinterfaces](https://scifiinterfaces.com/2015/07/13/iron-man-hud-just-the-functions/)
separates two layers:

- **Ambient** — a persistent strip (suit status, targeting, radar, artificial horizon, map) that never
  moves and costs no attention.
- **Summoned** — damage assessment, targeting, schematics, comms: "brought up for Tony's attention
  **when JARVIS believes they are needed**, or when Tony requests them."

Only optical zoom is user-driven. The AI does the filtering.

We have that split already: the Entretien rail is ambient, the right projection column is summoned
(`CHOREOGRAPHY.md` § "Data around the turning car"). The rule we break is putting **summoned content
in the ambient layer** — see the worked example below.

The second lesson from the same teardown is harder: **in fiction the assistant does not show a list.**
It shows the one thing and holds the rest. We have 29 tools and still hand the operator a flat
enumeration.

---

## 3. Principles worth stealing

**Complex to look at, instant to read.** Jayse Hansen, who designed the Iron Man HUD and the Avengers
UI, had 24 frames — one second — to land a read: it "has to look super complex, but it has to be
super clear at the same time"
([interview](https://www.pushing-pixels.org/2012/06/01/the-craft-of-screen-graphics-and-movie-user-interfaces-conversation-with-jayse-hansen.html)).
Complexity is *texture*. The read is one glance. A surface that has the texture and no read has
failed, however dense it looks.

**Nothing decorative.** Designing for stereo 3D put everything in focus, so every element had to have
a function and could not be decoration. Same instinct as our own *truth before spectacle* rule
(`CHOREOGRAPHY.md` § Priority & honesty) — worth keeping when a new surface is tempting.

**Detail as a physical mechanism, not a scroll.** Villeneuve briefed Territory Studio to give Blade
Runner 2049's tech "organic, abstract, optical and physical qualities". One screen was built as a
series of **optical lenses that shunt into place with each level of magnification**, animated as
looping states the actor triggers
([Territory](https://territorystudio.com/project/blade-runner-2049/),
[vfxblog](https://vfxblog.com/2017/11/08/a-visual-journey-through-the-screen-graphics-of-blade-runner-2049/)).
This is the direct answer to "how do I show seven planned services without a list": **magnify, don't
enumerate.**

**Restraint reads as real.** BR2049's screens are a limited palette, utilitarian, screen-burnt. We
already ration cyan/orange by role and express hierarchy as vibrancy tiers. Hold that line.

---

## 4. From fiction rather than film

- **Rainbows End** (Vinge) — everyone "looks at the world through a layer of data"; belief circles
  re-skin the same reality per viewer. The data is painted **on the world**, not beside it. Our region
  spotlight is this idea at five zones (`dashboard/src/scene/anchors.ts:26`). Anything that can be
  true *of a part of the car* should be drawn on the car.
- **The Diamond Age** (Stephenson) — the Primer adapts to its reader and answers **as a story**. It
  has no list view, no menu, no settings.
- **Neuromancer** (Gibson) — data as architecture you move through; distance encodes relevance.
  Nothing is "below the fold" — things are *far away*. A useful reframe for the 2030 spark plugs.
- **The Expanse** — hand terminals are deliberately mundane; ship displays show only what the scene
  needs. Restraint as realism.

Through-line: none of these assistants enumerate. They rank, they hold, and they answer at the
altitude of the question.

---

## 5. Worked example — the Entretien rail today

Verbatim from a live Mazda6 (abridged):

```
En cours
  Filtre à carburant            dans environ 8 mois
  Sonde de température — Sonde température liquide refroidissement
      L35G-18-840  MAZDA     Sonde température liquide de refroidissement
  Support moteur — support moteur arrière (derrière le moteur)
      TEG1101      TATSUMI   Support moteur arrière
      GJ6A-39-040A MAZDA     Support moteur arrière (origine Mazda)
      MZM-014      FEBEST    Support moteur arrière
  Support moteur — support moteur gauche (côté boîte de vitesses)
      … 3 more refs

Planifié
  Contrôle technique   dans environ 7 mois    21 mars 2027   OK
  Vidange              dans environ 11 mois   6 juil. 2027   OK
  Filtre à huile       dans environ 11 mois   6 juil. 2027   OK
  Liquide de refroidissement  dans environ 21 mois  30 avr. 2028  OK
  Filtre à air         dans environ 36 mois   24 juil. 2029  OK
  Filtre d'habitacle   dans environ 36 mois   24 juil. 2029  OK
  Bougies              dans environ 45 mois   28 avr. 2030   OK
```

Five defects, in descending order of how much they cost:

1. **Item-shaped, but the work is visit-shaped.** *Vidange* and *Filtre à huile* both sit at
   6 juil. 2027 — that is **one trip to the lift**, rendered as two rows. Same for the two engine
   mounts. Our own domain already models this correctly on the log side: a `MaintenanceRecord` is a
   **visit** carrying `items[]`. The plan side does not mirror it. Fixing the asymmetry halves the
   list for free and matches how a mechanic books work.
2. **Summoned content in the ambient layer.** Three brands at three prices for one engine mount is a
   *decision*, not a *status*. It belongs in `PartsManifest`, raised on demand. In the rail it is a
   catalogue bleeding into a to-do list.
3. **Seven rows saying "OK".** Zero information at seven rows of cost. When nothing is wrong the
   ambient layer should compress to one line, not enumerate its own silence.
4. **Two clocks, neither actionable.** « dans environ 8 mois » *and* « 21 mars 2027 » in the same row.
   A mechanic thinks *this week / next lift / later*. Absolute dates are detail-on-demand.
5. **Bougies · 2030 is not a task.** Forty-five months out is a schedule entry that wandered into a
   worklist. It should be far away, not listed.

---

## 6. Direction for the redesign

Binding constraints that do not move: no theatre / no padded latency
(`docs/prompt-pilot-motion.md` § Hard rules 1); truth before spectacle — every value a motion conveys
must exist at full precision in the truth panel; a new motion gets its `CHOREOGRAPHY.md` row before it
is written.

1. **Group the plan by visit, not by service.** A visit card ("6 juil. 2027 — vidange + filtre à
   huile") is the unit a garage actually schedules. Derive it from due-date proximity the same way
   `MaintenanceRecord.items[]` groups the log.
2. **Three horizons, not one flat list.** *Maintenant* (late or this month) fully drawn · *Prochaine
   visite* one card · *Plus tard* collapsed to a count that magnifies on demand. Gibson's distance
   rule: far is far, not hidden.
3. **The rail states, the column decides.** Nothing with a price or a brand in the ambient layer.
4. **Silence compresses.** Nothing due ⇒ one line, and the car's own health signal carries the rest.
5. **Draw on the car what is true of the car.** Anything region-addressable goes on the body before it
   goes in a list; the five regions of `anchors.ts` are the current ceiling, and the engine-bay reveal
   (workstream C in `CONFERENCE.md`) is what raises it.
6. **A parc surface, ranked and never enumerated.** `list_due_maintenance` already returns the whole
   garage. The design question is what a fleet answer *looks like* — a lot of cars where the ones that
   need work are simply nearer and lit. That is a new surface: design before build.
