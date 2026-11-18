# garage.bonneref.fr deployment

Public showcase of mon-parc-auto (mpa): the MCP server, the Atelier and a demo account. Deployed 2026-10-31 from
`bd086f7` on `<server>` (the bonneref.fr host). Plan: `docs/showcase.md` §1-2. bonneref.fr itself: `docs/bonneref-extraction.md`.

## What runs where

| piece | where |
| --- | --- |
| mpa api (`api/`, `frankenphp_prod`) | container `bonneref-garage`, `127.0.0.1:5383`, env `~/bonneref/garage.env`, Mongo db `garage` on `bonneref-mongodb`, bills on `bonneref-minio` (`STORAGE_ADAPTER=s3`). The same container serves bonneref.fr `/garage/*` and garage.bonneref.fr. |
| Mercure hub | inside `bonneref-garage` (image Caddyfile `http://php:80` site; `MERCURE_HUB_UPSTREAM=127.0.0.1:80`), reached publicly as `https://garage.bonneref.fr/.well-known/mercure`. Private updates only: the subscriber token is the cookie `GET /agent/link` sets, so the hub must stay same-origin with the Atelier. |
| Atelier (`dashboard/`) | static build in `~/sites/garage.bonneref.fr`, served by the host Caddy |
| in-app agent turn | `AGENT_TURN_DISPATCH=terminate` (no worker, no Postgres; in-memory messenger DSNs) |
| scheduled tasks | container `bonneref-garage-scheduler`, same image and env, `messenger:consume scheduler_default` (Symfony Scheduler `default` schedule: hourly `app:turn-attachments:prune`). One consumer only, a second would run every task twice. |
| auth | Casdoor `casdoor` container behind `auth.bonneref.fr`, org `bonneref`. Atelier app "Mon Parc Auto" (client `671b8c86d75444f3f4c4`), redirect URI `https://garage.bonneref.fr/callback`. MCP clients self-register (anonymous DCR). |

`~/bonneref.compose.yml` is compose project `soyuka`, shared with unrelated containers: never `--remove-orphans`.

## garage.env, host-dependent keys

```
DEFAULT_URI=https://garage.bonneref.fr          # Mercure topic prefix (session + pilot topics)
MCP_ALLOWED_HOSTS=garage.bonneref.fr,bonneref.fr  # a missing host reads like an OAuth failure
MERCURE_PUBLIC_URL=https://garage.bonneref.fr/.well-known/mercure
SERVER_NAME=:80                                 # TLS ends at the host Caddy
SYMFONY_TRUSTED_PROXIES=private_ranges          # scheme/host for the protected-resource metadata
```

APP_SECRET is the server's own (it keys the pilot-topic HMAC), never the committed dev value.

## Host Caddy (`/etc/caddy/bonneref.fr.caddy`, `garage.bonneref.fr` block)

`/`, `/index.html`, `/callback` get `index.html` (the Atelier uses hash routing); any existing file under the site
root is served (hashed `/assets/*` cached immutable); everything else goes to `localhost:5383` (`/mcp`,
`/.well-known/oauth-protected-resource`, `/.well-known/mercure`, `/garage/*`, `/agent/*`, `/vehicles/*`). Responses
on `/.well-known/mercure*` are not compressed.

Both sites import the `(bonneref_security)` snippet (2026-11-10, backup `bonneref.fr.caddy.pre-headers-20261110`):
HSTS, nosniff, Referrer-Policy and a floor CSP (`frame-ancestors 'none'; object-src 'none'; base-uri 'self'`).
garage.bonneref.fr overrides it with the Atelier's full CSP, i.e. `api/frankenphp/Caddyfile` `@frontend` minus
the Vite preamble hash. A new external origin in the Atelier build needs adding there too.

## Casdoor (org `bonneref`), set 2026-11-10

- Token format `JWT-Empty` on every app and as the org `defaultTokenFormat` (DCR apps copy it): the `JWT`
  format shipped the password hash and salt. Claims kept: `email`, `email_verified`, `aud`, `sub`, `tokenType`, `displayName`.
- "Mon Parc Auto": grants `authorization_code,refresh_token` only; redirect URIs include `https://localhost/`
  and `https://garage.bonneref.fr/` for the post-logout redirect (Casdoor 4.20 matches scheme, host and path exactly).
- Accounts must have `emailVerified=true`, both apps refuse the email owner claim otherwise.

## Rebuilding

```bash
cd ~/apps/mon-parc-auto.fr && git fetch && git status   # deploy from origin/main
# api
docker compose -p soyuka -f ~/bonneref.compose.yml build bonneref-garage
docker compose -p soyuka -f ~/bonneref.compose.yml up -d --no-deps bonneref-garage bonneref-garage-scheduler
# Atelier: VITE_API_BASE must be the public origin, it is also the Mercure topic prefix
D=~/bonneref/dashboard-build-$(date +%Y%m%d); mkdir -p $D && git archive HEAD dashboard | tar -x -C $D
docker run --rm -u $(id -u):$(id -g) -e HOME=/tmp -v $D/dashboard:/app -w /app \
  -e VITE_API_BASE=https://garage.bonneref.fr -e VITE_MERCURE_URL=/.well-known/mercure \
  -e VITE_CASDOOR_ISSUER=https://auth.bonneref.fr -e VITE_CASDOOR_CLIENT_ID=671b8c86d75444f3f4c4 \
  node:22-alpine sh -c 'npm ci && npm run build'
rsync -a --delete $D/dashboard/dist/ ~/sites/garage.bonneref.fr/
# bonneref front: `vite build` defaults to base `/` (only the dev server uses /bonneref/), no env to set
F=~/bonneref/front-build-$(date +%Y%m%d); mkdir -p $F && git archive HEAD bonneref | tar -x -C $F
docker run --rm -u $(id -u):$(id -g) -e HOME=/tmp -v $F/bonneref:/app -w /app \
  node:22-alpine sh -c 'npm ci && npm run build'
grep -o 'src="[^"]*"' $F/bonneref/dist/index.html   # must start with /assets/
rsync -a --delete $F/bonneref/dist/ ~/sites/bonneref.fr/
```

## Demo account

- Casdoor user `bonneref/demo`, email `demo@bonneref.fr` (the mpa owner key). Credentials in `~/bonneref/demo.env`
  (mode 600), published in the README.
- Seed: the owner's live (non-deleted) vehicles with their maintenance records, planned tasks, facts (planned-task
  `attributes`) and schedules (`serviceSchedule`), re-owned to the demo email with fresh ULIDs (time prefix kept)
  and references rewired. Not copied: bill attachments (emptied), `vehicle_documents`, agent turns. Fact URLs that
  pointed at an uncopied document are nulled.
- Snapshot: `~/bonneref/demo-snapshot/garage/*.bson` (mongodump of the demo-owner docs).
- Reset: `~/bonneref/bin/demo-reset.sh`, run by `demo-reset.timer` (systemd, 03:30 Europe/Paris). It deletes every
  doc with `owner = demo@bonneref.fr` in every `garage` collection except `users`, removes the demo's uploaded files
  from MinIO, restores the snapshot, then re-asserts the Casdoor user's email and password.
  `demo-reset.sh --snapshot` re-captures the snapshot from the current demo docs. Never pipe its output into
  `head`: SIGPIPE kills it between the delete and the restore.

## Offline fixture

The offline demo (`bin/demo`) loads `api/fixtures/demo/` with `app:demo:load`. That fixture is exported from the
owner's live garage by a command, never edited by hand:

```bash
# in api/, against the dev stack; the mapping file holds the real plates/VINs and stays out of the repo
docker compose exec -T php php -d memory_limit=1G bin/console app:demo:export \
  --owner=<owner email> --mapping=php://stdin \
  --vehicle=01KVKMEFM6V36AWETKE7A9QCJ8 --vehicle=01KW7CFJP6Y0ARF5287B7Y9CAY --vehicle=01KVFEQA72XQMWSS3HPBD93ASR \
  --skip-document=01M6NJ7C6D8MJKMJE1F216FGFJ --skip-document=01M3Z4RDF4XETN3TXNH9YZ3P3D \
  < ../../rewrite-mapping.txt
```

It rewrites plates and VINs through the mapping, drops bills, quotes and the owner's email, merges the vehicles
of `api/fixtures/demo/extras.json` (the BMW F 800 GS Adventure), and refuses to write if a real plate, VIN or
mapped value is left in the JSON or in a document's text, or if the documents exceed 50 MB. The hosted demo is a
separate snapshot (above) and does not contain the `extras.json` vehicles.

## Verifying

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://garage.bonneref.fr/                     # 200
curl -si -X POST https://garage.bonneref.fr/mcp | grep -i www-authenticate                # resource_metadata=…
curl -s https://garage.bonneref.fr/.well-known/oauth-protected-resource                   # resource …/mcp, auth.bonneref.fr
curl -s -m 2 -o /dev/null -w '%{http_code}\n' 'https://garage.bonneref.fr/.well-known/mercure?match=x'  # 200 (SSE)
```

## Rollback

Everything changed on 2026-10-31 has a `pre-garage-20261031` copy: `~/bonneref/garage.env`, `~/bonneref.compose.yml`
(unchanged), `/etc/caddy/bonneref.fr.caddy`, `/etc/caddy/Caddyfile` (unchanged), `~/bonneref/dumps/garage-pre-garage-20261031`
(mongodump of db `garage`), `~/backups/casdoor-app-user-pre-garage-20261031.sql` (Casdoor `application` + `user` tables).

## 2026-11-13 deploy: catalogue with bikes + EN, API Platform 5.0.7

Deployed `89e7e36` (bonneref-api, bonneref-garage, both fronts). The catalogue file mounted by bonneref-api
(`~/src/bdf2sql/catalog/catalog.sqlite`) is now the former `catalog-full.sqlite`: the 2026-10-30 file plus motorbike
tables (`derived_mc`) and English texts (`derived_en`), 24,055,025,664 B. The previous file is
`catalog-20261030.sqlite` (rollback: swap the two names back and `up -d --no-deps bonneref-api`); `catalog-prev.sqlite`
is the 2026-10-21 one. Its `PRAGMA quick_check` was still running at deploy time (`enqc.log`). Swapping the file
needs the container recreated (`up -d`), the bind mount pins the inode. `bonneref.fr/mcp` is not routed by the host
Caddy (405); the container answers it on `127.0.0.1:5382`.

## 2026-11-14 deploy: uploads, prompt attachments, scheduler

Deployed `02e2816` (bonneref-garage, Atelier): signed-out visitors go straight to sign-in, document and bill uploads
back in the Atelier, files attached to an in-app turn (`turn_attachments` on the S3 bucket, 24 h unless filed).
Added the `bonneref-garage-scheduler` service to `~/bonneref.compose.yml` (backup
`~/bonneref.compose.yml.pre-scheduler-20261114`). `debug:scheduler` in that container lists the hourly prune.

## 2026-11-15 deploy: security review fixes

Deployed `be43d2e` (bonneref-api first, then bonneref front, bonneref-garage + scheduler, Atelier), review in
`.claude/security-analysis-2026-11-14.md`. Env added: `CASDOOR_ORGANIZATION=bonneref` on both apps,
`MPA_SERVICE_CLIENT_SECRET` and `MERCURE_SUBSCRIBER_JWT_SECRET` in `garage.env` (backups `*.pre-secfix-20261115`).
Casdoor: new app `bonneref/mpa-service` (client id `466fc97138f0f6348ec3`), grant `client_credentials` only, signup
off; its token carries `owner: admin`, `sub: admin/mpa-service`, `azp`/`aud` = client id, no email, no password field
(token format left at `JWT`, checked 2026-11-15). This is a cutover: the new bonneref refuses forwarded user tokens.

Verified on prod: anonymous subscribe is 401 on both hubs for `match=*`, `match_urlpattern` and an exact topic
(Mercure 1.x takes `match`, a `topic` parameter is a 400 for an authorised client); signed in, the Atelier cookie
opens its pilot, fleet and session topics, bonneref's `subscriberToken` opens its session topic via
`Authorization: Bearer`; bonneref's front no longer keeps a token in localStorage; mpa → bonneref `/makes` with the
service token + `X-Bonneref-Owner` is 200, without a token 401.

## 2026-11-18 deploy: SIV HTTP 404 = unknown plate

Deployed `d5c23df` (bonneref-api only, also ships `40949d0` pdfinfo). The SIV vendor answers an unknown plate with
HTTP 404 + the usual envelope (`code_erreur: 404`, "Plaque immatriculation inconnue !"); motorbike plates land there,
the vendor has no motos. bonneref used to turn it into a 502; now `/vehicles/DF162TC` is 404, negatively cached
(second call 32 ms, no vendor hit), verified with the mpa-service token.

Then `0475910` (bonneref-garage + scheduler; bonneref-api already had `40949d0`): upload security fixes, attachment
prompt cache, `UPLOAD_GLOBAL_RATE_LIMIT=600`, `/agent/turn` body cap 52 MiB, pdfinfo page count in bonneref.
Then `e7956f4` (bonneref-garage + scheduler): a plate miss tells the model the SIV registry has no motorbikes and to
resolve make/model via list_makes → list_models → list_cars (carType MC); a transient upstream error names the real
failure (status or transport error) instead of "did not answer in time".
