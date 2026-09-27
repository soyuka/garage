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
| Replay and publish | `api/src/Mcp/Stream/McpStreamRunner.php` |

## The sequence

1. A client posts a `tools/call` to `/mcp` with `Accept: text/event-stream`.
2. The app mints a topic and a subscriber token, stores the call, and returns `200` with an empty
   body and two headers: `Mercure-Stream` and `Mercure-Stream-Token`.
3. Caddy intercepts that response. It strips both headers, subscribes to the topic, and starts
   streaming to the client.
4. Caddy posts `{"topic": "...", "state": "ready"}` to `/_mcp/stream-lifecycle`.
5. The app answers that callback at once, then runs the tool after the response is sent.
6. Each JSON-RPC message is published to the topic as a `message` event. A `mercure-stream-end`
   event closes the stream.

## Rules that the design depends on

Publish only after the `ready` callback. The Caddy handler does no replay and sends no
`Last-Event-ID`. A frame published before Caddy subscribes is lost.

Answer the `ready` callback before running the tool. Caddy waits about three seconds for that
callback and then gives up on the stream. A catalog lookup can take longer.

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
| `MCP_STREAM_LIFECYCLE_TOKEN` | `!ChangeThisMcpStreamLifecycleToken!` | Caddy and the callback endpoint |
| `MCP_STREAM_TIMEOUT` | `5m` | Caddy, total stream lifetime |
| `MCP_STREAM_IDLE_TIMEOUT` | `60s` | Caddy, closes a silent stream |
| `MERCURE_URL` | `http://php/.well-known/mercure` | the hub address Caddy subscribes to |

## Operational notes

The hub runs inside the same Caddy as the app. Mercure 1.0 allows one unnamed hub for each HTTP
app, and each `mercure` directive builds its own hub. Two directives therefore give two hubs that
never see each other's updates. The site on `php:80` forwards to the public site for this reason.

To rebuild the binary after changing a module version, run `docker compose build php`. The build
sets `GOTOOLCHAIN=auto` because Mercure 1.0.2 needs Go 1.27 and the builder image ships Go 1.26.
