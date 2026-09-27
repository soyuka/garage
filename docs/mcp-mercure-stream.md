# Delegated MCP streams

An MCP tool call that streams does not keep a PHP thread open. The endpoint answers with a topic,
Caddy serves the stream from the Mercure hub, and the app publishes the answer onto that topic.

## Why

The MCP SDK streams from PHP. `vendor/mcp/sdk/src/Server/Transport/StreamableHttpTransport.php`
runs a loop that flushes output and sleeps 100 ms per turn, and it stops the call after 120 seconds.
Under FrankenPHP worker mode that loop holds one worker thread for the whole call.

Delegation splits the work from the connection. PHP returns immediately. Caddy holds the client
socket as a goroutine. The tool runs later and publishes its frames to the hub.

## The parts

| Part | Where |
| --- | --- |
| `mercure_stream` Caddy handler | built into the binary, `api/Dockerfile` |
| `intercept` block that catches the header | `api/frankenphp/Caddyfile` |
| Response that starts a stream | `api/src/Mcp/Stream/MercureStreamResponseFactory.php` |
| Decision to delegate | `api/src/Controller/Mcp/DelegatingMcpController.php` |
| Held call | `api/src/Mcp/Stream/PendingCallStore.php` |
| Caddy callback | `api/src/Controller/Mcp/McpStreamLifecycleController.php` |
| The hub itself | the `http://php:80` site, `api/frankenphp/Caddyfile` |
| Replay and publish | `api/src/Mcp/Stream/McpStreamRunner.php` |

## The sequence

1. A client posts a `tools/call` to `/mcp` with `Accept: text/event-stream`.
2. The app mints a topic and a subscriber token, stores the call, and returns `200` with an empty
   body and two headers: `Mercure-Stream` and `Mercure-Stream-Token`.
3. Caddy intercepts that response. It strips both headers, subscribes to the topic, and starts
   streaming to the client.
4. Caddy posts `{"topic": "...", "state": "ready"}` to `/_mcp/stream-lifecycle`.
5. The app answers that callback with `204`, then runs the tool after the response is sent.
6. Each JSON-RPC message is published to the topic as a `message` event. A `mercure-stream-end`
   event closes the stream.

## Rules that the design depends on

Publish only after the `ready` callback. The Caddy handler does no replay and sends no
`Last-Event-ID`. A frame published before Caddy subscribes is lost.

Answer the `ready` callback before running the tool. Caddy waits about three seconds for that
callback and then gives up on the stream. A catalog lookup can take longer.

Answer it with `204` exactly. The handler compares the status to `http.StatusNoContent` and never
reads the body (`mercure-stream-delegation` delegate.go:264). A `200` aborts the stream with a `502`,
and the client sees a bad gateway rather than any MCP error.

Pass `lifecycle_token` as `{$MCP_STREAM_LIFECYCLE_TOKEN}`, not `{env....}`. The handler keeps the
configured string as it was written and never runs it through Caddy's replacer, so a runtime
placeholder is sent verbatim as the bearer and the callback answers `401`. The `mercure` directive
resolves `{env....}` itself, which is why the two forms differ in the same file.

Declare the hub once, on the internal `http://php:80` site. Mercure 1.0 refuses a second unnamed hub
anywhere in the configuration, and each `mercure` directive provisions its own. `SERVER_NAME` can
hold several addresses, and addresses that differ in scheme land in different Caddy servers, so a hub
on the public site is built once per server and the whole configuration is rejected. The stage
profile serves `https://localhost` and `http://garage.soyuka.me` and did not boot at all until the
hub moved. The public addresses `reverse_proxy` the hub path to it.

Keep FrankenPHP hot reload off. It publishes the browser refresh through a hub declared in its own
Caddy server, so with the hub on the internal site it fails provisioning with "no Mercure hub
configured". `compose.override.yaml` sets `FRANKENPHP_SITE_CONFIG` empty for this reason.

Do not depend on output buffering in the runner. A delegated call runs on `kernel.terminate`, after
the response is sent, and PHP then refuses a new buffer ("ob_start(): Failed to create buffer"). The
SDK only echoes when a fiber suspends; every other answer comes back on the response body, batched
into a JSON array when there is several.

Delegate `tools/call` only. The `initialize` method returns the session id and the negotiated
capabilities on the response itself. A client that received those over a stream has nothing to key
its next request on.

Publish private updates. The hub accepts anonymous subscribers, so a public update lets anyone who
learns the topic read another caller's result. Only the scoped token Caddy holds can read a private
one.

Carry the owner on the held call. The lifecycle callback has no bearer token. Every garage read and
write is owner-scoped, so an owner resolved again inside the callback resolves to nobody.

## Configuration

| Name | Default | Used by |
| --- | --- | --- |
| `MCP_STREAM_DELEGATION` | `0` (off, see below) | the app, to decide whether to delegate |
| `MCP_STREAM_LIFECYCLE_TOKEN` | `!ChangeThisMcpStreamLifecycleToken!` | Caddy and the callback endpoint |
| `MCP_STREAM_TIMEOUT` | `5m` | Caddy, total stream lifetime |
| `MCP_STREAM_IDLE_TIMEOUT` | `60s` | Caddy, closes a silent stream |
| `MERCURE_URL` | `http://php/.well-known/mercure` | the hub address Caddy subscribes to |

## What it costs

Measured on this machine, stage profile, `list_vehicles`, warm worker, median of the run:

| concurrent `tools/call` | delegation on | delegation off |
| --- | --- | --- |
| 1 | 0.116 s | 0.117 s |
| 8 | 0.283 s | 0.219 s |
| 24 | 0.917 s | 0.407 s |

Delegation is not free, and today it does not buy back what it costs. Two measured reasons.

An ordinary `tools/call` never streamed in the first place. The SDK takes its streaming branch only
when a fiber suspends, which is elicitation or sampling, and no tool here does either. With
delegation off the endpoint answers `Content-Type: application/json`, so the flush-and-sleep loop
this design routes around was never on the path.

The tool still runs on a PHP thread. `DeferredCalls` runs it on `kernel.terminate` of the lifecycle
request, so the work moves from one worker thread to another instead of leaving the pool, and each
call now costs two PHP requests rather than one.

The design pays off for a call that genuinely streams, or one long enough that holding the client
socket is the problem. Freeing the thread as well needs the tool to run off the request entirely, on
the Messenger worker that already serves agent turns.

## Operational notes

The hub runs inside the same Caddy as the app. Mercure 1.0 allows one unnamed hub for each HTTP
app, and each `mercure` directive builds its own hub. Two directives therefore give two hubs that
never see each other's updates. The site on `php:80` forwards to the public site for this reason.

To rebuild the binary after changing a module version, run `docker compose build php`. The build
sets `GOTOOLCHAIN=auto` because Mercure 1.0.2 needs Go 1.27 and the builder image ships Go 1.26.
