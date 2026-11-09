# CLAUDE.md — `bonneref/api/`

The bonneref backend: read-only TecDoc catalogue, SIV plate / VIN lookups and the finder agent. See
`README.md` for routes, env and how to run; repo-wide rules are in `../../CLAUDE.md`.

- MCP-first like mpa's api: a capability is an `ApiResource` DTO carrying HTTP operations and `mcp:` tools over a
  thin `State/` provider or processor. The in-process finder toolbox (`app.finder.mcp_toolbox`) drives the same
  MCP server (`mcp.server.catalog`) through an allowlist.
- MongoDB (ODM) is the only domain store. Postgres exists only for the optional `messenger` dispatch mode.
- Toolchain: `composer cs` / `composer cs:check` (php-cs-fixer, `@Symfony`, owns style), `composer mago` (Mago
  analyzer + linter, `mago.toml`, no baseline: fix the finding, or a narrow `// @mago-expect <category>:<code> <reason>`
  on the line before for a genuine false positive), `vendor/bin/phpunit <file>`. Raise the memory limit
  (`php -d memory_limit=1G`), run one test file at a time, never the suite.
- `/app/var` is an anonymous volume: clear the cache inside the container, not from the host.
- The class `Garage*`, `Maintenance*`, partsapi and TTS/STT code lives in mpa (`../../api`), not here. If a
  change needs one of them, it belongs in mpa.
