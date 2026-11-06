// The dev server sits behind the mpa Caddy at https://localhost/bonneref/ (`handle /bonneref*` in
// api/frankenphp/Caddyfile), while bonneref.fr serves the built dist/ at the site root. Keying the
// default on the vite command, not on an env var to remember, is what keeps a prod build from
// shipping /bonneref/assets/* URLs that the site answers with index.html.
export function viteBase(command: 'serve' | 'build', override: string | undefined): string {
  return override ?? (command === 'serve' ? '/bonneref/' : '/');
}
