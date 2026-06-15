# Authentication — Zitadel OIDC on `/mcp`

The `/mcp` endpoint is OAuth2-protected (MCP authorization spec, building on RFC 9728).
Zitadel is the authorization server; Symfony validates bearer tokens locally against
Zitadel's JWKS via the OIDC discovery document. No MCP-specific security code — it is the
standard Symfony `access_token` firewall (`config/packages/security.yaml`).

`/api` (public vehicle reference data) is left open. To protect it too, add an `api`
firewall mirroring `mcp` and an `access_control` rule on `^/api`.

## Moving parts

| Piece | File |
| --- | --- |
| Firewall + `oidc` token handler | `config/packages/security.yaml` |
| User provisioned from claims (find-or-create on `sub`) | `src/Security/ZitadelUserProvider.php`, `src/Entity/User.php` |
| RFC 9728 metadata endpoint | `src/Controller/Mcp/ProtectedResourceMetadataController.php` |
| `WWW-Authenticate` challenge on 401 | `src/EventListener/McpProtectedResourceChallengeListener.php` |
| Zitadel container | `compose.yaml` (`zitadel` service) |

## Bootstrap flow (what an MCP client does)

1. `POST /mcp` with no token → `401` carrying
   `WWW-Authenticate: Bearer resource_metadata="…/.well-known/oauth-protected-resource"`.
2. Client fetches `/.well-known/oauth-protected-resource` → learns the authorization server
   (`ZITADEL_ISSUER`).
3. Client runs the OAuth flow against Zitadel, gets an access token, retries `/mcp` with
   `Authorization: Bearer <token>`.
4. Symfony validates the JWT, `ZitadelUserProvider` resolves/creates the `User`, the tool runs.

## One-time Zitadel setup

`docker compose up` starts Zitadel at <http://localhost:8080> (console `/ui/console`, default
admin `admin` / `Password1!` — see `compose.yaml`). Then, in the console:

1. Create a **Project**.
2. Under the project, **enable "Assert access tokens as JWT"** (project settings). Without
   this, Zitadel issues opaque tokens and the local `oidc` handler cannot validate them —
   switch `security.yaml` to the `oauth2` introspection handler if you keep opaque tokens.
3. Create an **Application** (Web / PKCE for an interactive client). Note its **Client ID**.
4. Add **Identity Providers** (Google, GitHub) under the org's IdP settings and enable them
   on the login policy — this is the social login surface. Real Google/GitHub OAuth client
   IDs/secrets go in the Zitadel console, never in this repo.
5. Set `ZITADEL_AUDIENCE` (in `.env.local`) to the application's Client ID (or the project
   resource ID if you scope the project into the token audience).

## Environment variables

| Var | Purpose | Default |
| --- | --- | --- |
| `ZITADEL_ISSUER` | Public issuer URL (`iss`); discovery at `$ISSUER/.well-known/openid-configuration` | `http://localhost:8080` |
| `ZITADEL_AUDIENCE` | Token `aud` validated for `/mcp` (the app Client ID) | _(empty — set per instance)_ |
| `ZITADEL_MASTERKEY` | Zitadel encryption master key (32 chars) | dev placeholder — **externalize in prod** |
| `ZITADEL_EXTERNALSECURE` | `true` once behind TLS | `false` |

## Tests

A real Zitadel-signed JWT cannot be minted offline, so the test firewall swaps the
cryptographic handler for `tests/Security/FakeOidcTokenHandler`, which decodes the opaque
tokens produced by `tests/Functional/FakeOidcToken`. The firewall, `ZitadelUserProvider`,
find-or-create and role mapping all run for real — only signature verification is stubbed.
See `tests/Functional/McpOAuthTest.php`.
