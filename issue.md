# JSON-LD ItemNormalizer silently drops some read-only properties from the output

## Summary

On a custom (non-Doctrine) API resource backed by a state provider, the JSON-LD
serializer omits certain `readable` properties from the GET output **even though their
values are set, non-null, and the properties appear in the OpenAPI/Hydra schema**. The
properties are dropped at normalization time, not at metadata time. No error is raised —
the keys are simply absent from the response body.

Confirmed environment-independent: it reproduces in a clean test environment (fresh kernel,
rolled-back DB), so it is **not** a stale-cache artifact. `cache:clear` + worker restart do
not change it.

## Environment

- `api-platform/metadata` **v4.3.13**, `api-platform/json-schema` **v4.3.13**
- `api-platform/doctrine-orm`, `hydra`, `jsonld`, `openapi`, … **v4.3.10** (mixed)
- PHP 8.5.7, Symfony 7.x with the **new `symfony/type-info`** (legacy
  `Symfony\Component\PropertyInfo\Type` is gone — referencing it throws `Class not found`)
- Format: `application/ld+json` (the only normalization format enabled besides `html`)

## Affected resource

`api/src/ApiResource/GarageVehicle.php` — a plain DTO resource (no Doctrine), hydrated by
`api/src/State/GarageVehicleProvider.php` (provider sets the array properties after
`fromEntity()`), with a `Patch` operation.

## Observed behavior

`GET /garage/vehicles/{id}` (`application/ld+json`) returns:

```
@context, @id, @type, id, make, model, year, vehicleType, source, plate, vin,
usageValue, usageUnit, factCount, addedAt
```

Missing from the body although declared on the class and set by the provider:

| property | type | value at serialize | in OpenAPI schema? | readable (metadata)? | in body? |
|---|---|---|---|---|---|
| `facts` | `array` (`list<array{id,...}>`) | `[{...}]` (non-empty) | yes | `true` | **NO** |
| `openTasks`¹ | `int` | `1`/`4` (non-null) | yes | `true` | **NO** |
| `plannedTasks`¹ | `array` (`list<array{@id,...}>`) | `[{...}]` | yes | `true` | **NO** |
| `kType` | `?int` | `null` | yes | `true` | NO (skip-null, expected) |
| `factCount` | `int` | `0` | yes | `true` | yes |
| `make`/`model`/`vin`/… | scalar | set | yes | `true` | yes |

¹ `openTasks`/`plannedTasks` have since been removed from this resource (moved to a
subresource) as a workaround; they were dropped identically while present.

The metadata is correct: a `CachedPropertyMetadataFactory::create()` probe reports
`readable=true, writable=false` for every one of these, and the `GarageVehicle.jsonld`
**and** `GarageVehicle.html` OpenAPI schemas both list all of them.

## Reproducing test (currently red)

`api/tests/Functional/GarageVehiclePatchTest.php::testItemGetExposesFactsWithProvenance`
(line ~88) — asserts the item GET carries `facts` with provenance. Fails: `facts` absent.

```
Failed asserting that an array has the subset Array &0 [ 'facts' => Array &1 [ ... ] ].
```

Verified pre-existing and unrelated to the surrounding checklist refactor (fails identically
with all other changes stashed).

## Inconsistency that should localize the cause

A sibling resource, `api/src/ApiResource/PlannedTask.php`, has array properties with the
**same** attribute shape (`#[ApiProperty(writable: false, description: '...')] public array
$parts/$target`) and they **serialize fine** in its own `GET /garage/planned-tasks/{id}`
(verified live). So the drop is not purely a function of the property declaration — it
differs between two resources whose properties look identical.

A possibly-relevant difference: `GarageVehicle`'s dropped properties are **set by the
provider after `fromEntity()`** (`$resource->facts = ...`), whereas `PlannedTask` is fully
built inside `fromEntity()`. Both resources expose a `Patch` operation.

## Ruled out (each tested)

- **Stale cache** — reproduces in clean test env; `cache:clear` + worker restart no-op.
- **Metadata / not readable** — `readable=true`; present in both jsonld and html schemas.
- **`skip_null_values`** — explains `kType` (null) only; `openTasks=4` and non-empty
  `facts`/`plannedTasks` are non-null and still dropped.
- **`writable: false`** — removing it entirely does not restore `facts`.
- **explicit `readable: true`** — adding it does not restore `facts`.
- **complex phpstan `@var` array-shape** — simplifying `facts` to
  `@var array<int, array<string, mixed>>` does not restore it.
- **the `@id`/`id` key inside the array-shape `@var`** — `openTasks` (a bare `int`, no
  `@var`) is dropped too, so this is not the sole trigger.

## Impact

- The dashboard cannot read the open checklist or facts from the vehicle item. Worked around
  by exposing the checklist as a subresource (`GET /garage/vehicles/{id}/planned-tasks`); the
  "Données" (facts) panel is still affected when facts exist.
- `GarageVehicleView::toArray()` (the Mercure push, a hand-built array) carries everything
  correctly — so the divergence is specifically in the resource→JSON-LD normalizer path.

## Next steps to pin it (for a maintainer)

1. Dump what `AbstractItemNormalizer::getAttributes()` returns for `GarageVehicle` under the
   `_api_/garage/vehicles/{id}_get` operation — is `facts` in the returned attribute set?
2. If present in the attribute set, trace `getAttributeValue()` / the `skip_*` context flags
   and the `type-info` types resolved for the `array` properties.
3. Compare against `PlannedTask` (which works) to isolate the provider-set-vs-fromEntity-set
   difference.

## Files

- Resource: `api/src/ApiResource/GarageVehicle.php`
- Provider: `api/src/State/GarageVehicleProvider.php`
- Working sibling: `api/src/ApiResource/PlannedTask.php`
- Red test: `api/tests/Functional/GarageVehiclePatchTest.php::testItemGetExposesFactsWithProvenance`

---

## ROOT CAUSE (diagnosed 2026-06-18)

**Not an API Platform serializer / type-info / `writable: false` bug. It is a stale metadata
cache that `cache:clear` does not invalidate.** The fresh metadata is correct; the serializer
reads a stale cached property list.

### How it was pinned

1. Re-added a non-null `int` property set after `fromEntity()` (`openTasks = 7`) → dropped from
   the body, reproducing the report. `facts` was present once the metadata cache was rebuilt.
2. The `provider-set-vs-fromEntity-set` hypothesis is **false**: an `int` set inside `fromEntity`
   was dropped too, while another `int` set inside `fromEntity` (`factCount`) survived. The
   differentiator is *which* property, not where it is set.
3. Probed the metadata factories directly with `operation_name` options →
   `PropertyNameCollectionFactory::create()` returned the **full, fresh** list (all new props,
   `readable=true`, native types resolved). Metadata is fine.
4. Dumped `AbstractItemNormalizer::getAllowedAttributes()` at runtime → the serializer's
   `propertyNameCollectionFactory->create()` returned a **stale** list missing the new props.
   The serializer calls the factory with a *different* options array (from `getFactoryOptions()`)
   → different cache key bucket → it hit a stale cached `PropertyNameCollection`. New readable
   properties are silently absent, which is exactly the no-error symptom.
5. `rm -rf var/cache/<env>` fixes it. `bin/console cache:clear` and
   `bin/console cache:pool:clear cache.system` do **not** (verified empirically, repeatedly).

### Mechanism

- `api_platform.cache.metadata.property` (the `PropertyNameCollection` cache) is backed by
  `cache.system` — a `PhpFilesAdapter` under `var/cache/<env>/pools/system/<namespace>/`.
- That `<namespace>` is the **container build hash**. It only changes when a *tracked* container
  resource changes (config/service files, or a resource file being added/removed) — **not** when
  the body of an existing `ApiResource` class is edited (adding a property to an existing file
  does not change the tracked directory listing, so the container is considered fresh and is not
  rebuilt).
- The metadata cache **key** carries no source-version/mtime component. So: edit an existing
  resource → same namespace + same key → the stale `PropertyNameCollection` is served forever.
- `cache:clear` rebuilds the container but does not purge pool *items* in the unchanged
  namespace; `cache:pool:clear` clears the CLI build's namespace, which is often not the one the
  app/worker reads (15 orphaned namespace dirs were observed). Only physically deleting
  `var/cache` removes the stale namespace dir.
- **Worker mode (FrankenPHP/Symfony runtime) makes it worse:** the container and warmed metadata
  live in memory for the whole worker lifetime, and a restart re-reads the same stale persistent
  pool — matching the reporter's "worker restart was a no-op". Wiping `var/cache` *before* the
  restart is required.

### Why `PlannedTask` looked unaffected

Coincidence of cache state, not the property declaration. Its properties were warmed into the
cache while present; `GarageVehicle`'s later-added properties landed under an already-populated,
never-invalidated namespace.

### Workaround / fix options

- **Immediate:** `rm -rf api/var/cache/<env>` after editing a resource (then restart the worker).
- **CI/test:** ensure the test bootstrap wipes `var/cache/test` rather than relying on
  `cache:clear`.
- **Durable:** make resource edits invalidate the metadata namespace — e.g. track the
  `ApiResource`/`Entity` paths as content-sensitive container resources, or front the
  `api_platform.cache.metadata.*` pools with a non-persistent adapter in dev/test. (Decision
  pending — flagged for the user.)
