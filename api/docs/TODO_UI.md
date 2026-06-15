# TODO — UI (MCP Apps)

Deferred. Build the garage + maintenance **backend first** (entities, tools), then UI.
Captures the 2026-06-15 chat about UIs so we don't re-derive it.

## Context

MCP Apps extension = `io.modelcontextprotocol/ui` (php-sdk PR #281, merged). Lets an MCP
tool attach a `ui://` resource (`_meta.ui.resourceUri`); the host (Claude etc.) renders it
in a **sandboxed iframe** inline in the conversation. iframe ↔ host talk over standard MCP
JSON-RPC (app-bridge): the UI can call our existing tools back. Capability negotiated in
`initialize` via the extension id.

Dependency: needs `mcp/sdk` >= the version carrying the extension (today pinned by
mcp-bundle 0.9 → sdk 0.5). Confirm bump before starting UI work. See TODO.md "## MCP / platform".

## Decisions made

- **Maintenance record cards = the headline UI.** A vehicle's service history as a stack of
  cards (type icon, date, usage at service, cost, who did it, parts used, attached bills).
  This is the nicest thing to render and the main reason to do UI at all. Backend
  (`MaintenanceRecord` + tools) must exist first.
- **DROPPED: add-fact form on `not_in_catalog` miss.** Originally proposed a form (capacity,
  filter ref, source url, verified checkbox) → `add_vehicle_fact`. Rejected: users will say
  *"find oil capacity online and save it"* and the agent calls the MCP tool directly. No UI
  needed; keep `add_vehicle_fact` inputs **permissive** instead.
- Build backend before any UI. Don't gate domain work on the iframe layer.

## UI ideas (ranked, for later)

1. **Maintenance record cards / service timeline** — primary UI. Renders
   `get_maintenance_history`. Card per record. Timeline view. "Next due" badge
   (overdue = red) from `get_maintenance_due`. [Log service] button → `log_maintenance`.
2. **Vehicle facts card** — renders `get_vehicle_facts`. Row per fact, **verified badge
   color-coded** (green = TecDoc/partsapi verified, amber = llm/web unverified — turns the
   `verified` bool + provenance into a visual instead of a prose caveat). Source link, unit,
   addedAt. Zero new backend — pure win on existing provenance data.
3. **Garage grid** — home screen. Cards of all vehicles (make/model/year/plate, fact count,
   last-serviced). Click selects → drives next tool call. Needs a `list_garage`/`get_vehicle`
   tool (already on TODO.md "### Tools").
4. **Plate lookup card** — SIV plate input → vehicle identity card → "add to garage".
5. **Parts picker** — PartCategory/Article browse → pick filter → ref number + copy button.

## Future — bills & proofs (attachments)

Owner wants to attach **bills/invoices/proofs** to garage memory — e.g. work done by an
external garage, keep the invoice as proof. Drives both data model and UI:
- Backend: `Attachment` entity linked to `MaintenanceRecord` (filename, mime, size, kind
  {invoice|photo|manual|warranty}, uploadedAt, storage path). File upload via API Platform
  (Vich/file resource). Plan in the maintenance backend so the relation seam exists early.
- UI: attachment thumbnails on the maintenance card; click → open invoice PDF/photo.
- A logged record with an attached invoice is **ground-truth** (owner did it / has proof) —
  trust model differs from sourced specs; record provenance source=`user`, verified by proof.
