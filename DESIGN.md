---
name: Atelier
description: A garage tool you read like a parts catalogue — one job per screen, hairline modules, one interface blue, edge-drawn live 3D.
colors:
  graphite-ground: "#101113"
  module-fill: "#1c1e22"
  plate: "#24272c"
  plate-lit: "#3a3e45"
  hairline: "#2c2f35"
  ink: "#e8e6e1"
  ink-muted: "#a1a5ad"
  workshop-blue: "#5b8fd6"
  blue-ink: "#0d1622"
  fitted-green: "#3fa66b"
  due-amber: "#e09a2b"
  overdue-red: "#e5484d"
  scrollbar: "#3a3e45"
  light-ground: "#eceef1"
  light-module: "#ffffff"
  light-plate: "#e2e5ea"
  light-plate-lit: "#f7f8fa"
  light-hairline: "#d0d5dc"
  light-ink: "#14171b"
  light-ink-muted: "#4c525c"
  light-blue: "#1f5fbf"
  light-blue-ink: "#ffffff"
  light-fitted-green: "#1e7f4a"
  light-due-amber: "#a85f00"
  light-overdue-red: "#c62f2f"
  light-scrollbar: "#b9bfc8"
typography:
  headline:
    fontFamily: "Zen Kaku Gothic New, system-ui, sans-serif"
    fontSize: "22px"
    fontWeight: 700
    lineHeight: 1.12
    letterSpacing: "-0.01em"
  title:
    fontFamily: "Zen Kaku Gothic New, system-ui, sans-serif"
    fontSize: "18px"
    fontWeight: 700
    lineHeight: 1.25
  figure:
    fontFamily: "JetBrains Mono, ui-monospace, monospace"
    fontSize: "18px"
    fontWeight: 500
    lineHeight: 1.2
    fontFeature: "tnum"
  name:
    fontFamily: "Zen Kaku Gothic New, system-ui, sans-serif"
    fontSize: "14px"
    fontWeight: 700
  body:
    fontFamily: "Zen Kaku Gothic New, system-ui, sans-serif"
    fontSize: "13px"
    fontWeight: 400
    lineHeight: 1.35
  row:
    fontFamily: "Zen Kaku Gothic New, system-ui, sans-serif"
    fontSize: "12.5px"
    fontWeight: 400
    lineHeight: 1.35
  label:
    fontFamily: "Zen Kaku Gothic New, system-ui, sans-serif"
    fontSize: "12px"
    fontWeight: 700
    lineHeight: 1.3
    letterSpacing: "0"
  state:
    fontFamily: "Zen Kaku Gothic New, system-ui, sans-serif"
    fontSize: "11px"
    fontWeight: 500
    lineHeight: 1
  data:
    fontFamily: "JetBrains Mono, ui-monospace, monospace"
    fontSize: "0.93em"
    fontWeight: 400
    letterSpacing: "0"
    fontFeature: "tnum"
  micro:
    fontFamily: "Zen Kaku Gothic New, system-ui, sans-serif"
    fontSize: "10.5px"
    fontWeight: 400
rounded:
  none: "0"
  ring: "50%"
spacing:
  hairline: "1px"
  row: "5px"
  sm: "8px"
  md: "10px"
  lg: "12px"
  gutter: "16px"
  top-bar: "48px"
  dock: "68px"
  km-track: "64px"
components:
  button:
    backgroundColor: "{colors.plate}"
    textColor: "{colors.ink}"
    rounded: "{rounded.none}"
    padding: "0 12px"
    height: "28px"
    typography: "{typography.row}"
  button-primary:
    backgroundColor: "{colors.workshop-blue}"
    textColor: "{colors.blue-ink}"
    rounded: "{rounded.none}"
    padding: "0 12px"
    height: "28px"
  button-primary-held:
    backgroundColor: "transparent"
    textColor: "{colors.workshop-blue}"
    rounded: "{rounded.none}"
    padding: "0 12px"
    height: "28px"
  button-disabled:
    backgroundColor: "{colors.module-fill}"
    textColor: "{colors.ink-muted}"
    rounded: "{rounded.none}"
    height: "28px"
  icon-button:
    backgroundColor: "transparent"
    textColor: "{colors.ink-muted}"
    rounded: "{rounded.none}"
    size: "30px"
  more-link:
    backgroundColor: "transparent"
    textColor: "{colors.workshop-blue}"
    padding: "0"
  section-tab:
    backgroundColor: "transparent"
    textColor: "{colors.ink-muted}"
    padding: "0 13px"
    typography: "{typography.body}"
  section-tab-active:
    backgroundColor: "transparent"
    textColor: "{colors.ink}"
    padding: "0 13px"
  module:
    backgroundColor: "{colors.module-fill}"
    textColor: "{colors.ink}"
    rounded: "{rounded.none}"
    padding: "8px 10px 9px"
  module-title:
    textColor: "{colors.ink-muted}"
    typography: "{typography.label}"
  agent-dock:
    backgroundColor: "{colors.module-fill}"
    textColor: "{colors.ink}"
    padding: "0 16px"
    height: "68px"
  tool-chip:
    backgroundColor: "transparent"
    textColor: "{colors.workshop-blue}"
    rounded: "{rounded.none}"
    padding: "1px 6px"
  state-mark:
    typography: "{typography.state}"
  ring-label:
    backgroundColor: "{colors.workshop-blue}"
    textColor: "{colors.blue-ink}"
    rounded: "{rounded.none}"
    padding: "2px 8px"
  switcher:
    backgroundColor: "{colors.module-fill}"
    textColor: "{colors.ink}"
    rounded: "{rounded.none}"
    height: "30px"
    width: "220px"
  vehicle-row:
    backgroundColor: "{colors.module-fill}"
    textColor: "{colors.ink}"
    rounded: "{rounded.none}"
    height: "96px"
  vehicle-row-hover:
    backgroundColor: "{colors.plate}"
---

# Design System: Atelier

## Overview

**Creative North Star: "The Parts Catalogue Page"**

The Atelier is read like a printed parts catalogue: hairline-ruled modules where every vehicle is known by what is fitted on it and what is due. It descends from Japanese high-density web discipline: plainly titled boxes, a compact gothic sans at 11 to 14px, mono figures for every number, and only a few large type sizes, each one an answer (the job that is due, when it is due). It refuses both the showroom (a hero car on a gradient) and the SaaS dashboard (KPI cards with soft shadows).

Density is disciplined by one rule: one job per screen. Each section answers one question, lists stop short and hand off to the section that owns the rest, and the page is the only thing that scrolls. The ground is dark graphite by default, with a light variant that keeps the same colour roles for a garage bay under LED glare; the theme is stamped on `<html data-theme>` and persisted in `localStorage` (`mpa.theme`). Every surface is flat. Modules are outlined by 1px hairlines over the ground, and depth comes from the lit plate behind a 3D render and from the z-axis section switch, never from shadows.

Imagery is live 3D, drawn as edges only. Low-detail vehicle and part meshes render as ink line work, with the region in focus picked out in the interface blue. Each mesh sketches itself on when it arrives, stroke by stroke, outward from that focus. One muted workshop blue carries everything interactive: controls, the agent, running actions, focus. Green, amber and red appear only on state, and every state also carries a shape and a word.

**Key Characteristics:**
- One job per screen; the page is the only scroller.
- Hairline modules over a graphite ground, square corners, no shadows.
- One interface blue; state colours reserved for state, always paired with a shape and a word.
- Mono tabular figures with French narrow no-break thousands (`247 388 km`).
- Live 3D drawn as confident ink strokes on a lit plate, sketched on as they arrive.
- A permanent agent dock at the bottom (orb, running action, one input) on every screen, with the agent's proposal as a tray above it.
- Four section tabs as the only tabs; switching is a depth transition.

## Colors

A near-neutral graphite scale carries the structure, one muted blue carries interaction, and three ISO-3864-style state hues stay out of the chrome. Every token has a dark and a light value under one `--w-*` name in `dashboard/src/workshop/tokens.css`.

### Primary
- **Workshop Blue** (workshop-blue; light-blue in the light theme): the only interface colour. Primary buttons, the active tab underline, focus outlines, selection, row links on hover, "+ N →" links, the "Agent" label and marker, the running-tool chip, the dock's progress line, the orb while it listens or works, the cost view's priced-parts bars, the agent's highlight on a document page, the selected part tile and the open document's preview, the agent's ring, leader and label on the 3D car, focused edges on cars and parts, the selected km tick, the titles of modules the agent summoned, and the projection slot's scan line. Text on a blue fill uses **Blue Ink** (blue-ink / light-blue-ink).

### Tertiary (state only)
- **Fitted Green** (fitted-green / light-fitted-green): done or fitted. Always drawn with a filled square.
- **Due Amber** (due-amber / light-due-amber): due soon or due now. Always drawn with a hollow square.
- **Overdue Red** (overdue-red / light-overdue-red): overdue or unsafe. Always drawn with a doubled, struck square. It also inks the part renders of an overdue job and that job's km-track label.

### Neutral
- **Graphite Ground** (graphite-ground / light-ground): the page ground and the top bar. It shows wherever modules stop, so a stack ends visibly instead of stretching.
- **Module Fill** (module-fill / light-module): every module, the agent dock and tray, the km track, the plate caption, the switcher and its dropdown, vehicle rows.
- **Plate** (plate / light-plate) and **Plate Lit** (plate-lit / light-plate-lit): the lit stage behind 3D renders, a radial from Plate Lit at the centre through Plate to Module Fill. Plate is also the resting button fill and the hover fill on vehicle rows.
- **Hairline** (hairline / light-hairline): every rule, every module outline, every row separator.
- **Ink** (ink / light-ink): primary text and the line colour of 3D edges.
- **Ink Muted** (ink-muted / light-ink-muted): module titles, secondary text, refs, units, planned and unknown state marks, icons at rest.

### Named Rules
**The One Blue Rule.** If it can be clicked, is running, or is the agent, it is blue. Nothing else is blue, and no second accent exists.

**The State Is Not Chrome Rule.** Green, amber and red never colour a frame, a heading, a button or a background. They appear only on a state mark, a km tick label, or an overdue part render.

**The Same Roles Rule.** The light theme redefines values, never roles. A new token gets a dark and a light value under the same name.

## Typography

**Body Font:** Zen Kaku Gothic New (with system-ui, sans-serif), self-hosted via @fontsource in weights 400/500/700
**Data Font:** JetBrains Mono (with ui-monospace, monospace), weights 400/500

**Character:** A compact Japanese gothic gives the page its catalogue density. The mono carries every figure (km, dates, prices, refs, durations) in tabular numerals, so columns align and read like a parts list.

### Hierarchy
- **Headline** (700, 22px, 1.12, -0.01em): the job heading the projection slot ("Embrayage"). One per screen.
- **Title** (700, 18px, 1.25): the agent's proposal head (max 48ch, balanced) and the "Parc" title.
- **Figure** (mono 500, 18px, 1.2): the countdown beside the slot's job ("dans 2 000 km").
- **Name** (700, 14px): the vehicle name on a parc row; the wordmark is 14px/700 at 0.06em.
- **Body** (400, 13px, 1.35): the root size. Section tabs and the parc-row reading are 13px; the command input is 14px.
- **Row** (400, 12.5px): list rows, key/value rows, buttons, the switcher, the last-visit line. Refs and dims inside rows drop to 11.5px muted.
- **Label** (700, 12px, Ink Muted): module titles and in-module group titles, plain, sentence case.
- **State** (500, 11px): the word in a state mark.
- **Micro** (10.5px): km-track labels, keyboard hints, the "vérifié" tag, part names under renders, "silhouette générique".

### Named Rules
**The Mono Figures Rule.** Every number a mechanic would read off a gauge or an invoice is set in the data face with tabular numerals, and thousands are separated by a narrow no-break space (U+202F, `THIN` in `dashboard/src/format.ts`). An ASCII space there is a defect. Values carry locale decimals and unit symbols ("5,4 L", "965 g").

**The Few Large Figures Rule.** Sizes above 14px are reserved for the one job a screen leads with, its countdown, a proposal head, and the page title. Everything else stays between 10.5 and 14px.

**The One Casing Rule.** Job labels render through one helper in sentence case ("Embrayage"), everywhere a job is named: the slot, lists, Pièces groups, parc rows, the km track. Catalog keys are labelled in the reader's language ("Capacité d'huile de boîte", "Code moteur"), never as raw keys.

## Layout

A sticky top bar (48px) sits above the content and the agent dock (68px, plus the safe area on phones) is fixed at the bottom; the page pads its bottom by the dock and, when one is up, the CTA tray (measured, exposed as `--w-dock-space`). The page itself is the only scroller: no module, list or panel scrolls internally. A list that runs long stops and ends with a blue "+ N →" link to the section that owns the rest (or a muted "+ N" note where no section owns it).

**Car page, split section (Entretien).** Two equal columns separated by 1px. On the left, the lit car plate is sticky and fills the viewport height under the bars (minus the km track on Entretien), with a 32px caption strip (name, engine in mono, the reading pushed right). On the right, one content-sized stack of outlined modules; the ground shows below it.
- *Entretien:* the projection slot (by default the most urgent job: its heading, state and "planifiée" marks with the countdown on one line, its first three parts and "+ N pièces →" to Pièces, then the actions), À faire (other overdue or now jobs, only when there are any), Bientôt (the next three, the contrôle technique folded in by date), and the last visit on one line. When the slot holds something summoned, every overdue or now job stays under À faire. The km track is a 64px strip, sticky just above the dock, on Entretien only.

**Pièces (slice 2).** No car plate. On phones: the cost view collapses to one line (Dépensé and a 36px bar strip), the shelves follow, and the inspector opens only when a part is selected, as a bottom sheet over a scrim (portalled out of the section planes, which sit in a perspective), closed by ✕ or Escape. Two columns: the shelves (left, ~62%) under the cost view (≤180px), and the part inspector (right, ~38%, min 360px), sticky under the top bar at viewport height above the dock. Shelves: "À commander · {job}" (one per due job), then "Remplacées le {date} · {garage} · {km} · {visit}" (one per visit, newest first). A summoned slot that names no shelf part (a frame for an unknown task, a history) shows between the cost view and the shelves; a showParts / revealAssembly frame that matches a part selects it instead. Below 1200px it stacks: cost view, slot, inspector, shelves.

**Documents (slice 2).** The open document is the stage: centred (max 1100px), filling the height the strip leaves above the dock, only its page column scrolls. Below it, kind filter chips (when there are two kinds or more) and a horizontal strip of first-page previews. With nothing opened yet, the newest document is on stage. The dock's placeholder becomes "Chercher dans les documents…" and a submission there is sent as a document search; the showDocument frame that answers it lands on stage at the cited page with its highlight.
A blue "+ Document" more link under the kind chips (beside the "no documents" line when there are none) opens an add form above the strip, built like the hand editors (an outlined Module Fill editor with a ✕): a file button (PDF, JPEG or PNG), the kind (manual, technical note, quote, other), an optional title and an optional mono reference, then a primary "Ajouter" that reads "Envoi…" while the upload runs; a failure stays in the form under it, a success closes it and the list re-reads. Bills are not added here: on a visit that exists, "+ Facture" sends each picked file at once (its row reads "envoi…" or the failure); on a new visit they wait, labelled "envoyée à l'enregistrement de la visite".

**Caractéristiques (slice 2).** A datasheet mosaic (max 1440px, 16px gutters): system boxes packed edge to edge in three hairline-ruled columns (`columns: 3 380px`), each box led by its tier-1 figures. Modules the agent summoned (spec nodes) sit above, their title in blue; facts it spotlit get a blue outline and label.

**Parc (home).** A head line (title and mono count; the agent's state lives in the dock), then one 96px row per vehicle: a 220px low-detail edge render, name and mono plate ("silhouette générique" under it when the body is a stand-in), the next job with its state mark and countdown, and the mono reading right-aligned in a 120px column. J and K move between rows. Below, 16px down, "Échéances du parc": up to eight rows, leaving out each vehicle's own headline job, hidden when empty. A vehicle's next job is the car page's own most urgent job (one derivation), so the two never disagree.

**The Fixed Repères Rule.** Only the projection slot swaps content cinematically. Every other module keeps its place, so the eye can return to the same spot.

**Rhythm.** Modules are outlined by 1px hairlines and sit 1px apart. Modules pad 8px 10px 9px, with titles 6px above the body; the projection card pads 9px 14px 12px. Rows are 5px top and bottom, separated by hairlines. Inline gaps are 8, 10 or 12px. The page gutter is 16px.

**Responsive.** Below 1200px the split stacks: the car plate takes 40vh (min 260px) above the stack and stops being sticky; the tabs wrap to a full-width row of equal tabs; the ring's leader shortens to 22px. Below 720px, the "Parc" link text is visually hidden, tab number keys hide, the command status wraps under the input, parc rows put a 120px render beside name and job, panels pad 12px 16px, and the km track is no longer sticky, its rail drops the end labels and keys, and only overdue and selected ticks keep their labels.

## Elevation & Depth

The system has no shadows. Nothing in the workshop stylesheet declares a `box-shadow` (the one `box-shadow: none` exists to strip the legacy document viewer). Depth comes from three devices:

- **The lit plate.** A radial gradient (Plate Lit → Plate → Module Fill) behind every 3D render; parc rows and part frames use the same radial, fading to transparent or to Plate. It is the only gradient in the system.
- **The z-axis section switch.** Section planes live in a 1400px perspective. The front plane recedes to `translateZ(-110px)` and fades while the target comes forward from the same depth, over 320ms on the system ease-out. Nothing of the receded planes is visible at rest.
- **Sticky layering by z-index**: the top bar (40), the agent dock and tray (35), a document opened from the parc (32), the km track (25), the switcher dropdown (60). These are flat surfaces separated by hairlines, without any lift.

### Named Rules
**The Flat Lattice Rule.** A surface separates from its neighbour by a 1px hairline or a 1px gap, never by a shadow, a glow or a tint step beyond Module Fill / Plate.

## Motion

Motion explains state and points; it never decorates. Every transition runs on the system ease-out `cubic-bezier(0.16, 1, 0.3, 1)`; the 3D frame loop uses its exponential equivalent (`easeOutExpo`, `workshop/turntable.ts`).

- **The focal moment: the agent pointing.** When the agent (a frame, a narrated step that names a job) or a click (a km tick) aims the ring at a region, the car stage plays one sequence: the camera swings its azimuth so the region faces the viewer (500ms; it stays on the region's side of the car and never goes past 0.95 rad from that side's square-on view, so the car stays a three-quarter view), then the ring draws itself closed (stroke-dashoffset, 300ms) while its leader and label fade in (150ms, from 150ms), and the strokes inside the focus sphere redraw in Workshop Blue along their own length (400ms). The pen tick sounds when the ring closes (`onPoint`). The view's `focusSeq` counts aims, so a stage remounted by a section switch does not point again; a hovered part row on Pièces only rings its region, with no swing and no sound, and the 168px locator never swings.
- **The car stage is still at rest.** Drag rotates it (OrbitControls, damped, no zoom, no pan, polar 0.9–1.42); a double-click returns to the opening three-quarter view (400ms). A play/pause button at the right of the plate caption (14px drawn icon, 1.3px stroke, `aria-pressed`, "Rotation") runs a turntable at one turn per 40s, remembered per browser (`mpa.turntable`, off by default). It pauses without forgetting the preference while the car is dragged (resuming 2s after release), while a ring is on the car, while the stage is offscreen or the tab hidden. Parc rows and the locator never spin.
- **Drawing on, once.** A mesh sketches itself on (900ms, see Live edge renders) the first time its url and fit appear in the session (`shouldDraw`/`markDrawn`, `edgeDraw.ts`, marked when the draw completes); afterwards it is there at once. The pointing redraw in blue plays every time.
- **Documentation search log.** A `documentScan` frame (every `search_documentation`, `dashboard/src/workshop/docScan.ts`, `SearchLog.tsx`) drives its car in if needed, opens Documents and logs the search in place of the viewer, same footprint; no PDF loads. Left, a box page (≤44% wide, A4 ratio, hairline border): a title bar, ten text-line blocks, a figure and a table in plate tones under a mono query field. Each search types its query in (30ms a character, 900ms at most, square blue caret blinking at 1Hz), then a 1px Workshop Blue line sweeps the page top to bottom once (transform only, 700ms, system ease); as it passes, one box per hit (at most 6, spread down the text lines in rank order) lights with a 1px blue outline and a 15% blue fill and decodes into the hit's page label and a slice of its snippet, characters resolving from random glyphs left to right over 350ms (a leaf on requestAnimationFrame), the query terms in blue. Lights follow the eased line and stay at least 60ms apart. No match: nothing lights, the boxes dim to 50% after the sweep and « Aucun résultat » shows under the query. Right, the log (`role="log"`): one block per search of the turn, `› query` in mono and the count, then one row per hit (source, page label or « p. N », the snippet with the query terms marked, folded like the API), each row fading up as its box lights; older blocks stay in muted ink. Rows are buttons that open the document at that page. The log clears on a new turn or when the operator opens a document. The `showDocument` that follows ticks its row (blue border, the box fill deepens) with the point sound, holds 400ms, then opens the viewer once on that page with the agent's highlight (the cited words, not the query); a document the log does not hold opens at once. Below 720px the box page becomes a 132px band above the log with no box text. Reduced motion: no typing, sweep, decode or row fade; the query, lit boxes with their final text and the rows appear at once.
- **The section switch** recedes and advances the planes over 320ms (Elevation & Depth); the tray rises 8px over 220ms; the orb keeps its own states (Agent dock).
- **Reduced motion:** no camera swing (the ring, the blue strokes and the tick arrive at once), the double-click reset is instant, the turntable button stays visible but disabled with a title saying why, meshes draw at once, planes and buttons stop transitioning, the slot, ring and orb stop animating, and the dock's runner becomes a solid bar.

### Sound

The workshop plays a pen-and-workshop palette generated with ElevenLabs sound effects (`dashboard/public/audio/workshop-2026-11/`, `WORKSHOP_PALETTE` in `noyau/palettes.ts`); the legacy Atelier at `#/legacy` keeps the spatial set. Each sound marks one kind of event:

- **Select** (a paper slide): a vehicle change or a section tab change, whoever made it.
- **Point** (a pen tick): the ring closing on a pointed region, or a panel the agent raises. A point does not repeat inside 1.2s, so a panel and the ring it moves are one tick.
- **Proposal** (a two-note rise): an agent proposal arriving in the tray.
- **Error** (a dull thud): a stalled turn, a failed request, a failed hand edit or plan.
- **Settle** (a soft settle): a turn ending with a result.
- **Waiting bed:** a quiet loop while the agent has not answered yet. It starts only after 1.2s of a busy turn (fast answers stay silent), fades in over 600ms, fades out over 300ms as soon as the agent narrates a step, posts a line or a notice, proposes, or a documentation search is logged, or the turn ends, and drops to half its gain after 30s. It ducks under the voice like every cue.

Dismiss, mic and the opening are silent in the workshop; the opening greeting stays voice-only. A burst of frames sounds once (120ms window, the larger event wins). The preferences menu holds two switches, "Sons" (`mpa.sound`) and "Voix" (`mpa.voice.enabled`), both on by default; nothing plays before the first gesture lets the audio context start.

## Shapes

Every corner is square (0 radius): modules, buttons, inputs, the switcher, chips, ticks, the ring label, part render frames, state shapes. A single deliberate circle exists in the chrome: the agent's focus ring on the 3D car (56px, 2px blue stroke). It is the world's one pointing device, and its blue leader line and square label anchor it back to the grid. Drawn icons use circles only where the object is round (the sun).

Borders are 1px hairlines. A dashed border means "not available yet": a disabled button turns dashed and loses its fill, the "unknown" state shape is dashed, a part with no matching mesh shows a dashed placeholder glyph, and a primary held by a running agent turn becomes dashed blue.

Icons are drawn for this system on a 16px grid with a 1.5px stroke and square caps (`dashboard/src/workshop/Icons.tsx`); the turntable's play and pause are drawn at 14px with a 1.3px stroke, like the journal icon. State shapes are drawn on a 10px grid (`StateMark.tsx`).

## Components

### Buttons
Plain, square, compact controls.
- **Shape:** square, 28px high, 12px side padding, 1px hairline border.
- **Default:** Plate fill, Ink text, 12.5px/500. On hover, the border lifts to Ink Muted (120ms).
- **Primary:** Workshop Blue fill and border, Blue Ink text, 700. On hover, it mixes 14% Ink into the blue. One per action row; the slot's default primary is "Demander à l'agent".
- **Disabled:** drops the fill to Module Fill with a dashed hairline and Ink Muted text. A faded primary read as live, so the blue is removed entirely.
- **Held primary:** a primary waiting on a running agent turn keeps its identity: transparent fill, dashed blue border, blue text, 700. Beside it, an 11.5px muted "en attente de l'agent" note.
- **Icon button:** 30px square, transparent, Ink Muted icon. On hover, the icon turns Ink and a hairline appears.
- **More link:** a bare 12px/500 blue text button ("+ 6 pièces →"), underlined on hover, 6px below the list it extends.
- **Focus (all):** a 2px Workshop Blue outline with a 2px offset (-2px inside tabs and vehicle rows).

### Section tabs (Navigation)
The four sections (Entretien, Pièces, Documents, Caractéristiques) are the only tabs in the system.
- **Style:** 13px Ink Muted, with a 10px number key (1–4) before each label. They stretch to the top bar's full height.
- **Active:** Ink, 700, and a 2px Workshop Blue underline.
- **Switching:** the depth transition described in Elevation & Depth.
- **Top bar:** on the ground, a 10px square wordmark plate and "Atelier", the Parc link (its current state is a blue bottom border), the vehicle switcher, the tabs, and the theme toggle pushed right.
- **Vehicle switcher:** a 30px Module Fill box (min 220px) holding the name, mono plate and chevron. Its listbox is 380px with hairline-separated options; the active option is on Plate with an inset blue outline, and the selected name turns blue.

### Agent dock (signature, slice 2)
Fixed at the bottom of every screen, full width, 68px plus the phone safe area, Module Fill over a top hairline.
- **Orb (left, 44px):** the agent's presence sketched like the meshes: four pen strokes, each a randomly tilted great circle of a sphere with a slight wobble and an overshoot past its start (`orbStrokes`, seeded, `workshop/orb.ts`), 1.3px round-capped, never a globe of meridians. Each stroke draws itself on (900ms, staggered 140ms). It follows the noyau phase through `orbState`: idle breathes in Ink (scale 0.93↔1, opacity 0.72↔1, 4s sine); listening (noyau `listening`, or the mic arming, recording or transcribing) pulses tighter in blue (1.1s); working turns slowly (7s) while each stroke redraws and erases itself in turn (1.8s, staggered); done settles once (1.08→1, 700ms) in Ink. Transform, opacity and dash offset only; reduced motion holds each state still. The orb is the second deliberate circle in the system, after the ring.
- **Running action (next to the orb):** the action label (Ink), the tool chip, the mono elapsed time and "Annuler", in a hairline-separated block; the last outcome lingers 6s in Ink Muted. A 2px blue runner loops along the dock's top edge while a turn runs.
- **Input:** a 44px field on the ground with a 1px hairline (blue on focus), 16px text, a `/` hint, a placeholder per screen ("Demander à l'agent sur le parc / sur l'entretien…", "Chercher ou commander une pièce…", "Chercher dans les documents…", "Demander une caractéristique…"). `/` and ⌘K focus it, Enter sends. A 44px mic button (the noyau dictation: latched, a landed transcript sends itself) and a 44px send button follow. The mic disappears once the browser refuses it.
- **Phones:** the running action takes its own row above the orb and field, its label wrapping to two lines; placeholders shorten but stay scoped, each measured to fit the 192px field at 390px ("Demander sur le parc…", "Demander sur l'entretien…", "Chercher une pièce…", "Chercher un document…", "Quelle caractéristique ?"). With the tray up, tray and dock stay under a third of the viewport (224px of 844 measured).

### CTA tray (signature, slice 2)
An agent proposal (`proposeActions`) rises above the dock, aligned past the orb (72px in, max 520px): a blue top hairline and the 15px/700 headline with a close button (no "Agent" eyebrow: the orb beside it is the agent), the muted finding, then the action row (primary solid blue, the rest outlined). It enters with an 8px rise (220ms). On phones the finding folds away behind a chevron beside the close button. It persists across sections and vehicles until an action is taken, it is dismissed, or a new instruction is sent (which carries it as context). Proposals never enter the projection slot, and a proposal never changes the section.
### Modules (Cards / Containers)
- **Corner Style:** square.
- **Background:** Module Fill, outlined by a 1px hairline.
- **Shadow Strategy:** none (see Elevation & Depth).
- **Title:** a plain heading line: 12px/700, Ink Muted, sentence case, one line with ellipsis. Optional trailing content (a close button) sits on the same baseline. A module the agent summoned has its title in blue.
- **Internal Padding:** 8px 10px 9px.
- **Contents:** hairline-separated rows (main text clamped to 2 lines, mono ref, mono value pushed right; part rows keep ref and price whole and ellipsize the name), key/value lists (40% muted key column). A lit key/value row has an inset blue outline and a blue key. A "vérifié" tag is a hairline-boxed 10.5px muted word.

### State marks (Chips)
- **Style:** a 10px drawn shape plus a word, 11px/500, both in the state colour. The shape and the word always appear together.
- **Shapes:** done = filled square (green); planned = hollow square (Ink Muted); due = hollow square (amber); overdue = doubled square struck through (red); unknown = dashed hollow square (Ink Muted).

### Projection slot (signature)
The one zone that swaps, at the top of the right stack. It holds the most urgent job by default, or summoned facts, parts, a document reference, or a visit history. Proposals live in the CTA tray, never here.
- **Heading is the content:** the default card is headed by the job itself in the 22px headline, its service subtitle only when it differs. No label sits above a heading.
- **Enter:** the content rises 14px from a bottom clip over 380ms while a 1px blue scan line sweeps down the card (520ms).
- **Leave:** the content sinks 22px and fades (260ms).

### Live edge renders (signature)
- **Meshes:** every render uses the low-detail `-thumb.glb` (the full mesh only where the thumb is broken). A vehicle without its own mesh shows a shared generic body, captioned "silhouette générique".
- **Vehicles draw confident strokes only:** sharp edges above 26° on thumbs (36° full meshes, 48° motorcycles) at 0.92 opacity, no soft creases; strokes shorter than 3% of the vehicle's length dropped (1.5% for open frames); inside the wheels only strokes of at least 16% survive (tyre and rim, not spokes or tread). Closed bodies leave out strokes and fill deeper than 5% inside their hull, and draw a depth-only cabin proxy (the hull shrunk 0.97 × 0.95 × 0.84) first, so nothing behind the glass draws.
- **Parts** draw at 18°, primary edges at 0.92 and softer secondary edges at 0.26.
- **Fill:** a 0.05-opacity ink fill writes depth so back edges hide. It is an occlusion fill, never a shaded surface.
- **Colour:** Ink at rest; edges inside the focus sphere in Workshop Blue; an overdue job's parts in Overdue Red.
- **Drawing on:** edges are chained into continuous strokes; each draws along its own length on an exponential ease-out, staggered outward from the focus point (or bottom to top) across 900ms, the faint fill rising with it. Successive renders (parc rows) start 90ms apart, capped at five steps. Only the first appearance of a mesh in the session draws on (Motion); under reduced motion they draw at once; text never waits.
- **Pointing redraw:** when the agent points, the focused strokes turn blue along their own length over 400ms (a per-vertex focus flag and a reveal uniform in the stroke shader); a hover recolours at once.
- **Framing:** the camera fits the mesh's own vertices to the element's layout size, then centres the projected silhouette both ways: margin 1.18 on the stage, 1.3 on parc rows.
- **Part frames:** 40px (row), 104×84px or full-tile (shelf, 196px high) plates with their own lit radial. The mesh is chosen by the part's own words; a part no mesh matches shows a dashed placeholder, never another part's mesh.

### Shelf (Pièces, slice 2)
- **Shelf:** a 13px/700 title line (state mark + "À commander · {job}", or "Remplacées le {date}" with muted garage, reading and visit), then a grid of tiles (`minmax(196px, 1fr)`, 12px gaps, 268px rows). Each row stands on a 1px Ink Muted shelf line (a repeating gradient at the row pitch, so wrapped rows get their own line).
- **Tile:** name (13px/700, two lines), mono ref and price, then the part's live edge render on its lit plate, standing on the line. Hover outlines the plate in Ink Muted; the selected tile's plate is outlined and its edges and name drawn in blue. An overdue job's parts draw in Overdue Red. A part no mesh matches keeps the dashed placeholder, at 40px in the tile.
- **Keys:** the tiles are one roving tab stop: arrows move across tiles (left/right in reading order, up/down to the shelf above or below, same column clamped), Enter or click selects, Escape inside the inspector returns focus to the selected tile (`workshop/shelf.ts`).

### Part inspector (Pièces, slice 2)
Sticky, Module Fill, outlined, viewport height above the dock. Top: the selected part large on the lit plate, drawn in blue, drag to rotate (OrbitControls, no zoom, no pan, no auto-spin). Then the name (20px/700), boxed tier-2 spec tags (Réf., Marque, Qté, Prix, Fournisseur), the state line ("À commander pour {job}" with its state mark, or "Posée le {date}" + mono reading), and the actions: "Demander à l'agent" (prefills the dock with the part) and, for a part to order whose job is not planned yet, "Planifier". Bottom: a 168px car locator, the vehicle's edge render with the agent ring on the part's region. Default selection: the first part to order, else the most recent fitted part.

### Cost bars (Pièces, slice 2)
A compact view (≤180px) above the shelves: figures left, chart right.
- **Figures:** one 26px mono figure, spent over the period; beside it, smaller inline mono figures: the visit count, the parts share (priced parts over the bills of fully split visits that logged parts) and the cost per 1 000 km. That rate prices the span between the first and last km reading: the bills dated after the first reading up to the last one, over that distance (the first visit's bill paid for the km before it).
- **Chart:** one bar per visit at its date on a time axis (month ticks under 400 days, years beyond), up to 16px wide, square ends (the system has no radius). Each bar stacks priced parts (Workshop Blue, at the baseline), then the rest of the visit bill (`--w-chart-rest`, Ink Muted mixed down: #6c6f75 dark / #82868d light), then, where some parts carry no price, the non-attributable remainder as a 45° hatch of the same grey ("Non ventilé"). Segments are separated by a 2px gap. A bar whose split is incomplete (no bill, unpriced parts, or parts priced above the bill) carries a dashed cap, keyed in the legend as "Ventilation incomplète". Each bar with room is labelled with its total above and its date under it (newest first, never closer than 64px, so labels never drift off their bar). Hovering or focusing a bar turns that visit's shelf title blue; clicking it (or Enter) scrolls to that shelf and focuses its first part. No split is ever invented (`workshop/costs.ts`).
- **Reading it:** a legend for every series drawn, hairline gridlines at 0, half and the rounded maximum, a hover / focus tooltip per bar (date, visit, each segment, total, "ventilation incomplète"), and a visually hidden table of every visit. The two fills were run through the dataviz validator on both surfaces: CVD and normal-vision separation and the lightness band pass; the grey fails the chroma floor by design (it is a de-emphasis neutral, not a category), which the legend and table cover.

### Datasheet tiers (Caractéristiques, slice 2)
Facts are grouped by system (Identité, Moteur, Transmission, Fluides, Pneus & roues, Freinage, Électricité, Homologation et fabrication, Autres) and tiered by key family (`workshop/datasheet.ts`):
- **Tier 1, the figures a mechanic reads first:** plate, VIN, engine code, power, front tyre size, battery capacity, and the engine oil folded into one tile (viscosity, or the oil spec when no viscosity is known, · capacity). A Plate tile, 12.5px/700 muted label above a 30px mono figure that never breaks inside a word; a value longer than 28 characters drops to tier 2.
- **Tier 2:** capacities, fluid specs, codes, main part references, carte-grise basics. A boxed tag, 11.5px label over a 15px mono value; a fluid's type and capacity share one tag under the fluid's name ("Huile de boîte: SAE 75W-80 | 2,4 L"); past 48 characters it drops to tier 3.
- **Tier 3:** notes, alternatives, OE cross-references, weights, unknown keys (which land in Autres). A small inline tag, 12.5px label and value; a sentence is set in the text face, not mono.
Each tier is its own row inside a box. Agent-verified catalogue values keep their "vérifié" tag, except a fact whose alternatives differ (TecDoc's per-variant capacities "3 | 39 | 3,25 L"): it reads "varie selon la variante" in the same 12.5px muted fold in every tile (tier 1 included: the known spec stays at figure size, only the varying capacity folds), its values open on demand at 12.5px (a `details`, `splitVarying`), and it is never stamped verified. Facts stated twice are shown once (stated power over bare kW, carte-grise displacement over the user's, "essence" over "ES", one OPR). Identifiers (VIN, CNIT, DAM, OPR, APV, codes, references) are shown as written, never thousand-grouped. Boxes are packed into up to three columns by measured height, tallest first into the shortest column, each column then back in system order (`packColumns`).

### Document stage and preview strip (Documents, slice 2)
- **Stage:** Module Fill, outlined; a 44px head (16px/700 title, muted kind, "Télécharger"), a toolbar (page field with the manual's own labels, prev/next, zoom, fit, find with count and prev/next: 28px square controls on Plate, dashed when disabled), then the page column on the ground with white pages outlined by a hairline. The agent's highlight and find hits are blue washes on the page (28%), the current one stronger with a 2px blue outline. Images open at a legible fit, whichever of width or height gives the larger picture (never past their own pixels), and pan by drag. Toolbar controls are drawn icons from `Icons.tsx` (chevrons, minus, plus, fit width, fit screen, up/down arrows), shared with the legacy viewer. The panes are shared with the legacy viewer through a class prefix (`scene/documentPanes.tsx`, `DocSkin`); the workshop path no longer loads `documentviewer.css`.
- **Strip (≈120px):** kind chips stacked on its left (22px, square, blue when on), then 112px cards: a 60px first-page preview (rendered client-side from the PDF, one document at a time, skipped above 25 MB; images shown as is; otherwise a document glyph), the title (a manual's section name after its " — "), the kind, mono date and page count. The open one has a 2px blue outline and a blue title. Arrows move between cards, Enter opens. The strip and the page column use themed 6–8px scrollbars without arrow buttons (the root's standard `scrollbar-color` is reset there so the `::-webkit-scrollbar` rules apply).

### Agent ring (signature)
A 56px blue circle drawn closed as a 2px stroke (stroke-dashoffset, 300ms), a 1px blue leader (46px, 22px when stacked), and a square blue label with Blue Ink text at 11.5px/700, both fading in over 150ms as the circle closes. It marks the region the agent names, or the region of the hovered part row on Pièces. When pointed, it waits for the camera to face its region (Motion).

### Km track (signature)
A full-width 64px sticky strip at the bottom of Entretien.
- **Axis:** a 1px hairline, with mono end readings. The done run is a 3px Ink Muted bar at 55%.
- **Today:** a 1px Ink marker labelled "aujourd'hui" and the reading at 10.5px.
- **Ticks:** 15×19px buttons carrying a state shape. Labels alternate above and below at 10.5px muted; due and overdue labels take their state colour. Estimated ticks drop to 70%. An overdue tick stacks above its neighbours.
- **Selected tick:** a blue border; its label turns Ink 700 with a blue outline.
- **Keys:** J and K hints at the right.

### Vehicle rows (Parc)
- **Row:** 96px, outlined, Module Fill; hover Plate (120ms); focus a 2px inset blue outline.
- **Columns:** 220px render on a lit radial · name (14px/700) over a muted mono plate · next job (state mark, 500 label, mono countdown) · mono reading right-aligned in 120px.

## Do's and Don'ts

### Do:
- **Do** take every colour from the `--w-*` tokens in `dashboard/src/workshop/tokens.css`, and define any new token for both `dark` and `light`.
- **Do** give each screen one job, let the page be the only scroller, and end a long list with a blue "+ N →" to the section that owns the rest.
- **Do** outline modules with a 1px hairline over the ground, and let the ground show where a stack ends.
- **Do** title a module with a plain 12px/700 Ink Muted heading line, or head the slot with its content.
- **Do** pair every state colour with its drawn shape and its word (`StateMark`).
- **Do** set every km, date, price, ref and duration in JetBrains Mono with tabular numerals and U+202F thousands.
- **Do** render vehicles and parts as live low-detail edge meshes on a lit plate, sketching on as strokes over 900ms from the focus the first time they appear in the session. Show them complete at once afterwards and under `prefers-reduced-motion`.
- **Do** keep the agent dock on every screen, showing the orb, the running action, the tool, the elapsed time and a cancel control; keep a pending proposal in the tray across navigation.
- **Do** use `cubic-bezier(0.16, 1, 0.3, 1)` for every transition, and give each animation a reduced-motion path (planes and buttons stop transitioning, the slot and ring stop animating, progress becomes a solid bar).

### Don't:
- **Don't** add tabs or tab chips to modules. The four section tabs are the only tabs (user decision, 2026-11-04).
- **Don't** show stacked layer edges for the section planes. The switch is a depth transition, with nothing visible at rest (user decision, 2026-11-04).
- **Don't** scroll inside a module, list or panel; the exceptions are the document stage's page column and the horizontal preview strip.
- **Don't** put a label above a heading. The slot's card is headed by its job; the tray by the proposal itself.
- **Don't** use green, amber or red on chrome, headings, buttons or backgrounds, and never use red as an accent.
- **Don't** introduce a second interface colour, a cyan HUD glow, a hologram treatment, or a gradient other than the lit plate.
- **Don't** use `box-shadow` or rounded corners. The agent's ring and the dock orb are the only circles.
- **Don't** texture, shade or photo-render a mesh, and don't draw soft creases, hatching or interior strokes on a vehicle. Edges in Ink, focus in Workshop Blue, overdue parts in Overdue Red.
- **Don't** use icon glyphs or icon fonts. Draw icons on the 16px, 1.5px-stroke, square-cap grid in `Icons.tsx`.
- **Don't** fade a disabled primary. Remove its blue fill and dash its border instead.

## Known open items

- The orb does not follow the mic level: `useDictation` exposes no level, so listening is a fixed pulse.
- Fact keys outside `FACT_LABEL` still fall back to humanised English, visible in the datasheet's tier-3 tags (the build plate keys are now labelled).
- "Planifier" in the part inspector cannot appear today: a part to order always comes from an open task, so its job is already planned; the inspector shows the "planifiée" mark there instead.
- Live data holds no visit bill attachment, so a bill on stage has only been seen with the fixture's (unbacked) row; the stage was verified on manuals and on a photographed quote.
- The optional faint scan line over a mesh sketching on (re-layout brief) is not built; the only scan line is the projection slot's.
- `Module` still accepts a `tone` prop that renders `w-mod-title--blue`; no caller passes it and no rule styles it.
- `workshop.css` keeps the slice-1 rules and overrides them further down (`.w-mod` padding, `.w-mod-head`, `.w-car` inset, `.w-track` columns and rail margins, `.w-tick` width, `.w-plane.is-front`, `.w-root` grid), and still carries selectors no markup uses (`.w-plane-grid`, `.w-mosaic*`, `.w-area-*`, `.w-home-grid`, `.w-home-lists`, `.w-home-docs`, `.w-car-mod*`, `.w-figure`). `--w-tab-h` survives only in those overridden rules.
