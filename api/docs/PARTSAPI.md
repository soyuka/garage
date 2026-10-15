# partsapi.ru — endpoint inventory & status

Base: `GET https://api.partsapi.ru/?method=<METHOD>&key=<KEY>&<params>`.
**One key per method** (`PARTSAPI_KEY_<method>` in `.env.local`). Test keys: 50 calls/day/method.
Verified behavior, error envelopes, codes: see `../../prototype_1/PARTSAPI.md` (source of truth).

Quick facts (verified): `carType` ∈ {`PC`,`CV`} (strings); `carId` = TecDoc kType
= SIV `tecdoc_car_id`. Error envelopes: `{code:9000,status:401}` missing params,
`{error_code:5002,status:401}` bad/disabled key for THAT method, `{error_code:5005,status:500}` bad param type.

**Language** (`PARTSAPI_LANG`, default `6` = Français; `4` = English GB): the `lang` param sets the
group/article/path text language. It is injected into `App\Catalog\PartsCatalog` and is part of the
partsapi cache key, so switching languages re-fetches each method once (the imported lang=4 prototype
cache is not reused at lang=6). `App\Catalog\ServiceKind` search terms carry both FR + EN strings so
category matching works in either language. NOTE: the exact French TecDoc group names still need
confirming against a live `getSearchTree(lang=6)` — the EN terms are the working fallback until then.

**Brand ranking**: `getArticles` returns articles fitment-correct but **alphabetical by brand** (a
popular car's oil filter = 220 articles / 126 brands). `App\Mcp\ArticleGroup` groups by brand and
ranks via `App\Catalog\BrandTier` (curated OE/aftermarket tiers) so MANN/MAHLE/BOSCH/PURFLUX surface
instead of the alphabetically-first no-names. MCP/agent path only; REST stays full + paginated.

## Tokens we have

| Method | Use here | Notes |
|---|---|---|
| getMakes | `/makes`, `list_makes` | step 1 manufacturers — **verified live 2026-06-12**: takes `carType`, returns camelCase `[{makeId, makeName}]` (NOT TecDoc MFA_*), 536 makes for PC. 1 call spent, cached forever. |
| getSearchTree | `/cars/{carId}/part-categories` | step 4 group tree, filtered by kType applicability |
| getArticles | `…/part-categories/{id}/articles` | step 5; key `"PRODUCT GROUP"` contains a space |
| GetFillVolumes | `/cars/{carId}/fill-volumes` | AUTONORMS; same kType; RU titles, dup RU units filtered |

## Narrowing methods (verified live 2026-10-15, "TecDoc 2026 Q2")

Keys `PARTSAPI_KEY_getArticleCriteria` / `_getArticleCrosses` (`getArticleMedia` was verified too but is not used: the finder shows its own 3D blueprint, not TecDoc photos). Test keys are capped at
**50 calls/day/method**; a refusal is `{error_code:5005,status:500}`. Unlike `getArticles`, these three take
UPPERCASE params `ART_ID` and `LANG` (`getArticles` uses lowercase `lang`). They are article facts, cached
forever (not in `PartsApiClient::CATALOG_METHODS`).

| Method | Returns | Gotchas |
|---|---|---|
| getArticleCriteria | `[{CRITERIA_NAME, CRITERIA_VALUE}]` | **Per ARTICLE, not per vehicle**: the union over every vehicle the article fits (MANN W 712/73 lists engine code "Perkins 404D-22", multi-valued "Année à partir de", VIN breakpoints). Application-level criteria cannot discriminate for one car; only physical ones (dimensions with a `[unit]`, thread, filter type) can. Names are truncated at ~45 chars upstream (`Diamètre intérieur de la bague d'étanchéité [`). Thread is spelled `Filetage "3/4-16 UNF"` on one brand and `Taraudage/Filetage 1 "3/4\"-16"` on another. |
| getArticleCrosses | `[{ART_BRAND, ART_DISPLAY_NR, ART_ID\|null, ART_PRODUCT_NAME}]` | A loose INTERCHANGE list, not an equivalence class (W 712/73: 495 crosses, 186 brands, 16 different MAHLE numbers), so never cluster on it. It does carry OE numbers: entries whose brand is a vehicle maker (MAZDA LF10-14-302, FORD 1S7G-6714-CA, VOLVO 30711781). |

Fixtures: every `getArticleCriteria_<id>.json` / `getArticleCrosses_<id>.json` is a real response, exported from
the live cache for the 8 representatives of the Mazda 6 oil filter (kType 19107, category 100259). Dates and VIN
breakpoints are not available per car through these methods.

Narrowing rules (`App\Catalog\Narrowing`): only `hauteur`, outer diameter (bare `diametre` = outer), the two seal
diameters, `thread`, `type_de_filtre` and the discrete physical keys split parts; numbered diameters, packaging, weight,
torque and valve pressure are suppliers' own conventions and stay info-only. mm keys agree within max(2 mm, 2.5%).
Clusters whose crosses share a CARMAKER OE number (`make` input, normalised without spaces/dashes/dots/slashes) are
merged: on the Mazda 6 the 85 mm and 93 mm filters both cross to MAZDA LF10-14-302, so it is one part with
85-94 mm range fingerprints, not a question.

`App\Catalog\Narrowing\ArticleNarrower` (MCP `narrow_parts`, `GET /cars/{carId}/part-categories/{categoryId}/narrowed`)
spends at most 8 criteria + 1 crosses per initial physical cluster, then nothing once cached. A quota refusal
degrades the answer (`narrowed: false`, `degradedReason`) instead of failing it.

## Browse methods (verified live 2026-10-15)

| Method | Params | Response |
|---|---|---|
| getModels | `makeId`, `carType` (PC\|CV), `lang` (`manuId` is rejected: "Error converting request parameters") | `[{makeId, modelId, modelName, shortModelName, shortModelName2, yearsFrom, yearsTo}]`, camelCase, dates RFC 1123 (`Thu, 01 Aug 2002 00:00:00 GMT`). Mazda (72) = 247 rows. The same modelId can repeat with a different name (6987: "6 ULTRA…" / "ATENZA…") and some rows are exact duplicates: `PartsCatalog::models()` collapses on modelId + modelName. |
| getCars | `makeId`, `modelId`, `carType`, `lang` | `[{BODY, CAPACITY "1999/2.0 l", CAR_ID, CAR_NAME, CAR_TYPES, ENGINE_TYPE, FULL_MODEL_NAME, MAKE_ID, MAKE_NAME, MODEL_ID, MODEL_NAME, POWER_KW "104.0000", POWER_PS "141.0000", YEAR_START, YEAR_END}]`, UPPER_SNAKE, same date format. Mazda 6 Hatchback GG (4887) = 14 rows. `CAPACITY` is `cm3/litres`, mapped to `capacityCc`. |

Fixtures: `getModels_72_PC.json`, `getCars_72_4887_PC.json` (real responses). Dates are exposed as `yyyy-mm`.

## Full catalog (from partsapi.ru store, for reference)

TecDoc 2026 Q2: getMakes, getModels, getCars, getSearchTree, getArticles, getArticle,
getArticleMedia, getArticleCrosses, getArticleCriteria, getApplicability, getApplicability2,
getFullInfoCV, searchArticles, tecdocCrosses, getEngine, getPassengerCarInfo, getOEApplicability.

VIN: VINdecode, decodeVINus, VINdecodeOE, getPartsbyVIN, gosnomer2vin (RU plates only).

VIN, verified 2026-10-14 on a 307 1.4 KFW (`VF33CKFWB83471925`), keys in `.env.local`:
- `VINdecode` (`vin`, `lang` both required; `lang=fr` → `{"statusMsg":"Failed"}`, `lang=en` works).
  Matches on the 8-char VIN prefix only (`vin8`) and echoes ANOTHER car's VIN: returned kType 16182
  (307 3A/C 1.4 75 ch, 08/2000–09/2003) for a car first registered 09/2004. No build date, no PSA RPO.
- `VINdecodeOE` → `{"data":{"Schema":null,"Vehicles":null},"status":"success"}`: no PSA coverage.
- `getArticles` payload is 6 fields (ART_ID, brand, number, group, PT_ID, SUP_ID): no fitment criteria,
  so TecDoc "jusqu'à / à partir de n° d'organisation" splits need `getArticleCriteria` (no key yet).

AUTONORMS: GetNormsMakes, GetNormsModels, GetNormsMotors, GetNormsTimes, GetFillVolumes ✅.

Maintenance (ТО): toMakes, toModels, toTypes, toParts, toDoPass, toOils —
**abandoned route**: GetFillVolumes covers capacity+spec off kType in 1 call (verified).

Crosses/misc: FindEAN13, DecodeEAN13, PartSuggest, getCrosses, getCrossesTitle,
getCrossesWithBrand, getCarsByOilSpecification, getPartWeight, partNameSuggest,
getPartnameByBrandNumber, rusNameSuggest, carImpoundment.
