# mon-parc-auto — design library

Drop-in design tokens + React components for the Atelier UI. Framework-agnostic CSS custom
properties (`--mpa-*`) plus six typed `.tsx` primitives. No runtime dependencies beyond React.

## What's inside
```
design/
  styles.css            # single entry point — @imports every token + the Inter webfont
  tokens/               # colors · typography · spacing · effects · fonts
  components/           # Button · Badge · StatusPill · Card · MetricStat · HealthRing (+ index.ts)
```

## Install in this repo (admin/ — Vite + React 18 + TS)

1. Copy the `design/` folder into the Vite app:
   ```
   cp -r design admin/src/design
   ```
2. Import the stylesheet once, at the app entry (`admin/src/main.tsx`):
   ```ts
   import './design/styles.css';
   ```
3. Use the components anywhere:
   ```tsx
   import { Card, MetricStat, StatusPill, HealthRing } from './design/components';

   <Card>
     <MetricStat label="Kilométrage" value="142 000" unit="km" />
   </Card>
   <StatusPill status="overdue" />
   <HealthRing value={92} tone="ok" />
   ```

## Notes
- **Tokens, not hexes.** Style new UI through the `--mpa-*` variables (see `tokens/`), never hardcoded
  colors — that keeps everything themeable from one place.
- **react-admin / MUI:** this library is standalone and does not theme react-admin's Material UI out of
  the box. To align the admin shell, map these tokens into a MUI dark theme (palette
  `background.default = #0A0F17`, `primary = #22D3EE`, etc.) — happy to generate that theme file on request.
- **Inter** is loaded from Google Fonts via `tokens/fonts.css`. For self-hosting, drop `.woff2` files in
  `tokens/` and swap the `@import` for `@font-face` rules.
- Full guidelines, specimen cards, and the interactive dashboard recreation live in the design-system
  project this was exported from (see its root `readme.md`).

## Caveats
- No brand **logo** asset yet — the "M" mark is a CSS gradient tile; replace when you have the real logo.
- No icon set in use; **Lucide** (thin stroke) is the recommended match if/when you add icons.
