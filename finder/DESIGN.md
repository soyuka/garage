---
name: finder
description: Feuille de dessin. A parts finder that answers on an ISO engineering drawing sheet.
colors:
  drafting-blue: "#2b59c3"
  drafting-blue-ink: "#1f4597"
  graphite: "#222428"
  paper: "#ffffff"
  panel: "#e8eaee"
  hairline: "#d5d8de"
  mute: "#4f5460"
  warn: "#8a4b00"
  warn-tint: "#fbf1e4"
  error: "#b3261e"
  error-ink: "#8f1d16"
  error-tint: "#fbeceb"
  error-line: "#e3b1ad"
  mute-2: "#6b7080"
  mute-3: "#8a8f9a"
  line-2: "#c9cdd6"
  panel-2: "#f3f4f7"
typography:
  display:
    fontFamily: "IBM Plex Sans, system-ui, sans-serif"
    fontSize: "32px"
    fontWeight: 600
    lineHeight: 1.1
    letterSpacing: "-0.03em"
  reference:
    fontFamily: "IBM Plex Mono, ui-monospace, monospace"
    fontSize: "32px"
    fontWeight: 600
    lineHeight: 1.15
    letterSpacing: "-0.02em"
  headline:
    fontFamily: "IBM Plex Sans, system-ui, sans-serif"
    fontSize: "17px"
    fontWeight: 600
  body:
    fontFamily: "IBM Plex Sans, system-ui, sans-serif"
    fontSize: "14px"
    fontWeight: 400
    lineHeight: 1.4
  data:
    fontFamily: "IBM Plex Mono, ui-monospace, monospace"
    fontSize: "13px"
    fontWeight: 500
    fontFeature: "tnum"
  view-label:
    fontFamily: "IBM Plex Mono, ui-monospace, monospace"
    fontSize: "11.5px"
    fontWeight: 500
    letterSpacing: "0.04em"
  label:
    fontFamily: "IBM Plex Sans, system-ui, sans-serif"
    fontSize: "11px"
    fontWeight: 500
    letterSpacing: "0.02em"
rounded:
  tag: "3px"
  row: "4px"
  control: "6px"
  card: "8px"
spacing:
  gutter: "24px"
  gutter-mobile: "16px"
  cell: "10px 14px 11px"
  sheet-pad: "20px"
components:
  button-primary:
    backgroundColor: "{colors.graphite}"
    textColor: "{colors.paper}"
    rounded: "{rounded.control}"
    padding: "0 14px"
    height: "36px"
  button-secondary:
    backgroundColor: "{colors.paper}"
    textColor: "{colors.graphite}"
    rounded: "{rounded.control}"
    padding: "0 14px"
    height: "36px"
  button-secondary-hover:
    backgroundColor: "{colors.panel}"
  title-block-cell:
    backgroundColor: "{colors.paper}"
    textColor: "{colors.graphite}"
    padding: "{spacing.cell}"
  title-block-why:
    backgroundColor: "{colors.panel}"
    textColor: "{colors.graphite}"
    padding: "{spacing.cell}"
  tag:
    backgroundColor: "{colors.panel}"
    textColor: "{colors.graphite}"
    rounded: "{rounded.tag}"
    padding: "0 7px"
  tag-ok:
    backgroundColor: "{colors.paper}"
    textColor: "{colors.drafting-blue}"
    rounded: "{rounded.tag}"
    padding: "0 7px"
  tag-warn:
    backgroundColor: "{colors.warn-tint}"
    textColor: "{colors.warn}"
    rounded: "{rounded.tag}"
    padding: "0 7px"
  input-hero:
    backgroundColor: "{colors.paper}"
    textColor: "{colors.graphite}"
    rounded: "{rounded.control}"
    height: "54px"
  part-tab-selected:
    backgroundColor: "{colors.graphite}"
    textColor: "{colors.paper}"
    height: "36px"
    padding: "0 14px"
---

# Design System: finder

Scope: the `finder/` surface only. The repo-root `DESIGN.md` and `PRODUCT.md` describe the Atelier (night garage, JetBrains Mono, desktop-only); none of their visual rules apply here. Two product principles carry across verbatim: *no false precision* and *mono is data*.

Token source of truth: `finder/src/styles.css:1-17` (`:root`), `finder/src/scene/palette.ts` (WebGL colours and line weights), `finder/src/format.ts` + `finder/src/dims.ts` (every printed number).

## 1. Overview

**Creative North Star: "Feuille de dessin"**

The app is an ISO engineering drawing sheet that works. White paper, graphite frame, drafting-blue linework: the answer to "which part?" is a drawn part with its cotes and a title block, not a product photo in a card grid. The sheet is the page; the title block is the result card; the drawing is the product view. TecDoc photos were dropped from the build and do not come back.

Density is that of a drawing: thin rules, labelled cells, tabular figures, no decoration that is not a drafting device. Home (revised 2026-10-15, corrected same day) = a public showcase (vitrine) written for DIY owners first: headline `La réf. d’origine et ses équivalences.`, a lede saying what to type and what comes back, and three example chips under the composer (`STARTERS` in `src/home.ts`: two free searches that run in one click, `Photo de la carte grise` opening the file picker). Logged in, the recent searches take the space below (up to 10 rows). No real plate anywhere on the home (`src/home.test.ts`), and neither the catalogue nor the shops are named in the hero. *Was (morning of 2026-10-15):* the question `Quelle pièce, pour quel véhicule ?` with `Saisissez la plaque ou le VIN, puis la pièce.`, which `.impeccable/review/clarify.md` had kept "on purpose". *Why reversed:* user decision. The question only said what to type, never what comes back; final copy and rationale in `.impeccable/review/homepage-copy.md`. *Was:* an empty `PLANCHE` with a blank title block under the question, added after the finish review to give the entry the drawing-sheet world. *Why reversed:* without explanation it read as an empty table; history now owns the home. French UI throughout (`index.html` `lang="fr"`), French number formatting with narrow no-break space (`format.ts:1`, `formatNumber`).

Layout, as built (revised 2026-10-15): `100dvh` grid of 56px top bar / scrolling main / dock (`styles.css` `.app`, `.main`, `.dock`). The dock is always on screen at the bottom, on every width: agent status line, the open question (agent `ask_question` or the `differsOn` question) with its answer buttons, quick actions, then the composer, then on the home only the example chips (`ui/Dock.tsx` `Starters`). Workspace = the sheet alone; the narrowing trace is a drawing note in the sheet's top-left corner (`.sheet-notes`) and moves under the sheet when it stacks. The title block overlays the sheet's bottom-right corner at 520px when the sheet is at least 812px wide (`App.tsx` `SHEET_OVERLAY_MIN`); otherwise sheet, trace and title block stack. ≤759px: 16px gutter, the vehicle chip wraps to its own row. The journal docks as a 480px right column at ≥1280px spanning main and dock, a 580px overlay drawer below that.
*Was (until 2026-10-15):* a 288px left aside (trace, question, recent searches), the input in the top bar (hero input on the homepage) and a 28px footer. *Why reversed:* the user decided the composer must always be visible and continue the current search; a question belongs next to where it is answered.

**Key Characteristics:**
- Hidden-line 3D part (`scene/HiddenLine.tsx`, `scene/Blueprint.tsx` low-FOV perspective camera) is the ONLY product view: orbit on both axes around the part, pan, zoom, slow idle turntable.
- ISO 128 line weights, ISO 129 dimension placement, chain centre lines.
- Title block = result card; drawn cotes and title-block cotes come from one formatter.
- One composer (text, photo, PDF, video) docked at the bottom of every screen.
- Flat paper; shadows only on things that float above the sheet.

## 2. Colors: The Drafting Palette

Paper, graphite and one drafting blue. Blue is ink for drawn and measured things, not a brand fill.

### Primary
- **Drafting Blue** (`drafting-blue`): every drawn line (part outline, dimensions, leaders, chain lines, base view), every dimension value, the trace result row, the `ok` tag, selection, focus ring, caret, links. Also the WebGL `DRAFT_BLUE` (`palette.ts:2`).
- **Blue Ink** (`drafting-blue-ink`): blue text that must hold body-size contrast on paper (title-block source link, agent suggestion label).

### Neutral
- **Graphite** (`graphite`): text, sheet frame, title-block rules, primary buttons, selected part tab. `INK` in `palette.ts:3`.
- **Paper** (`paper`): the page and the 3D part's occluding fill (`PAPER`, `palette.ts:1`); that fill is what makes hidden lines hidden.
- **Panel** (`panel`): the "Pourquoi cette pièce" cell, hover rows, tags, plate chip, login and question cards.
- **Hairline** (`hairline`): secondary rules (top bar, aside dividers, list rows), quiet borders, scrollbars.
- **Mute** (`mute`): cell labels, metadata, hints. **Mute 2** (`mute-2`, `--mute-2`): placeholders, secondary icons. **Mute 3** (`mute-3`, `--mute-3`): quiet captions and separators.
- **Line 2** (`line-2`, `--line-2`): disabled text and the one border stronger than hairline. **Panel 2** (`panel-2`, `--panel-2`): the skeleton sheen highlight.

### Status
- **Warn** / **Warn Tint** (`warn`, `warn-tint`): degraded answers, quota near limit, `plafonné` tags.
- **Error** (`error`, `error-ink`, `error-tint`, `error-line`; `--error*`): field errors and icons (`error`), text on the error tint (`error-ink`), error card fill and border (`error-tint`, `error-line`). With warn, the only non-blue signal colours in the token layer.

### Named Rules
**The Ink Is Blue Rule.** Anything drawn or measured on the sheet is drafting blue; anything written about it is graphite. A blue element that is neither a drawing, a value, an interactive affordance nor a confirmed result is wrong.

**The Paper Fill Rule.** The 3D part is filled with paper colour, never shaded. Depth comes from occlusion and line weight, not lighting.

## 3. Typography

**Body Font:** IBM Plex Sans (with system-ui)
**Data Font:** IBM Plex Mono (with ui-monospace), loaded 400/500/600 from Google Fonts (`index.html:11`)

**Character:** A technical-document pairing: Plex Sans writes the prose, Plex Mono prints the data, the way a drawing's lettering differs from its notes.

### Hierarchy
- **Display** (600, 32px, 1.1, -0.03em; 28px ≤759px): the single entry question "Quelle pièce, pour quel véhicule ?". One per page, entry only.
- **Reference** (Mono 600, 32px, 1.15; 26px ≤759px): the OE reference in the title block (`.oe`). The largest thing on a result screen is the part number.
- **Headline** (600, 17px): title-block designation, journal heading.
- **Body** (400, 14px, 1.4): prose; 13px for secondary prose, 46-60ch measures.
- **Data** (Mono 500, 13px, tabular): dimension labels on the sheet (`.dim-label`), plates, kType, references, counts, durations; 12.5px in lists.
- **View label** (Mono 500, 11.5px, +0.04em): drawing view names, `VUE 3D — FILTRE À HUILE`, `PLANCHE` (was `VUE ISO —`: a freely orbiting view is not an isometric one, renamed 2026-10-15). Upper case is in the string itself, as on a drawing.
- **Label** (500, 11px, +0.02em, sentence case): title-block cell labels (`Désignation`, `Réf. OE · MAZDA`, `Cotes`).

### Named Rules
**The Mono Is Data Rule.** Plates, VIN, references, kType, cotes, counts, times and quotas render in Plex Mono with tabular figures (`.m`, `styles.css:73-76`). Prose and brand names stay in Plex Sans; equivalence lines set the brand in Sans and the reference in Mono.

**The One Formatter Rule.** Every printed number goes through `format.ts` (`formatNumber`, `formatRange`, `formatMeasure`, NNBSP grouping). Drawn dimensions and the title-block `Cotes` line both call `dimText` (`scene/annotations.ts`, `result.ts` `cotesLine`), so the sheet and the title block can never disagree.

**The Range Rule.** When variants of a part differ, a cote prints as a range (`75–78`), never a picked value; the mesh is built at the range midpoint (`dimMid`, `dims.ts`). False precision is prohibited.

**The Never Invent Rule** (2026-10-15). A cote is drawn only when the cluster fingerprint carries it (`scene/annotations.ts`, tested in `annotations.test.ts`); otherwise there is no dimension line at all, and the title block says `non renseignées par le catalogue`. Brakes: disc Ø and thickness only for a disc category, only from the fingerprint. *Was:* a missing cote printed `≈` + a figure measured on the mesh (`FilterPart.tsx`). *Why reversed:* a mesh measurement is not catalogue data; it read as one.

## 4. Elevation

The sheet is flat paper: hierarchy comes from rule weight (1px hairline < 1px graphite < 1.5px graphite title-block and journal frame), not shadow. Shadows exist only on layers that genuinely float above the sheet, all soft, negative-spread, graphite-tinted.

### Shadow Vocabulary
- **Popover** (`box-shadow: 0 12px 32px -12px rgba(34, 36, 40, 0.28)`): suggestion list, buy menu.
- **Toast** (`box-shadow: 0 10px 28px -10px rgba(34, 36, 40, 0.5)`): graphite toast.
- **Drawer** (`box-shadow: -18px 0 48px -28px rgba(34, 36, 40, 0.45)`): journal as overlay; removed when docked.
- **Focus halo** (`box-shadow: 0 0 0 3px rgba(43, 89, 195, 0.18)`): input field focus-within only.

### Named Rules
**The Flat Sheet Rule.** Nothing on the sheet casts a shadow. If the title block or a cell looks lifted, the drawing has become a dashboard.

## 5. Components

### The Sheet (signature)
- **Frame:** 1px graphite border, paper fill, `overflow: hidden`, min-height 420px (`styles.css` `.sheet`).
- **View label:** top-left at 20/24px, view-label type. Drag hint bottom-left, 11.5px mute. Trace note under the label (`.sheet-notes`, 232px).
- **3D view** (revised 2026-10-15): perspective camera at 22° FOV (`scene/camera.ts` `FOV`), close to a drawing's parallel lines. OrbitControls target = the drawn part's bounding-box centre (the paper fills tag `userData.fit`), recomputed per part; framing = camera distance fitted to the drawing area's aspect (`fitDistance`), the canvas itself sized to the free drawing area left of the title block. Azimuth unbounded, polar 0.05–π−0.05, left-drag orbit only, damping off under reduced motion. *Was (until 2026-10-15):* pan on right-drag / two fingers, zoom to cursor, double-click ease back to the home pose (`resetPose`, `lerpPose`), hint `Glisser : rotation · clic droit : déplacement · molette : zoom · double-clic : recentrer`. *Why removed (2026-10-15):* the user asked for simplicity, a click-drag orbit around the object and nothing else; the hint now reads `Glisser pour tourner`. *Earlier:* orthographic camera shifted beside the title block by `setViewOffset` while orbiting the world origin, no pan. *Why reversed:* the user could only move the part in one direction; drags swung the offset part off-axis. Idle turntable at one revolution per 40 s (`scene/turntable.ts`), stopped for the rest of the visit by the first pointer interaction, off under `prefers-reduced-motion`. `HiddenLine` draws creases (`EdgesGeometry`, 25° threshold) plus an inverted-hull silhouette pushed out a constant number of pixels in clip space (holds under perspective), both at `LINE_VISIBLE` 1.7px, over a paper fill pushed back by polygon offset. Ghost state (loading/preview) draws at 0.28 opacity.
- **Line weights (ISO 128):** visible outline 1.7px (`LINE_VISIBLE`), dimension, extension, leader and centre lines 0.85px (`LINE_THIN`), about 2:1 (`palette.ts:5-7`). Centre lines are chain lines, ISO 128 type 04: long dash, gap, short dash, gap (`chainSegments`, `scene/Dimension.tsx`; SVG `stroke-dasharray: 14 3 2 3`).
- **Dimensions (ISO 129):** extension lines start 1.5mm off the feature and run 2.5mm past an unbroken dimension line with filled arrowheads; the filter's cotes (`FilterPart.tsx`): height and thread leader the height cote and thread leader turn continuously about the part's axis to follow the camera azimuth, damped at 8/s along the shortest arc (`dampAngle`, never across the ±π seam the long way; instant under reduced motion), Ø sits on the top face (`rimTop`, under a domed crown) when the camera is above mid-height and on the bottom face (`rimBottom`) when below (`facingFace`), the face switch has 10° hysteresis and a 150 ms fade-swap-fade (none under reduced motion), the only fade left; height spans bottom to top plane on the silhouette's right edge, thread leader runs from the facing base rim to a note outside the silhouette; arrowheads open across the view so none shows edge-on; when two labels' projected rects collide, the lower one hides (Ø > H > thread > seal, `visibleLabels`). *History:* fixed to the part until 2026-10-15 morning, then re-oriented to the camera azimuth every frame (the Ø floated off the rim at some angles), then fixed again the same day, then snapped per quadrant/face (from below the fixed Ø was read through the part), then (2026-10-15) the quadrant snap reversed because it read as a reset: with `HOME.azimuth` 0.62 a boundary sat near x = 0 and the height cote and leader jumped 90° there; the face switch stays. *Why:* the per-frame re-orientation on top of the turntable made polar drags barely read; with a perspective camera orbiting the part centre, fixed lines read as a drawing. The value is DOM text (drei `<Html>`) on a paper ground, so it always faces the viewer and stays crisp; dimensions draw over the part, never behind it. Notes use a leader to a horizontal shelf.
- **Part meshes** (2026-10-15): 55 Wrench web meshes plus the filter and brakes (`public/parts/SOURCES.md`). TecDoc category → mesh is a rule table (`family.ts` `MESH_RULES`: folded leaf / within / except regexes, first match wins, specific before generic; avant/arrière only picks the rear variant where one exists). Only the active mesh loads (Draco, `scene/MeshPart.tsx`), centred on its bounding box and drawn at a fixed span: no cotes on these meshes, the title block's `Cotes` still prints the fingerprint. An approximate match is captioned `Forme type de la famille, pas la pièce exacte` (was `Aperçu : forme générique de la famille`, reworded 2026-10-15 to say what the drawing is not).
- **Generic placeholder** (2026-10-15): a family without a mesh draws an abstract chamfered block with one bore in the same hidden-line style (`scene/GenericPart.tsx`), captioned `Pas de modèle 3D : forme générique`, never with cotes.

### Base View (removed 2026-10-15)
- *Was:* a 2D SVG `VUE A — DESSOUS` / `VUE A — FACE` inset above the title block. *Why removed:* the user wants one 3D view only; the cotes it carried now sit on the 3D part.

### Title Block (signature; the result card)
- **Structure:** 3-column grid, 1.5px graphite outer frame, 1px graphite cell rules, docked flush to the sheet's bottom-right corner (`right/bottom: -1px`) at 520px; full width when stacked.
- **Cells, in order:** Désignation · Réf. OE (reference type) · Équivalences (brand Sans 500 + ref Mono, ref copies on click) · Véhicule | kType · Cotes | Source · Pourquoi cette pièce (panel fill) · actions.
- **Cell:** label type, then value; padding 10px 14px 11px; no radius.
- **Skeleton:** panel-to-`panel-2` sheen, 1.4s linear.

### Buttons
- **Shape:** gently squared (6px).
- **Primary:** graphite fill, white text, 500 13px, 36px high, 14px side padding; hover pure black. One per action row (`Copier la réf.`, `Chercher`).
- **Secondary:** paper fill, 1px graphite border; hover panel fill.
- **Quiet:** 30px, hairline border, hover graphite border.
- **Disabled:** hairline border, grey text, `not-allowed`.
- **Focus:** 2px drafting-blue outline, 2px offset, everywhere (`styles.css:44-47`).

### Inputs / Fields
- **Composer** (2026-10-15, was hero 54px on the homepage + 34px bar in the top bar): one field docked at the bottom, 1px graphite border, 6px radius, 46px with 16px text; it clears after each send; the category suggestion list opens upward. Attach (and camera on coarse pointers) as 36px icon buttons with inline 1.5px-stroke SVG. Focus-within adds the blue halo; dragging a file over it turns the border dashed blue on panel.

### Chips and Tags
- **Vehicle chip:** 34px, hairline border, 6px radius; plate on panel inset in Mono; engine hides below 1280px, kType below 760px.
- **Tags:** 11.5px, 3px radius, panel fill; `ok` = blue outline on paper; `warn` = warn on warn tint.

### Navigation and Lists
- **Recent searches (home, logged in):** the journal's last 10 restorable searches (`history.ts`), rows of input · plate + vehicle ("Make Model engine", else the model, else `kType N`) · outcome tag · relative time (`formatRelative`, exact time on hover) on hairline rules under a graphite top rule; one click follows the server-computed route. Only user-initiated submits are journaled (revised 2026-10-15): a composer submit or chip click sends `X-Finder-Rule` + `X-Finder-Input` (the typed text) and one `X-Finder-Search` id shared by the submit's requests, which fold into one row; restores from history, hash loads, retries and background fetches send none and leave no row. A ✕ per row ("Retirer de l'historique", on hover/focus, always on touch) hides that route, and is the only control. *Was (until 2026-10-15):* an "Effacer" text button hiding every row up to now (`historyClearedAt`, `POST /agent/turns/history/clear`) with an "Annuler" toast. *Why removed (2026-10-15):* clear-all removed at the user's request; the row ✕ is enough. Hiding never deletes: the journal drawer keeps every entry.
- **Question card:** panel fill, 8px radius, question in 14px/500, answers as secondary buttons (Mono only for cluster answers), hint below.
- **Garage picker:** on a 409 from the garage, the candidates as buttons `plate · model`, plus `Nouveau véhicule` (confirmNew) and `Annuler` (`ui/Garage.tsx`).
- **Trace (sheet note "Affinage"):** label/value rows on hairline rules under a graphite top rule; the result row is graphite-ruled and blue.
- **Part tabs:** one bordered strip, hairline separators, selected = graphite fill, white text.
- **Journal:** 1.5px graphite left frame and header rule; rows hover to panel; facts in a graphite-ruled 3-column grid (2 on mobile); payloads in Mono 11.5/1.45 on hairline boxes.

### Voice (2026-10-15)
The finder writes like a parts counter or a workshop manual, not an assistant. *Why:* user feedback 2026-10-15, the copy "sounds a lot like IA/LLM". Shipped wording and its before → after table: `.impeccable/review/clarify.md`.
- **Register:** French, vouvoiement in imperatives (`Saisissez la plaque ou le modèle, puis la pièce.`), impersonal everywhere else. Never `Je`, never `l’agent` in user-facing copy: the screen says what is happening (`Lecture de la demande…`, `Recherche dans le catalogue du véhicule.`). The journal, a dev tool, keeps `agent`.
- **Nouns over sentences:** hints name what the input was read as (`Plaque SIV`, `VIN`, `Référence de pièce`, `Recherche libre`); status lines are a noun + ellipsis (`Affinage des références…`); labels are the drawing's (`Équivalences`, `Cotes`, `Marques (non affiné)`).
- **Placeholders are examples:** home `plaquettes de frein clio 3…` (*was* `AB-123-CD`, dropped 2026-10-15: the home shows no plate); with a vehicle `filtre à huile, W 712/73…`. No list of everything the field accepts.
- **Errors:** what happened, then what to do, two short sentences (`Plaque XB-301-ZB introuvable. Vérifiez-la ou envoyez la carte grise en photo.`). No reassurance, no cause the system cannot know.
- **Buttons name the outcome:** `Copier le lien` (was `Partager`), `Nouveau véhicule` (was `Un autre véhicule`).
- **Workshop vocabulary verbatim:** réf., OE, cote, monte (`Laquelle est montée ?`), pièce démontée, pied à coulisse, carte grise, kType, numéro d’organisation.
- **Banned:** explanatory taglines (`Un seul champ :`), filler, rhetorical questions, exclamation marks, em dashes in prose (the drawing's `VUE 3D — …` view names are lettering, not prose), title case, `IA`/`intelligent`.
- **Agent text** follows the same rules from `api/config/prompts/finder.md` `<writing>` (rationale = one sentence stating the fact that makes the part fit; questions answerable in one tap). Server status lines for finder turns are fixed impersonal nouns (`AgentMessages::finderNarration`), never the Atelier's first-person `Je…` pool.
- **No footer.** *Was:* `Catalogue TecDoc · usage privé` under the composer. *Why removed:* user decision; the `TecDoc` source tag in the title block already carries provenance.

### Motion
- Ease `cubic-bezier(0.16, 1, 0.3, 1)`; state changes 0.15s; journal enters 0.32s (translate + clip); agent dot breathes 1.8s. `prefers-reduced-motion` collapses all of it (`styles.css:2035`).

### Demo mode (removed 2026-10-15)
- *Was:* `?demo` served recorded fixtures, replayed a synthetic DÉMO agent turn and showed a `mode démo` footer chip. *Why removed:* user decision; real searches on cached catalogue data cost no partsapi call, so reviews and captures use the real app. Fixtures the tests need live in `finder/test-fixtures/`, outside the bundle.

## 6. Do's and Don'ts

### Do:
- **Do** show the answer as a drawing: hidden-line part, cotes, view label, title block.
- **Do** keep visible lines at 1.7px and dimension/centre lines at 0.85px; import them from `palette.ts`, never re-type them.
- **Do** format every number through `format.ts` and every cote through `dimText`; ranges when variants differ, mesh at midpoint.
- **Do** keep the one composer docked at the bottom of every screen; text, photo, PDF and video enter through it, and a follow-up continues the current result: a deterministic result stays on screen, slightly greyed, until the agent shows a part or another vehicle (`TurnState.held`).
- **Do** name views the drafting way (`VUE 3D —`, `PLANCHE`) in Mono caps; label cells in sentence case.
- **Don't** draw a cote the catalogue does not carry, nor estimate one from a mesh.
- **Do** dock the journal right at ≥1280px and overlay it below.
- **Do** write the UI in French with French number grouping.

### Don't:
- **Don't** reintroduce TecDoc product photos as the product view.
- **Don't** print a single figure for a dimension that varies across variants, or more decimals than the source carries.
- **Don't** shade, light or texture the 3D part; it is paper with ink lines.
- **Don't** put a shadow, gradient or radius on the sheet or the title block.
- **Don't** use drafting blue as a large fill or decoration.
- **Don't** add uppercase kickers or eyebrows above headings; the only upper-case lettering is drawing view names.
- **Don't** use text glyphs as icons; icons are inline stroked SVG.
- **Don't** import the Atelier's night palette, JetBrains Mono, glow, or `--at-*` / `--mpa-*` tokens into finder.
