---
version: alpha
name: Control Panel
description: >-
  Industrial HMI / telemetry design system for mon-parc-auto.fr. Dark-first,
  instrument-panel surfaces, signal colors carrying status meaning. Built for
  dense fleet lists and real-time vehicle state at a glance.
colors:
  bg: "#0E1116"
  surface: "#181C22"
  surface-raised: "#1F242C"
  surface-inset: "#12151A"
  border: "#222831"
  border-strong: "#2E3640"
  text: "#E6EBF2"
  text-muted: "#8A95A3"
  text-faint: "#566070"
  primary: "#22D3EE"
  primary-dim: "#0E7490"
  signal-ok: "#34D399"
  signal-warn: "#FBBF24"
  signal-alarm: "#F43F5E"
  signal-info: "#60A5FA"
  led-off: "#33404D"
typography:
  display:
    fontFamily: "Inter, system-ui, sans-serif"
    fontSize: "28px"
    fontWeight: "700"
    lineHeight: "1.15"
    letterSpacing: "-0.01em"
  heading:
    fontFamily: "Inter, system-ui, sans-serif"
    fontSize: "18px"
    fontWeight: "600"
    lineHeight: "1.25"
    letterSpacing: "0em"
  label:
    fontFamily: "Inter, system-ui, sans-serif"
    fontSize: "11px"
    fontWeight: "600"
    lineHeight: "1.2"
    letterSpacing: "0.08em"
  body:
    fontFamily: "Inter, system-ui, sans-serif"
    fontSize: "14px"
    fontWeight: "400"
    lineHeight: "1.5"
    letterSpacing: "0em"
  readout:
    fontFamily: "JetBrains Mono, ui-monospace, monospace"
    fontSize: "14px"
    fontWeight: "500"
    lineHeight: "1.4"
    letterSpacing: "0em"
  readout-large:
    fontFamily: "JetBrains Mono, ui-monospace, monospace"
    fontSize: "32px"
    fontWeight: "600"
    lineHeight: "1.1"
    letterSpacing: "-0.01em"
rounded:
  none: "0px"
  sm: "4px"
  md: "6px"
  lg: "10px"
  pill: "999px"
spacing:
  0: "0px"
  1: "4px"
  2: "8px"
  3: "12px"
  4: "16px"
  5: "24px"
  6: "32px"
  7: "48px"
  8: "64px"
components:
  panel:
    backgroundColor: "{colors.surface}"
    border: "{colors.border}"
    rounded: "{rounded.md}"
    padding: "{spacing.5}"
  panel-header:
    typography: "{typography.label}"
    textColor: "{colors.text-muted}"
  readout-value:
    typography: "{typography.readout-large}"
    textColor: "{colors.text}"
  status-led:
    size: "8px"
    rounded: "{rounded.pill}"
  status-pill:
    typography: "{typography.label}"
    rounded: "{rounded.sm}"
    padding: "{spacing.1} {spacing.2}"
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.bg}"
    typography: "{typography.label}"
    rounded: "{rounded.sm}"
    padding: "{spacing.2} {spacing.4}"
    height: "36px"
  button-primary-hover:
    backgroundColor: "#3DDDF0"
    textColor: "{colors.bg}"
  button-ghost:
    backgroundColor: "transparent"
    textColor: "{colors.text-muted}"
    border: "{colors.border-strong}"
    rounded: "{rounded.sm}"
    height: "36px"
  button-ghost-hover:
    backgroundColor: "{colors.surface-raised}"
    textColor: "{colors.text}"
  data-row:
    backgroundColor: "{colors.surface}"
    border: "{colors.border}"
    height: "44px"
  data-row-hover:
    backgroundColor: "{colors.surface-raised}"
  input:
    backgroundColor: "{colors.surface-inset}"
    textColor: "{colors.text}"
    border: "{colors.border-strong}"
    rounded: "{rounded.sm}"
    height: "36px"
    padding: "{spacing.2} {spacing.3}"
  input-focus:
    border: "{colors.primary}"
---

> ⚠️ **DEPRECATED — superseded by [`theming/DESIGN-VISION.md`](theming/DESIGN-VISION.md) ("Atelier").**
> This flat "Control Panel" HMI is the *rejected* baseline, kept only as a record of the surface
> language Atelier deliberately leaves behind: flat fills, no gloss, no shadow, low radius,
> tonal-only depth. The salvaged bones — signal-color semantics (vert/ambre/rouge), mono telemetry,
> and "color is meaning" (upgraded to "light is meaning") — live on in DESIGN-VISION.md.
> **Do not build to this file.**

## Overview

Control Panel is an industrial HMI aesthetic — the screen reads like a SCADA
dashboard or a vehicle instrument cluster. Every surface is a panel; every panel
shows live state. The brand promise is *operational truth at a glance*: a fleet
manager should know the health of the whole parc auto in one sweep without
clicking.

Principles:

- **Dark-first.** Dark reduces eye fatigue on always-on dashboards and makes
  signal colors pop. There is no light theme in v1.
- **Status is color.** Color is reserved for meaning (ok / warn / alarm). Decor
  never borrows a signal color.
- **Data is monospace.** Plates, VINs, mileage, dates, counts — anything
  numeric or identifier-like — renders in mono so columns align and scanning is
  fast.
- **Flat, not glossy.** Depth comes from 1px borders and tonal surface steps,
  not drop shadows or gradients.

## Colors

Four neutral surface steps build depth without shadow: `bg` (app canvas) →
`surface` (panels) → `surface-raised` (hover, popovers) → `surface-inset`
(wells, inputs, code blocks). Separate panels with `border`; emphasize an active
or interactive edge with `border-strong`.

Signal colors carry fixed meaning and must not be used decoratively:

- `signal-ok` (green) — operational, up to date, healthy.
- `signal-warn` (amber) — attention soon: service due, document expiring.
- `signal-alarm` (red) — action now: overdue, breakdown, expired.
- `signal-info` (blue) — neutral notice, no action.

`primary` (cyan) is the single interactive accent — buttons, focus rings, active
tabs. It is *not* a status color; keep it off data rows so signals stay
unambiguous.

Text has three weights of presence: `text` for values, `text-muted` for labels
and secondary data, `text-faint` for disabled and placeholder.

## Typography

Inter for the UI, JetBrains Mono for data. The `label` style is the workhorse of
this system: small, uppercase-tracked (`letter-spacing: 0.08em`), used for panel
titles, column headers, button text, and pill labels — it gives the instrument
nameplate feel. Render labels in uppercase via CSS.

`readout` and `readout-large` are mono and reserved for telemetry: a vehicle's
mileage, the fleet count, days-until-service. Big numbers use `readout-large` so
a KPI reads from across the room.

## Layout

8px base grid; the spacing scale is its multiples. Panels sit on a dense grid
with `spacing.4` (16px) gutters. Inside a panel, pad `spacing.5` (24px). The
main page is a panel mosaic: a top KPI strip, then a fleet data table, with a
status/alerts rail. Prefer many small honest panels over one busy panel.

## Elevation & Depth

No shadows in normal state. Depth is tonal — a raised element steps one surface
level lighter (`surface` → `surface-raised`). Only true overlays (modals,
dropdowns) get a shadow: `0 8px 24px rgba(0,0,0,0.5)` plus a `border-strong`
edge. Active interactive elements may show a 1px `primary` border or a faint
`primary` glow (`0 0 0 1px primary`) — used sparingly, like an LED backlight.

## Shapes

Low radius throughout — `rounded.sm` (4px) is the default for buttons, inputs,
pills, and tags; `rounded.md` (6px) for panels. Sharp-ish corners read as
instrumentation. Reserve `rounded.pill` for status LEDs (8px dots) and toggle
tracks only — never for buttons.

## Components

- **Panel** — the base container. Header uses `label` style in `text-muted`,
  optionally with a status LED and an action affordance on the right.
- **Status LED** — an 8px filled dot in a signal color; `led-off` when inactive.
  Pair with a `label` for accessibility (color is never the only signal).
- **Status pill** — `label` text on a tinted background (signal color at ~15%
  alpha) with full-strength signal text. Used in table cells and alert lists.
- **Readout value** — `readout-large` for KPIs; pair with a `label` caption
  above or below.
- **Data row** — 44px tall, `border` divider, hover to `surface-raised`. Numeric
  columns right-aligned in mono; identifiers (plate, VIN) in mono.
- **Button** — `primary` (filled cyan) for the one main action per view; `ghost`
  (bordered) for everything else. 36px tall, `label` text.
- **Input** — inset well, `border-strong` edge, `primary` border on focus.

## Do's and Don'ts

- **Do** keep signal colors meaning-locked: green=ok, amber=soon, red=now.
- **Do** render all numeric/identifier data in mono and right-align numbers.
- **Do** pair every status color with text or an icon — never color alone.
- **Do** use one `primary` button per view.
- **Don't** introduce a light theme or white panels in v1.
- **Don't** use gradients, glossy fills, or decorative drop shadows.
- **Don't** color decorative elements with signal or primary colors.
- **Don't** round buttons/inputs beyond 4px or use pill shapes for them.
