# garage.bonneref.fr deployment

Public showcase of mon-parc-auto (mpa): the MCP server, the Atelier and a demo account. Deployed 2026-10-31 from
`bd086f7` on `<server>` (the bonneref.fr host). Plan: `docs/showcase.md` §1-2. bonneref.fr itself: `docs/bonneref-extraction.md`.

## What runs where

| piece | where |
| --- | --- |
| mpa api (`api/`, `frankenphp_prod`) | container `bonneref-garage`, `127.0.0.1:5383`, env `~/bonneref/garage.env`, Mongo db `garage` on `bonneref-mongodb`, bills on `bonneref-minio` (`STORAGE_ADAPTER=s3`). The same container serves bonneref.fr `/garage/*` and garage.bonneref.fr. |
| Mercure hub | inside `bonneref-garage` (image Caddyfile `http://php:80` site; `MERCURE_HUB_UPSTREAM=127.0.0.1:80`), reached publicly as `https://garage.bonneref.fr/.well-known/mercure`. Anonymous subscribers, the pilot topic digest is the access control. |
| Atelier (`dashboard/`) | static build in `~/sites/garage.bonneref.fr`, served by the host Caddy |
| in-app agent turn | `AGENT_TURN_DISPATCH=terminate` (no worker, no Postgres; in-memory messenger DSNs) |
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

## Rebuilding

```bash
cd ~/apps/mon-parc-auto.fr && git fetch && git status   # deploy from origin/main
# api
docker compose -p soyuka -f ~/bonneref.compose.yml build bonneref-garage
docker compose -p soyuka -f ~/bonneref.compose.yml up -d --no-deps bonneref-garage
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
