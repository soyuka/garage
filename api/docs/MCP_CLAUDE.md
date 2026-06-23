# Using the garage MCP server from Claude Code

The `mon-parc-auto` MCP server (catalog + writable garage) is exposed over **HTTP only**
at `https://localhost/mcp` and is **Casdoor-bearer-gated**. A bare `claude` will not see it:
the server isn't registered, node won't trust Caddy's self-signed cert, and writes need an
authenticated owner. The launcher handles all three.

## Quick start

```bash
cd api
bin/mpa-claude                 # interactive session, garage tools loaded
```

Headless:

```bash
cd api
bin/mpa-claude -p "Record an oil change on my Toro 246-H with filter ref TORO-120-4276" \
  --allowedTools "mcp__mon-parc-auto__list_vehicles" \
                 "mcp__mon-parc-auto__plan_maintenance" \
                 "mcp__mon-parc-auto__log_maintenance"
```

Inside a session, confirm the connection with `/mcp`. The tools appear as
`mcp__mon-parc-auto__<tool>` (e.g. `list_vehicles`, `plan_maintenance`, `log_maintenance`,
`get_vehicle_facts`, `get_maintenance_history`, `lookup_plate`, `list_articles`, …).

## What the launcher does

`bin/mpa-claude` guarantees three preconditions, then execs `claude` forwarding all args:

1. **TLS** — exports `NODE_EXTRA_CA_CERTS=var/caddy-root.crt` so node trusts Caddy's local CA.
   Extracts it from the `php` container (`/data/caddy/pki/authorities/local/root.crt`) if missing.
2. **Token** — re-mints a Casdoor access token via the dashboard's PKCE flow (as user `admin`)
   when `var/token.txt` is missing or within 1h of expiry. Tokens last 7 days.
3. **Registration** — (re)registers the `mon-parc-auto` HTTP server in **local scope** with the
   current bearer. Local scope keeps the secret token out of git.

All three artifacts live under `api/var/` (gitignored): `caddy-root.crt`, `token.txt`,
`mint_token.py`.

## Prerequisites

- The dev stack is up: `docker compose up -d` (php/casdoor/mongodb/…).
- `claude` CLI on `PATH`.
- Casdoor admin password is `123` by default. Override: `MPA_CASDOOR_PASSWORD=… bin/mpa-claude`.

## Identity

The minted token's `sub` is `fd24f3bd-…` = `admin@example.com`, the owner of every vehicle
currently in the garage (incl. the TORO Wheel Horse 246-H). All writes through the MCP server
land under that owner — see `docs/AUTH.md` for the tenancy seam.

## Manual setup (without the launcher)

```bash
cd api
export NODE_EXTRA_CA_CERTS=$PWD/var/caddy-root.crt   # extract once if absent (see launcher)
python3 var/mint_token.py                            # writes var/token.txt
claude mcp add mon-parc-auto https://localhost/mcp --transport http --scope local \
  --header "Authorization: Bearer $(cat var/token.txt)"
claude mcp get mon-parc-auto                         # → Status: ✔ Connected
```

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `Status: ✘ Failed to connect` | `NODE_EXTRA_CA_CERTS` not set → self-signed cert rejected | use `bin/mpa-claude`, or export the CA path |
| `401` on tool calls | token expired (>7 days) | `bin/mpa-claude` re-mints automatically; or rerun `mint_token.py` |
| Tools absent in a running session | server registered after launch | restart via `bin/mpa-claude` |
| `mint_token.py` → `LOGIN FAILED` | wrong admin password | set `MPA_CASDOOR_PASSWORD` |
| Curl `initialize` → `-32600 valid session id REQUIRED` | malformed request body, **not** a server bug | use a real MCP client; the handshake is fine |

## Caveats

- The token is a 7-day dev credential for `admin@example.com`. Don't commit `var/token.txt`.
- The PKCE flow depends on the app's `code_challenge` going in the **query string** (snake_case)
  while other oauth params go in the login JSON body — a Casdoor quirk baked into `mint_token.py`.
