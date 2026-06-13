# partsapi.ru — endpoint inventory & status

Base: `GET https://api.partsapi.ru/?method=<METHOD>&key=<KEY>&<params>`.
**One key per method** (`PARTSAPI_KEY_<method>` in `.env.local`). ~50 calls/method demo budget.
Verified behavior, error envelopes, codes: see `../../prototype_1/PARTSAPI.md` (source of truth).

Quick facts (verified): `carType` ∈ {`PC`,`CV`} (strings); `lang=4` = EN; `carId` = TecDoc kType
= SIV `tecdoc_car_id`. Error envelopes: `{code:9000,status:401}` missing params,
`{error_code:5002,status:401}` bad/disabled key for THAT method, `{error_code:5005,status:500}` bad param type.

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

AUTONORMS: GetNormsMakes, GetNormsModels, GetNormsMotors, GetNormsTimes, GetFillVolumes ✅.

Maintenance (ТО): toMakes, toModels, toTypes, toParts, toDoPass, toOils —
**abandoned route**: GetFillVolumes covers capacity+spec off kType in 1 call (verified).

Crosses/misc: FindEAN13, DecodeEAN13, PartSuggest, getCrosses, getCrossesTitle,
getCrossesWithBrand, getCarsByOilSpecification, getPartWeight, partNameSuggest,
getPartnameByBrandNumber, rusNameSuggest, carImpoundment.
