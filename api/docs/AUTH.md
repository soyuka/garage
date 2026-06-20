# Authentication — Casdoor OIDC on `/mcp` and the REST garage

Two firewalls are OAuth2-protected against the same Casdoor authorization server; Symfony
validates bearer tokens locally against Casdoor's JWKS (issuer + signature, **no audience**):

- **`/mcp`** — the MCP endpoint (MCP authorization spec, building on RFC 9728). Clients self-register
  via anonymous DCR.
- **`/garage/*` writes + `/agent/turn`** — the `api` firewall. The token is optional at the firewall
  (anonymous falls through so the public GET reads keep working); `access_control` forces it on the
  write methods (`POST/PUT/PATCH/DELETE` on `^/garage`) and on `^/agent/turn`. This is the dashboard
  Bearer obtained via the browser OAuth flow (see "Dashboard login").

GET reads on `/garage` stay public and Mercure is still unauthenticated — that latent multi-tenant
cluster is the next step. `/api` reference data is fully public.

## Why Casdoor (not Zitadel)

Stock Goose (and other rmcp-based MCP clients) authenticate by **anonymous RFC 7591 Dynamic
Client Registration**: the client `POST`s to the `registration_endpoint` with no credentials,
gets a fresh `client_id`, then runs the OAuth code flow on a loopback redirect
(`http://127.0.0.1:{random}/oauth_callback`). There is no way to inject a pre-registered
`client_id`. Casdoor advertises `registration_endpoint` and supports anonymous DCR; Zitadel
advertises neither DCR nor URL-based client IDs, so stock Goose cannot register against it.

## Moving parts

| Piece | File |
| --- | --- |
| Firewall + custom token handler | `config/packages/security.yaml`, `config/services.yaml` |
| Token handler (JWKS + signature + issuer, **no audience** — see below) | `src/Security/CasdoorTokenHandler.php` |
| User provisioned from claims (find-or-create on `sub`) | `src/Security/ZitadelUserProvider.php`, `src/Entity/User.php` |
| RFC 9728 metadata endpoint | `src/Controller/Mcp/ProtectedResourceMetadataController.php` |
| `WWW-Authenticate` challenge on 401 | `src/EventListener/McpProtectedResourceChallengeListener.php` |
| `api` firewall + write/agent `access_control` | `config/packages/security.yaml` |
| Casdoor server + dashboard OAuth app seed | `compose.yaml` (`casdoor`), `docker/casdoor/app.conf`, `docker/casdoor/init_data.json` |
| Dashboard OAuth client (PKCE) + `authFetch` | `dashboard/src/auth.ts`, `dashboard/src/AuthGate.tsx` |

## Why a custom token handler (no audience check)

Casdoor sets the access-token `aud` claim to the **OAuth client_id**. Under DCR that client_id
is minted fresh per MCP client, and Casdoor ignores the RFC 8707 `resource` parameter that
would otherwise let the resource server pin a stable audience. So Symfony's stock `oidc`
handler — which requires and checks a fixed `audience` — cannot validate these tokens.

`CasdoorTokenHandler` mirrors the OIDC handler (JWKS discovery, RS/ES signature verification,
`iat`/`nbf`/`exp` and `iss` checks) but **drops the audience check**. The authorization
boundary is therefore the issuer + signature (any token this Casdoor instance signs is
accepted) plus the `ZitadelUserProvider` find-or-create on `sub`. If `/mcp` must be limited to
a subset of users, add a scope/role check inside the handler.

## Bootstrap flow (what an MCP client does)

1. `POST /mcp` with no token → `401` carrying
   `WWW-Authenticate: Bearer resource_metadata="…/.well-known/oauth-protected-resource"`.
2. Client fetches `/.well-known/oauth-protected-resource` → learns the authorization server
   (`CASDOOR_ISSUER`) and reads its discovery document.
3. Client self-registers via `POST $CASDOOR_ISSUER/api/oauth/register` (anonymous DCR), runs
   the OAuth code flow against Casdoor, gets an access token, retries `/mcp` with
   `Authorization: Bearer <token>`.
4. `CasdoorTokenHandler` validates the JWT, `ZitadelUserProvider` resolves/creates the `User`,
   the tool runs.

## One-time Casdoor setup

`docker compose up` starts Casdoor at <http://localhost:8000> (console + SPA on the same
origin; the production `casbin/casdoor` image is used, not `casdoor-all-in-one`, whose
authorization endpoint lives on a separate `:7001` and breaks the browser redirect). It seeds
a built-in org/app and an admin on first boot. Then:

1. Log in to the console at <http://localhost:8000> as **`built-in` / `admin` / `123`**.
2. **Organization "built-in" → set `dcrPolicy` to `open`** (Dynamic Client Registration is
   **disabled by default**). This is what lets Goose register anonymously. Verify:

   ```sh
   # discovery advertises the registration endpoint
   curl -s http://localhost:8000/.well-known/openid-configuration | grep registration_endpoint
   # anonymous registration returns 201 (after dcrPolicy=open)
   curl -s -o /dev/null -w '%{http_code}\n' -X POST http://localhost:8000/api/oauth/register \
     -H 'Content-Type: application/json' \
     -d '{"client_name":"probe","redirect_uris":["http://127.0.0.1:51763/oauth_callback"],"token_endpoint_auth_method":"none"}'
   ```

3. (Optional) Add social Identity Providers (Google, GitHub) under Providers and enable them on
   the built-in app — real client IDs/secrets go in the Casdoor console, never in this repo.

`dcrPolicy` is an organization setting, not an init-time flag, so it can be toggled live
without re-initialising. It can also be set via the API (`POST /api/update-organization`).

## Dashboard login (browser OAuth + PKCE)

Unlike Goose (anonymous DCR), the SPA dashboard uses a **pre-registered public client** seeded
declaratively in `docker/casdoor/init_data.json` (the `dashboard` application: Authorization Code +
refresh_token grants, one redirect URI `https://localhost/callback`, no usable secret). Casdoor's
`InitDb()` always creates the built-in org/app/admin and applies init data **additively/idempotently**
(an object is created only if absent), so the seed only adds the dashboard app and never clobbers
the built-ins. The binary's workdir is `/` and `app.conf` has `initDataFile = "./init_data.json"`, so
compose mounts it at `/init_data.json`.

Apply it (the seed runs at Casdoor boot, so an existing instance needs a restart — additive, no data loss):

```sh
docker compose up -d --force-recreate casdoor
# verify the app exists
curl -s http://localhost:8000/.well-known/openid-configuration >/dev/null   # discovery up
```

The SPA flow (`dashboard/src/auth.ts`): `login()` redirects to `/login/oauth/authorize` with a PKCE
`code_challenge`; the user signs in on Casdoor's hosted page (the seeded **admin / 123**, any user you
add, or a social IdP); Casdoor redirects to `https://localhost/callback?code=…`; `completeLoginIfCallback()`
exchanges the code at the token endpoint sending the `code_verifier` and **no client secret** (Casdoor
skips the secret check when a PKCE challenge is present — `object/token_oauth.go`); the JWT access token
is stored and sent as `Authorization: Bearer` on every garage write + `/agent/turn` via `authFetch`.
A 401 drops the token and bounces back to login.

To log in you just need a Casdoor user — the seeded **admin / 123** works, or add one under
**Users** in the console. No social provider required (GitHub/Google are the optional step above).

## Environment variables

| Var | Purpose | Default |
| --- | --- | --- |
| `CASDOOR_ISSUER` | Public issuer URL (`iss`); discovery at `$ISSUER/.well-known/openid-configuration`, JWKS at the `jwks_uri` it points to | `http://localhost:8000` |
| `CASDOOR_PORT` | Host port the `casdoor` service publishes | `8000` |

There is intentionally **no audience variable** — see "Why a custom token handler" above.
Database credentials and `origin` live in `docker/casdoor/app.conf` (not env-interpolated);
supply your own file with real secrets for production.

## Tests

A real Casdoor-signed JWT cannot be minted offline, so the test firewall swaps
`CasdoorTokenHandler` for `tests/Security/FakeOidcTokenHandler`, which decodes the opaque
tokens produced by `tests/Functional/FakeOidcToken`. The firewall, `ZitadelUserProvider`,
find-or-create and role mapping all run for real — only signature verification is stubbed.
`CasdoorTokenHandler` itself is unit-tested (signature, issuer, expiry, arbitrary-audience
acceptance) in `tests/Security/CasdoorTokenHandlerTest.php`. See also
`tests/Functional/McpOAuthTest.php`.
