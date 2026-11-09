# Atelier (`dashboard`)

The clean-room rebuild of the `mon-parc-auto.fr` front-end — a lit 3D garage *room* where a living
agent (Le Noyau) walks around your car and the bodywork tells you what hurts. Standalone client over
the existing Symfony / API-Platform backend; **imports nothing from `dashboard/`**.

- **Contract:** `CONTRACT.md` (build against this + `src/types.ts`).

## Run

Served **same-origin behind Caddy** at `https://localhost` (Atelier is Caddy's `@frontend` upstream).

```bash
cd ../api && docker compose up -d      # backend + the dashboard dev server (Vite, in-container)
# → open https://localhost  (accept the self-signed cert once)
```

The `dashboard` compose service runs `npm install && npm run dev` in a `node:22-alpine` container
(node_modules is an anonymous volume — never shadowed by the host tree). HMR rides wss on `/vite-hmr`.

### Local tooling (tests / type-check / build)

```bash
npm install      # host install, for the commands below
npm test         # vitest (run once)
npm run build    # tsc --noEmit + vite build
```

## Layout

```
src/
  types.ts       frozen backend-contract types + the real UIAction/Mercure-frame union
  router.ts      minimal hash-based dev router (TDD'd matcher; OAuth owns the real pathname)
  router.test.ts
  App.tsx        bare routed dev shell — dev index + placeholder route slots per stage
  main.tsx       entry
```

## Stage status

- **Stage 0 — rails: done.** Scaffold, isolated tsconfig, same-origin serving, Vitest, frozen
  contract types, `CONTRACT.md`.
- Next: **Stage A** — Le Noyau standalone playground at `#/noyau`, driven by a scripted mock frame
  stream (TDD the frame→state machine against the real `status`/`turn`/`toast` shapes).
