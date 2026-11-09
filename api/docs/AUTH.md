# Authentication — hosted Casdoor OIDC on `/mcp` and the REST garage

Auth is the **hosted Casdoor at <https://auth.bonneref.fr>**, outside this compose stack (dev and stage
alike; there is no local `casdoor` container). Two firewalls are OAuth2-protected against it; Symfony
validates bearer tokens locally against its JWKS (issuer + signature + token type) and checks the
**audience** per firewall (§ Audience):

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
| Verifier (JWKS + signature + issuer + `tokenType: access-token`) | `src/Security/CasdoorTokenHandler.php` |
| Audience check, one instance per firewall (`app.access_token.mcp`, `app.access_token.api`) | `src/Security/AudienceTokenHandler.php`, `config/services.yaml` |
| This server's MCP resource URIs (metadata + `/mcp` audience) | `src/Security/McpResource.php` |
| User provisioned from claims (find-or-create; the tenant key is the `email` claim, `config/services.yaml:4`) | `src/Security/ZitadelUserProvider.php`, `src/Entity/User.php` |
| RFC 9728 metadata endpoint | `src/Controller/Mcp/ProtectedResourceMetadataController.php` |
| `WWW-Authenticate` challenge on 401 | `src/EventListener/McpProtectedResourceChallengeListener.php` |
| `api` firewall + write/agent `access_control` | `config/packages/security.yaml` |
| Issuer | `CASDOOR_ISSUER=https://auth.bonneref.fr` (`.env`, `.env.stage`; injected as container env by `compose.stage.yaml`) |
| Dashboard OAuth client (PKCE) + `authFetch` | `dashboard/src/auth.ts`, `dashboard/src/AuthGate.tsx` |

## Audience (since 2026-11-09)

MCP 2026-07-28: "MCP servers MUST validate that access tokens were issued specifically for them as the intended
audience, according to RFC 8707 Section 2." Each firewall wraps the verifier in an `AudienceTokenHandler` that
401s (`error="invalid_token"`, plus `resource_metadata` on `/mcp`) unless `aud` names something it serves:

| firewall | accepted `aud` | why |
| --- | --- | --- |
| `mcp` (`^/mcp`) | this server's resource URIs: `DEFAULT_URI` + `/mcp`, plus `MCP_RESOURCE_ALIASES` | MCP clients send the RFC 8707 `resource` (the one our RFC 9728 metadata advertises) and Casdoor binds `aud` to it |
| `api` (everything else) | `OAUTH_CLIENT_AUDIENCES`: the Atelier `671b8c86d75444f3f4c4`, the bonneref front `89646c002d2272351ca2` | browser PKCE clients send no `resource`, so Casdoor sets `aud` = their client_id; the bonneref front reaches `/garage/*` cross-origin |

An MCP token is not accepted on REST and a front-end token is not accepted on `/mcp`. The metadata's `resource`
is the configured URI on the origin the client reached (`McpResource::for()`), falling back to `DEFAULT_URI`:
clients refuse a `resource` on another origin.

**What Casdoor puts in `aud`** (source: casdoor/casdoor `object/token_jwt.go`, `generateJwtTokenWithOptions`):
`[client_id]`; `[client_id + "-org-" + owner]` for a *shared* application; replaced by `[resource]` when the
authorize request carried an RFC 8707 `resource` (`controllers/auth.go` reads it, the code stores it, the token
request must repeat the same value, `object/token_oauth.go` `GetAuthorizationCodeToken`; a refresh keeps it,
`RefreshToken` in `object/token_oauth_util.go`). RFC 8707 support landed upstream 2026-02-15 (`cf1af97b`); the
hosted instance is newer (its discovery lists `private_key_jwt`, added 2026-10-07, and lacks
`client_id_metadata_document_supported`, added 2026-10-19). Verified on a real token: a Claude Code DCR token for
`http://localhost/mcp` carried `aud: ["http://localhost/mcp"]`, `azp` = its DCR client_id. The earlier claim in
this file that Casdoor ignores `resource` was wrong for any build since February 2026.

Casdoor signs refresh and ID tokens with the same key and the same `aud`, so the verifier also requires
`tokenType: "access-token"`.

**Per deployment:** dev `DEFAULT_URI=https://localhost`, `.env.dev` adds `http://localhost/mcp` (plain-HTTP
clients); stage adds `https://garage.soyuka.me/mcp` (`.env.stage`, promoted by `compose.stage.yaml`); prod
`DEFAULT_URI=https://garage.bonneref.fr` and nothing else. Never put an alias in `.env`: it is baked into the prod
image (`composer dump-env prod`), and a localhost alias there would let prod accept tokens minted for anyone's
local server.

**bonneref** applies the same split (`bonneref/api`), plus one documented exception: mpa forwards its caller's
bearer to the bonneref catalogue, so the GET routes mpa calls also accept `CATALOGUE_PASSTHROUGH_AUDIENCES` (mpa's
Atelier client and MCP resource URIs). Never on bonneref's `/mcp`. This is token passthrough, which the MCP spec
forbids: debt, with the options for removing it, in `FUTURE.md` § "Token passthrough mpa → bonneref".

## Bootstrap flow (what an MCP client does)

1. `POST /mcp` with no token → `401` carrying
   `WWW-Authenticate: Bearer resource_metadata="…/.well-known/oauth-protected-resource"`.
2. Client fetches `/.well-known/oauth-protected-resource` → learns the authorization server
   (`CASDOOR_ISSUER`) and reads its discovery document.
3. Client self-registers via `POST $CASDOOR_ISSUER/api/oauth/register` (anonymous DCR), runs
   the OAuth code flow against Casdoor, gets an access token, retries `/mcp` with
   `Authorization: Bearer <token>`.
4. `CasdoorTokenHandler` validates the JWT, `AudienceTokenHandler` checks `aud` is this server's resource,
   `ZitadelUserProvider` resolves/creates the `User`,
   the tool runs.

## The hosted Casdoor

Discovery: <https://auth.bonneref.fr/.well-known/openid-configuration> (`issuer` is
`https://auth.bonneref.fr`, `registration_endpoint` is advertised). The php container fetches discovery
and the JWKS from it server-side on token verification, so it needs outbound https; `CASDOOR_ISSUER`
must equal the discovery `issuer` character for character or every token is rejected.

Nothing about this instance is seeded from this repo. What the repo's clients rely on:

| Client | Registration | Where |
| --- | --- | --- |
| MCP clients (Claude Code, Claude Desktop, Goose) | anonymous DCR, self-registered into the `bonneref` org | Caddy rewrite on the auth host, see below |
| The Atelier (`dashboard/`) | its own **public PKCE application** on `auth.bonneref.fr` | see below |
| bonneref (`bonneref/`) | pre-registered client `89646c002d2272351ca2` | owned by that surface |

Identity: the owner is the `email` claim, not `sub` (Casdoor re-mints `sub`), so one human is one tenant
whichever client signed them in.

## DCR lands in the `bonneref` organization

Casdoor registers an anonymous DCR client in `built-in` unless the call carries `?organization=`
(`controllers/oauth_dcr.go:64-67`), and discovery always advertises the bare `/api/oauth/register`
(`object/wellknown_oidc_discovery.go:140`), so stock clients can never pick the org. The Caddy site
`auth.bonneref.fr` (server `/etc/caddy/Caddyfile`) appends `organization=bonneref` to a
`POST /api/oauth/register` that has none, and "Enable dynamic client registration" is on for the
`bonneref` org only. Verified 2026-10-19: anonymous register → 201 with bonneref branding inherited.

## Atelier login (browser OAuth + PKCE)

Unlike MCP clients, the SPA uses a **pre-registered public client** on the hosted Casdoor. Create it in the
Casdoor console (Applications) with:

- grant types Authorization Code + refresh_token, no usable secret (PKCE public client);
- redirect URI `https://localhost/callback`;
- **CORS allowing `https://localhost`**: the token `POST` is a cross-origin `fetch()` straight to
  `https://auth.bonneref.fr/api/login/oauth/access_token` (the old same-origin `/casdoor/*` Caddy proxy is
  gone);
- its client id supplied to the SPA as `VITE_CASDOOR_CLIENT_ID` (default in `dashboard/src/auth.ts`).

The SPA flow (`dashboard/src/auth.ts`): `login()` redirects to `/login/oauth/authorize` with a PKCE
`code_challenge`; the user signs in on Casdoor's hosted page; Casdoor redirects to
`https://localhost/callback?code=…`; `completeLoginIfCallback()` exchanges the code sending the
`code_verifier` and **no client secret** (Casdoor skips the secret check when a PKCE challenge is present,
`object/token_oauth.go`); the JWT access token is stored and sent as `Authorization: Bearer` on every garage
write + `/agent/turn` via `authFetch`. A 401 drops the token and bounces back to login.

## MCP clients

`/mcp` clients do the whole dance themselves (see "Bootstrap flow"): Claude Code on first connect follows
the 401 challenge and protected-resource metadata to `auth.bonneref.fr`, registers by DCR and opens a browser
for login. There is no static bearer or token-minting script. See `MCP_CLAUDE.md`.

## Environment variables

| Var | Purpose | Default |
| --- | --- | --- |
| `CASDOOR_ISSUER` | Issuer URL (`iss`); discovery at `$ISSUER/.well-known/openid-configuration`, JWKS at the `jwks_uri` it points to | `https://auth.bonneref.fr` |
| `DEFAULT_URI` | public origin; `DEFAULT_URI/mcp` is the primary MCP resource URI | `https://localhost` |
| `MCP_RESOURCE_ALIASES` | extra MCP resource URIs (comma-separated, absolute) the same deployment answers on | empty (`.env.dev`: `http://localhost/mcp`) |
| `OAUTH_CLIENT_AUDIENCES` | client_ids accepted as `aud` on the REST firewall | `671b8c86d75444f3f4c4,89646c002d2272351ca2` |

## Tests

A real Casdoor-signed JWT cannot be minted offline, so the test firewall swaps
`CasdoorTokenHandler` for `tests/Security/FakeOidcTokenHandler`, which decodes the opaque
tokens produced by `tests/Functional/FakeOidcToken`. The firewall, `ZitadelUserProvider`,
find-or-create and role mapping all run for real — only signature verification is stubbed.
The audience check is NOT stubbed: `AudienceTokenHandler` wraps the fake verifier exactly as it wraps the real
one (`app.access_token.verifier` is the only service swapped under `when@test`). `FakeOidcToken::for()` carries
both test audiences by default (`MCP_RESOURCE`, forced as `MCP_RESOURCE_ALIASES` in `phpunit.dist.xml`, and
`ATELIER_CLIENT`, `OAUTH_CLIENT_AUDIENCES` in `.env.test`). `tests/Functional/TokenAudienceTest.php` pins right /
foreign / missing audiences on both firewalls; `CasdoorTokenHandler` is unit-tested (signature, issuer, expiry,
token type) in `tests/Security/CasdoorTokenHandlerTest.php`, `McpResource` in `tests/Security/McpResourceTest.php`.
See also `tests/Functional/McpOAuthTest.php`.
