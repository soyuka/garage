# finder — agentic parts finder

Private MVP (never sold). Flat frontend at https://localhost/finder/ on the existing `api/`;
`dashboard/` (the Atelier) stays. The pain it targets: finding the correct reference for a part.
Kept separate from the repo-root `FUTURE.md` on purpose. Design system: `finder/DESIGN.md`.

## State (2026-10-16)

Shipped, end to end:

- **Single input** (text / photo / video) over a blank drawing sheet. Local rules first
  (`finder/src/route-input.ts`): plate (SIV / FNI) → `GET /vehicles/{plate}`; VIN → `GET /vins/{vin}`
  (sunrise/vin, model year only for North-American VINs); reference shape → agent `intent=reference`;
  known category → `GET /cars/{carId}/part-categories/{id}/narrowed`; else agent turn. Photo/video →
  `POST /photo-intake` (one vision call: carte_grise | part | other; section C never extracted, file
  never stored); video = 3 frames sampled in the browser.
- **Product groups (2026-10-16)**: a category node can hold several TecDoc product groups (PT_ID +
  name, kept from getArticles). `/narrowed`, `narrow_parts` and `show_parts` take `productGroupId`;
  with several groups and none given they answer `groupRequired` / `choose_group` with the groups and
  spend no criteria/crosses call. The agent picks the group itself (finder.md `<product_groups>`,
  glossary) and checks every shown group against the request before end_turn (`<self_check>`); the
  result title is the product group name, never the agent's text. No group picker for the user: a
  restored route without `/g/` on a mixed category shows "Relancer la recherche" (original input to
  the agent). Routes: `p/<plate>/c/<cat>/g/<pt>`, `k/<carId>-<type>/c/<cat>/g/<pt>` (old ones valid).
  Meshes read the product group first; an inner axial joint gets the catalogue photo (no inner
  tie rod mesh ships), and so does PT 2066 « Rotule de direction » (2 unchecked articles: an
  unconfirmed mesh misleads). Copy icons (2026-10-16) sit beside each reference and copy that reference alone (the OE number without brand; an equivalence without its brand); no "Copier la réf." button.
- **Result clarity pass (2026-10-16, user review of the agent-retained 307 result)**: the « Affinage »
  trace is gone from the result (counts stay in the journal), the `?` stands alone, Véhicule/kType
  cells dropped (the chip says it), Source tag moved into Désignation, Cotes full width, title block
  600px from 1280px. The agent's verdict marks the rows (« Votre véhicule » + basis; the others « Ne
  correspond pas : <setAside> », muted, still selectable) and closes the cote question. Acheter /
  Ajouter / Copier le lien moved under the composer and act on the shown variant. The decoded build
  code rides with the car: `set_build_code` → `setBuildCode` frame → chip « OPR 09824 · oct. 2003 »,
  `sessionStorage` per car, sent back on every turn (the agent selects without asking), and written
  as garage facts `organisation_number` + `build_date` when the car is a garage row. Fold measured:
  1440×900 0 px overflow (unsettled, retained, single part); 390×844 designation + variant table + OE
  above the dock. Captures `.impeccable/review/clarity-*.png`. See DESIGN.md (Title Block, Variant
  table, Vehicle chip, Result actions).
- **Narrowing** (`api/src/Catalog/Narrowing/`): BrandTier top 8 reps → getArticleCriteria → physical
  fingerprint (canonical thread, synonym map, deny list of application-level keys, tolerance
  max(2 mm, 2.5 %)) → cluster → 1 getArticleCrosses per cluster → merge clusters sharing a carmaker OE
  → ranges + `differsOn`. Mazda 6 oil filter: 220 → 1 part, OE LF05-14-302A, no question.
  Sibling probe (2026-10-16): the best-ranked article of a represented brand that no member or cross
  claims is fingerprinted (+1 criteria, +1 crosses) and kept apart from a physically matching cluster
  when it shares no carmaker OE with it. Cluster applicability also unions the already-cached criteria
  of claimed variants (no call). OPR criteria ("pour numéro OPR …") now count as applicability.
- **Category choice by meaning (2026-10-16)**: keyword search failed ("rotule axiale" → 0 leaves,
  "rotule" → 5 mixed leaves incl. GEDORE wrenches under « Outils spéciaux > … > Rotules »). The agent
  no longer has `list_part_categories` (MCP tool unchanged for other surfaces). `FinderCategoryList`
  renders every leaf as `id name` under a `[parent breadcrumb]` header; it rides in the turn message
  when the vehicle is known and in the `set_vehicle` result otherwise (zero extra tool call, the
  Anthropic cache breakpoint on the last user block caches it for every later call of the turn).
  Excluded: top-level « Outils spéciaux », leaves named « Outils » (workshop tools under Direction,
  Moteur, Distribution, Refroidissement, Suspension), « Recherche d'un article par graphique » and
  « Graphique complet des pièces de carrosserie » (diagram navigation entries, not part families;
  their content is unverified, no getArticles cached). Kept: Accessoires, Portes-bagages, Pièces de
  maintenance (things a user may ask for). Peugeot 307 (carId 16182): 761 nodes, 610 leaves, 596
  listed under 146 headers, 24.9k chars ≈ 7.8k tokens (cl100k proxy; Claude's tokenizer runs higher,
  expect ~9–10k). The full path on every line was 43.8k chars ≈ 13.3k. Fixture:
  `getSearchTree_16182_PC.json` (trimmed real tree: Direction, Filtre, Outils spéciaux, Moteur >
  Distribution + the excluded leaf kinds).
- **differsOn between split clusters (2026-10-16)**: millimetre info measures (never splitting) are
  reported when clusters already split on something else disagree on them; packaging excluded,
  splitting criteria first. 307 axial joints (PT 51): « Longeur rotule [mm] » c1 322–338 / c2 358
  (BOSCH's spelling; DELPHI states 322 there). show_parts hands the agent per-cluster values, and
  finder.md has the OPR question offer the tape measure as the alternative.
- **Several parts on one sheet (2026-10-16)**: when `/narrowed` returns several clusters the title
  block opens with a variant table (`finder/src/ui/VariantTable.tsx`, `src/variants.ts`): one radio row
  per part (letter, carmaker OE, representative + count, the splitting `differsOn` cote as a range,
  OPR / organisation breakpoints). A row pick switches drawing, cotes, OE, Acheter/Ajouter and
  équivalences, lives in the URL as `/v/<clusterId>` and is labelled « choisie par vous », never
  confirmed; the cote question keeps `/k/<clusterId>` and « confirmé par la cote ». Multi-part title
  blocks add the carmaker's other OE numbers and every other catalogue cote; every row shows the OE in the numbering scheme all parts share for the make (`pickOe`, 3812 E0 / 3812 E5), the rest under « Aussi ». Multi-part blocks are shortened: équivalences on one line + « + N autres marques », « Pourquoi cette pièce » clamped to 2 lines (both expandable). At 1440×900 the actions end at y 871 (was 938), still under the composer dock when a question card is open (dock top ≈ 696). The sheet grows to fit a
  tall title block instead of clipping it. Question answers print the cluster's range (`differsOn`
  min/max), not its representative value. Design: `finder/DESIGN.md` § Variant table.
- **Agent selects a variant (2026-10-16)**: `show_parts` takes `clusterId`; an id outside that
  narrowing is refused (`clusterIds` listed, nothing drawn), a clusterId without the productGroupId on a
  mixed node comes back choose_group telling the agent to reuse the on-screen group, and redrawing a
  category already shown that turn no longer spends the 4-category cap. The frame carries
  `selection {clusterId, basis}`; the sheet opens that variant marked « retenue d'après <basis> »
  (route `/r/<clusterId>/<basis>`, DESIGN § Variant table, Agent selection). The follow-up summary
  names `productGroupId` next to `categoryId`, and finder.md tells the agent to reuse both. Chosen over
  a separate `select_part(clusterId, basis)`: turns are stateless, so that tool would need the
  category and group anyway to know which narrowing c1 belongs to (several parts per turn, c1 exists in
  each), and a new frame type acting on a part the page may not hold as an agent part (a held,
  route-driven result). One frame, one mapper, the validation done where the narrowing is at hand.
  The repeated group is made safe instead: the summary carries it, and a missing one is corrected.
  Fixes turn 01M520BZ7BSSB0Z36G1EVG66TV (decoded OPR 09824 ≤ 10310 → c1, screen kept « à confirmer »).
- **Agent-pick route hardened (2026-10-16, user: « harden this now »)**: the URL carried the agent's
  basis and set-aside phrase as free text (`/r/<clusterId>/<basis>/<setAside>`), so an edited share
  link could make the page claim any verdict, and that text also reached the agent in the follow-up
  summary. Now `show_parts clusterId` stores a `FinderSelection` (`api/src/Entity/FinderSelection.php`,
  `api/src/Agent/Finder/FinderSelections.php`; owner, turn id, car, category, group, cluster, basis,
  setAside, OPR) and the frame carries its ULID; routes are `/r/<clusterId>/<selectionId>` and
  `/v/<id>/r/<retained>/<selectionId>`. The page reads `GET /agent/selections/{id}` (anonymous, public
  fields only: no owner, plate, turn or OPR) and trusts it only for the car/category/group/cluster on
  screen; anything else, old text routes included, renders the neutral state. Other URL text checked:
  the plate is regex-normalised (`normalisePlate`/`normaliseFni`), the car ref, category and group
  are digits, cluster ids are only matched against the narrowing (never printed); nothing else from
  the URL is displayed. DESIGN § Variant table, Agent selection.
- **Build-code question (2026-10-16)**: finder.md now has the question cover both ways (« Code de
  fabrication (vignette de porte) ou longueur mesurée ? ») with options at the cluster ranges
  (322–338 mm / 358 mm), never a length answering a code question; a question naming the code de
  fabrication gets the finder's « Photo de la vignette » button (camera / picker → `build_label`
  intake) in place of « Donner la plaque ».
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
  unmapped categories show the catalogue photo or « ? » (below; the generic chamfered block was removed
  2026-10-16). Dev contact sheet:
  https://localhost/finder/dev/meshes.html (not in the build) (not in the build).
- **Catalogue photo where no mesh exists (2026-10-16)**: a part without a mesh shows its shown cluster's
  representative photo, pinned on the sheet (grayscale + multiply, framed, `Photo catalogue · <marque réf>`,
  never dimensioned), else a plain « ? ». API proxy `GET /cars/{carId}/part-categories/{categoryId}/articles/{artId}/photo`
  (`App\Catalog\ArticlePhotos`): getArticleMedia cached forever per artId, only for an article of an
  already cached listing (never spends quota on an arbitrary id), first https source only, fetched through
  `NoPrivateNetworkHttpClient`, MIME sniffed (jpeg/png/webp/gif), bytes cached forever in
  `cache.article_photos`. Proxy, not hot-link: the client never sees or sends an image URL (no SSRF
  surface, no viewer IP/referrer to the third-party host, one fetch per image ever). Live calls spent:
  2 (307 axial joint, artIds 112767557 and 112767504). **Blocked upstream**: `img.autodatabases.ru`, the
  host every media URL points to, was NXDOMAIN on 2026-10-16, so every photo currently falls back to « ? ».
  Re-check that host; if partsapi moved its images, the proxy follows whatever URL getArticleMedia returns. Title block = result card, Acheter / Ajouter au <véhicule> (409 → inline picker plate · model, re-post with the picked IRI or
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

- A garage car opened in a new tab does not show its `organisation_number` fact on the chip: GET
  /garage/vehicles carries no facts. Read it back (facts on the list, or one facts call on open) so
  the code also survives across sessions. A shared link does not carry the chip's OPR either.
- `set_build_code` and the `setAside` phrase have not run live with the model yet (fake-platform
  tests only): check the agent calls it instead of decode_build_code and fills setAside.

- Companion parts ("Souvent changé avec") as an agentic round — decided, not started; see repo-root `FUTURE.md` § "Finder: companion parts".

- Live-test the paths that cost money, with the user watching: real part photo, multi-part request
  ("courroie + pompe à eau"), web-found reference, the ask → answer round trip, and the PSA case
  "rotule axiale peugeot 307" → code de fabrication typed AND as a vignette photo (`build_label`).
  None of the 2026-10-15 agent paths ran live. The `?demo` mode was removed 2026-10-15 (user
  decision): captures use the real app on cached data.
- Checked on real 307 data (2026-10-16): the OPR applicability does discriminate the two inner axial
  joints (see below), so the build-code question stays.
- `Longueur` still never splits (only hauteur / diamètres do; supplier conventions vary). Since
  2026-10-16 it shows in differsOn once the carmaker OE has split the clusters. Two spellings stay
  separate keys (« Longueur » vs BOSCH « Longeur rotule »): DELPHI TA1776 states both with different
  values (338 / 322), so they are not one measure for every supplier. MEYLE / DENCKERMANN / COMLINE
  state 320–322 mm and land in the 338 mm cluster: unexplained, unverified.
- FEBI spells the thread "Dimension du filetage extérieur": not read as a thread (`THREAD` slug
  regex), so it is skipped. RIDEX's "Filetage extérieur [mm]" lands as an info measure.
- The rotule axiale agent path (choose_group → group 51 → OPR question → decode → show_parts
  clusterId) has not run live end to end; the selection, refusal and redraw paths are covered by
  scripted turns only (`FinderTurnTest`). The « Photo de la vignette » button is untested in a
  browser on a phone (the picker open relies on the click's transient activation through an effect).
- `FinderTurnTest::testFinderTurnRunsTheFinderAgentAndStreamsTheNarrowedParts` fails on `main`
  before 2026-10-16's selection work: it expects `list_part_categories` in the finder toolbox, which
  `FinderToolsTest::testTheFinderAgentHasNoKeywordCategorySearch` (and the leaf-list design) forbids.
- The leaf-list category choice (2026-10-16) has not run live; Claude-tokenizer size of the list unmeasured
  (no live call). Watch the journal's cache-write tokens on the first live turn.
- Dimension labels can overlap for a moment when the part is seen edge-on (height vs thread note).
- A follow-up from a deterministic result switches to the agent sheet ("Recherche en cours") instead
  of keeping the deterministic sheet up until the agent answers.
- 100/220 articles stay unattached on the Mazda oil filter (their crosses don't reach the 2 reps
  called). Options: one more crosses call on a third rep, or accept and keep the quiet line.
- Mesh view on a real non-filter category not yet seen in the app (every uncached category costs
  partsapi calls); verified only on the dev contact sheet. No cotes are drawn on these meshes yet:
  each family would need its own anchor logic, from fingerprint data only.
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

## Product groups inside one category (found 2026-10-16)

"rotule axiale" on Peugeot 307 XD-303-ZD (carId 16182 PC) showed two OUTER tie rod ends. Category
100198 "Direction > Rotules" holds 804 articles in three product groups: PT 914 « Rotule de barre de
connexion » ×514 (outer), PT 51 « Rotule de direction intérieure, barre de connexion » ×288 (inner
axial joint), PT 2066 « Rotule de direction » ×2. The narrowing ignored the group, so BrandTier's 8
reps all came from the outer group. Fixture: `getArticles_16182_PC_100198.json` (trimmed: tier 1/2
brands + 5 others per group).

Inside PT 51 every top brand sells two joints for this car, same M14×1.5 thread:

| | FEBI 19935 / BOSCH 0 986 141 079 | FEBI 29625 / BOSCH 0 986 141 133 |
| --- | --- | --- |
| length | 340 / 338 mm | 359 / 358 mm |
| carmaker OE (crosses) | PEUGEOT 3812.E0, 16 072 936 80 | PEUGEOT 3812 E5, 16 072 917 80 |
| OPR criterion | "pour numéro OPR jusqu’à" 10310 | "pour n° OPR (date de fabrication du véhicule)" 10311 |

One representative per brand only ever fingerprinted the first (alphabetical) article, so the E5
joints stayed unclassified; the sibling probe fixes that. Live narrowing of 100198 + PT 51 now gives
2 clusters (BOSCH 0 986 141 079 with FEBI 19935, BOSCH 0 986 141 133 with FEBI 29625 and 280477),
each with its OPR applicability: the agent asks for the code de fabrication. `differsOn` was empty
then; since 2026-10-16 it lists the joint length (see State).

## What the partsapi data really is (probed 2026-10-15, fixtures in `api/tests/Fixtures/partsapi/`)

- `getArticles`: 6 fields, fitment-correct, alphabetical — 220 articles / 126 brands for one filter.
- Crosses are a loose INTERCHANGE list, not an equivalence class (W 712/73 → 495 crosses, 186 brands,
  16 MAHLE numbers): never cluster on them. They do carry carmaker OE numbers, which is what merges.
- Criteria are per ARTICLE (union over every vehicle it fits; W 712/73 lists a Perkins engine code):
  physical criteria are reliable, dates / VIN breakpoints are not usable per car without per-linkage
  data (no key).
- Media (getArticleMedia): dropped 2026-10-15, back 2026-10-16 only where no mesh exists (see Shipped).

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
