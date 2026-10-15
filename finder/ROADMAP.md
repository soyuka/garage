# finder — agentic parts finder

Private MVP (never sold). Flat frontend at https://localhost/finder/ on the existing `api/`;
`dashboard/` (the Atelier) stays. The pain it targets: finding the correct reference for a part.
Kept separate from the repo-root `FUTURE.md` on purpose. Design system: `finder/DESIGN.md`.

## State (2026-10-15)

Shipped, end to end:

- **Single input** (text / photo / video) over a blank drawing sheet. Local rules first
  (`finder/src/route-input.ts`): plate (SIV / FNI) → `GET /vehicles/{plate}`; VIN → `GET /vins/{vin}`
  (sunrise/vin, model year only for North-American VINs); reference shape → agent `intent=reference`;
  known category → `GET /cars/{carId}/part-categories/{id}/narrowed`; else agent turn. Photo/video →
  `POST /photo-intake` (one vision call: carte_grise | part | other; section C never extracted, file
  never stored); video = 3 frames sampled in the browser.
- **Narrowing** (`api/src/Catalog/Narrowing/`): BrandTier top 8 reps → getArticleCriteria → physical
  fingerprint (canonical thread, synonym map, deny list of application-level keys, tolerance
  max(2 mm, 2.5 %)) → cluster → 1 getArticleCrosses per cluster → merge clusters sharing a carmaker OE
  → ranges + `differsOn`. Mazda 6 oil filter: 220 → 1 part, OE LF05-14-302A, no question.
- **Agent** `finder` (`api/config/prompts/finder.md`, POST /agent/turn `surface: "finder"`):
  vehicle-less starts (browse makes/models/cars), photos, reference lookup, `show_parts` narrows
  server-side, `set_vehicle`, `ask_question` for what the catalogue can't settle, web finds tagged
  "Source : web". Cap 25 tool calls.
- **Result**: drawing sheet with the hidden-line 3D part (brakes + oil filter meshes), "VUE A —
  DESSOUS" base view, one formatter for drawn dims and title-block cotes (ranges, never false
  precision), title block = result card, Copier / Acheter (Oscaro, Autodoc, Mister-Auto, web — URLs
  verified) / Ajouter au <véhicule> (`POST /garage/planned-tasks`, real) / Partager (hash URL).
- **Journal**: agent turns and deterministic searches in `agent_turns` (`surface`, `kind`), cache
  tokens, cost (Sonnet 5.5 prices in `.env`), partsapi calls per method vs the 50/day quota; drawer
  docks right ≥1280px.

Live checks done: "filtre à huile" on XB-301-ZB (6 s, 220 → 1); "filtre à huile mazda 6 2.0 2005"
without plate (16.6 s, $0.044, 3 partsapi calls) → correctly asks which of 4 versions (141 ch / 147 ch /
break / 2.0 DI) instead of guessing.

## Open

- Live-test the paths that cost money, with the user watching: real part photo, multi-part request
  ("courroie + pompe à eau"), web-found reference, the ask → answer round trip.
- 100/220 articles stay unattached on the Mazda oil filter (their crosses don't reach the 2 reps
  called). Options: one more crosses call on a third rep, or accept and keep the quiet line.
- Part families: only brakes + oil filter have meshes; everything else shows the category name.
  More GLBs come from the asset session (`blueprint-experiment/`, not committed — raw extraction).
- Base view hidden when the title block stacks (mobile, docked journal); 409 (ambiguous vehicle) on
  "Ajouter" only toasts a link to the Atelier, no picker; mobile vehicle chip truncates.
- Data source: partsapi test keys (50/day/method). Going public = TecAlliance licence (below) AND
  buying the Wrench meshes from the developer.

## Data source: partsapi.ru (accepted for the private MVP)

- Reseller; data now labelled "TecDoc 2026 Q2". TecAlliance cut Russia/Belarus off 2022-04-20 and
  exited Q4 2023, so this is most likely an unlicensed snapshot. Fine for a private MVP, NOT public.
- getArticles/getSearchTree cached 30 days (`PARTSAPI_CATALOG_TTL`); article facts (criteria,
  crosses) cached forever. getModels/getCars verified 2026-10-15 (`makeId`, not `manuId`).
- Going public = TecAlliance TecDoc Web Service / Data Package licence (no public price, negotiated
  yearly; "Restricted Access" = logged-in users only):
  https://www.tecalliance.net/tecdoc-data-user/ ,
  https://a.storyblok.com/f/297549/x/00d0febb87/gtc-en.pdf
- Scraping Oscaro/Autodoc/Mister-Auto: no (French database law, sui generis right). Manufacturer
  catalogs (MANN, 210k cross-refs) have no public API.

## What the partsapi data really is (probed 2026-10-15, fixtures in `api/tests/Fixtures/partsapi/`)

- `getArticles`: 6 fields, fitment-correct, alphabetical — 220 articles / 126 brands for one filter.
- Crosses are a loose INTERCHANGE list, not an equivalence class (W 712/73 → 495 crosses, 186 brands,
  16 MAHLE numbers): never cluster on them. They do carry carmaker OE numbers, which is what merges.
- Criteria are per ARTICLE (union over every vehicle it fits; W 712/73 lists a Perkins engine code):
  physical criteria are reliable, dates / VIN breakpoints are not usable per car without per-linkage
  data (no key).
- Media (getArticleMedia) dropped: the product shows the 3D blueprint, not photos.

## 3D assets (researched 2026-10-15)

- Shipped meshes are Wrench extracts (brakes, oil filter), user-approved for this private prototype
  (`finder/public/parts/SOURCES.md`). Only final blueprint GLBs go in the repo.
- Legit route if this ever goes public: parametric three.js for solids of revolution (filters, disc,
  plugs, spring, bearing, thermostat, pulley); CC-BY Sketchfab candidates (re-verify author + licence,
  skip brand-named models): tie rod end https://sketchfab.com/3d-models/none-08006a773eb345bab3a384b71185aa9e ,
  control arm https://sketchfab.com/3d-models/none-bc35286fb7554943a9a2f156df0ab378 ,
  coilover https://sketchfab.com/3d-models/none-92e61757780840a683abdd38c4e8a700 ,
  caliper https://sketchfab.com/3d-models/none-5feda9dbf20a49cf9fd29d550e55e4b9 ,
  CV joint https://sketchfab.com/3d-models/none-5374202ad612455ab0ade980bd507cc0 ; wheel hub and
  silentbloc → model in Blender. GrabCAD / TraceParts / manufacturer CAD forbid republishing.

## Serving

`handle /finder* { reverse_proxy finder:5173 }` in `api/frankenphp/Caddyfile` before `@frontend`;
`finder` service in `api/compose.override.yaml`; Casdoor redirect `https://localhost/finder/callback`.
Catalog reads (`/vehicles`, `/vins`, `/cars`) sit on a lenient `catalog` firewall: a valid token
journals the search, an invalid/expired one is served anonymously. Agent turns, photo intake and
garage writes need the token. Demo mode with recorded fixtures: https://localhost/finder/?demo
