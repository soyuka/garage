# Product

## Register

product

## What this is

A personal side project, never sold. Also the entry for the Symfony AI & MCP hackathon (submission 2026-11-22),
so the demo matters: a jury clones it, connects an MCP client, and watches the garage react.

## Users

Individual owners and small-fleet managers ("parc auto") in France maintaining cars, motos, utilitaires and groundscare machines. They are in a task: logging a maintenance visit, checking what's due, asking the agent to find a part. Mechanic-literate — they read part references and mileages verbatim. French by default, English available. The domain does not translate — a Contrôle Technique and a carte grise stay what they are.

## Product Purpose

AI-native vehicle-maintenance tracker (GMAO-leaning, not a DMS): forecast what's due from logged visits and usage, surface overdue work live, and let an agent do the catalog/paperwork legwork. Success = the owner knows the health of the whole parc at a glance and never misses a service.

## The agent drives the screen

The interface is not only clicked, it is driven. An MCP client (Claude Code, Claude Desktop, Goose…) talks to
the garage's MCP server; its render tools push frames over Mercure and the app reacts live. The same frames
come from the in-app agent. What the agent can ask the screen to do (`dashboard/src/types.ts`, transport in
`dashboard/CONTRACT.md`): list and select vehicles, show facts/specs, focus a panel, propose actions (CTA),
show parts for a planned task, show history, focus a body region of the car, reveal a part assembly, open a
document (bill PDF, page + highlight), toast, report status, mark a turn start/end. How each one looks and
moves is open.

## Assets to integrate

Real 3D vehicle models ship in `dashboard/public/*.glb` (web + thumb variants: Renault Espace IV, Peugeot 206,
Mazda 6, plus generic cv/moto/groundscare defaults) and generic part meshes in `dashboard/public/parts`. The
redesign uses them; their rendering treatment is open.

## Accessibility & Inclusion

Color-blind safe: no state carried by color alone. `prefers-reduced-motion` honored. WCAG contrast on data.
