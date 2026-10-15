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
- **Result**: drawing sheet with ONE hidden-line 3D view: 22° perspective camera orbiting the part's
  bbox centre on both axes, pan, zoom to cursor, double-click reset, idle turntable (1 rev / 40 s,
  stops on first touch, off under reduced motion); cotes fixed to the part with billboarded labels,
  drawn ONLY from the cluster fingerprint (never from the mesh).
- **Meshes (2026-10-15)**: 89 Wrench web meshes + filter + brakes (batch 3: 29, batch 4: 5, 2026-10-15) in `finder/public/parts/`
  (inventory, sizes, licence note: `SOURCES.md`). `family.ts` `MESH_RULES` maps TecDoc categories to
  them (133/497 leaves of the Mazda tree, incl. filter + brakes; tested against the real tree); only
  the active mesh loads; approximate matches captioned "Forme type de la famille, pas la pièce exacte";
  unmapped categories keep the generic chamfered block. Dev contact sheet:
  https://localhost/finder/dev/meshes.html (not in the build). Title block = result card, Copier /
  Acheter / Ajouter au <véhicule> (409 → inline picker plate · model, re-post with the picked IRI or
  confirmNew) / Copier le lien. "Enregistrer le véhicule" in the top bar (`POST /garage/vehicles/save`,
  same-plate match, different plate = different car, 409 picker when no plate).
- **Layout (2026-10-15)**: composer docked at the bottom on every width, with the agent status, its
  question + answers (or the differsOn question) and quick actions above it; no left aside (trace is
  a sheet note). A follow-up continues the search: POST /agent/turn carries `summary` (on-screen
  category, clusters, OE, cotes, applicability), `pendingContext`, `vehicle.plate`, and `buildLabel`
  for a door-vignette photo; the previous parts stay ghosted until new ones arrive.
- **History**: logged-in home lists the last 10 journal searches (relative time; the blank PLANCHE
  entry sheet was removed 2026-10-15); rows carry a server `route`
  (`p/<plate>/c/<cat>` or `k/<carId>-<type>/c/<cat>`) + `vehicle`; restoring re-reads /narrowed
  (cached). Agent catalogue results replace the hash with their result route, so they are shareable.
- **PSA build code**: photo intake kind `build_label` (OPR/DAM verbatim), `decode_build_code` in the
  finder allowlist, clusters expose `applicability` (per ARTICLE, non-splitting); finder.md says to
  use it only between clusters that already differ, never as certainty.
- **Journal**: agent turns and deterministic searches in `agent_turns` (`surface`, `kind`), cache
  tokens, cost (Sonnet 5.5 prices in `.env`), partsapi calls per method vs the 50/day quota; drawer
  docks right ≥1280px.

Live checks done: "filtre à huile" on XB-301-ZB (6 s, 220 → 1); "filtre à huile mazda 6 2.0 2005"
without plate (16.6 s, $0.044, 3 partsapi calls) → correctly asks which of 4 versions (141 ch / 147 ch /
break / 2.0 DI) instead of guessing.

## Open

- Live-test the paths that cost money, with the user watching: real part photo, multi-part request
  ("courroie + pompe à eau"), web-found reference, the ask → answer round trip, and the PSA case
  "rotule axiale peugeot 307" → code de fabrication typed AND as a vignette photo (`build_label`).
  None of the 2026-10-15 agent paths ran live. The `?demo` mode was removed 2026-10-15 (user
  decision): captures use the real app on cached data.
- Applicability values are per article: check on real 307 data whether they discriminate at all
  between the clusters; if never, drop the question rather than keep a weak signal.
- Dimension labels can overlap for a moment when the part is seen edge-on (height vs thread note).
- A follow-up from a deterministic result switches to the agent sheet ("Recherche en cours") instead
  of keeping the deterministic sheet up until the agent answers.
- 100/220 articles stay unattached on the Mazda oil filter (their crosses don't reach the 2 reps
  called). Options: one more crosses call on a third rep, or accept and keep the quiet line.
- Mesh view on a real non-filter category not yet seen in the app (every uncached category costs
  partsapi calls); verified only on the dev contact sheet. No cotes are drawn on these meshes yet:
  each family would need its own anchor logic, from fingerprint data only.
- History rows restored from a category pick show the raw category id as their input (`100259`)
  instead of the category label (seen on the home capture, 2026-10-15).
- Carte grise warnings come from the server in English (`RegistrationDocumentExtractor`: `A: not in
  the AA-123-AA format.`) and print as is in the French card result.
- Oil caps (HeadAcc_OilCap, OilCap_Type1) have no web build: not shipped.
- Garage: picker only, no fleet management; several same-model cars are told apart by plate only.
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

- Shipped meshes are Wrench extracts (92 files, see `SOURCES.md`), user-approved for this private prototype
  (`finder/public/parts/SOURCES.md`). Only final blueprint GLBs go in the repo.
- Not available in Wrench: EGR, A/C compressor and condenser, coolant expansion tank, vacuum pump,
  timing chain, clutch slave cylinder, catalytic converter, gearbox body.
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
garage writes need the token.
