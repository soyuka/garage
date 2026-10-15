# Product Marketing Context

**Document version:** v2
**Last updated:** 2026-10-15

Scope: **finder** (`finder/`), the parts finder. The Atelier (`dashboard/`) and the garage are not covered here. Auto-drafted from the repo; every fact cites its source. Gaps are marked `[NEED: …]`.

## Product Overview
**One-liner:** Pour un véhicule donné, finder ramène le catalogue de pièces à la pièce qui monte : réf. OE, équivalences par marque, cotes. (`finder/ROADMAP.md` § State)
**What it does:** One input takes a plate (SIV or FNI), a VIN, a part reference, free text (part name, symptom, several parts) or a photo (carte grise, the part itself, the door-pillar build-code sticker). It narrows the fitment-correct TecDoc list into physical parts by their published dimensions and carmaker OE numbers. When parts genuinely differ it asks one question; otherwise it shows the part as a 3D line drawing with catalogue cotes, a title block (OE, equivalences, vehicle, kType, cotes, source), buy links, and "Ajouter au <véhicule>" into the owner's garage. (`finder/ROADMAP.md` § State, `finder/DESIGN.md` § Overview)
**Product category:** Recherche de pièces auto par véhicule (parts lookup / cross-reference). `[NEED: the shelf users search under — "catalogue pièces auto", "recherche référence pièce", "équivalence filtre"?]`
**Product type:** Web app, private prototype at `https://localhost/finder/`; "Private MVP (never sold)". (`finder/ROADMAP.md:3`)
**Business model:** None today. Going public requires a TecAlliance TecDoc licence and buying the 3D meshes; the current data source (partsapi.ru reseller) is "NOT public". (`finder/ROADMAP.md` § Data source) `[NEED: intended model if it goes public — free, subscription for garages, affiliate on buy links?]`

## Target Audience
**Target companies:** DIY owners first (particuliers, decision 2026-10-15), then independent French garages.
**Decision-makers:** The mechanic or the owner ordering the part; no buying committee.
**Primary use case:** Find the reference of the part that actually fits a given vehicle before ordering it.
**Jobs to be done:**
- Get the OE reference and its equivalents across brands for one vehicle.
- Settle which of several variants is fitted (engine version, PSA build code, a cote measured on the removed part).
- Go from what is in hand (carte grise, removed part, door sticker) to an orderable reference.
**Use cases:**
- Oil filter on a Mazda 6 2.0 by plate XB-301-ZB: 220 catalogue references / 126 brands → 1 part, OE LF05-14-302A, no question, 6 s (live, `finder/ROADMAP.md` "Live checks done").
- "filtre à huile mazda 6 2.0 2005" without a plate: asks which of 4 versions (141 ch / 147 ch / break / 2.0 DI) instead of guessing (live, 16.6 s).
- "rotule axiale peugeot 307": PSA build code typed or as a sticker photo (`build_label`). Not live-tested yet (`finder/ROADMAP.md` § Open).

## Personas
| Persona | Cares about | Challenge | Value we promise |
|---------|-------------|-----------|------------------|
| Mécanicien, garage indépendant | Ordering the right reference once; choosing brand by price/stock | Parts sites list every fitment-correct article; variants hide in criteria | OE ref + equivalences per brand + cotes from the plate `[NEED: confirm with a real mechanic]` |
| Particulier qui entretient sa voiture | Not ordering the wrong part | Does not know engine code / variant; has the car, the carte grise, the old part | Start from a photo; one question when the car is ambiguous `[NEED: confirm]` |

## Problems & Pain Points
**Core problem:** Finding the correct reference for a part. (`finder/ROADMAP.md:4`)
**Why alternatives fall short:**
- The catalogue answer is a long alphabetical list: 220 articles / 126 brands for one filter, all fitment-correct, cheapest no-name brands first. (`finder/ROADMAP.md` § What the partsapi data really is; memory `partsapi-article-cap-hides-brands`)
- Cross-reference lists are loose interchange lists (W 712/73 → 495 crosses, 186 brands), not equivalence classes. (same section)
- Parts sites are old and you still order the wrong one (brief). `[NEED: verbatim from a mechanic or forum]`
**What it costs them:** `[NEED: returns, immobilised car, second order, time at the counter — no data in repo]`
**Emotional tension:** `[NEED: verbatim]`

## Competitive Landscape
**Direct:** Oscaro, Autodoc, Mister-Auto (plate → catalogue list) — falls short because they list every fitment-correct article and leave the variant to the buyer (brief; finder links to them as shops, `finder/src/buy.ts:15-17`). `[NEED: confirm wording; they are also buy-link partners, so naming them as competitors in copy is a decision]`
**Secondary:** TecDoc-based pro catalogues used at the parts counter `[NEED: which ones the target uses]`
**Indirect:** Asking the parts counter / the dealer with the VIN `[NEED: confirm]`

## Differentiation
**Key differentiators:**
- Groups catalogue references into physical parts by published cotes and carmaker OE; shows ranges where variants differ. (`api/src/Catalog/Narrowing/`, ROADMAP § State)
- Asks one question when parts genuinely differ, never guesses a carId. (`api/config/prompts/finder.md` `<vehicle>`)
- Photo entry: carte grise, the part (reads stamped references first), door-pillar sticker (PSA build code). (ROADMAP § State)
- The part is a line drawing with catalogue cotes only, never estimated from the mesh. (`finder/DESIGN.md` § Don't)
**How we do it differently:** Narrows the catalogue for you instead of listing it.
**Why that's better:** One reference to check against the removed part, with the cotes to measure. `[NEED: outcome evidence]`
**Why customers choose us:** `[NEED: no users yet]`

## Objections
| Objection | Response |
|-----------|----------|
| "Encore un truc IA qui invente" | Cotes come only from the catalogue; references the catalogue does not confirm are tagged "Source : web" (`finder/ROADMAP.md` § State, `finder/DESIGN.md` § Voice bans `IA`/`intelligent`) |
| `[NEED]` | |

**Anti-persona:** `[NEED]` (vehicles absent from TecDoc are out of the catalogue path; motorcycles/garden machinery live in the garage, not finder)

## Switching Dynamics
**Push:** Long lists, wrong part ordered (brief).
**Pull:** One part with OE + equivalences + cotes; photo entry.
**Habit:** Their usual parts site / counter. `[NEED]`
**Anxiety:** Trusting a narrowed answer; account required for agent searches and photos (`clarify.md`: "Connexion requise pour cette recherche.").

## Customer Language
**How they describe the problem:** `[NEED: verbatim]`
**How they describe us:** `[NEED: verbatim]`
**Words to use:** réf., OE, cote, monte / montée, pièce démontée, pied à coulisse, carte grise, plaque, VIN, kType, numéro d'organisation, équivalences (`finder/DESIGN.md` § Voice "Workshop vocabulary verbatim")
**Words to avoid:** IA, intelligent, agent (user-facing), Je, exclamation marks, explanatory taglines ("Un seul champ :") (`finder/DESIGN.md` § Voice "Banned")
**Glossary:**
| Term | Meaning |
|------|---------|
| Réf. OE | Carmaker original-equipment number (e.g. Mazda LF05-14-302A) |
| Équivalences | Same physical part under other brands (MANN W 712/73, MAHLE OC 1182…) |
| Cote | Catalogue dimension drawn on the part (Ø, H, filetage, joint) |
| kType | TecDoc vehicle type id |
| Vignette / numéro d'organisation | PSA door-pillar sticker carrying the build code (OPR, after "DAM") |

## Brand Voice
| Attribute | Means | Doesn't mean | Example line |
|-----------|-------|--------------|--------------|
| Comptoir pièces | Writes like a parts counter or workshop manual | Assistant chatter, "Je", reassurance | `Plaque XB-301-ZB introuvable. Vérifiez-la ou envoyez la carte grise en photo.` |
| Noms plutôt que phrases | Labels and status lines are nouns | Cryptic codes for a DIY reader | `Affinage des références…` |
| Précis sur la donnée | Ranges when variants differ, no figure the catalogue lacks | Hedging chains | `120 réf. de 96 marques pour cette même pièce.` |
| Vouvoiement à l'impératif | `Saisissez…`, `Mesurez…` | Tutoiement, casual "on" | `Mesurez au pied à coulisse.` |

**Tone by context:**
- Support: n/a (private)
- Launch: `[NEED]`
- Errors: what happened, then what to do, two short sentences (`finder/DESIGN.md` § Voice)
- Sales: n/a
- Social: `[NEED]`
**Terms we own:** "finder" (lowercase, as in the top bar), Feuille de dessin / planche, cartouche (title block)
**Never use (and why):** `IA` / `intelligent` (user said the copy "sounds a lot like IA/LLM", 2026-10-15); rhetorical questions and exclamation marks (DESIGN.md § Voice).

## Proof Points
**Metrics:** Mazda 6 2.0 oil filter: 220 refs / 126 brands → 1 part (OE LF05-14-302A), 120 réf. from 96 brands attached to it, 100 unattached; 6 s by plate. Free-text Mazda 6 2005: asks among 4 versions, 16.6 s. (`finder/ROADMAP.md`, `.impeccable/review/desktop.png`) Single-vehicle examples, not averages: never state them as general rates.
**Customers:** none (private prototype)
**Testimonials:** none. `[NEED]`
**Value themes:**
| Theme | Proof |
|-------|-------|
| Narrowing | Mazda 6 filter 220 → 1 (live) |
| Honest uncertainty | asks which of 4 versions instead of guessing (live) |
| Catalogue-only cotes | DESIGN.md rule + title block "Source TecDoc" |

## Goals
**Business goal:** `[NEED: private tool for the owner, or validate demand before a TecDoc licence?]`
**Conversion action:** First search from the public home (vitrine): the composer or one of the three example chips. Agent searches and photos require login.
**Current metrics:** Journal tracks searches, tokens, cost per turn, partsapi calls vs 50/day quota (`finder/ROADMAP.md` § Journal). No conversion metrics.

## Learnings
*Decisions and tested lessons. Newest first. Keep it to about 20 active entries.*
| Date | Learning | Evidence | Applies to | Status |
|------|----------|----------|------------|--------|
| 2026-10-15 | Decision: the home is a public showcase (vitrine) for DIY owners first, hero = direction 2 "La réf. d’origine et ses équivalences.". "OE" was replaced by "d’origine" because particuliers can't parse it. No real plate on the home, catalogue and shops not named in the hero, chips run in one click. | User decision; shipped `c5a1a6a`, `.impeccable/review/homepage-copy.md` | homepage, all public copy | active |
| 2026-10-15 | Hero rejected: "Trouve ta pièce." Tutoiement and a generic imperative that any parts site could print. | User rejection, 1 review | homepage hero, tu/vous | active |
| 2026-10-15 | Hero rejected: "La bonne pièce, du premier coup." + "Plaque, VIN ou photo de la pièce." Outcome promise every competitor claims (fails the swap test); avoid "la bonne pièce". | User rejection, 1 review | homepage hero | active |
| 2026-10-15 | Hero rejected: "220 références. Une seule monte sur votre voiture." + "Donnez la plaque, on trie le catalogue." A one-vehicle number used as the headline, casual "on", and "votre voiture" (mechanics work on clients' cars). Keep the 220 → 1 figure as on-page proof, never as the headline. | User rejection, 1 review | homepage hero, proof usage | active |
| 2026-10-15 | Current hero "Quelle pièce, pour quel véhicule ?" / "Saisissez la plaque ou le VIN, puis la pièce." disliked by the user: says what to type, nothing about what comes back. | User feedback | homepage hero | active |
| 2026-10-15 | Copy must read like a parts counter, never like an assistant (no Je, no IA, no taglines). | User feedback "sounds a lot like IA/LLM"; `.impeccable/review/clarify.md` | all finder copy | active |

## Changelog
*Newest first. One line per revision: what changed and why.*
- v2 (2026-10-15) — Audience set to particuliers first and home to public showcase; logged the direction-2 hero decision in Learnings.
- v1 (2026-10-15) — Initial context, auto-drafted from `finder/ROADMAP.md`, `finder/DESIGN.md`, `.impeccable/review/clarify.md`; logs the three rejected hero proposals.
