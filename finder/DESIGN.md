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
    fontSize: "40px"
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

Density is that of a drawing: thin rules, labelled cells, tabular figures, no decoration that is not a drafting device. Home (revised 2026-10-15, corrected same day) = a public showcase (vitrine) written for DIY owners first: headline `La réf. d’origine et ses équivalences.`, a lede saying what to type and what comes back, and three example chips under the composer (`STARTERS` in `src/home.ts`: two free searches that run in one click, `Photo de la carte grise` opening the file picker). Logged in, the recent searches take the space below (up to 10 rows). **Revised 2026-10-15 (evening): the home holds its own composer and fills its page.** Headline, lede, composer, then the picks, as one centred 880px column (`--home-col` in `.home`; hero, composer, picks, example sheet and recent searches all share its left and right edges, nothing wider; *was* 760px with the sheet at 1040px, which hung 80px outside the column; corrected 2026-10-15) whose top sits at `clamp(32px, 9vh, 96px)` (`ui/Home.tsx`, `styles.css` `.home`). Below it, separated by `clamp(48px, 8vh, 80px)`: logged in with at least one recent search, `Recherches récentes`; otherwise (logged out, or no history yet) the **example sheet** (`Showcase`): the Mazda 6 oil filter drawn in hidden line, turning on the idle turntable (static under reduced motion, never takes the pointer or the page scroll), with a reduced title block (Désignation, Réf. OE, four equivalences + count, Véhicule by name, `220 → 1`, Cotes) labelled `EXEMPLE · VUE 3D — FILTRE À HUILE`. Its data is one cached catalogue answer stored with the page (`src/showcase.json`, from `GET /cars/19107/part-categories/100259/narrowed`, plate and VIN stripped; `src/showcase.test.ts` pins no plate, real OE and cotes), so a visitor never spends a catalogue call and nothing on it is invented. *Was:* the hero at the top, a ~500px empty band, the composer docked at the bottom. *Why:* user feedback ("all this white space on the main screen"); Apple's move (apple.com product hero: headline in the upper third, the product itself fills the rest; Hopper: the tool is the hero, curated tiles under it) is to put the input under the headline and let the real artefact fill the page, not to dock it. No real plate anywhere on the public home (`src/home.test.ts`); logged in, the owner's own plates show in `Recherches récentes` and `Mes véhicules`, and neither the catalogue nor the shops are named in the hero. *Was (morning of 2026-10-15):* the question `Quelle pièce, pour quel véhicule ?` with `Saisissez la plaque ou le VIN, puis la pièce.`, which `.impeccable/review/clarify.md` had kept "on purpose". *Why reversed:* user decision. The question only said what to type, never what comes back; final copy and rationale in `.impeccable/review/homepage-copy.md`. *Was:* an empty `PLANCHE` with a blank title block under the question, added after the finish review to give the entry the drawing-sheet world. *Why reversed:* without explanation it read as an empty table; history now owns the home. French UI throughout (`index.html` `lang="fr"`), French number formatting with narrow no-break space (`format.ts:1`, `formatNumber`).

Layout, as built (revised 2026-10-15): `100dvh` grid of 56px top bar / scrolling main / dock (`styles.css` `.app`, `.main`, `.dock`). The dock is always on screen at the bottom, on every width: agent status line, the open question (agent `ask_question` or the `differsOn` question) with its answer buttons, quick actions, then the composer, *Revised 2026-10-15 (evening):* the home has no dock. Its composer sits under the headline (`Intake variant="home"`, 52px, 17px text) with, under it, two labelled rows on a shared label column: `Mes véhicules` (logged in, saved cars; the row's place is held while they load) **and** `Exemples` (always). *Was:* the dock on the home too, with `Mes véhicules` replacing the examples. *Why:* user decision, both must be visible when logged in. Leaving the home (any submit, chip, saved car) mounts the docked composer and moves focus into it (`App.tsx` `handoff`), so typing carries on. Workspace = the sheet alone; the narrowing trace is a drawing note in the sheet's top-left corner (`.sheet-notes`) and moves under the sheet when it stacks. The title block overlays the sheet's bottom-right corner at 520px when the sheet is at least 812px wide (`App.tsx` `SHEET_OVERLAY_MIN`); otherwise sheet, trace and title block stack. ≤759px: 16px gutter, the vehicle chip wraps to its own row. The journal docks as a 480px right column at ≥1280px spanning main and dock, a 580px overlay drawer below that.
*Was (until 2026-10-15):* a 288px left aside (trace, question, recent searches), the input in the top bar (hero input on the homepage) and a 28px footer. *Why reversed:* the user decided the composer must always be visible and continue the current search; a question belongs next to where it is answered.

**Key Characteristics:**
- Hidden-line 3D part (`scene/HiddenLine.tsx`, `scene/Blueprint.tsx` low-FOV perspective camera) is the ONLY product view: orbit on both axes around the part, pan, zoom, slow idle turntable.
- ISO 128 line weights, ISO 129 dimension placement, chain centre lines.
- Title block = result card; drawn cotes and title-block cotes come from one formatter.
- One composer (text, photo, PDF, video): under the headline on the home, docked at the bottom of every other screen.
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
- **Display** (600, `clamp(28px, 1.6vw + 16px, 40px)`, 1.1, -0.03em, balanced): the home headline. One per page, entry only. *Was (until 2026-10-15 evening):* a fixed 32px; raised so the headline leads the centred column at about 2.5× the lede, as a showcase hero does.
- **Reference** (Mono 600, 32px, 1.15; 26px ≤759px): the OE reference in the title block (`.oe`). The largest thing on a result screen is the part number.
- **Headline** (600, 17px): title-block designation, journal heading.
- **Body** (400, 14px, 1.4): prose; 13px for secondary prose, 46-60ch measures.
- **Data** (Mono 500, 13px, tabular): dimension labels on the sheet (`.dim-label`), plates, kType, references, counts, durations; 12.5px in lists.
- **View label** (Mono 500, 11.5px, +0.04em): drawing view names, `VUE 3D — FILTRE À HUILE`, `EXEMPLE · VUE 3D — …` on the home example (was `VUE ISO —`: a freely orbiting view is not an isometric one, renamed 2026-10-15). Upper case is in the string itself, as on a drawing. *Removed 2026-10-15 (evening):* `PLANCHE` on a vehicle's empty sheet. *Why:* user decision, a label naming an empty sheet said nothing; that sheet now shows only `Quelle pièce ?` (the helper `Nom, référence ou photo, dans le champ en bas.` was dropped too), and, logged in, up to 5 earlier searches on this vehicle under it (`Recherches sur ce véhicule`, `history.ts` `historyFor`, result routes only, no vehicle column).
- **Label** (500, 11px, +0.02em, sentence case): title-block cell labels (`Désignation`, `Réf. OE · MAZDA`, `Cotes`).

### Named Rules
**The Mono Is Data Rule.** Plates, VIN, references, kType, cotes, counts, times and quotas render in Plex Mono with tabular figures (`.m`, `styles.css:73-76`). Prose and brand names stay in Plex Sans; equivalence lines set the brand in Sans and the reference in Mono.

**The One Formatter Rule.** Every printed number goes through `format.ts` (`formatNumber`, `formatRange`, `formatMeasure`, NNBSP grouping). Drawn dimensions and the title-block `Cotes` line both call `dimText` (`scene/annotations.ts`, `result.ts` `cotesLine`), so the sheet and the title block can never disagree.

**The Range Rule.** When variants of a part differ, a cote prints as a range (`75–78`), never a picked value; the mesh is built at the range midpoint (`dimMid`, `dims.ts`). False precision is prohibited.

**The Never Invent Rule** (2026-10-15). A cote is drawn only when the cluster fingerprint carries it (`scene/annotations.ts`, tested in `annotations.test.ts`); otherwise there is no dimension line at all, and the title block says `non renseignées par le catalogue`. Brakes: disc Ø and thickness only for a disc category, only from the fingerprint. *Was:* a missing cote printed `≈` + a figure measured on the mesh (`FilterPart.tsx`). *Why reversed:* a mesh measurement is not catalogue data; it read as one.

## 4. Elevation

The sheet is flat paper: hierarchy comes from rule weight (1px hairline < 1px graphite < 1.5px graphite title-block and journal frame), not shadow. Shadows exist only on layers that genuinely float above the sheet, all soft, negative-spread, graphite-tinted.

### Shadow Vocabulary
- **Popover** (`box-shadow: 0 12px 32px -12px rgba(34, 36, 40, 0.28)`): buy menu.
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
- **Cells, in order:** Désignation · Variantes (only with several parts, see Variant table) · Réf. OE (reference type) · Équivalences (brand Sans 500 + ref Mono, ref copies on click) · Véhicule | kType · Cotes | Source · Pourquoi cette pièce (panel fill) · actions.
- **Cell:** label type, then value; padding 10px 14px 11px; no radius.
- **Skeleton:** panel-to-`panel-2` sheen, 1.4s linear.
- **Sheet growth** (2026-10-16): the overlaid title block never clips. `Sheet` observes the `.sheet-side` column (now sized to its content, anchored bottom-right) and sets the sheet's `min-height` to it, so a block taller than the sheet (the variant table below) makes the sheet taller and `.main` scrolls. *Was:* the column stretched top to bottom with `justify-content: flex-end` and the sheet's `overflow: hidden` cut the block's top (Désignation) off.

### Variant table (2026-10-16; several distinct parts)
The drawing-sheet device for one designation that exists in several versions: a tabulated drawing's variant table. When `/narrowed` returns more than one cluster, the title block opens with it, right under Désignation (`ui/VariantTable.tsx`, data `src/variants.ts` `variantRows`).
- **Head:** `Variantes · 2 pièces distinctes` in label type, the status tag right: `à confirmer` (nobody chose, variant A shown), `choisie par vous` (plain tag: picked in the table), `confirmé par la cote` (`ok` tag: the `differsOn` question answered).
- **Columns:** `Var.` (a 22px graphite-ruled letter box, A, B… in cluster order) · `Réf. OE` (the carmaker OE in Mono 600 14px, `pickOe`; under it the representative brand + ref and the article count, 12px mute) · one column per splitting `differsOn` key, headed by its catalogue label verbatim (`Longeur rotule`, BOSCH's spelling kept), values in drafting blue Mono 600 as ranges (`322–338 mm`, `358 mm`), the Range Rule applies · `Applicabilité (par article)`: only breakpoint criteria (OPR, numéro d'organisation, VIN, châssis, série), label with its leading `pour` dropped, values verbatim and sorted (`Numéro OPR jusqu’à 10310 · 10311`). Year criteria are left out of the table: they are the union over every car each article fits, a soup that tells nothing apart. A column with nothing to show is dropped.
- **Rows are radios** (`role="radiogroup"`, roving tabindex, arrow keys move and pick). Checked row = panel fill + letter box filled graphite, white letter (the part-tab selection); hover = panel. Picking a row switches everything the sheet shows to that cluster: the part drawn and its cotes, the title block's Réf. OE (labelled `Réf. OE · PEUGEOT · variante B`), Copier la réf., Acheter, Ajouter au véhicule and Équivalences.
- **Honest provenance.** A row pick is the user's decision: route `/v/<clusterId>`, `Pourquoi cette pièce` reads `Variante B choisie par vous : le catalogue ne départage pas ces pièces pour ce véhicule.`, the trace keeps `2 pièces distinctes` (only the cote question brings it to `1 pièce`), the question card's buttons stay unpressed, and the follow-up summary tells the agent `choisie par l’utilisateur, non confirmée par le catalogue`. The question answer keeps `/k/<clusterId>` (the only form before 2026-10-16, so old links keep their meaning) and `confirmé par la cote`. Nothing ever says `confirmé` for a row pick.
- **Title block extras, multi-part only:** under the OE, `Aussi :` and the carmaker's other OE numbers, spelling variants folded (`3812 E0` = `3812.E0`, `makerOe`); under the Cotes line, every other catalogue cote of the selected cluster, label + Mono value, packaging excluded, the splitting one in blue 600 (`otherDimensions`). A single-part result (Mazda oil filter) is unchanged: one Mazda OE among ~90 carmaker numbers would be noise there.
- **Mobile (≤759px):** no header row; each row is letter | reference | value (value right-aligned, its label printed above it in 11px mute), applicability on a second line under the reference.
- *Why this pattern:* the sheet already has a title block that *is* the result; a variant table is how a drawing says "this designation, in these versions" without a second card. Considered and rejected: `Variante A / B` tabs (only show a letter, hide what differs), a side-by-side comparison of two title blocks (doubles the block, does not fit 520px or a phone). *Was:* `2 pièces possibles. Répondez à la question pour trancher.` in `Pourquoi cette pièce`, the question as the only way to switch, and a manual URL edit as the only way to see part B.

### Buttons
- **Shape:** gently squared (6px).
- **Primary:** graphite fill, white text, 500 13px, 36px high, 14px side padding; hover pure black. One per action row (`Copier la réf.`, `Chercher`).
- **Secondary:** paper fill, 1px graphite border; hover panel fill.
- **Quiet:** 30px, hairline border, hover graphite border.
- **Disabled:** hairline border, grey text, `not-allowed`.
- **Focus:** 2px drafting-blue outline, 2px offset, everywhere (`styles.css:44-47`).

### Inputs / Fields
- **Composer** (2026-10-15, was hero 54px on the homepage + 34px bar in the top bar): on the home, one field under the headline (52px, 17px text, revised 2026-10-15 evening); everywhere else one field docked at the bottom, 1px graphite border, 6px radius, 46px with 16px text; it clears after each send. *Removed 2026-10-15:* the category autocomplete list under the composer (listbox, `Recherche libre : « … »` row, arrow-key navigation, combobox ARIA). *Why:* user decision ("shows weird categories, too much"). *Also removed 2026-10-15 (same day, user decision: categories "are weird, have no match, or show data that does not exist on Mister-Auto"):* the exact-category-name route. Enter submits through `routeText`: plate, VIN and reference shape stay local; every other typed text, a TecDoc category name included, goes to the agent turn (`routeText` takes no category list; `TextRoute` has no `category`). `/narrowed` still loads when a result is restored from a URL or history route; that is not typed routing. *Removed 2026-10-15 (user decision):* the curated suggestion chips on a vehicle page (TecDoc categories 100259, 100260, 100261, opening `/narrowed` directly under trace rule `suggestion`, earlier `category`) and that journal rule; the page offers the composer only. The home "Exemples" chips and the "Mes véhicules" row stay. The field is a plain search input again. Attach (and camera on coarse pointers) as 36px icon buttons with inline 1.5px-stroke SVG. Focus-within adds the blue halo; dragging a file over it turns the border dashed blue on panel.

### Chips and Tags
- **Vehicle chip:** 34px, hairline border, 6px radius; plate on panel inset in Mono; engine hides below 1280px, kType below 760px.
- **Vehicle context token** (2026-10-15): the car the composer searches on, pinned at the start of the field on every vehicle and result route (`ui/VehicleContext.tsx` `ContextToken`). 32px, panel fill, 4px radius, plate in Mono 12.5px (else the short name in Sans, else `kType N`), then a 26px ✕ button `Retirer le véhicule <plate>` that goes back to the home. The context *is* the route ref (`#/p/<plate>`, `#/k/<carId>-<type>`): no separate store, so the token, the categories and the agent turn's `vehicle` can never disagree. The ✕ is a real button before the input (Shift+Tab from the field); Backspace in an empty field does not remove it, since removing navigates away. Max 45% of the field; the placeholder ellipsises.
- **Attachment chip and carte grise submit** (2026-10-16, user decision, replaces the auto-read on attach and the carte grise confirmation table `CardResult`): attaching a file (picker, drop, paste, camera, video) only adds a chip inside the composer after the token (`Intake` `Chips`, `.chip-file`): 20px thumbnail for an image, `PDF`/`VID` mark otherwise, name ellipsised at 14ch, a 26px ✕ `Retirer <name>`; up to 3 (`attachments.ts` `MAX_ATTACHMENTS`), PDF at most 10 Mo. Nothing is read or sent until Chercher, which is enabled by text or by an attachment. Chercher then runs one pipeline (`submit.ts` `runSubmission`): one intake per attachment (PDF → `/registration-documents/extract`, photo or video frame → `/photo-intake`), and for a carte grise: plate lookup, silent `POST /garage/vehicles/save` carrying the read fields as `registration` (dedup by plate; facts VIN E, première immatriculation B, CNIT D.2.1, cylindrée P.1, puissance kW P.2, énergie P.3, puissance fiscale P.6, only non-blank, source `carte grise`, section C never read, file never stored), navigation to the vehicle route (token appears, composer focused), then the typed text as an ordinary agent turn on that vehicle (`App.tsx` `queued`, fired once the route's vehicle is loaded). Vehicle precedence (2026-10-16, later): the carte grise's plate, else a plate typed among the words (`extractVehicleRef`; the remaining words are the query, a VIN stays in the query), else the context vehicle; a typed plate that disagrees with the carte grise loses and the status line says so. A part photo always rides the turn as an image beside the query. Attachment only: steps up to the token, no turn. Status line: `Lecture de la carte grise…`, `Véhicule : Mazda 6`. There is no blocking confirmation: a misread plate shows in the token, and ✕ plus a retyped plate is the correction. Plate unreadable or not in the catalogue: the status says so (`Plaque XB-301-ZB introuvable dans le catalogue. Vérifiez la carte grise ou saisissez la plaque.`), the typed text stays in the composer, the attachment stays, and no turn is sent without a vehicle; a lookup miss that carries a VIN and make/model is still saved (without the doubtful plate). A garage save failure does not block the search. Part and vignette photos take the same path (intake at submit, then the agent turn). *Was:* reading on attach, then a table of fields with `Rechercher <plate>` that navigated nowhere on the entry route, so the next typed part had no vehicle (`App.tsx` `readRegistration` left the route at `entry`, hence `useAgentTurn(null, '')`).
- **Mes véhicules** (2026-10-15): logged-in home only, under the composer, above the example chips (revised 2026-10-15 evening, *was* in place of them) (`MyVehicles`, data `src/my-vehicles.ts` from `GET /garage/vehicles`). Label in label type, then quiet buttons `plate · short name` that wrap onto several lines from 768px up (no scrollbar), and on narrower screens stay on one scroll-snapped line with the scrollbar hidden and a right-edge fade only while more chips lie beyond (`data-fade`, set in `MyVehicles`); chips stay focusable buttons, so focus scrolls them into view. *Was:* a single line with a visible hairline scrollbar on desktop that clipped the 4th car. A pick opens the car's vehicle route with the composer focused and the token set; a car without plate opens `#/k/<kType>-<type>` (`cv` → `CV`, else `PC`). Cars with neither plate nor kType (lawn tractor, import) are left out: the catalogue cannot be scoped to them. Logged out or garage empty or unreadable: no row. Shape and alternatives considered: `.impeccable/review/vehicle-context-shape.md`.
- **Tags:** 11.5px, 3px radius, panel fill; `ok` = blue outline on paper; `warn` = warn on warn tint.

### Navigation and Lists
- **Result quick action** (2026-10-15): on a result route, the dock's quick actions carry `Chercher une autre pièce sur ce véhicule`: it closes the result (and any finished agent turn) and opens the vehicle route, so the composer is empty, focused, and keeps the token.
- **Recent searches (home, logged in):** the journal's last 10 restorable searches (`history.ts`), rows of input · plate + vehicle ("Make Model engine", else the model, else `kType N`) · outcome tag · relative time (`formatRelative`, exact time on hover) on hairline rules under a graphite top rule; one click follows the server-computed route. Only user-initiated submits are journaled (revised 2026-10-15): a composer submit or chip click sends `X-Finder-Rule` + `X-Finder-Input` (the typed text) and one `X-Finder-Search` id shared by the submit's requests, which fold into one row; restores from history, hash loads, retries and background fetches send none and leave no row. A ✕ per row ("Retirer de l'historique", on hover/focus, always on touch) hides that route, and is the only control. *Was (until 2026-10-15):* an "Effacer" text button hiding every row up to now (`historyClearedAt`, `POST /agent/turns/history/clear`) with an "Annuler" toast. *Why removed (2026-10-15):* clear-all removed at the user's request; the row ✕ is enough. Hiding never deletes: the journal drawer keeps every entry.
- **Question card:** panel fill, 8px radius, question in 14px/500, answers as secondary buttons (Mono only for cluster answers), hint below. An answer prints the range its part spans (`322–338 mm`, from `differsOn` min/max), never the one value TecDoc chose as representative (*was* `338 mm`, corrected 2026-10-16: a Range Rule breach that the variant table made visible). Pressed only when the question settled the part, not after a pick in the variant table.
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
- **Do** keep the one composer docked at the bottom of every screen but the home (where it sits under the headline, revised 2026-10-15 evening); text, photo, PDF and video enter through it, and a follow-up continues the current result: a deterministic result stays on screen, slightly greyed, until the agent shows a part or another vehicle (`TurnState.held`).
- **Do** name views the drafting way (`VUE 3D —`, `EXEMPLE ·`) in Mono caps, and name nothing on an empty sheet; label cells in sentence case.
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
