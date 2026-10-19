# Using the garage MCP server from Claude Code

The `mon-parc-auto` MCP server (catalog + writable garage) is exposed over **HTTP only** at
`https://localhost/mcp` (dev, Caddy self-signed cert) or `https://garage.soyuka.me/mcp` (stage tunnel) and is
**OAuth-gated** by the hosted Casdoor at `https://auth.bonneref.fr`. Claude Code authenticates natively: there
is no launcher, no static bearer and no token-minting script.

## Quick start

Stage (public host, no cert trouble): `stage/bin/atelier` launches `claude` in `stage/`, whose `.mcp.json`
points at `https://garage.soyuka.me/mcp`. Anywhere else:

```bash
claude mcp add mon-parc-auto https://garage.soyuka.me/mcp --transport http --scope local
```

For the dev stack use `https://localhost/mcp` and trust Caddy's local CA first:

```bash
export NODE_EXTRA_CA_CERTS=$PWD/api/var/caddy-root.crt   # extract from the php container if absent:
# docker compose cp php:/data/caddy/pki/authorities/local/root.crt api/var/caddy-root.crt
claude mcp add mon-parc-auto https://localhost/mcp --transport http --scope local
```

Then in a session run `/mcp`, select `mon-parc-auto` and authenticate. Claude Code follows the 401 +
`WWW-Authenticate` challenge to `/.well-known/oauth-protected-resource`, discovers `auth.bonneref.fr`,
self-registers by DCR and opens a browser for the Casdoor login. Confirm with `claude mcp get mon-parc-auto`
(`Status: ✔ Connected`). The tools appear as `mcp__mon-parc-auto__<tool>`.

## Prerequisites

- The stack is up (`docker compose up -d`; the stage launch layers `compose.stage.yaml`).
- The php container has outbound https: it fetches discovery + JWKS from `auth.bonneref.fr`.
- `claude` CLI on `PATH`.

## Identity

The owner is the `email` claim of the account you log in with at `auth.bonneref.fr`. Every read and write
through the MCP server is scoped to it — see `docs/AUTH.md` for the tenancy seam. The browser showing the
Atelier must be logged in as the same account, or tool calls succeed and render nothing (the pilot topic is
derived from the owner).

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `Status: ✘ Failed to connect` on `https://localhost/mcp` | `NODE_EXTRA_CA_CERTS` not set, self-signed cert rejected | export the CA path (above), or use the tunnel host |
| `401` on tool calls | OAuth token expired or never granted | `/mcp` → re-authenticate |
| `403 Invalid Host header` on authenticated calls only | host missing from `MCP_ALLOWED_HOSTS` | add it (see `config/packages/mcp.yaml`) |
| Every token rejected after a login succeeds | `CASDOOR_ISSUER` differs from the discovery `issuer` | make them equal; clear `var/cache` inside php + worker, restart |
| Tools absent in a running session | server registered after launch | restart the session |
| Curl `initialize` → `-32600 valid session id REQUIRED` | malformed request body, **not** a server bug | use a real MCP client; the handshake is fine |
