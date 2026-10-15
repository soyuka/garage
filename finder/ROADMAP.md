# finder — agentic parts finder

Private MVP. New flat frontend on the existing `api/`; `dashboard/` (the Atelier) stays as is. The
pain it targets: finding the correct reference for a part. Kept separate from the repo-root
`FUTURE.md` on purpose.

## MVP scope (decided 2026-10-15)

1. `finder/` Vite service, same-origin behind Caddy at `/finder*` (see "Serving" below).
2. Entry points: plate (`GET /vehicles/{plate}`, anonymous), VIN (`GET /vins/{vin}`, sunrise-php/vin,
   no upstream call), carte grise photo (`POST /registration-documents/extract`, Sonnet 5.5 vision,
   section C never extracted, file never stored).
3. Results streamed as a new Mercure frame (`showArticles`) from an agent turn.
4. Blueprint wireframe per part family: parametric three.js first, vetted CC-BY meshes later.

A VIN gives make/plant/year at best, never a TecDoc kType: the plate (SIV) stays the path to a kType.

## Data source: partsapi.ru (accepted for the private MVP)

- Reseller labelled "TecDoc 2025 Q4" (`api/src/PartsApi/PartsApiClient.php:28`). TecAlliance cut
  Russia/Belarus off 2022-04-20 and exited Q4 2023, so this is most likely an unlicensed quarterly
  snapshot with no update schedule. Fine for a private MVP, NOT for anything public.
- Our cache froze it a second time (`default_lifetime: 0`, `api/config/packages/cache.yaml`):
  getArticles/getSearchTree now get a TTL (`PARTSAPI_CATALOG_TTL`, 30 days).
- Going public = TecAlliance TecDoc Web Service / Data Package licence (no public price, negotiated
  yearly; "Restricted Access" = logged-in users only). Sources:
  https://www.tecalliance.net/tecdoc-data-user/ ,
  https://a.storyblok.com/f/297549/x/00d0febb87/gtc-en.pdf
- Scraping Oscaro/Autodoc/Mister-Auto: no (French database law, sui generis right).
- Manufacturer catalogs (MANN daily-updated, 210k cross-refs) have no public API found.

## The real problem: too many results

`getArticles` returns 6 fields (ART_ID, brand, number, group, PT_ID, SUP_ID), fitment-correct but
alphabetical — a popular car's oil filter is 220 articles / 126 brands (`api/docs/PARTSAPI.md`).
`BrandTier` + `ArticleGroup` rank brands, but that only reorders the pile. Narrowing needs:

- **Cross-reference clustering** (`getArticleCrosses` / `getOEApplicability`): 220 articles are a
  handful of distinct physical parts sold under many brands. Collapse to "N distinct parts", each
  with its OE number and its brand variants.
- **Fitment criteria** (`getArticleCriteria`): front/rear axle, disc diameter, "à partir de / jusqu'à
  n° d'organisation" splits. Distinct parts that survive clustering differ on a criterion: the agent
  asks ONE discriminating question (or reads it from the carte grise / build code) instead of listing.
- **Media** (`getArticleMedia`): dropped 2026-10-15; the product shows the 3D blueprint, not TecDoc photos.

Keys obtained 2026-10-15 — TEST keys, 50 calls/day/method/device; partsapi now labels them
"TecDoc 2026 Q2". Live probe on Mazda 6 oil filter (kType 19107, cat 100259), fixtures in
`api/tests/Fixtures/partsapi/`:

- Crosses are a loose INTERCHANGE list, not an equivalence class: W 712/73 → 495 crosses, 186 brands,
  16 different MAHLE numbers. Clustering on crosses merges different-sized filters. They do carry OE
  numbers (MAZDA LF10-14-302, FORD 1S7G-6714-CA, VOLVO 30711781). 106/220 car articles are crosses.
- Criteria are per ARTICLE (union over every vehicle it fits: W 712/73 lists a Perkins engine code).
  Physical criteria are reliable (W 712/73 Ø76 H93 vs PURFLUX LS1176 Ø76 H85 → two distinct parts);
  dates / VIN breakpoints are not usable per car without per-linkage data (no key).
- Media: one webp per article on img.autodatabases.ru.

Algorithm (in progress): BrandTier top 8 reps → criteria → cluster on physical fingerprint → 1 crosses
per cluster for OE numbers + long-tail assignment → 1 media per cluster → `differsOn` drives the one
question. Article facts cached forever; degrades to the ranked list on quota exhaustion.

## 3D assets (researched 2026-10-15)

- No game is a legitimate source. Part-level meshes exist only in proprietary games (Car Mechanic
  Simulator, My Summer Car, Wrench, BeamNG — BeamNG EULA §3.3/§4.2 explicitly forbid reuse,
  https://beamng.com/game/support/policies/eula/). Open games (Speed Dreams, VDrift, TORCS) ship
  car bodies only.
- Parametric (three.js `LatheGeometry`/`TubeGeometry` + `EdgesGeometry` wireframe): filters, brake
  disc, spark/glow plug, coil spring, bearing, thermostat, pulley, shock body.
- CC-BY Sketchfab candidates (re-verify author + licence, skip brand-named models, attribution page):
  tie rod end https://sketchfab.com/3d-models/none-08006a773eb345bab3a384b71185aa9e ,
  control arm https://sketchfab.com/3d-models/none-bc35286fb7554943a9a2f156df0ab378 ,
  coilover https://sketchfab.com/3d-models/none-92e61757780840a683abdd38c4e8a700 ,
  caliper https://sketchfab.com/3d-models/none-5feda9dbf20a49cf9fd29d550e55e4b9 ,
  CV joint https://sketchfab.com/3d-models/none-5374202ad612455ab0ade980bd507cc0 .
- Nothing found: wheel hub, silentbloc → model in Blender.
- GrabCAD / TraceParts / manufacturer CAD: licence forbids republishing as web meshes.

## Serving

`api/frankenphp/Caddyfile` proxies HTML to `{$FRONTEND_UPSTREAM:dashboard:5173}` via `@frontend`.
Add a `finder` service in `api/compose.override.yaml` (Vite `base: '/finder/'`), a
`handle /finder* { reverse_proxy finder:5173 }` before `@frontend`, exclude `/finder*` from it, and a
Casdoor redirect URI in `init_data.json`. Catalog reads are anonymous; agent turns and carte grise
extraction need the Casdoor token. Reusable from `dashboard/`: `agent.ts` (turn call), `useMercure.ts`
(EventSource), the `UIAction`/`MercureFrame` unions in `dashboard/src/types.ts:337-402`.
