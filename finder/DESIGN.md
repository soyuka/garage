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

Density is that of a drawing: thin rules, labelled cells, tabular figures, no decoration that is not a drafting device. The blank sheet is visible before any search (homepage = one input over an empty `PLANCHE` with an empty title block), so the user sees the form the answer will take before asking. French UI throughout (`index.html` `lang="fr"`), French number formatting with narrow no-break space (`format.ts:1`, `formatNumber`).

Layout, as built: 56px top bar / content / 28px foot (`styles.css:89-94`). Workspace = 288px aside + sheet (`styles.css:492-496`); the title block overlays the sheet's bottom-right corner at 520px with the base view stacked above it, only when the sheet column is at least 812px wide (`App.tsx:346,402`); otherwise the sheet and title block stack. Breakpoints: ≤1099px stacks sheet over title block and drops the aside to 248px; ≤759px collapses to one column with 16px gutter; the journal docks as a 480px right column at ≥1280px (`App.tsx:65`, `styles.css` `.app--journal`) and is a 580px overlay drawer below that.

**Key Characteristics:**
- Hidden-line orthographic 3D part (`scene/HiddenLine.tsx`, `scene/Blueprint.tsx` orthographic camera) is the main product view.
- ISO 128 line weights, ISO 129 dimension placement, chain centre lines.
- Title block = result card; drawn cotes and title-block cotes come from one formatter.
- Single input (text, photo, video) over a blank sheet.
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
- **View label** (Mono 500, 11.5px, +0.04em): drawing view names, `VUE ISO — FILTRE À HUILE`, `VUE A — DESSOUS`, `PLANCHE`. Upper case is in the string itself, as on a drawing.
- **Label** (500, 11px, +0.02em, sentence case): title-block cell labels (`Désignation`, `Réf. OE · MAZDA`, `Cotes`).

### Named Rules
**The Mono Is Data Rule.** Plates, VIN, references, kType, cotes, counts, times and quotas render in Plex Mono with tabular figures (`.m`, `styles.css:73-76`). Prose and brand names stay in Plex Sans; equivalence lines set the brand in Sans and the reference in Mono.

**The One Formatter Rule.** Every printed number goes through `format.ts` (`formatNumber`, `formatRange`, `formatMeasure`, NNBSP grouping). Drawn dimensions, the base view and the title-block `Cotes` line all call `dimText` (`dims.ts:24`, `result.ts:33-45`, `ui/BaseView.tsx`), so the sheet and the title block can never disagree.

**The Range Rule.** When variants of a part differ, a cote prints as a range (`75–78`), never a picked value; the mesh is built at the range midpoint (`dimMid`, `dims.ts:26`). With no catalogue value, a drawn cote prints `≈` before a mesh-derived figure (`scene/FilterPart.tsx:72,79`). False precision is prohibited.

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
- **View label:** top-left at 20/24px, view-label type. Drag hint bottom-left, 11.5px mute.
- **3D view:** orthographic camera; `HiddenLine` draws creases (`EdgesGeometry`, 25° threshold) plus a constant-pixel inverted-hull silhouette, both at `LINE_VISIBLE` 1.7px, over a paper fill pushed back by polygon offset. Ghost state (loading/preview) draws at 0.28 opacity.
- **Line weights (ISO 128):** visible outline 1.7px (`LINE_VISIBLE`), dimension, extension, leader and centre lines 0.85px (`LINE_THIN`), about 2:1 (`palette.ts:5-7`). Centre lines are chain lines, ISO 128 type 04: long dash, gap, short dash, gap (`chainSegments`, `scene/Dimension.tsx`; SVG `stroke-dasharray: 14 3 2 3`).
- **Dimensions (ISO 129):** extension lines start 1.5mm off the feature and run 2.5mm past an unbroken dimension line with filled arrowheads; the value sits beside the line (above for horizontal, right for vertical) as DOM text so it stays crisp; dimensions draw over the part, never behind it. Notes use a leader to a horizontal shelf.

### Base View (signature)
- 2D SVG orthographic `VUE A — DESSOUS` for filters (outer Ø, seal ring band at 10% blue fill, thread major/minor circles), `VUE A — FACE` for brakes (`ui/BaseView.tsx`). Same weights via `vector-effect: non-scaling-stroke`, same chain centre lines, leaders to a label column. Every circle is a midpoint; every label is the title block's range text. Shown above the title block only in the overlay layout.

### Title Block (signature; the result card)
- **Structure:** 3-column grid, 1.5px graphite outer frame, 1px graphite cell rules, docked flush to the sheet's bottom-right corner (`right/bottom: -1px`) at 520px; full width when stacked.
- **Cells, in order:** Désignation · Réf. OE (reference type) · Équivalences (brand Sans 500 + ref Mono, ref copies on click) · Véhicule | kType · Cotes | Source · Pourquoi cette pièce (panel fill) · actions.
- **Cell:** label type, then value; padding 10px 14px 11px; no radius.
- **Blank state:** same block on the entry sheet with hairline rules and `—` values.
- **Skeleton:** panel-to-`panel-2` sheen, 1.4s linear.

### Buttons
- **Shape:** gently squared (6px).
- **Primary:** graphite fill, white text, 500 13px, 36px high, 14px side padding; hover pure black. One per action row (`Copier la référence`, `Chercher`).
- **Secondary:** paper fill, 1px graphite border; hover panel fill.
- **Quiet:** 30px, hairline border, hover graphite border.
- **Disabled:** hairline border, grey text, `not-allowed`.
- **Focus:** 2px drafting-blue outline, 2px offset, everywhere (`styles.css:44-47`).

### Inputs / Fields
- **Single input:** one field, 1px graphite border, 6px radius; hero 54px with 18px text, bar 34px in the top bar. Attach (and camera on coarse pointers) as 36px icon buttons with inline 1.5px-stroke SVG. Focus-within adds the blue halo; dragging a file over it turns the border dashed blue on panel.

### Chips and Tags
- **Vehicle chip:** 34px, hairline border, 6px radius; plate on panel inset in Mono; engine hides below 1280px, kType below 760px.
- **Tags:** 11.5px, 3px radius, panel fill; `ok` = blue outline on paper; `warn` = warn on warn tint.

### Navigation and Lists
- **Trace (aside "Affinage"):** label/value rows on hairline rules under a graphite top rule; the result row is graphite-ruled and blue.
- **Part tabs:** one bordered strip, hairline separators, selected = graphite fill, white text.
- **Journal:** 1.5px graphite left frame and header rule; rows hover to panel; facts in a graphite-ruled 3-column grid (2 on mobile); payloads in Mono 11.5/1.45 on hairline boxes.

### Motion
- Ease `cubic-bezier(0.16, 1, 0.3, 1)`; state changes 0.15s; journal enters 0.32s (translate + clip); agent dot breathes 1.8s. `prefers-reduced-motion` collapses all of it (`styles.css:2035`).

## 6. Do's and Don'ts

### Do:
- **Do** show the answer as a drawing: hidden-line orthographic part, cotes, view label, title block.
- **Do** keep visible lines at 1.7px and dimension/centre lines at 0.85px; import them from `palette.ts`, never re-type them.
- **Do** format every number through `format.ts` and every cote through `dimText`; ranges when variants differ, mesh at midpoint.
- **Do** keep a single input on the homepage over a blank sheet; text, photo and video enter through the same field.
- **Do** name views the drafting way (`VUE ISO —`, `VUE A — DESSOUS`) in Mono caps; label cells in sentence case.
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
