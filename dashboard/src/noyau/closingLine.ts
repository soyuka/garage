// The Données chip's closing sentence:
// names the vehicle when it can. `name` follows the repo convention
// `[make, model].filter(Boolean).join(' ')` (ParcLot.tsx, greeting.ts) — the caller builds it, this
// only picks which template fits it.
export function dataClosingLine(name: string, withName: (name: string) => string, generic: string): string {
  return name ? withName(name) : generic;
}
