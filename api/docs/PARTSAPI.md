# partsapi.ru — endpoint inventory & status

Base: `GET https://api.partsapi.ru/?method=<METHOD>&key=<KEY>&<params>`.
**One key per method** (`PARTSAPI_KEY_<method>` in `.env.local`). ~50 calls/method demo budget.
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

## Wanted next (user can provide tokens)

| Method | Why |
|---|---|
| getModels | step 2 — brand/model browse entry |
| getCars | step 3 — modifications + kType for browse entry |

## Full catalog (from partsapi.ru store, for reference)

TecDoc 2025 Q4: getMakes, getModels, getCars, getSearchTree, getArticles, getArticle,
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
